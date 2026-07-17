<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Weglot' ) ){

    class Better_Messages_Weglot
    {
        public static function instance()
        {
            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Weglot();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter('better_messages_i18n_locale', array( $this, 'i18n_locale' ), 10, 1 );
        }

        public function i18n_locale( $locale )
        {
            if( ! function_exists('weglot_get_current_language') || ! function_exists('weglot_get_original_language') ){
                return $locale;
            }

            try {
                $language = weglot_get_current_language();
                $original = weglot_get_original_language();
            } catch ( Exception $exception ) {
                return $locale;
            }

            if( ! is_string( $language ) || $language === '' || $language === $original ){
                return $locale;
            }

            return $this->language_to_locale( $language, $locale );
        }

        public function language_to_locale( $language, $fallback )
        {
            $map = apply_filters( 'better_messages_weglot_locales_map', array(
                'en' => 'en_US',
                'br' => 'pt_BR',
                'zh' => 'zh_CN',
                'tw' => 'zh_TW',
                'no' => 'nb_NO',
            ) );

            if( isset( $map[ $language ] ) ){
                return $map[ $language ];
            }

            $available = get_available_languages();

            if( in_array( $language, $available, true ) ){
                return $language;
            }

            foreach( $available as $available_locale ){
                if( strpos( $available_locale, $language . '_' ) === 0 ){
                    return $available_locale;
                }
            }

            return $fallback;
        }
    }

}
