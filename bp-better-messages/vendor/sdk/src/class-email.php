<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Email' ) ) {
    final class WordPlus_SDK_Email
    {
        const FONT = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";

        const LIGHT = array(
            'page'   => '#f4f4f5',
            'card'   => '#ffffff',
            'text'   => '#18181b',
            'accent' => '#2563eb',
        );

        const DARK = array(
            'page'   => '#0f0f11',
            'card'   => '#1c1c1f',
            'text'   => '#f4f4f5',
            'border' => '#2e2e33',
            'bubble' => '#2a2a2f',
            'muted'  => '#a1a1aa',
        );

        const INITIALS = array(
            array( '#ede9fe', '#5b21b6' ),
            array( '#dbeafe', '#1e40af' ),
            array( '#dcfce7', '#166534' ),
            array( '#fef3c7', '#92400e' ),
            array( '#fce7f3', '#9d174d' ),
            array( '#ccfbf1', '#115e59' ),
        );

        public function render( array $email )
        {
            $colors = $this->colors( isset( $email['colors'] ) && is_array( $email['colors'] ) ? $email['colors'] : array() );
            $rtl    = isset( $email['rtl'] ) ? (bool) $email['rtl'] : is_rtl();
            $dark   = ! empty( $email['dark'] );
            $start  = $rtl ? 'right' : 'left';
            $title  = isset( $email['title'] ) ? (string) $email['title'] : '';
            $text   = array();

            $brand = $this->brand( isset( $email['brand'] ) && is_array( $email['brand'] ) ? $email['brand'] : array(), $colors, $text );
            $card  = $this->intro( $email, $colors, $text ) . $this->blocks( isset( $email['blocks'] ) && is_array( $email['blocks'] ) ? $email['blocks'] : array(), $colors, $rtl, $text );

            if ( ! empty( $email['button']['label'] ) && ! empty( $email['button']['url'] ) ) {
                $card  .= $this->button( (string) $email['button']['label'], (string) $email['button']['url'], $colors );
                $text[] = $email['button']['label'] . ': ' . $email['button']['url'];
            }

            $footer = $this->footer( isset( $email['footer'] ) && is_array( $email['footer'] ) ? $email['footer'] : array(), $colors, $text );

            $preheader = isset( $email['preheader'] ) ? trim( (string) $email['preheader'] ) : '';
            $hidden    = '' === $preheader ? '' : '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . esc_html( $preheader ) . str_repeat( '&#847;&zwnj;&nbsp;', 40 ) . '</div>';
            $schemes   = $dark ? 'light dark' : 'light';

            $html = '<!DOCTYPE html>'
                . '<html lang="' . esc_attr( str_replace( '_', '-', determine_locale() ) ) . '" dir="' . ( $rtl ? 'rtl' : 'ltr' ) . '" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">'
                . '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="x-apple-disable-message-reformatting">'
                . '<meta name="color-scheme" content="' . $schemes . '"><meta name="supported-color-schemes" content="' . $schemes . '">'
                . '<title>' . esc_html( $title ) . '</title>'
                . '<style>' . $this->styles( $dark ) . '</style>'
                . '<!--[if mso]><style>table,td,div,a,span{font-family:Arial,sans-serif !important;}</style><![endif]-->'
                . '</head>'
                . '<body class="wplus-email-page" style="margin:0;padding:0;width:100%;background:' . $colors['page'] . ';-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">'
                . $hidden
                . '<table role="presentation" class="wplus-email-page" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . $colors['page'] . '" style="width:100%;background:' . $colors['page'] . ';">'
                . '<tr><td align="center" class="wplus-email-outer" style="padding:32px 16px;">'
                . '<!--[if mso]><table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->'
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:560px;">'
                . ( '' === $brand ? '' : '<tr><td style="padding:0 4px 16px;text-align:' . $start . ';">' . $brand . '</td></tr>' )
                . '<tr><td class="wplus-email-card wplus-email-text" bgcolor="' . $colors['card'] . '" style="background:' . $colors['card'] . ';border:1px solid ' . $colors['border'] . ';border-radius:16px;padding:32px;text-align:' . $start . ';font-family:' . self::FONT . ';font-size:15px;line-height:24px;color:' . $colors['text'] . ';">'
                . $card
                . '</td></tr>'
                . ( '' === $footer ? '' : '<tr><td class="wplus-email-footer" style="padding:20px 16px 0;text-align:center;font-family:' . self::FONT . ';font-size:12px;line-height:18px;color:' . $colors['footer'] . ';">' . $footer . '</td></tr>' )
                . '</table>'
                . '<!--[if mso]></td></tr></table><![endif]-->'
                . '</td></tr></table>'
                . '</body></html>';

            return array(
                'html' => $html,
                'text' => implode( "\n\n", array_filter( $text, 'strlen' ) ) . "\n",
            );
        }

        public function blocks_html( array $blocks, array $colors = array(), $rtl = null )
        {
            $text = array();

            return $this->blocks( $blocks, $this->colors( $colors ), null === $rtl ? is_rtl() : (bool) $rtl, $text );
        }

        public function initials( $name )
        {
            $words = preg_split( '/[\s\-_.]+/u', trim( wp_strip_all_tags( (string) $name ) ), -1, PREG_SPLIT_NO_EMPTY );

            if ( empty( $words ) ) {
                return '?';
            }

            $first = $this->first_letter( $words[0] );

            if ( count( $words ) > 1 ) {
                $first .= $this->first_letter( $words[ count( $words ) - 1 ] );
            }

            return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first, 'UTF-8' ) : strtoupper( $first );
        }

        private function first_letter( $word )
        {
            return function_exists( 'mb_substr' ) ? mb_substr( $word, 0, 1, 'UTF-8' ) : substr( $word, 0, 1 );
        }

        private function brand( array $brand, array $colors, array &$text )
        {
            $name = isset( $brand['name'] ) ? trim( (string) $brand['name'] ) : '';
            $url  = isset( $brand['url'] ) ? (string) $brand['url'] : '';
            $logo = isset( $brand['logo'] ) ? (string) $brand['logo'] : '';

            if ( '' === $name && '' === $logo ) {
                return '';
            }

            if ( '' !== $name ) {
                $text[] = $name;
            }

            if ( '' !== $logo ) {
                $width = isset( $brand['logo_width'] ) ? min( 552, max( 0, (int) $brand['logo_width'] ) ) : 0;
                $inner = '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $name ) . '"' . ( $width > 0 ? ' width="' . $width . '"' : '' ) . ' style="display:block;' . ( $width > 0 ? 'width:' . $width . 'px;' : '' ) . 'max-width:100%;height:auto;border:0;outline:none;text-decoration:none;">';
            } else {
                $inner = '<span class="wplus-email-brand" style="font-family:' . self::FONT . ';font-size:17px;line-height:24px;font-weight:700;color:' . $colors['text'] . ';">' . esc_html( $name ) . '</span>';
            }

            if ( '' === $url ) {
                return $inner;
            }

            return '<a href="' . esc_url( $url ) . '" style="text-decoration:none;color:' . $colors['text'] . ';display:inline-block;">' . $inner . '</a>';
        }

        private function intro( array $email, array $colors, array &$text )
        {
            $html = '';

            if ( ! empty( $email['greeting'] ) ) {
                $html  .= '<div class="wplus-email-muted" style="margin:0 0 6px;font-size:15px;line-height:22px;color:' . $colors['muted'] . ';">' . esc_html( $email['greeting'] ) . '</div>';
                $text[] = (string) $email['greeting'];
            }

            $heading    = isset( $email['heading'] ) ? (string) $email['heading'] : '';
            $subheading = isset( $email['subheading'] ) ? (string) $email['subheading'] : '';

            if ( '' !== $heading ) {
                $html .= '<h1 class="wplus-email-text" style="margin:0 0 ' . ( '' === $subheading ? '24px' : '6px' ) . ';font-family:' . self::FONT . ';font-size:22px;line-height:30px;font-weight:600;color:' . $colors['text'] . ';">' . esc_html( $heading ) . '</h1>';
            }

            if ( '' !== $subheading ) {
                $html .= '<div class="wplus-email-muted" style="margin:0 0 24px;font-size:14px;line-height:20px;color:' . $colors['muted'] . ';">' . esc_html( $subheading ) . '</div>';
            }

            if ( '' !== $heading || '' !== $subheading ) {
                $text[] = trim( $heading . "\n" . $subheading );
            }

            return $html;
        }

        private function blocks( array $blocks, array $colors, $rtl, array &$text )
        {
            $html = '';

            foreach ( $blocks as $block ) {
                $type = isset( $block['type'] ) ? $block['type'] : '';

                switch ( $type ) {
                    case 'message':
                        $html .= $this->message( $block, $colors, $rtl, $text );
                        break;

                    case 'faces':
                        $html .= $this->faces( $block, $rtl );
                        break;

                    case 'stats':
                        $html .= $this->stats( $block, $colors, $rtl, $text );
                        break;

                    case 'system':
                        $line   = isset( $block['text'] ) ? (string) $block['text'] : '';
                        $html  .= '<div class="wplus-email-muted" style="margin:0 0 20px;text-align:center;font-size:13px;line-height:18px;color:' . $colors['muted'] . ';">' . $this->lines( $line ) . '</div>';
                        $text[] = '— ' . $line . ' —';
                        break;

                    case 'text':
                        $line   = isset( $block['text'] ) ? (string) $block['text'] : '';
                        $html  .= '<div class="wplus-email-text" style="margin:0 0 24px;font-size:15px;line-height:24px;color:' . $colors['text'] . ';">' . $this->lines( $line ) . '</div>';
                        $text[] = $line;
                        break;

                    case 'html':
                        $markup = isset( $block['html'] ) ? wp_kses_post( (string) $block['html'] ) : '';
                        $html  .= '<div style="margin:0 0 24px;">' . $markup . '</div>';
                        $text[] = $this->plain( $markup );
                        break;
                }
            }

            return $html;
        }

        private function message( array $block, array $colors, $rtl, array &$text )
        {
            $name   = isset( $block['name'] ) ? (string) $block['name'] : '';
            $time   = isset( $block['time'] ) ? (string) $block['time'] : '';
            $lines  = isset( $block['lines'] ) && is_array( $block['lines'] ) ? $block['lines'] : array();
            $avatar = isset( $block['avatar'] ) ? (string) $block['avatar'] : '';
            $end    = $rtl ? 'left' : 'right';
            $corner = $rtl ? '18px 4px 18px 18px' : '4px 18px 18px 18px';
            $face   = $this->face( $name, $avatar );

            $bubbles = '';

            foreach ( $lines as $line ) {
                $bubbles .= '<div style="margin:0 0 4px;"><div class="wplus-email-bubble" style="display:inline-block;max-width:100%;background:' . $colors['bubble'] . ';border-radius:' . $corner . ';padding:10px 14px;font-size:15px;line-height:22px;color:' . $colors['text'] . ';text-align:' . ( $rtl ? 'right' : 'left' ) . ';word-break:break-word;overflow-wrap:anywhere;">' . $this->lines( (string) $line ) . '</div></div>';
            }

            $meta = '<strong class="wplus-email-text" style="font-weight:600;color:' . $colors['text'] . ';">' . esc_html( $name ) . '</strong>'
                . ( '' === $time ? '' : '<span class="wplus-email-muted" style="color:' . $colors['muted'] . ';"> &middot; ' . esc_html( $time ) . '</span>' );

            $text[] = $name . ( '' === $time ? '' : ' · ' . $time ) . "\n" . implode( "\n", array_map( 'strval', $lines ) );

            return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 16px;"><tr>'
                . '<td width="36" valign="top" style="width:36px;padding-' . $end . ':12px;vertical-align:top;">' . $face . '</td>'
                . '<td valign="top" style="vertical-align:top;font-family:' . self::FONT . ';">'
                . '<div style="margin:0 0 6px;font-size:14px;line-height:20px;">' . $meta . '</div>'
                . $bubbles
                . '</td></tr></table>';
        }

        private function faces( array $block, $rtl )
        {
            $people = isset( $block['people'] ) && is_array( $block['people'] ) ? array_slice( $block['people'], 0, 6 ) : array();
            $end    = $rtl ? 'left' : 'right';
            $cells  = '';

            foreach ( $people as $person ) {
                $name   = isset( $person['name'] ) ? (string) $person['name'] : '';
                $cells .= '<td width="36" valign="middle" style="width:36px;padding-' . $end . ':8px;vertical-align:middle;" title="' . esc_attr( $name ) . '">'
                    . $this->face( $name, isset( $person['avatar'] ) ? (string) $person['avatar'] : '' )
                    . '</td>';
            }

            if ( '' === $cells ) {
                return '';
            }

            return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;"><tr>' . $cells . '</tr></table>';
        }

        private function stats( array $block, array $colors, $rtl, array &$text )
        {
            $items = array();

            foreach ( isset( $block['items'] ) && is_array( $block['items'] ) ? $block['items'] : array() as $item ) {
                $value = isset( $item['value'] ) ? trim( (string) $item['value'] ) : '';
                $label = isset( $item['label'] ) ? trim( (string) $item['label'] ) : '';

                if ( '' !== $value || '' !== $label ) {
                    $items[] = array( $value, $label );
                }
            }

            if ( empty( $items ) ) {
                return '';
            }

            $title = isset( $block['title'] ) ? trim( (string) $block['title'] ) : '';
            $start = $rtl ? 'right' : 'left';
            $end   = $rtl ? 'left' : 'right';
            $width = (int) floor( 100 / count( $items ) );
            $cells = '';
            $said  = array();

            foreach ( $items as $index => $item ) {
                $gap    = ( $index > 0 ? 'padding-' . $start . ':4px;' : '' ) . ( $index < count( $items ) - 1 ? 'padding-' . $end . ':4px;' : '' );
                $cells .= '<td width="' . $width . '%" valign="top" style="width:' . $width . '%;' . $gap . 'vertical-align:top;">'
                    . '<div class="wplus-email-tile" style="background:' . $colors['bubble'] . ';border-radius:12px;padding:12px 16px;font-family:' . self::FONT . ';">'
                    . '<div class="wplus-email-text" style="font-size:22px;line-height:28px;font-weight:700;color:' . $colors['text'] . ';">' . esc_html( $item[0] ) . '</div>'
                    . '<div class="wplus-email-muted" style="font-size:13px;line-height:18px;color:' . $colors['muted'] . ';">' . esc_html( $item[1] ) . '</div>'
                    . '</div></td>';
                $said[] = $item[1] . ': ' . $item[0];
            }

            $text[] = ( '' === $title ? '' : $title . "\n" ) . implode( ' · ', $said );

            return ( '' === $title ? '' : '<div class="wplus-email-muted" style="margin:0 0 8px;font-size:13px;line-height:18px;color:' . $colors['muted'] . ';">' . esc_html( $title ) . '</div>' )
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;margin:0 0 24px;"><tr>' . $cells . '</tr></table>';
        }

        private function face( $name, $avatar )
        {
            if ( '' !== $avatar ) {
                return '<img src="' . esc_url( $avatar ) . '" width="36" height="36" alt="" style="display:block;width:36px;height:36px;border-radius:50%;border:0;outline:none;">';
            }

            $tint = self::INITIALS[ abs( crc32( $name ) ) % count( self::INITIALS ) ];

            return '<div aria-hidden="true" style="width:36px;height:36px;border-radius:50%;background:' . $tint[0] . ';color:' . $tint[1] . ';font-family:' . self::FONT . ';font-size:14px;font-weight:600;line-height:36px;text-align:center;">' . esc_html( $this->initials( $name ) ) . '</div>';
        }

        private function button( $label, $url, array $colors )
        {
            return '<table role="presentation" class="wplus-email-button-table" cellpadding="0" cellspacing="0" border="0" style="margin:12px 0 0;"><tr>'
                . '<td bgcolor="' . $colors['accent'] . '" style="background:' . $colors['accent'] . ';border-radius:10px;text-align:center;">'
                . '<a href="' . esc_url( $url ) . '" class="wplus-email-button" target="_blank" style="display:inline-block;padding:13px 26px;border:1px solid ' . $colors['accent'] . ';border-radius:10px;font-family:' . self::FONT . ';font-size:15px;line-height:20px;font-weight:600;color:' . $colors['on_accent'] . ';text-decoration:none;">' . esc_html( $label ) . '</a>'
                . '</td></tr></table>';
        }

        private function footer( array $footer, array $colors, array &$text )
        {
            $rows  = array();
            $plain = array();

            foreach ( $footer as $line ) {
                if ( ! empty( $line['links'] ) && is_array( $line['links'] ) ) {
                    $links = array();
                    $words = array();

                    foreach ( $line['links'] as $link ) {
                        if ( empty( $link['label'] ) || empty( $link['url'] ) ) {
                            continue;
                        }

                        $links[] = '<a href="' . esc_url( $link['url'] ) . '" class="wplus-email-footer" style="color:' . $colors['footer'] . ';text-decoration:underline;">' . esc_html( $link['label'] ) . '</a>';
                        $words[] = $link['label'] . ': ' . $link['url'];
                    }

                    if ( ! empty( $links ) ) {
                        $rows[]  = implode( ' &nbsp;&middot;&nbsp; ', $links );
                        $plain[] = implode( "\n", $words );
                    }
                } elseif ( isset( $line['text'] ) && '' !== trim( (string) $line['text'] ) ) {
                    $rows[]  = $this->lines( (string) $line['text'] );
                    $plain[] = (string) $line['text'];
                } elseif ( isset( $line['html'] ) && '' !== trim( (string) $line['html'] ) ) {
                    $markup  = wp_kses_post( (string) $line['html'] );
                    $rows[]  = $markup;
                    $plain[] = $this->plain( $markup );
                }
            }

            if ( empty( $rows ) ) {
                return '';
            }

            $text[] = "--\n" . implode( "\n", $plain );

            return implode( '<div style="height:6px;line-height:6px;font-size:6px;">&nbsp;</div>', $rows );
        }

        private function styles( $dark )
        {
            $css = '@media only screen and (max-width:600px){'
                . '.wplus-email-outer{padding:20px 10px !important;}'
                . '.wplus-email-card{padding:24px 20px !important;border-radius:14px !important;}'
                . '.wplus-email-button-table{width:100% !important;}'
                . '.wplus-email-button{display:block !important;text-align:center !important;}'
                . '}';

            if ( ! $dark ) {
                return $css;
            }

            $d     = self::DARK;
            $rules = '.wplus-email-page{background:' . $d['page'] . ' !important;}'
                . '.wplus-email-card{background:' . $d['card'] . ' !important;border-color:' . $d['border'] . ' !important;}'
                . '.wplus-email-text,.wplus-email-brand{color:' . $d['text'] . ' !important;}'
                . '.wplus-email-muted,.wplus-email-footer{color:' . $d['muted'] . ' !important;}'
                . '.wplus-email-bubble{background:' . $d['bubble'] . ' !important;color:' . $d['text'] . ' !important;}'
                . '.wplus-email-tile{background:' . $d['bubble'] . ' !important;}';

            $outlook = '[data-ogsb] .wplus-email-page{background:' . $d['page'] . ' !important;}'
                . '[data-ogsb] .wplus-email-card{background:' . $d['card'] . ' !important;border-color:' . $d['border'] . ' !important;}'
                . '[data-ogsb] .wplus-email-bubble,[data-ogsb] .wplus-email-tile{background:' . $d['bubble'] . ' !important;}'
                . '[data-ogsc] .wplus-email-text,[data-ogsc] .wplus-email-brand,[data-ogsc] .wplus-email-bubble{color:' . $d['text'] . ' !important;}'
                . '[data-ogsc] .wplus-email-muted,[data-ogsc] .wplus-email-footer{color:' . $d['muted'] . ' !important;}';

            return ':root{color-scheme:light dark;supported-color-schemes:light dark;}' . $css . '@media (prefers-color-scheme:dark){' . $rules . '}' . $outlook;
        }

        private function colors( array $given )
        {
            $colors = array();

            foreach ( self::LIGHT as $key => $fallback ) {
                $hex            = isset( $given[ $key ] ) ? $this->hex( $given[ $key ] ) : '';
                $colors[ $key ] = '' === $hex ? $fallback : $hex;
            }

            $colors['border']    = $this->mix( $colors['card'], $colors['text'], 0.1 );
            $colors['bubble']    = $this->mix( $colors['card'], $colors['text'], $this->luminance( $colors['card'] ) < 0.2 ? 0.1 : 0.055 );
            $colors['muted']     = $this->mix( $colors['text'], $colors['card'], 0.42 );
            $colors['footer']    = $this->mix( $colors['text'], $colors['page'], 0.45 );
            $colors['on_accent'] = $this->contrast( $colors['accent'], '#ffffff' ) >= $this->contrast( $colors['accent'], '#18181b' ) ? '#ffffff' : '#18181b';

            return $colors;
        }

        private function hex( $color )
        {
            $color = strtolower( trim( (string) $color ) );

            if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $short ) ) {
                return '#' . $short[1] . $short[1] . $short[2] . $short[2] . $short[3] . $short[3];
            }

            return preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : '';
        }

        private function rgb( $hex )
        {
            return array( hexdec( substr( $hex, 1, 2 ) ), hexdec( substr( $hex, 3, 2 ) ), hexdec( substr( $hex, 5, 2 ) ) );
        }

        private function mix( $from, $to, $amount )
        {
            $a = $this->rgb( $from );
            $b = $this->rgb( $to );

            return sprintf(
                '#%02x%02x%02x',
                (int) round( $a[0] + ( $b[0] - $a[0] ) * $amount ),
                (int) round( $a[1] + ( $b[1] - $a[1] ) * $amount ),
                (int) round( $a[2] + ( $b[2] - $a[2] ) * $amount )
            );
        }

        private function luminance( $hex )
        {
            $channels = array();

            foreach ( $this->rgb( $hex ) as $value ) {
                $value      = $value / 255;
                $channels[] = $value <= 0.03928 ? $value / 12.92 : pow( ( $value + 0.055 ) / 1.055, 2.4 );
            }

            return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        }

        private function contrast( $one, $two )
        {
            $a = $this->luminance( $one );
            $b = $this->luminance( $two );

            return ( max( $a, $b ) + 0.05 ) / ( min( $a, $b ) + 0.05 );
        }

        private function lines( $text )
        {
            return str_replace( array( "\r\n", "\r", "\n" ), '<br>', esc_html( $text ) );
        }

        private function plain( $html )
        {
            $html = preg_replace( '/<br\s*\/?>/i', "\n", $html );

            return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) );
        }
    }
}
