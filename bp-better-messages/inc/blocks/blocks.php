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
        add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_block_editor_assets' ) );
        add_action( 'init', array( $this, 'register_blocks' ) );
        add_filter( 'block_categories_all', array( $this, 'register_block_category' ), 10, 2 );
    }

    public function enqueue_block_editor_assets()
    {
        Better_Messages()->enqueue_css();
    }

    public function register_blocks()
    {
        register_block_type(__DIR__ . '/builds/user-inbox');
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
