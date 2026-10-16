<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_License' ) ) {
    class Better_Messages_License
    {
        const OPTION = 'wordplus_cloud_license';

        const PLUGIN = 'better-messages';

        const ACCOUNT_URL = 'https://www.wordplus.cloud/licenses';

        const FREEMIUS_PRODUCT = '1557';

        const FREEMIUS_SLUG = 'bp-better-messages';

        const BETA_OPTION = 'better_messages_beta_updates';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new self();
            }

            return $instance;
        }

        public function product()
        {
            return function_exists( 'wordplus_sdk' ) ? wordplus_sdk( self::PLUGIN ) : null;
        }

        public function licence()
        {
            $product = $this->product();

            return $product ? $product->licence() : null;
        }

        public function get()
        {
            return $this->licence() ? $this->licence()->get() : array();
        }

        public function get_key()
        {
            return $this->licence() ? $this->licence()->get_key() : '';
        }

        public function is_active()
        {
            return $this->product() ? $this->product()->has_access() : false;
        }

        public function get_activation_id()
        {
            return $this->licence() ? $this->licence()->get_activation_id() : 0;
        }

        public function site_credentials()
        {
            return $this->licence() ? $this->licence()->site_credentials() : null;
        }

        public function realtime_credentials()
        {
            return $this->licence() ? $this->licence()->realtime_credentials() : array( 'site_id' => null, 'secret' => '' );
        }

        public function url()
        {
            return $this->licence() ? $this->licence()->url() : site_url();
        }

        public function server()
        {
            return class_exists( 'WordPlus_SDK' ) ? WordPlus_SDK::instance()->server() : 'https://api.wordplus.cloud/';
        }

        public function activate( $key )
        {
            return $this->licence() ? $this->licence()->activate( $key, self::PLUGIN ) : new WP_Error( 'unavailable', 'The licence is not available yet.' );
        }

        public function wants_beta()
        {
            return $this->product() ? $this->product()->wants_beta() : false;
        }

        public function set_beta( $enabled )
        {
            if ( $this->product() ) {
                $this->product()->set_beta( $enabled );
            }
        }

        public function __call( $method, $arguments )
        {
            $licence = $this->licence();

            if ( null === $licence || ! is_callable( array( $licence, $method ) ) ) {
                return new WP_Error( 'unavailable', 'The licence is not available yet.' );
            }

            return call_user_func_array( array( $licence, $method ), $arguments );
        }
    }
}
