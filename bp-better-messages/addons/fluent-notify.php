<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Fluent_Notify' ) ) {

    class Better_Messages_Fluent_Notify
    {
        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Fluent_Notify();
            }

            return $instance;
        }

        public function __construct()
        {
            if ( ! self::is_configured() ) return;

            add_filter( 'better_messages_3rd_party_push_active', '__return_true' );
            add_filter( 'better_messages_push_active', '__return_false' );
            add_filter( 'better_messages_push_message_in_settings', array( $this, 'push_message_in_settings' ) );

            add_filter( 'better_messages_bulk_pushs', array( $this, 'send_bulk_pushs' ), 10, 4 );
            add_filter( 'better_messages_push_request_data', array( $this, 'send_push' ), 10, 7 );
        }

        public static function is_configured()
        {
            if ( ! class_exists( 'FluentNotify\\App\\Services\\Helper' ) ) return false;

            return \FluentNotify\App\Services\Helper::isEnabled()
                && \FluentNotify\App\Services\Helper::isConfigComplete();
        }

        public function send_bulk_pushs( $pushs, $all_recipients, $notification, $message )
        {
            $request = $this->build_request( $all_recipients, $notification );

            if ( $request === false ) return $pushs;

            return $request;
        }

        public function send_push( $data, $user_id, $notification, $type, $thread_id, $message_id, $sender_id )
        {
            $request = $this->build_request( array( $user_id ), $notification );

            if ( $request === false ) return $data;

            $data['fcm_pushs'] = $request;

            return $data;
        }

        private function build_request( $user_ids, $notification )
        {
            $auth = $this->get_auth();

            if ( $auth === false ) return false;

            $tokens = $this->get_tokens( $user_ids );

            if ( empty( $tokens ) ) return false;

            $fields = $this->build_fields( $notification );

            if ( $fields === false ) return false;

            return array(
                'fcm'      => $auth,
                'tokens'   => $tokens,
                'user_ids' => array_map( 'strval', array_keys( $tokens ) ),
                'fields'   => $fields
            );
        }

        private function get_auth()
        {
            static $auth = null;

            if ( $auth !== null ) return $auth;

            $auth = false;

            $config = \FluentNotify\App\Services\Helper::getConfig();

            if ( empty( $config['project_id'] ) ) return $auth;

            $token = ( new \FluentNotify\App\Services\FcmApiService() )->getAccessToken();

            if ( is_wp_error( $token ) || empty( $token ) ) return $auth;

            $auth = array(
                'project_id' => $config['project_id'],
                'token'      => $token
            );

            return $auth;
        }

        private function get_tokens( $user_ids )
        {
            global $wpdb;

            $recipients = array();

            foreach ( (array) $user_ids as $user_id ) {
                $user_id = (int) $user_id;

                if ( $user_id <= 0 ) continue;

                if ( ! Better_Messages()->notifications->user_web_push_enabled( $user_id ) ) continue;

                $recipients[] = $user_id;
            }

            $recipients = array_values( array_unique( $recipients ) );

            if ( empty( $recipients ) ) return array();

            $table = $wpdb->prefix . 'fn_subscriptions';
            $in    = implode( ',', $recipients );

            $rows = $wpdb->get_results( "SELECT `user_id`, `fcm_token` FROM `{$table}` WHERE `status` = 'active' AND `user_id` IN ({$in})", ARRAY_A );

            $tokens = array();

            foreach ( (array) $rows as $row ) {
                if ( empty( $row['fcm_token'] ) ) continue;

                $user_id = (string) (int) $row['user_id'];

                if ( ! isset( $tokens[ $user_id ] ) ) $tokens[ $user_id ] = array();

                $tokens[ $user_id ][] = $row['fcm_token'];
            }

            return $tokens;
        }

        private function build_fields( $notification )
        {
            if ( ! is_array( $notification ) || empty( $notification['title'] ) ) return false;

            $title = $this->plain_text( $notification['title'] );

            if ( $title === '' ) return false;

            $fields = array(
                'title' => $title,
                'body'  => $this->plain_text( ! empty( $notification['body'] ) ? $notification['body'] : $notification['title'] )
            );

            if ( ! empty( $notification['icon'] ) ) {
                $fields['icon'] = htmlspecialchars_decode( $notification['icon'] );
            }

            if ( ! empty( $notification['tag'] ) ) {
                $fields['tag'] = $notification['tag'];
            }

            if ( ! empty( $notification['data']['url'] ) ) {
                $fields['action_url'] = $notification['data']['url'];
            }

            return $fields;
        }

        private function plain_text( $value )
        {
            return trim( wp_strip_all_tags( stripslashes_deep( wp_specialchars_decode( (string) $value, ENT_QUOTES ) ) ) );
        }

        public function push_message_in_settings( $message ){
            $message = '<p style="color: #0c5460;background-color: #d1ecf1;border: 1px solid #d1ecf1;padding: 15px;line-height: 24px;max-width: 550px;">';
            $message .= _x( 'The FluentNotify integration is active and will be used, this option do not need to be enabled.', 'Settings page', 'bp-better-messages' );
            $message .= '</p>';

            return $message;
        }

    }
}
