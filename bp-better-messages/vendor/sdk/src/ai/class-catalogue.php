<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Catalogue' ) ) {
    final class WordPlus_SDK_AI_Catalogue
    {
        const SCHEMA = 1;

        const PROVIDERS = array( 'openai', 'anthropic', 'gemini' );

        const PRICES = array( 'input', 'output', 'cacheRead', 'cacheWrite', 'audioInput', 'audioOutput' );

        const THINKING = array( 'adaptive', 'both', 'budget', 'level', 'none' );

        const SAMPLING = array( 'any', 'default', 'none' );

        const LEVELS = array( 'none', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max' );

        const MAX_BYTES = 524288;
        const MAX_MODELS = 400;

        const VERSION = '(?:[a-z0-9]|\.\d|-[1-9]\d?(?![a-z0-9]))';

        private $data;

        private $source;

        private $keys = array();

        public function __construct( array $data, $source = 'shipped' )
        {
            $this->data   = $data;
            $this->source = (string) $source;
        }

        public static function from_file( $file )
        {
            if ( ! is_readable( $file ) || filesize( $file ) > self::MAX_BYTES ) {
                return null;
            }

            $data = self::validate( json_decode( (string) file_get_contents( $file ), true ) );

            return is_wp_error( $data ) ? null : new self( $data, 'shipped' );
        }

        public static function newest( WordPlus_SDK_AI_Catalogue $shipped, $fetched = null )
        {
            if ( ! $fetched instanceof self || $fetched->updated() <= $shipped->updated() ) {
                return $shipped;
            }

            $data = $fetched->data();

            foreach ( self::PROVIDERS as $provider ) {
                if ( isset( $data['providers'][ $provider ] ) ) {
                    $data['providers'][ $provider ]['tiers'] = $shipped->tiers( $provider );
                }
            }

            return new self( $data, 'fetched' );
        }

        public static function validate( $raw )
        {
            $fail = function ( $why ) {
                return new WP_Error( 'wordplus_ai_catalogue', $why );
            };

            if ( ! is_array( $raw ) || self::SCHEMA !== ( isset( $raw['schema'] ) ? (int) $raw['schema'] : 0 ) ) {
                return $fail( 'not a catalogue of schema ' . self::SCHEMA );
            }

            if ( ! isset( $raw['updated'] ) || ! self::is_day( $raw['updated'] ) ) {
                return $fail( 'no date it was updated' );
            }

            $data = array(
                'schema'      => self::SCHEMA,
                'updated'     => (string) $raw['updated'],
                'providers'   => array(),
                'tools'       => array(),
                'images'      => array(),
                'imageTokens' => array(),
            );

            foreach ( self::PROVIDERS as $provider ) {
                if ( ! isset( $raw['providers'][ $provider ] ) ) {
                    continue;
                }

                $given = $raw['providers'][ $provider ];
                if ( ! is_array( $given ) || ! isset( $given['models'] ) || ! is_array( $given['models'] ) || count( $given['models'] ) > self::MAX_MODELS ) {
                    return $fail( "$provider has no list of models" );
                }

                $models = array();
                foreach ( $given['models'] as $key => $model ) {
                    $entry = is_array( $model ) && self::is_key( $key ) ? self::entry( $model ) : null;

                    if ( null === $entry ) {
                        return $fail( "$provider: $key is not a model" );
                    }

                    $models[ strtolower( (string) $key ) ] = $entry;
                }

                $defaults = isset( $given['defaults'] ) && is_array( $given['defaults'] ) ? self::rules( $given['defaults'] ) : array();

                $data['providers'][ $provider ] = array(
                    'checked'      => isset( $given['checked'] ) && self::is_day( $given['checked'] ) ? (string) $given['checked'] : '',
                    'sources'      => self::urls( isset( $given['sources'] ) ? $given['sources'] : array() ),
                    'defaults'     => null === $defaults ? array() : $defaults,
                    'serviceTiers' => self::multipliers( isset( $given['serviceTiers'] ) ? $given['serviceTiers'] : array() ),
                    'tiers'        => self::tier_lists( isset( $given['tiers'] ) ? $given['tiers'] : array() ),
                    'models'       => $models,
                );
            }

            if ( empty( $data['providers'] ) ) {
                return $fail( 'no providers' );
            }

            foreach ( array( 'web_search', 'file_search' ) as $tool ) {
                foreach ( self::PROVIDERS as $provider ) {
                    if ( isset( $raw['tools'][ $tool ][ $provider ] ) && is_array( $raw['tools'][ $tool ][ $provider ] ) ) {
                        foreach ( $raw['tools'][ $tool ][ $provider ] as $key => $price ) {
                            if ( ( '*' === $key || self::is_key( $key ) ) && self::is_price( $price ) ) {
                                $data['tools'][ $tool ][ $provider ][ strtolower( (string) $key ) ] = (float) $price;
                            }
                        }
                    }
                }
            }

            foreach ( isset( $raw['images'] ) && is_array( $raw['images'] ) ? $raw['images'] : array() as $model => $qualities ) {
                if ( ! self::is_key( $model ) || ! is_array( $qualities ) ) {
                    continue;
                }

                foreach ( $qualities as $quality => $sizes ) {
                    if ( ! in_array( $quality, array( 'low', 'medium', 'high', 'default' ), true ) || ! is_array( $sizes ) ) {
                        continue;
                    }

                    foreach ( $sizes as $size => $price ) {
                        if ( preg_match( '/^\d{2,5}x\d{2,5}$/', (string) $size ) && self::is_price( $price ) ) {
                            $data['images'][ strtolower( (string) $model ) ][ $quality ][ (string) $size ] = (float) $price;
                        }
                    }
                }
            }

            foreach ( isset( $raw['imageTokens'] ) && is_array( $raw['imageTokens'] ) ? $raw['imageTokens'] : array() as $model => $prices ) {
                if ( self::is_key( $model ) && is_array( $prices ) ) {
                    $entry = array();
                    foreach ( array( 'textInput', 'imageInput', 'textOutput', 'imageOutput' ) as $field ) {
                        $entry[ $field ] = isset( $prices[ $field ] ) && self::is_price( $prices[ $field ] ) ? (float) $prices[ $field ] : 0.0;
                    }
                    $data['imageTokens'][ strtolower( (string) $model ) ] = $entry;
                }
            }

            return $data;
        }

        private static function entry( array $model )
        {
            $entry = self::rules( $model );
            if ( null === $entry ) {
                return null;
            }

            if ( isset( $model['label'] ) ) {
                if ( ! is_string( $model['label'] ) || strlen( $model['label'] ) > 80 ) {
                    return null;
                }
                $entry['label'] = wp_strip_all_tags( $model['label'] );
            }

            if ( isset( $model['price'] ) ) {
                $entry['price'] = self::price_set( $model['price'] );
                if ( null === $entry['price'] ) {
                    return null;
                }
            }

            if ( isset( $model['long'] ) ) {
                $long = self::price_set( $model['long'] );
                if ( null === $long || ! isset( $model['long']['over'] ) || ! is_numeric( $model['long']['over'] ) || (int) $model['long']['over'] < 1 ) {
                    return null;
                }
                $entry['long'] = array( 'over' => (int) $model['long']['over'] ) + $long;
            }

            if ( isset( $model['changes'] ) ) {
                if ( ! is_array( $model['changes'] ) ) {
                    return null;
                }

                $entry['changes'] = array();
                foreach ( $model['changes'] as $change ) {
                    $price = is_array( $change ) && isset( $change['from'], $change['price'] ) && self::is_day( $change['from'] ) ? self::price_set( $change['price'] ) : null;
                    if ( null === $price ) {
                        return null;
                    }
                    $entry['changes'][] = array( 'from' => (string) $change['from'], 'price' => $price );
                }

                usort( $entry['changes'], function ( $a, $b ) {
                    return strcmp( $a['from'], $b['from'] );
                } );
            }

            if ( isset( $model['serviceTiers'] ) ) {
                $entry['serviceTiers'] = self::multipliers( $model['serviceTiers'] );
            }

            if ( isset( $model['unlisted'] ) && self::is_day( $model['unlisted'] ) ) {
                $entry['unlisted'] = (string) $model['unlisted'];
            }

            return $entry;
        }

        private static function rules( array $given )
        {
            $rules = array();

            if ( isset( $given['thinking'] ) ) {
                if ( ! in_array( $given['thinking'], self::THINKING, true ) ) {
                    return null;
                }
                $rules['thinking'] = $given['thinking'];
            }

            if ( isset( $given['sampling'] ) ) {
                if ( ! in_array( $given['sampling'], self::SAMPLING, true ) ) {
                    return null;
                }
                $rules['sampling'] = $given['sampling'];
            }

            foreach ( array( 'effort', 'levels' ) as $field ) {
                if ( isset( $given[ $field ] ) ) {
                    if ( ! is_array( $given[ $field ] ) || array_diff( $given[ $field ], self::LEVELS ) ) {
                        return null;
                    }
                    $rules[ $field ] = array_values( array_map( 'strval', $given[ $field ] ) );
                }
            }

            if ( isset( $given['budget'] ) ) {
                if ( ! is_array( $given['budget'] ) || 2 !== count( $given['budget'] ) || ! is_numeric( $given['budget'][0] ) || ! is_numeric( $given['budget'][1] ) ) {
                    return null;
                }
                $rules['budget'] = array( max( 0, (int) $given['budget'][0] ), max( 0, (int) $given['budget'][1] ) );
            }

            return $rules;
        }

        private static function price_set( $given )
        {
            if ( ! is_array( $given ) || ! isset( $given['input'], $given['output'] ) || ! self::is_price( $given['input'] ) || ! self::is_price( $given['output'] ) ) {
                return null;
            }

            $price = array();
            foreach ( self::PRICES as $field ) {
                if ( isset( $given[ $field ] ) ) {
                    if ( ! self::is_price( $given[ $field ] ) ) {
                        return null;
                    }
                    $price[ $field ] = (float) $given[ $field ];
                }
            }

            $price += array( 'cacheRead' => $price['input'], 'cacheWrite' => $price['input'] );

            return $price;
        }

        private static function multipliers( $given )
        {
            $out = array();

            foreach ( is_array( $given ) ? $given : array() as $tier => $times ) {
                if ( preg_match( '/^[a-z]{2,20}$/', (string) $tier ) && is_numeric( $times ) && $times >= 0 && $times <= 10 ) {
                    $out[ (string) $tier ] = (float) $times;
                }
            }

            return $out;
        }

        private static function tier_lists( $given )
        {
            $out = array();

            foreach ( is_array( $given ) ? $given : array() as $tier => $models ) {
                if ( preg_match( '/^[a-z]{2,20}$/', (string) $tier ) && is_array( $models ) ) {
                    $out[ (string) $tier ] = array_values( array_filter( array_map( 'strval', $models ), array( __CLASS__, 'is_key' ) ) );
                }
            }

            return $out;
        }

        private static function urls( $given )
        {
            return array_values( array_filter( is_array( $given ) ? $given : array(), function ( $url ) {
                return is_string( $url ) && 0 === strpos( $url, 'https://' ) && strlen( $url ) < 300;
            } ) );
        }

        public static function is_key( $key )
        {
            return is_string( $key ) && 1 === preg_match( '/^[a-z0-9][a-z0-9.\-]{0,79}$/', strtolower( $key ) );
        }

        private static function is_price( $value )
        {
            return ( is_int( $value ) || is_float( $value ) ) && $value >= 0 && $value <= 100000;
        }

        private static function is_day( $value )
        {
            return is_string( $value ) && 1 === preg_match( '/^20\d\d-[01]\d-[0-3]\d$/', $value );
        }

        public function updated()
        {
            return $this->data['updated'];
        }

        public function source()
        {
            return $this->source;
        }

        public function data()
        {
            return $this->data;
        }

        public function checked( $provider )
        {
            return isset( $this->data['providers'][ $provider ]['checked'] ) ? $this->data['providers'][ $provider ]['checked'] : '';
        }

        public function key( $provider, $model, $field = null )
        {
            $model = strtolower( (string) $model );
            $cache = $provider . '|' . $model . '|' . (string) $field;

            if ( array_key_exists( $cache, $this->keys ) ) {
                return $this->keys[ $cache ];
            }

            $found = null;

            if ( '' !== $model && isset( $this->data['providers'][ $provider ]['models'] ) ) {
                foreach ( $this->data['providers'][ $provider ]['models'] as $key => $entry ) {
                    if ( ( null === $field || isset( $entry[ $field ] ) ) && ( null === $found || strlen( $key ) > strlen( $found ) ) && self::matches( $model, $key ) ) {
                        $found = $key;
                    }
                }
            }

            return $this->keys[ $cache ] = $found;
        }

        public static function contains( $model, $key )
        {
            return self::find( (string) $model, (string) $key, false );
        }

        public static function matches( $model, $key )
        {
            return self::find( strtolower( (string) $model ), (string) $key, true );
        }

        private static function find( $model, $key, $versions )
        {
            $offset = 0;

            while ( '' !== $key && false !== ( $at = strpos( $model, $key, $offset ) ) ) {
                $after = (string) substr( $model, $at + strlen( $key ) );

                if ( ( 0 === $at || false !== strpos( '/.:', $model[ $at - 1 ] ) ) && ( ! $versions || ! preg_match( '/^' . self::VERSION . '/', $after ) ) ) {
                    return true;
                }
                $offset = $at + 1;
            }

            return false;
        }

        public function model( $provider, $model )
        {
            $defaults = isset( $this->data['providers'][ $provider ]['defaults'] ) ? $this->data['providers'][ $provider ]['defaults'] : array();
            $rules    = array_merge( array( 'thinking' => 'none', 'sampling' => 'any', 'effort' => array(), 'levels' => array(), 'budget' => array() ), $defaults );

            foreach ( array( 'thinking', 'sampling', 'effort', 'levels', 'budget' ) as $field ) {
                $key = $this->key( $provider, $model, $field );
                if ( null !== $key ) {
                    $rules[ $field ] = $this->data['providers'][ $provider ]['models'][ $key ][ $field ];
                }
            }

            $rules['key']   = $this->key( $provider, $model, 'price' );
            $rules['known'] = null !== $this->key( $provider, $model );
            $rules['label'] = $this->label( $provider, $model );

            return $rules;
        }

        public function price( $provider, $model, $day = null )
        {
            $key = $this->key( $provider, $model, 'price' );

            if ( null === $key ) {
                return null;
            }

            $entry = $this->data['providers'][ $provider ]['models'][ $key ];
            $price = $entry['price'];
            $day   = null === $day ? gmdate( 'Y-m-d' ) : (string) $day;

            foreach ( isset( $entry['changes'] ) ? $entry['changes'] : array() as $change ) {
                if ( $change['from'] <= $day ) {
                    $price = $change['price'];
                }
            }

            $long = $this->key( $provider, $model, 'long' );
            if ( null !== $long && strlen( $long ) >= strlen( $key ) ) {
                $price['long'] = $this->data['providers'][ $provider ]['models'][ $long ]['long'];
            }

            return $price;
        }

        public function service_tier( $provider, $model, $tier )
        {
            $tier = (string) $tier;
            $key  = $this->key( $provider, $model, 'serviceTiers' );

            if ( null !== $key && isset( $this->data['providers'][ $provider ]['models'][ $key ]['serviceTiers'][ $tier ] ) ) {
                return $this->data['providers'][ $provider ]['models'][ $key ]['serviceTiers'][ $tier ];
            }

            return isset( $this->data['providers'][ $provider ]['serviceTiers'][ $tier ] ) ? $this->data['providers'][ $provider ]['serviceTiers'][ $tier ] : 1.0;
        }

        public function tool_price( $tool, $provider, $model )
        {
            $prices = isset( $this->data['tools'][ $tool ][ $provider ] ) ? $this->data['tools'][ $tool ][ $provider ] : array();
            $model  = strtolower( (string) $model );
            $found  = null;

            foreach ( $prices as $key => $price ) {
                if ( '*' !== $key && ( null === $found || strlen( $key ) > strlen( $found ) ) && self::contains( $model, $key ) ) {
                    $found = $key;
                }
            }

            if ( null !== $found ) {
                return $prices[ $found ];
            }

            return isset( $prices['*'] ) ? $prices['*'] : 0.0;
        }

        public function images()
        {
            return $this->data['images'];
        }

        public function image_tokens()
        {
            return $this->data['imageTokens'];
        }

        public function tiers( $provider )
        {
            return isset( $this->data['providers'][ $provider ]['tiers'] ) ? $this->data['providers'][ $provider ]['tiers'] : array();
        }

        public function label( $provider, $model )
        {
            $key = $this->key( $provider, $model, 'label' );

            if ( null !== $key && $key === $this->key( $provider, $model ) ) {
                return $this->data['providers'][ $provider ]['models'][ $key ]['label'];
            }

            if ( 'anthropic' === $provider && preg_match( '/^claude-([a-z]+)-(\d+)(?:-(\d{1,2}))?(?:-|$)/', (string) $model, $m ) ) {
                return 'Claude ' . ucfirst( $m[1] ) . ' ' . $m[2] . ( isset( $m[3] ) && '' !== $m[3] ? '.' . $m[3] : '' );
            }

            return (string) $model;
        }

        public function pricing_rules( $day = null )
        {
            $out = array();

            foreach ( self::PROVIDERS as $provider ) {
                $keys = array();
                foreach ( isset( $this->data['providers'][ $provider ]['models'] ) ? $this->data['providers'][ $provider ]['models'] : array() as $key => $entry ) {
                    if ( isset( $entry['price'] ) ) {
                        $keys[] = $key;
                    }
                }

                usort( $keys, function ( $a, $b ) {
                    return strlen( $b ) - strlen( $a ) ?: strcmp( $a, $b );
                } );

                $out[ $provider ] = array();
                foreach ( $keys as $key ) {
                    $price = $this->price( $provider, $key, $day );
                    unset( $price['long'] );
                    $out[ $provider ][] = array( '(?:^|[/.:])' . str_replace( '.', '\\.', $key ) . '(?!' . self::VERSION . ')', $price );
                }
            }

            return $out;
        }

        public function tool_pricing()
        {
            return array(
                'web_search'       => $this->tool_price( 'web_search', 'openai', '' ),
                'file_search'      => $this->tool_price( 'file_search', 'openai', '' ),
                'gemini_search_2x' => $this->tool_price( 'web_search', 'gemini', 'gemini-2' ),
                'gemini_search_3x' => $this->tool_price( 'web_search', 'gemini', 'gemini-3' ),
            );
        }

        public function export( $day = null )
        {
            return array(
                'updated'      => $this->updated(),
                'source'       => $this->source(),
                'modelPricing' => $this->pricing_rules( $day ),
                'imagePricing' => $this->images(),
                'toolPricing'  => $this->tool_pricing(),
            );
        }
    }
}
