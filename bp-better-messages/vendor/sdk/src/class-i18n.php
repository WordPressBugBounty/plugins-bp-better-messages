<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_I18n' ) ) {
    final class WordPlus_SDK_I18n
    {
        const DOMAIN = 'wordplus-sdk';

        const SCRIPT = '(function(d){var i=window.wp&&window.wp.i18n;if(i){i.setLocaleData(d,"wordplus-sdk");}})(%s);';

        const NAME = '-[0-9a-f]{12}\.js';

        private $sdk;

        private $scripts;

        private $inline = array();

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function deps( $handle, array $deps = array() )
        {
            $script = $handle . '-i18n';

            if ( ! wp_script_is( $script, 'registered' ) ) {
                $hash = $this->hash( $handle );
                $url  = $hash ? $this->url( $handle, $hash ) : false;

                if ( $url ) {
                    wp_register_script( $script, $url, array( 'wp-i18n' ), $this->sdk->version(), true );
                } elseif ( isset( $this->inline[ $handle ] ) ) {
                    wp_register_script( $script, false, array( 'wp-i18n' ), $this->sdk->version(), true );
                    wp_add_inline_script( $script, $this->script( $this->inline[ $handle ] ) );
                } else {
                    return $deps;
                }
            }

            $deps[] = $script;

            return $deps;
        }

        public function inline( $handle )
        {
            return isset( $this->inline[ $handle ] ) ? array( 'locale_data' => array( self::DOMAIN => $this->inline[ $handle ] ) ) : null;
        }

        private function url( $handle, $hash )
        {
            $locale = $this->locale();
            $prefix = $this->name( $handle ) . '-' . $locale;
            $name   = $prefix . '-' . $hash . '.js';
            $dir    = $this->dir();

            if ( file_exists( $dir . $name ) ) {
                return $this->upload( 'baseurl' ) . '/wordplus/i18n/' . $name;
            }

            $data = $this->data( $handle, $locale );

            if ( ! $data ) {
                return false;
            }

            if ( ! wp_mkdir_p( $dir ) || false === @file_put_contents( $dir . $name, $this->script( $data ) ) ) {
                $this->inline[ $handle ] = $data;

                return false;
            }

            $rules = "<IfModule mod_headers.c>\n<FilesMatch \"^[a-z-]+-[A-Za-z0-9_]+" . self::NAME . "$\">\n  Header set Cache-Control \"public, max-age=31536000, immutable\"\n</FilesMatch>\n</IfModule>\n";

            if ( ! file_exists( $dir . '.htaccess' ) || file_get_contents( $dir . '.htaccess' ) !== $rules ) {
                file_put_contents( $dir . '.htaccess', $rules );
            }

            foreach ( glob( $dir . '*.js' ) ?: array() as $old ) {
                if ( $old !== $dir . $name && preg_match( '/^' . preg_quote( $prefix, '/' ) . self::NAME . '$/', basename( $old ) ) ) {
                    wp_delete_file( $old );
                }
            }

            return $this->upload( 'baseurl' ) . '/wordplus/i18n/' . $name;
        }

        private function hash( $handle )
        {
            $hashes = $this->scripts( 'hashes' );
            $locale = $this->locale();

            return isset( $hashes[ $locale ][ $handle ] ) ? substr( md5( self::SCRIPT . $hashes[ $locale ][ $handle ] ), 0, 12 ) : null;
        }

        private function data( $handle, $locale )
        {
            $strings = $this->scripts( 'strings' );
            $wanted  = array();

            foreach ( isset( $strings[ $handle ] ) ? $strings[ $handle ] : array() as $string ) {
                $wanted[ isset( $string[1] ) ? $string[1] . "\4" . $string[0] : $string[0] ] = true;
            }

            $this->sdk->load_text_domain();

            $translations = get_translations_for_domain( self::DOMAIN );
            $data         = array();

            foreach ( (array) $translations->entries as $entry ) {
                $key = $entry->key();

                if ( isset( $wanted[ $key ] ) && array() !== array_filter( (array) $entry->translations, 'strlen' ) ) {
                    $data[ $key ] = array_values( (array) $entry->translations );
                }
            }

            if ( ! $data ) {
                return array();
            }

            $headers = (array) $translations->headers;
            $plural  = isset( $headers['Plural-Forms'] ) ? $headers['Plural-Forms'] : ( isset( $headers['plural-forms'] ) ? $headers['plural-forms'] : '' );

            return array( '' => array_filter( array( 'domain' => self::DOMAIN, 'lang' => $locale, 'plural-forms' => $plural ) ) ) + $data;
        }

        private function script( array $data )
        {
            return sprintf( self::SCRIPT, wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) );
        }

        private function scripts( $key )
        {
            if ( null === $this->scripts ) {
                $file          = $this->sdk->path( 'languages/scripts.php' );
                $this->scripts = is_readable( $file ) ? (array) include $file : array();
            }

            return isset( $this->scripts[ $key ] ) ? (array) $this->scripts[ $key ] : array();
        }

        private function name( $handle )
        {
            return preg_replace( '/^wordplus-/', '', $handle );
        }

        private function locale()
        {
            return determine_locale();
        }

        private function dir()
        {
            return $this->upload( 'basedir' ) . '/wordplus/i18n/';
        }

        private function upload( $key )
        {
            $upload = wp_upload_dir( null, false );

            return untrailingslashit( (string) $upload[ $key ] );
        }
    }
}
