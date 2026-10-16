<?php
defined('ABSPATH') || exit;
if ( ! class_exists('Better_Messages_Blocks') ):
class Better_Messages_Blocks {

    public static function instance()
    {
        static $instance = null;

        if (null === $instance) {
            $instance = new Better_Messages_Blocks();
        }

        return $instance;
    }


    public function __construct()
    {
        add_action( 'enqueue_block_assets', array( $this, 'enqueue_block_editor_assets' ) );
        add_action( 'init', array( $this, 'register_blocks' ) );
        add_filter( 'block_categories_all', array( $this, 'register_block_category' ), 10, 2 );
    }

    public function enqueue_block_editor_assets()
    {
        if ( is_admin() ) {
            Better_Messages()->enqueue_css();
        }
    }

    public function register_blocks()
    {
        $this->register_jsx_runtime();

        register_block_type(__DIR__ . '/builds/user-inbox', $this->block_args( 'user-inbox' ));
        register_block_type(__DIR__ . '/builds/chat-room', $this->block_args( 'chat-room' ));
    }

    public function register_jsx_runtime()
    {
        if ( wp_script_is( 'react-jsx-runtime', 'registered' ) ) {
            return;
        }

        wp_register_script( 'react-jsx-runtime', false, array( 'wp-element' ) );
        wp_add_inline_script( 'react-jsx-runtime', 'window.ReactJSXRuntime=window.ReactJSXRuntime||(function(e){var h=Object.prototype.hasOwnProperty;function j(t,p,k){var o={},n;for(n in p){if(h.call(p,n)){o[n]=p[n];}}if(k!==undefined&&o.key===undefined){o.key=k;}return e.createElement(t,o);}return{jsx:j,jsxs:j,Fragment:e.Fragment};})(window.wp.element);' );
    }

    private function block_args( $name )
    {
        if ( version_compare( get_bloginfo( 'version' ), '6.1', '>=' ) ) {
            return array();
        }

        $template = __DIR__ . '/builds/' . $name . '/render.php';

        return array(
            'render_callback' => static function( $attributes, $content, $block ) use ( $template ) {
                ob_start();
                require $template;
                return ob_get_clean();
            },
        );
    }

    public function register_block_category( $block_categories, $block_editor_context )
    {
        $block_categories[] = [
            'slug'  => 'better-messages',
            'title' => __( 'Better Messages', 'bp-better-messages' ),
            'icon'  => 'format-chat',
        ];

        return $block_categories;
    }

}


function Better_Messages_Blocks(){
    return Better_Messages_Blocks::instance();
}

endif;
