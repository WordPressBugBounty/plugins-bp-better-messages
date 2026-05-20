<?php
defined( 'ABSPATH' ) || exit;

class Better_Messages_Shortcodes
{

    public static function instance()
    {

        // Store the instance locally to avoid private static replication
        static $instance = null;

        // Only run these methods if they haven't been run previously
        if ( null === $instance ) {
            $instance = new Better_Messages_Shortcodes;
            $instance->setup_actions();
        }

        // Always return the instance
        return $instance;

        // The last metroid is in captivity. The galaxy is at peace.
    }

    public function setup_actions(){
        add_shortcode( 'bp_better_messages_unread_counter', array( $this, 'unread_counter_shortcode' ) );
        add_shortcode( 'better_messages_unread_counter', array( $this, 'unread_counter_shortcode' ) );

        add_shortcode( 'bp_better_messages_my_messages_url', array( $this, 'bp_better_messages_url' ) );
        add_shortcode( 'better_messages_my_messages_url', array( $this, 'bp_better_messages_url' ) );

        add_shortcode( 'bp_better_messages_pm_button', array( $this, 'bp_better_messages_pm_button' ) );
        add_shortcode( 'better_messages_pm_button', array( $this, 'bp_better_messages_pm_button' ) );

        add_shortcode( 'bp_better_messages', array( $this, 'bp_better_messages' ) );
        add_shortcode( 'better_messages', array( $this, 'bp_better_messages' ) );

        /**
         * Premium buttons
         */
        add_shortcode( 'bp_better_messages_mini_chat_button',  array( $this, 'bp_better_messages_mini_chat_button' ) );
        add_shortcode( 'better_messages_mini_chat_button',  array( $this, 'bp_better_messages_mini_chat_button' ) );

        add_shortcode( 'bp_better_messages_video_call_button', array( $this, 'bp_better_messages_video_call_button' ) );
        add_shortcode( 'better_messages_video_call_button', array( $this, 'bp_better_messages_video_call_button' ) );

        add_shortcode( 'bp_better_messages_audio_call_button', array( $this, 'bp_better_messages_audio_call_button' ) );
        add_shortcode( 'better_messages_audio_call_button', array( $this, 'bp_better_messages_audio_call_button' ) );

        add_shortcode( 'better_messages_single_conversation', array( $this, 'better_messages_single_conversation' ) );
        add_shortcode( 'better_messages_user_conversation', array( $this, 'better_messages_user_conversation' ) );

        add_shortcode( 'better_messages_live_chat_button',  array( $this, 'better_messages_live_chat_button' ) );

        add_filter( 'better_messages_rest_thread_item', array( $this, 'apply_thread_info_banner' ), 20, 5 );
    }

    function esc_brackets($text = ''){
        return str_replace( [ "[" , "]" ] , [ "&#91;" , "&#93;" ] , $text );
    }

