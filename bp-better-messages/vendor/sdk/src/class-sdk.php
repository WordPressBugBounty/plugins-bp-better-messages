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

        private $app = null;

        private $pickers = null;

        const STORAGE_JOB = 'wordplus_storage_job';

        const ENCRYPTION_JOB = 'wordplus_encryption_job';

        const VIDEO_EFFECTS_UPDATE = 'wordplus_video_effects_update';

        const RECORDING_SYNC = 'wordplus_recording_sync';

        const RECORDING_SWEEP = 'wordplus_recording_sweep';

        private $recording = null;

        private $encryption = null;

        const EMOJI_CUT = 'wordplus_pickers_cut_emoji';

        private $storage = null;

        private $toasts = null;

        private $tooltips = null;

        private $menus = null;

        const CREDITS_CHECK = 'wordplus_credits_check';

        private $credits = null;

        private $lightbox = null;

        private $emoji_guard = null;

        private $video_effects = null;

        private $assets = null;

        private $asset_hashes = array();

        const PARTS = array(
            'updater'     => array( 'src/class-updater.php' ),
            'credits'     => array( 'src/class-credits.php' ),
            'push'        => array( 'src/class-push.php', 'src/class-push-table.php', 'src/class-push-progressify.php', 'push' ),
            'app'         => array( 'src/class-app.php', 'src/class-app-devices.php' ),
            'storage'     => array( 'src/class-storage.php', 'src/class-storage-admin.php', 'src/class-storage-cloud.php', 'src/class-storage-direct.php', 'src/class-storage-queue.php', 'src/class-storage-s3.php', 'src/class-storage-sweeper.php', 'storage' ),
            'pickers'     => array( 'src/class-pickers.php', 'src/pickers', 'pickers', 'stickers' ),
            'toasts'      => array( 'src/class-toasts.php', 'toasts' ),
            'tooltips'    => array( 'src/class-tooltips.php', 'tooltips' ),
            'menus'       => array( 'src/class-menus.php', 'menus' ),
            'lightbox'    => array( 'src/class-lightbox.php', 'lightbox' ),
            'emoji-guard' => array( 'src/class-emoji-guard.php', 'emoji-guard' ),
            'video-effects' => array( 'src/class-video-effects.php', 'video-effects' ),
            'recording'   => array( 'src/class-recording.php', 'src/class-recording-store.php', 'src/class-recording-cloud.php', 'src/class-recording-sync.php', 'src/class-recording-rest.php', 'src/class-recording-files.php', 'recording' ),
            'websites'    => array( 'src/class-websites.php' ),
            'bookings'    => array( 'src/class-bookings.php', 'src/bookings' ),
            'ai'          => array( 'src/class-ai.php', 'src/ai', 'ai' ),
            'captcha'     => array( 'src/class-captcha.php', 'src/captcha', 'captcha' ),
            'geo'         => array( 'src/class-geo.php', 'src/class-addresses.php', 'src/geo', 'geo' ),
            'profiles'    => array( 'src/class-profiles.php', 'src/profiles', 'profiles' ),
            'encryption'  => array( 'src/class-encryption.php', 'src/class-encryption-config.php', 'src/class-encryption-index.php', 'src/class-encryption-job.php', 'src/class-encryption-admin.php', 'src/class-encryption-files.php', 'encryption' ),
            'polls'       => array( 'src/class-polls.php', 'src/class-polls-table.php', 'src/class-polls-rest.php' ),
            'spam'        => array( 'src/class-spam.php', 'src/class-spam-table.php' ),
        );

        const GEO_DAILY = 'wordplus_geo_daily';

        private $part_copies = array();

        const PAGE_OPTIONS = array(
            'pickers' => array( 'wordplus_pickers' ),
            'storage' => array( 'wordplus_storage' ),
            'push'    => array( 'wordplus_push_keys', 'bp_better_messages_vapid_keys' ),
            'video-effects' => array( 'wordplus_video_effects' ),
            'recording'   => array( 'wordplus_recording' ),
        );

        const TRACKING_SYNC = 'wordplus_sdk_tracking_sync';

        const TRACKING_SOON = 'wordplus_sdk_tracking_soon';

        const WAITING_EVENT = 'wordplus_sdk_check_waiting';

        private $tracking = null;

        private $i18n = null;

        private $visitors = null;

        private $email = null;

        private $cors = null;

        private $websites = null;

        private $bookings = null;

        private $captcha = null;

        private $geo = null;

        private $addresses = null;

        private $profiles = null;

        private $polls = null;

        private $spam = null;

        private $visitor_move = null;

        const WEBSITES_SYNC = 'wordplus_sdk_websites_sync';

        const WEBSITES_SOON = 'wordplus_sdk_websites_soon';

        private $ai = null;

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

            add_filter( 'determine_current_user', array( $sdk, 'app_user' ), 30 );
            add_filter( 'query_vars', array( $sdk, 'app_query_vars' ) );
            add_action( 'parse_request', array( $sdk, 'maybe_serve_app' ) );
            add_action( 'after_password_reset', array( $sdk, 'app_password_reset' ) );
            add_action( 'profile_update', array( $sdk, 'app_profile_update' ), 10, 2 );

            add_action( self::TRACKING_SYNC, array( $sdk, 'run_tracking_sync' ) );
            add_action( self::TRACKING_SOON, array( $sdk, 'run_tracking_sync' ) );

            add_action( self::WAITING_EVENT, array( $sdk, 'check_waiting' ), 10, 2 );

            add_action( self::WEBSITES_SYNC, array( $sdk, 'run_websites_sync' ) );
            add_action( self::WEBSITES_SOON, array( $sdk, 'run_websites_sync' ) );
            add_action( 'wordplus_sdk_licence_activated', array( $sdk, 'websites_activated' ) );

            add_action( self::CREDITS_CHECK, array( $sdk, 'run_credits_check' ) );
            add_action( 'admin_notices', array( $sdk, 'credits_notice' ) );

            add_action( self::STORAGE_JOB, array( $sdk, 'run_storage_job' ) );

            add_action( 'wordplus_spam_prune', array( $sdk, 'run_spam_prune' ) );

            add_action( self::ENCRYPTION_JOB, array( $sdk, 'run_encryption_job' ) );
            add_action( 'admin_notices', array( $sdk, 'encryption_notice' ) );
            add_action( 'network_admin_notices', array( $sdk, 'encryption_notice' ) );
            add_filter( 'site_status_tests', array( $sdk, 'encryption_health' ) );

            add_action( self::EMOJI_CUT, array( $sdk, 'run_emoji_cut' ) );

            add_action( self::VIDEO_EFFECTS_UPDATE, array( $sdk, 'run_video_effects_update' ) );

            add_action( self::RECORDING_SYNC, array( $sdk, 'run_recording_sync' ) );
            add_action( self::RECORDING_SWEEP, array( $sdk, 'run_recording_sweep' ) );
            add_action( 'wordplus_storage_cloud_finished', array( $sdk, 'recording_files_stored' ), 10, 4 );

            add_action( 'wordplus_sdk_check_' . self::CLOUD, array( $sdk, 'run_ai_prices' ), 20 );
            add_action( 'upgrader_process_complete', array( $sdk, 'video_effects_after_update' ) );

            add_action( self::GEO_DAILY, array( $sdk, 'run_geo_daily' ) );
            add_filter( 'rest_request_before_callbacks', array( $sdk, 'addresses_rest' ), 10, 1 );
            add_action( 'wp_login', array( $sdk, 'addresses_login' ), 10, 2 );
            add_action( 'deleted_user', array( $sdk, 'addresses_deleted_user' ) );
            add_action( 'wordplus_visitor_seen', array( $sdk, 'addresses_visitor_seen' ) );

            add_filter( 'wordplus_encryption_shared_collections', array( $sdk, 'addresses_encryption_collection' ) );
            add_action( 'wordplus_encryption_turned_on', array( $sdk, 'addresses_encryption_on' ) );

            add_filter( 'pre_get_avatar_data', array( $sdk, 'profile_avatar_data' ), 20, 2 );
            add_action( 'delete_user', array( $sdk, 'profile_deleted_user' ) );
            add_action( 'wpmu_delete_user', array( $sdk, 'profile_deleted_network_user' ) );

            if ( is_admin() ) {
                add_filter( 'user_profile_picture_description', array( $sdk, 'profile_picture_description' ), 10, 2 );
                add_action( 'admin_post_wordplus_profile_remove_photo', array( $sdk, 'profile_admin_remove_photo' ) );
                add_filter( 'manage_users_columns', array( $sdk, 'addresses_users_columns' ) );
                add_filter( 'manage_users_custom_column', array( $sdk, 'addresses_users_column' ), 10, 3 );
                add_action( 'personal_options', array( $sdk, 'addresses_profile_summary' ) );
                add_action( 'show_user_profile', array( $sdk, 'addresses_profile_box' ), 1 );
                add_action( 'edit_user_profile', array( $sdk, 'addresses_profile_box' ), 1 );
            }

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

                if ( $this->app() ) {
                    $this->app()->routes();
                }

                if ( $this->pickers() ) {
                    $this->pickers()->routes();
                }

                if ( $this->storage() ) {
                    $this->storage()->admin()->routes();

                    if ( method_exists( $this->storage(), 'cloud' ) ) {
                        $this->storage()->cloud()->routes();
                    }
                }

                if ( $this->video_effects() ) {
                    $this->video_effects()->routes();
                }

                if ( $this->recording() ) {
                    $this->recording()->routes();
                }

                if ( $this->captcha() ) {
                    $this->captcha()->routes();
                }

                if ( $this->geo() ) {
                    $this->geo()->routes();
                    $this->addresses()->routes();
                }

                if ( $this->profiles() ) {
                    $this->profiles()->routes();
                    $this->visitor_move()->routes();
                }

                if ( $this->encryption() ) {
                    $this->encryption()->admin()->routes();
                }

                if ( $this->has_part( 'ai' ) ) {
                    require_once $this->path( 'src/class-ai.php' );
                    WordPlus_SDK_AI_Loader::routes( $this );
                }

                foreach ( $this->products as $product ) {
                    if ( $product->get( 'visitors' ) ) {
                        $this->visitors()->routes();
                        break;
                    }
                }

                $this->cors()->hook();
            }
        }

        public function cors()
        {
            if ( null === $this->cors ) {
                require_once __DIR__ . '/class-cors.php';
                $this->cors = new WordPlus_SDK_Cors( $this );
            }

            return $this->cors;
        }

        public function websites()
        {
            if ( null === $this->websites && $this->has_part( 'websites' ) ) {
                require_once $this->path( 'src/class-websites.php' );
                $this->websites = new WordPlus_SDK_Websites( $this );
            }

            return $this->websites;
        }

        public function run_websites_sync()
        {
            if ( $this->websites() ) {
                $this->websites()->run();
            }
        }

        public function websites_activated()
        {
            if ( $this->websites() ) {
                $this->websites()->activated();
            }
        }

        public function visitors()
        {
            if ( null === $this->visitors ) {
                require_once __DIR__ . '/class-visitors.php';
                $this->visitors = new WordPlus_SDK_Visitors( $this );
            }

            return $this->visitors;
        }

        public function email()
        {
            if ( null === $this->email ) {
                require_once __DIR__ . '/class-email.php';
                $this->email = new WordPlus_SDK_Email();
            }

            return $this->email;
        }

        public function bookings()
        {
            if ( null === $this->bookings && $this->has_part( 'bookings' ) ) {
                require_once $this->path( 'src/class-bookings.php' );
                $this->bookings = new WordPlus_SDK_Bookings( $this );
            }

            return $this->bookings;
        }

        public function captcha()
        {
            if ( null === $this->captcha && $this->has_part( 'captcha' ) ) {
                require_once $this->path( 'src/class-captcha.php' );
                $this->captcha = new WordPlus_SDK_Captcha( $this );
            }

            return $this->captcha;
        }

        public function geo()
        {
            if ( null === $this->geo && $this->has_part( 'geo' ) ) {
                require_once $this->path( 'src/class-geo.php' );
                $this->geo = new WordPlus_SDK_Geo( $this );
            }

            return $this->geo;
        }

        public function addresses()
        {
            if ( null === $this->addresses && $this->has_part( 'geo' ) ) {
                require_once $this->path( 'src/class-geo.php' );
                require_once $this->path( 'src/class-addresses.php' );
                $this->addresses = new WordPlus_SDK_Addresses( $this );
            }

            return $this->addresses;
        }

        public function profiles()
        {
            if ( null === $this->profiles && $this->has_part( 'profiles' ) ) {
                require_once $this->path( 'src/class-profiles.php' );
                $this->profiles = new WordPlus_SDK_Profiles( $this );
            }

            return $this->profiles;
        }

        public function polls()
        {
            if ( null === $this->polls && $this->has_part( 'polls' ) ) {
                require_once $this->path( 'src/class-polls.php' );
                $this->polls = new WordPlus_SDK_Polls( $this );
            }

            return $this->polls;
        }

        public function spam()
        {
            if ( null === $this->spam && $this->has_part( 'spam' ) ) {
                require_once $this->path( 'src/class-spam.php' );
                $this->spam = new WordPlus_SDK_Spam( $this );
            }

            return $this->spam;
        }

        public function run_spam_prune()
        {
            if ( $this->spam() ) {
                $this->spam()->prune();
            }
        }

        public function visitor_move()
        {
            if ( null === $this->visitor_move && $this->has_part( 'profiles' ) ) {
                require_once $this->path( 'src/profiles/class-visitor-move.php' );
                $this->visitor_move = new WordPlus_SDK_Visitor_Move( $this );
            }

            return $this->visitor_move;
        }

        public function profile_avatar_data( $args, $id_or_email = null )
        {
            $loaded = function_exists( 'wp_load_alloptions' ) ? wp_load_alloptions() : array();

            if ( empty( $loaded['wordplus_profiles_photos'] ) || empty( $this->products ) || ! $this->profiles() ) {
                return $args;
            }

            return $this->profiles()->avatar_data( $args, $id_or_email );
        }

        public function profile_deleted_user( $user_id )
        {
            if ( ! is_multisite() && $this->profiles() ) {
                $this->profiles()->forget( (int) $user_id );
            }
        }

        public function profile_deleted_network_user( $user_id )
        {
            if ( $this->profiles() ) {
                $this->profiles()->forget( (int) $user_id );
            }
        }

        public function profile_picture_description( $description, $user = null )
        {
            return $this->profiles() ? $this->profiles()->picture_description( $description, $user ) : $description;
        }

        public function profile_admin_remove_photo()
        {
            if ( $this->profiles() ) {
                $this->profiles()->admin_remove_photo();
            }
        }

        public function run_geo_daily()
        {
            if ( $this->geo() ) {
                $this->geo()->daily();
            }
        }

        public function addresses_rest( $response = null )
        {
            if ( empty( $this->products ) || get_current_user_id() <= 0 || ! $this->addresses() ) {
                return $response;
            }

            return $this->addresses()->rest_request( $response );
        }

        public function addresses_login( $login, $user = null )
        {
            if ( ! empty( $this->products ) && $this->addresses() ) {
                $this->addresses()->signed_in( $login, $user );
            }
        }

        public function addresses_deleted_user( $user_id )
        {
            if ( $this->addresses() ) {
                $this->addresses()->forget( (int) $user_id );
            }
        }

        public function addresses_visitor_seen( $visitor_id )
        {
            if ( ! empty( $this->products ) && $this->addresses() ) {
                $this->addresses()->seen( (int) $visitor_id );
            }
        }

        public function addresses_encryption_collection( $collections )
        {
            $collections = is_array( $collections ) ? $collections : array();
            $own         = $this->addresses() ? $this->addresses()->encryption_collection() : null;

            if ( null !== $own ) {
                $collections['addresses'] = $own;
            }

            return $collections;
        }

        public function addresses_encryption_on()
        {
            if ( $this->addresses() ) {
                $this->addresses()->forget_marks();
            }
        }

        public function addresses_users_columns( $columns )
        {
            return $this->addresses() ? $this->addresses()->users_columns( $columns ) : $columns;
        }

        public function addresses_users_column( $output, $column, $user_id )
        {
            return $this->addresses() ? $this->addresses()->users_column( $output, $column, $user_id ) : $output;
        }

        public function addresses_profile_box( $user )
        {
            if ( $this->addresses() ) {
                $this->addresses()->profile_box( $user );
            }
        }

        public function addresses_profile_summary( $user )
        {
            if ( $this->addresses() ) {
                $this->addresses()->profile_summary( $user );
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

        public function video_effects()
        {
            if ( null === $this->video_effects && $this->has_part( 'video-effects' ) ) {
                require_once $this->path( 'src/class-video-effects.php' );
                $this->video_effects = new WordPlus_SDK_Video_Effects( $this );
            }

            return $this->video_effects;
        }

        public function run_video_effects_update()
        {
            if ( $this->video_effects() ) {
                $this->video_effects()->update();
            }
        }

        public function recording()
        {
            if ( null === $this->recording && $this->has_part( 'recording' ) ) {
                require_once $this->path( 'src/class-recording.php' );
                $this->recording = new WordPlus_SDK_Recording( $this );
            }

            return $this->recording;
        }

        public function encryption()
        {
            if ( null === $this->encryption && $this->has_part( 'encryption' ) ) {
                require_once $this->path( 'src/class-encryption.php' );
                $this->encryption = new WordPlus_SDK_Encryption( $this );
            }

            return $this->encryption;
        }

        public function run_encryption_job()
        {
            if ( $this->encryption() ) {
                $this->encryption()->job()->run();
            }
        }

        public function encryption_in_use()
        {
            $state = get_site_option( 'wordplus_encryption', array() );

            return is_array( $state ) && ! empty( $state['keys'] );
        }

        public function encryption_notice()
        {
            if ( ! $this->encryption_in_use() ) {
                return;
            }

            if ( $this->encryption() ) {
                $this->encryption()->admin()->notice();

                return;
            }

            if ( current_user_can( is_multisite() ? 'manage_network_options' : 'manage_options' ) ) {
                printf(
                    '<div class="notice notice-error"><p><strong>%1$s</strong></p><p>%2$s</p></div>',
                    esc_html( _x( 'Encrypted data on this site cannot be read', 'Encryption', 'wordplus-sdk' ) ),
                    esc_html( _x( 'Data on this site was encrypted with the WordPlus encryption key, but none of the active plugins here can read it: their build has no encryption. It shows as unreadable until a build with encryption is active again.', 'Encryption', 'wordplus-sdk' ) )
                );
            }
        }

        public function encryption_health( $tests )
        {
            if ( ! is_array( $tests ) || ! $this->encryption_in_use() ) {
                return $tests;
            }

            if ( $this->encryption() ) {
                return $this->encryption()->admin()->health_tests( $tests );
            }

            $tests['direct']['wordplus_encryption_key'] = array(
                'label' => _x( 'WordPlus encryption key', 'Encryption', 'wordplus-sdk' ),
                'test'  => function () {
                    return array(
                        'test'        => 'wordplus_encryption_key',
                        'status'      => 'critical',
                        'badge'       => array( 'label' => _x( 'Security', 'Encryption', 'wordplus-sdk' ), 'color' => 'red' ),
                        'label'       => _x( 'Encrypted data on this site cannot be read', 'Encryption', 'wordplus-sdk' ),
                        'description' => '<p>' . esc_html( _x( 'Data on this site was encrypted with the WordPlus encryption key, but none of the active plugins here can read it: their build has no encryption. It shows as unreadable until a build with encryption is active again.', 'Encryption', 'wordplus-sdk' ) ) . '</p>',
                        'actions'     => '',
                    );
                },
            );

            return $tests;
        }

        public function ai()
        {
            if ( null === $this->ai ) {
                $this->ai = false;

                if ( $this->has_part( 'ai' ) ) {
                    require_once $this->path( 'src/class-ai.php' );
                    $this->ai = WordPlus_SDK_AI_Loader::load( $this );
                    $this->ai = $this->ai ? $this->ai : false;
                }
            }

            return $this->ai ? $this->ai : null;
        }

        public function ai_status()
        {
            if ( ! $this->has_part( 'ai' ) ) {
                return array(
                    'available' => false,
                    'reason'    => 'missing',
                    'php'       => PHP_VERSION,
                    'needs'     => '',
                    'message'   => _x( 'AI features are not part of this build of the plugin.', 'AI', 'wordplus-sdk' ),
                );
            }

            require_once $this->path( 'src/class-ai.php' );

            return WordPlus_SDK_AI_Loader::status();
        }

        public function run_ai_prices()
        {
            if ( $this->ai() ) {
                $this->ai()->prices()->daily();
            }
        }

        public function run_recording_sync()
        {
            if ( $this->recording() ) {
                $this->recording()->sync()->run();
            }
        }

        public function run_recording_sweep()
        {
            if ( $this->recording() ) {
                $this->recording()->sync()->sweep();
            }
        }

        public function recording_files_stored( $plugin, $ref, $files, $grant )
        {
            if ( is_array( $grant ) && isset( $grant['path'] ) && 0 === strpos( (string) $grant['path'], 'recordings/' ) && $this->recording() ) {
                $this->recording()->files_stored( (string) $plugin, (int) $ref, is_array( $files ) ? $files : array(), $grant );
            }
        }

        public function video_effects_after_update()
        {
            if ( $this->video_effects() ) {
                $this->video_effects()->maybe_update();
            }
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

        public function credits()
        {
            if ( null === $this->credits && $this->has_part( 'credits' ) ) {
                require_once $this->path( 'src/class-credits.php' );
                $this->credits = new WordPlus_SDK_Credits( $this );
            }

            return $this->credits;
        }

        public function run_credits_check()
        {
            if ( $this->credits() ) {
                $this->credits()->check();
            }
        }

        public function credits_notice()
        {
            if ( $this->has_part( 'credits' ) && get_option( 'wordplus_credits_spent' ) && $this->credits() ) {
                $this->credits()->notice();
            }
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

            if ( $this->app() ) {
                $this->app()->sign_out_user( (int) $user_id, 'user' );
            }
        }

        public function app()
        {
            if ( null === $this->app && $this->has_part( 'app' ) ) {
                require_once $this->path( 'src/class-app.php' );
                $this->app = new WordPlus_SDK_App( $this );
            }

            return $this->app;
        }

        public function app_user( $user_id )
        {
            if ( ! isset( $_SERVER['HTTP_X_WORDPLUS_APP'] ) || ! $this->app() ) {
                return $user_id;
            }

            return $this->app()->user( $user_id );
        }

        public function app_query_vars( $vars )
        {
            $vars[] = 'wordplus-app-signin';
            $vars[] = 'wordplus-app-session';

            return $vars;
        }

        public function maybe_serve_app( $wp )
        {
            $vars = is_object( $wp ) ? (array) $wp->query_vars : array();

            if ( ( ! empty( $vars['wordplus-app-signin'] ) || ! empty( $vars['wordplus-app-session'] ) || isset( $_COOKIE['wordplus_app_signin'] ) ) && $this->app() ) {
                $this->app()->maybe_serve( $wp );
            }
        }

        public function app_password_reset( $user )
        {
            if ( is_object( $user ) && $this->app() ) {
                $this->app()->sign_out_user( (int) $user->ID, 'password' );
            }
        }

        public function app_profile_update( $user_id, $old = null )
        {
            $user = is_object( $old ) ? get_userdata( (int) $user_id ) : false;

            if ( $user && (string) $old->user_pass !== (string) $user->user_pass && $this->app() ) {
                $this->app()->sign_out_user( (int) $user_id, 'password' );
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
