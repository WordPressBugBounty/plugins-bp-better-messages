<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Html_Tag_Processor' ) ):

    class Better_Messages_Html_Tag_Processor
    {
        private $html;
        private $offset = 0;
        private $in_tag = false;
        private $tag_name = '';
        private $tag_start = -1;
        private $attributes = array();
        private $inserted = array();

        private static $rawtext = array( 'script', 'style', 'textarea', 'title', 'iframe', 'noembed', 'noframes', 'xmp' );

        public function __construct( $html )
        {
            $this->html = (string) $html;
        }

        public function next_tag( $query = null )
        {
            $wanted = '';

            if ( is_string( $query ) ) {
                $wanted = strtolower( $query );
            } else if ( is_array( $query ) && isset( $query[ 'tag_name' ] ) ) {
                $wanted = strtolower( $query[ 'tag_name' ] );
            }

            $length = strlen( $this->html );

            while ( $this->offset < $length ) {
                $at = strpos( $this->html, '<', $this->offset );

                if ( $at === false ) {
                    break;
                }

                if ( substr( $this->html, $at, 4 ) === '<!--' ) {
                    $close = $this->comment_end( $at );

                    if ( $close === false ) {
                        break;
                    }

                    $this->offset = $close;
                    continue;
                }

                $lead = substr( $this->html, $at, 2 );

                $bogus = $lead === '<!' || $lead === '<?';

                if ( ! $bogus && $lead === '</' ) {
                    $bogus = ! isset( $this->html[ $at + 2 ] ) || ! ctype_alpha( $this->html[ $at + 2 ] );
                }

                if ( $bogus ) {
                    $close = strpos( $this->html, '>', $at + 2 );

                    if ( $close === false ) {
                        break;
                    }

                    $this->offset = $close + 1;
                    continue;
                }

                $tag = $this->parse_tag_at( $at );

                if ( $tag === false ) {
                    $this->offset = $at + 1;
                    continue;
                }

                if ( $tag[ 'incomplete' ] ) {
                    break;
                }

                $this->offset = $tag[ 'next' ];

                if ( $tag[ 'closing' ] ) {
                    continue;
                }

                if ( $wanted !== '' && $wanted !== $tag[ 'name' ] ) {
                    continue;
                }

                $this->in_tag     = true;
                $this->tag_name   = $tag[ 'name' ];
                $this->tag_start  = $tag[ 'start' ];
                $this->attributes = $tag[ 'attributes' ];
                $this->inserted   = array();

                return true;
            }

            $this->in_tag     = false;
            $this->tag_name   = '';
            $this->tag_start  = -1;
            $this->attributes = array();
            $this->inserted   = array();

            return false;
        }

        public function get_tag()
        {
            return $this->in_tag ? strtoupper( $this->tag_name ) : null;
        }

        public function get_attribute( $name )
        {
            if ( ! $this->in_tag ) {
                return null;
            }

            $key = strtolower( $name );

            if ( ! isset( $this->attributes[ $key ] ) ) {
                return null;
            }

            $value = $this->attributes[ $key ][ 'value' ];

            if ( $value === true ) {
                return true;
            }

            return html_entity_decode( $value, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        }

        public function get_attribute_names_with_prefix( $prefix )
        {
            if ( ! $this->in_tag ) {
                return null;
            }

            $prefix = strtolower( (string) $prefix );
            $names  = array();

            foreach ( array_keys( $this->attributes ) as $name ) {
                if ( $prefix === '' || strpos( $name, $prefix ) === 0 ) {
                    $names[] = $name;
                }
            }

            return $names;
        }

        public function set_attribute( $name, $value )
        {
            if ( ! $this->in_tag ) {
                return false;
            }

            $name_length = strlen( $name );

            if ( $name_length === 0
                || strcspn( $name, "\"'>&</ =" ) !== $name_length
                || strcspn( $name, "\x00\x01\x02\x03\x04\x05\x06\x07\x08\x09\x0A\x0B\x0C\x0D\x0E\x0F\x10\x11\x12\x13\x14\x15\x16\x17\x18\x19\x1A\x1B\x1C\x1D\x1E\x1F" ) !== $name_length ) {
                return false;
            }

            if ( $value === false ) {
                return $this->remove_attribute( $name );
            }

            $key = strtolower( $name );

            if ( $value === true ) {
                $updated = $name;
            } else {
                if ( in_array( $key, wp_kses_uri_attributes(), true ) ) {
                    $escaped = esc_url( $value );
                } else {
                    $escaped = strtr( (string) $value, array(
                        '<'  => '&lt;',
                        '>'  => '&gt;',
                        '&'  => '&amp;',
                        '"'  => '&quot;',
                        "'"  => '&apos;',
                    ) );
                }

                if ( $escaped === '' && $value !== '' ) {
                    return false;
                }

                $updated = $name . '="' . $escaped . '"';
            }

            if ( isset( $this->attributes[ $key ] ) && ! isset( $this->inserted[ $key ] ) ) {
                $start      = $this->attributes[ $key ][ 'start' ];
                $end        = $this->attributes[ $key ][ 'end' ];
                $this->html = substr_replace( $this->html, $updated, $start, $end - $start );
            } else {
                $this->rewrite_inserted_block( $key, ' ' . $updated );
            }

            return $this->refresh_current_tag();
        }

        public function remove_attribute( $name )
        {
            if ( ! $this->in_tag ) {
                return false;
            }

            $key = strtolower( $name );

            if ( ! isset( $this->attributes[ $key ] ) ) {
                return false;
            }

            if ( isset( $this->inserted[ $key ] ) ) {
                $this->rewrite_inserted_block( $key, null );

                return $this->refresh_current_tag();
            }

            $start = $this->attributes[ $key ][ 'start' ];
            $end   = $this->attributes[ $key ][ 'end' ];

            while ( $start > $this->tag_start && strpos( " \t\r\n\f", $this->html[ $start - 1 ] ) !== false ) {
                $start--;
            }

            $this->html = substr_replace( $this->html, '', $start, $end - $start );

            return $this->refresh_current_tag();
        }

        public function get_updated_html()
        {
            return $this->html;
        }

        private function rewrite_inserted_block( $key, $text )
        {
            $start  = $this->tag_start + 1 + strlen( $this->tag_name );
            $length = 0;

            foreach ( $this->inserted as $existing ) {
                $length += strlen( $existing );
            }

            if ( $text === null ) {
                unset( $this->inserted[ $key ] );
            } else {
                $this->inserted[ $key ] = $text;
            }

            $texts = array_values( $this->inserted );

            usort( $texts, 'strcmp' );

            $this->html = substr_replace( $this->html, implode( '', $texts ), $start, $length );
        }

        private function refresh_current_tag()
        {
            $tag = $this->parse_tag_at( $this->tag_start );

            if ( $tag === false || $tag[ 'incomplete' ] ) {
                $this->in_tag = false;

                return false;
            }

            $this->tag_name   = $tag[ 'name' ];
            $this->attributes = $tag[ 'attributes' ];
            $this->offset     = $tag[ 'next' ];

            return true;
        }

        private function comment_end( $at )
        {
            $length = strlen( $this->html );
            $closer = $at + 4;
            $dashes = strspn( $this->html, '-', $closer );

            if ( isset( $this->html[ $closer + $dashes ] ) && $this->html[ $closer + $dashes ] === '>' ) {
                return $closer + $dashes + 1;
            }

            while ( $closer < $length ) {
                $closer = strpos( $this->html, '--', $closer );

                if ( $closer === false ) {
                    return false;
                }

                if ( $closer + 2 < $length && $this->html[ $closer + 2 ] === '>' ) {
                    return $closer + 3;
                }

                if ( $closer + 3 < $length && $this->html[ $closer + 2 ] === '!' && $this->html[ $closer + 3 ] === '>' ) {
                    return $closer + 4;
                }

                $closer++;
            }

            return false;
        }

        private function parse_tag_at( $at )
        {
            if ( ! isset( $this->html[ $at ] ) || $this->html[ $at ] !== '<' ) {
                return false;
            }

            $length  = strlen( $this->html );
            $i       = $at + 1;
            $closing = false;

            if ( isset( $this->html[ $i ] ) && $this->html[ $i ] === '/' ) {
                $closing = true;
                $i++;
            }

            if ( ! isset( $this->html[ $i ] ) || ! ctype_alpha( $this->html[ $i ] ) ) {
                return false;
            }

            $name_length = 1 + strcspn( $this->html, " \t\f\r\n/>", $i + 1 );
            $name        = strtolower( substr( $this->html, $i, $name_length ) );
            $i          += $name_length;

            $attributes = array();
            $terminated = false;

            while ( $i < $length ) {
                $i += strspn( $this->html, " \t\r\n\f", $i );

                if ( $i >= $length ) {
                    break;
                }

                $char = $this->html[ $i ];

                if ( $char === '>' ) {
                    $i++;
                    $terminated = true;
                    break;
                }

                if ( $char === '/' ) {
                    $i++;
                    continue;
                }

                $name_length = $char === '='
                    ? 1 + strcspn( $this->html, "=/> \t\f\r\n", $i + 1 )
                    : strcspn( $this->html, "=/> \t\f\r\n", $i );

                if ( $name_length === 0 ) {
                    $i++;
                    continue;
                }

                $attribute_name  = strtolower( substr( $this->html, $i, $name_length ) );
                $attribute_start = $i;
                $i              += $name_length;
                $attribute_end   = $i;
                $value           = true;

                $after = $i + strspn( $this->html, " \t\r\n\f", $i );

                if ( isset( $this->html[ $after ] ) && $this->html[ $after ] === '=' ) {
                    $after++;
                    $after += strspn( $this->html, " \t\r\n\f", $after );

                    if ( ! isset( $this->html[ $after ] ) ) {
                        $i = $length;
                        break;
                    }

                    $quote = $this->html[ $after ];

                    if ( $quote === '"' || $quote === "'" ) {
                        $close = strpos( $this->html, $quote, $after + 1 );

                        if ( $close === false ) {
                            $i = $length;
                            break;
                        }

                        $value         = substr( $this->html, $after + 1, $close - $after - 1 );
                        $i             = $close + 1;
                        $attribute_end = $i;
                    } else {
                        $span          = strcspn( $this->html, " \t\r\n\f>", $after );
                        $value         = substr( $this->html, $after, $span );
                        $i             = $after + $span;
                        $attribute_end = $i;
                    }
                }

                if ( isset( $attributes[ $attribute_name ] ) ) {
                    continue;
                }

                $attributes[ $attribute_name ] = array(
                    'value' => $value,
                    'start' => $attribute_start,
                    'end'   => $attribute_end,
                );
            }

            if ( ! $terminated ) {
                return array(
                    'name'       => $name,
                    'closing'    => $closing,
                    'incomplete' => true,
                    'start'      => $at,
                    'end'        => $length,
                    'next'       => $length,
                    'attributes' => $attributes,
                );
            }

            $end        = $i;
            $next       = $end;
            $incomplete = false;

            if ( ! $closing && in_array( $name, self::$rawtext, true ) ) {
                if ( preg_match( '~</' . $name . '(?=[\s/>])[^>]*>~i', $this->html, $close, PREG_OFFSET_CAPTURE, $end ) ) {
                    $next = $close[ 0 ][ 1 ] + strlen( $close[ 0 ][ 0 ] );
                } else {
                    $incomplete = true;
                }
            }

            return array(
                'name'       => $name,
                'closing'    => $closing,
                'incomplete' => $incomplete,
                'start'      => $at,
                'end'        => $end,
                'next'       => $next,
                'attributes' => $attributes,
            );
        }
    }

endif;

if ( ! function_exists( 'better_messages_html_tag_processor' ) ):

    function better_messages_html_tag_processor( $html )
    {
        if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
            return new WP_HTML_Tag_Processor( $html );
        }

        return new Better_Messages_Html_Tag_Processor( $html );
    }

endif;