    public function better_messages_live_chat_button($args){
        $post  = $this->get_context_post();
        $class = 'bm-lc-button';
        $attrs = '';

        if( isset( $args['class'] ) ) {
            $class .= ' ' . $args['class'];
        }

        $tag  = ( isset( $args['type'] ) && $args['type'] === 'button' ) ? 'button' : 'a';
        $text = $this->resolve_text_token( isset( $args['text'] ) ? (string) $args['text'] : '', $post );

        if( isset( $args['subject'] ) && $args['subject'] !== '' ) {
            $subject = $this->resolve_context_token( (string) $args['subject'], $post );
            if( $subject !== '' ){
                $attrs .= ' data-subject="' . esc_attr( $subject ) . '"';
            }
        }

        if( isset( $args['target'] ) ) {
            $attrs .= ' target="' . esc_attr( $args['target'] ) . '"';
        }

        if( isset( $args['unique_tag'] ) && $args['unique_tag'] !== '' ) {
            $unique_tag = $this->resolve_unique_tag_token( (string) $args['unique_tag'], $post );
            if( $unique_tag !== '' ){
                $attrs .= ' data-bm-unique-key="' . esc_attr( $unique_tag ) . '"';
            }
        }

        if( isset( $args['object_id'] ) && $args['object_id'] !== '' ) {
            $object_id = $this->resolve_object_id_token( (string) $args['object_id'], $post );
            if( $object_id > 0 ){
                $attrs .= ' data-bm-object-id="' . $object_id . '"';
            }
        }

        if( isset( $args['alt'] ) && $args['alt'] !== '' ){
            $attrs .= ' title="' . esc_html( $args['alt'] ) . '"';
        }

        if( isset( $args['user_id'] ) && $args['user_id'] !== '' ) {
            $user_id = $this->resolve_user_id_token( (string) $args['user_id'], $post );
        } else {
            $user_id = (int) Better_Messages()->functions->get_member_id();
        }

        if( Better_Messages()->functions->get_current_user_id() === $user_id ){
            $class .= ' bm-self-button';
        }

        if( ! is_user_logged_in() && ! Better_Messages()->guests->guest_has_entry_point() ) {
            $link = Better_Messages()->functions->get_link( Better_Messages()->functions->get_current_user_id() );
            $attrs .= ' onclick="event.preventDefault(); event.stopImmediatePropagation(); event.stopPropagation(); location.href = \'' . $link . '\';"';
        }

        $style_attr = $this->build_inline_style_attr( $args );
        $icon_html  = $this->build_icon_html( isset( $args['icon'] ) ? (string) $args['icon'] : '' );

        Better_Messages()->enqueue_css();

        $inner = $icon_html . '<span class="bm-button-text">' . wp_kses( $text, [ 'i' => [ 'class' => [] ] ] ) . '</span>';

        return '<' . $tag . ' class="' . esc_attr( $class ) . '" data-user-id="' . $user_id . '"' . $style_attr . ' ' . $attrs . '>' . $inner . '</' . $tag . '>';
    }

    public function build_inline_style_attr( $args ){
        $map = array(
            'bg_color'      => 'background-color',
            'text_color'    => 'color',
            'font_size'     => 'font-size',
            'padding'       => 'padding',
            'margin'        => 'margin',
            'border_radius' => 'border-radius',
        );

        $parts = array();
        foreach( $map as $arg_key => $css_prop ){
            if( ! isset( $args[ $arg_key ] ) ) continue;
            $value = trim( (string) $args[ $arg_key ] );
            if( $value === '' ) continue;
            $parts[] = $css_prop . ': ' . $value;
        }

        if( empty( $parts ) ) return '';

        $style = safecss_filter_attr( implode( '; ', $parts ) );
        if( $style === '' ) return '';

        return ' style="' . esc_attr( $style ) . '"';
    }

    public function build_icon_html( $icon_value ){
        $svg = $this->sanitize_icon_svg( $icon_value );
        if( $svg === '' ) return '';

        return '<span class="bm-button-icon" aria-hidden="true">' . $svg . '</span>';
    }

    public function sanitize_icon_svg( $svg ){
        $svg = trim( (string) $svg );
        if( $svg === '' ) return '';
        if( stripos( ltrim( $svg ), '<svg' ) !== 0 ) return '';

        $svg = preg_replace( '/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is', '', $svg );
        $svg = preg_replace( '/<foreignObject\b[^<]*(?:(?!<\/foreignObject>)<[^<]*)*<\/foreignObject>/is', '', $svg );
        $svg = preg_replace( '/<iframe\b[^<]*(?:(?!<\/iframe>)<[^<]*)*<\/iframe>/is', '', $svg );
        $svg = preg_replace( '/<(object|embed)\b[^>]*>/i', '', $svg );
        $svg = preg_replace( '/<style\b[^<]*(?:(?!<\/style>)<[^<]*)*<\/style>/is', '', $svg );
        $svg = preg_replace( '/\son\w+\s*=\s*["\'][^"\']*["\']/i', '', $svg );
        $svg = preg_replace( '/\son\w+\s*=\s*[^\s>]*/i', '', $svg );
        $svg = preg_replace( '/(href|xlink:href|src)\s*=\s*["\']\s*(javascript|data|vbscript):[^"\']*["\']/i', '$1=""', $svg );
        $svg = preg_replace( '/(href|xlink:href|src)\s*=\s*(javascript|data|vbscript):[^\s>]*/i', '$1=""', $svg );

        if( $svg === null || stripos( ltrim( $svg ), '<svg' ) !== 0 ) return '';

        return $svg;
    }

