<?php
if ( !class_exists( 'Better_Messages_Rest_Push_Notifications' ) ):

    class Better_Messages_Rest_Push_Notifications
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Rest_Push_Notifications();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action('rest_api_init', array($this, 'rest_api_init'));
        }

        public function rest_api_init()
        {
            register_rest_route('better-messages/v1', '/pushNotifications/save', array(
                'methods' => 'POST',
                'callback' => array($this, 'save_user_push_subscription'),
                'permission_callback' => array(Better_Messages_Rest_Api(), 'is_user_authorized')
            ));

            register_rest_route('better-messages/v1', '/pushNotifications/delete', array(
                'methods' => 'POST',
                'callback' => array($this, 'delete_user_push_subscription'),
                'permission_callback' => array(Better_Messages_Rest_Api(), 'is_user_authorized')
            ));
        }

        public function save_user_push_subscription( WP_REST_Request $request ){
            $user_id      = Better_Messages()->functions->get_current_user_id();
            $subscription = json_decode( wp_unslash( (string) $request->get_param('sub') ) );

            if( ! is_object( Better_Messages()->websocket ) || ! is_object( $subscription ) || ! isset( $subscription->endpoint, $subscription->keys ) ){
                return false;
            }

            return Better_Messages()->websocket->save_push_subscription( $user_id, $subscription->endpoint, (array) $subscription->keys );
        }

        public function delete_user_push_subscription( WP_REST_Request $request ){
            $user_id      = Better_Messages()->functions->get_current_user_id();
            $subscription = json_decode( wp_unslash( (string) $request->get_param('sub') ) );

            if( is_object( Better_Messages()->websocket ) && is_object( $subscription ) && isset( $subscription->endpoint ) ){
                Better_Messages()->websocket->delete_push_subscription( $user_id, $subscription->endpoint );
            }

            return true;
        }
    }

    function Better_Messages_Rest_Push_Notifications(){
        return Better_Messages_Rest_Push_Notifications::instance();
    }
endif;
