<?php
// Legacy shim — loads the multi-provider GIF system.
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/gifs/gifs.php';

if ( ! function_exists( 'Better_Messages_Giphy' ) ) {
    function Better_Messages_Giphy()
    {
        return Better_Messages_Giphy_Provider::instance();
    }
}
