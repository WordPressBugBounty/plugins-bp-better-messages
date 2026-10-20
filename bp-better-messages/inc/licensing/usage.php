<?php
defined( 'ABSPATH' ) || exit;

function better_messages_usage_data()
{
    global $wpdb;

    $usage = array(
        'build'    => better_messages_is_websocket_build() ? 'websocket' : 'free',
        'realtime' => ! empty( Better_Messages()->realtime ),
    );

    $private = '/(key|secret|token|pass|salt|hash|licen[cs]e|e-?mail|url|domain|host|endpoint|webhook|api|private|cert|bucket|folder|path|prefix|zone|account|username|login|phone|address)/i';

    foreach ( (array) Better_Messages()->settings as $name => $value ) {
        $name = (string) $name;

        if ( preg_match( $private, $name ) || ! preg_match( '/^[A-Za-z0-9_]{1,80}$/', $name ) ) {
            continue;
        }

        $key = 'setting.' . $name;

        if ( is_bool( $value ) || is_int( $value ) || ( is_float( $value ) && is_finite( $value ) ) ) {
            $usage[ $key ] = $value;
        } elseif ( is_array( $value ) ) {
            $usage[ $key . '.count' ] = count( $value );
        } elseif ( is_string( $value ) ) {
            if ( '0' === $value || '1' === $value ) {
                $usage[ $key ] = '1' === $value;
            } elseif ( is_numeric( $value ) && strlen( $value ) <= 12 ) {
                $usage[ $key ] = $value + 0;
            } elseif ( '' === $value ) {
                $usage[ $key ] = '';
            } elseif ( preg_match( '/^[a-z][a-z0-9_.-]{0,23}$/', $value ) && ! preg_match( '/\d{4,}/', $value ) ) {
                $usage[ $key ] = $value;
            }
        }
    }

    $range = function ( $count ) {
        $count = (int) $count;

        foreach ( array( 0 => '0', 10 => '1-9', 100 => '10-99', 1000 => '100-999', 10000 => '1k-10k', 100000 => '10k-100k', 1000000 => '100k-1m' ) as $below => $label ) {
            if ( $count < $below || ( 0 === $below && 0 === $count ) ) {
                return $label;
            }
        }

        return '1m+';
    };

    $month_ago = ( time() - 30 * DAY_IN_SECONDS ) * 10000;

    $usage['size.members']        = $range( $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ) );
    $usage['size.conversations']  = $range( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . bm_get_table( 'threads' ) ) );
    $usage['size.messages_30d']   = $range( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . bm_get_table( 'messages' ) . ' WHERE `created_at` > %d', $month_ago ) ) );
    $usage['size.guests']         = $range( $wpdb->get_var( 'SELECT COUNT(*) FROM ' . bm_get_table( 'guests' ) ) );
    $rooms                        = wp_count_posts( 'bpbm-chat' );
    $usage['size.chat_rooms']     = $range( isset( $rooms->publish ) ? $rooms->publish : 0 );

    if ( function_exists( 'Better_Messages_Get_Started' ) ) {
        $usage = array_merge( $usage, Better_Messages_Get_Started()->usage() );
    }

    return $usage;
}
