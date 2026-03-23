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

        private $cdn_urls = [
            'apple'    => 'https://cdn.jsdelivr.net/npm/emoji-datasource-apple@14.0.0/img/apple/sheets-256/64.png',
            'twitter'  => 'https://cdn.jsdelivr.net/npm/emoji-datasource-twitter@14.0.0/img/twitter/sheets-256/64.png',
            'google'   => 'https://cdn.jsdelivr.net/npm/emoji-datasource-google@14.0.0/img/google/sheets-256/64.png',
            'facebook' => 'https://cdn.jsdelivr.net/npm/emoji-datasource-facebook@14.0.0/img/facebook/sheets-256/64.png',
        ];

        private $self_host_base = 'https://www.better-messages.com/emoji/';

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
            $selected_set = Better_Messages()->settings['emojiSet'];
            $this->set = $selected_set;

            add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
        }

        public function rest_api_init(){
            register_rest_route( 'better-messages/v1', '/getEmojiData', array(
                'methods' => 'GET',
                'callback' => array( $this, 'get_emoji_settings' ),
                'permission_callback' => '__return_true'
            ) );

            register_rest_route( 'better-messages/v1/admin', '/downloadEmojiSprite', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_download_sprite' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'args' => array(
                    'set' => array(
                        'required' => true,
                        'type' => 'string',
                        'enum' => array( 'apple', 'twitter', 'google', 'facebook' ),
                    ),
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/deleteEmojiSprite', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_delete_sprite' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
                'args' => array(
                    'set' => array(
                        'required' => true,
                        'type' => 'string',
                        'enum' => array( 'apple', 'twitter', 'google', 'facebook' ),
                    ),
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/emojiSpriteStatus', array(
                'methods' => 'GET',
                'callback' => array( $this, 'rest_sprite_status' ),
                'permission_callback' => function() {
                    return current_user_can( 'manage_options' );
                },
            ) );
        }

        public function rest_download_sprite( $request ) {
            $set = $request->get_param( 'set' );
            $result = $this->downloadSprite( $set );

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

            $path = $this->getLocalSpritePath( $set );
            if ( file_exists( $path ) ) {
                @unlink( $path );
            }

            return new \WP_REST_Response( array( 'deleted' => true ) );
        }

        public function rest_sprite_status() {
            return new \WP_REST_Response( $this->getLocalStatus() );
        }

        public function getDataset(){
            $emoji_path = Better_Messages()->path . 'assets/emojies/' . $this->set . '.json';
            return json_decode(file_get_contents( $emoji_path ), true );
        }

        public function getSpriteUrl(){
            $delivery = Better_Messages()->settings['emojiSpriteDelivery'] ?? 'cdn';
            $set = $this->set;

            if ( $delivery === 'self-hosted' ) {
                $local_url = $this->getLocalSpriteUrl( $set );
                if ( $local_url ) {
                    return trim( $local_url );
                }
                // Fallback to CDN if local file not available
            }

            return trim( $this->cdn_urls[ $set ] ?? $this->cdn_urls['apple'] );
        }

        /**
         * Get the local sprite URL if the file has been downloaded.
         */
        private function getLocalSpriteUrl( $set ) {
            $local_path = $this->getLocalSpritePath( $set );

            if ( file_exists( $local_path ) ) {
                $upload_dir = wp_upload_dir();
                return $upload_dir['baseurl'] . '/better-messages/emoji/' . $set . '.png';
            }

            return false;
        }

        /**
         * Get the local file system path for a sprite.
         */
        private function getLocalSpritePath( $set ) {
            $upload_dir = wp_upload_dir();
            return $upload_dir['basedir'] . '/better-messages/emoji/' . $set . '.png';
        }

        /**
         * Download emoji sprite from Better Messages server to local uploads.
         *
         * @param string $set Emoji set name (apple, twitter, google, facebook)
         * @return array|WP_Error Result with 'url' key on success
         */
        public function downloadSprite( $set ) {
            if ( ! isset( $this->emoji_sets[ $set ] ) ) {
                return new \WP_Error( 'invalid_set', 'Invalid emoji set' );
            }

            $remote_url = $this->self_host_base . $set . '.png';
            $local_path = $this->getLocalSpritePath( $set );
            $local_dir  = dirname( $local_path );

            // Ensure directory exists
            if ( ! file_exists( $local_dir ) ) {
                wp_mkdir_p( $local_dir );
            }

            // Download the file
            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp_file = download_url( $remote_url, 60 );

            if ( is_wp_error( $tmp_file ) ) {
                return $tmp_file;
            }

            // Move to final location
            if ( ! @rename( $tmp_file, $local_path ) ) {
                // rename may fail across filesystems
                if ( ! @copy( $tmp_file, $local_path ) ) {
                    @unlink( $tmp_file );
                    return new \WP_Error( 'move_failed', 'Could not save emoji sprite file' );
                }
                @unlink( $tmp_file );
            }

            return array(
                'url'  => $this->getLocalSpriteUrl( $set ),
                'size' => filesize( $local_path ),
            );
        }

        /**
         * Check which emoji sets are available locally.
         */
        public function getLocalStatus() {
            $result = array();
            foreach ( array_keys( $this->emoji_sets ) as $set ) {
                $path = $this->getLocalSpritePath( $set );
                $result[ $set ] = array(
                    'downloaded' => file_exists( $path ),
                    'size'       => file_exists( $path ) ? filesize( $path ) : 0,
                );
            }
            return $result;
        }

        public function get_emoji_settings(){
            $dataset = $this->getDataset();

            $emojis = get_option('bm-emoji-set-2');

            foreach( $dataset['categories'] as $category_index => $category ){
                $category = strtolower($category['id']);

                if( isset( $emojis[ $category ] ) ){
                    $emojis_overwrite = $emojis[$category];
                    $emojis_overwrite = array_filter( $emojis_overwrite, function( $id ){ return $id !== '__none__'; } );

                    if( count( $emojis_overwrite ) === 0 ){
                        unset( $dataset['categories'][ $category_index ] );
                    } else {
                        $dataset['categories'][ $category_index ]['emojis'] = array_values( $emojis_overwrite );
                    }
                }

            }

            $dataset['categories'] = array_values( $dataset['categories'] );

            return apply_filters('better_messages_get_emoji_dataset', $dataset);
        }
    }

endif;


function Better_Messages_Emojis()
{
    return Better_Messages_Emojis::instance();
}
