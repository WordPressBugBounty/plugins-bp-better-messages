<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Asset_Versions' ) ):

    class Better_Messages_Asset_Versions
    {
        const MARKER = 'bmv';

        private $own_handle_prefixes = array( 'better-messages', 'bm-', 'bpbm-' );

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Asset_Versions();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter( 'script_loader_src', array( $this, 'versioned_src' ), PHP_INT_MAX, 2 );
            add_filter( 'style_loader_src', array( $this, 'versioned_src' ), PHP_INT_MAX, 2 );
            add_filter( 'script_loader_tag', array( $this, 'versioned_tag' ), PHP_INT_MAX, 3 );
            add_filter( 'style_loader_tag', array( $this, 'versioned_tag' ), PHP_INT_MAX, 3 );
        }

        public function versioned_src( $src, $handle )
        {
            if ( ! is_string( $src ) || $src === '' ) {
                return $src;
            }

            $asset = $this->find_asset( $handle, current_filter() === 'style_loader_src' );

            if ( $asset === null ) {
                return $src;
            }

            return $this->with_version( $src, $asset['version'] );
        }

        public function versioned_tag( $tag, $handle, $src = '' )
        {
            if ( ! is_string( $tag ) || $tag === '' ) {
                return $tag;
            }

            $is_style = current_filter() === 'style_loader_tag';
            $asset    = $this->find_asset( $handle, $is_style );

            if ( $asset === null ) {
                return $tag;
            }

            $paths     = array_filter( array( $asset['path'], $this->url_path( $src ) ) );
            $attribute = $is_style ? 'href' : 'src';
            $tags      = better_messages_html_tag_processor( $tag );
            $changed   = false;

            while ( $tags->next_tag( $is_style ? 'link' : 'script' ) ) {
                $url = $tags->get_attribute( $attribute );

                if ( ! is_string( $url ) || ! in_array( $this->url_path( $url ), $paths, true ) ) {
                    continue;
                }

                $versioned = $this->with_version( $url, $asset['version'] );

                if ( $versioned !== $url && $tags->set_attribute( $attribute, $versioned ) ) {
                    $changed = true;
                }
            }

            return $changed ? $tags->get_updated_html() : $tag;
        }

        private function find_asset( $handle, $is_style )
        {
            if ( ! is_string( $handle ) || $handle === '' ) {
                return null;
            }

            $dependencies = $is_style ? wp_styles() : wp_scripts();

            if ( ! isset( $dependencies->registered[ $handle ] ) && substr( $handle, -4 ) === '-rtl' ) {
                $handle = substr( $handle, 0, -4 );
            }

            if ( ! isset( $dependencies->registered[ $handle ] ) ) {
                return null;
            }

            $registered = $dependencies->registered[ $handle ];

            if ( ! is_string( $registered->src ) || $registered->src === '' ) {
                return null;
            }

            $own_file = strpos( $registered->src, Better_Messages()->url ) === 0;

            if ( ! $own_file && ! $this->is_own_handle( $handle ) ) {
                return null;
            }

            $version = is_scalar( $registered->ver ) ? (string) $registered->ver : '';

            if ( $version === '' ) {
                if ( ! $own_file ) {
                    return null;
                }

                $version = Better_Messages()->version;
            }

            return array(
                'version' => $version,
                'path'    => $this->url_path( $registered->src ),
            );
        }

        private function is_own_handle( $handle )
        {
            foreach ( $this->own_handle_prefixes as $prefix ) {
                if ( strpos( $handle, $prefix ) === 0 ) {
                    return true;
                }
            }

            return false;
        }

        private function url_path( $url )
        {
            if ( ! is_string( $url ) || $url === '' ) {
                return '';
            }

            $path = wp_parse_url( $url, PHP_URL_PATH );

            return is_string( $path ) ? $path : '';
        }

        private function with_version( $url, $version )
        {
            if ( ! preg_match( '#^(?:https?:)?/#i', $url ) ) {
                return $url;
            }

            $url      = str_replace( array( '&#038;', '&amp;' ), '&', $url );
            $fragment = '';
            $hash_at  = strpos( $url, '#' );

            if ( $hash_at !== false ) {
                $fragment = substr( $url, $hash_at );
                $url      = substr( $url, 0, $hash_at );
            }

            $query_at = strpos( $url, '?' );
            $base     = $query_at === false ? $url : substr( $url, 0, $query_at );
            $pairs    = $query_at === false ? array() : explode( '&', substr( $url, $query_at + 1 ) );
            $encoded  = rawurlencode( $version );
            $kept     = array( self::MARKER . '=' . $encoded );
            $has_ver  = false;

            foreach ( $pairs as $pair ) {
                $name = strstr( $pair, '=', true );
                $name = $name === false ? $pair : $name;

                if ( $pair === '' || $name === self::MARKER ) {
                    continue;
                }

                if ( $name === 'ver' ) {
                    if ( $pair === 'ver' || $pair === 'ver=' ) {
                        continue;
                    }

                    $has_ver = true;
                }

                $kept[] = $pair;
            }

            if ( ! $has_ver ) {
                $kept[] = 'ver=' . $encoded;
            }

            return $base . '?' . implode( '&', $kept ) . $fragment;
        }
    }

endif;

function Better_Messages_Asset_Versions()
{
    return Better_Messages_Asset_Versions::instance();
}
