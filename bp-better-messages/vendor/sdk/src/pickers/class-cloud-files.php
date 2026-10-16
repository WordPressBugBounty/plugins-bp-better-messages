<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Cloud_Files' ) ) {
    final class WordPlus_SDK_Cloud_Files
    {
        const ORIGIN = 'https://api.wordplus.cloud/';

        const FILES = 'api/v1/assets/';

        const CDN = 'https://cdn.wordplus.cloud/';

        const MISSING = array( 404, 410 );

        public static function cdn_url( $url )
        {
            $url = (string) $url;
            $cdn = trim( (string) apply_filters( 'wordplus_sdk_cdn', self::CDN ) );

            if ( '' === $cdn || 0 !== strpos( $url, self::ORIGIN . self::FILES ) ) {
                return $url;
            }

            return trailingslashit( $cdn ) . substr( $url, strlen( self::ORIGIN ) );
        }

        public static function get( $url, array $args, $valid = null )
        {
            $cdn = self::cdn_url( $url );

            if ( $cdn !== $url ) {
                $response = wp_safe_remote_get( $cdn, $args );

                if ( self::missing( $response ) || ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) && ( null === $valid || $valid( (string) wp_remote_retrieve_body( $response ) ) ) ) ) {
                    return $response;
                }
            }

            return wp_safe_remote_get( $url, $args );
        }

        public static function download( $url, $timeout, $origin_timeout, array $headers = array(), $valid = null )
        {
            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $cdn = self::cdn_url( $url );

            if ( $cdn !== $url ) {
                $file = self::download_from( $cdn, $timeout, $headers );

                if ( is_wp_error( $file ) ? self::missing( $file ) : ( null === $valid || $valid( $file ) ) ) {
                    return $file;
                }

                if ( ! is_wp_error( $file ) ) {
                    wp_delete_file( $file );
                }
            }

            return self::download_from( $url, $origin_timeout, $headers );
        }

        private static function download_from( $url, $timeout, array $headers )
        {
            $with_headers = function ( $args, $asked ) use ( $url, $headers ) {
                if ( $asked === $url && $headers ) {
                    $args['headers'] = array_merge( (array) ( $args['headers'] ?? array() ), $headers );
                }

                return $args;
            };

            add_filter( 'http_request_args', $with_headers, 10, 2 );
            $file = download_url( $url, $timeout );
            remove_filter( 'http_request_args', $with_headers, 10 );

            return $file;
        }

        private static function missing( $response )
        {
            if ( is_wp_error( $response ) ) {
                $data = $response->get_error_data();

                return 'http_404' === $response->get_error_code() && is_array( $data ) && isset( $data['code'] ) && in_array( (int) $data['code'], self::MISSING, true );
            }

            return in_array( (int) wp_remote_retrieve_response_code( $response ), self::MISSING, true );
        }
    }
}
