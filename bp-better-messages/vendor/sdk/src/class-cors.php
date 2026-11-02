<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Cors' ) ) {
    final class WordPlus_SDK_Cors
    {
        const FILTER = 'wordplus_allowed_origins';

        const REFUSED_ACTION = 'wordplus_origin_refused';

        const OPTION = 'wordplus_origin_refusals';

        const ROUTES = array( '/wordplus/v1/visitors' );

        const ALLOW_METHODS = 'POST, OPTIONS';

        const ALLOW_HEADERS = 'Content-Type, X-WordPlus-Visitor';

        const MAX_AGE = 600;

        const MAX_ORIGINS = 2000;

        const GAP = 10;

        const KEEP_DAYS = 8;

        const MAX_ADDRESSES = 50;

        const LISTED = '#^https?://(?:(?:\*\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?|\[[0-9a-f:.]{2,45}\])(?::[0-9]{1,5})?$#';

        private $sdk;

        private $opened = array();

        private $sender = null;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function hook()
        {
            if ( ! has_action( 'rest_pre_serve_request', array( $this, 'serve' ) ) ) {
                add_filter( 'rest_pre_serve_request', array( $this, 'serve' ), 20, 3 );
                add_action( self::REFUSED_ACTION, array( $this, 'count' ), 10, 3 );
            }
        }

        public function open( $route, $tag = '' )
        {
            $route = '/' . ltrim( (string) $route, '/' );

            if ( '/' !== $route ) {
                $this->opened[ $route ] = is_callable( $tag ) ? $tag : (string) $tag;
                $this->hook();
            }

            return $this;
        }

        public function origins( $route = '' )
        {
            $given = apply_filters( self::FILTER, array(), (string) $route );
            $kept  = array();

            foreach ( is_array( $given ) ? $given : array() as $origin ) {
                $origin = self::normalize( $origin );

                if ( null !== $origin ) {
                    $kept[ $origin ] = true;
                }

                if ( count( $kept ) >= self::MAX_ORIGINS ) {
                    break;
                }
            }

            return array_keys( $kept );
        }

        public static function normalize( $origin )
        {
            if ( ! is_string( $origin ) ) {
                return null;
            }

            $origin = strtolower( trim( $origin ) );

            if ( '' === $origin || strlen( $origin ) > 300 ) {
                return null;
            }

            if ( false === strpos( $origin, '://' ) ) {
                $origin = 'https://' . $origin;
            }

            if ( 1 !== preg_match( self::LISTED, $origin ) ) {
                return null;
            }

            $wildcard = strpos( $origin, '://*.' );

            if ( false !== $wildcard && false === strpos( (string) preg_replace( '/:[0-9]+$/', '', substr( $origin, $wildcard + 5 ) ), '.' ) ) {
                return null;
            }

            return (string) preg_replace( array( '#^(https://.+):443$#', '#^(http://.+):80$#' ), '$1', $origin );
        }

        public static function parts( $origin )
        {
            $origin = strtolower( trim( (string) $origin ) );

            if ( 1 !== preg_match( '#^(https?)://(\[[0-9a-f:.]{2,45}\]|[a-z0-9.-]{1,253})(?::([0-9]{1,5}))?$#', $origin, $match ) ) {
                return null;
            }

            $port = isset( $match[3] ) ? $match[3] : '';

            if ( ( 'https' === $match[1] && '443' === $port ) || ( 'http' === $match[1] && '80' === $port ) ) {
                $port = '';
            }

            return array( 'scheme' => $match[1], 'host' => $match[2], 'port' => $port );
        }

        public static function matches( $origin, array $listed )
        {
            $page = self::parts( $origin );

            if ( null === $page ) {
                return false;
            }

            $exact = $page['scheme'] . '://' . $page['host'] . ( '' === $page['port'] ? '' : ':' . $page['port'] );

            foreach ( $listed as $entry ) {
                if ( false === strpos( (string) $entry, '://*.' ) ) {
                    if ( (string) $entry === $exact ) {
                        return true;
                    }

                    continue;
                }

                $base = self::parts( str_replace( '://*.', '://', (string) $entry ) );
                $tail = null === $base ? '' : '.' . $base['host'];

                if ( null !== $base && $page['scheme'] === $base['scheme'] && $page['port'] === $base['port'] && strlen( $page['host'] ) > strlen( $tail ) && substr( $page['host'], -strlen( $tail ) ) === $tail ) {
                    return true;
                }
            }

            return false;
        }

        public static function own( $origin )
        {
            $page = self::parts( $origin );

            if ( null === $page ) {
                return false;
            }

            $bare = static function ( $host ) {
                $host = strtolower( (string) $host );

                return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
            };

            foreach ( array( home_url(), site_url() ) as $url ) {
                $host = wp_parse_url( (string) $url, PHP_URL_HOST );

                if ( is_string( $host ) && '' !== $host && $bare( $host ) === $bare( $page['host'] ) ) {
                    return true;
                }
            }

            return false;
        }

        public function allows( $origin, $route = '' )
        {
            return self::own( $origin ) || self::matches( $origin, $this->origins( $route ) );
        }

        public function serve( $served, $result = null, $request = null )
        {
            if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
                return $served;
            }

            $route = untrailingslashit( (string) $request->get_route() );
            $tag   = $this->tag_for( $route, $request );

            if ( null !== $tag ) {
                $this->respond( (string) $request->get_header( 'origin' ), $route, $tag );
            }

            return $served;
        }

        public function respond( $origin, $route, $tag = '' )
        {
            $origin = trim( (string) $origin );

            if ( '' === $origin || self::own( $origin ) ) {
                return null;
            }

            if ( self::matches( $origin, $this->origins( $route ) ) ) {
                $this->send( array(
                    'Access-Control-Allow-Origin'  => $origin,
                    'Access-Control-Allow-Methods' => self::ALLOW_METHODS,
                    'Access-Control-Allow-Headers' => self::ALLOW_HEADERS,
                    'Access-Control-Max-Age'       => (string) self::MAX_AGE,
                    'Vary'                         => 'Origin',
                ), array( 'Access-Control-Allow-Credentials' ) );

                return true;
            }

            $this->send( array( 'Vary' => 'Origin' ), array( 'Access-Control-Allow-Origin', 'Access-Control-Allow-Credentials', 'Access-Control-Allow-Methods' ) );

            do_action( self::REFUSED_ACTION, substr( $origin, 0, 300 ), (string) $route, (string) $tag );

            if ( ! has_action( self::REFUSED_ACTION, array( $this, 'count' ) ) ) {
                $this->count( $origin, $route, $tag );
            }

            return false;
        }

        public function count( $origin, $route = '', $tag = '' )
        {
            $page = self::parts( $origin );

            if ( null === $page || self::own( $origin ) ) {
                return false;
            }

            $address = $page['host'];
            $tag     = substr( (string) $tag, 0, 64 );
            $now     = time();
            $all     = $this->stored();
            $entry   = isset( $all[ $address ] ) ? $all[ $address ] : array( 'origin' => '', 'days' => array(), 'tags' => array(), 'last' => 0, 'burst' => array() );

            if ( $now - (int) $entry['last'] < self::GAP ) {
                if ( '' === $tag || in_array( $tag, $entry['burst'], true ) ) {
                    return false;
                }

                $entry['tags'][ $tag ] = ( isset( $entry['tags'][ $tag ] ) ? (int) $entry['tags'][ $tag ] : 0 ) + 1;
                $entry['burst'][]      = $tag;
            } else {
                $day                    = gmdate( 'Y-m-d', $now );
                $entry['days'][ $day ]  = ( isset( $entry['days'][ $day ] ) ? (int) $entry['days'][ $day ] : 0 ) + 1;
                $entry['last']          = $now;
                $entry['burst']         = '' === $tag ? array() : array( $tag );

                if ( '' !== $tag ) {
                    $entry['tags'][ $tag ] = ( isset( $entry['tags'][ $tag ] ) ? (int) $entry['tags'][ $tag ] : 0 ) + 1;
                }
            }

            $entry['origin'] = $page['scheme'] . '://' . $page['host'] . ( '' === $page['port'] ? '' : ':' . $page['port'] );
            $all[ $address ] = $entry;

            update_option( self::OPTION, $this->pruned( $all, $now ), false );

            return true;
        }

        public function refusals( $days = 7 )
        {
            $since = gmdate( 'Y-m-d', time() - ( max( 1, (int) $days ) - 1 ) * DAY_IN_SECONDS );
            $list  = array();

            foreach ( $this->stored() as $address => $entry ) {
                $count = 0;

                foreach ( $entry['days'] as $day => $n ) {
                    if ( (string) $day >= $since ) {
                        $count += (int) $n;
                    }
                }

                if ( $count > 0 ) {
                    $list[] = array(
                        'address' => (string) $address,
                        'origin'  => (string) $entry['origin'],
                        'count'   => $count,
                        'last'    => (int) $entry['last'],
                        'tags'    => array_map( 'intval', (array) $entry['tags'] ),
                    );
                }
            }

            usort( $list, static function ( $a, $b ) {
                return $b['count'] === $a['count'] ? $b['last'] - $a['last'] : $b['count'] - $a['count'];
            } );

            return $list;
        }

        public function forget( $address = null )
        {
            if ( null === $address ) {
                delete_option( self::OPTION );

                return;
            }

            $all = $this->stored();
            unset( $all[ strtolower( (string) $address ) ] );
            update_option( self::OPTION, $all, false );
        }

        public function send_with( $sender )
        {
            $this->sender = is_callable( $sender ) ? $sender : null;
        }

        private function tag_for( $route, $request )
        {
            if ( in_array( $route, self::ROUTES, true ) ) {
                return '';
            }

            foreach ( $this->opened as $opened => $tag ) {
                $prefix = '*' === substr( $opened, -1 );

                if ( $prefix ? 0 === strpos( $route, substr( $opened, 0, -1 ) ) : $route === untrailingslashit( $opened ) ) {
                    return is_callable( $tag ) ? (string) call_user_func( $tag, $request ) : $tag;
                }
            }

            return null;
        }

        private function stored()
        {
            $all  = get_option( self::OPTION, array() );
            $kept = array();

            foreach ( is_array( $all ) ? $all : array() as $address => $entry ) {
                if ( is_array( $entry ) ) {
                    $kept[ (string) $address ] = array(
                        'origin' => isset( $entry['origin'] ) ? (string) $entry['origin'] : '',
                        'days'   => isset( $entry['days'] ) && is_array( $entry['days'] ) ? $entry['days'] : array(),
                        'tags'   => isset( $entry['tags'] ) && is_array( $entry['tags'] ) ? $entry['tags'] : array(),
                        'last'   => isset( $entry['last'] ) ? (int) $entry['last'] : 0,
                        'burst'  => isset( $entry['burst'] ) && is_array( $entry['burst'] ) ? $entry['burst'] : array(),
                    );
                }
            }

            return $kept;
        }

        private function pruned( array $all, $now )
        {
            $oldest = gmdate( 'Y-m-d', $now - ( self::KEEP_DAYS - 1 ) * DAY_IN_SECONDS );

            foreach ( $all as $address => $entry ) {
                foreach ( array_keys( $entry['days'] ) as $day ) {
                    if ( (string) $day < $oldest ) {
                        unset( $all[ $address ]['days'][ $day ] );
                    }
                }

                if ( empty( $all[ $address ]['days'] ) ) {
                    unset( $all[ $address ] );
                }
            }

            uasort( $all, static function ( $a, $b ) {
                return $b['last'] - $a['last'];
            } );

            return array_slice( $all, 0, self::MAX_ADDRESSES, true );
        }

        private function send( array $set, array $remove )
        {
            if ( null !== $this->sender ) {
                call_user_func( $this->sender, $set, $remove );

                return;
            }

            if ( headers_sent() ) {
                return;
            }

            foreach ( $remove as $name ) {
                header_remove( $name );
            }

            foreach ( $set as $name => $value ) {
                if ( 'Vary' !== $name ) {
                    header( $name . ': ' . $value );
                } elseif ( ! self::varies_by_origin( headers_list() ) ) {
                    header( 'Vary: Origin', false );
                }
            }
        }

        private static function varies_by_origin( array $headers )
        {
            foreach ( $headers as $header ) {
                if ( 1 === preg_match( '/^vary:.*\borigin\b/i', (string) $header ) ) {
                    return true;
                }
            }

            return false;
        }
    }
}
