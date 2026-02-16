<?php

use BetterMessages\GuzzleHttp\Exception\GuzzleException;
use BetterMessages\GuzzleHttp\Psr7\Utils;
use BetterMessages\React\EventLoop\Loop;
use BetterMessages\React\Http\Browser;
use BetterMessages\React\Stream\ThroughStream;

if( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_OpenAI_API' ) ) {
    class Better_Messages_OpenAI_API
    {

        private $api_key;

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_OpenAI_API();
            }

            return $instance;
        }

        public function __construct()
        {
            $this->api_key = Better_Messages()->settings['openAiApiKey'];
        }

        public function update_api_key()
        {
            $this->api_key = Better_Messages()->settings['openAiApiKey'];
        }

        public function get_api_key()
        {
            return $this->api_key;
        }

        public function get_client()
        {
            return new \BetterMessages\GuzzleHttp\Client([
                'base_uri' => 'https://api.openai.com/v1/',
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->get_api_key(),
                    'Content-Type' => 'application/json',
                ]
            ]);
        }

        public function check_api_key()
        {
            $client = $this->get_client();

            try {
                $client->request('GET', 'models');
                delete_option('better_messages_openai_error');
            } catch ( GuzzleException $e ) {
                $fullError = $e->getMessage();

                if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                    $fullError = $e->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                update_option( 'better_messages_openai_error', $fullError, false );
            }
        }

        public function moderate()
        {
            $client = $this->get_client();

            try {
                $input = [];

                $input[] = [
                    'type' => 'text',
                    'text' => 'love you'
                ];

                $args = [
                    'model' => 'omni-moderation-latest',
                    'input' => $input
                ];

                $response = $client->post( 'moderations', [
                    'json' => $args
                ] );

                $body = $response->getBody()->getContents();

                $data = json_decode($body, true);

                print_r( $data );
            } catch ( GuzzleException $e ) {
                print_r( $e->getMessage() );
            }
        }

        public function get_models()
        {
            $client = $this->get_client();

            try{
                $response = $client->request('GET', 'models');

                $body = $response->getBody();

                $data = json_decode($body, true);

                $models = [];

                foreach ($data['data'] as $result) {
                    $model_id = $result['id'];

                    if( str_contains($model_id, 'gpt') && ! str_contains($model_id, '-realtime-') ) {
                        $models[] = $model_id;
                    }
                }

                sort($models);

                return $models;
            } catch (GuzzleException $e) {
                return new WP_Error( 'openai_error', $e->getMessage() );
            }
        }

        public function audioProvider( $bot_id, $bot_user, $message ) {
            global $wpdb;

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

            $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sender_id, message 
            FROM `" . bm_get_table('messages') . "` 
            WHERE thread_id = %d 
            AND created_at <= %d
            ORDER BY `created_at` ASC", $message->thread_id, $message->created_at ) );

            $request_messages = [];

            if( ! empty( $bot_settings['instruction'] ) ) {
                $request_messages[] = [
                    'role' => 'system',
                    'content' => apply_filters( 'better_messages_open_ai_bot_instruction', $bot_settings['instruction'], $bot_id, $message->sender_id )
                ];
            }

            foreach ( $messages as $_message ){
                $is_error = Better_Messages()->functions->get_message_meta( $_message->id, 'ai_response_error' );
                if( $is_error ) continue;

                $content = [];

                $content[] = [
                    'type' => 'text',
                    'text' => preg_replace('/<!--(.|\s)*?-->/', '', $_message->message)
                ];

                $role = (int) $_message->sender_id === (int) $bot_user_id ? 'assistant' : 'user';

                if( $role === 'assistant' ) {
                    $audio_id = Better_Messages()->functions->get_message_meta( $_message->id, 'openai_audio_id' );
                    $message_content = preg_replace('/<!--(.|\s)*?-->/', '', $_message->message);

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
                                'content' => apply_filters( 'better_messages_open_ai_bot_instruction', $bot_settings['instruction'], $bot_id, $message->sender_id )
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
                    if ( defined('BM_DEBUG') ) {
                        file_put_contents(ABSPATH . 'open-ai.log', time() . ' - $_message - ' . print_r( $_message, true ) . "\n", FILE_APPEND | LOCK_EX );
                    }

                    if( str_replace('<!-- BM-AI -->', '', $_message->message ) === '<!-- BPBM-VOICE-MESSAGE -->' && $attachment_id = Better_Messages()->functions->get_message_meta( $_message->id, 'bpbm_voice_messages', true ) ){
                        $file_path = get_attached_file( $attachment_id );

                        if( ! $file_path || ! file_exists($file_path) || filesize($file_path) > 20 * 1024 * 1024 ){
                            continue;
                        }

                        $file_content = file_get_contents( $file_path );

                        $base64 = base64_encode( $file_content );

                        $content[] = [
                            'type' => 'input_audio',
                            'input_audio' => [
                                'data'   => $base64,
                                'format' => 'mp3'
                            ]
                        ];

                    } else {
                        $content[] = [
                            'type' => 'text',
                            'text' => preg_replace('/<!--(.|\s)*?-->/', '', $_message->message)
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

            $params = [
                'model' => $bot_settings['model'],
                'modalities' => ['text', 'audio'],
                'messages' => $request_messages,
                'user' => (string) $message->sender_id
            ];

            $params['audio'] = [
                'format' => 'mp3',
                'voice' => $voice,
            ];

            try {
                $client = $this->get_client();

                $response = $client->post('chat/completions', [
                    'json' => $params
                ]);

                $body = $response->getBody();
                $data = json_decode($body, true);

                if( isset($data['choices']) && is_array($data['choices']) && count($data['choices']) > 0 ) {
                    if( isset( $data['choices'][0]['message']['audio'] ) ) {
                        $audio = $data['choices'][0]['message']['audio'];
                        $id = $audio['id'];
                        $base64 = $audio['data'];
                        $expires_at = $audio['expires_at'];
                        $transcript = $audio['transcript'];

                        $mp3Data = base64_decode($base64);
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

                            Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_id', $id);
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_transcript', $transcript);
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_expires_at', $expires_at);
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'openai_audio_voice', $voice);
                            Better_Messages()->functions->update_message_meta($ai_response_id, 'ai_response_finish', time());
                            Better_Messages()->functions->delete_message_meta($message->id, 'ai_waiting_for_response');
                            Better_Messages()->functions->delete_thread_meta($message->thread_id, 'ai_waiting_for_response');

                            do_action('better_messages_thread_self_update', $message->thread_id, $message->sender_id);
                            do_action('better_messages_thread_updated', $message->thread_id, $message->sender_id);
                        } finally {
                            if (file_exists($temp_path)) {
                                unlink($temp_path);
                            }
                        }
                    } else if ( isset( $data['choices'][0]['message']['content'] ) ) {
                        $content = $data['choices'][0]['message']['content'];

                        $args =  [
                            'sender_id'    => $ai_message->sender_id,
                            'thread_id'    => $message->thread_id,
                            'message_id'   => $ai_response_id,
                            'content'      => htmlentities( $content )
                        ];

                        Better_Messages()->functions->update_message( $args );

                        //Better_Messages()->functions->update_message_meta( $ai_response_id, 'openai_meta', $part[1] );
                        Better_Messages()->functions->update_message_meta( $ai_response_id, 'ai_response_finish', time() );
                        Better_Messages()->functions->delete_message_meta( $message->id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );
                        do_action( 'better_messages_thread_self_update', $message->thread_id, $message->sender_id );
                        do_action( 'better_messages_thread_updated', $message->thread_id, $message->sender_id );
                    }
                }

            } catch (\BetterMessages\GuzzleHttp\Exception\GuzzleException $e) {
                $error = $e->getMessage();

                if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                    $error = $e->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($error, true);
                        if( isset($data['error']['message']) ){
                            $error = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                $args =  [
                    'sender_id'    => $ai_message->sender_id,
                    'thread_id'    => $ai_message->thread_id,
                    'message_id'   => $ai_message->id,
                    'content'      => $error
                ];

                Better_Messages()->functions->update_message( $args );

                Better_Messages()->functions->delete_message_meta( $message->id, 'ai_waiting_for_response' );
                Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );
                do_action( 'better_messages_thread_self_update', $message->thread_id, $message->sender_id );
                do_action( 'better_messages_thread_updated', $message->thread_id, $message->sender_id );
                Better_Messages()->functions->add_message_meta( $ai_response_id, 'ai_response_error', $error );
                Better_Messages()->functions->add_message_meta( $message->id, 'ai_response_error', $error );
            }
        }

        public function get_response( $response_id )
        {
            $client = $this->get_client();

            try{
                $response = $client->get( "responses/{$response_id}" );

                $data = json_decode($response->getBody()->getContents(), true);

                return $data;
            } catch ( \Exception $exception ) {
                $fullError = $exception->getMessage();

                if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                    $fullError = $exception->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                return new WP_Error( 'bm_failed_to_get_open_ai_conversation_id', $fullError );
            }
        }

        public function get_response_input( $response_id )
        {
            $client = $this->get_client();

            try {
                $response = $client->get("responses/{$response_id}/input_items?limit=1");

                $data = json_decode($response->getBody()->getContents(), true);

                if( isset($data['data'][0]['id']) ){
                    return $data['data'][0]['id'];
                } else {
                    return new WP_Error( 'bm_failed_to_find_open_ai_response_input', 'Response input not found' );
                }
            } catch ( \Exception $exception ) {
                $fullError = $exception->getMessage();

                if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                    $fullError = $exception->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                return new WP_Error( 'bm_failed_to_create_open_ai_conversation_id', $fullError );
            }
        }

        public function sync_conversation( $thread_id )
        {
            $open_ai_conversation_id = $this->get_open_ai_conversation( $thread_id );

            if( ! is_wp_error( $open_ai_conversation_id ) ) {
                $client = $this->get_client();

                try{
                    $response = $client->get("conversations/{$open_ai_conversation_id['id']}/items?limit=5");

                    $data = json_decode($response->getBody()->getContents(), true);

                    print_r( $data );
                } catch ( \Exception $exception ) {
                    $fullError = $exception->getMessage();

                    if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                        $fullError = $exception->getResponse()->getBody()->getContents();

                        try{
                            $data = json_decode($fullError, true);
                            if( isset($data['error']['message']) ){
                                $fullError = $data['error']['message'];
                            }
                        } catch ( Exception $exception ){}
                    }

                    return new WP_Error( 'bm_failed_to_sync_open_ai_conversation_id', $fullError );
                }
            }
        }

        public function delete_conversation_message( $conversation_id, $message_id )
        {
            $client = $this->get_client();

            try {
                $client->delete("conversations/{$conversation_id}/items/{$message_id}");
            } catch ( \Throwable $exception ) {
                $fullError = $exception->getMessage();

                if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                    $fullError = $exception->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                return new WP_Error( 'bm_failed_to_delete_open_ai_conversation_message', $fullError );
            }
        }

        public function get_open_ai_conversation( $thread_id )
        {
            $openai_conversation = Better_Messages()->functions->get_thread_meta( $thread_id, 'openai_conversation' );

            if( empty( $openai_conversation ) ) {
                $client = $this->get_client();

                $params = [
                    'metadata' => [
                        'bm_thread_id' => $thread_id
                    ]
                ];

                try{
                    $response = $client->post('conversations', [
                        'json' => $params
                    ]);

                    $conversation  = json_decode($response->getBody()->getContents(), true);

                    Better_Messages()->functions->update_thread_meta( $thread_id, 'openai_conversation', $conversation );

                    return $conversation;
                } catch ( \Exception $exception ) {
                    $fullError = $exception->getMessage();

                    if ( method_exists( $exception, 'getResponse' ) && $exception->getResponse() ) {
                        $fullError = $exception->getResponse()->getBody()->getContents();

                        try{
                            $data = json_decode($fullError, true);
                            if( isset($data['error']['message']) ){
                                $fullError = $data['error']['message'];
                            }
                        } catch ( Exception $exception ){}
                    }

                    return new WP_Error( 'bm_failed_to_create_open_ai_conversation_id', $fullError );
                }
            } else {
                return $openai_conversation;
            }
        }

        function responseProvider( $bot_id, $bot_user, $message, $ai_message_id )
       {
            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            $thread_id = $message->thread_id;

            $open_ai_conversation = $this->get_open_ai_conversation( $thread_id );

            if( is_wp_error( $open_ai_conversation ) ){
                return $open_ai_conversation;
            }

            $input = [];

            $input_images = [];
            $input_files = [];

            $message_content = preg_replace( '/<!--(.|\s)*?-->/', '', $message->message );

            $content = [];

            if( ! empty( $message_content ) ) {
                $content[] = [
                    'type' => 'input_text',
                    'text' => $message_content
                ];
            }

           if( $bot_settings['images'] ) {
               $attachments = Better_Messages()->functions->get_message_meta($message->id, 'attachments', true);

               if ( ! empty($attachments) ) {
                   foreach ($attachments as $id => $url) {
                       $file = get_attached_file( $id );

                       if( $file && file_exists($file) && filesize($file) <= 20 * 1024 * 1024 ){
                           $file_extension = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
                           $file_name = pathinfo( $file, PATHINFO_BASENAME );

                           $file_content = file_get_contents( $file );
                           $base64_content = base64_encode( $file_content );

                            if( in_array( $file_extension, [ 'jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp' ] ) ){
                                $input_images[] = $id;

                                $content[] = [
                                    'type' => 'input_image',
                                    'image_url' => 'data:image/jpeg;base64,' . $base64_content
                                ];
                            } else if( $file_extension === 'pdf' ){
                                $original_filename = (string) get_post_meta( $id, 'bp-better-messages-original-name', true );

                                $input_files[] = $id;
                                $content[] = [
                                    'type' => 'input_file',
                                    'filename' => ! empty( $original_filename ) ? $original_filename : $file_name,
                                    'file_data' => 'data:application/pdf;base64,' . $base64_content
                                ];
                            }

                       }
                   }
               }
           }

           if( count( $input_files ) > 0 ){
               Better_Messages()->functions->update_message_meta( $ai_message_id, 'input_files', $input_files );
           }

           if( count( $input_images ) > 0 ){
               Better_Messages()->functions->update_message_meta( $ai_message_id, 'input_images', $input_images );
           }

            $input[] = [
               'role' => 'user',
               'content' => $content
           ];

            $tools = [];

            if( $bot_settings['imagesGeneration'] === '1' ){
                $tools[] = [
                    'type' => 'image_generation',
                    'partial_images' => 0,
                    'model' => $bot_settings['imagesGenerationModel'], // gpt-image-1 or gpt-image-1-mini
                    'quality' => $bot_settings['imagesGenerationQuality'], # low, medium, high, or auto
                    'size' => $bot_settings['imagesGenerationSize'] # One of 1024x1024, 1024x1536, 1536x1024, or auto
                ];
            }

            if( $bot_settings['webSearch'] === '1' ){
                $tools[] = [
                    'type' => 'web_search',
                    'search_context_size' => $bot_settings['webSearchContextSize'], // low, medium, or high
                ];
            }

            if( $bot_settings['fileSearch'] === '1' && is_array($bot_settings['fileSearchVectorIds']) && count( $bot_settings['fileSearchVectorIds'] ) > 0 ){
                $tools[] = [
                    'type' => 'file_search',
                    'vector_store_ids' => $bot_settings['fileSearchVectorIds']
                ];
            }

            $params = [
                'model' => $bot_settings['model'],
                'conversation' => $open_ai_conversation['id'],
                'max_output_tokens' => null, // Control max tokens in response to limit costs
                'service_tier' => $bot_settings['serviceTier'], // 'flex', 'default', 'priority', or 'auto'
                'truncation' => 'auto',
                'tools' => $tools,
                'instructions' => apply_filters( 'better_messages_open_ai_bot_instruction', $bot_settings['instruction'], $bot_id, $message->sender_id ) .'. This is very important you to use correct markdown format for providing response, especially for code blocks and snippets.',
                'input' => $input,
                'background' => true,
                'stream' => true
            ];

            $client = $this->get_client();

            if( defined('BM_DEBUG') ) {
                file_put_contents(ABSPATH . 'open-ai.log', time() . ' - params - ' . print_r( $params, true ) . "\n", FILE_APPEND | LOCK_EX);
            }

            $response_id = '';
            $message_id = '';
            $model = '';
            $images_generated = [];
            $attachment_meta = [];

            try {
               $response = $client->post('responses', [
                   'json' => $params,
                   'stream' => true,
                   'timeout' => 3600
               ]);

               $body = $response->getBody();

               $buffer = '';

               while ( ! $body->eof() ) {
                   $chunk = $body->read(1024);

                   if ($chunk === '') {
                       continue;
                   }

                   $buffer .= $chunk;

                   // Process full lines
                   while (($pos = strpos($buffer, "\n")) !== false) {
                       $line = trim(substr($buffer, 0, $pos));
                       $buffer = substr($buffer, $pos + 1);

                       if ($line === '') {
                           continue;
                       }

                       if (strpos($line, 'data: ') === 0) {
                           $json = substr($line, 6);

                           $data = json_decode( $json, true );

                           if( ! is_array($data) || ! isset($data['type']) ){
                               continue;
                           }

                           if( defined('BM_DEBUG') ) {
                               file_put_contents( ABSPATH . 'open-ai.log', time() . ' - ' . print_r( $data, true ) . "\n", FILE_APPEND | LOCK_EX );
                           }

                           $type = $data['type'];

                           switch ($type) {
                               case 'response.created':
                                   $response_id = $data['response']['id'];
                                   $model = $data['response']['model'];
                                   $response_status = $data['response']['status'];
                                   Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', $response_status );
                                   Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_id', $response_id );
                                   yield ['tick'];
                                   break;
                               case 'response.in_progress':
                                   $response_id = $data['response']['id'];
                                   $model = $data['response']['model'];
                                   $response_status = $data['response']['status'];
                                   Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', $response_status );
                                   yield ['tick'];
                                   break;
                               case 'response.output_item.added':
                                   $message_id = $data['item']['id'];
                                   yield ['tick'];
                                   break;
                               case 'response.content_part.added':
                                   $message_id = $data['item_id'];
                                   $text = $data['part']['text'];
                                   yield $text;
                                   break;

                               case 'response.output_text.delta':
                                   $message_id = $data['item_id'];
                                   $text = $data['delta'];
                                   yield $text;
                                   break;

                               case 'response.content_part.done':
                                   $message_id = $data['item_id'];
                                   yield ['tick'];
                                   break;

                               case 'response.output_item.done':
                                   $message_id = $data['item']['id'];

                                   if( isset( $data['item'] ) ){

                                       $item = $data['item'];

                                       if( isset( $item['type'] ) ){
                                           $type = $item['type'];

                                           switch ($type) {
                                               case 'image_generation_call':
                                                   $id      = $item['id'];
                                                   $format = $item['output_format'];
                                                   $size = $item['size'];
                                                   $background = $item['background'];
                                                   $quality = $item['quality'];

                                                   $generated_image = [
                                                         'id' => $id,
                                                         'format' => $format,
                                                         'size' => $size,
                                                         'background' => $background,
                                                         'quality' => $quality
                                                   ];

                                                   $base64 = $item['result'];
                                                   $fileData = base64_decode($base64);
                                                   $name = Better_Messages()->functions->random_string(30);
                                                   $temp_dir = sys_get_temp_dir();
                                                   $temp_path = trailingslashit($temp_dir) . $name;

                                                   try {
                                                       file_put_contents($temp_path, $fileData);

                                                       $file = [
                                                           'name' => $name . '.' . $format,
                                                           'type' => 'image/' . $format,
                                                           'tmp_name' => $temp_path,
                                                           'error' => 0,
                                                           'size' => filesize($temp_path)
                                                       ];

                                                       $attachment_id = Better_Messages()->files->save_file( $file, $ai_message_id, absint( $bot_user->id ) * -1 );

                                                       if( ! is_wp_error( $attachment_id ) ) {
                                                           $generated_image['attachment_id'] = $attachment_id;
                                                           add_post_meta( $attachment_id, 'bm_openai_generated_image', 1, true );
                                                           add_post_meta( $attachment_id, 'bm_openai_file_id', $id, true );
                                                           add_post_meta( $attachment_id, 'bm_openai_quality', $quality, true );
                                                           add_post_meta( $attachment_id, 'bm_openai_background', $background, true );
                                                           add_post_meta( $attachment_id, 'bm_openai_size', $size, true );
                                                           $attachment_meta[ $attachment_id ] = wp_get_attachment_url( $attachment_id );
                                                       }

                                                       Better_Messages()->functions->update_message_meta( $ai_message_id, 'attachments', $attachment_meta );
                                                   } finally {
                                                       @unlink($temp_path);
                                                       $images_generated[] = $generated_image;
                                                   }

                                                   break;
                                           }
                                       }
                                   }
                                   yield ['tick'];
                                   break;

                               case 'response.failed':
                                   $errorMessage = 'Unknown error';
                                   if( isset( $data['response']['error']['message'] ) ){
                                        $errorMessage = $data['response']['error']['message'];
                                   }

                                   yield ['error', $errorMessage];
                                   break;

                               case 'response.completed':
                                   if( count( $attachment_meta ) > 0 ) {
                                       Better_Messages()->functions->update_message_meta( $ai_message_id, 'attachments', $attachment_meta );
                                   }

                                   $array = [
                                       'response_id' => $response_id,
                                       'message_id' => $message_id,
                                       'conversation_id' => $open_ai_conversation['id'],
                                       'model' => $model,
                                       'service_tier' => $data['response']['service_tier'],
                                       'usage' => $data['response']['usage']
                                   ];

                                   if( count( $images_generated ) > 0 ){
                                       $array['images_generated'] = $images_generated;
                                   }

                                   yield ['finish', $array];
                                   break;
                               default:
                                   yield ['tick'];
                                   break;
                           }
                       }
                   }
               }
           } catch ( GuzzleException $e ) {
               $fullError = $e->getMessage();

               if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                   $fullError = $e->getResponse()->getBody()->getContents();

                   try{
                       $data = json_decode($fullError, true);
                       if( isset($data['error']['message']) ){
                           $fullError = $data['error']['message'];
                       }
                   } catch ( Exception $exception ){}
               }

               if( defined('BM_DEBUG') ) {
                   file_put_contents(ABSPATH . 'open-ai.log', time() . ' - error responseProvider GuzzleException - ' . print_r($e, true) . "\n", FILE_APPEND | LOCK_EX);
               }

               yield ['error', $fullError];
           } catch (\Throwable $e) {
                $fullError = $e->getMessage();

                if( defined('BM_DEBUG') ) {
                    file_put_contents(ABSPATH . 'open-ai.log', time() . ' - error responseProvider - ' . print_r($e, true) . "\n", FILE_APPEND | LOCK_EX);
                }

                yield ['error', $fullError];
            }
       }

        public function ensureResponseCompletionJob()
        {
            global $wpdb;

            $table = bm_get_table('meta');

            $query = "SELECT `bm_message_id` 
            FROM `{$table}` 
            WHERE `meta_key` = 'openai_response_status'
            AND `meta_value` IN ('queued', 'in_progress');";

            $uncompleted = array_map( 'intval', $wpdb->get_col($query) );

            if( count($uncompleted) > 0 ){
                foreach($uncompleted as $message_id){
                    $message = Better_Messages()->functions->get_message( $message_id );

                    if( ! $message ){
                        Better_Messages()->functions->delete_message_meta( $message_id, 'openai_response_status' );
                        continue;
                    }

                    $error = Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_error' );

                    if( ! empty( $error ) ){
                        Better_Messages()->functions->update_message_meta( $message_id, 'openai_response_status', 'failed' );
                        continue;
                    }

                    $last_ping = (int) Better_Messages()->functions->get_message_meta( $message_id, 'ai_last_ping' );

                    if( time() - $last_ping <= 3 * 60 ) {
                        // if the last ping was within 3 minutes, skip processing
                        continue;
                    }

                    $response_id = Better_Messages()->functions->get_message_meta( $message_id, 'openai_response_id' );

                    $response = $this->get_response( $response_id );

                    if( ! is_wp_error( $response ) ){
                        if( $response['status'] === 'completed' ){
                            $images_generated = [];
                            $attachment_meta = [];

                            $output = $response['output'];

                            $message_text = '';

                            foreach( $output as $item ){
                                $type = $item['type'];

                                if( $type === 'image_generation_call' ){
                                    $id      = $item['id'];
                                    $format = $item['output_format'];
                                    $size = $item['size'];
                                    $background = $item['background'];
                                    $quality = $item['quality'];

                                    $generated_image = [
                                        'id' => $id,
                                        'format' => $format,
                                        'size' => $size,
                                        'background' => $background,
                                        'quality' => $quality
                                    ];

                                    $base64 = $item['result'];
                                    $fileData = base64_decode($base64);
                                    $name = Better_Messages()->functions->random_string(30);
                                    $temp_dir = sys_get_temp_dir();
                                    $temp_path = trailingslashit($temp_dir) . $name;

                                    try {
                                        file_put_contents($temp_path, $fileData);

                                        $file = [
                                            'name' => $name . '.' . $format,
                                            'type' => 'image/' . $format,
                                            'tmp_name' => $temp_path,
                                            'error' => 0,
                                            'size' => filesize($temp_path)
                                        ];

                                        $attachment_id = Better_Messages()->files->save_file( $file, $message_id, $message->sender_id );

                                        if( ! is_wp_error( $attachment_id ) ) {
                                            $generated_image['attachment_id'] = $attachment_id;
                                            add_post_meta( $attachment_id, 'bm_openai_generated_image', 1, true );
                                            add_post_meta( $attachment_id, 'bm_openai_file_id', $id, true );
                                            add_post_meta( $attachment_id, 'bm_openai_quality', $quality, true );
                                            add_post_meta( $attachment_id, 'bm_openai_background', $background, true );
                                            add_post_meta( $attachment_id, 'bm_openai_size', $size, true );
                                            $attachment_meta[ $attachment_id ] = wp_get_attachment_url( $attachment_id );
                                        }

                                        Better_Messages()->functions->update_message_meta( $message_id, 'attachments', $attachment_meta );
                                    } finally {
                                        $images_generated[] = $generated_image;
                                    }
                                }

                                if( $type === 'message' ){
                                    $content = $item['content'];

                                    foreach( $content as $content_item ){
                                        $text = $content_item['text'];

                                        $message_text .= $text;
                                    }
                                }
                            }

                            $args =  [
                                'sender_id'    => $message->sender_id,
                                'thread_id'    => $message->thread_id,
                                'message_id'   => $message_id,
                                'content'      => '<!-- BM-AI -->' . htmlentities( $message_text )
                            ];

                            Better_Messages()->functions->update_message( $args );

                            if( count( $attachment_meta ) > 0 ) {
                                Better_Messages()->functions->update_message_meta( $message_id, 'attachments', $attachment_meta );
                            }

                            $meta = [
                                'recovered' => true,
                                'response_id' => $response_id,
                                'message_id' => $message_id,
                                'conversation_id' => $response['conversation']['id'],
                                'model' => $response['model'],
                                'service_tier' => $response['service_tier'],
                                'usage' => $response['usage']
                            ];

                            if( count( $images_generated ) > 0 ){
                                $meta['images_generated'] = $images_generated;
                            }

                            Better_Messages()->functions->update_message_meta( $message_id, 'openai_response_status', 'completed' );
                            Better_Messages()->functions->update_message_meta( $message_id, 'openai_message_id', $meta['message_id'] );
                            Better_Messages()->functions->update_message_meta( $message_id, 'openai_meta', json_encode($meta) );
                            Better_Messages()->functions->update_message_meta( $message_id, 'ai_response_finish', time() );
                            Better_Messages()->functions->delete_thread_meta( $message->thread_id, 'ai_waiting_for_response' );

                            $original_message_id = Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_for' );
                            $original_message = Better_Messages()->functions->get_message( $original_message_id );

                            if( $original_message ){
                                Better_Messages()->functions->delete_message_meta( $original_message->id, 'ai_waiting_for_response' );
                                do_action( 'better_messages_thread_self_update', $original_message->thread_id, $original_message->sender_id );
                                do_action( 'better_messages_thread_updated', $original_message->thread_id, $original_message->sender_id );
                            }

                            $response_input = Better_Messages_OpenAI_API::instance()->get_response_input( $meta['response_id'] );

                            if( ! is_wp_error( $response_input ) ){
                                Better_Messages()->functions->update_message_meta( $message_id, 'openai_message_id', $response_input );
                            }
                        }
                    }
                }
            }
        }

        public function createResponse( $ai_message_id, $params )
        {
            $client = $this->get_client();

            try{
                $response = $client->post('responses', [
                    'json' => $params,
                    'timeout' => 3600
                ]);

                $body = $response->getBody();

                $data = json_decode($body->getContents(), true);

                if( defined('BM_DEBUG') ) {
                    file_put_contents( ABSPATH . 'open-ai.log', time() . ' - createResponse data - ' . print_r( $data, true ) . "\n", FILE_APPEND | LOCK_EX );
                }

                if( isset( $data['id'] ) ){
                    $response_id = $data['id'];
                    $response_status = $data['status'];
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_id', $response_id );
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', $response_status );
                    return $response_id;
                } else {
                    throw new Exception( 'Failed to create response: No response ID returned' );
                }
            } catch (GuzzleException $e) {
                $fullError = $e->getMessage();

                if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                    $fullError = $e->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                if( defined('BM_DEBUG') ) {
                    file_put_contents(ABSPATH . 'open-ai.log', time() . ' - GuzzleException error createResponse - ' . print_r($e, true) . "\n", FILE_APPEND | LOCK_EX);
                }

                throw $e;
            } catch (\Throwable $e) {
                $fullError = $e->getMessage();

                if( defined('BM_DEBUG') ) {
                    file_put_contents(ABSPATH . 'open-ai.log', time() . ' - Throwable error createResponse - ' . print_r($e, true) . "\n", FILE_APPEND | LOCK_EX);
                }

                throw $e;
            }
        }

        function chatProvider( $bot_id, $bot_user, $message ) {
            global $wpdb;

            $bot_settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            $bot_user_id = absint( $bot_user->id ) * -1;

            $messages = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, sender_id, message 
            FROM `" . bm_get_table('messages') . "` 
            WHERE thread_id = %d 
            AND created_at <= %d
            ORDER BY `created_at` ASC", $message->thread_id, $message->created_at ) );

            $request_messages = [];

            if( ! empty( $bot_settings['instruction'] ) ) {
                $request_messages[] = [
                    'role' => 'system',
                    'content' => apply_filters( 'better_messages_open_ai_bot_instruction', $bot_settings['instruction'], $bot_id, $message->sender_id )
                ];
            }

            foreach ( $messages as $_message ){
                $is_error = Better_Messages()->functions->get_message_meta( $_message->id, 'ai_response_error' );
                if( $is_error ) continue;

                $content = [];

                $content[] = [
                    'type' => 'text',
                    'text' => preg_replace('/<!--(.|\s)*?-->/', '', $_message->message)
                ];

                if( $bot_settings['images'] ) {
                    $attachments = Better_Messages()->functions->get_message_meta($_message->id, 'attachments', true);

                    if (!empty($attachments)) {
                        foreach ($attachments as $attachment) {
                            $content[] = [
                                "type" => "image_url",
                                "image_url" => ["url" => $attachment]
                            ];
                        }
                    }
                }

                $request_messages[] = [
                    'role' => $message->sender_id === $bot_user_id ? 'assistant' : 'user',
                    'content' => $content,
                ];
            }

            $params = [
                'model' => $bot_settings['model'],
                'messages' => $request_messages,
                'user' => $message->sender_id,
                'stream' => true
            ];

            $client = $this->get_client();

            try {
                $response = $client->post('chat/completions', [
                    'json' => $params,
                    'stream' => true
                ]);

                $body = $response->getBody();
                $buffer = '';

                $request_id = '';
                $model = '';
                $service_tier = '';
                $system_fingerprint = '';

                while (!$body->eof()) {
                    $chunk = $body->read(1024);
                    if ($chunk === '') {
                        continue;
                    }

                    $buffer .= $chunk;

                    // Process full lines
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = trim(substr($buffer, 0, $pos));
                        $buffer = substr($buffer, $pos + 1);

                        if ($line === '') {
                            continue;
                        }

                        if (strpos($line, 'data: ') === 0) {
                            $json = substr($line, 6);

                            if ($json === '[DONE]') {
                                yield ['finish', [
                                    'request_id' => $request_id,
                                    'model' => $model,
                                    'service_tier' => $service_tier,
                                    'system_fingerprint' => $system_fingerprint
                                ]];

                                return; // end of stream
                            }

                            $data = json_decode($json, true);

                            if( isset($data['id']) ) {
                                $request_id = $data['id'];
                            }

                            if( isset( $data['model'] ) ){
                                $model = $data['model'];
                            }

                            if( isset( $data['service_tier'] ) ){
                                $service_tier = $data['service_tier'];
                            }

                            if( isset( $data['system_fingerprint'] ) ){
                                $system_fingerprint = $data['system_fingerprint'];
                            }

                            if (isset($data['choices'][0]['delta']['content'])) {
                                yield $data['choices'][0]['delta']['content'];
                            }
                        }
                    }
                }
            } catch (GuzzleException $e) {
                $fullError = $e->getMessage();

                if ( method_exists( $e, 'getResponse' ) && $e->getResponse() ) {
                    $fullError = $e->getResponse()->getBody()->getContents();

                    try{
                        $data = json_decode($fullError, true);
                        if( isset($data['error']['message']) ){
                            $fullError = $data['error']['message'];
                        }
                    } catch ( Exception $exception ){}
                }

                yield ['error', $fullError];
            }
        }

        function process_reply( $bot_id, $message_id )
        {
            if( wp_get_scheduled_event( 'better_messages_ai_bot_ensure_completion', [ $bot_id, $message_id ] ) ){
                wp_clear_scheduled_hook( 'better_messages_ai_bot_ensure_completion', [ $bot_id, $message_id ] );
            }

            $message = Better_Messages()->functions->get_message( $message_id );

            if( ! $message ){
                return;
            }

            if( empty( Better_Messages()->functions->get_message_meta( $message_id, 'ai_waiting_for_response' ) ) ){
                return;
            }

            $recipient_user_id = $message->sender_id;

            $bot_user = Better_Messages()->ai->get_bot_user( $bot_id );

            if( ! $bot_user ){
                return;
            }

            $settings = Better_Messages()->ai->get_bot_settings( $bot_id );

            $ai_user_id = absint( $bot_user->id ) * -1;
            $ai_thread_id = $message->thread_id;

            $ai_message_id = Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_id' );

            if( $ai_message_id ){
                $ai_message = Better_Messages()->functions->get_message( $ai_message_id );
                if( ! $ai_message ){
                    $ai_message_id = false;
                }
            }

            if( ! $ai_message_id ){
                $ai_message_id = Better_Messages()->functions->new_message([
                    'sender_id'    => $ai_user_id,
                    'thread_id'    => $ai_thread_id,
                    'content'      => '<!-- BM-AI -->',
                    'count_unread' => false,
                    'return'       => 'message_id',
                    'error_type'   => 'wp_error'
                ]);

                Better_Messages()->functions->add_message_meta( $ai_message_id, 'ai_response_for', $message_id );

                if( ! is_wp_error( $ai_message_id ) ){
                    Better_Messages()->functions->add_message_meta( $ai_message_id, 'ai_response_start', time() );
                    Better_Messages()->functions->add_message_meta( $message_id, 'ai_response_id', $ai_message_id );
                } else {
                    return;
                }
            }

            // If Audio Model Used
            if( str_contains($settings['model'], '-audio-') && class_exists('BP_Better_Messages_Voice_Messages') ){
                $this->audioProvider( $bot_id, $bot_user, $message );
                return;
            }

            $loop = Loop::get();
            $browser = new Browser($loop);
            $stream = new ThroughStream(function ($data) { return $data; });

            $dataProvider = $this->responseProvider( $bot_id, $bot_user, $message, $ai_message_id );

            $last_ping = time();
            Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_last_ping', $last_ping );

            $parts = [];
            $process = null;
            $process = function () use (&$process, &$last_ping, $loop, $stream, $dataProvider, $message_id, $ai_user_id, $ai_message_id, $ai_thread_id, &$parts, $recipient_user_id ) {
                if ( defined('BM_DEBUG') ) {
                    file_put_contents(ABSPATH . 'open-ai.log', time() . ' - tick' . "\n", FILE_APPEND | LOCK_EX);
                }

                if ( time() - $last_ping >= 5 ) {
                    $last_ping = time();
                    Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_last_ping', $last_ping );
                }

                if ( $dataProvider->valid() ) {
                    $part = $dataProvider->current();

                    if( is_array($part) && $part[0] === 'error' ){
                        try {
                            if ( is_object($stream) && method_exists($stream, 'write') ) {
                                $stream->write( $part[1] );
                            }
                        } catch ( \Throwable $e ) {}

                        try {
                            if ( is_object($stream) && method_exists($stream, 'end') ) { $stream->end(); }
                        } catch ( \Throwable $e ) {}

                        $loop->stop();

                        $args =  [
                            'sender_id'    => $ai_user_id,
                            'thread_id'    => $ai_thread_id,
                            'message_id'   => $ai_message_id,
                            'content'      => '<!-- BM-AI -->' . $part[1]
                        ];

                        Better_Messages()->functions->update_message( $args );

                        Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                        do_action( 'better_messages_thread_self_update', $ai_thread_id, $recipient_user_id );
                        do_action( 'better_messages_thread_updated', $ai_thread_id, $recipient_user_id );

                        Better_Messages()->functions->add_message_meta( $ai_message_id, 'ai_response_error', $part[1] );
                        Better_Messages()->functions->add_message_meta( $message_id, 'ai_response_error', $part[1] );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', 'failed' );
                        Better_Messages()->functions->delete_message_meta( $ai_message_id, 'ai_last_ping' );
                        return;
                    }

                    if( is_array($part) && $part[0] === 'finish' ){
                        $stream->end();

                        $loop->stop();

                        $args =  [
                            'sender_id'    => $ai_user_id,
                            'thread_id'    => $ai_thread_id,
                            'message_id'   => $ai_message_id,
                            'content'      => '<!-- BM-AI -->' . htmlentities( implode('', $parts) )
                        ];

                        Better_Messages()->functions->update_message( $args );

                        $meta = $part[1];

                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_response_status', 'completed' );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_message_id', $meta['message_id'] );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'openai_meta', json_encode($meta) );
                        Better_Messages()->functions->update_message_meta( $ai_message_id, 'ai_response_finish', time() );
                        Better_Messages()->functions->delete_message_meta( $message_id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_thread_meta( $ai_thread_id, 'ai_waiting_for_response' );
                        Better_Messages()->functions->delete_message_meta( $ai_message_id, 'ai_last_ping' );

                        do_action( 'better_messages_thread_self_update', $ai_thread_id, $recipient_user_id );
                        do_action( 'better_messages_thread_updated', $ai_thread_id, $recipient_user_id );

                        $response_input = Better_Messages_OpenAI_API::instance()->get_response_input( $meta['response_id'] );

                        if( ! is_wp_error( $response_input ) ){
                            Better_Messages()->functions->update_message_meta( $message_id, 'openai_message_id', $response_input );
                        }

                        return;
                    }

                    if( is_string($part) ){
                        $parts[] = $part;

                        try {
                            if ( is_object($stream) && method_exists($stream, 'write') ) {
                                $stream->write( $part );
                            }
                        } catch ( \Throwable $e ) {}
                    }

                    $dataProvider->next();

                    $loop->futureTick($process);
                } else {
                    try {
                        if ( is_object($stream) && method_exists($stream, 'end') ) {
                            $stream->end();
                        }
                    } catch ( \Throwable $e ) {}

                    $loop->stop();
                }
            };

            if( Better_Messages()->websocket ) {
                $socket_server = apply_filters('bp_better_messages_realtime_server', 'https://cloud.better-messages.com/');
                $bm_endpoint = $socket_server . 'streamMessage';

                $browser->post($bm_endpoint, [
                    'x-site-id' => Better_Messages()->websocket->site_id,
                    'x-secret-key' => sha1(Better_Messages()->websocket->site_id . Better_Messages()->websocket->secret_key),
                    'x-message-id' => $ai_message_id,
                    'x-thread-id' => $ai_thread_id,
                    'x-recipient-user-id' => $recipient_user_id,
                    'x-sender-user-id' => $ai_user_id,
                ], $stream);
            }

            $loop->futureTick($process);
        }

        public function reply_to_message( WP_REST_Request $request )
        {
            Better_Messages()->functions->end_browser_output();

            $bot_id     = (int) $request->get_param( 'bot_id' );
            $message_id = (int) $request->get_param( 'message_id' );

            if( ! empty( $bot_id ) && ! empty( $message_id ) ){
                $this->process_reply( $bot_id, $message_id );
            }
        }

        public function cancel_response( WP_REST_Request $request )
        {
            global $wpdb;

            $user_id = Better_Messages()->functions->get_current_user_id();
            $thread_id = (int) $request->get_param( 'id' );

            $is_waiting = Better_Messages()->functions->get_thread_meta( $thread_id, 'ai_waiting_for_response' );

            if( $is_waiting ) {
                // Get last message in thread
                $query = $wpdb->prepare( "
                    SELECT id, thread_id, sender_id, message, created_at, updated_at, temp_id
                    FROM  " . bm_get_table('messages') . "
                    WHERE `thread_id` = %d
                    ORDER BY `created_at` DESC
                    LIMIT 0, 1
                    ", $thread_id );

                $message = $wpdb->get_row( $query, ARRAY_A );

                if( $message && str_starts_with($message['message'], '<!-- BM-AI -->') ){
                    // this is AI message
                    $message_id = $message['id'];

                    Better_Messages()->functions->add_message_meta( $message_id, 'ai_waiting_for_cancel', time() );
                    $response_id = Better_Messages()->functions->get_message_meta( $message_id, 'openai_response_id' );

                    $wait_time = 0;
                    while( ! $response_id && $wait_time < 20 ) {
                        sleep(1);
                        $wait_time++;
                        // Avoiding cache issues
                        $table = bm_get_table('meta');
                        $response_id = $wpdb->get_var( $wpdb->prepare( "SELECT `meta_value` FROM `{$table}` WHERE `bm_message_id` = %d AND `meta_key` = 'openai_response_id'", $message_id ) );
                    }

                    if( $response_id ) {
                        $client = $this->get_client();

                        try {
                            $response = $client->post('responses/' . $response_id . '/cancel', [
                                'timeout' => 30
                            ]);

                            $body = $response->getBody();

                            $data = json_decode($body->getContents(), true);

                            if (defined('BM_DEBUG')) {
                                file_put_contents(ABSPATH . 'open-ai.log', time() . ' - cancel_response data - ' . print_r($data, true) . "\n", FILE_APPEND | LOCK_EX);
                            }

                            if (isset($data['status']) && $data['status'] === 'cancelled') {
                                Better_Messages()->functions->delete_message( $message_id, $thread_id );
                            }
                        } catch (Throwable $e) {
                            Better_Messages()->functions->delete_message_meta($message_id, 'ai_waiting_for_response');
                        } finally {
                            Better_Messages()->functions->delete_thread_meta($thread_id, 'ai_waiting_for_response');
                            do_action('better_messages_thread_self_update', $thread_id, $user_id);
                            do_action('better_messages_thread_updated', $thread_id, $user_id);
                        }
                    } else {
                        Better_Messages()->functions->delete_thread_meta($thread_id, 'ai_waiting_for_response');
                        do_action('better_messages_thread_self_update', $thread_id, $user_id);
                        do_action('better_messages_thread_updated', $thread_id, $user_id);
                    }
                }
            } else {
                Better_Messages()->functions->delete_thread_meta($thread_id, 'ai_waiting_for_response');
                do_action('better_messages_thread_self_update', $thread_id, $user_id);
                do_action('better_messages_thread_updated', $thread_id, $user_id);
            }

            return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
        }
    }
}
