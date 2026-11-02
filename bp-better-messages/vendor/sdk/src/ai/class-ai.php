<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI' ) ) {
    require_once __DIR__ . '/class-catalogue.php';
    require_once __DIR__ . '/class-cost.php';
    require_once __DIR__ . '/class-keys.php';
    require_once __DIR__ . '/class-http.php';
    require_once __DIR__ . '/class-prices.php';

    final class WordPlus_SDK_AI
    {
        const API = 1;

        const PROVIDERS = array(
            'openai'    => 'WordPlus_SDK_AI_OpenAI',
            'anthropic' => 'WordPlus_SDK_AI_Anthropic',
            'gemini'    => 'WordPlus_SDK_AI_Gemini',
        );

        private $sdk;

        private $shipped = null;

        private $catalogue = null;

        private $keys = null;

        private $prices = null;

        private $libraries = false;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function api()
        {
            return self::API;
        }

        public function libraries()
        {
            if ( ! $this->libraries ) {
                require_once $this->sdk->path( 'ai/dependencies/autoload.php' );
                require_once __DIR__ . '/class-provider.php';
                require_once __DIR__ . '/class-openai.php';
                require_once __DIR__ . '/class-anthropic.php';
                require_once __DIR__ . '/class-gemini.php';
                require_once __DIR__ . '/class-stream.php';
                $this->libraries = true;
            }
        }

        public function providers()
        {
            $this->libraries();
            $list = array();

            foreach ( self::PROVIDERS as $id => $class ) {
                $provider = new $class( $this, '' );
                $list[]   = array( 'id' => $id, 'name' => $provider->name(), 'features' => $provider->features(), 'hasKey' => $this->keys()->has( $id ) );
            }

            return (array) apply_filters( 'wordplus_ai_providers', $list );
        }

        public function provider( $id, $key = null, $owner = '' )
        {
            $this->libraries();
            $key = null === $key ? $this->keys()->get( (string) $id, (string) $owner ) : (string) $key;

            if ( isset( self::PROVIDERS[ $id ] ) ) {
                $class = self::PROVIDERS[ $id ];

                return new $class( $this, $key );
            }

            $other = apply_filters( 'wordplus_ai_provider', null, (string) $id, $key, $this );

            return $other instanceof WordPlus_SDK_AI_Provider ? $other : null;
        }

        public function shipped()
        {
            if ( null === $this->shipped ) {
                $this->shipped = WordPlus_SDK_AI_Catalogue::from_file( $this->sdk->path( 'ai/catalogue.json' ) );

                if ( null === $this->shipped ) {
                    $this->shipped = new WordPlus_SDK_AI_Catalogue( array( 'schema' => 1, 'updated' => '2000-01-01', 'providers' => array(), 'tools' => array(), 'images' => array(), 'imageTokens' => array() ) );
                }
            }

            return $this->shipped;
        }

        public function catalogue()
        {
            if ( null === $this->catalogue ) {
                $this->catalogue = WordPlus_SDK_AI_Catalogue::newest( $this->shipped(), $this->prices()->fetched() );
            }

            return $this->catalogue;
        }

        public function forget_catalogue()
        {
            $this->catalogue = null;
        }

        public function prices()
        {
            if ( null === $this->prices ) {
                $this->prices = new WordPlus_SDK_AI_Prices( $this );
            }

            return $this->prices;
        }

        public function cost( $meta, array $options = array() )
        {
            return ( new WordPlus_SDK_AI_Cost( $this->catalogue() ) )->cost( $meta, $options );
        }

        public function usage( $usage, $provider = '' )
        {
            return WordPlus_SDK_AI_Cost::usage( $usage, $provider );
        }

        public function totals( $costs )
        {
            return WordPlus_SDK_AI_Cost::totals( $costs );
        }

        public function costs()
        {
            return new WordPlus_SDK_AI_Cost( $this->catalogue() );
        }

        public function keys()
        {
            if ( null === $this->keys ) {
                $this->keys = new WordPlus_SDK_AI_Keys();
            }

            return $this->keys;
        }

        public function stream( $answer, array $options = array() )
        {
            $this->libraries();

            return WordPlus_SDK_AI_Stream::pump( $answer, $options );
        }

        public function friendly( $message, $kind = '' )
        {
            return WordPlus_SDK_AI_Http::friendly( $message, $kind );
        }

        public function transient( $message )
        {
            return WordPlus_SDK_AI_Http::transient( $message );
        }

        public function retryable( $kind )
        {
            return WordPlus_SDK_AI_Http::retryable( $kind );
        }

        public function http( $base, array $headers = array(), $id = '' )
        {
            $this->libraries();

            return WordPlus_SDK_AI_Http::client( $base, $headers, $id );
        }
    }
}
