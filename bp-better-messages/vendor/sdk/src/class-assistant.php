<?php

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Assistant' ) ) {

    final class WordPlus_SDK_Assistant
    {
        const TOKEN_TTL = 300;

        const ENDPOINT = 'https://rest.wordplus.cloud/v1/assistant';

        private $product;

        public function __construct( WordPlus_SDK_Product $product )
        {
            $this->product = $product;
        }

        private function config()
        {
            $config = $this->product->get( 'assistant', array() );

            return is_array( $config ) ? $config : array();
        }

        public function enabled()
        {
            $config = $this->config();

            return ! empty( $config['name'] ) && '' !== $this->endpoint() && (bool) apply_filters( 'wordplus_sdk_assistant_enabled', true, $this->product->slug() );
        }

        public function endpoint()
        {
            return untrailingslashit( defined( 'WORDPLUS_ASSISTANT_ENDPOINT' ) ? (string) WORDPLUS_ASSISTANT_ENDPOINT : self::ENDPOINT );
        }

        private function dev_secret()
        {
            return defined( 'WORDPLUS_ASSISTANT_SECRET' ) ? (string) WORDPLUS_ASSISTANT_SECRET : '';
        }

        private function install_option()
        {
            return 'wordplus_assistant_install_' . str_replace( '-', '_', $this->product->slug() );
        }

        public function install( $renew = false )
        {
            if ( '' !== $this->dev_secret() ) {
                return array( 'id' => 0, 'secret' => $this->dev_secret() );
            }

            $saved = get_option( $this->install_option() );

            if ( ! $renew && is_array( $saved ) && ! empty( $saved['id'] ) && ! empty( $saved['secret'] ) && isset( $saved['url'] ) && home_url() === $saved['url'] ) {
                return array( 'id' => (int) $saved['id'], 'secret' => (string) $saved['secret'] );
            }

            $answer = $this->product->licence()->request( 'api/v1/assistant/register', array(
                'plugin'  => $this->product->slug(),
                'url'     => home_url(),
                'build'   => $this->product->is_paid_build() ? 'paid' : 'free',
                'version' => $this->product->version(),
            ) );

            if ( is_wp_error( $answer ) || empty( $answer['install']['id'] ) || empty( $answer['install']['secret'] ) ) {
                return new WP_Error( 'wordplus_assistant_register', _x( 'WordPlus Cloud couldn\'t be reached. Please try again in a minute.', 'Assistant', 'wordplus-sdk' ), array( 'status' => 503 ) );
            }

            $install = array( 'id' => (int) $answer['install']['id'], 'secret' => (string) $answer['install']['secret'], 'url' => home_url() );
            update_option( $this->install_option(), $install, false );

            return array( 'id' => $install['id'], 'secret' => $install['secret'] );
        }

        private function consent_meta()
        {
            return 'wordplus_assistant_consent_' . str_replace( '-', '_', $this->product->slug() );
        }

        public function consented()
        {
            return (int) get_user_meta( get_current_user_id(), $this->consent_meta(), true );
        }

        public function link()
        {
            if ( ! $this->enabled() || ! current_user_can( 'manage_options' ) ) {
                return null;
            }

            $url = $this->product->page_url( 'contact' );

            return '' === $url ? null : array(
                'url'   => $url . '#wplus-assistant',
                'label' => _x( 'Ask AI', 'Assistant: the button in the page headers', 'wordplus-sdk' ),
            );
        }

        public function page_data()
        {
            $config   = $this->config();
            $user     = wp_get_current_user();
            $links    = isset( $config['links'] ) && is_array( $config['links'] ) ? $config['links'] : array();
            $examples = isset( $config['examples'] ) && is_callable( $config['examples'] ) ? (array) call_user_func( $config['examples'] ) : array();

            return array(
                'name'      => (string) $config['name'],
                'avatar'    => ! empty( $config['avatar'] ) ? (string) $config['avatar'] : (string) $this->product->get( 'icon' ),
                'consented' => $this->consented() > 0,
                'renderer'  => ! empty( $config['renderer'] ),
                'links'     => array(
                    'setting' => isset( $links['setting'] ) ? admin_url( (string) $links['setting'] ) : '',
                    'stage'   => isset( $links['stage'] ) ? admin_url( (string) $links['stage'] ) : '',
                ),
                'examples'  => array_values( array_filter( array_map( 'strval', $examples ) ) ),
                'user'      => array(
                    'id'         => (int) $user->ID,
                    'name'       => (string) $user->display_name,
                    'first_name' => (string) $user->first_name,
                    'avatar'     => (string) get_avatar_url( $user->ID, array( 'size' => 64 ) ),
                ),
                'licence'   => $this->licence_state(),
                'trial'     => $this->trial_available(),
                'storage'   => 'wplus-assistant-' . substr( md5( home_url() . '|' . $this->product->slug() . '|' . $user->ID ), 0, 12 ),
            );
        }

        public function enqueue()
        {
            $config = $this->config();

            if ( $this->enabled() && ! empty( $config['renderer'] ) && is_callable( $config['renderer'] ) ) {
                call_user_func( $config['renderer'] );
            }
        }

        public function routes( $namespace, $base, $permission )
        {
            register_rest_route( $namespace, $base . '/assistant/consent', array(
                array(
                    'methods'             => 'POST',
                    'callback'            => function () {
                        $install = $this->install();

                        if ( is_wp_error( $install ) ) {
                            return $install;
                        }

                        update_user_meta( get_current_user_id(), $this->consent_meta(), time() );

                        return array( 'consented' => true );
                    },
                    'permission_callback' => $permission,
                ),
                array(
                    'methods'             => 'DELETE',
                    'callback'            => function () {
                        delete_user_meta( get_current_user_id(), $this->consent_meta() );

                        return array( 'consented' => false );
                    },
                    'permission_callback' => $permission,
                ),
            ) );

            register_rest_route( $namespace, $base . '/assistant/session', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_session' ),
                'permission_callback' => $permission,
            ) );
        }

        public function rest_session( $request = null )
        {
            if ( $this->consented() <= 0 ) {
                return new WP_Error( 'wordplus_assistant_consent', _x( 'Press Start before asking the assistant.', 'Assistant', 'wordplus-sdk' ), array( 'status' => 403 ) );
            }

            $install = $this->install( $request instanceof WP_REST_Request && (bool) $request->get_param( 'renew' ) );

            if ( is_wp_error( $install ) ) {
                return $install;
            }

            $expires = time() + self::TOKEN_TTL;

            return array(
                'endpoint' => $this->endpoint(),
                'token'    => $this->token( $install, $expires ),
                'expires'  => $expires,
                'site'     => $this->context(),
            );
        }

        private function token( array $install, $expires )
        {
            $payload = array(
                'p' => $this->product->slug(),
                's' => home_url(),
                'u' => get_current_user_id(),
                'e' => $expires,
            );

            if ( $install['id'] > 0 ) {
                $payload = array( 'i' => $install['id'] ) + $payload;
            }

            $body = $this->b64( (string) wp_json_encode( $payload ) );

            return $body . '.' . $this->b64( hash_hmac( 'sha256', $body, $install['secret'], true ) );
        }

        private function b64( $bytes )
        {
            return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
        }

        private function licence_state()
        {
            if ( $this->product->has_access() ) {
                return 'active';
            }

            return '' !== $this->product->licence()->get_key() ? 'expired' : 'none';
        }

        private function trial_available()
        {
            return 'none' === $this->licence_state() && ! $this->product->trial()->used();
        }

        public function context()
        {
            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $plugins = array();

            foreach ( get_plugins() as $file => $plugin ) {
                if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
                    $plugins[] = $plugin['Name'] . ' ' . $plugin['Version'];
                }
            }

            $theme = wp_get_theme();
            $env   = array(
                'product'         => $this->product->name() . ' ' . $this->product->version(),
                'build'           => $this->product->is_paid_build() ? 'paid' : 'free',
                'licence'         => $this->licence_state(),
                'trial_available' => $this->trial_available() ? 'yes' : 'no',
                'locale'          => get_user_locale(),
                'wordpress'       => get_bloginfo( 'version' ) . ( is_multisite() ? ', multisite' : '' ),
                'php'             => PHP_VERSION,
                'theme'           => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ')' : '' ),
                'active_plugins'  => $plugins,
            );

            $config = $this->config();
            $own    = isset( $config['context'] ) && is_callable( $config['context'] ) ? (array) call_user_func( $config['context'] ) : array();

            return array(
                'env'      => array_merge( $env, isset( $own['env'] ) && is_array( $own['env'] ) ? $own['env'] : array() ),
                'settings' => isset( $own['settings'] ) && is_array( $own['settings'] ) ? (object) $own['settings'] : new stdClass(),
                'defaults' => isset( $own['defaults'] ) && is_array( $own['defaults'] ) ? (object) $own['defaults'] : new stdClass(),
            );
        }

        public function privacy_content()
        {
            if ( ! function_exists( 'wp_add_privacy_policy_content' ) || ! $this->enabled() ) {
                return;
            }

            wp_add_privacy_policy_content(
                $this->product->name(),
                '<p>' . esc_html( sprintf(
                    /* translators: 1: the plugin's name, 2: the assistant's name */
                    _x( 'When an administrator asks %2$s on the %1$s Support page, the question, the site\'s address, the plugin\'s settings (without keys or passwords), the plugin, WordPress, PHP and theme versions, the active plugins and the administrator\'s language are sent to WordPlus Cloud, which answers it. Conversations are stored and read by the plugin\'s team to improve the assistant. Nothing is sent before the administrator presses Start, and visitors\' data is never sent.', 'Assistant', 'wordplus-sdk' ),
                    $this->product->name(),
                    (string) $this->config()['name']
                ) ) . '</p>'
            );
        }
    }
}
