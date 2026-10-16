<?php

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/pack-manager.php';
require_once __DIR__ . '/sticker-manifest.php';
require_once __DIR__ . '/catalog-client.php';
require_once __DIR__ . '/rest.php';

if ( ! class_exists( 'Better_Messages_Stickers_Manager' ) ) {
    class Better_Messages_Stickers_Manager
    {
        const ADOPTED_OPTION = 'better_messages_stickers_adopted';

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
            Better_Messages_Stickers_REST::instance();

            add_action( 'rest_api_init', array( $this, 'register_public_routes' ) );
            add_action( 'bp_better_chat_settings_updated', array( $this, 'on_settings_updated' ) );
            add_filter( 'bp_better_messages_script_variables', array( $this, 'script_variables' ) );

            add_filter( 'wordplus_stickers_pack_roots', array( $this, 'pack_roots' ) );
            add_filter( 'wordplus_pickers_user_roles', array( $this, 'user_roles' ), 10, 2 );
            add_filter( 'wordplus_stickers_roles', array( $this, 'panel_roles' ) );
            add_action( 'wordplus_sticker_packs_saved', array( $this, 'on_packs_saved' ), 10, 2 );

            add_action( 'init', array( $this, 'adopt_packs' ), 20 );
        }

        public static function library()
        {
            $pickers = function_exists( 'wordplus_pickers' ) ? wordplus_pickers() : null;
            return $pickers && method_exists( $pickers, 'stickers' ) ? $pickers->stickers() : null;
        }

        public function pack_roots( $roots )
        {
            $roots[] = Better_Messages_Sticker_Pack_Manager::ROOT;
            return $roots;
        }

        public function user_roles( $roles, $user_id )
        {
            return (int) $user_id !== 0 ? (array) Better_Messages()->functions->get_user_roles( (int) $user_id ) : $roles;
        }

        public function panel_roles( $roles )
        {
            $roles   = array_values( array_filter( (array) $roles, function( $role ) {
                return ! is_array( $role ) || ( $role['key'] ?? '' ) !== 'administrator';
            } ) );
            $roles[] = array( 'key' => 'bm-guest', 'name' => _x( 'Guests', 'Settings page', 'bp-better-messages' ) );

            return $roles;
        }

        public function enqueue_panel()
        {
            $library = self::library();

            if ( $library && method_exists( $library, 'admin' ) ) {
                $library->admin()->enqueue();
            }
        }

        public function adopt_packs()
        {
            $library = self::library();
            if ( ! $library ) {
                return;
            }

            $checked = get_option( self::ADOPTED_OPTION, false );
            if ( false !== $checked && (string) $checked === $this->stored_packs_hash() ) {
                return;
            }

            if ( false === get_option( WordPlus_SDK_Stickers::OPTION, false ) ) {
                $packs = get_option( Better_Messages_Sticker_Pack_Manager::OPTION_KEY, array() );

                if ( is_array( $packs ) && ! empty( $packs ) && $library->adopt( $packs, Better_Messages_Sticker_Pack_Manager::ROOT ) ) {
                    $upload = wp_upload_dir();
                    foreach ( glob( trailingslashit( $upload['basedir'] ) . 'better-messages/stickers/bm-stickers-*.json' ) ?: array() as $old ) {
                        @unlink( $old );
                    }
                }
            }

            update_option( self::ADOPTED_OPTION, $this->stored_packs_hash(), true );
        }

        private function stored_packs_hash()
        {
            $stored = get_option( 'bp-better-chat-settings', array() );

            return is_array( $stored ) && isset( $stored[ Better_Messages_Sticker_Pack_Manager::HASH_KEY ] ) && is_scalar( $stored[ Better_Messages_Sticker_Pack_Manager::HASH_KEY ] ) ? (string) $stored[ Better_Messages_Sticker_Pack_Manager::HASH_KEY ] : '';
        }

        public function on_packs_saved( $packs, $meta )
        {
            update_option( Better_Messages_Sticker_Pack_Manager::OPTION_KEY, $packs, false );

            $stored = get_option( 'bp-better-chat-settings', array() );
            if ( ! is_array( $stored ) ) {
                $stored = array();
            }

            $stored[ Better_Messages_Sticker_Pack_Manager::HASH_KEY ]    = isset( $meta['hash'] ) ? $meta['hash'] : '';
            $stored[ Better_Messages_Sticker_Pack_Manager::SUMMARY_KEY ] = isset( $meta['summary'] ) ? $meta['summary'] : array();

            $enable = ( ! isset( $stored['stickersProvider'] ) || $stored['stickersProvider'] === '' ) && empty( $stored['stipopApiKey'] ) && ! empty( $packs );
            if ( $enable ) {
                $stored['stickersProvider'] = 'builtin';
            }

            update_option( 'bp-better-chat-settings', $stored );
            update_option( self::ADOPTED_OPTION, $this->stored_packs_hash(), true );

            if ( isset( Better_Messages()->settings ) && is_array( Better_Messages()->settings ) ) {
                Better_Messages()->settings[ Better_Messages_Sticker_Pack_Manager::HASH_KEY ]    = $stored[ Better_Messages_Sticker_Pack_Manager::HASH_KEY ];
                Better_Messages()->settings[ Better_Messages_Sticker_Pack_Manager::SUMMARY_KEY ] = $stored[ Better_Messages_Sticker_Pack_Manager::SUMMARY_KEY ];
                if ( $enable ) {
                    Better_Messages()->settings['stickersProvider'] = 'builtin';
                }
            }
        }

        public function register_public_routes()
        {
            if ( ! $this->is_builtin_active() ) {
                return;
            }

            register_rest_route( 'better-messages/v1', '/stickers/manifest', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_get_manifest_url' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' ),
            ) );

            register_rest_route( 'better-messages/v1', '/stickers/builtin/(?P<id>\d+)/send', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_send_sticker' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'can_reply' ),
            ) );
        }

        public function rest_send_sticker( WP_REST_Request $request )
        {
            $thread_id   = intval( $request->get_param( 'id' ) );
            $sticker_url = esc_url_raw( (string) $request->get_param( 'sticker_url' ) );
            $temp_id     = sanitize_text_field( (string) $request->get_param( 'temp_id' ) );
            $temp_time   = sanitize_text_field( (string) $request->get_param( 'temp_time' ) );
            $user_id     = Better_Messages()->functions->get_current_user_id();

            if ( ! empty( $temp_id ) ) {
                $existing_message_id = Better_Messages_Rest_Api()->get_temp_id_message( $thread_id, $temp_id, $user_id );

                if ( $existing_message_id > 0 ) {
                    return array(
                        'result'   => $existing_message_id,
                        'redirect' => false,
                        'update'   => Better_Messages_Rest_Api()->get_message_update( $thread_id, $existing_message_id ),
                    );
                }
            }

            if ( empty( $sticker_url ) ) {
                return new WP_Error( 'missing_sticker', __( 'Missing sticker URL.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $library     = self::library();
            $sticker_url = $library ? $library->rewrite_url( $sticker_url ) : $sticker_url;
            if ( ! $library || strpos( $sticker_url, '..' ) !== false || ! $library->owns_url( $sticker_url ) ) {
                return new WP_Error( 'invalid_sticker', __( 'Invalid sticker URL.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $message = '<span class="bm-sticker"><img src="' . esc_url( $sticker_url ) . '" alt=""></span>';

            $args = array(
                'content'    => $message,
                'thread_id'  => $thread_id,
                'error_type' => 'wp_error',
                'return'     => 'message_id',
                'is_pending' => (int) Better_Messages()->moderation->is_moderation_enabled( $user_id, $thread_id, false ),
            );

            Better_Messages_Rest_Api()->apply_temp_id( $args, $temp_id, $temp_time );

            $errors = array();

            if ( ! Better_Messages()->functions->can_send_message_filter(
                Better_Messages()->functions->check_access( $thread_id ),
                $user_id,
                $thread_id
            ) ) {
                $errors[] = __( 'You are not allowed to reply to this conversation.', 'bp-better-messages' );
            }

            Better_Messages()->functions->before_message_send_filter( $args, $errors );

            $sent = null;
            if ( empty( $errors ) ) {
                remove_filter( 'better_messages_message_content_before_save', array( Better_Messages()->functions, 'messages_filter_kses' ), 1 );
                remove_action( 'better_messages_message_sent', 'messages_notification_new_message', 10 );
                $sent = Better_Messages()->functions->new_message( $args );
                add_action( 'better_messages_message_sent', 'messages_notification_new_message', 10 );
                Better_Messages()->functions->messages_mark_thread_read( $thread_id );
                add_filter( 'better_messages_message_content_before_save', array( Better_Messages()->functions, 'messages_filter_kses' ), 1 );

                if ( is_wp_error( $sent ) ) {
                    $errors[] = $sent->get_error_message();
                }
            }

            if ( ! empty( $errors ) ) {
                do_action( 'better_messages_on_message_not_sent', $thread_id, $temp_id, $errors );
                return array(
                    'result'   => false,
                    'errors'   => $errors,
                    'redirect' => 'redirect',
                );
            }

            $result = array(
                'result'   => $sent,
                'redirect' => false,
            );

            if ( $sent && ! is_wp_error( $sent ) ) {
                $result['update'] = Better_Messages_Rest_Api()->get_message_update( $thread_id, (int) $sent );
            }

            return $result;
        }

        public function rest_get_manifest_url()
        {
            $library = self::library();
            return $library ? $library->rest()->manifest() : rest_ensure_response( array( 'url' => '', 'locale' => determine_locale(), 'hash' => '' ) );
        }

        public function on_settings_updated( $settings )
        {
            $library = self::library();
            if ( $library ) {
                $library->manifest()->delete_manifests();
            }
        }

        public function is_enabled(){
            $provider = $this->get_provider();
            if ( $provider === 'disabled' ) {
                return false;
            }
            if ( $provider === 'stipop' ) {
                return ! empty( Better_Messages()->settings['stipopApiKey'] );
            }
            if ( $provider === 'builtin' ) {
                return ! empty( $this->get_allowed_pack_ids() );
            }
            if ( ! empty( Better_Messages()->settings['stipopApiKey'] ) ) {
                return true;
            }
            return ! empty( $this->get_allowed_pack_ids() );
        }

        public function get_provider(){
            $settings = Better_Messages()->settings;
            $provider = isset( $settings['stickersProvider'] ) ? $settings['stickersProvider'] : '';

            if ( $provider !== '' ) {
                return $provider;
            }

            return ! empty( $settings['stipopApiKey'] ) ? 'stipop' : 'disabled';
        }

        private function get_allowed_pack_ids(){
            $library = self::library();
            $summary = $library ? $library->summary() : array();

            if ( empty( $summary ) ) {
                return array();
            }

            $user_id    = Better_Messages()->functions->get_current_user_id();
            $user_roles = Better_Messages()->functions->get_user_roles( $user_id );
            $is_admin   = $user_id > 0 && user_can( $user_id, 'bm_can_administrate' );
            $ids        = array();

            foreach ( $summary as $pack ) {
                if ( ! is_array( $pack ) || ! isset( $pack['id'] ) ) {
                    continue;
                }
                $allowed = isset( $pack['allowed_roles'] ) ? (array) $pack['allowed_roles'] : array();
                if ( $is_admin || empty( $allowed ) || array_intersect( $allowed, $user_roles ) ) {
                    $ids[] = $pack['id'];
                }
            }
            return $ids;
        }

        public function script_variables( $vars ){
            $enabled  = $this->is_enabled();
            $provider = $enabled ? $this->get_provider() : 'disabled';

            $vars['stickers']              = ( $enabled ? '1' : '0' );
            $vars['stickersProvider']       = $provider;
            $vars['stickerManifestUrl']     = $this->get_manifest_url_for_current_locale();
            $vars['allowedStickerPacks']    = ( $provider === 'builtin' ) ? $this->get_allowed_pack_ids() : array();
            $vars['stickerSuggestions']     = (
                $provider === 'builtin'
                && ! empty( Better_Messages()->settings['stickerSuggestions'] )
                && Better_Messages()->settings['stickerSuggestions'] === '1'
                    ? '1' : '0'
            );

            return $vars;
        }

        public function is_builtin_active()
        {
            return $this->get_provider() === 'builtin';
        }

        public function get_manifest_url_for_current_locale()
        {
            if ( ! $this->is_builtin_active() ) {
                return '';
            }
            $library = self::library();
            return $library ? $library->manifest_url( determine_locale() ) : '';
        }
    }
}

if ( ! function_exists( 'Better_Messages_Stickers_Manager' ) ) {
    function Better_Messages_Stickers_Manager()
    {
        return Better_Messages_Stickers_Manager::instance();
    }
}

Better_Messages_Stickers_Manager();
