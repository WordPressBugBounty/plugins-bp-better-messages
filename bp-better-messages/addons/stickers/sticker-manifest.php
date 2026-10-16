<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Sticker_Manifest' ) ) {
    class Better_Messages_Sticker_Manifest
    {
        public static function instance()
        {
            return wordplus_pickers()->stickers()->manifest();
        }
    }
}

if ( ! function_exists( 'Better_Messages_Sticker_Manifest' ) ) {
    function Better_Messages_Sticker_Manifest()
    {
        return Better_Messages_Sticker_Manifest::instance();
    }
}
