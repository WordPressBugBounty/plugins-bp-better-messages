<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Trial' ) ) {
    final class WordPlus_SDK_Trial
    {
        const OPTION = 'wordplus_sdk_trial';

        const FIRST_AFTER = 86400;

        const AGAIN_AFTER = 2592000;

        const SHOWINGS = 3;

        const READY_CHECK = 3600;

        private $product;

        private $preview = null;

        public function __construct( WordPlus_SDK_Product $product )
        {
            $this->product = $product;
        }

        public function admin_hooks()
        {
            if ( null === $this->config() ) {
                return;
            }

            add_action( 'admin_init', array( $this, 'handle_link' ) );
            add_action( 'admin_notices', array( $this, 'notice' ) );
            add_action( 'network_admin_notices', array( $this, 'notice' ) );
        }

        private function config()
        {
            $trial = $this->product->get( 'trial', false );

            if ( empty( $trial ) ) {
                return null;
            }

            return array_merge( array( 'days' => 0, 'card' => true, 'ready' => null, 'preview' => null ), is_array( $trial ) ? $trial : array() );
        }

        public function previewing()
        {
            if ( null === $this->preview ) {
                $config        = $this->config();
                $this->preview = null !== $config && is_callable( $config['preview'] ) && (bool) call_user_func( $config['preview'] );
            }

            return $this->preview;
        }

        public function offer()
        {
            $config = $this->config();

            if ( null === $config || '' === $this->product->page( 'pricing' ) ) {
                return null;
            }

            if ( ! $this->previewing() && ( $this->product->has_access() || '' !== $this->product->licence()->get_key() || $this->used() ) ) {
                return null;
            }

            $state = $this->state();
            $days  = isset( $state['days'] ) ? (int) $state['days'] : (int) $config['days'];

            if ( $days < 1 ) {
                return null;
            }

            return array(
                'days' => $days,
                'card' => isset( $state['card'] ) ? (bool) $state['card'] : (bool) $config['card'],
                'url'  => add_query_arg( array( 'trial' => '1' ), $this->pricing_url() ),
            );
        }

        public function in_menu()
        {
            if ( null === $this->offer() ) {
                return false;
            }

            $state = $this->state();

            return $this->previewing() || ! empty( $state['showings'] );
        }

        public function used()
        {
            $data = $this->product->licence()->get();

            return ! empty( $data['had_license'] ) || $this->used_under_freemius();
        }

        private function used_under_freemius()
        {
            $slug = (string) $this->product->get( 'freemius.slug', '' );

            if ( '' === $slug ) {
                return false;
            }

            $sources = array( get_option( 'fs_accounts', array() ) );

            if ( is_multisite() ) {
                $sources[] = get_site_option( 'fs_accounts', array() );
            }

            foreach ( $sources as $accounts ) {
                $site = is_array( $accounts ) && isset( $accounts['sites'][ $slug ] ) ? (array) $accounts['sites'][ $slug ] : array();

                if ( isset( $site['trial_plan_id'] ) && is_numeric( $site['trial_plan_id'] ) ) {
                    return true;
                }
            }

            return false;
        }

        public function remember( $pricing )
        {
            if ( null === $this->config() || ! is_array( $pricing ) || ! isset( $pricing['plans'] ) || ! is_array( $pricing['plans'] ) ) {
                return;
            }

            $days = 0;
            $card = true;

            foreach ( $pricing['plans'] as $plan ) {
                if ( is_array( $plan ) && ! empty( $plan['trial_days'] ) ) {
                    $days = (int) $plan['trial_days'];
                    $card = ! empty( $plan['trial_requires_payment'] );
                    break;
                }
            }

            $state = $this->state();

            if ( isset( $state['days'], $state['card'] ) && $days === (int) $state['days'] && $card === (bool) $state['card'] ) {
                return;
            }

            $state['days'] = $days;
            $state['card'] = $card;
            $this->save( $state );
        }

        public function notice()
        {
            if ( ! $this->due() ) {
                return;
            }

            $offer = $this->offer();
            $days  = (int) $offer['days'];
            $words = $this->product->words();
            $pitch = isset( $words['paid_pitch'] ) ? trim( (string) $words['paid_pitch'] ) : '';
            $trial = $offer['card']
                /* translators: %d: how many days the free trial lasts */
                ? sprintf( _n( 'Try it free for %d day. Nothing is charged before the trial ends, and you can cancel at any time.', 'Try it free for %d days. Nothing is charged before the trial ends, and you can cancel at any time.', $days, 'wordplus-sdk' ), $days )
                /* translators: %d: how many days the free trial lasts */
                : sprintf( _n( 'Try it free for %d day. No card is needed.', 'Try it free for %d days. No card is needed.', $days, 'wordplus-sdk' ), $days );

            printf(
                '<div class="notice notice-info wordplus-sdk-trial"><p><strong>%1$s</strong></p><p>%2$s</p><p><a class="button button-primary" href="%3$s">%4$s</a> <a class="button" href="%5$s">%6$s</a></p></div>',
                esc_html( sprintf(
                    /* translators: %s: the plugin's name */
                    __( 'How do you like %s so far?', 'wordplus-sdk' ),
                    $this->product->name()
                ) ),
                esc_html( '' !== $pitch ? $pitch . ' ' . $trial : $trial ),
                esc_url( $this->link( 'start' ) ),
                esc_html_x( 'Start the free trial', 'Trial notice', 'wordplus-sdk' ),
                esc_url( $this->link( 'later' ) ),
                esc_html_x( 'Not now', 'Trial notice', 'wordplus-sdk' )
            );
        }

        private function due()
        {
            if ( $this->product->is_network_active() !== is_network_admin() || ! $this->product->admin()->can_change_license() || ! $this->on_screen() || null === $this->offer() ) {
                return false;
            }

            if ( $this->previewing() ) {
                return true;
            }

            if ( $this->product->is_paid_build() ) {
                return false;
            }

            $tracking = WordPlus_SDK::instance()->tracking();

            if ( $tracking->asks( $this->product ) && '' === $tracking->state( $this->product ) ) {
                return false;
            }

            $state = $this->state();

            if ( ! empty( $state['started_at'] ) ) {
                return false;
            }

            if ( ! empty( $state['showing'] ) ) {
                return true;
            }

            $showings = isset( $state['showings'] ) ? (int) $state['showings'] : 0;

            if ( $showings >= self::SHOWINGS || ( ! empty( $state['later_at'] ) && time() - (int) $state['later_at'] < self::AGAIN_AFTER ) ) {
                return false;
            }

            $before = $state;
            $due    = time() - $this->since( $state ) >= self::FIRST_AFTER && $this->ready( $state );

            if ( $due ) {
                $state['showings'] = $showings + 1;
                $state['showing']  = time();
                $tracking->queue( $this->product, 'trial_notice_shown' );
            }

            if ( $state !== $before ) {
                $this->save( $state );
            }

            return $due;
        }

        private function since( array &$state )
        {
            $entry = WordPlus_SDK::instance()->tracking()->entry( $this->product );

            if ( ! empty( $entry['first_activated'] ) ) {
                return (int) $entry['first_activated'];
            }

            if ( empty( $state['since'] ) ) {
                $state['since'] = time();
            }

            return (int) $state['since'];
        }

        private function ready( array &$state )
        {
            if ( ! empty( $state['ready_at'] ) ) {
                return true;
            }

            $ready = $this->config()['ready'];

            if ( null !== $ready && ! is_callable( $ready ) ) {
                return false;
            }

            if ( null !== $ready ) {
                if ( ! empty( $state['ready_checked'] ) && time() - (int) $state['ready_checked'] < self::READY_CHECK ) {
                    return false;
                }

                if ( ! call_user_func( $ready ) ) {
                    $state['ready_checked'] = time();

                    return false;
                }
            }

            unset( $state['ready_checked'] );
            $state['ready_at'] = time();

            return true;
        }

        private function on_screen()
        {
            $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
            $id     = $screen ? (string) $screen->id : '';

            if ( in_array( $id, array( 'dashboard', 'dashboard-network', 'plugins', 'plugins-network' ), true ) ) {
                return true;
            }

            foreach ( array( $this->product->page( 'pricing' ), WordPlus_SDK::instance()->tracking()->page( $this->product ) ) as $slug ) {
                if ( '' !== $slug && substr( $id, -strlen( '_page_' . $slug ) ) === '_page_' . $slug ) {
                    return false;
                }
            }

            $parent = (string) $this->product->get( 'menu.parent' );

            return '' !== $parent && ( false !== strpos( $id, '_page_' . $parent ) || 'toplevel_page_' . $parent === $id || $this->product->admin()->is_page_screen( $id ) );
        }

        public function handle_link()
        {
            if ( ! isset( $_GET['wordplus_sdk_trial'], $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wordplus_sdk_trial_' . $this->product->slug() ) || ! $this->product->admin()->can_change_license() ) {
                return;
            }

            $action = sanitize_key( wp_unslash( $_GET['wordplus_sdk_trial'] ) );

            if ( ! $this->previewing() ) {
                $state   = $this->state();
                $showing = ! empty( $state['showing'] );

                unset( $state['showing'] );

                if ( 'start' === $action ) {
                    $state['started_at'] = time();
                } else {
                    $state['later_at'] = time();
                }

                $this->save( $state );

                if ( $showing ) {
                    WordPlus_SDK::instance()->tracking()->queue( $this->product, 'start' === $action ? 'trial_notice_started' : 'trial_notice_later' );
                }
            }

            $offer = 'start' === $action ? $this->offer() : null;

            if ( wp_safe_redirect( null !== $offer ? $offer['url'] : remove_query_arg( array( 'wordplus_sdk_trial', '_wpnonce' ) ) ) ) {
                exit;
            }
        }

        private function link( $action )
        {
            return wp_nonce_url( add_query_arg( array( 'wordplus_sdk_trial' => $action ) ), 'wordplus_sdk_trial_' . $this->product->slug() );
        }

        private function pricing_url()
        {
            $path = 'admin.php?page=' . $this->product->page( 'pricing' );

            return $this->product->is_network_active() ? network_admin_url( $path ) : admin_url( $path );
        }

        private function state()
        {
            $all  = $this->product->is_network_active() ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );
            $slug = $this->product->slug();

            return is_array( $all ) && isset( $all[ $slug ] ) && is_array( $all[ $slug ] ) ? $all[ $slug ] : array();
        }

        private function save( array $state )
        {
            $network = $this->product->is_network_active();
            $all     = $network ? get_site_option( self::OPTION, array() ) : get_option( self::OPTION, array() );
            $all     = is_array( $all ) ? $all : array();

            $all[ $this->product->slug() ] = $state;

            if ( $network ) {
                update_site_option( self::OPTION, $all );
            } else {
                update_option( self::OPTION, $all, false );
            }
        }
    }
}
