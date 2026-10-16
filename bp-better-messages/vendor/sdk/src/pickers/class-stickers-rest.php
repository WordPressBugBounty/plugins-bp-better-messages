<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Stickers_REST' ) ) {
    final class WordPlus_SDK_Stickers_REST
    {
        const BASE = '/pickers/stickers';

        private $stickers;

        public function __construct( WordPlus_SDK_Stickers $stickers )
        {
            $this->stickers = $stickers;
        }

        public function routes()
        {
            $namespace = WordPlus_SDK_Pickers::REST_NAMESPACE;

            register_rest_route( $namespace, self::BASE . '/manifest', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'manifest' ),
                'permission_callback' => array( $this->stickers->pickers(), 'can_use' ),
            ) );

            $this->library_routes( $namespace, self::BASE . '/packs', self::BASE . '/catalog' );
        }

        public function library_routes( $namespace, $packs, $catalog, $requester = '' )
        {
            $admin     = array( $this, 'permission_admin' );
            $requester = (string) $requester;
            $handler   = function ( $method ) use ( $requester ) {
                if ( '' === $requester ) {
                    return array( $this, $method );
                }

                return function ( $request ) use ( $method, $requester ) {
                    $name = function () use ( $requester ) {
                        return $requester;
                    };
                    add_filter( 'wordplus_stickers_requester', $name, 1000 );

                    try {
                        return $this->$method( $request );
                    } finally {
                        remove_filter( 'wordplus_stickers_requester', $name, 1000 );
                    }
                };
            };

            register_rest_route( $namespace, $packs, array(
                array(
                    'methods'             => 'GET',
                    'callback'            => $handler( 'list_packs' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => $handler( 'create_pack' ),
                    'permission_callback' => $admin,
                ),
            ) );

            register_rest_route( $namespace, $packs . '/reorder', array(
                'methods'             => 'POST',
                'callback'            => $handler( 'reorder_packs' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( $namespace, $packs . '/(?P<id>[A-Za-z0-9_-]+)', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => $handler( 'update_pack' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => $handler( 'delete_pack' ),
                    'permission_callback' => $admin,
                ),
            ) );

            register_rest_route( $namespace, $packs . '/(?P<id>[A-Za-z0-9_-]+)/stickers', array(
                'methods'             => 'POST',
                'callback'            => $handler( 'upload_sticker' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( $namespace, $packs . '/(?P<id>[A-Za-z0-9_-]+)/stickers/reorder', array(
                'methods'             => 'POST',
                'callback'            => $handler( 'reorder_stickers' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( $namespace, $packs . '/(?P<id>[A-Za-z0-9_-]+)/stickers/(?P<sticker_id>[A-Za-z0-9_-]+)', array(
                'methods'             => 'DELETE',
                'callback'            => $handler( 'delete_sticker' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( $namespace, $packs . '/(?P<id>[A-Za-z0-9_-]+)/stickers/(?P<sticker_id>[A-Za-z0-9_-]+)/translations/(?P<locale>[A-Za-z0-9_-]+)/image', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => $handler( 'upload_sticker_translation_image' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => $handler( 'delete_sticker_translation_image' ),
                    'permission_callback' => $admin,
                ),
            ) );

            register_rest_route( $namespace, $packs . '/(?P<id>[A-Za-z0-9_-]+)/cover', array(
                'methods'             => 'POST',
                'callback'            => $handler( 'upload_pack_cover' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( $namespace, $packs . '/(?P<id>[A-Za-z0-9_-]+)/translations/(?P<locale>[A-Za-z0-9_-]+)/cover', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => $handler( 'upload_pack_translation_cover' ),
                    'permission_callback' => $admin,
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => $handler( 'delete_pack_translation_cover' ),
                    'permission_callback' => $admin,
                ),
            ) );

            register_rest_route( $namespace, $catalog, array(
                'methods'             => 'GET',
                'callback'            => $handler( 'get_catalog' ),
                'permission_callback' => $admin,
            ) );

            register_rest_route( $namespace, $catalog . '/install', array(
                'methods'             => 'POST',
                'callback'            => $handler( 'install_catalog_pack' ),
                'permission_callback' => $admin,
            ) );
        }

        public function permission_admin()
        {
            return current_user_can( (string) apply_filters( 'wordplus_stickers_capability', 'manage_options' ) );
        }

        public function manifest()
        {
            $locale = determine_locale();

            return rest_ensure_response( array(
                'url'    => $this->stickers->manifest_url( $locale ),
                'locale' => $locale,
                'hash'   => $this->stickers->hash(),
            ) );
        }

        public function list_packs()
        {
            return rest_ensure_response( array(
                'packs' => $this->stickers->packs()->get_all(),
                'hash'  => $this->stickers->packs()->get_hash(),
            ) );
        }

        public function create_pack( WP_REST_Request $request )
        {
            $data   = $request->get_json_params();
            $result = $this->stickers->packs()->create( is_array( $data ) ? $data : array() );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( $result );
        }

        public function update_pack( WP_REST_Request $request )
        {
            $id   = $request->get_param( 'id' );
            $data = $request->get_json_params();
            $result = $this->stickers->packs()->update( $id, is_array( $data ) ? $data : array() );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( $result );
        }

        public function delete_pack( WP_REST_Request $request )
        {
            $id = $request->get_param( 'id' );
            $dir    = $this->stickers->packs()->pack_dir( $id );
            $result = $this->stickers->packs()->delete( $id );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            $this->stickers->packs()->delete_pack_assets( $id, $dir );
            return rest_ensure_response( array( 'deleted' => true ) );
        }

        public function reorder_packs( WP_REST_Request $request )
        {
            $data = $request->get_json_params();
            $order = isset( $data['order'] ) && is_array( $data['order'] ) ? $data['order'] : array();
            $result = $this->stickers->packs()->reorder( $order );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( array( 'ok' => true ) );
        }

        public function upload_sticker( WP_REST_Request $request )
        {
            $pack_id = $request->get_param( 'id' );
            $pack    = $this->stickers->packs()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $files = $request->get_file_params();
            if ( empty( $files['file'] ) || ! is_array( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
                return new WP_Error( 'no_file', __( 'No sticker file was uploaded.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $file = $files['file'];
            if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
                return new WP_Error( 'invalid_upload', __( 'Invalid upload.', 'wordplus-sdk' ), array( 'status' => 400 ) );
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
                    __( 'Unsupported image format. Only PNG, WebP, and GIF stickers are allowed.', 'wordplus-sdk' ),
                    array( 'status' => 400 )
                );
            }

            $dirs     = $this->stickers->packs()->get_upload_dir( $pack_id );
            $base     = pathinfo( $file['name'], PATHINFO_FILENAME );
            $base     = sanitize_file_name( $base );
            if ( empty( $base ) ) {
                $base = 'sticker';
            }
            $filename = wp_unique_filename( $dirs['path'], $base . '.' . $finfo['ext'] );
            $dest     = $dirs['path'] . $filename;

            if ( ! $this->save_upload( $file, $dirs, $filename, $allowed_mimes ) ) {
                return new WP_Error( 'move_failed', __( 'Could not save the uploaded file.', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

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

            $added = $this->stickers->packs()->add_sticker( $pack_id, $sticker );
            if ( is_wp_error( $added ) ) {
                wp_delete_file( $dest );
                return $added;
            }

            return rest_ensure_response( $added );
        }

        public function delete_sticker( WP_REST_Request $request )
        {
            $pack_id    = $request->get_param( 'id' );
            $sticker_id = $request->get_param( 'sticker_id' );

            $pack = $this->stickers->packs()->get( $pack_id );
            $file_to_delete = null;
            if ( $pack && ! empty( $pack['stickers'] ) ) {
                foreach ( $pack['stickers'] as $sticker ) {
                    if ( isset( $sticker['id'] ) && $sticker['id'] === $sticker_id ) {
                        $file_to_delete = isset( $sticker['file'] ) ? $sticker['file'] : null;
                        break;
                    }
                }
            }

            $result = $this->stickers->packs()->remove_sticker( $pack_id, $sticker_id );
            if ( is_wp_error( $result ) ) {
                return $result;
            }

            if ( $file_to_delete ) {
                $path = $this->translation_file_to_local_path( $file_to_delete );
                if ( $path && file_exists( $path ) ) {
                    wp_delete_file( $path );
                }
            }

            return rest_ensure_response( array( 'deleted' => true ) );
        }

        public function reorder_stickers( WP_REST_Request $request )
        {
            $pack_id = $request->get_param( 'id' );
            $data    = $request->get_json_params();
            $order   = isset( $data['order'] ) && is_array( $data['order'] ) ? $data['order'] : array();
            $result  = $this->stickers->packs()->reorder_stickers( $pack_id, $order );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( array( 'ok' => true ) );
        }

        public function get_catalog( WP_REST_Request $request )
        {
            $catalog = $this->stickers->catalog()->fetch_catalog();
            if ( is_wp_error( $catalog ) ) {
                return $catalog;
            }
            if ( isset( $catalog['packs'] ) && is_array( $catalog['packs'] ) ) {
                foreach ( $catalog['packs'] as $index => $pack ) {
                    $remote_id = isset( $pack['id'] ) ? $pack['id'] : '';
                    $local     = $this->stickers->catalog()->find_by_remote_id( $remote_id );

                    $catalog['packs'][ $index ]['installed']         = $local ? true : false;
                    $catalog['packs'][ $index ]['installed_id']      = $local && isset( $local['id'] ) ? $local['id'] : '';
                    $catalog['packs'][ $index ]['installed_version'] = $local && isset( $local['version'] ) ? $local['version'] : '';
                    $catalog['packs'][ $index ]['installed_language'] = $local && isset( $local['language'] ) ? $local['language'] : '';
                    $catalog['packs'][ $index ]['installed_languages'] = $local
                        ? $this->collect_installed_languages( $local )
                        : array();

                    if ( isset( $pack['variants'] ) && is_array( $pack['variants'] ) ) {
                        foreach ( $pack['variants'] as $vi => $variant ) {
                            $lang = isset( $variant['language'] ) ? $variant['language'] : '';
                            $installed = false;
                            $installed_version = '';
                            if ( $local && $lang ) {
                                if ( $lang === 'universal' ) {
                                    $installed         = true;
                                    $installed_version = isset( $local['version'] ) ? $local['version'] : '';
                                } elseif ( isset( $local['language'] ) && $local['language'] === $lang ) {
                                    $installed         = true;
                                    $installed_version = isset( $local['version'] ) ? $local['version'] : '';
                                } elseif ( isset( $local['translations'][ $lang ] ) ) {
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

        public function upload_sticker_translation_image( WP_REST_Request $request )
        {
            $pack_id    = $request->get_param( 'id' );
            $sticker_id = $request->get_param( 'sticker_id' );
            $locale     = $request->get_param( 'locale' );

            if ( ! preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $locale ) ) {
                return new WP_Error( 'invalid_locale', __( 'Invalid locale code.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $pack = $this->stickers->packs()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $target_index = -1;
            foreach ( $pack['stickers'] as $index => $s ) {
                if ( isset( $s['id'] ) && $s['id'] === $sticker_id ) {
                    $target_index = $index;
                    break;
                }
            }
            if ( $target_index < 0 ) {
                return new WP_Error( 'sticker_not_found', __( 'Sticker not found.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $files = $request->get_file_params();
            if ( empty( $files['file'] ) || ! is_array( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
                return new WP_Error( 'no_file', __( 'No sticker file was uploaded.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }
            $file = $files['file'];
            if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
                return new WP_Error( 'invalid_upload', __( 'Invalid upload.', 'wordplus-sdk' ), array( 'status' => 400 ) );
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
                    __( 'Unsupported image format. Only PNG, WebP, and GIF stickers are allowed.', 'wordplus-sdk' ),
                    array( 'status' => 400 )
                );
            }

            $dirs = $this->stickers->packs()->get_upload_dir( $pack_id, $locale );
            $base = sanitize_file_name( $sticker_id );
            if ( empty( $base ) ) {
                $base = 'sticker';
            }
            $filename = wp_unique_filename( $dirs['path'], $base . '.' . $finfo['ext'] );
            $dest     = $dirs['path'] . $filename;

            if ( ! $this->save_upload( $file, $dirs, $filename, $allowed_mimes ) ) {
                return new WP_Error( 'move_failed', __( 'Could not save uploaded file.', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            $width  = 0;
            $height = 0;
            $size   = @getimagesize( $dest );
            if ( is_array( $size ) ) {
                $width  = (int) $size[0];
                $height = (int) $size[1];
            }

            $existing_tr = isset( $pack['stickers'][ $target_index ]['translations'][ $locale ] )
                ? $pack['stickers'][ $target_index ]['translations'][ $locale ]
                : array();
            if ( ! empty( $existing_tr['file'] ) ) {
                $old_local = $this->translation_file_to_local_path( $existing_tr['file'] );
                if ( $old_local && $old_local !== $dest && file_exists( $old_local ) ) {
                    wp_delete_file( $old_local );
                }
            }

            $tr         = is_array( $existing_tr ) ? $existing_tr : array();
            $tr['file'] = $dirs['url'] . rawurlencode( $filename );
            $tr['width']  = $width;
            $tr['height'] = $height;

            if ( ! isset( $pack['stickers'][ $target_index ]['translations'] ) || ! is_array( $pack['stickers'][ $target_index ]['translations'] ) ) {
                $pack['stickers'][ $target_index ]['translations'] = array();
            }
            $pack['stickers'][ $target_index ]['translations'][ $locale ] = $tr;

            $updated = $this->stickers->packs()->update( $pack_id, array( 'stickers' => $pack['stickers'] ) );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }

            return rest_ensure_response( $pack['stickers'][ $target_index ] );
        }

        public function delete_sticker_translation_image( WP_REST_Request $request )
        {
            $pack_id    = $request->get_param( 'id' );
            $sticker_id = $request->get_param( 'sticker_id' );
            $locale     = $request->get_param( 'locale' );

            $pack = $this->stickers->packs()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $target_index = -1;
            foreach ( $pack['stickers'] as $index => $s ) {
                if ( isset( $s['id'] ) && $s['id'] === $sticker_id ) {
                    $target_index = $index;
                    break;
                }
            }
            if ( $target_index < 0 ) {
                return new WP_Error( 'sticker_not_found', __( 'Sticker not found.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $tr = isset( $pack['stickers'][ $target_index ]['translations'][ $locale ] )
                ? $pack['stickers'][ $target_index ]['translations'][ $locale ]
                : array();

            if ( ! empty( $tr['file'] ) ) {
                $old_local = $this->translation_file_to_local_path( $tr['file'] );
                if ( $old_local && file_exists( $old_local ) ) {
                    wp_delete_file( $old_local );
                }
            }

            unset( $tr['file'], $tr['width'], $tr['height'] );
            if ( empty( $tr ) ) {
                unset( $pack['stickers'][ $target_index ]['translations'][ $locale ] );
            } else {
                $pack['stickers'][ $target_index ]['translations'][ $locale ] = $tr;
            }

            $updated = $this->stickers->packs()->update( $pack_id, array( 'stickers' => $pack['stickers'] ) );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }

            return rest_ensure_response( $pack['stickers'][ $target_index ] );
        }

        protected function store_pack_cover( $pack_id, $locale, $file )
        {
            if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
                return new WP_Error( 'invalid_upload', __( 'Invalid upload.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $pack = $this->stickers->packs()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ), array( 'status' => 404 ) );
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
                    __( 'Unsupported image format. Only PNG, WebP, and GIF stickers are allowed.', 'wordplus-sdk' ),
                    array( 'status' => 400 )
                );
            }

            $dirs = $this->stickers->packs()->get_upload_dir( $pack_id, $locale );
            $filename = wp_unique_filename( $dirs['path'], 'cover.' . $finfo['ext'] );
            $dest     = $dirs['path'] . $filename;

            if ( ! $this->save_upload( $file, $dirs, $filename, $allowed_mimes ) ) {
                return new WP_Error( 'move_failed', __( 'Could not save uploaded file.', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            $previous_url = '';
            if ( empty( $locale ) ) {
                $previous_url = isset( $pack['cover'] ) ? $pack['cover'] : '';
            } elseif ( isset( $pack['translations'][ $locale ]['cover'] ) ) {
                $previous_url = $pack['translations'][ $locale ]['cover'];
            }
            if ( $previous_url ) {
                $previous_local = $this->translation_file_to_local_path( $previous_url );
                if ( $previous_local && $previous_local !== $dest && file_exists( $previous_local ) ) {
                    wp_delete_file( $previous_local );
                }
            }

            $new_url = $dirs['url'] . rawurlencode( $filename );

            if ( empty( $locale ) ) {
                $updated = $this->stickers->packs()->update( $pack_id, array( 'cover' => $new_url ) );
            } else {
                $translations = isset( $pack['translations'] ) && is_array( $pack['translations'] )
                    ? $pack['translations']
                    : array();
                $entry = isset( $translations[ $locale ] ) && is_array( $translations[ $locale ] )
                    ? $translations[ $locale ]
                    : array();
                $entry['cover']          = $new_url;
                $translations[ $locale ] = $entry;
                $updated = $this->stickers->packs()->update( $pack_id, array( 'translations' => $translations ) );
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
                return new WP_Error( 'no_file', __( 'No cover file was uploaded.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }
            return rest_ensure_response( $this->store_pack_cover( $request->get_param( 'id' ), '', $files['file'] ) );
        }

        public function upload_pack_translation_cover( WP_REST_Request $request )
        {
            $locale = $request->get_param( 'locale' );
            if ( ! preg_match( '/^[A-Za-z]{2,3}([_-][A-Za-z0-9]{2,4})?$/', $locale ) ) {
                return new WP_Error( 'invalid_locale', __( 'Invalid locale code.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }
            $files = $request->get_file_params();
            if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
                return new WP_Error( 'no_file', __( 'No cover file was uploaded.', 'wordplus-sdk' ), array( 'status' => 400 ) );
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

            $pack = $this->stickers->packs()->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $translations = isset( $pack['translations'] ) && is_array( $pack['translations'] )
                ? $pack['translations']
                : array();
            if ( ! isset( $translations[ $locale ] ) ) {
                return rest_ensure_response( $pack );
            }

            $entry = $translations[ $locale ];
            if ( ! empty( $entry['cover'] ) ) {
                $old_local = $this->translation_file_to_local_path( $entry['cover'] );
                if ( $old_local && file_exists( $old_local ) ) {
                    wp_delete_file( $old_local );
                }
            }
            unset( $entry['cover'] );

            if ( empty( $entry ) ) {
                unset( $translations[ $locale ] );
            } else {
                $translations[ $locale ] = $entry;
            }

            $updated = $this->stickers->packs()->update( $pack_id, array( 'translations' => $translations ) );
            if ( is_wp_error( $updated ) ) {
                return $updated;
            }
            return rest_ensure_response( $updated );
        }

        private function save_upload( array $file, array $dirs, $filename, array $mimes )
        {
            require_once ABSPATH . 'wp-admin/includes/file.php';

            $folder = function ( $uploads ) use ( $dirs ) {
                $uploads['path']   = untrailingslashit( $dirs['path'] );
                $uploads['url']    = untrailingslashit( $dirs['url'] );
                $uploads['subdir'] = '';
                $uploads['error']  = false;

                return $uploads;
            };

            add_filter( 'upload_dir', $folder );
            $saved = wp_handle_upload( $file, array(
                'test_form'                => false,
                'mimes'                    => $mimes,
                'unique_filename_callback' => function () use ( $filename ) {
                    return $filename;
                },
            ) );
            remove_filter( 'upload_dir', $folder );

            return is_array( $saved ) && empty( $saved['error'] ) && ! empty( $saved['file'] );
        }

        protected function translation_file_to_local_path( $url )
        {
            if ( empty( $url ) || ! is_string( $url ) ) {
                return null;
            }
            $path = $this->stickers->local_path( $url );
            if ( false === $path ) {
                return null;
            }
            return $path;
        }

        public function install_catalog_pack( WP_REST_Request $request )
        {
            $data      = $request->get_json_params();
            $remote_id = isset( $data['remote_id'] ) ? sanitize_text_field( $data['remote_id'] ) : '';
            if ( empty( $remote_id ) ) {
                return new WP_Error( 'missing_remote_id', __( 'Missing catalog pack id.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $languages = array();
            if ( isset( $data['languages'] ) && is_array( $data['languages'] ) ) {
                $languages = $data['languages'];
            } elseif ( isset( $data['language'] ) ) {
                $languages = array( $data['language'] );
            }

            $result = $this->stickers->catalog()->install( $remote_id, $languages );
            if ( is_wp_error( $result ) ) {
                return $result;
            }
            return rest_ensure_response( $result );
        }
    }
}
