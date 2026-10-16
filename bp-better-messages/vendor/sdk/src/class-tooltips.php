<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Tooltips' ) ) {
    final class WordPlus_SDK_Tooltips
    {
        const HANDLE = 'wordplus-tooltips';

        private $sdk;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function script()
        {
            if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
                wp_register_script( self::HANDLE, $this->sdk->url( 'tooltips/tooltips.min.js' ), array(), $this->asset_version( 'tooltips/tooltips.min.js' ), false );
            }

            if ( ! wp_style_is( self::HANDLE, 'registered' ) ) {
                wp_register_style( self::HANDLE, $this->sdk->url( 'tooltips/tooltips.min.css' ), array(), $this->asset_version( 'tooltips/tooltips.min.css' ) );
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