    public function get_context_post(){
        $post_id = get_the_ID();
        if( ! $post_id ) return null;
        $post = get_post( $post_id );
        return ( $post instanceof WP_Post ) ? $post : null;
    }

    public function resolve_context_token( $value, $post ){
        $value = (string) $value;
        if( $value === '' ) return '';

        if( $post instanceof WP_Post ){
            $author_id   = (int) $post->post_author;
            $author      = $author_id ? get_userdata( $author_id ) : null;
            $author_name = $author ? $author->display_name : '';

            $replacements = [
                '{post_id}'     => (string) $post->ID,
                '{post_type}'   => $post->post_type,
                '{post_title}'  => $post->post_title,
                '{author_id}'   => (string) $author_id,
                '{author_name}' => $author_name,
            ];

            $value = strtr( $value, $replacements );
        } else {
            $value = preg_replace( '/\{[a-z_]+\}/', '', $value );
        }

        return $value;
    }

    public function resolve_text_token( $value, $post ){
        $value = (string) $value;

        if( $value === '' ){
            return __( 'Live Chat', 'bp-better-messages' );
        }

        if( $value === 'auto' ){
            $default = $post instanceof WP_Post
                ? _x( 'Chat with {author_name}', 'Live Chat Button auto label', 'bp-better-messages' )
                : __( 'Live Chat', 'bp-better-messages' );

            $value = apply_filters( 'better_messages_live_chat_button_auto_text', $default, $post );
        }

        $resolved = $this->resolve_context_token( $value, $post );

        return $resolved !== '' ? $resolved : __( 'Live Chat', 'bp-better-messages' );
    }

    public function resolve_unique_tag_token( $value, $post ){
        $value = (string) $value;
        if( $value === '' ) return '';

        if( $value === 'auto' ){
            if( ! $post instanceof WP_Post ) return '';
            return $post->post_type . '-' . $post->ID;
        }

        return $this->resolve_context_token( $value, $post );
    }

    public function resolve_object_id_token( $value, $post ){
        $value = (string) $value;
        if( $value === '' ) return 0;

        if( $value === 'auto' ){
            return $post instanceof WP_Post ? (int) $post->ID : 0;
        }

        return (int) $value;
    }

    public function resolve_user_id_token( $value, $post ){
        $value = (string) $value;
        if( $value === '' ) return 0;

        if( $value === 'post_author' ){
            return $post instanceof WP_Post ? (int) $post->post_author : 0;
        }

        if( strpos( $value, 'postmeta:' ) === 0 ){
            if( ! $post instanceof WP_Post ) return 0;
            $meta_key   = substr( $value, strlen( 'postmeta:' ) );
            $meta_value = get_post_meta( $post->ID, $meta_key, true );
            return is_numeric( $meta_value ) ? (int) $meta_value : 0;
        }

        return (int) $value;
    }

    public function better_messages_single_conversation( $args ){
        if( ! is_user_logged_in() && ! Better_Messages()->guests->guest_access_enabled() ) {
            return Better_Messages()->functions->render_login_form();
        }

        $thread_id = intval( $args['thread_id'] );
        $thread = Better_Messages()->functions->get_thread( $thread_id );

        if( $thread ) {
            return Better_Messages()->functions->get_conversation_layout($thread_id);
        } else {
            return '<p>' . __('Conversation not exists', 'bp-better-messages') .  '</p>';
        }
    }

