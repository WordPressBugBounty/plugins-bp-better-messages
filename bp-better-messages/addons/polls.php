<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Polls' ) ) {

    class Better_Messages_Polls {

        const CONTEXT = 'better-messages';

        private $pending = null;

        private $self_update = null;

        public static function instance() {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Polls();
            }

            return $instance;
        }

        public function __construct() {
            add_filter( 'better_messages_client_message_meta_keys', array( $this, 'client_meta_keys' ) );
            add_action( 'better_messages_before_message_send', array( $this, 'before_message_send' ), 10, 2 );
            add_action( 'better_messages_message_sent', array( $this, 'message_sent' ), 5, 1 );
            add_action( 'better_messages_message_pending', array( $this, 'message_sent' ), 5, 1 );
            add_action( 'better_messages_before_message_delete', array( $this, 'before_message_delete' ), 10, 3 );
            add_filter( 'rest_request_before_callbacks', array( $this, 'refuse_edit' ), 10, 3 );
            add_filter( 'bp_better_messages_pre_format_message', array( $this, 'label_text' ), 5, 2 );

            add_filter( 'better_messages_rest_message_meta', array( $this, 'message_meta' ), 10, 4 );
            add_filter( 'better_messages_rest_message_user_fields', array( $this, 'message_user_fields' ), 10, 5 );
            add_filter( 'better_messages_rest_thread_item', array( $this, 'thread_item' ), 30, 5 );
            add_filter( 'better_messages_thread_self_update_extra', array( $this, 'self_update_extra' ), 10, 3 );
            add_filter( 'bp_better_messages_script_variable', array( $this, 'script_vars' ), 10, 1 );
            add_filter( 'better_messages_style_dependencies', array( $this, 'style_dependencies' ), 10, 1 );
            add_filter( 'better_messages_mobile_app_style_handles', array( $this, 'mobile_app_style_handles' ), 10, 1 );

            add_action( 'wordplus_poll_changed', array( $this, 'poll_changed' ), 10, 3 );
            add_filter( 'better_messages_system_message_text', array( $this, 'system_message_text' ), 10, 3 );
            add_action( 'rest_api_init', array( $this, 'routes' ) );

            add_action( 'init', array( $this, 'register_worker_task' ) );
            add_action( 'better_messages_polls_end', array( $this, 'end_due' ) );
            add_action( 'deleted_user', array( $this, 'erase_user' ) );
        }

        public function is_enabled() {
            return Better_Messages()->settings['pollsEnable'] === '1' && function_exists( 'wordplus_polls' ) && null !== wordplus_polls();
        }

        public function client_meta_keys( $keys ) {
            $keys[] = 'poll';

            return $keys;
        }

        public function type_allowed( $thread_id ) {
            $settings = Better_Messages()->settings;
            $type     = Better_Messages()->functions->get_thread_type( (int) $thread_id );

            switch ( $type ) {
                case 'chat-room':
                    $key = 'pollsChatRooms';
                    break;
                case 'group':
                    $key = 'pollsGroups';
                    break;
                case 'course':
                    $key = 'pollsCourses';
                    break;
                case 'booking':
                    $key = 'pollsGroupThreads';
                    break;
                default:
                    $key = Better_Messages()->notification_levels->is_one_to_one( $thread_id ) ? 'pollsPrivate' : 'pollsGroupThreads';
                    break;
            }

            return isset( $settings[ $key ] ) && $settings[ $key ] === '1';
        }

        public function role_allowed( $user_id ) {
            return Better_Messages()->functions->role_list_allows( 'pollsRestrictRoles', $user_id );
        }

        public function is_e2e_thread( $thread_id ) {
            return class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_e2e_thread( (int) $thread_id );
        }

        public function can_create( $thread_id, $user_id ) {
            $allowed = $this->is_enabled()
                && (int) $user_id !== 0
                && (int) $thread_id > 0
                && $this->type_allowed( $thread_id )
                && $this->role_allowed( $user_id )
                && ! $this->is_e2e_thread( $thread_id );

            return (bool) apply_filters( 'better_messages_user_can_create_poll', $allowed, (int) $user_id, (int) $thread_id );
        }

        public function thread_item( $thread_item, $thread_id, $thread_type, $personal_data, $user_id ) {
            if ( ! isset( $thread_item['permissions'] ) || ! is_array( $thread_item['permissions'] ) ) {
                return $thread_item;
            }

            $thread_item['permissions']['canCreatePoll'] = ! empty( $thread_item['permissions']['canReply'] )
                && $this->can_create( (int) $thread_id, (int) $user_id );

            return $thread_item;
        }

        public function script_vars( $vars ) {
            if ( $this->is_enabled() ) {
                $vars['polls'] = '1';
            }

            return $vars;
        }

        public function style_dependencies( $dependencies ) {
            if ( ! $this->is_enabled() ) {
                return $dependencies;
            }

            $file    = 'assets/css/polls.min.css';
            $version = Better_Messages()->version;

            if ( defined( 'BM_DEV' ) && file_exists( Better_Messages()->path . 'assets/css/polls.css' ) ) {
                $file     = 'assets/css/polls.css';
                $version .= filemtime( Better_Messages()->path . $file );
            } elseif ( defined( 'BM_DEV_ASSETS' ) && file_exists( Better_Messages()->path . $file ) ) {
                $version .= filemtime( Better_Messages()->path . $file );
            }

            wp_register_style( 'better-messages-polls', Better_Messages()->url . $file, array( 'better-messages' ), $version );
            wp_enqueue_style( 'better-messages-polls' );

            return $dependencies;
        }

        public function mobile_app_style_handles( $handles ) {
            if ( $this->is_enabled() ) {
                $handles[] = 'better-messages-polls';
            }

            return $handles;
        }

        public function before_message_send( &$args, &$errors ) {
            if ( ! isset( $args['meta_data'] ) || ! is_array( $args['meta_data'] ) || ! array_key_exists( 'poll', $args['meta_data'] ) ) {
                return;
            }

            $definition = $args['meta_data']['poll'];
            unset( $args['meta_data']['poll'] );

            $this->pending = null;

            $thread_id = isset( $args['thread_id'] ) ? (int) $args['thread_id'] : 0;
            $sender_id = isset( $args['sender_id'] ) ? (int) $args['sender_id'] : (int) Better_Messages()->functions->get_current_user_id();

            if ( $thread_id > 0 && $this->is_e2e_thread( $thread_id ) ) {
                $errors['poll'] = _x( 'Polls cannot be created in encrypted conversations', 'Polls', 'bp-better-messages' );
                return;
            }

            if ( ! $this->can_create( $thread_id, $sender_id ) ) {
                $errors['poll'] = _x( 'You cannot create a poll in this conversation', 'Polls', 'bp-better-messages' );
                return;
            }

            $checked = wordplus_polls()->check( $definition );

            if ( is_wp_error( $checked ) ) {
                $errors['poll'] = $checked->get_error_message();
                return;
            }

            $args['content'] = WordPlus_SDK_Polls::label( $checked['question'] );

            $this->pending = array(
                'thread_id'  => $thread_id,
                'sender_id'  => $sender_id,
                'definition' => $checked,
            );
        }

        public function message_sent( &$message ) {
            if ( null === $this->pending || ! is_object( $message ) || empty( $message->id ) ) {
                return;
            }

            if ( (int) $message->thread_id !== $this->pending['thread_id'] || (int) $message->sender_id !== $this->pending['sender_id'] ) {
                return;
            }

            $pending       = $this->pending;
            $this->pending = null;

            wordplus_polls()->delete( self::CONTEXT, array( (int) $message->id ) );

            $poll = wordplus_polls()->create( self::CONTEXT, (int) $message->id, (int) $message->thread_id, (int) $message->sender_id, $pending['definition'] );

            if ( is_wp_error( $poll ) ) {
                return;
            }

            Better_Messages()->functions->update_message_meta( (int) $message->id, 'poll_id', (int) $poll['id'] );
        }

        public function poll_of( $message_id ) {
            $message_id = (int) $message_id;

            if ( $message_id <= 0 || ! Better_Messages()->functions->get_message_meta( $message_id, 'poll_id', true ) ) {
                return null;
            }

            return wordplus_polls()->get( self::CONTEXT, $message_id );
        }

        public function before_message_delete( $message_id, $thread_id = 0, $method = null ) {
            if ( ! Better_Messages()->functions->get_message_meta( (int) $message_id, 'poll_id', true ) ) {
                return;
            }

            wordplus_polls()->delete( self::CONTEXT, array( (int) $message_id ) );
            Better_Messages()->functions->delete_message_meta( (int) $message_id, 'poll_id' );
        }

        public function refuse_edit( $response, $handler, $request ) {
            if ( is_wp_error( $response ) || ! $request instanceof WP_REST_Request ) {
                return $response;
            }

            if ( ! preg_match( '#^/better-messages/v1/thread/\d+/save$#', (string) $request->get_route() ) ) {
                return $response;
            }

            $message_id = (int) $request->get_param( 'message_id' );

            if ( $message_id > 0 && Better_Messages()->functions->get_message_meta( $message_id, 'poll_id', true ) ) {
                return new WP_Error( 'rest_forbidden', _x( 'A poll cannot be edited', 'Polls', 'bp-better-messages' ), array( 'status' => 403 ) );
            }

            return $response;
        }

        public function label_text( $message, $message_id = 0 ) {
            if ( ! is_string( $message ) || 0 !== strpos( $message, '?' ) || (int) $message_id <= 0 ) {
                return $message;
            }

            if ( ! Better_Messages()->functions->get_message_meta( (int) $message_id, 'poll_id', true ) ) {
                return $message;
            }

            return (string) preg_replace( '/^\?{1,4} /', "\u{1F4CA} ", $message, 1 );
        }

        public function message_meta( $meta, $message_id, $thread_id, $content ) {
            $poll = $this->poll_of( $message_id );

            if ( null === $poll ) {
                return $meta;
            }

            if ( ! is_array( $meta ) ) {
                $meta = (array) $meta;
            }

            $meta['poll'] = array_merge( wordplus_polls()->state( $poll ), array( 'at' => $this->stamp( $message_id ) ) );

            return $meta;
        }

        private function stamp( $message_id ) {
            global $wpdb;

            return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT `updated_at` FROM `' . bm_get_table( 'messages' ) . '` WHERE `id` = %d', (int) $message_id ) );
        }

        public function message_user_fields( $fields, $message_id, $thread_id, $user_id, $meta ) {
            $meta = is_object( $meta ) ? get_object_vars( $meta ) : $meta;

            if ( ! is_array( $meta ) || ! isset( $meta['poll'] ) || (int) $user_id === 0 ) {
                return $fields;
            }

            $poll = $this->poll_of( $message_id );

            if ( null !== $poll ) {
                $fields['pollMe'] = array_merge( wordplus_polls()->member( $poll, (int) $user_id ), array( 'at' => $this->stamp( $message_id ) ) );
            }

            return $fields;
        }

        public function poll_changed( $context, $object_id, $poll ) {
            if ( self::CONTEXT !== $context || ! is_array( $poll ) ) {
                return;
            }

            $message_id = (int) $object_id;
            $thread_id  = (int) $poll['scope_id'];

            Better_Messages()->functions->update_message_update_time( $message_id );

            do_action( 'better_messages_message_meta_updated', $thread_id, $message_id, 'poll', wordplus_polls()->state( $poll ) );

            if ( null !== $poll['closedAt'] ) {
                $this->announce_end( $poll );
            }
        }

        private function announce_end( array $poll ) {
            $message_id = (int) $poll['object_id'];
            $thread_id  = (int) $poll['scope_id'];

            if ( $thread_id <= 0 || Better_Messages()->functions->get_message_meta( $message_id, 'poll_ended', true ) ) {
                return;
            }

            Better_Messages()->functions->update_message_meta( $message_id, 'poll_ended', 1 );

            $state   = wordplus_polls()->state( $poll );
            $texts   = array_column( $state['options'], 'text', 'id' );
            $data    = array(
                'message_id' => $message_id,
                'question'   => (string) $state['question'],
                'quiz'       => ! empty( $poll['quiz'] ),
                'voters'     => (int) $state['voters'],
                'winners'    => array(),
                'answer'     => '',
            );

            if ( ! empty( $poll['quiz'] ) ) {
                $data['answer'] = isset( $texts[ (int) $poll['quiz']['answer'] ] ) ? (string) $texts[ (int) $poll['quiz']['answer'] ] : '';
            } else {
                foreach ( WordPlus_SDK_Polls::winners( $state ) as $id ) {
                    $data['winners'][] = (string) $texts[ $id ];
                }
            }

            $creator    = (int) $poll['creator_id'];
            $recipients = Better_Messages()->functions->get_recipients_ids( $thread_id );
            $notify     = in_array( $creator, $recipients, true ) && ( null === $poll['closed_by'] || (int) $poll['closed_by'] !== $creator );
            $others     = array_values( array_diff( $recipients, array( $creator ) ) );

            Better_Messages()->functions->send_system_message( $thread_id, 'poll_ended', $data, array(
                'count_unread'   => $notify,
                'show_on_site'   => $notify,
                'send_push'      => $notify,
                'mobile_push'    => $notify,
                'notification'   => $notify ? array(
                    'title' => (string) $state['question'],
                    'body'  => $this->end_text( $data ),
                    'type'  => 'poll_ended',
                ) : false,
                'unread_exclude' => $others,
                'notify_exclude' => $others,
            ) );
        }

        private function end_text( array $data ) {
            $question = isset( $data['question'] ) ? (string) $data['question'] : '';

            if ( ! empty( $data['quiz'] ) ) {
                return '' === (string) ( $data['answer'] ?? '' )
                    ? sprintf( _x( 'The quiz "%s" ended', 'Polls (system message)', 'bp-better-messages' ), $question )
                    : sprintf( _x( 'The quiz "%1$s" ended. The right answer: %2$s', 'Polls (system message)', 'bp-better-messages' ), $question, (string) $data['answer'] );
            }

            $winners = isset( $data['winners'] ) && is_array( $data['winners'] ) ? $data['winners'] : array();

            if ( empty( $winners ) ) {
                return sprintf( _x( 'The poll "%s" ended with no votes', 'Polls (system message)', 'bp-better-messages' ), $question );
            }

            return sprintf( _x( 'The poll "%1$s" ended. Most votes: %2$s', 'Polls (system message)', 'bp-better-messages' ), $question, implode( ', ', $winners ) );
        }

        public function system_message_text( $text, $type, $data ) {
            return 'poll_ended' === $type ? $this->end_text( is_array( $data ) ? $data : array() ) : $text;
        }

        public function self_update_extra( $extra, $thread_id, $user_id ) {
            if ( null === $this->self_update || (int) $this->self_update['thread_id'] !== (int) $thread_id || (int) $this->self_update['user_id'] !== (int) $user_id ) {
                return $extra;
            }

            $extra['pollMe'] = array(
                'message_id' => (int) $this->self_update['message_id'],
                'member'     => $this->self_update['member'],
            );

            return $extra;
        }

        public function routes() {
            if ( ! $this->is_enabled() ) {
                return;
            }

            wordplus_polls()->routes( 'better-messages/v1', self::CONTEXT, array(
                'authorize' => array( $this, 'authorize' ),
                'user'      => array( $this, 'current_user' ),
                'can_view'  => array( $this, 'can_view' ),
                'can_vote'  => array( $this, 'can_vote' ),
                'can_end'   => array( $this, 'can_end' ),
                'respond'   => array( $this, 'respond' ),
                'user_item' => array( $this, 'user_item' ),
            ) );
        }

        public function authorize( $request ) {
            return true === Better_Messages_Rest_Api()->is_user_authorized( $request );
        }

        public function current_user() {
            return (int) Better_Messages()->functions->get_current_user_id();
        }

        private function message_of( $poll ) {
            $message = Better_Messages()->functions->get_message( (int) $poll['object_id'] );

            if ( ! $message || (int) $message->thread_id !== (int) $poll['scope_id'] ) {
                return null;
            }

            return $message;
        }

        public function can_view( $poll, $user_id ) {
            $user_id = (int) $user_id;
            $message = $this->message_of( $poll );

            if ( null === $message || $user_id === 0 ) {
                return false;
            }

            if ( $user_id > 0 && user_can( $user_id, 'bm_can_administrate' ) ) {
                return true;
            }

            return $this->can_read( $message, $user_id );
        }

        public function can_vote( $poll, $user_id ) {
            $user_id = (int) $user_id;
            $message = $this->message_of( $poll );

            return null !== $message && $user_id !== 0 && $this->can_read( $message, $user_id );
        }

        private function can_read( $message, $user_id ) {
            $thread_id = (int) $message->thread_id;

            if ( (int) $message->is_pending === 1 && (int) $message->sender_id !== $user_id ) {
                return false;
            }

            return Better_Messages()->functions->check_access( $thread_id, $user_id )
                && Better_Messages()->functions->can_read_chat_messages( $thread_id, $user_id );
        }

        public function can_end( $poll, $user_id ) {
            $user_id = (int) $user_id;

            if ( $user_id === 0 || ! $this->can_view( $poll, $user_id ) ) {
                return false;
            }

            if ( (int) $poll['creator_id'] === $user_id ) {
                return true;
            }

            if ( $user_id < 0 ) {
                return false;
            }

            return Better_Messages()->functions->can_moderate_thread( (int) $poll['scope_id'], $user_id );
        }

        public function respond( $poll, $user_id, $request = null ) {
            $thread_id  = (int) $poll['scope_id'];
            $message_id = (int) $poll['object_id'];

            if ( $request instanceof WP_REST_Request && '/vote' === substr( (string) $request->get_route(), -5 ) ) {
                $this->self_update = array(
                    'thread_id'  => $thread_id,
                    'user_id'    => (int) $user_id,
                    'message_id' => $message_id,
                    'member'     => array_merge( wordplus_polls()->member( $poll, (int) $user_id ), array( 'at' => $this->stamp( $message_id ) ) ),
                );

                do_action( 'better_messages_thread_self_update', $thread_id, (int) $user_id );

                $this->self_update = null;
            }

            return Better_Messages_Rest_Api()->get_messages( $thread_id, array( $message_id ) );
        }

        public function user_item( $user_id ) {
            return Better_Messages()->functions->rest_user_item( (int) $user_id );
        }

        public function register_worker_task() {
            Better_Messages_Worker()->add_task( 'better_messages_polls_end', MINUTE_IN_SECONDS );
        }

        public function end_due() {
            if ( $this->is_enabled() ) {
                wordplus_polls()->end_due( self::CONTEXT, 100 );
            }
        }

        public function erase_user( $user_id ) {
            if ( $this->is_enabled() ) {
                wordplus_polls()->erase_user( (int) $user_id );
            }
        }
    }
}
