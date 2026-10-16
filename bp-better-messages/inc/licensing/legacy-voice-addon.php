<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Legacy_Voice_Addon' ) ) {
    class Better_Messages_Legacy_Voice_Addon
    {
        const SLUG = 'voice-messages';

        private static $adopted = false;

        public static function hook()
        {
            add_action( 'plugins_loaded', array( __CLASS__, 'maybe_adopt' ), 25 );
        }

        public static function is_legacy()
        {
            return function_exists( 'bbmvm_fs' ) && class_exists( 'BP_Better_Messages_Voice_Messages' ) && ! defined( 'BP_Better_Messages_Voice_Messages::VERSION' );
        }

        public static function adopted()
        {
            return self::$adopted;
        }

        public static function maybe_adopt()
        {
            if ( self::$adopted || ! self::is_legacy() || ! function_exists( 'wordplus_sdk_register' ) || isset( $GLOBALS['bbmvm_fs'] ) ) {
                return;
            }

            $reflection = new ReflectionFunction( 'bbmvm_fs' );
            $file       = (string) $reflection->getFileName();
            $headers    = get_file_data( $file, array( 'Version' => 'Version' ) );

            wordplus_sdk_register( array(
                'slug'        => self::SLUG,
                'file'        => $file,
                'version'     => (string) $headers['Version'],
                'name'        => 'Voice and Video Messages',
                'icon'        => plugins_url( 'assets/images/icon.png', $file ),
                'licence'     => 'own',
                'option'      => 'better_messages_voice_license',
                'beta_option' => 'better_messages_voice_beta_updates',
                'paid_build'  => true,
                'menu'        => array(
                    'parent' => '',
                    'pages'  => array( 'account' => 'better-messages-voice-license', 'pricing' => false, 'contact' => false ),
                ),
                'freemius'    => array( 'product' => '8659', 'slug' => 'bp-better-messages-voice-messages', 'plans' => array( '*' => 'voice_messages' ) ),
                'support'     => array( self::SLUG => 'Voice and Video Messages' ),
                'urls'        => array(
                    'homepage' => 'https://www.better-messages.com/docs/addons/voice-messages/',
                    'docs'     => 'https://www.better-messages.com/docs/addons/voice-messages/',
                    'settings' => admin_url( 'admin.php?page=better-messages#/voice-messages' ),
                    'buy'      => Better_Messages_License_Admin::VOICE_ADDON_CHECKOUT_URL,
                ),
                'words'       => array( __CLASS__, 'words' ),
            ) );

            $GLOBALS['bbmvm_fs'] = new Better_Messages_Legacy_Voice_Freemius();
            self::$adopted       = true;
        }

        public static function product()
        {
            return self::$adopted && function_exists( 'wordplus_sdk' ) ? wordplus_sdk( self::SLUG ) : null;
        }

        public static function is_licensed()
        {
            $product = self::product();

            if ( null === $product ) {
                return false;
            }

            $product->licence()->maybe_migrate();

            return $product->has_access();
        }

        public static function licence_url()
        {
            $product = self::product();

            return null !== $product ? $product->page_url( 'account' ) : admin_url( 'admin.php?page=better-messages-account' );
        }

        public static function words()
        {
            return array( 'plans' => array( 'voice_messages' => _x( 'Voice and Video Messages', 'Licensing (WP Admin)', 'bp-better-messages' ) ) );
        }
    }
}

if ( ! class_exists( 'Better_Messages_Legacy_Voice_Freemius' ) ) {
    class Better_Messages_Legacy_Voice_Freemius
    {
        public function is_paying()
        {
            return Better_Messages_Legacy_Voice_Addon::is_licensed();
        }

        public function can_use_premium_code()
        {
            return Better_Messages_Legacy_Voice_Addon::is_licensed();
        }

        public function get_upgrade_url()
        {
            return Better_Messages_Legacy_Voice_Addon::licence_url();
        }

        public function get_activation_url()
        {
            return Better_Messages_Legacy_Voice_Addon::licence_url();
        }

        public function __call( $method, $arguments )
        {
            return false;
        }
    }
}

Better_Messages_Legacy_Voice_Addon::hook();
