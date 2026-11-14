<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Captcha_Provider' ) ) {
    abstract class WordPlus_SDK_Captcha_Provider
    {
        const TIMEOUT = 10;

        abstract public function id();

        abstract public function name();

        abstract public function kind();

        public function description()
        {
            return '';
        }

        public function self_hosted()
        {
            return false;
        }

        public function needs_keys()
        {
            return true;
        }

        public function secret_label()
        {
            return __( 'Secret key', 'wordplus-sdk' );
        }

        public function console_url()
        {
            return '';
        }

        public function about_url()
        {
            return '';
        }

        public function script_url( $site_key )
        {
            return '';
        }

        abstract public function verify( $token, $secret, $site_key, $ip, $place, array $settings );

        public function secret_works( $secret, $site_key )
        {
            $check = $this->verify( 'wordplus-sdk-key-check', $secret, $site_key, '', '', array() );

            if ( 'unavailable' === $check['result'] ) {
                return 'secret' === $check['code'] ? false : null;
            }

            return true;
        }

        public function client( $site_key )
        {
            return array(
                'provider' => $this->id(),
                'kind'     => $this->kind(),
                'siteKey'  => (string) $site_key,
                'script'   => $this->script_url( $site_key ),
            );
        }

        protected function post( $url, array $fields, array $headers = array(), $json = false )
        {
            $response = wp_remote_post( $url, array(
                'timeout'    => self::TIMEOUT,
                'headers'    => $json ? array_merge( array( 'Content-Type' => 'application/json' ), $headers ) : $headers,
                'body'       => $json ? wp_json_encode( $fields ) : $fields,
                'user-agent' => 'WordPress',
            ) );

            if ( is_wp_error( $response ) ) {
                return null;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

            if ( ! is_array( $body ) ) {
                return null;
            }

            $body['_status'] = $code;

            return $body;
        }

        protected function shared_answer( $body, array $secret_codes = array( 'invalid-input-secret', 'missing-input-secret' ) )
        {
            if ( null === $body ) {
                return array( 'result' => 'unavailable', 'code' => 'no-answer' );
            }

            $codes = isset( $body['error-codes'] ) && is_array( $body['error-codes'] ) ? array_map( 'strval', $body['error-codes'] ) : array();

            if ( array_intersect( $codes, $secret_codes ) ) {
                return array( 'result' => 'unavailable', 'code' => 'secret' );
            }

            if ( in_array( 'bad-request', $codes, true ) || in_array( 'internal-error', $codes, true ) ) {
                return array( 'result' => 'unavailable', 'code' => implode( ',', $codes ) );
            }

            if ( empty( $body['success'] ) ) {
                return array( 'result' => 'fail', 'code' => implode( ',', $codes ) );
            }

            return array( 'result' => 'pass', 'code' => '' );
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Captcha_Turnstile' ) ) {
    final class WordPlus_SDK_Captcha_Turnstile extends WordPlus_SDK_Captcha_Provider
    {
        const VERIFY = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

        public function id()
        {
            return 'turnstile';
        }

        public function name()
        {
            return __( 'Cloudflare Turnstile', 'wordplus-sdk' );
        }

        public function description()
        {
            return __( 'Most visitors pass automatically and the others are asked to tick a checkbox', 'wordplus-sdk' );
        }

        public function kind()
        {
            return 'visible';
        }

        public function console_url()
        {
            return 'https://dash.cloudflare.com/?to=/:account/turnstile';
        }

        public function about_url()
        {
            return 'https://www.cloudflare.com/products/turnstile/';
        }

        public function script_url( $site_key )
        {
            return 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
        }

        public function verify( $token, $secret, $site_key, $ip, $place, array $settings )
        {
            $fields = array( 'secret' => $secret, 'response' => $token );

            if ( '' !== $ip ) {
                $fields['remoteip'] = $ip;
            }

            $body   = $this->post( self::VERIFY, $fields );
            $answer = $this->shared_answer( $body );

            if ( 'pass' === $answer['result'] && '' !== $place && ! empty( $body['action'] ) && WordPlus_SDK_Captcha::action( $place ) !== $body['action'] ) {
                return array( 'result' => 'fail', 'code' => 'action' );
            }

            return $answer;
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Captcha_Recaptcha' ) ) {
    final class WordPlus_SDK_Captcha_Recaptcha extends WordPlus_SDK_Captcha_Provider
    {
        const VERIFY = 'https://www.google.com/recaptcha/api/siteverify';

        private $version;

        public function __construct( $version )
        {
            $this->version = in_array( $version, array( 'v2', 'invisible', 'v3' ), true ) ? $version : 'v2';
        }

        public function id()
        {
            return 'v2' === $this->version ? 'recaptcha' : 'recaptcha-' . $this->version;
        }

        public function name()
        {
            if ( 'v3' === $this->version ) {
                return __( 'Google reCAPTCHA v3', 'wordplus-sdk' );
            }

            return 'invisible' === $this->version ? __( 'Google reCAPTCHA v2, invisible', 'wordplus-sdk' ) : __( 'Google reCAPTCHA v2, checkbox', 'wordplus-sdk' );
        }

        public function description()
        {
            if ( 'v3' === $this->version ) {
                return __( 'Invisible to visitors. Google scores each visitor and rejects those with a low score', 'wordplus-sdk' );
            }

            if ( 'invisible' === $this->version ) {
                return __( 'No checkbox to tick. Visitors get an image challenge only when Google is not sure about them', 'wordplus-sdk' );
            }

            return __( 'Visitors tick a checkbox and are sometimes asked to solve an image challenge', 'wordplus-sdk' );
        }

        public function kind()
        {
            if ( 'v3' === $this->version ) {
                return 'score';
            }

            return 'invisible' === $this->version ? 'invisible' : 'visible';
        }

        public function console_url()
        {
            return 'https://www.google.com/recaptcha/admin';
        }

        public function about_url()
        {
            return 'https://cloud.google.com/security/products/recaptcha';
        }

        public function script_url( $site_key )
        {
            return 'https://www.google.com/recaptcha/api.js?render=explicit';
        }

        public function verify( $token, $secret, $site_key, $ip, $place, array $settings )
        {
            $fields = array( 'secret' => $secret, 'response' => $token );

            if ( '' !== $ip ) {
                $fields['remoteip'] = $ip;
            }

            $body   = $this->post( self::VERIFY, $fields );
            $answer = $this->shared_answer( $body );

            if ( 'pass' !== $answer['result'] || 'v3' !== $this->version ) {
                return $answer;
            }

            $score     = isset( $body['score'] ) ? (float) $body['score'] : 0.0;
            $threshold = isset( $settings['score'] ) ? (float) $settings['score'] : WordPlus_SDK_Captcha::SCORE;

            if ( $score < $threshold ) {
                return array( 'result' => 'fail', 'code' => 'score' );
            }

            if ( '' !== $place && ! empty( $body['action'] ) && WordPlus_SDK_Captcha::action( $place ) !== $body['action'] ) {
                return array( 'result' => 'fail', 'code' => 'action' );
            }

            return $answer;
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Captcha_Hcaptcha' ) ) {
    final class WordPlus_SDK_Captcha_Hcaptcha extends WordPlus_SDK_Captcha_Provider
    {
        const VERIFY = 'https://api.hcaptcha.com/siteverify';

        public function id()
        {
            return 'hcaptcha';
        }

        public function name()
        {
            return __( 'hCaptcha', 'wordplus-sdk' );
        }

        public function description()
        {
            return __( 'Visitors tick a checkbox and are sometimes asked to solve an image challenge', 'wordplus-sdk' );
        }

        public function kind()
        {
            return 'visible';
        }

        public function console_url()
        {
            return 'https://dashboard.hcaptcha.com/sites';
        }

        public function about_url()
        {
            return 'https://www.hcaptcha.com/';
        }

        public function script_url( $site_key )
        {
            return 'https://js.hcaptcha.com/1/api.js?render=explicit';
        }

        public function verify( $token, $secret, $site_key, $ip, $place, array $settings )
        {
            $fields = array( 'secret' => $secret, 'response' => $token );

            if ( '' !== $site_key ) {
                $fields['sitekey'] = $site_key;
            }

            if ( '' !== $ip ) {
                $fields['remoteip'] = $ip;
            }

            return $this->shared_answer( $this->post( self::VERIFY, $fields ), array( 'invalid-input-secret', 'missing-input-secret', 'invalid-or-already-seen-secret' ) );
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Captcha_Friendly' ) ) {
    final class WordPlus_SDK_Captcha_Friendly extends WordPlus_SDK_Captcha_Provider
    {
        const VERIFY = 'https://global.frcapi.com/api/v2/captcha/siteverify';

        const SCRIPT = 'https://cdn.jsdelivr.net/npm/@friendlycaptcha/sdk@1.1.3/site.compat.min.js';

        public function id()
        {
            return 'friendly';
        }

        public function name()
        {
            return __( 'Friendly Captcha', 'wordplus-sdk' );
        }

        public function description()
        {
            return __( 'The browser solves a puzzle in the background, so visitors have nothing to click', 'wordplus-sdk' );
        }

        public function kind()
        {
            return 'visible';
        }

        public function secret_label()
        {
            return __( 'API key', 'wordplus-sdk' );
        }

        public function console_url()
        {
            return 'https://app.friendlycaptcha.eu/dashboard';
        }

        public function about_url()
        {
            return 'https://developer.friendlycaptcha.com/';
        }

        public function script_url( $site_key )
        {
            return self::SCRIPT;
        }

        public function verify( $token, $secret, $site_key, $ip, $place, array $settings )
        {
            $fields = array( 'response' => $token );

            if ( '' !== $site_key ) {
                $fields['sitekey'] = $site_key;
            }

            $body = $this->post( self::VERIFY, $fields, array( 'X-API-Key' => $secret ), true );

            if ( null === $body ) {
                return array( 'result' => 'unavailable', 'code' => 'no-answer' );
            }

            $code = isset( $body['error']['error_code'] ) ? (string) $body['error']['error_code'] : '';

            if ( in_array( $code, array( 'auth_required', 'auth_invalid' ), true ) ) {
                return array( 'result' => 'unavailable', 'code' => 'secret' );
            }

            if ( in_array( $code, array( 'sitekey_invalid', 'bad_request' ), true ) ) {
                return array( 'result' => 'unavailable', 'code' => $code );
            }

            return empty( $body['success'] ) ? array( 'result' => 'fail', 'code' => $code ) : array( 'result' => 'pass', 'code' => '' );
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Captcha_Altcha' ) ) {
    final class WordPlus_SDK_Captcha_Altcha extends WordPlus_SDK_Captcha_Provider
    {
        public function id()
        {
            return 'altcha';
        }

        public function name()
        {
            return __( 'ALTCHA', 'wordplus-sdk' );
        }

        public function description()
        {
            return __( 'The browser solves a puzzle in the background, so visitors have nothing to click. It runs entirely on your site and uses no third-party service', 'wordplus-sdk' );
        }

        public function self_hosted()
        {
            return true;
        }

        public function about_url()
        {
            return 'https://altcha.org/';
        }

        public function kind()
        {
            return 'work';
        }

        public function needs_keys()
        {
            return false;
        }

        public function secret_works( $secret, $site_key )
        {
            return true;
        }

        public function verify( $token, $secret, $site_key, $ip, $place, array $settings )
        {
            return WordPlus_SDK_Captcha::check_work( (string) $token, $secret );
        }
    }
}
