<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_MCP_Address' ) ) {
    final class WordPlus_SDK_AI_MCP_Address
    {
        const PUBLIC = 'public';

        const PRIVATE = 'private';

        const NEVER = 'never';

        const NEVER_HOSTS = array( 'metadata', 'metadata.google.internal', 'metadata.goog', 'instance-data', 'instance-data.ec2.internal' );

        const NEVER_V4 = array(
            array( '0.0.0.0', 8 ),
            array( '169.254.0.0', 16 ),
            array( '192.0.0.0', 24 ),
            array( '198.18.0.0', 15 ),
            array( '168.63.129.16', 32 ),
            array( '100.100.100.200', 32 ),
            array( '224.0.0.0', 4 ),
            array( '240.0.0.0', 4 ),
        );

        const PRIVATE_V4 = array(
            array( '10.0.0.0', 8 ),
            array( '172.16.0.0', 12 ),
            array( '192.168.0.0', 16 ),
            array( '127.0.0.0', 8 ),
            array( '100.64.0.0', 10 ),
        );

        public static $resolver = null;

        public static function read( $url, $network = '' )
        {
            $url   = trim( (string) $url );
            $parts = '' === $url ? false : parse_url( $url );

            if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
                return self::refused( 'invalid', _x( 'Give the full address, starting with https.', 'MCP', 'wordplus-sdk' ) );
            }

            $scheme = strtolower( (string) $parts['scheme'] );
            $host   = strtolower( rtrim( (string) $parts['host'], '.' ) );

            if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
                return self::refused( 'invalid', _x( 'Give the full address, starting with https.', 'MCP', 'wordplus-sdk' ) );
            }

            if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) ) {
                return self::refused( 'invalid', _x( 'Give the address alone, with no name, password or part after a hash.', 'MCP', 'wordplus-sdk' ) );
            }

            $port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $scheme ? 443 : 80 );
            $bare = trim( $host, '[]' );

            if ( in_array( $host, self::NEVER_HOSTS, true ) ) {
                return self::never();
            }

            if ( self::obscured( $bare ) ) {
                return self::never();
            }

            $literal   = false !== filter_var( $bare, FILTER_VALIDATE_IP );
            $addresses = $literal ? array( $bare ) : self::resolve( $bare );

            if ( empty( $addresses ) ) {
                return self::refused( 'unknown', sprintf( /* translators: %s: a host name */ _x( 'No address was found for %s.', 'MCP', 'wordplus-sdk' ), $bare ) );
            }

            $place = '';
            foreach ( $addresses as $address ) {
                $one = self::place( $address );

                if ( self::NEVER === $one ) {
                    return self::never();
                }
                if ( '' !== $place && $place !== $one ) {
                    return self::refused( 'mixed', _x( 'This address points at the internet and at a private network at once, so it is not called.', 'MCP', 'wordplus-sdk' ) );
                }
                $place = $one;
            }

            if ( 'http' === $scheme && self::PRIVATE !== $place ) {
                return self::refused( 'http', _x( 'Use an https address. Only an address on your own network may start with http.', 'MCP', 'wordplus-sdk' ) );
            }

            if ( '' !== $network && $network !== $place ) {
                return self::refused( 'moved', _x( 'This address now points somewhere else than when it was added, so it is not called. Check it, and add it again if it is right.', 'MCP', 'wordplus-sdk' ) );
            }

            return array(
                'url'       => $url,
                'scheme'    => $scheme,
                'host'      => $bare,
                'port'      => $port,
                'network'   => $place,
                'addresses' => $addresses,
                'pin'       => $literal ? array() : array( self::pin( $bare, $port, $addresses[0] ) ),
            );
        }

        private static function pin( $host, $port, $address )
        {
            return $host . ':' . (int) $port . ':' . ( false !== strpos( $address, ':' ) ? '[' . $address . ']' : $address );
        }

        private static function obscured( $host )
        {
            if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) || false !== strpos( $host, ':' ) ) {
                return false;
            }

            $parts = explode( '.', $host );
            $last  = (string) end( $parts );

            return 1 === preg_match( '/^(0x[0-9a-f]+|[0-9]+)$/', $last ) || 1 === preg_match( '/^(0x[0-9a-f]+|0[0-7]+)(\.|$)/', $host );
        }

        private static function resolve( $host )
        {
            if ( 'localhost' === $host || '.localhost' === substr( $host, -10 ) ) {
                return array( '127.0.0.1' );
            }

            if ( null !== self::$resolver ) {
                return (array) call_user_func( self::$resolver, $host );
            }

            $found = function_exists( 'gethostbynamel' ) ? gethostbynamel( $host ) : false;
            $found = is_array( $found ) ? $found : array();

            if ( function_exists( 'dns_get_record' ) && '.local' !== substr( $host, -6 ) ) {
                $six = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

                foreach ( is_array( $six ) ? $six : array() as $record ) {
                    if ( ! empty( $record['ipv6'] ) ) {
                        $found[] = (string) $record['ipv6'];
                    }
                }
            }

            return array_values( array_unique( $found ) );
        }

        public static function place( $address )
        {
            $packed = @inet_pton( (string) $address ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( false === $packed ) {
                return self::NEVER;
            }

            if ( 4 === strlen( $packed ) ) {
                return self::place_v4( $packed );
            }

            $embedded = self::embedded_v4( $packed );

            return null === $embedded ? self::place_v6( $packed ) : self::place_v4( $embedded );
        }

        private static function embedded_v4( $packed )
        {
            $hex = bin2hex( $packed );

            if ( 0 === strpos( $hex, '00000000000000000000ffff' ) ) {
                return substr( $packed, 12 );
            }

            if ( 0 === strpos( $hex, '000000000000000000000000' ) && '00000000' !== substr( $hex, 24, 8 ) && '00000001' !== substr( $hex, 24, 8 ) ) {
                return substr( $packed, 12 );
            }

            if ( 0 === strpos( $hex, '0064ff9b0000000000000000' ) ) {
                return substr( $packed, 12 );
            }

            if ( 0 === strpos( $hex, '2002' ) ) {
                return substr( $packed, 2, 4 );
            }

            return null;
        }

        private static function place_v4( $packed )
        {
            foreach ( self::NEVER_V4 as $range ) {
                if ( self::within( $packed, inet_pton( $range[0] ), $range[1] ) ) {
                    return self::NEVER;
                }
            }

            foreach ( self::PRIVATE_V4 as $range ) {
                if ( self::within( $packed, inet_pton( $range[0] ), $range[1] ) ) {
                    return self::PRIVATE;
                }
            }

            return self::PUBLIC;
        }

        private static function place_v6( $packed )
        {
            $never = array(
                array( '::', 128 ),
                array( 'fe80::', 10 ),
                array( 'fec0::', 10 ),
                array( 'ff00::', 8 ),
                array( 'fd00:ec2::254', 128 ),
            );

            foreach ( $never as $range ) {
                if ( self::within( $packed, inet_pton( $range[0] ), $range[1] ) ) {
                    return self::NEVER;
                }
            }

            if ( self::within( $packed, inet_pton( '::1' ), 128 ) || self::within( $packed, inet_pton( 'fc00::' ), 7 ) ) {
                return self::PRIVATE;
            }

            return self::PUBLIC;
        }

        private static function within( $address, $range, $bits )
        {
            if ( strlen( $address ) !== strlen( $range ) ) {
                return false;
            }

            $bytes = (int) floor( $bits / 8 );

            if ( 0 !== substr_compare( $address, $range, 0, max( 1, $bytes ) ) && $bytes > 0 ) {
                return false;
            }

            $rest = $bits % 8;

            if ( 0 === $rest ) {
                return true;
            }

            $mask = 0xff & ( 0xff << ( 8 - $rest ) );

            return ( ord( $address[ $bytes ] ) & $mask ) === ( ord( $range[ $bytes ] ) & $mask );
        }

        private static function never()
        {
            return self::refused( 'never', _x( 'This address is not one a site should call, so it is refused.', 'MCP', 'wordplus-sdk' ) );
        }

        private static function refused( $why, $message )
        {
            return new WP_Error( 'wordplus_ai_mcp_address', $message, array( 'kind' => 'address', 'why' => $why ) );
        }
    }
}
