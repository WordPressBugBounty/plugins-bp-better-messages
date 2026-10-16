<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Sticker_Catalog' ) ) {
    final class WordPlus_SDK_Sticker_Catalog
    {
        const CATALOG_URL      = 'https://api.wordplus.cloud/api/v1/assets/stickers/manifest.json';
        const CATALOG_BASE_URL = 'https://api.wordplus.cloud/api/v1/assets/stickers/';

        private $stickers;

        public function __construct( WordPlus_SDK_Stickers $stickers )
        {
            $this->stickers = $stickers;
        }

        public function get_catalog_url()
        {
            return apply_filters( 'wordplus_stickers_catalog_url', self::CATALOG_URL );
        }

        public function get_catalog_base_url()
        {
            return apply_filters( 'wordplus_stickers_catalog_base_url', self::CATALOG_BASE_URL );
        }

        public function get_catalog_cdn_base_url()
        {
            require_once __DIR__ . '/class-cloud-files.php';

            return WordPlus_SDK_Cloud_Files::cdn_url( $this->get_catalog_base_url() );
        }

        public function plugin_header()
        {
            $sdk = 'wordplus-sdk' . ( '' === $this->stickers->version() ? '' : '/' . $this->stickers->version() );

            return (string) apply_filters( 'wordplus_stickers_requester', $sdk );
        }

        public function fetch_catalog()
        {
            require_once __DIR__ . '/class-cloud-files.php';

            $response = WordPlus_SDK_Cloud_Files::get(
                $this->get_catalog_url(),
                array(
                    'timeout' => 8,
                    'headers' => array( 'Accept' => 'application/json', 'X-WordPlus-Plugin' => $this->plugin_header() ),
                ),
                fn ( $body ) => $this->is_catalog( $body )
            );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                return new WP_Error(
                    'catalog_http_error',
                    sprintf( __( 'Failed to fetch sticker catalog (HTTP %d).', 'wordplus-sdk' ), $code )
                );
            }

            $body = wp_remote_retrieve_body( $response );

            if ( ! $this->is_catalog( $body ) ) {
                return new WP_Error( 'catalog_invalid', __( 'Invalid sticker catalog response.', 'wordplus-sdk' ) );
            }

            return json_decode( $body, true );
        }

        private function is_catalog( $body )
        {
            $data = json_decode( (string) $body, true );

            return is_array( $data ) && isset( $data['packs'] ) && is_array( $data['packs'] );
        }

        private function is_zip( $file )
        {
            return is_file( $file ) && "PK\x03\x04" === (string) file_get_contents( $file, false, null, 0, 4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
        }

        public function install( $remote_id, $languages = array() )
        {
            if ( ! class_exists( 'ZipArchive' ) ) {
                return new WP_Error( 'zip_unavailable', __( 'PHP ZipArchive extension is required to install sticker packs.', 'wordplus-sdk' ) );
            }

            $catalog = $this->fetch_catalog();
            if ( is_wp_error( $catalog ) ) {
                return $catalog;
            }

            if ( is_string( $languages ) ) {
                $languages = $languages === '' ? array() : array( $languages );
            }
            if ( ! is_array( $languages ) ) {
                $languages = array();
            }
            $languages = array_values( array_unique( array_map( 'sanitize_text_field', $languages ) ) );

            $remote_pack = null;
            foreach ( $catalog['packs'] as $p ) {
                if ( isset( $p['id'] ) && $p['id'] === $remote_id ) {
                    $remote_pack = $p;
                    break;
                }
            }
            if ( ! $remote_pack || empty( $remote_pack['variants'] ) || ! is_array( $remote_pack['variants'] ) ) {
                return new WP_Error( 'pack_not_in_catalog', __( 'This sticker pack is not in the catalog.', 'wordplus-sdk' ) );
            }

            $variants_by_lang = array();
            foreach ( $remote_pack['variants'] as $variant ) {
                if ( ! empty( $variant['language'] ) ) {
                    $variants_by_lang[ $variant['language'] ] = $variant;
                }
            }
            if ( empty( $variants_by_lang ) ) {
                return new WP_Error( 'no_variants', __( 'Sticker pack has no downloadable variants.', 'wordplus-sdk' ) );
            }

            if ( empty( $languages ) ) {
                $first_variant = $remote_pack['variants'][0];
                $languages = array( $first_variant['language'] );
            }

            $selected_languages = array();
            foreach ( $languages as $lang ) {
                if ( isset( $variants_by_lang[ $lang ] ) && ! in_array( $lang, $selected_languages, true ) ) {
                    $selected_languages[] = $lang;
                }
            }

            if ( empty( $selected_languages ) && isset( $variants_by_lang['universal'] ) ) {
                $selected_languages[] = 'universal';
            }

            if ( empty( $selected_languages ) ) {
                return new WP_Error( 'no_valid_variants', __( 'None of the requested languages are available for this pack.', 'wordplus-sdk' ) );
            }

            $primary_language = $selected_languages[0];

            require_once __DIR__ . '/class-cloud-files.php';

            $tmp_root = $this->stickers->uploads()['path'] . '/' . WordPlus_SDK_Stickers::ROOT . '/_tmp/' . wp_generate_password( 12, false ) . '/';
            wp_mkdir_p( $tmp_root );

            $variant_packs = array();
            $variant_dirs  = array();
            foreach ( $selected_languages as $lang ) {
                $variant = $variants_by_lang[ $lang ];
                if ( empty( $variant['downloadUrl'] ) ) {
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'variant_missing_download', sprintf( __( 'Variant "%s" has no download URL.', 'wordplus-sdk' ), $lang ) );
                }

                $tmp_zip = WordPlus_SDK_Cloud_Files::download(
                    $this->resolve_url( $variant['downloadUrl'] ),
                    30,
                    60,
                    array( 'X-WordPlus-Plugin' => $this->plugin_header() ),
                    fn ( $file ) => $this->is_zip( $file )
                );
                if ( is_wp_error( $tmp_zip ) ) {
                    $this->rmrf( $tmp_root );
                    return $tmp_zip;
                }

                $zip = new ZipArchive();
                if ( ! filesize( $tmp_zip ) || $zip->open( $tmp_zip ) !== true ) {
                    wp_delete_file( $tmp_zip );
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'zip_open_failed', sprintf( __( 'Could not open the sticker pack archive for "%s".', 'wordplus-sdk' ), $lang ) );
                }

                $lang_dir = $tmp_root . sanitize_file_name( $lang ) . '/';
                wp_mkdir_p( $lang_dir );
                if ( ! $zip->extractTo( $lang_dir ) ) {
                    $zip->close();
                    wp_delete_file( $tmp_zip );
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'zip_extract_failed', sprintf( __( 'Could not extract the sticker pack archive for "%s".', 'wordplus-sdk' ), $lang ) );
                }
                $zip->close();
                wp_delete_file( $tmp_zip );

                $pack_json_path = $this->find_pack_json( $lang_dir );
                if ( ! $pack_json_path ) {
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'pack_json_missing', sprintf( __( 'Sticker pack archive for "%s" is missing pack.json.', 'wordplus-sdk' ), $lang ) );
                }

                $pack_data = json_decode( file_get_contents( $pack_json_path ), true );
                if ( ! is_array( $pack_data ) ) {
                    $this->rmrf( $tmp_root );
                    return new WP_Error( 'pack_json_invalid', sprintf( __( 'Sticker pack archive for "%s" contains an invalid pack.json.', 'wordplus-sdk' ), $lang ) );
                }

                $variant_packs[ $lang ] = $pack_data;
                $variant_dirs[ $lang ]  = dirname( $pack_json_path );
            }

            $existing = $this->find_by_remote_id( $remote_id );

            if ( $existing ) {
                $this->stickers->packs()->delete_pack_assets( $existing['id'] );
            }

            $local_id     = $existing ? $existing['id'] : sanitize_title( $remote_id );
            $primary_pack = $variant_packs[ $primary_language ];
            $primary_dir  = $variant_dirs[ $primary_language ];
            $primary_dirs = $this->stickers->packs()->get_upload_dir( $local_id );

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

            $pack_translations = array();

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

                $lang_dirs  = $this->stickers->packs()->get_upload_dir( $local_id, $lang );
                $cover_dest = $lang_dirs['path'] . 'cover.' . pathinfo( $variant_cover_file, PATHINFO_EXTENSION );
                if ( ! copy( $cover_src, $cover_dest ) ) {
                    continue;
                }

                if ( ! isset( $pack_translations[ $lang ] ) ) {
                    $pack_translations[ $lang ] = array();
                }
                $pack_translations[ $lang ]['cover'] = $lang_dirs['url'] . basename( $cover_dest );
            }

            $stickers_by_id = array();

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

            foreach ( $selected_languages as $lang ) {
                if ( $lang === $primary_language ) {
                    continue;
                }
                $vp       = $variant_packs[ $lang ];
                $pack_dir = $variant_dirs[ $lang ];
                $lang_dirs = $this->stickers->packs()->get_upload_dir( $local_id, $lang );

                if ( ! isset( $vp['stickers'] ) || ! is_array( $vp['stickers'] ) ) {
                    continue;
                }
                foreach ( $vp['stickers'] as $sticker ) {
                    if ( empty( $sticker['id'] ) ) {
                        continue;
                    }
                    $sid = sanitize_title( $sticker['id'] );

                    if ( ! isset( $stickers_by_id[ $sid ] ) ) {
                        continue;
                    }

                    $entry = isset( $stickers_by_id[ $sid ]['translations'][ $lang ] )
                        ? $stickers_by_id[ $sid ]['translations'][ $lang ]
                        : array();

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

            $version = isset( $primary_pack['version'] )
                ? $primary_pack['version']
                : ( isset( $variants_by_lang[ $primary_language ]['version'] ) ? $variants_by_lang[ $primary_language ]['version'] : '' );

            $new_pack = array(
                'id'            => $local_id,
                'source'        => 'catalog',
                'remote_id'     => $remote_id,
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
                $new_pack['sort_order'] = isset( $existing['sort_order'] ) ? $existing['sort_order'] : 0;
                $new_pack['enabled']    = isset( $existing['enabled'] ) ? $existing['enabled'] : true;
                return $this->stickers->packs()->update( $existing['id'], $new_pack );
            }

            return $this->stickers->packs()->create( $new_pack );
        }

        public function find_by_remote_id( $remote_id )
        {
            foreach ( $this->stickers->packs()->get_all() as $pack ) {
                if ( isset( $pack['source'] ) && $pack['source'] === 'catalog'
                    && isset( $pack['remote_id'] ) && $pack['remote_id'] === $remote_id ) {
                    return $pack;
                }
            }
            return null;
        }

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

        public function rmrf( $dir )
        {
            WordPlus_SDK_Stickers::remove_folder( $dir );
        }
    }
}
