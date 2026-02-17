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
                require_once "api/open-ai.php";

                $this->api = Better_Messages_OpenAI_API::instance();

                add_action( 'admin_init', array( $this, 'register_event' ) );
                add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
                add_action( 'save_post', array( $this, 'save_post' ), 1, 2 );

                add_action( 'bp_better_chat_settings_updated', array($this, 'check_if_api_key_valid'));

                add_action( 'better_messages_message_sent', array( $this, 'on_message_sent'), 10, 1 );
                add_action( 'better_messages_before_message_delete', array( $this, 'before_delete_message' ), 10 , 3 );

                add_action( 'bp_better_messages_new_thread_created', array( $this, 'on_new_thread_created'), 10, 2 );
                add_filter( 'better_messages_can_send_message', array( $this, 'block_reply_if_needed' ), 20, 3 );
                add_action( 'better_messages_before_new_thread',  array( $this, 'restrict_new_thread_if_needed'), 10, 2 );

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

                            if( $bot_settings['enabled'] !== '1' || empty( $bot_settings['model'] ) ){
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
                    $user_id = $user->user_id;
                    if ($user_id < 0) {
                        $guest_id = absint($user_id);
                        $guest = Better_Messages()->guests->get_guest_user($guest_id);

                        if ($guest && $guest->ip && str_starts_with($guest->ip, 'ai-chat-bot-')) {
                            $bot_id = str_replace('ai-chat-bot-', '', $guest->ip);

                            if( $this->bot_exists( $bot_id ) && $this->is_bot_conversation( $bot_id, $thread_id ) ){
                                $bot_settings = $this->get_bot_settings($bot_id);

                                if ( $bot_settings['enabled'] !== '1'  || empty( $bot_settings['model'] ) ) {
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

        public function check_if_api_key_valid()
        {
            $api_key_exists = ! empty(Better_Messages()->settings['openAiApiKey']);

            if( $api_key_exists ){
                $this->api->update_api_key();
                $this->api->check_api_key();
            } else {
                delete_option( 'better_messages_openai_error' );
            }
        }

        public function rest_api_init()
        {
            register_rest_route('better-messages/v1/ai', '/createResponse', array(
                'methods' => 'GET',
                'callback' => array( $this->api, 'reply_to_message'),
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
                'callback' => array( $this->api, 'cancel_response'),
                'permission_callback' => array( Better_Messages()->api, 'check_thread_access' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ));

            register_rest_route('better-messages/v1/admin/ai', '/getModels', array(
                'methods' => 'GET',
                'callback' => array( $this->api, 'get_models'),
                'permission_callback' => array($this, 'user_is_admin'),
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
                'show_in_menu'         => 'bp-better-messages',
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

            $openai_error = get_option( 'better_messages_openai_error', false );
            $api_key_exists = ! empty(Better_Messages()->settings['openAiApiKey']) && empty($openai_error);

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
            <?php } else if ( ! $api_key_exists ){ ?>
                <div class="bm-admin-error" style="font-size: 150%;margin: 10px 0">
                    <?php echo sprintf(_x('Website must have valid Open AI Api Key, setup key at <a href="%s">settings page</a>.', 'Settings page', 'bp-better-messages'), add_query_arg( 'page', 'bp-better-messages', admin_url('admin.php') ) . '#integrations_openai' ); ?>
                </div>
            <?php } else  { ?>
            <div class="bm-ai-chat-bot-settings"
                 data-bot-id="<?php echo esc_attr($post->ID); ?>"
                 data-bot-user-id="<?php echo $bot_user_id; ?>"
                 data-settings="<?php echo esc_attr(json_encode($settings)); ?>"
                 data-roles="<?php echo esc_attr(json_encode($roles)); ?>"
                 data-voices="<?php echo esc_attr(json_encode($voices)); ?>"
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
                "images"  => "0",
                "imagesGeneration" => "0",
                "imagesGenerationModel" => "gpt-image-1-mini",
                "imagesGenerationQuality" => "auto",
                "imagesGenerationSize" => "auto",
                "files" => "0",
                "webSearch" => "0",
                "webSearchContextSize" => "medium",
                "fileSearch" => "0",
                "fileSearchVectorIds" => [],
                "serviceTier" => "auto",
                "temperature" => "",
                "maxOutputTokens" => "",
                "reasoningEffort" => "",
                "model"   => "",
                "instruction" => _x( 'You are a helpful assistant', 'AI Chat Bots (WP Admin)', 'bp-better-messages' ),
                "voice" => $voices[0]
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
                        if( $key === 'instruction' ){
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

        public function rest_thread_item( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ){
            if( $thread_type !== 'thread'){
                return $thread_item;
            }

            $recipients = $thread_item['participants'];
            if( count( $recipients ) === 2 ){
                foreach( $recipients as $user_id ){
                    if( $user_id < 0 ) {
                        $guest_id = absint($user_id);
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
                        $open_ai_conversation = Better_Messages_OpenAI_API::instance()->get_open_ai_conversation($message->thread_id);

                        if (is_wp_error($open_ai_conversation)) {
                            return;
                        }

                        if (!isset($open_ai_conversation['id'])) {
                            return;
                        }

                        $open_ai_conversation_id = $open_ai_conversation['id'];

                        $open_ai_message_id = Better_Messages()->functions->get_message_meta($message_id, 'openai_message_id');

                        if ( ! $open_ai_message_id) {
                            return;
                        }

                        $this->api->delete_conversation_message( $open_ai_conversation_id, $open_ai_message_id );

                        if( defined('BM_DEBUG') ) {
                            file_put_contents(ABSPATH . 'open-ai.log', time() . ' - deleted_message - ' . print_r( "$open_ai_conversation_id, $open_ai_message_id", true ) . "\n", FILE_APPEND | LOCK_EX);
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log( 'Better Messages AI: Error in before_delete_message: ' . $e->getMessage() );
            }
        }

        public function on_message_sent( $message )
        {
            // Sender ID
            $sender_id = (int) $message->sender_id;

            $new_thread = $message->new_thread;

            // Recipients User IDs
            $recipients = Better_Messages()->functions->get_recipients( (int) $message->thread_id );

            if( count( $recipients ) === 2 ){
                foreach ($recipients as $recipient){
                    $user_id = (int) $recipient->user_id;

                    if( $sender_id !== $user_id && $user_id < 0 ){
                        $guest_id = absint($user_id);
                        $guest = Better_Messages()->guests->get_guest_user( $guest_id );

                        if( $guest && $guest->ip && str_starts_with($guest->ip, 'ai-chat-bot-') ){
                            $bot_id = (int) str_replace('ai-chat-bot-', '', $guest->ip);

                            if( $this->bot_exists( $bot_id ) && ( $this->is_bot_conversation( $bot_id, $message->thread_id ) || $new_thread ) ){
                                global $wpdb;

                                $bot_settings = $this->get_bot_settings( $bot_id );
                                Better_Messages()->functions->update_message_meta($message->id, 'ai_bot_id', $bot_id);
                                Better_Messages()->functions->update_message_meta($message->id, 'ai_waiting_for_response', time());
                                Better_Messages()->functions->update_thread_meta($message->thread_id, 'ai_waiting_for_response', time());

                                do_action('better_messages_thread_self_update', $message->thread_id, $sender_id);
                                do_action('better_messages_thread_updated', $message->thread_id, $sender_id);

                                $table = bm_get_table('messages');
                                $wpdb->query( $wpdb->prepare( "UPDATE `{$table}` SET `message` = CONCAT(%s, `message`) WHERE `id` = %d;", '<!-- BM-AI -->', $message->id ) );
                                $message->message = '<!-- BM-AI -->' . $message->message;

                                if ( ! empty( Better_Messages()->settings['openAiApiKey'] ) && ! empty( $bot_settings['model'] ) ) {
                                    // Ensure create response trigger in case something goes wrong
                                    if( ! wp_get_scheduled_event( 'better_messages_ai_bot_ensure_completion', [ $bot_id, $message->id ] ) ){
                                        wp_schedule_single_event( time() + 15, 'better_messages_ai_bot_ensure_completion', [ $bot_id, $message->id ] );
                                    }

                                    $url = add_query_arg([
                                        'bot_id' => $bot_id,
                                        'message_id' => $message->id,
                                        'secret' => $this->get_ai_request_secret()
                                    ], Better_Messages()->functions->get_rest_api_url() . 'ai/createResponse');

                                    wp_remote_get( $url, [
                                        'blocking' => false,
                                        'timeout' => 0
                                    ] );
                                }
                            }
                        }
                    }
                }
            }
        }

        public function ai_bot_ensure_completion( $bot_id, $message_id )
        {
            $this->api->process_reply( $bot_id, $message_id );
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

            // Get message content
            $content = isset( $args['content'] ) ? strip_tags( $args['content'] ) : '';
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
                error_log( 'Better Messages AI Moderation Error: ' . $result->get_error_message() );
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
                error_log( 'Better Messages AI Moderation Error: ' . $result->get_error_message() );
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
            $message_preview = wp_trim_words( strip_tags( $message->message ), 50 );

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
    }

    function Better_Messages_AI(){
        return Better_Messages_AI::instance();
    }
}
