<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Client' ) ) {
    final class WordPlus_SDK_Client
    {
        const CLOUDFLARE = array(
            '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18',
            '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
            '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
            '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29',
            '2c0f:f248::/32',
        );

        const SERVER = array( 'REMOTE_ADDR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP' );

        const MAX_HOPS = 10;

        public static function ip( $server = null )
        {
            $server = is_array( $server ) ? $server : self::server();
            $client = self::address( isset( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '' );

            if ( '' === $client ) {
                return '';
            }

            $proxies    = array_filter( array_map( 'strval', (array) apply_filters( 'wordplus_trusted_proxies', array() ) ) );
            $cloudflare = (bool) apply_filters( 'wordplus_trust_cloudflare', true );
            $hops       = self::forwarded( $server );

            for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
                $from_cloudflare = $cloudflare && self::in_any( $client, self::CLOUDFLARE );

                if ( $from_cloudflare ) {
                    $named = self::address( isset( $server['HTTP_CF_CONNECTING_IP'] ) ? $server['HTTP_CF_CONNECTING_IP'] : '' );

                    if ( '' !== $named ) {
                        return $named;
                    }
                }

                if ( ! ( $from_cloudflare || self::local( $client ) || self::in_any( $client, $proxies ) ) || array() === $hops ) {
                    return $client;
                }

                $next = array_shift( $hops );

                if ( '' === $next ) {
                    return $client;
                }

                $client = $next;
            }

            return $client;
        }

        public static function crawler( $agent = null )
        {
            if ( null === $agent ) {
                $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
            }

            $agent = trim( (string) $agent );

            return '' === $agent || 1 === preg_match( '/(?<!cu)bot(?:\b|_)|crawl|spider|slurp|facebookexternalhit|google-inspectiontool|lighthouse|statuscake|site24x7|uptime[- ]kuma|jetmon|datadogsynthetics|gtmetrix|\bptst\//i', $agent );
        }

        public static function in( $ip, $range )
        {
            $parts = explode( '/', (string) $range, 2 );
            $ip    = self::address( $ip );
            $base  = self::address( $parts[0] );

            if ( '' === $ip || '' === $base ) {
                return false;
            }

            $address = inet_pton( $ip );
            $network = inet_pton( $base );

            if ( false === $address || false === $network || strlen( $address ) !== strlen( $network ) ) {
                return false;
            }

            $size = 8 * strlen( $network );

            if ( isset( $parts[1] ) && ! preg_match( '/^\d{1,3}$/', $parts[1] ) ) {
                return false;
            }

            $bits = isset( $parts[1] ) ? (int) $parts[1] : $size;

            if ( $bits > $size ) {
                return false;
            }

            $whole = intdiv( $bits, 8 );

            if ( substr( $address, 0, $whole ) !== substr( $network, 0, $whole ) ) {
                return false;
            }

            $left = $bits % 8;

            if ( 0 === $left ) {
                return true;
            }

            $mask = chr( ( 0xff << ( 8 - $left ) ) & 0xff );

            return ( $address[ $whole ] & $mask ) === ( $network[ $whole ] & $mask );
        }

        public static function address( $value )
        {
            $value = trim( (string) $value );

            if ( preg_match( '/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $value, $mapped ) ) {
                $value = $mapped[1];
            }

            return false === filter_var( $value, FILTER_VALIDATE_IP ) ? '' : $value;
        }

        public static function from_cloudflare( $server = null )
        {
            $server = is_array( $server ) ? $server : self::server();
            $client = self::address( isset( $server['REMOTE_ADDR'] ) ? $server['REMOTE_ADDR'] : '' );

            if ( '' === $client || ! apply_filters( 'wordplus_trust_cloudflare', true ) ) {
                return false;
            }

            $proxies = array_filter( array_map( 'strval', (array) apply_filters( 'wordplus_trusted_proxies', array() ) ) );
            $hops    = self::forwarded( $server );

            for ( $hop = 0; $hop <= self::MAX_HOPS; $hop++ ) {
                if ( self::in_any( $client, self::CLOUDFLARE ) ) {
                    return true;
                }

                if ( ! ( self::local( $client ) || self::in_any( $client, $proxies ) ) || array() === $hops ) {
                    return false;
                }

                $client = (string) array_shift( $hops );

                if ( '' === $client ) {
                    return false;
                }
            }

            return false;
        }

        private static function local( $ip )
        {
            return false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
        }

        private static function in_any( $ip, array $ranges )
        {
            foreach ( $ranges as $range ) {
                if ( self::in( $ip, $range ) ) {
                    return true;
                }
            }

            return false;
        }

        private static function forwarded( array $server )
        {
            $list = isset( $server['HTTP_X_FORWARDED_FOR'] ) ? trim( (string) $server['HTTP_X_FORWARDED_FOR'] ) : '';

            if ( '' === $list && isset( $server['HTTP_X_REAL_IP'] ) ) {
                $list = trim( (string) $server['HTTP_X_REAL_IP'] );
            }

            if ( '' === $list ) {
                return array();
            }

            $hops = array();

            foreach ( array_reverse( explode( ',', $list ) ) as $entry ) {
                $entry = trim( $entry );

                if ( preg_match( '/^\[([0-9a-f:.]+)\](?::\d+)?$/i', $entry, $bracketed ) ) {
                    $entry = $bracketed[1];
                } elseif ( preg_match( '/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $entry, $port ) ) {
                    $entry = $port[1];
                }

                $hops[] = self::address( $entry );

                if ( count( $hops ) >= self::MAX_HOPS ) {
                    break;
                }
            }

            return $hops;
        }

        private static function server()
        {
            $server = array();

            foreach ( self::SERVER as $key ) {
                if ( isset( $_SERVER[ $key ] ) ) {
                    $server[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
                }
            }

            return $server;
        }
    }
}
