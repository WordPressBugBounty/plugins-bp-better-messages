<?php

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/pack-manager.php';
require_once __DIR__ . '/sticker-manifest.php';
require_once __DIR__ . '/catalog-client.php';
require_once __DIR__ . '/rest.php';

if ( ! class_exists( 'Better_Messages_Stickers_Manager' ) ) {
    class Better_Messages_Stickers_Manager
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
            // Boot sub-systems. They register their own hooks.
            Better_Messages_Stickers_REST::instance();

            add_action( 'rest_api_init', array( $this, 'register_public_routes' ) );
            add_action( 'bp_better_chat_settings_updated', array( $this, 'on_settings_updated' ) );
            add_filter( 'bp_better_messages_script_variables', array( $this, 'script_variables' ) );

            // Defer first-boot hash sync to the `init` hook so the main settings option
            // is fully loaded and we don't race with Better_Messages()->settings being unset.
            add_action( 'init', array( $this, 'ensure_hash_exists' ), 20 );
        }

        public function ensure_hash_exists()
        {
            $pm   = Better_Messages_Sticker_Pack_Manager::instance();
            $hash = $pm->get_hash();

            if ( $hash === '' ) {
                $packs = $pm->get_all();
                if ( ! empty( $packs ) ) {
                    $pm->update_hash();
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

            if ( empty( $sticker_url ) ) {
                return new WP_Error( 'missing_sticker', __( 'Missing sticker URL.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $upload = wp_upload_dir();
            $prefix = trailingslashit( $upload['baseurl'] ) . 'better-messages/stickers/packs/';
            if ( strpos( $sticker_url, $prefix ) !== 0 || strpos( $sticker_url, '..' ) !== false ) {
                return new WP_Error( 'invalid_sticker', __( 'Invalid sticker URL.', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $message = '<span class="bpbm-sticker"><img src="' . esc_url( $sticker_url ) . '" alt=""></span>';

            $args = array(
                'content'    => $message,
                'thread_id'  => $thread_id,
                'error_type' => 'wp_error',
                'return'     => 'message_id',
            );

            $user_id = Better_Messages()->functions->get_current_user_id();
            $errors  = array();

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
                do_action( 'better_messages_on_message_not_sent', $thread_id, '', $errors );
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
                $result['update'] = Better_Messages_Rest_Api()->get_messages( $thread_id, array( $sent ) );
            }

            return $result;
        }

        public function rest_get_manifest_url()
        {
            $locale = determine_locale();
            $url    = Better_Messages_Sticker_Manifest::instance()->get_manifest_url( $locale );
            return rest_ensure_response( array(
                'url'    => $url,
                'locale' => $locale,
                'hash'   => Better_Messages_Sticker_Pack_Manager::instance()->get_hash(),
            ) );
        }

        public function on_settings_updated( $settings )
        {
            Better_Messages_Sticker_Manifest::instance()->delete_manifests();
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
            // Empty provider: backward-compat detection.
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

            // Empty provider: backward-compat detection.
            return ! empty( $settings['stipopApiKey'] ) ? 'stipop' : 'disabled';
        }

        private function get_allowed_pack_ids(){
            $settings = Better_Messages()->settings;
            $summary  = isset( $settings['stickerPacksSummary'] ) ? $settings['stickerPacksSummary'] : null;

            if ( ! is_array( $summary ) ) {
                return array();
            }

            if ( empty( $summary ) ) {
                return array();
            }

            $user_id    = Better_Messages()->functions->get_current_user_id();
            $user_roles = Better_Messages()->functions->get_user_roles( $user_id );
            // Admins bypass per-role restrictions so a pack limited to e.g.
            // "editor" is still visible to site administrators.
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
            $url = Better_Messages_Sticker_Manifest::instance()->get_manifest_url( determine_locale() );
            return $url ? $url : '';
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