    public function better_messages_user_conversation( $args )
    {
        if( ! is_user_logged_in() && ! Better_Messages()->guests->guest_access_enabled() ) {
            return Better_Messages()->functions->render_login_form();
        }

        if( isset( $args['user_id'] ) ) {
            $user_id = (int) $args['user_id'];
        } else {
            $user_id = (int) Better_Messages()->functions->get_member_id();
        }

        $initialHeight = (int) apply_filters( 'bp_better_messages_max_height', Better_Messages()->settings['messagesHeight'] );
        return '<div class="bp-messages-single-thread-wrap" style="height: ' . $initialHeight . 'px" data-user-id="' . $user_id . '">' . Better_Messages()->functions->container_placeholder() . '</div>';
    }

    public function bp_better_messages( $args ){
        ob_start();

        if( ! is_user_logged_in() && ! Better_Messages()->guests->guest_access_enabled() ){
            echo Better_Messages()->functions->render_login_form();
        } else {
            echo Better_Messages()->functions->get_page( $args );
        }

        return ob_get_clean();
    }

    public function bp_better_messages_pm_button( $args ){
        $class   = 'bpbm-pm-button';
        $target  = '';
        $text    = __('Private Message', 'bp-better-messages');
        $subject = '';
        $message = '';
        $fast    = true;
        $return_url = false;

        if( isset( $args['class'] ) ) {
            $class .= ' ' . $args['class'];
        }

        if( isset( $args['target'] ) ) {
            $target .= ' target="' .  esc_attr( $args['target'] ) . '"';
        }

        if( isset( $args['text'] ) ) {
            $text = $args['text'];
        }

        if( isset( $args['subject'] ) ) {
            $subject = urlencode($args['subject']);
        }

        if( isset( $args['message'] ) ) {
            $message = urlencode($args['message']);
        }

        if( isset( $args['fast_start'] ) && $args['fast_start'] === '0' ) {
            $fast = false;
        }

        if( isset( $args['url_only'] ) && $args['url_only'] === '1' ) {
            $return_url = true;
        }

        if( isset( $args['user_id'] ) ) {
            $user_id = (int) $args['user_id'];
        } else {
            $user_id = (int) Better_Messages()->functions->get_member_id();
        }

        if( ! Better_Messages()->functions->is_user_exists( $user_id ) ) return '';

        if( $user_id === Better_Messages()->functions->get_current_user_id() ) $class .= ' bm-self-button';

        $args = [
            'to' => $user_id
        ];

        $base_url = Better_Messages()->functions->get_link( Better_Messages()->functions->get_current_user_id() );

        if( Better_Messages()->settings['fastStart'] == '1' && $fast ){
            $args['bm-fast-start'] = '1';
            $class .= ' bm-fast-start';
        }

        if( ! empty( $subject ) ){
            $args['subject'] = $subject;
        }

        if( ! empty( $message ) ){
            $args['message'] = $message;
        }

        $attributes = '';

        if( isset( $args['subject'] ) ) {
            $attributes .= ' data-bm-subject="' . esc_attr($args['subject']) . '"';
        }

        if( isset( $args['unique_tag'] ) ) {
            $attributes .= ' data-bm-unique-key="' . esc_attr($args['unique_tag']) . '"';
        }

        if( isset($args['bm-fast-start']) && $args['bm-fast-start'] ){
            $link = add_query_arg( $args, $base_url );
        } else {
            $link = Better_Messages()->functions->add_hash_arg('new-conversation', $args, $base_url);
        }

        if( $return_url ) {
            return $link;
        }

        Better_Messages()->enqueue_css();

        return '<a href="' . esc_url($link) .  '" class="' . esc_attr($class) . '"' . $target . ' data-user-id="' . $user_id. '"' . $attributes . '><span class="bm-button-text">' . esc_attr($text) . '</span></a>';
    }

