<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Live_Chat_Builder' ) ) {

    class Better_Messages_Live_Chat_Builder
    {
        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Live_Chat_Builder();
                $instance->setup_actions();
            }

            return $instance;
        }

        public function setup_actions()
        {
            add_action( 'rest_api_init', array( $this, 'register_routes' ) );
            add_action( 'template_redirect', array( $this, 'maybe_render_preview_page' ), 0 );
        }

        public function maybe_render_preview_page()
        {
            if ( ! isset( $_GET['bm_lcb_preview_button'] ) ) return;
            if ( ! current_user_can( 'manage_options' ) ) {
                status_header( 403 );
                exit;
            }

            nocache_headers();
            header( 'X-Frame-Options: SAMEORIGIN' );

            $params                  = $this->params_from_query( $_GET );
            $params['previewPostId'] = (int) get_queried_object_id();

            $preview     = $this->build_preview( $params );
            $button_html = (string) $preview['buttonHtml'];

            Better_Messages()->enqueue_css();

            ?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>
html, body { background: transparent !important; margin: 0 !important; }
body { padding: 16px !important; min-height: 0 !important; }
body > *:not(.bm-lcb-button-host):not(script):not(noscript):not(style) { display: none !important; }
.bm-lcb-button-host { display: inline-block; }
.bm-lcb-button-host .bm-lc-button { pointer-events: none; }
</style>
</head>
<body class="bm-lcb-preview-page <?php echo esc_attr( implode( ' ', get_body_class() ) ); ?>">
<div class="bm-lcb-button-host"><?php echo $button_html; ?></div>
<?php wp_footer(); ?>
<script>
(function(){
  function reportSize(){
    var host = document.querySelector('.bm-lcb-button-host');
    if(!host) return;
    var rect = host.getBoundingClientRect();
    parent.postMessage({ type: 'bm_lcb_preview_height', height: Math.ceil(rect.height) + 32 }, '*');
  }
  window.addEventListener('load', reportSize);
  window.addEventListener('resize', reportSize);
  if (window.ResizeObserver) {
    var ro = new ResizeObserver(reportSize);
    var host = document.querySelector('.bm-lcb-button-host');
    if (host) ro.observe(host);
  }
  setTimeout(reportSize, 50);
  setTimeout(reportSize, 250);
})();
</script>
</body>
</html><?php
            exit;
        }

        public function register_routes()
        {
            register_rest_route( 'better-messages/v1/admin', '/livechatbuilder/preview', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'preview' ),
                'permission_callback' => array( $this, 'user_can_admin' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/livechatbuilder/test', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'test' ),
                'permission_callback' => array( $this, 'user_can_admin' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/livechatbuilder/searchPosts', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'search_posts' ),
                'permission_callback' => array( $this, 'user_can_admin' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/livechatbuilder/postmetaKeys', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'postmeta_keys' ),
                'permission_callback' => array( $this, 'user_can_admin' ),
            ) );
        }

        public function user_can_admin()
        {
            return current_user_can( 'manage_options' );
        }

        public function preview( WP_REST_Request $request )
        {
            return rest_ensure_response( $this->build_preview( $this->normalize_params( $request ) ) );
        }

        public function test( WP_REST_Request $request )
        {
            $params  = $this->normalize_params( $request );
            $preview = $this->build_preview( $params );

            if ( $preview['resolvedUserId'] <= 0 ) {
                return new WP_Error(
                    'bm_invalid_target_user',
                    _x( 'Target user could not be resolved against the preview post', 'Live Chat Builder', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            $current_user_id = (int) Better_Messages()->functions->get_current_user_id();
            if ( $current_user_id <= 0 ) {
                return new WP_Error(
                    'bm_not_logged_in',
                    _x( 'You must be logged in to run a test conversation', 'Live Chat Builder', 'bp-better-messages' ),
                    array( 'status' => 401 )
                );
            }

            if ( $current_user_id === $preview['resolvedUserId'] ) {
                return new WP_Error(
                    'bm_self_target',
                    _x( 'The resolved target is yourself. Pick another preview post or switch to a fixed user', 'Live Chat Builder', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            $subject       = (string) $preview['resolvedSubject'];
            $unique_tag    = (string) $preview['resolvedUniqueTag'];
            $object_id     = (int) $preview['resolvedObjectId'];
            $target_user_id = (int) $preview['resolvedUserId'];

            if ( $unique_tag !== '' ) {
                $result = Better_Messages()->functions->get_unique_pm_thread_id( $unique_tag, $target_user_id, $current_user_id, true, $subject );
            } else {
                $result = Better_Messages()->functions->get_pm_thread_id( $target_user_id, $current_user_id, true, $subject );
            }

            if ( ! isset( $result['result'] ) || ! in_array( $result['result'], array( 'thread_found', 'thread_created' ), true ) ) {
                return new WP_Error(
                    'bm_thread_create_failed',
                    isset( $result['errors'] ) ? ( is_array( $result['errors'] ) ? implode( "\n", $result['errors'] ) : (string) $result['errors'] ) : _x( 'Unable to start the conversation', 'Live Chat Builder', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            $thread_id = (int) $result['thread_id'];

            if ( $result['result'] === 'thread_created' && $object_id > 0 ) {
                $existing = Better_Messages()->functions->get_thread_meta( $thread_id, 'context_post_id' );
                if ( empty( $existing ) ) {
                    Better_Messages()->functions->update_thread_meta( $thread_id, 'context_post_id', $object_id );
                }
            }

            $preview['threadId']  = $thread_id;
            $preview['threadUrl'] = Better_Messages()->functions->get_link( $current_user_id ) . '#/conversation/' . $thread_id;

            return rest_ensure_response( $preview );
        }

        public function search_posts( WP_REST_Request $request )
        {
            $search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
            $page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
            $per_page = (int) $request->get_param( 'per_page' );
            if ( $per_page <= 0 || $per_page > 50 ) $per_page = 20;

            $args = array(
                'post_type'      => 'any',
                'post_status'    => 'publish',
                'posts_per_page' => $per_page + 1,
                'paged'          => $page,
                'orderby'        => 'date',
                'order'          => 'DESC',
            );

            if ( $search !== '' ) {
                if ( ctype_digit( $search ) ) {
                    $args['p'] = (int) $search;
                } else {
                    $args['s'] = $search;
                }
            }

            $query = new WP_Query( $args );
            $items = array();

            foreach ( $query->posts as $post ) {
                if ( ! ( $post instanceof WP_Post ) ) continue;
                $items[] = $this->format_post_summary( $post );
            }

            $has_more = count( $items ) > $per_page;
            if ( $has_more ) {
                $items = array_slice( $items, 0, $per_page );
            }

            return rest_ensure_response( array(
                'items'   => $items,
                'page'    => $page,
                'hasMore' => $has_more,
            ) );
        }

        public function postmeta_keys( WP_REST_Request $request )
        {
            global $wpdb;

            $post_type = sanitize_key( (string) $request->get_param( 'post_type' ) );
            $limit     = 50;

            $base = "SELECT DISTINCT meta_key FROM {$wpdb->postmeta}
                     INNER JOIN {$wpdb->posts} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
                     WHERE {$wpdb->posts}.post_status = 'publish'
                     AND {$wpdb->postmeta}.meta_value REGEXP '^[0-9]+$'";

            if ( $post_type === '' ) {
                $sql = $wpdb->prepare( "$base ORDER BY meta_key ASC LIMIT %d", $limit );
            } else {
                $sql = $wpdb->prepare(
                    "$base AND {$wpdb->posts}.post_type = %s ORDER BY meta_key ASC LIMIT %d",
                    $post_type,
                    $limit
                );
            }

            $keys = array_values( array_filter( (array) $wpdb->get_col( $sql ), function ( $k ) {
                return is_string( $k ) && $k !== '';
            } ) );

            return rest_ensure_response( array( 'keys' => $keys ) );
        }

        protected function format_post_summary( WP_Post $post )
        {
            $thumb_url = '';
            if ( has_post_thumbnail( $post ) ) {
                $thumb_id  = get_post_thumbnail_id( $post );
                $thumb_url = (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' );
            }

            $author    = $post->post_author ? get_userdata( (int) $post->post_author ) : null;
            $type_obj  = get_post_type_object( $post->post_type );

            return array(
                'id'          => (int) $post->ID,
                'title'       => html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
                'permalink'   => get_permalink( $post ),
                'postType'    => $post->post_type,
                'postTypeLabel' => $type_obj ? $type_obj->labels->singular_name : $post->post_type,
                'authorId'    => (int) $post->post_author,
                'authorName'  => $author ? $author->display_name : '',
                'thumbnail'   => $thumb_url,
            );
        }

        protected function param_schema()
        {
            return array(
                'userIdMode'      => array( 'key' => 'uim', 'type' => 'key',     'default' => 'post_author' ),
                'postmetaKey'     => array( 'key' => 'pmk', 'type' => 'text',    'default' => '' ),
                'fixedUserId'     => array( 'key' => 'fui', 'type' => 'int',     'default' => 0 ),
                'uniqueTagMode'   => array( 'key' => 'utm', 'type' => 'key',     'default' => '' ),
                'customUniqueTag' => array( 'key' => 'cut', 'type' => 'text',    'default' => '' ),
                'textMode'        => array( 'key' => 'tm',  'type' => 'key',     'default' => '' ),
                'customText'      => array( 'key' => 'ct',  'type' => 'text',    'default' => '' ),
                'buttonType'      => array( 'key' => 'bt',  'type' => 'key',     'default' => '' ),
                'buttonClass'     => array( 'key' => 'bc',  'type' => 'text',    'default' => '' ),
                'subject'         => array( 'key' => 'sb',  'type' => 'text',    'default' => '' ),
                'bannerEnabled'   => array( 'key' => 'be',  'type' => 'bool',    'default' => false ),
                'previewPostId'   => array( 'key' => null,  'type' => 'int',     'default' => 0 ),
                'bgColor'         => array( 'key' => 'bgc', 'type' => 'text',    'default' => '' ),
                'textColor'       => array( 'key' => 'txc', 'type' => 'text',    'default' => '' ),
                'fontSize'        => array( 'key' => 'fsz', 'type' => 'text',    'default' => '' ),
                'padding'         => array( 'key' => 'pad', 'type' => 'text',    'default' => '' ),
                'margin'          => array( 'key' => 'mar', 'type' => 'text',    'default' => '' ),
                'borderRadius'    => array( 'key' => 'brd', 'type' => 'text',    'default' => '' ),
                'icon'            => array( 'key' => 'icn', 'type' => 'icon',    'default' => '' ),
            );
        }

        protected function sanitize_value( $type, $raw )
        {
            switch ( $type ) {
                case 'key':
                    return sanitize_key( (string) $raw );
                case 'text':
                    return sanitize_text_field( (string) $raw );
                case 'int':
                    return (int) $raw;
                case 'bool':
                    return $raw === true || $raw === '1' || $raw === 1;
                case 'icon':
                    return Better_Messages_Shortcodes::instance()->sanitize_icon_svg( wp_unslash( (string) $raw ) );
            }
            return $raw;
        }

        protected function normalize_params( WP_REST_Request $request )
        {
            $out = array();
            foreach ( $this->param_schema() as $name => $meta ) {
                $raw = $request->get_param( $name );
                if ( $raw === null ) {
                    $out[ $name ] = $meta['default'];
                    continue;
                }
                $out[ $name ] = $this->sanitize_value( $meta['type'], $raw );
            }
            return $out;
        }

        protected function params_from_query( array $query )
        {
            $out = array();
            foreach ( $this->param_schema() as $name => $meta ) {
                $short = $meta['key'];
                if ( $short === null || ! isset( $query[ $short ] ) ) {
                    $out[ $name ] = $meta['default'];
                    continue;
                }
                $raw          = wp_unslash( $query[ $short ] );
                $out[ $name ] = $this->sanitize_value( $meta['type'], $raw );
            }
            return $out;
        }

        protected function resolve_user_id_attr_value( array $params, array &$warnings )
        {
            switch ( $params['userIdMode'] ) {
                case 'postmeta':
                    if ( $params['postmetaKey'] === '' ) {
                        $warnings[] = _x( 'Custom field key is empty', 'Live Chat Builder', 'bp-better-messages' );
                        return '';
                    }
                    return 'postmeta:' . $params['postmetaKey'];
                case 'fixed':
                    if ( $params['fixedUserId'] > 0 ) {
                        return (string) $params['fixedUserId'];
                    }
                    $warnings[] = _x( 'Pick a target user', 'Live Chat Builder', 'bp-better-messages' );
                    return '';
            }
            return 'post_author';
        }

        protected function build_preview( array $params )
        {
            $warnings        = array();
            $shortcode_attrs = array();

            $user_id_attr_value = $this->resolve_user_id_attr_value( $params, $warnings );

            $unique_tag_attr_value = '';
            if ( $params['uniqueTagMode'] === 'auto' ) {
                $unique_tag_attr_value = 'auto';
            } elseif ( $params['uniqueTagMode'] === 'custom' ) {
                $unique_tag_attr_value = $params['customUniqueTag'];
            }

            $text_attr_value = '';
            if ( $params['textMode'] === 'auto' ) {
                $text_attr_value = 'auto';
            } elseif ( $params['textMode'] === 'custom' || $params['textMode'] === 'static' ) {
                $text_attr_value = $params['customText'];
            }

            $style_attr_map = array(
                'bgColor'      => 'bg_color',
                'textColor'    => 'text_color',
                'fontSize'     => 'font_size',
                'padding'      => 'padding',
                'margin'       => 'margin',
                'borderRadius' => 'border_radius',
                'icon'         => 'icon',
            );

            $candidates = array(
                'user_id'    => $user_id_attr_value,
                'unique_tag' => $unique_tag_attr_value,
                'text'       => $text_attr_value,
                'type'       => $params['buttonType'] === 'button' ? 'button' : '',
                'class'      => $params['buttonClass'],
                'subject'    => $params['subject'],
                'object_id'  => $params['bannerEnabled'] ? 'auto' : '',
            );
            foreach ( $style_attr_map as $param_key => $attr_key ) {
                $candidates[ $attr_key ] = isset( $params[ $param_key ] ) ? trim( (string) $params[ $param_key ] ) : '';
            }

            foreach ( $candidates as $attr_key => $value ) {
                if ( $value !== '' ) {
                    $shortcode_attrs[ $attr_key ] = $value;
                }
            }

            $preview_post = $params['previewPostId'] > 0 ? get_post( $params['previewPostId'] ) : null;
            if ( ! ( $preview_post instanceof WP_Post ) ) {
                $preview_post = null;
            }

            $shortcodes_helper = Better_Messages_Shortcodes::instance();

            $resolved_user_id = 0;
            if ( $user_id_attr_value !== '' ) {
                $resolved_user_id = $shortcodes_helper->resolve_user_id_token( $user_id_attr_value, $preview_post );
            }

            if ( $resolved_user_id <= 0 ) {
                if ( $params['userIdMode'] === 'post_author' && $preview_post ) {
                    $warnings[] = _x( 'Selected preview post has no author', 'Live Chat Builder', 'bp-better-messages' );
                } else if ( $params['userIdMode'] === 'postmeta' && $params['postmetaKey'] !== '' && $preview_post ) {
                    $warnings[] = sprintf(
                        _x( 'No numeric user id stored in the "%s" field of this post', 'Live Chat Builder', 'bp-better-messages' ),
                        $params['postmetaKey']
                    );
                }
            }

            $resolved_user_label = '';
            if ( $resolved_user_id > 0 ) {
                $resolved_user_label = (string) Better_Messages()->functions->get_name( $resolved_user_id );
            }

            $resolved_unique_tag = '';
            if ( $unique_tag_attr_value !== '' ) {
                $resolved_unique_tag = $shortcodes_helper->resolve_unique_tag_token( $unique_tag_attr_value, $preview_post );
            }

            $resolved_text = $shortcodes_helper->resolve_text_token( $text_attr_value, $preview_post );

            $resolved_subject = '';
            if ( $params['subject'] !== '' ) {
                $resolved_subject = $shortcodes_helper->resolve_context_token( $params['subject'], $preview_post );
            }

            $resolved_object_id = 0;
            if ( $params['bannerEnabled'] ) {
                $resolved_object_id = $shortcodes_helper->resolve_object_id_token( 'auto', $preview_post );
            }

            $button_html = $this->render_button_html( $params, $resolved_user_id, $resolved_unique_tag, $resolved_text, $resolved_subject, $resolved_object_id );

            $banner_html = '';
            if ( $params['bannerEnabled'] && $preview_post ) {
                $banner_html = Better_Messages_Shortcodes::instance()->build_thread_info_banner_html( $preview_post, 0 );
            }

            return array(
                'shortcode'         => $this->build_shortcode_string( $shortcode_attrs ),
                'resolvedUserId'    => $resolved_user_id,
                'resolvedUserLabel' => $resolved_user_label,
                'resolvedUniqueTag' => $resolved_unique_tag,
                'resolvedText'      => $resolved_text,
                'resolvedSubject'   => $resolved_subject,
                'resolvedObjectId'  => $resolved_object_id,
                'buttonHtml'        => $button_html,
                'bannerHtml'        => $banner_html,
                'warnings'          => $warnings,
            );
        }

        protected function render_button_html( array $params, $user_id, $unique_tag, $text, $subject, $object_id )
        {
            $class = 'bm-lc-button';
            if ( $params['buttonClass'] !== '' ) {
                $class .= ' ' . $params['buttonClass'];
            }

            $attrs = '';
            if ( $subject !== '' )       $attrs .= ' data-subject="' . esc_attr( $subject ) . '"';
            if ( $unique_tag !== '' )    $attrs .= ' data-bm-unique-key="' . esc_attr( $unique_tag ) . '"';
            if ( $object_id > 0 )        $attrs .= ' data-bm-object-id="' . (int) $object_id . '"';

            $shortcodes_helper = Better_Messages_Shortcodes::instance();

            $style_attr = $shortcodes_helper->build_inline_style_attr( array(
                'bg_color'      => $params['bgColor'] ?? '',
                'text_color'    => $params['textColor'] ?? '',
                'font_size'     => $params['fontSize'] ?? '',
                'padding'       => $params['padding'] ?? '',
                'margin'        => $params['margin'] ?? '',
                'border_radius' => $params['borderRadius'] ?? '',
            ) );

            $icon_html = $shortcodes_helper->build_icon_html( $params['icon'] ?? '' );

            $label = $icon_html . '<span class="bm-button-text">' . wp_kses( $text, array( 'i' => array( 'class' => array() ) ) ) . '</span>';
            $tag   = $params['buttonType'] === 'button' ? 'button' : 'a';

            return '<' . $tag . ' class="' . esc_attr( $class ) . '" data-user-id="' . (int) $user_id . '"' . $style_attr . $attrs . '>' . $label . '</' . $tag . '>';
        }

        protected function build_shortcode_string( array $attrs )
        {
            $parts = array( '[better_messages_live_chat_button' );

            foreach ( $attrs as $key => $value ) {
                $value = (string) $value;
                $has_dq = strpos( $value, '"' ) !== false;
                $has_sq = strpos( $value, "'" ) !== false;

                if ( $has_dq && ! $has_sq ) {
                    $parts[] = $key . "='" . $value . "'";
                } else {
                    $parts[] = $key . '="' . esc_attr( $value ) . '"';
                }
            }

            return implode( ' ', $parts ) . ']';
        }
    }

    function Better_Messages_Live_Chat_Builder()
    {
        return Better_Messages_Live_Chat_Builder::instance();
    }

    Better_Messages_Live_Chat_Builder();
}
