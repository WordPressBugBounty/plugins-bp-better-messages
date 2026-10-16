<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Sticker_Pack_Manager' ) ) {
    class Better_Messages_Sticker_Pack_Manager
    {
        const ROOT = 'better-messages/stickers/packs';

        const OPTION_KEY  = 'better_messages_sticker_packs';
        const HASH_KEY    = 'stickerPacksHash';
        const SUMMARY_KEY = 'stickerPacksSummary';

        public static function instance()
        {
            return wordplus_pickers()->stickers()->packs();
        }

        public static function rewrite_url( $url )
        {
            return wordplus_pickers()->stickers()->rewrite_url( $url );
        }
    }
}

if ( ! function_exists( 'Better_Messages_Sticker_Pack_Manager' ) ) {
    function Better_Messages_Sticker_Pack_Manager()
    {
        return Better_Messages_Sticker_Pack_Manager::instance();
    }
}
