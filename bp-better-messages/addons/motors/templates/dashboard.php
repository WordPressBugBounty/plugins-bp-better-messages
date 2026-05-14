<?php
defined( 'ABSPATH' ) || exit;

if ( ! is_user_logged_in() ) {
    echo '<div class="bm-motors-dashboard-not-logged-in">'
        . esc_html_x( 'Please log in to view your messages.', 'Motors Integration (Dashboard tab)', 'bp-better-messages' )
        . '</div>';
    return;
}

echo '<div class="bm-motors-dashboard-messages">' . do_shortcode( '[better_messages]' ) . '</div>';
