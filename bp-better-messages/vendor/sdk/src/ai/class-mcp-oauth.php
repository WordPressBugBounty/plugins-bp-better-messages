<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_MCP_OAuth' ) ) {
    final class WordPlus_SDK_AI_MCP_OAuth
    {
        const ACCESS  = 'mcp-access';
        const REFRESH = 'mcp-refresh';
        const CLIENT  = 'mcp-client';

        const EARLY = 60;

        const TIMEOUT = 15;

        private $ai;

        private $url;

        private $owner;

        private $network;

        public function __construct( WordPlus_SDK_AI $ai, $url, $owner, $network = '' )
        {
            $this->ai      = $ai;
            $this->url     = trim( (string) $url );
            $this->owner   = (string) $owner;
            $this->network = (string) $network;
        }

        public function discover( $www = '' )
        {
            $server = WordPlus_SDK_AI_MCP_Address::read( $this->url, $this->network );

            if ( is_wp_error( $server ) ) {
                return $server;
            }

            $origin   = $server['scheme'] . '://' . self::authority( $server );
            $path     = rtrim( (string) parse_url( $this->url, PHP_URL_PATH ), '/' );
            $resource = null;
            $tried    = array();

            if ( preg_match( '/resource_metadata="?([^",\s]+)"?/i', (string) $www, $found ) ) {
                $tried[] = $found[1];
            }
            if ( '' !== $path ) {
                $tried[] = $origin . '/.well-known/oauth-protected-resource' . $path;
            }
            $tried[] = $origin . '/.well-known/oauth-protected-resource';

            foreach ( $tried as $address ) {
                $data = $this->read_json( $address );

                if ( is_array( $data ) && ! empty( $data['authorization_servers'] ) && is_array( $data['authorization_servers'] ) ) {
                    $resource = $data;
                    break;
                }
            }

            if ( null !== $resource && ! empty( $resource['resource'] ) && ! self::covers( (string) $resource['resource'], $this->url ) ) {
                return WordPlus_SDK_AI_MCP::failed( 'oauth', _x( 'The server named another address as its own, so the sign in stopped.', 'MCP', 'wordplus-sdk' ) );
            }

            $issuer = null === $resource ? $origin : rtrim( (string) reset( $resource['authorization_servers'] ), '/' );
            $meta   = $this->issuer_metadata( $issuer );

            if ( is_wp_error( $meta ) ) {
                return $meta;
            }

            if ( empty( $meta['code_challenge_methods_supported'] ) || ! in_array( 'S256', (array) $meta['code_challenge_methods_supported'], true ) ) {
                return WordPlus_SDK_AI_MCP::failed( 'oauth', _x( 'The service does not protect its sign in with PKCE, so this site does not sign in there.', 'MCP', 'wordplus-sdk' ) );
            }

            foreach ( array( 'authorization_endpoint', 'token_endpoint' ) as $field ) {
                $checked = empty( $meta[ $field ] ) ? null : $this->place_of( (string) $meta[ $field ] );

                if ( null === $checked || is_wp_error( $checked ) ) {
                    return WordPlus_SDK_AI_MCP::failed( 'oauth', _x( 'The service gave no address to sign in at that this site can use.', 'MCP', 'wordplus-sdk' ) );
                }
            }

            $scopes = array();
            if ( preg_match( '/scope="([^"]*)"/i', (string) $www, $found ) ) {
                $scopes = array( $found[1] );
            } elseif ( null !== $resource && ! empty( $resource['scopes_supported'] ) ) {
                $scopes = (array) $resource['scopes_supported'];
            }

            return array(
                'resource'               => null !== $resource && ! empty( $resource['resource'] ) ? (string) $resource['resource'] : $this->url,
                'issuer'                 => $issuer,
                'authorization_endpoint' => (string) $meta['authorization_endpoint'],
                'token_endpoint'         => (string) $meta['token_endpoint'],
                'registration_endpoint'  => isset( $meta['registration_endpoint'] ) ? (string) $meta['registration_endpoint'] : '',
                'scope'                  => implode( ' ', array_filter( array_map( 'strval', $scopes ) ) ),
                'document'               => ! empty( $meta['client_id_metadata_document_supported'] ),
                'methods'                => isset( $meta['token_endpoint_auth_methods_supported'] ) ? array_map( 'strval', (array) $meta['token_endpoint_auth_methods_supported'] ) : array(),
            );
        }

        private function issuer_metadata( $issuer )
        {
            $parts  = parse_url( $issuer );
            $origin = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'https' ) . '://' . ( isset( $parts['host'] ) ? $parts['host'] : '' ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
            $path   = rtrim( isset( $parts['path'] ) ? (string) $parts['path'] : '', '/' );

            $tried = '' === $path
                ? array( $origin . '/.well-known/oauth-authorization-server', $origin . '/.well-known/openid-configuration' )
                : array( $origin . '/.well-known/oauth-authorization-server' . $path, $origin . '/.well-known/openid-configuration' . $path, $origin . $path . '/.well-known/openid-configuration' );

            foreach ( $tried as $address ) {
                $data = $this->read_json( $address );

                if ( is_array( $data ) && ! empty( $data['authorization_endpoint'] ) ) {
                    if ( isset( $data['issuer'] ) && rtrim( (string) $data['issuer'], '/' ) !== rtrim( $issuer, '/' ) ) {
                        return WordPlus_SDK_AI_MCP::failed( 'oauth', _x( 'The service named another address as its own, so the sign in stopped.', 'MCP', 'wordplus-sdk' ) );
                    }

                    return $data;
                }
            }

            return WordPlus_SDK_AI_MCP::failed( 'oauth', _x( 'The server does not say where to sign in.', 'MCP', 'wordplus-sdk' ) );
        }

        private static function covers( $resource, $url )
        {
            $a = parse_url( $resource );
            $b = parse_url( $url );

            if ( ! is_array( $a ) || ! is_array( $b ) ) {
                return false;
            }

            foreach ( array( 'scheme', 'host', 'port' ) as $part ) {
                if ( strtolower( (string) ( isset( $a[ $part ] ) ? $a[ $part ] : '' ) ) !== strtolower( (string) ( isset( $b[ $part ] ) ? $b[ $part ] : '' ) ) ) {
                    return false;
                }
            }

            $path = rtrim( isset( $a['path'] ) ? (string) $a['path'] : '', '/' );

            return '' === $path || $path === rtrim( isset( $b['path'] ) ? (string) $b['path'] : '', '/' ) || 0 === strpos( (string) ( isset( $b['path'] ) ? $b['path'] : '' ), $path . '/' );
        }

        private static function authority( array $where )
        {
            $host = false !== strpos( $where['host'], ':' ) ? '[' . $where['host'] . ']' : $where['host'];

            return $host . ( ( 'https' === $where['scheme'] && 443 === $where['port'] ) || ( 'http' === $where['scheme'] && 80 === $where['port'] ) ? '' : ':' . $where['port'] );
        }

        public function start( array $options )
        {
            $options = array_merge( array( 'redirect' => '', 'document' => '', 'name' => 'WordPlus', 'id' => '', 'secret' => '', 'www' => '' ), $options );
            $found   = $this->discover( (string) $options['www'] );

            if ( is_wp_error( $found ) ) {
                return $found;
            }

            $client = $this->client_for( $found, $options );

            if ( is_wp_error( $client ) ) {
                return $client;
            }

            $this->keep_client( $client );

            $verifier = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' );
            $state    = bin2hex( random_bytes( 16 ) );
            $query    = array(
                'response_type'         => 'code',
                'client_id'             => $client['id'],
                'redirect_uri'          => (string) $options['redirect'],
                'state'                 => $state,
                'code_challenge'        => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ),
                'code_challenge_method' => 'S256',
                'resource'              => $client['resource'],
            );

            if ( '' !== (string) $found['scope'] ) {
                $query['scope'] = (string) $found['scope'];
            }

            $endpoint = (string) $found['authorization_endpoint'];

            return array(
                'url'      => $endpoint . ( false === strpos( $endpoint, '?' ) ? '?' : '&' ) . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ),
                'state'    => $state,
                'verifier' => $verifier,
                'how'      => $client['how'],
            );
        }

        private function client_for( array $found, array $options )
        {
            $base = array(
                'resource' => (string) $found['resource'],
                'issuer'   => (string) $found['issuer'],
                'token'    => (string) $found['token_endpoint'],
                'expires'  => 0,
                'account'  => '',
                'at'       => 0,
            );

            if ( '' !== (string) $options['id'] ) {
                $method = in_array( 'client_secret_post', (array) $found['methods'], true ) ? 'client_secret_post' : 'client_secret_basic';

                return array_merge( $base, array( 'how' => 'typed', 'id' => (string) $options['id'], 'secret' => (string) $options['secret'], 'method' => '' === (string) $options['secret'] ? 'none' : $method ) );
            }

            if ( ! empty( $found['document'] ) && 0 === strpos( (string) $options['document'], 'https://' ) ) {
                return array_merge( $base, array( 'how' => 'document', 'id' => (string) $options['document'], 'secret' => '', 'method' => 'none' ) );
            }

            if ( '' !== (string) $found['registration_endpoint'] ) {
                $registered = $this->send( (string) $found['registration_endpoint'], array(
                    'body'    => (string) wp_json_encode( array(
                        'client_name'                => (string) $options['name'],
                        'redirect_uris'              => array( (string) $options['redirect'] ),
                        'grant_types'                => array( 'authorization_code', 'refresh_token' ),
                        'response_types'             => array( 'code' ),
                        'token_endpoint_auth_method' => 'none',
                    ) ),
                    'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ),
                ) );

                if ( is_wp_error( $registered ) ) {
                    return $registered;
                }

                if ( empty( $registered['client_id'] ) ) {
                    return WordPlus_SDK_AI_MCP::failed( 'client', _x( 'The service did not register this site. Give its client ID and secret here.', 'MCP', 'wordplus-sdk' ) );
                }

                return array_merge( $base, array(
                    'how'    => 'registered',
                    'id'     => (string) $registered['client_id'],
                    'secret' => isset( $registered['client_secret'] ) ? (string) $registered['client_secret'] : '',
                    'method' => isset( $registered['token_endpoint_auth_method'] ) ? (string) $registered['token_endpoint_auth_method'] : ( isset( $registered['client_secret'] ) ? 'client_secret_post' : 'none' ),
                ) );
            }

            return WordPlus_SDK_AI_MCP::failed( 'client', _x( 'This service registers no clients by itself. Give its client ID and secret here.', 'MCP', 'wordplus-sdk' ) );
        }

        public function finish( $code, $verifier, $redirect )
        {
            $client = $this->client();

            if ( null === $client ) {
                return WordPlus_SDK_AI_MCP::failed( 'oauth', _x( 'That sign in is no longer open. Start it again.', 'MCP', 'wordplus-sdk' ) );
            }

            $tokens = $this->token_request( $client, array(
                'grant_type'    => 'authorization_code',
                'code'          => (string) $code,
                'redirect_uri'  => (string) $redirect,
                'code_verifier' => (string) $verifier,
            ) );

            if ( is_wp_error( $tokens ) ) {
                return $tokens;
            }

            $client['at'] = time();
            $this->keep_tokens( $client, $tokens );

            return $this->present();
        }

        public function token( $force = false )
        {
            $client = $this->client();
            $access = null === $client ? '' : $this->ai->keys()->get( self::ACCESS, $this->owner );

            if ( null === $client || '' === $access ) {
                return WordPlus_SDK_AI_MCP::failed( 'auth', _x( 'The sign in of this server ended.', 'MCP', 'wordplus-sdk' ), array( 'refused' => true ) );
            }

            if ( ! $force && ( 0 === (int) $client['expires'] || (int) $client['expires'] > time() + self::EARLY ) ) {
                return $access;
            }

            return $this->refresh( $client );
        }

        private function refresh( array $client )
        {
            $refresh = $this->ai->keys()->get( self::REFRESH, $this->owner );

            if ( '' === $refresh ) {
                $this->forget_tokens( $client );

                return WordPlus_SDK_AI_MCP::failed( 'auth', _x( 'The sign in of this server ended.', 'MCP', 'wordplus-sdk' ), array( 'refused' => true ) );
            }

            $lock = 'wordplus_ai_mcp_refresh_' . md5( $this->owner );

            if ( false !== get_transient( $lock ) ) {
                usleep( 1500000 );
                $this->ai->keys()->forget();
                $access = $this->ai->keys()->get( self::ACCESS, $this->owner );

                if ( '' !== $access ) {
                    return $access;
                }
            }

            set_transient( $lock, 1, 20 );

            $tokens = $this->token_request( $client, array( 'grant_type' => 'refresh_token', 'refresh_token' => $refresh ) );

            delete_transient( $lock );

            if ( is_wp_error( $tokens ) ) {
                $data   = (array) $tokens->get_error_data();
                $status = isset( $data['status'] ) ? (int) $data['status'] : 0;

                if ( $status < 400 || $status >= 500 ) {
                    return WordPlus_SDK_AI_MCP::failed( 'down', _x( 'The service could not be reached.', 'MCP', 'wordplus-sdk' ), array( 'status' => $status ) );
                }

                $this->forget_tokens( $client );

                return WordPlus_SDK_AI_MCP::failed( 'auth', _x( 'The service refused to renew the sign in.', 'MCP', 'wordplus-sdk' ), array( 'status' => $status, 'refused' => true ) );
            }

            $this->keep_tokens( $client, $tokens, $refresh );

            return (string) $tokens['access_token'];
        }

        private function token_request( array $client, array $form )
        {
            $form['resource'] = (string) $client['resource'];
            $headers          = array( 'Accept' => 'application/json', 'Content-Type' => 'application/x-www-form-urlencoded' );

            if ( 'client_secret_basic' === $client['method'] ) {
                $headers['Authorization'] = 'Basic ' . base64_encode( rawurlencode( (string) $client['id'] ) . ':' . rawurlencode( (string) $client['secret'] ) );
            } else {
                $form['client_id'] = (string) $client['id'];

                if ( 'client_secret_post' === $client['method'] ) {
                    $form['client_secret'] = (string) $client['secret'];
                }
            }

            $answer = $this->send( (string) $client['token'], array( 'body' => http_build_query( $form, '', '&', PHP_QUERY_RFC3986 ), 'headers' => $headers ) );

            if ( is_wp_error( $answer ) ) {
                return $answer;
            }

            if ( empty( $answer['access_token'] ) ) {
                return WordPlus_SDK_AI_MCP::failed( 'auth', _x( 'The service gave no token.', 'MCP', 'wordplus-sdk' ) );
            }

            return $answer;
        }

        private function client()
        {
            $json   = $this->ai->keys()->get( self::CLIENT, $this->owner );
            $client = '' === $json ? null : json_decode( $json, true );

            return is_array( $client ) ? $client : null;
        }

        private function keep_client( array $client )
        {
            $this->ai->keys()->save( self::CLIENT, (string) wp_json_encode( $client ), $this->owner );
        }

        private function keep_tokens( array $client, array $tokens, $old_refresh = '' )
        {
            $life              = isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] : 0;
            $client['expires'] = $life > 0 ? time() + $life : 0;
            $account           = self::account_of( $tokens );

            if ( '' !== $account ) {
                $client['account'] = $account;
            }

            $this->ai->keys()->save( self::ACCESS, (string) $tokens['access_token'], $this->owner );
            $this->ai->keys()->save( self::REFRESH, isset( $tokens['refresh_token'] ) ? (string) $tokens['refresh_token'] : (string) $old_refresh, $this->owner );
            $this->keep_client( $client );
        }

        private function forget_tokens( array $client )
        {
            $client['expires'] = 0;
            $this->ai->keys()->remove( self::ACCESS, $this->owner );
            $this->ai->keys()->remove( self::REFRESH, $this->owner );
            $this->keep_client( $client );
        }

        public function forget()
        {
            $this->ai->keys()->remove( self::ACCESS, $this->owner );
            $this->ai->keys()->remove( self::REFRESH, $this->owner );
            $this->ai->keys()->remove( self::CLIENT, $this->owner );
        }

        public function present()
        {
            $client = $this->client();
            $signed = null !== $client && '' !== $this->ai->keys()->get( self::ACCESS, $this->owner );

            return array(
                'signed'  => $signed,
                'host'    => null === $client ? '' : (string) parse_url( (string) $client['issuer'], PHP_URL_HOST ),
                'account' => null === $client ? '' : (string) $client['account'],
                'how'     => null === $client ? '' : (string) $client['how'],
                'at'      => null === $client ? 0 : (int) $client['at'],
                'expires' => null === $client ? 0 : (int) $client['expires'],
                'renews'  => $signed && '' !== $this->ai->keys()->get( self::REFRESH, $this->owner ),
            );
        }

        private static function account_of( array $tokens )
        {
            foreach ( array( 'id_token', 'access_token' ) as $field ) {
                $parts = isset( $tokens[ $field ] ) ? explode( '.', (string) $tokens[ $field ] ) : array();

                if ( 3 !== count( $parts ) ) {
                    continue;
                }

                $claims = json_decode( (string) base64_decode( strtr( $parts[1], '-_', '+/' ), true ), true );

                foreach ( array( 'email', 'preferred_username' ) as $claim ) {
                    if ( is_array( $claims ) && ! empty( $claims[ $claim ] ) && is_string( $claims[ $claim ] ) ) {
                        return mb_substr( sanitize_text_field( $claims[ $claim ] ), 0, 120 );
                    }
                }
            }

            return '';
        }

        public static function document( $id, $name, $redirect, $home )
        {
            return array(
                'client_id'                  => (string) $id,
                'client_name'                => (string) $name,
                'client_uri'                 => (string) $home,
                'redirect_uris'              => array( (string) $redirect ),
                'grant_types'                => array( 'authorization_code', 'refresh_token' ),
                'response_types'             => array( 'code' ),
                'token_endpoint_auth_method' => 'none',
            );
        }

        private function place_of( $url )
        {
            return WordPlus_SDK_AI_MCP_Address::read( $url, $this->allowed() );
        }

        private function allowed()
        {
            $server = WordPlus_SDK_AI_MCP_Address::read( $this->url, $this->network );

            return ! is_wp_error( $server ) && WordPlus_SDK_AI_MCP_Address::PRIVATE === $server['network'] ? '' : WordPlus_SDK_AI_MCP_Address::PUBLIC;
        }

        private function read_json( $url )
        {
            $answer = $this->request( 'GET', $url, array( 'headers' => array( 'Accept' => 'application/json', 'MCP-Protocol-Version' => WordPlus_SDK_AI_MCP::PROTOCOL ) ) );

            return is_wp_error( $answer ) || 200 !== $answer['status'] ? null : $answer['json'];
        }

        private function send( $url, array $options )
        {
            $answer = $this->request( 'POST', $url, $options );

            if ( is_wp_error( $answer ) ) {
                return $answer;
            }

            if ( $answer['status'] < 200 || $answer['status'] >= 300 ) {
                $json = is_array( $answer['json'] ) ? $answer['json'] : array();
                $said = isset( $json['error_description'] ) ? (string) $json['error_description'] : ( isset( $json['error'] ) ? (string) $json['error'] : '' );

                return WordPlus_SDK_AI_MCP::failed( 'oauth', '' !== $said ? mb_substr( sanitize_text_field( $said ), 0, 300 ) : sprintf( /* translators: %d: an HTTP status */ _x( 'The service answered with the status %d.', 'MCP', 'wordplus-sdk' ), $answer['status'] ), array( 'status' => $answer['status'], 'error' => isset( $json['error'] ) ? (string) $json['error'] : '' ) );
            }

            return is_array( $answer['json'] ) ? $answer['json'] : array();
        }

        private function request( $method, $url, array $options )
        {
            $where = $this->place_of( $url );

            if ( is_wp_error( $where ) ) {
                return $where;
            }

            $options = array_merge( $options, array(
                'timeout'         => self::TIMEOUT,
                'connect_timeout' => 5,
                'allow_redirects' => false,
                'http_errors'     => false,
                'sink'            => new WordPlus_SDK_AI_MCP_Sink( WordPlus_SDK_AI_MCP::MAX, null ),
            ) );

            if ( ! empty( $where['pin'] ) && defined( 'CURLOPT_RESOLVE' ) ) {
                $options['curl'] = array( CURLOPT_RESOLVE => $where['pin'] );
            }

            try {
                $response = $this->ai->http( $url, array(), 'mcp' )->request( $method, '', $options );
            } catch ( \Throwable $e ) {
                return WordPlus_SDK_AI_MCP::failed( 'down', _x( 'The service could not be reached.', 'MCP', 'wordplus-sdk' ), array( 'status' => 0 ) );
            }

            return array( 'status' => (int) $response->getStatusCode(), 'json' => json_decode( (string) $response->getBody(), true ) );
        }
    }
}
