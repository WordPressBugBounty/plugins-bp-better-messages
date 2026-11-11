<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_MCP' ) ) {
    final class WordPlus_SDK_AI_MCP
    {
        const PROTOCOL = '2025-11-25';

        const PROTOCOLS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

        const SESSION = 3600;

        const MAX = 1048576;

        const TIMEOUT = 15;

        const PAGES = 50;

        const TOOL_NAME = '/^[A-Za-z0-9_.\-\/]{1,128}$/';

        private $ai;

        private $url;

        private $auth;

        private $next = 1;

        public function __construct( WordPlus_SDK_AI $ai, $url, array $auth = array() )
        {
            $this->ai   = $ai;
            $this->url  = trim( (string) $url );
            $this->auth = array_merge( array( 'type' => 'none', 'header' => 'Authorization', 'value' => '', 'owner' => '', 'network' => '' ), $auth );
        }

        public function url()
        {
            return $this->url;
        }

        public function initialize( $timeout = self::TIMEOUT, $fresh = false )
        {
            $kept = get_transient( $this->key() );

            if ( ! $fresh && is_array( $kept ) ) {
                return $kept;
            }

            $started = microtime( true );
            $id      = $this->next++;
            $answer  = $this->post( array(
                'jsonrpc' => '2.0',
                'id'      => $id,
                'method'  => 'initialize',
                'params'  => array(
                    'protocolVersion' => self::PROTOCOL,
                    'capabilities'    => new \stdClass(),
                    'clientInfo'      => array( 'name' => (string) apply_filters( 'wordplus_ai_mcp_client', 'WordPlus SDK' ), 'version' => (string) WordPlus_SDK_AI::API ),
                ),
            ), $id, $timeout, array( 'session' => '', 'protocol' => '' ) );

            if ( is_wp_error( $answer ) ) {
                $data = (array) $answer->get_error_data();

                return in_array( isset( $data['status'] ) ? (int) $data['status'] : 0, array( 400, 404, 405 ), true ) && $this->older_way( $timeout ) ? self::failed( 'sse', self::older_words() ) : $answer;
            }

            $result   = (array) $answer['result'];
            $protocol = isset( $result['protocolVersion'] ) ? (string) $result['protocolVersion'] : '';

            if ( ! in_array( $protocol, self::PROTOCOLS, true ) ) {
                return self::failed( 'protocol', sprintf( /* translators: %s: a version of MCP */ _x( 'The server speaks MCP %s, which this site does not.', 'MCP', 'wordplus-sdk' ), $protocol ) );
            }

            $session = array(
                'id'           => (string) $answer['session'],
                'protocol'     => $protocol,
                'session'      => '' !== (string) $answer['session'],
                'server'       => array(
                    'name'    => self::words( isset( $result['serverInfo']['name'] ) ? $result['serverInfo']['name'] : '' ),
                    'title'   => self::words( isset( $result['serverInfo']['title'] ) ? $result['serverInfo']['title'] : '' ),
                    'version' => self::words( isset( $result['serverInfo']['version'] ) ? $result['serverInfo']['version'] : '' ),
                ),
                'capabilities' => isset( $result['capabilities'] ) ? (array) $result['capabilities'] : array(),
                'instructions' => self::words( isset( $result['instructions'] ) ? $result['instructions'] : '' ),
            );

            $this->post( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), null, $timeout, $session );

            $session['ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

            set_transient( $this->key(), $session, self::SESSION );

            return $session;
        }

        public function tools( $timeout = self::TIMEOUT )
        {
            $tools  = array();
            $cursor = '';

            for ( $page = 0; $page < self::PAGES; $page++ ) {
                $answer = $this->request( 'tools/list', '' === $cursor ? new \stdClass() : array( 'cursor' => $cursor ), $timeout );

                if ( is_wp_error( $answer ) ) {
                    return $answer;
                }

                foreach ( isset( $answer['tools'] ) && is_array( $answer['tools'] ) ? $answer['tools'] : array() as $tool ) {
                    if ( is_array( $tool ) && isset( $tool['name'] ) && 1 === preg_match( self::TOOL_NAME, (string) $tool['name'] ) ) {
                        $tools[] = self::tool( $tool );
                    }
                }

                $cursor = isset( $answer['nextCursor'] ) ? (string) $answer['nextCursor'] : '';

                if ( '' === $cursor ) {
                    break;
                }
            }

            return $tools;
        }

        private static function tool( array $tool )
        {
            $kept = array(
                'name'         => (string) $tool['name'],
                'title'        => self::words( isset( $tool['title'] ) ? $tool['title'] : ( isset( $tool['annotations']['title'] ) ? $tool['annotations']['title'] : '' ) ),
                'description'  => self::words( isset( $tool['description'] ) ? $tool['description'] : '' ),
                'input_schema' => isset( $tool['inputSchema'] ) && is_array( $tool['inputSchema'] ) ? $tool['inputSchema'] : array( 'type' => 'object' ),
                'annotations'  => isset( $tool['annotations'] ) && is_array( $tool['annotations'] ) ? array_intersect_key( $tool['annotations'], array_flip( array( 'readOnlyHint', 'destructiveHint', 'idempotentHint', 'openWorldHint' ) ) ) : array(),
            );

            $kept['hash'] = self::hash( $kept );

            return $kept;
        }

        public static function for_model( array $schema )
        {
            $out = self::plain_schema( $schema, $schema, 0 );

            return isset( $out['type'] ) && 'object' === $out['type'] ? $out : array( 'type' => 'object' );
        }

        private static function plain_schema( array $node, array $root, $depth )
        {
            if ( $depth > 12 ) {
                return array( 'type' => 'object' );
            }

            if ( isset( $node['$ref'] ) && is_string( $node['$ref'] ) ) {
                $path = explode( '/', ltrim( $node['$ref'], '#/' ) );
                $hit  = $root;

                foreach ( $path as $step ) {
                    $hit = is_array( $hit ) && isset( $hit[ $step ] ) ? $hit[ $step ] : null;
                }

                $rest = $node;
                unset( $rest['$ref'] );

                return self::plain_schema( array_merge( is_array( $hit ) ? $hit : array( 'type' => 'object' ), $rest ), $root, $depth + 1 );
            }

            if ( isset( $node['allOf'] ) && is_array( $node['allOf'] ) ) {
                $merged = $node;
                unset( $merged['allOf'] );

                foreach ( $node['allOf'] as $part ) {
                    if ( is_array( $part ) ) {
                        $part             = self::plain_schema( $part, $root, $depth + 1 );
                        $properties       = array_merge( isset( $merged['properties'] ) ? (array) $merged['properties'] : array(), isset( $part['properties'] ) ? (array) $part['properties'] : array() );
                        $required         = array_merge( isset( $merged['required'] ) ? (array) $merged['required'] : array(), isset( $part['required'] ) ? (array) $part['required'] : array() );
                        $merged           = array_merge( $part, $merged );
                        if ( ! empty( $properties ) ) {
                            $merged['properties'] = $properties;
                        }
                        if ( ! empty( $required ) ) {
                            $merged['required'] = array_values( array_unique( $required ) );
                        }
                    }
                }

                return self::plain_schema( $merged, $root, $depth + 1 );
            }

            foreach ( array( 'anyOf', 'oneOf' ) as $choice ) {
                if ( isset( $node[ $choice ] ) && is_array( $node[ $choice ] ) ) {
                    $paths = array_values( array_filter( $node[ $choice ], function ( $path ) {
                        return is_array( $path ) && ! ( isset( $path['type'] ) && 'null' === $path['type'] );
                    } ) );
                    $null  = count( $paths ) < count( $node[ $choice ] );
                    $rest  = $node;
                    unset( $rest[ $choice ] );

                    $one = self::plain_schema( array_merge( isset( $paths[0] ) ? $paths[0] : array( 'type' => 'string' ), $rest ), $root, $depth + 1 );

                    return $null ? array_merge( $one, array( 'nullable' => true ) ) : $one;
                }
            }

            $out = array_diff_key( $node, array_flip( array( '$schema', '$id', '$comment', '$defs', 'definitions', 'title', 'examples', 'default', 'not', 'if', 'then', 'else', 'readOnly', 'writeOnly', 'deprecated' ) ) );

            if ( isset( $out['type'] ) && is_array( $out['type'] ) ) {
                $types       = array_values( array_diff( $out['type'], array( 'null' ) ) );
                $null        = count( $types ) < count( $out['type'] );
                $out['type'] = isset( $types[0] ) ? (string) $types[0] : 'string';

                if ( $null ) {
                    $out['nullable'] = true;
                }
            }

            if ( ! isset( $out['type'] ) ) {
                $out['type'] = isset( $out['properties'] ) ? 'object' : ( isset( $out['items'] ) ? 'array' : 'string' );
            }

            if ( isset( $out['properties'] ) && is_array( $out['properties'] ) ) {
                foreach ( $out['properties'] as $name => $property ) {
                    $out['properties'][ $name ] = is_array( $property ) ? self::plain_schema( $property, $root, $depth + 1 ) : array( 'type' => 'string' );
                }
            }

            if ( isset( $out['items'] ) && is_array( $out['items'] ) ) {
                $out['items'] = self::plain_schema( $out['items'], $root, $depth + 1 );
            }

            return $out;
        }

        public static function hash( array $tool )
        {
            $what = array( 'name' => isset( $tool['name'] ) ? $tool['name'] : '', 'title' => isset( $tool['title'] ) ? $tool['title'] : '', 'description' => isset( $tool['description'] ) ? $tool['description'] : '', 'input_schema' => isset( $tool['input_schema'] ) ? $tool['input_schema'] : array(), 'annotations' => isset( $tool['annotations'] ) ? $tool['annotations'] : array() );

            return hash( 'sha256', (string) wp_json_encode( self::sorted( $what ) ) );
        }

        private static function sorted( $value )
        {
            if ( ! is_array( $value ) ) {
                return $value;
            }

            if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
                ksort( $value );
            }

            return array_map( array( __CLASS__, 'sorted' ), $value );
        }

        public function call( $name, array $args = array(), $timeout = self::TIMEOUT )
        {
            $started = microtime( true );
            $answer  = $this->request( 'tools/call', array( 'name' => (string) $name, 'arguments' => empty( $args ) ? new \stdClass() : $args ), $timeout );
            $ms      = (int) round( ( microtime( true ) - $started ) * 1000 );

            if ( is_wp_error( $answer ) ) {
                return new WP_Error( $answer->get_error_code(), $answer->get_error_message(), array_merge( (array) $answer->get_error_data(), array( 'ms' => $ms ) ) );
            }

            $read = self::read_result( $answer );

            if ( ! empty( $answer['isError'] ) ) {
                return self::failed( 'tool', '' !== $read['text'] ? mb_substr( $read['text'], 0, 500 ) : _x( 'The tool failed.', 'MCP', 'wordplus-sdk' ), array( 'ms' => $ms ) );
            }

            return array_merge( $read, array( 'ms' => $ms ) );
        }

        private static function read_result( array $result )
        {
            $text     = array();
            $sources  = array();
            $left_out = 0;

            foreach ( isset( $result['content'] ) && is_array( $result['content'] ) ? $result['content'] : array() as $block ) {
                $type = is_array( $block ) && isset( $block['type'] ) ? (string) $block['type'] : '';

                if ( 'text' === $type && isset( $block['text'] ) ) {
                    $text[] = (string) $block['text'];
                } elseif ( 'resource_link' === $type && isset( $block['uri'] ) ) {
                    $sources[] = array( 'label' => self::words( isset( $block['title'] ) ? $block['title'] : ( isset( $block['name'] ) ? $block['name'] : '' ) ), 'url' => (string) $block['uri'] );
                } else {
                    ++$left_out;
                }
            }

            $structured = isset( $result['structuredContent'] ) && is_array( $result['structuredContent'] ) ? $result['structuredContent'] : null;

            if ( empty( $text ) && null !== $structured ) {
                $text[] = (string) wp_json_encode( $structured );
            }

            return array( 'text' => implode( "\n\n", $text ), 'structured' => $structured, 'sources' => $sources, 'left_out' => $left_out );
        }

        public function close()
        {
            $kept = get_transient( $this->key() );
            delete_transient( $this->key() );

            if ( ! is_array( $kept ) || empty( $kept['id'] ) ) {
                return;
            }

            $where   = $this->where();
            $headers = $this->headers( $kept );

            if ( is_wp_error( $where ) || is_wp_error( $headers ) ) {
                return;
            }

            try {
                $this->client( $where )->delete( '', self::pinned( array( 'headers' => $headers, 'timeout' => 5, 'connect_timeout' => 5, 'allow_redirects' => false, 'http_errors' => false ), $where ) );
            } catch ( \Throwable $e ) {
                return;
            }
        }

        public function forget()
        {
            delete_transient( $this->key() );
        }

        private function request( $method, $params, $timeout )
        {
            for ( $try = 0; $try < 2; $try++ ) {
                $session = $this->initialize( $timeout );

                if ( is_wp_error( $session ) ) {
                    return $session;
                }

                $id     = $this->next++;
                $answer = $this->post( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params ), $id, $timeout, $session );

                if ( ! is_wp_error( $answer ) ) {
                    return (array) $answer['result'];
                }

                $data   = (array) $answer->get_error_data();
                $status = isset( $data['status'] ) ? (int) $data['status'] : 0;

                if ( 0 === $try && 404 === $status && ! empty( $session['id'] ) ) {
                    $this->forget();
                    continue;
                }

                if ( 0 === $try && 401 === $status && 'oauth' === $this->auth['type'] && $this->renew() ) {
                    continue;
                }

                return $answer;
            }

            return $answer;
        }

        private function renew()
        {
            return ! is_wp_error( $this->ai->mcp_oauth( $this->url, (string) $this->auth['owner'], (string) $this->auth['network'] )->token( true ) );
        }

        private function post( array $message, $id, $timeout, array $session )
        {
            $headers = $this->headers( $session );

            if ( is_wp_error( $headers ) ) {
                return $headers;
            }

            $where = $this->where();

            if ( is_wp_error( $where ) ) {
                return $where;
            }

            $sink    = new WordPlus_SDK_AI_MCP_Sink( self::MAX, $id );
            $status  = 0;
            $named   = '';
            $www     = '';
            $type    = '';
            $options = array(
                'body'            => (string) wp_json_encode( $message ),
                'headers'         => $headers,
                'timeout'         => (int) $timeout,
                'connect_timeout' => min( 5, (int) $timeout ),
                'allow_redirects' => false,
                'http_errors'     => false,
                'sink'            => $sink,
                'on_headers'      => function ( $response ) use ( &$status, &$named, &$www, &$type, $sink ) {
                    $status = (int) $response->getStatusCode();
                    $named  = $response->getHeaderLine( 'Mcp-Session-Id' );
                    $www    = $response->getHeaderLine( 'WWW-Authenticate' );
                    $type   = strtolower( $response->getHeaderLine( 'Content-Type' ) );

                    if ( false !== strpos( $type, 'text/event-stream' ) ) {
                        $sink->events();
                    }
                },
            );

            try {
                $this->client( $where )->post( '', self::pinned( $options, $where ) );
            } catch ( \Throwable $e ) {
                if ( null === $sink->found() ) {
                    if ( $sink->over() ) {
                        return self::failed( 'size', _x( 'The server answered more than 1 MB.', 'MCP', 'wordplus-sdk' ), array( 'status' => $status ) );
                    }

                    return self::unanswered( $e, $timeout );
                }
            }

            if ( null === $id ) {
                return array( 'result' => array(), 'session' => $named );
            }

            return $this->answer( $sink, $status, $type, $named, $www );
        }

        private function answer( WordPlus_SDK_AI_MCP_Sink $sink, $status, $type, $named, $www )
        {
            if ( 401 === $status || 403 === $status ) {
                return self::failed( 'auth', _x( 'The server refused the sign in.', 'MCP', 'wordplus-sdk' ), array( 'status' => $status, 'www' => $www ) );
            }

            if ( $status < 200 || $status >= 300 ) {
                return self::failed( 'http', sprintf( /* translators: %d: an HTTP status */ _x( 'The server answered with the status %d.', 'MCP', 'wordplus-sdk' ), $status ), array( 'status' => $status ) );
            }

            $sink->finish();

            $message = $sink->found();

            if ( null === $message ) {
                $sink->rewind();
                $message = json_decode( (string) $sink->getContents(), true );
            }

            if ( ! is_array( $message ) ) {
                return self::failed( 'answer', _x( 'The server gave no answer that could be read.', 'MCP', 'wordplus-sdk' ), array( 'status' => $status ) );
            }

            if ( isset( $message['error'] ) ) {
                $code = isset( $message['error']['code'] ) ? (int) $message['error']['code'] : 0;
                $said = isset( $message['error']['message'] ) ? self::words( $message['error']['message'] ) : '';

                return self::failed( 'rpc', '' !== $said ? mb_substr( $said, 0, 500 ) : _x( 'The server answered with an error.', 'MCP', 'wordplus-sdk' ), array( 'status' => $status, 'code' => $code ) );
            }

            if ( ! isset( $message['result'] ) ) {
                return self::failed( 'answer', _x( 'The server gave no answer that could be read.', 'MCP', 'wordplus-sdk' ), array( 'status' => $status ) );
            }

            return array( 'result' => (array) $message['result'], 'session' => $named );
        }

        private function older_way( $timeout )
        {
            $where = $this->where();

            if ( is_wp_error( $where ) ) {
                return false;
            }

            $headers = $this->headers( array( 'id' => '', 'protocol' => '' ) );
            $sink    = new WordPlus_SDK_AI_MCP_Older_Sink();
            $headers = is_wp_error( $headers ) ? array() : $headers;

            try {
                $this->client( $where )->get( '', self::pinned( array(
                    'headers'         => array_merge( $headers, array( 'Accept' => 'text/event-stream' ) ),
                    'timeout'         => min( 5, (int) $timeout ),
                    'connect_timeout' => min( 5, (int) $timeout ),
                    'allow_redirects' => false,
                    'http_errors'     => false,
                    'sink'            => $sink,
                ), $where ) );
            } catch ( \Throwable $e ) {
                return $sink->seen();
            }

            return $sink->seen();
        }

        private static function older_words()
        {
            return _x( 'This address is the older SSE way of MCP, which this site does not speak. Ask its maker for its Streamable HTTP address, often ending in /mcp.', 'MCP', 'wordplus-sdk' );
        }

        private function where()
        {
            return WordPlus_SDK_AI_MCP_Address::read( $this->url, (string) $this->auth['network'] );
        }

        private static function pinned( array $options, array $where )
        {
            if ( ! empty( $where['pin'] ) && defined( 'CURLOPT_RESOLVE' ) ) {
                $options['curl'] = array( CURLOPT_RESOLVE => $where['pin'] );
            }

            return $options;
        }

        private function client( array $where )
        {
            return $this->ai->http( $where['url'], array(), 'mcp' );
        }

        private function headers( array $session )
        {
            $headers = array( 'Accept' => 'application/json, text/event-stream', 'Content-Type' => 'application/json' );

            if ( ! empty( $session['id'] ) ) {
                $headers['Mcp-Session-Id'] = (string) $session['id'];
            }
            if ( ! empty( $session['protocol'] ) ) {
                $headers['MCP-Protocol-Version'] = (string) $session['protocol'];
            }

            $sign = $this->sign_in();

            return is_wp_error( $sign ) ? $sign : array_merge( $headers, $sign );
        }

        private function sign_in()
        {
            if ( 'header' === $this->auth['type'] ) {
                $value = '' !== (string) $this->auth['value'] ? (string) $this->auth['value'] : $this->ai->keys()->get( 'mcp-header', (string) $this->auth['owner'] );

                if ( '' === $value ) {
                    return self::failed( 'auth', _x( 'The token of this server is gone.', 'MCP', 'wordplus-sdk' ) );
                }

                $header = '' !== (string) $this->auth['header'] ? (string) $this->auth['header'] : 'Authorization';

                return array( $header => self::header_value( $header, $value ) );
            }

            if ( 'oauth' === $this->auth['type'] ) {
                $token = $this->ai->mcp_oauth( $this->url, (string) $this->auth['owner'], (string) $this->auth['network'] )->token();

                return is_wp_error( $token ) ? $token : array( 'Authorization' => 'Bearer ' . $token );
            }

            return array();
        }

        public static function header_value( $header, $value )
        {
            $value = trim( (string) $value );

            return 0 === strcasecmp( (string) $header, 'Authorization' ) && false === strpos( $value, ' ' ) ? 'Bearer ' . $value : $value;
        }

        private function key()
        {
            return 'wordplus_ai_mcp_' . md5( $this->url . '|' . $this->auth['type'] . '|' . $this->auth['header'] . '|' . $this->auth['owner'] . '|' . $this->auth['value'] );
        }

        private static function unanswered( \Throwable $e, $timeout )
        {
            $timed = false !== stripos( $e->getMessage(), 'timed out' ) || false !== stripos( $e->getMessage(), 'timeout' ) || ( $e instanceof \WordPlusSDK\GuzzleHttp\Exception\ConnectException && false !== strpos( $e->getMessage(), 'cURL error 28' ) );

            return self::failed(
                'down',
                $timed
                    ? sprintf( /* translators: %d: seconds */ _x( 'No answer in %d seconds.', 'MCP', 'wordplus-sdk' ), (int) $timeout )
                    : _x( 'The server could not be reached.', 'MCP', 'wordplus-sdk' ),
                array( 'status' => 0, 'timeout' => $timed )
            );
        }

        public static function failed( $kind, $message, array $data = array() )
        {
            return new WP_Error( 'wordplus_ai_mcp_' . $kind, $message, array_merge( array( 'kind' => $kind ), $data ) );
        }

        public static function kind( $error )
        {
            $data = is_wp_error( $error ) ? (array) $error->get_error_data() : array();

            return isset( $data['kind'] ) ? (string) $data['kind'] : '';
        }

        private static function words( $value )
        {
            return is_scalar( $value ) ? trim( (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value ) ) : '';
        }
    }
}
