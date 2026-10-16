<?php
if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/gifs/gifs.php';

if ( ! function_exists( 'Better_Messages_Giphy' ) ) {
    function Better_Messages_Giphy()
    {
        return Better_Messages_Gif_Provider_Factory::create( 'giphy' );
    }
}