    public function bp_better_messages_video_call_button( $args ){
        $class   = 'bpbm-pm-button video-call';
        $target  = '';
        $text    = __('Video Call', 'bp-better-messages');
        $return_url = false;

        if( isset( $args['class'] ) ) {
            $class .= ' ' . $args['class'];
        }

        if( isset( $args['target'] ) ) {
            $target .= ' target="' . esc_attr( $args['target'] ) . '"';
        }

        if( isset( $args['text'] ) ) {
            $text = $args['text'];
        }

        if( isset( $args['url_only'] ) && $args['url_only'] === '1' ) {
            $return_url = true;
        }

        if( isset( $args['user_id'] ) ) {
            $user_id = (int) $args['user_id'];
        } else {
            $user_id = (int) Better_Messages()->functions->get_member_id();
        }

        if( $user_id === Better_Messages()->functions->get_current_user_id() ) $class .= ' bm-self-button';

        if( ! Better_Messages()->functions->is_user_exists( $user_id ) ) return '';

        $args = [
            'fast-call' => '',
            'to' => $user_id,
            'type' => 'video'
        ];

        $base_url = Better_Messages()->functions->get_link( Better_Messages()->functions->get_current_user_id() );

        $link = add_query_arg( $args, $base_url );

        if( $return_url ) {
            return $link;
        }

        Better_Messages()->enqueue_css();

        return '<a href="' . esc_url($link) .  '" class="' . esc_attr($class) . '" data-user-id="' . $user_id . '"><span class="bm-button-text">' . esc_attr($text) . '</span></a>';
    }

    public function bp_better_messages_audio_call_button( $args ){
        $class   = 'bpbm-pm-button audio-call';
        $text    = __('Audio Call', 'bp-better-messages');
        $return_url = false;

        if( isset( $args['class'] ) ) {
            $class .= ' ' . $args['class'];
        }

        if( isset( $args['text'] ) ) {
            $text = $args['text'];
        }

        if( isset( $args['url_only'] ) && $args['url_only'] === '1' ) {
            $return_url = true;
        }

        if( isset( $args['user_id'] ) ) {
            $user_id = (int) $args['user_id'];
        } else {
            $user_id = (int) Better_Messages()->functions->get_member_id();
        }

        if( $user_id === Better_Messages()->functions->get_current_user_id() ) $class .= ' bm-self-button';

        if( ! Better_Messages()->functions->is_user_exists( $user_id ) ) return '';

        $args = [
            'fast-call' => '',
            'to' => $user_id,
            'type' => 'audio'
        ];

        $base_url = Better_Messages()->functions->get_link(Better_Messages()->functions->get_current_user_id());
        $link = add_query_arg( $args, $base_url );

        if( $return_url ) {
            return $link;
        }

        Better_Messages()->enqueue_css();

        return '<a href="' . esc_url($link) .  '" class="' . esc_attr($class) . '" data-user-id="' . $user_id . '"><span class="bm-button-text">' . esc_attr($text) . '</span></a>';
    }

    public function bp_better_messages_mini_chat_button( $args ){
        if (Better_Messages()->settings['miniChatsEnable'] !== '1') {
            return '';
        }

        $class   = 'bpbm-pm-button open-mini-chat';
        $text    = __('Private Message', 'bp-better-messages');

        if( isset( $args['class'] ) ) {
            $class .= ' ' . $args['class'];
        }

        if( isset( $args['text'] ) ) {
            $text = $args['text'];
        }

        if( isset( $args['user_id'] ) ) {
            $user_id = (int) $args['user_id'];
        } else {
            $user_id = (int) Better_Messages()->functions->get_member_id();
        }

        if( $user_id === Better_Messages()->functions->get_current_user_id() ) $class .= ' bm-self-button';

        if( ! Better_Messages()->functions->is_user_exists( $user_id ) ) return '';

        $attributes = '';

        if( isset( $args['subject'] ) ) {
            $attributes .= ' data-bm-subject="' . esc_attr($args['subject']) . '"';
        }

        if( isset( $args['unique_tag'] ) ) {
            $attributes .= ' data-bm-unique-key="' . esc_attr($args['unique_tag']) . '"';
        }

        $link = '#';

        if( ! is_user_logged_in() ) {
            $link = Better_Messages()->functions->get_link(Better_Messages()->functions->get_current_user_id());
        }

        Better_Messages()->enqueue_css();

        return '<a href="' . esc_url($link) .  '" class="' . esc_attr($class) . '" data-user-id="' . $user_id . '" '. $attributes. '><span class="bm-button-text">' . esc_attr($text) . '</span></a>';
    }

