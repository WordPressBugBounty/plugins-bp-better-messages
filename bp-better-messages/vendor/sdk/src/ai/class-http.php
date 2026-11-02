<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Http' ) ) {
    final class WordPlus_SDK_AI_Http
    {
        const KINDS = array( 'key', 'quota', 'rate', 'down', 'model', 'request', 'refused', 'empty' );

        const TRANSIENT = array( 'high demand', 'rate limit', 'rate_limit', 'too many requests', 'overloaded', 'capacity', 'temporarily unavailable',
            'server error', 'internal error', 'service unavailable', 'timeout', 'timed out', 'connection reset', 'connection refused',
            '529', '503', '502', '500', '429' );

        const RETRY = array( 'rate', 'down' );

        public static function retryable( $kind )
        {
            return in_array( (string) $kind, self::RETRY, true );
        }

        public static function retry_after( $e )
        {
            $response = $e instanceof \WordPlusSDK\Psr\Http\Message\ResponseInterface ? $e : ( is_object( $e ) && method_exists( $e, 'getResponse' ) ? $e->getResponse() : null );
            $header   = $response ? trim( (string) $response->getHeaderLine( 'Retry-After' ) ) : '';

            if ( '' === $header ) {
                return 0;
            }

            if ( is_numeric( $header ) ) {
                return max( 0, (int) ceil( (float) $header ) );
            }

            $at = strtotime( $header );

            return false === $at ? 0 : max( 0, $at - time() );
        }

        public static function unanswered( $e )
        {
            if ( ! $e instanceof \Throwable || self::status( $e ) > 0 ) {
                return false;
            }

            return $e instanceof \WordPlusSDK\GuzzleHttp\Exception\TransferException
                || $e instanceof \WordPlusSDK\Psr\Http\Client\NetworkExceptionInterface
                || $e instanceof \RuntimeException;
        }

        public static function client( $base, array $headers = array(), $provider = '', $json = true )
        {
            $options = array(
                'base_uri' => $base,
                'headers'  => $json ? array_merge( array( 'Content-Type' => 'application/json' ), $headers ) : $headers,
            );

            return new \WordPlusSDK\GuzzleHttp\Client( (array) apply_filters( 'wordplus_ai_http_options', $options, (string) $provider ) );
        }

        public static function message( $e )
        {
            $error = $e instanceof \Throwable ? $e->getMessage() : (string) $e;
            $body  = self::body( $e );

            if ( '' !== $body ) {
                $data = json_decode( $body, true );

                if ( isset( $data['error']['message'] ) && is_string( $data['error']['message'] ) ) {
                    $error = $data['error']['message'];
                } elseif ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
                    $error = $data['message'];
                } elseif ( isset( $data[0]['error']['message'] ) && is_string( $data[0]['error']['message'] ) ) {
                    $error = $data[0]['error']['message'];
                }
            }

            return $error;
        }

        public static function status( $e )
        {
            return is_object( $e ) && method_exists( $e, 'getResponse' ) && $e->getResponse() ? (int) $e->getResponse()->getStatusCode() : 0;
        }

        public static function body( $e )
        {
            static $read = null;

            if ( ! is_object( $e ) || ! method_exists( $e, 'getResponse' ) || ! $e->getResponse() ) {
                return '';
            }

            if ( null === $read ) {
                $read = new \WeakMap();
            }

            if ( ! isset( $read[ $e ] ) ) {
                $stream = $e->getResponse()->getBody();

                if ( $stream->isSeekable() ) {
                    $stream->rewind();
                }

                $read[ $e ] = (string) $stream->getContents();
            }

            return $read[ $e ];
        }

        public static function error( $kind, $message, array $data = array() )
        {
            $kind = in_array( $kind, self::KINDS, true ) ? $kind : 'request';

            return new WP_Error( 'wordplus_ai_' . $kind, (string) $message, array_merge( array( 'kind' => $kind ), $data ) );
        }

        public static function kind_of( $error )
        {
            $code = $error instanceof WP_Error ? (string) $error->get_error_code() : '';

            return 0 === strpos( $code, 'wordplus_ai_' ) && in_array( substr( $code, 12 ), self::KINDS, true ) ? substr( $code, 12 ) : 'request';
        }

        public static function kind( $status, $message )
        {
            $status = (int) $status;
            $lower  = strtolower( (string) $message );

            if ( 401 === $status || 403 === $status || ( ( false !== strpos( $lower, 'invalid' ) || false !== strpos( $lower, 'incorrect' ) ) && preg_match( '/\b(api )?key\b/', $lower ) ) ) {
                return 'key';
            }
            if ( 402 === $status || preg_match( '/quota|billing|insufficient|credit|balance/', $lower ) ) {
                return 'quota';
            }
            if ( 404 === $status || ( false !== strpos( $lower, 'model' ) && preg_match( '/not found|not supported|does not exist|deprecated|do not have access/', $lower ) ) ) {
                return 'model';
            }
            if ( 429 === $status || ( 0 === $status && preg_match( '/rate.?limit|too many requests/', $lower ) ) ) {
                return 'rate';
            }
            if ( $status >= 500 || ( 0 === $status && preg_match( '/overloaded|high demand|capacity|unavailable|server error|internal error|timeout|timed out|connection/', $lower ) ) ) {
                return 'down';
            }
            if ( preg_match( '/safety|blocked|content_filter|refus/', $lower ) ) {
                return 'refused';
            }

            return 'request';
        }

        private static function names( $lower, array $words )
        {
            foreach ( $words as $word ) {
                if ( preg_match( '/(?<![a-z0-9])' . preg_quote( $word, '/' ) . '(?![a-z0-9])/', $lower ) ) {
                    return true;
                }
            }

            return false;
        }

        public static function transient( $message )
        {
            return self::names( strtolower( (string) $message ), self::TRANSIENT );
        }

        private static function for_kind( $kind, $lower )
        {
            switch ( (string) $kind ) {
                case 'rate':
                    return _x( 'The AI service is busy right now. Please try again in a moment.', 'AI error', 'wordplus-sdk' );
                case 'down':
                    if ( preg_match( '/timeout|timed out/', $lower ) ) {
                        return _x( 'The AI service took too long to answer. Please try again.', 'AI error', 'wordplus-sdk' );
                    }
                    if ( false !== strpos( $lower, 'interrupted' ) ) {
                        return _x( 'The answer was interrupted. Please try again.', 'AI error', 'wordplus-sdk' );
                    }
                    if ( preg_match( '/overloaded|high demand|capacity/', $lower ) ) {
                        return _x( 'The AI service has a lot of requests right now. Please try again later.', 'AI error', 'wordplus-sdk' );
                    }

                    return _x( 'The AI service is not available for a moment. Please try again later.', 'AI error', 'wordplus-sdk' );
                case 'key':
                    return _x( 'The AI is not set up right. Please tell the administrator of the site.', 'AI error', 'wordplus-sdk' );
                case 'model':
                    return _x( 'The AI model chosen here is not available. Please tell the administrator of the site.', 'AI error', 'wordplus-sdk' );
                case 'quota':
                    return _x( 'The AI service has reached its limit for this site. Please tell the administrator of the site.', 'AI error', 'wordplus-sdk' );
                case 'refused':
                    return _x( 'The message could not be answered because of content rules.', 'AI error', 'wordplus-sdk' );
                case 'request':
                case 'empty':
                    return _x( 'Something went wrong while answering. Please try again.', 'AI error', 'wordplus-sdk' );
            }

            return '';
        }

        public static function friendly( $message, $kind = '' )
        {
            $lower = strtolower( (string) $message );
            $words = '' === (string) $kind ? '' : self::for_kind( $kind, $lower );

            if ( '' !== $words ) {
                return $words;
            }

            if ( preg_match( '/rate.?limit|too many requests|\b429\b/', $lower ) ) {
                return _x( 'The AI service is busy right now. Please try again in a moment.', 'AI error', 'wordplus-sdk' );
            }
            if ( preg_match( '/high demand|overloaded|capacity|\b529\b/', $lower ) ) {
                return _x( 'The AI service has a lot of requests right now. Please try again later.', 'AI error', 'wordplus-sdk' );
            }
            if ( preg_match( '/timeout|timed out/', $lower ) ) {
                return _x( 'The AI service took too long to answer. Please try again.', 'AI error', 'wordplus-sdk' );
            }
            if ( preg_match( '/\b(503|502|500)\b|server error|internal error|service unavailable|temporarily unavailable/', $lower ) ) {
                return _x( 'The AI service is not available for a moment. Please try again later.', 'AI error', 'wordplus-sdk' );
            }
            if ( ( false !== strpos( $lower, 'invalid' ) || false !== strpos( $lower, 'incorrect' ) ) && false !== strpos( $lower, 'key' ) ) {
                return _x( 'The AI is not set up right. Please tell the administrator of the site.', 'AI error', 'wordplus-sdk' );
            }
            if ( false !== strpos( $lower, 'model' ) && preg_match( '/not found|not supported|does not exist|deprecated|do not have access/', $lower ) ) {
                return _x( 'The AI model chosen here is not available. Please tell the administrator of the site.', 'AI error', 'wordplus-sdk' );
            }
            if ( preg_match( '/quota|billing|insufficient|credit|balance/', $lower ) ) {
                return _x( 'The AI service has reached its limit for this site. Please tell the administrator of the site.', 'AI error', 'wordplus-sdk' );
            }
            if ( preg_match( '/safety|blocked|content_filter/', $lower ) ) {
                return _x( 'The message could not be answered because of content rules.', 'AI error', 'wordplus-sdk' );
            }
            if ( false !== strpos( $lower, 'interrupted' ) ) {
                return _x( 'The answer was interrupted. Please try again.', 'AI error', 'wordplus-sdk' );
            }

            return _x( 'Something went wrong while answering. Please try again.', 'AI error', 'wordplus-sdk' );
        }
    }
}
