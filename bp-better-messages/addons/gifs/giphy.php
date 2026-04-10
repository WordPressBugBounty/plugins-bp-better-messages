<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Giphy_Provider' ) ) {
    class Better_Messages_Giphy_Provider extends Better_Messages_Gif_Provider
    {
        public $content_rating = 'g';
        public $lang           = 'en';

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Giphy_Provider();
            }

            return $instance;
        }

        public function __construct()
        {
            $settings = Better_Messages()->settings;

            $this->api_key        = isset( $settings['giphyApiKey'] )        ? $settings['giphyApiKey']        : '';
            $this->content_rating = isset( $settings['giphyContentRating'] ) ? $settings['giphyContentRating'] : 'g';
            $this->lang           = isset( $settings['giphyLanguage'] )      ? $settings['giphyLanguage']      : 'en';
        }

        public function get_provider_id()
        {
            return 'giphy';
        }

        public function get_provider_name()
        {
            return 'GIPHY';
        }

        public function get_supported_features()
        {
            return array( 'trending', 'search', 'rating', 'language' );
        }

        public function get_trending( $user_id, $page = 1 )
        {
            $page      = max( 1, intval( $page ) );
            $cache_key = 'bm_gifs_giphy_trending_' . $page . '_' . sanitize_key( $this->content_rating );

            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && ! empty( $cached['gifs'] ) ) {
                return $cached;
            }

            $random_id = $this->get_random_id( $user_id );
            $offset    = $page <= 1 ? 0 : ( $page * 20 ) - 20;

            $endpoint = add_query_arg( array(
                'api_key'   => $this->api_key,
                'limit'     => 20,
                'rating'    => $this->content_rating,
                'random_id' => $random_id,
                'offset'    => $offset,
            ), 'https://api.giphy.com/v1/gifs/trending' );

            $result = $this->fetch_and_format( $endpoint );

            // Cache successful, non-empty responses. Trending changes slowly so 30 min is safe.
            if ( ! empty( $result['gifs'] ) ) {
                set_transient( $cache_key, $result, 30 * MINUTE_IN_SECONDS );
            }

            return $result;
        }

        public function search( $user_id, $query, $page = 1 )
        {
            $page      = max( 1, intval( $page ) );
            $cache_key = 'bm_gifs_giphy_search_' . md5( $query . '|' . $this->content_rating . '|' . $this->lang ) . '_' . $page;

            $cached = get_transient( $cache_key );
            if ( is_array( $cached ) && ! empty( $cached['gifs'] ) ) {
                return $cached;
            }

            $random_id = $this->get_random_id( $user_id );
            $offset    = $page <= 1 ? 0 : ( $page * 20 ) - 20;

            $endpoint = add_query_arg( array(
                'api_key'   => $this->api_key,
                'q'         => $query,
                'limit'     => 20,
                'rating'    => $this->content_rating,
                'random_id' => $random_id,
                'offset'    => $offset,
                'lang'      => $this->lang,
            ), 'https://api.giphy.com/v1/gifs/search' );

            $result = $this->fetch_and_format( $endpoint );

            if ( ! empty( $result['gifs'] ) ) {
                set_transient( $cache_key, $result, 15 * MINUTE_IN_SECONDS );
            }

            return $result;
        }

        public function get_by_id( $gif_id, $user_id )
        {
            $random_id = $this->get_random_id( $user_id );

            $endpoint = add_query_arg( array(
                'api_key'   => $this->api_key,
                'gif_id'    => $gif_id,
                'random_id' => $random_id,
            ), 'https://api.giphy.com/v1/gifs/' . rawurlencode( $gif_id ) );

            $request = wp_remote_get( $endpoint, array( 'timeout' => 2 ) );

            if ( is_wp_error( $request ) ) {
                return false;
            }

            $response = json_decode( wp_remote_retrieve_body( $request ) );

            if ( ! isset( $response->data->id ) ) {
                return false;
            }

            $gif = $response->data;

            $result = array(
                'id' => $gif->id,
            );

            if ( isset( $gif->images->original_mp4->mp4 ) ) {
                $result['mp4'] = $gif->images->original_mp4->mp4;
            }
            if ( isset( $gif->images->{'480w_still'}->url ) ) {
                $result['poster'] = $gif->images->{'480w_still'}->url;
            }

            return $result;
        }

        public function check_api_key()
        {
            if ( empty( $this->api_key ) ) {
                delete_option( 'better_messages_gifs_giphy_error' );
                return;
            }

            $endpoint = add_query_arg( array(
                'api_key' => $this->api_key,
                'limit'   => 20,
                'rating'  => $this->content_rating,
                'offset'  => 0,
            ), 'https://api.giphy.com/v1/gifs/trending' );

            $request = wp_remote_get( $endpoint, array( 'timeout' => 2 ) );

            if ( is_wp_error( $request ) ) {
                update_option(
                    'better_messages_gifs_giphy_error',
                    'GIPHY Error: ' . $request->get_error_message(),
                    false
                );
                return;
            }

            $code = wp_remote_retrieve_response_code( $request );

            if ( $code !== 200 ) {
                $response = json_decode( wp_remote_retrieve_body( $request ) );
                $message  = isset( $response->message ) ? $response->message : ( 'HTTP ' . $code );
                update_option( 'better_messages_gifs_giphy_error', $message, false );
                return;
            }

            delete_option( 'better_messages_gifs_giphy_error' );
        }

        /**
         * Shared helper for trending and search.
         */
        protected function fetch_and_format( $endpoint )
        {
            $request = wp_remote_get( $endpoint, array( 'timeout' => 2 ) );

            if ( is_wp_error( $request ) ) {
                return $this->empty_response( $request->get_error_message() );
            }

            $response = json_decode( wp_remote_retrieve_body( $request ) );

            if ( ! isset( $response->data ) ) {
                return $this->empty_response();
            }

            $result = array(
                'pagination' => isset( $response->pagination ) ? $response->pagination : (object) array(),
                'gifs'       => array(),
            );

            foreach ( $response->data as $gif ) {
                $item = array(
                    'id'     => $gif->id,
                    'url'    => isset( $gif->images->fixed_width->url ) ? $gif->images->fixed_width->url : '',
                    'width'  => isset( $gif->images->fixed_width->width ) ? (int) $gif->images->fixed_width->width : 0,
                    'height' => isset( $gif->images->fixed_width->height ) ? (int) $gif->images->fixed_width->height : 0,
                );

                if ( isset( $gif->images->original_mp4->mp4 ) ) {
                    $item['mp4'] = $gif->images->original_mp4->mp4;
                }
                if ( isset( $gif->images->{'480w_still'}->url ) ) {
                    $item['poster'] = $gif->images->{'480w_still'}->url;
                }

                $result['gifs'][] = $item;
            }

            return $result;
        }

        /**
         * Get-or-create per-user random id for GIPHY analytics.
         * Cached in usermeta key bpbm_giphy_random_id (existing key kept for continuity).
         */
        protected function get_random_id( $user_id )
        {
            $random_id = Better_Messages()->functions->get_user_meta( $user_id, 'bpbm_giphy_random_id', true );
            if ( ! empty( $random_id ) ) {
                return $random_id;
            }

            $endpoint = add_query_arg( array(
                'api_key' => $this->api_key,
            ), 'https://api.giphy.com/v1/randomid' );

            $request = wp_remote_get( $endpoint, array( 'timeout' => 2 ) );

            if ( is_wp_error( $request ) ) {
                return '';
            }

            $response = json_decode( wp_remote_retrieve_body( $request ) );

            if ( ! isset( $response->data->random_id ) ) {
                return '';
            }

            $unique_id = $response->data->random_id;

            Better_Messages()->functions->update_user_meta( $user_id, 'bpbm_giphy_random_id', $unique_id );

            return $unique_id;
        }
    }
}
