<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_License_Admin' ) ) {
    class Better_Messages_License_Admin
    {
        const PAGES = array(
            'account' => 'better-messages-account',
            'pricing' => 'better-messages-pricing',
            'contact' => 'better-messages-contact',
        );

        const OLD_ADDONS_PAGE = 'bp-better-messages-addons';

        const VOICE_ADDON_FOLDER = 'bp-better-messages-voice-messages';

        const VOICE_ADDON_CHECKOUT_URL = 'https://www.wordplus.cloud/checkout/voice-messages';

        const VOICE_ADDON_DOCS_URL = 'https://www.better-messages.com/docs/addons/voice-messages/';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new self();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'admin_menu', array( $this, 'menu' ), 30 );
            add_action( 'network_admin_menu', array( $this, 'menu' ), 30 );
            add_action( 'admin_init', array( $this, 'forget_old_events' ) );
        }

        private function sdk_admin()
        {
            $product = Better_Messages_License::instance()->product();

            return $product ? $product->admin() : null;
        }

        public function is_licensing_screen( $screen_id )
        {
            return $this->sdk_admin() ? $this->sdk_admin()->is_page_screen( $screen_id ) : false;
        }

        public function license_capability()
        {
            return $this->sdk_admin() ? $this->sdk_admin()->license_capability() : 'manage_options';
        }

        public function can_change_license()
        {
            return current_user_can( $this->license_capability() );
        }

        public function summary()
        {
            return $this->sdk_admin() ? $this->sdk_admin()->summary() : array();
        }

        public function menu()
        {
            $old_addons = add_submenu_page( '', _x( 'Add-ons', 'WP Admin', 'bp-better-messages' ), '', 'manage_options', self::OLD_ADDONS_PAGE, '__return_null' );

            if ( $old_addons ) {
                add_action( 'load-' . $old_addons, array( $this, 'leave_old_addons_page' ) );
            }
        }

        public function leave_old_addons_page()
        {
            wp_safe_redirect( admin_url( 'admin.php?page=better-messages#/voice-messages' ) );
            exit;
        }

        public function forget_old_events()
        {
            foreach ( array( 'better_messages_license_refresh', 'better_messages_license_verify' ) as $event ) {
                if ( wp_next_scheduled( $event ) ) {
                    wp_clear_scheduled_hook( $event );
                }
            }
        }

        public function voice_addon()
        {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $file    = '';
            $version = '';

            foreach ( get_plugins() as $plugin_file => $plugin ) {
                if ( dirname( $plugin_file ) === self::VOICE_ADDON_FOLDER ) {
                    $file    = $plugin_file;
                    $version = (string) $plugin['Version'];
                    break;
                }
            }

            $active = '' !== $file && ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) );

            if ( $active ) {
                if ( ! Better_Messages()->functions->voice_messages_addon_supported() ) {
                    $state = 'outdated';
                } else {
                    $state = Better_Messages()->functions->voice_messages_addon_licensed() ? 'active' : 'unlicensed';
                }
            } else {
                $state = '' === $file ? 'missing' : 'inactive';
            }

            $licence   = Better_Messages_License::instance()->licence();
            $key_saved = in_array( $state, array( 'missing', 'inactive' ), true ) && null !== $licence
                && method_exists( 'WordPlus_SDK', 'waiting' ) && WordPlus_SDK::instance()->waiting( 'voice-messages', $licence->url() );

            return array(
                'state'       => $state,
                'version'     => $version,
                'keySaved'    => $key_saved,
                'buyUrl'      => $this->voice_addon_checkout( (string) apply_filters( 'better_messages_voice_addon_checkout_url', self::VOICE_ADDON_CHECKOUT_URL ), $state ),
                'docsUrl'     => self::VOICE_ADDON_DOCS_URL,
                'accountUrl'  => Better_Messages_License::ACCOUNT_URL,
                'activateUrl' => 'inactive' === $state && current_user_can( 'activate_plugins' ) ? add_query_arg( array( 'action' => 'activate', 'plugin' => rawurlencode( $file ), '_wpnonce' => wp_create_nonce( 'activate-plugin_' . $file ) ), admin_url( 'plugins.php' ) ) : '',
                'licenseUrl'  => 'unlicensed' !== $state ? '' : ( class_exists( 'Better_Messages_Voice_License_Admin' ) ? Better_Messages_Voice_License_Admin::instance()->page_url() : ( Better_Messages_Legacy_Voice_Addon::adopted() ? Better_Messages_Legacy_Voice_Addon::licence_url() : '' ) ),
                'pluginsUrl'  => admin_url( 'plugins.php' ),
            );
        }

        private function voice_addon_checkout( $checkout, $state )
        {
            $admin = $this->sdk_admin();

            if ( null === $admin || ! method_exists( $admin, 'addon_checkout_url' ) || ! in_array( $state, array( 'missing', 'unlicensed' ), true ) || ! $this->can_change_license() ) {
                return $checkout;
            }

            return $admin->addon_checkout_url( $checkout, admin_url( 'admin.php?page=better-messages#/voice-messages' ), 'missing' === $state );
        }

        public function voice_addon_url()
        {
            $addon = $this->voice_addon();

            if ( '' !== $addon['activateUrl'] ) {
                return $addon['activateUrl'];
            }

            if ( '' !== $addon['licenseUrl'] ) {
                return $addon['licenseUrl'];
            }

            return 'missing' === $addon['state'] ? $addon['buyUrl'] : $addon['pluginsUrl'];
        }
    }
}
