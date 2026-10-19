<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Emoji' ) ) {
    final class WordPlus_SDK_Emoji
    {
        const SETS = array(
            'apple'    => 'Apple',
            'facebook' => 'Facebook',
            'google'   => 'Google',
            'twitter'  => 'Twitter',
        );

        const CDN = 'https://cdn.jsdelivr.net/npm/emoji-datasource-%1$s@14.0.0/img/%1$s/sheets-256/64.png';

        const DOWNLOADS = 'https://api.wordplus.cloud/api/v1/assets/emoji/';

        const INDEX_TRANSIENT = 'wplus_emoji_annotations_index';

        const CUT_EVENT = 'wordplus_pickers_cut_emoji';

        const CUT_TRANSIENT = 'wplus_emoji_cut_';

        const DATA_NAME = '-[0-9a-f]{16}\.json';

        private $pickers;

        private $root;

        private $root_url;

        private $version;

        private $adopted = false;

        public function __construct( WordPlus_SDK_Pickers $pickers, $root, $root_url, $version = '' )
        {
            $this->pickers  = $pickers;
            $this->root     = untrailingslashit( (string) $root );
            $this->root_url = untrailingslashit( (string) $root_url );
            $this->version  = (string) $version;
        }

        public function set()
        {
            $set = (string) $this->pickers->settings()['emojiSet'];

            return array_key_exists( $set, self::SETS ) ? $set : 'native';
        }

        public function datasets_url()
        {
            return $this->root_url . '/pickers/data/emoji/';
        }

        public function data_url( $locale = '' )
        {
            $file = $this->data_file( $locale );

            return $file ? $this->upload( 'baseurl' ) . '/wordplus/emoji-data/' . basename( $file ) : false;
        }

        public function data( $locale = '' )
        {
            $file = $this->data_file( $locale );
            $data = $file ? json_decode( (string) file_get_contents( $file ), true ) : null;

            return is_array( $data ) ? $data : null;
        }

        public function delete_data()
        {
            foreach ( $this->data_files() as $file ) {
                wp_delete_file( $file );
            }
        }

        private function data_file( $locale )
        {
            $set    = $this->dataset();
            $locale = $this->sanitize_locale( '' !== (string) $locale ? $locale : determine_locale() );
            $dir    = $this->data_dir();
            $file   = $dir . $set . '-' . $locale . '-' . $this->data_hash( $set, $locale ) . '.json';

            if ( file_exists( $file ) ) {
                return $file;
            }

            $data = $this->build( $set, $locale );

            if ( ! $data || ! wp_mkdir_p( $dir ) || false === file_put_contents( $file, wp_json_encode( $data, JSON_UNESCAPED_UNICODE ) ) ) {
                return false;
            }

            $rules = "<IfModule mod_headers.c>\n<FilesMatch \"^[a-z]+-[A-Za-z0-9_-]+" . self::DATA_NAME . "$\">\n  Header set Cache-Control \"public, max-age=31536000, immutable\"\n</FilesMatch>\n</IfModule>\n";

            if ( ! file_exists( $dir . '.htaccess' ) || file_get_contents( $dir . '.htaccess' ) !== $rules ) {
                file_put_contents( $dir . '.htaccess', $rules );
            }

            foreach ( $this->data_files( preg_quote( $set . '-' . $locale, '/' ) ) as $old ) {
                if ( $old !== $file ) {
                    wp_delete_file( $old );
                }
            }

            return $file;
        }

        private function data_files( $set_locale = '[a-z]+-[A-Za-z0-9_-]+' )
        {
            $files = array();

            foreach ( glob( $this->data_dir() . '*.json' ) ?: array() as $file ) {
                if ( preg_match( '/^' . $set_locale . self::DATA_NAME . '$/', basename( $file ) ) ) {
                    $files[] = $file;
                }
            }

            return $files;
        }

        private function dataset()
        {
            $set = $this->set();

            return 'native' === $set ? 'apple' : $set;
        }

        private function data_hash( $set, $locale )
        {
            $dataset     = $this->root . '/pickers/data/emoji/' . $set . '.json';
            $annotations = $this->annotations_path( $locale );

            return substr( md5( implode( '|', array(
                $set,
                $locale,
                file_exists( $dataset ) ? filesize( $dataset ) . '-' . filemtime( $dataset ) : '',
                file_exists( $annotations ) ? filesize( $annotations ) . '-' . filemtime( $annotations ) : '',
                md5( (string) wp_json_encode( $this->pickers->settings()['emojiCategories'] ) ),
                $this->upload( 'baseurl' ),
            ) ) ), 0, 16 );
        }

        private function build( $set, $locale )
        {
            $file    = $this->root . '/pickers/data/emoji/' . $set . '.json';
            $dataset = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

            if ( ! is_array( $dataset ) ) {
                return false;
            }

            $dataset                 = $this->localize( $this->customize( $dataset ), $locale );
            $dataset['locale']       = $locale;
            $dataset['generated_at'] = time();

            return apply_filters( 'wordplus_pickers_emoji_dataset', $dataset, $set, $locale );
        }

        private function customize( array $dataset )
        {
            $chosen = $this->pickers->settings()['emojiCategories'];

            if ( empty( $dataset['categories'] ) || ! is_array( $dataset['categories'] ) || ! is_array( $chosen ) || ! $chosen ) {
                return $dataset;
            }

            foreach ( $dataset['categories'] as $index => $category ) {
                $id = strtolower( (string) $category['id'] );

                if ( ! isset( $chosen[ $id ] ) || ! is_array( $chosen[ $id ] ) ) {
                    continue;
                }

                $emojis = array_values( array_filter( $chosen[ $id ], function ( $emoji ) {
                    return '__none__' !== $emoji;
                } ) );

                if ( $emojis ) {
                    $dataset['categories'][ $index ]['emojis'] = $emojis;
                } else {
                    unset( $dataset['categories'][ $index ] );
                }
            }

            $dataset['categories'] = array_values( $dataset['categories'] );

            return $dataset;
        }

        private function localize( array $dataset, $locale )
        {
            $annotations = $this->annotations( $locale );

            if ( ! $annotations || empty( $dataset['emojis'] ) || ! is_array( $dataset['emojis'] ) ) {
                return $dataset;
            }

            foreach ( $dataset['emojis'] as $id => $emoji ) {
                $skin = ! empty( $emoji['skins'] ) && is_array( $emoji['skins'] ) ? reset( $emoji['skins'] ) : array();
                $hex  = ! empty( $skin['unified'] ) ? strtoupper( (string) $skin['unified'] ) : '';

                if ( '' === $hex || ! isset( $annotations[ $hex ] ) ) {
                    continue;
                }

                $entry = $annotations[ $hex ];

                if ( ! empty( $entry['name'] ) ) {
                    $dataset['emojis'][ $id ]['name'] = $entry['name'];
                }

                if ( ! empty( $entry['keywords'] ) && is_array( $entry['keywords'] ) ) {
                    $keywords = isset( $emoji['keywords'] ) && is_array( $emoji['keywords'] ) ? $emoji['keywords'] : array();

                    $dataset['emojis'][ $id ]['keywords'] = array_values( array_unique( array_merge( $keywords, array_map( 'strval', $entry['keywords'] ) ) ) );
                }

                $dataset['emojis'][ $id ] = apply_filters( 'wordplus_pickers_localize_emoji', $dataset['emojis'][ $id ], $entry, $locale );
            }

            return $dataset;
        }

        private function annotations( $locale )
        {
            $file = $this->annotations_path( $locale );
            $data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

            if ( ! is_array( $data ) ) {
                return array();
            }

            $entries = isset( $data['emojis'] ) && is_array( $data['emojis'] ) ? $data['emojis'] : $data;
            $result  = array();

            foreach ( $entries as $hex => $entry ) {
                $result[ strtoupper( (string) $hex ) ] = $entry;
            }

            return $result;
        }

        public function sprite_url( $cdn = true )
        {
            $set = $this->set();

            if ( 'native' === $set ) {
                return '';
            }

            if ( 'self-hosted' === $this->pickers->settings()['emojiSpriteDelivery'] || ! $cdn ) {
                $this->adopt();

                if ( file_exists( $this->sprite_path( $set ) ) ) {
                    return $this->upload( 'baseurl' ) . '/wordplus/emoji/' . $set . '.png';
                }
            }

            return $cdn ? sprintf( self::CDN, $set ) : '';
        }

        public function cdn_sprite_url( $set )
        {
            return array_key_exists( $set, self::SETS ) ? sprintf( self::CDN, $set ) : '';
        }

        public function download_sprite( $set )
        {
            if ( ! array_key_exists( $set, self::SETS ) ) {
                return new WP_Error( 'invalid_set', __( 'There is no such emoji set.', 'wordplus-sdk' ) );
            }

            $body = $this->download( self::DOWNLOADS . $set . '.png', fn ( $body ) => self::is_png( $body ) );

            if ( is_wp_error( $body ) ) {
                return $body;
            }

            if ( ! self::is_png( $body ) ) {
                return new WP_Error( 'invalid_sprite', __( 'The downloaded file is not an image.', 'wordplus-sdk' ) );
            }

            $saved = $this->save( $this->sprite_path( $set ), $body );

            if ( is_wp_error( $saved ) ) {
                return $saved;
            }

            $this->cut_images( $set );

            return array(
                'url'  => $this->upload( 'baseurl' ) . '/wordplus/emoji/' . $set . '.png',
                'size' => strlen( $body ),
            );
        }

        public function delete_sprite( $set )
        {
            if ( array_key_exists( $set, self::SETS ) ) {
                $this->adopt();
                wp_delete_file( $this->sprite_path( $set ) );
                $this->delete_images( $set );
            }
        }

        public function sprite_status()
        {
            $this->adopt();
            $status = array();

            foreach ( array_keys( self::SETS ) as $set ) {
                $file           = $this->sprite_path( $set );
                $status[ $set ] = array(
                    'downloaded' => file_exists( $file ),
                    'size'       => file_exists( $file ) ? filesize( $file ) : 0,
                    'images'     => file_exists( $this->images_dir( $set ) . 'index.json' ),
                );
            }

            return $status;
        }

        private function sprite_path( $set )
        {
            return $this->dir() . $set . '.png';
        }

        public function text_config( $set = '' )
        {
            $current = '' === $set || $set === $this->set();
            $set     = $current ? $this->set() : (string) $set;

            if ( 'native' !== $set && array_key_exists( $set, self::SETS ) ) {
                $index = $this->images_dir( $set ) . 'index.json';

                if ( file_exists( $index ) ) {
                    $url     = $this->upload( 'baseurl' ) . '/wordplus/emoji/' . $set . '/';
                    $version = (string) filemtime( $index );

                    $config = array(
                        'set'     => $set,
                        'images'  => $url,
                        'version' => $version,
                        'index'   => $url . 'index.json?v=' . $version,
                    );

                    if ( $current ) {
                        $config['rest'] = rest_url( WordPlus_SDK_Pickers::REST_NAMESPACE . '/pickers/emoji-index' );
                    }

                    return $config;
                }

                $this->adopt();

                if ( file_exists( $this->sprite_path( $set ) ) && ! get_transient( self::CUT_TRANSIENT . $set ) ) {
                    set_transient( self::CUT_TRANSIENT . $set, 1, HOUR_IN_SECONDS );

                    if ( ! wp_next_scheduled( self::CUT_EVENT, array( $set ) ) ) {
                        wp_schedule_single_event( time(), self::CUT_EVENT, array( $set ) );
                    }
                }
            }

            return array(
                'set'    => 'native',
                'images' => '',
                'index'  => '',
            );
        }

        public function cut_images( $set )
        {
            if ( ! array_key_exists( $set, self::SETS ) ) {
                return new WP_Error( 'invalid_set', __( 'There is no such emoji set.', 'wordplus-sdk' ) );
            }

            $this->adopt();

            $file    = $this->root . '/pickers/data/emoji/' . $set . '.json';
            $dataset = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

            if ( ! file_exists( $this->sprite_path( $set ) ) || ! is_array( $dataset ) || empty( $dataset['sheet']['cols'] ) || empty( $dataset['emojis'] ) ) {
                return new WP_Error( 'no_sprite', __( 'The emoji set has not been downloaded.', 'wordplus-sdk' ) );
            }

            if ( ! function_exists( 'imagecreatefrompng' ) ) {
                return new WP_Error( 'no_gd', __( 'The emoji images need PHP\'s GD extension.', 'wordplus-sdk' ) );
            }

            $info  = wp_getimagesize( $this->sprite_path( $set ) );
            $sheet = false;

            if ( $info && IMAGETYPE_PNG === $info[2] ) {
                wp_raise_memory_limit( 'image' );
                $sheet = imagecreatefrompng( $this->sprite_path( $set ) );
            }

            if ( ! $sheet ) {
                return new WP_Error( 'invalid_sprite', __( 'The downloaded file is not an image.', 'wordplus-sdk' ) );
            }

            imagepalettetotruecolor( $sheet );

            $cell = (int) floor( imagesx( $sheet ) / (int) $dataset['sheet']['cols'] );
            $size = $cell - 2;
            $dir  = $this->images_dir( $set );

            if ( $size < 1 || ! wp_mkdir_p( $dir ) ) {
                return new WP_Error( 'save_failed', __( 'The file could not be saved.', 'wordplus-sdk' ) );
            }

            wp_delete_file( $dir . 'index.json' );
            $keys = array();

            foreach ( $dataset['emojis'] as $emoji ) {
                foreach ( isset( $emoji['skins'] ) ? (array) $emoji['skins'] : array() as $skin ) {
                    if ( ! isset( $skin['x'], $skin['y'], $skin['unified'] ) ) {
                        continue;
                    }

                    $key = self::key( $skin['unified'] );

                    if ( '' === $key || isset( $keys[ $key ] ) ) {
                        continue;
                    }

                    $image = imagecreatetruecolor( $size, $size );
                    imagealphablending( $image, false );
                    imagesavealpha( $image, true );
                    imagefill( $image, 0, 0, imagecolorallocatealpha( $image, 0, 0, 0, 127 ) );
                    imagecopy( $image, $sheet, 0, 0, (int) $skin['x'] * $cell + 1, (int) $skin['y'] * $cell + 1, $size, $size );

                    if ( ! imagepng( $image, $dir . $key . '.png' ) ) {
                        return new WP_Error( 'save_failed', __( 'The file could not be saved.', 'wordplus-sdk' ) );
                    }

                    $keys[ $key ] = true;
                }
            }

            if ( ! file_exists( $dir . '.htaccess' ) ) {
                file_put_contents( $dir . '.htaccess', "<IfModule mod_headers.c>\n<FilesMatch \"\\.png$\">\n  Header set Cache-Control \"public, max-age=2592000\"\n</FilesMatch>\n</IfModule>\n" );
            }

            $saved = $this->save( $dir . 'index.json', (string) wp_json_encode( array( 'set' => $set, 'keys' => array_map( 'strval', array_keys( $keys ) ) ) ) );

            if ( is_wp_error( $saved ) ) {
                return $saved;
            }

            delete_transient( self::CUT_TRANSIENT . $set );

            return count( $keys );
        }

        public function images_index()
        {
            $set  = $this->set();
            $file = 'native' === $set ? '' : $this->images_dir( $set ) . 'index.json';
            $data = '' !== $file && file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

            return is_array( $data ) && isset( $data['keys'] ) ? $data : null;
        }

        public static function key( $unified )
        {
            $points = array();

            foreach ( explode( '-', strtolower( (string) $unified ) ) as $hex ) {
                if ( 'fe0f' !== $hex && preg_match( '/^[0-9a-f]{1,6}$/', $hex ) ) {
                    $points[] = dechex( hexdec( $hex ) );
                }
            }

            return implode( '-', $points );
        }

        private function delete_images( $set )
        {
            $dir = $this->images_dir( $set );

            foreach ( glob( $dir . '*.png' ) ?: array() as $file ) {
                wp_delete_file( $file );
            }

            wp_delete_file( $dir . 'index.json' );
            wp_delete_file( $dir . '.htaccess' );

            if ( is_dir( $dir ) ) {
                WordPlus_SDK::files()->rmdir( $dir );
            }
        }

        private function images_dir( $set )
        {
            return $this->dir() . $set . '/';
        }

        public function download_annotations( $locale )
        {
            $locale = $this->sanitize_locale( $locale );
            $body   = $this->download( self::DOWNLOADS . 'annotations/' . $locale . '.json', fn ( $body ) => self::is_json( $body ) );

            if ( is_wp_error( $body ) ) {
                return $body;
            }

            if ( ! self::is_json( $body ) ) {
                return new WP_Error( 'invalid_json', __( 'The downloaded names are not valid JSON.', 'wordplus-sdk' ) );
            }

            $saved = $this->save( $this->annotations_path( $locale ), $body );

            if ( is_wp_error( $saved ) ) {
                return $saved;
            }

            $this->delete_data();

            return array( 'size' => strlen( $body ) );
        }

        public function delete_annotations( $locale )
        {
            $this->adopt();
            wp_delete_file( $this->annotations_path( $locale ) );
            $this->delete_data();
        }

        public function annotations_status()
        {
            $this->adopt();
            $status = array();

            foreach ( glob( $this->dir() . 'annotations/*.json' ) ?: array() as $file ) {
                $status[ basename( $file, '.json' ) ] = array(
                    'downloaded' => true,
                    'size'       => filesize( $file ),
                );
            }

            return $status;
        }

        public function annotations_index( $force = false )
        {
            if ( ! $force ) {
                $cached = get_transient( self::INDEX_TRANSIENT );

                if ( is_array( $cached ) ) {
                    return $cached;
                }
            }

            require_once __DIR__ . '/class-cloud-files.php';

            $response = WordPlus_SDK_Cloud_Files::get( self::DOWNLOADS . 'annotations/index.json', $this->request( 15 ), fn ( $body ) => self::is_json( $body ) );

            if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                return array();
            }

            $data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

            if ( ! is_array( $data ) ) {
                return array();
            }

            $index = isset( $data['locales'] ) && is_array( $data['locales'] ) ? $data['locales'] : $data;

            set_transient( self::INDEX_TRANSIENT, $index, 12 * HOUR_IN_SECONDS );

            return $index;
        }

        private function annotations_path( $locale )
        {
            $this->adopt();

            return $this->dir() . 'annotations/' . $this->sanitize_locale( $locale ) . '.json';
        }

        private function dir()
        {
            return $this->upload( 'basedir' ) . '/wordplus/emoji/';
        }

        private function data_dir()
        {
            return $this->upload( 'basedir' ) . '/wordplus/emoji-data/';
        }

        private function upload( $key )
        {
            $upload = wp_upload_dir( null, false );

            return untrailingslashit( (string) $upload[ $key ] );
        }

        private function adopt()
        {
            if ( $this->adopted ) {
                return;
            }

            $this->adopted = true;
            $legacy        = $this->upload( 'basedir' ) . '/better-messages/emoji/';

            if ( is_dir( $this->dir() ) || ! is_dir( $legacy ) || ! wp_mkdir_p( $this->dir() . 'annotations' ) ) {
                return;
            }

            $files = glob( $legacy . 'annotations/*.json' ) ?: array();

            foreach ( array_keys( self::SETS ) as $set ) {
                $files[] = $legacy . $set . '.png';
            }

            foreach ( $files as $file ) {
                if ( file_exists( $file ) ) {
                    copy( $file, $this->dir() . substr( $file, strlen( $legacy ) ) );
                }
            }
        }

        private function request( $timeout )
        {
            return array(
                'timeout' => $timeout,
                'headers' => array( 'X-WordPlus-Plugin' => 'wordplus-sdk' . ( '' === $this->version ? '' : '/' . $this->version ) ),
            );
        }

        private function download( $url, $valid )
        {
            require_once __DIR__ . '/class-cloud-files.php';

            $response = WordPlus_SDK_Cloud_Files::get( $url, $this->request( 60 ), $valid );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
                return new WP_Error( 'download_failed', __( 'The file could not be downloaded.', 'wordplus-sdk' ) );
            }

            return (string) wp_remote_retrieve_body( $response );
        }

        private static function is_png( $body )
        {
            return 0 === strpos( (string) $body, "\x89PNG" );
        }

        private static function is_json( $body )
        {
            return is_array( json_decode( (string) $body, true ) );
        }

        private function save( $file, $body )
        {
            $this->adopt();

            if ( ! wp_mkdir_p( dirname( $file ) ) || false === file_put_contents( $file, $body ) ) {
                return new WP_Error( 'save_failed', __( 'The file could not be saved.', 'wordplus-sdk' ) );
            }

            return true;
        }

        private function sanitize_locale( $locale )
        {
            $locale = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $locale );

            return '' === $locale ? 'en_US' : $locale;
        }
    }
}
