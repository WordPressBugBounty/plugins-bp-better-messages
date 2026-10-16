<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Stickers_Admin' ) ) {
    final class WordPlus_SDK_Stickers_Admin
    {
        const SCRIPT = 'wordplus-stickers-admin';

        private $stickers;

        private $sdk;

        public function __construct( WordPlus_SDK_Stickers $stickers, WordPlus_SDK $sdk )
        {
            $this->stickers = $stickers;
            $this->sdk      = $sdk;
        }

        public function enqueue()
        {
            require_once $this->sdk->path( 'src/class-ui.php' );
            WordPlus_SDK_UI::enqueue();

            wp_register_script( self::SCRIPT, $this->sdk->url( 'stickers/stickers-admin.min.js' ), $this->sdk->i18n()->deps( self::SCRIPT, array( WordPlus_SDK_UI::UI, 'wp-element', 'wp-i18n', 'wp-api-fetch' ) ), WordPlus_SDK_UI::asset_version( 'stickers/stickers-admin.min.js' ), true );
            wp_register_style( self::SCRIPT, $this->sdk->url( 'stickers/stickers-admin.min.css' ), array( WordPlus_SDK_UI::UI ), WordPlus_SDK_UI::asset_version( 'stickers/stickers-admin.min.css' ) );

            wp_enqueue_script( self::SCRIPT );
            wp_enqueue_style( self::SCRIPT );
            wp_add_inline_script( self::SCRIPT, 'window.wordplusStickersData = ' . wp_json_encode( $this->page_data() ) . ';', 'before' );
        }

        public function page_data()
        {
            $catalog = $this->stickers->catalog();
            $base    = $catalog->get_catalog_cdn_base_url();
            $origin  = $catalog->get_catalog_base_url();

            return array(
                'catalogBase'     => $base,
                'catalogFallback' => $base === $origin ? '' : $origin,
                'locales'         => $this->locales(),
                'roles'           => $this->roles(),
            );
        }

        public function locales()
        {
            $locales = array( (string) get_locale() );

            if ( function_exists( 'get_available_languages' ) ) {
                foreach ( (array) get_available_languages() as $locale ) {
                    $locales[] = (string) $locale;
                }
            }

            return array_values( array_unique( array_filter( $locales ) ) );
        }

        public function roles()
        {
            $roles = array();

            if ( function_exists( 'wp_roles' ) ) {
                foreach ( (array) wp_roles()->get_names() as $key => $name ) {
                    $roles[] = array( 'key' => (string) $key, 'name' => translate_user_role( (string) $name ) );
                }
            }

            $roles = (array) apply_filters( 'wordplus_stickers_roles', $roles );

            return array_values( array_filter( $roles, fn ( $role ) => is_array( $role ) && isset( $role['key'], $role['name'] ) ) );
        }
    }
}
