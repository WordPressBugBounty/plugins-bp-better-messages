<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Gif_Provider_Factory' ) ) {
    class Better_Messages_Gif_Provider_Factory
    {
        /**
         * Create a provider instance by id.
         *
         * @param string $provider_id
         * @return Better_Messages_Gif_Provider|null
         */
        public static function create( $provider_id )
        {
            switch ( $provider_id ) {
                case 'giphy':
                    return Better_Messages_Giphy_Provider::instance();
                case 'klipy':
                    return Better_Messages_Klipy_Provider::instance();
                default:
                    return apply_filters( 'better_messages_gif_provider_create', null, $provider_id );
            }
        }

        /**
         * Returns the currently-active provider based on settings, or null if disabled/unconfigured.
         *
         * @return Better_Messages_Gif_Provider|null
         */
        public static function get_active()
        {
            $provider_id = self::get_active_provider_id();

            if ( empty( $provider_id ) || $provider_id === 'disabled' ) {
                return null;
            }

            $provider = self::create( $provider_id );

            if ( ! $provider || empty( $provider->get_api_key() ) ) {
                return null;
            }

            return $provider;
        }

        /**
         * Resolve the active provider id from settings.
         * Defaults to 'giphy' when gifsProvider is missing but a giphyApiKey exists (backward compat).
         *
         * @return string
         */
        public static function get_active_provider_id()
        {
            $settings = Better_Messages()->settings;

            if ( ! empty( $settings['gifsProvider'] ) ) {
                return $settings['gifsProvider'];
            }

            // Backward compat: existing installs with a GIPHY key keep working.
            if ( ! empty( $settings['giphyApiKey'] ) ) {
                return 'giphy';
            }

            return 'disabled';
        }

        /**
         * Info about all available providers (for admin UI).
         *
         * @return array
         */
        public static function get_providers_info()
        {
            $providers = array(
                array(
                    'id'           => 'giphy',
                    'name'         => 'GIPHY',
                    'features'     => array( 'trending', 'search', 'rating', 'language' ),
                    'hasGlobalKey' => ! empty( Better_Messages()->settings['giphyApiKey'] ),
                ),
                array(
                    'id'           => 'klipy',
                    'name'         => 'KLIPY',
                    'features'     => array( 'trending', 'search', 'language' ),
                    'hasGlobalKey' => ! empty( Better_Messages()->settings['klipyApiKey'] ),
                ),
            );

            return apply_filters( 'better_messages_gif_providers_info', $providers );
        }
    }
}
