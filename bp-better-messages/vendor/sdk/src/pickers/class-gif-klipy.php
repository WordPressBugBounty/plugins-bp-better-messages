<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Gif_Klipy' ) ) {
    class WordPlus_SDK_Gif_Klipy extends WordPlus_SDK_Gif_Provider
    {
        public $locale        = 'en';

        const BASE_URL    = 'https://api.klipy.com';
        const PER_PAGE    = 24;
        const FORMAT_LIST = 'mp4,webp,jpg';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new WordPlus_SDK_Gif_Klipy();
            }

            return $instance;
        }

        public function __construct()
        {
            $settings = WordPlus_SDK::instance()->pickers()->settings();

            $this->api_key       = isset( $settings['klipyApiKey'] )       ? $settings['klipyApiKey']      : '';
            $this->locale        = isset( $settings['klipyLocale'] )       ? $settings['klipyLocale']      : 'en';
        }

        public function get_provider_id()
        {
            return 'klipy';
        }

        public function get_provider_name()
        {
            return 'KLIPY';
        }

        public function get_supported_features()
        {
            return array( 'trending', 'search', 'language' );
        }

        public function get_trending( $user_id, $page = 1 )
        {
            $page      = max( 1, intval( $page ) );
            $cache_key = 'wplus_gifs_klipy_trending_' . $page . '_' . sanitize_key( $this->locale );

            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && ! empty( $cached['gifs'] ) ) {
                return $cached;
            }

            $endpoint = $this->build_url( '/gifs/trending', array(
                'page'          => $page,
                'per_page'      => self::PER_PAGE,
                'customer_id'   => $this->get_customer_id( $user_id ),
                'locale'        => $this->locale,
                'format_filter' => self::FORMAT_LIST,
            ) );

            $result = $this->fetch_and_format( $endpoint );

            if ( ! empty( $result['gifs'] ) ) {
                set_transient( $cache_key, $result, 30 * MINUTE_IN_SECONDS );
            }

            return $result;
        }

        public function search( $user_id, $query, $page = 1 )
        {
            $page      = max( 1, intval( $page ) );
            $cache_key = 'wplus_gifs_klipy_search_' . md5( $query . '|' . $this->locale ) . '_' . $page;

            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && ! empty( $cached['gifs'] ) ) {
                return $cached;
            }

            $endpoint = $this->build_url( '/gifs/search', array(
                'q'             => $query,
                'page'          => $page,
                'per_page'      => self::PER_PAGE,
                'customer_id'   => $this->get_customer_id( $user_id ),
                'locale'        => $this->locale,
                'format_filter' => self::FORMAT_LIST,
            ) );

            $result = $this->fetch_and_format( $endpoint );

            if ( ! empty( $result['gifs'] ) ) {
                set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
            }

            return $result;
        }

        public function get_by_id( $gif_id, $user_id )
        {
            $endpoint = $this->build_url( '/gifs/items', array(
                'ids'         => $gif_id,
                'customer_id' => $this->get_customer_id( $user_id ),
            ) );

            $request = wp_remote_get( $endpoint, array( 'timeout' => 2 ) );

            if ( is_wp_error( $request ) ) {
                return false;
            }

            $response = json_decode( wp_remote_retrieve_body( $request ) );

            $items = $this->extract_items( $response );

            if ( empty( $items ) ) {
                return false;
            }

            return $this->format_item( $items[0] );
        }

        public function check_api_key()
        {
            if ( empty( $this->api_key ) ) {
                delete_option( WordPlus_SDK_Pickers::KLIPY_ERROR );
                return;
            }

            $endpoint = $this->build_url( '/gifs/trending', array(
                'page'          => 1,
                'per_page'      => 1,
                'customer_id'   => 'wordplus-check',
                'locale'        => $this->locale,
                'format_filter' => self::FORMAT_LIST,
            ) );

            $request = wp_remote_get( $endpoint, array( 'timeout' => 3 ) );

            if ( is_wp_error( $request ) ) {
                update_option(
                    WordPlus_SDK_Pickers::KLIPY_ERROR,
                    'KLIPY Error: ' . $request->get_error_message(),
                    false
                );
                return;
            }

            $code = wp_remote_retrieve_response_code( $request );

            if ( $code !== 200 ) {
                $body    = json_decode( wp_remote_retrieve_body( $request ) );
                $message = isset( $body->message ) ? $body->message : ( 'HTTP ' . $code );
                update_option( WordPlus_SDK_Pickers::KLIPY_ERROR, $message, false );
                return;
            }

            $body = json_decode( wp_remote_retrieve_body( $request ) );

            if ( ! isset( $body->result ) || ! $body->result ) {
                $message = isset( $body->message ) ? $body->message : 'Invalid API key';
                update_option( WordPlus_SDK_Pickers::KLIPY_ERROR, $message, false );
                return;
            }

            delete_option( WordPlus_SDK_Pickers::KLIPY_ERROR );
        }

        protected function build_url( $path, $args )
        {
            $url = self::BASE_URL . '/api/v1/' . rawurlencode( $this->api_key ) . $path;
            return self::with_query( $args, $url );
        }

        protected function fetch_and_format( $endpoint )
        {
            $request = wp_remote_get( $endpoint, array( 'timeout' => 3 ) );

            if ( is_wp_error( $request ) ) {
                return $this->empty_response( $request->get_error_message() );
            }

            $response = json_decode( wp_remote_retrieve_body( $request ) );
            $items    = $this->extract_items( $response );

            if ( empty( $items ) ) {
                return $this->empty_response();
            }

            $gifs = array();
            foreach ( $items as $item ) {
                $formatted = $this->format_item( $item );
                if ( $formatted ) {
                    $gifs[] = $formatted;
                }
            }

            $current_page = isset( $response->data->current_page ) ? intval( $response->data->current_page ) : 1;
            $per_page     = isset( $response->data->per_page )     ? intval( $response->data->per_page )     : self::PER_PAGE;
            $has_next     = ! empty( $response->data->has_next );

            return array(
                'pagination' => (object) array(
                    'total_count' => $has_next ? ( $current_page * $per_page ) + 1 : ( ( $current_page - 1 ) * $per_page ) + count( $gifs ),
                    'count'       => count( $gifs ),
                    'offset'      => ( $current_page - 1 ) * $per_page,
                ),
                'gifs'       => $gifs,
            );
        }

        protected function extract_items( $response )
        {
            if ( ! is_object( $response ) ) {
                return array();
            }
            if ( isset( $response->data->data ) && is_array( $response->data->data ) ) {
                return $response->data->data;
            }
            if ( isset( $response->data->items ) && is_array( $response->data->items ) ) {
                return $response->data->items;
            }
            return array();
        }

        protected function format_item( $item )
        {
            if ( ! isset( $item->id ) || ! isset( $item->file ) ) {
                return null;
            }

            $result = array(
                'id'     => (string) $item->id,
                'width'  => 0,
                'height' => 0,
            );

            $thumb = $this->pick_asset( $item->file, array( 'md', 'sm', 'xs' ), array( 'webp', 'gif' ) );
            if ( $thumb ) {
                $result['url']    = $thumb['url'];
                $result['width']  = $thumb['width'];
                $result['height'] = $thumb['height'];
            }
            $mp4 = $this->pick_asset( $item->file, array( 'hd', 'md', 'sm' ), array( 'mp4' ) );
            if ( $mp4 ) {
                $result['mp4'] = $mp4['url'];
            }
            $poster = $this->pick_asset( $item->file, array( 'md', 'sm', 'hd' ), array( 'jpg' ) );
            if ( $poster ) {
                $result['poster'] = $poster['url'];
            }

            if ( empty( $result['mp4'] ) ) {
                return null;
            }

            return $result;
        }

        protected function pick_asset( $file, $sizes, $formats )
        {
            if ( ! is_object( $file ) ) {
                return null;
            }
            foreach ( $sizes as $size ) {
                if ( ! isset( $file->{$size} ) || ! is_object( $file->{$size} ) ) {
                    continue;
                }
                $bucket = $file->{$size};
                foreach ( $formats as $format ) {
                    if ( isset( $bucket->{$format}->url ) && ! empty( $bucket->{$format}->url ) ) {
                        return array(
                            'url'    => $bucket->{$format}->url,
                            'width'  => isset( $bucket->{$format}->width ) ? (int) $bucket->{$format}->width : 0,
                            'height' => isset( $bucket->{$format}->height ) ? (int) $bucket->{$format}->height : 0,
                        );
                    }
                }
            }
            return null;
        }

        protected function get_customer_id( $user_id )
        {
            $customer_id = WordPlus_SDK::instance()->pickers()->user_meta( $user_id, 'bm_klipy_customer_id', true );
            if ( ! empty( $customer_id ) ) {
                return $customer_id;
            }

            $customer_id = 'bm-' . wp_generate_uuid4();
            WordPlus_SDK::instance()->pickers()->set_user_meta( $user_id, 'bm_klipy_customer_id', $customer_id );

            return $customer_id;
        }
    }
}
