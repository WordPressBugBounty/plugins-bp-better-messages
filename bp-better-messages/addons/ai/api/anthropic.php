<?php

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Anthropic_API' ) ) {
    class Better_Messages_Anthropic_API extends Better_Messages_AI_Provider
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Anthropic_API();
            }

            return $instance;
        }

        public function get_provider_id()
        {
            return 'anthropic';
        }

        public function get_provider_name()
        {
            return 'Anthropic';
        }

        protected function build_attachment_blocks( $msg_id, $bot_settings )
        {
            return $this->attachment_parts( $msg_id, $bot_settings );
        }

        protected function messagesProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            return $this->respond( $bot_id, $bot_user, $message, $ai_message_id, $stream );
        }

        protected function build_request( $bot_id, $bot_user, $message, $ai_message_id, $stream )
        {
            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );
            $conversation = $this->conversation( $bot_id, $bot_user, $message, $bot_settings );
            $messages     = $this->request_messages( $conversation, $message, $bot_settings );

            if ( empty( $messages ) ) {
                return new \WP_Error( 'no_messages', 'No messages to process' );
            }

            $request = array_merge( array(
                'model'    => $bot_settings['model'],
                'system'   => $conversation['system'],
                'messages' => $messages,
                'stream'   => (bool) $stream,
            ), $this->sampling_request( $bot_settings ) );

            if ( ! empty( $bot_settings['extendedThinking'] ) && $bot_settings['extendedThinking'] === '1' ) {
                $request['thinking'] = array(
                    'on'     => true,
                    'budget' => ! empty( $bot_settings['thinkingBudget'] ) && intval( $bot_settings['thinkingBudget'] ) > 0 ? intval( $bot_settings['thinkingBudget'] ) : 0,
                );
            }

            if ( ! empty( $bot_settings['webSearch'] ) && $bot_settings['webSearch'] === '1' ) {
                $request['web_search'] = array( 'max' => 0 );
            }

            if ( $ai_message_id > 0 ) {
                Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'in_progress' );
            }

            return $request;
        }
    }
}
