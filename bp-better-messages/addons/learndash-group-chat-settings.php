<?php
defined( 'ABSPATH' ) || exit;

if ( class_exists( 'LearnDash_Settings_Metabox' ) && ! class_exists( 'Better_Messages_LearnDash_Group_Chat_Settings' ) ) {

    class Better_Messages_LearnDash_Group_Chat_Settings extends LearnDash_Settings_Metabox
    {

        public function __construct()
        {
            $this->settings_screen_id     = 'groups';
            $this->settings_metabox_key   = 'better-messages-learndash-group-chat';
            $this->settings_section_label = esc_html_x( 'Better Messages', 'LearnDash Integration (Group Settings)', 'bp-better-messages' );

            $this->settings_section_description = sprintf(
                esc_html_x( 'Controls the chat of this %s in Better Messages', 'LearnDash Integration (Group Settings)', 'bp-better-messages' ),
                learndash_get_custom_label_lower( 'group' )
            );

            $this->settings_fields_map = array(
                'better_messages_group_chat' => 'better_messages_group_chat',
            );

            parent::__construct();
        }

        public function load_settings_values()
        {
            if ( ! $this->_post instanceof WP_Post || $this->_post->post_type !== $this->settings_screen_id ) {
                return false;
            }

            $this->setting_option_values = array(
                'better_messages_group_chat' => Better_Messages_LearnDash::instance()->is_group_chat_enabled( $this->_post->ID ) ? 'on' : '',
            );

            $this->settings_values_loaded = true;

            return true;
        }

        public function load_settings_fields()
        {
            $value = isset( $this->setting_option_values['better_messages_group_chat'] ) ? $this->setting_option_values['better_messages_group_chat'] : 'on';

            $this->setting_option_fields = array(
                'better_messages_group_chat' => array(
                    'name'      => 'better_messages_group_chat',
                    'type'      => 'checkbox-switch',
                    'label'     => sprintf(
                        esc_html_x( '%s Chat', 'LearnDash Integration (Group Settings)', 'bp-better-messages' ),
                        learndash_get_custom_label( 'group' )
                    ),
                    'help_text' => sprintf(
                        esc_html_x( 'Gives the members and leaders of this %s a group chat in Better Messages. Turned off, the chat leaves their messenger and its messages are kept.', 'LearnDash Integration (Group Settings)', 'bp-better-messages' ),
                        learndash_get_custom_label_lower( 'group' )
                    ),
                    'default'   => 'on',
                    'value'     => $value,
                    'options'   => array(
                        ''   => '',
                        'on' => '',
                    ),
                ),
            );

            parent::load_settings_fields();
        }

        public function save_post_meta_box( $post_id = 0, $saved_post = null, $update = null, $settings_field_updates = null )
        {
            if ( ! $post_id || ! $saved_post ) return;

            if ( is_null( $settings_field_updates ) ) {
                $settings_field_updates = $this->get_post_settings_field_updates( $post_id, $saved_post, $update );
            }

            if ( ! is_array( $settings_field_updates ) || ! isset( $settings_field_updates['better_messages_group_chat'] ) ) return;

            Better_Messages_LearnDash::instance()->set_group_chat_enabled( $post_id, $settings_field_updates['better_messages_group_chat'] === 'on' );
        }
    }
}
