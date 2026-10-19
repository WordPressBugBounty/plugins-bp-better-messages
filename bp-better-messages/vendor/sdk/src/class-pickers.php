<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Pickers' ) ) {
    final class WordPlus_SDK_Pickers
    {
        const OPTION = 'wordplus_pickers';

        const GIPHY_ERROR = 'wordplus_pickers_giphy_error';

        const KLIPY_ERROR = 'wordplus_pickers_klipy_error';

        const GIF_KEYS = array( 'gifsProvider', 'giphyApiKey', 'giphyContentRating', 'giphyLanguage', 'klipyApiKey', 'klipyLocale' );

        const EMOJI_KEYS = array( 'emojiSet', 'emojiSpriteDelivery', 'emojiCategories' );

        const DEFAULTS = array(
            'gifsProvider'        => '',
            'giphyApiKey'         => '',
            'giphyContentRating'  => 'g',
            'giphyLanguage'       => 'en',
            'klipyApiKey'         => '',
            'klipyLocale'         => 'en',
            'emojiSet'            => 'native',
            'emojiSpriteDelivery' => 'self-hosted',
            'emojiCategories'     => array(),
        );

        const REST_NAMESPACE = 'wordplus/v1';

        const LOADER = 'wordplus-pickers-loader';

        const APP = 'wordplus-pickers';

        const TEXT = 'wordplus-emoji-text';

        const APP_DEPS = array( 'react', 'react-dom', 'wp-i18n' );

        private $sdk;

        private $enqueued = false;

        private $settings = null;

        private $gif_providers = array();

        private $emoji = null;

        private $stickers = null;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;

            require_once __DIR__ . '/pickers/class-gif-provider.php';
        }

        public function settings()
        {
            if ( null === $this->settings ) {
                $stored         = get_option( self::OPTION, array() );
                $settings       = array_merge( self::DEFAULTS, is_array( $stored ) ? array_intersect_key( $stored, self::DEFAULTS ) : array() );
                $filtered       = apply_filters( 'wordplus_pickers_settings', $settings );
                $this->settings = is_array( $filtered ) ? array_merge( self::DEFAULTS, array_intersect_key( $filtered, self::DEFAULTS ) ) : $settings;
            }

            return $this->settings;
        }

        public function update_settings( array $values )
        {
            $stored   = get_option( self::OPTION, array() );
            $stored   = is_array( $stored ) ? $stored : array();
            $values   = array_intersect_key( $values, self::DEFAULTS );
            $before   = $this->settings();
            $next     = array_merge( $stored, $values );
            $changed  = false;
            $emoji    = false;
            $differed = array();

            foreach ( self::GIF_KEYS as $key ) {
                if ( array_key_exists( $key, $values ) && (string) $values[ $key ] !== (string) $before[ $key ] ) {
                    $changed          = true;
                    $differed[ $key ] = $values[ $key ];
                }
            }

            foreach ( self::EMOJI_KEYS as $key ) {
                if ( array_key_exists( $key, $values ) && $values[ $key ] !== $before[ $key ] ) {
                    $emoji            = true;
                    $differed[ $key ] = $values[ $key ];
                }
            }

            if ( $next !== $stored ) {
                update_option( self::OPTION, $next, false );
            }

            $this->settings      = array_merge( $before, $values );
            $this->gif_providers = array();

            if ( $changed ) {
                $this->flush_gif_cache();
            }

            if ( $emoji ) {
                $this->emoji()->delete_data();
            }

            $provider = $this->gif_provider( $this->active_gif_provider_id() );

            if ( $provider && '' !== (string) $provider->get_api_key() ) {
                $provider->check_api_key();
            }

            if ( $differed ) {
                do_action( 'wordplus_pickers_settings_updated', $differed, $this->settings );
            }
        }

        public function emoji()
        {
            if ( null === $this->emoji ) {
                require_once __DIR__ . '/pickers/class-emoji.php';
                $root        = dirname( __DIR__ );
                $this->emoji = new WordPlus_SDK_Emoji( $this, $root, plugins_url( '', $root . '/start.php' ), $this->sdk->version() );
            }

            return $this->emoji;
        }

        public function sdk()
        {
            return $this->sdk;
        }

        public function stickers()
        {
            if ( null === $this->stickers ) {
                require_once __DIR__ . '/pickers/class-stickers.php';
                $this->stickers = new WordPlus_SDK_Stickers( $this, $this->sdk->version() );
            }

            return $this->stickers;
        }

        public function enqueue( array $args = array() )
        {
            if ( $this->enqueued ) {
                return;
            }

            $this->enqueued = true;

            wp_register_script( self::APP, $this->sdk->url( 'pickers/pickers.min.js' ), self::APP_DEPS, $this->asset_version( 'pickers/pickers.min.js' ), true );
            wp_register_script( self::LOADER, $this->sdk->url( 'pickers/pickers-loader.min.js' ), array(), $this->asset_version( 'pickers/pickers-loader.min.js' ), true );
            wp_enqueue_script( self::LOADER );
            wp_add_inline_script( self::LOADER, 'window.wordplusPickersConfig = ' . wp_json_encode( $this->page_config( $args ) ) . ';', 'before' );
        }

        public function page_config( array $args = array() )
        {
            $args   = array_merge( array( 'cdn' => false, 'gifs' => true, 'dark' => false ), $args );
            $emoji  = $this->emoji();
            $images = $emoji->text_config();
            $sprite = 'native' === $images['set'] ? $emoji->sprite_url( (bool) $args['cdn'] ) : '';
            $gifs   = $args['gifs'] ? $this->active_gif_provider() : null;
            $i18n   = $this->sdk->i18n();

            return array(
                'app'       => $this->sdk->asset_url( 'pickers/pickers.min.js' ),
                'style'     => $this->sdk->asset_url( 'pickers/pickers.min.css' ),
                'scripts'   => $this->sdk->script_chain( $i18n->deps( self::APP, self::APP_DEPS ) ),
                'rest'      => array(
                    'root'  => esc_url_raw( rest_url() ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                ),
                'emojiData' => (string) $emoji->data_url(),
                'settings'  => array(
                    'emojiSet'         => '' !== $sprite || 'native' !== $images['set'] ? $emoji->set() : 'native',
                    'spritesheet'      => $sprite,
                    'emojiImages'      => 'native' !== $images['set'] ? $images['images'] . '{key}.png?v=' . $images['version'] : '',
                    'gifs'             => null !== $gifs,
                    'gifsProvider'     => $gifs ? $gifs->get_provider_id() : '',
                    'gifsProviderName' => $gifs ? $gifs->get_provider_name() : '',
                    'assetsUrl'        => $this->sdk->url( 'pickers/' ),
                    'userId'           => $this->current_user_id(),
                    'rtl'              => function_exists( 'is_rtl' ) && is_rtl(),
                    'dark'             => (bool) $args['dark'],
                ),
                'locale'    => $i18n->inline( self::APP ),
            );
        }

        public function emoji_text_script()
        {
            if ( ! wp_script_is( self::TEXT, 'registered' ) ) {
                wp_register_script( self::TEXT, $this->sdk->url( 'pickers/emoji-text.min.js' ), array(), $this->asset_version( 'pickers/emoji-text.min.js' ), true );
                wp_add_inline_script( self::TEXT, 'window.wordplusEmojiConfig = ' . wp_json_encode( $this->emoji()->text_config() ) . ';', 'before' );
            }

            return self::TEXT;
        }

        private function asset_version( $file )
        {
            return $this->sdk->asset_version( $file );
        }

        public function gif_provider( $provider_id )
        {
            $provider_id = (string) $provider_id;

            if ( ! isset( $this->gif_providers[ $provider_id ] ) ) {
                $provider = null;

                if ( 'giphy' === $provider_id ) {
                    require_once __DIR__ . '/pickers/class-gif-giphy.php';
                    $provider = new WordPlus_SDK_Gif_Giphy();
                } elseif ( 'klipy' === $provider_id ) {
                    require_once __DIR__ . '/pickers/class-gif-klipy.php';
                    $provider = new WordPlus_SDK_Gif_Klipy();
                } else {
                    $provider = apply_filters( 'wordplus_pickers_gif_provider', null, $provider_id );
                }

                if ( ! $provider instanceof WordPlus_SDK_Gif_Provider ) {
                    return null;
                }

                $this->gif_providers[ $provider_id ] = $provider;
            }

            return $this->gif_providers[ $provider_id ];
        }

        public function active_gif_provider_id()
        {
            $settings = $this->settings();

            if ( '' !== (string) $settings['gifsProvider'] ) {
                return (string) $settings['gifsProvider'];
            }

            return '' !== (string) $settings['giphyApiKey'] ? 'giphy' : 'disabled';
        }

        public function active_gif_provider()
        {
            $provider_id = $this->active_gif_provider_id();

            if ( 'disabled' === $provider_id ) {
                return null;
            }

            $provider = $this->gif_provider( $provider_id );

            return $provider && '' !== (string) $provider->get_api_key() ? $provider : null;
        }

        public function gif_providers_info()
        {
            $settings = $this->settings();

            return apply_filters( 'wordplus_pickers_gif_providers_info', array(
                array(
                    'id'           => 'giphy',
                    'name'         => 'GIPHY',
                    'features'     => array( 'trending', 'search', 'rating', 'language' ),
                    'hasGlobalKey' => '' !== (string) $settings['giphyApiKey'],
                ),
                array(
                    'id'           => 'klipy',
                    'name'         => 'KLIPY',
                    'features'     => array( 'trending', 'search', 'language' ),
                    'hasGlobalKey' => '' !== (string) $settings['klipyApiKey'],
                ),
            ) );
        }

        public function gif_key_error( $provider_id )
        {
            $option = 'klipy' === $provider_id ? self::KLIPY_ERROR : self::GIPHY_ERROR;

            return get_option( $option, false );
        }

        public function flush_gif_cache()
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like( '_transient_wplus_gifs_' ) . '%',
                $wpdb->esc_like( '_transient_timeout_wplus_gifs_' ) . '%'
            ) );
        }

        public function current_user_id()
        {
            return (int) apply_filters( 'wordplus_pickers_user_id', get_current_user_id() );
        }

        public function can_use()
        {
            return (bool) apply_filters( 'wordplus_pickers_can_use', is_user_logged_in() );
        }

        public function user_meta( $user_id, $key )
        {
            $value = apply_filters( 'wordplus_pickers_user_meta', null, (int) $user_id, (string) $key );

            if ( null !== $value ) {
                return $value;
            }

            return (int) $user_id > 0 ? get_user_meta( (int) $user_id, (string) $key, true ) : '';
        }

        public function set_user_meta( $user_id, $key, $value )
        {
            if ( apply_filters( 'wordplus_pickers_set_user_meta', false, (int) $user_id, (string) $key, $value ) ) {
                return;
            }

            if ( (int) $user_id > 0 ) {
                update_user_meta( (int) $user_id, (string) $key, $value );
            }
        }

        public function routes()
        {
            register_rest_route( self::REST_NAMESPACE, '/pickers/gifs', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_gifs' ),
                'permission_callback' => array( $this, 'can_use' ),
            ) );

            register_rest_route( self::REST_NAMESPACE, '/pickers/emoji-index', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_emoji_index' ),
                'permission_callback' => array( $this, 'can_see_emoji' ),
            ) );

            $this->stickers()->routes();
        }

        public function can_see_emoji()
        {
            return true;
        }

        public function rest_emoji_index()
        {
            $index = $this->emoji()->images_index();

            if ( ! $index ) {
                return new WP_Error( 'emoji_native', __( 'The site uses the device\'s own emoji.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            return $index;
        }

        public function rest_gifs( $request )
        {
            $provider = $this->active_gif_provider();

            if ( ! $provider ) {
                return new WP_Error( 'gifs_disabled', __( 'GIFs are not enabled.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            $page   = max( 1, (int) $request->get_param( 'page' ) );
            $search = sanitize_text_field( (string) $request->get_param( 'search' ) );

            return '' !== $search
                ? $provider->search( $this->current_user_id(), $search, $page )
                : $provider->get_trending( $this->current_user_id(), $page );
        }
    }
}
