<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Keys' ) ) {
    final class WordPlus_SDK_AI_Keys
    {
        const OPTION = 'wordplus_ai_keys';

        const LABEL = 'wordplus-sdk-ai-keys';

        const CONSTANTS = array(
            'openai'    => 'WORDPLUS_AI_OPENAI_KEY',
            'anthropic' => 'WORDPLUS_AI_ANTHROPIC_KEY',
            'gemini'    => 'WORDPLUS_AI_GEMINI_KEY',
        );

        private $all = null;

        private function all()
        {
            if ( null === $this->all ) {
                $all       = get_option( self::OPTION, array() );
                $all       = is_array( $all ) ? $all : array();
                $this->all = array_merge( array( 'site' => array(), 'owners' => array(), 'models' => array(), 'constants' => array() ), $all );
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

        public static function valid_owner( $owner )
        {
            return '' === $owner || ( is_string( $owner ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_\-]{1,40}(:[A-Za-z0-9_\-]{1,40}){1,3}$/', $owner ) );
        }

        private static function valid_provider( $provider )
        {
            return is_string( $provider ) && 1 === preg_match( '/^[a-z0-9][a-z0-9_\-]{1,30}$/', $provider );
        }

        private function entry( $provider, $owner = '' )
        {
            $all = $this->all();

            if ( '' === $owner ) {
                return isset( $all['site'][ $provider ]['key'] ) ? $all['site'][ $provider ] : null;
            }

            return isset( $all['owners'][ $owner ][ $provider ]['key'] ) ? $all['owners'][ $owner ][ $provider ] : null;
        }

        public function constant( $provider )
        {
            $name = isset( self::CONSTANTS[ $provider ] ) ? self::CONSTANTS[ $provider ] : '';

            return '' !== $name && defined( $name ) && is_string( constant( $name ) ) ? trim( (string) constant( $name ) ) : '';
        }

        public function get( $provider, $owner = '' )
        {
            if ( '' !== $owner ) {
                $entry = $this->entry( $provider, $owner );
                $plain = null === $entry ? '' : self::open( (string) $entry['key'] );

                if ( '' !== $plain ) {
                    return $plain;
                }
            }

            $constant = $this->constant( $provider );
            if ( '' !== $constant ) {
                return $constant;
            }

            $entry = $this->entry( $provider );

            return null === $entry ? '' : self::open( (string) $entry['key'] );
        }

        public function has( $provider, $owner = '' )
        {
            return ( '' !== $owner && null !== $this->entry( $provider, $owner ) ) || '' !== $this->constant( $provider ) || null !== $this->entry( $provider );
        }

        public function providers()
        {
            $all  = $this->all();
            $have = array_keys( array_filter( $all['site'], function ( $entry ) {
                return is_array( $entry ) && ! empty( $entry['key'] );
            } ) );

            foreach ( array_keys( self::CONSTANTS ) as $provider ) {
                if ( '' !== $this->constant( $provider ) ) {
                    $have[] = $provider;
                }
            }

            return array_values( array_unique( $have ) );
        }

        public function save( $provider, $key, $owner = '', array $check = array() )
        {
            $key = trim( (string) $key );

            if ( ! self::valid_provider( $provider ) || ! self::valid_owner( $owner ) ) {
                return false;
            }

            if ( '' === $key ) {
                $this->remove( $provider, $owner );

                return true;
            }

            $entry = array(
                'key'     => self::seal( $key ),
                'end'     => self::end( $key ),
                'working' => ! array_key_exists( 'working', $check ) || ! empty( $check['working'] ),
                'kind'    => isset( $check['kind'] ) ? (string) $check['kind'] : '',
                'said'    => isset( $check['said'] ) ? (string) $check['said'] : '',
                'checked' => array_key_exists( 'working', $check ) ? time() : 0,
                'by'      => isset( $check['by'] ) ? (string) $check['by'] : '',
            );

            $all = $this->all();
            if ( '' === $owner ) {
                $all['site'][ $provider ] = $entry;
            } else {
                $all['owners'][ $owner ][ $provider ] = $entry;
            }
            $this->put( $all );

            return true;
        }

        public function mark( $provider, $working, $kind = '', $said = '', $owner = '' )
        {
            $check = array(
                'working' => (bool) $working,
                'kind'    => $working ? '' : (string) $kind,
                'said'    => $working ? '' : (string) $said,
                'checked' => time(),
            );

            $constant = '' === $owner ? $this->constant( $provider ) : '';
            if ( '' !== $constant ) {
                $all                            = $this->all();
                $all['constants'][ $provider ] = array( 'of' => self::fingerprint( $constant ) ) + $check;
                $this->put( $all );

                return;
            }

            $entry = $this->entry( $provider, $owner );
            if ( null === $entry ) {
                return;
            }

            $entry = array_merge( $entry, $check );

            $all = $this->all();
            if ( '' === $owner ) {
                $all['site'][ $provider ] = $entry;
            } else {
                $all['owners'][ $owner ][ $provider ] = $entry;
            }
            $this->put( $all );
        }

        public function remove( $provider, $owner = '' )
        {
            $all = $this->all();

            if ( '' === $owner ) {
                unset( $all['site'][ $provider ], $all['models'][ $provider ] );
            } else {
                unset( $all['owners'][ $owner ][ $provider ] );
                if ( empty( $all['owners'][ $owner ] ) ) {
                    unset( $all['owners'][ $owner ] );
                }
            }

            $this->put( $all );
        }

        public function forget_owner( $owner )
        {
            $all = $this->all();

            if ( isset( $all['owners'][ $owner ] ) ) {
                unset( $all['owners'][ $owner ] );
                $this->put( $all );
            }
        }

        public function present( $provider, $owner = '' )
        {
            $constant = '' === $owner ? $this->constant( $provider ) : '';
            $entry    = $this->entry( $provider, $owner );

            if ( '' !== $constant ) {
                $all   = $this->all();
                $check = isset( $all['constants'][ $provider ]['of'] ) && self::fingerprint( $constant ) === $all['constants'][ $provider ]['of'] ? $all['constants'][ $provider ] : array();

                return array(
                    'set'      => true,
                    'end'      => self::end( $constant ),
                    'working'  => ! array_key_exists( 'working', $check ) || ! empty( $check['working'] ),
                    'kind'     => isset( $check['kind'] ) ? (string) $check['kind'] : '',
                    'said'     => isset( $check['said'] ) ? (string) $check['said'] : '',
                    'checked'  => isset( $check['checked'] ) ? (int) $check['checked'] : 0,
                    'constant' => true,
                    'shared'   => true,
                );
            }

            if ( null === $entry ) {
                return array( 'set' => false, 'end' => '', 'working' => false, 'kind' => '', 'said' => '', 'checked' => 0, 'constant' => false, 'shared' => '' === $owner );
            }

            return array(
                'set'      => true,
                'end'      => (string) $entry['end'],
                'working'  => ! empty( $entry['working'] ),
                'kind'     => (string) $entry['kind'],
                'said'     => (string) $entry['said'],
                'checked'  => (int) $entry['checked'],
                'constant' => false,
                'shared'   => '' === $owner,
            );
        }

        public function adopt( $provider, $plain, $owner = '', $by = '' )
        {
            $plain = trim( (string) $plain );

            if ( '' === $plain ) {
                return false;
            }

            if ( null !== $this->entry( $provider, $owner ) ) {
                return true;
            }

            return $this->save( $provider, $plain, $owner, array( 'by' => (string) $by ) );
        }

        public function import( $provider, $sealed, $label, $owner = '', array $check = array() )
        {
            if ( null !== $this->entry( $provider, $owner ) ) {
                return true;
            }

            $plain = self::open( (string) $sealed, (string) $label );

            return '' !== $plain && $this->save( $provider, $plain, $owner, $check );
        }

        public function models( $provider )
        {
            $all = $this->all();

            return isset( $all['models'][ $provider ] ) && is_array( $all['models'][ $provider ] ) ? array_values( array_map( 'strval', $all['models'][ $provider ] ) ) : null;
        }

        public function keep_models( $provider, array $models )
        {
            $all                         = $this->all();
            $all['models'][ $provider ] = array_values( array_map( 'strval', $models ) );
            $this->put( $all );
        }

        private static function fingerprint( $key )
        {
            return substr( hash_hmac( 'sha256', (string) $key, self::secret( self::LABEL . ' check' ) ), 0, 16 );
        }

        public static function end( $key )
        {
            return strlen( (string) $key ) > 8 ? substr( (string) $key, -4 ) : '';
        }

        private static function secret( $label = self::LABEL )
        {
            return hash_hmac( 'sha256', (string) $label, wp_salt( 'secure_auth' ) . wp_salt( 'logged_in' ), true );
        }

        public static function seal( $plain, $label = self::LABEL )
        {
            if ( function_exists( 'sodium_crypto_secretbox' ) ) {
                $nonce = random_bytes( 24 );

                return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( (string) $plain, $nonce, self::secret( $label ) ) );
            }

            $iv     = random_bytes( 12 );
            $tag    = '';
            $cipher = openssl_encrypt( (string) $plain, 'aes-256-gcm', self::secret( $label ), OPENSSL_RAW_DATA, $iv, $tag );

            return 'o1:' . base64_encode( $iv . $tag . (string) $cipher );
        }

        public static function open( $sealed, $label = self::LABEL )
        {
            $sealed = (string) $sealed;
            $raw    = base64_decode( (string) substr( $sealed, 3 ), true );

            if ( false === $raw ) {
                return '';
            }

            if ( 0 === strpos( $sealed, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) && strlen( $raw ) > 24 ) {
                try {
                    $plain = sodium_crypto_secretbox_open( (string) substr( $raw, 24 ), (string) substr( $raw, 0, 24 ), self::secret( $label ) );
                } catch ( \Exception $e ) {
                    $plain = false;
                }

                return false === $plain ? '' : (string) $plain;
            }

            if ( 0 === strpos( $sealed, 'o1:' ) && strlen( $raw ) > 28 ) {
                $plain = openssl_decrypt( (string) substr( $raw, 28 ), 'aes-256-gcm', self::secret( $label ), OPENSSL_RAW_DATA, (string) substr( $raw, 0, 12 ), (string) substr( $raw, 12, 16 ) );

                return false === $plain ? '' : (string) $plain;
            }

            return '';
        }
    }
}
