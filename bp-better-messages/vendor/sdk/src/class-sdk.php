<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK' ) ) {
    final class WordPlus_SDK
    {
        const CLOUD = 'cloud';

        const SERVER = 'https://api.wordplus.cloud/';

        private static $instance = null;

        private $path = '';

        private $version = '';

        private $products = array();

        private $licences = array();

        private $admin_loaded = false;

        const PUSH_QUERY_VAR = 'wordplus-sw';

        private $push = null;

        private $pickers = null;

        const STORAGE_JOB = 'wordplus_storage_job';

        const EMOJI_CUT = 'wordplus_pickers_cut_emoji';

        private $storage = null;

        private $toasts = null;

        private $tooltips = null;

        private $menus = null;

        private $lightbox = null;

        private $emoji_guard = null;

        private $assets = null;

        private $asset_hashes = array();

        const PARTS = array(
            'updater'     => array( 'src/class-updater.php' ),
            'push'        => array( 'src/class-push.php', 'src/class-push-table.php', 'src/class-push-progressify.php', 'push' ),
            'storage'     => array( 'src/class-storage.php', 'src/class-storage-admin.php', 'src/class-storage-direct.php', 'src/class-storage-queue.php', 'src/class-storage-s3.php', 'src/class-storage-sweeper.php', 'storage' ),
            'pickers'     => array( 'src/class-pickers.php', 'src/pickers', 'pickers', 'stickers' ),
            'toasts'      => array( 'src/class-toasts.php', 'toasts' ),
            'tooltips'    => array( 'src/class-tooltips.php', 'tooltips' ),
            'menus'       => array( 'src/class-menus.php', 'menus' ),
            'lightbox'    => array( 'src/class-lightbox.php', 'lightbox' ),
            'emoji-guard' => array( 'src/class-emoji-guard.php', 'emoji-guard' ),
        );

        private $part_copies = array();

        const PAGE_OPTIONS = array(
            'pickers' => array( 'wordplus_pickers' ),
            'storage' => array( 'wordplus_storage' ),
            'push'    => array( 'wordplus_push_keys', 'bp_better_messages_vapid_keys' ),
        );

        const TRACKING_SYNC = 'wordplus_sdk_tracking_sync';

        const TRACKING_SOON = 'wordplus_sdk_tracking_soon';

        const WAITING_EVENT = 'wordplus_sdk_check_waiting';

        private $tracking = null;

        private $i18n = null;

        public static function instance()
        {
            if ( null === self::$instance ) {
                self::$instance = new self();
            }

            return self::$instance;
        }

        public static function boot( $path, $version, array $products )
        {
            $sdk          = self::instance();
            $sdk->path    = rtrim( (string) $path, '/' );
            $sdk->version = (string) $version;

            foreach ( $products as $product ) {
                $sdk->register( $product );
            }

            add_action( 'rest_api_init', array( $sdk, 'rest_api_init' ), 0 );

            add_filter( 'query_vars', array( $sdk, 'push_query_var' ) );
            add_action( 'parse_request', array( $sdk, 'maybe_serve_push_worker' ) );

            add_action( 'deleted_user', array( $sdk, 'push_deleted_user' ) );

            add_action( self::TRACKING_SYNC, array( $sdk, 'run_tracking_sync' ) );
            add_action( self::TRACKING_SOON, array( $sdk, 'run_tracking_sync' ) );

            add_action( self::WAITING_EVENT, array( $sdk, 'check_waiting' ), 10, 2 );

            add_action( self::STORAGE_JOB, array( $sdk, 'run_storage_job' ) );

            add_action( self::EMOJI_CUT, array( $sdk, 'run_emoji_cut' ) );

            if ( is_multisite() ) {
                add_action( 'wp_uninitialize_site', array( $sdk, 'retire_storage_site' ), 1 );
            }

            if ( is_admin() ) {
                add_action( 'init', array( $sdk, 'load_text_domain' ), 0 );
                add_action( 'admin_init', array( $sdk, 'take_hand_overs' ) );
                add_action( 'pre_uninstall_plugin', array( $sdk, 'tracking_uninstalling' ) );
                $sdk->load_admin();
            }

            if ( $sdk->wants_updaters() ) {
                $sdk->load_updaters();
            }
        }

        public function register( array $config )
        {
            $product = new WordPlus_SDK_Product( $config );

            $this->products[ $product->slug() ] = $product;

            $this->licence( $product );

            if ( '' !== $product->file() && ( is_admin() || ( defined( 'WP_CLI' ) && WP_CLI ) ) ) {
                add_action( 'activate_' . $product->basename(), function ( $network_wide = false ) use ( $product ) {
                    $this->tracking()->activated( $product, (bool) $network_wide );
                    $product->admin()->activated();
                } );
                add_action( 'deactivate_' . $product->basename(), function ( $network_wide = false ) use ( $product ) {
                    $this->tracking()->deactivated( $product, (bool) $network_wide );
                } );
            }

            if ( $this->admin_loaded ) {
                $product->admin();
                $this->tracking()->admin_hooks();
            }

            if ( '' !== $this->path && $this->wants_updaters() ) {
                $product->updater();
            }

            if ( did_action( 'init' ) ) {
                $this->take_hand_over( $product );
            }
        }

        const HAND_OVER_TTL = 2592000;

        private function hand_over_option( $slug )
        {
            return 'wordplus_sdk_handover_' . str_replace( '-', '_', (string) $slug );
        }

        public function hand_over( $slug, array $answer, $url )
        {
            $product = $this->product( $slug );

            if ( null !== $product ) {
                $product->licence()->store_purchase( $answer, $slug );

                return;
            }

            update_site_option( $this->hand_over_option( $slug ), array( 'answer' => $answer, 'url' => (string) $url, 'time' => time(), 'checked' => time() ) );
        }

        const WAITING_CHECK = 43200;

        public function waiting( $slug, $url )
        {
            $waiting = $this->waiting_record( $slug, $url );

            if ( null === $waiting ) {
                return false;
            }

            $args = array( (string) $slug, (string) $url );

            if ( ( ! isset( $waiting['checked'] ) || (int) $waiting['checked'] < time() - self::WAITING_CHECK ) && ! wp_next_scheduled( self::WAITING_EVENT, $args ) ) {
                wp_schedule_single_event( time(), self::WAITING_EVENT, $args );
            }

            return true;
        }

        private function waiting_record( $slug, $url )
        {
            $waiting = get_site_option( $this->hand_over_option( $slug ), null );

            if ( ! is_array( $waiting ) || empty( $waiting['answer'] ) || ! is_array( $waiting['answer'] )
                || (int) $waiting['time'] < time() - self::HAND_OVER_TTL || (string) $waiting['url'] !== (string) $url ) {
                return null;
            }

            return $waiting;
        }

        public function check_waiting( $slug, $url )
        {
            $option  = $this->hand_over_option( (string) $slug );
            $waiting = $this->waiting_record( $slug, $url );

            if ( null === $waiting ) {
                return;
            }

            $licence = null;

            foreach ( $this->products as $product ) {
                $licence = $product->licence();
                break;
            }

            $key = isset( $waiting['answer']['license_key'] ) ? (string) $waiting['answer']['license_key'] : '';

            if ( null === $licence || '' === $key ) {
                return;
            }

            $answer = $licence->request( 'api/v1/licenses/status', array( 'license_key' => $key, 'plugin' => (string) $slug, 'url' => (string) $url ) );
            $gone   = is_wp_error( $answer )
                ? in_array( $answer->get_error_code(), array( 'invalid_license', 'wrong_plugin' ), true )
                : ( ! isset( $answer['status'] ) || 'valid' !== $answer['status'] || empty( $answer['activation'] ) );

            if ( $gone ) {
                delete_site_option( $option );

                return;
            }

            $waiting['checked'] = time();
            update_site_option( $option, $waiting );
        }

        public function dismiss_waiting( $slug )
        {
            delete_site_option( $this->hand_over_option( $slug ) );
        }

        public function take_hand_overs()
        {
            foreach ( $this->products as $product ) {
                $this->take_hand_over( $product );
            }
        }

        private function take_hand_over( WordPlus_SDK_Product $product )
        {
            $option  = $this->hand_over_option( $product->slug() );
            $waiting = get_site_option( $option, null );

            if ( ! is_array( $waiting ) || empty( $waiting['answer'] ) || ! is_array( $waiting['answer'] ) ) {
                return;
            }

            if ( (int) $waiting['time'] < time() - self::HAND_OVER_TTL ) {
                delete_site_option( $option );

                return;
            }

            if ( $waiting['url'] !== $product->licence()->url() ) {
                return;
            }

            $product->licence()->store_purchase( $waiting['answer'], $product->slug() );
            delete_site_option( $option );
        }

        private function wants_updaters()
        {
            return is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI );
        }

        public function product( $slug )
        {
            return isset( $this->products[ $slug ] ) ? $this->products[ $slug ] : null;
        }

        public function products()
        {
            return $this->products;
        }

        public function host( $slug )
        {
            $addon = $this->product( $slug );

            foreach ( $this->products as $product ) {
                if ( $product->slug() !== $slug && '' !== $product->addon( $slug )
                    && ( null === $addon || $addon->licence()->is_network() === $product->licence()->is_network() ) ) {
                    return $product;
                }
            }

            return null;
        }

        public function licence( WordPlus_SDK_Product $product )
        {
            $store = $product->licence_store();

            if ( ! isset( $this->licences[ $store ] ) ) {
                $this->licences[ $store ] = new WordPlus_SDK_Licence( $store, $this );
            }

            return $this->licences[ $store ];
        }

        public function products_on( $store )
        {
            return array_values( array_filter( $this->products, function ( WordPlus_SDK_Product $product ) use ( $store ) {
                return $product->licence_store() === $store;
            } ) );
        }

        public function server()
        {
            return trailingslashit( (string) apply_filters( 'wordplus_sdk_server', self::SERVER ) );
        }

        public function path( $relative = '' )
        {
            return $this->copy_for( (string) $relative ) . ( '' === $relative ? '' : '/' . ltrim( $relative, '/' ) );
        }

        public function url( $relative = '' )
        {
            return plugins_url( ltrim( $relative, '/' ), $this->copy_for( (string) $relative ) . '/start.php' );
        }

        public static function part_of( $relative )
        {
            $relative = trim( (string) $relative, '/' );

            foreach ( self::PARTS as $part => $paths ) {
                foreach ( $paths as $path ) {
                    if ( $relative === $path || 0 === strpos( $relative, $path . '/' ) ) {
                        return $part;
                    }
                }
            }

            return null;
        }

        public function part_copy( $part )
        {
            if ( ! isset( self::PARTS[ $part ] ) ) {
                return '';
            }

            if ( ! isset( $this->part_copies[ $part ] ) ) {
                $marker = self::PARTS[ $part ][0];
                $found  = is_readable( $this->path . '/' . $marker ) ? $this->path . '/' . $marker : $this->locate( $marker );

                $this->part_copies[ $part ] = null === $found ? '' : substr( $found, 0, - strlen( '/' . $marker ) );
            }

            return $this->part_copies[ $part ];
        }

        public function has_part( $part )
        {
            return '' !== $this->part_copy( $part );
        }

        private function copy_for( $relative )
        {
            $part = self::part_of( $relative );
            $copy = null === $part ? '' : $this->part_copy( $part );

            return '' === $copy ? $this->path : $copy;
        }

        public function version()
        {
            return $this->version;
        }

        public static function name_table( $property, $table )
        {
            global $wpdb;

            if ( ! is_object( $wpdb ) ) {
                return;
            }

            if ( isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], '6.1', '<' ) ) {
                @$wpdb->$property = $table;
            } else {
                $wpdb->$property = $table;
            }
        }

        public static function files()
        {
            static $files = null;

            if ( null === $files ) {
                if ( ! class_exists( 'WP_Filesystem_Direct', false ) ) {
                    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
                    require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
                }

                $files = new WP_Filesystem_Direct( null );
            }

            return $files;
        }

        public static function several_activated()
        {
            return did_action( 'activated_plugin' ) > 1;
        }

        public function locate( $relative )
        {
            $found   = null;
            $version = '0';
            $copies  = isset( $GLOBALS['wordplus_sdk_copies'] ) && is_array( $GLOBALS['wordplus_sdk_copies'] ) ? $GLOBALS['wordplus_sdk_copies'] : array();

            $copies[ $this->path ] = $this->version;

            foreach ( $copies as $path => $copy_version ) {
                if ( version_compare( (string) $copy_version, $version, '>=' ) && is_readable( $path . '/' . $relative ) ) {
                    $found   = $path . '/' . $relative;
                    $version = (string) $copy_version;
                }
            }

            return $found;
        }

        public function load_text_domain()
        {
            if ( ! is_textdomain_loaded( 'wordplus-sdk' ) ) {
                load_textdomain( 'wordplus-sdk', $this->path( 'languages/wordplus-sdk-' . determine_locale() . '.mo' ) );
            }
        }

        public function tracking()
        {
            if ( null === $this->tracking ) {
                require_once __DIR__ . '/class-tracking.php';
                $this->tracking = new WordPlus_SDK_Tracking( $this );
            }

            return $this->tracking;
        }

        public function i18n()
        {
            if ( null === $this->i18n ) {
                require_once __DIR__ . '/class-i18n.php';
                $this->i18n = new WordPlus_SDK_I18n( $this );
            }

            return $this->i18n;
        }

        public function run_tracking_sync()
        {
            $this->tracking()->run_sync();
        }

        public function tracking_uninstalling( $basename )
        {
            $this->tracking()->uninstalling( (string) $basename );
        }

        public function load_admin()
        {
            $this->admin_loaded = true;

            require_once __DIR__ . '/class-ui.php';
            WordPlus_SDK_UI::register_hooks();

            foreach ( $this->products as $product ) {
                $product->admin();
            }

            if ( ! empty( $this->products ) ) {
                $this->tracking()->admin_hooks();
            }
        }

        public function rest_api_init()
        {
            $this->load_text_domain();

            foreach ( $this->products as $product ) {
                $product->admin()->routes();
                $this->tracking()->routes( $product );

                if ( $product->install() ) {
                    $product->install()->routes();
                }
            }

            if ( ! empty( $this->products ) ) {
                if ( $this->push() ) {
                    $this->push()->routes();
                }

                if ( $this->pickers() ) {
                    $this->pickers()->routes();
                }

                if ( $this->storage() ) {
                    $this->storage()->admin()->routes();
                }
            }
        }

        public function page_options( array $parts )
        {
            $options = array();

            foreach ( $parts as $part ) {
                if ( isset( self::PAGE_OPTIONS[ $part ] ) ) {
                    $options = array_merge( $options, self::PAGE_OPTIONS[ $part ] );
                }
            }

            return array_values( array_unique( $options ) );
        }

        public function pickers()
        {
            if ( null === $this->pickers && $this->has_part( 'pickers' ) ) {
                require_once $this->path( 'src/class-pickers.php' );
                $this->pickers = new WordPlus_SDK_Pickers( $this );
            }

            return $this->pickers;
        }

        public function storage()
        {
            if ( null === $this->storage && $this->has_part( 'storage' ) ) {
                require_once $this->path( 'src/class-storage.php' );
                $this->storage = new WordPlus_SDK_Storage( $this );
            }

            return $this->storage;
        }

        public function toasts()
        {
            if ( null === $this->toasts && $this->has_part( 'toasts' ) ) {
                require_once $this->path( 'src/class-toasts.php' );
                $this->toasts = new WordPlus_SDK_Toasts( $this );
            }

            return $this->toasts;
        }

        public function tooltips()
        {
            if ( null === $this->tooltips && $this->has_part( 'tooltips' ) ) {
                require_once $this->path( 'src/class-tooltips.php' );
                $this->tooltips = new WordPlus_SDK_Tooltips( $this );
            }

            return $this->tooltips;
        }

        public function emoji_guard()
        {
            if ( null === $this->emoji_guard && $this->has_part( 'emoji-guard' ) ) {
                require_once $this->path( 'src/class-emoji-guard.php' );
                $this->emoji_guard = new WordPlus_SDK_Emoji_Guard( $this );
            }

            return $this->emoji_guard;
        }

        public function menus()
        {
            if ( null === $this->menus && $this->has_part( 'menus' ) ) {
                require_once $this->path( 'src/class-menus.php' );
                $this->menus = new WordPlus_SDK_Menus( $this );
            }

            return $this->menus;
        }

        public function lightbox()
        {
            if ( null === $this->lightbox && $this->has_part( 'lightbox' ) ) {
                require_once $this->path( 'src/class-lightbox.php' );
                $this->lightbox = new WordPlus_SDK_Lightbox( $this );
            }

            return $this->lightbox;
        }

        public function script_chain( array $handles )
        {
            $scripts = wp_scripts();
            $list    = array();
            $seen    = array();

            $walk = function ( $handle ) use ( &$walk, &$list, &$seen, $scripts ) {
                if ( isset( $seen[ $handle ] ) || empty( $scripts->registered[ $handle ] ) ) {
                    return;
                }

                $seen[ $handle ] = true;
                $script          = $scripts->registered[ $handle ];

                foreach ( (array) $script->deps as $dep ) {
                    $walk( $dep );
                }

                if ( ! $script->src ) {
                    return;
                }

                $src = preg_match( '#^(https?:)?//#', $script->src ) ? $script->src : $scripts->base_url . $script->src;
                $ver = false === $script->ver ? $scripts->default_version : $script->ver;

                $list[] = array(
                    'handle' => $handle,
                    'src'    => $ver ? add_query_arg( 'ver', $ver, $src ) : $src,
                );
            };

            foreach ( $handles as $handle ) {
                $walk( $handle );
            }

            return $list;
        }

        public function asset_version( $file )
        {
            $this->assets()->hook();

            $copy    = $this->copy_for( (string) $file );
            $copies  = isset( $GLOBALS['wordplus_sdk_copies'] ) && is_array( $GLOBALS['wordplus_sdk_copies'] ) ? $GLOBALS['wordplus_sdk_copies'] : array();
            $version = $copy !== $this->path && isset( $copies[ $copy ] ) ? (string) $copies[ $copy ] : $this->version();
            $hashes  = $this->asset_hashes( $copy );

            if ( isset( $hashes[ $file ] ) ) {
                return $version . '.' . $hashes[ $file ];
            }

            $path = $this->path( $file );

            return $version . ( file_exists( $path ) ? '.' . filemtime( $path ) : '' );
        }

        public function asset_url( $file )
        {
            return WordPlus_SDK_Assets::versioned( $this->url( $file ), $this->asset_version( $file ) );
        }

        public function assets()
        {
            if ( null === $this->assets ) {
                require_once __DIR__ . '/class-assets.php';
                $this->assets = new WordPlus_SDK_Assets( $this );
            }

            return $this->assets;
        }

        private function asset_hashes( $copy )
        {
            if ( ! isset( $this->asset_hashes[ $copy ] ) ) {
                $file   = $copy === $this->path ? __DIR__ . '/asset-hashes.php' : $copy . '/src/asset-hashes.php';
                $hashes = file_exists( $file ) ? include $file : array();

                $this->asset_hashes[ $copy ] = is_array( $hashes ) ? $hashes : array();
            }

            return $this->asset_hashes[ $copy ];
        }

        public function run_storage_job()
        {
            if ( $this->storage() ) {
                $this->storage()->queue()->run();
            }
        }

        public function run_emoji_cut( $set )
        {
            if ( $this->pickers() ) {
                $this->pickers()->emoji()->cut_images( (string) $set );
            }
        }

        public function retire_storage_site( $site )
        {
            if ( $this->storage() ) {
                $this->storage()->queue()->retire_site( $site );
            }
        }

        public function push()
        {
            if ( null === $this->push && $this->has_part( 'push' ) ) {
                require_once $this->path( 'src/class-push.php' );
                $this->push = new WordPlus_SDK_Push( $this );
            }

            return $this->push;
        }

        public function push_query_var( $vars )
        {
            $vars[] = self::PUSH_QUERY_VAR;

            return $vars;
        }

        public function maybe_serve_push_worker( $wp )
        {
            if ( is_object( $wp ) && ! empty( $wp->query_vars[ self::PUSH_QUERY_VAR ] ) ) {
                $this->serve_push_worker();
            }
        }

        public function serve_push_worker()
        {
            if ( ! empty( $this->products ) && $this->push() ) {
                $this->push()->serve_worker();
            }
        }

        public function push_deleted_user( $user_id )
        {
            if ( $this->push() ) {
                $this->push()->store()->remove_user( (int) $user_id );
            }
        }

        public function load_updaters()
        {
            foreach ( $this->products as $product ) {
                $product->updater();
            }
        }
    }
}
