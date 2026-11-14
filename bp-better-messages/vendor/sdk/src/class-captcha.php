<?php
defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/captcha/class-captcha-provider.php';

if ( ! class_exists( 'WordPlus_SDK_Captcha' ) ) {
    final class WordPlus_SDK_Captcha
    {
        const OPTION = 'wordplus_captcha';

        const LABEL = 'wordplus-sdk-captcha';

        const CONSTANTS = array(
            'provider' => 'WORDPLUS_CAPTCHA_PROVIDER',
            'site_key' => 'WORDPLUS_CAPTCHA_SITE_KEY',
            'secret'   => 'WORDPLUS_CAPTCHA_SECRET_KEY',
        );

        const SCORE = 0.5;

        const WORK_TTL = 600;

        const WORK_MAX = 50000;

        const HANDLE = 'wordplus-captcha';

        const ADMIN = 'wordplus-captcha-admin';

        const REST_NAMESPACE = 'wordplus/v1';

        private $sdk;

        private $all = null;

        private $providers = null;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        private function all()
        {
            if ( null === $this->all ) {
                $all       = get_option( self::OPTION, array() );
                $all       = is_array( $all ) ? $all : array();
                $this->all = array_merge( array(
                    'provider' => '',
                    'site_key' => '',
                    'secret'   => '',
                    'end'      => '',
                    'score'    => self::SCORE,
                    'working'  => true,
                    'kind'     => '',
                    'checked'  => 0,
                    'by'       => '',
                    'work_key' => '',
                    'imported' => false,
                ), $all );
            }

            return $this->all;
        }

        private function put( array $all )
        {
            $this->all = $all;
            update_option( self::OPTION, $all, false );
        }

        public function forget()
        {
            $this->all = null;
        }

        private static function constant( $field )
        {
            $name = self::CONSTANTS[ $field ];

            return defined( $name ) && is_string( constant( $name ) ) ? trim( (string) constant( $name ) ) : '';
        }

        public function providers()
        {
            if ( null === $this->providers ) {
                $list = array(
                    new WordPlus_SDK_Captcha_Altcha(),
                    new WordPlus_SDK_Captcha_Turnstile(),
                    new WordPlus_SDK_Captcha_Recaptcha( 'v2' ),
                    new WordPlus_SDK_Captcha_Recaptcha( 'invisible' ),
                    new WordPlus_SDK_Captcha_Recaptcha( 'v3' ),
                    new WordPlus_SDK_Captcha_Hcaptcha(),
                    new WordPlus_SDK_Captcha_Friendly(),
                );

                $list = apply_filters( 'wordplus_captcha_providers', $list );

                $this->providers = array();

                foreach ( (array) $list as $provider ) {
                    if ( $provider instanceof WordPlus_SDK_Captcha_Provider && 1 === preg_match( '/^[a-z0-9][a-z0-9\-]{1,30}$/', (string) $provider->id() ) ) {
                        $this->providers[ $provider->id() ] = $provider;
                    }
                }
            }

            return $this->providers;
        }

        public function provider()
        {
            $constant = self::constant( 'provider' );
            $id       = '' !== $constant ? $constant : (string) $this->all()['provider'];
            $all      = $this->providers();

            return isset( $all[ $id ] ) ? $all[ $id ] : null;
        }

        public function site_key()
        {
            $constant = self::constant( 'site_key' );

            return '' !== $constant ? $constant : (string) $this->all()['site_key'];
        }

        private function secret()
        {
            $constant = self::constant( 'secret' );

            if ( '' !== $constant ) {
                return $constant;
            }

            $sealed = (string) $this->all()['secret'];

            return '' === $sealed ? '' : self::open( $sealed );
        }

        public function ready()
        {
            $provider = $this->provider();

            if ( null === $provider ) {
                return false;
            }

            return ! $provider->needs_keys() || ( '' !== $this->site_key() && '' !== $this->secret() );
        }

        public function client( $place = '' )
        {
            if ( ! $this->ready() ) {
                return null;
            }

            $provider = $this->provider();
            $client   = $provider->client( $provider->needs_keys() ? $this->site_key() : '' );

            $client['action'] = self::action( (string) $place );

            if ( 'work' === $provider->kind() ) {
                $client['challenge'] = rest_url( self::REST_NAMESPACE . '/captcha/challenge' );
            }

            return $client;
        }

        public static function action( $place )
        {
            return substr( (string) preg_replace( '/[^A-Za-z0-9_]+/', '_', (string) $place ), 0, 32 );
        }

        public function verify( $token, $place = '' )
        {
            if ( ! $this->ready() ) {
                return array( 'result' => 'off', 'code' => '' );
            }

            $provider = $this->provider();
            $token    = trim( (string) $token );

            if ( '' === $token ) {
                return array( 'result' => 'fail', 'code' => 'missing' );
            }

            if ( strlen( $token ) > 8192 ) {
                return array( 'result' => 'fail', 'code' => 'too-long' );
            }

            $secret = 'work' === $provider->kind() ? $this->work_key() : $this->secret();
            $ip     = $this->client_ip();
            $answer = $provider->verify( $token, $secret, $this->site_key(), $ip, (string) $place, $this->all() );
            $answer = array_merge( array( 'result' => 'unavailable', 'code' => '' ), is_array( $answer ) ? $answer : array() );

            if ( 'unavailable' === $answer['result'] && 'secret' === $answer['code'] ) {
                $this->mark( false, 'secret' );
            } else if ( 'pass' === $answer['result'] && ! $this->all()['working'] ) {
                $this->mark( true );
            }

            return $answer;
        }

        public function check( $token, $place = '' )
        {
            $answer = $this->verify( $token, $place );

            if ( 'pass' === $answer['result'] || 'off' === $answer['result'] ) {
                return true;
            }

            if ( 'fail' === $answer['result'] && 'missing' === $answer['code'] ) {
                return new WP_Error( 'wordplus_captcha_required', __( 'Please complete the captcha.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            if ( 'fail' === $answer['result'] ) {
                return new WP_Error( 'wordplus_captcha_failed', __( 'The captcha was not passed. Please try again.', 'wordplus-sdk' ), array( 'status' => 403 ) );
            }

            return new WP_Error( 'wordplus_captcha_unavailable', __( 'The captcha cannot be checked right now. Please try again later.', 'wordplus-sdk' ), array( 'status' => 503 ) );
        }

        private function client_ip()
        {
            require_once $this->sdk->path( 'src/class-client.php' );

            return WordPlus_SDK_Client::ip();
        }

        private function mark( $working, $kind = '' )
        {
            $all            = $this->all();
            $all['working'] = (bool) $working;
            $all['kind']    = $working ? '' : (string) $kind;
            $all['checked'] = time();
            $this->put( $all );
        }

        public function save( array $fields, $by = '' )
        {
            $all       = $this->all();
            $providers = $this->providers();

            if ( array_key_exists( 'provider', $fields ) ) {
                $id              = sanitize_key( (string) $fields['provider'] );
                $all['provider'] = isset( $providers[ $id ] ) ? $id : '';
            }

            if ( array_key_exists( 'site_key', $fields ) ) {
                $all['site_key'] = substr( (string) preg_replace( '/[^A-Za-z0-9_\-.]/', '', (string) $fields['site_key'] ), 0, 200 );
            }

            if ( array_key_exists( 'score', $fields ) ) {
                $all['score'] = max( 0.1, min( 0.9, round( (float) $fields['score'], 1 ) ) );
            }

            if ( ! empty( $fields['clear_secret'] ) ) {
                $all['secret'] = '';
                $all['end']    = '';
            }

            $secret = isset( $fields['secret'] ) ? trim( (string) $fields['secret'] ) : '';

            if ( '' !== $secret ) {
                $all['secret'] = self::seal( substr( $secret, 0, 300 ) );
                $all['end']    = strlen( $secret ) > 8 ? substr( $secret, -4 ) : '';
            }

            $all['by'] = (string) $by;
            $this->put( $all );

            $provider = $this->provider();

            if ( null !== $provider && $provider->needs_keys() && '' !== $this->secret() && ( '' !== $secret || array_key_exists( 'provider', $fields ) ) ) {
                $works = $provider->secret_works( $this->secret(), $this->site_key() );

                if ( null !== $works ) {
                    $this->mark( $works, $works ? '' : 'secret' );
                }
            }

            return $this->present();
        }

        public function present()
        {
            $all       = $this->all();
            $provider  = $this->provider();
            $constants = array();

            foreach ( array_keys( self::CONSTANTS ) as $field ) {
                $constants[ $field ] = '' !== self::constant( $field );
            }

            $secret_constant = self::constant( 'secret' );

            return array(
                'provider'  => null === $provider ? '' : $provider->id(),
                'providers' => array_values( array_map( function ( WordPlus_SDK_Captcha_Provider $one ) {
                    return array(
                        'id'          => $one->id(),
                        'name'        => $one->name(),
                        'description' => (string) $one->description(),
                        'selfHosted'  => (bool) $one->self_hosted(),
                        'about'       => (string) $one->about_url(),
                        'kind'        => $one->kind(),
                        'keys'        => $one->needs_keys(),
                        'secretLabel' => $one->secret_label(),
                        'console'     => $one->console_url(),
                    );
                }, $this->providers() ) ),
                'siteKey'   => $this->site_key(),
                'secret'    => array(
                    'set' => '' !== $secret_constant || '' !== (string) $all['secret'],
                    'end' => '' !== $secret_constant ? ( strlen( $secret_constant ) > 8 ? substr( $secret_constant, -4 ) : '' ) : (string) $all['end'],
                ),
                'score'     => (float) $all['score'],
                'ready'     => $this->ready(),
                'working'   => (bool) $all['working'],
                'kind'      => (string) $all['kind'],
                'checked'   => (int) $all['checked'],
                'constants' => $constants,
            );
        }

        public function import_live_support()
        {
            $all = $this->all();

            if ( ! empty( $all['imported'] ) || '' !== (string) $all['provider'] ) {
                return false;
            }

            $all['imported'] = true;
            $this->put( $all );

            if ( ! class_exists( 'WordPlus_Support_Settings' ) || ! class_exists( 'WordPlus_Support_Secrets' ) || ! method_exists( 'WordPlus_Support_Secrets', 'get' ) ) {
                return false;
            }

            $site_key = (string) WordPlus_Support_Settings::get( 'turnstileSite' );
            $secret   = (string) WordPlus_Support_Secrets::get( 'turnstile' );

            if ( '' === $site_key || '' === $secret ) {
                return false;
            }

            $this->save( array( 'provider' => 'turnstile', 'site_key' => $site_key, 'secret' => $secret ), 'wordplus-support' );

            return true;
        }

        private function work_key()
        {
            $all    = $this->all();
            $opened = '' === (string) $all['work_key'] ? '' : self::open( (string) $all['work_key'] );

            if ( '' !== $opened ) {
                return $opened;
            }

            $key             = bin2hex( random_bytes( 32 ) );
            $all['work_key'] = self::seal( $key );
            $this->put( $all );

            return $key;
        }

        public function challenge()
        {
            $max    = max( 1000, min( 1000000, (int) apply_filters( 'wordplus_captcha_work_max', self::WORK_MAX ) ) );
            $salt   = bin2hex( random_bytes( 12 ) ) . '?expires=' . ( time() + self::WORK_TTL );
            $number = random_int( 0, $max );
            $hash   = hash( 'sha256', $salt . $number );

            return array(
                'algorithm' => 'SHA-256',
                'challenge' => $hash,
                'maxnumber' => $max,
                'salt'      => $salt,
                'signature' => hash_hmac( 'sha256', $hash, $this->work_key() ),
            );
        }

        public static function check_work( $payload, $key )
        {
            $json = json_decode( (string) base64_decode( (string) $payload, true ), true );

            if ( ! is_array( $json ) || ! isset( $json['algorithm'], $json['challenge'], $json['number'], $json['salt'], $json['signature'] ) ) {
                return array( 'result' => 'fail', 'code' => 'shape' );
            }

            if ( 'SHA-256' !== $json['algorithm'] || ! is_numeric( $json['number'] ) || '' === (string) $key ) {
                return array( 'result' => 'fail', 'code' => 'shape' );
            }

            $salt    = (string) $json['salt'];
            $expires = 0;

            if ( false !== strpos( $salt, '?' ) ) {
                parse_str( (string) substr( $salt, strpos( $salt, '?' ) + 1 ), $params );
                $expires = isset( $params['expires'] ) ? (int) $params['expires'] : 0;
            }

            if ( $expires < time() ) {
                return array( 'result' => 'fail', 'code' => 'expired' );
            }

            $challenge = (string) $json['challenge'];

            if ( ! hash_equals( hash_hmac( 'sha256', $challenge, (string) $key ), (string) $json['signature'] ) ) {
                return array( 'result' => 'fail', 'code' => 'signature' );
            }

            if ( ! hash_equals( $challenge, hash( 'sha256', $salt . (int) $json['number'] ) ) ) {
                return array( 'result' => 'fail', 'code' => 'number' );
            }

            $seen = 'wordplus_captcha_' . substr( hash( 'sha256', $challenge ), 0, 32 );

            if ( false !== get_transient( $seen ) ) {
                return array( 'result' => 'fail', 'code' => 'replay' );
            }

            set_transient( $seen, 1, max( 60, $expires - time() ) );

            return array( 'result' => 'pass', 'code' => '' );
        }

        public function script()
        {
            if ( ! wp_script_is( self::HANDLE, 'registered' ) ) {
                wp_register_script( self::HANDLE, $this->sdk->url( 'captcha/captcha.min.js' ), $this->sdk->i18n()->deps( self::HANDLE, array( 'wp-i18n' ) ), $this->sdk->asset_version( 'captcha/captcha.min.js' ), true );
            }

            return self::HANDLE;
        }

        public function enqueue_admin()
        {
            $this->import_live_support();

            require_once $this->sdk->path( 'src/class-ui.php' );
            WordPlus_SDK_UI::enqueue();

            wp_register_script( self::ADMIN, $this->sdk->url( 'captcha/captcha-admin.min.js' ), $this->sdk->i18n()->deps( self::ADMIN, array( WordPlus_SDK_UI::UI, 'wp-element', 'wp-i18n', 'wp-api-fetch' ) ), WordPlus_SDK_UI::asset_version( 'captcha/captcha-admin.min.js' ), true );
            wp_enqueue_script( self::ADMIN );
            wp_add_inline_script( self::ADMIN, 'window.wordplusCaptchaData = ' . wp_json_encode( array(
                'rest'  => rest_url( self::REST_NAMESPACE . '/captcha' ),
                'state' => $this->present(),
            ) ) . ';', 'before' );
        }

        public function routes()
        {
            register_rest_route( self::REST_NAMESPACE, '/captcha', array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array( $this, 'rest_state' ),
                    'permission_callback' => array( $this, 'can_manage' ),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array( $this, 'rest_save' ),
                    'permission_callback' => array( $this, 'can_manage' ),
                ),
            ) );

            register_rest_route( self::REST_NAMESPACE, '/captcha/challenge', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_challenge' ),
                'permission_callback' => array( $this, 'can_ask_challenge' ),
            ) );
        }

        public function can_ask_challenge()
        {
            $provider = $this->provider();

            return null !== $provider && 'work' === $provider->kind();
        }

        public function can_manage()
        {
            return (bool) apply_filters( 'wordplus_captcha_can_manage', current_user_can( 'manage_options' ) );
        }

        public function rest_state()
        {
            return $this->present();
        }

        public function rest_save( $request )
        {
            $fields = array();

            foreach ( array( 'provider', 'site_key', 'secret', 'score', 'clear_secret' ) as $field ) {
                $value = $request->get_param( $field );

                if ( null !== $value ) {
                    $fields[ $field ] = $value;
                }
            }

            return $this->save( $fields, (string) $request->get_param( 'by' ) );
        }

        public function rest_challenge()
        {
            $provider = $this->provider();

            if ( null === $provider || 'work' !== $provider->kind() ) {
                return new WP_Error( 'wordplus_captcha_off', __( 'This captcha is not enabled on this site.', 'wordplus-sdk' ), array( 'status' => 404 ) );
            }

            if ( function_exists( 'nocache_headers' ) ) {
                nocache_headers();
            }

            return $this->challenge();
        }

        private static function secret_box_key()
        {
            return hash_hmac( 'sha256', self::LABEL, wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' ), true );
        }

        public static function seal( $plain )
        {
            if ( function_exists( 'sodium_crypto_secretbox' ) ) {
                $nonce = random_bytes( 24 );

                return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::secret_box_key() ) );
            }

            $iv     = random_bytes( 12 );
            $tag    = '';
            $cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', self::secret_box_key(), OPENSSL_RAW_DATA, $iv, $tag );

            return 'o1:' . base64_encode( $iv . $tag . (string) $cipher );
        }

        public static function open( $sealed )
        {
            $sealed = (string) $sealed;
            $raw    = base64_decode( (string) substr( $sealed, 3 ), true );

            if ( false === $raw ) {
                return '';
            }

            if ( 0 === strpos( $sealed, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > 24 ) {
                try {
                    $plain = sodium_crypto_secretbox_open( (string) substr( $raw, 24 ), (string) substr( $raw, 0, 24 ), self::secret_box_key() );
                } catch ( \Exception $e ) {
                    $plain = false;
                }

                return false === $plain ? '' : (string) $plain;
            }

            if ( 0 === strpos( $sealed, 'o1:' ) && strlen( $raw ) > 28 ) {
                $plain = openssl_decrypt( (string) substr( $raw, 28 ), 'aes-256-gcm', self::secret_box_key(), OPENSSL_RAW_DATA, (string) substr( $raw, 0, 12 ), (string) substr( $raw, 12, 16 ) );

                return false === $plain ? '' : (string) $plain;
            }

            return '';
        }
    }
}
