<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Toasts' ) ) {
    final class WordPlus_SDK_Toasts
    {
        const HANDLE = 'wordplus-toasts';

        private $sdk;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function script()
        {
            if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
                wp_register_script( self::HANDLE, $this->sdk->url( 'toasts/toasts.min.js' ), $this->sdk->i18n()->deps( self::HANDLE, array( 'wp-i18n' ) ), $this->asset_version( 'toasts/toasts.min.js' ), false );
            }

            if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
                wp_register_style( self::HANDLE, $this->sdk->url( 'toasts/toasts.min.css' ), array(), $this->asset_version( 'toasts/toasts.min.css' ) );
            }

            return self::HANDLE;
        }

        public function enqueue()
        {
            wp_enqueue_script( $this->script() );
            wp_enqueue_style( self::HANDLE );
        }

        private function asset_version( $file )
        {
            return $this->sdk->asset_version( $file );
        }
    }
}
