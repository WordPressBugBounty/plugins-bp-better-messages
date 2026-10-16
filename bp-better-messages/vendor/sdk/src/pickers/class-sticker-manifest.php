<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Sticker_Manifest' ) ) {
    final class WordPlus_SDK_Sticker_Manifest
    {
        const NAME = '-[0-9a-f]*-[0-9a-f]{8}\.json';

        private $stickers;

        private $upload_dir;
        private $upload_url;

        public function __construct( WordPlus_SDK_Stickers $stickers )
        {
            $this->stickers   = $stickers;
            $uploads          = $stickers->uploads();
            $this->upload_dir = $uploads['path'] . '/' . WordPlus_SDK_Stickers::ROOT . '/';
            $this->upload_url = $uploads['url'] . '/' . WordPlus_SDK_Stickers::ROOT . '/';
        }

        public function get_manifest_url( $locale = null )
        {
            if ( ! $locale ) {
                $locale = determine_locale();
            }

            $locale       = preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $locale );
            $locale       = '' === $locale ? 'en_US' : $locale;
            $hash         = $this->stickers->hash();
            $baseurl_hash = substr( md5( $this->upload_url ), 0, 8 );
            $file_name    = $locale . '-' . $hash . '-' . $baseurl_hash . '.json';
            $file_path    = $this->upload_dir . $file_name;
            $file_url     = $this->upload_url . $file_name;

            if ( file_exists( $file_path ) ) {
                return $file_url;
            }

            $packs = $this->stickers->packs()->get_all();

            if ( empty( $packs ) ) {
                return false;
            }

            $json = $this->build_manifest( $packs, $locale );

            if ( ! wp_mkdir_p( $this->upload_dir ) ) {
                return false;
            }

            if ( @file_put_contents( $file_path, wp_json_encode( $json, JSON_UNESCAPED_UNICODE ) ) === false ) {
                return false;
            }

            $this->ensure_htaccess();

            foreach ( $this->files( preg_quote( $locale, '/' ) ) as $old_file ) {
                if ( $old_file !== $file_path ) {
                    wp_delete_file( $old_file );
                }
            }

            return $file_url;
        }

        private function files( $locale = '[A-Za-z0-9_-]+' )
        {
            $files = array();

            foreach ( glob( $this->upload_dir . '*.json' ) ?: array() as $file ) {
                if ( preg_match( '/^' . $locale . self::NAME . '$/', basename( $file ) ) ) {
                    $files[] = $file;
                }
            }

            return $files;
        }

        protected function ensure_htaccess()
        {
            $path   = $this->upload_dir . '.htaccess';
            $rules  = "<IfModule mod_headers.c>\n";
            $rules .= "<FilesMatch \"^[A-Za-z0-9_-]+" . self::NAME . "$\">\n";
            $rules .= "  Header set Cache-Control \"public, max-age=31536000, immutable\"\n";
            $rules .= "</FilesMatch>\n";
            $rules .= "</IfModule>\n";
            if ( file_exists( $path ) && file_get_contents( $path ) === $rules ) {
                return;
            }
            @file_put_contents( $path, $rules );
        }

        public function delete_manifests()
        {
            if ( ! is_dir( $this->upload_dir ) ) {
                return;
            }
            foreach ( $this->files() as $file ) {
                wp_delete_file( $file );
            }
        }

        protected function build_manifest( $packs, $locale )
        {
            $manifest = array(
                'version'      => 1,
                'locale'       => $locale,
                'generated_at' => time(),
                'packs'        => array(),
            );

            foreach ( $packs as $pack ) {
                if ( empty( $pack['enabled'] ) ) {
                    continue;
                }
                if ( empty( $pack['stickers'] ) ) {
                    continue;
                }

                $manifest['packs'][] = $this->localize_pack( $pack, $locale );
            }

            return $manifest;
        }

        protected function pick_translation( $translations, $locale )
        {
            if ( ! is_array( $translations ) || empty( $translations ) ) {
                return null;
            }

            if ( isset( $translations[ $locale ] ) && is_array( $translations[ $locale ] ) ) {
                return $translations[ $locale ];
            }

            $target = strtolower( str_replace( '_', '-', $locale ) );
            foreach ( $translations as $key => $value ) {
                if ( ! is_array( $value ) ) {
                    continue;
                }
                if ( strtolower( str_replace( '_', '-', $key ) ) === $target ) {
                    return $value;
                }
            }

            $dash_pos = strpos( $target, '-' );
            if ( $dash_pos !== false ) {
                $base = substr( $target, 0, $dash_pos );
                foreach ( $translations as $key => $value ) {
                    if ( ! is_array( $value ) ) {
                        continue;
                    }
                    if ( strtolower( str_replace( '_', '-', $key ) ) === $base ) {
                        return $value;
                    }
                }
            }

            return null;
        }

        protected function localize_pack( $pack, $locale )
        {
            $out = array(
                'id'            => isset( $pack['id'] ) ? $pack['id'] : '',
                'title'         => isset( $pack['title'] ) ? $pack['title'] : '',
                'description'   => isset( $pack['description'] ) ? $pack['description'] : '',
                'cover'         => isset( $pack['cover'] ) ? $this->stickers->rewrite_url( $pack['cover'] ) : '',
                'type'          => isset( $pack['type'] ) ? $pack['type'] : '',
                'sort_order'    => isset( $pack['sort_order'] ) ? (int) $pack['sort_order'] : 0,
                'suggestions'   => isset( $pack['suggestions'] ) ? (bool) $pack['suggestions'] : true,
                'stickers'      => array(),
            );

            $tr = $this->pick_translation( isset( $pack['translations'] ) ? $pack['translations'] : array(), $locale );
            if ( $tr ) {
                if ( ! empty( $tr['title'] ) ) {
                    $out['title'] = $tr['title'];
                }
                if ( ! empty( $tr['description'] ) ) {
                    $out['description'] = $tr['description'];
                }
                if ( ! empty( $tr['cover'] ) ) {
                    $out['cover'] = $this->stickers->rewrite_url( $tr['cover'] );
                }
            }

            $stickers = isset( $pack['stickers'] ) && is_array( $pack['stickers'] ) ? $pack['stickers'] : array();
            foreach ( $stickers as $sticker ) {
                $out['stickers'][] = $this->localize_sticker( $sticker, $locale, $out['id'] );
            }

            return $out;
        }

        protected function localize_sticker( $sticker, $locale, $pack_id = '' )
        {
            $sticker_id = isset( $sticker['id'] ) ? $sticker['id'] : '';
            if ( $pack_id !== '' && $sticker_id !== '' ) {
                $sticker_id = $pack_id . '/' . $sticker_id;
            }

            $out = array(
                'id'       => $sticker_id,
                'name'     => isset( $sticker['name'] ) ? $sticker['name'] : '',
                'file'     => isset( $sticker['file'] ) ? $this->stickers->rewrite_url( $sticker['file'] ) : '',
                'width'    => isset( $sticker['width'] ) ? (int) $sticker['width'] : 0,
                'height'   => isset( $sticker['height'] ) ? (int) $sticker['height'] : 0,
                'keywords' => isset( $sticker['keywords'] ) && is_array( $sticker['keywords'] ) ? array_values( $sticker['keywords'] ) : array(),
            );

            $tr = $this->pick_translation( isset( $sticker['translations'] ) ? $sticker['translations'] : array(), $locale );
            if ( $tr ) {
                if ( ! empty( $tr['name'] ) ) {
                    $out['name'] = $tr['name'];
                }
                if ( isset( $tr['keywords'] ) && is_array( $tr['keywords'] ) && ! empty( $tr['keywords'] ) ) {
                    $out['keywords'] = array_values( $tr['keywords'] );
                }
                if ( ! empty( $tr['file'] ) ) {
                    $out['file'] = $this->stickers->rewrite_url( $tr['file'] );
                    if ( isset( $tr['width'] ) ) {
                        $out['width'] = (int) $tr['width'];
                    }
                    if ( isset( $tr['height'] ) ) {
                        $out['height'] = (int) $tr['height'];
                    }
                }
            }

            return $out;
        }
    }
}
