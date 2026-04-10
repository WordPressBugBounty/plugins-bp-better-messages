<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Klipy_Provider' ) ) {
    class Better_Messages_Klipy_Provider extends Better_Messages_Gif_Provider
    {
        public $locale        = 'en';

        const BASE_URL    = 'https://api.klipy.com';
        const PER_PAGE    = 24;
        const FORMAT_LIST = 'mp4,webp,jpg';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Klipy_Provider();
            }

            return $instance;
        }

        public function __construct()
        {
            $settings = Better_Messages()->settings;

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
            $cache_key = 'bm_gifs_klipy_trending_' . $page . '_' . sanitize_key( $this->locale );

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
            $cache_key = 'bm_gifs_klipy_search_' . md5( $query . '|' . $this->locale ) . '_' . $page;

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
                delete_option( 'better_messages_gifs_klipy_error' );
                return;
            }

            $endpoint = $this->build_url( '/gifs/trending', array(
                'page'          => 1,
                'per_page'      => 1,
                'customer_id'   => 'better-messages-check',
                'locale'        => $this->locale,
                'format_filter' => self::FORMAT_LIST,
            ) );

            $request = wp_remote_get( $endpoint, array( 'timeout' => 3 ) );

            if ( is_wp_error( $request ) ) {
                update_option(
                    'better_messages_gifs_klipy_error',
                    'KLIPY Error: ' . $request->get_error_message(),
                    false
                );
                return;
            }

            $code = wp_remote_retrieve_response_code( $request );

            if ( $code !== 200 ) {
                $body    = json_decode( wp_remote_retrieve_body( $request ) );
                $message = isset( $body->message ) ? $body->message : ( 'HTTP ' . $code );
                update_option( 'better_messages_gifs_klipy_error', $message, false );
                return;
            }

            $body = json_decode( wp_remote_retrieve_body( $request ) );

            if ( ! isset( $body->result ) || ! $body->result ) {
                $message = isset( $body->message ) ? $body->message : 'Invalid API key';
                update_option( 'better_messages_gifs_klipy_error', $message, false );
                return;
            }

            delete_option( 'better_messages_gifs_klipy_error' );
        }

        protected function build_url( $path, $args )
        {
            $url = self::BASE_URL . '/api/v1/' . rawurlencode( $this->api_key ) . $path;
            return add_query_arg( $args, $url );
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
                    // The plugin's existing frontend pagination math uses total_count / count.
                    // KLIPY only exposes has_next, so we infer a total that keeps the "more pages" flag correct.
                    'total_count' => $has_next ? ( $current_page * $per_page ) + 1 : ( ( $current_page - 1 ) * $per_page ) + count( $gifs ),
                    'count'       => count( $gifs ),
                    'offset'      => ( $current_page - 1 ) * $per_page,
                ),
                'gifs'       => $gifs,
            );
        }

        /**
         * @param mixed $response Decoded KLIPY response
         * @return array Array of raw item objects, or empty array.
         */
        protected function extract_items( $response )
        {
            if ( ! is_object( $response ) ) {
                return array();
            }
            if ( isset( $response->data->data ) && is_array( $response->data->data ) ) {
                return $response->data->data;
            }
            // Some endpoints wrap in data.items instead of data.data.
            if ( isset( $response->data->items ) && is_array( $response->data->items ) ) {
                return $response->data->items;
            }
            return array();
        }

        /**
         * Normalize a KLIPY item into the same shape the plugin frontend expects.
         * Picks the best available size for each role (thumbnail / mp4 / poster).
         */
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

            // Thumbnail: prefer md webp, fall back md gif, then sm.
            $thumb = $this->pick_asset( $item->file, array( 'md', 'sm', 'xs' ), array( 'webp', 'gif' ) );
            if ( $thumb ) {
                $result['url']    = $thumb['url'];
                $result['width']  = $thumb['width'];
                $result['height'] = $thumb['height'];
            }
            // Playable mp4: prefer hd, fall back md.
            $mp4 = $this->pick_asset( $item->file, array( 'hd', 'md', 'sm' ), array( 'mp4' ) );
            if ( $mp4 ) {
                $result['mp4'] = $mp4['url'];
            }
            // Poster (still): prefer md jpg, fall back sm jpg.
            $poster = $this->pick_asset( $item->file, array( 'md', 'sm', 'hd' ), array( 'jpg' ) );
            if ( $poster ) {
                $result['poster'] = $poster['url'];
            }

            if ( empty( $result['mp4'] ) ) {
                return null;
            }

            return $result;
        }

        /**
         * Returns [url, width, height] for the best-available asset, or null.
         */
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

        /**
         * KLIPY requires a stable per-user customer_id. Re-use the GIPHY cache slot
         * so GIF analytics stay consistent per user across providers, but namespace it.
         */
        protected function get_customer_id( $user_id )
        {
            $customer_id = Better_Messages()->functions->get_user_meta( $user_id, 'bm_klipy_customer_id', true );
            if ( ! empty( $customer_id ) ) {
                return $customer_id;
            }

            $customer_id = 'bm-' . wp_generate_uuid4();
            Better_Messages()->functions->update_user_meta( $user_id, 'bm_klipy_customer_id', $customer_id );

            return $customer_id;
        }
    }
}
