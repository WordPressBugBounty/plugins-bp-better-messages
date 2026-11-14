<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Message_Requests' ) ):

    class Better_Messages_Message_Requests
    {
        const REQUEST = 4;

        const DECLINED = 5;

        const MODES = array( 'off', 'new', 'anyone' );

        const CHOICE_META = 'bm_message_requests';

        private $starting = null;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Message_Requests();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'better_messages_before_new_thread', array( $this, 'capture_start' ), 70, 2 );
            add_action( 'bp_better_messages_new_thread_created', array( $this, 'thread_created' ), 5, 2 );
            add_action( 'better_messages_participants_added', array( $this, 'participants_added' ), 5, 2 );
            add_action( 'better_messages_message_sent', array( $this, 'accept_on_reply' ), 1 );
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
        }

        public function mode()
        {
            $mode = isset( Better_Messages()->settings['spamMessageRequests'] ) ? (string) Better_Messages()->settings['spamMessageRequests'] : 'off';

            return in_array( $mode, self::MODES, true ) ? $mode : 'off';
        }

        public function members_choose()
        {
            return $this->mode() !== 'off' && isset( Better_Messages()->settings['spamMessageRequestsChoice'] ) && Better_Messages()->settings['spamMessageRequestsChoice'] === '1';
        }

        public function member_choice( $user_id )
        {
            $choice = Better_Messages()->functions->get_user_meta( (int) $user_id, self::CHOICE_META, true );

            return $choice === 'deliver' ? 'deliver' : 'requests';
        }

        public function set_member_choice( $user_id, $choice )
        {
            Better_Messages()->functions->update_user_meta( (int) $user_id, self::CHOICE_META, $choice === 'deliver' ? 'deliver' : 'requests' );
        }

        public function applies( $sender_id, $recipient_id, $thread_id )
        {
            $sender_id    = (int) $sender_id;
            $recipient_id = (int) $recipient_id;
            $thread_id    = (int) $thread_id;
            $mode         = $this->mode();

            if ( $mode === 'off' || $sender_id === 0 || $recipient_id === 0 || $sender_id === $recipient_id ) {
                return false;
            }

            if ( Better_Messages()->functions->get_thread_type( $thread_id ) !== 'thread' ) {
                return false;
            }

            if ( $recipient_id < 0 && Better_Messages()->functions->is_ai_bot_user( $recipient_id ) ) {
                return false;
            }

            $trust = Better_Messages()->anti_spam->trust( $sender_id );

            if ( $trust === 'trusted' || ( $mode === 'new' && $trust !== 'new' ) ) {
                return false;
            }

            if ( $this->members_choose() && $this->member_choice( $recipient_id ) === 'deliver' ) {
                return false;
            }

            if ( $this->share_conversation( $sender_id, $recipient_id, $thread_id ) ) {
                return false;
            }

            return (bool) apply_filters( 'better_messages_message_request', true, $sender_id, $recipient_id, $thread_id );
        }

        public function share_conversation( $user_a, $user_b, $except_thread = 0 )
        {
            global $wpdb;

            return (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1
                FROM `" . bm_get_table( 'recipients' ) . "` `a`
                INNER JOIN `" . bm_get_table( 'recipients' ) . "` `b` ON `b`.`thread_id` = `a`.`thread_id`
                INNER JOIN `" . bm_get_table( 'threads' ) . "` `t` ON `t`.`id` = `a`.`thread_id`
                WHERE `a`.`user_id` = %d
                AND `b`.`user_id` = %d
                AND `a`.`thread_id` <> %d
                AND `t`.`type` IN ('thread', 'booking')
                AND `a`.`is_muted` NOT IN (%d, %d)
                AND `b`.`is_muted` NOT IN (%d, %d)
                LIMIT 1",
                (int) $user_a, (int) $user_b, (int) $except_thread,
                self::REQUEST, self::DECLINED, self::REQUEST, self::DECLINED
            ) );
        }

        public function capture_start( &$args, &$errors )
        {
            if ( isset( $args['action'] ) && $args['action'] === 'probe' ) {
                return;
            }

            $this->starting = array(
                'sender' => isset( $args['sender_id'] ) ? (int) $args['sender_id'] : (int) Better_Messages()->functions->get_current_user_id(),
                'unique' => ! empty( $args['unique_key'] ),
            );
        }

        public function thread_created( $thread_id, $message_id = null )
        {
            $thread_id = (int) $thread_id;
            $start     = $this->starting;

            $this->starting = null;

            if ( $this->mode() === 'off' ) {
                return;
            }

            $participants = array_map( 'intval', array_keys( (array) Better_Messages()->functions->get_recipients( $thread_id, false ) ) );
            $sender       = $start ? (int) $start['sender'] : (int) Better_Messages()->functions->get_current_user_id();

            if ( ( $start && $start['unique'] ) || $sender === 0 || ! in_array( $sender, $participants, true ) ) {
                return;
            }

            $this->mark( $thread_id, $sender, array_diff( $participants, array( $sender ) ) );
        }

        public function participants_added( $thread_id, $user_ids )
        {
            if ( $this->mode() === 'off' ) {
                return;
            }

            $adder = (int) Better_Messages()->functions->get_current_user_id();

            if ( $adder === 0 || ! Better_Messages()->functions->is_thread_participant( $adder, (int) $thread_id ) ) {
                return;
            }

            $this->mark( (int) $thread_id, $adder, array_map( 'intval', (array) $user_ids ) );
        }

        private function mark( $thread_id, $sender_id, array $recipient_ids )
        {
            global $wpdb;

            $requests = array();

            foreach ( $recipient_ids as $recipient_id ) {
                if ( $recipient_id !== $sender_id && $this->applies( $sender_id, $recipient_id, $thread_id ) ) {
                    $requests[] = (int) $recipient_id;
                }
            }

            if ( empty( $requests ) ) {
                return array();
            }

            $wpdb->query( $wpdb->prepare(
                "UPDATE `" . bm_get_table( 'recipients' ) . "` SET `is_muted` = %d, `last_update` = %d
                WHERE `thread_id` = %d AND `user_id` IN (" . implode( ',', $requests ) . ")",
                self::REQUEST,
                Better_Messages()->functions->get_microtime(),
                $thread_id
            ) );

            Better_Messages()->functions->update_thread_meta( $thread_id, 'bm_request_from', $sender_id );

            wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );

            if ( function_exists( 'Better_Messages_Notification_Levels' ) ) {
                Better_Messages_Notification_Levels()->forget_thread( $thread_id );
            }

            if ( isset( Better_Messages()->hooks ) && method_exists( Better_Messages()->hooks, 'clean_thread_cache' ) ) {
                Better_Messages()->hooks->clean_thread_cache( $thread_id );
            }

            do_action( 'better_messages_message_requests_created', $thread_id, $sender_id, $requests );

            return $requests;
        }

        public function stored( $thread_id, $user_id )
        {
            global $wpdb;

            $stored = $wpdb->get_var( $wpdb->prepare(
                "SELECT `is_muted` FROM `" . bm_get_table( 'recipients' ) . "` WHERE `thread_id` = %d AND `user_id` = %d LIMIT 1",
                (int) $thread_id, (int) $user_id
            ) );

            return is_null( $stored ) ? null : (int) $stored;
        }

        public function is_request( $thread_id, $user_id )
        {
            return $this->stored( $thread_id, $user_id ) === self::REQUEST;
        }

        public function requester( $thread_id )
        {
            return (int) Better_Messages()->functions->get_thread_meta( (int) $thread_id, 'bm_request_from' );
        }

        public function recipient_levels( $thread_id )
        {
            global $wpdb;

            $thread_id = (int) $thread_id;

            if ( $this->requester( $thread_id ) === 0 ) {
                return array();
            }

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `user_id`, `is_muted` FROM `" . bm_get_table( 'recipients' ) . "` WHERE `thread_id` = %d AND `is_muted` IN (%d, %d)",
                $thread_id, self::REQUEST, self::DECLINED
            ) );

            $levels = array();

            foreach ( (array) $rows as $row ) {
                $levels[ (int) $row->user_id ] = (int) $row->is_muted;
            }

            return $levels;
        }

        public function forget_levels( $thread_id )
        {
            $thread_id = (int) $thread_id;

            wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );

            if ( function_exists( 'Better_Messages_Notification_Levels' ) ) {
                Better_Messages_Notification_Levels()->forget_thread( $thread_id );
            }
        }

        public function accept( $thread_id, $user_id )
        {
            global $wpdb;

            if ( ! $this->is_request( $thread_id, $user_id ) ) {
                return false;
            }

            $wpdb->query( $wpdb->prepare(
                "UPDATE `" . bm_get_table( 'recipients' ) . "` SET `is_muted` = 0, `last_update` = %d WHERE `thread_id` = %d AND `user_id` = %d",
                Better_Messages()->functions->get_microtime(), (int) $thread_id, (int) $user_id
            ) );

            Better_Messages()->functions->messages_mark_thread_read( (int) $thread_id, (int) $user_id );

            $this->after_change( (int) $thread_id, (int) $user_id );

            do_action( 'better_messages_message_request_accepted', (int) $thread_id, (int) $user_id, $this->requester( $thread_id ) );

            return true;
        }

        public function decline( $thread_id, $user_id, array $options = array() )
        {
            global $wpdb;

            $thread_id = (int) $thread_id;
            $user_id   = (int) $user_id;

            if ( ! $this->is_request( $thread_id, $user_id ) ) {
                return false;
            }

            $requester = $this->requester( $thread_id );

            $wpdb->query( $wpdb->prepare(
                "UPDATE `" . bm_get_table( 'recipients' ) . "` SET `is_muted` = %d, `is_deleted` = 1, `unread_count` = 0, `last_update` = %d WHERE `thread_id` = %d AND `user_id` = %d",
                self::DECLINED, Better_Messages()->functions->get_microtime(), $thread_id, $user_id
            ) );

            $this->after_change( $thread_id, $user_id );

            if ( $requester !== 0 ) {
                Better_Messages()->anti_spam->reaction( $requester, 'declined', $user_id );

                if ( ! empty( $options['report'] ) ) {
                    Better_Messages()->anti_spam->reaction( $requester, 'reported', $user_id );
                }

                if ( ! empty( $options['block'] ) && class_exists( 'Better_Messages_Block_Users' ) ) {
                    Better_Messages_Block_Users::instance()->block_user( $user_id, $requester );
                }
            }

            do_action( 'better_messages_message_request_declined', $thread_id, $user_id, $requester, $options );

            return true;
        }

        private function after_change( $thread_id, $user_id )
        {
            wp_cache_delete( 'bm_thread_recipients_' . $thread_id, 'bm_messages' );

            if ( function_exists( 'Better_Messages_Notification_Levels' ) ) {
                Better_Messages_Notification_Levels()->forget_thread( $thread_id );
            }

            if ( isset( Better_Messages()->hooks ) && method_exists( Better_Messages()->hooks, 'clean_thread_cache' ) ) {
                Better_Messages()->hooks->clean_thread_cache( $thread_id );
            }

            wp_cache_delete( $user_id, 'bp_messages_unread_count' );

            do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
        }

        public function accept_on_reply( $message )
        {
            if ( ! is_object( $message ) || ! empty( $message->is_update ) || empty( $message->thread_id ) || empty( $message->sender_id ) ) {
                return;
            }

            if ( $this->is_request( (int) $message->thread_id, (int) $message->sender_id ) ) {
                $this->accept( (int) $message->thread_id, (int) $message->sender_id );
            }
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/acceptRequest', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'accept_api' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/declineRequest', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'decline_api' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
            ) );
        }

        public function accept_api( WP_REST_Request $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = (int) Better_Messages()->functions->get_current_user_id();

            if ( ! $this->accept( $thread_id, $user_id ) ) {
                return new WP_Error( 'not_a_request', _x( 'This conversation is not a message request', 'Message requests', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            return Better_Messages_Rest_Api()->get_threads( array( $thread_id ) );
        }

        public function decline_api( WP_REST_Request $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = (int) Better_Messages()->functions->get_current_user_id();

            $done = $this->decline( $thread_id, $user_id, array(
                'block'  => (bool) $request->get_param( 'block' ),
                'report' => (bool) $request->get_param( 'report' ),
            ) );

            if ( ! $done ) {
                return new WP_Error( 'not_a_request', _x( 'This conversation is not a message request', 'Message requests', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            return array( 'result' => 'declined', 'thread_id' => $thread_id );
        }
    }

endif;

function Better_Messages_Message_Requests()
{
    return Better_Messages_Message_Requests::instance();
}
