<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Cost' ) ) {
    final class WordPlus_SDK_AI_Cost
    {
        private $catalogue;

        public function __construct( WordPlus_SDK_AI_Catalogue $catalogue )
        {
            $this->catalogue = $catalogue;
        }

        public static function usage( $usage, $provider = '' )
        {
            $usage  = is_array( $usage ) ? $usage : array();
            $result = array(
                'inputTokens'       => 0,
                'outputTokens'      => 0,
                'thinkingTokens'    => 0,
                'cacheReadTokens'   => 0,
                'cacheWriteTokens'  => 0,
                'audioInputTokens'  => 0,
                'audioOutputTokens' => 0,
            );

            $anthropic = 'anthropic' === $provider || isset( $usage['cache_creation_input_tokens'] ) || isset( $usage['cache_read_input_tokens'] );

            if ( isset( $usage['input_tokens'] ) ) {
                $result['inputTokens']  = (int) $usage['input_tokens'];
                $result['outputTokens'] = (int) ( isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0 );

                if ( isset( $usage['cache_read_input_tokens'] ) ) {
                    $result['cacheReadTokens'] = (int) $usage['cache_read_input_tokens'];
                }
                if ( isset( $usage['cache_creation_input_tokens'] ) ) {
                    $result['cacheWriteTokens'] = (int) $usage['cache_creation_input_tokens'];
                }
                if ( isset( $usage['input_tokens_details']['cached_tokens'] ) ) {
                    $result['cacheReadTokens'] = (int) $usage['input_tokens_details']['cached_tokens'];
                }
                if ( isset( $usage['output_tokens_details']['reasoning_tokens'] ) ) {
                    $result['thinkingTokens'] = (int) $usage['output_tokens_details']['reasoning_tokens'];
                }
                if ( isset( $usage['input_tokens_details']['audio_tokens'] ) ) {
                    $result['audioInputTokens'] = (int) $usage['input_tokens_details']['audio_tokens'];
                }
                if ( isset( $usage['output_tokens_details']['audio_tokens'] ) ) {
                    $result['audioOutputTokens'] = (int) $usage['output_tokens_details']['audio_tokens'];
                }
            } elseif ( isset( $usage['promptTokenCount'] ) ) {
                $result['inputTokens']  = (int) $usage['promptTokenCount'];
                $result['outputTokens'] = (int) ( isset( $usage['candidatesTokenCount'] ) ? $usage['candidatesTokenCount'] : 0 );

                if ( isset( $usage['thoughtsTokenCount'] ) ) {
                    $result['thinkingTokens'] = (int) $usage['thoughtsTokenCount'];
                    $result['outputTokens']  += (int) $usage['thoughtsTokenCount'];
                }
                if ( isset( $usage['cachedContentTokenCount'] ) ) {
                    $result['cacheReadTokens'] = (int) $usage['cachedContentTokenCount'];
                }
            } elseif ( isset( $usage['total_tokens'] ) || isset( $usage['prompt_tokens'] ) ) {
                $result['inputTokens']  = (int) ( isset( $usage['prompt_tokens'] ) ? $usage['prompt_tokens'] : 0 );
                $result['outputTokens'] = (int) ( isset( $usage['completion_tokens'] ) ? $usage['completion_tokens'] : 0 );

                if ( isset( $usage['prompt_tokens_details']['cached_tokens'] ) ) {
                    $result['cacheReadTokens'] = (int) $usage['prompt_tokens_details']['cached_tokens'];
                }
                if ( isset( $usage['completion_tokens_details']['reasoning_tokens'] ) ) {
                    $result['thinkingTokens'] = (int) $usage['completion_tokens_details']['reasoning_tokens'];
                }
                if ( isset( $usage['prompt_tokens_details']['audio_tokens'] ) ) {
                    $result['audioInputTokens'] = (int) $usage['prompt_tokens_details']['audio_tokens'];
                }
                if ( isset( $usage['completion_tokens_details']['audio_tokens'] ) ) {
                    $result['audioOutputTokens'] = (int) $usage['completion_tokens_details']['audio_tokens'];
                }
            }

            $cached = $result['cacheReadTokens'] + $result['cacheWriteTokens'];

            if ( $anthropic && isset( $usage['input_tokens'] ) ) {
                $result['uncachedTokens'] = $result['inputTokens'];
                $result['promptTokens']   = $result['inputTokens'] + $cached;
            } else {
                $result['uncachedTokens'] = max( 0, $result['inputTokens'] - $cached );
                $result['promptTokens']   = $result['inputTokens'];
            }

            return $result;
        }

        public function web_search( $meta )
        {
            $provider = isset( $meta['provider'] ) ? (string) $meta['provider'] : '';
            $model    = isset( $meta['model'] ) ? (string) $meta['model'] : '';
            $calls    = isset( $meta['web_search_calls'] ) ? (int) $meta['web_search_calls'] : 0;

            if ( 0 === $calls && 'anthropic' === $provider && isset( $meta['usage']['server_tool_use']['web_search_requests'] ) ) {
                $calls = (int) $meta['usage']['server_tool_use']['web_search_requests'];
            }

            if ( $calls <= 0 ) {
                return array( 'calls' => 0, 'cost' => 0 );
            }

            return array( 'calls' => $calls, 'cost' => $calls * $this->catalogue->tool_price( 'web_search', $provider, $model ) );
        }

        public function images( $images )
        {
            if ( empty( $images ) || ! is_array( $images ) ) {
                return 0;
            }

            $pricing = $this->catalogue->images();
            $total   = 0;

            foreach ( $images as $image ) {
                $model   = isset( $image['model'] ) ? strtolower( (string) $image['model'] ) : 'gpt-image-1';
                $quality = isset( $image['quality'] ) ? (string) $image['quality'] : 'medium';
                $size    = isset( $image['size'] ) ? (string) $image['size'] : '1024x1024';

                if ( 'auto' === $quality ) {
                    $quality = 'medium';
                }
                if ( 'auto' === $size ) {
                    $size = '1024x1024';
                }
                if ( ! isset( $pricing[ $model ] ) ) {
                    $model = preg_replace( '/-(preview|latest)$/', '', $model );
                }

                if ( isset( $pricing[ $model ][ $quality ][ $size ] ) ) {
                    $total += $pricing[ $model ][ $quality ][ $size ];
                } elseif ( isset( $pricing[ $model ]['default'][ $size ] ) ) {
                    $total += $pricing[ $model ]['default'][ $size ];
                } elseif ( isset( $pricing[ $model ]['default'] ) ) {
                    $sizes  = $pricing[ $model ]['default'];
                    $total += reset( $sizes );
                } elseif ( isset( $pricing[ $model ]['medium']['1024x1024'] ) ) {
                    $total += $pricing[ $model ]['medium']['1024x1024'];
                }
            }

            return round( $total, 6 );
        }

        public function image_tokens( $generation )
        {
            if ( ! is_array( $generation ) || empty( $generation['model'] ) || empty( $generation['usage'] ) || ! is_array( $generation['usage'] ) ) {
                return null;
            }

            $pricing = $this->catalogue->image_tokens();
            $model   = strtolower( (string) $generation['model'] );

            if ( ! isset( $pricing[ $model ] ) ) {
                return null;
            }

            $prices = $pricing[ $model ];
            $usage  = $generation['usage'];
            $in     = isset( $usage['input_tokens_details'] ) && is_array( $usage['input_tokens_details'] ) ? $usage['input_tokens_details'] : null;
            $out    = isset( $usage['output_tokens_details'] ) && is_array( $usage['output_tokens_details'] ) ? $usage['output_tokens_details'] : null;

            $text_input   = $in ? (int) ( isset( $in['text_tokens'] ) ? $in['text_tokens'] : 0 ) : (int) ( isset( $usage['input_tokens'] ) ? $usage['input_tokens'] : 0 );
            $image_input  = $in ? (int) ( isset( $in['image_tokens'] ) ? $in['image_tokens'] : 0 ) : 0;
            $text_output  = $out ? (int) ( isset( $out['text_tokens'] ) ? $out['text_tokens'] : 0 ) : 0;
            $image_output = $out ? (int) ( isset( $out['image_tokens'] ) ? $out['image_tokens'] : 0 ) : (int) ( isset( $usage['output_tokens'] ) ? $usage['output_tokens'] : 0 );

            $cost = $text_input * $prices['textInput'] + $image_input * $prices['imageInput'] + $text_output * $prices['textOutput'] + $image_output * $prices['imageOutput'];

            return array(
                'cost'         => round( $cost / 1000000, 6 ),
                'inputTokens'  => $text_input + $image_input,
                'outputTokens' => $text_output + $image_output,
            );
        }

        public function cost( $meta, array $options = array() )
        {
            $meta     = is_array( $meta ) ? $meta : array();
            $usage    = isset( $meta['usage'] ) ? $meta['usage'] : array();
            $model    = isset( $meta['model'] ) ? (string) $meta['model'] : '';
            $provider = isset( $meta['provider'] ) ? (string) $meta['provider'] : '';
            $parsed   = self::usage( $usage, $provider );
            $own      = isset( $options['prices'] ) && is_array( $options['prices'] ) ? $options['prices'] : array();
            $day      = isset( $options['day'] ) ? (string) $options['day'] : null;

            $own_input  = isset( $own['input'] ) ? (float) $own['input'] : 0.0;
            $own_output = isset( $own['output'] ) ? (float) $own['output'] : 0.0;
            $has_own    = $own_input > 0 || $own_output > 0;
            $long       = false;

            if ( $has_own ) {
                $read   = isset( $own['cacheRead'] ) ? (float) $own['cacheRead'] : 0.0;
                $write  = isset( $own['cacheWrite'] ) ? (float) $own['cacheWrite'] : 0.0;
                $prices = array(
                    'input'      => $own_input,
                    'output'     => $own_output,
                    'cacheRead'  => $read > 0 ? $read : $own_input,
                    'cacheWrite' => $write > 0 ? $write : $own_input,
                );
            } else {
                $prices = $this->catalogue->price( $provider, $model, $day );

                if ( $prices && isset( $prices['long'] ) ) {
                    if ( $parsed['promptTokens'] > $prices['long']['over'] ) {
                        $prices = array_merge( $prices, array_diff_key( $prices['long'], array( 'over' => 0 ) ) );
                        $long   = true;
                    }
                    unset( $prices['long'] );
                }
            }

            $tier = isset( $meta['service_tier'] ) ? (string) $meta['service_tier'] : '';
            if ( $prices && ! $has_own && '' !== $tier ) {
                $times = $this->catalogue->service_tier( $provider, $model, $tier );

                foreach ( array( 'input', 'output', 'cacheRead', 'cacheWrite' ) as $field ) {
                    $prices[ $field ] *= $times;
                }
            }

            $images      = isset( $meta['images_generated'] ) && is_array( $meta['images_generated'] ) ? $meta['images_generated'] : array();
            $image_usage = $this->image_tokens( isset( $meta['image_generation_usage'] ) ? $meta['image_generation_usage'] : null );
            if ( null !== $image_usage && 0 === $image_usage['outputTokens'] && ! empty( $images ) ) {
                $image_usage = null;
            }
            $image_cost = null !== $image_usage ? $image_usage['cost'] : $this->images( $images );

            $file_search_calls = isset( $meta['file_search_calls'] ) ? (int) $meta['file_search_calls'] : 0;
            $file_search_cost  = $file_search_calls * $this->catalogue->tool_price( 'file_search', $provider, $model );
            $web_search        = $this->web_search( $meta );
            $tool_cost         = $web_search['cost'] + $file_search_cost;

            $cost = array(
                'inputTokens'       => $parsed['inputTokens'],
                'outputTokens'      => $parsed['outputTokens'],
                'thinkingTokens'    => $parsed['thinkingTokens'],
                'cacheReadTokens'   => $parsed['cacheReadTokens'],
                'cacheWriteTokens'  => $parsed['cacheWriteTokens'],
                'audioInputTokens'  => $parsed['audioInputTokens'],
                'audioOutputTokens' => $parsed['audioOutputTokens'],
                'promptTokens'      => $parsed['promptTokens'],
                'imagesCount'       => count( $images ),
                'imageCost'         => $image_cost,
                'model'             => $model,
                'provider'          => $provider,
                'pricesFrom'        => $has_own ? 'own' : $this->catalogue->updated(),
            );

            if ( null !== $image_usage ) {
                $cost['imageInputTokens']  = $image_usage['inputTokens'];
                $cost['imageOutputTokens'] = $image_usage['outputTokens'];
            }
            if ( $web_search['calls'] > 0 ) {
                $cost['webSearchCalls'] = $web_search['calls'];
                $cost['webSearchCost']  = round( $web_search['cost'], 6 );
            }
            if ( $file_search_calls > 0 ) {
                $cost['fileSearchCalls'] = $file_search_calls;
                $cost['fileSearchCost']  = round( $file_search_cost, 6 );
            }
            if ( 'flex' === $tier || 'priority' === $tier || 'fast' === $tier ) {
                $cost['serviceTier'] = $tier;
            }
            if ( $long ) {
                $cost['longPrompt'] = true;
            }

            if ( $prices ) {
                $read          = $parsed['cacheReadTokens'];
                $write         = $parsed['cacheWriteTokens'];
                $audio_input   = $parsed['audioInputTokens'];
                $audio_output  = $parsed['audioOutputTokens'];
                $regular_input = $parsed['uncachedTokens'];

                if ( ( $audio_input > 0 || $audio_output > 0 ) && isset( $prices['audioInput'] ) ) {
                    $text_input  = max( 0, $regular_input - $audio_input );
                    $text_output = max( 0, $parsed['outputTokens'] - $audio_output );
                    $input_cost  = ( $text_input * $prices['input'] + $read * $prices['cacheRead'] + $write * $prices['cacheWrite'] + $audio_input * $prices['audioInput'] ) / 1000000;
                    $output_cost = ( $text_output * $prices['output'] + $audio_output * ( isset( $prices['audioOutput'] ) ? $prices['audioOutput'] : $prices['output'] ) ) / 1000000;
                } else {
                    $input_cost  = ( $regular_input * $prices['input'] + $read * $prices['cacheRead'] + $write * $prices['cacheWrite'] ) / 1000000;
                    $output_cost = ( $parsed['outputTokens'] * $prices['output'] ) / 1000000;
                }

                $savings = ( $parsed['promptTokens'] * $prices['input'] ) / 1000000 - $input_cost;

                $cost['inputCost']    = round( $input_cost, 6 );
                $cost['outputCost']   = round( $output_cost, 6 );
                $cost['toolCost']     = round( $tool_cost, 6 );
                $cost['totalCost']    = round( $input_cost + $output_cost + $image_cost + $tool_cost, 6 );
                $cost['cacheSavings'] = round( max( 0, $savings ), 6 );
                $cost['prices']       = $prices;
            } elseif ( $tool_cost > 0 || $image_cost > 0 ) {
                $cost['toolCost']  = round( $tool_cost, 6 );
                $cost['totalCost'] = round( $image_cost + $tool_cost, 6 );
            }

            return $cost;
        }

        public static function totals( $costs )
        {
            $fields = array( 'inputTokens', 'outputTokens', 'thinkingTokens', 'cacheReadTokens', 'cacheWriteTokens', 'imagesCount' );
            $totals = array_fill_keys( $fields, 0 ) + array( 'imageCost' => 0.0 );
            $total  = null;

            foreach ( $costs as $cost ) {
                $cost = is_string( $cost ) ? json_decode( $cost, true ) : $cost;
                if ( ! is_array( $cost ) ) {
                    continue;
                }

                foreach ( $fields as $field ) {
                    $totals[ $field ] += (int) ( isset( $cost[ $field ] ) ? $cost[ $field ] : 0 );
                }
                $totals['imageCost'] += (float) ( isset( $cost['imageCost'] ) ? $cost['imageCost'] : 0 );

                if ( isset( $cost['totalCost'] ) ) {
                    $total = (float) $total + (float) $cost['totalCost'];
                }
            }

            $totals['imageCost'] = round( $totals['imageCost'], 6 );

            if ( null !== $total ) {
                $totals['totalCost'] = round( $total, 6 );
            }

            return $totals;
        }
    }
}
