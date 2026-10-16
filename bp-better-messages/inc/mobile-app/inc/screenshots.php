<?php
defined('ABSPATH') || exit;

if ( ! class_exists('Better_Messages_Mobile_App_Screenshots') ) {

    class Better_Messages_Mobile_App_Screenshots
    {
        const SURFACES = array( 'mobile' );

        public static function instance(): ?Better_Messages_Mobile_App_Screenshots
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Mobile_App_Screenshots();
            }

            return $instance;
        }

        public function __construct(){
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
        }

        public function rest_api_init(): void
        {
            register_rest_route('better-messages/v1/admin/app', '/screenshotManifest', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'screenshot_manifest' ),
                'permission_callback' => array( $this, 'user_is_admin' ),
            ));
        }

        public function user_is_admin(): bool
        {
            return current_user_can('manage_options');
        }

        public function screenshot_manifest( WP_REST_Request $request )
        {
            if ( ! class_exists( 'Better_Messages_Design' ) ) {
                return new WP_Error(
                    'design_unavailable',
                    __( 'The design preview is not available on this installation.', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }

            $design = Better_Messages_Design::instance();

            $manifest = $design->preview_manifest( self::SURFACES );

            if ( empty( $manifest['surfaces'] ) ) {
                return new WP_Error(
                    'no_surfaces',
                    __( 'No preview surface is available to photograph.', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }

            $manifest['threads']          = $design->preview_threads();
            $manifest['sideListMinWidth'] = $design->preview_side_list_min_width();

            return $manifest;
        }
    }
}

function Better_Messages_Mobile_App_Screenshots()
{
    return Better_Messages_Mobile_App_Screenshots::instance();
}
