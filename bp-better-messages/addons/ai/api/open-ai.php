<?php

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_OpenAI_API' ) ) {
    class Better_Messages_OpenAI_API extends Better_Messages_AI_Provider
    {

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_OpenAI_API();
            }

            return $instance;
        }

        public function update_api_key()
        {
        }

        public function get_provider_id()
        {
            return 'openai';
        }

        public function get_provider_name()
        {
            return 'OpenAI';
        }

        protected function models_options()
        {
            return array( 'audio' => class_exists( 'BP_Better_Messages_Voice_Messages' ) );
        }

        public function getResponseGenerator( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            $settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            if ( str_contains( $settings['model'], '-audio-' ) && class_exists( 'BP_Better_Messages_Voice_Messages' ) ) {
                $this->audioProvider( $bot_id, $bot_user, $message );
                return null;
            }

            return $this->responseProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream );
        }

        public function on_response_completed( $ai_message_id, $message_id, $meta )
        {
            if ( isset( $meta['message_id'] ) ) {
                Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_message_id', $meta['message_id'] );
            }
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_meta', json_encode( $meta ) );
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', 'completed' );

            if ( ! empty( $meta['response_id'] ) ) {
                $response_input = $this->get_response_input( $meta['response_id'] );

                if ( ! is_wp_error( $response_input ) ) {
                    Better_Messages()->functions->update_message_meta( $message_id, 'openai_message_id', $response_input );
                }
            }
        }

        public function on_message_deleted( $message_id, $thread_id, $message )
        {
            $meta = Better_Messages()->functions->get_message_meta( $message_id, 'openai_meta' );

            if ( ! empty( $meta ) ) {
                $meta = json_decode( $meta, true );

                if ( isset( $meta['conversation_id'] ) && isset( $meta['message_id'] ) ) {
                    $this->delete_conversation_message( $meta['conversation_id'], $meta['message_id'] );
                }
            }
        }

        public function moderate( $text = '', $image_data_uris = [] )
        {
            $sdk = $this->sdk();

            return $sdk ? $sdk->moderate( (string) $text, $image_data_uris ) : self::unavailable();
        }

        public function transcribe_audio( $attachment_id )
        {
            $sdk = $this->sdk();

            if ( ! $sdk ) {
                return self::unavailable();
            }

            $copy = Better_Messages()->ai->get_audio_copy( $attachment_id );

            if ( is_wp_error( $copy ) ) {
                return $copy;
            }

            try {
                return $sdk->transcribe( $copy['path'], array(
                    'model'  => Better_Messages()->settings['voiceTranscriptionModel'] ?: 'gpt-4o-mini-transcribe',
                    'prompt' => trim( Better_Messages()->settings['voiceTranscriptionPrompt'] ?? '' ),
                ) );
            } finally {
                call_user_func( $copy['cleanup'] );
            }
        }

        public function get_transcription_models()
        {
            $sdk = $this->sdk();

            return $sdk ? $sdk->transcription_models() : self::unavailable();
        }

        public function audioProvider( $bot_id, $bot_user, $message ) {
            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            $bot_user_id = absint( $bot_user->id ) * -1;

            $ai_response_id = Better_Messages()->functions->get_message_meta( $message->id, 'ai_response_id' );

            if( ! $ai_response_id ){
                return false;
            }

            $ai_message = Better_Messages()->functions->get_message( $ai_response_id );

            if( ! $ai_message ){
                return false;
            }

            $voice = $bot_settings['voice'];

            $context_limit = 0;
            if ( ! empty( $bot_settings['contextMessages'] ) && ! $this->is_group_thread( $message->thread_id ) ) {
                $context_limit = intval( $bot_settings['contextMessages'] );
            }

            $messages = $this->get_thread_messages( $message->thread_id, $message->created_at, $context_limit );

            $request_messages = [];

            if( ! empty( $bot_settings['instruction'] ) ) {
                $request_messages[] = [
                    'role' => 'system',
                    'content' => $this->get_bot_instruction( $bot_settings['instruction'], $bot_id, $message->sender_id, $message->thread_id, $message->id )
                ];
            }

            foreach ( $messages as $_message ){
                $is_error = Better_Messages()->functions->get_message_meta( $_message->id, 'ai_response_error' );
                if( $is_error ) continue;

                $content = [];

                $content[] = [
                    'type' => 'text',
                    'text' => $this->append_location_context( $_message->id, $this->clean_stored_message( $_message->message ) )
                ];

                $role = (int) $_message->sender_id === (int) $bot_user_id ? 'assistant' : 'user';

                if( $role === 'assistant' ) {
                    $audio_id = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_id' );
                    $message_content = $this->clean_stored_message( $_message->message );

                    if( $audio_id ){
                        $audio_expires_at = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_expires_at' );

                        if( ( time() - $audio_expires_at ) <= -60 ){
                            $voice = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_voice' );

                            $request_messages[] = [
                                'role' => $role,
                                'audio' => [ 'id' => $audio_id ]
                            ];
                        } else {
                            $request_messages[] = [
                                'role' => 'system',
                                'content' => $this->get_bot_instruction( $bot_settings['instruction'], $bot_id, $message->sender_id, $message->thread_id, $message->id )
                            ];
                        }
                    } else if( $transcript = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_transcript') ){
                        $content[] = [
                            'type' => 'text',
                            'text' => $transcript
                        ];
                    } else if( ! empty( $message_content ) ){
                        $content[] = [
                            'type' => 'text',
                            'text' => $message_content
                        ];
                    }
                } else {
                    if( str_replace('<!-- BM-AI -->', '', $_message->message ) === '<!-- BPBM-VOICE-MESSAGE -->' && $attachment_id = Better_Messages()->functions->get_message_meta( $_message->id, 'bpbm_voice_messages', true ) ){
                        $transcription = $this->get_voice_transcript( $_message );

                        if( ! is_string( $transcription ) || trim( $transcription ) === '' ){
                            $transcription = $this->transcribe_audio( $attachment_id );
                        }

                        if( is_wp_error( $transcription ) || empty( $transcription ) ){
                            continue;
                        }

                        $content[] = [
                            'type' => 'text',
                            'text' => $transcription
                        ];

                    } else {
                        $content[] = [
                            'type' => 'text',
                            'text' => $this->clean_stored_message( $_message->message )
                        ];
                    }
                }

                if( count( $content ) > 0 ) {
                    $request_messages[] = [
                        'role' => $role,
                        'content' => $content,
                    ];
                }

            }

            Better_Messages()->functions->update_message_meta( $ai_response_id, 'openai_response_status', 'in_progress' );

            $this->set_bot( $bot_id );
            $sdk    = $this->sdk();
            $answer = $sdk ? $sdk->audio_reply( $bot_settings['model'], $request_messages, $voice, (string) $message->sender_id ) : self::unavailable();

            if ( is_wp_error( $answer ) && $answer->get_error_code() === 'wordplus_ai_empty' ) {
                $args = [
                    'sender_id'  => $ai_message->sender_id,
                    'thread_id'  => $ai_message->thread_id,
                    'message_id' => $ai_message->id,
                    'content'    => '<!-- BM-AI -->' . $this->get_user_friendly_error( 'No response received from API' )
                ];

                Better_Messages()->functions->update_message( $args );
                Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_status', 'failed' );
                Better_Messages()->functions->delete_message_meta( $message->id, 'ai_waiting_for_response' );
                Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );
                Better_Messages()->functions->add_message_meta( $ai_response_id, 'ai_response_error', 'No response received from API' );
                do_action( 'better_messages_thread_self_update', $message->thread_id, $message->sender_id );
                do_action( 'better_messages_thread_updated', $message->thread_id, $message->sender_id );

                return false;
            }

            if ( is_wp_error( $answer ) ) {
                $error      = $answer->get_error_message();
                $user_error = $this->get_user_friendly_error( $error );

                $args =  [
                    'sender_id'    => $ai_message->sender_id,
                    'thread_id'    => $ai_message->thread_id,
                    'message_id'   => $ai_message->id,
                    'content'      => '<!-- BM-AI -->' . $user_error
                ];

                Better_Messages()->functions->update_message( $args );

                Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_status', 'failed' );
                Better_Messages()->functions->delete_message_meta( $message->id, 'ai_waiting_for_response' );
                Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );
                do_action( 'better_messages_thread_self_update', $message->thread_id, $message->sender_id );
                do_action( 'better_messages_thread_updated', $message->thread_id, $message->sender_id );
                Better_Messages()->functions->add_message_meta( $ai_response_id, 'ai_response_error', $error );
                Better_Messages()->functions->add_message_meta( $message->id, 'ai_response_error', $error );

                return false;
            }

            $audio_meta = $answer['meta'];

            if ( ! empty( $answer['audio'] ) ) {
                $audio = $answer['audio'];

                $mp3Data = base64_decode( $audio['data'] );
                $name = Better_Messages()->functions->random_string(30);
                $temp_dir = sys_get_temp_dir();
                $temp_path = trailingslashit($temp_dir) . $name;

                try {
                    file_put_contents($temp_path, $mp3Data);

                    $file = [
                        'name' => $name . '.mp3',
                        'type' => 'audio/mp3',
                        'tmp_name' => $temp_path,
                        'error' => 0,
                        'size' => filesize($temp_path)
                    ];

                    BP_Better_Messages_Voice_Messages()->save_voice_message_from_file($file, $ai_response_id);

                    Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_id', $audio['id']);
                    Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_transcript', $audio['transcript']);
                    Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_expires_at', $audio['expires_at']);
                    Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_voice', $voice);
                    Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_response_status', 'completed');
                    Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_provider', $this->get_provider_id());
                    Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_provider_meta', json_encode($audio_meta));
                    Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_response_finish', time());
                    Better_Messages()->functions->delete_message_meta($message->id, 'ai_waiting_for_response');
                    Better_Messages()->functions->delete_thread_meta($message->thread_id, 'ai_waiting_for_response');

                    Better_Messages()->ai->calculate_and_store_cost( $ai_response_id, $audio_meta, $bot_id, $message->sender_id, $message->thread_id );

                    do_action( 'better_messages_ai_response_completed', $ai_response_id, $message->id, [], $bot_id, $message->sender_id );
                    do_action('better_messages_thread_self_update', $message->thread_id, $message->sender_id);
                    do_action('better_messages_thread_updated', $message->thread_id, $message->sender_id);
                } finally {
                    if (file_exists($temp_path)) {
                        unlink($temp_path);
                    }
                }

                return true;
            }

            $args =  [
                'sender_id'    => $ai_message->sender_id,
                'thread_id'    => $message->thread_id,
                'message_id'   => $ai_response_id,
                'content'      => '<!-- BM-AI -->' . $this->convert_mention_placeholders( htmlentities( $answer['text'] ) )
            ];

            Better_Messages()->functions->update_message( $args );

            Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_status', 'completed' );
            Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_provider', $this->get_provider_id() );
            Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_provider_meta', json_encode( $audio_meta ) );
            Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_finish', time() );
            Better_Messages()->functions->delete_message_meta( $message->id, 'ai_waiting_for_response' );
            Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );

            Better_Messages()->ai->calculate_and_store_cost( $ai_response_id, $audio_meta, $bot_id, $message->sender_id, $message->thread_id );

            do_action( 'better_messages_ai_response_completed', $ai_response_id, $message->id, [], $bot_id, $message->sender_id );
            do_action( 'better_messages_thread_self_update', $message->thread_id, $message->sender_id );
            do_action( 'better_messages_thread_updated', $message->thread_id, $message->sender_id );

            return true;
        }

        public function get_response_input( $response_id )
        {
            $sdk = $this->sdk();

            return $sdk ? $sdk->response_input( $response_id ) : self::unavailable();
        }

        public function delete_conversation_message( $conversation_id, $message_id )
        {
            $sdk    = $this->sdk();
            $result = $sdk ? $sdk->delete_conversation_item( $conversation_id, $message_id ) : self::unavailable();

            return is_wp_error( $result ) ? new WP_Error( 'bm_failed_to_delete_open_ai_conversation_message', $result->get_error_message() ) : null;
        }

        public function get_open_ai_conversation( $thread_id )
        {
            $openai_conversation = Better_Messages()->functions->get_thread_meta( $thread_id, 'openai_conversation' );

            if ( ! empty( $openai_conversation ) ) {
                return $openai_conversation;
            }

            $sdk          = $this->sdk();
            $conversation = $sdk ? $sdk->conversation( array( 'bm_thread_id' => (string) $thread_id ) ) : self::unavailable();

            if ( is_wp_error( $conversation ) ) {
                return new WP_Error( 'bm_failed_to_create_open_ai_conversation_id', $conversation->get_error_message() );
            }

            if ( ! is_array( $conversation ) || empty( $conversation['id'] ) ) {
                return new WP_Error( 'bm_failed_to_create_open_ai_conversation_id', 'No conversation id returned' );
            }

            Better_Messages()->functions->update_thread_meta( $thread_id, 'openai_conversation', $conversation );

            return $conversation;
        }

        protected function get_responses_attachment_part( $attachment_id, $bot_settings, $links )
        {
            return $this->attachment_part( $attachment_id, $bot_settings, array( 'stored' => ! $links ) );
        }

        public function responseProvider( $bot_id, $bot_user, $message, $ai_message_id, $stream = true )
        {
            foreach ( $this->respond( $bot_id, $bot_user, $message, $ai_message_id, $stream ) as $part ) {
                if ( is_array( $part ) && $part[0] === 'error' && class_exists( 'WordPlus_SDK_AI_OpenAI' ) && WordPlus_SDK_AI_OpenAI::conversation_gone( $part[1] )
                    && ! empty( Better_Messages()->functions->get_thread_meta( $message->thread_id, 'openai_conversation' ) ) ) {
                    Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'openai_conversation' );
                }

                yield $part;
            }
        }

        protected function build_request( $bot_id, $bot_user, $message, $ai_message_id, $stream )
        {
            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );
            $thread_id    = $message->thread_id;
            $is_group     = $this->is_group_thread( $thread_id );

            if ( $is_group ) {
                $context_limit = ! empty( $bot_settings['groupContextMessages'] ) ? intval( $bot_settings['groupContextMessages'] ) : 20;
            } else {
                $context_limit = ! empty( $bot_settings['contextMessages'] ) ? intval( $bot_settings['contextMessages'] ) : 0;
            }

            $use_history_input    = $is_group || $context_limit > 0;
            $open_ai_conversation = null;
            $group_instruction    = '';

            if ( $use_history_input ) {
                $conversation = $this->conversation( $bot_id, $bot_user, $message, $bot_settings );

                if ( $is_group ) {
                    $group_instruction = $this->get_group_context_instruction( $conversation['sender_names'], $conversation['bot_user_id'], get_the_title( $bot_id ) );
                }
                if ( $conversation['summary'] ) {
                    $group_instruction .= "\n\nPrevious conversation summary:\n" . $conversation['summary'];
                }

                $input = $this->request_messages( $conversation, $message, $bot_settings, null, true );
            } else {
                $open_ai_conversation = $this->get_open_ai_conversation( $thread_id );

                if ( is_wp_error( $open_ai_conversation ) ) {
                    return $open_ai_conversation;
                }

                $message_content = $this->get_context_text( $message );
                $content         = [];
                $input_images    = [];
                $input_files     = [];

                if ( ! empty( $message_content ) ) {
                    $content[] = [ 'type' => 'text', 'text' => $message_content ];
                }

                if ( $bot_settings['images'] || $bot_settings['files'] ) {
                    $attachments = Better_Messages()->functions->get_message_meta( $message->id, 'attachments', true );

                    if ( ! empty( $attachments ) ) {
                        foreach ( $attachments as $id => $url ) {
                            $part = $this->get_responses_attachment_part( $id, $bot_settings, false );

                            if ( ! $part ) {
                                continue;
                            }

                            if ( $part['type'] === 'image' ) {
                                $input_images[] = $id;
                            } else {
                                $input_files[] = $id;
                            }

                            $content[] = $part;
                        }
                    }
                }

                if ( count( $input_files ) > 0 ) {
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'input_files', $input_files );
                }

                if ( count( $input_images ) > 0 ) {
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'input_images', $input_images );
                }

                $input = [ [ 'role' => 'user', 'content' => $content ] ];
            }

            $request = array_merge( array(
                'model'        => $bot_settings['model'],
                'service_tier' => $bot_settings['serviceTier'],
                'system'       => $this->get_bot_instruction( $bot_settings['instruction'], $bot_id, $message->sender_id, $message->thread_id, $message->id )
                    . $group_instruction
                    . '. This is very important you to use correct markdown format for providing response, especially for code blocks and snippets.'
                    . ( ! empty( $bot_settings['maxImagesPerResponse'] ) && intval( $bot_settings['maxImagesPerResponse'] ) > 0
                        ? ' You must generate no more than ' . intval( $bot_settings['maxImagesPerResponse'] ) . ' image(s) per response.'
                        : '' )
                    . ( ! empty( $bot_settings['maxWebSearchCalls'] ) && intval( $bot_settings['maxWebSearchCalls'] ) > 0
                        ? ' You must perform no more than ' . intval( $bot_settings['maxWebSearchCalls'] ) . ' web search(es) per response.'
                        : '' )
                    . ( ! empty( $bot_settings['maxFileSearchCalls'] ) && intval( $bot_settings['maxFileSearchCalls'] ) > 0
                        ? ' You must perform no more than ' . intval( $bot_settings['maxFileSearchCalls'] ) . ' file search(es) per response.'
                        : '' ),
                'messages'     => $input,
                'stream'       => (bool) $stream,
            ), $this->sampling_request( $bot_settings ) );

            if ( $open_ai_conversation ) {
                $request['conversation'] = $open_ai_conversation['id'];
            }

            if ( $bot_settings['imagesGeneration'] === '1' ) {
                $records = array();

                $request['image_generation'] = array(
                    'model'   => $bot_settings['imagesGenerationModel'],
                    'quality' => $bot_settings['imagesGenerationQuality'],
                    'size'    => $bot_settings['imagesGenerationSize'],
                    'max'     => intval( $bot_settings['maxImagesPerResponse'] ),
                );

                $request['on_image'] = function ( $image ) use ( $ai_message_id, $bot_user, &$records ) {
                    return $this->save_generated_image( $image, $ai_message_id, $bot_user, array(
                        'bm_openai_generated_image' => 1,
                        'bm_openai_file_id'         => isset( $image['id'] ) ? $image['id'] : '',
                        'bm_openai_quality'         => isset( $image['quality'] ) ? $image['quality'] : '',
                        'bm_openai_background'      => isset( $image['background'] ) ? $image['background'] : '',
                        'bm_openai_size'            => isset( $image['size'] ) ? $image['size'] : '',
                    ), $records );
                };
            }

            if ( $bot_settings['webSearch'] === '1' ) {
                $request['web_search'] = array(
                    'context_size' => $bot_settings['webSearchContextSize'],
                    'max'          => intval( $bot_settings['maxWebSearchCalls'] ),
                );
            }

            if ( $bot_settings['fileSearch'] === '1' && is_array( $bot_settings['fileSearchVectorIds'] ) && count( $bot_settings['fileSearchVectorIds'] ) > 0 ) {
                $request['file_search'] = array(
                    'vector_store_ids' => $bot_settings['fileSearchVectorIds'],
                    'max'              => intval( $bot_settings['maxFileSearchCalls'] ),
                );
            }

            if ( ! empty( $bot_settings['reasoningEffort'] ) && in_array( $bot_settings['reasoningEffort'], [ 'low', 'medium', 'high' ], true ) ) {
                $request['effort'] = $bot_settings['reasoningEffort'];
            }

            if ( $ai_message_id > 0 ) {
                $request['on_status'] = function ( $status ) use ( $ai_message_id ) {
                    if ( ! empty( $status['status'] ) ) {
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', $status['status'] );
                    }
                    if ( ! empty( $status['id'] ) ) {
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_id', $status['id'] );
                    }
                };
            }

            return $request;
        }

        public function ensureResponseCompletionJob()
        {
            global $wpdb;

            $table = bm_get_table('meta');

            $query = "SELECT `bm_message_id`
            FROM `{$table}`
            WHERE (`meta_key` = 'openai_response_status' AND `meta_value` IN ('queued', 'in_progress'))
               OR (`meta_key` = 'ai_response_status' AND `meta_value` = 'in_progress');";

            $uncompleted = array_map( 'intval', $wpdb->get_col($query) );

            if( count($uncompleted) > 0 ){
                foreach($uncompleted as $message_id){
                    $message = Better_Messages()->functions->get_message( $message_id );

                    if( ! $message ){
                        Better_Messages()->functions->delete_message_meta( $message_id, 'openai_response_status' );
                        Better_Messages()->functions->delete_message_meta( $message_id, 'ai_response_status' );
                        continue;
                    }

                    $error = Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_error' );

                    if( ! empty( $error ) ){
                        Better_Messages()->functions->update_message_meta( $message_id, 'openai_response_status', 'failed' );
                        Better_Messages()->functions->update_message_meta( $message_id, 'ai_response_status', 'failed' );
                        continue;
                    }

                    $last_ping = (int) Better_Messages()->functions->get_message_meta( $message_id, 'ai_last_ping' );

                    if( time() - $last_ping <= 3 * 60 ) {
                        continue;
                    }

                    $user_error = __( 'The response was interrupted. Please try again.', 'bp-better-messages' );

                    $args = [
                        'sender_id'  => $message->sender_id,
                        'thread_id'  => $message->thread_id,
                        'message_id' => $message_id,
                        'content'    => '<!-- BM-AI -->' . $user_error
                    ];

                    Better_Messages()->functions->update_message( $args );

                    Better_Messages()->functions->update_message_meta( $message_id, 'openai_response_status', 'failed' );
                    Better_Messages()->functions->update_message_meta( $message_id, 'ai_response_status', 'failed' );
                    Better_Messages()->functions->add_message_meta( $message_id, 'ai_response_error', 'Response interrupted (process terminated)' );
                    Better_Messages()->functions->delete_message_meta( $message_id, 'ai_last_ping' );
                    Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );

                    $original_message_id = Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_for' );
                    $original_message = Better_Messages()->functions->get_message( $original_message_id );

                    if ( $original_message ) {
                        Better_Messages()->functions->delete_message_meta( $original_message->id, 'ai_waiting_for_response' );
                        do_action( 'better_messages_thread_self_update', $original_message->thread_id, $original_message->sender_id );
                        do_action( 'better_messages_thread_updated', $original_message->thread_id, $original_message->sender_id );
                    }
                }
            }
        }
    }
}
