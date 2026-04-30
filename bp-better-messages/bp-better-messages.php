<?php
/*
    @wordpress-plugin
    Plugin Name: Better Messages
    Plugin URI: https://www.wordplus.org
    Description: Realtime private messaging system for WordPress
    Version: 2.15.0
    Author: WordPlus
    Author URI: https://www.wordplus.org
    Requires PHP: 7.4
    Requires at least: 5.9.0
    License: GPLv3
    Text Domain: bp-better-messages
    Domain Path: /languages/

    @fs_premium_only /inc/mobile-app/,/inc/websocket.php,/addons/calls.php,/addons/calls-group.php,/addons/e2e-encryption.php,/inc/mini.php,/assets/js/bp-messages-premium.min.js,/assets/js/bp-messages-premium.min.js.LICENSE.txt,/assets/js/bp-messages-app.min.js,/assets/js/bp-messages-app.min.js.LICENSE.txt,/assets/js/modules/calls,/assets/sounds/calling.mp3,/assets/sounds/calling.ogg,/assets/sounds/dialing.mp3,/assets/sounds/dialing.ogg,/assets/js/bpbm-worker.js,/assets/js/bpbm-worker.min.js,/assets/js/workers/e2ee.worker.js,/assets/js/workers/bmdb-encrypted-shared.worker.js,/assets/js/workers/bmdb-encrypted.worker.js,/assets/css/mobile-app.scss,/assets/css/mobile-app.css,/assets/css/mobile-app.css.map,/assets/css/mobile-app.min.css,/assets/css/mobile-app.min.css.map
    @fs_ignore /addons/ai/dependencies/
*/
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages' ) && ! function_exists( 'bpbm_fs' ) ) {
    class Better_Messages
    {
        public  $version = '2.15.0';

        public  $db_version = '1.0.4';

        public  $realtime;

        public  $path;

        public  $url;

        public  $settings;

        /** @var Better_Messages_Options $options */
        public  $options;

        /** @var Better_Messages_Functions $functions */
        public  $functions;

        /** @var Better_Messages_Shortcodes $shortcodes */
        public  $shortcodes;

        /** @var Better_Messages_Rest_Api $api */
        public $api;
        /** @var Better_Messages_Stickers $stickers */
        public  $stickers;

        /** @var Better_Messages_Mentions $mentions */
        public  $mentions;

        /** @var Better_Messages_Giphy_Provider $giphy */
        public  $giphy;

        /** @var Better_Messages_Urls $urls */
        public  $urls ;

        /** @var Better_Messages_Files $files */
        public  $files ;


        /** @var Better_Messages_Mini_List $mini_list */
        public $mini_list;

        /** @var Better_Messages_Emojis $emoji */
        public $emoji;

        /** @var Better_Messages_Chats $chats */
        public  $chats ;

        /** @var Better_Messages_Notifications $notifications */
        public  $notifications ;

        /** @var Better_Messages_Component $tab */
        public  $tab ;

        /** @var Better_Messages_Hooks $hooks */
        public  $hooks ;

        /** @var Better_Messages_Group $groups */
        public $groups;

        /** @var Better_Messages_Customize $customize */
        public $customize;

        /** @var Better_Messages_User_Config $user_config */
        public $user_config;

        /** @var Better_Messages_Moderation $moderation */
        public $moderation;

        /** @var Better_Messages_Users $users */
        public $users;

        /** @var Better_Messages_Guests $guests */
        public $guests;

        /** @var Better_Messages_Cleaner $cleaner */
        public $cleaner;

        /** @var Better_Messages_Bulk_Sender $bulk_sender */
        public $bulk_sender;

        /** @var Better_Messages_Capabilities $capabilities */
        public $capabilities;

        /** @var Better_Messages_WebSocket $functions */
        public  $websocket = false ;

        /** @var Better_Messages_Calls $calls */
        public $calls = false;

        /** @var Better_Messages_Calls_Group $group_calls */
        public $group_calls = false;

        /** @var Better_Messages_AI $group_calls */
        public $ai = false;

        /** @var Better_Messages_E2E_Encryption $e2e */
        public $e2e = false;

        /** @var Better_Messages_Blocks $blocks */
        public $blocks = false;
        public $privacy = false;

        /** @var Better_Messages_Abilities|false $abilities */
        public $abilities = false;

        /** @var Better_Messages_Mobile_App $mobile_app */
        public $mobile_app = false;

        public $script_variables;

        public static function instance()
        {
            // Store the instance locally to avoid private static replication
            static  $instance = null ;
            // Only run these methods if they haven't been run previously

            if ( null === $instance ) {
                $instance = new Better_Messages();
                $instance->setup_vars();
                $instance->setup_actions();
                $instance->setup_classes();
            }

            // Always return the instance
            return $instance;
            // The last metroid is in captivity. The galaxy is at peace.
        }

        public function setup_vars()
        {
            global  $wpdb ;
            $wpdb->__set( 'bm_threadmeta', bm_get_table('threadsmeta') );
            $wpdb->__set( 'bm_messagemeta', bm_get_table('meta') );
            $this->realtime = false;
            $this->path = plugin_dir_path( __FILE__ );
            $this->url  = plugin_dir_url( __FILE__ );
        }

        public function setup_actions()
        {
            $this->require_files();

            add_action( 'wp_enqueue_scripts', array( $this, 'load_scripts' ) );
            add_action( 'admin_enqueue_scripts', array( $this, 'load_admin_scripts' ) );
            add_action( 'init', array( $this, 'load_text_domain' ) );
        }

        /**
         * Require necessary files
         */
        public function require_files()
        {
            require_once 'inc/classes/message.php';
            require_once 'inc/classes/thread.php';
            require_once 'inc/functions-global.php';
            require_once 'inc/functions.php';
            /**
             * Require component only if BuddyPress is active
             */
            if ( class_exists( 'BP_Component' ) ) {
                require_once 'inc/component.php';
            }

            require_once 'inc/hooks.php';
            require_once 'inc/users.php';
            require_once 'inc/options.php';
            require_once 'inc/notifications.php';
            require_once 'inc/chats.php';
            require_once 'inc/mini-list.php';
            require_once 'inc/user-config.php';
            require_once 'inc/shortcodes.php';
            require_once 'inc/rest-api.php';
            require_once 'inc/capabilities.php';
            require_once 'inc/translations/translations.php';
            require_once 'inc/cleaner.php';
            require_once 'inc/bulk-sender.php';
            require_once 'inc/moderation.php';
            require_once 'inc/guests.php';
            require_once 'addons/urls.php';
            require_once 'addons/files.php';
            require_once 'addons/emojis.php';
            require_once 'addons/mentions.php';
            require_once 'addons/stickers/stickers.php';
            require_once 'addons/stickers/stipop.php';
            require_once 'addons/giphy.php';
            require_once 'addons/reactions.php';
            require_once 'addons/privacy.php';
            require_once 'inc/customize.php';

            require_once 'addons/ai/ai.php';
            require_once 'inc/blocks/blocks.php';

            if ( class_exists( 'WP_Ability' ) ) {
                require_once 'inc/abilities/abilities.php';
            }

            require_once Better_Messages()->path . 'vendor/AES256.php';
            require_once Better_Messages()->path . 'vendor/randomizer/randomizer-start.php';
            require_once Better_Messages()->path . 'vendor/random-name-generator/random-name-generator.php';

            bpbm_fs()->add_filter('plugin_icon', function () {
                return $this->path . 'assets/images/icon.png';
            });
        }


        public function setup_classes()
        {
            $this->functions = Better_Messages_Functions();
            $this->options = Better_Messages_Options();
            $this->load_options();
            $this->hooks = Better_Messages_Hooks();
            $this->users  = Better_Messages_Users();
            $this->guests = Better_Messages_Guests();

            $this->notifications = Better_Messages_Notifications();
            $this->chats = Better_Messages_Chats();
            $this->mini_list = Better_Messages_Mini_List();
            $this->files = Better_Messages_Files();

            if ( $this->settings['searchAllUsers'] === '1' && !defined( 'BP_MESSAGES_AUTOCOMPLETE_ALL' ) ) {
                define( 'BP_MESSAGES_AUTOCOMPLETE_ALL', true );
            }

            $this->capabilities = Better_Messages_Capabilities();
            $this->urls         = Better_Messages_Urls();
            $this->emoji        = Better_Messages_Emojis();
            $this->mentions     = Better_Messages_Mentions();
            $this->stickers     = Better_Messages_Stickers();
            $this->giphy        = Better_Messages_Giphy();
            $this->user_config  = Better_Messages_User_Config();
            $this->shortcodes   = Better_Messages_Shortcodes();
            $this->api          = Better_Messages_Rest_Api();
            $this->cleaner      = Better_Messages_Cleaner();
            $this->bulk_sender  = Better_Messages_Bulk_Sender();
            $this->moderation   = Better_Messages_Moderation();
            $this->ai           = Better_Messages_AI();
            $this->blocks       = Better_Messages_Blocks();
            $this->privacy      = Better_Messages_Privacy();

            if ( function_exists( 'Better_Messages_Abilities' ) ) {
                $this->abilities = Better_Messages_Abilities();
            }

            if ( file_exists($this->path . 'addons/e2e-encryption.php') && Better_Messages()->functions->can_use_premium_code_premium_only() ) {
                require_once $this->path . 'addons/e2e-encryption.php';
            }

            if ( function_exists( 'Better_Messages_E2E_Encryption' ) ) {
                $this->e2e = Better_Messages_E2E_Encryption();
            }

            if ( bm_bp_is_active( 'groups' ) ) {
                require_once 'inc/component-group.php';
                $this->groups = Better_Messages_Group();
            }

            if ( file_exists($this->path . 'addons/calls.php') && Better_Messages()->functions->can_use_premium_code_premium_only() ) {
                require_once $this->path . 'addons/calls.php';
            }

            if ( file_exists($this->path . 'addons/calls-group.php') && Better_Messages()->functions->can_use_premium_code_premium_only() ) {
                require_once $this->path . 'addons/calls-group.php';
            }

            if ( function_exists( 'Better_Messages_Calls' ) ) {
                if ( $this->settings['videoCalls'] === '1' || $this->settings['audioCalls'] === '1' || $this->settings['groupCallsGroups'] === '1' || $this->settings['groupCallsThreads'] === '1' || $this->settings['groupCallsChats'] === '1' ) {
                    $this->calls = Better_Messages_Calls();
                }
            }

            if ( function_exists( 'Better_Messages_Calls_Group' ) ) {
                if (
                    $this->settings['groupAudioCallsChats'] === '1' ||
                    $this->settings['groupAudioCallsThreads'] === '1' ||
                    $this->settings['groupAudioCallsGroups'] === '1' ||
                    $this->settings['groupCallsGroups'] === '1' ||
                    $this->settings['groupCallsThreads'] === '1' ||
                    $this->settings['groupCallsChats'] === '1'
                ) {
                    $this->group_calls = Better_Messages_Calls_Group();
                }
            }

            $this->customize  = Better_Messages_Customize();

            if ( bpbm_fs()->is__premium_only() ) {
                if ( file_exists($this->path . 'inc/mobile-app/mobile-app.php') && Better_Messages()->functions->can_use_premium_code_premium_only() ) {
                    require_once 'inc/mobile-app/mobile-app.php';
                    $this->mobile_app = Better_Messages_Mobile_App();
                }
            }
        }

        public function load_options()
        {
            $this->settings = $this->options->settings;

            $this->settings = apply_filters('bp_better_messages_overwrite_settings', $this->settings);

            if ( bpbm_fs()->is__premium_only() ) {
                if ( $this->settings['mechanism'] == 'websocket' ) {
                    require_once 'inc/websocket.php';
                    $this->websocket = Better_Messages_WebSocket();

                    $this->realtime = true;

                    if ( $this->settings['miniChatsEnable'] === '1' ) {
                        require_once 'inc/mini.php';
                        Better_Messages_Mini();
                    }
                }
            }
        }

        public function load_text_domain()
        {
            load_plugin_textdomain( 'bp-better-messages', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        }

        public function load_admin_scripts()
        {
            $this->enqueue_admin_js();
            $this->enqueue_admin_css();

            return true;
        }

        public function load_scripts()
        {
            if ( ! is_user_logged_in() && ! Better_Messages()->guests->guest_access_enabled() ) {
                return false;
            }

            $this->enqueue_js();
            $this->enqueue_css();

            return true;
        }

        public function ensure_version_included( $src, $handle ){
            $handles_to_ensure = [
                'better-messages',
                'better-messages-media',
                'better-messages-files-webcam',
                'better-messages-files-image-editor',
                'better-messages-files-react',
                'better-messages-files-core',
                'better-messages-app'
            ];

            if( ! in_array( $handle, $handles_to_ensure ) ) return $src;

            $parsed_vars = parse_url( $src, PHP_URL_QUERY );
            $version_included = false;

            if( $parsed_vars ){
                parse_str($parsed_vars, $url_vars);

                if( isset( $url_vars['ver'] ) ){
                    $version_included = true;
                }
            }

            if( ! $version_included ){
                $src = add_query_arg('ver', $this->version, $src);
            }


            return $src;
        }

        public function enqueue_admin_css(){
            $current_screen = get_current_screen();

            $admin_css_file = 'assets/admin/admin.min.css';

            $version = $this->version;

            if( defined( 'BM_DEV' ) && file_exists( $this->path . 'assets/admin/admin.css' ) ) {
                $admin_css_file = 'assets/admin/admin.css';
                $version .= filemtime( $this->path . $admin_css_file );
            }

            $include_css = false;

            switch ( $current_screen->id ){
                case 'toplevel_page_bp-better-messages':
                case 'edit-bpbm-chat':
                case 'bm-ai-chat-bot':
                case 'bpbm-chat':
                case 'user-edit':
                case 'profile':
                case 'product':
                    $include_css = true;
                    break;

                default:
                    if( str_ends_with( $current_screen->id, 'better-messages-viewer' ) ){
                        $include_css = true;
                    } else if( str_ends_with( $current_screen->id, 'better-messages-mobile-app' ) ){
                        $include_css = true;
                    } else if( str_ends_with( $current_screen->id, 'bp-better-messages-ai' ) ){
                        $include_css = true;
                    } else if( str_ends_with( $current_screen->id, 'bp-better-messages-chat-rooms' ) ){
                        $include_css = true;
                    }
            }



            if( $include_css ) {
                wp_enqueue_style(
                    'better-messages-admin',
                    plugins_url( $admin_css_file, __FILE__ ),
                    false,
                    $version
                );
            }
        }

        public function enqueue_admin_js(){
            $current_screen = get_current_screen();

            $include_js = false;

            switch ( $current_screen->id ){
                case 'toplevel_page_bp-better-messages':
                case 'edit-bpbm-chat':
                case 'bm-ai-chat-bot':
                case 'bpbm-chat':
                case 'user-edit':
                case 'profile':
                case 'product':
                $include_js = true;
                break;

                default:
                    if( str_ends_with( $current_screen->id, 'better-messages-viewer' ) ){
                        $include_js = true;
                        wp_enqueue_editor();
                    } else if( str_ends_with( $current_screen->id, 'better-messages-mobile-app' ) ){
                        $include_js = true;
                    } else if( str_ends_with( $current_screen->id, 'bp-better-messages-ai' ) ){
                        $include_js = true;
                    } else if( str_ends_with( $current_screen->id, 'bp-better-messages-chat-rooms' ) ){
                        $include_js = true;
                    }

            }

            if( ! $include_js ) return;

            $file_name = 'admin.min.js';

            $is_dev = defined( 'BM_DEV' );
            $version = $this->version;

            if( $is_dev && file_exists( $this->path . 'assets/admin/admin.js' ) ) {
                $file_name = 'admin.js';
                $version .= filemtime( $this->path . 'assets/admin/admin.js' );
            }

            $dependencies = array(
                'jquery',
            );

            wp_register_script(
                'better-messages-admin',
                plugins_url( 'assets/admin/' . $file_name, __FILE__ ),
                $dependencies,
                $version
            );

            $i18n_url = Better_Messages_Translations()->get_php_translation_file_url( 'better-messages-admin' );
            if ( $i18n_url ) {
                wp_register_script( 'better-messages-admin-i18n', $i18n_url, array(), null, false );
                wp_scripts()->registered['better-messages-admin']->deps[] = 'better-messages-admin-i18n';
            }

            // Inline fallback if file cache failed
            $inline_i18n = Better_Messages_Translations()->get_inline_translations( 'better-messages-admin' );
            if ( $inline_i18n ) {
                wp_register_script( 'better-messages-admin-i18n-inline', false, array(), null, false );
                wp_add_inline_script( 'better-messages-admin-i18n-inline', 'window.Better_Messages_i18n=' . wp_json_encode( $inline_i18n, JSON_UNESCAPED_UNICODE ) . ';' );
                wp_scripts()->registered['better-messages-admin']->deps[] = 'better-messages-admin-i18n-inline';
            }

            $script_variables = [
                'restUrl'               => $this->functions->get_rest_api_url(),
                'nonce'                 => wp_create_nonce( 'wp_rest' ),
                'adminUrl'              => admin_url(),
                'canEditUsers'          => current_user_can( 'edit_users' ),
                'premoderationEnabled'  => $this->settings['messagesPremoderation'] === '1',
                'hasWebSocketClass'     => class_exists('Better_Messages_WebSocket'),
            ];

            wp_localize_script( 'better-messages-admin', 'Better_Messages_Admin', $script_variables );

            wp_enqueue_script('better-messages-admin');
        }

        public $css_loaded = false;

        public function enqueue_css( $force = false ){
            if( ! $force && $this->css_loaded ) return;

            if( ! has_action('style_loader_src', array( $this, 'ensure_version_included')  ) ) {
                add_action('style_loader_src', array($this, 'ensure_version_included'), 999, 2);
            }

            $css_file = 'assets/css/bp-messages.min.css';

            $version = $this->version;

            if( defined( 'BM_DEV' ) && file_exists( $this->path . 'assets/css/bp-messages.css' ) ) {
                $css_file = 'assets/css/bp-messages.css';
                $version .= filemtime( $this->path . $css_file );
            }

            $dependencies = apply_filters('better_messages_style_dependencies', array());

            wp_register_style('better-messages', plugins_url( $css_file, __FILE__ ),
                $dependencies,
                $version
            );

            wp_add_inline_style('better-messages', Better_Messages()->hooks->css_customizations() );
            wp_add_inline_style('better-messages', Better_Messages()->customize->header_output() );

            wp_enqueue_style( 'better-messages' );

            $this->css_loaded = true;
        }

        public $js_loaded = false;

        public function enqueue_js(){
            if( $this->js_loaded ) return;

            if( ! has_action('script_loader_src', array( $this, 'ensure_version_included')  ) ) {
                add_action('script_loader_src', array($this, 'ensure_version_included'), 999, 2);
            }

            do_action('better_messages_register_script_dependencies');

            $deps_file = defined( 'BM_DEV' ) ? 'assets/js/modules/deps.js' : 'assets/js/modules/deps.min.js';
            $deps_version = $this->get_module_version( 'deps' );

            wp_register_script(
                'better-messages-deps',
                plugins_url( $deps_file, __FILE__ ),
                array(),
                $deps_version,
                false
            );

            $file_name = 'bp-messages-free.min.js';

            if ( bpbm_fs()->is__premium_only() ) {
                $file_name = 'bp-messages-premium.min.js';
            }

            // Use unminified dev bundle in development only
            if( defined( 'BM_DEV' ) && file_exists( $this->path . 'assets/js/bp-messages.js' ) ) {
                $file_name = 'bp-messages.js';
            }

            $version = $this->version;

            if( defined( 'BM_DEV' ) ) {
                $version .= filemtime( $this->path . 'assets/js/' . $file_name );
            }

            $dependencies = apply_filters('better_messages_script_dependencies', array(
                'wp-hooks', 'better-messages-deps'
            ));

            wp_register_script(
                'better-messages',
                plugins_url( 'assets/js/' . $file_name, __FILE__ ),
                $dependencies,
                $version,
                false
            );

            // Generate cacheable translation file using WP's own translation discovery
            $i18n_url = Better_Messages_Translations()->get_translation_file_url( 'better-messages' );
            if ( $i18n_url ) {
                wp_register_script( 'better-messages-i18n', $i18n_url, array(), null, false );
                wp_scripts()->registered['better-messages']->deps[] = 'better-messages-i18n';
            }

            // Inline fallback if file cache failed
            $inline_i18n = Better_Messages_Translations()->get_inline_translations( 'better-messages' );
            if ( $inline_i18n ) {
                wp_register_script( 'better-messages-i18n-inline', false, array(), null, false );
                wp_add_inline_script( 'better-messages-i18n-inline', 'window.Better_Messages_i18n=' . wp_json_encode( $inline_i18n, JSON_UNESCAPED_UNICODE ) . ';' );
                wp_scripts()->registered['better-messages']->deps[] = 'better-messages-i18n-inline';
            }

            $script_variables = $this->get_script_variables();

            wp_localize_script( 'better-messages', 'Better_Messages', apply_filters( 'bp_better_messages_script_variables', $script_variables ) );

            wp_enqueue_script( 'better-messages' );

            $this->js_loaded = true;
        }

        private $module_versions = null;

        public function get_module_version( $name ) {
            if ( $this->module_versions === null ) {
                $manifest_file = $this->path . 'assets/js/modules/versions.php';
                if ( file_exists( $manifest_file ) ) {
                    $this->module_versions = include $manifest_file;
                }
                if ( ! is_array( $this->module_versions ) ) {
                    $this->module_versions = array();
                }
            }

            if ( isset( $this->module_versions[ $name ] ) ) {
                return $this->module_versions[ $name ];
            }

            return $this->version;
        }

        private $worker_versions = null;

        private function get_worker_version() {
            if ( $this->worker_versions === null ) {
                $manifest_file = $this->path . 'assets/js/workers/versions.php';
                if ( file_exists( $manifest_file ) ) {
                    $this->worker_versions = include $manifest_file;
                }
                if ( ! is_array( $this->worker_versions ) ) {
                    $this->worker_versions = array();
                }
            }

            if ( isset( $this->worker_versions['bmdb-shared'] ) ) {
                return $this->worker_versions['bmdb-shared'];
            }

            if ( defined( 'BM_DEV' ) ) {
                $worker_file = $this->path . 'assets/js/workers/bmdb-shared.worker.js';
                if ( file_exists( $worker_file ) ) {
                    return $this->version . '-' . filemtime( $worker_file );
                }
            }

            return $this->version;
        }

        public function get_script_variables(){
            $vars = $this->_compute_script_variables_inner();
            $vars = $this->_filter_default_equal_entries( $vars );

            $this->script_variables = apply_filters( 'bp_better_messages_script_variable', $vars );

            return $this->script_variables;
        }

        private static $_always_send_keys = array(
            'hash', 'user_id', 'version', 'workerVersion', 'blogId',
            'ajaxUrl', 'restUrl', 'nonce', 'authToken',
            'url', 'threadUrl', 'baseUrl',
            'assets', 'sounds', 'soundLevels',
            'notificationSoundUrl', 'sentSoundUrl', 'callSoundUrl', 'dialingSoundUrl',
            'sprite', 'emojiHash',
            'loginUrl', 'registerUrl', 'adminUrl',
            'total_unread', 'enableSound',
            'realtime', 'friends', 'groups', 'guests',
            'me', 'ukey',
            'translationLanguage',
            'isAdmin', 'canBulk',
            'pointsBalance', 'pointsBalanceUrl',
            'pointsBalanceHeader', 'pointsBalanceThreadsList', 'pointsBalanceThreadsListBottom',
            'pointsBalanceUserMenu', 'pointsBalanceUserMenuPopup', 'pointsBalanceReplyForm',
            'reactions',
            'userStatuses',
            'suggestedConversations',
            'voiceMaxDuration',
            'unreadCounter',
            'ws', 'socket_server', 'turn_server', 'video_server', 'site_id', 'secret_key',
            'callRequestTimeLimit', 'fast', 'le', 'encryption', 'ekey',
            'vapidPublicKey', 'PNworker',
            'badWords',
            'fileSigningKey',
            'mobile',
            'miniWidgetsOrder', 'sidePanelTabsOrder', 'mobileTabsOrder',
            'color', 'darkColor', 'locale', 'widgetsPosition',
            'datePosition', 'timeFormat', 'avatars', 'subName',
        );

        private function _filter_default_equal_entries( array $vars ) {
            static $defaults_snapshot = null;

            if ( $defaults_snapshot === null ) {
                $real = $this->settings;
                $this->settings = $this->options->defaults;
                $defaults_snapshot = $this->_compute_script_variables_inner();
                $this->settings = $real;
            }

            foreach ( $defaults_snapshot as $key => $default_value ) {
                if ( in_array( $key, self::$_always_send_keys, true ) ) continue;
                if ( array_key_exists( $key, $vars ) && $vars[ $key ] === $default_value ) {
                    unset( $vars[ $key ] );
                }
            }

            return $vars;
        }

        private function _compute_script_variables_inner(){
            $enableSound = '1';

            if( Better_Messages()->settings['allowSoundDisable'] === '1' ){
                $disabled = ( Better_Messages()->functions->get_user_meta( get_current_user_id(), 'bpbm_disable_sound_notification', true ) === 'yes');
                if( $disabled ){
                    $enableSound = '0';
                }
            }

            if( is_user_logged_in() ) {
                $unread_count = Better_Messages()->functions->get_total_threads_for_user(get_current_user_id(), 'unread');
            } else {
                $unread_count = 0;
            }

            $ukey = Better_Messages()->functions->get_user_secret_key( get_current_user_id() );

            $locale = sanitize_file_name( strtolower( get_locale() ) );
            $locale = str_replace( '_', '-', $locale );
            $locale = apply_filters( 'bp_better_messages_time_locale', $locale );

            $friends = Better_Messages()->functions->is_friends_active();
            $groups  = Better_Messages()->functions->is_groups_active();

            $localEncryption = ( $this->settings['encryptionLocal'] === '1' );

            $hash = serialize( $this->settings ) . serialize( $this->functions->get_user_roles( get_current_user_id() ) ) . $this->db_version;
            if( $localEncryption ) $hash .= $ukey;

            $script_variables = array(
                'hash'                   => md5( $hash ) ,
                'user_id'                => get_current_user_id(),
                'version'                => $this->version,
                'workerVersion'          => $this->get_worker_version(),
                'blogId'                 => ( is_multisite() ? get_current_blog_id() : '' ),
                'ajaxUrl'                => admin_url('admin-ajax.php'),
                'restUrl'                => esc_url_raw(get_rest_url(null, '/better-messages/v1/')),
                'nonce'                  => wp_create_nonce( 'wp_rest' ),
                'siteRefresh'            => ( isset( $this->settings['site_interval'] ) ? intval( $this->settings['site_interval'] ) * 1000 : 10000 ),
                'threadRefresh'          => ( isset( $this->settings['thread_interval'] ) ? intval( $this->settings['thread_interval'] ) * 1000 : 3000 ),
                'url'                    => $this->functions->get_link(),
                'threadUrl'              => $this->functions->get_link( get_current_user_id() ) . '#/conversation/',
                'baseUrl'                => $this->functions->get_link( get_current_user_id() ),
                'assets'                 => plugin_dir_url( __FILE__ ) . 'assets/',
                'sounds'                 => apply_filters( 'bp_better_messages_sounds_assets', plugin_dir_url( __FILE__ ) . 'assets/sounds/' ),
                'soundLevels' => array(
                    'notification'  => $this->settings['notificationSound'] / 100,
                    'sent'          => $this->settings['sentSound'] / 100,
                    'calling'       => $this->settings['callSound'] / 100,
                    'dialing'       => $this->settings['dialingSound'] / 100,
                ),
                'color'                 => get_theme_mod('main-bm-color', '#21759b'),
                'darkColor'             => get_theme_mod('main-bm-color-dark', '#fff'),
                'locale'                => $locale,
                'stickers'              => '0',
                'stickersProvider'      => 'disabled',
                'stickerManifestUrl'    => '',
                'stickerSuggestions'    => '0',
                'gifs'                  => '0',
                'realtime'              => ( $this->realtime ? '1' : '0' ),
                'minHeight'             => (int) $this->settings['messagesMinHeight'],
                'maxHeight'             => (int) apply_filters( 'bp_better_messages_max_height', $this->settings['messagesHeight'] ),
                'headerHeight'          => (int) $this->settings['fixedHeaderHeight'],
                'sideWidth'             => (int) $this->settings['sideThreadsWidth'],
                'sidebarCompactMode'    => $this->settings['sidebarCompactMode'],
                'sidebarUserToggle'     => $this->settings['sidebarUserToggle'],
                'sidebarCompactBreakpoint' => (int) $this->settings['sidebarCompactBreakpoint'],
                'sidebarHideBreakpoint' => (int) $this->settings['sidebarHideBreakpoint'],
                'favorite'              => ( $this->settings['disableFavoriteMessages'] == '1' ? '0' : '1' ),
                'unreadFilter'          => ( $this->settings['enableUnreadFilter'] == '1' ? '1' : '0' ),
                'fullScreen'            => ( $this->settings['desktopFullScreen'] == '1' ? '1' : '0' ),
                'myProfile'             => ( $this->settings['myProfileButton'] == '1' ? '1' : '0' ),
                'replies'               => ( $this->settings['enableReplies'] == '1' ? '1' : '0' ),
                'selfReplies'           => ( $this->settings['enableSelfReplies'] == '1' ? '1' : '0' ),
                'privateReplies'        => ( $this->settings['privateReplies'] == '1' ? '1' : '0' ),
                'forwardMessages'       => ( $this->settings['enableForwardMessages'] == '1' ? '1' : '0' ),
                'editMessageTimeLimit'  => (int) $this->settings['editMessageTimeLimit'],
                'template'              => $this->settings['template'],
                'layout'                => $this->settings['modernLayout'],
                'singleThread'          => ( $this->settings['singleThreadMode'] == '1' ? '1' : '0' ),
                'forceThread'           => ( $this->settings['newThreadMode'] == '1' ? '1' : '0' ),
                'groupThreads'          => ( $this->settings['disableGroupThreads'] == '1' ? '0': '1'),
                'subjects'              => ( $this->settings['disableSubject'] == '1' ? '0' : '1' ),
                'suggestions'           => ( $this->settings['enableUsersSuggestions'] == '1' ? '1' : '0' ),
                'friends'               => ( $friends ? '1' : '0' ),
                'groups'                => ( $groups ? '1' : '0' ),
                'newThread'             => ( ( $this->settings['disableNewThread'] == '1' && ! current_user_can('manage_options') ) ? '0' : '1' ),
                'mobileFullScreen'      => ( $this->settings['mobileFullScreen'] == '1' ? '1' : '0' ),
                'mobileSwipeBack'       => ( $this->settings['mobileSwipeBack'] == '1' ? '1' : '0' ),
                'autoFullScreen'        => ( $this->settings['autoFullScreen'] == '1' ? '1' : '0' ),
                'tapToOpen'             => ( $this->settings['tapToOpenMsg'] == '1' ? '1' : '0' ),
                'emojiHash'             => get_option('bm-emoji-hash', ''),
                'emojiSet'              => $this->settings['emojiSet'],
                'smileToEmoji'          => ( $this->settings['smileToEmoji'] === '1' ? '1' : '0' ),
                'emojiPicker'           => ( $this->settings['emojiPicker'] === '1' ? '1' : '0' ),
                'sprite'                => Better_Messages_Emojis()->getSpriteUrl(),
                'search'                => ( $this->settings['disableSearch'] == '1' ? '0' : '1' ),
                'datePosition'          =>  ( get_theme_mod('bm-date-position', 'message' ) === 'stack' ? 'stack' : 'message' ),
                'timeFormat'            =>  ( get_theme_mod('bm-time-format', '24' ) === '12' ? '12' : '24' ),
                'avatars'               =>  ( in_array(get_theme_mod('bm-avatars-list', 'show' ), ['hide_private', 'hide_groups', 'hide']) ? get_theme_mod('bm-avatars-list', 'show' ) : 'show' ),
                'subName'               => ( in_array(get_theme_mod('bm-private-sub-name', 'show' ), ['online', 'subject', 'hide']) ? get_theme_mod('bm-private-sub-name', 'show' ) : 'online' ),
                'touchEnter'            => ( $this->settings['disableEnterForTouch'] == '1' ? '0' : '1' ),
                'loginUrl'              => apply_filters( 'better_messages_login_url', wp_login_url( add_query_arg([]) ) ),
                'total_unread'           => (int) $unread_count,
                'disableEnter'           => ( $this->settings['disableEnterForDesktop'] == '1' ? '1' : '0' ),
                'miniClose'              => ( $this->settings['enableMiniCloseButton'] ? '1' : '0' ),
                'miniChats'              => ( $this->realtime && $this->settings['miniChatsEnable'] ? '1' : '0' ),
                'miniMessages'           => ( $this->realtime && $this->settings['miniThreadsEnable'] ? '1' : '0' ),
                'miniAIBots'             => ( $this->settings['miniAIBotsEnable'] == '1' ? '1' : '0' ),
                'miniChatRooms'          => ( $this->settings['miniChatRoomsEnable'] == '1' ? '1' : '0' ),
                'miniUsers'              => ( $this->settings['miniUsersEnable'] == '1' ? '1' : '0' ),
                'combinedAIBots'         => ( $this->settings['combinedAIBotsEnable'] == '1' ? '1' : '0' ),
                'combinedChatRooms'      => ( $this->settings['combinedChatRoomsEnable'] == '1' ? '1' : '0' ),
                'combinedUsers'          => ( $this->settings['combinedUsersEnable'] == '1' ? '1' : '0' ),
                'mobileAIBots'           => ( $this->settings['mobileAIBotsEnable'] == '1' ? '1' : '0' ),
                'mobileChatRooms'        => ( $this->settings['mobileChatRoomsEnable'] == '1' ? '1' : '0' ),
                'mobileUsers'            => ( $this->settings['mobileUsersEnable'] == '1' ? '1' : '0' ),
                'miniWidgetsIconsOnly'   => ( $this->settings['miniWidgetsIconsOnly'] == '1' ? '1' : '0' ),
                'sidePanelIconsOnly'     => ( $this->settings['sidePanelIconsOnly'] == '1' ? '1' : '0' ),
                'mobileTabsIconsOnly'    => ( $this->settings['mobileTabsIconsOnly'] == '1' ? '1' : '0' ),
                'widgetIconMessages'     => (string) ( $this->settings['widgetIconMessages'] ?? '' ),
                'widgetIconFriends'      => (string) ( $this->settings['widgetIconFriends'] ?? '' ),
                'widgetIconGroups'       => (string) ( $this->settings['widgetIconGroups'] ?? '' ),
                'widgetIconAIBots'       => (string) ( $this->settings['widgetIconAIBots'] ?? '' ),
                'widgetIconChatRooms'    => (string) ( $this->settings['widgetIconChatRooms'] ?? '' ),
                'widgetIconUsers'        => (string) ( $this->settings['widgetIconUsers'] ?? '' ),
                'widgetFriendsHideWhenEmpty'   => ( ( $this->settings['widgetFriendsHideWhenEmpty']  ?? '0' ) === '1' ? '1' : '0' ),
                'widgetGroupsHideWhenEmpty'    => ( ( $this->settings['widgetGroupsHideWhenEmpty']   ?? '0' ) === '1' ? '1' : '0' ),
                'widgetAIBotsHideWhenEmpty'    => ( ( $this->settings['widgetAIBotsHideWhenEmpty']   ?? '0' ) === '1' ? '1' : '0' ),
                'widgetChatRoomsHideWhenEmpty' => ( ( $this->settings['widgetChatRoomsHideWhenEmpty'] ?? '0' ) === '1' ? '1' : '0' ),
                'widgetUsersHideWhenEmpty'     => ( ( $this->settings['widgetUsersHideWhenEmpty']    ?? '0' ) === '1' ? '1' : '0' ),
                'widgetUsersOnlineOnly'  => ( ( $this->settings['widgetUsersOnlineOnly']  ?? '0' ) === '1' ? '1' : '0' ),
                'widgetUsersOnlineFirst' => ( ( $this->settings['widgetUsersOnlineFirst'] ?? '0' ) === '1' ? '1' : '0' ),
                'widgetUsersShowOnlineCount' => ( ( $this->settings['widgetUsersShowOnlineCount'] ?? '0' ) === '1' ? '1' : '0' ),
                'widgetUsersSortBy'      => ( in_array( ( $this->settings['widgetUsersSortBy'] ?? '' ), array( 'last_active', 'display_name', 'newest' ), true ) ? $this->settings['widgetUsersSortBy'] : 'last_active' ),
                'widgetUsersDisplayMode' => ( in_array( ( $this->settings['widgetUsersDisplayMode'] ?? '' ), array( 'all', 'roles', 'specific' ), true ) ? $this->settings['widgetUsersDisplayMode'] : 'all' ),
                'widgetUsersIds'         => ( is_array( $this->settings['widgetUsersIds'] ?? null )
                    ? array_values( array_filter( array_map( 'intval', $this->settings['widgetUsersIds'] ), function( $id ){ return $id > 0; } ) )
                    : array() ),
                'widgetUsersRoleHashes'  => Better_Messages()->functions->hash_role_slugs(
                    is_array( $this->settings['widgetUsersRoles'] ?? null ) ? $this->settings['widgetUsersRoles'] : array()
                ),
                'widgetFriendsShowSearch'   => ( ( $this->settings['widgetFriendsShowSearch']   ?? '1' ) === '1' ? '1' : '0' ),
                'widgetGroupsShowSearch'    => ( ( $this->settings['widgetGroupsShowSearch']    ?? '1' ) === '1' ? '1' : '0' ),
                'widgetAIBotsShowSearch'    => ( ( $this->settings['widgetAIBotsShowSearch']    ?? '1' ) === '1' ? '1' : '0' ),
                'widgetChatRoomsShowSearch' => ( ( $this->settings['widgetChatRoomsShowSearch'] ?? '1' ) === '1' ? '1' : '0' ),
                'widgetUsersShowSearch'     => ( ( $this->settings['widgetUsersShowSearch']     ?? '1' ) === '1' ? '1' : '0' ),
                'combinedChats'          => ( $this->realtime && $this->settings['combinedChatsEnable'] == '1' ? '1' : '0' ),
                'miniWidgetsStyle'       => ( $this->settings['miniWidgetsStyle'] ?? 'classic' ),
                'widgetsPosition'        => ( get_theme_mod('bm-widgets-position', 'right') === 'left' ? 'left' : 'right' ),
                'miniWidgetsAnimation'   => ( $this->settings['miniWidgetsAnimation'] ?? '1' ),
                'bubbleChatHeads'        => ( $this->settings['bubbleChatHeads'] ?? '0' ),
                'bubbleChatHeadsLimit'   => intval( $this->settings['bubbleChatHeadsLimit'] ?? 5 ),
                'bubbleIcon'             => ( $this->settings['bubbleIcon'] ?? 'comment' ),
                'bubbleCloseOnOutside'   => ( $this->settings['bubbleCloseOnOutside'] ?? '0' ),
                'miniAudio'              => ( $this->realtime && $this->settings['miniChatAudioCall'] ? '1' : '0' ),
                'miniVideo'              => ( $this->realtime && $this->settings['miniChatVideoCall'] ? '1' : '0' ),
                'messagesStatus'         => ( $this->realtime && $this->settings['messagesStatus'] ? '1' : '0' ),
                'listStatus'             => ( $this->realtime && $this->settings['messagesStatusList'] ? '1' : '0' ),
                'statusDetails'          => ( $this->realtime && $this->settings['messagesStatusDetailed'] ? '1' : '0' ),
                'combinedView'           => ( $this->settings['combinedView'] == '1' ? '1' : '0' ),
                'onSiteNotification'     => ( $this->settings['disableOnSiteNotification'] == '1' ? '0' : '1' ),
                'onsitePosition'         => ( $this->settings['onsitePosition'] === 'right' ? 'right': 'left' ),
                'titleNotifications'     => ( $this->settings['titleNotifications'] == '1' ? '1' : '0' ),
                'hPBE'                   => ( $this->settings['hidePossibleBreakingElements'] == '1' ? '1' : '0' ),
                'userSettings'           => ( $this->settings['disableUserSettings'] == '1' ? '0' : '1'),
                'miniSync'               => ( $this->settings['miniChatDisableSync'] != '1' ? '1' : '0' ),
                'pinning'                => ( $this->settings['pinnedThreads'] == '1' ? '1' : '0' ),
                'drafts'                 => ( $this->settings['enableDrafts'] == '1' ? '1' : '0' ),
                'mobileOnsite'           => ( in_array($this->settings['mobileOnsiteLocation'], ['top', 'bottom'] ) ? $this->settings['mobileOnsiteLocation'] : 'auto' ),
                'enableSound'            => $enableSound,
                'forceMentions'          => ( $this->settings['mentionsForceNotifications'] == '1' ? '1' : '0' ),
                'guests'                 => ( Better_Messages()->guests->guest_access_enabled() ? '1' : '0' ),
                'reports'                => ( $this->settings['allowReports'] == '1' ? '1' : '0' ),
                'translationLanguage'    => ( $this->settings['aiTranslationEnabled'] === '1' && is_user_logged_in()
                    ? Better_Messages()->functions->get_user_meta( get_current_user_id(), 'bpbm_translation_language', true )
                    : '' ),
            );

            $sounds_keys = [
                'notificationSound',
                'sentSound',
                'callSound',
                'dialingSound'
            ];

            foreach ( $sounds_keys as $key ) {
                $setting_key = $key . 'Url';
                if( ! empty( $this->settings[$setting_key] ) ){
                    $script_variables[$setting_key] = $this->settings[$setting_key];
                }
            }

            $suggested_conversations = apply_filters('better_messages_suggested_conversations', $this->settings['suggestedConversations'], get_current_user_id() );

            if( ! empty( $suggested_conversations ) ){
                $script_variables['suggestedConversations'] = $suggested_conversations;
            }

            if( ! is_user_logged_in() && get_option( 'users_can_register' ) ){
                $script_variables['registerUrl'] = apply_filters( 'better_messages_registration_url', wp_registration_url() );
            }

            if( current_user_can('manage_options') ){
                $script_variables['isAdmin'] = '1';
            }

            $balanceKeys = [
                'pointsBalanceHeader',
                'pointsBalanceThreadsList',
                'pointsBalanceThreadsListBottom',
                'pointsBalanceUserMenu',
                'pointsBalanceUserMenuPopup',
                'pointsBalanceReplyForm',
            ];
            $hasBalance = false;
            foreach ( $balanceKeys as $key ) {
                if ( ( $this->settings[ $key ] ?? '0' ) === '1' ) {
                    $script_variables[ $key ] = '1';
                    $hasBalance = true;
                }
            }
            if ( $hasBalance && is_user_logged_in() && class_exists( 'Better_Messages_Points' ) ) {
                $provider = Better_Messages_Points()->get_provider();
                if ( $provider ) {
                    $script_variables['pointsBalance'] = (float) $provider->get_user_balance( get_current_user_id() );
                }
                if ( ! empty( $this->settings['pointsBalanceUrl'] ) ) {
                    $script_variables['pointsBalanceUrl'] = $this->settings['pointsBalanceUrl'];
                }
            }

            if( Better_Messages_Rest_Api_Bulk_Message()->has_access() ){
                $script_variables['canBulk'] = '1';
                $script_variables['adminUrl'] = admin_url();
            }

            if( $this->settings['enableReactions'] == '1' ){
                $script_variables['reactions']     = Better_Messages_Reactions::instance()->get_reactions();
                $script_variables['reactionsList'] = ( $this->settings['enableReactionsPopup'] == '1' ? '1' : '0' );
            }

            if( $friends ){
                $script_variables['combinedFriends'] = ( $this->settings['combinedFriendsEnable'] == '1' ? '1' : '0' );
                $script_variables['miniFriends']     = ( $this->settings['miniFriendsEnable'] == '1' ? '1' : '0' );
                $script_variables['mobileFriends']   = ( $this->settings['mobileFriendsEnable'] == '1' ? '1' : '0' );
            }

            if( $groups ){
                $script_variables['combinedGroups'] = ( ($this->settings['combinedGroupsEnable'] == '1') ? '1' : '0' );
                $script_variables['miniGroups']     = ( $this->settings['enableMiniGroups'] == '1' ? '1' : '0' );
                $script_variables['mobileGroups']   = ( $this->settings['mobileGroupsEnable'] == '1' ? '1' : '0' );
            }

            // Resolve widget order arrays for frontend (map integration-specific IDs to generic)
            $order_map = [
                'bp-friends' => 'friends', 'um-friends' => 'friends', 'ps-friends' => 'friends',
                'bp-groups'  => 'groups',  'um-groups'  => 'groups',  'ps-groups'  => 'groups',  'fc-groups' => 'groups',
            ];

            foreach ( ['miniWidgetsOrder', 'sidePanelTabsOrder', 'mobileTabsOrder'] as $order_key ) {
                $raw = isset( $this->settings[$order_key] ) ? $this->settings[$order_key] : [];
                if ( ! empty( $raw ) && is_array( $raw ) ) {
                    $resolved = [];
                    foreach ( $raw as $id ) {
                        $mapped = isset( $order_map[$id] ) ? $order_map[$id] : $id;
                        if ( ! in_array( $mapped, $resolved, true ) ) {
                            $resolved[] = $mapped;
                        }
                    }
                    $script_variables[$order_key] = $resolved;
                }
            }

            if( $this->realtime && $this->settings['userStatuses'] ){
                $script_variables['userStatuses'] = $this->websocket->get_all_statuses();
            }

            if( $this->settings['unreadCounter'] === 'conversations' ){
                $script_variables['unreadCounter'] = 'conversations';
            }

            if( is_user_logged_in() ) {
                $user_data = $this->functions->rest_user_item( get_current_user_id(), false );

                $script_variables['me'] = [
                    'user_id'  => get_current_user_id(),
                    'name'     => base64_encode( $user_data['name'] ),
                    'url'      => base64_encode( $user_data['url'] ?? '' ),
                    'avatar'   => base64_encode( $user_data['avatar'] ),
                    'verified' => (int) ( $user_data['verified'] ?? 0 ),
                ];

                if ( isset( $user_data['status'] ) ) {
                    $script_variables['me']['status'] = $user_data['status'];
                }

                if ( $this->realtime ) {
                    $profile = $this->functions->build_ws_profile( get_current_user_id(), $user_data );
                    $script_variables['me']['pd']  = $profile['pd'];
                    $script_variables['me']['pdh'] = $profile['pdh'];
                    $script_variables['me']['pds'] = $profile['pds'];
                    $script_variables['me']['role_hashes']     = $profile['role_hashes'];
                    $script_variables['me']['role_hashes_sig'] = $profile['role_hashes_sig'];
                }
            }

            $script_variables['ukey'] = $ukey;

            if ( get_current_user_id() > 0 && $this->settings['attachmentsProxy'] === '1' ) {
                $script_variables['fileSigningKey'] = hash_hmac( 'sha256', $ukey, wp_salt( 'auth' ) );
            }

            if ( bpbm_fs()->is__premium_only() ) {
                $script_variables['ws'] = '1';

                if ( $this->realtime ) {
                    $socket_server = apply_filters('better_messages_realtime_server', apply_filters( 'bp_better_messages_realtime_server', 'https://cloud.better-messages.com/' ) );
                    $turn_server   = apply_filters( 'better_messages_turn_server', 'turn.bpbettermessages.com' );
                    $video_server   = apply_filters( 'better_messages_video_server', 'video-cloud.better-messages.com' );

                    $script_variables['socket_server'] = $socket_server;
                    $script_variables['turn_server']   = $turn_server;
                    $script_variables['video_server']  = $video_server;
                    $script_variables['site_id']       = Better_Messages_WebSocket()->site_id;
                    $script_variables['secret_key']    = sha1($script_variables['site_id'] . Better_Messages_WebSocket()->secret_key . get_current_user_id() );

                    if ( apply_filters( 'better_messages_3rd_party_push_active', false ) ) {
                        $this->settings['enablePushNotifications'] = '0';
                    }

                    $script_variables['callRequestTimeLimit'] = $this->settings['callRequestTimeLimit'];
                    $script_variables['callsRevertIcons']     = ( $this->settings['callsRevertIcons'] === '1' ? '0' : '1' );
                    $script_variables['fast']                 = apply_filters('bp_better_messages_fast', '1');

                    if ( $this->settings['enablePushNotifications'] === '1' ) {
                        $vapid_keys = Better_Messages()->websocket->get_vapid_keys();
                        $script_variables['vapidPublicKey'] = $vapid_keys['publicKey'];
                        $script_variables['PNworker'] = site_url('/');
                    }

                    $script_variables['le'] = $localEncryption ? '1' : '0' ;

                    $script_variables['encryption'] = '0';

                    if ( $this->settings['encryptionEnabled'] === '1' ) {
                        $script_variables['encryption'] = '1';
                        $script_variables['ekey'] = $this->websocket->get_site_secret_key();
                    }

                    if( ! empty( trim(Better_Messages()->settings['badWordsList']) ) ){
                        $skip_for_admin = Better_Messages()->settings['badWordsSkipAdmins'] === '1' && current_user_can('manage_options');
                        if( ! $skip_for_admin ){
                            $script_variables['badWords'] = md5(Better_Messages()->settings['badWordsList']);
                        }
                    }
                }
            }

            $voice_max_duration = (int) $this->settings['voiceMessagesMaxDuration'];
            if ( $voice_max_duration > 0 ) {
                $script_variables['voiceMaxDuration'] = $voice_max_duration;
            }

            return $script_variables;
        }
    }


    if( ! class_exists('BP_Better_Messages') ){
        class BP_Better_Messages extends Better_Messages {}
    }

    if( ! function_exists('BP_Better_Messages') ) {
        /**
         * Fallback to old naming
         * @return Better_Messages|null
         */
        function BP_Better_Messages()
        {
            return Better_Messages();
        }
    }

    function Better_Messages()
    {
        return Better_Messages::instance();
    }

    function bm_bp_is_active( $component = '', $feature = '' ){
        if( function_exists('bp_is_active') ) {
            return bp_is_active( $component, $feature );
        } else {
            return false;
        }
    }

    function bm_get_table( $table ){
        global  $wpdb;
        $bp_prefix = apply_filters( 'bp_core_get_table_prefix', $wpdb->base_prefix );

        if( function_exists( 'buddypress' ) && bm_bp_is_active('messages') ){
            switch ( $table ){
                case 'threads' :
                    return $bp_prefix . 'bm_threads';
                case 'messages' :
                    return $bp_prefix . 'bm_message_messages';
                    break;
                case 'meta' :
                    return $bp_prefix . 'bm_message_meta';
                    break;
                case 'recipients' :
                    return $bp_prefix . 'bm_message_recipients';
                    break;
                case 'notifications' :
                    if( isset(buddypress()->notifications) ) {
                        return buddypress()->notifications->table_name;
                    } else {
                        return false;
                    }
                    break;
                case 'threadsmeta' :
                    return $bp_prefix . 'bm_thread_meta';
                    break;
                case 'mentions' :
                    return $bp_prefix . 'bm_mentions';
                    break;
                case 'moderation' :
                    return $bp_prefix . 'bm_moderation';
                    break;
                case 'guests';
                    return $bp_prefix . 'bm_guests';
                case 'users' :
                    return $bp_prefix . 'bm_user_index';
                case 'roles' :
                    return $bp_prefix . 'bm_user_roles_index';
                    break;
                case 'bulk_jobs' :
                    return $bp_prefix . 'bm_bulk_jobs';
                case 'bulk_job_threads' :
                    return $bp_prefix . 'bm_bulk_job_threads';
                case 'ai_usage' :
                    return $bp_prefix . 'bm_ai_usage';
            }
        } else {
            switch ( $table ){
                case 'threads' :
                    return $bp_prefix . 'bm_threads';
                case 'messages' :
                    return $bp_prefix . 'bm_message_messages';
                    break;
                case 'meta' :
                    return $bp_prefix . 'bm_message_meta';
                    break;
                case 'recipients' :
                    return $bp_prefix . 'bm_message_recipients';
                    break;
                case 'threadsmeta' :
                    return $bp_prefix . 'bm_thread_meta';
                    break;
                case 'mentions' :
                    return $bp_prefix . 'bm_mentions';
                    break;
                case 'moderation' :
                    return $bp_prefix . 'bm_moderation';
                    break;
                case 'notifications' :
                    return false;
                    break;
                case 'guests';
                    return $bp_prefix . 'bm_guests';
                case 'users' :
                    return $bp_prefix . 'bm_user_index';
                    break;
                case 'roles' :
                    return $bp_prefix . 'bm_user_roles_index';
                    break;
                case 'bulk_jobs' :
                    return $bp_prefix . 'bm_bulk_jobs';
                case 'bulk_job_threads' :
                    return $bp_prefix . 'bm_bulk_job_threads';
                case 'ai_usage' :
                    return $bp_prefix . 'bm_ai_usage';
            }
        }

        return false;
    }

    function Better_Messages_Incompatible_PHP(){
        echo '<div class="notice notice-error">';
        echo '<p><b>Better Messages</b> require at least <b>PHP 7.1</b>, currently running PHP <b>' . PHP_VERSION . '</b>.</p>';
        echo '<p>Please upgrade your website PHP version to use Better Messages.</p>';
        echo '<p class="button-container"><a class="button button-primary" href="https://wordpress.org/support/update-php/" target="_blank" rel="noopener" style="vertical-align: middle;">Learn more about updating PHP <span class="screen-reader-text">(opens in a new tab)</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a>	</p>';
        echo '</div>';
    }


    function Better_Messages_Init()
    {
        if( version_compare(PHP_VERSION, '7.1') < 0 ){
            add_action( 'admin_notices', 'Better_Messages_Incompatible_PHP' );
            return;
        }
        if ( class_exists( 'BuddyPress' ) && bm_bp_is_active( 'messages' ) ) {
            Better_Messages();
        } else {
            $is_activating_buddypress = isset( $_GET['plugin'] )
                && isset( $_GET['action'] )
                && strpos( $_GET['plugin'], 'bp-loader.php' ) !== false
                && $_GET['action'] == 'activate';

            $is_activating_buddypress_2 = isset( $_GET['plugin'] )
                && isset( $_GET['action'] )
                && strpos( $_GET['plugin'], 'bp-loader.php' ) !== false
                && $_GET['action'] == 'activate-plugin';

            $is_activating_buddypress_3 = isset( $_GET['plugins'] )
                && isset( $_GET['action'] )
                && strpos( $_GET['plugins'], 'bp-loader.php' ) !== false
                && $_GET['action'] == 'update-selected';

            if ( $is_activating_buddypress || $is_activating_buddypress_2 || $is_activating_buddypress_3 ) {
                Better_Messages();
            } else {
                require_once 'vendor/buddypress/functions.php';
                Better_Messages();
            }
        }
    }

    // Uncanny Automator
    require_once trailingslashit(dirname(__FILE__)) . 'addons/uncanny/uncanny-automator-better-messages.php';
    // AutomatorWP
    require_once trailingslashit(dirname(__FILE__))     . 'addons/automatorwp/automatorwp.php';

    add_action( 'plugins_loaded', 'Better_Messages_Init', 20 );
    require_once trailingslashit( dirname(__FILE__) ) . 'inc/install.php';
    register_activation_hook( __FILE__, 'better_messages_activation' );
    register_deactivation_hook( __FILE__, 'better_messages_deactivation' );

    if ( ! function_exists( 'bpbm_fs' ) ) {
        // Create a helper function for easy SDK access.
        function bpbm_fs()
        {
            global  $bbm_fs;

            if ( ! isset( $bbm_fs ) ) {
                if ( !defined( 'WP_FS__PRODUCT_1557_MULTISITE' ) ) {
                    define( 'WP_FS__PRODUCT_1557_MULTISITE', true );
                }

                // Include Freemius SDK.
                require_once dirname(__FILE__) . '/vendor/freemius/start.php';

                $bbm_fs = fs_dynamic_init( array(
                    'id'                  => '1557',
                    'slug'                => 'bp-better-messages',
                    'premium_slug'        => 'bp-better-messages-websocket',
                    'type'                => 'plugin',
                    'public_key'          => 'pk_8af54172153e9907893f32a4706e2',
                    'is_premium'          => false,
                    'premium_suffix'      => '- WebSocket Version',
                    'has_addons'          => true,
                    'has_paid_plans'      => true,
                    'trial'               => array(
                        'days'               => 3,
                        'is_require_payment' => true,
                    ),
                    'menu'                => array(
                        'slug'           => 'bp-better-messages',
                        'support'        => false,
                    ),
                    'is_live'             => true,
                    'is_org_compliant'    => true,
                ) );
            }

            return $bbm_fs;
        }

        // Init Freemius.
        bpbm_fs();
        // Signal that SDK was initiated.
        do_action( 'bbm_fs_loaded' );

        bpbm_fs()->add_filter( 'templates/checkout.php', function ( $template ) {
            if ( false !== strpos( $template, '&billing_cycle=annual' ) ) {
                $template = str_replace( '&billing_cycle=annual', '&billing_cycle=annual&show_monthly_switch=true', $template );
            }

            return $template;
        } );
    }

}
