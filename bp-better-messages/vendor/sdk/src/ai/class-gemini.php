<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Gemini' ) ) {
    final class WordPlus_SDK_AI_Gemini extends WordPlus_SDK_AI_Provider
    {
        const URL = 'https://generativelanguage.googleapis.com/v1beta/';

        const CONTEXT = 900000;

        const LINK_MAX = 15728640;

        const SCHEMA_KEYS = array( 'type', 'description', 'properties', 'required', 'enum', 'items', 'format', 'nullable', 'minimum', 'maximum' );

        public function id()
        {
            return 'gemini';
        }

        public function name()
        {
            return 'Google Gemini';
        }

        public function features()
        {
            return array( 'images', 'files', 'imagesGeneration', 'webSearch', 'extendedThinking', 'temperature', 'maxOutputTokens', 'tools' );
        }

        private function api()
        {
            return $this->client( self::URL, array( 'x-goog-api-key' => $this->key ) );
        }

        private static function generation( $model )
        {
            return preg_match( '/^(?:models\/)?gemini-(\d+)/', (string) $model, $m ) ? (int) $m[1] : 0;
        }

        public function input_rules( $model, array $context = array() )
        {
            $links = self::generation( $model ) >= 3 ? array( 'image/jpeg', 'image/png', 'image/webp', 'image/bmp', 'application/pdf' ) : array();

            return array( 'images' => array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp' ), 'links' => $links, 'link_max' => self::LINK_MAX, 'max' => self::MAX_FILE );
        }

        protected function fetch_models( array $options )
        {
            $models = array();
            $token  = '';

            try {
                for ( $page = 0; $page < 20; $page++ ) {
                    $data = json_decode( (string) $this->api()->request( 'GET', 'models?pageSize=100' . ( '' !== $token ? '&pageToken=' . rawurlencode( $token ) : '' ) )->getBody(), true );

                    foreach ( isset( $data['models'] ) && is_array( $data['models'] ) ? $data['models'] : array() as $model ) {
                        if ( isset( $model['name'] ) ) {
                            $models[] = array(
                                'name'    => (string) $model['name'],
                                'methods' => isset( $model['supportedGenerationMethods'] ) ? array_values( (array) $model['supportedGenerationMethods'] ) : array(),
                                'input'   => isset( $model['inputTokenLimit'] ) ? (int) $model['inputTokenLimit'] : 0,
                            );
                        }
                    }

                    $token = isset( $data['nextPageToken'] ) ? (string) $data['nextPageToken'] : '';
                    if ( '' === $token ) {
                        break;
                    }
                }
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }

            return $models;
        }

        protected function chat_models( array $all, array $options )
        {
            $models = array();

            foreach ( $all as $model ) {
                $id = str_replace( 'models/', '', isset( $model['name'] ) ? (string) $model['name'] : '' );

                if ( in_array( 'generateContent', isset( $model['methods'] ) ? (array) $model['methods'] : array(), true ) && 0 === strpos( $id, 'gemini' ) ) {
                    $models[] = $id;
                }
            }

            sort( $models );

            return $models;
        }

        private function context( $model )
        {
            $all = get_transient( 'wordplus_ai_models_gemini_' . substr( hash( 'sha256', $this->key ), 0, 12 ) );

            foreach ( is_array( $all ) ? $all : array() as $entry ) {
                if ( isset( $entry['name'], $entry['input'] ) && str_replace( 'models/', '', $entry['name'] ) === $model && $entry['input'] > 0 ) {
                    return (int) $entry['input'];
                }
            }

            return self::CONTEXT;
        }

        public function check()
        {
            try {
                $this->api()->request( 'GET', 'models?pageSize=1' );

                return true;
            } catch ( \Throwable $e ) {
                return $this->failure( $e );
            }
        }

        public function classify( $status, $json, $raw )
        {
            $error   = isset( $json['error'] ) ? (array) $json['error'] : array();
            $state   = isset( $error['status'] ) ? (string) $error['status'] : '';
            $message = strtolower( isset( $error['message'] ) ? (string) $error['message'] : (string) $raw );
            $reasons = strtolower( (string) wp_json_encode( isset( $error['details'] ) ? $error['details'] : array() ) );
            $status  = (int) $status;

            if ( 401 === $status || 403 === $status || 'PERMISSION_DENIED' === $state || 'UNAUTHENTICATED' === $state || false !== strpos( $reasons, 'api_key_invalid' ) || false !== strpos( $message, 'api key not valid' ) || false !== strpos( $message, 'api key expired' ) ) {
                return 'key';
            }
            if ( false !== strpos( $message, 'billing' ) || false !== strpos( $message, 'prepayment credits are depleted' ) ) {
                return 'quota';
            }
            if ( 404 === $status || 'NOT_FOUND' === $state ) {
                return 'model';
            }
            if ( 429 === $status || 'RESOURCE_EXHAUSTED' === $state ) {
                return 'rate';
            }
            if ( $status >= 500 || 'UNAVAILABLE' === $state || 'INTERNAL' === $state ) {
                return 'down';
            }

            return 'request';
        }

        public function makes_images( $model )
        {
            $images = $this->ai->catalogue()->images();
            $model  = strtolower( (string) $model );
            $base   = preg_replace( '/-(preview|latest)$/', '', $model );

            return isset( $images[ $model ] ) || ( $base !== $model && isset( $images[ $base ] ) );
        }

        protected function begin( array $request )
        {
            $contents = array();

            foreach ( self::turns( (array) $request['messages'] ) as $turn ) {
                $parts = array();

                if ( 'assistant' === $turn['role'] ) {
                    foreach ( $turn['thoughts'] as $thought ) {
                        $part = array( 'thought' => true, 'text' => isset( $thought['text'] ) ? (string) $thought['text'] : '' );
                        if ( ! empty( $thought['thoughtSignature'] ) ) {
                            $part['thoughtSignature'] = (string) $thought['thoughtSignature'];
                        }
                        $parts[] = $part;
                    }
                }

                foreach ( $turn['parts'] as $part ) {
                    $type = isset( $part['type'] ) ? $part['type'] : 'text';

                    if ( 'text' === $type ) {
                        if ( '' !== (string) ( isset( $part['text'] ) ? $part['text'] : '' ) ) {
                            $parts[] = array( 'text' => (string) $part['text'] );
                        }
                    } elseif ( 'user' === $turn['role'] && ( 'image' === $type || 'file' === $type ) ) {
                        $parts[] = ! empty( $part['url'] )
                            ? array( 'file_data' => array( 'mime_type' => (string) $part['mime'], 'file_uri' => (string) $part['url'] ) )
                            : array( 'inline_data' => array( 'mime_type' => (string) $part['mime'], 'data' => (string) $part['data'] ) );
                    }
                }

                if ( ! empty( $parts ) ) {
                    $contents[] = array( 'role' => 'assistant' === $turn['role'] ? 'model' : 'user', 'parts' => $parts );
                }
            }

            $limit    = (int) $request['context_limit'] > 0 ? (int) $request['context_limit'] : $this->context( (string) $request['model'] );
            $contents = self::trim_turns( $contents, $limit, function ( $content ) {
                $tokens = 0;
                foreach ( $content['parts'] as $part ) {
                    $tokens += isset( $part['text'] ) ? (int) ( mb_strlen( $part['text'] ) / 4 ) : 0;
                    $tokens += isset( $part['inline_data']['data'] ) ? 258 : 0;
                }

                return $tokens;
            } );

            if ( empty( $contents ) ) {
                return WordPlus_SDK_AI_Http::error( 'request', 'No messages to process' );
            }

            return array( 'contents' => $contents );
        }

        const STRING_FORMATS = array( 'enum', 'date-time' );

        private static function gemini_schema( array $schema )
        {
            $out = array_intersect_key( $schema, array_flip( self::SCHEMA_KEYS ) );

            if ( isset( $out['format'], $out['type'] ) && 'string' === $out['type'] && ! in_array( $out['format'], self::STRING_FORMATS, true ) ) {
                unset( $out['format'] );
            }

            if ( isset( $out['properties'] ) && ( is_array( $out['properties'] ) || $out['properties'] instanceof \stdClass ) ) {
                $properties = array();
                foreach ( (array) $out['properties'] as $name => $property ) {
                    $properties[ $name ] = is_array( $property ) ? self::gemini_schema( $property ) : $property;
                }
                if ( empty( $properties ) ) {
                    unset( $out['properties'] );
                } else {
                    $out['properties'] = $properties;
                }
            }
            if ( isset( $out['items'] ) && is_array( $out['items'] ) ) {
                $out['items'] = self::gemini_schema( $out['items'] );
            }

            return $out;
        }

        private function body( array $state, array $request, $last )
        {
            $model  = (string) $request['model'];
            $rules  = $this->rules( $model );
            $config = array();

            if ( null !== $request['temperature'] && '' !== $request['temperature'] && is_numeric( $request['temperature'] ) && 'any' === $rules['sampling'] ) {
                $config['temperature'] = (float) $request['temperature'];
            }
            if ( (int) $request['max_tokens'] > 0 ) {
                $config['maxOutputTokens'] = (int) $request['max_tokens'];
            }

            if ( ! empty( $request['thinking']['on'] ) || '' !== (string) $request['effort'] ) {
                $budget = (int) $request['thinking']['budget'];

                if ( 'level' === $rules['thinking'] ) {
                    $level = self::effort_for( $request['effort'], $budget, (array) $rules['levels'] );
                    if ( '' !== $level ) {
                        $config['thinkingConfig'] = array( 'thinkingLevel' => $level );
                    }
                } elseif ( 'budget' === $rules['thinking'] && $budget > 0 ) {
                    $range                    = (array) $rules['budget'];
                    $config['thinkingConfig'] = array( 'thinkingBudget' => 2 === count( $range ) ? max( $range[0], min( $range[1], $budget ) ) : $budget );
                }
            }

            if ( ! empty( $request['image_generation'] ) && $this->makes_images( $model ) ) {
                $config['responseModalities'] = array( 'TEXT', 'IMAGE' );
            }

            $body = array( 'contents' => $state['contents'] );

            if ( '' !== (string) $request['system'] ) {
                $body['system_instruction'] = array( 'parts' => array( array( 'text' => (string) $request['system'] ) ) );
            }
            if ( ! empty( $config ) ) {
                $body['generationConfig'] = $config;
            }

            $tools = array();
            if ( ! empty( $request['web_search'] ) ) {
                $tools[] = array( 'google_search' => new \stdClass() );
            }
            if ( ! empty( $request['tools'] ) ) {
                $tools[] = array( 'functionDeclarations' => array_map( function ( $tool ) {
                    return array(
                        'name'        => (string) $tool['name'],
                        'description' => (string) ( isset( $tool['description'] ) ? $tool['description'] : '' ),
                        'parameters'  => self::gemini_schema( self::schema( isset( $tool['parameters'] ) ? (array) $tool['parameters'] : array() ) ),
                    );
                }, array_values( (array) $request['tools'] ) ) );

                if ( $last ) {
                    $body['toolConfig'] = array( 'functionCallingConfig' => array( 'mode' => 'NONE' ) );
                }
            }
            if ( ! empty( $tools ) ) {
                $body['tools'] = $tools;
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
            $body     = $this->body( $state, $request, $last );
            $model    = (string) $request['model'];
            $path     = 'models/' . rawurlencode( $model ) . ( $request['stream'] ? ':streamGenerateContent?alt=sse' : ':generateContent' );
            $max      = isset( $request['image_generation']['max'] ) ? (int) $request['image_generation']['max'] : 0;
            $usage    = array();
            $web      = 0;
            $images   = array();
            $thoughts = array();
            $calls    = array();
            $native   = array();

            try {
                $response = $this->api()->post( $path, array( 'json' => $body, 'stream' => (bool) $request['stream'], 'timeout' => (int) $request['timeout'] ) );
                $events   = $request['stream'] ? self::events( $response->getBody() ) : array( json_decode( (string) $response->getBody(), true ) );

                foreach ( $events as $data ) {
                    if ( ! is_array( $data ) ) {
                        continue;
                    }

                    if ( isset( $data['error'] ) && is_array( $data['error'] ) ) {
                        $code = isset( $data['error']['code'] ) ? (int) $data['error']['code'] : 0;
                        $kind = $this->classify( $code, $data, '' );
                        $kind = 'request' === $kind && 0 === $code && empty( $data['error']['status'] ) ? 'down' : $kind;
                        yield self::failed( isset( $data['error']['message'] ) ? (string) $data['error']['message'] : 'Unknown Gemini error', $kind, $usage, array( 'status' => $code ) );
                        return null;
                    }

                    if ( isset( $data['promptFeedback']['blockReason'] ) ) {
                        yield self::failed( 'Prompt blocked by Gemini: ' . $data['promptFeedback']['blockReason'], 'refused', $usage );
                        return null;
                    }

                    foreach ( isset( $data['candidates'][0]['content']['parts'] ) ? (array) $data['candidates'][0]['content']['parts'] : array() as $index => $part ) {
                        if ( ! is_array( $part ) ) {
                            continue;
                        }

                        $native[] = $part;

                        if ( isset( $part['thought'] ) && true === $part['thought'] ) {
                            $thought = array( 'text' => isset( $part['text'] ) ? (string) $part['text'] : '' );
                            if ( isset( $part['thoughtSignature'] ) ) {
                                $thought['thoughtSignature'] = (string) $part['thoughtSignature'];
                            }
                            $thoughts[] = $thought;
                            continue;
                        }

                        if ( isset( $part['functionCall'] ) && is_array( $part['functionCall'] ) ) {
                            $calls[] = array(
                                'id'   => isset( $part['functionCall']['id'] ) ? (string) $part['functionCall']['id'] : 'call_' . count( $calls ),
                                'name' => isset( $part['functionCall']['name'] ) ? (string) $part['functionCall']['name'] : '',
                                'args' => self::args( isset( $part['functionCall']['args'] ) ? $part['functionCall']['args'] : array() ),
                            );
                            continue;
                        }

                        if ( isset( $part['text'] ) && '' !== (string) $part['text'] ) {
                            yield (string) $part['text'];
                        }

                        if ( isset( $part['inlineData']['mimeType'] ) && 0 === strpos( (string) $part['inlineData']['mimeType'], 'image/' ) && ( $max <= 0 || count( $images ) < $max ) ) {
                            $mime     = (string) $part['inlineData']['mimeType'];
                            $formats  = array( 'image/png' => 'png', 'image/jpeg' => 'jpeg', 'image/webp' => 'webp', 'image/gif' => 'gif' );
                            $made     = array( 'model' => $model, 'format' => isset( $formats[ $mime ] ) ? $formats[ $mime ] : 'png', 'mime' => $mime, 'data' => isset( $part['inlineData']['data'] ) ? (string) $part['inlineData']['data'] : '' );
                            $images[] = self::image( $request, $made );
                            yield array( 'image', $made );
                        }
                    }

                    if ( isset( $data['candidates'][0]['groundingMetadata']['searchEntryPoint'] ) ) {
                        $web++;
                    }
                    if ( isset( $data['usageMetadata'] ) && is_array( $data['usageMetadata'] ) ) {
                        $usage = $data['usageMetadata'];
                    }
                    if ( isset( $data['modelVersion'] ) ) {
                        $model = (string) $data['modelVersion'];
                    }
                    if ( isset( $data['candidates'][0]['finishReason'] ) && 'SAFETY' === $data['candidates'][0]['finishReason'] ) {
                        yield self::failed( 'Response blocked by Gemini safety filters', 'refused', $usage );
                        return null;
                    }

                    yield array( 'tick' );
                }
            } catch ( \Throwable $e ) {
                yield $this->error_part( $e, $usage );
                return null;
            }

            $meta = array( 'model' => $model, 'provider' => 'gemini' );
            if ( ! empty( $usage ) ) {
                $meta['usage'] = $usage;
            }
            if ( $web > 0 ) {
                $meta['web_search_calls'] = $web;
            }
            if ( ! empty( $images ) ) {
                $meta['images_generated'] = $images;
            }
            if ( ! empty( $thoughts ) ) {
                $meta['gemini_thought_parts'] = $thoughts;
            }

            return array( 'calls' => $calls, 'meta' => $meta, 'native' => $native );
        }

        protected function follow( array $state, array $turn, array $results, array $request )
        {
            $state['contents'][] = array( 'role' => 'model', 'parts' => array_values( (array) $turn['native'] ) );

            $parts = array();
            foreach ( $results as $result ) {
                $response = array( 'name' => $result['name'], 'response' => array( 'result' => $result['content'] ) );
                if ( 0 !== strpos( $result['id'], 'call_' ) ) {
                    $response['id'] = $result['id'];
                }
                $parts[] = array( 'functionResponse' => $response );
            }

            $state['contents'][] = array( 'role' => 'user', 'parts' => $parts );

            return $state;
        }
    }
}
