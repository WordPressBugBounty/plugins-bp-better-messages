<?php

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Gemini_API' ) ) {
    class Better_Messages_Gemini_API extends Better_Messages_AI_Provider
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Gemini_API();
            }

            return $instance;
        }

        public function get_provider_id()
        {
            return 'gemini';
        }

        public function get_provider_name()
        {
            return 'Google Gemini';
        }

        private function model_supports_image_generation( $model_id )
        {
            $sdk = $this->sdk();

            return $sdk && method_exists( $sdk, 'makes_images' ) && $sdk->makes_images( $model_id );
        }

        protected function build_user_parts( $msg_id, $message_text, $bot_settings )
        {
            $parts = $this->attachment_parts( $msg_id, $bot_settings );

            if ( ! empty( $message_text ) ) {
                $parts[] = array( 'type' => 'text', 'text' => $message_text );
            }

            return $parts;
        }

        protected function streamContentProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            return $this->respond( $bot_id, $bot_user, $message, $ai_message_id, $stream );
        }

        protected function build_request( $bot_id, $bot_user, $message, $ai_message_id, $stream )
        {
            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );
            $model_id     = $bot_settings['model'];
            $conversation = $this->conversation( $bot_id, $bot_user, $message, $bot_settings );

            $messages = $this->request_messages( $conversation, $message, $bot_settings, function ( $row, $role, $entry ) {
                if ( $role === 'assistant' ) {
                    $thought_parts = Better_Messages()->functions->get_message_meta( $row->id, 'gemini_thought_parts', true );

                    if ( ! empty( $thought_parts ) && is_array( $thought_parts ) ) {
                        $entry['thoughts'] = array_values( $thought_parts );
                    }
                }

                return $entry;
            } );

            if ( empty( $messages ) ) {
                return new \WP_Error( 'no_messages', 'No messages to process' );
            }

            $limits = '';
            $can_generate_images = $bot_settings['imagesGeneration'] === '1' && $this->model_supports_image_generation( $model_id );

            if ( ! $can_generate_images ) {
                $limits .= ' You cannot generate, create, or produce images. If asked to generate an image, explain that you are a text-only assistant and cannot create images. Never output fake tool calls, JSON actions, or pretend to call image generation tools like DALL-E.';
            }

            if ( ! empty( $bot_settings['maxWebSearchCalls'] ) && intval( $bot_settings['maxWebSearchCalls'] ) > 0 ) {
                $limits .= ' You must perform no more than ' . intval( $bot_settings['maxWebSearchCalls'] ) . ' web search(es) per response.';
            }
            if ( $can_generate_images && ! empty( $bot_settings['maxImagesPerResponse'] ) && intval( $bot_settings['maxImagesPerResponse'] ) > 0 ) {
                $limits .= ' You must generate no more than ' . intval( $bot_settings['maxImagesPerResponse'] ) . ' image(s) per response.';
            }

            $request = array_merge( array(
                'model'    => $model_id,
                'system'   => $conversation['system'] . $limits,
                'messages' => $messages,
                'stream'   => (bool) $stream,
            ), $this->sampling_request( $bot_settings ) );

            if ( $bot_settings['extendedThinking'] === '1' ) {
                $request['thinking'] = array(
                    'on'     => true,
                    'budget' => ! empty( $bot_settings['thinkingBudget'] ) && intval( $bot_settings['thinkingBudget'] ) > 0 ? intval( $bot_settings['thinkingBudget'] ) : 0,
                );
            }

            if ( $can_generate_images ) {
                $max     = ! empty( $bot_settings['maxImagesPerResponse'] ) ? intval( $bot_settings['maxImagesPerResponse'] ) : 0;
                $records = array();

                $request['image_generation'] = array( 'max' => $max );
                $request['on_image']         = function ( $image ) use ( $ai_message_id, $bot_user, &$records ) {
                    return $this->save_generated_image( $image, $ai_message_id, $bot_user, array( 'bm_ai_generated_image' => 1 ), $records );
                };
            }

            if ( $bot_settings['webSearch'] === '1' ) {
                $request['web_search'] = array( 'max' => 0 );
            }

            if ( $ai_message_id > 0 ) {
                Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_status', 'in_progress' );
            }

            return $request;
        }

        public function on_response_completed( $ai_message_id, $message_id, $meta )
        {
            if ( ! empty( $meta['gemini_thought_parts'] ) ) {
                Better_Messages()->functions->update_message_meta( $ai_message_id, 'gemini_thought_parts', $meta['gemini_thought_parts'] );
            }
        }
    }
}
