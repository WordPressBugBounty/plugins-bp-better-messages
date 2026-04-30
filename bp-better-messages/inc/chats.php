<?php
defined( 'ABSPATH' ) || exit;

class Better_Messages_Chats
{

    public static function instance()
    {

        // Store the instance locally to avoid private static replication
        static $instance = null;

        // Only run these methods if they haven't been run previously
        if ( null === $instance ) {
            $instance = new Better_Messages_Chats;
            $instance->setup_actions();
        }

        // Always return the instance
        return $instance;

        // The last metroid is in captivity. The galaxy is at peace.
    }

    public function setup_actions(){
        add_action( 'init',      array( $this, 'register_post_type' ) );
        add_action( 'save_post', array( $this, 'save_post' ), 1, 2 );
        add_action( 'edit_form_after_title', array( $this, 'render_chat_settings' ) );

        add_shortcode( 'bp_better_messages_chat_room', array( $this, 'layout' ) );
        add_shortcode( 'better_messages_chat_room', array( $this, 'layout' ) );

        //add_action( 'messages_message_sent', array( $this, 'on_message_sent' ) );

        add_action( "save_post_bpbm-chat", array( $this, 'on_chat_update' ), 10, 3 );
        add_action( 'before_delete_post',  array( $this, 'on_chat_delete' ), 10, 1 );

        add_action( 'better_messages_chat_room_sync_auto_add_users', array( $this, 'sync_auto_add_users'), 10, 1 );

        add_action( 'user_register', array( $this, 'on_user_register' ), 20, 2 );
        add_action( 'add_user_role', array( $this, 'on_user_role_change' ), 20, 2 );
        add_action( 'remove_user_role', array( $this, 'on_user_role_change' ), 20, 2 );

        add_action( 'set_user_role', array( $this, 'on_user_role_change' ), 20, 3 );
        add_action( 'better_messages_guest_registered', array( $this, 'guest_registered' ), 20, 1 );

        add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
        add_filter( 'better_messages_rest_thread_item', array( $this, 'rest_thread_item'), 10, 5 );

        add_filter('better_messages_thread_title', array( $this, 'chat_thread_title' ), 10, 3 );
        add_action('better_messages_before_message_send',  array( $this, 'before_message_send' ), 20, 2 );

        add_filter( 'better_messages_guest_register_allowed', array( $this, 'check_if_chat_room_guests_allowed' ), 10,2 );
    }

    public function check_if_chat_room_guests_allowed( $allowed, $registerData )
    {
        if( is_array( $registerData ) && isset( $registerData['chatId'] ) ){
            $chat_id  = intval($registerData['chatId']);
            if( $this->is_chat_room( $chat_id ) ) {
                $settings = $this->get_chat_settings($chat_id);
                if( in_array( 'bm-guest', $settings['can_join'] ) ){
                    $allowed = true;
                }
            }
        }

        return $allowed;
    }

    public function is_chat_room( $chat_id )
    {
        $chat = get_post( $chat_id );

        if( $chat && $chat->post_type === 'bpbm-chat' ){
            return true;
        }

        return false;
    }

    function before_message_send( &$args, &$errors ){
        $thread_id = $args['thread_id'];
        $type = Better_Messages()->functions->get_thread_type( $thread_id );
        if( $type !== 'chat-room' ) return;

        $chat_id = Better_Messages()->functions->get_thread_meta( $thread_id, 'chat_id' );
        if( !! $chat_id ) {
            $user_id = (isset($args['sender_id'])) ? $args['sender_id'] : Better_Messages()->functions->get_current_user_id();

            if (!$this->user_can_reply($user_id, $chat_id)) {
                $settings = $this->get_chat_settings($chat_id);
                $errors['not_allowed_to_reply'] = $settings['not_allowed_reply_text'];
            }
        }
    }

    /**
     * @param string $title
     * @param int $thread_id
     * @param BM_Thread $thread
     * @return string
     */
    public function chat_thread_title(string $title, int $thread_id, $thread ){
        $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );
        if( $thread_type !== 'chat-room' ) return $title;

        $chat_id = (int) Better_Messages()->functions->get_thread_meta($thread_id, 'chat_id');
        $chat = get_post( $chat_id );

        if( $chat ){
            return $chat->post_title;
        }

