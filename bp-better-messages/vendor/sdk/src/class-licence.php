<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Licence' ) ) {
    final class WordPlus_SDK_Licence
    {
        const CLOUD_OPTION = 'wordplus_cloud_license';

        const OFFLINE_GRACE = 691200;

        const GRACE_AFTER_EXPIRY = 259200;

        const RECOVER_EVERY = 600;

        const STALE_AFTER = 172800;

        const STALE_RETRY = 3600;

        const ENDED_STALE_AFTER = 3600;

        const SECRET_REFUSALS = array( 'site_secret_revoked', 'bad_signature', 'unknown_site' );

        const CONFIRM_AFTER = 3600;

        const MOVE_SETTLING = 1209600;

        const COPY_MOVE = 'move';

        const COPY_SEPARATE = 'separate';

        const COPY_STAGING = 'staging';

        const COPY_ANSWERS = array( self::COPY_MOVE, self::COPY_SEPARATE, self::COPY_STAGING );

        const COPY_REFUSALS = array( 'staging_limit', 'license_utilized' );

        private $store;

        private $sdk;

        private $activation_tried = false;

        public function __construct( $store, WordPlus_SDK $sdk )
        {
            $this->store = (string) $store;
            $this->sdk   = $sdk;

            add_action( 'init', array( $this, 'on_init' ) );
            add_action( 'admin_init', array( $this, 'maybe_refresh_stale' ) );
            add_action( 'admin_init', array( $this, 'maybe_check_in_plugins' ) );
            add_action( $this->event( 'check' ), array( $this, 'refresh' ) );
            add_action( $this->event( 'check_soon' ), array( $this, 'refresh' ) );
            add_action( $this->event( 'verify' ), array( $this, 'verify_migration' ) );
        }

        public function event( $kind )
        {
            return 'wordplus_sdk_' . $kind . '_' . str_replace( '-', '_', $this->store );
        }

        public function option()
        {
            if ( WordPlus_SDK::CLOUD === $this->store ) {
                return self::CLOUD_OPTION;
            }

            $own = (string) $this->setting( 'option' );

            return '' !== $own ? $own : 'wordplus_sdk_licence_' . str_replace( '-', '_', $this->store );
        }

        public function lead()
        {
            $products = $this->sdk->products_on( $this->store );
            $data     = $this->raw();

            if ( ! empty( $data['plugin'] ) ) {
                foreach ( $products as $product ) {
                    if ( $product->slug() === $data['plugin'] ) {
                        return $product;
                    }
                }
            }

            return $products[0];
        }

        private function setting( $key )
        {
            foreach ( $this->sdk->products_on( $this->store ) as $product ) {
                $value = $product->get( $key );

                if ( ! empty( $value ) ) {
                    return $value;
                }
            }

            return null;
        }

        private function forced_key()
        {
            $constant = (string) $this->setting( 'force_key' );

            return '' !== $constant && defined( $constant ) ? (string) constant( $constant ) : null;
        }

        private function forced_domain()
        {
            $constant = (string) $this->setting( 'force_domain' );
            $domain   = '' !== $constant && defined( $constant ) ? trim( (string) constant( $constant ) ) : '';

            if ( '' === $domain ) {
                return null;
            }

            return preg_match( '#^[a-z][a-z0-9+.-]*://#i', $domain ) ? $domain : 'https://' . ltrim( $domain, '/' );
        }

        private function unchecked()
        {
            return null !== $this->forced_key() && null !== $this->forced_domain();
        }

        public function on_init()
        {
            $this->maybe_migrate();
            $this->maybe_migrate_site();

            $event = $this->event( 'check' );

            if ( $this->is_network() && ! $this->per_site_addresses() && ! is_main_site() ) {
                if ( wp_next_scheduled( $event ) ) {
                    wp_clear_scheduled_hook( $event );
                }

                return;
            }

            if ( ! wp_next_scheduled( $event ) ) {
                wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', $event );
            }
        }

        public function maybe_refresh_stale()
        {
            if ( wp_doing_ajax() || wp_doing_cron() || $this->unchecked() || '' === $this->get_key() ) {
                return;
            }

            if ( $this->is_network() && ! $this->per_site_addresses() && ! is_main_site() ) {
                return;
            }

            $data = $this->get();

            if ( time() - ( isset( $data['checked_at'] ) ? (int) $data['checked_at'] : 0 ) < $this->stale_after( $data )
                || time() - ( isset( $data['stale_check_at'] ) ? (int) $data['stale_check_at'] : 0 ) < self::STALE_RETRY ) {
                return;
            }

            $data['stale_check_at'] = time();
            $this->save( $data );

            if ( ! wp_next_scheduled( $this->event( 'check_soon' ) ) ) {
                wp_schedule_single_event( time(), $this->event( 'check_soon' ) );
            }
        }

        private function stale_after( $data )
        {
            return null !== $this->copy_source() ? 0 : ( $this->has_ended( $data ) ? self::ENDED_STALE_AFTER : self::STALE_AFTER );
        }

        public function check_due()
        {
            if ( $this->unchecked() || '' === $this->get_key() ) {
                return false;
            }

            if ( $this->is_network() && ! $this->per_site_addresses() && ! is_main_site() ) {
                return false;
            }

            $data = $this->get();

            if ( $this->awaiting_verification( $data ) && ! $this->deactivated_here( $data ) ) {
                return true;
            }

            return time() - ( isset( $data['checked_at'] ) ? (int) $data['checked_at'] : 0 ) >= $this->stale_after( $data );
        }

        public function maybe_check_in_plugins()
        {
            if ( wp_doing_ajax() || wp_doing_cron() || $this->unchecked() || '' === $this->get_key() || ! $this->is_active() ) {
                return;
            }

            if ( $this->is_network() && ! $this->per_site_addresses() && ! is_main_site() ) {
                return;
            }

            $here = array();

            foreach ( $this->sdk->products_on( $this->store ) as $product ) {
                $here[ $product->slug() ] = (string) $product->version();
            }

            ksort( $here );

            $data  = $this->get();
            $url   = $this->url();
            $asked = isset( $data['checked_in'][ $url ] ) && is_array( $data['checked_in'][ $url ] ) ? $data['checked_in'][ $url ] : array();

            if ( $asked === $here ) {
                return;
            }

            $data['checked_in'][ $url ] = $here;
            $this->save( $data );

            if ( array() !== array_diff_assoc( $here, $asked ) && ! wp_next_scheduled( $this->event( 'check_soon' ) ) ) {
                wp_schedule_single_event( time(), $this->event( 'check_soon' ) );
            }
        }

        private function has_ended( $data )
        {
            if ( empty( $data['key'] ) || ! isset( $data['status'] ) ) {
                return false;
            }

            if ( 'expired' === $data['status'] || 'cancelled' === $data['status'] ) {
                return true;
            }

            return 'valid' === $data['status'] && ! empty( $data['access_ends_at'] ) && strtotime( $data['access_ends_at'] ) < time();
        }

        public function server()
        {
            return $this->sdk->server();
        }

        private function raw()
        {
            $data = $this->is_network() ? get_site_option( $this->option(), null ) : get_option( $this->option(), null );

            return is_array( $data ) ? $data : null;
        }

        public function get()
        {
            $data = $this->raw();

            if ( null === $data ) {
                $data = $this->move_old_option();
            }

            return is_array( $data ) ? $data : array();
        }

        private function move_old_option()
        {
            $name = (string) $this->setting( 'old_option' );

            if ( '' === $name ) {
                return array();
            }

            $old = $this->is_network() ? get_site_option( $name, null ) : get_option( $name, null );

            if ( ! is_array( $old ) ) {
                return array();
            }

            $this->save( $old );

            if ( $this->is_network() ) {
                delete_site_option( $name );
            } else {
                delete_option( $name );
            }

            return $old;
        }

        private function save( $data )
        {
            if ( $this->is_network() ) {
                update_site_option( $this->option(), $data );
            } else {
                update_option( $this->option(), $data, true );
            }
        }

        public function is_network()
        {
            foreach ( $this->sdk->products_on( $this->store ) as $product ) {
                return $product->is_network_active();
            }

            return false;
        }

        public function per_site_addresses()
        {
            return $this->is_network() && defined( 'SUBDOMAIN_INSTALL' ) && SUBDOMAIN_INSTALL && null === $this->forced_domain();
        }

        public function url()
        {
            if ( null !== $this->forced_domain() ) {
                return $this->forced_domain();
            }

            if ( $this->is_network() && ! $this->per_site_addresses() ) {
                return get_site_url( get_main_site_id() );
            }

            return site_url();
        }

        public function get_key()
        {
            if ( null !== $this->forced_key() ) {
                return $this->forced_key();
            }

            $data = $this->get();

            return isset( $data['key'] ) ? (string) $data['key'] : '';
        }

        public function is_forced()
        {
            return null !== $this->forced_key();
        }

        public function is_active()
        {
            if ( null !== $this->forced_key() && '' !== $this->forced_key() ) {
                return true;
            }

            $data = $this->get();

            if ( empty( $data['key'] ) || ! isset( $data['status'] ) || 'valid' !== $data['status'] ) {
                return false;
            }

            if ( ! empty( $data['access_ends_at'] ) && strtotime( $data['access_ends_at'] ) < time() ) {
                return false;
            }

            if ( ! isset( $data['last_ok_at'] ) ) {
                return false;
            }

            $failing = isset( $data['checked_at'] ) && (int) $data['checked_at'] > (int) $data['last_ok_at'];

            if ( $failing && ( time() - (int) $data['last_ok_at'] ) >= self::OFFLINE_GRACE ) {
                return false;
            }

            return $this->get_activation_id() > 0;
        }

        public function get_activation_id()
        {
            $data = $this->get();
            $url  = $this->url();

            return isset( $data['activations'][ $url ]['id'] ) ? (int) $data['activations'][ $url ]['id'] : 0;
        }

        public function site_credentials()
        {
            $data       = $this->get();
            $url        = $this->url();
            $activation = isset( $data['activations'][ $url ] ) && is_array( $data['activations'][ $url ] ) ? $data['activations'][ $url ] : array();

            if ( empty( $activation['site_key'] ) || empty( $activation['secret'] ) ) {
                return null;
            }

            return array(
                'key'             => (string) $activation['site_key'],
                'secret'          => (string) $activation['secret'],
                'effective_at'    => isset( $activation['effective_at'] ) ? (int) $activation['effective_at'] : 0,
                'previous_secret' => isset( $activation['previous_secret'] ) ? (string) $activation['previous_secret'] : '',
            );
        }

        public function copy_source()
        {
            if ( $this->is_network() || null !== $this->forced_key() || null !== $this->site_credentials() || '' === $this->get_key() ) {
                return null;
            }

            $data = $this->get();
            $url  = $this->url();
            $best = null;

            if ( $this->deactivated_here( $data ) ) {
                return null;
            }

            foreach ( isset( $data['activations'] ) && is_array( $data['activations'] ) ? $data['activations'] : array() as $address => $activation ) {
                if ( $address === $url || ! is_array( $activation ) || empty( $activation['site_key'] ) || empty( $activation['secret'] ) ) {
                    continue;
                }

                if ( null === $best || (int) ( isset( $activation['effective_at'] ) ? $activation['effective_at'] : 0 ) > (int) ( isset( $best['effective_at'] ) ? $best['effective_at'] : 0 ) ) {
                    $best = $activation + array( 'url' => (string) $address );
                }
            }

            return null === $best ? null : array( 'url' => $best['url'], 'key' => (string) $best['site_key'], 'secret' => (string) $best['secret'] );
        }

        public function copy_state()
        {
            $data  = $this->get();
            $entry = isset( $data['activations'][ $this->url() ] ) && is_array( $data['activations'][ $this->url() ] ) ? $data['activations'][ $this->url() ] : array();

            if ( ! empty( $entry['awaits_answer'] ) ) {
                return array( 'of' => isset( $entry['copy_of'] ) ? (string) $entry['copy_of'] : '', 'refused' => '' );
            }

            $source = $this->copy_source();

            if ( null !== $source && ! empty( $data['copy_refused'] ) ) {
                return array( 'of' => (string) wp_parse_url( $source['url'], PHP_URL_HOST ), 'refused' => (string) $data['copy_refused'] );
            }

            return null;
        }

        public function answer_copy( $answer )
        {
            if ( ! in_array( $answer, self::COPY_ANSWERS, true ) ) {
                return new WP_Error( 'invalid_answer', 'invalid_answer' );
            }

            if ( '' === $this->get_key() ) {
                return new WP_Error( 'no_license', 'No licence key' );
            }

            return $this->activate( $this->get_key(), null, $answer );
        }

        private function configured_copy_answer()
        {
            return defined( 'WORDPLUS_SITE_COPY' ) && in_array( WORDPLUS_SITE_COPY, self::COPY_ANSWERS, true ) ? (string) WORDPLUS_SITE_COPY : null;
        }

        public function realtime_credentials()
        {
            $legacy = array( 'site_id' => null, 'secret' => $this->get_key() );
            $site   = null !== $this->forced_key() ? null : $this->site_credentials();

            if ( null === $site ) {
                return $legacy;
            }

            if ( $site['effective_at'] <= time() ) {
                return array( 'site_id' => $site['key'], 'secret' => $site['secret'] );
            }

            return '' !== $site['previous_secret'] ? array( 'site_id' => $site['key'], 'secret' => $site['previous_secret'] ) : $legacy;
        }

        public function activate( $key, $plugin = null, $copy = null, $replace = '' )
        {
            $this->activation_tried = true;

            $key     = trim( (string) $key );
            $data    = $this->get();
            $product = null !== $plugin && $this->sdk->product( $plugin ) ? $this->sdk->product( $plugin ) : $this->lead();
            $body    = array(
                'license_key' => $key,
                'plugin'      => $product->slug(),
                'url'         => $this->url(),
                'version'     => $product->version(),
                'uid'         => $this->uid(),
            );

            $install_id = $this->install_id_for( $key, $data );

            if ( $install_id > 0 ) {
                $body['install_id'] = $install_id;
            }

            $source  = isset( $data['key'] ) && $data['key'] === $key ? $this->copy_source() : null;
            $waiting = null !== $this->copy_state();
            $copy    = null !== $copy ? $copy : $this->configured_copy_answer();

            if ( null !== $copy && ( null !== $source || $waiting ) ) {
                $body['copy'] = $copy;
            }

            $answer = $this->request( 'api/v1/licenses/activate', $body, true, $source );

            if ( is_wp_error( $answer ) && in_array( $answer->get_error_code(), self::SECRET_REFUSALS, true ) ) {
                $this->forget_secret();
                $answer = $this->request( 'api/v1/licenses/activate', $body );
            }

            if ( is_wp_error( $answer ) ) {
                if ( null !== $source && in_array( $answer->get_error_code(), self::COPY_REFUSALS, true ) ) {
                    $data['copy_refused'] = $answer->get_error_code();
                    $this->save( $data );
                }

                return $answer;
            }

            $extra   = array( 'key' => $key, 'plugin' => $product->slug(), 'copy_refused' => '' );
            $refused = isset( $data['refused'] ) && is_array( $data['refused'] ) ? $data['refused'] : array();

            if ( ! empty( $data['migrated_from'] ) && empty( $data['verified'] ) ) {
                $extra['verified'] = time();
            }

            unset( $refused[ $product->slug() ] );
            $extra['refused'] = $refused;

            if ( isset( $data['key'] ) && $data['key'] !== $key ) {
                $extra['activations'] = array();
                $extra['refused']     = array();
                $extra['checked_in']  = array();
            }

            $this->store_answer( $answer, $extra );

            return $this->get();
        }

        public function rotate_secret( $end_previous = false )
        {
            if ( null === $this->site_credentials() ) {
                return new WP_Error( 'no_site_secret', 'No site secret' );
            }

            $answer = $this->signed_request( 'api/v1/licenses/secret', array(
                'plugin'       => $this->lead()->slug(),
                'end_previous' => (bool) $end_previous,
            ) );

            if ( is_wp_error( $answer ) ) {
                return $answer;
            }

            $this->store_answer( $answer );

            return $this->get();
        }

        public function recover()
        {
            $data = $this->get();
            $key  = $this->get_key();

            if ( '' === $key || $this->unchecked() ) {
                return new WP_Error( 'no_license', 'No licence key' );
            }

            if ( $this->deactivated_here( $data ) ) {
                return new WP_Error( 'deactivated_in_account', 'deactivated_in_account' );
            }

            if ( ! empty( $data['recovered_at'] ) && ( time() - (int) $data['recovered_at'] ) < self::RECOVER_EVERY ) {
                return new WP_Error( 'try_later', 'try_later' );
            }

            $data['recovered_at'] = time();
            $this->save( $data );
            $this->forget_secret();

            $result = $this->activate( $key );

            if ( is_wp_error( $result ) ) {
                $data                   = $this->get();
                $data['secret_refused'] = $result->get_error_code();
                $this->save( $data );
            }

            return $result;
        }

        private function secret_required( $data )
        {
            return isset( $data['secret_refused'] ) && 'site_secret_required' === $data['secret_refused'];
        }

        private function note_refusal( WP_Error $refusal )
        {
            if ( 'site_secret_required' !== $refusal->get_error_code() ) {
                return;
            }

            $data                   = $this->get();
            $data['secret_refused'] = 'site_secret_required';
            $this->save( $data );
        }

        private function forget_secret()
        {
            $data = $this->get();
            $url  = $this->url();

            if ( isset( $data['activations'][ $url ] ) && is_array( $data['activations'][ $url ] ) ) {
                unset( $data['activations'][ $url ]['secret'], $data['activations'][ $url ]['previous_secret'], $data['activations'][ $url ]['effective_at'] );
                $this->save( $data );
            }
        }

        public function signed_request( $path, $body )
        {
            $answer = $this->request( $path, $this->without_key( $body ), true );

            if ( is_wp_error( $answer ) && in_array( $answer->get_error_code(), self::SECRET_REFUSALS, true ) && ! is_wp_error( $this->recover() ) ) {
                $answer = $this->request( $path, $this->without_key( $body ), true );
            }

            return $answer;
        }

        private function without_key( $body )
        {
            if ( null !== $this->site_credentials() ) {
                unset( $body['license_key'], $body['url'] );
            }

            return $body;
        }

        private function install_id_for( $key, $data )
        {
            if ( $this->per_site_addresses() ) {
                return $this->freemius_site_install( $key );
            }

            return ! empty( $data['install_id'] ) && isset( $data['key'] ) && $data['key'] === $key ? (int) $data['install_id'] : 0;
        }

        private function freemius()
        {
            $freemius = $this->setting( 'freemius' );

            return is_array( $freemius ) && ! empty( $freemius['product'] ) && ! empty( $freemius['slug'] ) ? $freemius : null;
        }

        public function freemius_site_install( $key )
        {
            $freemius = $this->freemius();

            if ( null === $freemius ) {
                return 0;
            }

            $accounts = get_option( 'fs_accounts', array() );
            $site     = is_array( $accounts ) && isset( $accounts['sites'][ $freemius['slug'] ] ) ? (array) $accounts['sites'][ $freemius['slug'] ] : array();

            if ( empty( $site['id'] ) || empty( $site['license_id'] ) ) {
                return 0;
            }

            $license = $this->freemius_license( (int) $site['license_id'] );

            return null !== $license && (string) $license['secret_key'] === (string) $key ? (int) $site['id'] : 0;
        }

        private function freemius_license( $license_id )
        {
            $freemius = $this->freemius();
            $sources  = array( get_option( 'fs_accounts', array() ) );

            if ( is_multisite() ) {
                array_unshift( $sources, get_site_option( 'fs_accounts', array() ) );
            }

            foreach ( $sources as $source ) {
                if ( null === $freemius || ! is_array( $source ) || empty( $source['all_licenses'][ $freemius['product'] ] ) || ! is_array( $source['all_licenses'][ $freemius['product'] ] ) ) {
                    continue;
                }

                foreach ( $source['all_licenses'][ $freemius['product'] ] as $candidate ) {
                    $candidate = (array) $candidate;

                    if ( isset( $candidate['id'] ) && (int) $candidate['id'] === (int) $license_id && ! empty( $candidate['secret_key'] ) ) {
                        return $candidate;
                    }
                }
            }

            return null;
        }

        public function store_purchase( $answer, $plugin = null )
        {
            if ( ! is_array( $answer ) || empty( $answer['license_key'] ) ) {
                return;
            }

            $data = $this->get();

            if ( empty( $data['migration_checked'] ) ) {
                $data['migration_checked'] = time();
                $this->save( $data );
            }

            $this->store_answer( $answer, array( 'key' => (string) $answer['license_key'], 'plugin' => null !== $plugin ? (string) $plugin : $this->lead()->slug() ) );
        }

        public function deactivate()
        {
            $key  = $this->get_key();
            $data = $this->get();

            if ( '' !== $key ) {
                $urls = $this->per_site_addresses() && ! empty( $data['activations'] ) ? array_keys( $data['activations'] ) : array( $this->url() );

                foreach ( $urls as $url ) {
                    $body = array(
                        'license_key' => $key,
                        'plugin'      => $this->lead()->slug(),
                        'url'         => $url,
                    );

                    $entry = isset( $data['activations'][ $url ] ) && is_array( $data['activations'][ $url ] ) ? $data['activations'][ $url ] : array();

                    if ( $url === $this->url() ) {
                        $this->signed_request( 'api/v1/licenses/deactivate', $body );
                    } elseif ( ! empty( $entry['site_key'] ) && ! empty( $entry['secret'] ) ) {
                        $this->request( 'api/v1/licenses/deactivate', array( 'plugin' => $body['plugin'] ), true, array( 'key' => (string) $entry['site_key'], 'secret' => (string) $entry['secret'] ) );
                    } else {
                        $this->request( 'api/v1/licenses/deactivate', $body );
                    }
                }
            }

            $this->save( array(
                'uid'               => isset( $data['uid'] ) ? $data['uid'] : $this->uid(),
                'migration_checked' => isset( $data['migration_checked'] ) ? $data['migration_checked'] : time(),
                'had_license'       => true,
            ) );

            return true;
        }

        public function refresh()
        {
            $this->activation_tried = false;
            $result                 = $this->refresh_lead();

            if ( ! $this->activation_tried ) {
                $this->verify_migration();
                $result = $this->get();
            }

            if ( ! $this->unchecked() && $this->is_active() && count( $this->sdk->products_on( $this->store ) ) > 1 ) {
                $this->check_in_others();
                $result = $this->get();
            }

            return $result;
        }

        private function check_in_others()
        {
            $lead    = $this->lead()->slug();
            $data    = $this->get();
            $refused = isset( $data['refused'] ) && is_array( $data['refused'] ) ? $data['refused'] : array();
            $before  = $refused;

            foreach ( $this->sdk->products_on( $this->store ) as $product ) {
                if ( $product->slug() === $lead ) {
                    continue;
                }

                $answer = $this->signed_request( 'api/v1/licenses/status', array(
                    'license_key' => $this->get_key(),
                    'plugin'      => $product->slug(),
                    'url'         => $this->url(),
                    'version'     => $product->version(),
                ) );

                if ( ! is_wp_error( $answer ) ) {
                    unset( $refused[ $product->slug() ] );
                } elseif ( 'wrong_plugin' === $answer->get_error_code() ) {
                    $refused[ $product->slug() ] = 'wrong_plugin';
                }
            }

            if ( $refused !== $before ) {
                $data            = $this->get();
                $data['refused'] = $refused;
                $this->save( $data );
            }
        }

        public function refused( $slug )
        {
            $data = $this->get();

            return isset( $data['refused'][ $slug ] ) ? (string) $data['refused'][ $slug ] : null;
        }

        private function confirmed( array &$data, $what )
        {
            $first = isset( $data['refused_at'][ $what ] ) ? (int) $data['refused_at'][ $what ] : 0;

            if ( $first > 0 && time() - $first >= self::CONFIRM_AFTER ) {
                unset( $data['refused_at'][ $what ] );

                if ( empty( $data['refused_at'] ) ) {
                    unset( $data['refused_at'] );
                }

                return true;
            }

            if ( 0 === $first ) {
                $data['refused_at'][ $what ] = time();
            }

            if ( ! wp_next_scheduled( $this->event( 'check_soon' ) ) ) {
                wp_schedule_single_event( time() + self::CONFIRM_AFTER, $this->event( 'check_soon' ) );
            }

            return false;
        }

        private function refresh_lead()
        {
            $key = $this->get_key();

            if ( '' === $key || $this->unchecked() ) {
                return $this->get();
            }

            $answer = $this->signed_request( 'api/v1/licenses/status', array(
                'license_key' => $key,
                'plugin'      => $this->lead()->slug(),
                'url'         => $this->url(),
                'version'     => $this->lead()->version(),
            ) );

            $data = $this->get();

            if ( is_wp_error( $answer ) ) {
                $data['checked_at'] = time();
                $code               = $answer->get_error_code();

                if ( 'invalid_license' === $code && $this->move_settling( $data ) ) {
                    if ( empty( $data['refused_at']['licence'] ) ) {
                        $data['refused_at']['licence'] = time();
                    }

                    $data['last_ok_at'] = time();
                } elseif ( in_array( $code, array( 'invalid_license', 'wrong_plugin' ), true ) && $this->confirmed( $data, 'licence' ) ) {
                    $data['status'] = 'invalid';
                }

                $this->save( $data );

                return $data;
            }

            if ( empty( $answer['activation'] ) ) {
                if ( $this->awaiting_verification( $data ) && isset( $answer['status'] ) && 'valid' === $answer['status'] ) {
                    $this->store_answer( $answer );
                    $this->verify_migration();

                    return $this->get();
                }

                if ( $this->get_activation_id() > 0 ) {
                    if ( ! $this->confirmed( $data, 'activation' ) ) {
                        $data['checked_at'] = time();
                        $data['last_ok_at'] = time();
                        $this->save( $data );

                        return $data;
                    }

                    $data['activations'][ $this->url() ] = array( 'deactivated' => time() );
                    $this->save( $data );
                } elseif ( ! $this->deactivated_here( $data ) && isset( $answer['status'] ) && 'valid' === $answer['status'] ) {
                    $activated = $this->activate( $key );

                    if ( ! is_wp_error( $activated ) ) {
                        return $activated;
                    }
                }
            }

            $this->store_answer( $answer );

            $data = $this->get();
            $url  = $this->url();

            if ( $this->is_active() && ! $this->deactivated_here( $data ) && null === $this->site_credentials()
                && ( empty( $data['activations'][ $url ]['credentials_asked'] ) || $this->secret_required( $data ) ) ) {
                $data['activations'][ $url ]['credentials_asked'] = time();
                $this->save( $data );

                $activated = $this->activate( $key );

                if ( ! is_wp_error( $activated ) ) {
                    return $activated;
                }

                $this->note_refusal( $activated );
            }

            return $this->get();
        }

        private function deactivated_here( $data )
        {
            return ! empty( $data['activations'][ $this->url() ]['deactivated'] );
        }

        public function sites()
        {
            $key = $this->get_key();

            if ( '' === $key ) {
                return new WP_Error( 'no_license', 'No licence key' );
            }

            return $this->signed_request( 'api/v1/licenses/sites', array(
                'license_key' => $key,
                'plugin'      => $this->lead()->slug(),
                'url'         => $this->url(),
            ) );
        }

        private function site_migrated_option()
        {
            return 'wordplus_sdk_site_migrated_' . str_replace( '-', '_', $this->store );
        }

        public function maybe_migrate()
        {
            $data = $this->get();

            if ( ! empty( $data['key'] ) || ! empty( $data['migration_checked'] ) || $this->unchecked() ) {
                return;
            }

            $migrated = $this->read_freemius();

            if ( null !== $migrated && null !== $this->forced_key() && $migrated['key'] !== $this->forced_key() ) {
                $migrated = null;
            }

            if ( null === $migrated && $this->is_network() && ! is_main_site() ) {
                return;
            }

            $data['migration_checked'] = time();

            if ( null !== $migrated && $this->per_site_addresses() ) {
                $own                     = $this->freemius_site_install( $migrated['key'] );
                $migrated['install_id']  = $own;
                $migrated['activations'] = $own > 0 ? array( $this->url() => array( 'id' => $own, 'domain' => '', 'unverified' => true ) ) : array();
                update_option( $this->site_migrated_option(), time(), true );
            }

            if ( null !== $migrated ) {
                $data = array_merge( $data, $migrated );

                if ( empty( $data['uid'] ) && ! empty( $migrated['freemius_uid'] ) ) {
                    $data['uid'] = $migrated['freemius_uid'];
                }

                wp_schedule_single_event( time() + 30, $this->event( 'verify' ) );
            }

            $this->save( $data );
        }

        public function maybe_migrate_site()
        {
            if ( ! $this->per_site_addresses() || get_option( $this->site_migrated_option() ) ) {
                return;
            }

            $data = $this->get();

            if ( empty( $data['key'] ) || empty( $data['migrated_from'] ) ) {
                return;
            }

            update_option( $this->site_migrated_option(), time(), true );

            $install = $this->freemius_site_install( $data['key'] );

            if ( $install <= 0 || isset( $data['activations'][ $this->url() ] ) ) {
                return;
            }

            $data['activations'][ $this->url() ] = array( 'id' => $install, 'domain' => '', 'unverified' => true );
            $this->save( $data );

            wp_schedule_single_event( time() + 30, $this->event( 'verify' ) );
        }

        private function awaiting_verification( $data )
        {
            if ( empty( $data['migrated_from'] ) ) {
                return false;
            }

            if ( $this->per_site_addresses() ) {
                return ! empty( $data['activations'][ $this->url() ]['unverified'] );
            }

            return empty( $data['verified'] );
        }

        private function move_settling( $data )
        {
            return $this->awaiting_verification( $data ) && ! empty( $data['migration_checked'] ) && ( time() - (int) $data['migration_checked'] ) < self::MOVE_SETTLING;
        }

        public function verify_migration()
        {
            $data = $this->get();

            if ( empty( $data['key'] ) || ! $this->awaiting_verification( $data ) || $this->deactivated_here( $data ) ) {
                return;
            }

            $result = $this->activate( $data['key'] );

            if ( is_wp_error( $result ) ) {
                $this->note_refusal( $result );

                $statuses = array(
                    'invalid_license'   => 'invalid',
                    'wrong_plugin'      => 'invalid',
                    'install_mismatch'  => 'invalid',
                    'license_expired'   => 'expired',
                    'license_cancelled' => 'cancelled',
                );
                $code     = $result->get_error_code();

                $settling = 'invalid_license' === $code && $this->move_settling( $data );

                if ( isset( $statuses[ $code ] ) && ! $settling ) {
                    $data = $this->get();

                    unset( $data['activations'][ $this->url() ] );

                    if ( 'install_mismatch' !== $code || ! $this->per_site_addresses() ) {
                        $data['status']   = $statuses[ $code ];
                        $data['verified'] = time();
                    }

                    $this->save( $data );
                } else {
                    wp_schedule_single_event( time() + DAY_IN_SECONDS, $this->event( 'verify' ) );
                }

                return;
            }

            $data             = $this->get();
            $data['verified'] = time();
            $this->save( $data );
        }

        public function read_freemius()
        {
            $freemius = $this->freemius();

            if ( null === $freemius ) {
                return null;
            }

            $accounts = $this->is_network() ? get_site_option( 'fs_accounts', array() ) : get_option( 'fs_accounts', array() );
            $blog     = get_option( 'fs_accounts', array() );
            $main     = $this->is_network() ? get_blog_option( get_main_site_id(), 'fs_accounts', array() ) : array();
            $installs = $this->is_network() && ! $this->per_site_addresses() ? array( $main, $blog, $accounts ) : array( $blog, $main, $accounts );
            $site     = null;
            $license  = null;

            foreach ( $installs as $install ) {
                if ( ! is_array( $install ) || ! isset( $install['sites'][ $freemius['slug'] ] ) ) {
                    continue;
                }

                $entry = (array) $install['sites'][ $freemius['slug'] ];

                if ( empty( $entry['license_id'] ) ) {
                    continue;
                }

                foreach ( array( $accounts, $main, $blog ) as $source ) {
                    if ( ! is_array( $source ) || empty( $source['all_licenses'][ $freemius['product'] ] ) || ! is_array( $source['all_licenses'][ $freemius['product'] ] ) ) {
                        continue;
                    }

                    foreach ( $source['all_licenses'][ $freemius['product'] ] as $candidate ) {
                        $candidate = (array) $candidate;

                        if ( isset( $candidate['id'] ) && (int) $candidate['id'] === (int) $entry['license_id'] && ! empty( $candidate['secret_key'] ) ) {
                            $site    = $entry;
                            $license = $candidate;
                            break 3;
                        }
                    }
                }
            }

            if ( null === $site || null === $license ) {
                return null;
            }

            $plans       = isset( $freemius['plans'] ) && is_array( $freemius['plans'] ) ? $freemius['plans'] : array();
            $expiration  = ! empty( $license['expiration'] ) ? strtotime( $license['expiration'] . ' UTC' ) : false;
            $cancelled   = ! empty( $license['is_cancelled'] );
            $access_ends = $expiration ? $expiration + self::GRACE_AFTER_EXPIRY : false;
            $plan_id     = isset( $license['plan_id'] ) ? (string) $license['plan_id'] : '';

            return array(
                'key'            => (string) $license['secret_key'],
                'install_id'     => isset( $site['id'] ) ? (int) $site['id'] : 0,
                'activations'    => empty( $site['id'] ) ? array() : array( $this->url() => array( 'id' => (int) $site['id'], 'domain' => '' ) ),
                'license_id'     => (string) $license['id'],
                'plan'           => isset( $plans[ $plan_id ] ) ? $plans[ $plan_id ] : ( isset( $plans['*'] ) ? $plans['*'] : null ),
                'quota'          => isset( $license['quota'] ) ? $license['quota'] : null,
                'expires_at'     => $expiration ? gmdate( 'c', $expiration ) : null,
                'access_ends_at' => $access_ends ? gmdate( 'c', $access_ends ) : null,
                'status'         => $cancelled ? 'cancelled' : ( $access_ends && $access_ends < time() ? 'expired' : 'valid' ),
                'last_ok_at'     => time(),
                'migrated_from'  => 'freemius',
                'freemius_uid'   => isset( $accounts['unique_id'] ) ? (string) $accounts['unique_id'] : ( isset( $blog['unique_id'] ) ? (string) $blog['unique_id'] : '' ),
            );
        }

        private function store_answer( $answer, $extra = array() )
        {
            $data    = $this->get();
            $license = isset( $answer['license'] ) && is_array( $answer['license'] ) ? $answer['license'] : array();

            $data = array_merge( $data, $extra, array(
                'status'         => isset( $answer['status'] ) ? (string) $answer['status'] : 'invalid',
                'license_id'     => isset( $license['id'] ) ? (string) $license['id'] : ( isset( $data['license_id'] ) ? $data['license_id'] : '' ),
                'plan'           => isset( $license['plan'] ) ? $license['plan'] : null,
                'features'       => isset( $license['features'] ) && is_array( $license['features'] ) ? $license['features'] : array(),
                'expires_at'     => isset( $license['expires_at'] ) ? $license['expires_at'] : null,
                'access_ends_at' => isset( $license['access_ends_at'] ) ? $license['access_ends_at'] : null,
                'quota'          => array_key_exists( 'quota', $license ) ? $license['quota'] : null,
                'used'           => isset( $license['used'] ) ? (int) $license['used'] : 0,
                'account'        => isset( $license['account']['email'] ) && is_string( $license['account']['email'] ) && '' !== $license['account']['email']
                    ? array( 'email' => $license['account']['email'], 'verified' => ! empty( $license['account']['verified'] ) )
                    : null,
                'checked_at'     => time(),
                'last_ok_at'     => time(),
            ) );

            unset( $data['refused_at']['licence'] );

            if ( ! empty( $answer['activation']['id'] ) ) {
                unset( $data['refused_at']['activation'] );
            }

            if ( empty( $data['refused_at'] ) ) {
                unset( $data['refused_at'] );
            }

            $url      = $this->url();
            $existing = isset( $data['activations'][ $url ] ) && is_array( $data['activations'][ $url ] ) ? $data['activations'][ $url ] : array();

            if ( ! empty( $answer['activation']['id'] ) ) {
                unset( $existing['unverified'], $existing['deactivated'] );

                $existing = array_merge( $existing, array(
                    'id'            => (int) $answer['activation']['id'],
                    'domain'        => isset( $answer['activation']['domain'] ) ? (string) $answer['activation']['domain'] : '',
                    'staging'       => ! empty( $answer['activation']['staging'] ),
                    'staging_why'   => isset( $answer['activation']['staging_reason'] ) ? (string) $answer['activation']['staging_reason'] : '',
                    'copy_of'       => isset( $answer['activation']['copy_of'] ) ? (string) $answer['activation']['copy_of'] : '',
                    'awaits_answer' => ! empty( $answer['activation']['awaits_answer'] ),
                ) );

                if ( ! empty( $answer['activation']['site_key'] ) ) {
                    $existing['site_key'] = (string) $answer['activation']['site_key'];
                }

                $data['activations'][ $url ] = $existing;
            }

            if ( array_key_exists( 'site', $answer ) ) {
                $site = $answer['site'];

                if ( is_array( $site ) && ! empty( $site['key'] ) && ! empty( $site['secret'] ) ) {
                    $old = isset( $existing['secret'] ) ? (string) $existing['secret'] : '';
                    $new = (string) $site['secret'];

                    $existing = array_merge( $existing, array(
                        'site_key'           => (string) $site['key'],
                        'secret'             => $new,
                        'effective_at'       => ! empty( $site['effective_at'] ) ? (int) strtotime( (string) $site['effective_at'] ) : time(),
                        'previous_secret'    => $old !== $new ? $old : ( isset( $existing['previous_secret'] ) ? (string) $existing['previous_secret'] : '' ),
                        'secret_received_at' => $old !== $new ? time() : ( isset( $existing['secret_received_at'] ) ? (int) $existing['secret_received_at'] : time() ),
                    ) );

                    unset( $data['secret_refused'] );
                }

                $existing['credentials_asked'] = time();
                $data['activations'][ $url ]   = $existing;
            }

            $data['had_license'] = true;

            $this->save( $data );
        }

        public function uid()
        {
            $data = $this->get();

            if ( ! empty( $data['uid'] ) ) {
                return (string) $data['uid'];
            }

            $data['uid'] = wp_generate_password( 32, false );
            $this->save( $data );

            return $data['uid'];
        }

        public function request( $path, $body, $sign = false, $credentials = null )
        {
            $json    = wp_json_encode( $body );
            $headers = array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            );
            $site    = null !== $credentials ? $credentials : ( $sign ? $this->site_credentials() : null );

            if ( null !== $site ) {
                $timestamp = (string) time();
                $uri       = (string) wp_parse_url( $this->server() . $path, PHP_URL_PATH );

                $headers['X-WordPlus-Site']      = $site['key'];
                $headers['X-WordPlus-Timestamp'] = $timestamp;
                $headers['X-WordPlus-Signature'] = hash_hmac( 'sha256', implode( "\n", array( $timestamp, 'POST', $uri, hash( 'sha256', (string) $json ) ) ), $site['secret'] );
            }

            $response = wp_remote_post( $this->server() . $path, array(
                'timeout' => 15,
                'headers' => $headers,
                'body'    => $json,
            ) );

            if ( is_wp_error( $response ) ) {
                return new WP_Error( 'unreachable', $response->get_error_message() );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $data   = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 === $status && is_array( $data ) ) {
                return $data;
            }

            $code = is_array( $data ) && ! empty( $data['error'] ) ? (string) $data['error'] : 'http_' . $status;

            return new WP_Error( $code, $code, is_array( $data ) ? $data : array() );
        }
    }
}
