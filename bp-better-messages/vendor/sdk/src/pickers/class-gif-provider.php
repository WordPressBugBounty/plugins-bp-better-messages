<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Gif_Provider' ) ) {
    abstract class WordPlus_SDK_Gif_Provider
    {
        protected $api_key = '';

        abstract public function get_provider_id();

        abstract public function get_provider_name();

        abstract public function get_supported_features();

        abstract public function get_trending( $user_id, $page = 1 );

        abstract public function search( $user_id, $query, $page = 1 );

        abstract public function get_by_id( $gif_id, $user_id );

        abstract public function check_api_key();

        public function register_usage( $user_id, $gif_id )
        {
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

        protected static function with_query( array $args, $url )
        {
            return add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $args ) ), $url );
        }

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
