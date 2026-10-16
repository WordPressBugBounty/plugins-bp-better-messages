<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'BP_Core_Notification_Abstract' ) ) {
    return;
}

class BetterMessagesNewMessageNotification extends BP_Core_Notification_Abstract {

    private static $instance = null;

    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function __construct() {
        $this->start();

        add_filter( 'bb_is_better_messages_new_message_email_preference_type_render', array( $this, 'force_enable_email' ), 10, 3 );
        add_filter( 'better_messages_is_user_emails_enabled', array( $this, 'overwrite_user_email_enabled' ), 10, 2 );

        add_action( 'better_messages_user_emails_enabled_update', array( $this, 'user_emails_enabled_update' ), 10, 2 );
    }

    public function user_emails_enabled_update( $user_id, $value ){
        Better_Messages()->functions->update_user_meta( $user_id, 'better_messages_new_message', $value );
    }

    public function overwrite_user_email_enabled($enabled, $user_id){
        $enabled = Better_Messages()->functions->get_user_meta( $user_id, 'better_messages_new_message', true ) != 'no';
        return $enabled;
    }

    public function force_enable_email( $is_render, $key, $_key ){
        return true;
    }

    public function load() {

        $this->register_notification_group(
            'better_messages',
            esc_html__( 'Private Messages', 'bp-better-messages' ),
            esc_html__( 'Better Messages', 'bp-better-messages' )
        );

        $this->register_custom_notification();


    }

    public function register_custom_notification() {
        $this->register_notification_type(
            'better_messages_new_message',
            esc_html__( 'You receive a new private message', 'bp-better-messages' ),
            esc_html__( 'A member receives a new private message', 'bp-better-messages' ),
            'better_messages'
        );

        $this->register_notification(
            'better_messages',
            'better_messages_new_message',
            'better_messages_new_message'
        );
    }

    public function format_notification( $content, $item_id, $secondary_item_id, $action_item_count, $component_action_name, $component_name, $notification_id, $screen ) {
        return $content;
    }
}
