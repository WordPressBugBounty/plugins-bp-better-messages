<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Emoji_Annotations' ) ) {

    class Better_Messages_Emoji_Annotations
    {
        private static $instance = null;

        private $remote_base = 'https://www.better-messages.com/emoji/annotations/';
        private $index_url   = 'https://www.better-messages.com/emoji/annotations/index.json';
        private $index_transient = 'bm_emoji_annotations_index';

        public static function instance()
        {
            if ( self::$instance === null ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        }

        public function register_routes()
        {
            register_rest_route( 'better-messages/v1/admin', '/downloadEmojiAnnotations', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_download' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
                'args' => array(
                    'locale' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/deleteEmojiAnnotations', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_delete' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
                'args' => array(
                    'locale' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/emojiAnnotationsStatus', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_status' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ) );

            register_rest_route( 'better-messages/v1/admin', '/refreshEmojiAnnotationsIndex', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_refresh_index' ),
                'permission_callback' => function() { return current_user_can( 'manage_options' ); },
            ) );
        }

        public function local_dir()
        {
            $upload = wp_upload_dir();
            return $upload['basedir'] . '/better-messages/emoji/annotations/';
        }

        public function local_path( $locale )
        {
            return $this->local_dir() . $this->sanitize_locale( $locale ) . '.json';
        }

        public function rest_download( $request )
        {
            $locale = $this->sanitize_locale( $request->get_param( 'locale' ) );

            $result = $this->download( $locale );

            if ( is_wp_error( $result ) ) {
                return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 500 );
            }

            return new WP_REST_Response( array(
                'downloaded' => true,
                'locale'     => $locale,
                'size'       => $result['size'],
                'status'     => $this->get_status(),
            ) );
        }

        public function rest_delete( $request )
        {
            $locale = $this->sanitize_locale( $request->get_param( 'locale' ) );

            $this->delete( $locale );

            return new WP_REST_Response( array(
                'deleted' => true,
                'locale'  => $locale,
                'status'  => $this->get_status(),
            ) );
        }

        public function rest_status()
        {
            return new WP_REST_Response( array(
                'status' => $this->get_status(),
                'index'  => $this->get_index(),
            ) );
        }

        public function rest_refresh_index()
        {
            delete_transient( $this->index_transient );
            $index = $this->get_index( true );

            return new WP_REST_Response( array(
                'index'  => $index,
                'status' => $this->get_status(),
            ) );
        }

        public function download( $locale )
        {
            $locale = $this->sanitize_locale( $locale );

            if ( ! wp_mkdir_p( $this->local_dir() ) ) {
                return new WP_Error( 'mkdir_failed', __( 'Could not create annotations directory.', 'bp-better-messages' ) );
            }

            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $tmp = download_url( $this->remote_base . $locale . '.json', 60 );
            if ( is_wp_error( $tmp ) ) {
                return $tmp;
            }

            $raw = @file_get_contents( $tmp );
            if ( $raw === false || ! is_array( json_decode( $raw, true ) ) ) {
                @unlink( $tmp );
                return new WP_Error( 'invalid_json', __( 'Downloaded annotation file is not valid JSON.', 'bp-better-messages' ) );
            }

            $local_path = $this->local_path( $locale );
            $moved      = @rename( $tmp, $local_path ) || @copy( $tmp, $local_path );
            @unlink( $tmp );

            if ( ! $moved ) {
                return new WP_Error( 'move_failed', __( 'Could not save annotation file.', 'bp-better-messages' ) );
            }

            update_option( 'bm-emoji-annotations-hash-' . $locale, md5_file( $local_path ) );

            if ( class_exists( 'Better_Messages_Emoji_Manifest' ) ) {
                Better_Messages_Emoji_Manifest::instance()->delete_manifests();
            }

            return array( 'size' => filesize( $local_path ) );
        }

        public function delete( $locale )
        {
            $locale = $this->sanitize_locale( $locale );
            $path   = $this->local_path( $locale );

            if ( file_exists( $path ) ) {
                @unlink( $path );
            }

            delete_option( 'bm-emoji-annotations-hash-' . $locale );

            if ( class_exists( 'Better_Messages_Emoji_Manifest' ) ) {
                Better_Messages_Emoji_Manifest::instance()->delete_manifests();
            }
        }

        public function get_index( $force = false )
        {
            if ( ! $force ) {
                $cached = get_transient( $this->index_transient );
                if ( is_array( $cached ) ) {
                    return $cached;
                }
            }

            $response = wp_remote_get( $this->index_url, array( 'timeout' => 15 ) );

            if ( is_wp_error( $response ) ) {
                return array();
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                return array();
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! is_array( $data ) ) {
                return array();
            }

            $index = isset( $data['locales'] ) && is_array( $data['locales'] ) ? $data['locales'] : $data;

            set_transient( $this->index_transient, $index, 12 * HOUR_IN_SECONDS );

            return $index;
        }

        public function get_status()
        {
            $result = array();
            $dir    = $this->local_dir();

            if ( is_dir( $dir ) ) {
                $files = glob( $dir . '*.json' );
                if ( $files ) {
                    foreach ( $files as $file ) {
                        $locale = basename( $file, '.json' );
                        $result[ $locale ] = array(
                            'downloaded' => true,
                            'size'       => filesize( $file ),
                        );
                    }
                }
            }

            return $result;
        }

        protected function sanitize_locale( $locale )
        {
            $locale = (string) $locale;
            $locale = preg_replace( '/[^A-Za-z0-9_\-]/', '', $locale );
            if ( $locale === '' ) {
                $locale = 'en_US';
            }
            return $locale;
        }
    }
}

if ( ! function_exists( 'Better_Messages_Emoji_Annotations' ) ) {
    function Better_Messages_Emoji_Annotations()
    {
        return Better_Messages_Emoji_Annotations::instance();
    }
}
