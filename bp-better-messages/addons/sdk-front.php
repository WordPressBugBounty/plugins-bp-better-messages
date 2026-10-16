<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! function_exists( 'better_messages_sdk_front_handle' ) ) {
    function better_messages_sdk_front_handle( $part ) {
        $accessor = 'wordplus_' . $part;
        $sdk      = function_exists( $accessor ) ? $accessor() : null;

        if ( $sdk ) {
            return $sdk->script();
        }

        $handle = 'wordplus-' . $part;

        if ( ! wp_script_is( $handle, 'registered' ) ) {
            $build = 'vendor/sdk/' . $part . '/' . $part;
            $types = 'menus' === $part ? array( 'js' ) : array( 'js', 'css' );

            foreach ( $types as $type ) {
                $file    = $build . '.min.' . $type;
                $version = Better_Messages()->version . ( file_exists( Better_Messages()->path . $file ) ? '.' . filemtime( Better_Messages()->path . $file ) : '' );

                if ( 'css' === $type ) {
                    wp_register_style( $handle, Better_Messages()->url . $file, array(), $version );
                } elseif ( 'toasts' === $part ) {
                    wp_register_script( $handle, Better_Messages()->url . $file, array( 'wp-i18n' ), $version, false );
                } else {
                    wp_register_script( $handle, Better_Messages()->url . $file, array(), $version, false );
                }
            }
        }

        return $handle;
    }
}

add_filter( 'better_messages_script_dependencies', function ( $dependencies ) {
    $dependencies[] = better_messages_sdk_front_handle( 'toasts' );
    $dependencies[] = better_messages_sdk_front_handle( 'tooltips' );
    $dependencies[] = better_messages_sdk_front_handle( 'menus' );

    return $dependencies;
} );

add_filter( 'better_messages_style_dependencies', function ( $dependencies ) {
    $dependencies[] = better_messages_sdk_front_handle( 'toasts' );
    $dependencies[] = better_messages_sdk_front_handle( 'tooltips' );

    return $dependencies;
} );
