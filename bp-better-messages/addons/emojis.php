<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists('Better_Messages_Emojis') ):

    class Better_Messages_Emojis
    {

        public $emoji_sets = [
            'apple'    => 'Apple Emojis',
            'facebook' => 'Facebook Emojis',
            'google'   => 'Google Emojis',
            'twitter'  => 'Twitter Emojis',
        ];

        public $set;

        public static function instance()
        {

            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Emojis();
            }

            return $instance;
        }


        public function __construct()
        {
            $this->set = Better_Messages()->settings['emojiSet'];

            add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
            add_filter( 'bp_better_messages_script_variables', array( $this, 'script_variables' ) );

            add_filter( 'wordplus_pickers_emoji_dataset', function ( $dataset, $set, $locale ) {
                return apply_filters( 'better_messages_get_emoji_dataset', $dataset, $set, $locale );
            }, 10, 3 );

            add_filter( 'wordplus_pickers_localize_emoji', function ( $emoji, $entry, $locale ) {
                return apply_filters( 'better_messages_localize_emoji', $emoji, $entry, $locale );
            }, 10, 3 );
        }

        public function sdk()
        {
            return function_exists( 'wordplus_pickers' ) && wordplus_pickers() ? wordplus_pickers()->emoji() : null;
        }

        public function script_variables( $vars ){
            $vars['emojiDataUrl'] = $this->sdk() ? $this->sdk()->data_url() : false;
            $vars['emojiHash']    = (string) get_option( 'bm-emoji-hash', '' );
            return $vars;
        }

        public function rest_api_init(){
            $admin = function() {
                return current_user_can( 'manage_options' );
            };

            $set_arg = array(
                'set' => array(
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => array_keys( $this->emoji_sets ),
                ),
            );

            $locale_arg = array(
                'locale' => array(
                    'required' => true,
                    'type'     => 'string',
                ),
            );

            register_rest_route( 'better-messages/v1', '/getEmojiData', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_emoji_data_legacy' ),
                'permission_callback' => '__return_true',
            ) );

            register_rest_route( 'better-messages/v1/admin', '/downloadEmojiSprite', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_download_sprite' ),
                'permission_callback' => $admin,
                'args'                => $set_arg,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/deleteEmojiSprite', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_delete_sprite' ),
                'permission_callback' => $admin,
                'args'                => $set_arg,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/emojiSpriteStatus', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_sprite_status' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/downloadEmojiAnnotations', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_download_annotations' ),
                'permission_callback' => $admin,
                'args'                => $locale_arg,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/deleteEmojiAnnotations', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_delete_annotations' ),
                'permission_callback' => $admin,
                'args'                => $locale_arg,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/emojiAnnotationsStatus', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_annotations_status' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/refreshEmojiAnnotationsIndex', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_refresh_annotations_index' ),
                'permission_callback' => $admin,
            ) );
        }

        public function rest_download_sprite( $request ) {
            $result = $this->downloadSprite( $request->get_param( 'set' ) );

            if ( is_wp_error( $result ) ) {
                return new \WP_REST_Response( array(
                    'error' => $result->get_error_message(),
                ), 500 );
            }

            return new \WP_REST_Response( $result );
        }

        public function rest_delete_sprite( $request ) {
            $set = $request->get_param( 'set' );
            if ( ! isset( $this->emoji_sets[ $set ] ) ) {
                return new \WP_REST_Response( array( 'error' => 'Invalid emoji set' ), 400 );
            }

            if ( $this->sdk() ) {
                $this->sdk()->delete_sprite( $set );
            }

            return new \WP_REST_Response( array( 'deleted' => true ) );
        }

        public function rest_sprite_status() {
            return new \WP_REST_Response( $this->getLocalStatus() );
        }

        public function rest_get_emoji_data_legacy() {
            $data = $this->sdk() ? $this->sdk()->data() : null;

            if ( ! is_array( $data ) ) {
                return new \WP_REST_Response( array( 'error' => 'Manifest could not be generated' ), 500 );
            }

            $response = new \WP_REST_Response( $data );
            $response->header( 'Deprecation', 'true' );
            $response->header( 'Link', '<' . $this->sdk()->data_url() . '>; rel="successor-version"' );
            return $response;
        }

        public function rest_download_annotations( $request ) {
            $locale = $this->sanitize_locale( $request->get_param( 'locale' ) );
            $result = $this->sdk() ? $this->sdk()->download_annotations( $locale ) : new WP_Error( 'unavailable', '' );

            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
            }

            return new WP_REST_Response( array(
                'downloaded' => true,
                'locale'     => $locale,
                'size'       => $result['size'],
                'status'     => $this->sdk()->annotations_status(),
            ) );
        }

        public function rest_delete_annotations( $request ) {
            $locale = $this->sanitize_locale( $request->get_param( 'locale' ) );

            if ( $this->sdk() ) {
                $this->sdk()->delete_annotations( $locale );
            }

            return new WP_REST_Response( array(
                'deleted' => true,
                'locale'  => $locale,
                'status'  => $this->sdk() ? $this->sdk()->annotations_status() : array(),
            ) );
        }

        public function rest_annotations_status() {
            return new WP_REST_Response( array(
                'status' => $this->sdk() ? $this->sdk()->annotations_status() : array(),
                'index'  => $this->sdk() ? $this->sdk()->annotations_index() : array(),
            ) );
        }

        public function rest_refresh_annotations_index() {
            return new WP_REST_Response( array(
                'index'  => $this->sdk() ? $this->sdk()->annotations_index( true ) : array(),
                'status' => $this->sdk() ? $this->sdk()->annotations_status() : array(),
            ) );
        }

        public function getSpriteUrl(){
            return $this->sdk() ? $this->sdk()->sprite_url( true ) : '';
        }

        public function downloadSprite( $set ) {
            if ( ! isset( $this->emoji_sets[ $set ] ) ) {
                return new \WP_Error( 'invalid_set', 'Invalid emoji set' );
            }

            return $this->sdk() ? $this->sdk()->download_sprite( $set ) : new \WP_Error( 'unavailable', 'Emoji sprites are not available' );
        }

        public function getLocalStatus() {
            return $this->sdk() ? $this->sdk()->sprite_status() : array();
        }

        private function sanitize_locale( $locale ) {
            $locale = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $locale );
            return $locale === '' ? 'en_US' : $locale;
        }

    }

endif;


function Better_Messages_Emojis()
{
    return Better_Messages_Emojis::instance();
}
