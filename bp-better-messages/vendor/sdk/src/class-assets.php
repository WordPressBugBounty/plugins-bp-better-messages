<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Assets' ) ) {
    final class WordPlus_SDK_Assets
    {
        const MARKER = 'wplusv';

        const COPY_PATHS = array( '/vendor/sdk/', '/vendor/wordplus-sdk/' );

        private $sdk;

        private $hooked = false;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function hook()
        {
            if ( $this->hooked ) {
                return;
            }

            $this->hooked = true;

            add_filter( 'script_loader_src', array( $this, 'script_src' ), PHP_INT_MAX, 2 );
            add_filter( 'style_loader_src', array( $this, 'style_src' ), PHP_INT_MAX, 2 );
            add_filter( 'script_loader_tag', array( $this, 'script_tag' ), PHP_INT_MAX, 3 );
            add_filter( 'style_loader_tag', array( $this, 'style_tag' ), PHP_INT_MAX, 3 );
        }

        public function script_src( $src, $handle )
        {
            return $this->src( $src, $handle, false );
        }

        public function style_src( $src, $handle )
        {
            return $this->src( $src, $handle, true );
        }

        public function script_tag( $tag, $handle, $src = '' )
        {
            return $this->tag( $tag, $handle, $src, false );
        }

        public function style_tag( $tag, $handle, $src = '' )
        {
            return $this->tag( $tag, $handle, $src, true );
        }

        private function src( $src, $handle, $is_style )
        {
            if ( ! is_string( $src ) || '' === $src ) {
                return $src;
            }

            $asset = $this->find( $handle, $is_style );

            return null === $asset ? $src : self::versioned( $src, $asset['version'] );
        }

        private function tag( $tag, $handle, $src, $is_style )
        {
            if ( ! is_string( $tag ) || '' === $tag || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
                return $tag;
            }

            $asset = $this->find( $handle, $is_style );

            if ( null === $asset ) {
                return $tag;
            }

            $paths     = array_filter( array( $asset['path'], self::path_of( $src ) ) );
            $attribute = $is_style ? 'href' : 'src';
            $tags      = new WP_HTML_Tag_Processor( $tag );
            $changed   = false;

            while ( $tags->next_tag( $is_style ? 'link' : 'script' ) ) {
                $url = $tags->get_attribute( $attribute );

                if ( ! is_string( $url ) || ! in_array( self::path_of( $url ), $paths, true ) ) {
                    continue;
                }

                $versioned = self::versioned( $url, $asset['version'] );

                if ( $versioned !== $url && $tags->set_attribute( $attribute, $versioned ) ) {
                    $changed = true;
                }
            }

            return $changed ? $tags->get_updated_html() : $tag;
        }

        private function find( $handle, $is_style )
        {
            if ( ! is_string( $handle ) || '' === $handle ) {
                return null;
            }

            $dependencies = $is_style ? wp_styles() : wp_scripts();

            if ( ! isset( $dependencies->registered[ $handle ] ) && '-rtl' === substr( $handle, -4 ) ) {
                $handle = substr( $handle, 0, -4 );
            }

            if ( ! isset( $dependencies->registered[ $handle ] ) ) {
                return null;
            }

            $registered = $dependencies->registered[ $handle ];
            $path       = is_string( $registered->src ) ? self::path_of( $registered->src ) : '';

            if ( '' === $path || ( ! self::in_copy( $path ) && 0 !== strpos( $path, self::path_of( $this->sdk->url( '' ) ) ) ) ) {
                return null;
            }

            $version = is_scalar( $registered->ver ) ? (string) $registered->ver : '';

            return array(
                'version' => '' !== $version ? $version : $this->sdk->version(),
                'path'    => $path,
            );
        }

        private static function in_copy( $path )
        {
            foreach ( self::COPY_PATHS as $copy ) {
                if ( false !== strpos( $path, $copy ) ) {
                    return true;
                }
            }

            return false;
        }

        private static function path_of( $url )
        {
            if ( ! is_string( $url ) || '' === $url ) {
                return '';
            }

            $path = wp_parse_url( $url, PHP_URL_PATH );

            return is_string( $path ) ? $path : '';
        }

        public static function versioned( $url, $version )
        {
            if ( ! is_string( $url ) || ! preg_match( '#^(?:https?:)?/#i', $url ) ) {
                return $url;
            }

            $url      = str_replace( array( '&#038;', '&amp;' ), '&', $url );
            $fragment = '';
            $hash_at  = strpos( $url, '#' );

            if ( false !== $hash_at ) {
                $fragment = substr( $url, $hash_at );
                $url      = substr( $url, 0, $hash_at );
            }

            $query_at = strpos( $url, '?' );
            $base     = false === $query_at ? $url : substr( $url, 0, $query_at );
            $pairs    = false === $query_at ? array() : explode( '&', substr( $url, $query_at + 1 ) );
            $kept     = array( self::MARKER . '=' . rawurlencode( (string) $version ) );

            foreach ( $pairs as $pair ) {
                $name = strstr( $pair, '=', true );
                $name = false === $name ? $pair : $name;

                if ( '' === $pair || self::MARKER === $name || 'ver' === $name ) {
                    continue;
                }

                $kept[] = $pair;
            }

            return $base . '?' . implode( '&', $kept ) . $fragment;
        }
    }
}
