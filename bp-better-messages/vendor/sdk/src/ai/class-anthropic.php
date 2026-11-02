<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Anthropic' ) ) {
    final class WordPlus_SDK_AI_Anthropic extends WordPlus_SDK_AI_Provider
    {
        const URL = 'https://api.anthropic.com/v1/';

        const VERSION = '2023-06-01';

        const MAX_TOKENS = 4096;

        const BUDGET = 10000;

        const CONTEXT = 200000;

        public function id()
        {
            return 'anthropic';
        }

        public function name()
        {
            return 'Anthropic';
        }

        public function features()
        {
            return array( 'images', 'files', 'webSearch', 'temperature', 'maxOutputTokens', 'extendedThinking', 'tools' );
        }

        private function api()
        {
            return $this->client( self::URL, array( 'x-api-key' => $this->key, 'anthropic-version' => self::VERSION ) );
        }

        public function input_rules( $model, array $context = array() )
        {
            $images = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

            return array( 'images' => $images, 'links' => array_merge( $images, array( 'application/pdf' ) ), 'link_max' => 0, 'max' => self::MAX_FILE );
        }

        protected function fetch_models( array $options )
        {
            $models = array();
            $after  = '';

            try {
                for ( $page = 0; $page < 10; $page++ ) {
                    $data = json_decode( (string) $this->api()->request( 'GET', 'models?limit=1000' . ( '' !== $after ? '&after_id=' . rawurlencode( $after ) : '' ) )->getBody(), true );

                    foreach ( isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array() as $model ) {
                        if ( isset( $model['id'] ) ) {
                            $models[] = (string) $model['id'];
                        }
                    }

                    if ( empty( $data['has_more'] ) || empty( $data['last_id'] ) ) {
                        break;
                    }
                    $after = (string) $data['last_id'];
                }
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }

            sort( $models );

            return array_values( array_unique( $models ) );
        }

        protected function chat_models( array $all, array $options )
        {
            $models = array_values( array_filter( $all, function ( $model ) {
                return false !== strpos( (string) $model, 'claude' );
            } ) );
            sort( $models );

            return $models;
        }

        public function check()
        {
            try {
                $this->api()->request( 'GET', 'models?limit=1' );

                return true;
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }
        }

        public function classify( $status, $json, $raw )
        {
            $type    = isset( $json['error']['type'] ) ? (string) $json['error']['type'] : '';
            $message = strtolower( isset( $json['error']['message'] ) ? (string) $json['error']['message'] : (string) $raw );
            $status  = (int) $status;

            if ( 401 === $status || 403 === $status || 'authentication_error' === $type || 'permission_error' === $type ) {
                return 'key';
            }
            if ( 402 === $status || 'billing_error' === $type || preg_match( '/credit balance|billing|purchase credits|spend limit/', $message ) ) {
                return 'quota';
            }
            if ( 404 === $status || 'not_found_error' === $type ) {
                return 'model';
            }
            if ( 429 === $status || 'rate_limit_error' === $type ) {
                return 'rate';
            }
            if ( $status >= 500 || 'overloaded_error' === $type || 'api_error' === $type ) {
                return 'down';
            }

            return 'request';
        }

        protected function begin( array $request )
        {
            $messages = array();

            foreach ( self::turns( (array) $request['messages'] ) as $turn ) {
                $content = array();

                foreach ( $turn['parts'] as $part ) {
                    $type = isset( $part['type'] ) ? $part['type'] : 'text';

                    if ( 'text' === $type ) {
                        if ( '' !== (string) ( isset( $part['text'] ) ? $part['text'] : '' ) ) {
                            $content[] = array( 'type' => 'text', 'text' => (string) $part['text'] );
                        }
                    } elseif ( 'user' === $turn['role'] && ( 'image' === $type || 'file' === $type ) ) {
                        $content[] = array(
                            'type'   => 'image' === $type ? 'image' : 'document',
                            'source' => ! empty( $part['url'] )
                                ? array( 'type' => 'url', 'url' => (string) $part['url'] )
                                : array( 'type' => 'base64', 'media_type' => (string) $part['mime'], 'data' => (string) $part['data'] ),
                        );
                    }
                }

                if ( ! empty( $content ) ) {
                    $messages[] = array( 'role' => $turn['role'], 'content' => $content );
                }
            }

            $limit    = (int) $request['context_limit'] > 0 ? (int) $request['context_limit'] : self::CONTEXT;
            $messages = self::trim_turns( $messages, $limit, function ( $turn ) {
                $tokens = 0;
                foreach ( $turn['content'] as $block ) {
                    $tokens += isset( $block['text'] ) ? (int) ( mb_strlen( $block['text'] ) / 4 ) : 0;
                    $tokens += isset( $block['source']['data'] ) ? 1600 : 0;
                }

                return $tokens;
            } );

            if ( empty( $messages ) ) {
                return WordPlus_SDK_AI_Http::error( 'request', 'No messages to process' );
            }

            return array( 'messages' => $messages );
        }

        private function body( array $state, array $request, $last )
        {
            $model = (string) $request['model'];
            $rules = $this->rules( $model );
            $body  = array( 'model' => $model, 'messages' => $state['messages'] );

            if ( $request['stream'] ) {
                $body['stream'] = true;
            }

            if ( '' !== (string) $request['system'] ) {
                $body['system'] = array( array( 'type' => 'text', 'text' => (string) $request['system'], 'cache_control' => array( 'type' => 'ephemeral' ) ) );
            }

            $body['max_tokens'] = (int) $request['max_tokens'] > 0 ? (int) $request['max_tokens'] : self::MAX_TOKENS;

            $budget_thinking = false;
            $effort          = '';

            if ( ! empty( $request['thinking']['on'] ) ) {
                if ( 'budget' === $rules['thinking'] || 'both' === $rules['thinking'] ) {
                    $budget             = (int) $request['thinking']['budget'] > 0 ? (int) $request['thinking']['budget'] : self::BUDGET;
                    $body['thinking']   = array( 'type' => 'enabled', 'budget_tokens' => $budget );
                    $budget_thinking    = true;

                    if ( $body['max_tokens'] <= $budget ) {
                        $body['max_tokens'] = $budget + self::MAX_TOKENS;
                    }
                } elseif ( 'adaptive' === $rules['thinking'] ) {
                    $body['thinking'] = array( 'type' => 'adaptive' );
                    $effort           = self::effort_for( $request['effort'], (int) $request['thinking']['budget'] > 0 ? (int) $request['thinking']['budget'] : self::BUDGET, (array) $rules['effort'] );
                }
            } elseif ( '' !== (string) $request['effort'] ) {
                $effort = self::effort_for( $request['effort'], 0, (array) $rules['effort'] );
            }

            if ( '' !== $effort ) {
                $body['output_config'] = array( 'effort' => $effort );
            }

            if ( null !== $request['temperature'] && '' !== $request['temperature'] && is_numeric( $request['temperature'] ) && 'any' === $rules['sampling'] && ! $budget_thinking ) {
                $body['temperature'] = (float) $request['temperature'];
            }

            $tools = array();

            if ( ! empty( $request['web_search'] ) ) {
                $search = array( 'type' => 'web_search_20250305', 'name' => 'web_search' );
                if ( isset( $request['web_search']['max'] ) && (int) $request['web_search']['max'] > 0 ) {
                    $search['max_uses'] = (int) $request['web_search']['max'];
                }
                $tools[] = $search;
            }

            foreach ( (array) $request['tools'] as $tool ) {
                $tools[] = array(
                    'name'         => (string) $tool['name'],
                    'description'  => (string) ( isset( $tool['description'] ) ? $tool['description'] : '' ),
                    'input_schema' => self::schema( isset( $tool['parameters'] ) ? (array) $tool['parameters'] : array() ),
                );
            }

            if ( ! empty( $tools ) ) {
                $body['tools'] = $tools;
            }
            if ( ! empty( $request['tools'] ) && $last ) {
                $body['tool_choice'] = array( 'type' => 'none' );
            }

            return $body;
        }

        public function preview( array $request )
        {
            $request = $this->request( $request );
            $state   = $this->begin( $request );

            return is_wp_error( $state ) ? $state : $this->body( $state, $request, empty( $request['tools'] ) );
        }

        protected function round( array $state, array $request, $last )
        {
            $body   = $this->body( $state, $request, $last );
            $id     = '';
            $model  = (string) $request['model'];
            $usage  = array();
            $blocks = array();
            $stop   = '';
            $ended  = false;
            $text   = false;

            try {
                $response = $this->api()->post( 'messages', array( 'json' => $body, 'stream' => (bool) $request['stream'], 'timeout' => (int) $request['timeout'] ) );

                if ( ! $request['stream'] ) {
                    $data   = json_decode( (string) $response->getBody(), true );
                    $data   = is_array( $data ) ? $data : array();
                    $events = array( array( 'type' => 'message_start', 'message' => array_diff_key( $data, array( 'content' => true ) ) ) );

                    foreach ( isset( $data['content'] ) && is_array( $data['content'] ) ? $data['content'] : array() as $index => $block ) {
                        $events[] = array( 'type' => 'content_block_start', 'index' => $index, 'content_block' => $block );
                        $events[] = array( 'type' => 'content_block_stop', 'index' => $index );
                    }

                    $events[] = array( 'type' => 'message_delta', 'delta' => array( 'stop_reason' => isset( $data['stop_reason'] ) ? $data['stop_reason'] : '' ) );
                    $events[] = array( 'type' => 'message_stop' );
                } else {
                    $events = self::events( $response->getBody() );
                }

                foreach ( $events as $data ) {
                    if ( ! is_array( $data ) || ! isset( $data['type'] ) ) {
                        continue;
                    }

                    $index = isset( $data['index'] ) ? (int) $data['index'] : 0;

                    switch ( $data['type'] ) {
                        case 'message_start':
                            $id    = isset( $data['message']['id'] ) ? (string) $data['message']['id'] : $id;
                            $model = isset( $data['message']['model'] ) ? (string) $data['message']['model'] : $model;
                            if ( isset( $data['message']['usage'] ) && is_array( $data['message']['usage'] ) ) {
                                $usage = array_merge( $usage, $data['message']['usage'] );
                            }
                            yield array( 'tick' );
                            break;

                        case 'content_block_start':
                            $block = isset( $data['content_block'] ) ? (array) $data['content_block'] : array();
                            if ( 'tool_use' === ( isset( $block['type'] ) ? $block['type'] : '' ) && $request['stream'] ) {
                                $block['json'] = '';
                            }
                            if ( ! $request['stream'] && 'text' === ( isset( $block['type'] ) ? $block['type'] : '' ) && '' !== (string) $block['text'] ) {
                                $text = true;
                                yield (string) $block['text'];
                            }
                            $blocks[ $index ] = $block;
                            yield array( 'tick' );
                            break;

                        case 'content_block_delta':
                            $delta = isset( $data['delta'] ) ? (array) $data['delta'] : array();
                            $block = isset( $blocks[ $index ] ) ? $blocks[ $index ] : array( 'type' => 'text', 'text' => '' );
                            $kind  = isset( $delta['type'] ) ? $delta['type'] : '';

                            if ( 'text_delta' === $kind && isset( $delta['text'] ) ) {
                                $block['text'] = ( isset( $block['text'] ) ? $block['text'] : '' ) . $delta['text'];
                                if ( '' !== (string) $delta['text'] ) {
                                    $text = true;
                                    yield (string) $delta['text'];
                                }
                            } elseif ( 'input_json_delta' === $kind ) {
                                $block['json'] = ( isset( $block['json'] ) ? $block['json'] : '' ) . ( isset( $delta['partial_json'] ) ? $delta['partial_json'] : '' );
                            } elseif ( 'thinking_delta' === $kind ) {
                                $block['thinking'] = ( isset( $block['thinking'] ) ? $block['thinking'] : '' ) . ( isset( $delta['thinking'] ) ? $delta['thinking'] : '' );
                            } elseif ( 'signature_delta' === $kind ) {
                                $block['signature'] = ( isset( $block['signature'] ) ? $block['signature'] : '' ) . ( isset( $delta['signature'] ) ? $delta['signature'] : '' );
                            }
                            $blocks[ $index ] = $block;
                            break;

                        case 'content_block_stop':
                            if ( isset( $blocks[ $index ]['json'] ) ) {
                                $json                      = trim( (string) $blocks[ $index ]['json'] );
                                $blocks[ $index ]['input'] = '' === $json ? array() : self::args( $json );
                                unset( $blocks[ $index ]['json'] );
                            }
                            yield array( 'tick' );
                            break;

                        case 'message_delta':
                            if ( isset( $data['usage'] ) && is_array( $data['usage'] ) ) {
                                $usage = array_merge( $usage, $data['usage'] );
                            }
                            $stop = isset( $data['delta']['stop_reason'] ) ? (string) $data['delta']['stop_reason'] : $stop;
                            yield array( 'tick' );
                            break;

                        case 'message_stop':
                            $ended = true;
                            break 2;

                        case 'error':
                            $error = isset( $data['error'] ) ? (array) $data['error'] : array();
                            $type  = isset( $error['type'] ) ? (string) $error['type'] : '';
                            $kind  = $this->classify( 'overloaded_error' === $type ? 529 : 0, $data, '' );
                            yield self::failed( isset( $error['message'] ) ? (string) $error['message'] : 'Unknown Anthropic error', 'request' === $kind && 'invalid_request_error' !== $type ? 'down' : $kind, $usage );
                            return null;

                        default:
                            yield array( 'tick' );
                    }
                }
            } catch ( \Throwable $e ) {
                yield $this->error_part( $e, $usage );
                return null;
            }

            if ( ! $ended ) {
                yield self::failed( 'response interrupted: the stream ended before the response was completed', 'down', $usage );
                return null;
            }

            ksort( $blocks );
            $calls = array();
            foreach ( $blocks as $block ) {
                if ( 'tool_use' === ( isset( $block['type'] ) ? $block['type'] : '' ) ) {
                    $calls[] = array( 'id' => (string) $block['id'], 'name' => (string) $block['name'], 'args' => self::args( isset( $block['input'] ) ? $block['input'] : array() ) );
                }
            }

            if ( ! $text && empty( $calls ) && 'refusal' === $stop ) {
                yield self::failed( 'refusal: the model declined to answer', 'refused', $usage );
                return null;
            }

            $meta = array( 'response_id' => $id, 'model' => $model, 'provider' => 'anthropic' );
            if ( ! empty( $usage ) ) {
                $meta['usage'] = $usage;
            }
            if ( '' !== $stop ) {
                $meta['stop_reason'] = $stop;
            }

            return array( 'calls' => $calls, 'meta' => $meta, 'native' => array_values( $blocks ) );
        }

        protected function follow( array $state, array $turn, array $results, array $request )
        {
            $content = array();

            foreach ( (array) $turn['native'] as $block ) {
                switch ( isset( $block['type'] ) ? $block['type'] : '' ) {
                    case 'text':
                        if ( '' !== (string) $block['text'] ) {
                            $content[] = array( 'type' => 'text', 'text' => (string) $block['text'] );
                        }
                        break;
                    case 'tool_use':
                        $content[] = array( 'type' => 'tool_use', 'id' => (string) $block['id'], 'name' => (string) $block['name'], 'input' => empty( $block['input'] ) ? new \stdClass() : $block['input'] );
                        break;
                    case 'thinking':
                        $content[] = array( 'type' => 'thinking', 'thinking' => isset( $block['thinking'] ) ? (string) $block['thinking'] : '', 'signature' => isset( $block['signature'] ) ? (string) $block['signature'] : '' );
                        break;
                    case 'redacted_thinking':
                        $content[] = array( 'type' => 'redacted_thinking', 'data' => isset( $block['data'] ) ? (string) $block['data'] : '' );
                        break;
                    case 'server_tool_use':
                    case 'web_search_tool_result':
                        $content[] = $block;
                        break;
                }
            }

            $state['messages'][] = array( 'role' => 'assistant', 'content' => $content );
            $state['messages'][] = array( 'role' => 'user', 'content' => array_map( function ( $result ) {
                return array( 'type' => 'tool_result', 'tool_use_id' => $result['id'], 'content' => $result['content'] );
            }, $results ) );

            return $state;
        }
    }
}
