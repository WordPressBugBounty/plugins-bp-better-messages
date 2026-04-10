<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Sticker_Catalog_Client' ) ) {
    /**
     * Fetches the sticker catalog from better-messages.com and installs packs locally.
     *
     * Catalog layout (static files at the remote host):
     *
     *   {BASE}/manifest.json                   — top-level index of all available packs
     *   {BASE}/{pack-id}/{pack-id}-{version}.zip      — downloadable pack archive
     *   {BASE}/{pack-id}/optimized/pack.json    — per-pack manifest inside the zip (also at this URL for previews)
     *
     * The manifest is fetched fresh on every admin page load so new packs and
     * version bumps show up immediately.
     */
    class Better_Messages_Sticker_Catalog_Client
    {
        const CATALOG_URL      = 'https://better-messages.com/stickers/manifest.json';
        const CATALOG_BASE_URL = 'https://better-messages.com/stickers/';

        private static $instance = null;

        public static function instance()
        {
            if ( self::$instance === null ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Return the base URL for the catalog (filterable for self-hosted testing).
         */
        public function get_catalog_url()
        {
            return apply_filters( 'better_messages_sticker_catalog_url', self::CATALOG_URL );
        }

        public function get_catalog_base_url()
        {
            return apply_filters( 'better_messages_sticker_catalog_base_url', self::CATALOG_BASE_URL );
        }

        /**
         * Fetch the top-level catalog manifest.
         *
         * @return array|WP_Error
         */
        public function fetch_catalog()
        {
            $response = wp_remote_get( $this->get_catalog_url(), array(
                'timeout' => 8,
                'headers' => array( 'Accept' => 'application/json' ),
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                return new WP_Error(
                    'catalog_http_error',
                    sprintf( __( 'Failed to fetch sticker catalog (HTTP %d).', 'bp-better-messages' ), $code )
                );
            }

            $body = wp_remote_retrieve_body( $response );
            $data = json_decode( $body, true );

            if ( ! is_array( $data ) || ! isset( $data['packs'] ) || ! is_array( $data['packs'] ) ) {
                return new WP_Error( 'catalog_invalid', __( 'Invalid sticker catalog response.', 'bp-better-messages' ) );
            }

            return $data;
        }

        /**
         * Install (or update) a pack from the remote catalog by its remote id.
         *
         * The remote manifest lists a pack as a collection of language variants
         * (`variants[]`). Each variant is a downloadable zip containing a full
         * `pack.json` plus images for that specific image-language. The admin
         * chooses which image languages to import; all selected variants are
         * merged into a SINGLE local pack where:
         *
         *   - the first selected language becomes the primary (its strings and
         *     images populate the top-level fields of each sticker), and
         *   - every other selected language is folded into the per-sticker
         *     `translations` dict with its own name/keywords AND its own
         *     file/width/height (image overrides), so the manifest generator
         *     can swap the displayed image based on the viewer's locale.
         *
         * Label-only translations (multi-language labels for universal images)
         * are also picked up from the variants' `labels` dicts and merged into
         * the pack/sticker translations — so "same image, different language"
         * packs just work from a single variant download.
         *
         * @param string        $remote_id
         * @param array|string  $languages  Language codes to import. Defaults
         *                                  to the first variant if empty.
         * @return array|WP_Error The created/updated pack
         */
        public function install( $remote_id, $languages = array() )
        {
            if ( ! class_exists( 'ZipArchive' ) ) {
                return new WP_Error( 'zip_unavailable', __( 'PHP ZipArchive extension is required to install sticker packs.', 'bp-better-messages' ) );
            }

            $catalog = $this->fetch_catalog();
            if ( is_wp_error( $catalog ) ) {
                return $catalog;
            }

            // Normalize languages argument.
            if ( is_string( $languages ) ) {
                $languages = $languages === '' ? array() : array( $languages );
            }
            if ( ! is_array( $languages ) ) {
                $languages = array();
            }
            $languages = array_values( array_unique( array_map( 'sanitize_text_field', $languages ) ) );

            // Find the remote pack in the manifest.
            $remote_pack = null;
            foreach ( $catalog['packs'] as $p ) {
                if ( isset( $p['id'] ) && $p['id'] === $remote_id ) {
                    $remote_pack = $p;
                    break;
                }
            }
            if ( ! $remote_pack || empty( $remote_pack['variants'] ) || ! is_array( $remote_pack['variants'] ) ) {
                return new WP_Error( 'pack_not_in_catalog', __( 'This sticker pack is not in the catalog.', 'bp-better-messages' ) );
            }

            // Index variants by language for easy lookup.
            $variants_by_lang = array();
            foreach ( $remote_pack['variants'] as $variant ) {
                if ( ! empty( $variant['language'] ) ) {
                    $variants_by_lang[ $variant['language'] ] = $variant;
                }
            }
            if ( empty( $variants_by_lang ) ) {
                return new WP_Error( 'no_variants', __( 'Sticker pack has no downloadable variants.', 'bp-better-messages' ) );
            }

            // If no languages were requested, default to the first variant.
            if ( empty( $languages ) ) {
                $first_variant = $remote_pack['variants'][0];
                $languages = array( $first_variant['language'] );
            }

            // Filter to languages that actually exist in the manifest, preserving order.
            $selected_languages = array();
            foreach ( $languages as $lang ) {
                if ( isset( $variants_by_lang[ $lang ] ) && ! in_array( $lang, $selected_languages, true ) ) {
                    $selected_languages[] = $lang;
                }
            }

            // Universal-image packs ship a single variant with the sentinel
            // language "universal" — its labels dict then carries all the
            // real language codes. If the admin requested specific languages
            // that aren't in the manifest but the pack has a universal
            // variant, silently use it: one download covers every language.
            if ( empty( $selected_languages ) && isset( $variants_by_lang['universal'] ) ) {
                $selected_languages[] = 'universal';
            }

            if ( empty( $selected_languages ) ) {
                return new WP_Error( 'no_valid_variants', __( 'None of the requested languages are available for this pack.', 'bp-better-messages' ) );
            }

            $primary_language = $selected_languages[0];

            // download_url() lives in wp-admin/includes/file.php which is not
            // loaded on REST requests.
            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }

            $upload   = wp_upload_dir();
            $tmp_root = trailingslashit( $upload['basedir'] ) . 'better-messages/stickers/_tmp/' . wp_generate_password( 12, false ) . '/';
            wp_mkdir_p( $tmp_root );

            // Download + extract every selected variant up front so we can
            // fail atomically before touching the live packs option.
            $variant_packs = array(); // [lang => pack.json array]
            $variant_dirs  = array(); // [lang => extracted dir containing pack.json]
            foreach ( $selected_languages as $lang ) {
                $variant = $variants_by_lang[ $lang ];
                if ( empty( $variant['downloadUrl'] ) ) {
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'variant_missing_download', sprintf( __( 'Variant "%s" has no download URL.', 'bp-better-messages' ), $lang ) );
                }

                $download_url = $this->resolve_url( $variant['downloadUrl'] );
                $tmp_zip      = download_url( $download_url, 30 );
                if ( is_wp_error( $tmp_zip ) ) {
                    $this->rmrf( $tmp_root );
                    return $tmp_zip;
                }

                $zip = new ZipArchive();
                if ( $zip->open( $tmp_zip ) !== true ) {
                    @unlink( $tmp_zip );
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'zip_open_failed', sprintf( __( 'Could not open the sticker pack archive for "%s".', 'bp-better-messages' ), $lang ) );
                }

                $lang_dir = $tmp_root . sanitize_file_name( $lang ) . '/';
                wp_mkdir_p( $lang_dir );
                if ( ! $zip->extractTo( $lang_dir ) ) {
                    $zip->close();
                    @unlink( $tmp_zip );
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'zip_extract_failed', sprintf( __( 'Could not extract the sticker pack archive for "%s".', 'bp-better-messages' ), $lang ) );
                }
                $zip->close();
                @unlink( $tmp_zip );

                $pack_json_path = $this->find_pack_json( $lang_dir );
                if ( ! $pack_json_path ) {
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'pack_json_missing', sprintf( __( 'Sticker pack archive for "%s" is missing pack.json.', 'bp-better-messages' ), $lang ) );
                }

                $pack_data = json_decode( file_get_contents( $pack_json_path ), true );
                if ( ! is_array( $pack_data ) ) {
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'pack_json_invalid', sprintf( __( 'Sticker pack archive for "%s" contains an invalid pack.json.', 'bp-better-messages' ), $lang ) );
                }

                $variant_packs[ $lang ] = $pack_data;
                $variant_dirs[ $lang ]  = dirname( $pack_json_path );
            }

            // Existing installed pack with the same remote id → update flow.
            $existing = $this->find_by_remote_id( $remote_id );

            // Blow away old assets for the pack. For updates we always
            // replace the full image set across all languages because the
            // source versions may have changed file names / dimensions.
            if ( $existing ) {
                Better_Messages_Sticker_Pack_Manager::instance()->delete_pack_assets( $existing['id'] );
            }

            $local_id     = $existing ? $existing['id'] : sanitize_title( $remote_id );
            $primary_pack = $variant_packs[ $primary_language ];
            $primary_dir  = $variant_dirs[ $primary_language ];
            $primary_dirs = Better_Messages_Sticker_Pack_Manager::instance()->get_upload_dir( $local_id );

            // Universal packs ship one variant whose `language` is the
            // sentinel "universal" while their labels dict contains several
            // real language codes (en, es, pt-br, …). We still use the
            // "universal" variant as the image source, but we pick a real
            // language from its labels dict to act as the top-level primary
            // for display purposes (title/description, default sticker
            // name/keywords). English is preferred when present; otherwise
            // the first key in the labels dict wins.
            $primary_label_language = $primary_language;
            $primary_labels         = array();
            if ( isset( $primary_pack['labels'] ) && is_array( $primary_pack['labels'] ) ) {
                if ( isset( $primary_pack['labels'][ $primary_language ] ) && is_array( $primary_pack['labels'][ $primary_language ] ) ) {
                    $primary_labels = $primary_pack['labels'][ $primary_language ];
                } else {
                    $label_keys = array_keys( $primary_pack['labels'] );
                    if ( in_array( 'en', $label_keys, true ) ) {
                        $primary_label_language = 'en';
                    } elseif ( ! empty( $label_keys ) ) {
                        $primary_label_language = $label_keys[0];
                    }
                    if ( isset( $primary_pack['labels'][ $primary_label_language ] ) && is_array( $primary_pack['labels'][ $primary_label_language ] ) ) {
                        $primary_labels = $primary_pack['labels'][ $primary_label_language ];
                    }
                }
            }

            // ---- Pack-level title/description + translations ----
            $pack_translations = array();

            // Walk ALL variants' pack-level labels dicts — a variant may carry
            // labels for several languages at once (Scenario B: universal
            // images, translated labels). We skip whichever language is
            // serving as the top-level primary so its strings don't end up
            // duplicated inside the translations dict.
            foreach ( $variant_packs as $lang => $vp ) {
                if ( empty( $vp['labels'] ) || ! is_array( $vp['labels'] ) ) {
                    continue;
                }
                foreach ( $vp['labels'] as $lbl_lang => $lbl ) {
                    if ( $lbl_lang === $primary_label_language ) {
                        continue;
                    }
                    if ( ! isset( $pack_translations[ $lbl_lang ] ) ) {
                        $pack_translations[ $lbl_lang ] = array();
                    }
                    if ( ! empty( $lbl['name'] ) ) {
                        $pack_translations[ $lbl_lang ]['title'] = $lbl['name'];
                    }
                    if ( ! empty( $lbl['description'] ) ) {
                        $pack_translations[ $lbl_lang ]['description'] = $lbl['description'];
                    }
                }
            }

            // ---- Cover from the primary variant ----
            $cover_url  = '';
            $cover_file = isset( $primary_pack['cover'] ) ? $primary_pack['cover'] : '';
            if ( $cover_file ) {
                $cover_src = $primary_dir . '/' . basename( $cover_file );
                if ( file_exists( $cover_src ) ) {
                    $cover_dest = $primary_dirs['path'] . 'cover.' . pathinfo( $cover_file, PATHINFO_EXTENSION );
                    if ( copy( $cover_src, $cover_dest ) ) {
                        $cover_url = $primary_dirs['url'] . basename( $cover_dest );
                    }
                }
            }

            // ---- Covers from every other variant → per-locale overrides ----
            // Packs like `reactions` where the cover image contains language-
            // specific text ("LOL" vs "JA JA") ship a distinct cover per image
            // variant. Copy each one into packs/{id}/{lang}/cover.<ext> and
            // store the URL on pack.translations[lang].cover so the manifest
            // generator can swap the cover when serving that locale.
            foreach ( $selected_languages as $lang ) {
                if ( $lang === $primary_language ) {
                    continue;
                }
                $vp       = $variant_packs[ $lang ];
                $pack_dir = $variant_dirs[ $lang ];
                $variant_cover_file = isset( $vp['cover'] ) ? $vp['cover'] : '';
                if ( empty( $variant_cover_file ) ) {
                    continue;
                }
                $cover_src = $pack_dir . '/' . basename( $variant_cover_file );
                if ( ! file_exists( $cover_src ) ) {
                    continue;
                }

                $lang_dirs  = Better_Messages_Sticker_Pack_Manager::instance()->get_upload_dir( $local_id, $lang );
                $cover_dest = $lang_dirs['path'] . 'cover.' . pathinfo( $variant_cover_file, PATHINFO_EXTENSION );
                if ( ! copy( $cover_src, $cover_dest ) ) {
                    continue;
                }

                if ( ! isset( $pack_translations[ $lang ] ) ) {
                    $pack_translations[ $lang ] = array();
                }
                $pack_translations[ $lang ]['cover'] = $lang_dirs['url'] . basename( $cover_dest );
            }

            // ---- Stickers ----
            // Key stickers by id so we can merge per-language data across variants.
            $stickers_by_id = array();

            // Pass 1: primary variant → populate top-level fields for each sticker.
            if ( isset( $primary_pack['stickers'] ) && is_array( $primary_pack['stickers'] ) ) {
                foreach ( $primary_pack['stickers'] as $sticker ) {
                    if ( empty( $sticker['id'] ) || empty( $sticker['file'] ) ) {
                        continue;
                    }
                    $sid = sanitize_title( $sticker['id'] );
                    $src = $primary_dir . '/' . basename( $sticker['file'] );
                    if ( ! file_exists( $src ) ) {
                        continue;
                    }
                    $dest_name = basename( $sticker['file'] );
                    $dest_path = $primary_dirs['path'] . $dest_name;
                    if ( ! copy( $src, $dest_path ) ) {
                        continue;
                    }

                    // Resolve the primary label using the effective primary
                    // language (which may differ from the variant language
                    // for universal packs).
                    $primary_label = isset( $sticker['labels'][ $primary_label_language ] ) && is_array( $sticker['labels'][ $primary_label_language ] )
                        ? $sticker['labels'][ $primary_label_language ]
                        : array();

                    $stickers_by_id[ $sid ] = array(
                        'id'           => $sid,
                        'name'         => isset( $primary_label['name'] ) ? $primary_label['name'] : '',
                        'file'         => $primary_dirs['url'] . rawurlencode( $dest_name ),
                        'width'        => isset( $sticker['width'] ) ? (int) $sticker['width'] : 0,
                        'height'       => isset( $sticker['height'] ) ? (int) $sticker['height'] : 0,
                        'keywords'     => ( isset( $primary_label['keywords'] ) && is_array( $primary_label['keywords'] ) )
                            ? array_values( $primary_label['keywords'] )
                            : array(),
                        'translations' => array(),
                    );

                    // Collect any additional label languages shipped inside
                    // the primary variant itself (label-only translations).
                    if ( isset( $sticker['labels'] ) && is_array( $sticker['labels'] ) ) {
                        foreach ( $sticker['labels'] as $lbl_lang => $lbl ) {
                            if ( $lbl_lang === $primary_label_language || ! is_array( $lbl ) ) {
                                continue;
                            }
                            $entry = array();
                            if ( ! empty( $lbl['name'] ) ) {
                                $entry['name'] = $lbl['name'];
                            }
                            if ( ! empty( $lbl['keywords'] ) && is_array( $lbl['keywords'] ) ) {
                                $entry['keywords'] = array_values( $lbl['keywords'] );
                            }
                            if ( ! empty( $entry ) ) {
                                $stickers_by_id[ $sid ]['translations'][ $lbl_lang ] = $entry;
                            }
                        }
                    }
                }
            }

            // Pass 2: every other selected variant → images + labels become
            // per-locale translations. Files live in a per-language subfolder
            // so they never collide with the primary filenames.
            foreach ( $selected_languages as $lang ) {
                if ( $lang === $primary_language ) {
                    continue;
                }
                $vp       = $variant_packs[ $lang ];
                $pack_dir = $variant_dirs[ $lang ];
                $lang_dirs = Better_Messages_Sticker_Pack_Manager::instance()->get_upload_dir( $local_id, $lang );

                if ( ! isset( $vp['stickers'] ) || ! is_array( $vp['stickers'] ) ) {
                    continue;
                }
                foreach ( $vp['stickers'] as $sticker ) {
                    if ( empty( $sticker['id'] ) ) {
                        continue;
                    }
                    $sid = sanitize_title( $sticker['id'] );

                    // Only keep translations for stickers that exist in the
                    // primary variant — otherwise an orphan id has no image
                    // to fall back to in the manifest.
                    if ( ! isset( $stickers_by_id[ $sid ] ) ) {
                        continue;
                    }

                    $entry = isset( $stickers_by_id[ $sid ]['translations'][ $lang ] )
                        ? $stickers_by_id[ $sid ]['translations'][ $lang ]
                        : array();

                    // Localized image override.
                    if ( ! empty( $sticker['file'] ) ) {
                        $src = $pack_dir . '/' . basename( $sticker['file'] );
                        if ( file_exists( $src ) ) {
                            $dest_name = basename( $sticker['file'] );
                            $dest_path = $lang_dirs['path'] . $dest_name;
                            if ( copy( $src, $dest_path ) ) {
                                $entry['file']   = $lang_dirs['url'] . rawurlencode( $dest_name );
                                $entry['width']  = isset( $sticker['width'] ) ? (int) $sticker['width'] : 0;
                                $entry['height'] = isset( $sticker['height'] ) ? (int) $sticker['height'] : 0;
                            }
                        }
                    }

                    // Localized labels for the variant's own language.
                    if ( isset( $sticker['labels'][ $lang ] ) && is_array( $sticker['labels'][ $lang ] ) ) {
                        $lbl = $sticker['labels'][ $lang ];
                        if ( ! empty( $lbl['name'] ) ) {
                            $entry['name'] = $lbl['name'];
                        }
                        if ( ! empty( $lbl['keywords'] ) && is_array( $lbl['keywords'] ) ) {
                            $entry['keywords'] = array_values( $lbl['keywords'] );
                        }
                    }

                    if ( ! empty( $entry ) ) {
                        $stickers_by_id[ $sid ]['translations'][ $lang ] = $entry;
                    }

                    // This variant may also carry labels for OTHER languages
                    // (e.g. Spanish variant shipping Italian labels). Fold
                    // them in without touching file/width/height.
                    if ( isset( $sticker['labels'] ) && is_array( $sticker['labels'] ) ) {
                        foreach ( $sticker['labels'] as $lbl_lang => $lbl ) {
                            if ( $lbl_lang === $lang || $lbl_lang === $primary_label_language || ! is_array( $lbl ) ) {
                                continue;
                            }
                            $other = isset( $stickers_by_id[ $sid ]['translations'][ $lbl_lang ] )
                                ? $stickers_by_id[ $sid ]['translations'][ $lbl_lang ]
                                : array();
                            if ( ! empty( $lbl['name'] ) ) {
                                $other['name'] = $lbl['name'];
                            }
                            if ( ! empty( $lbl['keywords'] ) && is_array( $lbl['keywords'] ) ) {
                                $other['keywords'] = array_values( $lbl['keywords'] );
                            }
                            if ( ! empty( $other ) ) {
                                $stickers_by_id[ $sid ]['translations'][ $lbl_lang ] = $other;
                            }
                        }
                    }
                }
            }

            $this->rmrf( $tmp_root );

            // Preserve the manifest ordering of stickers.
            $stickers_out = array();
            if ( isset( $primary_pack['stickers'] ) && is_array( $primary_pack['stickers'] ) ) {
                foreach ( $primary_pack['stickers'] as $sticker ) {
                    if ( empty( $sticker['id'] ) ) {
                        continue;
                    }
                    $sid = sanitize_title( $sticker['id'] );
                    if ( isset( $stickers_by_id[ $sid ] ) ) {
                        $stickers_out[] = $stickers_by_id[ $sid ];
                    }
                }
            }

            // Version comes from the primary variant's pack.json, with a
            // fallback to the manifest's variant entry.
            $version = isset( $primary_pack['version'] )
                ? $primary_pack['version']
                : ( isset( $variants_by_lang[ $primary_language ]['version'] ) ? $variants_by_lang[ $primary_language ]['version'] : '' );

            $new_pack = array(
                'id'            => $local_id,
                'source'        => 'catalog',
                'remote_id'     => $remote_id,
                // Store the effective label language so the installed-languages
                // badge and any per-locale lookups have a real code to work
                // with. For non-universal packs this equals $primary_language.
                // For universal packs it's the label key we promoted to
                // primary (usually "en").
                'language'      => $primary_label_language,
                'version'       => $version,
                'type'          => isset( $primary_pack['type'] ) ? $primary_pack['type'] : ( isset( $remote_pack['type'] ) ? $remote_pack['type'] : '' ),
                'title'         => isset( $primary_labels['name'] ) ? $primary_labels['name'] : $remote_id,
                'description'   => isset( $primary_labels['description'] ) ? $primary_labels['description'] : '',
                'cover'         => $cover_url,
                'enabled'       => true,
                'allowed_roles' => $existing ? ( isset( $existing['allowed_roles'] ) ? $existing['allowed_roles'] : array() ) : array(),
                'translations'  => $pack_translations,
                'stickers'      => $stickers_out,
            );

            if ( $existing ) {
                // Preserve admin customizations on metadata but refresh content.
                $new_pack['sort_order'] = isset( $existing['sort_order'] ) ? $existing['sort_order'] : 0;
                $new_pack['enabled']    = isset( $existing['enabled'] ) ? $existing['enabled'] : true;
                return Better_Messages_Sticker_Pack_Manager::instance()->update( $existing['id'], $new_pack );
            }

            return Better_Messages_Sticker_Pack_Manager::instance()->create( $new_pack );
        }

        /**
         * Find an installed pack by its remote id.
         */
        public function find_by_remote_id( $remote_id )
        {
            foreach ( Better_Messages_Sticker_Pack_Manager::instance()->get_all() as $pack ) {
                if ( isset( $pack['source'] ) && $pack['source'] === 'catalog'
                    && isset( $pack['remote_id'] ) && $pack['remote_id'] === $remote_id ) {
                    return $pack;
                }
            }
            return null;
        }

        /**
         * Resolve a potentially-relative URL against the catalog base URL.
         */
        protected function resolve_url( $url )
        {
            if ( empty( $url ) ) {
                return '';
            }
            if ( strpos( $url, 'http://' ) === 0 || strpos( $url, 'https://' ) === 0 ) {
                return $url;
            }
            return $this->get_catalog_base_url() . ltrim( $url, '/' );
        }

        /**
         * Locate the pack.json file inside an extracted archive.
         * Supports both flat layouts and Telegram-style subfolders.
         */
        protected function find_pack_json( $base )
        {
            $direct = $base . 'pack.json';
            if ( file_exists( $direct ) ) {
                return $direct;
            }

            $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, RecursiveDirectoryIterator::SKIP_DOTS ) );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() && $file->getFilename() === 'pack.json' ) {
                    return $file->getPathname();
                }
            }
            return null;
        }

        protected function rmrf( $dir )
        {
            if ( ! is_dir( $dir ) ) {
                return;
            }
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) {
                    @rmdir( $file->getPathname() );
                } else {
                    @unlink( $file->getPathname() );
                }
            }
            @rmdir( $dir );
        }
    }
}

if ( ! function_exists( 'Better_Messages_Sticker_Catalog_Client' ) ) {
    function Better_Messages_Sticker_Catalog_Client()
    {
        return Better_Messages_Sticker_Catalog_Client::instance();
    }
}
