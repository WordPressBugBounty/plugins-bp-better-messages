<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Stream' ) ) {
    final class WordPlus_SDK_AI_Stream
    {
        public static function pump( $answer, array $options = array() )
        {
            $options = array_merge( array(
                'url'      => '',
                'headers'  => array(),
                'seal'     => null,
                'on_tick'  => null,
                'friendly' => array( 'WordPlus_SDK_AI_Http', 'friendly' ),
                'drain'    => 5,
            ), $options );

            $result = array( 'status' => '', 'text' => '', 'meta' => array(), 'error' => '', 'kind' => '', 'data' => array() );

            if ( '' === (string) $options['url'] ) {
                foreach ( $answer as $part ) {
                    if ( self::take( $part, $result, $options ) ) {
                        return $result;
                    }
                }

                return self::interrupted( $result );
            }

            $loop    = \WordPlusSDK\React\EventLoop\Loop::get();
            $browser = new \WordPlusSDK\React\Http\Browser( null, $loop );
            $stream  = new \WordPlusSDK\React\Stream\ThroughStream();
            $pending = array();
            $request = null;
            $settled = false;

            $write = function ( $piece ) use ( $stream, &$pending, $options ) {
                try {
                    if ( is_callable( $options['seal'] ) ) {
                        $piece = (string) call_user_func( $options['seal'], (string) $piece );
                    }

                    if ( count( $stream->listeners( 'data' ) ) > 0 ) {
                        foreach ( $pending as $held ) {
                            $stream->write( $held );
                        }
                        $pending = array();
                        $stream->write( $piece );
                    } else {
                        $pending[] = $piece;
                    }
                } catch ( \Throwable $e ) {
                }
            };

            $finish = function () use ( $loop, $stream, &$pending, &$request, &$settled, $options ) {
                try {
                    if ( count( $stream->listeners( 'data' ) ) > 0 ) {
                        foreach ( $pending as $held ) {
                            $stream->write( $held );
                        }
                    }
                    $pending = array();
                    $stream->end();
                } catch ( \Throwable $e ) {
                }

                if ( $settled || null === $request ) {
                    $loop->stop();
                    return;
                }

                $timer = $loop->addTimer( max( 0, (float) $options['drain'] ), function () use ( $loop ) {
                    $loop->stop();
                } );

                $request->then( function () use ( $loop, $timer ) {
                    $loop->cancelTimer( $timer );
                    $loop->stop();
                }, function () use ( $loop, $timer ) {
                    $loop->cancelTimer( $timer );
                    $loop->stop();
                } );
            };

            $answer = $answer instanceof \Iterator ? $answer : new \ArrayIterator( is_array( $answer ) ? $answer : iterator_to_array( $answer ) );
            $answer->rewind();
            $first = true;

            $step = function () use ( &$step, $loop, $answer, &$first, &$result, $options, $write, $finish ) {
                if ( ! $first ) {
                    $answer->next();
                }
                $first = false;

                if ( ! $answer->valid() ) {
                    self::interrupted( $result );
                    $write( call_user_func( $options['friendly'], $result['error'], $result['kind'] ) );
                    $finish();
                    return;
                }

                $part = $answer->current();

                if ( self::take( $part, $result, $options ) ) {
                    if ( 'failed' === $result['status'] ) {
                        $write( call_user_func( $options['friendly'], $result['error'], $result['kind'] ) );
                    }
                    $finish();
                    return;
                }

                if ( is_string( $part ) ) {
                    $write( $part );
                }

                $loop->futureTick( $step );
            };

            $request = $browser->post( (string) $options['url'], (array) $options['headers'], $stream );
            $request->then( function () use ( &$settled ) {
                $settled = true;
            }, function () use ( &$settled ) {
                $settled = true;
            } );

            $loop->futureTick( $step );
            $loop->run();

            return $result;
        }

        private static function take( $part, array &$result, array $options )
        {
            if ( is_callable( $options['on_tick'] ) ) {
                call_user_func( $options['on_tick'], $part );
            }

            if ( is_string( $part ) ) {
                $result['text'] .= $part;

                return false;
            }

            if ( is_array( $part ) && isset( $part[0] ) && 'finish' === $part[0] ) {
                $result['status'] = 'finished';
                $result['meta']   = isset( $part[1] ) && is_array( $part[1] ) ? $part[1] : array();

                return true;
            }

            if ( is_array( $part ) && isset( $part[0] ) && 'error' === $part[0] ) {
                $result['status'] = 'failed';
                $result['error']  = isset( $part[1] ) ? (string) $part[1] : '';
                $result['kind']   = isset( $part[2] ) ? (string) $part[2] : WordPlus_SDK_AI_Http::kind( 0, $result['error'] );
                $result['data']   = isset( $part[3] ) && is_array( $part[3] ) ? $part[3] : array();

                return true;
            }

            return false;
        }

        private static function interrupted( array &$result )
        {
            $result['status'] = 'failed';
            $result['error']  = 'response interrupted: the reply ended without a result';
            $result['kind']   = 'down';

            return $result;
        }
    }
}
