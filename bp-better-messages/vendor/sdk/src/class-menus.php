<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Menus' ) ) {
    final class WordPlus_SDK_Menus
    {
        const HANDLE = 'wordplus-menus';

        private $sdk;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function script()
        {
            if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
                $file = 'menus/menus.min.js';

                wp_register_script( self::HANDLE, $this->sdk->url( $file ), array(), $this->sdk->asset_version( $file ), false );
            }

            return self::HANDLE;
        }

        public function enqueue()
        {
            wp_enqueue_script( $this->script() );
        }
    }
}
