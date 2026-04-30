<?php

use BetterMessages\GuzzleHttp\Exception\GuzzleException;

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Anthropic_API' ) ) {
    class Better_Messages_Anthropic_API extends Better_Messages_AI_Provider
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Anthropic_API();
            }

            return $instance;
        }

        public function __construct()
        {
            $this->api_key = Better_Messages()->settings['anthropicApiKey'] ?? '';
        }

        public function get_provider_id()
        {
            return 'anthropic';
        }

        public function get_provider_name()
        {
            return 'Anthropic';
        }

        public function get_supported_features()
        {
            return [ 'images', 'files', 'temperature', 'maxOutputTokens', 'extendedThinking', 'webSearch' ];
        }

        private function get_client()
        {
            return $this->get_guzzle_client( 'https://api.anthropic.com/v1/', [
                'x-api-key'          => $this->get_api_key(),
                'anthropic-version'  => '2023-06-01',
            ] );
        }

        public function generateSummary( $system_prompt, $user_content, $model = '', $max_tokens = 3000 )
        {
            $client = $this->get_client();

            $params = [
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => [
                    [ 'type' => 'text', 'text' => $system_prompt ]
                ],
                'messages'   => [
                    [ 'role' => 'user', 'content' => $user_content ]
                ],
            ];

            try {
                $response = $client->post( 'messages', [
                    'json'    => $params,
                    'timeout' => 120
                ] );

                $data = json_decode( $response->getBody()->getContents(), true );

                if ( isset( $data['content'] ) ) {
                    $text = '';
                    $usage = [];

                    foreach ( $data['content'] as $block ) {
                        if ( isset( $block['type'] ) && $block['type'] === 'text' ) {
                            $text .= $block['text'];
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
                'model'      => $model,
                'max_tokens' => $max_tokens,
                'system'     => [
                    [ 'type' => 'text', 'text' => $system_prompt ]
                ],
                'messages'   => [
                    [ 'role' => 'user', 'content' => $user_content ]
                ],
            ];

            if ( ! empty( $options['webSearch'] ) ) {
                $params['tools'] = [ [
                    'type' => 'web_search_20250305',
                    'name' => 'web_search',
                ] ];
            }

            try {
                $response = $client->post( 'messages', [
                    'json'    => $params,
                    'timeout' => 120
                ] );

                $data = json_decode( $response->getBody()->getContents(), true );

                if ( isset( $data['content'] ) ) {
                    $text = '';
                    $usage = [];

                    foreach ( $data['content'] as $block ) {
                        if ( isset( $block['type'] ) && $block['type'] === 'text' ) {
                            $text .= $block['text'];
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
                $client->request( 'GET', 'models?limit=1' );
                delete_option( 'better_messages_anthropic_error' );
            } catch ( GuzzleException $e ) {
                $error = $this->parse_guzzle_error( $e );
                update_option( 'better_messages_anthropic_error', $error, false );
            }
        }

        /**
         * Fetch models from Anthropic API, cached for 24 hours
         */
        private function get_all_models_cached()
        {
            $cached = get_transient( 'bm_anthropic_models' );

            if ( $cached !== false ) {
                return $cached;
            }

            $client = $this->get_client();

            try {
                $all_models = [];
                $has_more   = true;
                $after_id   = null;

                while ( $has_more ) {
                    $url = 'models?limit=100';
                    if ( $after_id ) {
                        $url .= '&after_id=' . urlencode( $after_id );
                    }

                    $response = $client->request( 'GET', $url );
                    $data     = json_decode( $response->getBody(), true );

                    if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
                        foreach ( $data['data'] as $model ) {
                            if ( isset( $model['id'] ) ) {
                                $all_models[] = $model['id'];
                            }
                        }
                    }

                    $has_more = ! empty( $data['has_more'] );
                    if ( $has_more && ! empty( $data['last_id'] ) ) {
                        $after_id = $data['last_id'];
                    } else {
                        $has_more = false;
                    }
                }

                sort( $all_models );
                set_transient( 'bm_anthropic_models', $all_models, DAY_IN_SECONDS );

                return $all_models;
            } catch ( GuzzleException $e ) {
                return new \WP_Error( 'anthropic_error', $this->parse_guzzle_error( $e ) );
            }
        }

        public function get_models()
        {
            $all = $this->get_all_models_cached();

            if ( is_wp_error( $all ) ) {
                return $all;
            }

            $models = [];

            foreach ( $all as $model_id ) {
                if ( str_contains( $model_id, 'claude' ) ) {
                    $models[] = $model_id;
                }
            }

            sort( $models );

            return $models;
        }

        /**
         * Get input token limit for an Anthropic model.
         * All current Claude models support 200K context.
         */
        private function get_model_input_limit( $model_id )
        {
            return 200000;
        }

        /**
         * Auto-trim Anthropic messages array if estimated token count exceeds model input limit.
         * Removes oldest messages first, always keeps at least the last 2 entries.
         */
        private function auto_trim_messages( $messages, $model_id )
        {
            $limit           = $this->get_model_input_limit( $model_id );
            $effective_limit = (int) ( $limit * 0.85 ); // 15% buffer for system prompt + overhead

            $estimate_tokens = function ( $content ) {
                $tokens = 0;
                if ( is_string( $content ) ) {
                    return (int) ( mb_strlen( $content ) / 4 );
                }
                if ( is_array( $content ) ) {
                    foreach ( $content as $block ) {
                        if ( isset( $block['text'] ) ) {
                            $tokens += (int) ( mb_strlen( $block['text'] ) / 4 );
                        }
                        if ( isset( $block['source']['data'] ) ) {
                            $tokens += 1600; // rough per-image/document estimate
                        }
                    }
                }
                return $tokens;
            };

            $total = 0;
            foreach ( $messages as $msg ) {
                $total += $estimate_tokens( $msg['content'] );
            }

            while ( $total > $effective_limit && count( $messages ) > 2 ) {
                $removed = array_shift( $messages );
                $total  -= $estimate_tokens( $removed['content'] );
            }

            return $messages;
        }

        public function getResponseGenerator( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            return $this->messagesProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream );
        }

        /**
         * Stream response via Anthropic Messages API
         */
        private function messagesProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );
            $bot_user_id  = absint( $bot_user->id ) * -1;

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

            $request_messages = [];
            $system_prompt    = '';

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

            foreach ( $thread_messages as $_message ) {
                $is_error = Better_Messages()->functions->get_message_meta( $_message->id, 'ai_response_error' );
                if ( $is_error ) continue;

                $message_text = $this->clean_stored_message( $_message->message );
                if ( empty( trim( $message_text ) ) && $_message->id !== $message->id ) {
                    // Don't skip if message has attachments that the bot can process
                    $has_processable_attachments = false;
                    if ( $bot_settings['images'] || $bot_settings['files'] ) {
                        $check_attachments = Better_Messages()->functions->get_message_meta( $_message->id, 'attachments', true );
                        $has_processable_attachments = ! empty( $check_attachments );
                    }
                    if ( ! $has_processable_attachments ) continue;
                }

                $content = [];
                $role    = (int) $_message->sender_id === (int) $bot_user_id ? 'assistant' : 'user';

                // In group context: strip mention HTML and prefix with sender name
                if ( $is_group && $role === 'user' && ! empty( $message_text ) ) {
                    $message_text = $this->strip_mention_html( $message_text, $sender_names );
                    $sid = (int) $_message->sender_id;
                    $sender_label = isset( $sender_names[ $sid ] ) ? $sender_names[ $sid ] : 'User #' . abs( $sid );
                    $message_text = '[' . $sender_label . ']: ' . $message_text;
                }

                if ( $role === 'user' && ( $bot_settings['images'] || $bot_settings['files'] ) ) {
                    $attachments = Better_Messages()->functions->get_message_meta( $_message->id, 'attachments', true );

                    if ( ! empty( $attachments ) ) {
                        foreach ( $attachments as $id => $url ) {
                            $file = get_attached_file( $id );

                            if ( $file && file_exists( $file ) && filesize( $file ) <= 20 * 1024 * 1024 ) {
                                $ext = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );

                                if ( $bot_settings['images'] && in_array( $ext, [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ] ) ) {
                                    $media_type = 'image/' . ( $ext === 'jpg' ? 'jpeg' : $ext );
                                    $base64     = base64_encode( file_get_contents( $file ) );

                                    $content[] = [
                                        'type'   => 'image',
                                        'source' => [
                                            'type'       => 'base64',
                                            'media_type' => $media_type,
                                            'data'       => $base64
                                        ]
                                    ];
                                } else if ( $bot_settings['files'] && $ext === 'pdf' ) {
                                    $base64 = base64_encode( file_get_contents( $file ) );

                                    $content[] = [
                                        'type'   => 'document',
                                        'source' => [
                                            'type'       => 'base64',
                                            'media_type' => 'application/pdf',
                                            'data'       => $base64
                                        ]
                                    ];
                                }
                            }
                        }
                    }
                }

                if ( ! empty( $message_text ) ) {
                    $content[] = [
                        'type' => 'text',
                        'text' => $message_text
                    ];
                }

                if ( empty( $content ) ) continue;

                // Claude requires alternating user/assistant messages
                // Merge consecutive same-role messages
                $last_index = count( $request_messages ) - 1;
                if ( $last_index >= 0 && $request_messages[ $last_index ]['role'] === $role ) {
                    $existing_content = $request_messages[ $last_index ]['content'];
                    if ( is_string( $existing_content ) ) {
                        $existing_content = [ [ 'type' => 'text', 'text' => $existing_content ] ];
                    }
                    $request_messages[ $last_index ]['content'] = array_merge( $existing_content, $content );
                } else {
                    $request_messages[] = [
                        'role'    => $role,
                        'content' => $content,
                    ];
                }
            }

            if ( empty( $request_messages ) ) {
                yield ['error', 'No messages to process'];
                return;
            }

            // Ensure first message is from user (Claude requirement)
            if ( $request_messages[0]['role'] !== 'user' ) {
                array_unshift( $request_messages, [
                    'role'    => 'user',
                    'content' => [[ 'type' => 'text', 'text' => '...' ]]
                ] );
            }

            // Auto-trim if context exceeds model limit
            $request_messages = $this->auto_trim_messages( $request_messages, $bot_settings['model'] );

            // Ensure first message is from user after trimming (Claude requirement)
            while ( ! empty( $request_messages ) && $request_messages[0]['role'] !== 'user' ) {
                array_shift( $request_messages );
            }

            if ( empty( $request_messages ) ) {
                yield ['error', 'No messages to process after trimming'];
                return;
            }

            $params = [
                'model'         => $bot_settings['model'],
                'messages'      => $request_messages,
            ];

            if ( $stream ) {
                $params['stream'] = true;
            }

            if ( ! empty( $system_prompt ) ) {
                $params['system'] = [
                    [
                        'type'          => 'text',
                        'text'          => $system_prompt,
                        'cache_control' => [ 'type' => 'ephemeral' ],
                    ]
                ];
            }

            $max_tokens = 4096;
            if ( ! empty( $bot_settings['maxOutputTokens'] ) && intval( $bot_settings['maxOutputTokens'] ) > 0 ) {
                $max_tokens = intval( $bot_settings['maxOutputTokens'] );
            }
            $params['max_tokens'] = $max_tokens;

            if ( ! empty( $bot_settings['temperature'] ) && is_numeric( $bot_settings['temperature'] ) ) {
                $params['temperature'] = floatval( $bot_settings['temperature'] );
            }

            // Extended thinking
            if ( ! empty( $bot_settings['extendedThinking'] ) && $bot_settings['extendedThinking'] === '1' ) {
                $thinking_budget = 10000;
                if ( ! empty( $bot_settings['thinkingBudget'] ) && intval( $bot_settings['thinkingBudget'] ) > 0 ) {
                    $thinking_budget = intval( $bot_settings['thinkingBudget'] );
                }

                $params['thinking'] = [
                    'type'          => 'enabled',
                    'budget_tokens' => $thinking_budget
                ];

                // Extended thinking requires max_tokens to be larger than budget
                if ( $params['max_tokens'] <= $thinking_budget ) {
                    $params['max_tokens'] = $thinking_budget + 4096;
                }

                // Temperature must not be set when using extended thinking
                unset( $params['temperature'] );
            }

            // Web search
            if ( ! empty( $bot_settings['webSearch'] ) && $bot_settings['webSearch'] === '1' ) {
                $params['tools'] = [ [
                    'type' => 'web_search_20250305',
                    'name' => 'web_search',
                ] ];
            }

            $client = $this->get_client();

            if ( $ai_message_id > 0 ) {
                Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'in_progress' );
            }

            if ( defined( 'BM_DEBUG' ) ) {
                $log_params = $params;
                unset( $log_params['messages'] );
                file_put_contents( ABSPATH . 'anthropic.log', time() . ' - params - ' . print_r( $log_params, true ) . "\n", FILE_APPEND | LOCK_EX );
            }

            $response_id = '';
            $model       = '';
            $usage       = [];

            try {
                if ( ! $stream ) {
                    $response = $client->post( 'messages', [
                        'json'    => $params,
                        'timeout' => 3600
                    ] );

                    $data = json_decode( $response->getBody()->getContents(), true );

                    $text = '';
                    if ( isset( $data['content'] ) ) {
                        foreach ( $data['content'] as $block ) {
                            if ( isset( $block['type'] ) && $block['type'] === 'text' ) {
                                $text .= $block['text'];
                            }
                        }
                    }

                    $meta = [
                        'response_id' => $data['id'] ?? '',
                        'model'       => $data['model'] ?? $bot_settings['model'],
                        'provider'    => 'anthropic',
                    ];

                    if ( isset( $data['usage'] ) ) {
                        $meta['usage'] = $data['usage'];
                    }

                    if ( ! empty( $text ) ) {
                        yield $text;
                    }
                    yield ['finish', $meta];
                    return;
                }

                $response = $client->post( 'messages', [
                    'json'    => $params,
                    'stream'  => true,
                    'timeout' => 3600
                ] );

                $body   = $response->getBody();
                $buffer = '';
                $current_block_type = '';

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

                            if ( ! is_array( $data ) || ! isset( $data['type'] ) ) {
                                continue;
                            }

                            if ( defined( 'BM_DEBUG' ) ) {
                                $log_line = $data['type'];
                                if ( $data['type'] === 'message_start' && isset( $data['message']['usage'] ) ) {
                                    $log_line .= ' usage=' . json_encode( $data['message']['usage'] );
                                }
                                if ( $data['type'] === 'message_delta' && isset( $data['usage'] ) ) {
                                    $log_line .= ' usage=' . json_encode( $data['usage'] );
                                }
                                file_put_contents( ABSPATH . 'anthropic.log', time() . ' - ' . $log_line . "\n", FILE_APPEND | LOCK_EX );
                            }

                            switch ( $data['type'] ) {
                                case 'message_start':
                                    if ( isset( $data['message']['id'] ) ) {
                                        $response_id = $data['message']['id'];
                                    }
                                    if ( isset( $data['message']['model'] ) ) {
                                        $model = $data['message']['model'];
                                    }
                                    // Capture input token usage (includes cache tokens)
                                    if ( isset( $data['message']['usage'] ) ) {
                                        $usage = array_merge( $usage, $data['message']['usage'] );
                                    }
                                    yield ['tick'];
                                    break;

                                case 'content_block_start':
                                    $current_block_type = $data['content_block']['type'] ?? '';
                                    yield ['tick'];
                                    break;

                                case 'content_block_delta':
                                    $delta = $data['delta'] ?? [];

                                    if ( isset( $delta['type'] ) && $delta['type'] === 'text_delta' && isset( $delta['text'] ) ) {
                                        yield $delta['text'];
                                    }
                                    // Skip thinking_delta - we don't output thinking to user
                                    break;

                                case 'content_block_stop':
                                    $current_block_type = '';
                                    yield ['tick'];
                                    break;

                                case 'message_delta':
                                    // Merge output token usage
                                    if ( isset( $data['usage'] ) ) {
                                        $usage = array_merge( $usage, $data['usage'] );
                                    }
                                    yield ['tick'];
                                    break;

                                case 'message_stop':
                                    $meta = [
                                        'response_id' => $response_id,
                                        'model'       => $model,
                                        'provider'    => 'anthropic',
                                    ];

                                    if ( ! empty( $usage ) ) {
                                        $meta['usage'] = $usage;
                                    }

                                    yield ['finish', $meta];
                                    break;

                                case 'ping':
                                    yield ['tick'];
                                    break;

                                case 'error':
                                    $error_msg = $data['error']['message'] ?? 'Unknown Anthropic error';
                                    yield ['error', $error_msg];
                                    break;

                                default:
                                    yield ['tick'];
                                    break;
                            }
                        }
                    }
                }
            } catch ( GuzzleException $e ) {
                $error = $this->parse_guzzle_error( $e );

                if ( defined( 'BM_DEBUG' ) ) {
                    file_put_contents( ABSPATH . 'anthropic.log', time() . ' - GuzzleException - ' . $error . "\n", FILE_APPEND | LOCK_EX );
                }

                yield ['error', $error];
            } catch ( \Throwable $e ) {
                if ( defined( 'BM_DEBUG' ) ) {
                    file_put_contents( ABSPATH . 'anthropic.log', time() . ' - Throwable - ' . $e->getMessage() . "\n", FILE_APPEND | LOCK_EX );
                }

                yield ['error', $e->getMessage()];
            }
        }
    }
}
