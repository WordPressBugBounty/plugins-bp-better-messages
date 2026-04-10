<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Stickers_REST' ) ) {
    class Better_Messages_Stickers_REST
    {
        private static $instance = null;

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
            $admin = array( $this, 'permission_admin' );

            register_rest_route( 'better-messages/v1/admin', '/sticker-packs', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'list_packs' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'create_pack' ),
                    'permission_callback' => $admin,
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/sticker-packs/reorder', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'reorder_packs' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/sticker-packs/(?P<id>[A-Za-z0-9_-]+)', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'update_pack' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'delete_pack' ),
                    'permission_callback' => $admin,
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/sticker-packs/(?P<id>[A-Za-z0-9_-]+)/stickers', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'upload_sticker' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/sticker-packs/(?P<id>[A-Za-z0-9_-]+)/stickers/reorder', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'reorder_stickers' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/sticker-packs/(?P<id>[A-Za-z0-9_-]+)/stickers/(?P<sticker_id>[A-Za-z0-9_-]+)', array(
                'methods'             => 'DELETE',
                'callback'            => array( $this, 'delete_sticker' ),
                'permission_callback' => $admin,
            ) );

            // Upload a translated image for a specific sticker + locale. The
            // file goes into packs/{pack_id}/{locale}/ and the sticker's
            // translations[locale] entry is populated with file/width/height
            // so the manifest generator can swap the image per viewer locale.
            register_rest_route( 'better-messages/v1/admin', '/sticker-packs/(?P<id>[A-Za-z0-9_-]+)/stickers/(?P<sticker_id>[A-Za-z0-9_-]+)/translations/(?P<locale>[A-Za-z0-9_-]+)/image', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'upload_sticker_translation_image' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'delete_sticker_translation_image' ),
                    'permission_callback' => $admin,
                ),
            ) );

            // Pack cover upload. POST without a locale replaces the primary
            // cover; the `/translations/{locale}/cover` variant stores a
            // localized cover image used only when the manifest is generated
            // for that locale.
            register_rest_route( 'better-messages/v1/admin', '/sticker-packs/(?P<id>[A-Za-z0-9_-]+)/cover', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'upload_pack_cover' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/sticker-packs/(?P<id>[A-Za-z0-9_-]+)/translations/(?P<locale>[A-Za-z0-9_-]+)/cover', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'upload_pack_translation_cover' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => array( $this, 'delete_pack_translation_cover' ),
                    'permission_callback' => $admin,
                ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/sticker-catalog', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_catalog' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( 'better-messages/v1/admin', '/sticker-catalog/install', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'install_catalog_pack' ),
                'permission_callback' => $admin,
            ) );
        }

        public function permission_admin()
        {
            return current_user_can( 'manage_options' );
        }

        public function list_packs()
        {
            return rest_ensure_response( array(
                'packs' => Better_Messages_Sticker_Pack_Manager::instance()->get_all(),
                'hash'  => Better_Messages_Sticker_Pack_Manager::instance()->get_hash(),
            ) );
        }

        public function create_pack( WP_REST_Request $request )
        {
            $data   = $request->get_json_params();
            $result = Better_Messages_Sticker_Pack_Manager::instance()->create( is_array( $data ) ? $data : array() );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( $result );
        }

        public function update_pack( WP_REST_Request $request )
        {
            $id   = $request->get_param( 'id' );
            $data = $request->get_json_params();
            $result = Better_Messages_Sticker_Pack_Manager::instance()->update( $id, is_array( $data ) ? $data : array() );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( $result );
        }

        public function delete_pack( WP_REST_Request $request )
        {
            $id = $request->get_param( 'id' );
            $result = Better_Messages_Sticker_Pack_Manager::instance()->delete( $id );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            // Delete local asset folder too.
            Better_Messages_Sticker_Pack_Manager::instance()->delete_pack_assets( $id );
            return rest_ensure_response( array( 'deleted' => true ) );
        }

        public function reorder_packs( WP_REST_Request $request )
        {
            $data = $request->get_json_params();
            $order = isset( $data['order'] ) && is_array( $data['order'] ) ? $data['order'] : array();
            $result = Better_Messages_Sticker_Pack_Manager::instance()->reorder( $order );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( array( 'ok' => true ) );
        }

        public function upload_sticker( WP_REST_Request $request )
        {
            $pack_id = $request->get_param( 'id' );
            $pack    = Better_Messages_Sticker_Pack_Manager::instance()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $files = $request->get_file_params();
            if ( empty( $files['file'] ) || ! is_array( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
                return new WP_Error( 'no_file', __( 'No sticker file was uploaded.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $file = $files['file'];
            if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
                return new WP_Error( 'invalid_upload', __( 'Invalid upload.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $allowed_mimes = array(
                'png'  => 'image/png',
                'webp' => 'image/webp',
                'gif'  => 'image/gif',
            );

            $finfo = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
            if ( empty( $finfo['ext'] ) || empty( $finfo['type'] ) ) {
                return new WP_Error(
                    'unsupported_format',
                    __( 'Unsupported image format. Only PNG, WebP, and GIF stickers are allowed.', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            // Compute a unique filename inside the pack folder.
            $dirs     = Better_Messages_Sticker_Pack_Manager::instance()->get_upload_dir( $pack_id );
            $base     = pathinfo( $file['name'], PATHINFO_FILENAME );
            $base     = sanitize_file_name( $base );
            if ( empty( $base ) ) {
                $base = 'sticker';
            }
            $filename = wp_unique_filename( $dirs['path'], $base . '.' . $finfo['ext'] );
            $dest     = $dirs['path'] . $filename;

            if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
                return new WP_Error( 'move_failed', __( 'Could not save the uploaded file.', 'bp-better-messages' ), array( 'status' => 500 ) );
            }

            // Read image size (best effort; animated WebP/GIF may report only the first frame).
            $size = @getimagesize( $dest );
            $width  = is_array( $size ) ? (int) $size[0] : 0;
            $height = is_array( $size ) ? (int) $size[1] : 0;

            $sticker = array(
                'name'     => isset( $file['name'] ) ? pathinfo( $file['name'], PATHINFO_FILENAME ) : '',
                'file'     => $dirs['url'] . rawurlencode( $filename ),
                'width'    => $width,
                'height'   => $height,
                'keywords' => array(),
            );

            $added = Better_Messages_Sticker_Pack_Manager::instance()->add_sticker( $pack_id, $sticker );
            if ( is_wp_error( $added ) ) {
                @unlink( $dest );
                return $added;
            }

            return rest_ensure_response( $added );
        }

        public function delete_sticker( WP_REST_Request $request )
        {
            $pack_id    = $request->get_param( 'id' );
            $sticker_id = $request->get_param( 'sticker_id' );

            // Look up the sticker before deleting so we can also remove its file.
            $pack = Better_Messages_Sticker_Pack_Manager::instance()->get( $pack_id );
            $file_to_delete = null;
            if ( $pack && ! empty( $pack['stickers'] ) ) {
                foreach ( $pack['stickers'] as $sticker ) {
                    if ( isset( $sticker['id'] ) && $sticker['id'] === $sticker_id ) {
                        $file_to_delete = isset( $sticker['file'] ) ? $sticker['file'] : null;
                        break;
                    }
                }
            }

            $result = Better_Messages_Sticker_Pack_Manager::instance()->remove_sticker( $pack_id, $sticker_id );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            // Best-effort delete of the underlying file if it's inside our uploads folder.
            if ( $file_to_delete ) {
                $upload = wp_upload_dir();
                $prefix = $upload['baseurl'] . '/better-messages/stickers/packs/';
                if ( strpos( $file_to_delete, $prefix ) === 0 ) {
                    $rel  = substr( $file_to_delete, strlen( $upload['baseurl'] ) + 1 );
                    $path = trailingslashit( $upload['basedir'] ) . urldecode( $rel );
                    if ( file_exists( $path ) ) {
                        @unlink( $path );
                    }
                }
            }

            return rest_ensure_response( array( 'deleted' => true ) );
        }

        public function reorder_stickers( WP_REST_Request $request )
        {
            $pack_id = $request->get_param( 'id' );
            $data    = $request->get_json_params();
            $order   = isset( $data['order'] ) && is_array( $data['order'] ) ? $data['order'] : array();
            $result  = Better_Messages_Sticker_Pack_Manager::instance()->reorder_stickers( $pack_id, $order );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( array( 'ok' => true ) );
        }

        public function get_catalog( WP_REST_Request $request )
        {
            $catalog = Better_Messages_Sticker_Catalog_Client::instance()->fetch_catalog();
            if ( is_wp_error( $catalog ) ) {
                return $catalog;
            }
            // Annotate each pack (and each of its variants) with local install state so
            // the admin UI can render per-language install / update buttons.
            if ( isset( $catalog['packs'] ) && is_array( $catalog['packs'] ) ) {
                foreach ( $catalog['packs'] as $index => $pack ) {
                    $remote_id = isset( $pack['id'] ) ? $pack['id'] : '';
                    $local     = Better_Messages_Sticker_Catalog_Client::instance()->find_by_remote_id( $remote_id );

                    // Pack-level flags. `installed` = any variant installed.
                    $catalog['packs'][ $index ]['installed']         = $local ? true : false;
                    $catalog['packs'][ $index ]['installed_id']      = $local && isset( $local['id'] ) ? $local['id'] : '';
                    $catalog['packs'][ $index ]['installed_version'] = $local && isset( $local['version'] ) ? $local['version'] : '';
                    $catalog['packs'][ $index ]['installed_language'] = $local && isset( $local['language'] ) ? $local['language'] : '';
                    $catalog['packs'][ $index ]['installed_languages'] = $local
                        ? $this->collect_installed_languages( $local )
                        : array();

                    // Per-variant enrichment so the UI can show "Installed" /
                    // "Update available" next to each language chip.
                    if ( isset( $pack['variants'] ) && is_array( $pack['variants'] ) ) {
                        foreach ( $pack['variants'] as $vi => $variant ) {
                            $lang = isset( $variant['language'] ) ? $variant['language'] : '';
                            $installed = false;
                            $installed_version = '';
                            if ( $local && $lang ) {
                                if ( $lang === 'universal' ) {
                                    // A "universal" variant covers every label
                                    // language in one download — it's installed
                                    // whenever the pack itself is installed.
                                    $installed         = true;
                                    $installed_version = isset( $local['version'] ) ? $local['version'] : '';
                                } elseif ( isset( $local['language'] ) && $local['language'] === $lang ) {
                                    $installed         = true;
                                    $installed_version = isset( $local['version'] ) ? $local['version'] : '';
                                } elseif ( isset( $local['translations'][ $lang ] ) ) {
                                    // Non-primary language present in translations
                                    // = that language's assets are already imported.
                                    $installed         = true;
                                    $installed_version = isset( $local['version'] ) ? $local['version'] : '';
                                }
                            }
                            $catalog['packs'][ $index ]['variants'][ $vi ]['installed']         = $installed;
                            $catalog['packs'][ $index ]['variants'][ $vi ]['installed_version'] = $installed_version;
                        }
                    }
                }
            }
            return rest_ensure_response( $catalog );
        }

        /**
         * Collect every language a locally-installed pack has content for:
         * its primary language plus any entries in the pack-level or
         * sticker-level translations.
         *
         * @param array $pack
         * @return array List of language codes, each appearing once.
         */
        protected function collect_installed_languages( $pack )
        {
            $langs = array();
            if ( ! empty( $pack['language'] ) ) {
                $langs[ $pack['language'] ] = true;
            }
            if ( isset( $pack['translations'] ) && is_array( $pack['translations'] ) ) {
                foreach ( array_keys( $pack['translations'] ) as $lang ) {
                    $langs[ $lang ] = true;
                }
            }
            if ( isset( $pack['stickers'] ) && is_array( $pack['stickers'] ) ) {
                foreach ( $pack['stickers'] as $sticker ) {
                    if ( isset( $sticker['translations'] ) && is_array( $sticker['translations'] ) ) {
                        foreach ( array_keys( $sticker['translations'] ) as $lang ) {
                            $langs[ $lang ] = true;
                        }
                    }
                }
            }
            return array_keys( $langs );
        }

        /**
         * Upload a translated image for a sticker + locale.
         * Stores the file in the pack's per-locale subfolder and sets
         * file/width/height on the sticker's translations[locale] entry.
         */
        public function upload_sticker_translation_image( WP_REST_Request $request )
        {
            $pack_id    = $request->get_param( 'id' );
            $sticker_id = $request->get_param( 'sticker_id' );
            $locale     = $request->get_param( 'locale' );

            if ( ! preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $locale ) ) {
                return new WP_Error( 'invalid_locale', __( 'Invalid locale code.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $pack = Better_Messages_Sticker_Pack_Manager::instance()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            // Locate the target sticker.
            $target_index = -1;
            foreach ( $pack['stickers'] as $index => $s ) {
                if ( isset( $s['id'] ) && $s['id'] === $sticker_id ) {
                    $target_index = $index;
                    break;
                }
            }
            if ( $target_index < 0 ) {
                return new WP_Error( 'sticker_not_found', __( 'Sticker not found.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $files = $request->get_file_params();
            if ( empty( $files['file'] ) || ! is_array( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
                return new WP_Error( 'no_file', __( 'No sticker file was uploaded.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }
            $file = $files['file'];
            if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
                return new WP_Error( 'invalid_upload', __( 'Invalid upload.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $allowed_mimes = array(
                'png'  => 'image/png',
                'webp' => 'image/webp',
                'gif'  => 'image/gif',
            );
            $finfo = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
            if ( empty( $finfo['ext'] ) || empty( $finfo['type'] ) ) {
                return new WP_Error(
                    'unsupported_format',
                    __( 'Unsupported image format. Only PNG, WebP, and GIF stickers are allowed.', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            // Destination: packs/{pack_id}/{locale}/{sticker_id}.{ext}
            // Keep the sticker id in the filename so it's obvious which sticker
            // the image belongs to when browsing on disk.
            $dirs = Better_Messages_Sticker_Pack_Manager::instance()->get_upload_dir( $pack_id, $locale );
            $base = sanitize_file_name( $sticker_id );
            if ( empty( $base ) ) {
                $base = 'sticker';
            }
            $filename = wp_unique_filename( $dirs['path'], $base . '.' . $finfo['ext'] );
            $dest     = $dirs['path'] . $filename;

            if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
                return new WP_Error( 'move_failed', __( 'Could not save uploaded file.', 'bp-better-messages' ), array( 'status' => 500 ) );
            }

            // Measure dimensions so the picker can render the image at the
            // correct aspect ratio without waiting for the img tag to load.
            $width  = 0;
            $height = 0;
            $size   = @getimagesize( $dest );
            if ( is_array( $size ) ) {
                $width  = (int) $size[0];
                $height = (int) $size[1];
            }

            // If there was a previous translated image for this locale, remove it.
            $existing_tr = isset( $pack['stickers'][ $target_index ]['translations'][ $locale ] )
                ? $pack['stickers'][ $target_index ]['translations'][ $locale ]
                : array();
            if ( ! empty( $existing_tr['file'] ) ) {
                $old_local = $this->translation_file_to_local_path( $existing_tr['file'] );
                if ( $old_local && $old_local !== $dest && file_exists( $old_local ) ) {
                    @unlink( $old_local );
                }
            }

            // Merge the new file into the sticker's translations.
            $tr         = is_array( $existing_tr ) ? $existing_tr : array();
            $tr['file'] = $dirs['url'] . rawurlencode( $filename );
            $tr['width']  = $width;
            $tr['height'] = $height;

            if ( ! isset( $pack['stickers'][ $target_index ]['translations'] ) || ! is_array( $pack['stickers'][ $target_index ]['translations'] ) ) {
                $pack['stickers'][ $target_index ]['translations'] = array();
            }
            $pack['stickers'][ $target_index ]['translations'][ $locale ] = $tr;

            $updated = Better_Messages_Sticker_Pack_Manager::instance()->update( $pack_id, array( 'stickers' => $pack['stickers'] ) );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }

            return rest_ensure_response( $pack['stickers'][ $target_index ] );
        }

        /**
         * Remove a previously-uploaded translated image for a sticker + locale.
         * Leaves other translation fields (name/keywords) intact.
         */
        public function delete_sticker_translation_image( WP_REST_Request $request )
        {
            $pack_id    = $request->get_param( 'id' );
            $sticker_id = $request->get_param( 'sticker_id' );
            $locale     = $request->get_param( 'locale' );

            $pack = Better_Messages_Sticker_Pack_Manager::instance()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $target_index = -1;
            foreach ( $pack['stickers'] as $index => $s ) {
                if ( isset( $s['id'] ) && $s['id'] === $sticker_id ) {
                    $target_index = $index;
                    break;
                }
            }
            if ( $target_index < 0 ) {
                return new WP_Error( 'sticker_not_found', __( 'Sticker not found.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $tr = isset( $pack['stickers'][ $target_index ]['translations'][ $locale ] )
                ? $pack['stickers'][ $target_index ]['translations'][ $locale ]
                : array();

            if ( ! empty( $tr['file'] ) ) {
                $old_local = $this->translation_file_to_local_path( $tr['file'] );
                if ( $old_local && file_exists( $old_local ) ) {
                    @unlink( $old_local );
                }
            }

            // Drop the image-related keys but keep name/keywords so label-only
            // translations aren't wiped by this action.
            unset( $tr['file'], $tr['width'], $tr['height'] );
            if ( empty( $tr ) ) {
                unset( $pack['stickers'][ $target_index ]['translations'][ $locale ] );
            } else {
                $pack['stickers'][ $target_index ]['translations'][ $locale ] = $tr;
            }

            $updated = Better_Messages_Sticker_Pack_Manager::instance()->update( $pack_id, array( 'stickers' => $pack['stickers'] ) );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }

            return rest_ensure_response( $pack['stickers'][ $target_index ] );
        }

        /**
         * Handle a pack cover file upload. When `$locale` is non-empty the
         * file is written under the pack's per-locale subdirectory and the
         * result is saved in `translations[locale].cover`. Otherwise it
         * replaces the primary `cover` field on the pack.
         *
         * @param string $pack_id
         * @param string $locale   Empty string for primary, locale code otherwise.
         * @param array  $file     $_FILES-style array from WP_REST_Request::get_file_params().
         * @return array|WP_Error  The updated pack.
         */
        protected function store_pack_cover( $pack_id, $locale, $file )
        {
            if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
                return new WP_Error( 'invalid_upload', __( 'Invalid upload.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $pack = Better_Messages_Sticker_Pack_Manager::instance()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $allowed_mimes = array(
                'png'  => 'image/png',
                'webp' => 'image/webp',
                'gif'  => 'image/gif',
            );
            $finfo = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_mimes );
            if ( empty( $finfo['ext'] ) || empty( $finfo['type'] ) ) {
                return new WP_Error(
                    'unsupported_format',
                    __( 'Unsupported image format. Only PNG, WebP, and GIF stickers are allowed.', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            $dirs = Better_Messages_Sticker_Pack_Manager::instance()->get_upload_dir( $pack_id, $locale );
            // Base filename keeps a stable "cover.ext" name so repeated uploads
            // overwrite cleanly and the URL stays predictable.
            $filename = wp_unique_filename( $dirs['path'], 'cover.' . $finfo['ext'] );
            $dest     = $dirs['path'] . $filename;

            if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
                return new WP_Error( 'move_failed', __( 'Could not save uploaded file.', 'bp-better-messages' ), array( 'status' => 500 ) );
            }

            // Remove the previous cover file on disk (if any) so uploads don't
            // accumulate stale files for the same pack/locale combination.
            $previous_url = '';
            if ( empty( $locale ) ) {
                $previous_url = isset( $pack['cover'] ) ? $pack['cover'] : '';
            } elseif ( isset( $pack['translations'][ $locale ]['cover'] ) ) {
                $previous_url = $pack['translations'][ $locale ]['cover'];
            }
            if ( $previous_url ) {
                $previous_local = $this->translation_file_to_local_path( $previous_url );
                if ( $previous_local && $previous_local !== $dest && file_exists( $previous_local ) ) {
                    @unlink( $previous_local );
                }
            }

            $new_url = $dirs['url'] . rawurlencode( $filename );

            if ( empty( $locale ) ) {
                // Primary cover replacement.
                $updated = Better_Messages_Sticker_Pack_Manager::instance()->update( $pack_id, array( 'cover' => $new_url ) );
            } else {
                // Translated cover — merge into translations[locale].
                $translations = isset( $pack['translations'] ) && is_array( $pack['translations'] )
                    ? $pack['translations']
                    : array();
                $entry = isset( $translations[ $locale ] ) && is_array( $translations[ $locale ] )
                    ? $translations[ $locale ]
                    : array();
                $entry['cover']          = $new_url;
                $translations[ $locale ] = $entry;
                $updated = Better_Messages_Sticker_Pack_Manager::instance()->update( $pack_id, array( 'translations' => $translations ) );
            }

            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            return $updated;
        }

        public function upload_pack_cover( WP_REST_Request $request )
        {
            $files = $request->get_file_params();
            if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
                return new WP_Error( 'no_file', __( 'No cover file was uploaded.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }
            return rest_ensure_response( $this->store_pack_cover( $request->get_param( 'id' ), '', $files['file'] ) );
        }

        public function upload_pack_translation_cover( WP_REST_Request $request )
        {
            $locale = $request->get_param( 'locale' );
            if ( ! preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $locale ) ) {
                return new WP_Error( 'invalid_locale', __( 'Invalid locale code.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }
            $files = $request->get_file_params();
            if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
                return new WP_Error( 'no_file', __( 'No cover file was uploaded.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }
            $result = $this->store_pack_cover( $request->get_param( 'id' ), $locale, $files['file'] );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( $result );
        }

        public function delete_pack_translation_cover( WP_REST_Request $request )
        {
            $pack_id = $request->get_param( 'id' );
            $locale  = $request->get_param( 'locale' );

            $pack = Better_Messages_Sticker_Pack_Manager::instance()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $translations = isset( $pack['translations'] ) && is_array( $pack['translations'] )
                ? $pack['translations']
                : array();
            if ( ! isset( $translations[ $locale ] ) ) {
                // Nothing to remove — just return the current pack.
                return rest_ensure_response( $pack );
            }

            $entry = $translations[ $locale ];
            if ( ! empty( $entry['cover'] ) ) {
                $old_local = $this->translation_file_to_local_path( $entry['cover'] );
                if ( $old_local && file_exists( $old_local ) ) {
                    @unlink( $old_local );
                }
            }
            unset( $entry['cover'] );

            if ( empty( $entry ) ) {
                unset( $translations[ $locale ] );
            } else {
                $translations[ $locale ] = $entry;
            }

            $updated = Better_Messages_Sticker_Pack_Manager::instance()->update( $pack_id, array( 'translations' => $translations ) );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            return rest_ensure_response( $updated );
        }

        /**
         * Translate a public sticker image URL back to an absolute filesystem
         * path so we can safely unlink it. Only paths inside the plugin's
         * uploads folder are resolved — anything else returns null.
         */
        protected function translation_file_to_local_path( $url )
        {
            if ( empty( $url ) || ! is_string( $url ) ) {
                return null;
            }
            $upload = wp_upload_dir();
            $base_url = trailingslashit( $upload['baseurl'] ) . 'better-messages/stickers/packs/';
            if ( strpos( $url, $base_url ) !== 0 ) {
                return null;
            }
            $rel = substr( $url, strlen( $base_url ) );
            $rel = urldecode( $rel );
            // Block path-traversal attempts.
            if ( strpos( $rel, '..' ) !== false ) {
                return null;
            }
            return $upload['basedir'] . '/better-messages/stickers/packs/' . $rel;
        }

        public function install_catalog_pack( WP_REST_Request $request )
        {
            $data      = $request->get_json_params();
            $remote_id = isset( $data['remote_id'] ) ? sanitize_text_field( $data['remote_id'] ) : '';
            if ( empty( $remote_id ) ) {
                return new WP_Error( 'missing_remote_id', __( 'Missing catalog pack id.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            // Accept both `languages` (new) and `language` (legacy) params.
            $languages = array();
            if ( isset( $data['languages'] ) && is_array( $data['languages'] ) ) {
                $languages = $data['languages'];
            } elseif ( isset( $data['language'] ) ) {
                $languages = array( $data['language'] );
            }

            $result = Better_Messages_Sticker_Catalog_Client::instance()->install( $remote_id, $languages );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( $result );
        }
    }
}

if ( ! function_exists( 'Better_Messages_Stickers_REST' ) ) {
    function Better_Messages_Stickers_REST()
    {
        return Better_Messages_Stickers_REST::instance();
    }
}
