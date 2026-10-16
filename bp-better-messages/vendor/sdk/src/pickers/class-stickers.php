<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Stickers' ) ) {
    final class WordPlus_SDK_Stickers
    {
        const OPTION = 'wordplus_sticker_packs';

        const META_OPTION = 'wordplus_sticker_packs_meta';

        const ROOT = 'wordplus/stickers';

        const ROOTS_OPTION = 'wordplus_sticker_roots';

        private $pickers;

        private $version;

        private $packs = null;

        private $manifest = null;

        private $catalog = null;

        private $rest = null;

        private $admin = null;

        public function __construct( WordPlus_SDK_Pickers $pickers, $version = '' )
        {
            $this->pickers = $pickers;
            $this->version = (string) $version;
        }

        public function pickers()
        {
            return $this->pickers;
        }

        public function version()
        {
            return $this->version;
        }

        public function packs()
        {
            if ( null === $this->packs ) {
                require_once __DIR__ . '/class-sticker-packs.php';
                $this->packs = new WordPlus_SDK_Sticker_Packs( $this );
            }

            return $this->packs;
        }

        public function manifest()
        {
            if ( null === $this->manifest ) {
                require_once __DIR__ . '/class-sticker-manifest.php';
                $this->manifest = new WordPlus_SDK_Sticker_Manifest( $this );
            }

            return $this->manifest;
        }

        public function catalog()
        {
            if ( null === $this->catalog ) {
                require_once __DIR__ . '/class-sticker-catalog.php';
                $this->catalog = new WordPlus_SDK_Sticker_Catalog( $this );
            }

            return $this->catalog;
        }

        public function rest()
        {
            if ( null === $this->rest ) {
                require_once __DIR__ . '/class-stickers-rest.php';
                $this->rest = new WordPlus_SDK_Stickers_REST( $this );
            }

            return $this->rest;
        }

        public function admin()
        {
            if ( null === $this->admin ) {
                require_once __DIR__ . '/class-stickers-admin.php';
                $this->admin = new WordPlus_SDK_Stickers_Admin( $this, $this->pickers->sdk() );
            }

            return $this->admin;
        }

        public function routes()
        {
            $this->rest()->routes();
        }

        public function roots()
        {
            $roots   = array( self::ROOT . '/packs' );
            $adopted = get_option( self::ROOTS_OPTION, array() );

            foreach ( array_merge( is_array( $adopted ) ? $adopted : array(), (array) apply_filters( 'wordplus_stickers_pack_roots', array() ) ) as $root ) {
                $root = trim( str_replace( '\\', '/', (string) $root ), '/' );

                if ( '' !== $root && false === strpos( $root, '..' ) && ! in_array( $root, $roots, true ) ) {
                    $roots[] = $root;
                }
            }

            return $roots;
        }

        public function uploads()
        {
            $upload = wp_upload_dir();

            return array(
                'path' => untrailingslashit( (string) $upload['basedir'] ),
                'url'  => untrailingslashit( (string) $upload['baseurl'] ),
            );
        }

        public static function remove_folder( $dir )
        {
            if ( ! is_dir( $dir ) || is_link( $dir ) ) {
                return;
            }

            if ( ! class_exists( 'WP_Filesystem_Direct', false ) ) {
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
            }

            $filesystem = new WP_Filesystem_Direct( null );
            $children   = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ( $children as $child ) {
                if ( $child->isDir() && ! $child->isLink() ) {
                    $filesystem->rmdir( $child->getPathname() );
                } else {
                    wp_delete_file( $child->getPathname() );
                }
            }

            $filesystem->rmdir( $dir );
        }

        public function local_path( $url )
        {
            $place = $this->place( $url );

            if ( null === $place ) {
                return false;
            }

            $uploads   = $this->uploads();
            $real      = realpath( $uploads['path'] . '/' . $place['relative'] );
            $real_root = realpath( $uploads['path'] . '/' . $place['root'] );

            if ( false === $real || false === $real_root || 0 !== strpos( $real, trailingslashit( $real_root ) ) ) {
                return false;
            }

            return $real;
        }

        public function owns_url( $url )
        {
            return null !== $this->place( $url );
        }

        private function place( $url )
        {
            if ( ! is_string( $url ) || '' === $url || false !== strpos( $url, '..' ) || false !== strpos( $url, "\0" ) ) {
                return null;
            }

            $url  = $this->rewrite_url( $url );
            $base = trailingslashit( $this->uploads()['url'] );

            if ( 0 !== strpos( $url, $base ) ) {
                return null;
            }

            $relative = rawurldecode( (string) wp_parse_url( substr( $url, strlen( $base ) ), PHP_URL_PATH ) );

            if ( '' === $relative || false !== strpos( $relative, '..' ) || false !== strpos( $relative, "\0" ) ) {
                return null;
            }

            foreach ( $this->roots() as $root ) {
                if ( 0 === strpos( $relative, $root . '/' ) ) {
                    return array( 'relative' => $relative, 'root' => $root );
                }
            }

            return null;
        }

        public function rewrite_url( $url )
        {
            if ( ! is_string( $url ) || '' === $url ) {
                return $url;
            }

            $path = wp_parse_url( $url, PHP_URL_PATH );

            if ( ! $path ) {
                return $url;
            }

            $base = trailingslashit( $this->uploads()['url'] );

            if ( '' === $base || 0 === strpos( $url, $base ) ) {
                return $url;
            }

            foreach ( $this->url_roots() as $root ) {
                $at = strpos( $path, '/' . $root . '/' );

                if ( false !== $at ) {
                    return esc_url_raw( $base . substr( $path, $at + 1 ) );
                }
            }

            return $url;
        }

        public function url_roots()
        {
            $roots = array();

            foreach ( $this->roots() as $root ) {
                $parent = dirname( $root );
                $roots[] = '.' === $parent ? $root : $parent;
            }

            return array_values( array_unique( $roots ) );
        }

        public function user_roles( $user_id = null )
        {
            $user_id = null === $user_id ? $this->pickers->current_user_id() : (int) $user_id;
            $roles   = array();

            if ( $user_id > 0 ) {
                $user  = get_userdata( $user_id );
                $roles = $user && isset( $user->roles ) ? (array) $user->roles : array();
            }

            return array_values( array_map( 'strval', (array) apply_filters( 'wordplus_pickers_user_roles', $roles, $user_id ) ) );
        }

        public function allowed_pack_ids( $user_id = null )
        {
            $roles   = $this->user_roles( $user_id );
            $allowed = array();

            foreach ( $this->summary() as $pack ) {
                $pack_roles = isset( $pack['allowed_roles'] ) ? (array) $pack['allowed_roles'] : array();

                if ( empty( $pack_roles ) || array_intersect( $pack_roles, $roles ) ) {
                    $allowed[] = (string) $pack['id'];
                }
            }

            return $allowed;
        }

        public function manifest_url( $locale = null )
        {
            $url = $this->manifest()->get_manifest_url( $locale );

            return is_string( $url ) ? $url : '';
        }

        public function user_settings()
        {
            return array(
                'stickerManifestUrl'  => $this->manifest_url(),
                'allowedStickerPacks' => $this->allowed_pack_ids(),
            );
        }

        public function hash()
        {
            $meta = get_option( self::META_OPTION, array() );

            return is_array( $meta ) && isset( $meta['hash'] ) ? (string) $meta['hash'] : '';
        }

        public function summary()
        {
            $meta = get_option( self::META_OPTION, array() );

            return is_array( $meta ) && isset( $meta['summary'] ) && is_array( $meta['summary'] ) ? $meta['summary'] : array();
        }

        public function adopt( array $packs, $root )
        {
            if ( false !== get_option( self::OPTION, false ) || empty( $packs ) ) {
                return false;
            }

            $root    = trim( (string) $root, '/' );
            $adopted = array();

            if ( '' !== $root && false === strpos( $root, '..' ) ) {
                $roots = get_option( self::ROOTS_OPTION, array() );
                $roots = is_array( $roots ) ? $roots : array();
                if ( ! in_array( $root, $roots, true ) ) {
                    $roots[] = $root;
                    update_option( self::ROOTS_OPTION, $roots, true );
                }
            }

            foreach ( $packs as $pack ) {
                if ( ! is_array( $pack ) || empty( $pack['id'] ) ) {
                    continue;
                }

                if ( empty( $pack['dir'] ) && '' !== $root ) {
                    $pack['dir'] = $root . '/' . sanitize_file_name( (string) $pack['id'] );
                }

                $adopted[] = $pack;
            }

            $this->packs()->save_all( $adopted );

            return true;
        }
    }
}
