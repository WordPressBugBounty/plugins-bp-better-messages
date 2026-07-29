<?php
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'better_messages_suppress_admin_error_display' ) ) {
    function better_messages_suppress_admin_error_display() {
        if ( ! is_admin() || ! isset( $_GET['page'] ) || ! is_string( $_GET['page'] ) ) {
            return;
        }

        $page = wp_unslash( $_GET['page'] );

        if ( strpos( $page, 'bp-better-messages' ) === 0 || strpos( $page, 'better-messages' ) === 0 ) {
            ini_set( 'display_errors', '0' );
        }
    }

    better_messages_suppress_admin_error_display();
}
