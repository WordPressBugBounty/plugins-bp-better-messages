<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_UI' ) ) {
    final class WordPlus_SDK_UI
    {
        const UI = 'wordplus-ui';

        const PAGES = 'wordplus-sdk-pages';

        const SURVEY = 'wordplus-sdk-survey';

        const SCHEMES = array(
            'fresh'     => array( '#2271b1', '#135e96', '#0a4b78' ),
            'light'     => array( '#007cba', '#006ba1', '#005a87' ),
            'modern'    => array( '#3858e9', '#2145e6', '#183ad6' ),
            'blue'      => array( '#437aa8', '#3c6d96', '#346084' ),
            'coffee'    => array( '#916745', '#805b3d', '#6e4e35' ),
            'ectoplasm' => array( '#646c3e', '#555c35', '#464c2b' ),
            'midnight'  => array( '#cf4339', '#c0382f', '#ab322a' ),
            'ocean'     => array( '#567958', '#4b6a4d', '#415b42' ),
            'sunrise'   => array( '#ad631e', '#97571a', '#824a16' ),
        );

        private static $page = null;

        public static function register_hooks()
        {
            if ( ! has_action( 'admin_enqueue_scripts', array( __CLASS__, 'register' ) ) ) {
                add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register' ), 0 );
            }
        }

        public static function asset_version( $relative )
        {
            return WordPlus_SDK::instance()->asset_version( $relative );
        }

        public static function register()
        {
            if ( wp_script_is( self::UI, 'registered' ) ) {
                return;
            }

            $sdk  = WordPlus_SDK::instance();
            $i18n = $sdk->i18n();

            wp_register_script( self::UI, $sdk->url( 'ui/ui.min.js' ), $i18n->deps( self::UI, array( 'wp-element', 'wp-i18n' ) ), self::asset_version( 'ui/ui.min.js' ), true );
            wp_register_style( self::UI, $sdk->url( 'ui/ui.min.css' ), array(), self::asset_version( 'ui/ui.min.css' ) );

            $scheme = self::scheme_css();

            if ( '' !== $scheme ) {
                wp_add_inline_style( self::UI, $scheme );
            }

            wp_register_script( self::PAGES, $sdk->url( 'pages/pages.min.js' ), $i18n->deps( self::PAGES, array( self::UI, 'wp-element', 'wp-i18n', 'wp-api-fetch' ) ), self::asset_version( 'pages/pages.min.js' ), true );
            wp_register_style( self::PAGES, $sdk->url( 'pages/pages.min.css' ), array( self::UI ), self::asset_version( 'pages/pages.min.css' ) );

            wp_register_script( self::SURVEY, $sdk->url( 'tracking/survey.min.js' ), $i18n->deps( self::SURVEY, array( self::UI, 'wp-element', 'wp-i18n', 'wp-api-fetch' ) ), self::asset_version( 'tracking/survey.min.js' ), true );
            wp_register_style( self::SURVEY, $sdk->url( 'tracking/survey.min.css' ), array( self::UI ), self::asset_version( 'tracking/survey.min.css' ) );
        }

        public static function scheme_css( $scheme = null )
        {
            $styles = wp_styles();

            if ( isset( $styles->registered['wp-admin'] ) && in_array( 'wp-base-styles', (array) $styles->registered['wp-admin']->deps, true ) ) {
                return '';
            }

            $scheme = null === $scheme ? (string) get_user_option( 'admin_color' ) : (string) $scheme;

            if ( ! isset( self::SCHEMES[ $scheme ] ) ) {
                return '';
            }

            $names = array( '--wp-admin-theme-color', '--wp-admin-theme-color-darker-10', '--wp-admin-theme-color-darker-20' );
            $css   = '';

            foreach ( self::SCHEMES[ $scheme ] as $index => $color ) {
                $rgb  = implode( ', ', array_map( 'hexdec', str_split( substr( $color, 1 ), 2 ) ) );
                $css .= $names[ $index ] . ':' . $color . ';' . $names[ $index ] . '--rgb:' . $rgb . ';';
            }

            return ':where(body.admin-color-' . $scheme . '){' . $css . '}';
        }

        public static function enqueue_survey( array $data )
        {
            self::register();
            wp_enqueue_script( self::SURVEY );
            wp_enqueue_style( self::SURVEY );
            wp_add_inline_script( self::SURVEY, 'window.wordplusSdkSurvey = ' . wp_json_encode( $data ) . ';', 'before' );
        }

        public static function enqueue()
        {
            self::register();
            wp_enqueue_script( self::UI );
            wp_enqueue_style( self::UI );
        }

        public static function enqueue_page( array $data )
        {
            self::$page = $data;

            add_action( 'admin_enqueue_scripts', function () {
                self::register();
                wp_enqueue_script( self::PAGES );
                wp_enqueue_style( self::PAGES );
                wp_add_inline_script( self::PAGES, 'window.wordplusSdkPage = ' . wp_json_encode( self::$page ) . ';', 'before' );
            } );

            add_filter( 'admin_body_class', function ( $classes ) {
                return $classes . ' wplus-screen';
            } );
        }
    }
}
