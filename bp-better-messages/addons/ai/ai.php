<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_AI' ) ) {
    class Better_Messages_AI
    {
        public $api;
        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_AI();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'init',      array( $this, 'register_post_type' ) );
            add_filter( 'better_messages_rest_thread_item', array( $this, 'rest_thread_item'), 20, 5 );
            add_filter('better_messages_get_user_roles', array($this, 'get_user_roles'), 10, 2 );

            if ( version_compare(phpversion(), '8.1', '>=') ) {
                // Requires PHP 8.1+
                require_once "dependencies/autoload.php";
                require_once "api/provider-interface.php";
                require_once "api/provider-factory.php";
                require_once "api/open-ai.php";
                require_once "api/anthropic.php";
                require_once "api/gemini.php";
                require_once "summarization.php";

                $this->api = Better_Messages_OpenAI_API::instance();
                Better_Messages_AI_Summarization::instance();

                add_action( 'admin_init', array( $this, 'register_event' ) );
                add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
                add_action( 'save_post', array( $this, 'save_post' ), 1, 2 );

                add_action( 'bp_better_chat_settings_updated', array($this, 'check_if_api_key_valid'));

                add_action( 'better_messages_message_sent', array( $this, 'on_message_sent'), 10, 1 );
                add_action( 'better_messages_message_reply', array( $this, 'on_message_reply'), 10, 2 );
                add_action( 'better_messages_before_message_delete', array( $this, 'before_delete_message' ), 10 , 3 );

                add_action( 'bp_better_messages_new_thread_created', array( $this, 'on_new_thread_created'), 10, 2 );
                add_filter( 'better_messages_can_send_message', array( $this, 'block_reply_if_needed' ), 20, 3 );
                add_filter( 'better_messages_can_send_message', array( $this, 'check_ai_bot_balance' ), 21, 3 );
                add_action( 'better_messages_before_new_thread',  array( $this, 'restrict_new_thread_if_needed'), 10, 2 );
                add_action( 'better_messages_before_new_thread',  array( $this, 'check_ai_bot_new_thread_balance'), 11, 2 );
                add_action( 'better_messages_ai_response_completed', array( $this, 'charge_for_ai_response' ), 10, 5 );

                add_filter( 'better_messages_mentions_suggestions', array( $this, 'add_bot_mention_suggestions' ), 10, 3 );
                add_filter( 'better_messages_search_user_results', array( $this, 'add_bots_to_search_results' ), 10, 3 );
                add_action('better_messages_ai_bot_ensure_completion', array( $this, 'ai_bot_ensure_completion'), 10, 2 );
                add_action('better_messages_ai_ensure_completion_job', array( $this->api, 'ensureResponseCompletionJob' ) );

                if( Better_Messages()->settings['aiModerationEnabled'] === '1'
                    && ! empty( Better_Messages()->settings['openAiApiKey'] )
                ) {
                    add_action( 'better_messages_before_message_send', array( $this, 'moderate_message_content' ), 15, 2 );
                    add_action( 'better_messages_before_new_thread', array( $this, 'moderate_message_content' ), 15, 2 );
                    add_action( 'better_messages_message_sent', array( $this, 'schedule_background_moderation' ), 10, 1 );
                    add_action( 'better_messages_message_pending', array( $this, 'schedule_background_moderation' ), 10, 1 );
                    add_action( 'better_messages_ai_moderate_message', array( $this, 'run_background_moderation' ), 10, 1 );
                }

                if ( Better_Messages()->settings['voiceTranscription'] === '1'
                    && ! empty( Better_Messages()->settings['openAiApiKey'] )
                    && class_exists( 'BP_Better_Messages_Voice_Messages' )
                ) {
                    add_filter( 'better_messages_rest_message_meta', array( $this, 'voice_transcription_meta' ), 12, 4 );
                }
            }
        }

        public function register_event()
        {
            if ( ! wp_next_scheduled( 'better_messages_ai_ensure_completion_job' ) ) {
                wp_schedule_event( time(), 'better_messages_ai_ensure_completion_job', 'better_messages_ai_ensure_completion_job' );
            }
        }

        public function get_user_roles( $roles, $user_id )
        {
            if( $user_id < 0 ){
                $guest_id = absint($user_id);
                $guest = Better_Messages()->guests->get_guest_user( $guest_id );

                if( $guest && $guest->ip && str_starts_with($guest->ip, 'ai-chat-bot-') ){
                    $bot_id = str_replace('ai-chat-bot-', '', $guest->ip);

                    if( $this->bot_exists( $bot_id ) ){
                        $roles = ['bm-bot'];
                    }
                }
            }

            return $roles;
        }

        /**
         * Add AI bots with respondToMentions enabled to mention suggestions.
         */
        public function add_bot_mention_suggestions( $response, $thread_id, $search )
        {
            // Don't suggest bots in E2E encrypted threads
            if ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_e2e_thread( $thread_id ) ) {
                return $response;
            }

            $existing_ids = array_column( $response, 'user_id' );

            $bots = get_posts( array(
                'post_type'   => 'bm-ai-chat-bot',
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields'      => 'ids',
            ) );

            foreach ( $bots as $bot_id ) {
                $settings = $this->get_bot_settings( $bot_id );

                if ( $settings['enabled'] !== '1' || $settings['respondToMentions'] !== '1' ) {
                    continue;
                }

                $bot_user = $this->get_bot_user( $bot_id );
                if ( ! $bot_user ) continue;

                $bot_user_id = absint( $bot_user->id ) * -1;

                if ( in_array( $bot_user_id, $existing_ids ) ) continue;

                $bot_name = get_the_title( $bot_id );

                if ( ! empty( $search ) && stripos( $bot_name, $search ) === false ) {
                    continue;
                }

                $response[] = array(
                    'user_id' => $bot_user_id,
                    'label'   => $bot_name,
                );
            }

            return $response;
        }

        public function add_bots_to_search_results( $users, $search, $user_id )
        {
            global $wpdb;

            $search_like = '%' . $wpdb->esc_like( $search ) . '%';

            $bot_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT `ID` FROM `" . bm_get_table('users') . "`
                WHERE `ID` < 0
                AND `ID` IN (SELECT `user_id` FROM `" . bm_get_table('roles') . "` WHERE `role` = 'bm-bot')
                AND `display_name` LIKE %s
                LIMIT 10",
                $search_like
            ) );

            foreach ( $bot_ids as $bot_user_id ) {
                $bot_user_id = intval( $bot_user_id );
                if ( ! in_array( $bot_user_id, $users ) ) {
                    $users[] = $bot_user_id;
                }
            }

            return $users;
        }

        public function restrict_new_thread_if_needed( &$args, &$errors ){
            // Get array with recipients user ids, which user trying to start conversation with
            $recipients = $args['recipients'];

            if( $recipients && count( $recipients ) === 1 ){
                $recipient_id = reset( $recipients );

                if( $recipient_id < 0 ){
                    $guest_id = absint($recipient_id);
                    $guest = Better_Messages()->guests->get_guest_user( $guest_id );

                    if( $guest && $guest->ip && str_starts_with($guest->ip, 'ai-chat-bot-') ){
                        $bot_id = str_replace('ai-chat-bot-', '', $guest->ip);

                        if( $this->bot_exists( $bot_id ) ){
                            $bot_settings = $this->get_bot_settings( $bot_id );

                            $provider_id = ! empty( $bot_settings['provider'] ) ? $bot_settings['provider'] : 'openai';
                            $has_api_key = ! empty( $bot_settings['apiKey'] ) || ! empty( Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id ) );

                            if( $bot_settings['enabled'] !== '1' || empty( $bot_settings['model'] ) || ! $has_api_key ){
                                $errors['bot_disabled'] = _x('The bot is currently disabled', 'AI Chat Bots', 'bp-better-messages');
                            }
                        }
                    }
                }
            }
        }

        public function on_new_thread_created( $thread_id, $message_id = null )
        {
            $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );

            if( $thread_type !== 'thread'){
                return;
            }

            $recipients = Better_Messages()->functions->get_recipients( $thread_id );

            if( count( $recipients ) === 2 ) {
                foreach ($recipients as $user) {
                    $user_id = $user->user_id;
                    if ($user_id < 0) {
                        $guest_id = absint($user_id);
                        $guest = Better_Messages()->guests->get_guest_user($guest_id);

                        if ($guest && $guest->ip && str_starts_with($guest->ip, 'ai-chat-bot-')) {
                            $bot_id = str_replace('ai-chat-bot-', '', $guest->ip);

                            if( $this->bot_exists( $bot_id ) ){
                                Better_Messages()->functions->update_thread_meta( $thread_id, 'ai_bot_thread', $bot_id );
                            }
                        }
                    }
                }
            }
        }

        public function bot_exists( $bot_id )
        {
            $post = get_post( $bot_id );

            if( $post && $post->post_type === 'bm-ai-chat-bot' && $post->post_status !== 'trash' ){
                return true;
            }

            return false;
        }

        public function is_bot_conversation( $bot_id, $thread_id )
        {
            $bot_thread_id = Better_Messages()->functions->get_thread_meta( $thread_id, 'ai_bot_thread' );

            if( empty( $bot_thread_id) ) return false;

            return (int) $bot_thread_id === (int) $bot_id;
        }

        public function block_reply_if_needed( $allowed, $user_id, $thread_id )
        {
            $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );

            if( $thread_type !== 'thread'){
                return $allowed;
            }

            $recipients = Better_Messages()->functions->get_recipients( $thread_id );

            if( count( $recipients ) === 2 ) {
                foreach ($recipients as $user) {
                    $recipient_user_id = $user->user_id;
                    if ($recipient_user_id < 0) {
                        $guest_id = absint($recipient_user_id);
                        $guest = Better_Messages()->guests->get_guest_user($guest_id);

                        if ($guest && $guest->ip && str_starts_with($guest->ip, 'ai-chat-bot-')) {
                            $bot_id = str_replace('ai-chat-bot-', '', $guest->ip);

                            if( $this->bot_exists( $bot_id ) && $this->is_bot_conversation( $bot_id, $thread_id ) ){
                                $bot_settings = $this->get_bot_settings($bot_id);

                                $provider_id = ! empty( $bot_settings['provider'] ) ? $bot_settings['provider'] : 'openai';
                                $has_api_key = ! empty( $bot_settings['apiKey'] ) || ! empty( Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id ) );

                                if ( $bot_settings['enabled'] !== '1' || empty( $bot_settings['model'] ) || ! $has_api_key ) {
                                    $allowed = false;
                                    global $bp_better_messages_restrict_send_message;
                                    $bp_better_messages_restrict_send_message['bot_is_disabled'] = _x('The bot is currently disabled', 'AI Chat Bots', 'bp-better-messages');
                                } else {
                                    $is_waiting_for_response = Better_Messages()->functions->get_thread_meta( $thread_id, 'ai_waiting_for_response' );

                                    if ($is_waiting_for_response) {
                                        $time_ago = time() - $is_waiting_for_response;
                                        $time_limit = 60 * 5; // 5 minutes

                                        if ($time_ago < $time_limit) {
                                            $allowed = false;
                                            global $bp_better_messages_restrict_send_message;
                                            $bp_better_messages_restrict_send_message['waiting_for_ai_response'] = _x('Please wait until response is completed', 'AI Chat Bots', 'bp-better-messages');
                                        } else {
                                            Better_Messages()->functions->delete_thread_meta( $thread_id, 'ai_waiting_for_response' );
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $allowed;
        }

        private function is_user_exempt_from_pricing( $user_id, $bot_settings )
        {
            $exempt_roles = $bot_settings['userPricingExemptRoles'] ?? array( 'administrator' );
            if ( empty( $exempt_roles ) ) return false;

            $user = get_userdata( $user_id );
            if ( ! $user ) return false;

            return ! empty( array_intersect( $user->roles, $exempt_roles ) );
        }

        public function check_ai_bot_balance( $allowed, $user_id, $thread_id )
        {
            if ( ! $allowed ) return $allowed;
            if ( $user_id <= 0 ) return $allowed;

            $bot_id = Better_Messages()->functions->get_thread_meta( $thread_id, 'ai_bot_thread' );
            if ( empty( $bot_id ) ) return $allowed;

            $bot_settings = $this->get_bot_settings( $bot_id );
            $mode = $bot_settings['userPricingMode'] ?? 'disabled';
            if ( $mode === 'disabled' ) return $allowed;

            if ( $this->is_user_exempt_from_pricing( $user_id, $bot_settings ) ) return $allowed;

            if ( ! class_exists( 'Better_Messages_Points' ) ) return $allowed;
            $provider = Better_Messages_Points()->get_provider();
            if ( ! $provider ) return $allowed;

            $balance = $provider->get_user_balance( $user_id );
            $required = 0;

            if ( $mode === 'fixed' ) {
                $required = intval( $bot_settings['userPricingFixedAmount'] ?? 0 );
            } else if ( $mode === 'cost_based' ) {
                $required = intval( $bot_settings['userPricingMinimumBalance'] ?? 0 );
            }

            if ( $balance < $required ) {
                $allowed = false;
                global $bp_better_messages_restrict_send_message;
                $message = $bot_settings['userPricingInsufficientMessage'] ?? '';
                if ( empty( $message ) ) {
                    $message = _x( 'Insufficient points balance to use this bot', 'AI Chat Bots', 'bp-better-messages' );
                }
                $bp_better_messages_restrict_send_message['ai_bot_insufficient_balance'] = $message;
            }

            return $allowed;
        }

        public function check_ai_bot_new_thread_balance( &$args, &$errors )
        {
            $recipients = $args['recipients'];
            if ( ! is_array( $recipients ) || count( $recipients ) !== 1 ) return;

            $recipient_id = reset( $recipients );
            if ( $recipient_id >= 0 ) return;

            $bot_id = $this->get_bot_id_from_user( $recipient_id );
            if ( ! $bot_id ) return;

            $bot_settings = $this->get_bot_settings( $bot_id );
            $mode = $bot_settings['userPricingMode'] ?? 'disabled';
            if ( $mode === 'disabled' ) return;

            $user_id = Better_Messages()->functions->get_current_user_id();
            if ( $user_id <= 0 ) return;

            if ( $this->is_user_exempt_from_pricing( $user_id, $bot_settings ) ) return;

            if ( ! class_exists( 'Better_Messages_Points' ) ) return;
            $provider = Better_Messages_Points()->get_provider();
            if ( ! $provider ) return;

            $balance  = $provider->get_user_balance( $user_id );
            $required = 0;

            if ( $mode === 'fixed' ) {
                $required = intval( $bot_settings['userPricingFixedAmount'] ?? 0 );
            } else if ( $mode === 'cost_based' ) {
                $required = intval( $bot_settings['userPricingMinimumBalance'] ?? 0 );
            }

            if ( $required > 0 && $balance < $required ) {
                $message = $bot_settings['userPricingInsufficientMessage'] ?? '';
                if ( empty( $message ) ) {
                    $message = _x( 'Insufficient points balance to use this bot', 'AI Chat Bots', 'bp-better-messages' );
                }
                $errors['ai_bot_insufficient_balance'] = $message;
            }
        }

        /**
         * Check if a user has sufficient balance for a group mention/reply bot response.
         * Returns true if the bot should respond, false if the user lacks balance.
         */
        private function check_group_mention_balance( $user_id, $bot_settings )
        {
            if ( ( $bot_settings['userPricingChargeGroupMentions'] ?? '0' ) !== '1' ) {
                return true;
            }

            $mode = $bot_settings['userPricingMode'] ?? 'disabled';
            if ( $mode === 'disabled' ) return true;

            if ( $user_id <= 0 ) return true;

            if ( $this->is_user_exempt_from_pricing( $user_id, $bot_settings ) ) return true;

            if ( ! class_exists( 'Better_Messages_Points' ) ) return true;
            $provider = Better_Messages_Points()->get_provider();
            if ( ! $provider ) return true;

            $balance  = $provider->get_user_balance( $user_id );
            $required = 0;

            if ( $mode === 'fixed' ) {
                $required = intval( $bot_settings['userPricingFixedAmount'] ?? 0 );
            } else if ( $mode === 'cost_based' ) {
                $required = intval( $bot_settings['userPricingMinimumBalance'] ?? 0 );
            }

            return $balance >= $required;
        }

        public function charge_for_ai_response( $ai_message_id, $user_message_id, $cost_data, $bot_id, $user_id )
        {
            if ( $user_id <= 0 ) return;

            $bot_settings = $this->get_bot_settings( $bot_id );
            $mode = $bot_settings['userPricingMode'] ?? 'disabled';
            if ( $mode === 'disabled' ) return;

            // Skip charging for group mentions/replies if not enabled
            if ( ( $bot_settings['userPricingChargeGroupMentions'] ?? '0' ) !== '1' ) {
                $ai_message = Better_Messages()->functions->get_message( $ai_message_id );
                if ( $ai_message ) {
                    $recipients = Better_Messages()->functions->get_recipients( $ai_message->thread_id );
                    if ( count( $recipients ) > 2 ) {
                        return;
                    }
                }
            }

            if ( $this->is_user_exempt_from_pricing( $user_id, $bot_settings ) ) return;

            if ( ! class_exists( 'Better_Messages_Points' ) ) return;
            $provider = Better_Messages_Points()->get_provider();
            if ( ! $provider ) return;

            $charge_amount = 0;

            if ( $mode === 'fixed' ) {
                $charge_amount = intval( $bot_settings['userPricingFixedAmount'] ?? 0 );
            } else if ( $mode === 'cost_based' ) {
                $rate = intval( $bot_settings['userPricingCostRate'] ?? 0 );
                $total_cost = isset( $cost_data['totalCost'] ) ? floatval( $cost_data['totalCost'] ) : 0;
                $charge_amount = (int) ceil( $total_cost * $rate );
            }

            if ( $charge_amount <= 0 ) return;

            $log_template = $bot_settings['userPricingLogEntry'] ?? 'Better Messages for AI bot response #{id}';
            $log_entry = str_replace( '{id}', $ai_message_id, $log_template );

            $provider->deduct_points(
                $user_id,
                $charge_amount,
                'better_messages_ai_response_' . $ai_message_id,
                $log_entry
            );

            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_user_charge', $charge_amount );

            // Store points charged in ai_usage table
            global $wpdb;
            $ai_usage_table = bm_get_table('ai_usage');
            if ( $ai_usage_table ) {
                $wpdb->update(
                    $ai_usage_table,
                    array( 'points_charged' => $charge_amount ),
                    array( 'message_id' => $ai_message_id )
                );
            }
        }

        public function check_if_api_key_valid()
        {
            // Invalidate cached models lists when API keys change
            delete_transient( 'bm_openai_models' );
            delete_transient( 'bm_anthropic_models' );
            delete_transient( 'bm_gemini_models' );

            // Validate OpenAI key
            if ( ! empty( Better_Messages()->settings['openAiApiKey'] ) ) {
                $this->api->update_api_key();
                $this->api->check_api_key();
            } else {
                delete_option( 'better_messages_openai_error' );
            }

            // Validate Anthropic key
            if ( ! empty( Better_Messages()->settings['anthropicApiKey'] ) ) {
                $provider = Better_Messages_AI_Provider_Factory::create( 'anthropic' );
                $provider->set_api_key( Better_Messages()->settings['anthropicApiKey'] );
                $provider->check_api_key();
            } else {
                delete_option( 'better_messages_anthropic_error' );
            }

            // Validate Gemini key
            if ( ! empty( Better_Messages()->settings['geminiApiKey'] ) ) {
                $provider = Better_Messages_AI_Provider_Factory::create( 'gemini' );
                $provider->set_api_key( Better_Messages()->settings['geminiApiKey'] );
                $provider->check_api_key();
            } else {
                delete_option( 'better_messages_gemini_error' );
            }
        }

        public function rest_api_init()
        {
            register_rest_route('better-messages/v1/ai', '/createResponse', array(
                'methods' => 'GET',
                'callback' => array( $this, 'handle_create_response'),
                'permission_callback' => function( WP_REST_Request $request ) {
                    $provided = $request->get_param('secret');
                    if( ! empty( $provided ) && $provided === $this->get_ai_request_secret() ){
                        return true;
                    }
                    return false;
                },
            ));

            register_rest_route('better-messages/v1/ai', '/cancelResponse/(?P<id>\d+)', array(
                'methods' => 'POST',
                'callback' => array( $this, 'handle_cancel_response'),
                'permission_callback' => array( Better_Messages()->api, 'check_thread_access' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            if ( Better_Messages()->settings['voiceTranscription'] === '1'
                && ! empty( Better_Messages()->settings['openAiApiKey'] )
            ) {
                register_rest_route('better-messages/v1/ai', '/transcribeVoice/(?P<id>\d+)/(?P<message_id>\d+)', array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'transcribe_voice_message' ),
                    'permission_callback' => array( Better_Messages()->api, 'check_thread_access' ),
                    'args' => array(
                        'id' => array(
                            'validate_callback' => function( $param ) {
                                return is_numeric( $param );
                            }
                        ),
                        'message_id' => array(
                            'validate_callback' => function( $param ) {
                                return is_numeric( $param );
                            }
                        ),
                    ),
                ));
            }

            register_rest_route('better-messages/v1/admin/ai', '/getModels', array(
                'methods' => 'GET',
                'callback' => array( $this, 'handle_get_models'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/getTranscriptionModels', array(
                'methods' => 'GET',
                'callback' => array( $this, 'handle_get_transcription_models'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/getProviders', array(
                'methods' => 'GET',
                'callback' => array( $this, 'handle_get_providers'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            // Admin bot CRUD endpoints
            register_rest_route('better-messages/v1/admin/ai', '/bots', array(
                'methods' => 'GET',
                'callback' => array( $this, 'rest_get_bots'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/bots', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_create_bot'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/bots/(?P<id>\d+)', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_update_bot'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/bots/(?P<id>\d+)', array(
                'methods' => 'DELETE',
                'callback' => array( $this, 'rest_delete_bot'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/bots/(?P<id>\d+)/cleanup', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_cleanup_bot_data'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            // Bot conversation management endpoints
            register_rest_route('better-messages/v1/admin/ai', '/bots/(?P<id>\d+)/conversations', array(
                'methods' => 'GET',
                'callback' => array( $this, 'rest_get_bot_conversations'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/bots/(?P<id>\d+)/conversations', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_add_bot_to_conversation'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/bots/(?P<id>\d+)/conversations/(?P<thread_id>\d+)', array(
                'methods' => 'DELETE',
                'callback' => array( $this, 'rest_remove_bot_from_conversation'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                    'thread_id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            // Test API key endpoint
            register_rest_route('better-messages/v1/admin/ai', '/testApiKey', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_test_api_key'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            // Token usage endpoint
            register_rest_route('better-messages/v1/admin/ai', '/bots/(?P<id>\d+)/usage', array(
                'methods' => 'GET',
                'callback' => array( $this, 'rest_get_bot_usage'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            // Error log endpoint
            register_rest_route('better-messages/v1/admin/ai', '/bots/(?P<id>\d+)/errors', array(
                'methods' => 'GET',
                'callback' => array( $this, 'rest_get_bot_errors'),
                'permission_callback' => array($this, 'user_is_admin'),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            register_rest_route('better-messages/v1/ai', '/moderateMessage', array(
                'methods' => 'GET',
                'callback' => array( $this, 'rest_moderate_message'),
                'permission_callback' => function( WP_REST_Request $request ) {
                    $provided = $request->get_param('secret');
                    if( ! empty( $provided ) && $provided === $this->get_ai_request_secret() ){
                        return true;
                    }
                    return false;
                },
            ));

        }

        /**
         * Get the provider instance for a specific bot
         */
        public function get_bot_provider( $bot_id )
        {
            $settings    = $this->get_bot_settings( $bot_id );
            $provider_id = ! empty( $settings['provider'] ) ? $settings['provider'] : 'openai';

            $provider = Better_Messages_AI_Provider_Factory::create( $provider_id );

            if ( ! $provider ) {
                $provider = Better_Messages_AI_Provider_Factory::create( 'openai' );
            }

            $global_key = Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );
            $provider->set_api_key( $global_key );

            if ( ! empty( $settings['apiKey'] ) ) {
                $provider->set_bot_api_key( $settings['apiKey'] );
            }

            return $provider;
        }

        /**
         * REST: Create AI response (delegates to bot's provider)
         */
        public function handle_create_response( WP_REST_Request $request )
        {
            Better_Messages()->functions->end_browser_output();

            $bot_id     = (int) $request->get_param( 'bot_id' );
            $message_id = (int) $request->get_param( 'message_id' );

            if ( ! empty( $bot_id ) && ! empty( $message_id ) ) {
                $provider = $this->get_bot_provider( $bot_id );
                $provider->process_reply( $bot_id, $message_id );
            }
        }

        /**
         * REST: Cancel AI response (determines provider from message meta)
         */
        public function handle_cancel_response( WP_REST_Request $request )
        {
            global $wpdb;

            $user_id   = Better_Messages()->functions->get_current_user_id();
            $thread_id = (int) $request->get_param( 'id' );

            $is_waiting = Better_Messages()->functions->get_thread_meta( $thread_id, 'ai_waiting_for_response' );

            if ( $is_waiting ) {
                $query = $wpdb->prepare( "
                    SELECT id, thread_id, sender_id, message, created_at, updated_at, temp_id
                    FROM  " . bm_get_table('messages') . "
                    WHERE `thread_id` = %d
                    ORDER BY `created_at` DESC
                    LIMIT 0, 1
                    ", $thread_id );

                $message = $wpdb->get_row( $query, ARRAY_A );

                if ( $message && str_starts_with( $message['message'], '<!-- BM-AI -->' ) ) {
                    $message_id  = $message['id'];
                    $provider_id = Better_Messages()->functions->get_message_meta( $message_id, 'ai_provider' );

                    if ( empty( $provider_id ) ) {
                        $provider_id = 'openai';
                    }

                    Better_Messages()->functions->add_message_meta( $message_id, 'ai_waiting_for_cancel', time() );

                    // For OpenAI: wait for response_id and cancel via API
                    if ( $provider_id === 'openai' ) {
                        $response_id = Better_Messages()->functions->get_message_meta( $message_id, 'openai_response_id' );

                        $wait_time = 0;
                        while ( ! $response_id && $wait_time < 20 ) {
                            sleep(1);
                            $wait_time++;
                            $table = bm_get_table('meta');
                            $response_id = $wpdb->get_var( $wpdb->prepare( "SELECT `meta_value` FROM `{$table}` WHERE `bm_message_id` = %d AND `meta_key` = 'openai_response_id'", $message_id ) );
                        }

                        if ( $response_id ) {
                            $provider = $this->get_bot_provider_by_message( $message_id, $provider_id );

                            try {
                                $cancelled = $provider->cancel_response_api( $response_id );
                                if ( $cancelled ) {
                                    Better_Messages()->functions->delete_message( $message_id, $thread_id );
                                }
                            } catch ( \Throwable $e ) {
                                Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                            } finally {
                                Better_Messages()->functions->delete_thread_meta( $thread_id, 'ai_waiting_for_response' );
                                do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
                                do_action( 'better_messages_thread_updated', $thread_id, $user_id );
                            }
                        } else {
                            Better_Messages()->functions->delete_thread_meta( $thread_id, 'ai_waiting_for_response' );
                            do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
                            do_action( 'better_messages_thread_updated', $thread_id, $user_id );
                        }
                    } else {
                        // For non-OpenAI providers: just clean up
                        Better_Messages()->functions->delete_message( $message_id, $thread_id );
                        Better_Messages()->functions->delete_thread_meta( $thread_id, 'ai_waiting_for_response' );
                        do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
                        do_action( 'better_messages_thread_updated', $thread_id, $user_id );
                    }
                } else {
                    // Last message is not an AI message (e.g. cancel pressed before AI placeholder was created)
                    Better_Messages()->functions->delete_thread_meta( $thread_id, 'ai_waiting_for_response' );
                    do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
                    do_action( 'better_messages_thread_updated', $thread_id, $user_id );
                }
            } else {
                Better_Messages()->functions->delete_thread_meta( $thread_id, 'ai_waiting_for_response' );
                do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
                do_action( 'better_messages_thread_updated', $thread_id, $user_id );
            }

            return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
        }

        /**
         * Get a provider instance using message meta for API key context
         */
        private function get_bot_provider_by_message( $message_id, $provider_id )
        {
            $bot_id = Better_Messages()->functions->get_message_meta( $message_id, 'ai_bot_id' );

            if ( $bot_id ) {
                return $this->get_bot_provider( $bot_id );
            }

            $provider = Better_Messages_AI_Provider_Factory::create( $provider_id );
            $global_key = Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );
            $provider->set_api_key( $global_key );
            return $provider;
        }

        /**
         * REST: Get models for a specific provider
         */
        public function handle_get_models( WP_REST_Request $request )
        {
            $provider_id = $request->get_param( 'provider' ) ?: 'openai';
            $api_key     = $request->get_param( 'apiKey' ) ?: '';

            $provider   = Better_Messages_AI_Provider_Factory::create( $provider_id );
            $global_key = Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );
            $provider->set_api_key( $global_key );

            if ( ! empty( $api_key ) ) {
                $provider->set_bot_api_key( $api_key );
            }

            return $provider->get_models();
        }

        /**
         * REST: Get transcription models for a specific provider
         */
        public function handle_get_transcription_models( WP_REST_Request $request )
        {
            $provider_id = $request->get_param( 'provider' ) ?: 'openai';

            $provider   = Better_Messages_AI_Provider_Factory::create( $provider_id );
            $global_key = Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );
            $provider->set_api_key( $global_key );

            return $provider->get_transcription_models();
        }

        /**
         * REST: Get available providers info
         */
        public function handle_get_providers( WP_REST_Request $request )
        {
            return Better_Messages_AI_Provider_Factory::get_providers_info();
        }

        /**
         * REST: Get bots (paginated)
         */
        public function rest_get_bots( WP_REST_Request $request )
        {
            $page     = max( 1, (int) ( $request->get_param('page') ?: 1 ) );
            $per_page = max( 1, min( 100, (int) ( $request->get_param('per_page') ?: 20 ) ) );
            $search   = sanitize_text_field( $request->get_param('search') ?: '' );

            $query_args = array(
                'post_type'      => 'bm-ai-chat-bot',
                'post_status'    => array('publish', 'draft'),
                'posts_per_page' => $per_page,
                'paged'          => $page,
                'orderby'        => 'date',
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

            $bots = array();
            foreach ( $query->posts as $post ) {
                $bots[] = $this->format_bot_for_rest( $post );
            }

            return array(
                'items'   => $bots,
                'total'   => (int) $query->found_posts,
                'page'    => $page,
                'perPage' => $per_page,
                'pages'   => (int) $query->max_num_pages,
            );
        }

        /**
         * REST: Create a new bot
         */
        public function rest_create_bot( WP_REST_Request $request )
        {
            $name = sanitize_text_field( $request->get_param('name') ?: '' );

            if ( empty( $name ) ) {
                return new WP_Error('missing_name', _x('Bot name is required', 'AI Chat Bots (WP Admin)', 'bp-better-messages'), array('status' => 400));
            }

            $post_id = wp_insert_post(array(
                'post_type'   => 'bm-ai-chat-bot',
                'post_title'  => $name,
                'post_status' => 'publish',
            ));

            if ( is_wp_error( $post_id ) ) {
                return $post_id;
            }

            $defaults = $this->get_default_settings();
            update_post_meta( $post_id, 'bm-ai-chat-bot-settings', $defaults );
            $this->create_or_update_bot_user( $post_id, $name );

            $post = get_post( $post_id );
            return $this->format_bot_for_rest( $post );
        }

        /**
         * REST: Update a bot
         */
        public function rest_update_bot( WP_REST_Request $request )
        {
            $bot_id = (int) $request->get_param('id');
            $post = get_post( $bot_id );

            if ( ! $post || $post->post_type !== 'bm-ai-chat-bot' ) {
                return new WP_Error('not_found', 'Bot not found', array('status' => 404));
            }

            $params = $request->get_json_params();

            // Update title if provided
            if ( isset( $params['name'] ) ) {
                $name = sanitize_text_field( $params['name'] );
                if ( ! empty( $name ) ) {
                    wp_update_post(array(
                        'ID'         => $bot_id,
                        'post_title' => $name,
                    ));
                    $this->create_or_update_bot_user( $bot_id, $name );
                }
            }

            // Update avatar if provided
            if ( isset( $params['avatarId'] ) ) {
                $avatar_id = (int) $params['avatarId'];
                if ( $avatar_id > 0 ) {
                    set_post_thumbnail( $bot_id, $avatar_id );
                } else {
                    delete_post_thumbnail( $bot_id );
                }
            }

            // Update settings if provided
            if ( isset( $params['settings'] ) && is_array( $params['settings'] ) ) {
                $new_settings = $params['settings'];
                $old_settings = $this->get_bot_settings( $bot_id );

                // Sanitize
                foreach ( $new_settings as $key => $value ) {
                    if ( is_string( $value ) ) {
                        if ( $key === 'instruction' || $key === 'summarizationPrompt' ) {
                            $new_settings[$key] = sanitize_textarea_field( $value );
                        } else {
                            $new_settings[$key] = sanitize_text_field( $value );
                        }
                    }
                }

                // Handle fileSearchVectorIds
                if ( ! empty( $new_settings['fileSearchVectorIds'] ) && is_string( $new_settings['fileSearchVectorIds'] ) ) {
                    $lines = explode( "\n", $new_settings['fileSearchVectorIds'] );
                    $vector_ids = array();
                    $added = 0;
                    foreach ( $lines as $line ) {
                        $line = trim( $line );
                        if ( ! empty( $line ) ) {
                            $vector_ids[] = $line;
                            $added++;
                        }
                        if ( $added >= 2 ) break;
                    }
                    $new_settings['fileSearchVectorIds'] = array_unique( $vector_ids );
                } else if ( isset( $new_settings['fileSearchVectorIds'] ) && is_array( $new_settings['fileSearchVectorIds'] ) ) {
                    // Already an array, keep it
                } else {
                    $new_settings['fileSearchVectorIds'] = $old_settings['fileSearchVectorIds'];
                }

                // Handle userPricingExemptRoles
                if ( isset( $new_settings['userPricingExemptRoles'] ) && is_array( $new_settings['userPricingExemptRoles'] ) ) {
                    $new_settings['userPricingExemptRoles'] = array_map( 'sanitize_text_field', $new_settings['userPricingExemptRoles'] );
                } else {
                    $new_settings['userPricingExemptRoles'] = $old_settings['userPricingExemptRoles'] ?? array( 'administrator' );
                }

                $defaults = $this->get_default_settings();
                $merged = wp_parse_args( $new_settings, $old_settings );
                $merged = wp_parse_args( $merged, $defaults );

                update_post_meta( $bot_id, 'bm-ai-chat-bot-settings', $merged );
            }

            $post = get_post( $bot_id );
            return $this->format_bot_for_rest( $post );
        }

        /**
         * REST: Delete a bot
         */
        public function rest_delete_bot( WP_REST_Request $request )
        {
            $bot_id = (int) $request->get_param('id');
            $post = get_post( $bot_id );

            if ( ! $post || $post->post_type !== 'bm-ai-chat-bot' ) {
                return new WP_Error('not_found', 'Bot not found', array('status' => 404));
            }

            $bot_user = $this->get_bot_user( $bot_id );

            if ( $bot_user ) {
                // Delete the guest user record
                global $wpdb;
                $wpdb->delete( bm_get_table('guests'), array( 'id' => $bot_user->id ) );
                wp_cache_delete( 'bot_user_' . $bot_id, 'bm_messages' );
                wp_cache_delete( 'guest_user_' . $bot_user->id, 'bm_messages' );
            }

            // Force delete, skip trash
            wp_delete_post( $bot_id, true );

            return array( 'success' => true );
        }

        /**
         * REST: Batch cleanup bot data (messages/threads) with progress
         */
        public function rest_cleanup_bot_data( WP_REST_Request $request )
        {
            global $wpdb;

            $bot_id = (int) $request->get_param('id');
            $post = get_post( $bot_id );

            if ( ! $post || $post->post_type !== 'bm-ai-chat-bot' ) {
                return new WP_Error('not_found', 'Bot not found', array('status' => 404));
            }

            $params = $request->get_json_params();
            $delete_messages        = ! empty( $params['deleteMessages'] );
            $delete_private_threads = ! empty( $params['deletePrivateThreads'] );
            $batch_size             = 10;

            $bot_user = $this->get_bot_user( $bot_id );
            if ( ! $bot_user ) {
                return array( 'done' => true, 'deleted' => 0, 'remaining' => 0 );
            }

            $bot_user_id = absint( $bot_user->id ) * -1;

            $thread_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT thread_id FROM " . bm_get_table('recipients') . " WHERE user_id = %d LIMIT %d",
                $bot_user_id, $batch_size
            ) );

            if ( empty( $thread_ids ) ) {
                return array( 'done' => true, 'deleted' => 0, 'remaining' => 0 );
            }

            $deleted = 0;

            foreach ( $thread_ids as $thread_id ) {
                $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );

                if ( $delete_private_threads && $thread_type === 'thread' ) {
                    $recipients = Better_Messages()->functions->get_recipients( $thread_id );
                    if ( count( $recipients ) <= 2 ) {
                        Better_Messages()->functions->erase_thread( $thread_id );
                        $deleted++;
                        continue;
                    }
                }

                if ( $delete_messages ) {
                    $message_ids = $wpdb->get_col( $wpdb->prepare(
                        "SELECT id FROM " . bm_get_table('messages') . " WHERE thread_id = %d AND sender_id = %d",
                        $thread_id, $bot_user_id
                    ) );

                    foreach ( $message_ids as $message_id ) {
                        Better_Messages()->functions->delete_message( $message_id, $thread_id, false, 'delete' );
                    }

                    if ( ! empty( $message_ids ) ) {
                        do_action( 'better_messages_thread_updated', $thread_id );
                    }
                }

                // Remove bot from this thread's recipients so next batch picks up new threads
                $wpdb->delete( bm_get_table('recipients'), array(
                    'thread_id' => $thread_id,
                    'user_id'   => $bot_user_id,
                ) );

                $deleted++;
            }

            $remaining = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . bm_get_table('recipients') . " WHERE user_id = %d",
                $bot_user_id
            ) );

            return array(
                'done'      => $remaining === 0,
                'deleted'   => $deleted,
                'remaining' => $remaining,
            );
        }

        /**
         * REST: Get all conversations a bot participates in
         */
        public function rest_get_bot_conversations( WP_REST_Request $request )
        {
            global $wpdb;

            $bot_id   = (int) $request->get_param('id');
            $page     = max( 1, (int) $request->get_param('page') ?: 1 );
            $per_page = 20;

            if ( ! $this->bot_exists( $bot_id ) ) {
                return new WP_Error('not_found', 'Bot not found', array('status' => 404));
            }

            $bot_user = $this->get_bot_user( $bot_id );

            if ( ! $bot_user ) {
                return array( 'items' => array(), 'total' => 0, 'page' => 1, 'perPage' => $per_page );
            }

            $bot_user_id = absint( $bot_user->id ) * -1;

            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . bm_get_table('recipients') . " WHERE user_id = %d",
                $bot_user_id
            ) );

            if ( $total === 0 ) {
                return array( 'items' => array(), 'total' => 0, 'page' => 1, 'perPage' => $per_page );
            }

            $offset = ( $page - 1 ) * $per_page;
            $thread_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT thread_id FROM " . bm_get_table('recipients') . " WHERE user_id = %d ORDER BY thread_id DESC LIMIT %d OFFSET %d",
                $bot_user_id, $per_page, $offset
            ) );

            if ( empty( $thread_ids ) ) {
                return array( 'items' => array(), 'total' => $total, 'page' => $page, 'perPage' => $per_page );
            }

            $thread_ids = array_map( 'intval', $thread_ids );
            $placeholders = implode( ',', array_fill( 0, count( $thread_ids ), '%d' ) );

            // Thread types (uses caching internally)
            $thread_types = array();
            foreach ( $thread_ids as $tid ) {
                $thread_types[ $tid ] = Better_Messages()->functions->get_thread_type( $tid );
            }

            // Batch: recipient counts per thread
            $count_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT thread_id, COUNT(*) as cnt FROM " . bm_get_table('recipients') . "
                 WHERE thread_id IN ($placeholders) GROUP BY thread_id",
                ...$thread_ids
            ) );
            $thread_recipient_counts = array();
            foreach ( $count_rows as $r ) {
                $thread_recipient_counts[ (int) $r->thread_id ] = (int) $r->cnt;
            }

            // For label building: only need up to 5 non-bot participants per thread
            // Use a query with row numbering to limit per thread
            $recip_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT thread_id, user_id FROM " . bm_get_table('recipients') . "
                 WHERE thread_id IN ($placeholders) AND user_id != %d
                 ORDER BY thread_id, user_id LIMIT 1000",
                ...array_merge( $thread_ids, array( $bot_user_id ) )
            ) );
            $thread_sample_users = array();
            $all_user_ids = array();
            $all_guest_ids = array();
            foreach ( $recip_rows as $r ) {
                $tid = (int) $r->thread_id;
                $uid = (int) $r->user_id;
                if ( ! isset( $thread_sample_users[ $tid ] ) ) {
                    $thread_sample_users[ $tid ] = array();
                }
                if ( count( $thread_sample_users[ $tid ] ) < 5 ) {
                    $thread_sample_users[ $tid ][] = $uid;
                    if ( $uid > 0 ) {
                        $all_user_ids[] = $uid;
                    } else {
                        $all_guest_ids[] = absint( $uid );
                    }
                }
            }

            // Batch: user display names
            $user_names = array();
            if ( ! empty( $all_user_ids ) ) {
                $all_user_ids = array_unique( $all_user_ids );
                $user_placeholders = implode( ',', array_fill( 0, count( $all_user_ids ), '%d' ) );
                $user_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($user_placeholders)",
                    ...$all_user_ids
                ) );
                foreach ( $user_rows as $r ) {
                    $user_names[ (int) $r->ID ] = $r->display_name;
                }
            }

            // Batch: guest names
            $guest_names = array();
            if ( ! empty( $all_guest_ids ) && isset( Better_Messages()->guests ) ) {
                foreach ( array_unique( $all_guest_ids ) as $gid ) {
                    $guest = Better_Messages()->guests->get_guest_user( $gid );
                    if ( $guest ) {
                        $guest_names[ $gid ] = $guest->name;
                    }
                }
            }

            // Batch: last message dates
            $last_msg_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT thread_id, MAX(created_at) as last_date FROM " . bm_get_table('messages') . "
                 WHERE thread_id IN ($placeholders) GROUP BY thread_id",
                ...$thread_ids
            ) );
            $last_messages = array();
            foreach ( $last_msg_rows as $r ) {
                $last_messages[ (int) $r->thread_id ] = $r->last_date;
            }

            // Batch: chat_id for chat-room threads
            $chat_room_ids = array();
            foreach ( $thread_ids as $tid ) {
                if ( isset( $thread_types[ $tid ] ) && $thread_types[ $tid ] === 'chat-room' ) {
                    $chat_room_ids[] = $tid;
                }
            }
            $chat_labels = array();
            if ( ! empty( $chat_room_ids ) ) {
                $cr_placeholders = implode( ',', array_fill( 0, count( $chat_room_ids ), '%d' ) );
                $chat_id_rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT bm_thread_id, meta_value FROM " . bm_get_table('threadsmeta') . "
                     WHERE bm_thread_id IN ($cr_placeholders) AND meta_key = 'chat_id'",
                    ...$chat_room_ids
                ) );
                $post_ids = array();
                foreach ( $chat_id_rows as $r ) {
                    $post_ids[ (int) $r->bm_thread_id ] = (int) $r->meta_value;
                }
                foreach ( $post_ids as $tid => $pid ) {
                    $chat_post = get_post( $pid );
                    if ( $chat_post ) {
                        $chat_labels[ $tid ] = $chat_post->post_title;
                    }
                }
            }

            // Batch: per-conversation bot settings
            $settings_key = 'ai_bot_settings_' . $bot_id;
            $settings_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT bm_thread_id, meta_value FROM " . bm_get_table('threadsmeta') . "
                 WHERE bm_thread_id IN ($placeholders) AND meta_key = %s",
                ...array_merge( $thread_ids, array( $settings_key ) )
            ) );
            $conv_settings_map = array();
            foreach ( $settings_rows as $r ) {
                $val = maybe_unserialize( $r->meta_value );
                if ( ! empty( $val ) ) {
                    $conv_settings_map[ (int) $r->bm_thread_id ] = $val;
                }
            }

            // Build response
            $conversations = array();
            foreach ( $thread_ids as $thread_id ) {
                $thread_type = isset( $thread_types[ $thread_id ] ) ? $thread_types[ $thread_id ] : 'thread';
                $recipient_count = isset( $thread_recipient_counts[ $thread_id ] ) ? $thread_recipient_counts[ $thread_id ] : 0;
                $sample_users = isset( $thread_sample_users[ $thread_id ] ) ? $thread_sample_users[ $thread_id ] : array();

                // Thread label
                $label = '';
                if ( $thread_type === 'chat-room' && isset( $chat_labels[ $thread_id ] ) ) {
                    $label = $chat_labels[ $thread_id ];
                } else if ( $thread_type === 'group' ) {
                    $label = apply_filters( 'better_messages_thread_title', '', $thread_id );
                }

                if ( empty( $label ) ) {
                    $participant_names = array();
                    foreach ( $sample_users as $uid ) {
                        if ( $uid > 0 ) {
                            $participant_names[] = isset( $user_names[ $uid ] ) ? $user_names[ $uid ] : sprintf( 'User #%d', $uid );
                        } else {
                            $gid = absint( $uid );
                            $participant_names[] = isset( $guest_names[ $gid ] ) ? $guest_names[ $gid ] : sprintf( 'Guest #%d', $gid );
                        }
                    }
                    $others = $recipient_count - 1 - count( $participant_names ); // -1 for bot
                    $label = implode( ', ', $participant_names );
                    if ( $others > 0 ) {
                        $label .= sprintf( ' +%d', $others );
                    }
                }

                $conversations[] = array(
                    'threadId'         => $thread_id,
                    'type'             => $thread_type,
                    'label'            => $label,
                    'participantCount' => $recipient_count,
                    'lastMessageDate'  => isset( $last_messages[ $thread_id ] ) ? $last_messages[ $thread_id ] : '',
                    'settings'         => isset( $conv_settings_map[ $thread_id ] ) ? $conv_settings_map[ $thread_id ] : new \stdClass(),
                );
            }

            return array(
                'items'   => $conversations,
                'total'   => $total,
                'page'    => $page,
                'perPage' => $per_page,
            );
        }

        /**
         * REST: Add bot to a conversation
         */
        public function rest_add_bot_to_conversation( WP_REST_Request $request )
        {
            $bot_id    = (int) $request->get_param('id');
            $params    = $request->get_json_params();
            $thread_id = isset( $params['threadId'] ) ? (int) $params['threadId'] : 0;

            if ( ! $this->bot_exists( $bot_id ) ) {
                return new WP_Error('not_found', 'Bot not found', array('status' => 404));
            }

            if ( $thread_id <= 0 ) {
                return new WP_Error('invalid_thread', 'Thread ID is required', array('status' => 400));
            }

            $thread = Better_Messages()->functions->get_thread( $thread_id );

            if ( ! $thread ) {
                return new WP_Error('thread_not_found', 'Thread not found', array('status' => 404));
            }

            if ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_e2e_thread( $thread_id ) ) {
                return new WP_Error('e2ee_thread', 'Bots cannot be added to end-to-end encrypted conversations', array('status' => 400));
            }

            $bot_user = $this->get_bot_user( $bot_id );

            if ( ! $bot_user ) {
                return new WP_Error('bot_user_not_found', 'Bot user not found', array('status' => 404));
            }

            $bot_user_id = absint( $bot_user->id ) * -1;

            $result = Better_Messages()->functions->add_participant_to_thread( $thread_id, $bot_user_id );

            if ( ! $result ) {
                return new WP_Error('add_failed', 'Failed to add bot to conversation', array('status' => 500));
            }

            return array( 'success' => true );
        }

        /**
         * REST: Remove bot from a conversation
         */
        public function rest_remove_bot_from_conversation( WP_REST_Request $request )
        {
            $bot_id    = (int) $request->get_param('id');
            $thread_id = (int) $request->get_param('thread_id');

            if ( ! $this->bot_exists( $bot_id ) ) {
                return new WP_Error('not_found', 'Bot not found', array('status' => 404));
            }

            $bot_user = $this->get_bot_user( $bot_id );

            if ( ! $bot_user ) {
                return new WP_Error('bot_user_not_found', 'Bot user not found', array('status' => 404));
            }

            $bot_user_id = absint( $bot_user->id ) * -1;

            Better_Messages()->functions->remove_participant_from_thread( $thread_id, $bot_user_id );

            return array( 'success' => true );
        }

        /**
         * REST: Test an AI provider API key
         */
        public function rest_test_api_key( WP_REST_Request $request )
        {
            $provider_id = sanitize_text_field( $request->get_param('provider') );
            $api_key     = sanitize_text_field( $request->get_param('apiKey') );

            if ( empty( $provider_id ) ) {
                return new WP_Error( 'missing_provider', 'Provider is required', array( 'status' => 400 ) );
            }

            $provider = Better_Messages_AI_Provider_Factory::create( $provider_id );

            if ( ! $provider ) {
                return new WP_Error( 'invalid_provider', 'Unknown provider', array( 'status' => 400 ) );
            }

            // Use provided key or fall back to saved global key
            if ( ! empty( $api_key ) ) {
                $provider->set_api_key( $api_key );
            } else {
                $global_key = Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id );
                if ( empty( $global_key ) ) {
                    return new WP_Error( 'no_api_key', 'No API key configured', array( 'status' => 400 ) );
                }
                $provider->set_api_key( $global_key );
            }

            // Clear model cache to force a fresh API call
            $cache_keys = array(
                'openai'    => 'bm_openai_models',
                'anthropic' => 'bm_anthropic_models',
                'gemini'    => 'bm_gemini_models',
            );
            if ( isset( $cache_keys[ $provider_id ] ) ) {
                delete_transient( $cache_keys[ $provider_id ] );
            }

            $models = $provider->get_models();

            if ( is_wp_error( $models ) ) {
                return new WP_REST_Response( array(
                    'success' => false,
                    'error'   => $models->get_error_message(),
                ), 200 );
            }

            return new WP_REST_Response( array(
                'success'     => true,
                'modelsCount' => count( $models ),
            ), 200 );
        }

        /**
         * REST: Get token usage stats for a bot
         */
        public function rest_get_bot_usage( WP_REST_Request $request )
        {
            global $wpdb;

            $bot_id   = (int) $request->get_param('id');
            $page     = max( 1, (int) $request->get_param('page') ?: 1 );
            $per_page = 50;

            if ( ! $this->bot_exists( $bot_id ) ) {
                return new WP_Error('not_found', 'Bot not found', array('status' => 404));
            }

            $empty = array( 'items' => array(), 'total' => 0, 'page' => 1, 'perPage' => 50, 'totals' => array( 'inputTokens' => 0, 'outputTokens' => 0, 'thinkingTokens' => 0, 'cacheReadTokens' => 0, 'cacheWriteTokens' => 0, 'imagesCount' => 0, 'imageCost' => 0 ) );

            $ai_usage_table = bm_get_table('ai_usage');
            if ( ! $ai_usage_table ) {
                return $empty;
            }

            // Get total count
            $total = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$ai_usage_table} WHERE bot_id = %d",
                $bot_id
            ) );

            if ( $total === 0 ) {
                return $empty;
            }

            // Get paginated items
            $offset = ( $page - 1 ) * $per_page;
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, message_id, thread_id, user_id, is_summary, points_charged, cost_data, created_at
                 FROM {$ai_usage_table}
                 WHERE bot_id = %d
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                $bot_id, $per_page, $offset
            ) );

            $items = array();

            foreach ( $rows as $row ) {
                $cost = json_decode( $row->cost_data, true );
                if ( ! is_array( $cost ) ) continue;

                $item = array(
                    'messageId'         => (int) $row->message_id,
                    'threadId'          => (int) $row->thread_id,
                    'date'              => $row->created_at,
                    'model'             => isset( $cost['model'] ) ? $cost['model'] : '',
                    'provider'          => isset( $cost['provider'] ) ? $cost['provider'] : '',
                    'inputTokens'       => (int) ( $cost['inputTokens'] ?? 0 ),
                    'outputTokens'      => (int) ( $cost['outputTokens'] ?? 0 ),
                    'thinkingTokens'    => (int) ( $cost['thinkingTokens'] ?? 0 ),
                    'cacheReadTokens'   => (int) ( $cost['cacheReadTokens'] ?? 0 ),
                    'cacheWriteTokens'  => (int) ( $cost['cacheWriteTokens'] ?? 0 ),
                    'imagesCount'       => (int) ( $cost['imagesCount'] ?? 0 ),
                    'imageCost'         => (float) ( $cost['imageCost'] ?? 0 ),
                    'totalCost'         => isset( $cost['totalCost'] ) ? (float) $cost['totalCost'] : null,
                    'userId'            => (int) $row->user_id,
                );

                if ( $row->is_summary ) {
                    $item['isSummary'] = true;
                }

                if ( (int) $row->points_charged > 0 ) {
                    $item['pointsCharged'] = (int) $row->points_charged;
                }

                $items[] = $item;
            }

            // Get overall totals via PHP aggregation (compatible with all MySQL/MariaDB versions)
            $all_cost_rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT cost_data FROM {$ai_usage_table} WHERE bot_id = %d",
                $bot_id
            ) );

            $grand_input = 0;
            $grand_output = 0;
            $grand_thinking = 0;
            $grand_cache_read = 0;
            $grand_cache_write = 0;
            $grand_images = 0;
            $grand_image_cost = 0;
            $grand_total_cost = 0;
            $has_any_cost = false;

            foreach ( $all_cost_rows as $cost_json ) {
                $cost = json_decode( $cost_json, true );
                if ( ! is_array( $cost ) ) continue;

                $grand_input       += (int) ( $cost['inputTokens'] ?? 0 );
                $grand_output      += (int) ( $cost['outputTokens'] ?? 0 );
                $grand_thinking    += (int) ( $cost['thinkingTokens'] ?? 0 );
                $grand_cache_read  += (int) ( $cost['cacheReadTokens'] ?? 0 );
                $grand_cache_write += (int) ( $cost['cacheWriteTokens'] ?? 0 );
                $grand_images      += (int) ( $cost['imagesCount'] ?? 0 );
                $grand_image_cost  += (float) ( $cost['imageCost'] ?? 0 );
                if ( isset( $cost['totalCost'] ) ) {
                    $grand_total_cost += (float) $cost['totalCost'];
                    $has_any_cost = true;
                }
            }

            $totals = array(
                'inputTokens'      => $grand_input,
                'outputTokens'     => $grand_output,
                'thinkingTokens'   => $grand_thinking,
                'cacheReadTokens'  => $grand_cache_read,
                'cacheWriteTokens' => $grand_cache_write,
                'imagesCount'      => $grand_images,
                'imageCost'        => round( $grand_image_cost, 6 ),
            );

            if ( $has_any_cost ) {
                $totals['totalCost'] = round( $grand_total_cost, 6 );
            }

            $grand_points = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(points_charged), 0) FROM {$ai_usage_table} WHERE bot_id = %d",
                $bot_id
            ) );
            if ( $grand_points > 0 ) {
                $totals['totalPointsCharged'] = $grand_points;
            }

            // Resolve user display names for userId fields
            $user_ids = array_unique( array_filter( array_column( $items, 'userId' ) ) );
            $users = array();
            foreach ( $user_ids as $uid ) {
                $user = get_userdata( $uid );
                if ( $user ) {
                    $users[ $uid ] = $user->display_name;
                }
            }

            return array(
                'items'   => $items,
                'total'   => $total,
                'page'    => $page,
                'perPage' => $per_page,
                'totals'  => $totals,
                'users'   => (object) $users,
            );
        }

        /**
         * REST: Get recent errors for a bot
         */
        public function rest_get_bot_errors( WP_REST_Request $request )
        {
            global $wpdb;

            $bot_id = (int) $request->get_param('id');

            if ( ! $this->bot_exists( $bot_id ) ) {
                return new WP_Error('not_found', 'Bot not found', array('status' => 404));
            }

            $items = array();

            $bot_user = $this->get_bot_user( $bot_id );

            if ( $bot_user ) {
                $bot_user_id    = absint( $bot_user->id ) * -1;
                $messages_table = bm_get_table('messages');
                $meta_table     = bm_get_table('meta');

                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT m.id, m.thread_id, m.created_at, em.meta_value as error
                     FROM {$messages_table} m
                     INNER JOIN {$meta_table} em ON em.bm_message_id = m.id AND em.meta_key = 'ai_response_error'
                     WHERE m.sender_id = %d
                     ORDER BY m.created_at DESC
                     LIMIT 20",
                    $bot_user_id
                ) );

                foreach ( $rows as $row ) {
                    $items[] = array(
                        'messageId' => (int) $row->id,
                        'threadId'  => (int) $row->thread_id,
                        'date'      => $row->created_at,
                        'error'     => $row->error,
                    );
                }
            }

            // Include digest/summary errors from post meta
            $post_meta_errors = get_post_meta( $bot_id, '_bm_ai_errors', true );
            if ( is_array( $post_meta_errors ) ) {
                foreach ( $post_meta_errors as $entry ) {
                    $timestamp = strtotime( $entry['date'] );
                    $items[] = array(
                        'messageId' => 0,
                        'threadId'  => (int) $entry['thread_id'],
                        'date'      => $timestamp * 1000 * 10,
                        'error'     => '[' . ucfirst( $entry['type'] ) . '] ' . $entry['error'],
                    );
                }

                // Re-sort by date descending, limit to 20
                usort( $items, function( $a, $b ) {
                    return $b['date'] <=> $a['date'];
                } );
                $items = array_slice( $items, 0, 20 );
            }

            return array( 'items' => $items );
        }

        /**
         * Parse usage tokens from provider meta into a normalized format.
         *
         * For Anthropic: input_tokens includes ALL input (regular + cache).
         *   cache_creation_input_tokens and cache_read_input_tokens are subsets of input_tokens.
         * For OpenAI Responses API: input_tokens is total, input_tokens_details.cached_tokens is the cached subset.
         *   output_tokens_details.reasoning_tokens gives thinking tokens (subset of output_tokens).
         * For Gemini: candidatesTokenCount is text output, thoughtsTokenCount is thinking output (separate).
         */
        private function parse_usage_tokens( $usage, $provider = '' )
        {
            $result = array(
                'inputTokens'      => 0,
                'outputTokens'     => 0,
                'thinkingTokens'   => 0,
                'cacheReadTokens'  => 0,
                'cacheWriteTokens' => 0,
                'audioInputTokens'  => 0,
                'audioOutputTokens' => 0,
            );

            if ( isset( $usage['input_tokens'] ) ) {
                // OpenAI & Anthropic format
                $result['inputTokens']  = (int) $usage['input_tokens'];
                $result['outputTokens'] = (int) ( $usage['output_tokens'] ?? 0 );

                // Anthropic cache tokens
                if ( isset( $usage['cache_read_input_tokens'] ) ) {
                    $result['cacheReadTokens'] = (int) $usage['cache_read_input_tokens'];
                }
                if ( isset( $usage['cache_creation_input_tokens'] ) ) {
                    $result['cacheWriteTokens'] = (int) $usage['cache_creation_input_tokens'];
                }

                // OpenAI cached tokens (Responses API)
                if ( isset( $usage['input_tokens_details']['cached_tokens'] ) ) {
                    $result['cacheReadTokens'] = (int) $usage['input_tokens_details']['cached_tokens'];
                }

                // OpenAI reasoning tokens (subset of output_tokens)
                if ( isset( $usage['output_tokens_details']['reasoning_tokens'] ) ) {
                    $result['thinkingTokens'] = (int) $usage['output_tokens_details']['reasoning_tokens'];
                }

                // OpenAI audio tokens (Responses API)
                if ( isset( $usage['input_tokens_details']['audio_tokens'] ) ) {
                    $result['audioInputTokens'] = (int) $usage['input_tokens_details']['audio_tokens'];
                }
                if ( isset( $usage['output_tokens_details']['audio_tokens'] ) ) {
                    $result['audioOutputTokens'] = (int) $usage['output_tokens_details']['audio_tokens'];
                }
            } else if ( isset( $usage['promptTokenCount'] ) ) {
                // Gemini format
                $result['inputTokens']  = (int) $usage['promptTokenCount'];
                $result['outputTokens'] = (int) ( $usage['candidatesTokenCount'] ?? 0 );

                // Gemini thinking tokens are SEPARATE from candidatesTokenCount
                if ( isset( $usage['thoughtsTokenCount'] ) ) {
                    $thinking = (int) $usage['thoughtsTokenCount'];
                    $result['thinkingTokens'] = $thinking;
                    // Add to output since thinking is charged at output rate
                    $result['outputTokens'] += $thinking;
                }

                if ( isset( $usage['cachedContentTokenCount'] ) ) {
                    $result['cacheReadTokens'] = (int) $usage['cachedContentTokenCount'];
                }
            } else if ( isset( $usage['total_tokens'] ) ) {
                // Legacy OpenAI format
                $result['inputTokens']  = (int) ( $usage['prompt_tokens'] ?? 0 );
                $result['outputTokens'] = (int) ( $usage['completion_tokens'] ?? 0 );

                if ( isset( $usage['prompt_tokens_details']['cached_tokens'] ) ) {
                    $result['cacheReadTokens'] = (int) $usage['prompt_tokens_details']['cached_tokens'];
                }

                if ( isset( $usage['completion_tokens_details']['reasoning_tokens'] ) ) {
                    $result['thinkingTokens'] = (int) $usage['completion_tokens_details']['reasoning_tokens'];
                }

                // Audio tokens (legacy Chat Completions)
                if ( isset( $usage['prompt_tokens_details']['audio_tokens'] ) ) {
                    $result['audioInputTokens'] = (int) $usage['prompt_tokens_details']['audio_tokens'];
                }
                if ( isset( $usage['completion_tokens_details']['audio_tokens'] ) ) {
                    $result['audioOutputTokens'] = (int) $usage['completion_tokens_details']['audio_tokens'];
                }
            }

            return $result;
        }

        /**
         * Built-in pricing for tool calls.
         * Returns cost per single call in USD.
         */
        public function get_tool_pricing()
        {
            return array(
                'web_search_reasoning'     => 0.01,   // OpenAI reasoning models (gpt-5, o-series): $10 per 1k calls + search tokens at model rate
                'web_search_non_reasoning' => 0.025,  // OpenAI non-reasoning models: $25 per 1k calls, search tokens free
                'file_search'              => 0.0025, // OpenAI: $2.50 per 1k calls
                'gemini_search_2x'         => 0.035,  // Gemini 2.x: $35 per 1k grounded prompts
                'gemini_search_3x'         => 0.014,  // Gemini 3.x: $14 per 1k search queries
            );
        }

        /**
         * Calculate cost for generated images based on model, quality and size.
         */
        private function calc_image_cost( $images )
        {
            if ( empty( $images ) || ! is_array( $images ) ) {
                return 0;
            }

            $pricing = $this->get_image_pricing();

            $total = 0;
            foreach ( $images as $img ) {
                $model   = isset( $img['model'] ) ? $img['model'] : 'gpt-image-1';
                $quality = isset( $img['quality'] ) ? $img['quality'] : 'medium';
                $size    = isset( $img['size'] ) ? $img['size'] : '1024x1024';

                // 'auto' defaults
                if ( $quality === 'auto' ) $quality = 'medium';
                if ( $size === 'auto' )    $size = '1024x1024';

                if ( isset( $pricing[ $model ][ $quality ][ $size ] ) ) {
                    $total += $pricing[ $model ][ $quality ][ $size ];
                } else if ( isset( $pricing[ $model ]['default'][ $size ] ) ) {
                    // Gemini models use 'default' quality
                    $total += $pricing[ $model ]['default'][ $size ];
                } else if ( isset( $pricing[ $model ]['default'] ) ) {
                    // Gemini fallback: first available size in default quality
                    $sizes = $pricing[ $model ]['default'];
                    $total += reset( $sizes );
                } else if ( isset( $pricing[ $model ]['medium']['1024x1024'] ) ) {
                    // OpenAI fallback to medium 1024x1024
                    $total += $pricing[ $model ]['medium']['1024x1024'];
                }
            }

            return round( $total, 6 );
        }

        /**
         * Return model pricing rules in a JSON-serializable format for the frontend.
         * Structure: { provider: [ [pattern, {input, output, cacheRead, cacheWrite}], ... ] }
         */
        public function get_model_pricing_rules()
        {
            // Call get_model_pricing with dummy args to populate the static $rules
            $this->get_model_pricing( '', '' );

            // Access the static rules via reflection-free approach: just rebuild from the same source
            $result = array();
            foreach ( array( 'anthropic', 'openai', 'gemini' ) as $provider ) {
                $result[ $provider ] = array();
            }

            // We need the raw rules — get_model_pricing uses a static var, so let's just
            // expose the same data structure. The patterns are PHP regex strings like "/foo/"
            // which JS can reconstruct via new RegExp(inner).
            $rules = $this->get_pricing_rules_raw();

            foreach ( $rules as $provider => $provider_rules ) {
                $result[ $provider ] = array();
                foreach ( $provider_rules as $rule ) {
                    // Strip PHP regex delimiters: "/pattern/" -> "pattern"
                    $pattern = trim( $rule[0], '/' );
                    $result[ $provider ][] = array( $pattern, $rule[1] );
                }
            }

            return $result;
        }

        /**
         * Return the raw pricing rules array (used by both get_model_pricing and get_model_pricing_rules).
         */
        private function get_pricing_rules_raw()
        {
            static $rules = null;

            if ( $rules === null ) {
                $rules = array(
                    'anthropic' => array(
                        array( '/claude-opus-4-[56]/',  array( 'input' => 5,     'output' => 25,   'cacheRead' => 0.50,  'cacheWrite' => 6.25 ) ),
                        array( '/claude-opus-4-1/',     array( 'input' => 15,    'output' => 75,   'cacheRead' => 1.50,  'cacheWrite' => 18.75 ) ),
                        array( '/claude-opus-4/',       array( 'input' => 15,    'output' => 75,   'cacheRead' => 1.50,  'cacheWrite' => 18.75 ) ),
                        array( '/claude-sonnet-4/',     array( 'input' => 3,     'output' => 15,   'cacheRead' => 0.30,  'cacheWrite' => 3.75 ) ),
                        array( '/claude-3-7-sonnet/',   array( 'input' => 3,     'output' => 15,   'cacheRead' => 0.30,  'cacheWrite' => 3.75 ) ),
                        array( '/claude-3-5-sonnet/',   array( 'input' => 3,     'output' => 15,   'cacheRead' => 0.30,  'cacheWrite' => 3.75 ) ),
                        array( '/claude-haiku-4/',      array( 'input' => 1,     'output' => 5,    'cacheRead' => 0.10,  'cacheWrite' => 1.25 ) ),
                        array( '/claude-3-5-haiku/',    array( 'input' => 0.80,  'output' => 4,    'cacheRead' => 0.08,  'cacheWrite' => 1 ) ),
                        array( '/claude-3-opus/',       array( 'input' => 15,    'output' => 75,   'cacheRead' => 1.50,  'cacheWrite' => 18.75 ) ),
                        array( '/claude-3-haiku/',      array( 'input' => 0.25,  'output' => 1.25, 'cacheRead' => 0.03,  'cacheWrite' => 0.30 ) ),
                    ),
                    'openai' => array(
                        array( '/gpt-5\\.4-pro/',          array( 'input' => 30,    'output' => 180,  'cacheRead' => 30,    'cacheWrite' => 30 ) ),
                        array( '/gpt-5\\.4/',              array( 'input' => 2.50,  'output' => 15,   'cacheRead' => 0.25,  'cacheWrite' => 2.50 ) ),
                        array( '/gpt-5\\.3/',              array( 'input' => 1.75,  'output' => 14,   'cacheRead' => 0.175, 'cacheWrite' => 1.75 ) ),
                        array( '/gpt-5\\.2-pro/',          array( 'input' => 21,    'output' => 168,  'cacheRead' => 21,    'cacheWrite' => 21 ) ),
                        array( '/gpt-5\\.2/',              array( 'input' => 1.75,  'output' => 14,   'cacheRead' => 0.175, 'cacheWrite' => 1.75 ) ),
                        array( '/gpt-5\\.1-codex-mini/',   array( 'input' => 0.25,  'output' => 2,    'cacheRead' => 0.025, 'cacheWrite' => 0.25 ) ),
                        array( '/gpt-5\\.1/',              array( 'input' => 1.25,  'output' => 10,   'cacheRead' => 0.125, 'cacheWrite' => 1.25 ) ),
                        array( '/gpt-5-pro/',             array( 'input' => 15,    'output' => 120,  'cacheRead' => 15,    'cacheWrite' => 15 ) ),
                        array( '/gpt-5-nano/',            array( 'input' => 0.05,  'output' => 0.40, 'cacheRead' => 0.005, 'cacheWrite' => 0.05 ) ),
                        array( '/gpt-5-mini/',            array( 'input' => 0.25,  'output' => 2,    'cacheRead' => 0.025, 'cacheWrite' => 0.25 ) ),
                        array( '/gpt-5-search/',          array( 'input' => 1.25,  'output' => 10,   'cacheRead' => 0.125, 'cacheWrite' => 1.25 ) ),
                        array( '/gpt-5/',                 array( 'input' => 1.25,  'output' => 10,   'cacheRead' => 0.125, 'cacheWrite' => 1.25 ) ),
                        array( '/gpt-4\\.1-nano/',         array( 'input' => 0.10,  'output' => 0.40, 'cacheRead' => 0.025, 'cacheWrite' => 0.10 ) ),
                        array( '/gpt-4\\.1-mini/',         array( 'input' => 0.40,  'output' => 1.60, 'cacheRead' => 0.10,  'cacheWrite' => 0.40 ) ),
                        array( '/gpt-4\\.1/',              array( 'input' => 2,     'output' => 8,    'cacheRead' => 0.50,  'cacheWrite' => 2 ) ),
                        array( '/gpt-4o-mini-audio/',     array( 'input' => 0.15,  'output' => 0.60, 'cacheRead' => 0.15,  'cacheWrite' => 0.15, 'audioInput' => 10,   'audioOutput' => 20 ) ),
                        array( '/gpt-4o-mini-realtime/',  array( 'input' => 0.60,  'output' => 2.40, 'cacheRead' => 0.30,  'cacheWrite' => 0.60, 'audioInput' => 10,   'audioOutput' => 20 ) ),
                        array( '/gpt-4o-mini/',           array( 'input' => 0.15,  'output' => 0.60, 'cacheRead' => 0.075, 'cacheWrite' => 0.15 ) ),
                        array( '/gpt-4o-audio/',          array( 'input' => 2.50,  'output' => 10,   'cacheRead' => 2.50,  'cacheWrite' => 2.50, 'audioInput' => 40,   'audioOutput' => 80 ) ),
                        array( '/gpt-4o-realtime/',       array( 'input' => 5,     'output' => 20,   'cacheRead' => 2.50,  'cacheWrite' => 5,    'audioInput' => 40,   'audioOutput' => 80 ) ),
                        array( '/gpt-4o/',                array( 'input' => 2.50,  'output' => 10,   'cacheRead' => 1.25,  'cacheWrite' => 2.50 ) ),
                        array( '/gpt-realtime-mini/',     array( 'input' => 0.60,  'output' => 2.40, 'cacheRead' => 0.06,  'cacheWrite' => 0.60, 'audioInput' => 10,   'audioOutput' => 20 ) ),
                        array( '/gpt-realtime-1\.5/',     array( 'input' => 4,     'output' => 16,   'cacheRead' => 0.40,  'cacheWrite' => 4,    'audioInput' => 32,   'audioOutput' => 64 ) ),
                        array( '/gpt-realtime/',          array( 'input' => 4,     'output' => 16,   'cacheRead' => 0.40,  'cacheWrite' => 4,    'audioInput' => 32,   'audioOutput' => 64 ) ),
                        array( '/gpt-audio-mini/',        array( 'input' => 0.60,  'output' => 2.40, 'cacheRead' => 0.60,  'cacheWrite' => 0.60, 'audioInput' => 10,   'audioOutput' => 20 ) ),
                        array( '/gpt-audio-1\.5/',        array( 'input' => 2.50,  'output' => 10,   'cacheRead' => 2.50,  'cacheWrite' => 2.50, 'audioInput' => 32,   'audioOutput' => 64 ) ),
                        array( '/gpt-audio/',             array( 'input' => 2.50,  'output' => 10,   'cacheRead' => 2.50,  'cacheWrite' => 2.50, 'audioInput' => 32,   'audioOutput' => 64 ) ),
                        array( '/o1-pro/',                array( 'input' => 150,   'output' => 600,  'cacheRead' => 150,   'cacheWrite' => 150 ) ),
                        array( '/o1-mini/',               array( 'input' => 1.10,  'output' => 4.40, 'cacheRead' => 0.55,  'cacheWrite' => 1.10 ) ),
                        array( '/o1/',                    array( 'input' => 15,    'output' => 60,   'cacheRead' => 7.50,  'cacheWrite' => 15 ) ),
                        array( '/o3-pro/',                array( 'input' => 20,    'output' => 80,   'cacheRead' => 20,    'cacheWrite' => 20 ) ),
                        array( '/o3-deep-research/',      array( 'input' => 10,    'output' => 40,   'cacheRead' => 2.50,  'cacheWrite' => 10 ) ),
                        array( '/o3-mini/',               array( 'input' => 1.10,  'output' => 4.40, 'cacheRead' => 0.55,  'cacheWrite' => 1.10 ) ),
                        array( '/o3/',                    array( 'input' => 2,     'output' => 8,    'cacheRead' => 0.50,  'cacheWrite' => 2 ) ),
                        array( '/o4-mini-deep-research/', array( 'input' => 2,     'output' => 8,    'cacheRead' => 0.50,  'cacheWrite' => 2 ) ),
                        array( '/o4-mini/',               array( 'input' => 1.10,  'output' => 4.40, 'cacheRead' => 0.275, 'cacheWrite' => 1.10 ) ),
                    ),
                    'gemini' => array(
                        array( '/gemini-3\\.1-pro/',            array( 'input' => 2,     'output' => 12,   'cacheRead' => 0.20,  'cacheWrite' => 2 ) ),
                        array( '/gemini-3\\.1-flash-lite/',     array( 'input' => 0.25,  'output' => 1.50, 'cacheRead' => 0.025, 'cacheWrite' => 0.25 ) ),
                        array( '/gemini-3\\.1-flash-image/',    array( 'input' => 0.50,  'output' => 3,    'cacheRead' => 0.50,  'cacheWrite' => 0.50 ) ),
                        array( '/gemini-3\\.1-flash/',          array( 'input' => 0.25,  'output' => 1.50, 'cacheRead' => 0.025, 'cacheWrite' => 0.25 ) ),
                        array( '/gemini-3-pro-image/',          array( 'input' => 2,     'output' => 12,   'cacheRead' => 2,     'cacheWrite' => 2 ) ),
                        array( '/gemini-3-flash/',              array( 'input' => 0.50,  'output' => 3,    'cacheRead' => 0.05,  'cacheWrite' => 0.50 ) ),
                        array( '/gemini-3-pro/',                array( 'input' => 2,     'output' => 12,   'cacheRead' => 0.20,  'cacheWrite' => 2 ) ),
                        array( '/gemini-2\\.5-pro/',            array( 'input' => 1.25,  'output' => 10,   'cacheRead' => 0.125, 'cacheWrite' => 1.25 ) ),
                        array( '/gemini-2\\.5-flash-lite/',     array( 'input' => 0.10,  'output' => 0.40, 'cacheRead' => 0.01,  'cacheWrite' => 0.10 ) ),
                        array( '/gemini-2\\.5-flash-image/',    array( 'input' => 0.30,  'output' => 2.50, 'cacheRead' => 0.30,  'cacheWrite' => 0.30 ) ),
                        array( '/gemini-2\\.5-flash/',          array( 'input' => 0.30,  'output' => 2.50, 'cacheRead' => 0.03,  'cacheWrite' => 0.30 ) ),
                        array( '/gemini-2\\.0-flash-lite/',     array( 'input' => 0.075, 'output' => 0.30, 'cacheRead' => 0.075, 'cacheWrite' => 0.075 ) ),
                        array( '/gemini-2\\.0-flash/',          array( 'input' => 0.10,  'output' => 0.40, 'cacheRead' => 0.025, 'cacheWrite' => 0.10 ) ),
                    ),
                );
            }

            return $rules;
        }

        /**
         * Built-in pricing database for known AI models.
         * Prices are per 1 million tokens in USD.
         * Returns array with keys: input, output, cacheRead, cacheWrite — or null if model not found.
         */
        public function get_model_pricing( $model_id, $provider )
        {
            $rules = $this->get_pricing_rules_raw();

            if ( ! isset( $rules[ $provider ] ) ) {
                return null;
            }

            foreach ( $rules[ $provider ] as $rule ) {
                if ( preg_match( $rule[0], $model_id ) ) {
                    return $rule[1];
                }
            }

            return null;
        }

        /**
         * Get image generation pricing table.
         * Returns array of model => quality => size => price per image.
         */
        public function get_image_pricing()
        {
            return array(
                    // OpenAI models
                    'gpt-image-1-mini' => array(
                            'low'    => array( '1024x1024' => 0.005, '1024x1536' => 0.006, '1536x1024' => 0.006 ),
                            'medium' => array( '1024x1024' => 0.011, '1024x1536' => 0.015, '1536x1024' => 0.015 ),
                            'high'   => array( '1024x1024' => 0.036, '1024x1536' => 0.052, '1536x1024' => 0.052 ),
                    ),
                    'gpt-image-1' => array(
                            'low'    => array( '1024x1024' => 0.011, '1024x1536' => 0.016, '1536x1024' => 0.016 ),
                            'medium' => array( '1024x1024' => 0.042, '1024x1536' => 0.063, '1536x1024' => 0.063 ),
                            'high'   => array( '1024x1024' => 0.167, '1024x1536' => 0.25,  '1536x1024' => 0.25 ),
                    ),
                    'gpt-image-1.5' => array(
                            'low'    => array( '1024x1024' => 0.009, '1024x1536' => 0.013, '1536x1024' => 0.013 ),
                            'medium' => array( '1024x1024' => 0.034, '1024x1536' => 0.05,  '1536x1024' => 0.05 ),
                            'high'   => array( '1024x1024' => 0.133, '1024x1536' => 0.20,  '1536x1024' => 0.20 ),
                    ),
                    // Gemini models (native image generation — single quality level)
                    'gemini-2.0-flash' => array(
                            'default' => array( '1024x1024' => 0.039 ),
                    ),
                    'gemini-2.5-flash-image' => array(
                            'default' => array( '1024x1024' => 0.039 ),
                    ),
                    'gemini-3.1-flash-image' => array(
                            'default' => array( '512x512' => 0.045, '1024x1024' => 0.067, '2048x2048' => 0.101, '4096x4096' => 0.151 ),
                    ),
                    'gemini-3-pro-image' => array(
                            'default' => array( '1024x1024' => 0.134, '2048x2048' => 0.134, '4096x4096' => 0.24 ),
                    ),
            );
        }

        /**
         * Calculate the cost of an AI response and store it as message meta.
         * Uses bot-configured prices if set, otherwise falls back to built-in pricing database.
         * Returns the cost array or null if no pricing data available.
         */
        public function calculate_and_store_cost( $ai_message_id, $meta, $bot_id, $user_id = 0, $thread_id = 0, $is_summary = false )
        {
            $usage    = isset( $meta['usage'] ) ? $meta['usage'] : array();
            $model    = isset( $meta['model'] ) ? $meta['model'] : '';
            $provider = isset( $meta['provider'] ) ? $meta['provider'] : '';

            $parsed = $this->parse_usage_tokens( $usage, $provider );

            // Get prices: bot settings first, then built-in database
            $bot_settings = $this->get_bot_settings( $bot_id );
            $custom_input  = floatval( $bot_settings['inputTokenPrice'] );
            $custom_output = floatval( $bot_settings['outputTokenPrice'] );
            $custom_cache_read  = floatval( $bot_settings['cacheReadTokenPrice'] );
            $custom_cache_write = floatval( $bot_settings['cacheWriteTokenPrice'] );

            $has_custom = $custom_input > 0 || $custom_output > 0;

            if ( $has_custom ) {
                $prices = array(
                    'input'      => $custom_input,
                    'output'     => $custom_output,
                    'cacheRead'  => $custom_cache_read ?: $custom_input,
                    'cacheWrite' => $custom_cache_write ?: $custom_input,
                );
            } else {
                $prices = $this->get_model_pricing( $model, $provider );
            }

            // OpenAI service tier pricing adjustments (only for built-in prices)
            $service_tier = isset( $meta['service_tier'] ) ? $meta['service_tier'] : '';
            if ( $prices && ! $has_custom ) {
                if ( $service_tier === 'flex' ) {
                    // Flex = 50% discount
                    $prices['input']      *= 0.5;
                    $prices['output']     *= 0.5;
                    $prices['cacheRead']  *= 0.5;
                    $prices['cacheWrite'] *= 0.5;
                } else if ( $service_tier === 'priority' ) {
                    // Priority = 2x cost
                    $prices['input']      *= 2;
                    $prices['output']     *= 2;
                    $prices['cacheRead']  *= 2;
                    $prices['cacheWrite'] *= 2;
                }
            }

            // Image cost
            $images     = isset( $meta['images_generated'] ) ? $meta['images_generated'] : array();
            $image_cost = $this->calc_image_cost( $images );

            // Tool call costs (OpenAI built-in tools)
            $tool_pricing      = $this->get_tool_pricing();
            $web_search_calls  = isset( $meta['web_search_calls'] ) ? (int) $meta['web_search_calls'] : 0;
            $file_search_calls = isset( $meta['file_search_calls'] ) ? (int) $meta['file_search_calls'] : 0;

            // Web search cost depends on provider
            // Anthropic: no per-call fee, web search results are billed as regular input tokens
            if ( $provider === 'anthropic' ) {
                $web_search_cost = 0;
            } elseif ( $provider === 'gemini' && $web_search_calls > 0 ) {
                $is_gemini_3 = preg_match( '/gemini-3/', $model );
                $web_search_cost = $web_search_calls * ( $is_gemini_3 ? $tool_pricing['gemini_search_3x'] : $tool_pricing['gemini_search_2x'] );
            } elseif ( $provider === 'openai' && $web_search_calls > 0 ) {
                $is_reasoning = preg_match( '/^(gpt-5|o[1-9])/', $model );
                $web_search_cost = $web_search_calls * ( $is_reasoning ? $tool_pricing['web_search_reasoning'] : $tool_pricing['web_search_non_reasoning'] );
            } else {
                $web_search_cost = 0;
            }

            $file_search_cost  = $file_search_calls * $tool_pricing['file_search'];
            $tool_cost         = $web_search_cost + $file_search_cost;

            $cost_data = array(
                'inputTokens'      => $parsed['inputTokens'],
                'outputTokens'     => $parsed['outputTokens'],
                'thinkingTokens'   => $parsed['thinkingTokens'],
                'cacheReadTokens'  => $parsed['cacheReadTokens'],
                'cacheWriteTokens' => $parsed['cacheWriteTokens'],
                'audioInputTokens'  => $parsed['audioInputTokens'],
                'audioOutputTokens' => $parsed['audioOutputTokens'],
                'imagesCount'      => count( $images ),
                'imageCost'        => $image_cost,
                'model'            => $model,
                'provider'         => $provider,
                'userId'           => (int) $user_id,
            );

            if ( $web_search_calls > 0 ) {
                $cost_data['webSearchCalls'] = $web_search_calls;
                $cost_data['webSearchCost']  = round( $web_search_cost, 6 );
            }

            if ( $file_search_calls > 0 ) {
                $cost_data['fileSearchCalls'] = $file_search_calls;
                $cost_data['fileSearchCost']  = round( $file_search_cost, 6 );
            }

            if ( $service_tier === 'flex' || $service_tier === 'priority' ) {
                $cost_data['serviceTier'] = $service_tier;
            }

            if ( $prices ) {
                $input_tokens   = $parsed['inputTokens'];
                $cache_read     = $parsed['cacheReadTokens'];
                $cache_write    = $parsed['cacheWriteTokens'];
                $audio_input    = $parsed['audioInputTokens'];
                $audio_output   = $parsed['audioOutputTokens'];
                $regular_input  = max( 0, $input_tokens - $cache_read - $cache_write );

                if ( ( $audio_input > 0 || $audio_output > 0 ) && isset( $prices['audioInput'] ) ) {
                    // Audio models: text and audio tokens have different rates
                    $text_input  = max( 0, $regular_input - $audio_input );
                    $text_output = max( 0, $parsed['outputTokens'] - $audio_output );

                    $input_cost  = ( $text_input * $prices['input'] + $cache_read * $prices['cacheRead'] + $cache_write * $prices['cacheWrite'] + $audio_input * $prices['audioInput'] ) / 1000000;
                    $output_cost = ( $text_output * $prices['output'] + $audio_output * $prices['audioOutput'] ) / 1000000;
                } else {
                    $input_cost  = ( $regular_input * $prices['input'] + $cache_read * $prices['cacheRead'] + $cache_write * $prices['cacheWrite'] ) / 1000000;
                    $output_cost = ( $parsed['outputTokens'] * $prices['output'] ) / 1000000;
                }
                $total_cost  = $input_cost + $output_cost + $image_cost + $tool_cost;

                // What it would cost without cache discounts
                $no_cache_cost = ( $input_tokens * $prices['input'] ) / 1000000;
                $cache_savings = $no_cache_cost - $input_cost;

                $cost_data['inputCost']    = round( $input_cost, 6 );
                $cost_data['outputCost']   = round( $output_cost, 6 );
                $cost_data['toolCost']     = round( $tool_cost, 6 );
                $cost_data['totalCost']    = round( $total_cost, 6 );
                $cost_data['cacheSavings'] = round( max( 0, $cache_savings ), 6 );
                $cost_data['prices']       = $prices;
            } else if ( $tool_cost > 0 || $image_cost > 0 ) {
                // Even without token pricing, we can still calculate tool + image costs
                $cost_data['toolCost']  = round( $tool_cost, 6 );
                $cost_data['totalCost'] = round( $image_cost + $tool_cost, 6 );
            }

            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_cost', json_encode( $cost_data ) );

            // Write to dedicated ai_usage table (persists even if message is deleted)
            global $wpdb;
            $ai_usage_table = bm_get_table('ai_usage');

            if ( $ai_usage_table ) {
                $created_at = 0;

                // Resolve thread_id and created_at from message
                if ( $ai_message_id > 0 ) {
                    $messages_table = bm_get_table('messages');
                    $msg = $wpdb->get_row( $wpdb->prepare(
                        "SELECT thread_id, created_at FROM {$messages_table} WHERE id = %d",
                        $ai_message_id
                    ) );
                    if ( $msg ) {
                        if ( $thread_id <= 0 ) {
                            $thread_id = (int) $msg->thread_id;
                        }
                        $created_at = (int) $msg->created_at;
                    }
                }

                if ( $created_at <= 0 ) {
                    $created_at = (int) ( microtime( true ) * 1000 ) * 10;
                }

                $wpdb->insert( $ai_usage_table, array(
                    'bot_id'     => (int) $bot_id,
                    'message_id' => (int) $ai_message_id,
                    'thread_id'  => (int) $thread_id,
                    'user_id'    => (int) $user_id,
                    'is_summary' => $is_summary ? 1 : 0,
                    'cost_data'  => json_encode( $cost_data ),
                    'created_at' => $created_at,
                ) );
            }

            return $cost_data;
        }

        /**
         * Format a bot post for REST response
         */
        private function format_bot_for_rest( $post )
        {
            $settings = $this->get_bot_settings( $post->ID );
            $bot_user = $this->get_bot_user( $post->ID );
            $bot_user_id = $bot_user ? absint( $bot_user->id ) * -1 : 0;

            $avatar_id  = get_post_thumbnail_id( $post->ID );
            $avatar_url = $avatar_id ? wp_get_attachment_image_url( $avatar_id, 'thumbnail' ) : '';

            return array(
                'id'         => $post->ID,
                'name'       => $post->post_title,
                'botUserId'  => $bot_user_id,
                'avatarId'   => (int) $avatar_id,
                'avatarUrl'  => $avatar_url ?: '',
                'settings'   => $settings,
            );
        }

        public function user_is_admin(){
            return current_user_can('manage_options');
        }

        public function get_ai_request_secret(){
            $secret = get_transient('better_messages_ai_request_secret');
            if( empty( $secret ) ){
                $secret = wp_generate_password( 32, false );
                set_transient( 'better_messages_ai_request_secret', $secret, HOUR_IN_SECONDS );
            }
            return $secret;
        }

        public function register_post_type(){
            $args = array(
                'public'               => false,
                'labels'               => [
                    'name'          => _x( 'AI Chat Bots', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'singular_name' => _x( 'AI Chat Bot', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'add_new'       => _x( 'Create new AI Chat Bot', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'add_new_item'  => _x( 'Create new AI Chat Bot', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'edit_item'     => _x( 'Edit AI Chat Bot', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'new_item'      => _x( 'New AI Chat Bot', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'featured_image'        => _x( 'AI Chat Bot Avatar', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'set_featured_image'    => _x( 'Set AI Chat Bot avatar', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'remove_featured_image' => _x( 'Remove AI Chat Bot avatar', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                    'use_featured_image'    => _x( 'Use as AI Chat Bot avatar', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                ],
                'publicly_queryable'   => false,
                'show_ui'              => true,
                'show_in_menu'         => false,
                'menu_position'        => 1,
                'query_var'            => false,
                'capability_type'      => 'page',
                'has_archive'          => false,
                'hierarchical'         => false,
                'show_in_admin_bar'    => false,
                'show_in_nav_menus'    => false,
                'supports'             => array( 'title', 'thumbnail' ),
                'register_meta_box_cb' => array( $this, 'register_meta_box' )

            );

            register_post_type( 'bm-ai-chat-bot', $args );
        }

        public function register_meta_box()
        {
            add_meta_box(
                'bm-ai-chat-bot-settings',
                _x( 'Settings', 'Chat rooms settings page', 'bp-better-messages' ),
                array( $this, 'bot_settings' ),
                null,
                'advanced'
            );
        }

        public function bot_settings( $post )
        {
            $roles = get_editable_roles();
            if(isset($roles['administrator'])) unset( $roles['administrator'] );

            $roles['bm-guest'] = [
                'name' => _x('Guests', 'Settings page', 'bp-better-messages' )
            ];

            $settings = $this->get_bot_settings( $post->ID );

            wp_nonce_field( 'bm-save-ai-chat-bot-settings-' . $post->ID, 'bm_save_ai_chat_bot_nonce' );

            $bot = $this->get_bot_user( $post->ID );
            $voices = $this->get_voices();
            $bot_user_id = $bot ? absint($bot->id) * -1 : 0;

            $any_key_exists = ! empty(Better_Messages()->settings['openAiApiKey'])
                || ! empty(Better_Messages()->settings['anthropicApiKey'])
                || ! empty(Better_Messages()->settings['geminiApiKey']);

            $providers = version_compare(phpversion(), '8.1', '>=') ? Better_Messages_AI_Provider_Factory::get_providers_info() : [];

            $voice_messages_banner = '';
            if( ! class_exists('BP_Better_Messages_Voice_Messages') ){
                $voice_messages_banner = '<div class="bp-better-messages-banner bm-error">';
                $voice_messages_banner .= sprintf(_x('<a href="%s" target="_blank">Voice Messages</a> add-on is required to use audio models.', 'Settings page', 'bp-better-messages'), admin_url('admin.php?page=bp-better-messages-addons') );
                $voice_messages_banner .= '</div>';
            }

            if (version_compare(phpversion(), '8.1', '<')) { ?>
            <div class="bm-admin-error" style="font-size: 150%;margin: 10px 0">
                <?php echo sprintf(esc_html_x('Website must to have PHP version %s or higher, currently PHP version %s is used.', 'Settings page', 'bp-better-messages'), '<strong>8.1</strong>', '<strong>' . phpversion() . '</strong>' ); ?>
            </div>
            <?php } else if ( ! $any_key_exists ){ ?>
                <div class="bm-admin-error" style="font-size: 150%;margin: 10px 0">
                    <?php echo sprintf(_x('Website must have at least one valid API Key configured. Setup keys at <a href="%s">settings page</a>.', 'Settings page', 'bp-better-messages'), add_query_arg( 'page', 'bp-better-messages', admin_url('admin.php') ) . '#integrations_openai' ); ?>
                </div>
            <?php } else  { ?>
            <div class="bm-ai-chat-bot-settings"
                 data-bot-id="<?php echo esc_attr($post->ID); ?>"
                 data-bot-user-id="<?php echo $bot_user_id; ?>"
                 data-settings="<?php echo esc_attr(json_encode($settings)); ?>"
                 data-roles="<?php echo esc_attr(json_encode($roles)); ?>"
                 data-voices="<?php echo esc_attr(json_encode($voices)); ?>"
                 data-providers="<?php echo esc_attr(json_encode($providers)); ?>"
                 data-voice-messages-banner="<?php echo esc_attr($voice_messages_banner); ?>">
                <p style="text-align: center"><?php _ex( 'Loading',  'WP Admin', 'bp-better-messages' ); ?></p>
            </div>
            <?php
            }
        }

        public function get_default_settings()
        {
            $voices = $this->get_voices();

            $defaults = array(
                "enabled" => "0",
                "provider" => "openai",
                "apiKey" => "",
                "images"  => "0",
                "imagesGeneration" => "0",
                "imagesGenerationModel" => "gpt-image-1-mini",
                "imagesGenerationQuality" => "auto",
                "imagesGenerationSize" => "auto",
                "maxImagesPerResponse" => "",
                "maxWebSearchCalls" => "",
                "maxFileSearchCalls" => "",
                "files" => "0",
                "webSearch" => "0",
                "webSearchContextSize" => "medium",
                "fileSearch" => "0",
                "fileSearchVectorIds" => [],
                "serviceTier" => "auto",
                "temperature" => "",
                "maxOutputTokens" => "",
                "contextMessages" => "",
                "respondToMentions" => "0",
                "groupContextMessages" => "",
                "reasoningEffort" => "",
                "extendedThinking" => "0",
                "thinkingBudget" => "",
                "model"   => "",
                "instruction" => _x( 'You are a helpful assistant', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                "voice" => $voices[0],
                "inputTokenPrice" => "",
                "outputTokenPrice" => "",
                "cacheReadTokenPrice" => "",
                "cacheWriteTokenPrice" => "",
                "summarizationEnabled" => "0",
                "summarizationModel" => "",
                "summarizationPrompt" => "",
                "summarizationStyle" => "narrative",
                "summarizationLength" => "brief",
                "summarizationMaxTokens" => "",
                "summarizationLanguage" => "",
                "useSummaryContext" => "0",
                "digestEnabled" => "0",
                "digestModel" => "",
                "digestPrompt" => "",
                "digestMaxTokens" => "",
                "digestLanguage" => "",
                "digestContextDigests" => "3",
                "userPricingMode" => "disabled",
                "userPricingFixedAmount" => "",
                "userPricingCostRate" => "",
                "userPricingMinimumBalance" => "",
                "userPricingInsufficientMessage" => "",
                "userPricingExemptRoles" => array( "administrator" ),
                "userPricingChargeGroupMentions" => "0",
                "userPricingLogEntry" => "Better Messages for AI bot response #{id}",
            );

            return $defaults;
        }

        public function get_voices()
        {
            return [
                'alloy',
                'ash',
                'ballad',
                'coral',
                'echo',
                'sage',
                'shimmer',
                'verse'
            ];
        }

        public function get_bot_settings( $bot_id )
        {
            $defaults = $this->get_default_settings();

            $args = get_post_meta( $bot_id, 'bm-ai-chat-bot-settings', true );

            if( empty( $args ) || ! is_array( $args ) ){
                $args = array();
            }

            if( ! isset( $args['images'] ) && ! empty($args['model'] )  ){
                $args['images'] = str_contains($args['model'], 'gpt-4-turbo') || str_contains($args['model'], 'gpt-4o') ? '1' : '0';
            }

            if( isset( $args['voice'] ) ){
                $voices = $this->get_voices();

                if( ! in_array( $args['voice'], $voices ) ){
                    $args['voice'] = $defaults['voice'];
                }
            }

            $result = wp_parse_args( $args, $defaults );

            return $result;
        }

        public function save_post( $post_id, $post ){
            if( ! isset($_POST['bm_save_ai_chat_bot_nonce']) ){
                return $post->ID;
            }

            //Verify it came from proper authorization.
            if ( ! wp_verify_nonce($_POST['bm_save_ai_chat_bot_nonce'], 'bm-save-ai-chat-bot-settings-' . $post->ID ) ) {
                return $post->ID;
            }

            //Check if the current user can edit the post
            if ( ! current_user_can( 'manage_options' ) ) {
                return $post->ID;
            }

            if( isset( $_POST['bm'] ) && is_array($_POST['bm']) ){
                $old_settings = $this->get_bot_settings( $post->ID );

                $settings = (array) $_POST['bm'];

                // Sanitize all string fields
                foreach( $settings as $key => $value ){
                    if( is_string($value) ){
                        if( $key === 'instruction' || $key === 'summarizationPrompt' ){
                            $settings[$key] = sanitize_textarea_field( $value );
                        } else {
                            $settings[$key] = sanitize_text_field( $value );
                        }
                    }
                }

                if( ! $settings['model'] ){
                    $settings['model'] = $old_settings['model'];
                }

                if( ! empty( $settings['fileSearchVectorIds'] ) ){
                    $lines = explode( "\n", $settings['fileSearchVectorIds']);

                    $vector_ids = [];

                    $added_lines = 0;
                    foreach( $lines as $line ){
                        $line = trim( $line );
                        if( ! empty( $line ) ){
                            $vector_ids[] = $line;
                            $added_lines++;
                        }

                        if( $added_lines == 2 ){
                            break;
                        }
                    }

                    $settings['fileSearchVectorIds'] = array_unique( $vector_ids );
                } else {
                    $settings['fileSearchVectorIds'] = [];
                }

                $defaults = $this->get_default_settings();

                $settings = wp_parse_args( $settings, $defaults );

                update_post_meta( $post->ID, 'bm-ai-chat-bot-settings', $settings );

                $this->create_or_update_bot_user( $post->ID, $post->post_title );
            }
        }

        public function get_bot_user( $bot_id )
        {
            $bot_user = wp_cache_get( 'bot_user_' . $bot_id, 'bm_messages' );

            if( $bot_user ){
                return $bot_user;
            }

            global $wpdb;

            $query = $wpdb->prepare( "SELECT * FROM `" . bm_get_table('guests') . "` WHERE `ip` = %s AND `deleted_at` IS NULL", "ai-chat-bot-" . $bot_id );

            $guest_user = $wpdb->get_row( $query );

            if( $guest_user ){
                wp_cache_set( 'bot_user_' . $bot_id, $guest_user, 'bm_messages' );

                return $guest_user;
            } else {
                return false;
            }
        }

        public function create_or_update_bot_user( $bot_id, $name )
        {
            $bot = $this->get_bot_user( $bot_id );

            if( $bot ){
                if( $bot->name != $name ){
                    global $wpdb;

                    $wpdb->update( bm_get_table('guests'), ['name' => $name], ['id' => $bot->id] );
                    do_action( 'better_messages_guest_updated', absint($bot->id) * -1 );
                    do_action( 'better_messages_user_updated', absint($bot->id) * -1 );
                }
            } else {
                global $wpdb;

                $result = $wpdb->insert( bm_get_table('guests'), [
                    'ip'     => "ai-chat-bot-" . $bot_id,
                    'name'   => $name,
                    'secret' => ''
                ] );

                if( $result ) {
                    $bot_id = $wpdb->insert_id;
                    do_action('better_messages_guest_updated', absint($bot_id) * -1);
                    do_action('better_messages_user_updated', absint($bot_id) * -1);
                }
            }
        }

        public function rest_thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $current_user_id ){
            if( $thread_type !== 'thread'){
                return $thread_item;
            }

            $recipients = $thread_item['participants'];
            if( count( $recipients ) === 2 ){
                foreach( $recipients as $recipient_id ){
                    if( $recipient_id < 0 ) {
                        $guest_id = absint($recipient_id);
                        $guest = Better_Messages()->guests->get_guest_user($guest_id);

                        if ( $guest && $guest->ip && str_starts_with($guest->ip, 'ai-chat-bot-') ) {
                            $bot_id = str_replace('ai-chat-bot-', '', $guest->ip);
                            if ( $this->is_bot_conversation($bot_id, $thread_id) ) {
                                $settings = $this->get_bot_settings($bot_id);

                                $thread_item['botId'] = (int) $bot_id;
                                $thread_item['permissions']['canAudioCall'] = false;
                                $thread_item['permissions']['canVideoCall'] = false;
                                $thread_item['permissions']['canEditOwnMessages'] = false;
                                $thread_item['permissions']['canDeleteOwnMessages'] = false;
                                $thread_item['permissions']['canDeleteAllMessages'] = false;
                                $thread_item['permissions']['canInvite'] = false;
                                $thread_item['permissions']['preventReplies'] = true;

                                $thread_item['permissions']['preventVoiceMessages'] = ( ! str_contains($settings['model'], '-audio-') || ! class_exists('BP_Better_Messages_Voice_Messages') );

                                if (isset($thread_item['permissions']['canUpload'])) {
                                    $support_images = $settings['images'];
                                    $support_files = true;

                                    $thread_item['permissions']['canUpload'] = (bool) $support_images;

                                    $formats = [];

                                    if ( $support_images ) {
                                        $formats[] = '.png';
                                        $formats[] = '.jpg';
                                        $formats[] = '.jpeg';
                                        $formats[] = '.gif';
                                        $formats[] = '.webp';
                                    }

                                    if( $support_files ) {
                                        $formats[] = '.pdf';
                                    }

                                    if( count($formats) > 0 ){
                                        $thread_item['permissions']['canUploadExtensions'] = $formats;
                                        $thread_item['permissions']['canUploadMaxSize'] = 20;
                                        $thread_item['permissions']['totalMaxUploadSize'] = 50;
                                    }
                                }

                                // Add messageCharge for AI bot user pricing
                                if ( $include_personal ) {
                                    $pricing_mode = $settings['userPricingMode'] ?? 'disabled';
                                    $is_exempt = $this->is_user_exempt_from_pricing( $current_user_id, $settings );

                                    if ( $pricing_mode !== 'disabled' && ! $is_exempt && class_exists( 'Better_Messages_Points' ) && Better_Messages_Points()->get_provider() ) {
                                        if ( $pricing_mode === 'fixed' ) {
                                            $charge = intval( $settings['userPricingFixedAmount'] ?? 0 );
                                            if ( $charge > 0 ) {
                                                $thread_item['messageCharge'] = $charge;
                                            }
                                        } else if ( $pricing_mode === 'cost_based' ) {
                                            $thread_item['messageCharge'] = -1;
                                        }
                                    } else {
                                        $thread_item['messageCharge'] = 0;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $thread_item;
        }

        public function before_delete_message( $message_id, $thread_id, $deleteMethod )
        {
            try {
                $message = Better_Messages()->functions->get_message($message_id);

                if ( $message ) {
                    if (str_starts_with($message->message, '<!-- BM-AI -->')) {
                        $provider_id = Better_Messages()->functions->get_message_meta( $message_id, 'ai_provider' );

                        if ( empty( $provider_id ) ) {
                            $provider_id = 'openai';
                        }

                        $provider = $this->get_bot_provider_by_message( $message_id, $provider_id );
                        $provider->on_message_deleted( $message_id, $thread_id, $message );
                    }
                }
            } catch (Throwable $e) {
            }
        }

        public function on_message_sent( $message )
        {
            $sender_id = (int) $message->sender_id;

            // Bots should not trigger other bots
            if ( $sender_id < 0 && $this->get_bot_id_from_user( $sender_id ) ) {
                return;
            }

            // Skip E2E encrypted threads — bots cannot read encrypted content
            if ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_e2e_thread( (int) $message->thread_id ) ) {
                return;
            }

            $new_thread = $message->new_thread;
            $recipients = Better_Messages()->functions->get_recipients( (int) $message->thread_id );

            // 1:1 bot conversation trigger
            if( count( $recipients ) === 2 ){
                foreach ($recipients as $recipient){
                    $user_id = (int) $recipient->user_id;

                    if( $sender_id !== $user_id && $user_id < 0 ){
                        $bot_id = $this->get_bot_id_from_user( $user_id );

                        if( $bot_id && $this->bot_exists( $bot_id ) && ( $this->is_bot_conversation( $bot_id, $message->thread_id ) || $new_thread ) ){
                            $this->trigger_bot_response( $bot_id, $message, $sender_id );
                        }
                    }
                }
                return;
            }

            // Group/chat-room: trigger bots mentioned in the message
            $this->check_mention_trigger( $message, $sender_id );
        }

        /**
         * Extract bot ID from a bot guest user_id (negative).
         * Returns bot post ID or false.
         */
        public function get_bot_id_from_user( $user_id )
        {
            $guest_id = absint( $user_id );
            $guest = Better_Messages()->guests->get_guest_user( $guest_id );

            if ( $guest && $guest->ip && str_starts_with( $guest->ip, 'ai-chat-bot-' ) ) {
                return (int) str_replace( 'ai-chat-bot-', '', $guest->ip );
            }

            return false;
        }

        /**
         * Check if a message mentions any AI bots and trigger responses.
         */
        private function check_mention_trigger( $message, $sender_id )
        {
            $content = $message->message;

            // Parse mentioned user IDs from entity-encoded message HTML
            $mention_match = '&lt;span class=&quot;bm-mention&quot; data-user-id=&quot;';
            preg_match_all( '/' . preg_quote( $mention_match, '/' ) . '(-?\d+)&quot;/', $content, $matches );

            if ( empty( $matches[1] ) ) {
                return;
            }

            $mentioned_ids = array_unique( array_map( 'intval', $matches[1] ) );

            foreach ( $mentioned_ids as $mentioned_user_id ) {
                if ( $mentioned_user_id >= 0 ) {
                    continue; // Not a guest/bot user
                }

                $bot_id = $this->get_bot_id_from_user( $mentioned_user_id );

                if ( ! $bot_id || ! $this->bot_exists( $bot_id ) ) {
                    continue;
                }

                $bot_settings = $this->get_bot_settings( $bot_id );

                if ( $bot_settings['enabled'] !== '1' || $bot_settings['respondToMentions'] !== '1' ) {
                    continue;
                }

                if ( ! $this->check_group_mention_balance( $sender_id, $bot_settings ) ) {
                    continue;
                }

                $this->trigger_bot_response( $bot_id, $message, $sender_id );
            }
        }

        /**
         * Handle reply to a message — trigger bot if the replied-to message was from a bot.
         */
        public function on_message_reply( $message, $replied_to_message )
        {
            $sender_id = (int) $message->sender_id;
            $replied_sender_id = (int) $replied_to_message->sender_id;

            // Skip E2E encrypted threads
            if ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_e2e_thread( (int) $message->thread_id ) ) {
                return;
            }

            // Only care about replies to bot messages (negative user IDs)
            if ( $replied_sender_id >= 0 ) {
                return;
            }

            // Don't trigger if the sender is also a bot
            if ( $sender_id < 0 && $this->get_bot_id_from_user( $sender_id ) ) {
                return;
            }

            // Must be a group conversation (1:1 is handled by on_message_sent)
            $recipients = Better_Messages()->functions->get_recipients( (int) $message->thread_id );
            if ( count( $recipients ) <= 2 ) {
                return;
            }

            $bot_id = $this->get_bot_id_from_user( $replied_sender_id );

            if ( ! $bot_id || ! $this->bot_exists( $bot_id ) ) {
                return;
            }

            $bot_settings = $this->get_bot_settings( $bot_id );

            if ( $bot_settings['enabled'] !== '1' || $bot_settings['respondToMentions'] !== '1' ) {
                return;
            }

            if ( ! $this->check_group_mention_balance( $sender_id, $bot_settings ) ) {
                return;
            }

            // Don't double-trigger if this message also @mentions the same bot
            $content = $message->message;
            $mention_match = '&lt;span class=&quot;bm-mention&quot; data-user-id=&quot;';
            preg_match_all( '/' . preg_quote( $mention_match, '/' ) . '(-?\d+)&quot;/', $content, $matches );

            if ( ! empty( $matches[1] ) ) {
                $mentioned_ids = array_unique( array_map( 'intval', $matches[1] ) );
                $bot_user_id = $replied_sender_id;
                if ( in_array( $bot_user_id, $mentioned_ids ) ) {
                    return; // Already handled by check_mention_trigger
                }
            }

            $this->trigger_bot_response( $bot_id, $message, $sender_id );
        }

        /**
         * Trigger an AI bot response to a message.
         */
        private function trigger_bot_response( $bot_id, $message, $sender_id )
        {
            global $wpdb;

            $bot_settings = $this->get_bot_settings( $bot_id );

            Better_Messages()->functions->update_message_meta( $message->id, 'ai_bot_id', $bot_id );
            Better_Messages()->functions->update_message_meta( $message->id, 'ai_waiting_for_response', time() );
            Better_Messages()->functions->update_thread_meta( $message->thread_id, 'ai_waiting_for_response', time() );

            do_action( 'better_messages_thread_self_update', $message->thread_id, $sender_id );
            do_action( 'better_messages_thread_updated', $message->thread_id, $sender_id );

            $table = bm_get_table('messages');
            $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `message` = CONCAT(%s, `message`) WHERE `id` = %d;", '<!-- BM-AI -->', $message->id ) );
            $message->message = '<!-- BM-AI -->' . $message->message;

            $provider_id = ! empty( $bot_settings['provider'] ) ? $bot_settings['provider'] : 'openai';
            $has_key = ! empty( $bot_settings['apiKey'] ) || ! empty( Better_Messages_AI_Provider_Factory::get_global_api_key( $provider_id ) );

            if ( $has_key && ! empty( $bot_settings['model'] ) ) {
                if ( ! wp_get_scheduled_event( 'better_messages_ai_bot_ensure_completion', [ $bot_id, $message->id ] ) ) {
                    wp_schedule_single_event( time() + 15, 'better_messages_ai_bot_ensure_completion', [ $bot_id, $message->id ] );
                }

                $url = add_query_arg([
                    'bot_id'     => $bot_id,
                    'message_id' => $message->id,
                    'secret'     => $this->get_ai_request_secret()
                ], Better_Messages()->functions->get_rest_api_url() . 'ai/createResponse');

                wp_remote_get( $url, [
                    'blocking' => false,
                    'timeout'  => 0
                ] );
            }
        }

        public function ai_bot_ensure_completion( $bot_id, $message_id )
        {
            $provider = $this->get_bot_provider( $bot_id );
            $provider->process_reply( $bot_id, $message_id );
        }

        /**
         * Check whether AI moderation should run in background for this message.
         * Only "flag" mode uses background (message is sent regardless, so no blocking needed).
         */
        private function is_background_moderation()
        {
            return Better_Messages()->settings['aiModerationAction'] === 'flag';
        }

        /**
         * Check if a sender bypasses AI moderation (admin, role bypass, whitelist).
         */
        private function sender_bypasses_moderation( $sender_id, $thread_id )
        {
            $settings = Better_Messages()->settings;

            // Administrators always bypass
            if( $sender_id > 0 && user_can( $sender_id, 'bm_can_administrate' ) ) {
                return true;
            }

            // Check role-based bypass
            $bypass_roles = (array) $settings['aiModerationBypassRoles'];
            if( ! empty( $bypass_roles ) && $sender_id > 0 ) {
                $user_roles = Better_Messages()->functions->get_user_roles( $sender_id );
                if( ! empty( $user_roles ) ) {
                    foreach( $user_roles as $role ) {
                        if( in_array( $role, $bypass_roles ) ) {
                            return true;
                        }
                    }
                }
            }

            // Check if user is whitelisted
            if( $sender_id !== 0 && $thread_id > 0 ) {
                if( Better_Messages()->moderation->is_user_whitelisted( $sender_id, $thread_id ) ) {
                    return true;
                }
            } else if( $sender_id !== 0 ) {
                if( Better_Messages()->moderation->is_user_whitelisted( $sender_id ) ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Get base64 data URIs from attachment IDs, filtering to only image mime types.
         * Uses base64 encoding so that the site doesn't need to be publicly accessible.
         */
        private function get_image_data_uris_from_attachments( $attachment_ids )
        {
            $image_data_uris = [];

            if( empty( $attachment_ids ) || ! is_array( $attachment_ids ) ) {
                return $image_data_uris;
            }

            foreach( $attachment_ids as $attachment_id ) {
                $data_uri = $this->get_attachment_base64_data_uri( (int) $attachment_id );
                if( $data_uri ) {
                    $image_data_uris[] = $data_uri;
                }
            }

            return $image_data_uris;
        }

        /**
         * Convert a WordPress image attachment to a base64 data URI.
         *
         * @param int $attachment_id
         * @return string|false Data URI string or false on failure
         */
        private function get_attachment_base64_data_uri( $attachment_id )
        {
            $mime_type = get_post_mime_type( $attachment_id );
            if( ! $mime_type || strpos( $mime_type, 'image/' ) !== 0 ) {
                return false;
            }

            $file_path = get_attached_file( $attachment_id );
            if( ! $file_path || ! file_exists( $file_path ) ) {
                return false;
            }

            $contents = file_get_contents( $file_path );
            if( $contents === false ) {
                return false;
            }

            return 'data:' . $mime_type . ';base64,' . base64_encode( $contents );
        }

        /**
         * Pre-send hook for AI moderation.
         * In background mode: skips API call, defers to background processing.
         * In synchronous mode: calls OpenAI API inline (hold mode only).
         */
        public function moderate_message_content( &$args, &$errors )
        {
            // Skip moderation if an earlier hook already blocked the message
            if( ! empty( $errors ) ) {
                return;
            }

            $sender_id = isset( $args['sender_id'] ) ? (int) $args['sender_id'] : (int) Better_Messages()->functions->get_current_user_id();
            $thread_id = isset( $args['thread_id'] ) ? (int) $args['thread_id'] : 0;

            if( $this->sender_bypasses_moderation( $sender_id, $thread_id ) ) {
                return;
            }

            // Skip E2E encrypted messages — content is ciphertext, cannot be moderated
            $content = isset( $args['content'] ) ? $args['content'] : '';
            if ( class_exists( 'Better_Messages_E2E_Encryption' ) && strpos( $content, Better_Messages_E2E_Encryption::E2E_PREFIX ) === 0 ) {
                return;
            }

            // Get message content
            $content = strip_tags( $content );
            $has_text = ! empty( trim( $content ) );

            // Get image data URIs if image moderation is enabled
            $settings = Better_Messages()->settings;
            $image_data_uris = [];
            if( $settings['aiModerationImages'] === '1' && ! empty( $args['attachments'] ) ) {
                $image_data_uris = $this->get_image_data_uris_from_attachments( $args['attachments'] );
            }

            $has_images = ! empty( $image_data_uris );

            // Nothing to moderate
            if( ! $has_text && ! $has_images ) {
                return;
            }

            // Background mode: defer API call, mark for later processing
            if( $this->is_background_moderation() ) {
                $args['ai_moderation_deferred'] = true;
                return;
            }

            // Synchronous mode (hold only): call OpenAI API inline
            $result = $this->api->moderate( $content, $image_data_uris );

            // Fail open on API error
            if( is_wp_error( $result ) ) {
                return;
            }

            // Not flagged — nothing to do
            if( empty( $result['flagged'] ) ) {
                return;
            }

            $flagged_categories = $this->get_flagged_categories( $result );

            if( empty( $flagged_categories ) ) {
                return;
            }

            // Store full result for meta saving later
            $args['ai_moderation_result'] = [
                'flagged'                      => true,
                'categories'                   => $result['categories'],
                'category_scores'              => $result['category_scores'],
                'category_applied_input_types'  => isset( $result['category_applied_input_types'] ) ? $result['category_applied_input_types'] : [],
                'flagged_categories'           => array_keys( $flagged_categories )
            ];

            $args['is_pending'] = 1;
        }

        /**
         * Filter flagged categories by enabled categories and threshold.
         */
        private function get_flagged_categories( $result )
        {
            $settings = Better_Messages()->settings;
            $enabled_categories = (array) $settings['aiModerationCategories'];
            $threshold = (float) $settings['aiModerationThreshold'];
            $flagged_categories = [];

            if( isset( $result['categories'] ) && is_array( $result['categories'] ) ) {
                foreach( $result['categories'] as $category => $is_flagged ) {
                    if( ! $is_flagged ) continue;

                    // Check if this category or its base category is enabled
                    $base_category = explode( '/', $category )[0];
                    if( ! in_array( $base_category, $enabled_categories ) && ! in_array( $category, $enabled_categories ) ) {
                        continue;
                    }

                    $score = isset( $result['category_scores'][ $category ] ) ? (float) $result['category_scores'][ $category ] : 0;
                    if( $score >= $threshold ) {
                        $flagged_categories[ $category ] = $score;
                    }
                }
            }

            return $flagged_categories;
        }

        /**
         * Called after message is saved. Schedules background AI moderation if deferred,
         * or saves moderation meta inline if result is already available (synchronous mode).
         */
        public function schedule_background_moderation( &$message )
        {
            // Synchronous mode: result already available, save meta now
            if( ! empty( $message->ai_moderation_result ) ) {
                $result = $message->ai_moderation_result;

                Better_Messages()->functions->update_message_meta( $message->id, 'ai_moderation_flagged', '1' );
                Better_Messages()->functions->update_message_meta( $message->id, 'ai_moderation_categories', json_encode( $result['flagged_categories'] ) );
                Better_Messages()->functions->update_message_meta( $message->id, 'ai_moderation_result', json_encode( $result ) );

                // Send email notification only for "flag" action (message sent normally).
                // For "hold" action, notify_pending_message in moderation.php handles the email.
                if( empty( $message->is_pending ) ){
                    $this->notify_ai_moderation( $message );
                }
                return;
            }

            // Background mode: schedule the check
            if( empty( $message->ai_moderation_deferred ) ) {
                return;
            }

            $message_id = $message->id;

            // Cron fallback in case the self-request fails
            if( ! wp_get_scheduled_event( 'better_messages_ai_moderate_message', [ $message_id ] ) ){
                wp_schedule_single_event( time() + 15, 'better_messages_ai_moderate_message', [ $message_id ] );
            }

            // Non-blocking self-request for immediate processing
            $url = add_query_arg([
                'message_id' => $message_id,
                'secret'     => $this->get_ai_request_secret()
            ], Better_Messages()->functions->get_rest_api_url() . 'ai/moderateMessage');

            wp_remote_get( $url, [
                'blocking' => false,
                'timeout'  => 0
            ] );
        }

        /**
         * REST endpoint callback for background moderation.
         */
        public function rest_moderate_message( WP_REST_Request $request )
        {
            $message_id = (int) $request->get_param('message_id');

            if( ! empty( $message_id ) ){
                $this->run_background_moderation( $message_id );
            }
        }

        /**
         * Run the actual AI moderation check in the background.
         * Called via self-request or WP-Cron fallback.
         */
        public function run_background_moderation( $message_id )
        {
            $message = Better_Messages()->functions->get_message( $message_id );

            if( ! $message ) {
                return;
            }

            // Already processed (e.g. cron fired after self-request already handled it)
            $already_checked = Better_Messages()->functions->get_message_meta( $message_id, 'ai_moderation_checked' );
            if( ! empty( $already_checked ) ) {
                return;
            }

            // Mark as checked to prevent duplicate processing
            Better_Messages()->functions->update_message_meta( $message_id, 'ai_moderation_checked', '1' );

            // Skip E2E encrypted messages — content is ciphertext, cannot be moderated
            if ( is_string( $message->message ) && class_exists( 'Better_Messages_E2E_Encryption' ) && strpos( $message->message, Better_Messages_E2E_Encryption::E2E_PREFIX ) === 0 ) {
                return;
            }

            $content = strip_tags( $message->message );
            $has_text = ! empty( trim( $content ) );

            // Get image data URIs if image moderation is enabled
            $settings = Better_Messages()->settings;
            $image_data_uris = [];
            if( $settings['aiModerationImages'] === '1' ) {
                $attachments = Better_Messages()->functions->get_message_meta( $message_id, 'attachments', true );
                if( is_array( $attachments ) && ! empty( $attachments ) ) {
                    $attachment_ids = array_keys( $attachments );
                    $image_data_uris = $this->get_image_data_uris_from_attachments( $attachment_ids );
                }
            }

            $has_images = ! empty( $image_data_uris );

            // Nothing to moderate
            if( ! $has_text && ! $has_images ) {
                return;
            }

            // Call OpenAI Moderation API
            $result = $this->api->moderate( $has_text ? $content : '', $image_data_uris );

            // Fail open on API error — message stays as-is
            if( is_wp_error( $result ) ) {
                return;
            }

            // Not flagged — clean up and return
            if( empty( $result['flagged'] ) ) {
                Better_Messages()->functions->delete_message_meta( $message_id, 'ai_moderation_checked' );
                return;
            }

            $flagged_categories = $this->get_flagged_categories( $result );

            // No categories above threshold — clean up and return
            if( empty( $flagged_categories ) ) {
                Better_Messages()->functions->delete_message_meta( $message_id, 'ai_moderation_checked' );
                return;
            }

            // Message is flagged — save moderation meta
            $moderation_result = [
                'flagged'                      => true,
                'categories'                   => $result['categories'],
                'category_scores'              => $result['category_scores'],
                'category_applied_input_types'  => isset( $result['category_applied_input_types'] ) ? $result['category_applied_input_types'] : [],
                'flagged_categories'           => array_keys( $flagged_categories )
            ];

            Better_Messages()->functions->update_message_meta( $message_id, 'ai_moderation_flagged', '1' );
            Better_Messages()->functions->update_message_meta( $message_id, 'ai_moderation_categories', json_encode( $moderation_result['flagged_categories'] ) );
            Better_Messages()->functions->update_message_meta( $message_id, 'ai_moderation_result', json_encode( $moderation_result ) );

            // Flag mode: message was already sent, notify admin
            $message->ai_moderation_result = $moderation_result;
            $this->notify_ai_moderation( $message );
        }

        /**
         * Send email notification for AI-flagged messages.
         */
        private function notify_ai_moderation( $message )
        {
            $emails = Better_Messages()->settings['messagesModerationNotificationEmails'];

            if( empty( trim( $emails ) ) ) {
                return;
            }

            $email_list = array_filter( array_map( 'trim', preg_split( '/[\n,]+/', $emails ) ) );

            if( empty( $email_list ) ) {
                return;
            }

            $result = $message->ai_moderation_result;
            $sender_id = $message->sender_id;
            $sender_item = Better_Messages()->functions->rest_user_item( $sender_id, false );
            $sender_name = $sender_item['name'];
            $thread_id = $message->thread_id;

            // Build categories string with input types (e.g. "sexual (image), violence (text)")
            $input_types = isset( $result['category_applied_input_types'] ) ? $result['category_applied_input_types'] : [];
            $category_parts = [];
            if( isset( $result['flagged_categories'] ) ) {
                foreach( $result['flagged_categories'] as $cat ) {
                    $types = isset( $input_types[ $cat ] ) ? $input_types[ $cat ] : [];
                    if( ! empty( $types ) ) {
                        $category_parts[] = $cat . ' (' . implode( ', ', $types ) . ')';
                    } else {
                        $category_parts[] = $cat;
                    }
                }
            }
            $categories = implode( ', ', $category_parts );

            $subject = sprintf(
                _x( '[%s] AI Flagged Message', 'AI Moderation', 'bp-better-messages' ),
                get_bloginfo( 'name' )
            );

            $admin_url = admin_url( 'admin.php?page=better-messages-viewer' );
            if ( is_string( $message->message ) && class_exists( 'Better_Messages_E2E_Encryption' ) && strpos( $message->message, Better_Messages_E2E_Encryption::E2E_PREFIX ) === 0 ) {
                $message_preview = _x( 'Encrypted message', 'AI Moderation', 'bp-better-messages' );
            } else {
                $message_preview = wp_trim_words( strip_tags( $message->message ), 50 );
            }

            $body  = sprintf( _x( 'Sender: %s (ID: %d)', 'AI Moderation', 'bp-better-messages' ), $sender_name, $sender_id ) . "\n";
            $body .= sprintf( _x( 'Conversation: #%d', 'AI Moderation', 'bp-better-messages' ), $thread_id ) . "\n";
            $body .= sprintf( _x( 'Flagged Categories: %s', 'AI Moderation', 'bp-better-messages' ), $categories ) . "\n";
            $body .= "\n" . sprintf( _x( 'Message: %s', 'AI Moderation', 'bp-better-messages' ), $message_preview ) . "\n\n";
            $body .= _x( 'Note: This message was sent to the recipient but flagged for review.', 'AI Moderation', 'bp-better-messages' ) . "\n\n";
            $body .= sprintf( _x( 'Review in moderation panel: %s', 'AI Moderation', 'bp-better-messages' ), $admin_url );

            foreach( $email_list as $email ) {
                if( is_email( $email ) ) {
                    wp_mail( $email, $subject, $body );
                }
            }
        }

        /**
         * REST callback: transcribe a voice message via OpenAI Whisper.
         */
        public function transcribe_voice_message( WP_REST_Request $request )
        {
            $message_id    = intval( $request->get_param( 'message_id' ) );
            $attachment_id = Better_Messages()->functions->get_message_meta( $message_id, 'bpbm_voice_messages', true );

            if ( ! $attachment_id ) {
                return new WP_Error(
                    'not_voice_message',
                    _x( 'This message is not a voice message', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            $message = Better_Messages()->functions->get_message( $message_id );

            // Check cached transcription first (metadata_exists distinguishes "never transcribed" from "empty result")
            if ( metadata_exists( 'post', $attachment_id, 'bm_voice_transcription' ) ) {
                return Better_Messages_Rest_Api()->get_messages( (int) $message->thread_id, [ $message_id ] );
            }

            // Concurrent request lock — only one Whisper API call per voice message
            $lock_key = 'bm_transcribing_' . $attachment_id;
            if ( get_transient( $lock_key ) ) {
                return new WP_Error(
                    'already_processing',
                    _x( 'Transcription is already in progress', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 409 )
                );
            }

            set_transient( $lock_key, true, 2 * MINUTE_IN_SECONDS );

            $result = $this->api->transcribe_audio( $attachment_id );

            if ( is_wp_error( $result ) ) {
                delete_transient( $lock_key );
                return $result;
            }

            update_post_meta( $attachment_id, 'bm_voice_transcription', $result );
            delete_transient( $lock_key );

            // Broadcast updated meta to all thread participants (WebSocket + AJAX paths)
            if ( $message ) {
                Better_Messages()->functions->update_message_update_time( $message_id );
                do_action( 'better_messages_message_meta_updated', (int) $message->thread_id, $message_id, 'bm_voice_transcription', $result );
            }

            return Better_Messages_Rest_Api()->get_messages( (int) $message->thread_id, [ $message_id ] );
        }

        /**
         * Add canTranscribe and cached transcription to voice message meta.
         */
        public function voice_transcription_meta( $meta, $message_id, $thread_id, $content )
        {
            $is_voice_message = strpos( $content, '<!-- BPBM-VOICE-MESSAGE -->', 0 ) === 0;

            if ( ! $is_voice_message ) {
                return $meta;
            }

            $meta['canTranscribe'] = true;

            $attachment_id = Better_Messages()->functions->get_message_meta( $message_id, 'bpbm_voice_messages', true );
            if ( $attachment_id && metadata_exists( 'post', $attachment_id, 'bm_voice_transcription' ) ) {
                $meta['transcription'] = get_post_meta( $attachment_id, 'bm_voice_transcription', true );
            }

            return $meta;
        }
    }

    function Better_Messages_AI(){
        return Better_Messages_AI::instance();
    }
}
