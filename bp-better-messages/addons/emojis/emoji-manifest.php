<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Emoji_Manifest' ) ) {

    class Better_Messages_Emoji_Manifest
    {
        private static $instance = null;

        private $upload_dir;
        private $upload_url;

        public static function instance()
        {
            if ( self::$instance === null ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function __construct()
        {
            $upload           = wp_upload_dir();
            $this->upload_dir = $upload['basedir'] . '/better-messages/emoji-data/';
            $this->upload_url = $upload['baseurl'] . '/better-messages/emoji-data/';

            add_action( 'updated_option', array( $this, 'on_option_changed' ), 10, 1 );
            add_action( 'added_option',   array( $this, 'on_option_changed' ), 10, 1 );
            add_action( 'deleted_option', array( $this, 'on_option_changed' ), 10, 1 );
        }

        public function on_option_changed( $option_name )
        {
            if ( $option_name === 'bm-emoji-set-2' || $option_name === 'bm-better-messages-settings' ) {
                $this->delete_manifests();
            }
        }

        public function get_manifest_url( $locale = null )
        {
            $set = isset( Better_Messages()->settings['emojiSet'] ) ? Better_Messages()->settings['emojiSet'] : 'apple';

            if ( ! $this->is_valid_set( $set ) ) {
                $set = 'apple';
            }

            if ( ! $locale ) {
                $locale = determine_locale();
            }

            $locale = $this->sanitize_locale( $locale );

            $hash         = $this->compute_hash( $set, $locale );
            $baseurl_hash = substr( md5( $this->upload_url ), 0, 8 );
            $file_name    = 'bm-emoji-' . $set . '-' . $locale . '-' . $hash . '-' . $baseurl_hash . '.json';
            $file_path    = $this->upload_dir . $file_name;
            $file_url     = $this->upload_url . $file_name;

            if ( file_exists( $file_path ) ) {
                return $file_url;
            }

            $json = $this->build_manifest( $set, $locale );

            if ( ! $json ) {
                return false;
            }

            if ( ! wp_mkdir_p( $this->upload_dir ) ) {
                return false;
            }

            if ( @file_put_contents( $file_path, wp_json_encode( $json, JSON_UNESCAPED_UNICODE ) ) === false ) {
                return false;
            }

            $this->ensure_htaccess();

            $old_pattern = $this->upload_dir . 'bm-emoji-' . $set . '-' . $locale . '-*.json';
            foreach ( glob( $old_pattern ) as $old_file ) {
                if ( $old_file !== $file_path ) {
                    @unlink( $old_file );
                }
            }

            return $file_url;
        }

        protected function ensure_htaccess()
        {
            $path = $this->upload_dir . '.htaccess';
            if ( file_exists( $path ) ) {
                return;
            }
            $rules  = "<FilesMatch \"bm-emoji-.*\\.json$\">\n";
            $rules .= "  Header set Cache-Control \"public, max-age=31536000, immutable\"\n";
            $rules .= "</FilesMatch>\n";
            @file_put_contents( $path, $rules );
        }

        public function get_manifest_data( $locale = null )
        {
            $url = $this->get_manifest_url( $locale );
            if ( ! $url ) {
                return null;
            }

            $file = $this->upload_dir . basename( $url );
            $raw  = file_exists( $file ) ? @file_get_contents( $file ) : false;
            $data = $raw === false ? null : json_decode( $raw, true );

            return is_array( $data ) ? $data : null;
        }

        public function delete_manifests()
        {
            if ( ! is_dir( $this->upload_dir ) ) {
                return;
            }
            $files = glob( $this->upload_dir . 'bm-emoji-*.json' );
            if ( $files ) {
                foreach ( $files as $file ) {
                    @unlink( $file );
                }
            }
        }

        protected function is_valid_set( $set )
        {
            $sets = array( 'apple', 'facebook', 'google', 'twitter' );
            return in_array( $set, $sets, true );
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

        protected function compute_hash( $set, $locale )
        {
            $customizations = get_option( 'bm-emoji-set-2', array() );
            $custom_hash    = is_array( $customizations ) && ! empty( $customizations )
                ? md5( wp_json_encode( $customizations ) )
                : '';

            $parts = array(
                Better_Messages()->version,
                $set,
                $locale,
                $custom_hash,
                (string) get_option( 'bm-emoji-annotations-hash-' . $locale, '' ),
            );
            return substr( md5( implode( '|', $parts ) ), 0, 16 );
        }

        protected function build_manifest( $set, $locale )
        {
            $base_path = Better_Messages()->path . 'assets/emojies/' . $set . '.json';

            if ( ! file_exists( $base_path ) ) {
                return false;
            }

            $raw = @file_get_contents( $base_path );
            if ( $raw === false ) {
                return false;
            }

            $dataset = json_decode( $raw, true );
            if ( ! is_array( $dataset ) ) {
                return false;
            }

            $dataset = $this->apply_customizations( $dataset );
            $dataset = $this->apply_localization( $dataset, $locale );

            $dataset['locale']       = $locale;
            $dataset['generated_at'] = time();

            return apply_filters( 'better_messages_get_emoji_dataset', $dataset, $set, $locale );
        }

        public function apply_customizations( $dataset )
        {
            if ( empty( $dataset['categories'] ) || ! is_array( $dataset['categories'] ) ) {
                return $dataset;
            }

            $emojis = get_option( 'bm-emoji-set-2', array() );
            if ( ! is_array( $emojis ) || empty( $emojis ) ) {
                return $dataset;
            }

            foreach ( $dataset['categories'] as $category_index => $category ) {
                $category_id = strtolower( $category['id'] );

                if ( isset( $emojis[ $category_id ] ) ) {
                    $emojis_overwrite = $emojis[ $category_id ];

                    if ( ! is_array( $emojis_overwrite ) ) {
                        continue;
                    }

                    $emojis_overwrite = array_filter( $emojis_overwrite, function( $id ) { return $id !== '__none__'; } );

                    if ( count( $emojis_overwrite ) === 0 ) {
                        unset( $dataset['categories'][ $category_index ] );
                    } else {
                        $dataset['categories'][ $category_index ]['emojis'] = array_values( $emojis_overwrite );
                    }
                }
            }

            $dataset['categories'] = array_values( $dataset['categories'] );

            return $dataset;
        }

        protected function apply_localization( $dataset, $locale )
        {
            if ( empty( $dataset['emojis'] ) || ! is_array( $dataset['emojis'] ) ) {
                return $dataset;
            }

            $annotations = $this->load_annotations( $locale );

            if ( empty( $annotations ) ) {
                return $dataset;
            }

            foreach ( $dataset['emojis'] as $emoji_id => $emoji ) {
                $hex = $this->emoji_hexcode( $emoji );
                if ( ! $hex ) continue;

                if ( ! isset( $annotations[ $hex ] ) ) continue;

                $entry = $annotations[ $hex ];

                if ( ! empty( $entry['name'] ) ) {
                    $dataset['emojis'][ $emoji_id ]['name'] = $entry['name'];
                }

                if ( ! empty( $entry['keywords'] ) && is_array( $entry['keywords'] ) ) {
                    $existing = isset( $emoji['keywords'] ) && is_array( $emoji['keywords'] ) ? $emoji['keywords'] : array();
                    $merged   = array_values( array_unique( array_merge( $existing, array_map( 'strval', $entry['keywords'] ) ) ) );
                    $dataset['emojis'][ $emoji_id ]['keywords'] = $merged;
                }

                $dataset['emojis'][ $emoji_id ] = apply_filters(
                    'better_messages_localize_emoji',
                    $dataset['emojis'][ $emoji_id ],
                    $entry,
                    $locale
                );
            }

            return $dataset;
        }

        protected function emoji_hexcode( $emoji )
        {
            if ( empty( $emoji['skins'] ) || ! is_array( $emoji['skins'] ) ) {
                return '';
            }
            $first = reset( $emoji['skins'] );
            if ( empty( $first['unified'] ) ) {
                return '';
            }
            return strtoupper( (string) $first['unified'] );
        }

        protected function load_annotations( $locale )
        {
            if ( ! class_exists( 'Better_Messages_Emoji_Annotations' ) ) {
                return array();
            }

            $path = Better_Messages_Emoji_Annotations::instance()->local_path( $locale );
            $raw  = file_exists( $path ) ? @file_get_contents( $path ) : false;
            $data = $raw === false ? null : json_decode( $raw, true );

            if ( ! is_array( $data ) ) {
                return array();
            }

            $entries = ( isset( $data['emojis'] ) && is_array( $data['emojis'] ) ) ? $data['emojis'] : $data;

            $normalized = array();
            foreach ( $entries as $hex => $entry ) {
                $normalized[ strtoupper( (string) $hex ) ] = $entry;
            }
            return $normalized;
        }
    }
}

if ( ! function_exists( 'Better_Messages_Emoji_Manifest' ) ) {
    function Better_Messages_Emoji_Manifest()
    {
        return Better_Messages_Emoji_Manifest::instance();
    }
}
