<?php

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_AI_Provider_Factory' ) ) {
    class Better_Messages_AI_Provider_Factory
    {
        /**
         * Create a provider instance
         *
         * @param string $provider_id Provider identifier ('openai', 'anthropic', 'gemini')
         * @return Better_Messages_AI_Provider|null
         */
        public static function create( $provider_id )
        {
            switch ( $provider_id ) {
                case 'openai':
                    return Better_Messages_OpenAI_API::instance();
                case 'anthropic':
                    return Better_Messages_Anthropic_API::instance();
                case 'gemini':
                    return Better_Messages_Gemini_API::instance();
                default:
                    return apply_filters( 'better_messages_ai_provider_create', null, $provider_id );
            }
        }

        /**
         * Get info about all available providers (for admin UI)
         *
         * @return array
         */
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
                    'hasGlobalKey' => ! empty( Better_Messages()->settings['openAiApiKey'] ),
                ],
                [
                    'id'       => 'anthropic',
                    'name'     => 'Anthropic',
                    'features' => [
                        'images', 'files', 'webSearch', 'temperature', 'maxOutputTokens',
                        'extendedThinking'
                    ],
                    'hasGlobalKey' => ! empty( Better_Messages()->settings['anthropicApiKey'] ),
                ],
                [
                    'id'       => 'gemini',
                    'name'     => 'Google Gemini',
                    'features' => [
                        'images', 'files', 'imagesGeneration', 'webSearch',
                        'extendedThinking', 'temperature', 'maxOutputTokens'
                    ],
                    'hasGlobalKey' => ! empty( Better_Messages()->settings['geminiApiKey'] ),
                ],
            ];

            return apply_filters( 'better_messages_ai_providers_info', $providers );
        }

        /**
         * Get global API key for a provider
         *
         * @param string $provider_id
         * @return string
         */
        public static function get_global_api_key( $provider_id )
        {
            switch ( $provider_id ) {
                case 'openai':
                    return Better_Messages()->settings['openAiApiKey'] ?? '';
                case 'anthropic':
                    return Better_Messages()->settings['anthropicApiKey'] ?? '';
                case 'gemini':
                    return Better_Messages()->settings['geminiApiKey'] ?? '';
                default:
                    return apply_filters( 'better_messages_ai_provider_global_key', '', $provider_id );
            }
        }
    }
}
