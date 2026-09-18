<?php
defined('ABSPATH') || exit;

if ( ! class_exists('Better_Messages_Mobile_App_Screenshots') ) {

    /**
     * Store screenshots for the Mobile App builder.
     *
     * Apple and Google both refuse a listing until it carries screenshots at
     * their exact pixel sizes, and the app being submitted is the one thing a
     * site owner cannot screenshot before it is built. So the messenger is
     * photographed instead of the app: the Design tab's preview already boots
     * the real messenger, at a real mobile layout, filled with the fixture
     * conversations rather than anything from the live site — which is both
     * what the app shows and the only content safe to publish on a store page.
     *
     * The capture itself happens in the browser (see
     * src/admin/mobile-app/screenshots/). All this side does is hand the tab
     * the same preview documents the Design tab uses, and only the surface it
     * photographs. Building one prints the messenger's entire script and style
     * chain, so the others are not built here, and this one is not built until
     * the tab is actually opened.
     */
    class Better_Messages_Mobile_App_Screenshots
    {
        /**
         * The only surface photographed, tablets included.
         *
         * The app mounts the messenger with `forceMobile`, which makes it use
         * the single-pane mobile arrangement on every device it runs on — so
         * an iPad screenshot taken from the `desktop` document would show the
         * two-pane web layout the app never renders.
         */
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

        /**
         * The preview documents the capture frames are booted from.
         *
         * Same shape the Design tab receives, so the frame-building code is
         * shared: { surfaces: { <name>: { head, body, bodyClass } }, lang,
         * dir, charset }.
         */
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

            // The fixture thread ids the scenes route to. They are constants
            // on the design class rather than anything this site stores, but
            // reading them from here keeps the two sides from drifting.
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
