<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Storage_Admin' ) ):

    class Better_Messages_Storage_Admin
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Storage_Admin();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
        }

        private function storage()
        {
            return Better_Messages_Storage();
        }

        public function can_manage()
        {
            return current_user_can( 'manage_options' );
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1/admin', '/storage/groups', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_groups' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/storage/groups/apply', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_apply_groups' ),
                'permission_callback' => array( $this, 'can_manage' ),
            ) );
        }

        public function enqueue_panel()
        {
            $sdk = $this->storage()->sdk();

            if ( $sdk ) {
                $sdk->admin()->enqueue();
            }
        }

        public function save_groups( $input )
        {
            $storage   = $this->storage();
            $routes    = $storage->routes();
            $connected = $storage->connected_types();
            $input     = is_array( $input ) ? $input : array();

            foreach ( Better_Messages_Storage::groups() as $group ) {
                if ( ! isset( $input[ $group ] ) ) {
                    continue;
                }

                $route = (string) $input[ $group ];

                if ( $route !== 'local' && ! in_array( $route, $connected, true ) ) {
                    return new WP_Error( 'bm_storage_invalid', _x( 'A file group goes to a storage that is not connected. Connect it first, or choose another storage.', 'Settings page', 'bp-better-messages' ), array( 'status' => 400 ) );
                }

                $routes[ $group ] = $route;
            }

            $storage->save_routes( $routes );

            return $storage->routes();
        }

        private function target_sql()
        {
            global $wpdb;

            $storage = $this->storage();
            $target  = array();

            foreach ( Better_Messages_Storage::groups() as $group ) {
                $location         = $storage->route( $group );
                $target[ $group ] = $location ? $location['id'] : '';
            }

            return $wpdb->prepare(
                "CASE WHEN f.`kind` = 'e2e' THEN %s WHEN f.`kind` = 'voice' THEN %s WHEN f.`kind` = 'video' THEN %s WHEN f.`mime` LIKE 'image/%%' THEN %s WHEN f.`mime` LIKE 'video/%%' THEN %s WHEN f.`mime` LIKE 'audio/%%' THEN %s ELSE %s END",
                $target['encrypted'], $target['voice_messages'], $target['video_messages'], $target['images'], $target['videos'], $target['audio'], $target['documents']
            );
        }

        private function misplaced_sql()
        {
            global $wpdb;

            $storage = $this->storage();
            $objects = $storage->sdk()->table();

            return "FROM `{$storage->table()}` f INNER JOIN `{$objects}` o ON o.`id` = f.`object_id`"
                . $wpdb->prepare( ' WHERE f.`blog_id` = %d AND f.`attachment_id` IS NOT NULL', get_current_blog_id() )
                . " AND o.`state` IN ('local','failed','stored') AND o.`target` IS NULL"
                . " AND IF(o.`state` = 'stored', o.`location`, '') <> (" . $this->target_sql() . ')';
        }

        public function groups_status()
        {
            global $wpdb;

            $storage = $this->storage();
            $status  = array(
                'misplaced' => array( 'files' => 0, 'bytes' => 0 ),
                'waiting'   => 0,
                'indexed'   => (int) $storage->backfill_state()['indexed'],
                'indexDone' => $storage->backfill_done(),
            );

            if ( ! $storage->sdk() || ! $storage->table_exists() || ! $storage->sdk()->table_exists() ) {
                return $status;
            }

            $row = $wpdb->get_row( 'SELECT COUNT(*) AS `files`, COALESCE(SUM(o.`size`), 0) AS `bytes` ' . $this->misplaced_sql() );

            $status['misplaced'] = array( 'files' => (int) $row->files, 'bytes' => (int) $row->bytes );
            $status['waiting']   = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$storage->sdk()->table()}` WHERE `blog_id` = %d AND `plugin` = %s AND `target` IS NOT NULL AND `state` IN ('local','failed','stored','moving')",
                get_current_blog_id(), Better_Messages_Storage::PLUGIN
            ) );

            return $status;
        }

        public function rest_groups()
        {
            return $this->groups_status();
        }

        public function rest_apply_groups()
        {
            global $wpdb;

            $storage = $this->storage();

            if ( ! $storage->sdk() || ! $storage->table_exists() || ! $storage->sdk()->table_exists() ) {
                return $this->groups_status();
            }

            $objects = $storage->sdk()->table();

            $wpdb->query(
                "UPDATE `{$objects}` o INNER JOIN `{$storage->table()}` f ON o.`id` = f.`object_id`"
                . ' SET o.`target` = (' . $this->target_sql() . '), o.`attempts` = 0, o.`error` = NULL, o.`next_attempt_at` = ' . time() . ', o.`updated_at` = ' . time()
                . $wpdb->prepare( ' WHERE f.`blog_id` = %d AND f.`attachment_id` IS NOT NULL', get_current_blog_id() )
                . " AND o.`state` IN ('local','failed','stored') AND o.`target` IS NULL"
                . " AND IF(o.`state` = 'stored', o.`location`, '') <> (" . $this->target_sql() . ')'
            );

            $storage->sdk()->schedule( 0 );

            return $this->groups_status();
        }
    }

endif;

function Better_Messages_Storage_Admin()
{
    return Better_Messages_Storage_Admin::instance();
}
