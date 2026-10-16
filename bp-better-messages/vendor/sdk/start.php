<?php
defined( 'ABSPATH' ) || exit;

if ( ! isset( $GLOBALS['wordplus_sdk_copies'] ) || ! is_array( $GLOBALS['wordplus_sdk_copies'] ) ) {
    $GLOBALS['wordplus_sdk_copies'] = array();
}

$GLOBALS['wordplus_sdk_copies'][ __DIR__ ] = '1.0.0';

if ( is_readable( __DIR__ . '/realtime/realtime.php' ) ) {
    require_once __DIR__ . '/realtime/realtime.php';
}

if ( is_readable( __DIR__ . '/realtime/channels/realtime.php' ) ) {
    require_once __DIR__ . '/realtime/channels/realtime.php';
}

if ( ! function_exists( 'wordplus_sdk_register' ) ) {
    function wordplus_sdk_register( array $product ) {
        if ( empty( $product['slug'] ) ) {
            return;
        }

        if ( ! isset( $GLOBALS['wordplus_sdk_products'] ) || ! is_array( $GLOBALS['wordplus_sdk_products'] ) ) {
            $GLOBALS['wordplus_sdk_products'] = array();
        }

        $GLOBALS['wordplus_sdk_products'][ (string) $product['slug'] ] = $product;

        if ( class_exists( 'WordPlus_SDK' ) ) {
            WordPlus_SDK::instance()->register( $product );
        } elseif ( did_action( 'plugins_loaded' ) && function_exists( 'wordplus_sdk_boot' ) && ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
            wordplus_sdk_boot();
        }
    }

    function wordplus_sdk( $slug ) {
        return class_exists( 'WordPlus_SDK' ) ? WordPlus_SDK::instance()->product( (string) $slug ) : null;
    }

    function wordplus_sdk_ui_enqueue() {
        if ( class_exists( 'WordPlus_SDK_UI' ) ) {
            WordPlus_SDK_UI::enqueue();
        }
    }

    function wordplus_sdk_newest_copy( array $copies ) {
        $newest  = null;
        $version = '0';

        foreach ( $copies as $path => $copy_version ) {
            if ( version_compare( (string) $copy_version, $version, '>' ) && is_readable( $path . '/src/loader.php' ) ) {
                $newest  = (string) $path;
                $version = (string) $copy_version;
            }
        }

        return null === $newest ? null : array( $newest, $version );
    }

    function wordplus_sdk_boot() {
        if ( class_exists( 'WordPlus_SDK' ) ) {
            return;
        }

        $newest = wordplus_sdk_newest_copy( (array) $GLOBALS['wordplus_sdk_copies'] );

        if ( null === $newest ) {
            return;
        }

        require_once $newest[0] . '/src/loader.php';

        WordPlus_SDK::boot( $newest[0], $newest[1], isset( $GLOBALS['wordplus_sdk_products'] ) ? (array) $GLOBALS['wordplus_sdk_products'] : array() );
    }

    add_action( 'plugins_loaded', 'wordplus_sdk_boot', PHP_INT_MIN );
}

if ( ! function_exists( 'wordplus_push' ) ) {
    function wordplus_push() {
        if ( ! class_exists( 'WordPlus_SDK' ) || ! method_exists( 'WordPlus_SDK', 'push' ) || '' === WordPlus_SDK::instance()->path() ) {
            return null;
        }

        return WordPlus_SDK::instance()->push();
    }
}

if ( ! function_exists( 'wordplus_pickers' ) ) {
    function wordplus_pickers() {
        if ( ! class_exists( 'WordPlus_SDK' ) || ! method_exists( 'WordPlus_SDK', 'pickers' ) || '' === WordPlus_SDK::instance()->path() ) {
            return null;
        }

        return WordPlus_SDK::instance()->pickers();
    }
}

if ( ! function_exists( 'wordplus_storage' ) ) {
    function wordplus_storage() {
        if ( ! class_exists( 'WordPlus_SDK' ) || ! method_exists( 'WordPlus_SDK', 'storage' ) || '' === WordPlus_SDK::instance()->path() ) {
            return null;
        }

        return WordPlus_SDK::instance()->storage();
    }
}

if ( ! function_exists( 'wordplus_toasts' ) ) {
    function wordplus_toasts() {
        if ( ! class_exists( 'WordPlus_SDK' ) || ! method_exists( 'WordPlus_SDK', 'toasts' ) || '' === WordPlus_SDK::instance()->path() ) {
            return null;
        }

        return WordPlus_SDK::instance()->toasts();
    }
}

if ( ! function_exists( 'wordplus_tooltips' ) ) {
    function wordplus_tooltips() {
        if ( ! class_exists( 'WordPlus_SDK' ) || ! method_exists( 'WordPlus_SDK', 'tooltips' ) || '' === WordPlus_SDK::instance()->path() ) {
            return null;
        }

        return WordPlus_SDK::instance()->tooltips();
    }
}

if ( ! function_exists( 'wordplus_emoji_guard' ) ) {
    function wordplus_emoji_guard() {
        if ( ! class_exists( 'WordPlus_SDK' ) || ! method_exists( 'WordPlus_SDK', 'emoji_guard' ) || '' === WordPlus_SDK::instance()->path() ) {
            return null;
        }

        return WordPlus_SDK::instance()->emoji_guard();
    }
}

if ( ! function_exists( 'wordplus_menus' ) ) {
    function wordplus_menus() {
        if ( ! class_exists( 'WordPlus_SDK' ) || ! method_exists( 'WordPlus_SDK', 'menus' ) || '' === WordPlus_SDK::instance()->path() ) {
            return null;
        }

        return WordPlus_SDK::instance()->menus();
    }
}

if ( ! function_exists( 'wordplus_lightbox' ) ) {
    function wordplus_lightbox() {
        if ( ! class_exists( 'WordPlus_SDK' ) || ! method_exists( 'WordPlus_SDK', 'lightbox' ) || '' === WordPlus_SDK::instance()->path() ) {
            return null;
        }

        return WordPlus_SDK::instance()->lightbox();
    }
}
