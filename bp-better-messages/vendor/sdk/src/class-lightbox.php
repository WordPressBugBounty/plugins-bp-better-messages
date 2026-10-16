<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Lightbox' ) ) {
    final class WordPlus_SDK_Lightbox
    {
        const LOADER = 'wordplus-lightbox-loader';

        const APP = 'wordplus-lightbox';

        const APP_DEPS = array( 'react', 'react-dom', 'wp-i18n' );

        private $sdk;

        private $enqueued = false;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function enqueue()
        {
            if ( $this->enqueued ) {
                return;
            }

            $this->enqueued = true;

            wp_register_script( self::APP, $this->sdk->url( 'lightbox/lightbox.min.js' ), self::APP_DEPS, $this->sdk->asset_version( 'lightbox/lightbox.min.js' ), true );
            wp_register_script( self::LOADER, $this->sdk->url( 'lightbox/lightbox-loader.min.js' ), array(), $this->sdk->asset_version( 'lightbox/lightbox-loader.min.js' ), true );
            wp_enqueue_script( self::LOADER );
            wp_add_inline_script( self::LOADER, 'window.wordplusLightboxConfig = ' . wp_json_encode( $this->page_config() ) . ';', 'before' );
        }

        public function page_config()
        {
            $i18n = $this->sdk->i18n();

            return array(
                'app'     => $this->sdk->asset_url( 'lightbox/lightbox.min.js' ),
                'style'   => $this->sdk->asset_url( 'lightbox/lightbox.min.css' ),
                'scripts' => $this->sdk->script_chain( $i18n->deps( self::APP, self::APP_DEPS ) ),
                'locale'  => $i18n->inline( self::APP ),
            );
        }
    }
}
