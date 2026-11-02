<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Loader' ) ) {
    final class WordPlus_SDK_AI_Loader
    {
        const PHP = '8.1';

        public static function supported()
        {
            return version_compare( PHP_VERSION, self::PHP, '>=' );
        }

        public static function load( $sdk )
        {
            if ( ! self::supported() ) {
                return null;
            }

            require_once __DIR__ . '/ai/class-ai.php';

            return new WordPlus_SDK_AI( $sdk );
        }

        public static function status( $carried = true )
        {
            $reason = ! $carried ? 'missing' : ( self::supported() ? '' : 'php' );

            return array(
                'available' => '' === $reason,
                'reason'    => $reason,
                'php'       => PHP_VERSION,
                'needs'     => self::PHP,
                'message'   => self::message( $reason ),
            );
        }

        public static function message( $reason )
        {
            if ( 'php' === $reason ) {
                /* translators: 1: the PHP version the AI features need, such as 8.1, 2: the PHP version this site runs */
                return sprintf( _x( 'AI features need PHP %1$s or newer. This site runs PHP %2$s. Ask your host to update PHP, and they turn on by themselves.', 'AI', 'wordplus-sdk' ), self::PHP, PHP_VERSION );
            }

            if ( 'missing' === $reason ) {
                return _x( 'AI features are not part of this build of the plugin.', 'AI', 'wordplus-sdk' );
            }

            return '';
        }

        public static function routes( $sdk )
        {
            if ( ! self::supported() ) {
                return;
            }

            register_rest_route( 'wordplus/v1', '/ai/prices', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => function () use ( $sdk ) {
                        return $sdk->ai() ? rest_ensure_response( $sdk->ai()->prices()->state() ) : new WP_Error( 'wordplus_ai_unavailable', self::message( 'php' ), array( 'status' => 404 ) );
                    },
                    'permission_callback' => array( __CLASS__, 'can_manage' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => function () use ( $sdk ) {
                        return $sdk->ai() ? rest_ensure_response( $sdk->ai()->prices()->check() ) : new WP_Error( 'wordplus_ai_unavailable', self::message( 'php' ), array( 'status' => 404 ) );
                    },
                    'permission_callback' => array( __CLASS__, 'can_manage' ),
                ),
            ) );
        }

        public static function can_manage()
        {
            return current_user_can( 'manage_options' );
        }
    }
}
