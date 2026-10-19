<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Urls' ) ):

    class Better_Messages_Urls
    {

        public $user_agent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; de; rv:1.9.2.3) Gecko/20100401 Firefox/3.6.3';

        public static function instance()
        {

            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Urls();
            }

            return $instance;
        }


        public function __construct()
        {
            add_filter( 'bp_better_messages_after_format_message', array( $this, 'nice_links' ), 100, 4 );
        }

        public function check_if_url_is_file( $url ){
            $parsed_url = parse_url( $url );

            if( isset( $parsed_url['path'] ) ) {
                $pathInfo = pathinfo( $parsed_url['path'] );
                if( isset( $pathInfo['extension'] ) ){
                    return true;
                }
            }

            return false;
        }

        public function is_url_allowed( $url ){
            $valid_url = esc_url_raw( $url, ['http', 'https'] );

            if( empty( $valid_url ) ) return false;

            $valid_url = wp_http_validate_url( $valid_url );

            if( ! $valid_url ) return false;

            $parts = parse_url( $valid_url );

            if( isset($parts['port']) ){
                if( $parts['port'] !== 443 && $parts['port'] !== 80 ){
                    return false;
                }
            }

            $home = parse_url( get_option( 'home' ), PHP_URL_HOST );

            if ( is_string( $home ) && strtolower( $home ) === strtolower( $parts['host'] ) ) {
                return true;
            }

            $host = trim( $parts['host'], '.' );
            $ip   = filter_var( $host, FILTER_VALIDATE_IP ) ? $host : gethostbyname( $host );

            $public_only = defined( 'FILTER_FLAG_GLOBAL_RANGE' ) ? FILTER_FLAG_GLOBAL_RANGE : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

            return filter_var( $ip, FILTER_VALIDATE_IP, $public_only ) !== false;
        }

        public function nice_links( $message, $message_id, $context, $user_id )
        {
            if ( $context !== 'stack' ) return $message;
            global $processedUrls;

            static $formatted_cache = array();

            $cache_key = $message_id . ':' . md5( $message );

            if ( isset( $formatted_cache[ $cache_key ] ) ) {
                return $formatted_cache[ $cache_key ];
            }

            $links = array();
            $wrap_token = function( $url ) use ( &$links ) {
                return '<' . array_push( $links, '<a target="_blank" href="' . $url . '">' . $url . '</a>' ) . '>';
            };

            $message = preg_replace_callback('~(<a\s.*?>.*?</a>|<.*?>)~is', function ($match) use (&$links) { return '<' . array_push($links, $match[1]) . '>'; }, $message);

            $message = preg_replace_callback('~```[\s\S]*?```~', function ($match) use (&$links) { return '<' . array_push($links, $match[0]) . '>'; }, $message);

            $message = preg_replace_callback('~`[^`\n]+`~', function ($match) use (&$links) { return '<' . array_push($links, $match[0]) . '>'; }, $message);

            $message = preg_replace_callback('~!?\[.*?\]\(.*?\)~sU', function ($match) use (&$links) { return '<' . array_push($links, $match[0]) . '>'; }, $message);

            $regex = '/\b(https?|ftp|file):\/\/[-A-Z0-9+&@#\/%?=~_|$!:,.;]*[A-Z0-9+&@#\/%=~_|$]/i';
            preg_match_all( $regex, $message, $urls );

            if( ! empty( $urls[0] ) ){
                $urls[0] = array_unique($urls[0]);
                usort( $urls[0], function( $a, $b ) { return strlen( $b ) - strlen( $a ); } );
            }

            foreach ( $urls[ 0 ] as $matched_url ) {
                $url = strip_tags(html_entity_decode(esc_url( $matched_url )));
                $_url = esc_url_raw($url);

                if( ! $this->is_url_allowed( $_url ) ){
                    continue;
                }

                if ( ! isset( $processedUrls[ $message_id ] )
                        || !in_array( $_url, $processedUrls[ $message_id ] )
                        || !in_array( $url, $processedUrls[ $message_id ] )
                ) {

                    $url_md5 = md5( $url );

                    if( Better_Messages()->settings['enableNiceLinks'] === '1' ) {
                        $cache = Better_Messages()->functions->get_message_meta($message_id, 'url_info_' . $url_md5, true);

                        if (!empty($cache)) {
                            $link = $this->render_nice_link($message_id, $url, $cache);
                        } else {

                            if ($this->check_if_url_is_file($url)) {
                                $info = false;
                            } else {
                                $info = $this->fetch_meta_tags($url);
                            }

                            if ($info !== false) {
                                Better_Messages()->functions->update_message_meta($message_id, 'url_info_' . $url_md5, $info);
                                $link = $this->render_nice_link($message_id, $url, $info);
                            } else {
                                Better_Messages()->functions->update_message_meta($message_id, 'url_info_' . $url_md5, '404');
                                $link = $this->render_nice_link($message_id, $url, '404');
                            }

                        }
                    }

                    if( Better_Messages()->settings['oEmbedEnable'] === '1' ){

                        $video_providers = [
                            'youtube',
                            'vimeo',
                            'videopress',
                            'dailymotion',
                            'kickstarter'
                        ];

                        $hide_link = [
                            'twitter',
                        ];

                        $excluded_oembed = [
                            'facebook',
                            'giphy',
                            'reverbnation',
                            'twitter',
                            'cloudup',
                            'imgur',
                            'instagram',
                            'issuu',
                            'reddit',
                            'plugins',
                        ];

                        $is_excluded = false;
                        foreach( $excluded_oembed as $item ){
                            if( strpos( $url, $item ) !== false ){
                                $is_excluded = true;
                                break;
                            }
                        }

                        $oembed = new WP_oEmbed();
                        if( $is_excluded ){
                            $embed = false;
                        } else {
                            $cache = Better_Messages()->functions->get_message_meta( $message_id, 'media_info_' . $url_md5, true );
                            if ( $cache === 'none' ) {
                                $embed  = false;
                            } else if ( ! empty( $cache ) ) {
                                $embed  = $cache;
                            } else {
                                $embed  = $oembed->get_data( $url, ['height' => '200', 'discover' => false] );
                                Better_Messages()->functions->update_message_meta( $message_id, 'media_info_' . $url_md5, ( $embed === false ) ? 'none' : $embed );
                            }
                        }

                        if( $embed !== false ){
                            $html = false;
                            $privacy_embeds = Better_Messages()->settings['privacyEmbeds'] === '1';

                            if( isset($embed->provider_name) && in_array( strtolower($embed->provider_name), $video_providers ) ){
                                if ( $privacy_embeds ) {
                                    $html = $this->render_privacy_embed( $url, $embed );
                                } else {
                                    $embed_html = $this->apply_embed_playback_params( $embed->html, strtolower( $embed->provider_name ), $embed );
                                    $html = '<span class="bm-iframe-container">' . $embed_html . '</span>';
                                }
                            } else if( isset($embed->html) ) {
                                if ( ! $privacy_embeds ) {
                                    $html = $embed->html;
                                }
                            }

                            if( isset($embed->provider_name) && in_array( strtolower($embed->provider_name), $hide_link ) ){
                                $link = $html;
                            } else if( isset( $link ) )  {
                                $link = $html . $link;
                            } else {
                                $link = $html;
                            }

                            if( $html ) {
                                $message = str_replace( $matched_url, '', $message );
                            } else {
                                $message = str_replace( $matched_url, $wrap_token( $_url ), $message );
                            }
                        } else {
                            $message = str_replace( $matched_url, $wrap_token( $_url ), $message );
                        }
                    } else {
                        $message = str_replace( $matched_url, $wrap_token( $_url ), $message );
                    }

                    if( isset( $link ) ) {
                        $processedUrls[$message_id][] = $link;

                        $message .= '%%link_' . count($processedUrls[$message_id]) . '%%';

                    }
                }
            }

            $message = preg_replace_callback('/<(\d+)>/', function ($match) use (&$links) { return $links[$match[1] - 1]; }, $message);

            if ( count( $formatted_cache ) > 200 ) {
                $formatted_cache = array();
            }

            $formatted_cache[ $cache_key ] = $message;

            return $message;
        }


        public function render_nice_link( $message_id, $url, $info )
        {
            if ( $info == '404' || empty( $info[ 'title' ] ) ) {
                return '';
            }
            ob_start();
            ?>
            <a href="<?php echo $url; ?>" target="_blank" class="url-wrap">
                <?php
?>
                <span class="url-description">
                    <span class="url-title"><?php echo esc_attr( $info[ 'title' ] ); ?></span>
                    <span class="url-site"><?php echo esc_attr( $info[ 'site' ] ); ?></span>
                </span>
            </a>
            <?php
            return str_replace("\n", "", ob_get_clean());
        }

        public function fetch_meta_tags( $url )
        {

            $args = [
                'user-agent'  => $this->user_agent,
                'headers'     => [],
                'redirection' => 0
            ];

            if( isset ( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
                $args['headers']['Accept-Language'] = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
            }

            $location = $url;

            for ( $hop = 0; $hop <= 5; $hop++ ) {
                $response = wp_safe_remote_get( $location, $args );

                if ( is_wp_error( $response ) ) {
                    return false;
                }

                $code = (int) wp_remote_retrieve_response_code( $response );

                if ( $code < 300 || $code > 399 ) {
                    break;
                }

                $next = wp_remote_retrieve_header( $response, 'location' );

                if ( is_array( $next ) ) {
                    $next = end( $next );
                }

                if ( ! is_string( $next ) || $next === '' ) {
                    return false;
                }

                $location = WP_Http::make_absolute_url( $next, $location );

                if ( ! $this->is_url_allowed( $location ) ) {
                    return false;
                }
            }

            if ( $code === 200 ) {
                $tags = $this->getMetaTags( wp_remote_retrieve_body( $response ) );

                $url_parts = parse_url( $url );

                $info = array(
                    'title'       => false,
                    'description' => false,
                    'site'        => $url_parts[ 'host' ]
                );

                if ( isset( $tags[ 'title' ] ) ) $info[ 'title' ] = $tags[ 'title' ];
                if ( isset( $tags[ 'og:title' ] ) ) $info[ 'title' ] = $tags[ 'og:title' ];
                if ( isset( $tags[ 'og:description' ] ) ) $info[ 'description' ] = $tags[ 'og:description' ];

                if ( isset( $tags[ 'og:site_name' ] ) ) $info[ 'site' ] = $tags[ 'og:site_name' ];

                return $info;
            } else {
                return false;
            }
        }

        public function getMetaTags( $str )
        {
            $out  = array();
            $keys = array( 'name', 'property', 'http-equiv' );
            $tags = better_messages_html_tag_processor( $str );

            while ( $tags->next_tag( 'meta' ) ) {
                $content = $tags->get_attribute( 'content' );

                if ( ! is_string( $content ) ) {
                    continue;
                }

                $names = $tags->get_attribute_names_with_prefix( '' );

                if ( empty( $names ) ) {
                    continue;
                }

                foreach ( $names as $name ) {
                    if ( ! in_array( $name, $keys, true ) ) {
                        continue;
                    }

                    $key = $tags->get_attribute( $name );

                    if ( is_string( $key ) && trim( $key ) !== '' ) {
                        $out[ trim( $key ) ] = trim( $content );
                    }

                    break;
                }
            }

            if ( preg_match( '~<title[^>]*>(.*?)</title>~is', $str, $titles ) ) {
                $out['title'] = trim( html_entity_decode( $titles[1], ENT_QUOTES, 'UTF-8' ) );
            }

            return $out;
        }

        public function apply_embed_playback_params( $html, $provider, $embed = null ) {
            if ( empty( $html ) ) {
                return $html;
            }

            $sandbox = $this->get_embed_sandbox( $provider );
            $poster  = is_object( $embed ) ? $this->get_embed_poster( $embed ) : '';
            $title   = is_object( $embed ) && isset( $embed->title ) ? trim( (string) $embed->title ) : '';
            $tags    = better_messages_html_tag_processor( $html );

            while ( $tags->next_tag( 'iframe' ) ) {
                if ( $provider === 'youtube' ) {
                    $src = $tags->get_attribute( 'src' );

                    if ( $src ) {
                        $tags->set_attribute( 'src', $this->add_youtube_playsinline( $src ) );
                    }
                }

                if ( $sandbox !== '' ) {
                    $tags->set_attribute( 'sandbox', $sandbox );
                }

                if ( $poster !== '' ) {
                    $tags->set_attribute( 'data-poster', $poster );
                }

                if ( $title !== '' ) {
                    $tags->set_attribute( 'data-title', $title );
                }
            }

            return $tags->get_updated_html();
        }

        public function get_embed_sandbox( $provider ) {
            $sandbox = apply_filters(
                'better_messages_embed_sandbox',
                'allow-scripts allow-same-origin allow-presentation allow-popups allow-popups-to-escape-sandbox',
                $provider
            );

            return is_string( $sandbox ) ? trim( $sandbox ) : '';
        }

        public function add_youtube_playsinline( $src ) {
            if ( ! apply_filters( 'better_messages_youtube_playsinline_fullscreen', true, $src ) ) {
                return $src;
            }

            if ( strpos( $src, 'playsinline=' ) !== false ) {
                return $src;
            }

            return add_query_arg( 'playsinline', '0', $src );
        }

        public function get_embed_poster( $embed ) {
            $poster = isset( $embed->thumbnail_url ) ? esc_url_raw( (string) $embed->thumbnail_url ) : '';

            return strpos( $poster, 'https://' ) === 0 ? $poster : '';
        }

        public function render_privacy_embed( $url, $embed ) {
            $provider  = isset( $embed->provider_name ) ? strtolower( $embed->provider_name ) : '';
            $title     = isset( $embed->title ) ? esc_attr( $embed->title ) : '';

            $iframe_src = '';
            if ( isset( $embed->html ) ) {
                $tags = better_messages_html_tag_processor( $embed->html );

                if ( $tags->next_tag( 'iframe' ) ) {
                    $iframe_src = (string) $tags->get_attribute( 'src' );
                }

                if ( $iframe_src !== '' && $provider === 'youtube' ) {
                    $iframe_src = str_replace( 'youtube.com', 'youtube-nocookie.com', $iframe_src );
                    $iframe_src = $this->add_youtube_playsinline( $iframe_src );
                }
            }

            if ( empty( $iframe_src ) ) {
                return false;
            }

            return '<span class="bm-iframe-container">'
                . '<span class="bm-embed-consent" data-title="' . $title . '" data-sandbox="' . esc_attr( $this->get_embed_sandbox( $provider ) ) . '" data-src="' . esc_attr( $iframe_src ) . '">'
                . '<span class="bm-embed-consent-play"></span>'
                . ( $title ? '<span class="bm-embed-consent-title">' . esc_html( $title ) . '</span>' : '' )
                . '</span>'
                . '</span>';
        }

        private function extract_youtube_id( $url ) {
            $patterns = [
                '/youtube\.com\/watch\?v=([a-zA-Z0-9_-]+)/',
                '/youtu\.be\/([a-zA-Z0-9_-]+)/',
                '/youtube\.com\/embed\/([a-zA-Z0-9_-]+)/',
                '/youtube\.com\/shorts\/([a-zA-Z0-9_-]+)/',
            ];
            foreach ( $patterns as $pattern ) {
                if ( preg_match( $pattern, $url, $matches ) ) {
                    return $matches[1];
                }
            }
            return false;
        }
    }

endif;


function Better_Messages_Urls()
{
    return Better_Messages_Urls::instance();
}
