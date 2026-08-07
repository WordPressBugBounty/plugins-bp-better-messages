<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_GTranslate' ) ){

    class Better_Messages_GTranslate
    {
        public static function instance()
        {
            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_GTranslate();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter('better_messages_i18n_locale', array( $this, 'i18n_locale' ), 10, 1 );
        }

        public function i18n_locale( $locale )
        {
            $language = $this->get_current_language();

            if( $language === '' ){
                return $locale;
            }

            return $this->language_to_locale( $language, $locale );
        }

        public function get_current_language()
        {
            if( ! isset( $_SERVER['HTTP_X_GT_LANG'] ) || ! is_string( $_SERVER['HTTP_X_GT_LANG'] ) ){
                return '';
            }

            $language = trim( wp_unslash( $_SERVER['HTTP_X_GT_LANG'] ) );

            if( ! preg_match( '/^[a-z]{2,3}(-[a-z]{2,4})?$/i', $language ) ){
                return '';
            }

            return $language;
        }

        public function language_to_locale( $language, $fallback )
        {
            $map = apply_filters( 'better_messages_gtranslate_locales_map', array(
                'en'    => 'en_US',
                'de'    => 'de_DE',
                'fr'    => 'fr_FR',
                'nl'    => 'nl_NL',
                'es'    => 'es_ES',
                'pt'    => 'pt_BR',
                'fa'    => 'fa_IR',
                'sv'    => 'sv_SE',
                'da'    => 'da_DK',
                'no'    => 'nb_NO',
                'zh-CN' => 'zh_CN',
                'zh-TW' => 'zh_TW',
                'iw'    => 'he_IL',
                'jw'    => 'jv_ID',
            ) );

            $available   = get_available_languages();
            $available[] = 'en_US';

            if( isset( $map[ $language ] ) && in_array( $map[ $language ], $available, true ) ){
                return $map[ $language ];
            }

            if( in_array( $language, $available, true ) ){
                return $language;
            }

            $base = strtolower( strtok( $language, '-' ) );

            foreach( $available as $available_locale ){
                if( strpos( strtolower( $available_locale ), $base . '_' ) === 0 ){
                    return $available_locale;
                }
            }

            return $fallback;
        }
    }

}
