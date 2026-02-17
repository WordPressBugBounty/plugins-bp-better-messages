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


        public function rest_api_init(){
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

            $search = $request->get_param('search');

            if( empty( $search ) ) {
                return [];
            }

            $sql = $wpdb->prepare("
            SELECT ID FROM `" . bm_get_table('users') . "`
            WHERE ( ID = %s
            OR `user_nicename` LIKE %s
            OR `display_name` LIKE %s 
            OR `first_name` LIKE %s
            OR `last_name` LIKE %s
            OR `nickname` LIKE %s )
            LIMIT 0, 10", $search, '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%', '%' . $search . '%');

            $search_results = $wpdb->get_col( $sql );

            $return = [];

            foreach( $search_results as $user_id ){
                $return[] = Better_Messages()->functions->rest_user_item( $user_id );
            }

            return $return;
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
                $meta_table = bm_get_table('meta');

                $count = (int) $wpdb->get_var( "
                SELECT COUNT(DISTINCT `messages`.`id`)
                FROM `" . bm_get_table('messages') . "` `messages`
                LEFT JOIN `{$meta_table}` `user_reports_meta`
                    ON `messages`.`id` = `user_reports_meta`.`bm_message_id`
                    AND `user_reports_meta`.`meta_key` = 'user_reports'
                LEFT JOIN `{$meta_table}` `ai_flag_meta`
                    ON `messages`.`id` = `ai_flag_meta`.`bm_message_id`
                    AND `ai_flag_meta`.`meta_key` = 'ai_moderation_flagged'
                    AND `ai_flag_meta`.`meta_value` = '1'
                WHERE `messages`.`created_at` > 0
                AND `messages`.`message` != '<!-- BBPM START THREAD -->'
                AND (
                    `user_reports_meta`.`meta_value` IS NOT NULL
                    OR ( `ai_flag_meta`.`meta_value` = '1' AND `messages`.`is_pending` = 0 )
                )
                $sender_sql $search_sql $thread_sql");

                $sql = "SELECT `messages`.*,
                `user_reports_meta`.`meta_value` as user_reports,
                (SELECT COUNT(*)  FROM `" . bm_get_table('recipients') . "` WHERE `thread_id` = `messages`.`thread_id`) participants
                FROM `" . bm_get_table('messages') . "` `messages`
                LEFT JOIN `{$meta_table}` `user_reports_meta`
                    ON `messages`.`id` = `user_reports_meta`.`bm_message_id`
                    AND `user_reports_meta`.`meta_key` = 'user_reports'
                LEFT JOIN `{$meta_table}` `ai_flag_meta`
                    ON `messages`.`id` = `ai_flag_meta`.`bm_message_id`
                    AND `ai_flag_meta`.`meta_key` = 'ai_moderation_flagged'
                    AND `ai_flag_meta`.`meta_value` = '1'
                WHERE `messages`.`created_at` > 0
                    AND `messages`.`message` != '<!-- BBPM START THREAD -->'
                    AND (
                        `user_reports_meta`.`meta_value` IS NOT NULL
                        OR ( `ai_flag_meta`.`meta_value` = '1' AND `messages`.`is_pending` = 0 )
                    )
                $sender_sql $search_sql $thread_sql
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
                        'sender'       => Better_Messages()->functions->rest_user_item( $message['sender_id'] ),
                        'thread_id'    => $message['thread_id'],
                        'message'      => $content,
                        'time'         => $message['date_sent'],
                        'view_link'    => $view_link,
                        'participants' => $participants_count
                    ];

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

            $meta_table = bm_get_table('meta');

            return (int) $wpdb->get_var( "
                SELECT COUNT(DISTINCT `messages`.`id`)
                FROM `" . bm_get_table('messages') . "` `messages`
                LEFT JOIN `{$meta_table}` `user_reports_meta`
                    ON `messages`.`id` = `user_reports_meta`.`bm_message_id`
                    AND `user_reports_meta`.`meta_key` = 'user_reports'
                LEFT JOIN `{$meta_table}` `ai_flag_meta`
                    ON `messages`.`id` = `ai_flag_meta`.`bm_message_id`
                    AND `ai_flag_meta`.`meta_key` = 'ai_moderation_flagged'
                    AND `ai_flag_meta`.`meta_value` = '1'
                WHERE `messages`.`created_at` > 0
                AND `messages`.`message` != '<!-- BBPM START THREAD -->'
                AND (
                    `user_reports_meta`.`meta_value` IS NOT NULL
                    OR ( `ai_flag_meta`.`meta_value` = '1' AND `messages`.`is_pending` = 0 )
                )
            " );
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
    }

    function Better_Messages_Rest_Api_Admin(){
        return Better_Messages_Rest_Api_Admin::instance();
    }

endif;
