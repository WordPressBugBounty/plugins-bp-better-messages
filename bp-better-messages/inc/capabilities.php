<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Capabilities' ) ):

    class Better_Messages_Capabilities
    {   public static function instance()
        {

            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Capabilities();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'bp_better_chat_settings_updated', array( $this, 'register_capabilities' ) );
        }

        public function register_capabilities()
        {
            $role_obj = get_role('administrator');

            if ( $role_obj ) {
                if( ! $role_obj->has_cap('bm_can_administrate') ) {
                    $role_obj->add_cap('bm_can_administrate');
                }
            }
        }
    }

endif;

function Better_Messages_Capabilities()
{
    return Better_Messages_Capabilities::instance();
}
