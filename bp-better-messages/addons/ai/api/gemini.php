<?php

use BetterMessages\GuzzleHttp\Exception\GuzzleException;

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Gemini_API' ) ) {
    class Better_Messages_Gemini_API extends Better_Messages_AI_Provider
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Gemini_API();
            }

            return $instance;
        }

        public function __construct()
        {
            $this->api_key = Better_Messages()->settings['geminiApiKey'] ?? '';
        }

        public function get_provider_id()
        {
            return 'gemini';
        }

        public function get_provider_name()
        {
            return 'Google Gemini';
        }

        public function get_supported_features()
        {
            return [ 'images', 'files', 'imagesGeneration', 'webSearch', 'extendedThinking', 'temperature', 'maxOutputTokens' ];
        }

        private function get_client()
        {
            return $this->get_guzzle_client( 'https://generativelanguage.googleapis.com/v1beta/', [] );
        }

        public function generateSummary( $system_prompt, $user_content, $model = '', $max_tokens = 3000 )
        {
            $client = $this->get_client();

            $params = [
                'system_instruction' => [
                    'parts' => [ [ 'text' => $system_prompt ] ]
                ],
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [ [ 'text' => $user_content ] ]
                    ]
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $max_tokens
                ]
            ];

            $url = 'models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $this->get_api_key() );

            try {
                $response = $client->post( $url, [
                    'json'    => $params,
                    'timeout' => 120
                ] );

                $data = json_decode( $response->getBody()->getContents(), true );

                if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
                    $text = '';
                    $usage = [];

                    foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
                        if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
                            $text .= $part['text'];
                        }
                    }

                    if ( isset( $data['usageMetadata'] ) ) {
                        $usage = $data['usageMetadata'];
                    }

                    return [ 'text' => $text, 'usage' => $usage ];
                }

                if ( isset( $data['promptFeedback']['blockReason'] ) ) {
                    return new \WP_Error( 'blocked', 'Request blocked: ' . $data['promptFeedback']['blockReason'] );
                }

                return new \WP_Error( 'no_output', 'No output in response' );
            } catch ( GuzzleException $e ) {
                return new \WP_Error( 'api_error', $this->parse_gemini_error( $e ) );
            }
        }

        public function generateDigest( $system_prompt, $user_content, $model = '', $max_tokens = 3000, $options = [] )
        {
            $client = $this->get_client();

            $params = [
                'system_instruction' => [
                    'parts' => [ [ 'text' => $system_prompt ] ]
                ],
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [ [ 'text' => $user_content ] ]
                    ]
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $max_tokens
                ]
            ];

            if ( ! empty( $options['webSearch'] ) ) {
                $params['tools'] = [ [ 'google_search' => new \stdClass() ] ];
            }

            $url = 'models/' . urlencode( $model ) . ':generateContent?key=' . urlencode( $this->get_api_key() );

            try {
                $response = $client->post( $url, [
                    'json'    => $params,
                    'timeout' => 120
                ] );

                $data = json_decode( $response->getBody()->getContents(), true );

                if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
                    $text = '';
                    $usage = [];

                    foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
                        if ( isset( $part['text'] ) && empty( $part['thought'] ) ) {
                            $text .= $part['text'];
                        }
                    }

                    if ( isset( $data['usageMetadata'] ) ) {
                        $usage = $data['usageMetadata'];
                    }

                    return [ 'text' => $text, 'usage' => $usage ];
                }

                if ( isset( $data['promptFeedback']['blockReason'] ) ) {
                    return new \WP_Error( 'blocked', 'Request blocked: ' . $data['promptFeedback']['blockReason'] );
                }

                return new \WP_Error( 'no_output', 'No output in response' );
            } catch ( GuzzleException $e ) {
                return new \WP_Error( 'api_error', $this->parse_gemini_error( $e ) );
            }
        }

        public function check_api_key()
        {
            $client = $this->get_client();

            try {
                $client->request( 'GET', 'models?key=' . urlencode( $this->get_api_key() ) . '&pageSize=1' );
                delete_option( 'better_messages_gemini_error' );
            } catch ( GuzzleException $e ) {
                $error = $this->parse_gemini_error( $e );
                update_option( 'better_messages_gemini_error', $error, false );
            }
        }

        /**
         * Parse Gemini-specific error format
         */
        private function parse_gemini_error( $e )
        {
            $error = $e->getMessage();

            if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                $body = $e->getResponse()->getBody()->getContents();

                try {
                    $data = json_decode( $body, true );
                    if ( isset( $data['error']['message'] ) ) {
                        $error = $data['error']['message'];
                    }
                } catch ( \Exception $ignored ) {}
            }

            return $error;
        }

        /**
         * Fetch models from Gemini API, cached for 24 hours
         */
        private function get_all_models_cached()
        {
            $cached = get_transient( 'bm_gemini_models' );

            if ( $cached !== false ) {
                return $cached;
            }

            $client = $this->get_client();

            try {
                $all_models     = [];
                $next_page_token = null;

                do {
                    $url = 'models?key=' . urlencode( $this->get_api_key() ) . '&pageSize=100';
                    if ( $next_page_token ) {
                        $url .= '&pageToken=' . urlencode( $next_page_token );
                    }

                    $response = $client->request( 'GET', $url );
                    $data     = json_decode( $response->getBody(), true );

                    if ( isset( $data['models'] ) && is_array( $data['models'] ) ) {
                        foreach ( $data['models'] as $model ) {
                            if ( isset( $model['name'] ) ) {
                                $all_models[] = $model;
                            }
                        }
                    }

                    $next_page_token = $data['nextPageToken'] ?? null;
                } while ( $next_page_token );

                set_transient( 'bm_gemini_models', $all_models, DAY_IN_SECONDS );

                return $all_models;
            } catch ( GuzzleException $e ) {
                return new \WP_Error( 'gemini_error', $this->parse_gemini_error( $e ) );
            }
        }

        public function get_models()
        {
            $all = $this->get_all_models_cached();

            if ( is_wp_error( $all ) ) {
                return $all;
            }

            $models = [];

            foreach ( $all as $model ) {
                $name    = $model['name'] ?? '';
                $methods = $model['supportedGenerationMethods'] ?? [];

                // Only include models that support generateContent
                if ( in_array( 'generateContent', $methods ) ) {
                    // Strip "models/" prefix
                    $model_id = str_replace( 'models/', '', $name );

                    // Only include gemini models
                    if ( str_starts_with( $model_id, 'gemini' ) ) {
                        $models[] = $model_id;
                    }
                }
            }

            sort( $models );

            return $models;
        }

        /**
         * Check if a Gemini model supports native image generation (responseModalities: IMAGE).
         */
        private function model_supports_image_generation( $model_id )
        {
            $image_models = Better_Messages()->ai->get_image_pricing();
            if ( isset( $image_models[ $model_id ] ) ) {
                return true;
            }
            // Strip common suffixes like -preview, -latest
            $base = preg_replace( '/-(preview|latest)$/', '', $model_id );
            return $base !== $model_id && isset( $image_models[ $base ] );
        }

        /**
         * Get the input token limit for a model from cached model info.
         */
        private function get_model_input_limit( $model_id )
        {
            $models = $this->get_all_models_cached();
            if ( is_wp_error( $models ) ) {
                return 900000;
            }

            foreach ( $models as $model ) {
                $name = str_replace( 'models/', '', $model['name'] ?? '' );
                if ( $name === $model_id ) {
                    return isset( $model['inputTokenLimit'] ) ? (int) $model['inputTokenLimit'] : 900000;
                }
            }

            return 900000;
        }

        /**
         * Build parts array for a user message (images, files, text).
         */
        private function build_user_parts( $msg_id, $message_text, $bot_settings )
        {
            $parts = [];

            if ( $bot_settings['images'] || $bot_settings['files'] ) {
                $attachments = Better_Messages()->functions->get_message_meta( $msg_id, 'attachments', true );

                if ( ! empty( $attachments ) ) {
                    $mime_map = [
                        'jpg'  => 'image/jpeg',
                        'jpeg' => 'image/jpeg',
                        'png'  => 'image/png',
                        'gif'  => 'image/gif',
                        'webp' => 'image/webp',
                        'bmp'  => 'image/bmp',
                    ];

                    foreach ( $attachments as $id => $url ) {
                        $file = get_attached_file( $id );

                        if ( $file && file_exists( $file ) && filesize( $file ) <= 20 * 1024 * 1024 ) {
                            $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

                            if ( $bot_settings['images'] && isset( $mime_map[ $ext ] ) ) {
                                $base64  = base64_encode( file_get_contents( $file ) );
                                $parts[] = [
                                    'inline_data' => [
                                        'mime_type' => $mime_map[ $ext ],
                                        'data'      => $base64
                                    ]
                                ];
                            } else if ( $bot_settings['files'] && $ext === 'pdf' ) {
                                $base64  = base64_encode( file_get_contents( $file ) );
                                $parts[] = [
                                    'inline_data' => [
                                        'mime_type' => 'application/pdf',
                                        'data'      => $base64
                                    ]
                                ];
                            }
                        }
                    }
                }
            }

            if ( ! empty( $message_text ) ) {
                $parts[] = [ 'text' => $message_text ];
            }

            return $parts;
        }

        /**
         * Build Gemini contents array from messages.
         * Includes stored thinking parts (with thoughtSignature) for model messages.
         */
        private function build_contents( $thread_messages, $bot_user_id, $bot_settings, $current_user_msg_id, $sender_names = array() )
        {
            $is_group = ! empty( $sender_names );
            $contents = [];

            foreach ( $thread_messages as $_message ) {
                $is_error = Better_Messages()->functions->get_message_meta( $_message->id, 'ai_response_error' );
                if ( $is_error ) continue;

                $message_text = $this->clean_stored_message( $_message->message );
                if ( empty( trim( $message_text ) ) && (int) $_message->id !== (int) $current_user_msg_id ) {
                    $has_processable_attachments = false;
                    if ( $bot_settings['images'] || $bot_settings['files'] ) {
                        $check_attachments = Better_Messages()->functions->get_message_meta( $_message->id, 'attachments', true );
                        $has_processable_attachments = ! empty( $check_attachments );
                    }
                    if ( ! $has_processable_attachments ) continue;
                }

                $parts = [];
                $role  = (int) $_message->sender_id === (int) $bot_user_id ? 'model' : 'user';

                // In group context: strip mention HTML and prefix with sender name
                if ( $is_group && $role === 'user' && ! empty( $message_text ) ) {
                    $message_text = $this->strip_mention_html( $message_text, $sender_names );
                    $sid = (int) $_message->sender_id;
                    $sender_label = isset( $sender_names[ $sid ] ) ? $sender_names[ $sid ] : 'User #' . abs( $sid );
                    $message_text = '[' . $sender_label . ']: ' . $message_text;
                }

                if ( $role === 'user' ) {
                    $parts = $this->build_user_parts( $_message->id, $message_text, $bot_settings );
                } else {
                    // Include stored thinking parts with signatures for model messages
                    $thought_parts = Better_Messages()->functions->get_message_meta( $_message->id, 'gemini_thought_parts', true );
                    if ( ! empty( $thought_parts ) && is_array( $thought_parts ) ) {
                        foreach ( $thought_parts as $tp ) {
                            $thought_part = [ 'thought' => true, 'text' => $tp['text'] ?? '' ];
                            if ( ! empty( $tp['thoughtSignature'] ) ) {
                                $thought_part['thoughtSignature'] = $tp['thoughtSignature'];
                            }
                            $parts[] = $thought_part;
                        }
                    }

                    if ( ! empty( $message_text ) ) {
                        $parts[] = [ 'text' => $message_text ];
                    }
                }

                if ( empty( $parts ) ) continue;

                // Gemini requires alternating user/model roles
                $last_index = count( $contents ) - 1;
                if ( $last_index >= 0 && $contents[ $last_index ]['role'] === $role ) {
                    $contents[ $last_index ]['parts'] = array_merge( $contents[ $last_index ]['parts'], $parts );
                } else {
                    $contents[] = [
                        'role'  => $role,
                        'parts' => $parts,
                    ];
                }
            }

            return $contents;
        }

        /**
         * Auto-trim contents if estimated token count exceeds model input limit.
         * Removes oldest messages first, always keeps at least the last 2 entries.
         */
        private function auto_trim_contents( $contents, $model_id )
        {
            $limit           = $this->get_model_input_limit( $model_id );
            $effective_limit = (int) ( $limit * 0.85 ); // 15% buffer for system prompt + overhead

            $estimate_tokens = function ( $parts ) {
                $tokens = 0;
                foreach ( $parts as $part ) {
                    if ( isset( $part['text'] ) ) {
                        $tokens += (int) ( mb_strlen( $part['text'] ) / 4 );
                    }
                    if ( isset( $part['inline_data']['data'] ) ) {
                        $tokens += 258; // rough per-image estimate
                    }
                }
                return $tokens;
            };

            $total = 0;
            foreach ( $contents as $c ) {
                $total += $estimate_tokens( $c['parts'] );
            }

            while ( $total > $effective_limit && count( $contents ) > 2 ) {
                $removed = array_shift( $contents );
                $total  -= $estimate_tokens( $removed['parts'] );
            }

            return $contents;
        }

        public function getResponseGenerator( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            return $this->streamContentProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream );
        }

        /**
         * Stream response via Gemini GenerateContent API
         */
        private function streamContentProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );
            $bot_user_id  = absint( $bot_user->id ) * -1;
            $model_id     = $bot_settings['model'];

            $is_group     = $this->is_group_thread( $message->thread_id );
            $sender_names = array();

            if ( $is_group ) {
                $context_limit = ! empty( $bot_settings['groupContextMessages'] ) ? intval( $bot_settings['groupContextMessages'] ) : 20;
            } else {
                $context_limit = ! empty( $bot_settings['contextMessages'] ) ? intval( $bot_settings['contextMessages'] ) : 0;
            }

            $summary_result  = $this->get_thread_messages_with_summary( $message->thread_id, $message->created_at, $context_limit, $bot_user_id, $bot_settings );
            $thread_messages = $summary_result['messages'];
            $summary_context = $summary_result['summary'];

            if ( $is_group ) {
                $sender_names = $this->resolve_sender_names( $thread_messages, $bot_user_id );
            }

            $this->enrich_with_reply_context( $thread_messages, $message, $sender_names );

            // System prompt
            $system_prompt = '';
            if ( ! empty( $bot_settings['instruction'] ) ) {
                $system_prompt = $this->get_bot_instruction( $bot_settings['instruction'], $bot_id, $message->sender_id, $message->thread_id, $message->id );
            }

            if ( $is_group ) {
                $bot_name = get_the_title( $bot_id );
                $system_prompt .= $this->get_group_context_instruction( $sender_names, $bot_user_id, $bot_name );
            }

            if ( $summary_context ) {
                $system_prompt .= "\n\nPrevious conversation summary:\n" . $summary_context;
            }

            // Build limits
            $limits = '';
            $can_generate_images = $bot_settings['imagesGeneration'] === '1' && $this->model_supports_image_generation( $model_id );

            if ( ! $can_generate_images ) {
                $limits .= ' You cannot generate, create, or produce images. If asked to generate an image, explain that you are a text-only assistant and cannot create images. Never output fake tool calls, JSON actions, or pretend to call image generation tools like DALL-E.';
            }

            if ( ! empty( $bot_settings['maxWebSearchCalls'] ) && intval( $bot_settings['maxWebSearchCalls'] ) > 0 ) {
                $limits .= ' You must perform no more than ' . intval( $bot_settings['maxWebSearchCalls'] ) . ' web search(es) per response.';
            }
            if ( $can_generate_images && ! empty( $bot_settings['maxImagesPerResponse'] ) && intval( $bot_settings['maxImagesPerResponse'] ) > 0 ) {
                $limits .= ' You must generate no more than ' . intval( $bot_settings['maxImagesPerResponse'] ) . ' image(s) per response.';
            }

            // Generation config
            $generation_config = [];

            if ( ! empty( $bot_settings['temperature'] ) && is_numeric( $bot_settings['temperature'] ) ) {
                $generation_config['temperature'] = floatval( $bot_settings['temperature'] );
            }

            if ( ! empty( $bot_settings['maxOutputTokens'] ) && intval( $bot_settings['maxOutputTokens'] ) > 0 ) {
                $generation_config['maxOutputTokens'] = intval( $bot_settings['maxOutputTokens'] );
            }

            // Extended thinking (Gemini thinking mode)
            if ( $bot_settings['extendedThinking'] === '1' ) {
                $thinking_config = [ 'thinkingMode' => 'enabled' ];

                if ( ! empty( $bot_settings['thinkingBudget'] ) && intval( $bot_settings['thinkingBudget'] ) > 0 ) {
                    $thinking_config['thinkingBudget'] = intval( $bot_settings['thinkingBudget'] );
                }

                $generation_config['thinkingConfig'] = $thinking_config;
            }

            // Image generation (native — only for models that support it)
            if ( $can_generate_images ) {
                $generation_config['responseModalities'] = [ 'TEXT', 'IMAGE' ];
            }

            // Tools
            $tools = [];
            if ( $bot_settings['webSearch'] === '1' ) {
                $tools[] = [ 'google_search' => new \stdClass() ];
            }

            $client = $this->get_client();

            if ( $ai_message_id > 0 ) {
                Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'in_progress' );
            }

            if ( ! $stream ) {
                $url = 'models/' . urlencode( $model_id ) . ':generateContent?key=' . urlencode( $this->get_api_key() );

                // Build full conversation history
                $contents = $this->build_contents( $thread_messages, $bot_user_id, $bot_settings, $message->id, $sender_names );

                if ( empty( $contents ) ) {
                    yield ['error', 'No messages to process'];
                    return;
                }

                if ( $contents[0]['role'] !== 'user' ) {
                    array_unshift( $contents, [
                        'role'  => 'user',
                        'parts' => [[ 'text' => '...' ]]
                    ] );
                }

                $contents = $this->auto_trim_contents( $contents, $model_id );

                // Ensure first message is from user after trimming (Gemini requirement)
                while ( ! empty( $contents ) && $contents[0]['role'] !== 'user' ) {
                    array_shift( $contents );
                }

                if ( empty( $contents ) ) {
                    yield ['error', 'No messages to process after trimming'];
                    return;
                }

                $params = [ 'contents' => $contents ];

                if ( ! empty( $system_prompt ) || ! empty( $limits ) ) {
                    $params['system_instruction'] = [
                        'parts' => [[ 'text' => $system_prompt . $limits ]]
                    ];
                }

                if ( ! empty( $generation_config ) ) {
                    $params['generationConfig'] = $generation_config;
                }

                if ( ! empty( $tools ) ) {
                    $params['tools'] = $tools;
                }

                try {
                    $response = $client->post( $url, [
                        'json'    => $params,
                        'timeout' => 3600
                    ] );

                    $data = json_decode( $response->getBody()->getContents(), true );

                    $text = '';
                    $usage = [];
                    $web_search_calls = 0;

                    if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
                        foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
                            if ( isset( $part['thought'] ) && $part['thought'] === true ) {
                                continue;
                            }
                            if ( isset( $part['text'] ) ) {
                                $text .= $part['text'];
                            }
                        }
                    }

                    if ( isset( $data['candidates'][0]['groundingMetadata']['searchEntryPoint'] ) ) {
                        $web_search_calls++;
                    }

                    if ( isset( $data['usageMetadata'] ) ) {
                        $usage = $data['usageMetadata'];
                    }

                    if ( isset( $data['promptFeedback']['blockReason'] ) ) {
                        yield ['error', 'Prompt blocked by Gemini: ' . $data['promptFeedback']['blockReason']];
                        return;
                    }

                    $finish_reason = $data['candidates'][0]['finishReason'] ?? '';
                    if ( $finish_reason === 'SAFETY' ) {
                        yield ['error', 'Response blocked by Gemini safety filters'];
                        return;
                    }

                    $meta = [
                        'model'    => $model_id,
                        'provider' => 'gemini',
                    ];

                    if ( ! empty( $usage ) ) {
                        $meta['usage'] = $usage;
                    }

                    if ( $web_search_calls > 0 ) {
                        $meta['web_search_calls'] = $web_search_calls;
                    }

                    if ( ! empty( $text ) ) {
                        yield $text;
                    }
                    yield ['finish', $meta];
                } catch ( GuzzleException $e ) {
                    yield ['error', $this->parse_gemini_error( $e )];
                } catch ( \Throwable $e ) {
                    yield ['error', $e->getMessage()];
                }
                return;
            }

            $url = 'models/' . urlencode( $model_id ) . ':streamGenerateContent?alt=sse&key=' . urlencode( $this->get_api_key() );

            $model                   = $model_id;
            $usage                   = [];
            $web_search_calls        = 0;
            $images_generated        = [];
            $attachment_meta         = [];
            $max_images              = ! empty( $bot_settings['maxImagesPerResponse'] ) ? intval( $bot_settings['maxImagesPerResponse'] ) : 0;
            $thought_parts_collected = [];

            // Build full conversation history (with stored thinking parts + signatures)
            $contents = $this->build_contents( $thread_messages, $bot_user_id, $bot_settings, $message->id, $sender_names );

            if ( empty( $contents ) ) {
                yield ['error', 'No messages to process'];
                return;
            }

            // Ensure first message is from user (Gemini requirement)
            if ( $contents[0]['role'] !== 'user' ) {
                array_unshift( $contents, [
                    'role'  => 'user',
                    'parts' => [[ 'text' => '...' ]]
                ] );
            }

            // Auto-trim if context exceeds model limit
            $contents = $this->auto_trim_contents( $contents, $model_id );

            // Ensure first message is from user after trimming (Gemini requirement)
            while ( ! empty( $contents ) && $contents[0]['role'] !== 'user' ) {
                array_shift( $contents );
            }

            if ( empty( $contents ) ) {
                yield ['error', 'No messages to process after trimming'];
                return;
            }

            $params = [ 'contents' => $contents ];

            if ( ! empty( $system_prompt ) || ! empty( $limits ) ) {
                $params['system_instruction'] = [
                    'parts' => [[ 'text' => $system_prompt . $limits ]]
                ];
            }

            if ( ! empty( $generation_config ) ) {
                $params['generationConfig'] = $generation_config;
            }

            if ( ! empty( $tools ) ) {
                $params['tools'] = $tools;
            }

            if ( defined( 'BM_DEBUG' ) ) {
                $log_params = $params;
                unset( $log_params['contents'] );
                file_put_contents( ABSPATH . 'gemini.log', time() . ' - params - ' . print_r( $log_params, true ) . "\n", FILE_APPEND | LOCK_EX );
            }

            try {
                $response = $client->post( $url, [
                    'json'    => $params,
                    'stream'  => true,
                    'timeout' => 3600
                ] );

                $body   = $response->getBody();
                $buffer = '';

                while ( ! $body->eof() ) {
                    $chunk = $body->read( 1024 );

                    if ( $chunk === '' ) {
                        continue;
                    }

                    $buffer .= $chunk;

                    while ( ( $pos = strpos( $buffer, "\n" ) ) !== false ) {
                        $line   = trim( substr( $buffer, 0, $pos ) );
                        $buffer = substr( $buffer, $pos + 1 );

                        if ( $line === '' ) {
                            continue;
                        }

                        if ( strpos( $line, 'data: ' ) === 0 ) {
                            $json = substr( $line, 6 );
                            $data = json_decode( $json, true );

                            if ( ! is_array( $data ) ) {
                                continue;
                            }

                            if ( defined( 'BM_DEBUG' ) ) {
                                file_put_contents( ABSPATH . 'gemini.log', time() . ' - chunk' . "\n", FILE_APPEND | LOCK_EX );
                            }

                            // Extract text, images, and thinking parts from candidates
                            if ( isset( $data['candidates'][0]['content']['parts'] ) ) {
                                foreach ( $data['candidates'][0]['content']['parts'] as $part ) {
                                    // Capture thinking parts (with thoughtSignature) instead of skipping
                                    if ( isset( $part['thought'] ) && $part['thought'] === true ) {
                                        $thought_entry = [ 'text' => $part['text'] ?? '' ];
                                        if ( isset( $part['thoughtSignature'] ) ) {
                                            $thought_entry['thoughtSignature'] = $part['thoughtSignature'];
                                        }
                                        $thought_parts_collected[] = $thought_entry;
                                        continue;
                                    }
                                    if ( isset( $part['text'] ) ) {
                                        yield $part['text'];
                                    }
                                    // Handle inline image data
                                    if ( isset( $part['inlineData']['mimeType'] ) && str_starts_with( $part['inlineData']['mimeType'], 'image/' ) ) {
                                        if ( $max_images > 0 && count( $images_generated ) >= $max_images ) {
                                            continue;
                                        }

                                        $mime = $part['inlineData']['mimeType'];
                                        $ext_map = [ 'image/png' => 'png', 'image/jpeg' => 'jpeg', 'image/webp' => 'webp', 'image/gif' => 'gif' ];
                                        $ext = $ext_map[ $mime ] ?? 'png';

                                        $generated_image = [
                                            'model'  => $model_id,
                                            'format' => $ext,
                                        ];

                                        $fileData = base64_decode( $part['inlineData']['data'] );
                                        $name = Better_Messages()->functions->random_string( 30 );
                                        $temp_dir = sys_get_temp_dir();
                                        $temp_path = trailingslashit( $temp_dir ) . $name;

                                        try {
                                            file_put_contents( $temp_path, $fileData );

                                            $file = [
                                                'name'     => $name . '.' . $ext,
                                                'type'     => $mime,
                                                'tmp_name' => $temp_path,
                                                'error'    => 0,
                                                'size'     => filesize( $temp_path )
                                            ];

                                            $attachment_id = Better_Messages()->files->save_file( $file, $ai_message_id, absint( $bot_user->id ) * -1 );

                                            if ( ! is_wp_error( $attachment_id ) ) {
                                                $generated_image['attachment_id'] = $attachment_id;
                                                add_post_meta( $attachment_id, 'bm_ai_generated_image', 1, true );
                                                $attachment_meta[ $attachment_id ] = wp_get_attachment_url( $attachment_id );
                                            }
                                        } catch ( \Throwable $e ) {
                                            // Skip failed image
                                        } finally {
                                            if ( file_exists( $temp_path ) ) {
                                                @unlink( $temp_path );
                                            }
                                        }

                                        $images_generated[] = $generated_image;
                                    }
                                }
                            }

                            // Track grounding (web search) calls
                            if ( isset( $data['candidates'][0]['groundingMetadata']['searchEntryPoint'] ) ) {
                                $web_search_calls++;
                            }

                            // Track usage
                            if ( isset( $data['usageMetadata'] ) ) {
                                $usage = $data['usageMetadata'];
                            }

                            // Check for model info
                            if ( isset( $data['modelVersion'] ) ) {
                                $model = $data['modelVersion'];
                            }

                            // Check for finish
                            if ( isset( $data['candidates'][0]['finishReason'] ) ) {
                                $finish_reason = $data['candidates'][0]['finishReason'];

                                if ( $finish_reason === 'SAFETY' ) {
                                    yield ['error', 'Response blocked by Gemini safety filters'];
                                    return;
                                }

                                if ( in_array( $finish_reason, [ 'STOP', 'MAX_TOKENS', 'RECITATION' ] ) ) {
                                    // Last chunk before finish
                                }
                            }

                            // Check for errors in promptFeedback
                            if ( isset( $data['promptFeedback']['blockReason'] ) ) {
                                $reason = $data['promptFeedback']['blockReason'];
                                yield ['error', 'Prompt blocked by Gemini: ' . $reason];
                                return;
                            }
                        }
                    }
                }

                // Save attachment meta if any images were generated
                if ( ! empty( $attachment_meta ) ) {
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'attachments', $attachment_meta );
                }

                // Stream ended, yield finish
                $meta = [
                    'model'    => $model,
                    'provider' => 'gemini',
                ];

                if ( ! empty( $usage ) ) {
                    $meta['usage'] = $usage;
                }

                if ( $web_search_calls > 0 ) {
                    $meta['web_search_calls'] = $web_search_calls;
                }

                if ( ! empty( $images_generated ) ) {
                    $meta['images_generated'] = $images_generated;
                }

                if ( ! empty( $thought_parts_collected ) ) {
                    $meta['gemini_thought_parts'] = $thought_parts_collected;
                }

                yield ['finish', $meta];

            } catch ( GuzzleException $e ) {
                $error = $this->parse_gemini_error( $e );

                if ( defined( 'BM_DEBUG' ) ) {
                    file_put_contents( ABSPATH . 'gemini.log', time() . ' - GuzzleException - ' . $error . "\n", FILE_APPEND | LOCK_EX );
                }

                yield ['error', $error];
            } catch ( \Throwable $e ) {
                if ( defined( 'BM_DEBUG' ) ) {
                    file_put_contents( ABSPATH . 'gemini.log', time() . ' - Throwable - ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX );
                }

                yield ['error', $e->getMessage()];
            }
        }

        /**
         * Store Gemini-specific response data (thought parts with signatures) as separate meta
         * so they can be included in subsequent conversation history.
         */
        public function on_response_completed( $ai_message_id, $message_id, $meta )
        {
            if ( ! empty( $meta['gemini_thought_parts'] ) ) {
                Better_Messages()->functions->update_message_meta( $ai_message_id, 'gemini_thought_parts', $meta['gemini_thought_parts'] );
            }
        }
    }
}
