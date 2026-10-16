<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Sticker_Catalog_Client' ) ) {
    class Better_Messages_Sticker_Catalog_Client
    {
        public static function instance()
        {
            return wordplus_pickers()->stickers()->catalog();
        }
    }
}

if ( ! function_exists( 'Better_Messages_Sticker_Catalog_Client' ) ) {
    function Better_Messages_Sticker_Catalog_Client()
    {
        return Better_Messages_Sticker_Catalog_Client::instance();
    }
}
