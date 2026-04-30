<?php

use BetterMessages\GuzzleHttp\Exception\GuzzleException;
use BetterMessages\GuzzleHttp\Psr7\Utils;
use BetterMessages\React\EventLoop\Loop;
use BetterMessages\React\Http\Browser;
use BetterMessages\React\Stream\ThroughStream;

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_OpenAI_API' ) ) {
    class Better_Messages_OpenAI_API extends Better_Messages_AI_Provider
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_OpenAI_API();
            }

            return $instance;
        }

        public function __construct()
        {
            $this->api_key = Better_Messages()->settings['openAiApiKey'];
        }

        public function update_api_key()
        {
            $this->api_key = Better_Messages()->settings['openAiApiKey'];
        }

        public function get_provider_id()
        {
            return 'openai';
        }

        public function get_provider_name()
        {
            return 'OpenAI';
        }

        public function get_supported_features()
        {
            return [
                'images', 'files', 'imagesGeneration', 'webSearch',
                'fileSearch', 'audio', 'moderation', 'transcription',
                'reasoningEffort', 'serviceTier', 'temperature', 'maxOutputTokens'
            ];
        }

        public function getResponseGenerator( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            $settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            if ( str_contains( $settings['model'], '-audio-' ) && class_exists( 'BP_Better_Messages_Voice_Messages' ) ) {
                $this->audioProvider( $bot_id, $bot_user, $message );
                return null;
            }

            return $this->responseProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream );
        }

        public function on_response_completed( $ai_message_id, $message_id, $meta )
        {
            if ( isset( $meta['message_id'] ) ) {
                Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_message_id', $meta['message_id'] );
            }
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_meta', json_encode( $meta ) );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', 'completed' );

            if ( isset( $meta['response_id'] ) ) {
                $response_input = $this->get_response_input( $meta['response_id'] );

                if ( ! is_wp_error( $response_input ) ) {
                    Better_Messages()->functions->update_message_meta( $message_id, 'openai_message_id', $response_input );
                }
            }
        }

        public function on_message_deleted( $message_id, $thread_id, $message )
        {
            $meta = Better_Messages()->functions->get_message_meta( $message_id, 'openai_meta' );

            if ( ! empty( $meta ) ) {
                $meta = json_decode( $meta, true );

                if ( isset( $meta['conversation_id'] ) && isset( $meta['message_id'] ) ) {
                    $this->delete_conversation_message( $meta['conversation_id'], $meta['message_id'] );
                }
            }
        }

        public function cancel_response_api( $response_id )
        {
            $client = $this->get_client();

            try {
                $response = $client->post( 'responses/' . $response_id . '/cancel', [
                    'timeout' => 30
                ] );

                $body = $response->getBody();
                $data = json_decode( $body->getContents(), true );

                if ( isset( $data['status'] ) && $data['status'] === 'cancelled' ) {
                    return true;
                }

                return false;
            } catch ( \Throwable $e ) {
                return false;
            }
        }

        public function get_client()
        {
            return new \BetterMessages\GuzzleHttp\Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->get_api_key(),
                    'Content-Type' => 'application/json',
                ]
            ]);
        }

        public function generateSummary( $system_prompt, $user_content, $model = '', $max_tokens = 3000 )
        {
            $client = $this->get_client();

            $params = [
                'model'             => $model,
                'instructions'      => $system_prompt,
                'input'             => [
                    [ 'role' => 'user', 'content' => [ [ 'type' => 'input_text', 'text' => $user_content ] ] ]
                ],
                'stream'            => false,
                'max_output_tokens' => $max_tokens,
            ];

            try {
                $response = $client->post( 'responses', [
                    'json'    => $params,
                    'timeout' => 120
                ] );

                $data = json_decode( $response->getBody()->getContents(), true );

                if ( isset( $data['output'] ) ) {
                    $text = '';
                    $usage = [];

                    foreach ( $data['output'] as $item ) {
                        if ( isset( $item['content'] ) ) {
                            foreach ( $item['content'] as $block ) {
                                if ( isset( $block['text'] ) ) {
                                    $text .= $block['text'];
                                }
                            }
                        }
                    }

                    if ( isset( $data['usage'] ) ) {
                        $usage = $data['usage'];
                    }

                    return [ 'text' => $text, 'usage' => $usage ];
                }

                return new \WP_Error( 'no_output', 'No output in response' );
            } catch ( GuzzleException $e ) {
                return new \WP_Error( 'api_error', $this->parse_guzzle_error( $e ) );
            }
        }

        public function generateDigest( $system_prompt, $user_content, $model = '', $max_tokens = 3000, $options = [] )
        {
            $client = $this->get_client();

            $params = [
                'model'             => $model,
                'instructions'      => $system_prompt,
                'input'             => [
                    [ 'role' => 'user', 'content' => [ [ 'type' => 'input_text', 'text' => $user_content ] ] ]
                ],
                'stream'            => false,
                'max_output_tokens' => $max_tokens,
            ];

            if ( ! empty( $options['webSearch'] ) ) {
                $params['tools'] = [ [
                    'type'                => 'web_search_preview',
                    'search_context_size' => $options['webSearchContextSize'] ?? 'medium',
                ] ];
            }

            try {
                $response = $client->post( 'responses', [
                    'json'    => $params,
                    'timeout' => 120
                ] );

                $raw = $response->getBody()->getContents();
                $data = json_decode( $raw, true );

                if ( isset( $data['output'] ) ) {
                    $text = '';
                    $usage = [];

                    foreach ( $data['output'] as $item ) {
                        if ( isset( $item['content'] ) ) {
                            foreach ( $item['content'] as $block ) {
                                if ( isset( $block['text'] ) ) {
                                    $text .= $block['text'];
                                }
                            }
                        }
                    }

                    if ( isset( $data['usage'] ) ) {
                        $usage = $data['usage'];
                    }

                    return [ 'text' => $text, 'usage' => $usage ];
                }

                return new \WP_Error( 'no_output', 'No output in response' );
            } catch ( GuzzleException $e ) {
                return new \WP_Error( 'api_error', $this->parse_guzzle_error( $e ) );
            }
        }

        public function check_api_key()
        {
            $client = $this->get_client();

            try {
                $client->request('GET', 'models');
                delete_option('better_messages_openai_error');
            } catch ( GuzzleException $e ) {
                $fullError = $e->getMessage();

                if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                    $fullError = $e->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                update_option( 'better_messages_openai_error', $fullError, false );
            }
        }

        /**
         * Moderate content using OpenAI Moderation API
         *
         * @param string $text Text content to moderate
         * @param array $image_data_uris Base64 data URIs of images (data:image/...;base64,...)
         * @return array|WP_Error Moderation result array or WP_Error on failure
         */
        public function moderate( $text = '', $image_data_uris = [] )
        {
            $text = trim( $text );
            $has_text = ! empty( $text );
            $has_images = ! empty( $image_data_uris ) && is_array( $image_data_uris );

            if( ! $has_text && ! $has_images ) {
                return new \WP_Error( 'empty_content', 'No content to moderate' );
            }

            $client = $this->get_client();

            try {
                $input = [];

                if( $has_text ) {
                    $input[] = [
                        'type' => 'text',
                        'text' => $text
                    ];
                }

                if( $has_images ) {
                    foreach( $image_data_uris as $data_uri ) {
                        $input[] = [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => $data_uri
                            ]
                        ];
                    }
                }

                $args = [
                    'model' => 'omni-moderation-latest',
                    'input' => $input
                ];

                // Images may take longer to process
                $timeout = $has_images ? 30 : 10;

                $response = $client->post( 'moderations', [
                    'json' => $args,
                    'timeout' => $timeout,
                    'connect_timeout' => 5
                ] );

                $body = $response->getBody()->getContents();
                $data = json_decode($body, true);

                if( isset( $data['results'][0] ) ) {
                    return $data['results'][0];
                }

                return new \WP_Error( 'invalid_response', 'Invalid response from OpenAI Moderation API' );
            } catch ( GuzzleException $e ) {
                $fullError = $e->getMessage();

                try {
                    if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                        $fullError = $e->getResponse()->getBody()->getContents();

                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    }
                } catch ( \Exception $ignored ) {}

                return new \WP_Error( 'openai_error', $fullError );
            }
        }

        /**
         * Transcribe an audio file using OpenAI Whisper API
         *
         * @param int $attachment_id WordPress attachment ID
         * @return string|\WP_Error Transcribed text or WP_Error on failure
         */
        public function transcribe_audio( $attachment_id )
        {
            $file_path = get_attached_file( $attachment_id );

            if ( ! $file_path || ! file_exists( $file_path ) ) {
                return new \WP_Error( 'file_not_found', 'Audio file not found' );
            }

            if ( filesize( $file_path ) > 25 * 1024 * 1024 ) {
                return new \WP_Error( 'file_too_large', 'Audio file exceeds 25MB limit' );
            }

            // Create client WITHOUT Content-Type: application/json (multipart needs own boundary)
            $client = new \BetterMessages\GuzzleHttp\Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'headers'  => [ 'Authorization' => 'Bearer ' . $this->get_api_key() ]
            ]);

            try {
                $multipart = [
                    [
                        'name'     => 'file',
                        'contents' => Utils::tryFopen( $file_path, 'r' ),
                        'filename' => basename( $file_path )
                    ],
                    [
                        'name'     => 'model',
                        'contents' => Better_Messages()->settings['voiceTranscriptionModel'] ?: 'gpt-4o-mini-transcribe'
                    ],
                    [
                        'name'     => 'response_format',
                        'contents' => 'text'
                    ],
                ];

                $prompt = trim( Better_Messages()->settings['voiceTranscriptionPrompt'] ?? '' );
                if ( $prompt !== '' ) {
                    $multipart[] = [
                        'name'     => 'prompt',
                        'contents' => $prompt,
                    ];
                }

                $response = $client->post( 'audio/transcriptions', [
                    'multipart'       => $multipart,
                    'timeout'         => 120,
                    'connect_timeout' => 10,
                ] );

                return trim( $response->getBody()->getContents() );
            } catch ( GuzzleException $e ) {
                $fullError = $e->getMessage();

                try {
                    if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                        $fullError = $e->getResponse()->getBody()->getContents();

                        $data = json_decode( $fullError, true );
                        if ( isset( $data['error']['message'] ) ) {
                            $fullError = $data['error']['message'];
                        }
                    }
                } catch ( \Exception $ignored ) {}

                return new \WP_Error( 'openai_error', $fullError );
            }
        }

        /**
         * Fetch all model IDs from OpenAI API, cached for 24 hours.
         *
         * @return string[]|\WP_Error Sorted array of all model IDs or WP_Error
         */
        private function get_all_models_cached()
        {
            $cached = get_transient( 'bm_openai_models' );

            if ( $cached !== false ) {
                return $cached;
            }

            $client = $this->get_client();

            try {
                $response   = $client->request( 'GET', 'models' );
                $data       = json_decode( $response->getBody(), true );
                $all_models = array_column( $data['data'], 'id' );

                sort( $all_models );
                set_transient( 'bm_openai_models', $all_models, DAY_IN_SECONDS );

                return $all_models;
            } catch ( GuzzleException $e ) {
                return new \WP_Error( 'openai_error', $e->getMessage() );
            }
        }

        /**
         * Get chat/completion models (gpt-*, o1-*, etc.) from cached list.
         */
        public function get_models()
        {
            $all = $this->get_all_models_cached();

            if ( is_wp_error( $all ) ) {
                return $all;
            }

            $models = [];

            foreach ( $all as $model_id ) {
                if ( ( str_contains( $model_id, 'gpt' ) || preg_match( '/^o[0-9]/', $model_id ) ) && ! str_contains( $model_id, '-realtime-' ) ) {
                    $models[] = $model_id;
                }
            }

            sort( $models );

            return $models;
        }

        /**
         * Get transcription-capable models (whisper-*, gpt-4o-transcribe, etc.) from cached list.
         */
        public function get_transcription_models()
        {
            $all = $this->get_all_models_cached();

            if ( is_wp_error( $all ) ) {
                return $all;
            }

            $models = [];

            foreach ( $all as $model_id ) {
                if ( str_contains( $model_id, 'whisper' ) || str_contains( $model_id, 'transcri' ) ) {
                    $models[] = $model_id;
                }
            }

            sort( $models );

            return $models;
        }

        public function audioProvider( $bot_id, $bot_user, $message ) {
            global $wpdb;

            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            $bot_user_id = absint( $bot_user->id ) * -1;

            $ai_response_id = Better_Messages()->functions->get_message_meta( $message->id, 'ai_response_id' );

            if( ! $ai_response_id ){
                return false;
            }

            $ai_message = Better_Messages()->functions->get_message( $ai_response_id );

            if( ! $ai_message ){
                return false;
            }

            $voice = $bot_settings['voice'];

            $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sender_id, message 
            FROM `" . bm_get_table('messages') . "` 
            WHERE thread_id = %d 
            AND created_at <= %d
            ORDER BY `created_at` ASC", $message->thread_id, $message->created_at ) );

            $request_messages = [];

            if( ! empty( $bot_settings['instruction'] ) ) {
                $request_messages[] = [
                    'role' => 'system',
                    'content' => $this->get_bot_instruction( $bot_settings['instruction'], $bot_id, $message->sender_id, $message->thread_id, $message->id )
                ];
            }

            foreach ( $messages as $_message ){
                $is_error = Better_Messages()->functions->get_message_meta( $_message->id, 'ai_response_error' );
                if( $is_error ) continue;

                $content = [];

                $content[] = [
                    'type' => 'text',
                    'text' => $this->clean_stored_message( $_message->message )
                ];

                $role = (int) $_message->sender_id === (int) $bot_user_id ? 'assistant' : 'user';

                if( $role === 'assistant' ) {
                    $audio_id = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_id' );
                    $message_content = $this->clean_stored_message( $_message->message );

                    if( $audio_id ){
                        $audio_expires_at = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_expires_at' );

                        if( ( time() - $audio_expires_at ) <= -60 ){
                            $voice = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_voice' );

                            $request_messages[] = [
                                'role' => $role,
                                'audio' => [ 'id' => $audio_id ]
                            ];
                        } else {
                            $request_messages[] = [
                                'role' => 'system',
                                'content' => $this->get_bot_instruction( $bot_settings['instruction'], $bot_id, $message->sender_id, $message->thread_id, $message->id )
                            ];
                        }
                    } else if( $transcript = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_transcript') ){
                        $content[] = [
                            'type' => 'text',
                            'text' => $transcript
                        ];
                    } else if( ! empty( $message_content ) ){
                        $content[] = [
                            'type' => 'text',
                            'text' => $message_content
                        ];
                    }
                } else {
                    if ( defined('BM_DEBUG') ) {
                        file_put_contents(ABSPATH . 'open-ai.log', time() . ' - $_message - ' . print_r( $_message, true ) . "\n", FILE_APPEND | LOCK_EX );
                    }

                    if( str_replace('<!-- BM-AI -->', '', $_message->message ) === '<!-- BPBM-VOICE-MESSAGE -->' && $attachment_id = Better_Messages()->functions->get_message_meta( $_message->id, 'bpbm_voice_messages', true ) ){
                        $transcription = $this->transcribe_audio( $attachment_id );

                        if( is_wp_error( $transcription ) || empty( $transcription ) ){
                            continue;
                        }

                        $content[] = [
                            'type' => 'text',
                            'text' => $transcription
                        ];

                    } else {
                        $content[] = [
                            'type' => 'text',
                            'text' => $this->clean_stored_message( $_message->message )
                        ];
                    }
                }

                if( count( $content ) > 0 ) {
                    $request_messages[] = [
                        'role' => $role,
                        'content' => $content,
                    ];
                }

            }

            $params = [
                'model' => $bot_settings['model'],
                'modalities' => ['text', 'audio'],
                'messages' => $request_messages,
                'user' => (string) $message->sender_id
            ];

            $params['audio'] = [
                'format' => 'mp3',
                'voice' => $voice,
            ];

            try {
                Better_Messages()->functions->update_message_meta( $ai_response_id, 'openai_response_status', 'in_progress' );

                $client = $this->get_client();

                $response = $client->post('chat/completions', [
                    'json' => $params
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                // Build meta for cost calculation
                $audio_meta = [
                    'usage'    => isset( $data['usage'] ) ? $data['usage'] : [],
                    'model'    => isset( $data['model'] ) ? $data['model'] : $bot_settings['model'],
                    'provider' => 'openai',
                ];

                if( isset($data['choices']) && is_array($data['choices']) && count($data['choices']) > 0 ) {
                    if( isset( $data['choices'][0]['message']['audio'] ) ) {
                        $audio = $data['choices'][0]['message']['audio'];
                        $id = $audio['id'];
                        $base64 = $audio['data'];
                        $expires_at = $audio['expires_at'];
                        $transcript = $audio['transcript'];

                        $mp3Data = base64_decode($base64);
                        $name = Better_Messages()->functions->random_string(30);
                        $temp_dir = sys_get_temp_dir();
                        $temp_path = trailingslashit($temp_dir) . $name;

                        try {
                            file_put_contents($temp_path, $mp3Data);

                            $file = [
                                'name' => $name . '.mp3',
                                'type' => 'audio/mp3',
                                'tmp_name' => $temp_path,
                                'error' => 0,
                                'size' => filesize($temp_path)
                            ];

                            BP_Better_Messages_Voice_Messages()->save_voice_message_from_file($file, $ai_response_id);

                            Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_id', $id);
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_transcript', $transcript);
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_expires_at', $expires_at);
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_voice', $voice);
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_response_status', 'completed');
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_provider', $this->get_provider_id());
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_provider_meta', json_encode($audio_meta));
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_response_finish', time());
                            Better_Messages()->functions->delete_message_meta($message->id, 'ai_waiting_for_response');
                            Better_Messages()->functions->delete_thread_meta($message->thread_id, 'ai_waiting_for_response');

                            Better_Messages()->ai->calculate_and_store_cost( $ai_response_id, $audio_meta, $bot_id, $message->sender_id, $message->thread_id );

                            do_action( 'better_messages_ai_response_completed', $ai_response_id, $message->id, [], $bot_id, $message->sender_id );
                            do_action('better_messages_thread_self_update', $message->thread_id, $message->sender_id);
                            do_action('better_messages_thread_updated', $message->thread_id, $message->sender_id);
                        } finally {
                            if (file_exists($temp_path)) {
                                unlink($temp_path);
                            }
                        }
                    } else if ( isset( $data['choices'][0]['message']['content'] ) ) {
                        $content = $data['choices'][0]['message']['content'];

                        $args =  [
                            'sender_id'    => $ai_message->sender_id,
                            'thread_id'    => $message->thread_id,
                            'message_id'   => $ai_response_id,
                            'content'      => '<!-- BM-AI -->' . $this->convert_mention_placeholders( htmlentities( $content ) )
                        ];

                        Better_Messages()->functions->update_message( $args );

                        Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_status', 'completed' );
                        Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_provider', $this->get_provider_id() );
                        Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_provider_meta', json_encode( $audio_meta ) );
                        Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_finish', time() );
                        Better_Messages()->functions->delete_message_meta( $message->id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );

                        Better_Messages()->ai->calculate_and_store_cost( $ai_response_id, $audio_meta, $bot_id, $message->sender_id, $message->thread_id );

                        do_action( 'better_messages_ai_response_completed', $ai_response_id, $message->id, [], $bot_id, $message->sender_id );
                        do_action( 'better_messages_thread_self_update', $message->thread_id, $message->sender_id );
                        do_action( 'better_messages_thread_updated', $message->thread_id, $message->sender_id );
                    }
                } else {
                    // No valid response from API
                    $args = [
                        'sender_id'  => $ai_message->sender_id,
                        'thread_id'  => $ai_message->thread_id,
                        'message_id' => $ai_message->id,
                        'content'    => '<!-- BM-AI -->' . $this->get_user_friendly_error( 'No response received from API' )
                    ];

                    Better_Messages()->functions->update_message( $args );
                    Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_status', 'failed' );
                    Better_Messages()->functions->delete_message_meta( $message->id, 'ai_waiting_for_response' );
                    Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );
                    Better_Messages()->functions->add_message_meta( $ai_response_id, 'ai_response_error', 'No response received from API' );
                    do_action( 'better_messages_thread_self_update', $message->thread_id, $message->sender_id );
                    do_action( 'better_messages_thread_updated', $message->thread_id, $message->sender_id );
                }

            } catch (\BetterMessages\GuzzleHttp\Exception\GuzzleException $e) {
                $error = $e->getMessage();

                if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                    $error = $e->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($error, true);
                        if( isset($data['error']['message']) ){
                            $error = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                $user_error = $this->get_user_friendly_error( $error );

                $args =  [
                    'sender_id'    => $ai_message->sender_id,
                    'thread_id'    => $ai_message->thread_id,
                    'message_id'   => $ai_message->id,
                    'content'      => '<!-- BM-AI -->' . $user_error
                ];

                Better_Messages()->functions->update_message( $args );

                Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_status', 'failed' );
                Better_Messages()->functions->delete_message_meta( $message->id, 'ai_waiting_for_response' );
                Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );
                do_action( 'better_messages_thread_self_update', $message->thread_id, $message->sender_id );
                do_action( 'better_messages_thread_updated', $message->thread_id, $message->sender_id );
                Better_Messages()->functions->add_message_meta( $ai_response_id, 'ai_response_error', $error );
                Better_Messages()->functions->add_message_meta( $message->id, 'ai_response_error', $error );
            }
        }

        public function get_response( $response_id )
        {
            $client = $this->get_client();

            try{
                $response = $client->get( "responses/{$response_id}" );

                $data = json_decode($response->getBody()->getContents(), true);

                return $data;
            } catch ( \Exception $exception ) {
                $fullError = $exception->getMessage();

                if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                    $fullError = $exception->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                return new WP_Error( 'bm_failed_to_get_open_ai_conversation_id', $fullError );
            }
        }

        public function get_response_input( $response_id )
        {
            $client = $this->get_client();

            try {
                $response = $client->get("responses/{$response_id}/input_items?limit=1");

                $data = json_decode($response->getBody()->getContents(), true);

                if( isset($data['data'][0]['id']) ){
                    return $data['data'][0]['id'];
                } else {
                    return new WP_Error( 'bm_failed_to_find_open_ai_response_input', 'Response input not found' );
                }
            } catch ( \Exception $exception ) {
                $fullError = $exception->getMessage();

                if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                    $fullError = $exception->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                return new WP_Error( 'bm_failed_to_create_open_ai_conversation_id', $fullError );
            }
        }

        public function sync_conversation( $thread_id )
        {
            $open_ai_conversation_id = $this->get_open_ai_conversation( $thread_id );

            if( ! is_wp_error( $open_ai_conversation_id ) ) {
                $client = $this->get_client();

                try{
                    $response = $client->get("conversations/{$open_ai_conversation_id['id']}/items?limit=5");

                    $data = json_decode($response->getBody()->getContents(), true);

                    // Data retrieved successfully
                } catch ( \Exception $exception ) {
                    $fullError = $exception->getMessage();

                    if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                        $fullError = $exception->getResponse()->getBody()->getContents();

                        try{
                            $data = json_decode($fullError, true);
                            if( isset($data['error']['message']) ){
                                $fullError = $data['error']['message'];
                            }
                        } catch ( Exception $exception ){}
                    }

                    return new WP_Error( 'bm_failed_to_sync_open_ai_conversation_id', $fullError );
                }
            }
        }

        public function delete_conversation_message( $conversation_id, $message_id )
        {
            $client = $this->get_client();

            try {
                $client->delete("conversations/{$conversation_id}/items/{$message_id}");
            } catch ( \Throwable $exception ) {
                $fullError = $exception->getMessage();

                if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                    $fullError = $exception->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                return new WP_Error( 'bm_failed_to_delete_open_ai_conversation_message', $fullError );
            }
        }

        private function is_conversation_not_found_error( $error_message )
        {
            $lower = strtolower( $error_message );
            return strpos( $lower, 'conversation' ) !== false && strpos( $lower, 'not found' ) !== false;
        }

        public function get_open_ai_conversation( $thread_id )
        {
            $openai_conversation = Better_Messages()->functions->get_thread_meta( $thread_id, 'openai_conversation' );

            if( empty( $openai_conversation ) ) {
                $client = $this->get_client();

                $params = [
                    'metadata' => [
                        'bm_thread_id' => $thread_id
                    ]
                ];

                try{
                    $response = $client->post('conversations', [
                        'json' => $params
                    ]);

                    $conversation  = json_decode($response->getBody()->getContents(), true);

                    Better_Messages()->functions->update_thread_meta( $thread_id, 'openai_conversation', $conversation );

                    return $conversation;
                } catch ( \Exception $exception ) {
                    $fullError = $exception->getMessage();

                    if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                        $fullError = $exception->getResponse()->getBody()->getContents();

                        try{
                            $data = json_decode($fullError, true);
                            if( isset($data['error']['message']) ){
                                $fullError = $data['error']['message'];
                            }
                        } catch ( Exception $exception ){}
                    }

                    return new WP_Error( 'bm_failed_to_create_open_ai_conversation_id', $fullError );
                }
            } else {
                return $openai_conversation;
            }
        }

        function responseProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
       {
            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            $thread_id = $message->thread_id;

            $input = [];

            $is_group = $this->is_group_thread( $thread_id );

            $open_ai_conversation = null;
            if ( ! $is_group ) {
                $open_ai_conversation = $this->get_open_ai_conversation( $thread_id );

                if( is_wp_error( $open_ai_conversation ) ){
                    yield ['error', $open_ai_conversation->get_error_message()];
                    return;
                }
            }

            $input_images = [];
            $input_files = [];

            $bot_user_id = absint( $bot_user->id ) * -1;
            $sender_names = array();
            $group_instruction = '';

            if ( $is_group ) {
                $context_limit = ! empty( $bot_settings['groupContextMessages'] ) ? intval( $bot_settings['groupContextMessages'] ) : 20;
                $summary_result  = $this->get_thread_messages_with_summary( $thread_id, $message->created_at, $context_limit, $bot_user_id, $bot_settings );
                $thread_messages = $summary_result['messages'];
                $summary_context = $summary_result['summary'];
                $sender_names = $this->resolve_sender_names( $thread_messages, $bot_user_id );
                $this->enrich_with_reply_context( $thread_messages, $message, $sender_names );
                $bot_name = get_the_title( $bot_id );
                $group_instruction = $this->get_group_context_instruction( $sender_names, $bot_user_id, $bot_name );
                if ( $summary_context ) {
                    $group_instruction .= "\n\nPrevious conversation summary:\n" . $summary_context;
                }

                // Build full conversation history as input (no server-side conversation for groups)
                foreach ( $thread_messages as $_message ) {
                    $is_error = Better_Messages()->functions->get_message_meta( $_message->id, 'ai_response_error' );
                    if ( $is_error ) continue;

                    $msg_text = $this->clean_stored_message( $_message->message );
                    if ( empty( trim( $msg_text ) ) ) {
                        $has_processable_attachments = false;
                        if ( $bot_settings['images'] || $bot_settings['files'] ) {
                            $check_attachments = Better_Messages()->functions->get_message_meta( $_message->id, 'attachments', true );
                            $has_processable_attachments = ! empty( $check_attachments );
                        }
                        if ( ! $has_processable_attachments ) continue;
                    }

                    $role = (int) $_message->sender_id === (int) $bot_user_id ? 'assistant' : 'user';

                    $content = [];

                    if ( $role === 'user' ) {
                        $msg_text = $this->strip_mention_html( $msg_text, $sender_names );
                        $sid = (int) $_message->sender_id;
                        $sender_label = isset( $sender_names[ $sid ] ) ? $sender_names[ $sid ] : 'User #' . abs( $sid );
                        $msg_text = '[' . $sender_label . ']: ' . $msg_text;

                        $attachments = Better_Messages()->functions->get_message_meta( $_message->id, 'attachments', true );

                        if ( ! empty( $attachments ) ) {
                            foreach ( $attachments as $id => $url ) {
                                $file = get_attached_file( $id );

                                if ( $file && file_exists( $file ) && filesize( $file ) <= 20 * 1024 * 1024 ) {
                                    $file_extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
                                    $file_name = pathinfo( $file, PATHINFO_BASENAME );
                                    $base64_content = base64_encode( file_get_contents( $file ) );

                                    $mime_map = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp' ];
                                    if ( $bot_settings['images'] && isset( $mime_map[ $file_extension ] ) ) {
                                        $content[] = [
                                            'type'      => 'input_image',
                                            'image_url' => 'data:' . $mime_map[ $file_extension ] . ';base64,' . $base64_content
                                        ];
                                    } else if ( $bot_settings['files'] && $file_extension === 'pdf' ) {
                                        $original_filename = (string) get_post_meta( $id, 'bp-better-messages-original-name', true );
                                        $content[] = [
                                            'type'      => 'input_file',
                                            'filename'  => ! empty( $original_filename ) ? $original_filename : $file_name,
                                            'file_data' => 'data:application/pdf;base64,' . $base64_content
                                        ];
                                    }
                                }
                            }
                        }
                    }

                    $content[] = [ 'type' => $role === 'assistant' ? 'output_text' : 'input_text', 'text' => $msg_text ];

                    $input[] = [
                        'role'    => $role,
                        'content' => $content
                    ];
                }
            } else {
                $message_content = $this->clean_stored_message( $message->message );

                $content = [];

                if( ! empty( $message_content ) ) {
                    $content[] = [
                        'type' => 'input_text',
                        'text' => $message_content
                    ];
                }

               if( $bot_settings['images'] || $bot_settings['files'] ) {
                   $attachments = Better_Messages()->functions->get_message_meta($message->id, 'attachments', true);

                   if ( ! empty($attachments) ) {
                       foreach ($attachments as $id => $url) {
                           $file = get_attached_file( $id );

                           if( $file && file_exists($file) && filesize($file) <= 20 * 1024 * 1024 ){
                               $file_extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
                               $file_name = pathinfo( $file, PATHINFO_BASENAME );

                               $file_content = file_get_contents( $file );
                               $base64_content = base64_encode( $file_content );

                                $mime_map = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp' ];
                                if( $bot_settings['images'] && isset( $mime_map[ $file_extension ] ) ){
                                    $input_images[] = $id;

                                    $content[] = [
                                        'type' => 'input_image',
                                        'image_url' => 'data:' . $mime_map[ $file_extension ] . ';base64,' . $base64_content
                                    ];
                                } else if( $bot_settings['files'] && $file_extension === 'pdf' ){
                                    $original_filename = (string) get_post_meta( $id, 'bp-better-messages-original-name', true );

                                    $input_files[] = $id;
                                    $content[] = [
                                        'type' => 'input_file',
                                        'filename' => ! empty( $original_filename ) ? $original_filename : $file_name,
                                        'file_data' => 'data:application/pdf;base64,' . $base64_content
                                    ];
                                }

                           }
                       }
                   }
               }

               if( count( $input_files ) > 0 ){
                   Better_Messages()->functions->update_message_meta( $ai_message_id, 'input_files', $input_files );
               }

               if( count( $input_images ) > 0 ){
                   Better_Messages()->functions->update_message_meta( $ai_message_id, 'input_images', $input_images );
               }

                $input[] = [
                   'role' => 'user',
                   'content' => $content
               ];
            }

            $tools = [];

            if( $bot_settings['imagesGeneration'] === '1' ){
                $tools[] = [
                    'type' => 'image_generation',
                    'partial_images' => 0,
                    'model' => $bot_settings['imagesGenerationModel'], // gpt-image-1 or gpt-image-1-mini
                    'quality' => $bot_settings['imagesGenerationQuality'], # low, medium, high, or auto
                    'size' => $bot_settings['imagesGenerationSize'] # One of 1024x1024, 1024x1536, 1536x1024, or auto
                ];
            }

            if( $bot_settings['webSearch'] === '1' ){
                $tools[] = [
                    'type' => 'web_search',
                    'search_context_size' => $bot_settings['webSearchContextSize'], // low, medium, or high
                ];
            }

            if( $bot_settings['fileSearch'] === '1' && is_array($bot_settings['fileSearchVectorIds']) && count( $bot_settings['fileSearchVectorIds'] ) > 0 ){
                $tools[] = [
                    'type' => 'file_search',
                    'vector_store_ids' => $bot_settings['fileSearchVectorIds']
                ];
            }

            $params = [
                'model' => $bot_settings['model'],
                'service_tier' => $bot_settings['serviceTier'], // 'flex', 'default', 'priority', or 'auto'
                'truncation' => 'auto',
                'tools' => $tools,
            ];

            if ( $open_ai_conversation ) {
                $params['conversation'] = $open_ai_conversation['id'];
            }

            $params += [
                'instructions' => $this->get_bot_instruction( $bot_settings['instruction'], $bot_id, $message->sender_id, $message->thread_id, $message->id )
                    . $group_instruction
                    . '. This is very important you to use correct markdown format for providing response, especially for code blocks and snippets.'
                    . ( ! empty( $bot_settings['maxImagesPerResponse'] ) && intval( $bot_settings['maxImagesPerResponse'] ) > 0
                        ? ' You must generate no more than ' . intval( $bot_settings['maxImagesPerResponse'] ) . ' image(s) per response.'
                        : '' )
                    . ( ! empty( $bot_settings['maxWebSearchCalls'] ) && intval( $bot_settings['maxWebSearchCalls'] ) > 0
                        ? ' You must perform no more than ' . intval( $bot_settings['maxWebSearchCalls'] ) . ' web search(es) per response.'
                        : '' )
                    . ( ! empty( $bot_settings['maxFileSearchCalls'] ) && intval( $bot_settings['maxFileSearchCalls'] ) > 0
                        ? ' You must perform no more than ' . intval( $bot_settings['maxFileSearchCalls'] ) . ' file search(es) per response.'
                        : '' ),
                'input' => $input,
            ];

            if ( $stream ) {
                $params['stream'] = true;
            }

            if( ! empty( $bot_settings['maxOutputTokens'] ) && intval( $bot_settings['maxOutputTokens'] ) > 0 ){
                $params['max_output_tokens'] = intval( $bot_settings['maxOutputTokens'] );
            }

            if( ! empty( $bot_settings['temperature'] ) && is_numeric( $bot_settings['temperature'] ) ){
                $params['temperature'] = floatval( $bot_settings['temperature'] );
            }

            if( ! empty( $bot_settings['reasoningEffort'] ) && in_array( $bot_settings['reasoningEffort'], ['low', 'medium', 'high'] ) ){
                $params['reasoning'] = [
                    'effort' => $bot_settings['reasoningEffort']
                ];
            }

            $client = $this->get_client();

            if( defined('BM_DEBUG') ) {
                file_put_contents(ABSPATH . 'open-ai.log', time() . ' - params - ' . print_r( $params, true ) . "\n", FILE_APPEND | LOCK_EX);
            }

            if ( ! $stream ) {
                try {
                    $response = $client->post('responses', [
                        'json'    => $params,
                        'timeout' => 3600
                    ]);

                    $data = json_decode( $response->getBody()->getContents(), true );

                    $text = '';
                    $web_search_calls = 0;
                    $file_search_calls = 0;

                    if ( isset( $data['output'] ) ) {
                        foreach ( $data['output'] as $item ) {
                            $item_type = $item['type'] ?? '';
                            switch ( $item_type ) {
                                case 'message':
                                    if ( isset( $item['content'] ) ) {
                                        foreach ( $item['content'] as $block ) {
                                            if ( isset( $block['text'] ) ) {
                                                $text .= $block['text'];
                                            }
                                        }
                                    }
                                    break;
                                case 'web_search_call':
                                    $web_search_calls++;
                                    break;
                                case 'file_search_tool_call':
                                    $file_search_calls++;
                                    break;
                            }
                        }
                    }

                    $meta = [
                        'response_id'  => $data['id'] ?? '',
                        'message_id'   => '',
                        'model'        => $data['model'] ?? $bot_settings['model'],
                        'provider'     => 'openai',
                        'service_tier' => $data['service_tier'] ?? '',
                        'usage'        => $data['usage'] ?? [],
                    ];

                    if ( $web_search_calls > 0 ) {
                        $meta['web_search_calls'] = $web_search_calls;
                    }
                    if ( $file_search_calls > 0 ) {
                        $meta['file_search_calls'] = $file_search_calls;
                    }

                    if ( ! empty( $text ) ) {
                        yield $text;
                    }
                    yield ['finish', $meta];
                } catch ( GuzzleException $e ) {
                    $fullError = $this->parse_guzzle_error( $e );

                    if ( $open_ai_conversation && $this->is_conversation_not_found_error( $fullError ) ) {
                        Better_Messages()->functions->delete_thread_meta( $thread_id, 'openai_conversation' );
                    }

                    yield ['error', $fullError];
                } catch ( \Throwable $e ) {
                    yield ['error', $e->getMessage()];
                }
                return;
            }

            $response_id = '';
            $message_id = '';
            $model = '';
            $images_generated = [];
            $attachment_meta = [];
            $max_images = ! empty( $bot_settings['maxImagesPerResponse'] ) ? intval( $bot_settings['maxImagesPerResponse'] ) : 0;
            $max_web_searches = ! empty( $bot_settings['maxWebSearchCalls'] ) ? intval( $bot_settings['maxWebSearchCalls'] ) : 0;
            $max_file_searches = ! empty( $bot_settings['maxFileSearchCalls'] ) ? intval( $bot_settings['maxFileSearchCalls'] ) : 0;
            $web_search_calls = 0;
            $file_search_calls = 0;

            try {
               $response = $client->post('responses', [
                   'json' => $params,
                   'stream' => true,
                   'timeout' => 3600
               ]);

               $body = $response->getBody();

               $buffer = '';

               while ( ! $body->eof() ) {
                   $chunk = $body->read(1024);

                   if ($chunk === '') {
                       continue;
                   }

                   $buffer .= $chunk;

                   // Process full lines
                   while (($pos = strpos($buffer, "\n")) !== false) {
                       $line = trim(substr($buffer, 0, $pos));
                       $buffer = substr($buffer, $pos + 1);

                       if ($line === '') {
                           continue;
                       }

                       if (strpos($line, 'data: ') === 0) {
                           $json = substr($line, 6);

                           $data = json_decode( $json, true );

                           if( ! is_array($data) || ! isset($data['type']) ){
                               continue;
                           }

                           if( defined('BM_DEBUG') ) {
                               file_put_contents( ABSPATH . 'open-ai.log', time() . ' - ' . print_r( $data, true ) . "\n", FILE_APPEND | LOCK_EX );
                           }

                           $type = $data['type'];

                           switch ($type) {
                               case 'response.created':
                                   $response_id = $data['response']['id'];
                                   $model = $data['response']['model'];
                                   $response_status = $data['response']['status'];
                                   Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', $response_status );
                                   Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_id', $response_id );
                                   yield ['tick'];
                                   break;
                               case 'response.in_progress':
                                   $response_id = $data['response']['id'];
                                   $model = $data['response']['model'];
                                   $response_status = $data['response']['status'];
                                   Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', $response_status );
                                   yield ['tick'];
                                   break;
                               case 'response.output_item.added':
                                   $message_id = $data['item']['id'];
                                   yield ['tick'];
                                   break;
                               case 'response.content_part.added':
                                   $message_id = $data['item_id'];
                                   $text = $data['part']['text'];
                                   yield $text;
                                   break;

                               case 'response.output_text.delta':
                                   $message_id = $data['item_id'];
                                   $text = $data['delta'];
                                   yield $text;
                                   break;

                               case 'response.content_part.done':
                                   $message_id = $data['item_id'];
                                   yield ['tick'];
                                   break;

                               case 'response.output_item.done':
                                   $message_id = $data['item']['id'];

                                   if( isset( $data['item'] ) ){

                                       $item = $data['item'];

                                       if( isset( $item['type'] ) ){
                                           $type = $item['type'];

                                           switch ($type) {
                                               case 'image_generation_call':
                                                   if ( $max_images > 0 && count( $images_generated ) >= $max_images ) {
                                                       break;
                                                   }
                                                   $id      = $item['id'];
                                                   $format = $item['output_format'];
                                                   $size = $item['size'];
                                                   $background = $item['background'];
                                                   $quality = $item['quality'];

                                                   $generated_image = [
                                                         'id' => $id,
                                                         'model' => $bot_settings['imagesGenerationModel'],
                                                         'format' => $format,
                                                         'size' => $size,
                                                         'background' => $background,
                                                         'quality' => $quality
                                                   ];

                                                   $base64 = $item['result'];
                                                   $fileData = base64_decode($base64);
                                                   $name = Better_Messages()->functions->random_string(30);
                                                   $temp_dir = sys_get_temp_dir();
                                                   $temp_path = trailingslashit($temp_dir) . $name;

                                                   try {
                                                       file_put_contents($temp_path, $fileData);

                                                       $file = [
                                                           'name' => $name . '.' . $format,
                                                           'type' => 'image/' . $format,
                                                           'tmp_name' => $temp_path,
                                                           'error' => 0,
                                                           'size' => filesize($temp_path)
                                                       ];

                                                       $attachment_id = Better_Messages()->files->save_file( $file, $ai_message_id, absint( $bot_user->id ) * -1 );

                                                       if( ! is_wp_error( $attachment_id ) ) {
                                                           $generated_image['attachment_id'] = $attachment_id;
                                                           add_post_meta( $attachment_id, 'bm_openai_generated_image', 1, true );
                                                           add_post_meta( $attachment_id, 'bm_openai_file_id', $id, true );
                                                           add_post_meta( $attachment_id, 'bm_openai_quality', $quality, true );
                                                           add_post_meta( $attachment_id, 'bm_openai_background', $background, true );
                                                           add_post_meta( $attachment_id, 'bm_openai_size', $size, true );
                                                           $attachment_meta[ $attachment_id ] = wp_get_attachment_url( $attachment_id );
                                                       }

                                                       Better_Messages()->functions->update_message_meta( $ai_message_id, 'attachments', $attachment_meta );
                                                   } finally {
                                                       @unlink($temp_path);
                                                       $images_generated[] = $generated_image;
                                                   }

                                                   break;
                                               case 'web_search_call':
                                                   $web_search_calls++;
                                                   break;
                                               case 'file_search_tool_call':
                                                   $file_search_calls++;
                                                   break;
                                           }
                                       }
                                   }
                                   yield ['tick'];
                                   break;

                               case 'response.failed':
                                   $errorMessage = 'Unknown error';
                                   if( isset( $data['response']['error']['message'] ) ){
                                        $errorMessage = $data['response']['error']['message'];
                                   }

                                   yield ['error', $errorMessage];
                                   break;

                               case 'response.completed':
                                   if( count( $attachment_meta ) > 0 ) {
                                       Better_Messages()->functions->update_message_meta( $ai_message_id, 'attachments', $attachment_meta );
                                   }

                                   $array = [
                                       'response_id' => $response_id,
                                       'message_id' => $message_id,
                                       'model' => $model,
                                       'provider' => 'openai',
                                       'service_tier' => $data['response']['service_tier'] ?? '',
                                       'usage' => $data['response']['usage']
                                   ];

                                   if ( $open_ai_conversation ) {
                                       $array['conversation_id'] = $open_ai_conversation['id'];
                                   }

                                   if( count( $images_generated ) > 0 ){
                                       $array['images_generated'] = $images_generated;
                                   }

                                   if ( $web_search_calls > 0 ) {
                                       $array['web_search_calls'] = $web_search_calls;
                                   }

                                   if ( $file_search_calls > 0 ) {
                                       $array['file_search_calls'] = $file_search_calls;
                                   }

                                   yield ['finish', $array];
                                   break;
                               default:
                                   yield ['tick'];
                                   break;
                           }
                       }
                   }
               }
           } catch ( GuzzleException $e ) {
               $fullError = $this->parse_guzzle_error( $e );

               if( defined('BM_DEBUG') ) {
                   file_put_contents(ABSPATH . 'open-ai.log', time() . ' - error responseProvider GuzzleException - ' . $fullError . "\n", FILE_APPEND | LOCK_EX);
               }

               if ( $open_ai_conversation && $this->is_conversation_not_found_error( $fullError ) ) {
                   Better_Messages()->functions->delete_thread_meta( $thread_id, 'openai_conversation' );
               }

               yield ['error', $fullError];
           } catch (\Throwable $e) {
                $fullError = $e->getMessage();

                if( defined('BM_DEBUG') ) {
                    file_put_contents(ABSPATH . 'open-ai.log', time() . ' - error responseProvider - ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                }

                yield ['error', $fullError];
            }
       }

        public function ensureResponseCompletionJob()
        {
            global $wpdb;

            $table = bm_get_table('meta');

            $query = "SELECT `bm_message_id`
            FROM `{$table}`
            WHERE (`meta_key` = 'openai_response_status' AND `meta_value` IN ('queued', 'in_progress'))
               OR (`meta_key` = 'ai_response_status' AND `meta_value` = 'in_progress');";

            $uncompleted = array_map( 'intval', $wpdb->get_col($query) );

            if( count($uncompleted) > 0 ){
                foreach($uncompleted as $message_id){
                    $message = Better_Messages()->functions->get_message( $message_id );

                    if( ! $message ){
                        Better_Messages()->functions->delete_message_meta( $message_id, 'openai_response_status' );
                        Better_Messages()->functions->delete_message_meta( $message_id, 'ai_response_status' );
                        continue;
                    }

                    $error = Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_error' );

                    if( ! empty( $error ) ){
                        Better_Messages()->functions->update_message_meta( $message_id, 'openai_response_status', 'failed' );
                        Better_Messages()->functions->update_message_meta( $message_id, 'ai_response_status', 'failed' );
                        continue;
                    }

                    $last_ping = (int) Better_Messages()->functions->get_message_meta( $message_id, 'ai_last_ping' );

                    if( time() - $last_ping <= 3 * 60 ) {
                        // if the last ping was within 3 minutes, skip processing
                        continue;
                    }

                    // Without background mode, stalled responses mean the PHP process died
                    // and the OpenAI response was also cancelled. Mark as failed and clean up.
                    $user_error = __( 'The response was interrupted. Please try again.', 'bp-better-messages' );

                    $args = [
                        'sender_id'  => $message->sender_id,
                        'thread_id'  => $message->thread_id,
                        'message_id' => $message_id,
                        'content'    => '<!-- BM-AI -->' . $user_error
                    ];

                    Better_Messages()->functions->update_message( $args );

                    Better_Messages()->functions->update_message_meta( $message_id, 'openai_response_status', 'failed' );
                    Better_Messages()->functions->update_message_meta( $message_id, 'ai_response_status', 'failed' );
                    Better_Messages()->functions->add_message_meta( $message_id, 'ai_response_error', 'Response interrupted (process terminated)' );
                    Better_Messages()->functions->delete_message_meta( $message_id, 'ai_last_ping' );
                    Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );

                    $original_message_id = Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_for' );
                    $original_message = Better_Messages()->functions->get_message( $original_message_id );

                    if ( $original_message ) {
                        Better_Messages()->functions->delete_message_meta( $original_message->id, 'ai_waiting_for_response' );
                        do_action( 'better_messages_thread_self_update', $original_message->thread_id, $original_message->sender_id );
                        do_action( 'better_messages_thread_updated', $original_message->thread_id, $original_message->sender_id );
                    }
                }
            }
        }

        public function createResponse( $ai_message_id, $params )
        {
            $client = $this->get_client();

            try{
                $response = $client->post('responses', [
                    'json' => $params,
                    'timeout' => 3600
                ]);

                $body = $response->getBody();

                $data = json_decode($body->getContents(), true);

                if( defined('BM_DEBUG') ) {
                    file_put_contents( ABSPATH . 'open-ai.log', time() . ' - createResponse data - ' . print_r( $data, true ) . "\n", FILE_APPEND | LOCK_EX );
                }

                if( isset( $data['id'] ) ){
                    $response_id = $data['id'];
                    $response_status = $data['status'];
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_id', $response_id );
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', $response_status );
                    return $response_id;
                } else {
                    throw new Exception( 'Failed to create response: No response ID returned' );
                }
            } catch (GuzzleException $e) {
                $fullError = $e->getMessage();

                if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                    $fullError = $e->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                if( defined('BM_DEBUG') ) {
                    file_put_contents(ABSPATH . 'open-ai.log', time() . ' - GuzzleException error createResponse - ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                }

                throw $e;
            } catch (\Throwable $e) {
                $fullError = $e->getMessage();

                if( defined('BM_DEBUG') ) {
                    file_put_contents(ABSPATH . 'open-ai.log', time() . ' - Throwable error createResponse - ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX);
                }

                throw $e;
            }
        }

        function chatProvider( $bot_id, $bot_user, $message ) {
            global $wpdb;

            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            $bot_user_id = absint( $bot_user->id ) * -1;

            $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sender_id, message 
            FROM `" . bm_get_table('messages') . "` 
            WHERE thread_id = %d 
            AND created_at <= %d
            ORDER BY `created_at` ASC", $message->thread_id, $message->created_at ) );

            $request_messages = [];

            if( ! empty( $bot_settings['instruction'] ) ) {
                $request_messages[] = [
                    'role' => 'system',
                    'content' => $this->get_bot_instruction( $bot_settings['instruction'], $bot_id, $message->sender_id, $message->thread_id, $message->id )
                ];
            }

            foreach ( $messages as $_message ){
                $is_error = Better_Messages()->functions->get_message_meta( $_message->id, 'ai_response_error' );
                if( $is_error ) continue;

                $content = [];

                $content[] = [
                    'type' => 'text',
                    'text' => $this->clean_stored_message( $_message->message )
                ];

                $attachments = Better_Messages()->functions->get_message_meta($_message->id, 'attachments', true);

                if ( ! empty( $attachments ) ) {
                    foreach ( $attachments as $id => $url ) {
                        $file = get_attached_file( $id );

                        if ( $file && file_exists( $file ) && filesize( $file ) <= 20 * 1024 * 1024 ) {
                            $file_extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
                            $base64_content = base64_encode( file_get_contents( $file ) );

                            $mime_map = [ 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp', 'webp' => 'image/webp' ];
                            if ( $bot_settings['images'] && isset( $mime_map[ $file_extension ] ) ) {
                                $content[] = [
                                    'type'      => 'image_url',
                                    'image_url' => [ 'url' => 'data:' . $mime_map[ $file_extension ] . ';base64,' . $base64_content ]
                                ];
                            } else if ( $bot_settings['files'] && $file_extension === 'pdf' ) {
                                $file_name = pathinfo( $file, PATHINFO_BASENAME );
                                $original_filename = (string) get_post_meta( $id, 'bp-better-messages-original-name', true );
                                $content[] = [
                                    'type'      => 'input_file',
                                    'filename'  => ! empty( $original_filename ) ? $original_filename : $file_name,
                                    'file_data' => 'data:application/pdf;base64,' . $base64_content
                                ];
                            }
                        }
                    }
                }

                $request_messages[] = [
                    'role' => $_message->sender_id === $bot_user_id ? 'assistant' : 'user',
                    'content' => $content,
                ];
            }

            $params = [
                'model' => $bot_settings['model'],
                'messages' => $request_messages,
                'user' => $message->sender_id,
                'stream' => true
            ];

            $client = $this->get_client();

            try {
                $response = $client->post('chat/completions', [
                    'json' => $params,
                    'stream' => true
                ]);

                $body = $response->getBody();
                $buffer = '';

                $request_id = '';
                $model = '';
                $service_tier = '';
                $system_fingerprint = '';

                while (!$body->eof()) {
                    $chunk = $body->read(1024);
                    if ($chunk === '') {
                        continue;
                    }

                    $buffer .= $chunk;

                    // Process full lines
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $pos));
                        $buffer = substr($buffer, $pos + 1);

                        if ($line === '') {
                            continue;
                        }

                        if (strpos($line, 'data: ') === 0) {
                            $json = substr($line, 6);

                            if ($json === '[DONE]') {
                                yield ['finish', [
                                    'request_id' => $request_id,
                                    'model' => $model,
                                    'provider' => 'openai',
                                    'service_tier' => $service_tier,
                                    'system_fingerprint' => $system_fingerprint
                                ]];

                                return; // end of stream
                            }

                            $data = json_decode($json, true);

                            if( isset($data['id']) ) {
                                $request_id = $data['id'];
                            }

                            if( isset( $data['model'] ) ){
                                $model = $data['model'];
                            }

                            if( isset( $data['service_tier'] ) ){
                                $service_tier = $data['service_tier'];
                            }

                            if( isset( $data['system_fingerprint'] ) ){
                                $system_fingerprint = $data['system_fingerprint'];
                            }

                            if (isset($data['choices'][0]['delta']['content'])) {
                                yield $data['choices'][0]['delta']['content'];
                            }
                        }
                    }
                }
            } catch (GuzzleException $e) {
                $fullError = $e->getMessage();

                if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                    $fullError = $e->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                yield ['error', $fullError];
            }
        }

    }
}
