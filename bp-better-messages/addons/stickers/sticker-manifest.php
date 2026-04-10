<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Sticker_Manifest' ) ) {
    /**
     * Generates per-locale sticker manifest JSON files in
     *   wp-content/uploads/better-messages/stickers/bm-stickers-{locale}-{hash}.json
     *
     * Mirrors the translations.php caching pattern. Files are regenerated
     * lazily on first fetch per-locale. The current hash is stored in the
     * main settings option (`stickerPacksHash`) so the URL can be computed
     * on every page load without reading the big packs option.
     */
    class Better_Messages_Sticker_Manifest
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
            $this->upload_dir = $upload['basedir'] . '/better-messages/stickers/';
            $this->upload_url = $upload['baseurl'] . '/better-messages/stickers/';
        }

        /**
         * Resolve the URL to the manifest file for the given locale.
         * Generates the file on first access.
         *
         * @param string|null $locale
         * @return string|false
         */
        public function get_manifest_url( $locale = null )
        {
            if ( ! $locale ) {
                $locale = determine_locale();
            }

            $hash      = Better_Messages_Sticker_Pack_Manager::instance()->get_hash();
            $file_name = 'bm-stickers-' . $locale . '-' . $hash . '.json';
            $file_path = $this->upload_dir . $file_name;
            $file_url  = $this->upload_url . $file_name;

            if ( file_exists( $file_path ) ) {
                return $file_url;
            }

            $packs = Better_Messages_Sticker_Pack_Manager::instance()->get_all();

            // No packs → no manifest. Frontend will show the empty state.
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

            // Clean old versions for this locale.
            $old_pattern = $this->upload_dir . 'bm-stickers-' . $locale . '-*.json';
            foreach ( glob( $old_pattern ) as $old_file ) {
                if ( $old_file !== $file_path ) {
                    @unlink( $old_file );
                }
            }

            return $file_url;
        }

        /**
         * Delete ALL cached manifest files. Called when packs change so that
         * the next frontend request for any locale regenerates from scratch.
         */
        public function delete_manifests()
        {
            if ( ! is_dir( $this->upload_dir ) ) {
                return;
            }
            $files = glob( $this->upload_dir . 'bm-stickers-*.json' );
            if ( $files ) {
                foreach ( $files as $file ) {
                    @unlink( $file );
                }
            }
        }

        /**
         * Build the manifest payload for the given locale.
         * Applies per-pack and per-sticker translations, falling back to primary strings.
         *
         * @return array
         */
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

        /**
         * Given a WordPress locale and a translations dict, return the best-
         * matching entry. Matches are intentionally forgiving because the
         * plugin accepts several locale spellings side-by-side:
         *
         *   - Catalog installer writes short codes (`en`, `es`) and BCP47
         *     kebab-case codes (`pt-br`, `zh-hant`) as shipped by the remote
         *     manifest.
         *   - Admin-authored translations come from the WordPress locale
         *     picker and use the full underscore form (`en_US`, `pt_BR`).
         *
         * Lookup order:
         *   1. Exact match on the requested locale (backward compat).
         *   2. Case-insensitive match after normalizing both sides to
         *      lowercase + hyphen separator (so `pt_BR` finds `pt-br` and
         *      vice versa).
         *   3. Fallback to the base language code (`pt_BR` → `pt`), again
         *      matched case-insensitively.
         *
         * @return array|null
         */
        protected function pick_translation( $translations, $locale )
        {
            if ( ! is_array( $translations ) || empty( $translations ) ) {
                return null;
            }

            // 1. Exact match.
            if ( isset( $translations[ $locale ] ) && is_array( $translations[ $locale ] ) ) {
                return $translations[ $locale ];
            }

            // 2. Case + separator insensitive match.
            $target = strtolower( str_replace( '_', '-', $locale ) );
            foreach ( $translations as $key => $value ) {
                if ( ! is_array( $value ) ) {
                    continue;
                }
                if ( strtolower( str_replace( '_', '-', $key ) ) === $target ) {
                    return $value;
                }
            }

            // 3. Base language fallback.
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

        /**
         * Apply translations for a single pack and its stickers.
         * Strips the raw `translations` field from output.
         */
        protected function localize_pack( $pack, $locale )
        {
            $out = array(
                'id'            => isset( $pack['id'] ) ? $pack['id'] : '',
                'title'         => isset( $pack['title'] ) ? $pack['title'] : '',
                'description'   => isset( $pack['description'] ) ? $pack['description'] : '',
                'cover'         => isset( $pack['cover'] ) ? $pack['cover'] : '',
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
                // Per-locale cover override (for packs where the cover image
                // itself is a language-specific version).
                if ( ! empty( $tr['cover'] ) ) {
                    $out['cover'] = $tr['cover'];
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
                'file'     => isset( $sticker['file'] ) ? $sticker['file'] : '',
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
                // When a translation has its own localized image (packs where the
                // image itself contains text, like "HELLO" → "HOLA"), use it in
                // place of the primary sticker's file. Dimensions follow suit.
                if ( ! empty( $tr['file'] ) ) {
                    $out['file'] = $tr['file'];
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

if ( ! function_exists( 'Better_Messages_Sticker_Manifest' ) ) {
    function Better_Messages_Sticker_Manifest()
    {
        return Better_Messages_Sticker_Manifest::instance();
    }
}
