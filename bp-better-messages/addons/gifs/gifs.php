<?php

if ( ! defined( 'ABSPATH' ) ) exit;

require_once __DIR__ . '/provider-interface.php';
require_once __DIR__ . '/provider-factory.php';
require_once __DIR__ . '/giphy.php';
require_once __DIR__ . '/klipy.php';

if ( ! class_exists( 'Better_Messages_Gifs' ) ) {
    class Better_Messages_Gifs
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Gifs();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
            add_action( 'bp_better_chat_settings_updated', array( $this, 'on_settings_updated' ) );

            add_filter( 'bp_better_messages_pre_format_message', array( $this, 'format_message' ), 9, 4 );
            add_filter( 'bp_better_messages_after_format_message', array( $this, 'after_format_message' ), 9, 4 );
            add_filter( 'bp_better_messages_script_variables', array( $this, 'script_variables' ) );
        }

        public function is_enabled(){
            return Better_Messages_Gif_Provider_Factory::get_active() !== null;
        }

        public function script_variables( $vars ){
            $vars['gifs'] = ( $this->is_enabled() ? '1' : '0' );
            return $vars;
        }

        public function rest_api_init()
        {
            if ( ! Better_Messages_Gif_Provider_Factory::get_active() ) {
                return;
            }

            register_rest_route( 'better-messages/v1', '/gifs', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'get_gifs' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' ),
            ) );

            register_rest_route( 'better-messages/v1', '/gifs/(?P<id>[A-Za-z0-9_-]+)/send', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'send_gif' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'can_reply' ),
            ) );
        }

        public function get_gifs( WP_REST_Request $request )
        {
            $provider = Better_Messages_Gif_Provider_Factory::get_active();
            if ( ! $provider ) {
                return new WP_Error( 'gifs_disabled', __( 'GIFs are not enabled.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $user_id = Better_Messages()->functions->get_current_user_id();
            $page    = intval( $request->get_param( 'page' ) );
            $search  = sanitize_text_field( (string) $request->get_param( 'search' ) );

            if ( $search !== '' ) {
                return $provider->search( $user_id, $search, $page );
            }

            return $provider->get_trending( $user_id, $page );
        }

        public function send_gif( WP_REST_Request $request )
        {
            $provider = Better_Messages_Gif_Provider_Factory::get_active();
            if ( ! $provider ) {
                return new WP_Error( 'gifs_disabled', __( 'GIFs are not enabled.', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $thread_id = intval( $request->get_param( 'id' ) );
            $gif_id    = sanitize_text_field( (string) $request->get_param( 'gif_id' ) );
            $user_id   = Better_Messages()->functions->get_current_user_id();

            $gif = $provider->get_by_id( $gif_id, $user_id );
            if ( ! $gif || empty( $gif['mp4'] ) ) {
                return false;
            }

            $poster = isset( $gif['poster'] ) ? esc_url( $gif['poster'] ) : '';
            $mp4    = esc_url( $gif['mp4'] );

            $message  = '<span class="bpbm-gif">';
            $message .= '<video preload="auto" muted playsinline="playsinline" loop="loop" poster="' . $poster . '">';
            $message .= '<source src="' . $mp4 . '" type="video/mp4">';
            $message .= '</video>';
            $message .= '</span>';

            $args = array(
                'content'    => $message,
                'thread_id'  => $thread_id,
                'error_type' => 'wp_error',
                'return'     => 'message_id',
            );

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
                } else {
                    $provider->register_usage( $user_id, $gif_id );
                }
            }

            if ( ! empty( $errors ) ) {
                do_action( 'better_messages_on_message_not_sent', $thread_id, '', $errors );

                $redirect = 'redirect';
                if ( count( $errors ) === 1 && isset( $errors['empty'] ) ) {
                    $redirect = false;
                }

                return array(
                    'result'   => false,
                    'errors'   => $errors,
                    'redirect' => $redirect,
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

        public function on_settings_updated( $settings )
        {
            $old = Better_Messages()->settings;

            $gif_keys = array(
                'gifsProvider',
                'giphyApiKey', 'giphyContentRating', 'giphyLanguage',
                'klipyApiKey', 'klipyLocale',
            );

            $changed = false;
            foreach ( $gif_keys as $key ) {
                $new_val = isset( $settings[ $key ] ) ? $settings[ $key ] : null;
                $old_val = isset( $old[ $key ] ) ? $old[ $key ] : null;
                if ( $new_val !== null && $new_val !== $old_val ) {
                    $changed = true;
                    break;
                }
            }

            if ( $changed ) {
                self::flush_cache();
            }

            $provider_id = isset( $settings['gifsProvider'] )
                ? $settings['gifsProvider']
                : Better_Messages_Gif_Provider_Factory::get_active_provider_id();

            if ( $provider_id === 'disabled' ) {
                return;
            }

            $provider = Better_Messages_Gif_Provider_Factory::create( $provider_id );
            if ( $provider && ! empty( $provider->get_api_key() ) ) {
                $provider->check_api_key();
            }
        }

        public static function flush_cache()
        {
            global $wpdb;
            $like = $wpdb->esc_like( '_transient_bm_gifs_' ) . '%';
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $wpdb->esc_like( '_transient_timeout_bm_gifs_' ) . '%'
            ) );
        }

        public function format_message( $message, $message_id, $context, $user_id )
        {
            if ( strpos( $message, '<span class="bpbm-gif">', 0 ) === 0 ) {
                if ( $context !== 'stack' ) {
                    return '%bpbmgif%';
                }
            }
            return $message;
        }

        public function after_format_message( $message, $message_id, $context, $user_id )
        {
            $is_gif = strpos( $message, '<span class="bpbm-gif">', 0 ) === 0 || $message === '%bpbmgif%';

            if ( ! $is_gif ) {
                return $message;
            }

            if ( $context === 'stack' ) {
                return $message;
            }

            if ( $context === 'mobile_app' ) {
                return __( 'GIF', 'bp-better-messages' );
            }

            return '<i class="bpbm-gifs-icon" title="' . __( 'GIF', 'bp-better-messages' ) . '"></i>';
        }
    }
}

if ( ! function_exists( 'Better_Messages_Gifs' ) ) {
    function Better_Messages_Gifs()
    {
        return Better_Messages_Gifs::instance();
    }
}

Better_Messages_Gifs();
