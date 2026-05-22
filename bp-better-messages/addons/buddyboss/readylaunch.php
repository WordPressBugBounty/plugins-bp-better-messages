<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyBoss_ReadyLaunch' ) ) {

    class Better_Messages_BuddyBoss_ReadyLaunch {

        public static function instance() {
            static $instance = null;
            if ( null === $instance ) {
                $instance = new self();
            }
            return $instance;
        }

        public static function is_active() {
            if ( function_exists( 'bb_load_readylaunch' ) ) {
                $rl = bb_load_readylaunch();
                if ( is_object( $rl ) && method_exists( $rl, 'bb_is_enabled' ) ) {
                    return (bool) $rl->bb_is_enabled();
                }
            }
            $opt = get_option( 'bb_rl_enabled' );
            return $opt === '1' || $opt === 1 || $opt === true;
        }

        public function __construct() {
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 30 );
            add_filter( 'body_class', array( $this, 'body_class' ), 99 );
        }

        public function is_fullpage_messenger_enabled() {
            $settings = Better_Messages()->settings;
            return ! empty( $settings['bbRLStandaloneMessages'] ) && $settings['bbRLStandaloneMessages'] === '1';
        }

        public function is_fullscreen_enabled() {
            $settings = Better_Messages()->settings;
            return ! empty( $settings['bbRLFullScreen'] ) && $settings['bbRLFullScreen'] === '1';
        }

        public function is_page_title_enabled() {
            $settings = Better_Messages()->settings;
            return ! empty( $settings['bbRLPageTitle'] ) && $settings['bbRLPageTitle'] === '1';
        }

        public function is_hide_mini_widget_enabled() {
            $settings = Better_Messages()->settings;
            return ! empty( $settings['bbRLHideMiniWidget'] ) && $settings['bbRLHideMiniWidget'] === '1';
        }

        public function is_on_bp_messages_screen() {
            if ( ! function_exists( 'bp_is_current_component' ) ) {
                return false;
            }
            $bm_slug = isset( Better_Messages()->settings['bpProfileSlug'] ) ? Better_Messages()->settings['bpProfileSlug'] : 'bp-messages';
            if ( bp_is_current_component( $bm_slug ) ) {
                return true;
            }
            return function_exists( 'bp_is_messages_component' ) && bp_is_messages_component();
        }

        public function enqueue_assets() {
            if ( ! self::is_active() ) {
                return;
            }
            if ( ! wp_style_is( 'better-messages', 'enqueued' ) && ! wp_style_is( 'better-messages', 'registered' ) ) {
                return;
            }

            $base_url  = Better_Messages()->url . 'addons/buddyboss/';
            $base_path = Better_Messages()->path . 'addons/buddyboss/';

            if ( function_exists( 'buddypress' ) && ! empty( buddypress()->plugin_url ) && ! wp_style_is( 'bm-bb-legacy-icons', 'enqueued' ) ) {
                wp_enqueue_style(
                    'bm-bb-legacy-icons',
                    buddypress()->plugin_url . 'bp-templates/bp-nouveau/icons/css/bb-icons.min.css',
                    array(),
                    defined( 'BP_PLATFORM_VERSION' ) ? BP_PLATFORM_VERSION : null
                );
            }

            $css_file = $base_path . 'readylaunch.css';
            $js_file  = $base_path . 'readylaunch.js';

            if ( file_exists( $css_file ) ) {
                wp_enqueue_style(
                    'better-messages-bb-readylaunch',
                    $base_url . 'readylaunch.css',
                    array( 'better-messages' ),
                    filemtime( $css_file )
                );
            }

            if ( file_exists( $js_file ) ) {
                wp_enqueue_script(
                    'better-messages-bb-readylaunch',
                    $base_url . 'readylaunch.js',
                    array( 'better-messages' ),
                    filemtime( $js_file ),
                    true
                );

                wp_localize_script( 'better-messages-bb-readylaunch', 'BMBBReadyLaunch', array(
                    'themeMode'      => $this->get_theme_mode(),
                    'messagesTitle'  => __( 'Messages', 'bp-better-messages' ),
                ) );
            }
        }

        public function body_class( $classes ) {
            if ( ! self::is_active() ) {
                return $classes;
            }

            $classes[] = 'bm-bb-readylaunch';

            if ( $this->is_dark_mode_active() ) {
                $classes = array_values( array_diff( $classes, array( 'bm-messages-light' ) ) );
                if ( ! in_array( 'bm-messages-dark', $classes, true ) ) {
                    $classes[] = 'bm-messages-dark';
                }
            } else {
                $classes = array_values( array_diff( $classes, array( 'bm-messages-dark' ) ) );
                if ( ! in_array( 'bm-messages-light', $classes, true ) ) {
                    $classes[] = 'bm-messages-light';
                }
            }

            if ( $this->is_on_bp_messages_screen() ) {
                if ( $this->is_fullpage_messenger_enabled() ) {
                    $classes[] = 'bm-bb-rl-fullpage-msg';
                    if ( $this->is_fullscreen_enabled() ) {
                        $classes[] = 'bm-bb-rl-fullscreen';
                    }
                    if ( $this->is_page_title_enabled() ) {
                        $classes[] = 'bm-bb-rl-page-title';
                    }
                }
                if ( $this->is_hide_mini_widget_enabled() ) {
                    $classes[] = 'bm-bb-rl-hide-mini';
                }
            }

            return $classes;
        }

        public function get_theme_mode() {
            $mode = get_option( 'bb_rl_theme_mode', 'choice' );
            return in_array( $mode, array( 'light', 'dark', 'choice' ), true ) ? $mode : 'choice';
        }

        public function is_dark_mode_active() {
            $mode = $this->get_theme_mode();

            if ( $mode === 'dark' ) {
                return true;
            }

            if ( $mode === 'choice' && isset( $_COOKIE['bb-rl-dark-mode'] ) ) {
                return sanitize_text_field( wp_unslash( $_COOKIE['bb-rl-dark-mode'] ) ) === 'true';
            }

            return false;
        }
    }
}
