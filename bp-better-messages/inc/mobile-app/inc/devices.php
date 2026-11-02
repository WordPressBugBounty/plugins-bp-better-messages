<?php
defined('ABSPATH') || exit;

if ( ! class_exists('Better_Messages_Mobile_App_Devices') ) {
    class Better_Messages_Mobile_App_Devices
    {
        const REMOVED_OPTION = 'better_messages_mobile_app_removed_devices';

        const REMOVED_LIMIT = 1000;

        public $settings;

        public $defaults;

        public static function instance(): ?Better_Messages_Mobile_App_Devices
        {
            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Mobile_App_Devices();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
        }

        public function rest_api_init()
        {
            register_rest_route('better-messages/v1/admin/app', '/getDevices', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_devices'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/signOutDevice', array(
                'methods' => 'POST',
                'callback' => array($this, 'admin_sign_out_device'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'required' => true,
                        'type'     => 'integer'
                    )
                )
            ));
        }

        public function user_is_admin(): bool
        {
            return current_user_can('manage_options');
        }

        public function admin_sign_out_device( WP_REST_Request $request )
        {
            $signed_out = $this->sign_out_devices( array( 'id' => (int) $request->get_param( 'id' ) ), 'admin' );

            if( $signed_out === 0 ){
                return new WP_Error(
                    'bm_app_device_not_found',
                    _x( 'This device is not signed in any more', 'Mobile App', 'bp-better-messages' ),
                    array( 'status' => 404 )
                );
            }

            return array( 'signed_out' => $signed_out );
        }

        public function sign_out_user_devices( int $user_id, string $reason, int $except_id = 0 ): int
        {
            if( $user_id <= 0 ){
                return 0;
            }

            return $this->sign_out_devices( array( 'user_id' => $user_id ), $reason, $except_id );
        }

        public function sign_out_devices( array $where, string $reason, int $except_id = 0 ): int
        {
            global $wpdb;

            $table = Better_Messages_Mobile_App()->devices_table;

            $conditions = array();
            $values     = array();

            if( isset( $where['id'] ) ){
                $conditions[] = '`id` = %d';
                $values[]     = (int) $where['id'];
            }

            if( isset( $where['user_id'] ) ){
                $conditions[] = '`user_id` = %d';
                $values[]     = (int) $where['user_id'];
            }

            if( isset( $where['device_id'] ) ){
                $conditions[] = '`device_id` = %s';
                $values[]     = (string) $where['device_id'];
            }

            if( isset( $where['app_id'] ) ){
                $conditions[] = '`app_id` = %s';
                $values[]     = (string) $where['app_id'];
            }

            if( count( $conditions ) === 0 ){
                return 0;
            }

            if( $except_id > 0 ){
                $conditions[] = '`id` != %d';
                $values[]     = $except_id;
            }

            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT `id`, `user_id`, `device_id`, `platform`, `environment`, `app_id`, `push_token`, `push_token_voip` FROM `{$table}` WHERE " . implode( ' AND ', $conditions ), $values ), ARRAY_A );

            if( empty( $rows ) ){
                return 0;
            }

            $ids = array_map( 'intval', array_column( $rows, 'id' ) );

            $deleted = (int) $wpdb->query( "DELETE FROM `{$table}` WHERE `id` IN (" . implode( ',', $ids ) . ")" );

            $this->queue_removed_devices( $rows );

            $cache_group = Better_Messages_Mobile_App()->cache_group;

            foreach( array_unique( array_map( 'intval', array_column( $rows, 'user_id' ) ) ) as $user_id ){
                wp_cache_delete( 'user_devices_' . $user_id, $cache_group );
            }

            foreach( $rows as $row ){
                do_action( 'better_messages_mobile_app_device_signed_out', (int) $row['user_id'], $row, $reason );
            }

            $this->send_signed_out_events( $rows );

            return $deleted;
        }

        public function get_signed_out_event( array $rows ): array
        {
            $ids     = array();
            $devices = array();

            foreach( $rows as $row ){
                $ids[]     = (int) $row['id'];
                $devices[] = array(
                    'device_id' => (string) $row['device_id'],
                    'app_id'    => (string) $row['app_id']
                );
            }

            return array(
                'type'    => 'app_devices_signed_out',
                'ids'     => $ids,
                'devices' => $devices
            );
        }

        private function send_signed_out_events( array $rows )
        {
            $websocket = isset( Better_Messages()->websocket ) ? Better_Messages()->websocket : null;

            if( ! is_object( $websocket ) || ! method_exists( $websocket, 'send_realtime_event' ) ){
                return;
            }

            $by_user = array();

            foreach( $rows as $row ){
                $user_id = (int) $row['user_id'];

                if( $user_id > 0 ){
                    $by_user[ $user_id ][] = $row;
                }
            }

            foreach( $by_user as $user_id => $user_rows ){
                $websocket->send_realtime_event( array( $user_id ), $this->get_signed_out_event( $user_rows ) );
            }
        }

        private function queue_removed_devices( array $rows )
        {
            if( ! Better_Messages()->mobile_app || ! Better_Messages()->mobile_app->is_active ){
                return;
            }

            $removed = get_option( self::REMOVED_OPTION, array() );

            if( ! is_array( $removed ) ){
                $removed = array();
            }

            $changed = false;

            foreach( $rows as $row ){
                if( empty( $row['push_token'] ) && empty( $row['push_token_voip'] ) ){
                    continue;
                }

                $removed[ $row['device_id'] . "\t" . $row['app_id'] ] = array(
                    'user_id'     => (int) $row['user_id'],
                    'device_id'   => (string) $row['device_id'],
                    'platform'    => (string) $row['platform'],
                    'environment' => (string) $row['environment'],
                    'app_id'      => (string) $row['app_id']
                );

                $changed = true;
            }

            if( ! $changed ){
                return;
            }

            if( count( $removed ) > self::REMOVED_LIMIT ){
                $removed = array_slice( $removed, -1 * self::REMOVED_LIMIT, null, true );
            }

            update_option( self::REMOVED_OPTION, $removed, false );
        }

        public function forget_removed_device( string $device_id, string $app_id )
        {
            $removed = get_option( self::REMOVED_OPTION, array() );
            $key     = $device_id . "\t" . $app_id;

            if( ! is_array( $removed ) || ! isset( $removed[ $key ] ) ){
                return;
            }

            unset( $removed[ $key ] );

            if( count( $removed ) === 0 ){
                delete_option( self::REMOVED_OPTION );
            } else {
                update_option( self::REMOVED_OPTION, $removed, false );
            }
        }

        public function get_devices( WP_Rest_Request $request )
        {
            global $wpdb;

            $table = Better_Messages()->mobile_app->devices_table;

            $devices = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY COALESCE( `last_active`, `last_login`, `created_at` ) DESC, `id` DESC", ARRAY_A );

            if( $devices ){
                foreach( $devices as $key => $device ){
                    $devices[$key]['user'] = Better_Messages()->functions->rest_user_item($device['user_id']);
                    $devices[$key]['push_token'] = ! empty($device['push_token']);
                    $devices[$key]['push_token_voip'] = ! empty($device['push_token_voip']);
                }
            }

            return $devices;
        }
    }
}
