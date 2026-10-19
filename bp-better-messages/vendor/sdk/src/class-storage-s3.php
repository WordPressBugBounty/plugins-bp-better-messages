<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Storage_S3' ) ) {
    final class WordPlus_SDK_Storage_S3
    {
        const MAX_REQUEST = 33554432;

        const MAX_SINGLE_PUT = 8388608;

        private $scheme;
        private $host;
        private $port;
        private $base_path;
        private $region;
        private $bucket;
        private $path_style;
        private $access_key;
        private $secret_key;

        public $last_error = '';

        public $last_date = 0;

        public function __construct( array $location )
        {
            $parts = wp_parse_url( trim( isset( $location['endpoint'] ) ? (string) $location['endpoint'] : '' ) );

            $this->scheme     = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : 'https';
            $this->host       = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
            $this->port       = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
            $this->base_path  = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
            $this->region     = isset( $location['region'] ) && '' !== (string) $location['region'] ? (string) $location['region'] : 'us-east-1';
            $this->bucket     = isset( $location['bucket'] ) ? (string) $location['bucket'] : '';
            $this->path_style = ! empty( $location['path_style'] ) && '0' !== $location['path_style'];
            $this->access_key = isset( $location['access_key'] ) ? (string) $location['access_key'] : '';
            $this->secret_key = isset( $location['secret_key'] ) ? (string) $location['secret_key'] : '';

            if ( filter_var( $this->host, FILTER_VALIDATE_IP ) ) {
                $this->path_style = true;
            }
        }

        public function get_bucket()
        {
            return $this->bucket;
        }

        private function host_header()
        {
            $host         = $this->path_style ? $this->host : $this->bucket . '.' . $this->host;
            $default_port = 'https' === $this->scheme ? 443 : 80;

            if ( $this->port && $this->port !== $default_port ) {
                $host .= ':' . $this->port;
            }

            return $host;
        }

        public static function encode_key( $key )
        {
            $segments = explode( '/', ltrim( (string) $key, '/' ) );

            return implode( '/', array_map( 'rawurlencode', $segments ) );
        }

        private function canonical_uri( $key )
        {
            $path = $this->base_path;

            if ( $this->path_style ) {
                $path .= '/' . rawurlencode( $this->bucket );
            }

            if ( null === $key ) {
                return $path . '/';
            }

            return $path . '/' . self::encode_key( $key );
        }

        private static function canonical_query( array $query )
        {
            $encoded = array();

            foreach ( $query as $name => $value ) {
                $encoded[ rawurlencode( (string) $name ) ] = rawurlencode( (string) $value );
            }

            ksort( $encoded, SORT_STRING );

            $pairs = array();

            foreach ( $encoded as $name => $value ) {
                $pairs[] = $name . '=' . $value;
            }

            return implode( '&', $pairs );
        }

        private function signing_key( $date )
        {
            $key = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
            $key = hash_hmac( 'sha256', $this->region, $key, true );
            $key = hash_hmac( 'sha256', 's3', $key, true );

            return hash_hmac( 'sha256', 'aws4_request', $key, true );
        }

        private function scope( $date )
        {
            return $date . '/' . $this->region . '/s3/aws4_request';
        }

        private function url( $key, $canonical_query )
        {
            $url = $this->scheme . '://' . $this->host_header() . $this->canonical_uri( $key );

            if ( '' !== $canonical_query ) {
                $url .= '?' . $canonical_query;
            }

            return $url;
        }

        public function presign( $method, $key, $expires, array $query = array(), array $headers = array(), $time = null )
        {
            $time    = null === $time ? time() : (int) $time;
            $expires = max( 1, min( 604800, (int) $expires ) );

            $amz_date = gmdate( 'Ymd\THis\Z', $time );
            $date     = gmdate( 'Ymd', $time );

            $signed = array( 'host' => $this->host_header() );

            foreach ( $headers as $name => $value ) {
                $signed[ strtolower( $name ) ] = trim( (string) $value );
            }

            ksort( $signed, SORT_STRING );

            $query['X-Amz-Algorithm']     = 'AWS4-HMAC-SHA256';
            $query['X-Amz-Credential']    = $this->access_key . '/' . $this->scope( $date );
            $query['X-Amz-Date']          = $amz_date;
            $query['X-Amz-Expires']       = (string) $expires;
            $query['X-Amz-SignedHeaders'] = implode( ';', array_keys( $signed ) );

            $canonical_query   = self::canonical_query( $query );
            $canonical_headers = '';

            foreach ( $signed as $name => $value ) {
                $canonical_headers .= $name . ':' . $value . "\n";
            }

            $canonical_request = implode( "\n", array(
                strtoupper( $method ),
                $this->canonical_uri( $key ),
                $canonical_query,
                $canonical_headers,
                implode( ';', array_keys( $signed ) ),
                'UNSIGNED-PAYLOAD',
            ) );

            $string_to_sign = implode( "\n", array(
                'AWS4-HMAC-SHA256',
                $amz_date,
                $this->scope( $date ),
                hash( 'sha256', $canonical_request ),
            ) );

            $signature = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date ) );

            return $this->url( $key, $canonical_query ) . '&X-Amz-Signature=' . $signature;
        }

        public function request( $method, $key, array $args = array() )
        {
            $method  = strtoupper( $method );
            $query   = isset( $args['query'] ) ? (array) $args['query'] : array();
            $headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();
            $body    = isset( $args['body'] ) ? (string) $args['body'] : '';
            $file    = isset( $args['file'] ) ? (string) $args['file'] : '';
            $sink    = isset( $args['sink'] ) ? (string) $args['sink'] : '';
            $offset  = isset( $args['offset'] ) ? (int) $args['offset'] : 0;
            $length  = isset( $args['length'] ) ? (int) $args['length'] : -1;
            $timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 30;

            if ( '' !== $file ) {
                if ( $length < 0 ) {
                    $length = max( 0, (int) filesize( $file ) - $offset );
                }

                $payload_hash = 'UNSIGNED-PAYLOAD';
            } else {
                $payload_hash = hash( 'sha256', $body );
            }

            $time     = time();
            $amz_date = gmdate( 'Ymd\THis\Z', $time );
            $date     = gmdate( 'Ymd', $time );

            $signed = array();

            foreach ( $headers as $name => $value ) {
                $signed[ strtolower( $name ) ] = trim( (string) $value );
            }

            $signed['host']                 = $this->host_header();
            $signed['x-amz-date']           = $amz_date;
            $signed['x-amz-content-sha256'] = $payload_hash;

            ksort( $signed, SORT_STRING );

            $canonical_query   = self::canonical_query( $query );
            $canonical_headers = '';

            foreach ( $signed as $name => $value ) {
                $canonical_headers .= $name . ':' . $value . "\n";
            }

            $signed_names = implode( ';', array_keys( $signed ) );

            $canonical_request = implode( "\n", array(
                $method,
                $this->canonical_uri( $key ),
                $canonical_query,
                $canonical_headers,
                $signed_names,
                $payload_hash,
            ) );

            $string_to_sign = implode( "\n", array(
                'AWS4-HMAC-SHA256',
                $amz_date,
                $this->scope( $date ),
                hash( 'sha256', $canonical_request ),
            ) );

            $signature = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date ) );

            $send_headers = array();

            foreach ( $signed as $name => $value ) {
                if ( 'host' !== $name ) {
                    $send_headers[ $name ] = $value;
                }
            }

            $send_headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $this->scope( $date ) . ', SignedHeaders=' . $signed_names . ', Signature=' . $signature;

            $url = $this->url( $key, $canonical_query );

            $result = $this->send( $method, $url, $send_headers, $body, $file, $offset, $length, $sink, $timeout );

            if ( ! empty( $result['headers']['date'] ) ) {
                $this->last_date = (int) strtotime( $result['headers']['date'] );
            }

            $result['ok'] = $result['code'] >= 200 && $result['code'] < 300;

            if ( ! $result['ok'] ) {
                $result['error_code'] = '';

                if ( 'HEAD' !== $method && is_string( $result['body'] ) && '' !== $result['body'] ) {
                    if ( preg_match( '#<Code>([^<]*)</Code>#', $result['body'], $m ) ) {
                        $result['error_code'] = $m[1];
                    }

                    if ( preg_match( '#<Message>([^<]*)</Message>#', $result['body'], $m ) ) {
                        $result['error'] = html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
                    }
                }

                if ( empty( $result['error'] ) ) {
                    $result['error'] = $result['code'] > 0 ? 'HTTP ' . $result['code'] : 'Connection failed';
                }

                $this->last_error = ( '' !== $result['error_code'] ? $result['error_code'] . ': ' : '' ) . $result['error'];
            }

            return $result;
        }

        private function send( $method, $url, array $headers, $body, $file, $offset, $length, $sink, $timeout )
        {
            if ( '' !== $file ) {
                if ( $length > self::MAX_REQUEST ) {
                    return array( 'code' => 0, 'headers' => array(), 'body' => '', 'error' => 'A request over 32 MB' );
                }

                $body = (string) file_get_contents( $file, false, null, $offset, $length );
            }

            $headers['Expect'] = '';

            if ( 'PUT' === $method && '' === (string) $body ) {
                $headers['Content-Length'] = '0';
            }

            $args = array(
                'method'      => $method,
                'headers'     => $headers,
                'body'        => $body,
                'timeout'     => $timeout,
                'redirection' => 0,
                'decompress'  => false,
                'user-agent'  => 'WordPlus SDK/' . WordPlus_SDK::instance()->version() . '; ' . home_url( '/' ),
            );

            if ( '' !== $sink ) {
                $args['stream']   = true;
                $args['filename'] = $sink;
            }

            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                return array( 'code' => 0, 'headers' => array(), 'body' => '', 'error' => $response->get_error_message() );
            }

            $response_headers = array();

            foreach ( wp_remote_retrieve_headers( $response ) as $name => $value ) {
                $response_headers[ strtolower( $name ) ] = is_array( $value ) ? end( $value ) : $value;
            }

            $code          = (int) wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );

            if ( '' !== $sink && $code >= 300 ) {
                $response_body = file_exists( $sink ) ? (string) file_get_contents( $sink ) : '';
                wp_delete_file( $sink );
            }

            return array(
                'code'    => $code,
                'headers' => $response_headers,
                'body'    => (string) $response_body,
                'error'   => '',
            );
        }

        public function put_file( $key, $path, array $headers = array() )
        {
            $size = (int) filesize( $path );

            if ( $size > self::MAX_SINGLE_PUT ) {
                return $this->put_file_multipart( $key, $path, $size, $headers );
            }

            $headers['content-md5'] = base64_encode( md5_file( $path, true ) );

            return $this->request( 'PUT', $key, array(
                'headers' => $headers,
                'file'    => $path,
                'timeout' => max( 60, (int) ceil( $size / 1048576 ) * 10 ),
            ) );
        }

        public function put_string( $key, $body, array $headers = array() )
        {
            $headers['content-md5'] = base64_encode( md5( $body, true ) );

            return $this->request( 'PUT', $key, array(
                'headers' => $headers,
                'body'    => $body,
            ) );
        }

        public static function part_size( $size, $max_parts = 10000 )
        {
            $max_parts = max( 1, (int) $max_parts );
            $divisor   = $max_parts > 1000 ? $max_parts - 1000 : $max_parts;
            $part      = max( 8388608, (int) ceil( $size / $divisor ) );

            return (int) ( ceil( $part / 1048576 ) * 1048576 );
        }

        public function put_file_multipart( $key, $path, $size, array $headers = array(), $max_parts = 10000 )
        {
            $part_size = self::part_size( $size, $max_parts );

            if ( $part_size > self::MAX_REQUEST ) {
                return array( 'ok' => false, 'code' => 0, 'error' => 'Too large for this storage', 'headers' => array(), 'body' => '' );
            }

            $upload_id = $this->create_multipart( $key, $headers );

            if ( '' === $upload_id ) {
                return array( 'ok' => false, 'code' => 0, 'error' => $this->last_error, 'headers' => array(), 'body' => '' );
            }

            $parts = array();
            $number    = 1;

            for ( $offset = 0; $offset < $size; $offset += $part_size ) {
                $length = min( $part_size, $size - $offset );
                $part   = $this->upload_part( $key, $upload_id, $number, $path, $offset, $length );

                if ( empty( $part['ok'] ) || empty( $part['headers']['etag'] ) ) {
                    $this->abort_multipart( $key, $upload_id );

                    return $part;
                }

                $parts[ $number ] = $part['headers']['etag'];
                $number++;
            }

            $complete = $this->complete_multipart( $key, $upload_id, $parts );

            if ( empty( $complete['ok'] ) ) {
                $this->abort_multipart( $key, $upload_id );
            }

            return $complete;
        }

        public function create_multipart( $key, array $headers = array() )
        {
            $result = $this->request( 'POST', $key, array(
                'query'   => array( 'uploads' => '' ),
                'headers' => $headers,
            ) );

            if ( ! empty( $result['ok'] ) && preg_match( '#<UploadId>([^<]+)</UploadId>#', $result['body'], $m ) ) {
                return html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
            }

            return '';
        }

        public function upload_part( $key, $upload_id, $number, $path, $offset, $length )
        {
            return $this->request( 'PUT', $key, array(
                'query'   => array( 'partNumber' => (string) $number, 'uploadId' => $upload_id ),
                'file'    => $path,
                'offset'  => $offset,
                'length'  => $length,
                'timeout' => max( 60, (int) ceil( $length / 1048576 ) * 10 ),
            ) );
        }

        public function complete_multipart( $key, $upload_id, array $parts )
        {
            ksort( $parts, SORT_NUMERIC );

            $xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';

            foreach ( $parts as $number => $etag ) {
                $xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars( $etag, ENT_XML1 ) . '</ETag></Part>';
            }

            $xml .= '</CompleteMultipartUpload>';

            $result = $this->request( 'POST', $key, array(
                'query'   => array( 'uploadId' => $upload_id ),
                'headers' => array( 'content-type' => 'application/xml' ),
                'body'    => $xml,
                'timeout' => 120,
            ) );

            if ( ! empty( $result['ok'] ) && false !== strpos( $result['body'], '<Error>' ) ) {
                $result['ok'] = false;

                if ( preg_match( '#<Message>([^<]*)</Message>#', $result['body'], $m ) ) {
                    $result['error'] = $m[1];
                }
            }

            return $result;
        }

        public function abort_multipart( $key, $upload_id )
        {
            return $this->request( 'DELETE', $key, array(
                'query' => array( 'uploadId' => $upload_id ),
            ) );
        }

        public function list_parts( $key, $upload_id )
        {
            $parts  = array();
            $marker = '';

            for ( $page = 0; $page < 20; $page++ ) {
                $query = array( 'uploadId' => $upload_id, 'max-parts' => '1000' );

                if ( '' !== $marker ) {
                    $query['part-number-marker'] = $marker;
                }

                $result = $this->request( 'GET', $key, array( 'query' => $query ) );

                if ( empty( $result['ok'] ) ) {
                    return null;
                }

                if ( preg_match_all( '#<Part>(.*?)</Part>#s', $result['body'], $matches ) ) {
                    foreach ( $matches[1] as $part ) {
                        if ( preg_match( '#<PartNumber>(\d+)</PartNumber>#', $part, $n ) && preg_match( '#<ETag>([^<]+)</ETag>#', $part, $e ) ) {
                            $size                 = preg_match( '#<Size>(\d+)</Size>#', $part, $s ) ? (int) $s[1] : 0;
                            $parts[ (int) $n[1] ] = array( 'etag' => html_entity_decode( $e[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ), 'size' => $size );
                        }
                    }
                }

                if ( false === strpos( $result['body'], '<IsTruncated>true</IsTruncated>' ) || ! preg_match( '#<NextPartNumberMarker>(\d+)</NextPartNumberMarker>#', $result['body'], $next ) ) {
                    break;
                }

                $marker = $next[1];
            }

            return $parts;
        }

        public function head( $key )
        {
            $result = $this->request( 'HEAD', $key, array( 'timeout' => 20 ) );

            $result['size'] = isset( $result['headers']['content-length'] ) ? (int) $result['headers']['content-length'] : -1;
            $result['etag'] = isset( $result['headers']['etag'] ) ? trim( $result['headers']['etag'], '"' ) : '';
            $result['type'] = isset( $result['headers']['content-type'] ) ? $result['headers']['content-type'] : '';

            return $result;
        }

        public function delete( $key )
        {
            $result = $this->request( 'DELETE', $key, array( 'timeout' => 20 ) );

            if ( 404 === $result['code'] ) {
                $result['ok'] = true;
            }

            return $result;
        }

        public function download( $key, $path )
        {
            return $this->request( 'GET', $key, array(
                'sink'    => $path,
                'timeout' => 600,
            ) );
        }

        public function copy_object( $source_bucket, $source_key, $dest_key )
        {
            $result = $this->request( 'PUT', $dest_key, array(
                'headers' => array( 'x-amz-copy-source' => '/' . rawurlencode( $source_bucket ) . '/' . self::encode_key( $source_key ) ),
                'timeout' => 600,
            ) );

            if ( ! empty( $result['ok'] ) && false !== strpos( $result['body'], '<Error>' ) ) {
                $result['ok'] = false;

                if ( preg_match( '#<Message>([^<]*)</Message>#', $result['body'], $m ) ) {
                    $result['error'] = $m[1];
                }

                $this->last_error = $result['error'];
            }

            return $result;
        }

        public function list_objects( $prefix, $continuation = '', $max = 1000 )
        {
            $query = array(
                'list-type' => '2',
                'prefix'    => (string) $prefix,
                'max-keys'  => (string) max( 1, min( 1000, (int) $max ) ),
            );

            if ( '' !== $continuation ) {
                $query['continuation-token'] = $continuation;
            }

            $result  = $this->request( 'GET', null, array( 'query' => $query ) );
            $objects = array();
            $next    = '';

            if ( ! empty( $result['ok'] ) ) {
                if ( preg_match_all( '#<Contents>(.*?)</Contents>#s', $result['body'], $matches ) ) {
                    foreach ( $matches[1] as $item ) {
                        if ( preg_match( '#<Key>([^<]*)</Key>#', $item, $k ) ) {
                            $objects[] = array(
                                'key'      => html_entity_decode( $k[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ),
                                'size'     => preg_match( '#<Size>(\d+)</Size>#', $item, $s ) ? (int) $s[1] : 0,
                                'modified' => preg_match( '#<LastModified>([^<]+)</LastModified>#', $item, $m ) ? (int) strtotime( $m[1] ) : 0,
                            );
                        }
                    }
                }

                if ( false !== strpos( $result['body'], '<IsTruncated>true</IsTruncated>' ) && preg_match( '#<NextContinuationToken>([^<]+)</NextContinuationToken>#', $result['body'], $t ) ) {
                    $next = html_entity_decode( $t[1], ENT_QUOTES | ENT_XML1, 'UTF-8' );
                }
            }

            return array( 'ok' => ! empty( $result['ok'] ), 'objects' => $objects, 'next' => $next, 'error' => $result['ok'] ? '' : $this->last_error );
        }

        public function get_cors()
        {
            return $this->request( 'GET', null, array( 'query' => array( 'cors' => '' ) ) );
        }

        public function put_cors( $xml )
        {
            return $this->request( 'PUT', null, array(
                'query'   => array( 'cors' => '' ),
                'headers' => array(
                    'content-type' => 'application/xml',
                    'content-md5'  => base64_encode( md5( $xml, true ) ),
                ),
                'body'    => $xml,
            ) );
        }
    }
}
