<?php

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_AI_Provider_Factory' ) ) {
    class Better_Messages_AI_Provider_Factory
    {
        const PROVIDERS = array( 'openai', 'anthropic', 'gemini' );

        public static function create( $provider_id )
        {
            switch ( $provider_id ) {
                case 'openai':
                    return new Better_Messages_OpenAI_API();
                case 'anthropic':
                    return new Better_Messages_Anthropic_API();
                case 'gemini':
                    return new Better_Messages_Gemini_API();
                default:
                    return apply_filters( 'better_messages_ai_provider_create', null, $provider_id );
            }
        }

        public static function get_providers_info()
        {
            $providers = [
                [
                    'id'       => 'openai',
                    'name'     => 'OpenAI',
                    'features' => [
                        'images', 'files', 'imagesGeneration', 'webSearch',
                        'fileSearch', 'audio', 'moderation', 'transcription',
                        'reasoningEffort', 'serviceTier', 'temperature', 'maxOutputTokens'
                    ],
                    'hasGlobalKey' => self::has_key( 'openai' ),
                ],
                [
                    'id'       => 'anthropic',
                    'name'     => 'Anthropic',
                    'features' => [
                        'images', 'files', 'webSearch', 'temperature', 'maxOutputTokens',
                        'extendedThinking'
                    ],
                    'hasGlobalKey' => self::has_key( 'anthropic' ),
                ],
                [
                    'id'       => 'gemini',
                    'name'     => 'Google Gemini',
                    'features' => [
                        'images', 'files', 'imagesGeneration', 'webSearch',
                        'extendedThinking', 'temperature', 'maxOutputTokens'
                    ],
                    'hasGlobalKey' => self::has_key( 'gemini' ),
                ],
            ];

            return apply_filters( 'better_messages_ai_providers_info', $providers );
        }

        public static function get_global_api_key( $provider_id )
        {
            if ( in_array( $provider_id, self::PROVIDERS, true ) ) {
                $ai = Better_Messages_AI_Provider::ai();

                return $ai ? $ai->keys()->get( $provider_id ) : '';
            }

            return apply_filters( 'better_messages_ai_provider_global_key', '', $provider_id );
        }

        public static function has_key( $provider_id, $bot_id = 0 )
        {
            $ai  = Better_Messages_AI_Provider::ai();
            $has = $ai ? $ai->keys()->has( $provider_id, $bot_id > 0 ? Better_Messages_AI_Provider::owner( $bot_id ) : '' ) : false;

            if ( ! $has && ! in_array( $provider_id, self::PROVIDERS, true ) ) {
                return '' !== (string) self::get_global_api_key( $provider_id );
            }

            return $has;
        }

        public static function present_keys( $bot_id = 0 )
        {
            $ai = Better_Messages_AI_Provider::ai();

            if ( ! $ai ) {
                return array();
            }

            $owner = $bot_id > 0 ? Better_Messages_AI_Provider::owner( $bot_id ) : '';
            $keys  = array();

            foreach ( self::PROVIDERS as $provider_id ) {
                $keys[ $provider_id ] = $ai->keys()->present( $provider_id, $owner );
            }

            return $keys;
        }
    }
}
