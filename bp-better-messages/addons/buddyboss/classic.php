<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_BuddyBoss_Classic' ) ) {

    class Better_Messages_BuddyBoss_Classic {

        public static function instance() {
            static $instance = null;
            if ( null === $instance ) {
                $instance = new self();
            }
            return $instance;
        }

        public function __construct() {
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 30 );
            add_filter( 'body_class', array( $this, 'body_class' ), 99 );
            add_action( 'bp_before_member_home_content', array( $this, 'render_page_title' ) );
        }

        public function render_page_title() {
            if ( ! $this->is_on_bp_messages_screen() ) {
                return;
            }
            if ( ! $this->is_fullpage_messenger_enabled() || ! $this->is_page_title_enabled() ) {
                return;
            }
            ?>
            <header class="entry-header bm-bb-classic-msg-page-header">
                <h1 class="entry-title"><?php echo esc_html__( 'Messages', 'bp-better-messages' ); ?></h1>
            </header>
            <?php
        }

        public function is_fullpage_messenger_enabled() {
            $settings = Better_Messages()->settings;
            return ! empty( $settings['bbClassicStandaloneMessages'] ) && $settings['bbClassicStandaloneMessages'] === '1';
        }

        public function is_page_title_enabled() {
            $settings = Better_Messages()->settings;
            return ! empty( $settings['bbClassicPageTitle'] ) && $settings['bbClassicPageTitle'] === '1';
        }

        public function is_hide_mini_widget_enabled() {
            $settings = Better_Messages()->settings;
            return ! empty( $settings['bbClassicHideMiniWidget'] ) && $settings['bbClassicHideMiniWidget'] === '1';
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
            if ( ! wp_style_is( 'better-messages', 'enqueued' ) && ! wp_style_is( 'better-messages', 'registered' ) ) {
                return;
            }

            $base_url  = Better_Messages()->url . 'addons/buddyboss/';
            $base_path = Better_Messages()->path . 'addons/buddyboss/';

            $css_file = $base_path . 'classic.css';

            if ( file_exists( $css_file ) ) {
                wp_enqueue_style(
                    'better-messages-bb-classic',
                    $base_url . 'classic.css',
                    array( 'better-messages' ),
                    filemtime( $css_file )
                );
            }
        }

        public function body_class( $classes ) {
            $classes[] = 'bm-bb-classic';

            if ( $this->is_on_bp_messages_screen() ) {
                if ( $this->is_fullpage_messenger_enabled() ) {
                    $classes[] = 'bm-bb-classic-fullpage-msg';
                    if ( $this->is_page_title_enabled() ) {
                        $classes[] = 'bm-bb-classic-page-title';
                    }
                }
                if ( $this->is_hide_mini_widget_enabled() ) {
                    $classes[] = 'bm-bb-classic-hide-mini';
                }
            }

            return $classes;
        }
    }
}
