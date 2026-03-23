<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_AI_Summarization' ) ) {
    class Better_Messages_AI_Summarization
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_AI_Summarization();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
            add_action( 'better_messages_message_sent', array( $this, 'on_message_sent' ), 20, 1 );
            add_filter( 'better_messages_rest_thread_item', array( $this, 'rest_thread_item' ), 25, 5 );
            add_filter( 'better_messages_rest_message_meta', array( $this, 'rest_message_meta' ), 10, 4 );

            add_action( 'bm_generate_summary', array( $this, 'cron_generate_summary' ), 10, 1 );
            add_action( 'bm_cron_summarization', array( $this, 'cron_summarize_all' ) );
            add_action( 'init', array( $this, 'register_cron_schedules' ) );
        }

        public function register_cron_schedules()
        {
            if ( ! wp_next_scheduled( 'bm_cron_summarization' ) ) {
                wp_schedule_event( time(), 'hourly', 'bm_cron_summarization' );
            }
        }

        /**
         * Get the per-thread summarization config.
         * Returns null if not configured.
         */
        public function get_thread_config( $thread_id )
        {
            $raw = Better_Messages()->functions->get_thread_meta( $thread_id, 'ai_summarization' );

            if ( empty( $raw ) ) {
                return null;
            }

            $config = json_decode( $raw, true );

            if ( ! is_array( $config ) || empty( $config['botId'] ) ) {
                return null;
            }

            return wp_parse_args( $config, array(
                'botId'        => 0,
                'schedule'     => 'manual',
                'autoMessages' => 100,
            ) );
        }

        /**
         * Save the per-thread summarization config.
         */
        public function save_thread_config( $thread_id, $config )
        {
            $sanitized = array();

            $sanitized['botId']        = intval( $config['botId'] ?? 0 );
            $sanitized['schedule']     = in_array( $config['schedule'] ?? '', array( 'manual', 'messages', 'hourly', 'twicedaily', 'daily' ) ) ? $config['schedule'] : 'manual';
            $sanitized['autoMessages'] = max( 10, intval( $config['autoMessages'] ?? 100 ) );

            if ( $sanitized['botId'] === 0 ) {
                Better_Messages()->functions->delete_thread_meta( $thread_id, 'ai_summarization' );
            } else {
                $bot_settings = Better_Messages()->ai->get_bot_settings( $sanitized['botId'] );
                if ( ( $bot_settings['summarizationEnabled'] ?? '0' ) !== '1' ) {
                    return new \WP_Error( 'bot_not_enabled', __( 'Summarization is not enabled for this bot', 'bp-better-messages' ) );
                }

                Better_Messages()->functions->update_thread_meta( $thread_id, 'ai_summarization', wp_json_encode( $sanitized ) );
            }

            return $sanitized;
        }

        /**
         * Get the per-thread digest config.
         * Returns null if not configured.
         */
        public function get_thread_digest_config( $thread_id )
        {
            $raw = Better_Messages()->functions->get_thread_meta( $thread_id, 'ai_digest_config' );

            if ( empty( $raw ) ) {
                return null;
            }

            $config = json_decode( $raw, true );

            if ( ! is_array( $config ) || empty( $config['botId'] ) ) {
                return null;
            }

            return wp_parse_args( $config, array(
                'botId'       => 0,
                'schedule'    => 'daily',
                'digestTimes' => array( '09:00' ),
            ) );
        }

        /**
         * Save the per-thread digest config.
         */
        public function save_thread_digest_config( $thread_id, $config )
        {
            $sanitized = array();

            $sanitized['botId']    = intval( $config['botId'] ?? 0 );
            $sanitized['schedule'] = in_array( $config['schedule'] ?? '', array( 'hourly', 'twicedaily', 'daily' ) ) ? $config['schedule'] : 'daily';

            // Validate digest times (HH:MM format)
            $times = isset( $config['digestTimes'] ) && is_array( $config['digestTimes'] ) ? $config['digestTimes'] : array( '09:00' );
            $valid_times = array();
            foreach ( $times as $time ) {
                if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ) {
                    $valid_times[] = $time;
                }
            }
            if ( empty( $valid_times ) ) {
                $valid_times = array( '09:00' );
            }
            $sanitized['digestTimes'] = $valid_times;

            if ( $sanitized['botId'] === 0 ) {
                Better_Messages()->functions->delete_thread_meta( $thread_id, 'ai_digest_config' );
            } else {
                $bot_settings = Better_Messages()->ai->get_bot_settings( $sanitized['botId'] );
                if ( ( $bot_settings['digestEnabled'] ?? '0' ) !== '1' ) {
                    return new \WP_Error( 'bot_not_enabled', __( 'Digest is not enabled for this bot', 'bp-better-messages' ) );
                }

                Better_Messages()->functions->update_thread_meta( $thread_id, 'ai_digest_config', wp_json_encode( $sanitized ) );
            }

            return $sanitized;
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/summarization', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'rest_get_config' ),
                    'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_save_config' ),
                    'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/summary', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_generate_summary' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/digest-config', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'rest_get_digest_config' ),
                    'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_save_digest_config' ),
                    'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/digest', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_generate_digest' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/ai-settings', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_ai_settings' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
            ) );
        }

        public function rest_get_ai_settings( $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            if ( ! $this->user_can_configure( $user_id, $thread_id ) ) {
                return new \WP_Error( 'rest_forbidden', __( 'You do not have permission', 'bp-better-messages' ), array( 'status' => 403 ) );
            }

            $tz = wp_timezone();
            $now = new \DateTime( 'now', $tz );

            return rest_ensure_response( array(
                'summarization' => array(
                    'config'        => $this->get_thread_config( $thread_id ),
                    'availableBots' => $this->get_available_bots( $thread_id ),
                ),
                'digest' => array(
                    'config'        => $this->get_thread_digest_config( $thread_id ),
                    'availableBots' => $this->get_available_digest_bots( $thread_id ),
                    'timezone'      => $now->format( 'T' ),
                ),
            ) );
        }

        public function rest_get_config( $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            if ( ! $this->user_can_configure( $user_id, $thread_id ) ) {
                return new \WP_Error( 'rest_forbidden', __( 'You do not have permission', 'bp-better-messages' ), array( 'status' => 403 ) );
            }

            return rest_ensure_response( array(
                'config'        => $this->get_thread_config( $thread_id ),
                'availableBots' => $this->get_available_bots( $thread_id ),
            ) );
        }

        public function rest_save_config( $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            if ( ! $this->user_can_configure( $user_id, $thread_id ) ) {
                return new \WP_Error( 'rest_forbidden', __( 'You do not have permission', 'bp-better-messages' ), array( 'status' => 403 ) );
            }
            $config    = $request->get_json_params();

            $saved = $this->save_thread_config( $thread_id, $config );

            if ( is_wp_error( $saved ) ) {
                return $saved;
            }

            do_action( 'better_messages_thread_updated', $thread_id );
            do_action( 'better_messages_info_changed', $thread_id );

            return rest_ensure_response( array(
                'success' => true,
                'config'  => $saved,
            ) );
        }

        public function rest_generate_summary( $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            if ( ! $this->user_can_configure( $user_id, $thread_id ) ) {
                return new \WP_Error( 'rest_forbidden', __( 'You do not have permission', 'bp-better-messages' ), array( 'status' => 403 ) );
            }
            $config    = $this->get_thread_config( $thread_id );

            if ( ! $config || empty( $config['botId'] ) ) {
                return new \WP_Error( 'not_configured', __( 'Summarization is not configured for this conversation', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $lock_key = 'bm_generating_summary_' . $thread_id;
            if ( get_transient( $lock_key ) ) {
                return new \WP_Error( 'already_in_progress', __( 'Summary generation is already in progress', 'bp-better-messages' ), array( 'status' => 409 ) );
            }
            set_transient( $lock_key, 1, 300 );

            // Pre-validate before closing connection
            $bot_id = (int) $config['botId'];

            if ( ! Better_Messages()->ai->bot_exists( $bot_id ) ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'invalid_bot', __( 'Bot not found', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            if ( ( $bot_settings['summarizationEnabled'] ?? '0' ) !== '1' ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'not_enabled', __( 'Summarization is not enabled for this bot', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $provider_id = ! empty( $bot_settings['provider'] ) ? $bot_settings['provider'] : 'openai';
            $api_key = ! empty( $bot_settings['apiKey'] ) ? $bot_settings['apiKey'] : Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );

            if ( empty( $api_key ) ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'no_api_key', __( 'No API key configured', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $model = ! empty( $bot_settings['summarizationModel'] ) ? $bot_settings['summarizationModel'] : $bot_settings['model'];
            if ( empty( $model ) ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'no_model', __( 'No model configured', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $message_count = $this->count_messages_since_summary( $thread_id );
            if ( $message_count < 3 ) {
                delete_transient( $lock_key );
                $error_msg = $message_count === 0
                    ? __( 'No new messages to summarize', 'bp-better-messages' )
                    : __( 'Not enough new messages to summarize (minimum 3)', 'bp-better-messages' );
                return new \WP_Error( 'insufficient_messages', $error_msg, array( 'status' => 400 ) );
            }

            $this->send_json_and_close( array( 'success' => true ) );

            $result = $this->generate_summary( $thread_id );
            if ( is_wp_error( $result ) ) {
                $this->log_bot_error( $bot_id, $thread_id, 'summary', $result->get_error_message() );
            }
            delete_transient( $lock_key );

            exit;
        }

        public function rest_get_digest_config( $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            if ( ! $this->user_can_configure( $user_id, $thread_id ) ) {
                return new \WP_Error( 'rest_forbidden', __( 'You do not have permission', 'bp-better-messages' ), array( 'status' => 403 ) );
            }
            $tz = wp_timezone();
            $now = new \DateTime( 'now', $tz );

            return rest_ensure_response( array(
                'config'        => $this->get_thread_digest_config( $thread_id ),
                'availableBots' => $this->get_available_digest_bots( $thread_id ),
                'timezone'      => $now->format( 'T' ),
            ) );
        }

        public function rest_save_digest_config( $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            if ( ! $this->user_can_configure( $user_id, $thread_id ) ) {
                return new \WP_Error( 'rest_forbidden', __( 'You do not have permission', 'bp-better-messages' ), array( 'status' => 403 ) );
            }
            $config    = $request->get_json_params();

            $saved = $this->save_thread_digest_config( $thread_id, $config );

            if ( is_wp_error( $saved ) ) {
                return $saved;
            }

            do_action( 'better_messages_thread_updated', $thread_id );
            do_action( 'better_messages_info_changed', $thread_id );

            return rest_ensure_response( array(
                'success' => true,
                'config'  => $saved,
            ) );
        }

        public function rest_generate_digest( $request )
        {
            $thread_id = (int) $request->get_param( 'id' );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            if ( ! $this->user_can_configure( $user_id, $thread_id ) ) {
                return new \WP_Error( 'rest_forbidden', __( 'You do not have permission', 'bp-better-messages' ), array( 'status' => 403 ) );
            }
            $config    = $this->get_thread_digest_config( $thread_id );

            if ( ! $config || empty( $config['botId'] ) ) {
                return new \WP_Error( 'not_configured', __( 'Digest is not configured for this conversation', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $lock_key = 'bm_generating_digest_' . $thread_id;
            if ( get_transient( $lock_key ) ) {
                return new \WP_Error( 'already_in_progress', __( 'Digest generation is already in progress', 'bp-better-messages' ), array( 'status' => 409 ) );
            }
            set_transient( $lock_key, 1, 300 );

            // Pre-validate before closing connection
            $bot_id = (int) $config['botId'];

            if ( ! Better_Messages()->ai->bot_exists( $bot_id ) ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'invalid_bot', __( 'Bot not found', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            if ( ( $bot_settings['digestEnabled'] ?? '0' ) !== '1' ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'not_enabled', __( 'Digest is not enabled for this bot', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $provider_id = ! empty( $bot_settings['provider'] ) ? $bot_settings['provider'] : 'openai';
            $api_key = ! empty( $bot_settings['apiKey'] ) ? $bot_settings['apiKey'] : Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );

            if ( empty( $api_key ) ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'no_api_key', __( 'No API key configured', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $model = ! empty( $bot_settings['digestModel'] ) ? $bot_settings['digestModel'] : $bot_settings['model'];
            if ( empty( $model ) ) {
                delete_transient( $lock_key );
                return new \WP_Error( 'no_model', __( 'No model configured', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $this->send_json_and_close( array( 'success' => true ) );

            $result = $this->generate_digest( $thread_id );
            if ( is_wp_error( $result ) ) {
                $this->log_bot_error( $bot_id, $thread_id, 'digest', $result->get_error_message() );
            }
            delete_transient( $lock_key );

            exit;
        }

        /**
         * Get bots in the thread that have digest enabled.
         */
        private function get_available_digest_bots( $thread_id )
        {
            $recipients = Better_Messages()->functions->get_recipients( $thread_id );
            $bots = array();

            foreach ( $recipients as $recipient ) {
                $user_id = (int) $recipient->user_id;

                if ( $user_id >= 0 ) {
                    continue;
                }

                $bot_id = $this->get_bot_id_from_user( $user_id );

                if ( ! $bot_id || ! Better_Messages()->ai->bot_exists( $bot_id ) ) {
                    continue;
                }

                $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

                if ( ( $bot_settings['digestEnabled'] ?? '0' ) !== '1' ) {
                    continue;
                }

                $bots[] = array(
                    'id'   => (int) $bot_id,
                    'name' => get_the_title( $bot_id ),
                );
            }

            return $bots;
        }

        /**
         * Get bots in the thread that have summarization enabled.
         */
        private function get_available_bots( $thread_id )
        {
            $recipients = Better_Messages()->functions->get_recipients( $thread_id );
            $bots = array();

            foreach ( $recipients as $recipient ) {
                $user_id = (int) $recipient->user_id;

                if ( $user_id >= 0 ) {
                    continue;
                }

                $bot_id = $this->get_bot_id_from_user( $user_id );

                if ( ! $bot_id || ! Better_Messages()->ai->bot_exists( $bot_id ) ) {
                    continue;
                }

                $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

                if ( ( $bot_settings['summarizationEnabled'] ?? '0' ) !== '1' ) {
                    continue;
                }

                $bots[] = array(
                    'id'   => (int) $bot_id,
                    'name' => get_the_title( $bot_id ),
                );
            }

            return $bots;
        }

        /**
         * Generate a summary for a thread.
         * Which bot is determined from thread config.
         * How to summarize (style, model, etc.) comes from bot settings.
         */
        public function generate_summary( $thread_id, $user_id = 0 )
        {
            ignore_user_abort( true );
            set_time_limit( 0 );

            $config = $this->get_thread_config( $thread_id );

            if ( ! $config || empty( $config['botId'] ) ) {
                return new \WP_Error( 'not_configured', 'Summarization not configured' );
            }

            $bot_id = (int) $config['botId'];

            if ( ! Better_Messages()->ai->bot_exists( $bot_id ) ) {
                return new \WP_Error( 'invalid_bot', 'Bot not found' );
            }

            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            if ( ( $bot_settings['summarizationEnabled'] ?? '0' ) !== '1' ) {
                return new \WP_Error( 'not_enabled', 'Summarization is not enabled for this bot' );
            }

            $bot_user = Better_Messages()->ai->get_bot_user( $bot_id );

            if ( ! $bot_user ) {
                return new \WP_Error( 'no_bot_user', 'Bot user not found' );
            }

            $bot_user_id = -1 * absint( $bot_user->id );

            $provider_id = ! empty( $bot_settings['provider'] ) ? $bot_settings['provider'] : 'openai';
            $api_key = ! empty( $bot_settings['apiKey'] ) ? $bot_settings['apiKey'] : Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );

            if ( empty( $api_key ) ) {
                return new \WP_Error( 'no_api_key', 'No API key configured for provider: ' . $provider_id );
            }

            $model = ! empty( $bot_settings['summarizationModel'] ) ? $bot_settings['summarizationModel'] : $bot_settings['model'];

            if ( empty( $model ) ) {
                return new \WP_Error( 'no_model', 'No model configured' );
            }

            $last_summary_created_at = $this->get_last_summary_created_at( $thread_id );
            $now = Better_Messages()->functions->get_microtime();

            global $wpdb;
            $messages_table = bm_get_table( 'messages' );

            $where_after = $last_summary_created_at > 0
                ? $wpdb->prepare( " AND created_at > %d", $last_summary_created_at )
                : '';

            $thread_messages = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, sender_id, message
                FROM `{$messages_table}`
                WHERE thread_id = %d
                AND created_at <= %d
                {$where_after}
                ORDER BY `created_at` ASC",
                $thread_id, $now
            ) );

            if ( empty( $thread_messages ) ) {
                return new \WP_Error( 'no_messages', 'No messages to summarize' );
            }

            $thread_messages = array_filter( $thread_messages, function ( $msg ) {
                $content = trim( $msg->message );
                if ( empty( $content ) ) return false;
                if ( strpos( $content, '<!-- BM-SYSTEM-MESSAGE:' ) === 0 ) return false;
                return true;
            } );

            if ( count( $thread_messages ) < 3 ) {
                return new \WP_Error( 'too_few_messages', 'Not enough messages to summarize (minimum 3)' );
            }

            $sender_names = $this->resolve_sender_names( $thread_messages );
            $messages_text = $this->build_messages_text( $thread_messages, $sender_names );
            $system_prompt = $this->build_system_prompt( $bot_settings, $sender_names );

            $provider = Better_Messages_AI_Provider_Factory::create( $provider_id );

            if ( ! $provider ) {
                return new \WP_Error( 'invalid_provider', 'Provider not found: ' . $provider_id );
            }

            $provider->set_api_key( $api_key );

            $custom_max_tokens = $bot_settings['summarizationMaxTokens'] ?? '';
            if ( $custom_max_tokens !== '' && (int) $custom_max_tokens > 0 ) {
                $max_tokens = (int) $custom_max_tokens;
            } elseif ( ( $bot_settings['summarizationLength'] ?? 'brief' ) === 'detailed' ) {
                $max_tokens = 6000;
            } else {
                $max_tokens = 3000;
            }

            $result = $provider->generateSummary( $system_prompt, $messages_text, $model, $max_tokens );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $summary_text = $result['text'];

            if ( empty( trim( $summary_text ) ) ) {
                return new \WP_Error( 'empty_summary', 'Generated summary was empty' );
            }

            $content = '<!-- BM-AI -->' . $provider->convert_mention_placeholders( htmlentities( $summary_text ) );

            $message_id = Better_Messages()->functions->new_message( array(
                'sender_id'    => $bot_user_id,
                'thread_id'    => $thread_id,
                'content'      => $content,
                'count_unread' => true,
                'return'       => 'message_id',
                'error_type'   => 'wp_error'
            ) );

            if ( is_wp_error( $message_id ) ) {
                return $message_id;
            }

            $message_ids = array_map( function( $m ) { return (int) $m->id; }, array_values( $thread_messages ) );
            $meta = array(
                'style'         => $bot_settings['summarizationStyle'] ?? 'narrative',
                'length'        => $bot_settings['summarizationLength'] ?? 'brief',
                'message_range' => array(
                    'from_id' => $message_ids[0],
                    'to_id'   => end( $message_ids ),
                    'count'   => count( $message_ids ),
                ),
                'provider'      => $provider_id,
                'model'         => $model,
                'usage'         => isset( $result['usage'] ) ? $result['usage'] : array(),
            );

            Better_Messages()->functions->add_message_meta( $message_id, 'ai_summary', wp_json_encode( $meta ) );
            Better_Messages()->functions->add_message_meta( $message_id, 'ai_bot_id', $bot_id );

            $provider_meta = array(
                'usage'    => isset( $result['usage'] ) ? $result['usage'] : array(),
                'model'    => $model,
                'provider' => $provider_id,
            );
            Better_Messages()->functions->add_message_meta( $message_id, 'ai_provider_meta', json_encode( $provider_meta ) );

            Better_Messages()->ai->calculate_and_store_cost( $message_id, $provider_meta, $bot_id, $user_id, $thread_id, true );

            if ( isset( Better_Messages()->mentions ) ) {
                Better_Messages()->mentions->process_mentions( $thread_id, $message_id, $content );
            }

            return (int) $message_id;
        }

        /**
         * Generate a digest for a thread.
         */
        public function generate_digest( $thread_id )
        {
            ignore_user_abort( true );
            set_time_limit( 0 );

            $config = $this->get_thread_digest_config( $thread_id );

            if ( ! $config || empty( $config['botId'] ) ) {
                return new \WP_Error( 'not_configured', 'Digest not configured' );
            }

            $bot_id = (int) $config['botId'];

            if ( ! Better_Messages()->ai->bot_exists( $bot_id ) ) {
                return new \WP_Error( 'invalid_bot', 'Bot not found' );
            }

            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            if ( ( $bot_settings['digestEnabled'] ?? '0' ) !== '1' ) {
                return new \WP_Error( 'not_enabled', 'Digest is not enabled for this bot' );
            }

            $bot_user = Better_Messages()->ai->get_bot_user( $bot_id );

            if ( ! $bot_user ) {
                return new \WP_Error( 'no_bot_user', 'Bot user not found' );
            }

            $bot_user_id = -1 * absint( $bot_user->id );

            $provider_id = ! empty( $bot_settings['provider'] ) ? $bot_settings['provider'] : 'openai';
            $api_key = ! empty( $bot_settings['apiKey'] ) ? $bot_settings['apiKey'] : Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );

            if ( empty( $api_key ) ) {
                return new \WP_Error( 'no_api_key', 'No API key configured for provider: ' . $provider_id );
            }

            $model = ! empty( $bot_settings['digestModel'] ) ? $bot_settings['digestModel'] : $bot_settings['model'];

            if ( empty( $model ) ) {
                return new \WP_Error( 'no_model', 'No model configured' );
            }

            $provider = Better_Messages_AI_Provider_Factory::create( $provider_id );

            if ( ! $provider ) {
                return new \WP_Error( 'invalid_provider', 'Provider not found: ' . $provider_id );
            }

            $provider->set_api_key( $api_key );

            $custom_max_tokens = $bot_settings['digestMaxTokens'] ?? '';
            $max_tokens = ( $custom_max_tokens !== '' && (int) $custom_max_tokens > 0 ) ? (int) $custom_max_tokens : 32000;

            $context_limit = (int) ( $bot_settings['digestContextDigests'] ?? 3 );
            $previous_digests = $context_limit > 0 ? $this->get_recent_digests( $thread_id, $context_limit ) : array();

            $system_prompt = $this->build_digest_prompt( $bot_settings, $previous_digests );
            $user_content  = ! empty( $bot_settings['digestPrompt'] ) ? $bot_settings['digestPrompt'] : 'Generate a digest.';

            $options = array();
            if ( ( $bot_settings['webSearch'] ?? '0' ) === '1' ) {
                $options['webSearch'] = true;
                $options['webSearchContextSize'] = $bot_settings['webSearchContextSize'] ?? 'medium';
            }

            $result = $provider->generateDigest( $system_prompt, $user_content, $model, $max_tokens, $options );

            if ( is_wp_error( $result ) ) {
                return $result;
            }

            $digest_text = $result['text'];

            if ( empty( trim( $digest_text ) ) ) {
                return new \WP_Error( 'empty_digest', 'Generated digest was empty' );
            }

            $content = '<!-- BM-AI -->' . htmlentities( $digest_text );

            $message_id = Better_Messages()->functions->new_message( array(
                'sender_id'    => $bot_user_id,
                'thread_id'    => $thread_id,
                'content'      => $content,
                'count_unread' => true,
                'return'       => 'message_id',
                'error_type'   => 'wp_error'
            ) );

            if ( is_wp_error( $message_id ) ) {
                return $message_id;
            }

            $meta = array(
                'provider' => $provider_id,
                'model'    => $model,
                'usage'    => isset( $result['usage'] ) ? $result['usage'] : array(),
            );

            Better_Messages()->functions->add_message_meta( $message_id, 'ai_digest', wp_json_encode( $meta ) );
            Better_Messages()->functions->add_message_meta( $message_id, 'ai_bot_id', $bot_id );

            $provider_meta = array(
                'usage'    => isset( $result['usage'] ) ? $result['usage'] : array(),
                'model'    => $model,
                'provider' => $provider_id,
            );
            Better_Messages()->functions->add_message_meta( $message_id, 'ai_provider_meta', json_encode( $provider_meta ) );

            Better_Messages()->ai->calculate_and_store_cost( $message_id, $provider_meta, $bot_id, 0, $thread_id, true );

            return (int) $message_id;
        }

        private function build_digest_prompt( $bot_settings, $previous_digests = array() )
        {
            $prompt = "You are a digest bot. Your task is to generate a digest based on the instructions provided by the user. Search the web for the latest information when available. Present the digest in a clear, well-structured format.\n\n";

            $language = $bot_settings['digestLanguage'] ?? '';

            if ( ! empty( $language ) ) {
                $prompt .= "Write the digest in " . $language . ".\n";
            }

            $prompt .= "Do not add introductory or closing phrases. Start directly with the content.";

            if ( ! empty( $previous_digests ) ) {
                $prompt .= "\n\n## Previous digests\n\nBelow are your previous digests. Avoid repeating the same information. Focus on new developments, updates, and topics not already covered.\n\n";

                foreach ( $previous_digests as $digest ) {
                    $prompt .= "--- Digest from " . $digest['date'] . " ---\n" . $digest['text'] . "\n\n";
                }
            }

            return $prompt;
        }

        /**
         * Get recent digest messages for a thread.
         */
        private function get_recent_digests( $thread_id, $limit = 3 )
        {
            global $wpdb;

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT m.message, m.created_at
                FROM `" . bm_get_table( 'messages' ) . "` m
                INNER JOIN `" . bm_get_table( 'meta' ) . "` mt ON mt.bm_message_id = m.id
                WHERE m.thread_id = %d
                AND mt.meta_key = 'ai_digest'
                ORDER BY m.created_at DESC
                LIMIT %d",
                $thread_id, $limit
            ) );

            if ( empty( $rows ) ) {
                return array();
            }

            $digests = array();

            foreach ( array_reverse( $rows ) as $row ) {
                $text = $row->message;
                $text = str_replace( '<!-- BM-AI -->', '', $text );
                $text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
                $text = trim( $text );

                if ( empty( $text ) ) {
                    continue;
                }

                $timestamp = (int) ( (int) $row->created_at / 10 / 1000 );
                $date = wp_date( 'Y-m-d H:i', $timestamp );

                $digests[] = array(
                    'date' => $date,
                    'text' => $text,
                );
            }

            return $digests;
        }

        private function get_last_digest_created_at( $thread_id )
        {
            global $wpdb;

            $result = $wpdb->get_var( $wpdb->prepare(
                "SELECT m.created_at
                FROM `" . bm_get_table( 'messages' ) . "` m
                INNER JOIN `" . bm_get_table( 'meta' ) . "` mt ON mt.bm_message_id = m.id
                WHERE m.thread_id = %d
                AND mt.meta_key = 'ai_digest'
                ORDER BY m.created_at DESC
                LIMIT 1",
                $thread_id
            ) );

            return $result ? (int) $result : 0;
        }

        private function build_system_prompt( $bot_settings, $sender_names = array() )
        {
            $style = $bot_settings['summarizationStyle'] ?? 'narrative';
            $length = $bot_settings['summarizationLength'] ?? 'brief';
            $language = $bot_settings['summarizationLanguage'] ?? '';
            $custom_prompt = $bot_settings['summarizationPrompt'] ?? '';

            $prompt = "You are a conversation summarizer. Your task is to create a clear, accurate summary of the provided conversation.\n\n";

            if ( ! empty( $sender_names ) ) {
                $participants = array();
                foreach ( $sender_names as $uid => $name ) {
                    $participants[] = $name . ' (ID: ' . $uid . ')';
                }
                $prompt .= "Participants: " . implode( ', ', $participants ) . ".\n";
                $prompt .= "When referring to participants in your summary, use this format: {{mention:USER_ID:@Username}}\n";
                $prompt .= "Replace USER_ID with the participant's numeric ID and @Username with their name.\n\n";
            }

            switch ( $style ) {
                case 'narrative':
                    $prompt .= "Write the summary as a cohesive narrative paragraph.\n";
                    break;
                case 'key_decisions':
                    $prompt .= "Focus on key decisions, agreements, and conclusions made during the conversation. Present them as a list.\n";
                    break;
                case 'action_items':
                    $prompt .= "Extract action items, tasks, and commitments from the conversation. Present them as a list with the responsible person when identifiable.\n";
                    break;
                case 'bullet_points':
                default:
                    $prompt .= "Present the summary as concise bullet points covering the main topics discussed.\n";
                    break;
            }

            if ( $length === 'brief' ) {
                $prompt .= "Keep the summary brief — no more than 3-5 points or 2-3 sentences.\n";
            } else {
                $prompt .= "Provide a detailed and comprehensive summary.\n";
            }

            if ( ! empty( $language ) ) {
                $prompt .= "Write the summary in " . $language . ".\n";
            } else {
                $prompt .= "Write the summary in the same language as the conversation.\n";
            }

            if ( ! empty( $custom_prompt ) ) {
                $prompt .= "\nAdditional instructions:\n" . $custom_prompt . "\n";
            }

            $prompt .= "\nDo not add any introductory or closing phrases like 'Here is the summary'. Just output the summary directly.";

            return $prompt;
        }

        private function build_messages_text( $thread_messages, $sender_names )
        {
            $lines = array();

            foreach ( $thread_messages as $msg ) {
                $sender_id = (int) $msg->sender_id;
                $name = isset( $sender_names[ $sender_id ] ) ? $sender_names[ $sender_id ] : 'User ' . $sender_id;

                $text = $msg->message;
                $text = preg_replace( '/<!--(.|\s)*?-->/', '', $text );
                $text = wp_strip_all_tags( html_entity_decode( $text ) );
                $text = trim( $text );

                if ( ! empty( $text ) ) {
                    $lines[] = '[' . $name . ']: ' . $text;
                }
            }

            return implode( "\n", $lines );
        }

        private function resolve_sender_names( $thread_messages )
        {
            global $wpdb;

            $user_ids = array();
            $guest_ids = array();

            foreach ( $thread_messages as $msg ) {
                $sid = (int) $msg->sender_id;
                if ( $sid > 0 ) {
                    $user_ids[] = $sid;
                } elseif ( $sid < 0 ) {
                    $guest_ids[] = absint( $sid );
                }
            }

            $names = array();

            if ( ! empty( $user_ids ) ) {
                $user_ids = array_unique( $user_ids );
                $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ({$placeholders})",
                    ...$user_ids
                ) );
                foreach ( $rows as $row ) {
                    $names[ (int) $row->ID ] = $row->display_name;
                }
            }

            if ( ! empty( $guest_ids ) ) {
                $guest_ids = array_unique( $guest_ids );
                $placeholders = implode( ',', array_fill( 0, count( $guest_ids ), '%d' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT id, name FROM `" . bm_get_table( 'guests' ) . "` WHERE id IN ({$placeholders})",
                    ...$guest_ids
                ) );
                foreach ( $rows as $row ) {
                    $names[ -1 * absint( $row->id ) ] = $row->name;
                }
            }

            return $names;
        }

        private function get_last_summary_created_at( $thread_id )
        {
            global $wpdb;

            $result = $wpdb->get_var( $wpdb->prepare(
                "SELECT m.created_at
                FROM `" . bm_get_table( 'messages' ) . "` m
                INNER JOIN `" . bm_get_table( 'meta' ) . "` mt ON mt.bm_message_id = m.id
                WHERE m.thread_id = %d
                AND mt.meta_key = 'ai_summary'
                ORDER BY m.created_at DESC
                LIMIT 1",
                $thread_id
            ) );

            return $result ? (int) $result : 0;
        }

        private function count_messages_since_summary( $thread_id )
        {
            global $wpdb;

            $last_summary_at = $this->get_last_summary_created_at( $thread_id );

            $where_after = $last_summary_at > 0
                ? $wpdb->prepare( " AND created_at > %d", $last_summary_at )
                : '';

            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*)
                FROM `" . bm_get_table( 'messages' ) . "`
                WHERE thread_id = %d
                {$where_after}",
                $thread_id
            ) );
        }

        /**
         * Send a JSON response and close the connection so the client returns immediately.
         * PHP continues executing after this call.
         */
        private function send_json_and_close( $data )
        {
            ignore_user_abort( true );
            set_time_limit( 0 );

            $json = wp_json_encode( $data );

            // Clean any existing output buffers
            while ( ob_get_level() > 0 ) {
                ob_end_clean();
            }

            header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
            header( 'Content-Length: ' . strlen( $json ) );
            header( 'Connection: close' );

            echo $json;
            flush();

            if ( function_exists( 'fastcgi_finish_request' ) ) {
                fastcgi_finish_request();
            }

            if ( function_exists( 'litespeed_finish_request' ) ) {
                litespeed_finish_request();
            }
        }

        public function user_can_configure( $user_id, $thread_id )
        {
            if ( Better_Messages()->functions->is_thread_super_moderator( $user_id, $thread_id ) ) {
                return true;
            }

            if ( Better_Messages()->functions->is_thread_moderator( $thread_id, $user_id ) ) {
                return true;
            }

            return false;
        }

        /**
         * Log an error to the bot's post meta. Keeps last 20 entries.
         */
        private function log_bot_error( $bot_id, $thread_id, $type, $error_message )
        {
            $meta_key = '_bm_ai_errors';
            $errors = get_post_meta( $bot_id, $meta_key, true );

            if ( ! is_array( $errors ) ) {
                $errors = array();
            }

            $errors[] = array(
                'type'      => $type,
                'thread_id' => $thread_id,
                'error'     => $error_message,
                'date'      => current_time( 'mysql' ),
            );

            if ( count( $errors ) > 20 ) {
                $errors = array_slice( $errors, -20 );
            }

            update_post_meta( $bot_id, $meta_key, $errors );
        }

        public function on_message_sent( $message )
        {
            $sender_id = (int) $message->sender_id;

            if ( $sender_id < 0 && Better_Messages()->functions->is_ai_bot_user( $sender_id ) ) {
                return;
            }

            $thread_id = (int) $message->thread_id;
            $config = $this->get_thread_config( $thread_id );

            if ( ! $config || empty( $config['botId'] ) || ( $config['schedule'] ?? 'manual' ) !== 'messages' ) {
                return;
            }

            $threshold = max( 10, intval( $config['autoMessages'] ?? 100 ) );
            $count = $this->count_messages_since_summary( $thread_id );

            if ( $count >= $threshold ) {
                if ( ! wp_next_scheduled( 'bm_generate_summary', array( $thread_id ) ) ) {
                    wp_schedule_single_event( time() + 10, 'bm_generate_summary', array( $thread_id ) );
                }
            }
        }

        public function cron_generate_summary( $thread_id )
        {
            $result = $this->generate_summary( $thread_id );
            if ( is_wp_error( $result ) ) {
                $config = $this->get_thread_config( $thread_id );
                if ( $config && ! empty( $config['botId'] ) ) {
                    $this->log_bot_error( (int) $config['botId'], $thread_id, 'summary', $result->get_error_message() );
                }
            }
        }

        public function cron_summarize_all()
        {
            global $wpdb;

            $meta_table = bm_get_table( 'threadsmeta' );

            $rows = $wpdb->get_results(
                "SELECT bm_thread_id, meta_value FROM `{$meta_table}` WHERE meta_key = 'ai_summarization'"
            );

            foreach ( $rows as $row ) {
                $thread_id = (int) $row->bm_thread_id;
                $config = json_decode( $row->meta_value, true );

                $schedule = $config['schedule'] ?? 'manual';

                if ( ! is_array( $config ) || empty( $config['botId'] ) || ! in_array( $schedule, array( 'hourly', 'twicedaily', 'daily' ) ) ) {
                    continue;
                }

                $threshold = max( 10, intval( $config['autoMessages'] ?? 100 ) );
                $count = $this->count_messages_since_summary( $thread_id );

                if ( $count >= $threshold ) {
                    $last_at = $this->get_last_summary_created_at( $thread_id );
                    $min_interval = $this->get_interval_seconds( $schedule );

                    if ( $last_at > 0 ) {
                        $last_time = (int) ( $last_at / 10 / 1000 );
                        if ( ( time() - $last_time ) < $min_interval ) {
                            continue;
                        }
                    }

                    $result = $this->generate_summary( $thread_id );
                    if ( is_wp_error( $result ) ) {
                        $this->log_bot_error( (int) $config['botId'], $thread_id, 'summary', $result->get_error_message() );
                    }
                }
            }

            // Digest loop
            $digest_rows = $wpdb->get_results(
                "SELECT bm_thread_id, meta_value FROM `{$meta_table}` WHERE meta_key = 'ai_digest_config'"
            );

            $tz = wp_timezone();
            $now = new \DateTime( 'now', $tz );
            $current_hour = (int) $now->format( 'G' );

            foreach ( $digest_rows as $row ) {
                $thread_id = (int) $row->bm_thread_id;
                $config = json_decode( $row->meta_value, true );

                if ( ! is_array( $config ) || empty( $config['botId'] ) ) {
                    continue;
                }

                $schedule = $config['schedule'] ?? 'daily';

                if ( ! in_array( $schedule, array( 'hourly', 'twicedaily', 'daily' ) ) ) {
                    continue;
                }

                // Check if current hour matches any configured digest time
                $digest_times = isset( $config['digestTimes'] ) && is_array( $config['digestTimes'] ) ? $config['digestTimes'] : array( '09:00' );

                if ( $schedule !== 'hourly' ) {
                    $allowed_hours = array();
                    foreach ( $digest_times as $time ) {
                        $parts = explode( ':', $time );
                        $allowed_hours[] = (int) $parts[0];
                    }

                    if ( ! in_array( $current_hour, $allowed_hours ) ) {
                        continue;
                    }
                }

                // Check minimum interval to prevent duplicate runs within the same hour
                $last_digest_at = $this->get_last_digest_created_at( $thread_id );
                if ( $last_digest_at > 0 ) {
                    $last_time = (int) ( $last_digest_at / 10 / 1000 );
                    if ( ( time() - $last_time ) < 3000 ) { // 50 min guard
                        continue;
                    }
                }

                $result = $this->generate_digest( $thread_id );
                if ( is_wp_error( $result ) ) {
                    $this->log_bot_error( (int) $config['botId'], $thread_id, 'digest', $result->get_error_message() );
                }
            }
        }

        private function get_interval_seconds( $interval )
        {
            switch ( $interval ) {
                case 'hourly':
                    return 3600;
                case 'twicedaily':
                    return 43200;
                case 'daily':
                default:
                    return 86400;
            }
        }

        private function get_bot_id_from_user( $user_id )
        {
            $guest_id = absint( $user_id );
            $guest = Better_Messages()->guests->get_guest_user( $guest_id );

            if ( $guest && $guest->ip && str_starts_with( $guest->ip, 'ai-chat-bot-' ) ) {
                return (int) str_replace( 'ai-chat-bot-', '', $guest->ip );
            }

            return false;
        }

        public function rest_thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id )
        {
            if ( ! $include_personal || $user_id <= 0 ) {
                return $thread_item;
            }

            if ( ! $this->user_can_configure( $user_id, $thread_id ) ) {
                return $thread_item;
            }

            if ( isset( $thread_item['participants'] ) ) {
                foreach ( $thread_item['participants'] as $participant_id ) {
                    if ( $participant_id >= 0 ) {
                        continue;
                    }

                    $bot_id = $this->get_bot_id_from_user( $participant_id );

                    if ( ! $bot_id || ! Better_Messages()->ai->bot_exists( $bot_id ) ) {
                        continue;
                    }

                    $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

                    if ( ( $bot_settings['summarizationEnabled'] ?? '0' ) === '1' ) {
                        $thread_item['canConfigureSummarization'] = true;
                    }

                    if ( ( $bot_settings['digestEnabled'] ?? '0' ) === '1' ) {
                        $thread_item['canConfigureDigest'] = true;
                    }

                    if ( ! empty( $thread_item['canConfigureSummarization'] ) && ! empty( $thread_item['canConfigureDigest'] ) ) {
                        break;
                    }
                }
            }

            $config = $this->get_thread_config( $thread_id );
            if ( $config && ! empty( $config['botId'] ) ) {
                $thread_item['canRequestSummary'] = true;
            }

            $digest_config = $this->get_thread_digest_config( $thread_id );
            if ( $digest_config && ! empty( $digest_config['botId'] ) ) {
                $thread_item['canRequestDigest'] = true;
            }

            return $thread_item;
        }

        public function rest_message_meta( $meta, $message_id, $thread_id, $message_content )
        {
            $summary = Better_Messages()->functions->get_message_meta( $message_id, 'ai_summary', true );

            if ( ! empty( $summary ) ) {
                $meta['aiSummary'] = true;
            }

            $digest = Better_Messages()->functions->get_message_meta( $message_id, 'ai_digest', true );

            if ( ! empty( $digest ) ) {
                $meta['aiDigest'] = true;
            }

            return $meta;
        }
    }
}
