<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Sticker_Pack_Manager' ) ) {
    /**
     * CRUD for sticker packs stored in wp_options.
     *
     * Storage layout:
     *   option 'better_messages_sticker_packs' (autoload=false) — array of pack arrays
     *   option 'bp-better-chat-settings' ['stickerPacksHash'] (autoload=true) — md5 of the packs blob for cache busting
     *
     * Pack shape:
     *   [
     *     'id'            => 'reactions',       // stable slug (immutable)
     *     'source'        => 'custom'|'catalog',
     *     'remote_id'     => 'reactions',       // for catalog packs only
     *     'language'      => 'en',              // catalog image-language variant this pack came from
     *     'version'       => '1.0.0',
     *     'type'          => 'reaction',        // taxonomy
     *     'title'         => 'Reactions',
     *     'description'   => '...',
     *     'cover'         => 'https://.../cover.png',
     *     'enabled'       => true,
     *     'sort_order'    => 0,
     *     'suggestions'   => true,              // whether this pack is included in inline sticker suggestions
     *     'allowed_roles' => [],                // empty = everyone
     *     'created_at'    => 1712670000,
     *     'updated_at'    => 1712670000,
     *     'translations'  => [ 'ru_RU' => [ 'title' => '...', 'description' => '...' ], ... ],
     *     'stickers'      => [
     *       [
     *         'id' => '01-hi', 'name' => 'Hi', 'file' => 'https://...', 'width' => 768, 'height' => 768,
     *         'keywords' => ['hi','hello'],
     *         'translations' => [
     *           // Label-only translation: same image, localized name/keywords.
     *           'ru_RU' => [ 'name' => 'Привет', 'keywords' => [...] ],
     *           // Language with a localized *image*: file/width/height override the
     *           // primary sticker's image when the picker is rendered in that locale.
     *           'es'    => [ 'name' => 'Hola', 'keywords' => [...], 'file' => 'https://.../es/01-hi.png', 'width' => 704, 'height' => 376 ],
     *         ],
     *       ],
     *       ...
     *     ],
     *   ]
     */
    class Better_Messages_Sticker_Pack_Manager
    {
        const OPTION_KEY   = 'better_messages_sticker_packs';
        const HASH_KEY     = 'stickerPacksHash';
        const SUMMARY_KEY  = 'stickerPacksSummary';

        private static $instance = null;
        private $packs_cache     = null;

        public static function instance()
        {
            if ( self::$instance === null ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Return all packs as stored (including translations and full sticker data).
         *
         * @return array
         */
        public function get_all()
        {
            if ( $this->packs_cache !== null ) {
                return $this->packs_cache;
            }
            $packs = get_option( self::OPTION_KEY, array() );
            if ( ! is_array( $packs ) ) {
                $packs = array();
            }
            // Ensure consistent sort order.
            usort( $packs, function( $a, $b ) {
                $oa = isset( $a['sort_order'] ) ? (int) $a['sort_order'] : 0;
                $ob = isset( $b['sort_order'] ) ? (int) $b['sort_order'] : 0;
                if ( $oa === $ob ) {
                    return strcmp( isset( $a['id'] ) ? $a['id'] : '', isset( $b['id'] ) ? $b['id'] : '' );
                }
                return $oa - $ob;
            } );
            $this->packs_cache = $packs;
            return $packs;
        }

        /**
         * Get a single pack by id.
         *
         * @param string $id
         * @return array|null
         */
        public function get( $id )
        {
            foreach ( $this->get_all() as $pack ) {
                if ( isset( $pack['id'] ) && $pack['id'] === $id ) {
                    return $pack;
                }
            }
            return null;
        }

        /**
         * Check if a pack with the given id exists.
         */
        public function exists( $id )
        {
            return $this->get( $id ) !== null;
        }

        /**
         * Create a new pack.
         *
         * @param array $data
         * @return array|WP_Error The created pack or an error.
         */
        public function create( $data )
        {
            $pack = $this->normalize_pack( $data );

            if ( empty( $pack['id'] ) ) {
                $pack['id'] = $this->generate_unique_id( isset( $data['title'] ) ? $data['title'] : 'pack' );
            } elseif ( $this->exists( $pack['id'] ) ) {
                return new WP_Error( 'pack_exists', __( 'A sticker pack with this id already exists.', 'bp-better-messages' ) );
            }

            if ( empty( $pack['title'] ) ) {
                return new WP_Error( 'pack_missing_title', __( 'Sticker pack title is required.', 'bp-better-messages' ) );
            }

            $packs = $this->get_all();

            // Append at end, highest sort_order + 1.
            $max_order = 0;
            foreach ( $packs as $p ) {
                if ( isset( $p['sort_order'] ) && $p['sort_order'] > $max_order ) {
                    $max_order = $p['sort_order'];
                }
            }
            $pack['sort_order'] = $max_order + 1;
            $pack['created_at'] = time();
            $pack['updated_at'] = time();

            $packs[] = $pack;
            $this->save_all( $packs );

            return $pack;
        }

        /**
         * Update a pack (shallow merge of provided fields).
         *
         * @param string $id
         * @param array  $data
         * @return array|WP_Error
         */
        public function update( $id, $data )
        {
            $packs = $this->get_all();
            $found = false;
            foreach ( $packs as $index => $pack ) {
                if ( $pack['id'] === $id ) {
                    $merged = array_merge( $pack, $this->normalize_pack( $data, false ) );
                    // Never allow id changes.
                    $merged['id']         = $pack['id'];
                    $merged['created_at'] = isset( $pack['created_at'] ) ? $pack['created_at'] : time();
                    $merged['updated_at'] = time();
                    // Preserve stickers array unless explicitly provided.
                    if ( ! array_key_exists( 'stickers', $data ) ) {
                        $merged['stickers'] = isset( $pack['stickers'] ) ? $pack['stickers'] : array();
                    }
                    $packs[ $index ] = $merged;
                    $found           = true;
                    break;
                }
            }

            if ( ! $found ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ) );
            }

            $this->save_all( $packs );
            return $this->get( $id );
        }

        /**
         * Delete a pack by id. Does NOT delete its image files — call the catalog client for that.
         */
        public function delete( $id )
        {
            $packs   = $this->get_all();
            $removed = false;
            $filtered = array();
            foreach ( $packs as $pack ) {
                if ( $pack['id'] === $id ) {
                    $removed = true;
                    continue;
                }
                $filtered[] = $pack;
            }

            if ( ! $removed ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ) );
            }

            $this->save_all( $filtered );
            return true;
        }

        /**
         * Reorder packs. $order is an array of pack ids in the desired order.
         */
        public function reorder( $order )
        {
            if ( ! is_array( $order ) ) {
                return new WP_Error( 'invalid_order', __( 'Invalid order data.', 'bp-better-messages' ) );
            }

            $packs  = $this->get_all();
            $by_id  = array();
            foreach ( $packs as $pack ) {
                $by_id[ $pack['id'] ] = $pack;
            }

            $reordered = array();
            $position  = 0;
            foreach ( $order as $id ) {
                if ( isset( $by_id[ $id ] ) ) {
                    $pack               = $by_id[ $id ];
                    $pack['sort_order'] = $position++;
                    $pack['updated_at'] = time();
                    $reordered[]        = $pack;
                    unset( $by_id[ $id ] );
                }
            }
            // Append any packs not mentioned in $order at the end, preserving relative order.
            foreach ( $by_id as $pack ) {
                $pack['sort_order'] = $position++;
                $reordered[]        = $pack;
            }

            $this->save_all( $reordered );
            return true;
        }

        /**
         * Add a single sticker to an existing pack.
         *
         * @param string $pack_id
         * @param array  $sticker Associative array with at least 'file'
         * @return array|WP_Error The added sticker or an error.
         */
        public function add_sticker( $pack_id, $sticker )
        {
            $pack = $this->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ) );
            }

            $sticker = $this->normalize_sticker( $sticker );

            if ( empty( $sticker['file'] ) ) {
                return new WP_Error( 'sticker_missing_file', __( 'Sticker file is required.', 'bp-better-messages' ) );
            }

            if ( empty( $sticker['id'] ) ) {
                $sticker['id'] = $this->generate_unique_sticker_id( $pack, isset( $sticker['name'] ) ? $sticker['name'] : '' );
            } else {
                // Make sure id is unique within the pack.
                foreach ( $pack['stickers'] as $existing ) {
                    if ( isset( $existing['id'] ) && $existing['id'] === $sticker['id'] ) {
                        $sticker['id'] = $this->generate_unique_sticker_id( $pack, $sticker['id'] );
                        break;
                    }
                }
            }

            if ( ! isset( $sticker['sort_order'] ) ) {
                $sticker['sort_order'] = count( $pack['stickers'] );
            }

            $pack['stickers'][] = $sticker;
            $pack['updated_at'] = time();

            $this->replace_pack( $pack );

            return $sticker;
        }

        /**
         * Remove a sticker from a pack.
         */
        public function remove_sticker( $pack_id, $sticker_id )
        {
            $pack = $this->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ) );
            }

            $filtered = array();
            $removed  = false;
            foreach ( $pack['stickers'] as $sticker ) {
                if ( isset( $sticker['id'] ) && $sticker['id'] === $sticker_id ) {
                    $removed = true;
                    continue;
                }
                $filtered[] = $sticker;
            }

            if ( ! $removed ) {
                return new WP_Error( 'sticker_not_found', __( 'Sticker not found.', 'bp-better-messages' ) );
            }

            $pack['stickers']   = $filtered;
            $pack['updated_at'] = time();
            $this->replace_pack( $pack );

            return true;
        }

        /**
         * Reorder stickers within a pack.
         */
        public function reorder_stickers( $pack_id, $order )
        {
            $pack = $this->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ) );
            }

            $by_id = array();
            foreach ( $pack['stickers'] as $sticker ) {
                $by_id[ $sticker['id'] ] = $sticker;
            }

            $reordered = array();
            $position  = 0;
            foreach ( $order as $id ) {
                if ( isset( $by_id[ $id ] ) ) {
                    $sticker               = $by_id[ $id ];
                    $sticker['sort_order'] = $position++;
                    $reordered[]           = $sticker;
                    unset( $by_id[ $id ] );
                }
            }
            foreach ( $by_id as $sticker ) {
                $sticker['sort_order'] = $position++;
                $reordered[]           = $sticker;
            }

            $pack['stickers']   = $reordered;
            $pack['updated_at'] = time();
            $this->replace_pack( $pack );

            return true;
        }

        /**
         * Returns the upload directory info for sticker assets.
         * Creates it on first call if missing.
         *
         * When `$language` is passed, returns a per-locale subdirectory used
         * for storing translated image variants (e.g. same pack, Spanish text
         * baked into the images).
         *
         * @return array [ 'path' => absolute path, 'url' => public URL ]
         */
        public function get_upload_dir( $pack_id = null, $language = '' )
        {
            $upload = wp_upload_dir();
            $path   = $upload['basedir'] . '/better-messages/stickers/packs';
            $url    = $upload['baseurl'] . '/better-messages/stickers/packs';

            if ( $pack_id ) {
                $path .= '/' . sanitize_file_name( $pack_id );
                $url  .= '/' . rawurlencode( $pack_id );
            }

            if ( ! empty( $language ) ) {
                $lang_slug = sanitize_file_name( $language );
                $path .= '/' . $lang_slug;
                $url  .= '/' . rawurlencode( $lang_slug );
            }

            if ( ! is_dir( $path ) ) {
                wp_mkdir_p( $path );
            }

            return array(
                'path' => trailingslashit( $path ),
                'url'  => trailingslashit( $url ),
            );
        }

        /**
         * Delete a pack's local image folder on disk, including any
         * per-language subfolders created for image translations.
         */
        public function delete_pack_assets( $pack_id )
        {
            $upload = wp_upload_dir();
            $dir    = $upload['basedir'] . '/better-messages/stickers/packs/' . sanitize_file_name( $pack_id );
            if ( ! is_dir( $dir ) ) {
                return;
            }
            $this->rmrf_dir( $dir );
        }

        /**
         * Recursively delete a directory tree.
         */
        protected function rmrf_dir( $dir )
        {
            if ( ! is_dir( $dir ) ) {
                return;
            }
            $items = scandir( $dir );
            if ( ! is_array( $items ) ) {
                @rmdir( $dir );
                return;
            }
            foreach ( $items as $item ) {
                if ( $item === '.' || $item === '..' ) {
                    continue;
                }
                $full = $dir . '/' . $item;
                if ( is_dir( $full ) ) {
                    $this->rmrf_dir( $full );
                } else {
                    @unlink( $full );
                }
            }
            @rmdir( $dir );
        }

        /**
         * Save the entire pack list and recompute the hash in main settings.
         */
        public function save_all( $packs )
        {
            update_option( self::OPTION_KEY, $packs, false );
            $this->packs_cache = $packs;
            $this->update_hash();
            $this->auto_enable_builtin();

            // Trigger manifest regeneration on next frontend request.
            if ( class_exists( 'Better_Messages_Sticker_Manifest' ) ) {
                Better_Messages_Sticker_Manifest::instance()->delete_manifests();
            }

            do_action( 'better_messages_sticker_packs_updated', $packs );
        }

        /**
         * Replace a single pack in the stored list by id.
         */
        protected function replace_pack( $updated )
        {
            $packs = $this->get_all();
            foreach ( $packs as $index => $pack ) {
                if ( $pack['id'] === $updated['id'] ) {
                    $packs[ $index ] = $updated;
                    $this->save_all( $packs );
                    return;
                }
            }
            // Not found → append.
            $packs[] = $updated;
            $this->save_all( $packs );
        }

        /**
         * Recompute and store the content hash in the main settings option.
         * The main settings option is autoloaded on every request, so
         * the frontend can cheaply read the current manifest hash.
         *
         * IMPORTANT: read the existing settings directly from the DB and mutate
         * only the hash field. Never use `Better_Messages()->settings` as the
         * base here — during early plugin boot it may be unset, which would
         * cause this method to overwrite the entire settings blob.
         */
        public function update_hash()
        {
            $packs   = $this->get_all();
            $hash    = substr( md5( wp_json_encode( $packs ) ), 0, 8 );
            $summary = array();

            foreach ( $packs as $pack ) {
                if ( empty( $pack['enabled'] ) || empty( $pack['stickers'] ) ) {
                    continue;
                }
                $summary[] = array(
                    'id'            => isset( $pack['id'] ) ? $pack['id'] : '',
                    'allowed_roles' => isset( $pack['allowed_roles'] ) ? (array) $pack['allowed_roles'] : array(),
                );
            }

            $stored = get_option( 'bp-better-chat-settings', array() );
            if ( ! is_array( $stored ) ) {
                $stored = array();
            }

            $stored[ self::HASH_KEY ]    = $hash;
            $stored[ self::SUMMARY_KEY ] = $summary;
            update_option( 'bp-better-chat-settings', $stored );

            if ( isset( Better_Messages()->settings ) && is_array( Better_Messages()->settings ) ) {
                Better_Messages()->settings[ self::HASH_KEY ]    = $hash;
                Better_Messages()->settings[ self::SUMMARY_KEY ] = $summary;
            }

            return $hash;
        }

        protected function auto_enable_builtin()
        {
            $settings = Better_Messages()->settings;
            $provider = isset( $settings['stickersProvider'] ) ? $settings['stickersProvider'] : '';

            if ( $provider !== '' ) {
                return;
            }

            if ( ! empty( $settings['stipopApiKey'] ) ) {
                return;
            }

            $stored = get_option( 'bp-better-chat-settings', array() );
            if ( ! is_array( $stored ) ) {
                $stored = array();
            }

            $stored['stickersProvider'] = 'builtin';
            update_option( 'bp-better-chat-settings', $stored );

            if ( isset( Better_Messages()->settings ) && is_array( Better_Messages()->settings ) ) {
                Better_Messages()->settings['stickersProvider'] = 'builtin';
            }
        }

        public function get_hash()
        {
            if ( isset( Better_Messages()->settings ) && is_array( Better_Messages()->settings ) ) {
                if ( ! empty( Better_Messages()->settings[ self::HASH_KEY ] ) ) {
                    return Better_Messages()->settings[ self::HASH_KEY ];
                }
            }

            $stored = get_option( 'bp-better-chat-settings', array() );
            if ( is_array( $stored ) && ! empty( $stored[ self::HASH_KEY ] ) ) {
                return $stored[ self::HASH_KEY ];
            }

            return '';
        }

        /**
         * Normalize a pack associative array coming from user input.
         * When $full is false we only sanitize fields that are present (for updates).
         */
        protected function normalize_pack( $data, $full = true )
        {
            $pack = array();

            if ( isset( $data['id'] ) ) {
                $pack['id'] = sanitize_title( $data['id'] );
            }
            if ( isset( $data['title'] ) ) {
                $pack['title'] = sanitize_text_field( $data['title'] );
            }
            if ( isset( $data['description'] ) ) {
                $pack['description'] = sanitize_textarea_field( $data['description'] );
            }
            if ( isset( $data['cover'] ) ) {
                $pack['cover'] = esc_url_raw( $data['cover'] );
            }
            if ( isset( $data['type'] ) ) {
                $pack['type'] = sanitize_text_field( $data['type'] );
            }
            if ( isset( $data['source'] ) ) {
                $pack['source'] = in_array( $data['source'], array( 'custom', 'catalog' ), true ) ? $data['source'] : 'custom';
            } elseif ( $full ) {
                $pack['source'] = 'custom';
            }
            if ( isset( $data['remote_id'] ) ) {
                $pack['remote_id'] = sanitize_text_field( $data['remote_id'] );
            }
            if ( isset( $data['language'] ) ) {
                // Image-language variant code (e.g. "en", "es"). Empty string means
                // "custom / universal" — used for locally-authored packs.
                $pack['language'] = sanitize_text_field( $data['language'] );
            }
            if ( isset( $data['version'] ) ) {
                $pack['version'] = sanitize_text_field( $data['version'] );
            }
            if ( isset( $data['enabled'] ) ) {
                $pack['enabled'] = (bool) $data['enabled'];
            } elseif ( $full ) {
                $pack['enabled'] = true;
            }
            if ( isset( $data['suggestions'] ) ) {
                $pack['suggestions'] = (bool) $data['suggestions'];
            } elseif ( $full ) {
                $pack['suggestions'] = true;
            }
            if ( isset( $data['sort_order'] ) ) {
                $pack['sort_order'] = (int) $data['sort_order'];
            }
            if ( isset( $data['allowed_roles'] ) && is_array( $data['allowed_roles'] ) ) {
                $pack['allowed_roles'] = array_values( array_map( 'sanitize_key', $data['allowed_roles'] ) );
            } elseif ( $full ) {
                $pack['allowed_roles'] = array();
            }
            if ( isset( $data['translations'] ) && is_array( $data['translations'] ) ) {
                $pack['translations'] = $this->sanitize_translations( $data['translations'] );
            } elseif ( $full ) {
                $pack['translations'] = array();
            }
            if ( isset( $data['stickers'] ) && is_array( $data['stickers'] ) ) {
                $pack['stickers'] = array();
                foreach ( $data['stickers'] as $sticker ) {
                    $pack['stickers'][] = $this->normalize_sticker( $sticker );
                }
            } elseif ( $full ) {
                $pack['stickers'] = array();
            }

            return $pack;
        }

        protected function normalize_sticker( $data )
        {
            $sticker = array();
            if ( isset( $data['id'] ) ) {
                $sticker['id'] = sanitize_title( $data['id'] );
            }
            if ( isset( $data['name'] ) ) {
                $sticker['name'] = sanitize_text_field( $data['name'] );
            }
            if ( isset( $data['file'] ) ) {
                $sticker['file'] = esc_url_raw( $data['file'] );
            }
            if ( isset( $data['width'] ) ) {
                $sticker['width'] = (int) $data['width'];
            }
            if ( isset( $data['height'] ) ) {
                $sticker['height'] = (int) $data['height'];
            }
            if ( isset( $data['keywords'] ) ) {
                if ( is_array( $data['keywords'] ) ) {
                    $sticker['keywords'] = array_values( array_map( 'sanitize_text_field', $data['keywords'] ) );
                } elseif ( is_string( $data['keywords'] ) ) {
                    $parts               = array_map( 'trim', explode( ',', $data['keywords'] ) );
                    $sticker['keywords'] = array_values( array_filter( array_map( 'sanitize_text_field', $parts ) ) );
                } else {
                    $sticker['keywords'] = array();
                }
            }
            if ( isset( $data['sort_order'] ) ) {
                $sticker['sort_order'] = (int) $data['sort_order'];
            }
            if ( isset( $data['translations'] ) && is_array( $data['translations'] ) ) {
                $sticker['translations'] = $this->sanitize_sticker_translations( $data['translations'] );
            }
            return $sticker;
        }

        /**
         * Accept any of these locale forms:
         *   - 2- or 3-letter language code: "en", "es", "fil"
         *   - Language + region with underscore: "en_US", "pt_BR" (WordPress style)
         *   - Language + region with hyphen: "en-US", "pt-BR", "pt-br" (BCP47 / catalog style)
         *   - Language + script: "zh_Hans", "zh-Hant"
         *
         * The manifest generator is tolerant of case/separator differences at
         * lookup time, so we preserve the caller's exact string here.
         */
        protected function is_valid_locale_key( $locale )
        {
            return (bool) preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $locale );
        }

        protected function sanitize_translations( $translations )
        {
            $sanitized = array();
            foreach ( $translations as $locale => $fields ) {
                $locale = sanitize_text_field( $locale );
                if ( ! $this->is_valid_locale_key( $locale ) ) {
                    continue;
                }
                $entry = array();
                if ( isset( $fields['title'] ) ) {
                    $entry['title'] = sanitize_text_field( $fields['title'] );
                }
                if ( isset( $fields['description'] ) ) {
                    $entry['description'] = sanitize_textarea_field( $fields['description'] );
                }
                // Optional localized cover image override — uploaded via the
                // pack editor on a non-primary locale tab. When absent, the
                // primary pack cover is served to that locale's viewers.
                if ( isset( $fields['cover'] ) ) {
                    $entry['cover'] = esc_url_raw( $fields['cover'] );
                }
                if ( ! empty( $entry ) ) {
                    $sanitized[ $locale ] = $entry;
                }
            }
            return $sanitized;
        }

        protected function sanitize_sticker_translations( $translations )
        {
            $sanitized = array();
            foreach ( $translations as $locale => $fields ) {
                $locale = sanitize_text_field( $locale );
                if ( ! $this->is_valid_locale_key( $locale ) ) {
                    continue;
                }
                $entry = array();
                if ( isset( $fields['name'] ) ) {
                    $entry['name'] = sanitize_text_field( $fields['name'] );
                }
                if ( isset( $fields['keywords'] ) && is_array( $fields['keywords'] ) ) {
                    $entry['keywords'] = array_values( array_map( 'sanitize_text_field', $fields['keywords'] ) );
                }
                // Optional per-locale image override — only stored when the admin
                // (or the catalog installer) uploads a distinct image for that
                // language. When absent, the primary sticker file is reused.
                if ( isset( $fields['file'] ) ) {
                    $entry['file'] = esc_url_raw( $fields['file'] );
                }
                if ( isset( $fields['width'] ) ) {
                    $entry['width'] = (int) $fields['width'];
                }
                if ( isset( $fields['height'] ) ) {
                    $entry['height'] = (int) $fields['height'];
                }
                if ( ! empty( $entry ) ) {
                    $sanitized[ $locale ] = $entry;
                }
            }
            return $sanitized;
        }

        protected function generate_unique_id( $seed )
        {
            $base = sanitize_title( $seed );
            if ( empty( $base ) ) {
                $base = 'pack';
            }
            $id     = $base;
            $suffix = 1;
            while ( $this->exists( $id ) ) {
                $id = $base . '-' . ( ++$suffix );
            }
            return $id;
        }

        protected function generate_unique_sticker_id( $pack, $seed )
        {
            $base = sanitize_title( $seed );
            if ( empty( $base ) ) {
                $base = 'sticker';
            }
            $taken = array();
            foreach ( $pack['stickers'] as $sticker ) {
                if ( isset( $sticker['id'] ) ) {
                    $taken[ $sticker['id'] ] = true;
                }
            }
            $id     = $base;
            $suffix = 1;
            while ( isset( $taken[ $id ] ) ) {
                $id = $base . '-' . ( ++$suffix );
            }
            return $id;
        }
    }
}

if ( ! function_exists( 'Better_Messages_Sticker_Pack_Manager' ) ) {
    function Better_Messages_Sticker_Pack_Manager()
    {
        return Better_Messages_Sticker_Pack_Manager::instance();
    }
}
