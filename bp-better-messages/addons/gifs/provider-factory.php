<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( function_exists( 'wordplus_pickers' ) && wordplus_pickers() ) {
    if ( ! class_exists( 'Better_Messages_Gif_Provider' ) && class_exists( 'WordPlus_SDK_Gif_Provider' ) ) {
        class_alias( 'WordPlus_SDK_Gif_Provider', 'Better_Messages_Gif_Provider' );
    }

    add_filter( 'wordplus_pickers_gif_provider', function ( $provider, $provider_id ) {
        return apply_filters( 'better_messages_gif_provider_create', $provider, $provider_id );
    }, 10, 2 );

    add_filter( 'wordplus_pickers_gif_providers_info', function ( $providers ) {
        return apply_filters( 'better_messages_gif_providers_info', $providers );
    } );
}

if ( ! class_exists( 'Better_Messages_Gif_Provider_Factory' ) ) {
    class Better_Messages_Gif_Provider_Factory
    {
        public static function create( $provider_id )
        {
            return function_exists( 'wordplus_pickers' ) && wordplus_pickers() ? wordplus_pickers()->gif_provider( $provider_id ) : null;
        }

        public static function get_active()
        {
            return function_exists( 'wordplus_pickers' ) && wordplus_pickers() ? wordplus_pickers()->active_gif_provider() : null;
        }

        public static function get_active_provider_id()
        {
            return function_exists( 'wordplus_pickers' ) && wordplus_pickers() ? wordplus_pickers()->active_gif_provider_id() : 'disabled';
        }

        public static function get_providers_info()
        {
            return function_exists( 'wordplus_pickers' ) && wordplus_pickers() ? wordplus_pickers()->gif_providers_info() : array();
        }
    }
}
