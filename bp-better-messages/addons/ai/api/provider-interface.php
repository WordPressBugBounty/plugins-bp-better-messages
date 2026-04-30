<?php

use BetterMessages\React\EventLoop\Loop;
use BetterMessages\React\Http\Browser;
use BetterMessages\React\Stream\ThroughStream;

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_AI_Provider' ) ) {
    abstract class Better_Messages_AI_Provider
    {
        protected $api_key = '';
        protected $bot_api_key = '';

        /**
         * @return string Provider ID ('openai', 'anthropic', 'gemini')
         */
        abstract public function get_provider_id();

        /**
         * @return string Human-readable provider name
         */
        abstract public function get_provider_name();

        /**
         * Validate the current API key
         */
        abstract public function check_api_key();

        /**
         * @return string[]|\WP_Error Array of model IDs
         */
        abstract public function get_models();

        /**
         * @return string[] Array of supported feature IDs
         */
        abstract public function get_supported_features();

        /**
         * @return \Generator|null Generator yielding response data, or null if handled directly (e.g., audio)
         *
         * Generator yield contract:
         *   string           - Text content delta
         *   ['tick']         - Keep-alive (no-op)
         *   ['finish', $meta] - Response complete with metadata array
         *   ['error', $msg]  - Error with message string
         */
        abstract public function getResponseGenerator( $bot_id, $bot_user, $message, $ai_message_id, $stream = true );

        public function get_api_key()
        {
            if ( ! empty( $this->bot_api_key ) ) {
                return $this->bot_api_key;
            }
            return $this->api_key;
        }

        public function set_api_key( $key )
        {
            $this->api_key = $key;
        }

        public function set_bot_api_key( $key )
        {
            $this->bot_api_key = $key;
        }

        public function supports( $feature )
        {
            return in_array( $feature, $this->get_supported_features() );
        }

        /**
         * @return string[]|\WP_Error
         */
        public function get_transcription_models()
        {
            return [];
        }

        /**
         * @return array|\WP_Error
         */
        public function moderate( $text = '', $image_data_uris = [] )
        {
            return new \WP_Error( 'not_supported', 'Moderation not supported by this provider' );
        }

        /**
         * @return string|\WP_Error
         */
        public function transcribe_audio( $attachment_id )
        {
            return new \WP_Error( 'not_supported', 'Transcription not supported by this provider' );
        }

        /**
         * Generate a non-streaming summary. Override in providers.
         *
         * @param string $system_prompt System instruction
         * @param string $user_content  Messages text to summarize
         * @param string $model         Model ID (empty = provider default)
         * @param int    $max_tokens    Max output tokens
         * @return string|\WP_Error     Summary text or error
         */
        public function generateSummary( $system_prompt, $user_content, $model = '', $max_tokens = 3000 )
        {
            return new \WP_Error( 'not_supported', 'Summarization not supported by this provider' );
        }

        /**
         * Generate a non-streaming digest. By default delegates to generateSummary().
         * Override in providers that support web search tools.
         *
         * @param string $system_prompt System instruction
         * @param string $user_content  Digest topic/instructions
         * @param string $model         Model ID
         * @param int    $max_tokens    Max output tokens
         * @param array  $options       Additional options (webSearch, webSearchContextSize)
         * @return array|\WP_Error      ['text' => ..., 'usage' => ...] or error
         */
        public function generateDigest( $system_prompt, $user_content, $model = '', $max_tokens = 3000, $options = [] )
        {
            return $this->generateSummary( $system_prompt, $user_content, $model, $max_tokens );
        }

        /**
         * Cancel a response via provider API. Override in providers that support it.
         */
        public function cancel_response_api( $response_id )
        {
            return true;
        }

        /**
         * Handle message deletion. Override for providers with conversation state.
         */
        public function on_message_deleted( $message_id, $thread_id, $message )
        {
        }

        /**
         * Called after response is fully completed and saved. Override for provider-specific meta.
         */
        public function on_response_completed( $ai_message_id, $message_id, $meta )
        {
        }

        /**
         * Background job to recover stalled responses. Override for providers that support it.
         */
        public function ensureResponseCompletionJob()
        {
        }

        /**
         * Check if an error message indicates a transient/retryable failure
         */
        protected function is_transient_error( $error_message )
        {
            $transient_patterns = [
                'high demand',
                'rate limit',
                'rate_limit',
                'too many requests',
                'overloaded',
                'capacity',
                'temporarily unavailable',
                'server error',
                'internal error',
                'service unavailable',
                'timeout',
                'timed out',
                'connection reset',
                'connection refused',
                '529',
                '503',
                '502',
                '500',
                '429',
            ];

            $lower = strtolower( $error_message );

            foreach ( $transient_patterns as $pattern ) {
                if ( strpos( $lower, $pattern ) !== false ) {
                    return true;
                }
            }

            return false;
        }

        /**
         * Convert a raw API error into a user-friendly message
         */
        protected function get_user_friendly_error( $error_message )
        {
            $lower = strtolower( $error_message );

            if ( strpos( $lower, 'rate limit' ) !== false || strpos( $lower, 'rate_limit' ) !== false || strpos( $lower, 'too many requests' ) !== false || strpos( $lower, '429' ) !== false ) {
                $friendly = __( 'The AI service is currently busy. Please try again in a moment.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'high demand' ) !== false || strpos( $lower, 'overloaded' ) !== false || strpos( $lower, 'capacity' ) !== false || strpos( $lower, '529' ) !== false ) {
                $friendly = __( 'The AI service is experiencing high demand. Please try again later.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'timeout' ) !== false || strpos( $lower, 'timed out' ) !== false ) {
                $friendly = __( 'The AI service took too long to respond. Please try again.', 'bp-better-messages' );
            } elseif ( strpos( $lower, '503' ) !== false || strpos( $lower, '502' ) !== false || strpos( $lower, '500' ) !== false || strpos( $lower, 'server error' ) !== false || strpos( $lower, 'internal error' ) !== false || strpos( $lower, 'service unavailable' ) !== false || strpos( $lower, 'temporarily unavailable' ) !== false ) {
                $friendly = __( 'The AI service is temporarily unavailable. Please try again later.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'invalid' ) !== false && strpos( $lower, 'key' ) !== false ) {
                $friendly = __( 'There is an issue with the AI configuration. Please contact the site administrator.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'quota' ) !== false || strpos( $lower, 'billing' ) !== false || strpos( $lower, 'insufficient' ) !== false || strpos( $lower, 'credit' ) !== false || strpos( $lower, 'balance' ) !== false ) {
                $friendly = __( 'The AI service quota has been exceeded. Please contact the site administrator.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'safety' ) !== false || strpos( $lower, 'blocked' ) !== false || strpos( $lower, 'content_filter' ) !== false ) {
                $friendly = __( 'The message could not be processed due to content restrictions.', 'bp-better-messages' );
            } else {
                $friendly = __( 'Something went wrong while generating a response. Please try again.', 'bp-better-messages' );
            }

            if ( defined( 'BM_DEBUG' ) ) {
                error_log( 'BM AI error [' . $this->get_provider_id() . '] raw: ' . $error_message . ' → friendly: ' . $friendly );
            }

            return $friendly;
        }

        /**
         * Wrap getResponseGenerator with automatic retry for transient errors.
         * Retries up to 3 times with increasing delays (5s, 15s, 30s).
         * Only retries errors that occur before any content is streamed (connection-level failures).
         */
        protected function getResponseGeneratorWithRetry( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            $max_retries  = 3;
            $retry_delays = [ 5, 15, 30 ];

            for ( $attempt = 0; $attempt <= $max_retries; $attempt++ ) {
                if ( $attempt > 0 ) {
                    sleep( $retry_delays[ $attempt - 1 ] ?? 30 );
                }

                $gen = $this->getResponseGenerator( $bot_id, $bot_user, $message, $ai_message_id, $stream );

                if ( $gen === null ) {
                    return null;
                }

                if ( ! $gen->valid() ) {
                    return $gen;
                }

                $first = $gen->current();

                // If first yield is a retryable error, try again
                if ( is_array( $first ) && $first[0] === 'error' ) {
                    $is_transient = $this->is_transient_error( $first[1] );

                    if ( defined( 'BM_DEBUG' ) ) {
                        error_log( 'BM AI generator error [' . $this->get_provider_id() . '] attempt ' . ( $attempt + 1 ) . ': ' . $first[1] . ( $is_transient ? ' (transient)' : ' (permanent)' ) );
                    }

                    if ( $attempt < $max_retries && $is_transient ) {
                        continue;
                    }
                }

                // Return a generator that re-emits the already-consumed first value, then continues
                return ( function () use ( $first, $gen ) {
                    yield $first;
                    $gen->next();
                    while ( $gen->valid() ) {
                        yield $gen->current();
                        $gen->next();
                    }
                } )();
            }

            // All retries exhausted — return the last error as a generator
            return ( function () use ( $first ) {
                yield $first;
            } )();
        }

        /**
         * Apply the bot system-prompt filter (also fires the deprecated alias).
         */
        protected function get_bot_instruction( $instruction, $bot_id, $sender_id, $thread_id, $message_id )
        {
            $instruction = apply_filters( 'better_messages_ai_bot_instruction', $instruction, $bot_id, $sender_id, $thread_id, $message_id );

            return apply_filters_deprecated(
                'better_messages_open_ai_bot_instruction',
                [ $instruction, $bot_id, $sender_id ],
                '2.15.0',
                'better_messages_ai_bot_instruction'
            );
        }

        /**
         * Create a Guzzle HTTP client
         */
        protected function get_guzzle_client( $base_uri, $headers = [] )
        {
            $config = [
                'base_uri' => $base_uri,
                'headers'  => array_merge( [
                    'Content-Type' => 'application/json',
                ], $headers )
            ];

            return new \BetterMessages\GuzzleHttp\Client( $config );
        }

        /**
         * Extract error message from a Guzzle exception
         */
        protected function parse_guzzle_error( $e )
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
         * Get thread messages from database for building conversation history.
         * When $limit > 0, returns only the most recent $limit messages (in chronological order).
         */
        protected function get_thread_messages( $thread_id, $up_to_created_at, $limit = 0, $exclude_welcome = false )
        {
            global $wpdb;

            $exclude_join  = '';
            $exclude_where = '';
            if ( $exclude_welcome ) {
                $exclude_join  = "LEFT JOIN `" . bm_get_table('meta') . "` welcome_meta ON welcome_meta.bm_message_id = m.id AND welcome_meta.meta_key = 'ai_welcome_message'";
                $exclude_where = "AND welcome_meta.meta_id IS NULL";
            }

            if ( $limit > 0 ) {
                $results = $wpdb->get_results( $wpdb->prepare(
                    "SELECT m.id, m.sender_id, m.message
                    FROM `" . bm_get_table('messages') . "` m
                    $exclude_join
                    WHERE m.thread_id = %d
                    AND m.created_at <= %d
                    $exclude_where
                    ORDER BY m.created_at DESC
                    LIMIT %d",
                    $thread_id, $up_to_created_at, $limit
                ) );

                return array_reverse( $results );
            }

            return $wpdb->get_results( $wpdb->prepare(
                "SELECT m.id, m.sender_id, m.message
                FROM `" . bm_get_table('messages') . "` m
                $exclude_join
                WHERE m.thread_id = %d
                AND m.created_at <= %d
                $exclude_where
                ORDER BY m.created_at ASC",
                $thread_id, $up_to_created_at
            ) );
        }

        /**
         * Get thread messages with summary context compression.
         * If useSummaryContext is enabled and a summary exists, loads only messages after the summary.
         *
         * @return array [ 'summary' => string|null, 'messages' => array ]
         */
        protected function get_thread_messages_with_summary( $thread_id, $up_to_created_at, $limit, $bot_user_id, $bot_settings )
        {
            $exclude_welcome = isset( $bot_settings['welcomeMessageInContext'] ) && $bot_settings['welcomeMessageInContext'] === '0';

            if ( ! empty( $bot_settings['useSummaryContext'] ) && $bot_settings['useSummaryContext'] === '1' ) {
                global $wpdb;

                // Find last summary in the thread from any bot
                $last_summary = $wpdb->get_row( $wpdb->prepare(
                    "SELECT m.id, m.created_at, m.message
                    FROM `" . bm_get_table('messages') . "` m
                    INNER JOIN `" . bm_get_table('meta') . "` mt ON mt.bm_message_id = m.id
                    WHERE m.thread_id = %d
                    AND mt.meta_key = 'ai_summary'
                    AND m.created_at <= %d
                    ORDER BY m.created_at DESC
                    LIMIT 1",
                    $thread_id, $up_to_created_at
                ) );

                if ( $last_summary ) {
                    $summary_text = $last_summary->message;
                    $summary_text = preg_replace( '/<!--(.|\s)*?-->/', '', $summary_text );
                    $summary_text = wp_strip_all_tags( html_entity_decode( $summary_text ) );

                    $exclude_join  = '';
                    $exclude_where = '';
                    if ( $exclude_welcome ) {
                        $exclude_join  = "LEFT JOIN `" . bm_get_table('meta') . "` welcome_meta ON welcome_meta.bm_message_id = m.id AND welcome_meta.meta_key = 'ai_welcome_message'";
                        $exclude_where = "AND welcome_meta.meta_id IS NULL";
                    }

                    $messages_after = $wpdb->get_results( $wpdb->prepare(
                        "SELECT m.id, m.sender_id, m.message
                        FROM `" . bm_get_table('messages') . "` m
                        $exclude_join
                        WHERE m.thread_id = %d
                        AND m.created_at > %d
                        AND m.created_at <= %d
                        $exclude_where
                        ORDER BY m.created_at ASC",
                        $thread_id, $last_summary->created_at, $up_to_created_at
                    ) );

                    return array(
                        'summary'  => $summary_text,
                        'messages' => $messages_after
                    );
                }
            }

            return array(
                'summary'  => null,
                'messages' => $this->get_thread_messages( $thread_id, $up_to_created_at, $limit, $exclude_welcome )
            );
        }

        /**
         * Enrich thread messages with reply-to context.
         * If the triggering message is a reply to another message that is not in the context,
         * inject the replied-to message so the bot can access its content and attachments.
         * Also annotates the replying message text with a reply indicator.
         */
        protected function enrich_with_reply_context( &$thread_messages, $message, $sender_names = array() )
        {
            $reply_to_id = Better_Messages()->functions->get_message_meta( $message->id, 'reply_to', true );
            if ( empty( $reply_to_id ) ) {
                return;
            }

            $reply_to_id = (int) $reply_to_id;
            $replied_msg = Better_Messages()->functions->get_message( $reply_to_id );
            if ( ! $replied_msg ) {
                return;
            }

            // Check if the replied-to message is already in the context
            $already_in_context = false;
            foreach ( $thread_messages as $msg ) {
                if ( (int) $msg->id === $reply_to_id ) {
                    $already_in_context = true;
                    break;
                }
            }

            // If not in context, inject it right before the triggering message
            if ( ! $already_in_context ) {
                $last = array_pop( $thread_messages );
                $thread_messages[] = $replied_msg;
                $thread_messages[] = $last;
            }

            // Annotate the triggering message with reply context
            $replied_text = preg_replace( '/<!--(.|\s)*?-->/', '', $replied_msg->message );
            $replied_text = wp_strip_all_tags( html_entity_decode( $replied_text ) );
            $replied_text = mb_substr( $replied_text, 0, 200 );

            $replied_sender_id = (int) $replied_msg->sender_id;
            $replied_sender_name = isset( $sender_names[ $replied_sender_id ] ) ? $sender_names[ $replied_sender_id ] : '';

            // Check if replied-to message has attachments
            $replied_attachments = Better_Messages()->functions->get_message_meta( $reply_to_id, 'attachments', true );
            $has_attachments = ! empty( $replied_attachments );

            $reply_prefix = '[Replying to';
            if ( $replied_sender_name ) {
                $reply_prefix .= ' ' . $replied_sender_name . "'s";
            }
            $reply_prefix .= ' message';
            if ( ! empty( $replied_text ) ) {
                $reply_prefix .= ': "' . $replied_text . '"';
            }
            if ( $has_attachments ) {
                $count = count( $replied_attachments );
                $reply_prefix .= ' (' . $count . ' attachment' . ( $count > 1 ? 's' : '' ) . ')';
            }
            $reply_prefix .= ']: ';

            // Find and annotate the triggering message
            foreach ( $thread_messages as &$msg ) {
                if ( (int) $msg->id === (int) $message->id ) {
                    $msg->message = $reply_prefix . $msg->message;
                    break;
                }
            }
            unset( $msg );
        }

        /**
         * Check if a thread is a group conversation (more than 2 participants).
         */
        protected function is_group_thread( $thread_id )
        {
            $recipients = Better_Messages()->functions->get_recipients( $thread_id );
            return count( $recipients ) > 2;
        }

        /**
         * Resolve display names for sender IDs in thread messages.
         * Returns an array mapping sender_id => display_name.
         */
        protected function resolve_sender_names( $thread_messages, $bot_user_id )
        {
            global $wpdb;

            $user_ids = array();
            $guest_ids = array();

            foreach ( $thread_messages as $msg ) {
                $sid = (int) $msg->sender_id;
                if ( $sid === (int) $bot_user_id ) continue;
                if ( $sid > 0 ) {
                    $user_ids[] = $sid;
                } else if ( $sid < 0 ) {
                    $guest_ids[] = absint( $sid );
                }
            }

            $names = array();

            if ( ! empty( $user_ids ) ) {
                $user_ids = array_unique( $user_ids );
                $placeholders = implode( ',', array_fill( 0, count( $user_ids ), '%d' ) );
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT ID, display_name FROM {$wpdb->users} WHERE ID IN ($placeholders)",
                    ...$user_ids
                ) );
                foreach ( $rows as $r ) {
                    $names[ (int) $r->ID ] = $r->display_name;
                }
            }

            if ( ! empty( $guest_ids ) && isset( Better_Messages()->guests ) ) {
                foreach ( array_unique( $guest_ids ) as $gid ) {
                    $guest = Better_Messages()->guests->get_guest_user( $gid );
                    if ( $guest ) {
                        $names[ -$gid ] = $guest->name;
                    }
                }
            }

            return $names;
        }

        /**
         * Build a group context system instruction supplement.
         * Tells the bot about the conversation participants and how to mention them.
         */
        protected function get_group_context_instruction( $sender_names, $bot_user_id, $bot_name = '' )
        {
            $participants = array();
            foreach ( $sender_names as $uid => $name ) {
                $participants[] = $name . ' (ID: ' . $uid . ')';
            }

            $instruction = "\n\nYour name is " . $bot_name . " and your user ID is " . $bot_user_id . ".";
            $instruction .= "\nThis is a group conversation. You were mentioned or replied to and should respond to that message.";
            $instruction .= "\nParticipants: " . implode( ', ', $participants ) . '.';
            $instruction .= "\nTo mention a user in your response, use this format: {{mention:USER_ID:@Username}}";
            $instruction .= "\nReplace USER_ID with the participant's ID and @Username with their name. For example: {{mention:5:@John}}";

            return $instruction;
        }

        protected function clean_stored_message( $stored_text )
        {
            $text = preg_replace( '/<!--(.|\s)*?-->/', '', (string) $stored_text );
            return html_entity_decode( $text, ENT_QUOTES | ENT_HTML401, 'UTF-8' );
        }

        protected function strip_mention_html( $text, $sender_names = array() )
        {
            $callback = function ( $m ) use ( $sender_names ) {
                $uid = (int) $m[1];
                if ( isset( $sender_names[ $uid ] ) ) {
                    return '@' . $sender_names[ $uid ];
                }
                return $m[2];
            };

            $text = preg_replace_callback(
                '/&lt;span class=&quot;bm-mention&quot; data-user-id=&quot;(-?\d+)&quot;&gt;(@[^&]*)&lt;\/span&gt;/',
                $callback,
                $text
            );

            $text = preg_replace_callback(
                '/<span class="bm-mention" data-user-id="(-?\d+)">(@[^<]*)<\/span>/',
                $callback,
                $text
            );

            return $text;
        }

        /**
         * Convert {{mention:USER_ID:@Username}} placeholders to entity-encoded mention HTML.
         */
        public function convert_mention_placeholders( $text )
        {
            $tag = '{{mention:';
            $result = '';
            $offset = 0;

            while ( ( $start = strpos( $text, $tag, $offset ) ) !== false ) {
                $end = strpos( $text, '}}', $start );
                if ( $end === false ) break;

                $result .= substr( $text, $offset, $start - $offset );

                $inner = substr( $text, $start + strlen( $tag ), $end - $start - strlen( $tag ) );
                $colon = strpos( $inner, ':@' );

                if ( $colon !== false ) {
                    $user_id  = trim( substr( $inner, 0, $colon ) );
                    $username = trim( substr( $inner, $colon + 1 ) );
                    $result .= '&lt;span class=&quot;bm-mention&quot; data-user-id=&quot;' . $user_id . '&quot;&gt;' . htmlspecialchars( $username, ENT_QUOTES, 'UTF-8' ) . '&lt;/span&gt;';
                } else {
                    $result .= substr( $text, $start, $end + 2 - $start );
                }

                $offset = $end + 2;
            }

            $result .= substr( $text, $offset );

            return $result;
        }

        /**
         * Common process_reply logic for all providers
         */
        public function process_reply( $bot_id, $message_id )
        {
            if ( wp_get_scheduled_event( 'better_messages_ai_bot_ensure_completion', [ $bot_id, $message_id ] ) ) {
                wp_clear_scheduled_hook( 'better_messages_ai_bot_ensure_completion', [ $bot_id, $message_id ] );
            }

            $message = Better_Messages()->functions->get_message( $message_id );

            if ( ! $message ) {
                return;
            }

            if ( empty( Better_Messages()->functions->get_message_meta( $message_id, 'ai_waiting_for_response' ) ) ) {
                return;
            }

            $recipient_user_id = $message->sender_id;

            $bot_user = Better_Messages()->ai->get_bot_user( $bot_id );

            if ( ! $bot_user ) {
                Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );
                return;
            }

            $settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            $ai_user_id    = absint( $bot_user->id ) * -1;
            $ai_thread_id  = $message->thread_id;

            // Show typing indicator (mark_thread_read is handled by v2/send when bot response is delivered)
            if ( Better_Messages()->websocket ) {
                Better_Messages()->websocket->send_typing( $ai_thread_id, $ai_user_id );
            }

            $is_group = $this->is_group_thread( $ai_thread_id );

            if ( $is_group ) {
                $this->process_reply_group( $bot_id, $message_id, $message, $bot_user, $ai_user_id, $ai_thread_id, $recipient_user_id, $settings );
            } else {
                $this->process_reply_stream( $bot_id, $message_id, $message, $bot_user, $ai_user_id, $ai_thread_id, $recipient_user_id, $settings );
            }
        }

        /**
         * Group conversation reply: generate full response, then send as regular message.
         */
        private function process_reply_group( $bot_id, $message_id, $message, $bot_user, $ai_user_id, $ai_thread_id, $recipient_user_id, $settings )
        {
            $dataProvider = $this->getResponseGeneratorWithRetry( $bot_id, $bot_user, $message, 0, false );

            if ( $dataProvider === null ) {
                Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                return;
            }

            // Consume generator fully to collect response
            $parts = [];
            $meta  = null;
            $error = null;
            $last_typing = time();

            while ( $dataProvider->valid() ) {
                $part = $dataProvider->current();

                if ( is_array( $part ) && $part[0] === 'error' ) {
                    $error = $part[1];
                    break;
                }

                if ( is_array( $part ) && $part[0] === 'finish' ) {
                    $meta = $part[1];
                    break;
                }

                if ( is_string( $part ) ) {
                    $parts[] = $part;
                }

                // Send periodic typing indicator
                if ( Better_Messages()->websocket && time() - $last_typing >= 2 ) {
                    $last_typing = time();
                    Better_Messages()->websocket->send_typing( $ai_thread_id, $ai_user_id );
                }

                $dataProvider->next();
            }

            Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
            Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );

            if ( $error !== null ) {
                $user_error = $this->get_user_friendly_error( $error );
                Better_Messages()->functions->add_message_meta( $message_id, 'ai_response_error', $user_error );
                return;
            }

            if ( empty( $parts ) || $meta === null ) {
                return;
            }

            $raw_text = implode( '', $parts );
            $content = '<!-- BM-AI -->' . $this->convert_mention_placeholders( htmlentities( $raw_text ) );

            // Send as a regular message with unread counting
            $ai_message_id = Better_Messages()->functions->new_message( [
                'sender_id'    => $ai_user_id,
                'thread_id'    => $ai_thread_id,
                'content'      => $content,
                'count_unread' => true,
                'return'       => 'message_id',
                'error_type'   => 'wp_error'
            ] );

            if ( is_wp_error( $ai_message_id ) ) {
                return;
            }

            Better_Messages()->functions->add_message_meta( $ai_message_id, 'ai_response_for', $message_id );
            Better_Messages()->functions->update_message_meta( $message_id, 'ai_response_id', $ai_message_id );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_provider', $this->get_provider_id() );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'completed' );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_provider_meta', json_encode( $meta ) );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_finish', time() );

            // Process mentions in bot response
            if ( isset( Better_Messages()->mentions ) ) {
                Better_Messages()->mentions->process_mentions( $ai_thread_id, $ai_message_id, $content );
            }

            // Calculate and store cost
            $cost_data = Better_Messages()->ai->calculate_and_store_cost( $ai_message_id, $meta, $bot_id, $recipient_user_id, $ai_thread_id );

            do_action( 'better_messages_ai_response_completed', $ai_message_id, $message_id, $cost_data, $bot_id, $recipient_user_id );

            do_action( 'better_messages_thread_self_update', $ai_thread_id, $recipient_user_id );
            do_action( 'better_messages_thread_updated', $ai_thread_id, $recipient_user_id );

            $this->on_response_completed( $ai_message_id, $message_id, $meta );
        }

        /**
         * 1:1 conversation reply: placeholder message with streaming.
         */
        private function process_reply_stream( $bot_id, $message_id, $message, $bot_user, $ai_user_id, $ai_thread_id, $recipient_user_id, $settings )
        {
            $ai_message_id = Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_id' );

            if ( $ai_message_id ) {
                $ai_message = Better_Messages()->functions->get_message( $ai_message_id );
                if ( ! $ai_message ) {
                    $ai_message_id = false;
                }
            }

            if ( ! $ai_message_id ) {
                $ai_message_id = Better_Messages()->functions->new_message( [
                    'sender_id'    => $ai_user_id,
                    'thread_id'    => $ai_thread_id,
                    'content'      => '<!-- BM-AI -->',
                    'count_unread' => false,
                    'send_push'    => false,
                    'return'       => 'message_id',
                    'error_type'   => 'wp_error'
                ] );

                Better_Messages()->functions->add_message_meta( $ai_message_id, 'ai_response_for', $message_id );

                if ( ! is_wp_error( $ai_message_id ) ) {
                    Better_Messages()->functions->add_message_meta( $ai_message_id, 'ai_response_start', time() );
                    Better_Messages()->functions->add_message_meta( $message_id, 'ai_response_id', $ai_message_id );
                } else {
                    Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                    Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                    return;
                }
            }

            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_provider', $this->get_provider_id() );

            $dataProvider = $this->getResponseGeneratorWithRetry( $bot_id, $bot_user, $message, $ai_message_id );

            if ( $dataProvider === null ) {
                Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                return;
            }

            $loop    = Loop::get();
            $browser = new Browser( $loop );
            $stream  = new ThroughStream( function ( $data ) { return $data; } );

            $last_ping = time();
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_last_ping', $last_ping );

            $parts         = [];
            $pendingWrites = [];
            $process       = null;
            $provider      = $this;
            $last_typing   = time();

            /**
             * Write to the stream, buffering if the cloud connection is not yet established.
             * ThroughStream emits 'data' events immediately on write(), but if no listener
             * is attached yet (Browser hasn't piped the stream), the data is lost.
             * We detect readiness by checking for 'data' listeners on the stream.
             */
            $streamWrite = function ( $data ) use ( $stream, &$pendingWrites ) {
                try {
                    if ( ! is_object( $stream ) || ! method_exists( $stream, 'write' ) ) {
                        return;
                    }

                    // Flush any buffered writes first
                    if ( ! empty( $pendingWrites ) && count( $stream->listeners( 'data' ) ) > 0 ) {
                        foreach ( $pendingWrites as $buffered ) {
                            $stream->write( $buffered );
                        }
                        $pendingWrites = [];
                    }

                    if ( count( $stream->listeners( 'data' ) ) > 0 ) {
                        $stream->write( $data );
                    } else {
                        $pendingWrites[] = $data;
                    }
                } catch ( \Throwable $e ) {}
            };

            $process = function () use ( &$process, &$last_ping, &$last_typing, $loop, $stream, $dataProvider, $message_id, $ai_user_id, $ai_message_id, $ai_thread_id, &$parts, &$pendingWrites, $recipient_user_id, $provider, $bot_id, $streamWrite ) {
                if ( defined( 'BM_DEBUG' ) ) {
                    file_put_contents( ABSPATH . 'ai-provider.log', time() . ' - tick' . "\n", FILE_APPEND | LOCK_EX );
                }

                if ( time() - $last_ping >= 5 ) {
                    $last_ping = time();
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_last_ping', $last_ping );
                }

                if ( Better_Messages()->websocket && time() - $last_typing >= 2 ) {
                    $last_typing = time();
                    Better_Messages()->websocket->send_typing( $ai_thread_id, $ai_user_id );
                }

                if ( $dataProvider->valid() ) {
                    $part = $dataProvider->current();

                    if ( is_array( $part ) && $part[0] === 'error' ) {
                        $raw_error = $part[1];
                        $user_error = $provider->get_user_friendly_error( $raw_error );

                        $streamWrite( $user_error );

                        try {
                            if ( is_object( $stream ) && method_exists( $stream, 'end' ) ) { $stream->end(); }
                        } catch ( \Throwable $e ) {}

                        $loop->stop();

                        $args = [
                            'sender_id'  => $ai_user_id,
                            'thread_id'  => $ai_thread_id,
                            'message_id' => $ai_message_id,
                            'content'    => '<!-- BM-AI -->' . $user_error
                        ];

                        Better_Messages()->functions->update_message( $args );

                        Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                        do_action( 'better_messages_thread_self_update', $ai_thread_id, $recipient_user_id );
                        do_action( 'better_messages_thread_updated', $ai_thread_id, $recipient_user_id );

                        Better_Messages()->functions->add_message_meta( $ai_message_id, 'ai_response_error', $raw_error );
                        Better_Messages()->functions->add_message_meta( $message_id, 'ai_response_error', $raw_error );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'failed' );
                        Better_Messages()->functions->delete_message_meta( $ai_message_id, 'ai_last_ping' );
                        return;
                    }

                    if ( is_array( $part ) && $part[0] === 'finish' ) {
                        // Flush any buffered stream writes before ending
                        try {
                            if ( ! empty( $pendingWrites ) && is_object( $stream ) && method_exists( $stream, 'write' ) ) {
                                foreach ( $pendingWrites as $buffered ) {
                                    $stream->write( $buffered );
                                }
                                $pendingWrites = [];
                            }
                        } catch ( \Throwable $e ) {}

                        $stream->end();
                        $loop->stop();

                        $raw_text = implode( '', $parts );

                        $args = [
                            'sender_id'  => $ai_user_id,
                            'thread_id'  => $ai_thread_id,
                            'message_id' => $ai_message_id,
                            'content'    => '<!-- BM-AI -->' . $this->convert_mention_placeholders( htmlentities( $raw_text ) )
                        ];

                        Better_Messages()->functions->update_message( $args );

                        // Process mentions in bot response
                        if ( isset( Better_Messages()->mentions ) ) {
                            Better_Messages()->mentions->process_mentions( $ai_thread_id, $ai_message_id, $args['content'] );
                        }

                        $meta = $part[1];

                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'completed' );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_provider_meta', json_encode( $meta ) );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_finish', time() );
                        Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_message_meta( $ai_message_id, 'ai_last_ping' );

                        // Calculate and store cost
                        $cost_data = Better_Messages()->ai->calculate_and_store_cost( $ai_message_id, $meta, $bot_id, $recipient_user_id, $ai_thread_id );

                        do_action( 'better_messages_ai_response_completed', $ai_message_id, $message_id, $cost_data, $bot_id, $recipient_user_id );

                        do_action( 'better_messages_thread_self_update', $ai_thread_id, $recipient_user_id );
                        do_action( 'better_messages_thread_updated', $ai_thread_id, $recipient_user_id );

                        $provider->on_response_completed( $ai_message_id, $message_id, $meta );

                        return;
                    }

                    if ( is_string( $part ) ) {
                        $parts[] = $part;
                        $streamWrite( $part );
                    }

                    $dataProvider->next();

                    $loop->futureTick( $process );
                } else {
                    try {
                        if ( is_object( $stream ) && method_exists( $stream, 'end' ) ) {
                            $stream->end();
                        }
                    } catch ( \Throwable $e ) {}

                    $loop->stop();
                }
            };

            if ( Better_Messages()->websocket ) {
                $socket_server = apply_filters( 'bp_better_messages_realtime_server', 'https://cloud.better-messages.com/' );
                $bm_endpoint   = $socket_server . 'streamMessage';

                $browser->post( $bm_endpoint, [
                    'x-site-id'            => Better_Messages()->websocket->site_id,
                    'x-secret-key'         => sha1( Better_Messages()->websocket->site_id . Better_Messages()->websocket->secret_key ),
                    'x-message-id'         => $ai_message_id,
                    'x-thread-id'          => $ai_thread_id,
                    'x-recipient-user-id'  => $recipient_user_id,
                    'x-sender-user-id'     => $ai_user_id,
                ], $stream )->otherwise( function ( \Throwable $e ) {} );
            }

            $loop->futureTick( $process );
        }
    }
}
