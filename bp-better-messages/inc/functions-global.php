<?php
defined( 'ABSPATH' ) || exit;

function bm_bp_is_current_component($component = ''){
    if( ! function_exists('bp_is_current_component') ){
        return false;
    } else {
        return bp_is_current_component( $component );
    }
}

if ( ! function_exists( 'str_ends_with' ) ) {
    function str_ends_with( $haystack, $needle ) {
        if ( '' === $haystack && '' !== $needle ) {
            return false;
        }

        $len = strlen( $needle );

        return 0 === substr_compare( $haystack, $needle, -$len, $len );
    }
}
