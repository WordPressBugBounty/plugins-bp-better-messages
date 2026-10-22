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

        abstract public function get_provider_id();

        abstract public function get_provider_name();

        abstract public function check_api_key();

        abstract public function get_models();

        abstract public function get_supported_features();

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

        public function get_transcription_models()
        {
            return [];
        }

        public function moderate( $text = '', $image_data_uris = [] )
        {
            return new \WP_Error( 'not_supported', 'Moderation not supported by this provider' );
        }

        public function transcribe_audio( $attachment_id )
        {
            return new \WP_Error( 'not_supported', 'Transcription not supported by this provider' );
        }

        public function generateSummary( $system_prompt, $user_content, $model = '', $max_tokens = 3000 )
        {
            return new \WP_Error( 'not_supported', 'Summarization not supported by this provider' );
        }

        public function generateDigest( $system_prompt, $user_content, $model = '', $max_tokens = 3000, $options = [] )
        {
            return $this->generateSummary( $system_prompt, $user_content, $model, $max_tokens );
        }

        public function cancel_response_api( $response_id )
        {
            return true;
        }

        public function on_message_deleted( $message_id, $thread_id, $message )
        {
        }

        public function on_response_completed( $ai_message_id, $message_id, $meta )
        {
        }

        public function ensureResponseCompletionJob()
        {
        }

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
            } elseif ( ( strpos( $lower, 'invalid' ) !== false || strpos( $lower, 'incorrect' ) !== false ) && strpos( $lower, 'key' ) !== false ) {
                $friendly = __( 'There is an issue with the AI configuration. Please contact the site administrator.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'model' ) !== false && ( strpos( $lower, 'not found' ) !== false || strpos( $lower, 'not supported' ) !== false || strpos( $lower, 'does not exist' ) !== false || strpos( $lower, 'deprecated' ) !== false || strpos( $lower, 'do not have access' ) !== false ) ) {
                $friendly = __( 'The AI model configured for this bot is not available. Please contact the site administrator.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'quota' ) !== false || strpos( $lower, 'billing' ) !== false || strpos( $lower, 'insufficient' ) !== false || strpos( $lower, 'credit' ) !== false || strpos( $lower, 'balance' ) !== false ) {
                $friendly = __( 'The AI service quota has been exceeded. Please contact the site administrator.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'safety' ) !== false || strpos( $lower, 'blocked' ) !== false || strpos( $lower, 'content_filter' ) !== false ) {
                $friendly = __( 'The message could not be processed due to content restrictions.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'voice transcription' ) !== false ) {
                $friendly = __( 'The voice message could not be transcribed. Please try again or send a text message.', 'bp-better-messages' );
            } elseif ( strpos( $lower, 'interrupted' ) !== false ) {
                $friendly = __( 'The response was interrupted. Please try again.', 'bp-better-messages' );
            } else {
                $friendly = __( 'Something went wrong while generating a response. Please try again.', 'bp-better-messages' );
            }

            if ( defined( 'BM_DEBUG' ) ) {
                error_log( 'BM AI error [' . $this->get_provider_id() . '] raw: ' . $error_message . ' → friendly: ' . $friendly );
            }

            return $friendly;
        }

        protected function get_voice_transcript( $_message )
        {
            if ( strpos( (string) $_message->message, '<!-- BPBM-VOICE-MESSAGE -->' ) === false ) {
                return null;
            }

            if ( ! isset( Better_Messages()->ai ) ) {
                return null;
            }

            return Better_Messages()->ai->get_voice_transcript( $_message->id );
        }

        protected function get_context_text( $_message )
        {
            $text       = $this->clean_stored_message( $_message->message );
            $transcript = $this->get_voice_transcript( $_message );

            if ( is_string( $transcript ) && trim( $transcript ) !== '' ) {
                $text = trim( $text );
                $text = $text === '' ? $transcript : $text . "\n" . $transcript;
            }

            return $this->append_location_context( $_message->id, $text );
        }

        protected function get_attachment_input( $attachment_id, $bot_settings, array $image_types, array $link_types = array(), $link_max_bytes = 0 )
        {
            $storage = Better_Messages_Storage();
            $mime    = $storage->content_type( $attachment_id );

            if ( $bot_settings['images'] && in_array( $mime, $image_types, true ) ) {
                $kind = 'image';
            } else if ( $bot_settings['files'] && $mime === 'application/pdf' ) {
                $kind = 'pdf';
            } else {
                return null;
            }

            $max_bytes = 20 * 1024 * 1024;
            $size      = $storage->get_file_size( $attachment_id );

            if ( $size <= 0 || $size > $max_bytes ) {
                return null;
            }

            $input = array(
                'kind' => $kind,
                'mime' => $mime,
                'name' => $storage->original_name( $attachment_id ),
                'url'  => '',
                'data' => '',
            );

            if ( $storage->is_cloud_file( $attachment_id )
                && in_array( $mime, $link_types, true )
                && ( $link_max_bytes <= 0 || $size <= $link_max_bytes )
                && apply_filters( 'better_messages_ai_file_links', true, $attachment_id, $this->get_provider_id() ) ) {
                $signed = $storage->signed_url( $attachment_id, 'inline' );

                if ( $signed && ! empty( $signed['url'] ) ) {
                    $input['url'] = $signed['url'];
                    return $input;
                }
            }

            $copy = $storage->get_local_copy( $attachment_id, $max_bytes );

            if ( is_wp_error( $copy ) ) {
                return null;
            }

            $contents = file_get_contents( $copy['path'] );
            call_user_func( $copy['cleanup'] );

            if ( $contents === false || $contents === '' ) {
                return null;
            }

            $input['data'] = base64_encode( $contents );

            return $input;
        }

        protected function prepare_voice_input( $bot_id, $message )
        {
            if ( ! isset( Better_Messages()->ai ) ) {
                return null;
            }

            $attachment_id = Better_Messages()->ai->get_voice_attachment_id( $message->id );

            if ( ! $attachment_id ) {
                return null;
            }

            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            if ( str_contains( $bot_settings['model'], '-audio-' ) && $this->supports( 'audio' ) ) {
                return null;
            }

            $transcript = Better_Messages()->ai->get_voice_transcript( $message->id );

            if ( $transcript === null ) {
                if ( ! Better_Messages()->ai->bot_accepts_voice_input( $bot_settings ) ) {
                    return 'voice transcription is not enabled';
                }

                $transcript = Better_Messages()->ai->get_voice_transcript( $message->id, true );
            }

            if ( $transcript === null || trim( $transcript ) === '' ) {
                return 'voice transcription failed';
            }

            return null;
        }

        protected function getResponseGeneratorWithRetry( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            $voice_error = $this->prepare_voice_input( $bot_id, $message );

            if ( $voice_error !== null ) {
                return ( function () use ( $voice_error ) {
                    yield [ 'error', $voice_error ];
                } )();
            }

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

                if ( is_array( $first ) && $first[0] === 'error' ) {
                    $is_transient = $this->is_transient_error( $first[1] );

                    if ( defined( 'BM_DEBUG' ) ) {
                        error_log( 'BM AI generator error [' . $this->get_provider_id() . '] attempt ' . ( $attempt + 1 ) . ': ' . $first[1] . ( $is_transient ? ' (transient)' : ' (permanent)' ) );
                    }

                    if ( $attempt < $max_retries && $is_transient ) {
                        continue;
                    }
                }

                return ( function () use ( $first, $gen ) {
                    yield $first;
                    $gen->next();
                    while ( $gen->valid() ) {
                        yield $gen->current();
                        $gen->next();
                    }
                } )();
            }

            return ( function () use ( $first ) {
                yield $first;
            } )();
        }

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

        protected function get_thread_messages_with_summary( $thread_id, $up_to_created_at, $limit, $bot_user_id, $bot_settings )
        {
            $exclude_welcome = isset( $bot_settings['welcomeMessageInContext'] ) && $bot_settings['welcomeMessageInContext'] === '0';

            if ( ! empty( $bot_settings['useSummaryContext'] ) && $bot_settings['useSummaryContext'] === '1' ) {
                global $wpdb;

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
                    $summary_text = wp_strip_all_tags( $this->decode_entities_deep( $summary_text ) );

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

        protected function enrich_with_reply_context( &$thread_messages, $message, $sender_names = array() )
        {
            $reply_to_id = Better_Messages()->functions->get_message_meta( $message->id, 'reply_to', true );
            if ( empty( $reply_to_id ) ) {
                return;
            }

            $reply_to_id = (int) $reply_to_id;
            $replied_msg = Better_Messages()->functions->get_message( $reply_to_id );
            if ( ! $replied_msg || (int) $replied_msg->thread_id !== (int) $message->thread_id ) {
                return;
            }

            $already_in_context = false;
            foreach ( $thread_messages as $msg ) {
                if ( (int) $msg->id === $reply_to_id ) {
                    $already_in_context = true;
                    break;
                }
            }

            if ( ! $already_in_context ) {
                $last = array_pop( $thread_messages );
                $thread_messages[] = $replied_msg;
                $thread_messages[] = $last;
            }

            $replied_text = preg_replace( '/<!--(.|\s)*?-->/', '', $replied_msg->message );
            $replied_text = wp_strip_all_tags( $this->decode_entities_deep( $replied_text ) );

            if ( trim( $replied_text ) === '' ) {
                $replied_transcript = $this->get_voice_transcript( $replied_msg );

                if ( is_string( $replied_transcript ) ) {
                    $replied_text = $replied_transcript;
                }
            }

            $replied_text = mb_substr( $replied_text, 0, 200 );

            $replied_sender_id = (int) $replied_msg->sender_id;
            $replied_sender_name = isset( $sender_names[ $replied_sender_id ] ) ? $sender_names[ $replied_sender_id ] : '';

            $replied_attachments = Better_Messages()->functions->get_message_meta( $reply_to_id, 'attachments', true );
            $has_attachments = ! empty( $replied_attachments );

            $replied_location = $this->get_location_context( $reply_to_id );

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
            if ( $replied_location !== '' ) {
                $reply_prefix .= ' (shared location: ' . $replied_location . ')';
            }
            $reply_prefix .= ']: ';

            foreach ( $thread_messages as &$msg ) {
                if ( (int) $msg->id === (int) $message->id ) {
                    $msg->message = $reply_prefix . $msg->message;
                    break;
                }
            }
            unset( $msg );
        }

        protected function is_group_thread( $thread_id )
        {
            $recipients = Better_Messages()->functions->get_recipients( $thread_id );
            return count( $recipients ) > 2;
        }

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

        protected function decode_entities_deep( $text )
        {
            $text = (string) $text;
            for ( $i = 0; $i < 3; $i++ ) {
                $decoded = html_entity_decode( $text, ENT_QUOTES | ENT_HTML401, 'UTF-8' );
                if ( $decoded === $text ) break;
                $text = $decoded;
            }
            return $text;
        }

        protected function clean_stored_message( $stored_text )
        {
            $text = preg_replace( '/<!--(.|\s)*?-->/', '', (string) $stored_text );
            return $this->decode_entities_deep( $text );
        }

        protected function get_location_context( $message_id )
        {
            if ( ! isset( Better_Messages()->ai ) ) {
                return '';
            }

            return Better_Messages()->ai->get_location_context( $message_id );
        }

        protected function append_location_context( $message_id, $message_text )
        {
            $location = $this->get_location_context( $message_id );

            if ( $location === '' ) {
                return $message_text;
            }

            $message_text = trim( (string) $message_text );
            $annotation   = '[Shared location: ' . $location . ']';

            if ( strpos( $message_text, $annotation ) !== false ) {
                return $message_text;
            }

            return $message_text === '' ? $annotation : $message_text . "\n" . $annotation;
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
                '/&lt;span class=&quot;bm-mention&quot; data-user-id=&quot;(-?\d+|all)&quot;&gt;(@[^&]*)&lt;\/span&gt;/',
                $callback,
                $text
            );

            $text = preg_replace_callback(
                '/<span class="bm-mention" data-user-id="(-?\d+|all)">(@[^<]*)<\/span>/',
                $callback,
                $text
            );

            return $text;
        }

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

                    if ( preg_match( '/-?\d+$/', $user_id, $id_match ) ) {
                        $user_id = $id_match[0];
                    }

                    if ( preg_match( '/^-?\d+$/', $user_id ) ) {
                        $result .= '&lt;span class=&quot;bm-mention&quot; data-user-id=&quot;' . $user_id . '&quot;&gt;' . htmlspecialchars( $username, ENT_QUOTES, 'UTF-8' ) . '&lt;/span&gt;';
                    } else {
                        $result .= htmlspecialchars( $username, ENT_QUOTES, 'UTF-8' );
                    }
                } else {
                    $result .= substr( $text, $start, $end + 2 - $start );
                }

                $offset = $end + 2;
            }

            $result .= substr( $text, $offset );

            return $result;
        }

        public function process_reply( $bot_id, $message_id )
        {
            $lock = Better_Messages()->functions->lock( 'ai_reply|' . (int) $message_id, 0 );

            if ( $lock === false ) {
                return;
            }

            try {
                wp_cache_delete( (int) $message_id, 'bm_message_meta' );

                $this->reply_to_message( $bot_id, $message_id );
            } finally {
                Better_Messages()->functions->unlock( $lock );
            }
        }

        private function reply_to_message( $bot_id, $message_id )
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

        private function process_reply_group( $bot_id, $message_id, $message, $bot_user, $ai_user_id, $ai_thread_id, $recipient_user_id, $settings )
        {
            $dataProvider = $this->getResponseGeneratorWithRetry( $bot_id, $bot_user, $message, 0, false );

            if ( $dataProvider === null ) {
                Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                return;
            }

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

            $ai_message_id = Better_Messages()->functions->new_message( [
                'sender_id'    => $ai_user_id,
                'thread_id'    => $ai_thread_id,
                'content'      => $content,
                'count_unread' => true,
                'return'       => 'message_id',
                'error_type'   => 'wp_error',
                'skip_moderation' => true,
                'meta_data'    => [ 'ai_response_for' => (int) $message_id ]
            ] );

            if ( is_wp_error( $ai_message_id ) ) {
                return;
            }

            Better_Messages()->functions->update_message_meta( $message_id, 'ai_response_id', $ai_message_id );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_provider', $this->get_provider_id() );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'completed' );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_provider_meta', json_encode( $meta ) );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_finish', time() );

            $cost_data = Better_Messages()->ai->calculate_and_store_cost( $ai_message_id, $meta, $bot_id, $recipient_user_id, $ai_thread_id );

            do_action( 'better_messages_ai_response_completed', $ai_message_id, $message_id, $cost_data, $bot_id, $recipient_user_id );

            do_action( 'better_messages_thread_self_update', $ai_thread_id, $recipient_user_id );
            do_action( 'better_messages_thread_updated', $ai_thread_id, $recipient_user_id );

            $this->on_response_completed( $ai_message_id, $message_id, $meta );
        }

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
                    'mobile_push'  => false,
                    'return'       => 'message_id',
                    'error_type'   => 'wp_error',
                    'skip_moderation' => true
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

            $streamWrite = function ( $data ) use ( $stream, &$pendingWrites, $recipient_user_id ) {
                try {
                    if ( ! is_object( $stream ) || ! method_exists( $stream, 'write' ) ) {
                        return;
                    }

                    if ( Better_Messages()->websocket && method_exists( Better_Messages()->websocket, 'seal_stream_chunk' ) ) {
                        $data = Better_Messages()->websocket->seal_stream_chunk( $data, $recipient_user_id );
                    }

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

            $fail = function ( $raw_error ) use ( $loop, $stream, $message_id, $ai_user_id, $ai_message_id, $ai_thread_id, $recipient_user_id, $provider, $streamWrite ) {
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
            };

            $process = function () use ( &$process, &$last_ping, &$last_typing, $loop, $stream, $dataProvider, $message_id, $ai_user_id, $ai_message_id, $ai_thread_id, &$parts, &$pendingWrites, $recipient_user_id, $provider, $bot_id, $streamWrite, $fail ) {
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
                        $fail( $part[1] );
                        return;
                    }

                    if ( is_array( $part ) && $part[0] === 'finish' ) {
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

                        $meta = $part[1];

                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'completed' );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_provider_meta', json_encode( $meta ) );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_finish', time() );
                        Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_message_meta( $ai_message_id, 'ai_last_ping' );

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
                    $fail( 'response interrupted: the reply ended without a result' );
                }
            };

            if ( Better_Messages()->websocket && ! Better_Messages()->hooks->is_view_restricted( $recipient_user_id ) ) {
                $socket_server = Better_Messages()->websocket->api_server();
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
