<?php
if ( !class_exists( 'Better_Messages_Rest_Api_Admin' ) ):

    class Better_Messages_Rest_Api_Admin
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Rest_Api_Admin();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
            add_action( 'wp_ajax_better_messages_admin_save_settings', array( $this, 'save_settings' ) );
        }

        public function user_can_admin(){
            return current_user_can('bm_can_administrate');
        }

        public function user_is_admin(){
            return current_user_can('manage_options');
        }

        public function rest_api_init(){
            register_rest_route('better-messages/v1/admin', '/settings/save', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rest_save_settings'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/settings/get', array(
                'methods'             => 'GET',
                'callback'            => array($this, 'rest_get_settings'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/settings/createPage', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rest_create_messages_page'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));
            register_rest_route('better-messages/v1/admin', '/getMessages', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_messages'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            /* register_rest_route('better-messages/v1/admin', '/getThreads', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_threads'),
                'permission_callback' => array($this, 'user_is_admin'),
            )); */

            register_rest_route('better-messages/v1/admin', '/searchSenders', array(
                'methods' => 'GET',
                'callback' => array($this, 'search_senders'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/searchUsers', array(
                'methods' => 'GET',
                'callback' => array($this, 'search_users'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/getUsersByIds', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_users_by_ids'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/searchBots', array(
                'methods' => 'GET',
                'callback' => array($this, 'search_bots'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/getBotsByIds', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_bots_by_ids'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/searchChatRooms', array(
                'methods' => 'GET',
                'callback' => array($this, 'search_chat_rooms'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/getChatRoomsByIds', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_chat_rooms_by_ids'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/getGuests', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_guests'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/deleteMessages', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'delete_messages'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/deleteAccount', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'deleteAccount'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/deleteAccountMessages', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'deleteAccountMessages'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/approveMessage', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'approveMessage'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/whitelistUser', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'whitelist_user'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/unwhitelistUser', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'unwhitelist_user'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/getWhitelistedUsers', array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_whitelisted_users'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/blacklistUser', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'blacklist_user'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/unblacklistUser', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'unblacklist_user'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/getBlacklistedUsers', array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_blacklisted_users'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/getUser', array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_user'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/getGuest', array(
                'methods'             => 'GET',
                'callback'            => array($this, 'get_guest'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/whitelistUserAndApproveAllMessages', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'whitelist_user_and_approve_all_messages'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/blacklistUserAndDeleteAllPendingMessages', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'blacklist_user_and_delete_all_pending_messages'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/dismissAiFlag', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'dismiss_ai_flag'),
                'permission_callback' => array($this, 'user_can_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/tools/sync-user-index', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rest_sync_user_index'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/tools/convert-utf8mb4', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rest_convert_utf8mb4'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/tools/reset-database', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rest_reset_database'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin', '/tools/import-settings', array(
                'methods'             => 'POST',
                'callback'            => array($this, 'rest_import_settings'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

        }

        public function get_user( WP_REST_Request $request ){
            $user_id = (int) $request->get_param('userId');

            if( ! $user_id ){
                return new WP_Error( 'invalid_user', 'Invalid user ID', array( 'status' => 400 ) );
            }

            global $wpdb;

            $messages_count = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM `" . bm_get_table('messages') . "`
                WHERE `sender_id` = %d", $user_id ));

            $conversations_count = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM `" . bm_get_table('recipients') . "`
                WHERE `user_id` = %d", $user_id ));

            $moderation_status = Better_Messages()->moderation->get_user_moderation_status( $user_id );
            $is_admin = user_can( $user_id, 'bm_can_administrate' );

            $user_item = Better_Messages()->functions->rest_user_item( $user_id );
            $user_item['messagesCount']      = $messages_count;
            $user_item['conversationsCount'] = $conversations_count;
            $user_item['moderationStatus']   = $moderation_status;
            $user_item['isAdmin']            = $is_admin;

            return $user_item;
        }

        public function get_guest( WP_REST_Request $request ){
            $guest_id = (int) $request->get_param('guestId');

            if( ! $guest_id ){
                return new WP_Error( 'invalid_guest', 'Invalid guest ID', array( 'status' => 400 ) );
            }

            global $wpdb;

            // Get guest data from guests table
            $guest = $wpdb->get_row( $wpdb->prepare("
                SELECT id, name, email, ip, created_at
                FROM `" . bm_get_table('guests') . "`
                WHERE `id` = %d
                AND `deleted_at` IS NULL
            ", $guest_id ), ARRAY_A );

            if( ! $guest ){
                return new WP_Error( 'guest_not_found', 'Guest not found', array( 'status' => 404 ) );
            }

            // Guest user ID is negative
            $guest_user_id = -1 * abs($guest_id);

            $messages_count = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM `" . bm_get_table('messages') . "`
                WHERE `sender_id` = %d", $guest_user_id ));

            $conversations_count = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM `" . bm_get_table('recipients') . "`
                WHERE `user_id` = %d", $guest_user_id ));

            $user_item = Better_Messages()->functions->rest_user_item( $guest_user_id );
            $user_item['id']             = abs( $user_item['id'] );
            $user_item['email']          = $guest['email'];
            $user_item['ip']             = $guest['ip'];
            $user_item['createdAt']      = $guest['created_at'];
            $user_item['messages']       = $messages_count;
            $user_item['conversations']  = $conversations_count;
            $user_item['isWhitelisted']  = Better_Messages_Moderation()->is_user_whitelisted( $guest_user_id );
            $user_item['isBlacklisted']  = Better_Messages_Moderation()->is_user_blacklisted( $guest_user_id );

            return $user_item;
        }

        public function save_settings()
        {
            $nonce    = $_POST['nonce'];

            if ( ! wp_verify_nonce($nonce, 'bm-save-settings') ){
                exit;
            }

            if( ! current_user_can('manage_options') ){
                exit;
            }

            $data = json_decode( wp_unslash($_POST['data']), true );

            unset( $data['_wpnonce'], $data['_wp_http_referer'] );

            Better_Messages_Options::instance()->update_settings( $data );

            wp_send_json_success();
        }

        public function deleteAccount( WP_REST_Request $request ){
            $user_ids = (array) $request->get_param('userIds');

            if( count( $user_ids ) === 0 ){
                return false;
            }

            foreach ( $user_ids as $user_id ){
                Better_Messages()->guests->delete_guest_user( $user_id );
            }
        }

        public function approveMessage( WP_REST_Request $request )
        {
            $message_id = (int) $request->get_param('messageId');

            $message = Better_Messages()->functions->get_message( $message_id );

            if( ! $message ){
                return new WP_Error( 'message_not_found', 'Message not found', array( 'status' => 404 ) );
            }

            $result = Better_Messages()->moderation->approve_message( $message_id );

            return $result;
        }

        public function deleteAccountMessages( WP_REST_Request $request ){
            $user_ids = $request->get_param('userIds');
            //var_dump( $user_ids );
        }

        public function get_guests( WP_REST_Request $request ){
            global $wpdb;

            $page   = ( $request->has_param('page') ) ? intval( $request->get_param('page') ) : 1;

            $search = ( $request->has_param('search') ) ? sanitize_text_field( $request->get_param('search') ) : "";

            $search_sql = "";

            if( $search ){
                $search_sql = $wpdb->prepare("
                    AND( `guests`.`name` LIKE %s
                    OR `guests`.`email` LIKE %s
                    OR `guests`.`ip` LIKE %s )
                ", "%" . $search . "%", "%" . $search . "%", "%" . $search . "%" );
            }

            $per_page = 20;

            $offset = 0;

            if( $page > 1 ){
                $offset = ( $page - 1 ) * $per_page;
            }

            $count = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) 
                FROM `" . bm_get_table('guests') . "` `guests`
                WHERE `deleted_at` IS NULL
                AND `ip` NOT LIKE 'ai-chat-bot-%'
                $search_sql
            "));

            $user_ids = $wpdb->get_results( $wpdb->prepare("
                SELECT id, email, ip, created_at,
                (SELECT COUNT(*) 
                  FROM `" . bm_get_table('messages') . "` 
                 WHERE `sender_id` = (-1 * `guests`.`id`) ) messages,
                (SELECT COUNT(*) 
                  FROM `" . bm_get_table('recipients') . "` 
                 WHERE `user_id` = (-1 * `guests`.`id` )) participants
                FROM `" . bm_get_table('guests') . "` `guests`
                WHERE `deleted_at` IS NULL
                AND `ip` NOT LIKE 'ai-chat-bot-%'
                $search_sql
                ORDER BY id ASC
                LIMIT {$offset}, {$per_page}
            "), ARRAY_A );

            $return = [
                'total'    => $count,
                'page'     => $page,
                'perPage'  => $per_page,
                'pages'    => ceil( $count / $per_page ),
                'users' => []
            ];

            foreach( $user_ids as $user ){
                $guest_user_id = -1 * abs($user['id']);
                $user_item = Better_Messages()->functions->rest_user_item( $guest_user_id );
                $user_item['id']            = abs( $user_item['id'] );
                $user_item['email']         = $user['email'];
                $user_item['ip']            = $user['ip'];
                $user_item['createdAt']      = $user['created_at'];
                $user_item['messages']      = $user['messages'];
                $user_item['conversations'] = $user['participants'];
                $user_item['isWhitelisted']  = Better_Messages_Moderation()->is_user_whitelisted( $guest_user_id );
                $user_item['isBlacklisted']  = Better_Messages_Moderation()->is_user_blacklisted( $guest_user_id );

                $return['users'][] = $user_item;
            }

            return $return;

        }

        public function get_users( WP_REST_Request $request ){
            /*global $wpdb;

            $page = ( $request->has_param('page') ) ? intval( $request->get_param('page') ) : 1;

            $search = ( $request->has_param('search') ) ? sanitize_text_field( $request->get_param('search') ) : "";

            $search_sql = "";

            if( $search ){
                $search_sql = $wpdb->prepare("
                    AND (
                        ID = %s
                        OR `user_nicename` LIKE %s
                        OR `display_name` LIKE %s
                        OR `ID` IN (
                            SELECT user_id
                            FROM `{$wpdb->usermeta}`
                            WHERE `meta_key` IN ( 'nickname', 'first_name', 'last_name' )
                            AND `meta_value` LIKE %s
                        )
                    )
                ", "%" . $search . "%", "%" . $search . "%", "%" . $search . "%", "%" . $search . "%" );
            }

            $per_page = 20;

            $offset = 0;

            if( $page > 1 ){
                $offset = ( $page - 1 ) * $per_page;
            }

            $count = (int) $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*)
                FROM `{$wpdb->users}` `users`
                WHERE 1 = 1
                {$search_sql}
            "));

            $user_ids = $wpdb->get_results( $wpdb->prepare("
                SELECT ID,
                (SELECT COUNT(*)
                  FROM `" . bm_get_table('messages') . "`
                 WHERE `sender_id` = `users`.`ID`) messages,
                (SELECT COUNT(*)
                  FROM `" . bm_get_table('recipients') . "`
                 WHERE `user_id` = `users`.`ID`) participants
                FROM `{$wpdb->users}` `users`
                WHERE 1 = 1
                {$search_sql}
                ORDER BY ID ASC
                LIMIT {$offset}, {$per_page}
            "), ARRAY_A );

            $return = [
                'total'    => $count,
                'page'     => $page,
                'perPage'  => $per_page,
                'pages'    => ceil( $count / $per_page ),
                'users' => []
            ];

            foreach( $user_ids as $user ){
                $user_item = Better_Messages()->functions->rest_user_item( $user['ID'] );
                $user_item['messages']      = $user['messages'];
                $user_item['conversations'] = $user['participants'];

                $return['users'][] = $user_item;
            }

            return $return;
            */

            return [];
        }

        public function delete_messages( WP_REST_Request $request ){
            set_time_limit(0);

            $messageIds = $request->get_param('messageIds');

            if( ! is_array( $messageIds ) ) return false;

            $messageIds = array_map( 'intval', $messageIds );

            foreach ( $messageIds as $messageId ) {
                Better_Messages()->functions->delete_message( $messageId, false, true, 'delete' );
            }

            return true;
        }

        public function search_users( WP_REST_Request $request )
        {
            global $wpdb;

            $search   = (string) $request->get_param('search');
            $paginated_mode = ( $request->get_param( 'page' ) !== null || $request->get_param( 'per_page' ) !== null );

            $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
            $per_page = (int) $request->get_param( 'per_page' );
            if ( $per_page <= 0 || $per_page > 100 ) $per_page = $paginated_mode ? 20 : 10;

            if( empty( $search ) && ! $paginated_mode ) {
                return [];
            }

            $where_sql = '';
            $where_args = array();

            if ( $search !== '' ) {
                $search_like = '%' . $wpdb->esc_like( $search ) . '%';
                $where_sql = "WHERE ( `bm_users`.`ID` = %s
                OR `bm_users`.`user_nicename` LIKE %s
                OR `bm_users`.`display_name` LIKE %s
                OR `bm_users`.`first_name` LIKE %s
                OR `bm_users`.`last_name` LIKE %s
                OR `bm_users`.`nickname` LIKE %s
                OR `wp_users`.`user_email` LIKE %s )";
                $where_args = array( $search, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like );
            }

            $offset = ( $page - 1 ) * $per_page;
            $sql = $wpdb->prepare(
                "SELECT `bm_users`.`ID` FROM `" . bm_get_table('users') . "` `bm_users`
                LEFT JOIN `{$wpdb->users}` `wp_users` ON `wp_users`.`ID` = `bm_users`.`ID`
                {$where_sql}
                ORDER BY `bm_users`.`display_name` ASC
                LIMIT %d, %d",
                array_merge( $where_args, array( $offset, $per_page + 1 ) )
            );

            $search_results = $wpdb->get_col( $sql );

            $has_more = count( $search_results ) > $per_page;
            if ( $has_more ) {
                $search_results = array_slice( $search_results, 0, $per_page );
            }

            $return = [];
            foreach( $search_results as $user_id ){
                $return[] = Better_Messages()->functions->rest_user_item( $user_id );
            }

            if ( ! $paginated_mode ) {
                return $return;
            }

            return array(
                'items'   => $return,
                'page'    => $page,
                'hasMore' => $has_more,
            );
        }

        public function get_users_by_ids( WP_REST_Request $request ){
            $ids = (array) $request->get_param( 'ids' );
            $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
            if ( empty( $ids ) ) return array();

            $return = array();
            foreach ( $ids as $user_id ) {
                $item = Better_Messages()->functions->rest_user_item( $user_id );
                if ( $item ) $return[] = $item;
            }
            return $return;
        }

        public function search_bots( WP_REST_Request $request ){
            if ( ! post_type_exists( 'bm-ai-chat-bot' ) ) {
                return array( 'items' => array(), 'page' => 1, 'hasMore' => false );
            }

            $search   = (string) $request->get_param( 'search' );
            $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
            $per_page = (int) $request->get_param( 'per_page' );
            if ( $per_page <= 0 || $per_page > 100 ) $per_page = 20;

            $args = array(
                'post_type'        => 'bm-ai-chat-bot',
                'post_status'      => 'publish',
                'posts_per_page'   => $per_page + 1,
                'paged'            => $page,
                'orderby'          => 'title',
                'order'            => 'ASC',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            );
            if ( $search !== '' ) $args['s'] = $search;

            $posts    = get_posts( $args );
            $has_more = count( $posts ) > $per_page;
            if ( $has_more ) $posts = array_slice( $posts, 0, $per_page );

            $ai_instance = class_exists( 'Better_Messages_AI' ) ? Better_Messages_AI::instance() : null;
            $items = array();
            foreach ( $posts as $post ) {
                $enabled = false;
                if ( $ai_instance ) {
                    $bot_settings = $ai_instance->get_bot_settings( $post->ID );
                    $enabled      = isset( $bot_settings['enabled'] ) && $bot_settings['enabled'] === '1';
                }
                $items[] = array(
                    'value'      => (int) $post->ID,
                    'label'      => $post->post_title !== '' ? $post->post_title : sprintf( __( 'Bot #%d', 'bp-better-messages' ), (int) $post->ID ),
                    'muted'      => ! $enabled,
                    'mutedLabel' => ! $enabled ? _x( 'disabled', 'Admin settings bot picker', 'bp-better-messages' ) : null,
                );
            }

            return array( 'items' => $items, 'page' => $page, 'hasMore' => $has_more );
        }

        public function get_bots_by_ids( WP_REST_Request $request ){
            if ( ! post_type_exists( 'bm-ai-chat-bot' ) ) return array();
            $ids = (array) $request->get_param( 'ids' );
            $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
            if ( empty( $ids ) ) return array();

            $posts = get_posts( array(
                'post_type'        => 'bm-ai-chat-bot',
                'post_status'      => 'any',
                'posts_per_page'   => count( $ids ),
                'post__in'         => $ids,
                'orderby'          => 'post__in',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            ) );

            $ai_instance = class_exists( 'Better_Messages_AI' ) ? Better_Messages_AI::instance() : null;
            $items = array();
            foreach ( $posts as $post ) {
                $enabled = false;
                if ( $ai_instance ) {
                    $bot_settings = $ai_instance->get_bot_settings( $post->ID );
                    $enabled      = isset( $bot_settings['enabled'] ) && $bot_settings['enabled'] === '1';
                }
                $items[] = array(
                    'value'      => (int) $post->ID,
                    'label'      => $post->post_title !== '' ? $post->post_title : sprintf( __( 'Bot #%d', 'bp-better-messages' ), (int) $post->ID ),
                    'muted'      => ! $enabled,
                    'mutedLabel' => ! $enabled ? _x( 'disabled', 'Admin settings bot picker', 'bp-better-messages' ) : null,
                );
            }
            return $items;
        }

        public function search_chat_rooms( WP_REST_Request $request ){
            if ( ! post_type_exists( 'bpbm-chat' ) ) {
                return array( 'items' => array(), 'page' => 1, 'hasMore' => false );
            }

            $search   = (string) $request->get_param( 'search' );
            $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
            $per_page = (int) $request->get_param( 'per_page' );
            if ( $per_page <= 0 || $per_page > 100 ) $per_page = 20;

            $args = array(
                'post_type'        => 'bpbm-chat',
                'post_status'      => 'publish',
                'posts_per_page'   => $per_page + 1,
                'paged'            => $page,
                'orderby'          => 'title',
                'order'            => 'ASC',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            );
            if ( $search !== '' ) $args['s'] = $search;

            $posts    = get_posts( $args );
            $has_more = count( $posts ) > $per_page;
            if ( $has_more ) $posts = array_slice( $posts, 0, $per_page );

            $items = array();
            foreach ( $posts as $post ) {
                $items[] = $this->format_chat_room_picker_item( $post );
            }
            return array( 'items' => $items, 'page' => $page, 'hasMore' => $has_more );
        }

        public function get_chat_rooms_by_ids( WP_REST_Request $request ){
            if ( ! post_type_exists( 'bpbm-chat' ) ) return array();
            $ids = (array) $request->get_param( 'ids' );
            $ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
            if ( empty( $ids ) ) return array();

            $posts = get_posts( array(
                'post_type'        => 'bpbm-chat',
                'post_status'      => 'any',
                'posts_per_page'   => count( $ids ),
                'post__in'         => $ids,
                'orderby'          => 'post__in',
                'no_found_rows'    => true,
                'suppress_filters' => true,
            ) );

            $items = array();
            foreach ( $posts as $post ) {
                $items[] = $this->format_chat_room_picker_item( $post );
            }
            return $items;
        }

        private function format_chat_room_picker_item( $post ){
            $avatar = '';
            if ( has_post_thumbnail( $post->ID ) ) {
                $thumb_id = (int) get_post_thumbnail_id( $post->ID );
                if ( $thumb_id ) {
                    $src = wp_get_attachment_image_src( $thumb_id, array( 40, 40 ) );
                    if ( $src ) $avatar = $src[0];
                }
            }
            return array(
                'value'  => (int) $post->ID,
                'label'  => $post->post_title !== '' ? $post->post_title : sprintf( __( 'Chat Room #%d', 'bp-better-messages' ), (int) $post->ID ),
                'avatar' => $avatar,
            );
        }

        public function search_senders( WP_REST_Request $request ){
            global $wpdb;

            $search = $request->get_param('search');

            if( empty( $search ) ) {
                return [];
            }

            $sql = $wpdb->prepare("
            SELECT ID FROM `" . bm_get_table('users') . "`
            WHERE ID IN (SELECT sender_id FROM `" . bm_get_table('messages') . "` GROUP BY sender_id)
            AND (
                ID = %s
                OR `user_nicename` LIKE %s
                OR `display_name` LIKE %s 
                OR `first_name` LIKE %s
                OR `last_name` LIKE %s
                OR `nickname` LIKE %s
            )
            LIMIT 0, 10", $search, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');

            $search_results = $wpdb->get_col( $sql );

            $return = [];

            foreach( $search_results as $user_id ){
                $return[] = Better_Messages()->functions->rest_user_item( $user_id );
            }

            return $return;
        }

        public function get_messages( WP_REST_Request $request ){
            global $wpdb;

            $page = ( $request->has_param('page') ) ? intval( $request->get_param('page') ) : 1;

            $per_page = 20;

            $offset = 0;

            if( $page > 1 ){
                $offset = ( $page - 1 ) * $per_page;
            }

            $sender_id = $request->has_param('sender_id') ?  intval($request->get_param('sender_id' )) : false;
            $search = $request->has_param('search') ?  sanitize_text_field( $request->get_param('search' )) : false;
            $thread_id = $request->has_param('thread_id') ?  intval($request->get_param('thread_id' )) : false;

            $message_ids = $request->has_param('message_ids') ?  $request->get_param('message_ids' ) : false;

            $only_reported    = $request->has_param('reported') && intval($request->get_param('reported')) === 1;
            $only_pending     = $request->has_param('pending') && intval($request->get_param('pending')) === 1;

            $sender_sql = $search_sql = $thread_sql = '';

            if( $sender_id ) {
                $sender_sql = $wpdb->prepare('AND `sender_id` = %d', $sender_id);
            }

            if( $search ){
                $search_sql = $wpdb->prepare('AND `message` LIKE %s', '%'. $search . '%');
            }

            if( $thread_id ){
                $thread_sql = $wpdb->prepare('AND `thread_id` = %d', $thread_id);
            }

            $count = (int) $wpdb->get_var( "
            SELECT COUNT(*) 
            FROM `" . bm_get_table('messages') . "`
            WHERE `created_at` > 0
            AND `message` != '<!-- BBPM START THREAD -->'
            $sender_sql $search_sql $thread_sql");

            if( $message_ids ){
                $message_ids = array_map( 'intval', $message_ids );

                $message_ids = implode( ',', $message_ids );

                $sql = "SELECT `messages`.*,
                `user_reports_meta`.`meta_value` as user_reports,
                (SELECT COUNT(*)  FROM `" . bm_get_table('recipients') . "` WHERE `thread_id` = `messages`.`thread_id`) participants
                FROM `" . bm_get_table('messages') . "` `messages`
                LEFT JOIN `" . bm_get_table('meta') . "` `user_reports_meta`
                    ON `messages`.`id` = `user_reports_meta`.`bm_message_id`
                    AND `user_reports_meta`.`meta_key` = 'user_reports'
                WHERE `created_at` > 0
                    AND `message` != '<!-- BBPM START THREAD -->'
                    AND `id` IN ({$message_ids})";
            } else if( $only_reported ) {
                $meta_table     = bm_get_table('meta');
                $messages_table = bm_get_table('messages');

                // Invert the join direction: narrow via the indexed meta table first, then filter
                // messages by PRIMARY KEY. The prior LEFT JOIN + OR pattern went full-scan at 6M+ rows.
                $candidates          = $this->get_reported_candidate_ids();
                $candidate_sql       = empty( $candidates['all_ids'] ) ? '0' : implode( ',', $candidates['all_ids'] );
                $user_reports_in_sql = empty( $candidates['user_report_ids'] ) ? '0' : implode( ',', $candidates['user_report_ids'] );

                $where = "`messages`.`id` IN ({$candidate_sql})
                    AND `messages`.`created_at` > 0
                    AND `messages`.`message` != '<!-- BBPM START THREAD -->'
                    AND ( `messages`.`id` IN ({$user_reports_in_sql}) OR `messages`.`is_pending` = 0 )
                    $sender_sql $search_sql $thread_sql";

                $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$messages_table}` `messages` WHERE $where" );

                $sql = "SELECT `messages`.*,
                `user_reports_meta`.`meta_value` as user_reports,
                (SELECT COUNT(*)  FROM `" . bm_get_table('recipients') . "` WHERE `thread_id` = `messages`.`thread_id`) participants
                FROM `{$messages_table}` `messages`
                LEFT JOIN `{$meta_table}` `user_reports_meta`
                    ON `messages`.`id` = `user_reports_meta`.`bm_message_id`
                    AND `user_reports_meta`.`meta_key` = 'user_reports'
                WHERE $where
                ORDER BY `messages`.`created_at` DESC
                LIMIT {$offset}, {$per_page}";
            } else if( $only_pending ) {
                $count = (int) $wpdb->get_var( "
                SELECT COUNT(*)
                FROM `" . bm_get_table('messages') . "`
                WHERE `created_at` > 0
                AND `message` != '<!-- BBPM START THREAD -->'
                AND `is_pending` = 1
                $sender_sql $search_sql $thread_sql");

                $sql = "SELECT `messages`.*,
                `user_reports_meta`.`meta_value` as user_reports,
                (SELECT COUNT(*)  FROM `" . bm_get_table('recipients') . "` WHERE `thread_id` = `messages`.`thread_id`) participants
                FROM `" . bm_get_table('messages') . "` `messages`
                LEFT JOIN `" . bm_get_table('meta') . "` `user_reports_meta`
                    ON `messages`.`id` = `user_reports_meta`.`bm_message_id`
                    AND `user_reports_meta`.`meta_key` = 'user_reports'
                WHERE `created_at` > 0
                    AND `message` != '<!-- BBPM START THREAD -->'
                    AND `is_pending` = 1
                $sender_sql $search_sql $thread_sql
                ORDER BY `created_at` DESC
                LIMIT {$offset}, {$per_page}";
            } else {
                $sql = "SELECT `messages`.*,
                `user_reports_meta`.`meta_value` as user_reports,
                (SELECT COUNT(*)  FROM `" . bm_get_table('recipients') . "` WHERE `thread_id` = `messages`.`thread_id`) participants
                FROM `" . bm_get_table('messages') . "` `messages`
                LEFT JOIN `" . bm_get_table('meta') . "` `user_reports_meta`
                    ON `messages`.`id` = `user_reports_meta`.`bm_message_id`
                    AND `user_reports_meta`.`meta_key` = 'user_reports'
                WHERE `created_at` > 0
                    AND `message` != '<!-- BBPM START THREAD -->'
                $sender_sql $search_sql $thread_sql
                ORDER BY `created_at` DESC
                LIMIT {$offset}, {$per_page}";
            }

            $messages = $wpdb->get_results( $sql, ARRAY_A );

            $return = [
                'total'    => $count,
                'page'     => $page,
                'perPage'  => $per_page,
                'pages'    => ceil( $count / $per_page ),
                'messages' => [],
            ];

            $return['reported'] = $this->get_reported_count();
            $return['pending'] = Better_Messages()->functions->get_pending_messages_count();

            if( count( $messages ) > 0 ) {
                foreach ($messages as $i => $message) {
                    $view_link = Better_Messages()->functions->add_hash_arg('conversation/' . $message['thread_id'], [
                        'scrollToContainer' => ''
                    ], Better_Messages()->functions->get_link() );

                    $content = $message['message'];

                    // System messages: convert to readable text for admin
                    if ( strpos( $content, '<!-- BM-SYSTEM-MESSAGE:' ) === 0 ) {
                        preg_match( '/<!-- BM-SYSTEM-MESSAGE:(\w+)/', $content, $type_match );
                        $sys_type = isset( $type_match[1] ) ? $type_match[1] : '';
                        switch ( $sys_type ) {
                            case 'user_joined':
                                $sys_data = Better_Messages()->functions->get_message_meta( $message['id'], 'system_data', true );
                                $sys_name = is_array( $sys_data ) && isset( $sys_data['user_name'] ) ? esc_html( $sys_data['user_name'] ) : __( 'A user', 'bp-better-messages' );
                                $content = '<em>' . sprintf( __( '%s joined the conversation', 'bp-better-messages' ), $sys_name ) . '</em>';
                                break;
                            case 'user_left':
                                $sys_data = Better_Messages()->functions->get_message_meta( $message['id'], 'system_data', true );
                                $sys_name = is_array( $sys_data ) && isset( $sys_data['user_name'] ) ? esc_html( $sys_data['user_name'] ) : __( 'A user', 'bp-better-messages' );
                                $content = '<em>' . sprintf( __( '%s left the conversation', 'bp-better-messages' ), $sys_name ) . '</em>';
                                break;
                            default:
                                // Allow addons to handle additional system message types
                                $content = apply_filters( 'better_messages_admin_system_message', '<em>' . _x( 'System message', 'WP Admin', 'bp-better-messages' ) . '</em>', $sys_type, $message );
                                break;
                        }
                    }

                    if( $content === '<!-- BPBM-VOICE-MESSAGE -->' ){
                        $content = __('Voice Message', 'bp-better-messages');

                        $attachment_id = Better_Messages()->functions->get_message_meta( $message['id'], 'bpbm_voice_messages', true );

                        $attachment_url = wp_get_attachment_url( $attachment_id );
                        if( $attachment_url ) {
                            $content .= '<div><ul>';
                            $content .= '<li><a target="_blank" href="' . $attachment_url . '">' . $attachment_url . '</a></li>';
                            $content .= '</ul></div>';
                        }
                    }

                    $attachments = Better_Messages()->functions->get_message_meta( $message['id'], 'attachments', true );

                    if( is_array($attachments) && count( $attachments ) > 0 ){
                        $content .= '<div>';
                        $content .= sprintf( _x( 'This message contains %s attachment(s):', 'WP Admin', 'bp-better-messages' ), count( $attachments ) );

                        $content .= '<ul>';
                        foreach ( $attachments as $id => $attachment ){
                            $content .= '<li><a target="_blank" href="' . $attachment . '">' . $attachment . '</a></li>';
                        }
                        $content .= '</ul>';
                        $content .= '</div>';
                    }

                    $participants_count = (int) $message['participants'];

                    $item = [
                        'id'           => $message['id'],
                        'sender'       => ( (int) $message['sender_id'] === 0 )
                            ? [
                                'id'      => '0',
                                'user_id' => 0,
                                'name'    => _x( 'System', 'WP Admin', 'bp-better-messages' ),
                                'avatar'  => Better_Messages()->url . 'assets/images/avatar.png',
                                'url'     => false,
                            ]
                            : Better_Messages()->functions->rest_user_item( $message['sender_id'] ),
                        'thread_id'    => $message['thread_id'],
                        'message'      => $content,
                        'time'         => $message['date_sent'],
                        'view_link'    => $view_link,
                        'participants' => $participants_count
                    ];

                    // Allow addons (E2E) to modify the moderation item (e.g. replace encrypted content)
                    $item = apply_filters( 'better_messages_admin_moderation_item', $item, $message );

                    if( $participants_count === 2 ){
                         $recipients = Better_Messages()->functions->get_recipients( $message['thread_id'] );

                         if( count($recipients) === 2 ) {
                             $receivers = array_filter($recipients, function ($item) use ($message) {
                                 return (int) $item->user_id !== (int) $message['sender_id'];
                             });

                             $item['receiver'] = Better_Messages()->functions->rest_user_item( reset($receivers)->user_id );
                         }
                    }

                    $is_pending = $message['is_pending'] !== '0';

                    if( class_exists('Better_Messages_User_Reports') ){
                        $reports = maybe_unserialize( $message['user_reports'] );

                        if( is_array( $reports ) && count( $reports ) > 0 ){
                            $categories = Better_Messages_User_Reports::instance()->get_categories( (int) $message['id'], (int) $message['thread_id'] );

                            foreach ( $reports as $user_id => $report ){
                                $reports[ $user_id ]['user'] = Better_Messages()->functions->rest_user_item( $user_id );
                                $reports[ $user_id ]['category'] = $categories[$report['category']] ?? $report['category'];
                            }

                            $item['reports'] = $reports;
                        }
                    }

                    if( $is_pending ){
                        $item['is_pending'] = true;
                    }

                    // Add whitelist/blacklist status for sender (global and thread-specific)
                    $sender_id = (int) $message['sender_id'];
                    $thread_id = (int) $message['thread_id'];
                    if( $sender_id !== 0 ){
                        // Global status (works for both regular users and guests with negative IDs)
                        $item['sender_whitelisted'] = Better_Messages()->moderation->is_user_whitelisted( $sender_id );
                        $item['sender_blacklisted'] = Better_Messages()->moderation->is_user_blacklisted( $sender_id );
                        // Thread-specific status
                        if( $thread_id > 0 ){
                            $item['sender_thread_whitelisted'] = Better_Messages()->moderation->is_user_whitelisted( $sender_id, $thread_id );
                            $item['sender_thread_blacklisted'] = Better_Messages()->moderation->is_user_blacklisted( $sender_id, $thread_id );
                        }
                    }

                    // Add AI moderation data
                    $ai_flagged = Better_Messages()->functions->get_message_meta( $message['id'], 'ai_moderation_flagged', true );
                    if( $ai_flagged === '1' ){
                        $item['ai_moderation_flagged'] = true;
                        $ai_categories = Better_Messages()->functions->get_message_meta( $message['id'], 'ai_moderation_categories', true );
                        $item['ai_moderation_categories'] = $ai_categories ? json_decode( $ai_categories, true ) : [];
                        $ai_result = Better_Messages()->functions->get_message_meta( $message['id'], 'ai_moderation_result', true );
                        $result_data = $ai_result ? json_decode( $ai_result, true ) : [];
                        $item['ai_moderation_scores'] = isset( $result_data['category_scores'] ) ? $result_data['category_scores'] : [];

                        $ai_provider = Better_Messages()->functions->get_message_meta( $message['id'], 'ai_moderation_provider', true );
                        if ( ! empty( $ai_provider ) ) {
                            $item['ai_moderation_provider'] = $ai_provider;
                        }
                        if ( isset( $result_data['reason'] ) && ! empty( $result_data['reason'] ) ) {
                            $item['ai_moderation_reason'] = $result_data['reason'];
                        }
                    }

                    // Add AI cost data from dedicated usage table
                    $ai_usage_table = bm_get_table('ai_usage');
                    if ( $ai_usage_table ) {
                        $ai_usage_row = $wpdb->get_row( $wpdb->prepare(
                            "SELECT cost_data, points_charged FROM {$ai_usage_table} WHERE message_id = %d LIMIT 1",
                            $message['id']
                        ) );
                        if ( $ai_usage_row && ! empty( $ai_usage_row->cost_data ) ) {
                            $ai_cost = json_decode( $ai_usage_row->cost_data, true );
                            if ( (int) $ai_usage_row->points_charged > 0 ) {
                                $ai_cost['pointsCharged'] = (int) $ai_usage_row->points_charged;
                            }
                            $item['ai_cost'] = $ai_cost;
                        }
                    }

                    // Add translations data
                    $raw_translations = Better_Messages()->functions->get_message_meta( $message['id'], 'bm_translations', true );
                    $translations = ! empty( $raw_translations ) ? ( is_array( $raw_translations ) ? $raw_translations : json_decode( $raw_translations, true ) ) : array();
                    $translations = is_array( $translations ) ? array_filter( $translations, function( $v ) { return ! empty( $v ); } ) : array();
                    if ( ! empty( $translations ) ) {
                        $item['translations'] = $translations;
                    }

                    $return['messages'][] = $item;
                }
            }

            return $return;
        }

        public function whitelist_user( WP_REST_Request $request )
        {
            $user_id   = (int) $request->get_param('userId');
            $thread_id = $request->has_param('threadId') ? (int) $request->get_param('threadId') : null;
            $duration  = $request->has_param('duration') ? (int) $request->get_param('duration') : null;

            if( ! $user_id ){
                return new WP_Error( 'invalid_user', 'Invalid user ID', array( 'status' => 400 ) );
            }

            $result = Better_Messages()->moderation->whitelist_user( $user_id, $thread_id, $duration );

            if( $result ){
                return array(
                    'success' => true,
                    'message' => 'User whitelisted successfully'
                );
            }

            return new WP_Error( 'whitelist_failed', 'Failed to whitelist user', array( 'status' => 500 ) );
        }

        public function unwhitelist_user( WP_REST_Request $request )
        {
            $user_id   = (int) $request->get_param('userId');
            $thread_id = $request->has_param('threadId') ? (int) $request->get_param('threadId') : null;

            if( ! $user_id ){
                return new WP_Error( 'invalid_user', 'Invalid user ID', array( 'status' => 400 ) );
            }

            $result = Better_Messages()->moderation->unwhitelist_user( $user_id, $thread_id );

            if( $result ){
                return array(
                    'success' => true,
                    'message' => 'User removed from whitelist successfully'
                );
            }

            return new WP_Error( 'unwhitelist_failed', 'Failed to remove user from whitelist', array( 'status' => 500 ) );
        }

        public function get_whitelisted_users( WP_REST_Request $request )
        {
            $thread_id = $request->has_param('threadId') ? (int) $request->get_param('threadId') : null;

            $whitelisted = Better_Messages()->moderation->get_whitelisted_users( $thread_id );

            $users = [];

            foreach( $whitelisted as $item ){
                $user_data = Better_Messages()->functions->rest_user_item( $item['user_id'] );
                $user_data['expiration'] = $item['expiration'];
                $user_data['admin_id'] = $item['admin_id'];

                if( $item['admin_id'] ){
                    $user_data['admin'] = Better_Messages()->functions->rest_user_item( $item['admin_id'] );
                }

                $users[] = $user_data;
            }

            return array(
                'success' => true,
                'users' => $users
            );
        }

        public function blacklist_user( WP_REST_Request $request )
        {
            $user_id   = (int) $request->get_param('userId');
            $thread_id = $request->has_param('threadId') ? (int) $request->get_param('threadId') : null;
            $duration  = $request->has_param('duration') ? (int) $request->get_param('duration') : null;

            if( ! $user_id ){
                return new WP_Error( 'invalid_user', 'Invalid user ID', array( 'status' => 400 ) );
            }

            $result = Better_Messages()->moderation->blacklist_user( $user_id, $thread_id, $duration );

            if( $result ){
                return array(
                    'success' => true,
                    'message' => 'User blacklisted successfully'
                );
            }

            return new WP_Error( 'blacklist_failed', 'Failed to blacklist user', array( 'status' => 500 ) );
        }

        public function unblacklist_user( WP_REST_Request $request )
        {
            $user_id   = (int) $request->get_param('userId');
            $thread_id = $request->has_param('threadId') ? (int) $request->get_param('threadId') : null;

            if( ! $user_id ){
                return new WP_Error( 'invalid_user', 'Invalid user ID', array( 'status' => 400 ) );
            }

            $result = Better_Messages()->moderation->unblacklist_user( $user_id, $thread_id );

            if( $result ){
                return array(
                    'success' => true,
                    'message' => 'User removed from blacklist successfully'
                );
            }

            return new WP_Error( 'unblacklist_failed', 'Failed to remove user from blacklist', array( 'status' => 500 ) );
        }

        public function get_reported_count()
        {
            global $wpdb;

            $candidates = $this->get_reported_candidate_ids();

            if ( empty( $candidates['all_ids'] ) ) {
                return 0;
            }

            $candidate_sql       = implode( ',', $candidates['all_ids'] );
            $user_reports_in_sql = empty( $candidates['user_report_ids'] ) ? '0' : implode( ',', $candidates['user_report_ids'] );

            return (int) $wpdb->get_var( "
                SELECT COUNT(*) FROM `" . bm_get_table('messages') . "`
                WHERE `id` IN ({$candidate_sql})
                  AND `created_at` > 0
                  AND `message` != '<!-- BBPM START THREAD -->'
                  AND ( `id` IN ({$user_reports_in_sql}) OR `is_pending` = 0 )
            " );
        }

        private function get_reported_candidate_ids()
        {
            global $wpdb;

            $meta_table = bm_get_table('meta');

            $user_report_ids = array_map( 'intval', $wpdb->get_col( "SELECT DISTINCT `bm_message_id` FROM `{$meta_table}` WHERE `meta_key` = 'user_reports'" ) );
            $ai_flagged_ids  = array_map( 'intval', $wpdb->get_col( "SELECT `bm_message_id` FROM `{$meta_table}` WHERE `meta_key` = 'ai_moderation_flagged' AND `meta_value` = '1'" ) );

            return [
                'user_report_ids' => $user_report_ids,
                'ai_flagged_ids'  => $ai_flagged_ids,
                'all_ids'         => array_unique( array_merge( $user_report_ids, $ai_flagged_ids ) ),
            ];
        }

        public function dismiss_ai_flag( WP_REST_Request $request )
        {
            $message_id = (int) $request->get_param('messageId');

            if( ! $message_id ){
                return new WP_Error( 'invalid_message', 'Invalid message ID', array( 'status' => 400 ) );
            }

            Better_Messages()->functions->delete_message_meta( $message_id, 'ai_moderation_flagged' );
            Better_Messages()->functions->delete_message_meta( $message_id, 'ai_moderation_categories' );
            Better_Messages()->functions->delete_message_meta( $message_id, 'ai_moderation_result' );
            Better_Messages()->functions->delete_message_meta( $message_id, 'ai_moderation_provider' );

            return array(
                'success'  => true,
                'reported' => $this->get_reported_count()
            );
        }

        public function get_blacklisted_users( WP_REST_Request $request )
        {
            $thread_id = $request->has_param('threadId') ? (int) $request->get_param('threadId') : null;

            $blacklisted = Better_Messages()->moderation->get_blacklisted_users( $thread_id );

            $users = [];

            foreach( $blacklisted as $item ){
                $user_data = Better_Messages()->functions->rest_user_item( $item['user_id'] );
                $user_data['expiration'] = $item['expiration'];
                $user_data['admin_id'] = $item['admin_id'];

                if( $item['admin_id'] ){
                    $user_data['admin'] = Better_Messages()->functions->rest_user_item( $item['admin_id'] );
                }

                $users[] = $user_data;
            }

            return array(
                'success' => true,
                'users' => $users
            );
        }

        public function get_threads( WP_REST_Request $request ){
            global $wpdb;
            $page = (isset($_GET['cpage'])) ? intval( $_GET['cpage'] ) : 1;

            $per_page = 20;
            $offset = 0;
            if( $page > 1 ){
                $offset = ( $page - 1 ) * $per_page;
            }

            $count = $wpdb->get_var("SELECT COUNT(*) FROM `" . bm_get_table('threads') . "`");

            $return = [
                'total' => $count,
                'threads' => []
            ];

            $threads = $wpdb->get_results( "
                SELECT *, 
                (SELECT COUNT(*) 
                  FROM `" . bm_get_table('recipients') . "` 
                 WHERE `thread_id` = `threads`.`id`) participants,
                (SELECT COUNT(*) 
                  FROM `" . bm_get_table('messages') . "` 
                 WHERE `thread_id` = `threads`.`id`) messages
                FROM `" . bm_get_table('threads') . "` `threads`
                ORDER BY `threads`.`id` DESC
                LIMIT {$offset}, {$per_page}
            ", ARRAY_A );

            if( count($threads) > 0 ){
                foreach( $threads as $thread ){
                    $item = [
                        'id'           => $thread['id'],
                        'subject'      => $thread['subject'],
                        'participants' => $thread['participants'],
                        'messages'     => $thread['messages']
                    ];

                    $return['threads'][] = $item;
                }
            }

            return $return;
        }

        public function whitelist_user_and_approve_all_messages( WP_REST_Request $request )
        {
            $user_id   = (int) $request->get_param('userId');
            $thread_id = $request->has_param('threadId') ? (int) $request->get_param('threadId') : null;
            $duration  = $request->has_param('duration') ? (int) $request->get_param('duration') : null;

            if( ! $user_id ){
                return new WP_Error( 'invalid_user', 'Invalid user ID', array( 'status' => 400 ) );
            }

            // First whitelist the user
            $whitelist_result = Better_Messages()->moderation->whitelist_user( $user_id, $thread_id, $duration );

            if( ! $whitelist_result ){
                return new WP_Error( 'whitelist_failed', 'Failed to whitelist user', array( 'status' => 500 ) );
            }

            // Then approve all pending messages from this user
            $approved_count = Better_Messages()->moderation->approve_all_pending_messages_from_user( $user_id );

            return array(
                'success'        => true,
                'message'        => 'User whitelisted and messages approved successfully',
                'approvedCount'  => $approved_count
            );
        }

        public function blacklist_user_and_delete_all_pending_messages( WP_REST_Request $request )
        {
            $user_id   = (int) $request->get_param('userId');
            $thread_id = $request->has_param('threadId') ? (int) $request->get_param('threadId') : null;
            $duration  = $request->has_param('duration') ? (int) $request->get_param('duration') : null;

            if( ! $user_id ){
                return new WP_Error( 'invalid_user', 'Invalid user ID', array( 'status' => 400 ) );
            }

            // First delete all pending messages from this user
            $deleted_count = Better_Messages()->moderation->delete_all_pending_messages_from_user( $user_id );

            // Then blacklist the user
            $blacklist_result = Better_Messages()->moderation->blacklist_user( $user_id, $thread_id, $duration );

            if( ! $blacklist_result ){
                return new WP_Error( 'blacklist_failed', 'Failed to blacklist user', array( 'status' => 500 ) );
            }

            return array(
                'success'      => true,
                'message'      => 'User blacklisted and pending messages deleted successfully',
                'deletedCount' => $deleted_count
            );
        }
        public function rest_save_settings( WP_REST_Request $request ) {
            $data = $request->get_json_params();

            if ( empty( $data ) || ! is_array( $data ) ) {
                return new WP_Error( 'invalid_data', 'No settings provided', array( 'status' => 400 ) );
            }

            // Handle emojiSettings separately — it saves to its own option
            // and should not go through update_settings().
            if ( isset( $data['emojiSettings'] ) ) {
                $emoji_json = $data['emojiSettings'];
                unset( $data['emojiSettings'] );

                if ( ! empty( trim( $emoji_json ) ) ) {
                    $emojies = json_decode( wp_unslash( $emoji_json ), true );
                    if ( is_array( $emojies ) ) {
                        $emojies = Better_Messages_Options::instance()->sanitize_emoji_data( $emojies );
                        update_option( 'bm-emoji-set-2', $emojies );
                        update_option( 'bm-emoji-hash', hash( 'md5', json_encode( $emojies ) ) );
                    }
                }
            }

            // If other settings were changed, merge and save them.
            if ( ! empty( $data ) ) {
                $existing = Better_Messages_Options::instance()->settings;
                $merged   = array_merge( $existing, $data );

                Better_Messages_Options::instance()->update_settings( $merged );
            }

            $response_settings = Better_Messages_Options::instance()->settings;
            $response_settings['emailCustomHtml'] = Better_Messages_Options::instance()->get_email_custom_html();

            return rest_ensure_response( array(
                'success'  => true,
                'settings' => $response_settings,
            ) );
        }

        public function rest_create_messages_page( WP_REST_Request $request ) {
            $page_title = _x( 'Messages', 'Default page title', 'bp-better-messages' );

            // Use Gutenberg block if block editor is available, otherwise shortcode
            if ( function_exists( 'use_block_editor_for_post_type' ) && use_block_editor_for_post_type( 'page' ) ) {
                $page_content = '<!-- wp:better-messages/user-inbox /-->';
            } else {
                $page_content = '[bp-better-messages]';
            }

            $page_id = wp_insert_post( array(
                'post_title'   => $page_title,
                'post_content' => $page_content,
                'post_status'  => 'publish',
                'post_type'    => 'page',
            ) );

            if ( is_wp_error( $page_id ) ) {
                return new WP_Error( 'create_failed', $page_id->get_error_message(), array( 'status' => 500 ) );
            }

            // Auto-select the new page as messages location
            $existing = Better_Messages_Options::instance()->settings;
            $existing['chatPage'] = (string) $page_id;
            Better_Messages_Options::instance()->update_settings( $existing );

            return rest_ensure_response( array(
                'success'   => true,
                'pageId'    => $page_id,
                'pageTitle' => $page_title,
                'pageUrl'   => get_permalink( $page_id ),
                'settings'  => Better_Messages_Options::instance()->settings,
            ) );
        }

        public function rest_sync_user_index( WP_REST_Request $request ) {
            Better_Messages()->users->sync_all_users();

            return rest_ensure_response( array(
                'success' => true,
                'message' => 'User synchronization is finished',
            ) );
        }

        public function rest_convert_utf8mb4( WP_REST_Request $request ) {
            if ( class_exists( 'Better_Messages_Rest_Api_DB_Migrate' ) ) {
                Better_Messages_Rest_Api_DB_Migrate()->update_collate();
            }

            return rest_ensure_response( array(
                'success' => true,
                'message' => 'Database was converted',
            ) );
        }

        public function rest_reset_database( WP_REST_Request $request ) {
            if ( class_exists( 'Better_Messages_Rest_Api_DB_Migrate' ) ) {
                $migrate = Better_Messages_Rest_Api_DB_Migrate();
                $migrate->drop_tables();
                $migrate->delete_bulk_reports();
                $migrate->first_install();

                $settings = get_option( 'bp-better-chat-settings', array() );
                $settings['updateTime'] = time();
                update_option( 'bp-better-chat-settings', $settings );

                do_action( 'better_messages_reset_database' );
            }

            return rest_ensure_response( array(
                'success' => true,
                'message' => 'Database was reset',
            ) );
        }

        public function rest_import_settings( WP_REST_Request $request ) {
            $data = $request->get_json_params();

            if ( empty( $data ) || ! is_array( $data ) ) {
                return new WP_Error( 'invalid_data', 'Invalid settings data', array( 'status' => 400 ) );
            }

            Better_Messages_Options::instance()->update_settings( $data );

            return rest_ensure_response( array(
                'success'  => true,
                'message'  => 'Settings imported successfully',
            ) );
        }

        public function rest_get_settings( WP_REST_Request $request ) {
            $settings = Better_Messages_Options::instance()->settings;

            if ( ! function_exists( 'get_editable_roles' ) ) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
            }

            $all_roles = get_editable_roles();
            $roles = array();
            foreach ( $all_roles as $role_key => $role_data ) {
                $roles[] = array(
                    'key'  => $role_key,
                    'name' => $role_data['name'],
                );
            }

            $roles[] = array( 'key' => 'bm-guest', 'name' => _x( 'Guests', 'Settings page', 'bp-better-messages' ) );

            $pages_list = get_pages( array( 'sort_column' => 'post_title', 'sort_order' => 'ASC' ) );
            $pages = array();
            if ( ! empty( $pages_list ) ) {
                foreach ( $pages_list as $page ) {
                    $pages[] = array(
                        'id'    => $page->ID,
                        'title' => $page->post_title,
                    );
                }
            }

            return rest_ensure_response( array(
                'settings' => $settings,
                'roles'    => $roles,
                'pages'    => $pages,
            ) );
        }
    }

    function Better_Messages_Rest_Api_Admin(){
        return Better_Messages_Rest_Api_Admin::instance();
    }

endif;
