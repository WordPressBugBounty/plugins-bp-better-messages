<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Sticker_Packs' ) ) {
    final class WordPlus_SDK_Sticker_Packs
    {
        private $stickers;

        private $packs_cache = null;

        public function __construct( WordPlus_SDK_Stickers $stickers )
        {
            $this->stickers = $stickers;
        }

        public function get_all()
        {
            if ( $this->packs_cache !== null ) {
                return $this->packs_cache;
            }
            $packs = get_option( WordPlus_SDK_Stickers::OPTION, array() );
            if ( ! is_array( $packs ) ) {
                $packs = array();
            }
            usort( $packs, function( $a, $b ) {
                $oa = isset( $a['sort_order'] ) ? (int) $a['sort_order'] : 0;
                $ob = isset( $b['sort_order'] ) ? (int) $b['sort_order'] : 0;
                if ( $oa === $ob ) {
                    return strcmp( isset( $a['id'] ) ? $a['id'] : '', isset( $b['id'] ) ? $b['id'] : '' );
                }
                return $oa - $ob;
            } );

            $rewritten = $this->rewrite_packs_urls( $packs, $changed );
            $this->packs_cache = $rewritten;

            if ( $changed ) {
                update_option( WordPlus_SDK_Stickers::OPTION, $rewritten, false );
                $this->stickers->manifest()->delete_manifests();
                $this->update_hash();
            }

            return $rewritten;
        }

        public function rewrite_packs_urls( $packs, &$changed = null )
        {
            $changed = false;
            if ( ! is_array( $packs ) || empty( $packs ) ) {
                return $packs;
            }

            $first_pack    = $packs[0];
            $sample_cover  = isset( $first_pack['cover'] ) ? $first_pack['cover'] : '';
            $sample_file   = '';
            if ( ! empty( $first_pack['stickers'] ) && is_array( $first_pack['stickers'] ) ) {
                $first_sticker = $first_pack['stickers'][0];
                if ( isset( $first_sticker['file'] ) ) {
                    $sample_file = $first_sticker['file'];
                }
            }

            if ( $this->url_is_current( $sample_cover ) && $this->url_is_current( $sample_file ) ) {
                return $packs;
            }

            foreach ( $packs as $pi => $pack ) {
                if ( isset( $pack['cover'] ) ) {
                    $new = $this->rewrite_url( $pack['cover'] );
                    if ( $new !== $pack['cover'] ) {
                        $packs[ $pi ]['cover'] = $new;
                        $changed = true;
                    }
                }

                if ( ! empty( $pack['translations'] ) && is_array( $pack['translations'] ) ) {
                    foreach ( $pack['translations'] as $loc => $entry ) {
                        if ( isset( $entry['cover'] ) ) {
                            $new = $this->rewrite_url( $entry['cover'] );
                            if ( $new !== $entry['cover'] ) {
                                $packs[ $pi ]['translations'][ $loc ]['cover'] = $new;
                                $changed = true;
                            }
                        }
                    }
                }

                if ( ! empty( $pack['stickers'] ) && is_array( $pack['stickers'] ) ) {
                    foreach ( $pack['stickers'] as $si => $sticker ) {
                        if ( isset( $sticker['file'] ) ) {
                            $new = $this->rewrite_url( $sticker['file'] );
                            if ( $new !== $sticker['file'] ) {
                                $packs[ $pi ]['stickers'][ $si ]['file'] = $new;
                                $changed = true;
                            }
                        }
                        if ( ! empty( $sticker['translations'] ) && is_array( $sticker['translations'] ) ) {
                            foreach ( $sticker['translations'] as $loc => $entry ) {
                                if ( isset( $entry['file'] ) ) {
                                    $new = $this->rewrite_url( $entry['file'] );
                                    if ( $new !== $entry['file'] ) {
                                        $packs[ $pi ]['stickers'][ $si ]['translations'][ $loc ]['file'] = $new;
                                        $changed = true;
                                    }
                                }
                            }
                        }
                    }
                }
            }

            return $packs;
        }

        protected function url_is_current( $url )
        {
            return ! is_string( $url ) || $url === '' || $this->rewrite_url( $url ) === $url;
        }

        public function rewrite_url( $url )
        {
            return $this->stickers->rewrite_url( $url );
        }

        public function get( $id )
        {
            foreach ( $this->get_all() as $pack ) {
                if ( isset( $pack['id'] ) && $pack['id'] === $id ) {
                    return $pack;
                }
            }
            return null;
        }

        public function exists( $id )
        {
            return $this->get( $id ) !== null;
        }

        public function create( $data )
        {
            $pack = $this->normalize_pack( $data );

            if ( empty( $pack['id'] ) ) {
                $pack['id'] = $this->generate_unique_id( isset( $data['title'] ) ? $data['title'] : 'pack' );
            } elseif ( $this->exists( $pack['id'] ) ) {
                return new WP_Error( 'pack_exists', __( 'A sticker pack with this id already exists.', 'wordplus-sdk' ) );
            }

            if ( empty( $pack['title'] ) ) {
                return new WP_Error( 'pack_missing_title', __( 'Sticker pack title is required.', 'wordplus-sdk' ) );
            }

            $packs = $this->get_all();

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

        public function update( $id, $data )
        {
            $packs = $this->get_all();
            $found = false;
            foreach ( $packs as $index => $pack ) {
                if ( $pack['id'] === $id ) {
                    $merged = array_merge( $pack, $this->normalize_pack( $data, false ) );
                    $merged['id']         = $pack['id'];
                    $merged['created_at'] = isset( $pack['created_at'] ) ? $pack['created_at'] : time();
                    $merged['updated_at'] = time();
                    if ( ! array_key_exists( 'stickers', $data ) ) {
                        $merged['stickers'] = isset( $pack['stickers'] ) ? $pack['stickers'] : array();
                    }
                    $packs[ $index ] = $merged;
                    $found           = true;
                    break;
                }
            }

            if ( ! $found ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ) );
            }

            $this->save_all( $packs );
            return $this->get( $id );
        }

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
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ) );
            }

            $this->save_all( $filtered );
            return true;
        }

        public function reorder( $order )
        {
            if ( ! is_array( $order ) ) {
                return new WP_Error( 'invalid_order', __( 'Invalid order data.', 'wordplus-sdk' ) );
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
            foreach ( $by_id as $pack ) {
                $pack['sort_order'] = $position++;
                $reordered[]        = $pack;
            }

            $this->save_all( $reordered );
            return true;
        }

        public function add_sticker( $pack_id, $sticker )
        {
            $pack = $this->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ) );
            }

            $sticker = $this->normalize_sticker( $sticker );

            if ( empty( $sticker['file'] ) ) {
                return new WP_Error( 'sticker_missing_file', __( 'Sticker file is required.', 'wordplus-sdk' ) );
            }

            if ( empty( $sticker['id'] ) ) {
                $sticker['id'] = $this->generate_unique_sticker_id( $pack, isset( $sticker['name'] ) ? $sticker['name'] : '' );
            } else {
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

        public function remove_sticker( $pack_id, $sticker_id )
        {
            $pack = $this->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ) );
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
                return new WP_Error( 'sticker_not_found', __( 'Sticker not found.', 'wordplus-sdk' ) );
            }

            $pack['stickers']   = $filtered;
            $pack['updated_at'] = time();
            $this->replace_pack( $pack );

            return true;
        }

        public function reorder_stickers( $pack_id, $order )
        {
            $pack = $this->get( $pack_id );
            if ( ! $pack ) {
                return new WP_Error( 'pack_not_found', __( 'Sticker pack not found.', 'wordplus-sdk' ) );
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

        public function get_upload_dir( $pack_id = null, $language = '' )
        {
            $uploads = $this->stickers->uploads();

            if ( $pack_id ) {
                $dir  = $this->pack_dir( $pack_id );
                $path = $uploads['path'] . '/' . $dir;
                $url  = $uploads['url'] . '/' . implode( '/', array_map( 'rawurlencode', explode( '/', $dir ) ) );
            } else {
                $path = $uploads['path'] . '/' . WordPlus_SDK_Stickers::ROOT . '/packs';
                $url  = $uploads['url'] . '/' . WordPlus_SDK_Stickers::ROOT . '/packs';
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

        public function delete_pack_assets( $pack_id, $dir = null )
        {
            $dir     = null === $dir ? $this->pack_dir( $pack_id ) : $this->safe_dir( (string) $dir, $pack_id );
            $uploads = $this->stickers->uploads();
            $path    = $uploads['path'] . '/' . $dir;
            if ( ! is_dir( $path ) ) {
                return;
            }
            $this->rmrf_dir( $path );
        }

        public function pack_dir( $pack_id )
        {
            $pack = $this->get( (string) $pack_id );

            return $this->safe_dir( $pack && isset( $pack['dir'] ) ? (string) $pack['dir'] : '', $pack_id );
        }

        protected function safe_dir( $dir, $pack_id )
        {
            $dir = trim( str_replace( '\\', '/', $dir ), '/' );

            if ( '' !== $dir && false === strpos( $dir, '..' ) ) {
                foreach ( $this->stickers->roots() as $root ) {
                    if ( dirname( $dir ) === $root ) {
                        return $dir;
                    }
                }
            }

            return WordPlus_SDK_Stickers::ROOT . '/packs/' . sanitize_file_name( (string) $pack_id );
        }

        protected function rmrf_dir( $dir )
        {
            WordPlus_SDK_Stickers::remove_folder( $dir );
        }

        public function save_all( $packs )
        {
            $packs = array_values( (array) $packs );
            update_option( WordPlus_SDK_Stickers::OPTION, $packs, false );
            $this->packs_cache = null;
            $this->packs_cache = $this->get_all();
            $this->update_hash();

            $this->stickers->manifest()->delete_manifests();

            do_action( 'wordplus_sticker_packs_saved', $this->packs_cache, get_option( WordPlus_SDK_Stickers::META_OPTION, array() ) );
        }

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
            $packs[] = $updated;
            $this->save_all( $packs );
        }

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

            update_option( WordPlus_SDK_Stickers::META_OPTION, array( 'hash' => $hash, 'summary' => $summary ), true );

            return $hash;
        }

        public function get_hash()
        {
            return $this->stickers->hash();
        }

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
            if ( empty( $base ) || strlen( $base ) > 40 || strpos( $base, '%' ) !== false ) {
                $base = 'pack-' . substr( md5( $seed . wp_rand() ), 0, 6 );
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
            if ( empty( $base ) || strlen( $base ) > 40 || strpos( $base, '%' ) !== false ) {
                $base = 'sticker-' . substr( md5( $seed . wp_rand() ), 0, 6 );
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
