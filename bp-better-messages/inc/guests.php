<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Guests' ) ):

    class Better_Messages_Guests
    {
        private $table;
        private $current_guest_id = false;
        private $guest_authorization_failed = false;
        private $guest_authorization_failed_reason = '';

        const REGISTRY_DB_VERSION = 2.96;

        const REGISTRY_OPTION = 'better_messages_guests_registry';

        const ADOPT_BATCH = 5000;

        const COUNTER_FLOOR = 2000000000;

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Guests();
            }

            return $instance;
        }

        public function __construct()
        {
            $this->table = bm_get_table('guests');

            $this->check_guest_auth();

            add_action('wp_enqueue_scripts', array($this, 'load_scripts'));
            add_action('rest_api_init', array($this, 'rest_api_init'));
            add_filter('better_messages_rest_is_user_authorized', array($this, 'guest_auth'), 10, 2);
            add_filter('better_messages_rest_user_item', array($this, 'guest_user_item'), 30, 3);
            add_filter('better_messages_guest_user_id', array( $this, 'guest_user_id' ) );
            add_filter('better_messages_guest_display_name', array( $this, 'guest_display_name' ), 10, 2 );

            add_filter( 'better_messages_user_config', array( $this, 'user_config'), 10 );

            add_action( 'init', array( $this, 'registry_tasks' ) );
            add_action( 'better_messages_guests_registry_job', array( $this, 'adopt_into_registry' ) );
        }

        public function guest_display_name( $retval, $user_id )
        {
            $guest_user = $this->get_guest_user( $user_id );

            if( $guest_user ){
                return $guest_user->name;
            }

            return $retval;
        }


        public function user_config( $settings ){
            $user_id = Better_Messages()->functions->get_current_user_id();

            if( $user_id >= 0 ) {
                return $settings;
            }

            $guest_user = $this->get_guest_user( $user_id );

            $options = [];

            $options[] = [
                'id'      => 'guest_display_name',
                'label'   => __('Display Name', 'bp-better-messages'),
                'value'   => $guest_user->name,
                'type'  => 'text',
                'desc'    => _x('Your display name', 'User settings', 'bp-better-messages')
            ];

            if( count( $options ) > 0 ) {
                $settings[] = [
                    'id' => 'guest_display_name',
                    'title' => _x('Guest Settings', 'User settings', 'bp-better-messages'),
                    'type' => 'settings_group',
                    'options' => $options
                ];
            }

            return $settings;
        }
        public function guest_access_enabled(){
            return Better_Messages()->settings['guestChat'] === '1';
        }

        public function chat_page_is_public_wp_page(){
            $chat_page = Better_Messages()->settings['chatPage'];
            return is_numeric( $chat_page ) && (int) $chat_page > 0;
        }

        public function guest_chat_page_id(){
            if ( ! $this->guest_access_enabled() ) return 0;
            if ( $this->chat_page_is_public_wp_page() ) return 0;
            $id = (int) Better_Messages()->settings['guestChatPage'];
            return $id > 0 ? $id : 0;
        }

        public function guest_has_entry_point(){
            if ( ! $this->guest_access_enabled() ) return false;
            if ( $this->chat_page_is_public_wp_page() ) return true;
            if ( $this->guest_chat_page_id() ) return true;
            return Better_Messages()->settings['miniChatsEnable'] === '1';
        }

        public function guest_user_id( $user_id ){
            if( is_user_logged_in() ){
                return $user_id;
            }

            if( $this->current_guest_id ){
                return -1 * abs( $this->current_guest_id );
            }

            return $user_id;
        }

        public function check_guest_auth(){
            if( ! isset( $_SERVER['HTTP_BM_GUEST_ID'] ) || ! isset( $_SERVER['HTTP_BM_GUEST_SECRET'] ) ){
                return false;
            }

            $guest_id     = intval( $_SERVER['HTTP_BM_GUEST_ID'] );
            $guest_secret = strval( $_SERVER['HTTP_BM_GUEST_SECRET'] );

            $guest = $this->get_guest_user( $guest_id );

            if( $guest ) {
                if ( ! empty( $guest_secret ) && ! empty( $guest->secret ) && $guest_secret === $guest->secret) {
                    $this->current_guest_id = $guest_id;
                    $this->update_last_active( $guest_id );
                    return true;
                } else {
                    $this->guest_authorization_failed = true;
                    $this->guest_authorization_failed_reason = 'secret_key_mismatch';

                    return false;
                }
            } else {
                $this->guest_authorization_failed = true;
                $this->guest_authorization_failed_reason = 'not_found';

                return false;
            }
        }

        public function update_last_active( int $guest_id ){
            Better_Messages()->users->update_last_activity( -1 * abs( $guest_id ) );
        }

        public function guest_auth($authorized, WP_REST_Request $request ){
            if( is_user_logged_in() ) {
                return $authorized;
            } else if( $this->current_guest_id ) {
                return true;
            } else {
                if( $this->guest_authorization_failed ){
                    $message = _x( 'Guest authorization failed', 'Rest API Error', 'bp-better-messages' );
                    return new WP_Error(
                        'guest_' . $this->guest_authorization_failed_reason,
                        $message,
                        array( 'status' => rest_authorization_required_code() )
                    );
                }

                return $authorized;
            }
        }


        public function is_guest_exists( int $user_id ){
            $user_id = abs( $user_id );

            global $wpdb;

            $query = $wpdb->prepare( "SELECT COUNT(*) FROM `" . bm_get_table('guests') . "` WHERE `id` = %d", $user_id );

            return (bool) $wpdb->get_var($query);
        }

        public function get_guest_user( int $user_id ) {
            $user_id = abs( $user_id );

            $guest_user = wp_cache_get( 'guest_user_' . $user_id, 'bm_messages' );

            if( $guest_user ){
                return $guest_user;
            }

            global $wpdb;

            $query = $wpdb->prepare( "SELECT * FROM `" . bm_get_table('guests') . "` WHERE `id` = %d AND `deleted_at` IS NULL", $user_id );

            $guest_user = $wpdb->get_row( $query );

            if( $guest_user ){
                if( empty( $guest_user->name ) ){
                    $guest_user->name = _x('Anonymous User', 'Not logged-in user', 'bp-better-messages');
                }

                wp_cache_set( 'guest_user_' . $user_id, $guest_user, 'bm_messages' );

                return $guest_user;
            } else {
                return false;
            }
        }

        public function guest_bot_id( $guest_user ){
            if( ! $guest_user || empty( $guest_user->bot_id ) ) return 0;

            return (int) $guest_user->bot_id;
        }

        public function get_bot_id( $user_id ){
            $user_id = (int) $user_id;

            if( $user_id === 0 ) return 0;

            return $this->guest_bot_id( $this->get_guest_user( $user_id ) );
        }

        public function get_all_guest_ids(){

            global $wpdb;

            $ids = (array) $wpdb->get_col( "SELECT id FROM `" . bm_get_table('guests') . "` WHERE `deleted_at` IS NULL" );

            $ids = array_map(function($guest_id): int {
                return -1 * abs( $guest_id );
            }, $ids);


            return $ids;
        }

        public function delete_guest_user( int $user_id ){
            global $wpdb;

            $guest_user_id = -1 * abs( $user_id );
            $guest = $this->get_guest_user( $user_id );

            if( $guest ){
                $wpdb->query( $wpdb->prepare( "UPDATE `" . bm_get_table('guests') . "` SET `deleted_at` = current_timestamp() WHERE `id` = %d", $guest->id ) );
                Better_Messages()->users->update_last_changed( $guest_user_id );

                wp_cache_delete( 'guest_user_' . $guest->id, 'bm_messages' );

                do_action( 'better_messages_revoke_user', $guest_user_id );

                $sql = $wpdb->prepare("
                SELECT `threads`.`id`
                FROM `" .  bm_get_table('threads') . "` `threads`
                RIGHT JOIN `" . bm_get_table('recipients') . "` `recipients`
                ON `recipients`.`thread_id` = `threads`.`id`
                    WHERE `threads`.`type` = 'chat-room'
                    AND `recipients`.`user_id` = %d", $guest_user_id);

                $chat_rooms_participant = array_map( 'intval', $wpdb->get_col( $sql ) );

                if( count( $chat_rooms_participant ) > 0 ){
                    foreach( $chat_rooms_participant as $thread_id ){
                        Better_Messages()->functions->remove_participant_from_thread( $thread_id, $guest_user_id );
                    }
                }

                do_action( 'better_messages_guest_deleted', $user_id );
                do_action( 'better_messages_user_updated', $guest_user_id );

                return true;
            }

            return false;
        }

        public function guest_user_item( $item, $user_id, $include_personal ){
            if( $user_id >= 0 ) {
                return $item;
            }

            $item['url']      = false;
            $item['canBlock'] = 0;
            $item['verified'] = 0;

            if( $include_personal && Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                $item['canVideo'] = 0;
                $item['canAudio'] = 0;
            }

            $item['avatar'] = Better_Messages()->url . 'assets/images/avatar.png';

            $guest_user = $this->get_guest_user( $user_id );

            if( $guest_user ){
                $item['name'] = sanitize_user( $guest_user->name );

                $bot_id = $this->guest_bot_id( $guest_user );

                if( $bot_id > 0 ){
                    $item['is_bot'] = 1;
                    $item['botActive'] = function_exists( 'Better_Messages_AI' ) && Better_Messages_AI()->bot_can_answer( $bot_id ) ? 1 : 0;

                    if( has_post_thumbnail( $bot_id ) ) {
                        $image_id = get_post_thumbnail_id($bot_id);

                        if ($image_id) {
                            $image_src = wp_get_attachment_image_src($image_id, [100, 100]);
                            if ($image_src) {
                                $item['avatar'] = $image_src[0];
                            }
                        }
                    }
                } else if( ! empty( $guest_user->email ) ){
                    $avatar = Better_Messages()->functions->avatar_url( get_avatar_url( $guest_user->email ) );
                    if( $avatar ){
                        $item['avatar'] = $avatar;
                    }
                }
            } else {
                $item['name'] = __('Deleted Guest', 'bp-better-messages');
                $item['is_bot']    = 0;
                $item['botActive'] = 0;
            }

            return $item;
        }

        public function rest_api_init(){
            register_rest_route( 'better-messages/v1', '/guests/register', array(
                'methods' => 'POST',
                'callback' => array( $this, 'register' ),
                'permission_callback' => function() {
                    return ! is_user_logged_in();
                },
            ) );

            register_rest_route( 'better-messages/v1', '/guests/update', array(
                'methods' => 'POST',
                'callback' => array( $this, 'update' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' )
            ) );
        }

        public function update( WP_REST_Request $request ){
            global $wpdb;

            $user_id = Better_Messages()->functions->get_current_user_id();

            if( $user_id >= 0 ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $guest_user = $this->get_guest_user( $user_id );

            if( ! $guest_user ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $data = [];

            $name = $this->clean_name( $request->get_param('name') );

            if( ! empty( $name ) ){
                $data['name'] = $name;

                if( $this->names_must_be_unique() && $name !== (string) $guest_user->name && $this->is_name_taken( $name, absint( $user_id ) ) ){
                    return new WP_Error(
                        'rest_forbidden',
                        _x( 'Sorry, such this name already used by someone else. Please try to use other name.', 'Rest API Error', 'bp-better-messages' ),
                        array( 'status' => 403 )
                    );
                }
            }

            if( count( $data ) > 0 ) {
                $result = $wpdb->update( bm_get_table('guests'), $data, [ 'id' => absint($user_id) ] );
                if ( false !== $result ) {
                    Better_Messages()->users->update_last_changed( absint($user_id) * -1 );
                    wp_cache_delete( 'guest_user_' . absint($user_id), 'bm_messages' );
                    $guest_user = $this->get_guest_user( $user_id );

                    do_action( 'better_messages_guest_updated', $user_id );
                    do_action( 'better_messages_user_updated', $user_id );
                }
            }

            $user_item = Better_Messages()->functions->rest_user_item( $user_id );

            $return = [
                'id'     => (int) $guest_user->id,
                'secret' => $guest_user->secret,
                'name'   => $guest_user->name,
                'email'  => $guest_user->email,
                'user'   => $user_item,
                'cjn'    => ( Better_Messages()->functions->get_user_meta( $user_id, 'bm_call_join_notice', true ) === 'no' ) ? '0' : '1',
                'crn'    => ( Better_Messages()->functions->get_user_meta( $user_id, 'bm_call_recording_notice', true ) === 'no' ) ? '0' : '1'
            ];

            if ( Better_Messages()->websocket ) {
                $ws_profile = Better_Messages()->functions->build_ws_profile( $user_id, $user_item );
                $return['pd']  = $ws_profile['pd'];
                $return['pdh'] = $ws_profile['pdh'];
                $return['pds'] = $ws_profile['pds'];
                $return['role_hashes']     = $ws_profile['role_hashes'];
                $return['role_hashes_sig'] = $ws_profile['role_hashes_sig'];
                $return['gk'] = Better_Messages_WebSocket()->user_socket_key( $user_id );
                $return['ak'] = Better_Messages_WebSocket()->user_socket_key( 0 );
            }

            return $return;
        }

        public function check_if_name_already_used( $name, $guest_id = 0 )
        {
            global $wpdb;
            $sql = $wpdb->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE `name` LIKE %s AND `id` != %d", $wpdb->esc_like( $name ), absint( $guest_id ) );
            return (bool) $wpdb->get_var( $sql );
        }

        public function is_name_taken( $name, $guest_id = 0 )
        {
            global $wpdb;

            $taken = $this->check_if_name_already_used( $name, $guest_id );

            if( ! $taken ){
                $taken = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$wpdb->users}` WHERE `user_login` = %s OR `display_name` = %s LIMIT 1", $name, $name ) );
            }

            return (bool) apply_filters( 'better_messages_guest_name_taken', $taken, $name, absint( $guest_id ) );
        }

        public function clean_name( $name )
        {
            $name = (string) preg_replace( '/\p{Cf}+/u', '', (string) $name );
            $name = (string) preg_replace( '/[\p{Z}\s]+/u', ' ', $name );

            return sanitize_user( trim( sanitize_text_field( $name ) ) );
        }

        public function names_must_be_unique()
        {
            return Better_Messages()->settings['guestUniqueNames'] === '1';
        }

        public function register( WP_REST_Request $request ){

            global $wpdb;

            $secret = Better_Messages()->functions->random_string( 30 );

            $registerData = $request->has_param('registerData') ? $request->get_param('registerData') : null;

            $guest_access_enabled = $this->guest_access_enabled();

            $is_allowed = apply_filters( 'better_messages_guest_register_allowed', $guest_access_enabled, $registerData );

            if( ! $is_allowed ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $returning = $this->returning_guest();

            if( $returning ){
                return $this->registration_answer( (int) $returning->id, (string) $returning->secret, (string) $returning->name, (string) $returning->email );
            }

            $name = false;

            if( $registerData ){
                if( isset( $registerData['name'] ) ){
                    $name = $this->clean_name( $registerData['name'] );

                    if( $name && $this->names_must_be_unique() && $this->is_name_taken( $name ) ){
                        return new WP_Error(
                            'rest_forbidden',
                            _x( 'Sorry, such this name already used by someone else. Please try to use other name.', 'Rest API Error', 'bp-better-messages' ),
                            array( 'status' => 403 )
                        );
                    }

                }
            }

            if( ! $name ) {
                $generator = new \BetterMessages\RandomNameGenerator\Alliteration();
                $generated_name = apply_filters('better_messages_generated_guest_name', $generator->getName());

                $name = $generated_name;
                $index = 0;

                while ( $this->check_if_name_already_used( $name ) ){
                    $index++;
                    $name = $generated_name . ' ' . $index;
                }
            }

            $email = '';

            $row = [
                'secret' => $secret,
                'name'   => sanitize_user($name),
                'email'  => $email,
                'ip'     => $this->get_client_ip(),
            ];

            if( $this->takes_registry_ids() ){
                $credentials = $this->registry_credentials();

                if( is_wp_error( $credentials ) ){
                    return $credentials;
                }

                $row['id']     = $credentials['id'];
                $row['secret'] = $secret = $credentials['secret'];
            }

            $result = $wpdb->insert( $this->table, $row );

            if( $result ) {
                $guest_id = isset( $row['id'] ) ? (int) $row['id'] : $wpdb->insert_id;

                do_action( 'better_messages_guest_registered', $guest_id );

                return $this->registration_answer( $guest_id, $secret, $name, $email );
            }

            return false;
        }

        private function registration_answer( int $guest_id, string $secret, string $name, string $email ){
            $user_item = Better_Messages()->functions->rest_user_item( -$guest_id );

            $return = [
                'id'     => $guest_id,
                'secret' => $secret,
                'name'   => $name,
                'email'  => $email,
                'user'   => $user_item,
                'cjn'    => ( Better_Messages()->functions->get_user_meta( -$guest_id, 'bm_call_join_notice', true ) === 'no' ) ? '0' : '1',
                'crn'    => ( Better_Messages()->functions->get_user_meta( -$guest_id, 'bm_call_recording_notice', true ) === 'no' ) ? '0' : '1'
            ];

            if ( Better_Messages()->websocket ) {
                $ws_profile = Better_Messages()->functions->build_ws_profile( -$guest_id, $user_item );
                $return['pd']  = $ws_profile['pd'];
                $return['pdh'] = $ws_profile['pdh'];
                $return['pds'] = $ws_profile['pds'];
                $return['role_hashes']     = $ws_profile['role_hashes'];
                $return['role_hashes_sig'] = $ws_profile['role_hashes_sig'];
                $return['gk'] = Better_Messages_WebSocket()->user_socket_key( -$guest_id );
                $return['ak'] = Better_Messages_WebSocket()->user_socket_key( 0 );
            }

            return $return;
        }

        private function returning_guest(){
            $registry = $this->takes_registry_ids() ? $this->registry() : null;
            $known    = $registry ? (int) $registry->current() : 0;

            if( $known >= 0 ){
                return null;
            }

            $token = (string) $registry->token();
            $dot   = strpos( $token, '.' );
            $guest = $this->get_guest_user( $known );

            if( false === $dot || ! $guest || $this->guest_bot_id( $guest ) > 0 || '' === (string) $guest->secret ){
                return null;
            }

            return hash_equals( (string) $guest->secret, substr( $token, $dot + 1 ) ) ? $guest : null;
        }

        public function takes_registry_ids(){
            return (float) get_option( 'better_messages_2_db_version', 0 ) >= self::REGISTRY_DB_VERSION;
        }

        public function registry(){
            $visitors = function_exists( 'wordplus_visitors' ) ? wordplus_visitors() : null;

            return ( $visitors && method_exists( $visitors, 'adopt_many' ) ) ? $visitors : null;
        }

        private function registry_credentials(){
            $registry = $this->registry();
            $failed   = new WP_Error( 'guest_registry', _x( 'Something went wrong', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 503 ) );

            if( ! $registry ){
                return $failed;
            }

            $known = (int) $registry->current();
            $token = '';

            if( $known < 0 && ! $this->is_guest_exists( $known ) ){
                $token = (string) $registry->token();
            } else {
                for( $attempt = 0; $attempt < 3 && '' === $token; $attempt++ ){
                    $made = $registry->create();

                    if( is_wp_error( $made ) ){
                        return $failed;
                    }

                    if( ! $this->is_guest_exists( (int) $made['id'] ) ){
                        $known = (int) $made['id'];
                        $token = (string) $made['token'];
                    } else {
                        $this->skip_taken_numbers( $registry, (int) $made['id'] );
                    }
                }
            }

            $dot = strpos( $token, '.' );

            if( $known >= 0 || false === $dot ){
                return $failed;
            }

            return array( 'id' => abs( $known ), 'secret' => substr( $token, $dot + 1 ) );
        }

        public function registry_bot_id(){
            $registry = $this->registry();

            for( $attempt = 0; $registry && $attempt < 3; $attempt++ ){
                $made = $registry->create( 'bot' );

                if( ! is_array( $made ) || (int) $made['id'] >= 0 ){
                    return 0;
                }

                if( ! $this->is_guest_exists( (int) $made['id'] ) ){
                    return abs( (int) $made['id'] );
                }

                $this->skip_taken_numbers( $registry, (int) $made['id'] );
            }

            return 0;
        }

        private function skip_taken_numbers( $registry, $taken_id ){
            global $wpdb;

            if( ! method_exists( $registry, 'skip_past' ) ){
                return;
            }

            $highest = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(`id`) FROM `{$this->table}` WHERE `id` >= %d AND `id` < %d",
                abs( (int) $taken_id ),
                self::COUNTER_FLOOR - 1
            ) );

            if( $highest > 0 ){
                $registry->skip_past( $highest );
            }
        }

        public function adopt_into_registry(){
            $done_to = get_option( self::REGISTRY_OPTION, '0' );

            if( 'done' === $done_to || ! $this->takes_registry_ids() ){
                return;
            }

            $registry = $this->registry();

            if( ! $registry ){
                return;
            }

            global $wpdb;

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `id`, `secret`, `bot_id` FROM `{$this->table}` WHERE `id` > %d AND `id` < %d AND `deleted_at` IS NULL ORDER BY `id` ASC LIMIT %d",
                (int) $done_to,
                (int) WordPlus_SDK_Visitors::START,
                self::ADOPT_BATCH
            ) );

            if( empty( $rows ) ){
                update_option( self::REGISTRY_OPTION, 'done', false );
                return;
            }

            $guests = array();

            foreach( $rows as $row ){
                $guests[] = array(
                    'id'     => (int) $row->id,
                    'secret' => (string) $row->secret,
                    'kind'   => (int) $row->bot_id > 0 ? 'bot' : 'visitor',
                );
            }

            $registry->adopt_many( $guests );

            if( count( $rows ) < self::ADOPT_BATCH ){
                update_option( self::REGISTRY_OPTION, 'done', false );
                return;
            }

            update_option( self::REGISTRY_OPTION, (string) (int) end( $rows )->id, false );
            Better_Messages_Worker()->run_soon( 'better_messages_guests_registry_job' );
        }

        public function registry_tasks(){
            if( function_exists( 'Better_Messages_Worker' ) ){
                Better_Messages_Worker()->add_task( 'better_messages_guests_registry_job', 5 * MINUTE_IN_SECONDS );
            }
        }

        public function load_scripts()
        {
            if( is_user_logged_in() ) return;

        }

        public function html_element(){
            if( is_user_logged_in() ) return;
            echo '<div id="bm-support-chat-container"></div>';
        }

        public function register_new_guest(){

        }

        public function get_guest_meta( $guest_id, $meta_key = false ){
            global $wpdb;

            $guest_id = abs( $guest_id );

            $meta = $wpdb->get_var($wpdb->prepare( "SELECT meta FROM {$this->table} WHERE id = %d", $guest_id ));

            $result = [];
            if( $meta ){
                try{
                    $result = json_decode( $meta, true );
                } catch ( Exception $exception ){}
            }

            if( $meta_key ) {
                return $result[$meta_key] ?? null;
            }

            return $result;
        }

        public function update_guest_meta( $guest_id, $meta_key, $meta_value ){
            global $wpdb;
            $guest_id = abs( $guest_id );
            $meta = $this->get_guest_meta( $guest_id );

            if( isset( $meta[$meta_key] ) && $meta[$meta_key] === $meta_value ){
                return true;
            }

            $meta[$meta_key] = $meta_value;

            $result = $wpdb->update( $this->table, ['meta' => json_encode( $meta ) ], [ 'id' => $guest_id ], ['%s'], ['%d'] );

            if( $result ){
                if( Better_Messages()->websocket ){
                    Better_Messages()->websocket->on_usermeta_update(0, $guest_id  * -1, $meta_key, $meta_value);
                }
            }

            return $result;
        }

        public function delete_guest_meta( $guest_id, $meta_key ){
            global $wpdb;
            $guest_id = abs( $guest_id );
            $meta = $this->get_guest_meta( $guest_id );

            if( isset( $meta[$meta_key] ) ){
                unset( $meta[$meta_key] );
                $wpdb->update( $this->table, ['meta' => json_encode( $meta ) ], [ 'id' => $guest_id ], ['%s'], ['%d'] );
            }

            return true;
        }

        public function get_client_ip(){
            $sources = [ 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];

            foreach( $sources as $source ){
                if( empty( $_SERVER[ $source ] ) ) continue;

                $ip = trim( (string) $_SERVER[ $source ] );

                if( str_contains( $ip, ',' ) ) continue;

                if( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ){
                    return $ip;
                }
            }

            return '';
        }
    }

endif;

function Better_Messages_Guests(){
    return Better_Messages_Guests::instance();
}
