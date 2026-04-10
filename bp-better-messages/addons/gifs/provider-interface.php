<?php

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Better_Messages_Gif_Provider' ) ) {
    abstract class Better_Messages_Gif_Provider
    {
        protected $api_key = '';

        /**
         * @return string Provider ID ('giphy', 'klipy')
         */
        abstract public function get_provider_id();

        /**
         * @return string Human-readable provider name
         */
        abstract public function get_provider_name();

        /**
         * @return string[] Array of supported feature IDs (e.g. 'search', 'trending', 'ads')
         */
        abstract public function get_supported_features();

        /**
         * Fetch trending GIFs for the current user.
         *
         * @param int $user_id Current user id (may be negative for guests).
         * @param int $page    1-indexed page number.
         * @return array {
         *   pagination: { total_count: int, count: int, offset: int },
         *   gifs: array of [ 'id' => string, 'url' => string, 'mp4' => string, 'poster' => string ]
         * }
         */
        abstract public function get_trending( $user_id, $page = 1 );

        /**
         * Search GIFs by query string.
         *
         * @param int    $user_id Current user id.
         * @param string $query   Search text.
         * @param int    $page    1-indexed page number.
         * @return array Same shape as get_trending().
         */
        abstract public function search( $user_id, $query, $page = 1 );

        /**
         * Fetch a single GIF by its provider-specific id.
         *
         * @param string $gif_id
         * @param int    $user_id
         * @return array|false Associative array with at least 'id', 'mp4', 'poster' — or false on failure.
         */
        abstract public function get_by_id( $gif_id, $user_id );

        /**
         * Validate the currently configured API key.
         * Should update the provider-specific error option on failure and delete it on success.
         */
        abstract public function check_api_key();

        /**
         * Optional: register that a user sent a specific GIF (for analytics / de-duping).
         */
        public function register_usage( $user_id, $gif_id )
        {
            // no-op by default
        }

        public function get_api_key()
        {
            return $this->api_key;
        }

        public function set_api_key( $key )
        {
            $this->api_key = $key;
        }

        public function supports( $feature )
        {
            return in_array( $feature, $this->get_supported_features(), true );
        }

        /**
         * Returns a normalized empty response with an optional error message.
         */
        protected function empty_response( $error = '' )
        {
            $response = array(
                'pagination' => (object) array(
                    'total_count' => 0,
                    'count'       => 0,
                    'offset'      => 0,
                ),
                'gifs'       => array(),
            );

            if ( ! empty( $error ) ) {
                $response['error'] = $error;
            }

            return $response;
        }
    }
}
