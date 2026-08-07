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
            add_filter( 'user_has_cap', array( $this, 'grant_capabilities_to_administrators' ) );
            add_filter( 'woocommerce_prevent_admin_access', array( $this, 'allow_delegated_users_admin_access' ) );
        }

        public function grant_capabilities_to_administrators( $allcaps )
        {
            if( ! empty( $allcaps['manage_options'] ) ){
                $allcaps['bm_can_administrate'] = true;
                $allcaps['bm_can_bulk_message'] = true;
            }

            return $allcaps;
        }

        public function allow_delegated_users_admin_access( $prevent_access )
        {
            if( $prevent_access && ! current_user_can('manage_options') ){
                if( current_user_can('bm_can_bulk_message') || current_user_can('bm_can_administrate') ){
                    return false;
                }
            }

            return $prevent_access;
        }

        public function register_capabilities()
        {
            $role_obj = get_role('administrator');

            if ( $role_obj ) {
                if( ! $role_obj->has_cap('bm_can_administrate') ) {
                    $role_obj->add_cap('bm_can_administrate');
                }

                if( ! $role_obj->has_cap('bm_can_bulk_message') ) {
                    $role_obj->add_cap('bm_can_bulk_message');
                }
            }

            $this->sync_role_capability( 'bulkMessagingRoles', 'bm_can_bulk_message' );
            $this->sync_role_capability( 'administrationRoles', 'bm_can_administrate' );
        }

        public function sync_bulk_messaging_roles()
        {
            $this->sync_role_capability( 'bulkMessagingRoles', 'bm_can_bulk_message' );
        }

        public function sync_administration_roles()
        {
            $this->sync_role_capability( 'administrationRoles', 'bm_can_administrate' );
        }

        private function sync_role_capability( $setting_key, $capability )
        {
            $allowed_roles = Better_Messages()->settings[ $setting_key ] ?? array();

            if( ! is_array( $allowed_roles ) ) {
                $allowed_roles = array();
            }

            foreach ( wp_roles()->roles as $role_key => $role_data ) {
                if( $role_key === 'administrator' ) continue;

                $role_obj = get_role( $role_key );
                if( ! $role_obj ) continue;

                if( in_array( $role_key, $allowed_roles, true ) ){
                    if( ! $role_obj->has_cap( $capability ) ){
                        $role_obj->add_cap( $capability );
                    }
                } else {
                    if( $role_obj->has_cap( $capability ) ){
                        $role_obj->remove_cap( $capability );
                    }
                }
            }
        }
    }

endif;

function Better_Messages_Capabilities()
{
    return Better_Messages_Capabilities::instance();
}
