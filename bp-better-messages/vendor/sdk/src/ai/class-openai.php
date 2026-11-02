<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_OpenAI' ) ) {
    final class WordPlus_SDK_AI_OpenAI extends WordPlus_SDK_AI_Provider
    {
        const URL = 'https://api.openai.com/v1/';

        const NOT_CHAT = array( 'chat-latest', 'chatgpt', 'deep-research', 'search-preview', 'search-api', 'instruct', 'realtime', 'transcribe', 'whisper', 'image', 'tts', 'moderation', 'embedding' );

        public function id()
        {
            return 'openai';
        }

        public function name()
        {
            return 'OpenAI';
        }

        public function features()
        {
            return array( 'images', 'files', 'imagesGeneration', 'webSearch', 'fileSearch', 'audio', 'moderation', 'transcription', 'reasoningEffort', 'serviceTier', 'temperature', 'maxOutputTokens', 'tools' );
        }

        private function api( $json = true )
        {
            return $this->client( self::URL, array( 'Authorization' => 'Bearer ' . $this->key ), $json );
        }

        public function input_rules( $model, array $context = array() )
        {
            $images = array( 'image/jpeg', 'image/png', 'image/gif', 'image/bmp', 'image/webp' );

            return array(
                'images'   => $images,
                'links'    => empty( $context['stored'] ) ? array_merge( $images, array( 'application/pdf' ) ) : array(),
                'link_max' => 0,
                'max'      => self::MAX_FILE,
            );
        }

        protected function fetch_models( array $options )
        {
            try {
                $data   = json_decode( (string) $this->api()->request( 'GET', 'models' )->getBody(), true );
                $models = array_values( array_filter( array_map( 'strval', array_column( isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : array(), 'id' ) ) ) );
                sort( $models );

                return $models;
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }
        }

        protected function chat_models( array $all, array $options )
        {
            $audio  = ! empty( $options['audio'] );
            $models = array();

            foreach ( $all as $model ) {
                $model = (string) $model;

                if ( false === strpos( $model, 'gpt' ) && ! preg_match( '/^o[0-9]/', $model ) ) {
                    continue;
                }
                if ( false !== strpos( $model, 'audio' ) && ( ! $audio || false === strpos( $model, '-audio-' ) ) ) {
                    continue;
                }
                foreach ( self::NOT_CHAT as $word ) {
                    if ( false !== strpos( $model, $word ) ) {
                        continue 2;
                    }
                }

                $models[] = $model;
            }

            sort( $models );

            return $models;
        }

        public function transcription_models()
        {
            $all = $this->models_raw();

            if ( is_wp_error( $all ) ) {
                return $all;
            }

            $models = array_values( array_filter( $all, function ( $model ) {
                return false !== strpos( $model, 'whisper' ) || false !== strpos( $model, 'transcri' );
            } ) );
            sort( $models );

            return $models;
        }

        private function models_raw()
        {
            $name   = 'wordplus_ai_models_' . $this->id() . '_' . substr( hash( 'sha256', $this->key ), 0, 12 );
            $cached = get_transient( $name );

            if ( false === $cached ) {
                $cached = $this->all_models();
                if ( is_wp_error( $cached ) ) {
                    return $cached;
                }
                set_transient( $name, $cached, DAY_IN_SECONDS );
            }

            return (array) $cached;
        }

        public function check()
        {
            try {
                $this->api()->request( 'GET', 'models' );

                return true;
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }
        }

        public function classify( $status, $json, $raw )
        {
            $error = is_array( $json ) && isset( $json['error'] ) ? (array) $json['error'] : ( is_array( $json ) ? $json : array() );
            $code  = strtolower( ( isset( $error['code'] ) ? (string) $error['code'] : '' ) . ' ' . ( isset( $error['type'] ) ? (string) $error['type'] : '' ) );

            if ( false !== strpos( $code, 'insufficient_quota' ) || false !== strpos( $code, 'billing' ) ) {
                return 'quota';
            }
            if ( 401 === (int) $status || false !== strpos( $code, 'invalid_api_key' ) ) {
                return 'key';
            }
            if ( 403 === (int) $status ) {
                return false !== strpos( $code, 'unsupported_country' ) ? 'request' : 'key';
            }
            if ( 404 === (int) $status || false !== strpos( $code, 'model_not_found' ) ) {
                return 'model';
            }
            if ( 429 === (int) $status || false !== strpos( $code, 'rate_limit' ) ) {
                return 'rate';
            }
            if ( (int) $status >= 500 || false !== strpos( $code, 'server_error' ) ) {
                return 'down';
            }

            return WordPlus_SDK_AI_Http::kind( $status, isset( $error['message'] ) ? $error['message'] : $raw );
        }

        protected function begin( array $request )
        {
            $input = array();

            foreach ( (array) $request['messages'] as $message ) {
                $role    = isset( $message['role'] ) && 'assistant' === $message['role'] ? 'assistant' : 'user';
                $content = array();

                foreach ( self::parts( isset( $message['content'] ) ? $message['content'] : '' ) as $part ) {
                    $type = isset( $part['type'] ) ? $part['type'] : 'text';

                    if ( 'text' === $type ) {
                        $content[] = array( 'type' => 'assistant' === $role ? 'output_text' : 'input_text', 'text' => (string) ( isset( $part['text'] ) ? $part['text'] : '' ) );
                    } elseif ( 'user' === $role && 'image' === $type ) {
                        $content[] = array( 'type' => 'input_image', 'image_url' => ! empty( $part['url'] ) ? (string) $part['url'] : 'data:' . $part['mime'] . ';base64,' . $part['data'] );
                    } elseif ( 'user' === $role && 'file' === $type ) {
                        $content[] = ! empty( $part['url'] )
                            ? array( 'type' => 'input_file', 'file_url' => (string) $part['url'] )
                            : array( 'type' => 'input_file', 'filename' => (string) ( isset( $part['name'] ) ? $part['name'] : 'file.pdf' ), 'file_data' => 'data:' . ( isset( $part['mime'] ) ? $part['mime'] : 'application/pdf' ) . ';base64,' . $part['data'] );
                    }
                }

                $input[] = array( 'role' => $role, 'content' => $content );
            }

            return array( 'input' => $input, 'previous' => '' );
        }

        private function body( array $state, array $request, $last )
        {
            $model  = (string) $request['model'];
            $rules  = $this->rules( $model );
            $tools  = array();
            $limits = array();

            if ( ! empty( $request['image_generation'] ) ) {
                $image   = (array) $request['image_generation'];
                $tools[] = array(
                    'type'           => 'image_generation',
                    'partial_images' => 0,
                    'model'          => isset( $image['model'] ) ? (string) $image['model'] : 'gpt-image-1-mini',
                    'quality'        => isset( $image['quality'] ) ? (string) $image['quality'] : 'auto',
                    'size'           => isset( $image['size'] ) ? (string) $image['size'] : 'auto',
                );
                $limits[] = isset( $image['max'] ) ? (int) $image['max'] : 0;
            }

            if ( ! empty( $request['web_search'] ) ) {
                $search   = (array) $request['web_search'];
                $tools[]  = array( 'type' => ! empty( $request['complete'] ) ? 'web_search_preview' : 'web_search', 'search_context_size' => isset( $search['context_size'] ) ? (string) $search['context_size'] : 'medium' );
                $limits[] = isset( $search['max'] ) ? (int) $search['max'] : 0;
            }

            if ( ! empty( $request['file_search']['vector_store_ids'] ) && is_array( $request['file_search']['vector_store_ids'] ) ) {
                $tools[]  = array( 'type' => 'file_search', 'vector_store_ids' => array_values( $request['file_search']['vector_store_ids'] ) );
                $limits[] = isset( $request['file_search']['max'] ) ? (int) $request['file_search']['max'] : 0;
            }

            foreach ( (array) $request['tools'] as $tool ) {
                $tools[] = array(
                    'type'        => 'function',
                    'name'        => (string) $tool['name'],
                    'description' => (string) ( isset( $tool['description'] ) ? $tool['description'] : '' ),
                    'parameters'  => self::schema( isset( $tool['parameters'] ) ? (array) $tool['parameters'] : array() ),
                );
            }

            $body = array( 'model' => $model );

            if ( empty( $request['complete'] ) ) {
                if ( '' !== (string) $request['service_tier'] ) {
                    $body['service_tier'] = (string) $request['service_tier'];
                }
                $body['truncation'] = 'auto';
                $body['tools']      = $tools;
            } elseif ( ! empty( $tools ) ) {
                $body['tools'] = $tools;
            }

            if ( ! empty( $limits ) && min( $limits ) > 0 ) {
                $body['max_tool_calls'] = array_sum( $limits );
            }

            if ( '' !== (string) $request['conversation'] && '' === $state['previous'] ) {
                $body['conversation'] = (string) $request['conversation'];
            }
            if ( '' !== $state['previous'] ) {
                $body['previous_response_id'] = $state['previous'];
            }
            if ( '' !== (string) $request['system'] ) {
                $body['instructions'] = (string) $request['system'];
            }

            $body['input'] = $state['input'];

            if ( null !== $request['store'] ) {
                $body['store'] = (bool) $request['store'];
            }
            $body['stream'] = (bool) $request['stream'];

            if ( (int) $request['max_tokens'] > 0 ) {
                $body['max_output_tokens'] = (int) $request['max_tokens'];
            }
            if ( null !== $request['temperature'] && '' !== $request['temperature'] && is_numeric( $request['temperature'] ) && 'any' === $rules['sampling'] ) {
                $body['temperature'] = (float) $request['temperature'];
            }

            $effort = (string) $request['effort'];
            if ( '' !== $effort && in_array( $effort, (array) $rules['effort'], true ) ) {
                $body['reasoning'] = array( 'effort' => $effort );

                if ( false === $request['store'] ) {
                    $body['include'] = array( 'reasoning.encrypted_content' );
                }
            }

            if ( ! empty( $request['tools'] ) && $last ) {
                $body['tool_choice'] = 'none';
            }

            return $body;
        }

        public function preview( array $request )
        {
            $request = $this->request( $request );

            return $this->body( $this->begin( $request ), $request, empty( $request['tools'] ) );
        }

        protected function round( array $state, array $request, $last )
        {
            $body  = $this->body( $state, $request, $last );
            $model = (string) $request['model'];
            $image = isset( $request['image_generation']['model'] ) ? (string) $request['image_generation']['model'] : 'gpt-image-1-mini';
            $max   = isset( $request['image_generation']['max'] ) ? (int) $request['image_generation']['max'] : 0;

            $response_id = '';
            $message_id  = '';
            $images      = array();
            $web         = 0;
            $files       = 0;
            $calls       = array();
            $items       = array();
            $has_text    = false;
            $ended       = false;
            $meta        = null;

            try {
                $response = $this->api()->post( 'responses', array( 'json' => $body, 'stream' => (bool) $request['stream'], 'timeout' => (int) $request['timeout'] ) );

                if ( ! $request['stream'] ) {
                    $data   = json_decode( (string) $response->getBody(), true );
                    $data   = is_array( $data ) ? $data : array();
                    $events = array( array( 'type' => 'response.created', 'response' => $data ) );

                    foreach ( isset( $data['output'] ) && is_array( $data['output'] ) ? $data['output'] : array() as $item ) {
                        if ( 'message' === ( isset( $item['type'] ) ? $item['type'] : '' ) ) {
                            foreach ( isset( $item['content'] ) ? (array) $item['content'] : array() as $block ) {
                                if ( isset( $block['text'] ) && '' !== (string) $block['text'] ) {
                                    $events[] = array( 'type' => 'response.output_text.delta', 'item_id' => isset( $item['id'] ) ? $item['id'] : '', 'delta' => (string) $block['text'] );
                                }
                            }
                        }
                        $events[] = array( 'type' => 'response.output_item.done', 'item' => $item );
                    }

                    $events[] = array( 'type' => isset( $data['status'] ) && 'incomplete' === $data['status'] ? 'response.incomplete' : 'response.completed', 'response' => $data );
                } else {
                    $events = self::events( $response->getBody() );
                }

                foreach ( $events as $data ) {
                    if ( ! is_array( $data ) || ! isset( $data['type'] ) ) {
                        continue;
                    }

                    $type = (string) $data['type'];

                    switch ( $type ) {
                        case 'response.created':
                        case 'response.in_progress':
                            $response_id = isset( $data['response']['id'] ) ? (string) $data['response']['id'] : $response_id;
                            $model       = isset( $data['response']['model'] ) ? (string) $data['response']['model'] : $model;
                            $status      = isset( $data['response']['status'] ) ? (string) $data['response']['status'] : '';

                            if ( is_callable( $request['on_status'] ) ) {
                                call_user_func( $request['on_status'], array( 'id' => $response_id, 'status' => $status ) );
                            }
                            yield 'response.created' === $type ? array( 'started', array( 'id' => $response_id, 'status' => $status ) ) : array( 'tick' );
                            break;

                        case 'response.output_item.added':
                            $message_id = isset( $data['item']['id'] ) ? (string) $data['item']['id'] : $message_id;
                            yield array( 'tick' );
                            break;

                        case 'response.content_part.added':
                            $message_id = isset( $data['item_id'] ) ? (string) $data['item_id'] : $message_id;
                            $text       = isset( $data['part']['text'] ) ? (string) $data['part']['text'] : '';
                            if ( '' !== $text ) {
                                $has_text = true;
                                yield $text;
                            }
                            break;

                        case 'response.output_text.delta':
                            $message_id = isset( $data['item_id'] ) ? (string) $data['item_id'] : $message_id;
                            $text       = isset( $data['delta'] ) ? (string) $data['delta'] : '';
                            if ( '' !== $text ) {
                                $has_text = true;
                                yield $text;
                            }
                            break;

                        case 'response.output_item.done':
                            $item       = isset( $data['item'] ) && is_array( $data['item'] ) ? $data['item'] : array();
                            $message_id = isset( $item['id'] ) ? (string) $item['id'] : $message_id;
                            $kind       = isset( $item['type'] ) ? (string) $item['type'] : '';
                            $items[]    = $item;

                            if ( 'image_generation_call' === $kind && isset( $item['result'] ) && ( $max <= 0 || count( $images ) < $max ) ) {
                                $format = isset( $item['output_format'] ) ? (string) $item['output_format'] : 'png';
                                $made   = array(
                                    'id'         => isset( $item['id'] ) ? (string) $item['id'] : '',
                                    'model'      => $image,
                                    'format'     => $format,
                                    'size'       => isset( $item['size'] ) ? (string) $item['size'] : '',
                                    'background' => isset( $item['background'] ) ? (string) $item['background'] : '',
                                    'quality'    => isset( $item['quality'] ) ? (string) $item['quality'] : '',
                                    'mime'       => 'image/' . $format,
                                    'data'       => (string) $item['result'],
                                );
                                $images[] = self::image( $request, $made );
                                yield array( 'image', $made );
                            } elseif ( 'web_search_call' === $kind ) {
                                $web++;
                            } elseif ( 'file_search_call' === $kind || 'file_search_tool_call' === $kind ) {
                                $files++;
                            } elseif ( 'function_call' === $kind ) {
                                $calls[] = array( 'id' => isset( $item['call_id'] ) ? (string) $item['call_id'] : '', 'name' => isset( $item['name'] ) ? (string) $item['name'] : '', 'args' => self::args( isset( $item['arguments'] ) ? $item['arguments'] : '' ) );
                            }
                            yield array( 'tick' );
                            break;

                        case 'response.failed':
                            $ended = true;
                            $error = isset( $data['response']['error'] ) ? (array) $data['response']['error'] : array();
                            yield self::failed( isset( $error['message'] ) ? (string) $error['message'] : 'Unknown error', $this->stream_kind( $error ), isset( $data['response']['usage'] ) && is_array( $data['response']['usage'] ) ? $data['response']['usage'] : array() );
                            return null;

                        case 'error':
                            $ended = true;
                            $error = isset( $data['error'] ) ? (array) $data['error'] : $data;
                            yield self::failed( isset( $data['message'] ) ? (string) $data['message'] : ( isset( $error['message'] ) ? (string) $error['message'] : 'Unknown error' ), $this->stream_kind( $error ) );
                            return null;

                        case 'response.incomplete':
                        case 'response.completed':
                            $ended = true;
                            $meta  = array(
                                'response_id'  => $response_id,
                                'message_id'   => $message_id,
                                'model'        => $model,
                                'provider'     => 'openai',
                                'service_tier' => isset( $data['response']['service_tier'] ) ? (string) $data['response']['service_tier'] : '',
                                'usage'        => isset( $data['response']['usage'] ) && is_array( $data['response']['usage'] ) ? $data['response']['usage'] : array(),
                            );

                            if ( 'response.incomplete' === $type ) {
                                $reason = isset( $data['response']['incomplete_details']['reason'] ) ? (string) $data['response']['incomplete_details']['reason'] : 'unknown';

                                if ( ! $has_text && empty( $images ) && empty( $calls ) ) {
                                    yield self::failed( 'response incomplete: ' . $reason, 'content_filter' === $reason ? 'refused' : 'empty', $meta['usage'] );
                                    return null;
                                }
                                $meta['incomplete'] = $reason;
                            }

                            if ( '' !== (string) $request['conversation'] ) {
                                $meta['conversation_id'] = (string) $request['conversation'];
                            }
                            if ( ! empty( $images ) ) {
                                $meta['images_generated'] = $images;
                            }
                            if ( ! empty( $data['response']['tool_usage']['image_gen'] ) ) {
                                $meta['image_generation_usage'] = array( 'model' => $image, 'usage' => $data['response']['tool_usage']['image_gen'] );
                            }
                            if ( $web > 0 ) {
                                $meta['web_search_calls'] = $web;
                            }
                            if ( $files > 0 ) {
                                $meta['file_search_calls'] = $files;
                            }
                            break 2;

                        default:
                            yield array( 'tick' );
                    }
                }

                if ( ! $ended ) {
                    yield self::failed( 'response interrupted: the stream ended before the response was completed', 'down' );
                    return null;
                }
            } catch ( \Throwable $e ) {
                yield $this->error_part( $e );
                return null;
            }

            return array( 'calls' => $calls, 'meta' => $meta, 'native' => array( 'items' => $items, 'response_id' => $response_id ) );
        }

        private function stream_kind( array $error )
        {
            $kind = $this->classify( 0, array( 'error' => $error ), '' );

            return 'request' === $kind && empty( $error['code'] ) && empty( $error['type'] ) ? 'down' : $kind;
        }

        protected function follow( array $state, array $turn, array $results, array $request )
        {
            $outputs = array();
            foreach ( $results as $result ) {
                $outputs[] = array( 'type' => 'function_call_output', 'call_id' => $result['id'], 'output' => $result['content'] );
            }

            if ( false !== $request['store'] ) {
                return array( 'input' => $outputs, 'previous' => '' !== (string) $request['conversation'] ? '' : (string) $turn['native']['response_id'] );
            }

            foreach ( (array) $turn['native']['items'] as $item ) {
                $type = isset( $item['type'] ) ? $item['type'] : '';

                if ( 'reasoning' === $type && ! empty( $item['encrypted_content'] ) ) {
                    $state['input'][] = array( 'type' => 'reasoning', 'id' => (string) $item['id'], 'summary' => isset( $item['summary'] ) ? (array) $item['summary'] : array(), 'encrypted_content' => (string) $item['encrypted_content'] );
                } elseif ( 'function_call' === $type ) {
                    $state['input'][] = array( 'type' => 'function_call', 'call_id' => (string) $item['call_id'], 'name' => (string) $item['name'], 'arguments' => isset( $item['arguments'] ) ? (string) $item['arguments'] : '{}' );
                } elseif ( 'message' === $type ) {
                    $text = '';
                    foreach ( isset( $item['content'] ) ? (array) $item['content'] : array() as $block ) {
                        $text .= isset( $block['text'] ) ? (string) $block['text'] : '';
                    }
                    if ( '' !== $text ) {
                        $state['input'][] = array( 'role' => 'assistant', 'content' => array( array( 'type' => 'output_text', 'text' => $text ) ) );
                    }
                }
            }

            $state['input'] = array_merge( $state['input'], $outputs );

            return $state;
        }

        public function cancel( $id )
        {
            try {
                $data = json_decode( (string) $this->api()->post( 'responses/' . rawurlencode( (string) $id ) . '/cancel', array( 'timeout' => 30 ) )->getBody(), true );

                return isset( $data['status'] ) && 'cancelled' === $data['status'];
            } catch ( \Throwable $e ) {
                return false;
            }
        }

        public function response( $id )
        {
            try {
                return json_decode( (string) $this->api()->get( 'responses/' . rawurlencode( (string) $id ) )->getBody(), true );
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }
        }

        public function response_input( $id )
        {
            try {
                $data = json_decode( (string) $this->api()->get( 'responses/' . rawurlencode( (string) $id ) . '/input_items?limit=1' )->getBody(), true );

                return isset( $data['data'][0]['id'] ) ? (string) $data['data'][0]['id'] : WordPlus_SDK_AI_Http::error( 'request', 'Response input not found' );
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }
        }

        public function conversation( array $metadata = array() )
        {
            try {
                $body = empty( $metadata ) ? array() : array( 'metadata' => $metadata );

                return json_decode( (string) $this->api()->post( 'conversations', array( 'json' => $body ) )->getBody(), true );
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }
        }

        public function delete_conversation_item( $conversation, $item )
        {
            try {
                $this->api()->delete( 'conversations/' . rawurlencode( (string) $conversation ) . '/items/' . rawurlencode( (string) $item ) );

                return true;
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }
        }

        public static function conversation_gone( $message )
        {
            $lower = strtolower( (string) $message );

            return false !== strpos( $lower, 'conversation' ) && false !== strpos( $lower, 'not found' );
        }

        public function moderate( $text = '', $image_data_uris = array() )
        {
            $text   = trim( (string) $text );
            $images = is_array( $image_data_uris ) ? array_values( $image_data_uris ) : array();

            if ( '' === $text && empty( $images ) ) {
                return new WP_Error( 'empty_content', 'No content to moderate' );
            }

            $input = array();
            if ( '' !== $text ) {
                $input[] = array( 'type' => 'text', 'text' => $text );
            }
            foreach ( $images as $uri ) {
                $input[] = array( 'type' => 'image_url', 'image_url' => array( 'url' => (string) $uri ) );
            }

            try {
                $response = $this->api()->post( 'moderations', array(
                    'json'            => array( 'model' => 'omni-moderation-latest', 'input' => $input ),
                    'timeout'         => empty( $images ) ? 10 : 30,
                    'connect_timeout' => 5,
                ) );
                $data = json_decode( (string) $response->getBody(), true );

                return isset( $data['results'][0] ) ? $data['results'][0] : new WP_Error( 'invalid_response', 'Invalid response from OpenAI Moderation API' );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'openai_error', WordPlus_SDK_AI_Http::message( $e ) );
            }
        }

        public function transcribe( $file, array $options = array() )
        {
            if ( ! is_readable( $file ) ) {
                return new WP_Error( 'openai_error', 'The recording could not be read' );
            }

            $parts = array(
                array( 'name' => 'file', 'contents' => \WordPlusSDK\GuzzleHttp\Psr7\Utils::tryFopen( $file, 'r' ), 'filename' => basename( $file ) ),
                array( 'name' => 'model', 'contents' => ! empty( $options['model'] ) ? (string) $options['model'] : 'gpt-4o-mini-transcribe' ),
                array( 'name' => 'response_format', 'contents' => 'text' ),
            );

            if ( isset( $options['prompt'] ) && '' !== trim( (string) $options['prompt'] ) ) {
                $parts[] = array( 'name' => 'prompt', 'contents' => trim( (string) $options['prompt'] ) );
            }

            try {
                return trim( (string) $this->api( false )->post( 'audio/transcriptions', array( 'multipart' => $parts, 'timeout' => 120, 'connect_timeout' => 10 ) )->getBody() );
            } catch ( \Throwable $e ) {
                return new WP_Error( 'openai_error', WordPlus_SDK_AI_Http::message( $e ) );
            }
        }

        public function audio_reply( $model, array $messages, $voice = 'alloy', $user = '' )
        {
            $body = array(
                'model'      => (string) $model,
                'modalities' => array( 'text', 'audio' ),
                'messages'   => $messages,
                'user'       => (string) $user,
                'audio'      => array( 'format' => 'mp3', 'voice' => (string) $voice ),
            );

            try {
                $data = json_decode( (string) $this->api()->post( 'chat/completions', array( 'json' => $body ) )->getBody(), true );
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }

            if ( empty( $data['choices'][0]['message'] ) ) {
                return WordPlus_SDK_AI_Http::error( 'empty', 'No response received from API' );
            }

            $message = $data['choices'][0]['message'];
            $answer  = array(
                'text' => isset( $message['content'] ) ? (string) $message['content'] : '',
                'meta' => array( 'usage' => isset( $data['usage'] ) ? $data['usage'] : array(), 'model' => isset( $data['model'] ) ? $data['model'] : $model, 'provider' => 'openai' ),
            );

            if ( isset( $message['audio'] ) && is_array( $message['audio'] ) ) {
                $answer['audio'] = array(
                    'id'         => isset( $message['audio']['id'] ) ? (string) $message['audio']['id'] : '',
                    'data'       => isset( $message['audio']['data'] ) ? (string) $message['audio']['data'] : '',
                    'expires_at' => isset( $message['audio']['expires_at'] ) ? (int) $message['audio']['expires_at'] : 0,
                    'transcript' => isset( $message['audio']['transcript'] ) ? (string) $message['audio']['transcript'] : '',
                );
            }

            return $answer;
        }
    }
}
