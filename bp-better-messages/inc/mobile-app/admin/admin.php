<?php

defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Mobile_App_Admin' ) ):

    class Better_Messages_Mobile_App_Admin
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Mobile_App_Admin();
            }

            return $instance;
        }

        public function __construct(){
            add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
            add_action( 'admin_menu', array( $this, 'settings_page' ), 20 );
            add_action( 'admin_notices', array( $this, 'core_notice' ) );
            add_action( 'admin_init', array( $this, 'dismiss_core_notice' ) );
        }

        public function dismiss_core_notice(){
            if( ! isset( $_GET['bm_dismiss_app_core'] ) || ! current_user_can( 'manage_options' ) ){
                return;
            }

            check_admin_referer( 'bm_dismiss_app_core' );

            update_user_meta( get_current_user_id(), 'bm_app_core_notice_dismissed', absint( $_GET['bm_dismiss_app_core'] ) );

            wp_safe_redirect( remove_query_arg( array( 'bm_dismiss_app_core', '_wpnonce' ) ) );
            exit;
        }

        public function core_notice(){
            if( ! current_user_can( 'manage_options' ) ){
                return;
            }

            if( ! Better_Messages_Mobile_App()->can_build ){
                return;
            }

            $screen = get_current_screen();

            if( $screen && strpos( $screen->id, 'better-messages-mobile-app' ) !== false ){
                return;
            }

            $state = get_option( 'better-messages-app-outdated-builds', false );

            if( ! is_array( $state ) || empty( $state['builds'] ) || empty( $state['revision'] ) ){
                return;
            }

            if( (int) get_user_meta( get_current_user_id(), 'bm_app_core_notice_dismissed', true ) >= (int) $state['revision'] ){
                return;
            }

            $labels = array();

            foreach( $state['builds'] as $build ){
                $labels[] = ( $build['platform'] === 'ios' ? 'iOS' : 'Android' ) . ' ' . ( $build['type'] === 'production'
                    ? _x( 'production', 'Admin notice', 'bp-better-messages' )
                    : _x( 'development', 'Admin notice', 'bp-better-messages' ) );
            }

            $page_url    = admin_url( 'admin.php?page=better-messages-mobile-app#/overview' );
            $dismiss_url = wp_nonce_url( add_query_arg( 'bm_dismiss_app_core', (int) $state['revision'] ), 'bm_dismiss_app_core' );

            echo '<div class="notice notice-warning"><p><b>Better Messages</b> ';
            echo sprintf(
                esc_html_x( 'A newer version of the mobile app is available (app revision %1$d). Your %2$s builds carry an older one — build again and submit the new build to the stores.', 'Admin notice', 'bp-better-messages' ),
                (int) $state['revision'],
                esc_html( implode( ', ', $labels ) )
            );

            if( ! empty( $state['notes'] ) ){
                echo ' <i>' . esc_html( $state['notes'] ) . '</i>';
            }

            echo ' <a href="' . esc_url( $page_url ) . '">' . esc_html_x( 'Open the Mobile App page', 'Admin notice', 'bp-better-messages' ) . '</a>';
            echo ' &middot; <a href="' . esc_url( $dismiss_url ) . '">' . esc_html_x( 'Dismiss for this version', 'Admin notice', 'bp-better-messages' ) . '</a>';
            echo '</p></div>';
        }

        public function enqueue_scripts(){
            if( ! defined('BM_DEV') ) return;
            //$filepath = Better_Messages()->path . 'assets/admin/admin.js';

            //$version = Better_Messages()->version;
            //$version .= filemtime( $filepath );

            //wp_register_script('better-messages-admin', Better_Messages()->url . 'assets/admin.js', [], $version, true );
            //wp_enqueue_script( 'better-messages-admin' );
        }

        public function settings_page(){
            add_submenu_page(
                'bp-better-messages',
                _x('Mobile App', 'Admin Menu', 'bp-better-messages'),
                _x('Mobile App', 'Admin Menu', 'bp-better-messages'),
                'manage_options',
                'better-messages-mobile-app',
                array($this, 'settings_page_html'),
                5
            );
        }

        public function settings_page_html(){
            $data = Better_Messages()->mobile_app->settings->get_page_data();
            ?>
            <script type="text/javascript">
                window.BM_MobileApp_Data = <?php echo wp_json_encode( $data ); ?>;
            </script>
            <div id="bm-mobile-app-admin"></div>
            <?php
        }
    }

endif;

function Better_Messages_Mobile_App_Admin()
{
    return Better_Messages_Mobile_App_Admin::instance();
}