        return $title;
    }

    public function rest_api_init(){
        register_rest_route('better-messages/v1/admin', '/getChatParticipants', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'get_chat_participants'),
            'permission_callback' => array($this, 'user_is_admin'),
        ));

        register_rest_route( 'better-messages/v1', '/getChatRooms', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_get_user_facing_chat_rooms' ),
            'permission_callback' => '__return_true',
        ) );

        register_rest_route( 'better-messages/v1', '/chat/(?P<id>\d+)/join', array(
            'methods' => 'POST',
            'callback' => array( $this, 'join_chat' ),
            'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' )
        ) );

        register_rest_route( 'better-messages/v1', '/chat/(?P<id>\d+)/leave', array(
            'methods' => 'POST',
            'callback' => array( $this, 'leave_chat' ),
            'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' )
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_admin_list_chat_rooms' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_admin_create_chat_room' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms/(?P<id>\d+)', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_admin_update_chat_room' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms/(?P<id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'rest_admin_delete_chat_room' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms/(?P<id>\d+)/participants', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'rest_admin_get_participants' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms/(?P<id>\d+)/participants', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_admin_add_participant' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms/(?P<id>\d+)/participants/(?P<user_id>-?\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'rest_admin_remove_participant' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms/(?P<id>\d+)/duplicate', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_admin_duplicate_chat_room' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms/(?P<id>\d+)/clear', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'rest_admin_clear_chat_room' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );

        register_rest_route( 'better-messages/v1', '/admin/chat-rooms/(?P<id>\d+)/participants', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'rest_admin_remove_all_participants' ),
            'permission_callback' => array( $this, 'user_is_admin' ),
        ) );
    }

    public function user_is_admin(){
        return current_user_can('manage_options');
    }

    public function get_chat_participants( WP_REST_Request $request ){
        global $wpdb;

        $chat_id = intval( $request->get_param('chatId') );

        $thread_id = $this->get_chat_thread_id( $chat_id );

        $page   = ( $request->has_param('page') ) ? intval( $request->get_param('page') ) : 1;

        $per_page = 10;

        $offset = 0;

        if( $page > 1 ){
            $offset = ( $page - 1 ) * $per_page;
        }

        $table = bm_get_table('recipients');

        $total_results = (int) $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) 
        FROM `{$table}` `recipients`
        LEFT JOIN " . $wpdb->users . " `users`
            ON `users`.`ID` = `recipients`.`user_id`
        WHERE `thread_id` = %d
        AND (  ( `recipients`.`user_id` >= 0 AND `users`.`ID` IS NOT NULL ) OR ( `recipients`.`user_id` < 0 ) )", $thread_id ) );

        $sql = $wpdb->prepare("
        SELECT `user_id` 
        FROM `{$table}` `recipients`
        LEFT JOIN " . $wpdb->users . " `users`
            ON `users`.`ID` = `recipients`.`user_id`
        WHERE `thread_id` = %d 
        AND (  ( `recipients`.`user_id` >= 0 AND `users`.`ID` IS NOT NULL ) OR ( `recipients`.`user_id` < 0 ) )
        LIMIT {$offset}, {$per_page}", $thread_id );

        $results = $wpdb->get_col( $sql );

        $pages = ceil( $total_results / $per_page );

        $users = [];

        foreach ( $results as $user_id ) {
            $users[] = Better_Messages()->functions->rest_user_item($user_id);
        }

        $result = [
            'total' => $total_results,
            'pages' => $pages,
            'users' => $users
        ];


        return $result;
    }

    public function rest_get_user_facing_chat_rooms( WP_REST_Request $request ) {
        global $wpdb;

        $user_id = Better_Messages()->functions->get_current_user_id();

        $display_mode  = isset( Better_Messages()->settings['widgetChatRoomsDisplayMode'] )
            ? Better_Messages()->settings['widgetChatRoomsDisplayMode']
            : 'all';
        $allowed_ids   = isset( Better_Messages()->settings['widgetChatRoomsIds'] ) && is_array( Better_Messages()->settings['widgetChatRoomsIds'] )
            ? array_map( 'intval', Better_Messages()->settings['widgetChatRoomsIds'] )
            : array();
        $filter_active = ( $display_mode === 'specific' );

        if ( $filter_active && empty( $allowed_ids ) ) {
            return array();
        }

        $query_args = array(
            'post_type'      => 'bpbm-chat',
            'post_status'    => 'publish',
            'posts_per_page' => 100,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        );

        if ( $filter_active ) {
            $query_args['post__in'] = $allowed_ids;
            $query_args['orderby']  = 'post__in';
        }

        $query = new WP_Query( $query_args );

        $recipients_table = bm_get_table( 'recipients' );
        $rooms            = array();
        $thread_ids       = array();

        foreach ( $query->posts as $post ) {
            $chat_id   = (int) $post->ID;
            $thread_id = (int) $this->get_chat_thread_id( $chat_id );
            if ( ! $thread_id ) continue;

            $is_joined = false;
            if ( $user_id !== 0 ) {
                $is_joined = (bool) $wpdb->get_var( $wpdb->prepare(
                    "SELECT 1 FROM `{$recipients_table}` WHERE `thread_id` = %d AND `user_id` = %d LIMIT 1",
                    $thread_id, $user_id
                ) );
            }

            $can_join = $this->user_can_join( $user_id, $chat_id );

            if ( ! $is_joined && ! $can_join ) {
                continue;
            }

            $image_url = '';
            if ( has_post_thumbnail( $chat_id ) ) {
                $image_id = (int) get_post_thumbnail_id( $chat_id );
                if ( $image_id ) {
                    $src = wp_get_attachment_image_src( $image_id, array( 100, 100 ) );
                    if ( $src ) {
                        $image_url = $src[0];
                    }
                }
            }

            $member_count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$recipients_table}` WHERE `thread_id` = %d",
                $thread_id
            ) );

            $rooms[] = array(
                'chat_id'      => $chat_id,
                'thread_id'    => $thread_id,
                'title'        => $post->post_title,
                'image'        => $image_url,
                'isJoined'     => $is_joined ? 1 : 0,
                'canJoin'      => $can_join ? 1 : 0,
                'memberCount'  => $member_count,
                'participants' => array(),
                'showOnline'   => 0,
            );
            $thread_ids[] = $thread_id;
        }

        if ( ! empty( $thread_ids ) ) {
            $thread_placeholders = implode( ',', array_fill( 0, count( $thread_ids ), '%d' ) );

            $by_thread = $this->fetch_packed_recipients( $thread_ids, $thread_placeholders );

            foreach ( $rooms as &$room ) {
                $room['participants'] = isset( $by_thread[ $room['thread_id'] ] )
                    ? $by_thread[ $room['thread_id'] ]
                    : array();
            }
            unset( $room );
        }

        $show_online = Better_Messages()->realtime
            && ! empty( Better_Messages()->settings['chatRoomsShowOnline'] )
            && Better_Messages()->settings['chatRoomsShowOnline'] === '1';

        if ( $show_online ) {
            foreach ( $rooms as &$room ) {
                $room['showOnline'] = 1;
            }
            unset( $room );
        }

        return apply_filters( 'better_messages_get_chat_rooms', $rooms, $user_id );
    }

    private function fetch_packed_recipients( array $thread_ids, $thread_placeholders ) {
        global $wpdb;

        if ( $this->db_supports_window_functions() ) {
            $recipients = bm_get_table( 'recipients' );

            $prev_suppress = $wpdb->suppress_errors( true );
            $prev_show     = $wpdb->show_errors( false );

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `thread_id`, MIN(`user_id`) AS `rs`, MAX(`user_id`) AS `re`
                 FROM (
                    SELECT `thread_id`, `user_id`,
                        `user_id` - CAST(ROW_NUMBER() OVER (PARTITION BY `thread_id` ORDER BY `user_id`) AS SIGNED) AS `grp`
                    FROM `{$recipients}`
                    WHERE `thread_id` IN ({$thread_placeholders})
                      AND `is_deleted` = 0
                 ) `t`
                 GROUP BY `thread_id`, `grp`
                 ORDER BY `thread_id` ASC, `rs` ASC",
                $thread_ids
            ) );

            $error = $wpdb->last_error;
            $wpdb->show_errors( $prev_show );
            $wpdb->suppress_errors( $prev_suppress );

            if ( empty( $error ) && is_array( $rows ) ) {
                $by_thread = array();
                foreach ( $rows as $row ) {
                    $tid = (int) $row->thread_id;
                    $rs  = (int) $row->rs;
                    $re  = (int) $row->re;
                    if ( ! isset( $by_thread[ $tid ] ) ) $by_thread[ $tid ] = array();
                    $by_thread[ $tid ][] = ( $rs === $re ) ? $rs : array( $rs, $re );
                }
                return $by_thread;
            }
        }

        return $this->fetch_packed_recipients_php( $thread_ids, $thread_placeholders );
    }

    private function db_supports_window_functions() {
        static $supports = null;
        if ( $supports !== null ) return $supports;

        global $wpdb;

        $version = $wpdb->db_version();
        $server  = '';
        if ( method_exists( $wpdb, 'db_server_info' ) ) {
            $server = (string) $wpdb->db_server_info();
        }

        if ( empty( $version ) ) {
            return $supports = false;
        }

        if ( stripos( $server, 'mariadb' ) !== false ) {
            $supports = version_compare( $version, '10.2', '>=' );
        } else {
            $supports = version_compare( $version, '8.0', '>=' );
        }

        return $supports;
    }

    private function fetch_packed_recipients_php( array $thread_ids, $thread_placeholders ) {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT `thread_id`, `user_id`
             FROM `" . bm_get_table( 'recipients' ) . "`
             WHERE `thread_id` IN ({$thread_placeholders})
               AND `is_deleted` = 0
             ORDER BY `thread_id` ASC, `user_id` ASC",
            $thread_ids
        ) );

        $by_thread       = array();
        $current_tid     = null;
        $current_entries = array();
        $range_start     = null;
        $range_end       = null;

        $flush_run = function() use ( &$current_entries, &$range_start, &$range_end ) {
            if ( $range_start === null ) return;
            $current_entries[] = ( $range_start === $range_end )
                ? $range_start
                : array( $range_start, $range_end );
            $range_start = null;
            $range_end   = null;
        };

        foreach ( $rows as $row ) {
            $tid = (int) $row->thread_id;
            $uid = (int) $row->user_id;

            if ( $current_tid !== $tid ) {
                if ( $current_tid !== null ) {
                    $flush_run();
                    $by_thread[ $current_tid ] = $current_entries;
                }
                $current_tid     = $tid;
                $current_entries = array();
                $range_start     = $uid;
                $range_end       = $uid;
                continue;
            }

            if ( $uid === $range_end + 1 ) {
                $range_end = $uid;
            } else {
                $flush_run();
                $range_start = $uid;
                $range_end   = $uid;
            }
        }
        if ( $current_tid !== null ) {
            $flush_run();
            $by_thread[ $current_tid ] = $current_entries;
        }

        return $by_thread;
    }

    public function rest_admin_list_chat_rooms( WP_REST_Request $request ) {
        $page     = max( 1, (int) ( $request->get_param('page') ?: 1 ) );
        $per_page = max( 1, min( 100, (int) ( $request->get_param('per_page') ?: 20 ) ) );
        $search   = sanitize_text_field( $request->get_param('search') ?: '' );

        $query_args = array(
            'post_type'      => 'bpbm-chat',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => array( 'publish', 'draft' ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        );

        if ( ! empty( $search ) ) {
            $query_args['s'] = $search;
        }

        $include = $request->get_param('include');
        if ( ! empty( $include ) ) {
            $query_args['post__in'] = array_map( 'intval', (array) $include );
            $query_args['posts_per_page'] = count( $query_args['post__in'] );
            unset( $query_args['paged'] );
        }

        $query = new WP_Query( $query_args );

        $chat_rooms = array();
        foreach ( $query->posts as $post ) {
            $item = $this->format_chat_room_for_rest( $post->ID );
            if ( $item ) $chat_rooms[] = $item;
        }

        return array(
            'items'   => $chat_rooms,
            'total'   => (int) $query->found_posts,
            'page'    => $page,
            'perPage' => $per_page,
            'pages'   => (int) $query->max_num_pages,
        );
    }

    public function rest_admin_create_chat_room( WP_REST_Request $request ) {
        $title = sanitize_text_field( $request->get_param( 'title' ) );

        if ( empty( $title ) ) {
            return new WP_Error( 'missing_title', __( 'Chat room title is required', 'bp-better-messages' ), array( 'status' => 400 ) );
        }

        $post_id = wp_insert_post( array(
            'post_type'   => 'bpbm-chat',
            'post_title'  => $title,
            'post_status' => 'publish',
        ) );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        $defaults = $this->get_chat_settings( 0 );
        update_post_meta( $post_id, 'bpbm-chat-settings', $defaults );

        return $this->format_chat_room_for_rest( $post_id );
    }

    public function rest_admin_update_chat_room( WP_REST_Request $request ) {
        $chat_id = intval( $request->get_param( 'id' ) );
        $post    = get_post( $chat_id );

        if ( ! $post || $post->post_type !== 'bpbm-chat' ) {
            return new WP_Error( 'not_found', __( 'Chat room not found', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        $title  = $request->get_param( 'title' );
        $status = $request->get_param( 'status' );

        $update = array( 'ID' => $chat_id );
        if ( $title !== null ) {
            $update['post_title'] = sanitize_text_field( $title );
        }
        if ( $status !== null && in_array( $status, array( 'publish', 'draft' ), true ) ) {
            $update['post_status'] = $status;
        }
        if ( count( $update ) > 1 ) {
            wp_update_post( $update );
        }

        $image_id = $request->get_param( 'imageId' );
        if ( $image_id !== null ) {
            $image_id = intval( $image_id );
            if ( $image_id > 0 ) {
                set_post_thumbnail( $chat_id, $image_id );
            } else {
                delete_post_thumbnail( $chat_id );
            }
        }

        $settings = $request->get_param( 'settings' );
        if ( is_array( $settings ) ) {
            $this->save_chat_settings( $chat_id, $settings );
        }

        return $this->format_chat_room_for_rest( $chat_id );
    }

    public function rest_admin_delete_chat_room( WP_REST_Request $request ) {
        $chat_id = intval( $request->get_param( 'id' ) );
        $post    = get_post( $chat_id );

        if ( ! $post || $post->post_type !== 'bpbm-chat' ) {
            return new WP_Error( 'not_found', __( 'Chat room not found', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        wp_delete_post( $chat_id, true );

        return array( 'deleted' => true );
    }

    public function rest_admin_get_participants( WP_REST_Request $request ) {
        global $wpdb;

        $chat_id   = intval( $request->get_param( 'id' ) );
        $post      = get_post( $chat_id );

        if ( ! $post || $post->post_type !== 'bpbm-chat' ) {
            return new WP_Error( 'not_found', __( 'Chat room not found', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        $thread_id = $this->get_chat_thread_id( $chat_id );
        $page      = max( 1, intval( $request->get_param( 'page' ) ?: 1 ) );
        $per_page  = 10;
        $offset    = ( $page - 1 ) * $per_page;
        $search    = sanitize_text_field( $request->get_param( 'search' ) ?: '' );

        $table = bm_get_table( 'recipients' );

        $search_clause = '';
        if ( ! empty( $search ) ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $search_clause = $wpdb->prepare(
                " AND ( `users`.`display_name` LIKE %s OR `users`.`user_login` LIKE %s OR `users`.`user_email` LIKE %s )",
                $like, $like, $like
            );
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
            FROM `{$table}` `recipients`
            LEFT JOIN {$wpdb->users} `users` ON `users`.`ID` = `recipients`.`user_id`
            WHERE `thread_id` = %d
            AND ( ( `recipients`.`user_id` >= 0 AND `users`.`ID` IS NOT NULL ) OR ( `recipients`.`user_id` < 0 ) )"
            . $search_clause,
            $thread_id
        ) );

        $user_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT `user_id`
            FROM `{$table}` `recipients`
            LEFT JOIN {$wpdb->users} `users` ON `users`.`ID` = `recipients`.`user_id`
            WHERE `thread_id` = %d
            AND ( ( `recipients`.`user_id` >= 0 AND `users`.`ID` IS NOT NULL ) OR ( `recipients`.`user_id` < 0 ) )"
            . $search_clause .
            " ORDER BY `user_id` ASC
            LIMIT %d, %d",
            $thread_id, $offset, $per_page
        ) );

        $users = array();
        foreach ( $user_ids as $user_id ) {
            $users[] = Better_Messages()->functions->rest_user_item( $user_id );
        }

        return array(
            'users' => $users,
            'total' => $total,
            'pages' => (int) ceil( $total / $per_page ),
        );
    }

    public function rest_admin_add_participant( WP_REST_Request $request ) {
        $chat_id  = intval( $request->get_param( 'id' ) );
        $post     = get_post( $chat_id );

        if ( ! $post || $post->post_type !== 'bpbm-chat' ) {
            return new WP_Error( 'not_found', __( 'Chat room not found', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        $user_ids = $request->get_param( 'user_ids' );
        if ( ! is_array( $user_ids ) ) {
            $user_ids = array( intval( $user_ids ) );
        }
        $user_ids = array_map( 'intval', $user_ids );

        $thread_id = $this->get_chat_thread_id( $chat_id );
        $added = 0;

        foreach ( $user_ids as $user_id ) {
            if ( ! Better_Messages()->functions->is_thread_participant( $user_id, $thread_id, true ) ) {
                Better_Messages()->functions->add_participant_to_thread( $thread_id, $user_id );
                $added++;
            }
        }

        do_action( 'better_messages_thread_updated', $thread_id );
        do_action( 'better_messages_info_changed', $thread_id );

        return array( 'added' => $added );
    }

    public function rest_admin_remove_participant( WP_REST_Request $request ) {
        $chat_id = intval( $request->get_param( 'id' ) );
        $user_id = intval( $request->get_param( 'user_id' ) );
        $post    = get_post( $chat_id );

        if ( ! $post || $post->post_type !== 'bpbm-chat' ) {
            return new WP_Error( 'not_found', __( 'Chat room not found', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        $thread_id = $this->get_chat_thread_id( $chat_id );

        if ( ! Better_Messages()->functions->is_thread_participant( $user_id, $thread_id, true ) ) {
            return new WP_Error( 'not_participant', __( 'User is not a participant', 'bp-better-messages' ), array( 'status' => 400 ) );
        }

        Better_Messages()->functions->remove_participant_from_thread( $thread_id, $user_id );

        do_action( 'better_messages_thread_updated', $thread_id );
        do_action( 'better_messages_info_changed', $thread_id );

        return array( 'removed' => true );
    }

    public function rest_admin_duplicate_chat_room( WP_REST_Request $request ) {
        $chat_id = intval( $request->get_param( 'id' ) );
        $post    = get_post( $chat_id );

        if ( ! $post || $post->post_type !== 'bpbm-chat' ) {
            return new WP_Error( 'not_found', __( 'Chat room not found', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        $settings = $this->get_chat_settings( $chat_id );

        $new_id = wp_insert_post( array(
            'post_type'   => 'bpbm-chat',
            'post_title'  => $post->post_title . ' (' . __( 'Copy', 'bp-better-messages' ) . ')',
            'post_status' => 'publish',
        ) );

        if ( is_wp_error( $new_id ) ) {
            return $new_id;
        }

        update_post_meta( $new_id, 'bpbm-chat-settings', $settings );

        $can_join = get_post_meta( $chat_id, 'bpbm-chat-can-join', true );
        if ( ! empty( $can_join ) ) {
            update_post_meta( $new_id, 'bpbm-chat-can-join', $can_join );
        }

        $auto_add = get_post_meta( $chat_id, 'bpbm-chat-auto-add', true );
        if ( ! empty( $auto_add ) ) {
            update_post_meta( $new_id, 'bpbm-chat-auto-add', $auto_add );
        }

        if ( has_post_thumbnail( $chat_id ) ) {
            set_post_thumbnail( $new_id, get_post_thumbnail_id( $chat_id ) );
        }

        $copy_participants = ! empty( $request->get_param( 'copyParticipants' ) );

        if ( $copy_participants ) {
            $source_thread_id = $this->get_chat_thread_id( $chat_id );
            if ( $source_thread_id ) {
                $new_thread_id = $this->get_chat_thread_id( $new_id );
                if ( $new_thread_id ) {
                    $recipient_ids = Better_Messages()->functions->get_recipients_ids( $source_thread_id );
                    foreach ( $recipient_ids as $user_id ) {
                        Better_Messages()->functions->add_participant_to_thread( $new_thread_id, $user_id );
                    }
                }
            }
        }

        return $this->format_chat_room_for_rest( $new_id );
    }

    public function rest_admin_clear_chat_room( WP_REST_Request $request ) {
        global $wpdb;

        $chat_id = intval( $request->get_param( 'id' ) );
        $post    = get_post( $chat_id );

        if ( ! $post || $post->post_type !== 'bpbm-chat' ) {
            return new WP_Error( 'not_found', __( 'Chat room not found', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        $thread_id = $this->get_chat_thread_id( $chat_id );

        if ( ! $thread_id ) {
            return new WP_Error( 'no_thread', __( 'Chat room has no thread', 'bp-better-messages' ), array( 'status' => 400 ) );
        }

        $batch_size = 50;

        $message_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM " . bm_get_table('messages') . " WHERE `thread_id` = %d LIMIT %d",
            $thread_id, $batch_size
        ) );

        $deleted = count( $message_ids );

        if ( $deleted > 0 ) {
            foreach ( $message_ids as $message_id ) {
                Better_Messages()->functions->delete_message( $message_id, $thread_id, false, 'delete' );
            }
        }

        $remaining = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM " . bm_get_table('messages') . " WHERE `thread_id` = %d",
            $thread_id
        ) );

        $done = $remaining === 0;

        if ( $done ) {
            $time = bp_core_current_time();

            $wpdb->query( $wpdb->prepare(
                "UPDATE " . bm_get_table('recipients') . "
                SET unread_count = 0,
                last_read = %s,
                last_delivered = %s
                WHERE thread_id = %d",
                $time, $time, $thread_id
            ) );

            do_action( 'better_messages_thread_updated', $thread_id );
            do_action( 'better_messages_thread_cleared', $thread_id );
        }

        return array(
            'done'      => $done,
            'deleted'   => $deleted,
            'remaining' => $remaining,
        );
    }

    public function rest_admin_remove_all_participants( WP_REST_Request $request ) {
        global $wpdb;

        $chat_id = intval( $request->get_param( 'id' ) );
        $post    = get_post( $chat_id );

        if ( ! $post || $post->post_type !== 'bpbm-chat' ) {
            return new WP_Error( 'not_found', __( 'Chat room not found', 'bp-better-messages' ), array( 'status' => 404 ) );
        }

        $thread_id = $this->get_chat_thread_id( $chat_id );

        if ( ! $thread_id ) {
            return new WP_Error( 'no_thread', __( 'Chat room has no thread', 'bp-better-messages' ), array( 'status' => 400 ) );
        }

        $wpdb->delete( bm_get_table( 'recipients' ), array( 'thread_id' => $thread_id ), array( '%d' ) );

        do_action( 'better_messages_thread_updated', $thread_id );
        do_action( 'better_messages_info_changed', $thread_id );

        return array( 'removed' => true );
    }

    private function format_chat_room_for_rest( $chat_id ) {
        $post = get_post( $chat_id );
        if ( ! $post ) return null;

        $settings = $this->get_chat_settings( $chat_id );
        $thread_id = $this->get_chat_thread_id( $chat_id );

        $image_id  = 0;
        $image_url = '';

        if ( has_post_thumbnail( $chat_id ) ) {
            $image_id = (int) get_post_thumbnail_id( $chat_id );
            if ( $image_id ) {
                $src = wp_get_attachment_image_src( $image_id, array( 100, 100 ) );
                if ( $src ) {
                    $image_url = $src[0];
                }
            }
        }

        $inbox_url = '';
        if ( $thread_id ) {
            $inbox_url = Better_Messages()->functions->get_user_messages_url( get_current_user_id(), $thread_id );
        }

        $message_count = 0;
        if ( $thread_id ) {
            $message_count = (int) Better_Messages()->functions->get_thread_message_count( $thread_id );
        }

        return array(
            'id'           => (int) $post->ID,
            'title'        => $post->post_title,
            'status'       => $post->post_status,
            'threadId'     => (int) $thread_id,
            'inboxUrl'     => $inbox_url,
            'imageId'      => $image_id,
            'imageUrl'     => $image_url,
            'settings'     => $settings,
            'messageCount' => $message_count,
        );
    }

    private function save_chat_settings( $chat_id, $settings ) {
        $thread_id = $this->get_chat_thread_id( $chat_id );

        $checkbox_fields = array(
            'only_joined_can_read', 'auto_join', 'auto_exclude', 'hide_participants',
            'hide_participants_count', 'enable_chat_email_notifications', 'enable_files',
            'hide_from_thread_list', 'enable_notifications', 'allow_guests', 'show_online_users'
        );

        foreach ( $checkbox_fields as $field ) {
            if ( ! isset( $settings[ $field ] ) ) {
                $settings[ $field ] = '0';
            }
        }

        if ( ! isset( $settings['auto_exclude'] ) || $settings['auto_exclude'] !== '1' ) {
            Better_Messages()->functions->delete_thread_meta( $thread_id, 'auto_exclude_hash' );
        }

        $kses_fields = array( 'must_join_message', 'not_allowed_text', 'not_allowed_reply_text', 'must_login_text', 'closed_message' );
        foreach ( $kses_fields as $field ) {
            if ( isset( $settings[ $field ] ) && ! empty( $settings[ $field ] ) ) {
                $settings[ $field ] = wp_kses( $settings[ $field ], 'user_description' );
            }
        }

        $text_fields = array( 'join_button_text', 'login_button_text', 'guest_button_text' );
        foreach ( $text_fields as $field ) {
            if ( isset( $settings[ $field ] ) && ! empty( $settings[ $field ] ) ) {
                $settings[ $field ] = sanitize_text_field( $settings[ $field ] );
            }
        }

        update_post_meta( $chat_id, 'bpbm-chat-settings', $settings );

        $notifications_enabled = true;

        if ( $settings['hide_from_thread_list'] === '1' ) {
            Better_Messages()->functions->update_thread_meta( $thread_id, 'exclude_from_threads_list', '1' );
            $notifications_enabled = false;
        } else {
            Better_Messages()->functions->delete_thread_meta( $thread_id, 'exclude_from_threads_list' );
        }

        if ( $settings['enable_notifications'] === '1' ) {
            Better_Messages()->functions->update_thread_meta( $thread_id, 'enable_notifications', '1' );
        } else {
            Better_Messages()->functions->delete_thread_meta( $thread_id, 'enable_notifications' );
            $notifications_enabled = false;
        }

        if ( ! $notifications_enabled ) {
            Better_Messages()->functions->update_thread_meta( $thread_id, 'email_disabled', '1' );
        } else {
            Better_Messages()->functions->delete_thread_meta( $thread_id, 'email_disabled' );
        }

        if ( isset( $settings['can_join'] ) ) {
            update_post_meta( $chat_id, 'bpbm-chat-can-join', $settings['can_join'] );
        } else {
            delete_post_meta( $chat_id, 'bpbm-chat-can-join' );
            Better_Messages()->functions->delete_thread_meta( $thread_id, 'auto_exclude_hash' );
        }

        if ( isset( $settings['auto_add'] ) ) {
            update_post_meta( $chat_id, 'bpbm-chat-auto-add', $settings['auto_add'] );
        } else {
            delete_post_meta( $chat_id, 'bpbm-chat-auto-add' );
            Better_Messages()->functions->delete_thread_meta( $thread_id, 'auto_add_hash' );
        }

        $this->sync_auto_add_users( $chat_id );

        do_action( 'better_messages_thread_updated', $thread_id );
        do_action( 'better_messages_info_changed', $thread_id );
    }

    public function rest_thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ){
        if( $thread_type !== 'chat-room'){
            return $thread_item;
        }

        $chat_id = (int) Better_Messages()->functions->get_thread_meta($thread_id, 'chat_id');
        $settings = $this->get_chat_settings( $chat_id );

        $recipients = Better_Messages()->functions->get_recipients( $thread_id );



        $is_participant = isset( $recipients[$user_id] );

        if( has_post_thumbnail( $chat_id ) ) {
            $image_id = get_post_thumbnail_id($chat_id);

            if ($image_id) {
                $image_src = wp_get_attachment_image_src($image_id, [100, 100]);
                if ($image_src) {
                    $thread_item['image'] = $image_src[0];
                }
            }
        }

        $thread_item['chatRoom']['id']                   = (int) $chat_id;

        $post = get_post( $chat_id );
        $is_closed = ( $post && $post->post_status === 'draft' );
        $thread_item['chatRoom']['isClosed']             = $is_closed;

        $template =  $settings['template'];
        $thread_item['chatRoom']['template']             = $template;
        $thread_item['chatRoom']['modernLayout']         = $settings['modernLayout'];

        $thread_item['chatRoom']['onlyJoinedCanRead']    = ( $settings['only_joined_can_read'] === '1' );
        $thread_item['chatRoom']['enableFiles']          = ( $settings['enable_files'] === '1' );
        $thread_item['chatRoom']['guestAllowed']         = in_array( 'bm-guest', $settings['can_join'] );
        $thread_item['chatRoom']['mustJoinMessage']      = $settings['must_join_message'];
        $thread_item['chatRoom']['joinButtonText']       = $settings['join_button_text'];
        $thread_item['chatRoom']['notAllowedText']       = $settings['not_allowed_text'];
        $thread_item['chatRoom']['notAllowedReplyText']  = $settings['not_allowed_reply_text'];
        $thread_item['chatRoom']['mustLoginText']        = $settings['must_login_text'];
        $thread_item['chatRoom']['loginButtonText']      = $settings['login_button_text'];
        $thread_item['chatRoom']['guestButtonText']        = $settings['guest_button_text'];
        $thread_item['chatRoom']['showOnlineUsers']        = ( $settings['show_online_users'] === '1' );
        $thread_item['chatRoom']['closedMessage']            = $settings['closed_message'];


        if( $include_personal ) {
            $is_moderator = user_can( $user_id, 'manage_options') || Better_Messages()->functions->is_thread_moderator( $thread_id, $user_id ) ;

            $auto_join = $this->auto_join_enabled( $chat_id, $user_id );

            if( $auto_join && ! $is_participant ){
                $is_participant = $this->add_to_chat( $user_id, $chat_id );
            }

            $thread_item['chatRoom']['autoJoin'] = $auto_join;
            $thread_item['chatRoom']['isJoined'] = $is_participant;
            $thread_item['chatRoom']['canJoin']  = $this->user_can_join($user_id, $chat_id);
            $thread_item['chatRoom']['hideParticipants'] = ( ! $is_moderator && $settings['hide_participants'] === '1' );
            $thread_item['chatRoom']['hideParticipantsCount'] = ( ! $is_moderator && $settings['hide_participants_count'] === '1' );

            if ( ! $is_participant ) {
                $thread_item['isHidden'] = 1;
                $thread_item['permissions']['canReply'] = false;
                $thread_item['permissions']['canMinimize'] = false;
                $thread_item['permissions']['canMuteThread'] = false;
                $thread_item['chatRoom']['hideParticipants'] = true;
                $thread_item['chatRoom']['hideParticipantsCount'] = true;
            } else {
                if( $is_moderator ){
                    $thread_item['restricted'] = Better_Messages()->moderation->get_restricted_users( $thread_id );
                }

                if ( $is_closed && ! $is_moderator ) {
                    $thread_item['permissions']['canReply'] = false;
                    $thread_item['permissions']['canReplyMsg']['chat_room_closed'] = $settings['closed_message'];
                } else {
                    $can_reply = $this->user_can_reply( $user_id, $chat_id );
                    $thread_item['permissions']['canReply'] = $can_reply;

                    if( ! $can_reply ){
                        if( count($thread_item['permissions']['canReplyMsg']) === 0 ) $thread_item['permissions']['canReplyMsg']['cant_reply_to_chat'] = $settings['not_allowed_reply_text'];
                    }
                }
            }
        }

        return $thread_item;
    }

    public function on_user_register( $user_id, $userdata = null ){
        $roles = Better_Messages()->functions->get_user_roles( $user_id );

        if( count($roles) === 0 ) return false;

        global $wpdb;

        $clauses = [];

        foreach( $roles as $role ){
            $clauses[] = $wpdb->prepare("( `postmeta`.`meta_key` = 'bpbm-chat-auto-add' AND `postmeta`.`meta_value` LIKE %s )", '%"' . $role . '"%');
        }

        $chat_ids = $wpdb->get_col("SELECT 
        `posts`.`ID`
        FROM {$wpdb->posts} posts
        INNER JOIN {$wpdb->postmeta} postmeta 
        ON ( `posts`.`ID` = `postmeta`.`post_id` ) 
        WHERE 1=1  
        AND ( " . implode(' OR ', $clauses ) . " ) 
        AND `posts`.`post_type` = 'bpbm-chat' 
        GROUP BY `posts`.ID");

        if( count( $chat_ids ) > 0 ){
            foreach ( $chat_ids as $chat_id ) {
                $this->add_to_chat( $user_id, $chat_id );
            }
        }
    }

    public function guest_registered( $guest_id ){
        global $wpdb;

        $guest_id =  -1 * abs( $guest_id );

        $chat_ids = $wpdb->get_col("SELECT 
        `posts`.`ID`
        FROM {$wpdb->posts} posts
        INNER JOIN {$wpdb->postmeta} postmeta 
        ON ( `posts`.`ID` = `postmeta`.`post_id` ) 
        WHERE 1=1  
        AND `postmeta`.`meta_key` = 'bpbm-chat-auto-add'
        AND `postmeta`.`meta_value` LIKE '%bm-guest%'
        AND `posts`.`post_type` = 'bpbm-chat'   
        GROUP BY `posts`.ID");

        if( count( $chat_ids ) > 0 ){
            foreach ( $chat_ids as $chat_id ) {
                $this->add_to_chat( $guest_id, $chat_id );
            }
        }
    }

    public function on_user_role_change( $user_id, $role, $old_roles = [] ){
        $old_roles[] = $role;
        $this->sync_roles_update( array_unique( $old_roles ) );
    }

    public function on_chat_update( $post_ID, $post, $update ){
        $thread_id = $this->get_chat_thread_id( $post_ID );

        $name = get_the_title( $post_ID );
        global $wpdb;

        $wpdb->update(
            bm_get_table('threads'),
            array(
                'subject'   => $name,
            ),
            array(
                'id' => $thread_id,
            ),
            array( '%s' ), array( '%d' )
        );

        wp_cache_delete( 'thread_' . $thread_id . '_type', 'bm_messages' );
        wp_cache_delete( 'thread_' . $thread_id, 'bm_messages' );
    }

    public function on_chat_delete( $post_ID ){
        $post = get_post( $post_ID );
        if( $post->post_type === 'bpbm-chat' ){
            $thread_id = $this->get_chat_thread_id( $post_ID );
            Better_Messages()->functions->erase_thread( $thread_id );
        }
    }

    public function on_message_sent( $message )
    {
        if( ! isset($message->thread_id) ) return false;

        $thread_id = $message->thread_id;
        $chat_id   = Better_Messages()->functions->get_thread_meta( $thread_id, 'chat_id' );

        if( ! $chat_id ) return false;
        global $wpdb;
        $wpdb->update(bm_get_table('recipients'), ['unread_count' => 0], ['thread_id' => $thread_id], ['%d'], ['%d']);
        Better_Messages()->hooks->clean_thread_cache( $thread_id );

        return true;
    }

    public function auto_join_enabled( $chat_id, $user_id ){
        $settings = Better_Messages()->chats->get_chat_settings( $chat_id );

        $auto_join = $settings['auto_join'] === '1';

        if( ! $auto_join && isset($settings['auto_add']) && count($settings['auto_add']) > 0 ){
            $user_roles = Better_Messages()->functions->get_user_roles($user_id);
            $common_roles = array_intersect($user_roles, $settings['auto_add']);
            if( count($common_roles) > 0 ){
                $auto_join = true;
            }
        }

        return $auto_join;
    }

    public function leave_chat( WP_REST_Request $request ){
        global $wpdb;

        $user_id = Better_Messages()->functions->get_current_user_id();
        $chat_id = intval($request->get_param('id'));

        $thread_id = $this->get_chat_thread_id( $chat_id );

        $auto_join = $this->auto_join_enabled( $chat_id, $user_id );

        if( $auto_join ){
            return new WP_Error(
                'rest_forbidden',
                _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                array( 'status' => rest_authorization_required_code() )
            );
        }

        $result = false;

        $userIsParticipant = (bool) $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM `" . bm_get_table('recipients') . "` WHERE `user_id` = %d AND `thread_id` = %d
        ", $user_id, $thread_id));

        if( $userIsParticipant ) {
            $result = (bool) $wpdb->delete(
                bm_get_table('recipients'),
                array(
                    'user_id'   => $user_id,
                    'thread_id' => $thread_id
                ),
                array( '%d', '%d' )
            );
        }

        Better_Messages()->hooks->clean_thread_cache( $thread_id );

        do_action( 'better_messages_after_chat_left', $thread_id, $chat_id );
        do_action( 'better_messages_thread_updated', $thread_id );
        do_action( 'better_messages_info_changed',   $thread_id );

        $return = Better_Messages()->api->get_threads( [ $thread_id ], false, false );
        $return['result'] = $result;

        return $return;
    }

    public function join_chat( WP_REST_Request $request ){
        $user_id = Better_Messages()->functions->get_current_user_id();
        $chat_id = intval($request->get_param('id'));

        $is_joined = $this->add_to_chat( $user_id, $chat_id );

        $thread_id = $this->get_chat_thread_id( $chat_id );

        $return = Better_Messages()->api->get_threads( [ $thread_id ], false, false );

        $return['result'] = $is_joined;

        return $return;
    }

    public function add_to_chat( $user_id, $chat_id ){
        if( ! $this->user_can_join( $user_id, $chat_id ) ){
            return false;
        }

        $thread_id = $this->get_chat_thread_id( $chat_id );

        $result = Better_Messages()->functions->add_participant_to_thread( $thread_id, $user_id );

        do_action( 'better_messages_after_chat_join', $thread_id, $chat_id );
        do_action( 'better_messages_thread_updated', $thread_id );
        do_action( 'better_messages_info_changed',   $thread_id );

        return $result;
    }

    public function register_post_type(){
        $args = array(
            'public'               => false,
            'labels'               => [
                'name'          => _x( 'Chat Rooms', 'Chat Rooms', 'bp-better-messages' ),
                'singular_name' => _x( 'Chat Room', 'Chat Rooms', 'bp-better-messages' ),
                'add_new'       => _x( 'Create new Chat Room', 'Chat Rooms', 'bp-better-messages' ),
                'add_new_item'  => _x( 'Create new Chat Room', 'Chat Rooms', 'bp-better-messages' ),
                'edit_item'     => _x( 'Edit Chat Room', 'Chat Rooms', 'bp-better-messages' ),
                'new_item'      => _x( 'New Chat Room', 'Chat Rooms', 'bp-better-messages' ),
                'featured_image'        => _x( 'Chat Thumbnail', 'Chat Rooms', 'bp-better-messages' ),
                'set_featured_image'    => _x( 'Set chat thumbnail', 'Chat Rooms', 'bp-better-messages' ),
                'remove_featured_image' => _x( 'Remove chat thumbnail', 'Chat Rooms', 'bp-better-messages' ),
                'use_featured_image'    => _x( 'Use as chat thumbnail', 'Chat Rooms', 'bp-better-messages' ),
            ],
            'publicly_queryable'   => false,
            'show_ui'              => true,
            'show_in_menu'         => false,
            'show_in_rest'         => true,
            'menu_position'        => 1,
            'query_var'            => false,
            'capability_type'      => 'page',
            'has_archive'          => false,
            'hierarchical'         => false,
            'show_in_admin_bar'    => false,
            'show_in_nav_menus'    => false,
            'supports'             => array( 'title', 'thumbnail' )

        );

        register_post_type( 'bpbm-chat', $args );
    }

    public function chat_participants( $post ){
        echo '<div class="bm-chat-participants" data-chat-id="' . intval( $post->ID ) . '">' . __( 'Loading', 'bp-better-messages' ) . '</div>';
    }

    public function get_chat_settings( $chat_id ){
        $defaults = array(
            'only_joined_can_read'            => '0',
            'enable_chat_email_notifications' => '0',
            'can_join'                        => [],
            'can_reply'                       => [],
            'auto_add'                        => [],
            'template'                        => 'default',
            'modernLayout'                    => 'default',
            'auto_join'                       => '0',
            'auto_exclude'                    => '0',
            'enable_notifications'            => '0',
            'allow_guests'                    => '0',
            'hide_participants'               => '0',
            'hide_participants_count'         => '0',
            'show_online_users'              => '0',
            'enable_files'                    => '0',
            'hide_from_thread_list'           => '1',
            'must_join_message'               => _x('You need to join this chat room to send messages', 'Chat rooms settings page', 'bp-better-messages'),
            'join_button_text'                => _x('Join chat room', 'Chat rooms settings page', 'bp-better-messages'),
            'not_allowed_text'                => _x('You are not allowed to join this chat room', 'Chat rooms settings page', 'bp-better-messages'),
            'not_allowed_reply_text'          => _x('You are not allowed to reply in this chat room', 'Chat rooms settings page', 'bp-better-messages'),
            'must_login_text'                 => _x('You need to login to website to send messages', 'Chat rooms settings page', 'bp-better-messages'),
            'login_button_text'               => _x('Login', 'Chat rooms settings page', 'bp-better-messages'),
            'guest_button_text'               => _x('Chat as Guest', 'Chat rooms settings page', 'bp-better-messages'),
            'closed_message'                  => _x('This chat room is currently closed', 'Chat rooms settings page', 'bp-better-messages')
        );

        $args = get_post_meta( $chat_id, 'bpbm-chat-settings', true );

        if( empty($args) || ! is_array($args) ){
            $args = array();
        }

        $result = wp_parse_args( $args, $defaults );

        if( isset($result['allow_guests_chat']) && $result['allow_guests_chat'] === '1' ){
            $result['can_join'][] = 'bm-guest';
            $result['can_reply'][] = 'bm-guest';
        }

        return $result;
    }

    public function save_post( $post_id, $post ){
        if( ! isset($_POST['bpbm_save_chat_nonce']) ){
            return $post->ID;
        }

        //Verify it came from proper authorization.
        if ( ! wp_verify_nonce($_POST['bpbm_save_chat_nonce'], 'bpbm-save-chat-settings-' . $post->ID ) ) {
            return $post->ID;
        }

        //Check if the current user can edit the post
        if ( ! current_user_can( 'manage_options' ) ) {
            return $post->ID;
        }

        $thread_id = $this->get_chat_thread_id( $post->ID );

        if( isset( $_POST['bpbm'] ) && is_array($_POST['bpbm']) ){
            $settings = (array) $_POST['bpbm'];

            if ( ! isset( $settings['only_joined_can_read'] ) ) {
                $settings['only_joined_can_read'] = '0';
            }

            if ( ! isset( $settings['auto_join'] ) ) {
                $settings['auto_join'] = '0';
            }

            if ( ! isset( $settings['auto_exclude'] ) ) {
                $settings['auto_exclude'] = '0';
                Better_Messages()->functions->delete_thread_meta( $thread_id, 'auto_exclude_hash' );
            }

            if ( ! isset( $settings['hide_participants'] ) ) {
                $settings['hide_participants'] = '0';
            }

            if ( ! isset( $settings['hide_participants_count'] ) ) {
                $settings['hide_participants_count'] = '0';
            }

            if ( ! isset( $settings['enable_chat_email_notifications'] ) ) {
                $settings['enable_chat_email_notifications'] = '0';
            }

            if ( ! isset( $settings['enable_files'] ) ) {
                $settings['enable_files'] = '0';
            }

            if ( ! isset( $settings['hide_from_thread_list'] ) ) {
                $settings['hide_from_thread_list'] = '0';
            }

            if ( ! isset( $settings['enable_notifications'] ) ) {
                $settings['enable_notifications'] = '0';
            }

            if ( ! isset( $settings['allow_guests'] ) ) {
                $settings['allow_guests'] = '0';
            }

            if ( ! isset( $settings['show_online_users'] ) ) {
                $settings['show_online_users'] = '0';
            }

            if ( ! isset( $settings['must_join_message'] ) || empty( $settings['must_join_message'] )  ) {
                $settings['must_join_message'] = _x('You need to join this chat room to send messages', 'Chat rooms settings page', 'bp-better-messages');
            } else {
                $settings['must_join_message'] = wp_kses( $settings['must_join_message'], 'user_description' );
            }

            if ( ! isset( $settings['join_button_text'] ) || empty( $settings['join_button_text'] )  ) {
                $settings['join_button_text'] = _x('Join chat room', 'Chat rooms settings page', 'bp-better-messages');
            } else {
                $settings['join_button_text'] = sanitize_text_field( $settings['join_button_text'] );
            }

            if ( ! isset( $settings['login_button_text'] ) || empty( $settings['login_button_text'] )  ) {
                $settings['login_button_text'] = _x('Login', 'Chat rooms settings page', 'bp-better-messages');
            } else {
                $settings['login_button_text'] = sanitize_text_field( $settings['login_button_text'] );
            }

            if ( ! isset( $settings['guest_button_text'] ) || empty( $settings['guest_button_text'] )  ) {
                $settings['guest_button_text'] = _x('Chat as Guest', 'Chat rooms settings page', 'bp-better-messages');
            } else {
                $settings['guest_button_text'] = sanitize_text_field( $settings['guest_button_text'] );
            }

            if ( ! isset( $settings['must_login_text'] ) || empty( $settings['must_login_text'] )  ) {
                $settings['must_login_text'] =  _x('You need to login to website to send messages', 'Chat rooms settings page', 'bp-better-messages');
            } else {
                $settings['must_login_text'] = wp_kses( $settings['must_login_text'], 'user_description' );
            }

            if ( ! isset( $settings['not_allowed_text'] ) || empty( $settings['not_allowed_text'] )  ) {
                $settings['not_allowed_text'] = _x('You are not allowed to join this chat room', 'Chat rooms settings page', 'bp-better-messages');
            } else {
                $settings['not_allowed_text'] = wp_kses( $settings['not_allowed_text'], 'user_description' );
            }

            if ( ! isset( $settings['not_allowed_reply_text'] ) || empty( $settings['not_allowed_reply_text'] )  ) {
                $settings['not_allowed_reply_text'] = _x('You are not allowed to reply in this chat room', 'Chat rooms settings page', 'bp-better-messages');
            } else {
                $settings['not_allowed_reply_text'] = wp_kses( $settings['not_allowed_reply_text'], 'user_description' );
            }

            update_post_meta( $post->ID, 'bpbm-chat-settings', $settings );

            $notifications_enabled = true;

            if( $settings['hide_from_thread_list'] === '1' ) {
                Better_Messages()->functions->update_thread_meta($thread_id, 'exclude_from_threads_list', '1');
                $notifications_enabled = false;
            } else {
                Better_Messages()->functions->delete_thread_meta($thread_id, 'exclude_from_threads_list');
            }

            if( $settings['enable_notifications'] === '1' ) {
                Better_Messages()->functions->update_thread_meta($thread_id, 'enable_notifications', '1');
            } else {
                Better_Messages()->functions->delete_thread_meta($thread_id, 'enable_notifications');
                $notifications_enabled = false;
            }

            if( ! $notifications_enabled ){
                Better_Messages()->functions->update_thread_meta($thread_id, 'email_disabled', '1');
            } else {
                Better_Messages()->functions->delete_thread_meta( $thread_id, 'email_disabled' );
            }

            if( isset( $settings['can_join'] ) ) {
                update_post_meta( $post->ID, 'bpbm-chat-can-join', $settings['can_join'] );
            } else {
                delete_post_meta( $post->ID, 'bpbm-chat-can-join' );
                Better_Messages()->functions->delete_thread_meta( $thread_id, 'auto_exclude_hash' );
            }

            if( isset( $settings['auto_add'] ) ) {
                update_post_meta( $post->ID, 'bpbm-chat-auto-add', $settings['auto_add'] );
            } else {
                delete_post_meta( $post->ID, 'bpbm-chat-auto-add' );
                Better_Messages()->functions->delete_thread_meta( $thread_id, 'auto_add_hash' );
            }

            $this->sync_auto_add_users( $post->ID );

            do_action( 'better_messages_thread_updated', $thread_id );
            do_action( 'better_messages_info_changed',   $thread_id );
        }

    }

    /**
     * @deprecated Use render_chat_settings() instead
     */
    public function bpbm_chat_settings( $post ){
        $this->render_chat_settings( $post );
    }

    public function render_chat_settings( $post ){
        if ( $post->post_type !== 'bpbm-chat' ) {
            return;
        }

        $roles = get_editable_roles();
        if(isset($roles['administrator'])) unset( $roles['administrator'] );

        $roles['bm-guest'] = [
            'name' => _x('Guests', 'Settings page', 'bp-better-messages' )
        ];

        wp_nonce_field( 'bpbm-save-chat-settings-' . $post->ID, 'bpbm_save_chat_nonce' );

        $settings = $this->get_chat_settings( $post->ID );
        $thread_id = $this->get_chat_thread_id( $post->ID );
        $inbox_url = $thread_id ? Better_Messages()->functions->get_user_messages_url( get_current_user_id(), $thread_id ) : '';
        $is_websocket = Better_Messages()->settings['mechanism'] === 'websocket' ? '1' : '0'; ?>
        <div class="bm-chat-settings" data-chat-id="<?php echo esc_attr($post->ID); ?>" data-thread-id="<?php echo esc_attr($thread_id ? $thread_id : ''); ?>" data-inbox-url="<?php echo esc_attr($inbox_url); ?>" data-settings="<?php echo esc_attr(json_encode($settings)); ?>" data-roles="<?php echo esc_attr(json_encode($roles)); ?>" data-is-websocket="<?php echo esc_attr($is_websocket); ?>">
            <p style="text-align: center"><?php _ex( 'Loading',  'WP Admin', 'bp-better-messages' ); ?></p>
        </div>
    <?php
    }

    public function layout( $args ){
        $chat_id = $args['id'];
        $disable_init = isset($args['disable_auto_init']);
        $full_screen = isset($args['full_screen']) ? $args['full_screen'] : '0';

        if (defined('WP_DEBUG') && true === WP_DEBUG) {
            // some debug to add later
        } else {
            error_reporting(0);
        }

        $thread_id     = $this->get_chat_thread_id( $chat_id );

        if( ! $thread_id ) return false;

        $chat_settings = $this->get_chat_settings( $chat_id );

        if( ! is_user_logged_in() ){
            $allow_guests = $chat_settings['allow_guests'] === '1';
            if( ! $allow_guests ) {
                return Better_Messages()->functions->render_login_form();
            } else {
                Better_Messages()->enqueue_js();
                Better_Messages()->enqueue_css();

                add_action('wp_footer', array( Better_Messages_Customize(), 'header_output' ), 100);
            }
        }

        $this->sync_auto_add_users( $chat_id );

        global $bpbm_errors;
        $bpbm_errors = [];

        do_action('bp_better_messages_before_chat', $chat_id, $thread_id );

        ob_start();

        if( ! Better_Messages()->functions->is_ajax() && count( $bpbm_errors ) > 0 ) {
            echo '<p class="bpbm-notice">' . implode('</p><p class="bpbm-notice">', $bpbm_errors) . '</p>';
        }

        $initialHeight = (int) apply_filters( 'bp_better_messages_max_height', Better_Messages()->settings['messagesHeight'] );
        $class = 'bp-messages-chat-wrap';
        if( $disable_init ) $class .= ' bm-disable-auto-init';
        echo '<div class="' . $class . '" style="height: ' . $initialHeight . 'px" data-thread-id="' .  esc_attr($thread_id) . '" data-chat-id="'  . esc_attr($chat_id) . '" data-full-screen="' . esc_attr($full_screen) . '">' . Better_Messages()->functions->container_placeholder() . '</div>';

        $content = ob_get_clean();
        $content = str_replace( 'loading="lazy"', '', $content );

        $content = Better_Messages()->functions->minify_html( $content );

        do_action('bp_better_messages_after_chat', $chat_id, $thread_id);

        return $content;
    }

    public function user_can_join( $user_id, $chat_id ){
        if( user_can( $user_id, 'manage_options') ) return true;
        if( Better_Messages()->functions->is_ai_bot_user( $user_id ) ) return true;

        $post = get_post( $chat_id );
        if ( $post && $post->post_status === 'draft' ) return false;

        $settings = $this->get_chat_settings( $chat_id );
        $thread_id = $this->get_chat_thread_id( $chat_id );

        $has_access = false;

        $user_roles = Better_Messages()->functions->get_user_roles($user_id);

        foreach ($user_roles as $role) {
            if (in_array($role, $settings['can_join'])) {
                $has_access = true;
            }
        }

        return apply_filters( 'better_messages_chat_user_can_join', $has_access, $user_id, $chat_id, $thread_id );
    }

    public function user_can_reply( $user_id, $chat_id ){
        if( user_can( $user_id, 'manage_options') ) return true;
        if( Better_Messages()->functions->is_ai_bot_user( $user_id ) ) return true;

        $post = get_post( $chat_id );
        if ( $post && $post->post_status === 'draft' ) return false;

        $settings = $this->get_chat_settings( $chat_id );
        $thread_id = $this->get_chat_thread_id( $chat_id );

        $has_access = false;

        $user_roles = Better_Messages()->functions->get_user_roles($user_id);

        foreach ($user_roles as $role) {
            if (in_array($role, $settings['can_reply'])) {
                $has_access = true;
            }
        }

        return Better_Messages()->functions->can_send_message_filter( $has_access, $user_id, $thread_id );
    }

    public function get_chat_thread_id( $chat_id ){
        global $wpdb;

        $thread_id = (int) $wpdb->get_var( $wpdb->prepare( "
        SELECT bm_thread_id 
        FROM `" . bm_get_table('threadsmeta') . "` 
        WHERE `meta_key` = 'chat_id' 
        AND   `meta_value` = %s
        ", $chat_id ) );

        if( $thread_id === 0 ) {
            $thread_id = false;
        } else {
            $messages_count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*)  FROM `" . bm_get_table('threads') . "` WHERE `id` = %d", $thread_id));

            if( $messages_count === 0 ) {
                $thread_id = false;
            }
        }

        if( ! $thread_id ) {
            $chat = get_post($chat_id);
            if( ! $chat ) return false;

            $wpdb->query( $wpdb->prepare( "
            DELETE 
            FROM `" . bm_get_table('threadsmeta') . "` 
            WHERE `meta_key` = 'chat_id' 
            AND   `meta_value` = %s
            ", $chat_id ) );

            $name = get_the_title( $chat_id );

            $wpdb->insert(
                bm_get_table('threads'),
                array(
                    'subject' => $name,
                    'type'    => 'chat-room'
                )
            );

            $thread_id = $wpdb->insert_id;

            Better_Messages()->functions->update_thread_meta( $thread_id, 'chat_thread', true );
            Better_Messages()->functions->update_thread_meta( $thread_id, 'chat_id', $chat_id );

            wp_cache_delete( 'thread_' . $thread_id . '_type', 'bm_messages' );
            wp_cache_delete( 'thread_' . $thread_id, 'bm_messages' );
        }

        return $thread_id;
    }

    public function sync_roles_update( $roles = [] ){
        if( count( $roles ) === 0 ) return false;

        global $wpdb;

        $clauses = [];

        foreach( $roles as $role ){
            $clauses[] = $wpdb->prepare("( `postmeta`.`meta_key` = 'bpbm-chat-auto-add' AND `postmeta`.`meta_value` LIKE %s )", '%"' . $role . '"%');
        }

        $chat_ids = $wpdb->get_col("SELECT 
        `posts`.`ID`
        FROM {$wpdb->posts} posts
        INNER JOIN {$wpdb->postmeta} postmeta 
        ON ( `posts`.`ID` = `postmeta`.`post_id` ) 
        WHERE 1=1  
        AND ( " . implode(' OR ', $clauses ) . " ) 
        AND `posts`.`post_type` = 'bpbm-chat' 
        GROUP BY `posts`.ID");

        foreach( $chat_ids as $chat_id ){
            if( ! wp_get_scheduled_event( 'better_messages_chat_room_sync_auto_add_users', [ $chat_id ] ) ){
                wp_schedule_single_event( time(), 'better_messages_chat_room_sync_auto_add_users', [ $chat_id ] );
            }
        }
    }

    public function sync_auto_add_users( $chat_id ){
        $thread_id  = $this->get_chat_thread_id( $chat_id );

        if( ! $thread_id ) return false;

        $settings = Better_Messages()->chats->get_chat_settings( $chat_id );


        $auto_exclude = $settings['auto_exclude'] === '1';
        $auto_add     = count( $settings['auto_add'] ) > 0;

        if( ! $auto_add && ! $auto_exclude ) {
            return false;
        }

        $changed = false;

        set_time_limit(0);
        ignore_user_abort(true);
        ini_set('memory_limit', '-1');

        global $wpdb;

        if( $auto_add ){
            $roles = $settings['auto_add'];
            $role_placeholders = implode(',', array_fill(0, count($roles), '%s'));

            $users_hash_sql = $wpdb->prepare("
            SELECT MD5(GROUP_CONCAT(DISTINCT(`roles`.`user_id`))) as users_hash
            FROM `" . bm_get_table('roles') . "` `roles`
            LEFT JOIN `" . bm_get_table('moderation') . "` `moderation`
            ON `roles`.`user_id` = `moderation`.`user_id`
            AND `moderation`.`thread_id` = %d
            AND `moderation`.`type` = 'ban'
            WHERE `roles`.`role` IN ({$role_placeholders})
            AND `moderation`.`user_id` IS NULL
            ORDER BY `roles`.`user_id` ASC", array_merge([$thread_id], $roles));

            $users_hash = $wpdb->get_var($users_hash_sql);

            $thread_hash = Better_Messages()->functions->get_thread_meta( $thread_id, 'auto_add_hash' );

            if( $users_hash !== $thread_hash ){
                $not_added_users_count_sql = $wpdb->prepare("
                SELECT COUNT(DISTINCT(`roles`.`user_id`)) as user_ids
                FROM `" . bm_get_table('roles') . "` `roles`
                LEFT JOIN `" . bm_get_table('recipients') . "` `recipients`
                ON `roles`.`user_id` = `recipients`.`user_id`
                AND `recipients`.`thread_id` = %d
                LEFT JOIN `" . bm_get_table('moderation') . "` `moderation`
                ON `roles`.`user_id` = `moderation`.`user_id`
                AND `moderation`.`thread_id` = %d
                AND `moderation`.`type` = 'ban'
                AND `moderation`.`expiration` > NOW()
                WHERE `roles`.`role` IN ({$role_placeholders})
                AND `recipients`.`user_id` IS NULL
                AND `moderation`.`user_id` IS NULL", array_merge([$thread_id, $thread_id], $roles));

                $not_added_users_count = (int) $wpdb->get_var($not_added_users_count_sql);

                if( $not_added_users_count > 0 ){
                    $insert_sql = $wpdb->prepare("
                    INSERT INTO " . bm_get_table('recipients') . " (user_id, thread_id, unread_count, is_deleted)
                    SELECT DISTINCT(`roles`.`user_id`) as user_id, %d, 0, 0
                    FROM `" . bm_get_table('roles') . "` `roles`
                    LEFT JOIN `" . bm_get_table('recipients') . "` `recipients`
                    ON `roles`.`user_id` = `recipients`.`user_id`
                    AND `recipients`.`thread_id` = %d
                    LEFT JOIN `" . bm_get_table('moderation') . "` `moderation`
                    ON `roles`.`user_id` = `moderation`.`user_id`
                    AND `moderation`.`thread_id` = %d
                    AND `moderation`.`type` = 'ban'
                    AND `moderation`.`expiration` > NOW()
                    WHERE `roles`.`role` IN ({$role_placeholders})
                    AND `recipients`.`user_id` IS NULL
                    AND `moderation`.`user_id` IS NULL", array_merge([$thread_id, $thread_id, $thread_id], $roles));

                    $wpdb->query($insert_sql);

                    $changed = true;
                }

                Better_Messages()->functions->update_thread_meta( $thread_id, 'auto_add_hash', $users_hash );
            }
        }


        if( $auto_exclude ){
            $not_exclude_roles = array_merge(['administrator'], $settings['can_join']);
            $exclude_role_placeholders = implode(',', array_fill(0, count($not_exclude_roles), '%s'));

            $users_hash_sql = $wpdb->prepare("SELECT MD5(GROUP_CONCAT(DISTINCT(`roles`.`user_id`))) as users_hash
            FROM `" . bm_get_table('roles') . "` `roles`
            WHERE `roles`.`role` IN ({$exclude_role_placeholders})
            ORDER BY `roles`.`user_id` ASC", $not_exclude_roles);

            $users_hash = $wpdb->get_var($users_hash_sql);

            $thread_hash = Better_Messages()->functions->get_thread_meta( $thread_id, 'auto_exclude_hash' );

            if( $users_hash !== $thread_hash ){
                $to_exclude_users_sql = $wpdb->prepare("
                SELECT DISTINCT(`roles`.`user_id`) as user_id
                FROM `" . bm_get_table('roles') . "` `roles`
                LEFT JOIN `" . bm_get_table('recipients') . "` `recipients`
                    ON `roles`.`user_id` = `recipients`.`user_id`
                AND `recipients`.`thread_id` = %d
                WHERE `recipients`.`user_id` NOT IN (
                    SELECT DISTINCT(`roles`.`user_id`) as user_id
                    FROM `" . bm_get_table('roles') . "` `roles`
                    LEFT JOIN `" . bm_get_table('recipients') . "` `recipients`
                        ON `roles`.`user_id` = `recipients`.`user_id`
                        AND `recipients`.`thread_id` = %d
                    WHERE `roles`.`role` IN ({$exclude_role_placeholders})
                    AND `recipients`.`user_id` IS NOT NULL
                )", array_merge([$thread_id, $thread_id], $not_exclude_roles));

                $to_exclude_users = array_map('intval', $wpdb->get_col($to_exclude_users_sql));

                if( count($to_exclude_users) > 0 ){
                    // Ensure AI bot users (negative IDs) are never auto-excluded
                    $to_exclude_users = array_filter( $to_exclude_users, function( $uid ) {
                        return $uid > 0 || ! Better_Messages()->functions->is_ai_bot_user( $uid );
                    });
                }

                if( count($to_exclude_users) > 0 ){
                    $delete_sql = $wpdb->prepare("
                    DELETE FROM `" . bm_get_table('recipients') . "`
                    WHERE `user_id` IN (" . implode(',', $to_exclude_users) . ")
                    AND `thread_id` = %d", $thread_id);

                    $wpdb->query( $delete_sql );

                    $changed = true;
                }

                Better_Messages()->functions->update_thread_meta( $thread_id, 'auto_exclude_hash', $users_hash );
            }
        }

        if( $changed ) {
            Better_Messages()->hooks->clean_thread_cache($thread_id);
            do_action('better_messages_thread_updated', $thread_id);
            do_action('better_messages_info_changed', $thread_id);
        }
    }
}

function Better_Messages_Chats()
{
    return Better_Messages_Chats::instance();
}
