<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Progressify' ) ) {
    class Better_Messages_Progressify
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Progressify();
            }

            return $instance;
        }

        public function __construct()
        {
            add_filter( 'better_messages_3rd_party_push_active', '__return_true' );
            add_filter( 'better_messages_push_active', '__return_false' );
            add_filter( 'better_messages_push_message_in_settings', array( $this, 'push_message_in_settings' ) );

            add_filter( 'better_messages_bulk_pushs', array( $this, 'send_bulk_pushs' ), 10, 4 );
            add_filter( 'better_messages_get_user_push_subscriptions', array( $this, 'override_user_push_subscriptions' ), 10, 2 );
            add_filter( 'better_messages_vapid_keys', array( $this, 'override_vapid_keys' ), 10, 1 );
        }

        public function override_vapid_keys( $keys )
        {
            $vapid = get_option('daftplug_progressify_vapid_keys');

            if( empty( $vapid ) || !isset( $vapid['publicKey'] ) || !isset( $vapid['privateKey'] ) ) {
                return $keys;
            }

            return $vapid;
        }

        public function override_user_push_subscriptions( $subs, $user_id )
        {
            global $wpdb;

            $table = $wpdb->prefix . 'daftplug_progressify_push_notifications_subscribers';

            $subscriptions = $wpdb->get_results( $wpdb->prepare( "SELECT `endpoint`, `auth_key`,`p256dh_key` FROM `{$table}` WHERE `wp_user_id` = %d", $user_id ), ARRAY_A);

            $result = [];

            foreach( $subscriptions as $subscription ) {
                $result[ $subscription['endpoint'] ] = [
                    'auth'   => $subscription['auth_key'],
                    'p256dh' => $subscription['p256dh_key']
                ];
            }

            return $result;
        }

        public function send_bulk_pushs( $pushs, $all_recipients, $notification, $message )
        {
            global $wpdb;
            $table = $wpdb->prefix . 'daftplug_progressify_push_notifications_subscribers';
            $user_ids = array_map('intval', $all_recipients);

            $vapid = get_option('daftplug_progressify_vapid_keys');

            if( empty( $vapid ) || !isset( $vapid['publicKey'] ) || !isset( $vapid['privateKey'] ) ) {
                return $pushs;
            }

            $subscribers = $wpdb->get_results("SELECT `wp_user_id` as `user_id`, `endpoint`, `auth_key`,`p256dh_key` FROM `{$table}` WHERE `wp_user_id` IN (" . implode(',', $user_ids) . ")", ARRAY_A);

            if( count( $subscribers ) === 0 ){
                return $pushs;
            }

            $prepare_bulk_data = [];

            $subs = [];

            foreach( $subscribers as $subscriber ) {
                $user_id    = $subscriber['user_id'];
                $endpoint   = $subscriber['endpoint'];
                $auth_key   = $subscriber['auth_key'];
                $p256dh_key = $subscriber['p256dh_key'];

                if( ! isset( $subs[$user_id] ) ) {
                    $subs[$user_id] = [];
                }

                $subs[$user_id][] = [
                    'endpoint' => $endpoint,
                    'keys'     => [
                        'auth'   => $auth_key,
                        'p256dh' => $p256dh_key,
                    ],
                ];
            }

            foreach( $subs as $user_id => $subscriptions ) {
                $prepare_bulk_data[] = [
                    'user_id'       => $user_id,
                    'subs'          => $subscriptions,
                    'notification'  => $notification
                ];
            }

            if( empty( $prepare_bulk_data ) ) return $pushs;

            $email = get_option('admin_email');

            return [
                'email'         => $email,
                'pushs'         => $prepare_bulk_data,
                'vapidKeys'     => $vapid
            ];
        }

        public function push_message_in_settings( $message ){
            $message = '<p style="color: #0c5460;background-color: #d1ecf1;border: 1px solid #d1ecf1;padding: 15px;line-height: 24px;max-width: 550px;">';
            $message .= sprintf(_x('The Progressify plugin integration is active and will be used, this option do not need to be enabled.', 'Settings page', 'bp-better-messages'), 'https://www.better-messages.com/docs/integrations/progressify/');
            $message .= '</p>';

            return $message;
        }
    }
}
