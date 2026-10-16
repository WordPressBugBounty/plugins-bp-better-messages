<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Stickers_REST' ) ) {
    class Better_Messages_Stickers_REST
    {
        private static $instance = null;

        public static function instance()
        {
            if ( self::$instance === null ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        }

        public function register_routes()
        {
            $library = Better_Messages_Stickers_Manager::library();
            if ( ! $library ) {
                return;
            }

            $library->rest()->library_routes(
                'better-messages/v1/admin',
                '/sticker-packs',
                '/sticker-catalog',
                'better-messages/' . Better_Messages()->version
            );
        }
    }
}

if ( ! function_exists( 'Better_Messages_Stickers_REST' ) ) {
    function Better_Messages_Stickers_REST()
    {
        return Better_Messages_Stickers_REST::instance();
    }
}
