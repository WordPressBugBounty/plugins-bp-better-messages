<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Prices' ) ) {
    final class WordPlus_SDK_AI_Prices
    {
        const OPTION = 'wordplus_ai_catalogue';

        const URL = 'https://api.wordplus.cloud/api/v1/assets/ai/catalogue.json';

        const EVERY = 7 * DAY_IN_SECONDS;

        private $ai;

        private $fetched = null;

        public function __construct( WordPlus_SDK_AI $ai )
        {
            $this->ai = $ai;
        }

        public function url()
        {
            return (string) apply_filters( 'wordplus_ai_catalogue_url', self::URL );
        }

        private function stored()
        {
            $stored = get_option( self::OPTION, array() );

            return is_array( $stored ) ? $stored : array();
        }

        public function fetched()
        {
            if ( null === $this->fetched ) {
                $stored = $this->stored();
                $data   = isset( $stored['catalogue'] ) ? WordPlus_SDK_AI_Catalogue::validate( $stored['catalogue'] ) : null;

                $this->fetched = is_array( $data ) ? new WordPlus_SDK_AI_Catalogue( $data, 'fetched' ) : false;
            }

            return $this->fetched ? $this->fetched : null;
        }

        public function daily()
        {
            $sdk    = WordPlus_SDK::instance();
            $stored = $this->stored();

            if ( empty( $stored['error'] ) && isset( $stored['checked'] ) && (int) $stored['checked'] > time() - self::EVERY ) {
                return null;
            }

            foreach ( $sdk->products_on( WordPlus_SDK::CLOUD ) as $product ) {
                if ( $sdk->licence( $product )->is_active() ) {
                    return $this->check();
                }
            }

            return null;
        }

        public function check()
        {
            $before  = $this->ai->catalogue()->updated();
            $stored  = $this->stored();
            $url     = $this->url();
            $args    = array( 'timeout' => 8, 'headers' => array( 'Accept' => 'application/json', 'X-WordPlus-Plugin' => 'wordplus-sdk/' . WordPlus_SDK::instance()->version() ) );
            $valid   = function ( $body ) {
                return strlen( (string) $body ) <= WordPlus_SDK_AI_Catalogue::MAX_BYTES && is_array( WordPlus_SDK_AI_Catalogue::validate( json_decode( (string) $body, true ) ) );
            };
            $files   = $this->cloud_files();
            $answer  = $files ? WordPlus_SDK_Cloud_Files::get( $url, $args, $valid ) : wp_safe_remote_get( $url, $args );
            $code    = is_wp_error( $answer ) ? 0 : (int) wp_remote_retrieve_response_code( $answer );
            $body    = 200 !== $code ? '' : (string) wp_remote_retrieve_body( $answer );
            $data    = '' === $body || strlen( $body ) > WordPlus_SDK_AI_Catalogue::MAX_BYTES ? null : WordPlus_SDK_AI_Catalogue::validate( json_decode( $body, true ) );

            $stored['checked'] = time();

            if ( is_array( $data ) ) {
                $stored['error'] = '';

                if ( $data['updated'] > $this->ai->shipped()->updated() && ( ! $this->fetched() || $data['updated'] >= $this->fetched()->updated() ) ) {
                    $stored['catalogue'] = $data;
                    $stored['fetched']   = time();
                }
            } elseif ( in_array( $code, array( 404, 410 ), true ) ) {
                $stored['error'] = '';
            } else {
                $stored['error'] = 200 === $code ? 'invalid' : 'unreachable';
            }

            update_option( self::OPTION, $stored, false );

            $this->fetched = null;
            $this->ai->forget_catalogue();

            return array_merge( $this->state(), array( 'newer' => $this->ai->catalogue()->updated() > $before ) );
        }

        public function state()
        {
            $stored  = $this->stored();
            $fetched = $this->fetched();
            $reason  = isset( $stored['error'] ) ? (string) $stored['error'] : '';

            if ( '' !== $reason && 'invalid' !== $reason ) {
                $reason = 'unreachable';
            }

            return array(
                'updated'    => $this->ai->catalogue()->updated(),
                'source'     => $this->ai->catalogue()->source(),
                'shipped'    => $this->ai->shipped()->updated(),
                'fetched'    => $fetched ? $fetched->updated() : '',
                'checked'    => isset( $stored['checked'] ) ? (int) $stored['checked'] : 0,
                'error'      => self::error_message( $reason ),
                'error_code' => $reason,
            );
        }

        public static function error_message( $reason )
        {
            switch ( $reason ) {
                case '':
                    return '';
                case 'invalid':
                    return _x( 'WordPlus Cloud sent a price list that this site could not read, so the prices in use stay as they are. Please try again later.', 'AI prices', 'wordplus-sdk' );
                default:
                    return _x( 'WordPlus Cloud could not be reached, so the prices in use stay as they are. Please try again later. If this keeps happening, check that this site can connect to api.wordplus.cloud.', 'AI prices', 'wordplus-sdk' );
            }
        }

        public function forget()
        {
            delete_option( self::OPTION );
            $this->fetched = null;
            $this->ai->forget_catalogue();
        }

        private function cloud_files()
        {
            if ( ! class_exists( 'WordPlus_SDK_Cloud_Files' ) && is_readable( WordPlus_SDK::instance()->path( 'src/pickers/class-cloud-files.php' ) ) ) {
                require_once WordPlus_SDK::instance()->path( 'src/pickers/class-cloud-files.php' );
            }

            return class_exists( 'WordPlus_SDK_Cloud_Files' );
        }
    }
}
