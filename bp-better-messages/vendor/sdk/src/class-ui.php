<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_UI' ) ) {
    final class WordPlus_SDK_UI
    {
        const UI = 'wordplus-ui';

        const PAGES = 'wordplus-sdk-pages';

        const SURVEY = 'wordplus-sdk-survey';

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

            wp_register_script( self::PAGES, $sdk->url( 'pages/pages.min.js' ), $i18n->deps( self::PAGES, array( self::UI, 'wp-element', 'wp-i18n', 'wp-api-fetch' ) ), self::asset_version( 'pages/pages.min.js' ), true );
            wp_register_style( self::PAGES, $sdk->url( 'pages/pages.min.css' ), array( self::UI ), self::asset_version( 'pages/pages.min.css' ) );

            wp_register_script( self::SURVEY, $sdk->url( 'tracking/survey.min.js' ), $i18n->deps( self::SURVEY, array( self::UI, 'wp-element', 'wp-i18n', 'wp-api-fetch' ) ), self::asset_version( 'tracking/survey.min.js' ), true );
            wp_register_style( self::SURVEY, $sdk->url( 'tracking/survey.min.css' ), array( self::UI ), self::asset_version( 'tracking/survey.min.css' ) );
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