    public function bp_better_messages_url(){
        if( ! is_user_logged_in() ){
            return '';
        }

        return Better_Messages()->functions->get_link( Better_Messages()->functions->get_current_user_id() );
    }

    function unread_counter_shortcode( $args ) {
        if( ! is_user_logged_in() ){
            return '';
        }

        $hide_when_no_messages = false;
        $preserve_space = false;
        if( isset( $args['hide_when_no_messages'] ) && $args['hide_when_no_messages'] === '1' ) {
            $hide_when_no_messages = true;
        }

        if( isset( $args['preserve_space'] ) && $args['preserve_space'] === '1' ) {
            $preserve_space = true;
        }

        $classes = ['bp-better-messages-unread', 'bpbmuc'];
        if( $hide_when_no_messages ){
            $classes[] = 'bpbmuc-hide-when-null';
        }

        if( $preserve_space ){
            $classes[] = 'bpbmuc-preserve-space';
        }

        $class = implode(' ', $classes );
        if( Better_Messages()->settings['mechanism'] !== 'websocket'){
            $unread = Better_Messages()->functions->get_total_threads_for_user( Better_Messages()->functions->get_current_user_id(), 'unread' );
            return '<span class="' . $class . '" data-count="' . $unread . '">' . $unread . '</span>';
        } else {
            return '<span class="' . $class . '" data-count="0">0</span>';
        }
    }

    public function apply_thread_info_banner( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ){
        if( $thread_type !== 'thread' ) return $thread_item;
        if( ! empty( $thread_item['threadInfo'] ) ) return $thread_item;

        $context_post_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'context_post_id' );
        if( $context_post_id <= 0 ) return $thread_item;

        $post = get_post( $context_post_id );
        if( ! $post instanceof WP_Post ) return $thread_item;
        if( $post->post_status !== 'publish' ) return $thread_item;
        if( function_exists( 'is_post_publicly_viewable' ) && ! is_post_publicly_viewable( $post ) ) return $thread_item;

        $html = $this->build_thread_info_banner_html( $post, $thread_id );
        if( $html !== '' ) $thread_item['threadInfo'] = $html;

        return $thread_item;
    }

    public function build_thread_info_banner_html( WP_Post $post, $thread_id ){
        $title = get_the_title( $post );
        $url   = get_permalink( $post );
        $image = '';

        if( has_post_thumbnail( $post ) ){
            $image = (string) wp_get_attachment_image_url( get_post_thumbnail_id( $post ), 'thumbnail' );
        }

        $html  = '<div class="bm-product-info bm-thread-context-info">';
        if( $image ){
            $html .= '<div class="bm-product-image">';
            $html .= '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $image ) . '" alt="' . esc_attr( $title ) . '" /></a>';
            $html .= '</div>';
        }
        $html .= '<div class="bm-product-details">';
        $html .= '<div class="bm-product-title"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $title ) . '</a></div>';
        $html .= '</div>';
        $html .= '</div>';

        return apply_filters( 'better_messages_thread_info_banner_html', $html, $post, $thread_id );
    }

}

function Better_Messages_Shortcodes()
{
    return Better_Messages_Shortcodes::instance();
}

if( ! function_exists( 'better_messages_live_chat_button' ) ){
    function better_messages_live_chat_button( array $args = [] ): string {
        return Better_Messages_Shortcodes::instance()->better_messages_live_chat_button( $args );
    }
}
