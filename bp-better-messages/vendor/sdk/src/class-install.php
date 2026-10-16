<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Install' ) ) {
    final class WordPlus_SDK_Install
    {
        const TICKET_TTL = 172800;

        const MAX_ATTEMPTS = 10;

        const ERRORS = array( 'unknown_purchase', 'not_allowed', 'file_mods_disabled', 'filesystem', 'install_failed', 'activate_failed', 'too_many_attempts' );

        private $product;

        public function __construct( WordPlus_SDK_Product $product )
        {
            $this->product = $product;
        }

        private function tickets_option()
        {
            return 'wordplus_sdk_install_tickets_' . str_replace( '-', '_', $this->product->slug() );
        }

        private function attempts_transient()
        {
            return 'wordplus_sdk_install_attempts_' . str_replace( '-', '_', $this->product->slug() );
        }

        private function route()
        {
            $route = trim( (string) $this->product->get( 'rest.install' ), '/' );
            $parts = explode( '/', $route );

            return array( implode( '/', array_slice( $parts, 0, 2 ) ), '/' . implode( '/', array_slice( $parts, 2 ) ) );
        }

        public function routes()
        {
            list( $namespace, $path ) = $this->route();

            register_rest_route( $namespace, $path, array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_install' ),
                'permission_callback' => array( $this, 'can_receive' ),
                'args'                => array(
                    'code'  => array( 'required' => true, 'type' => 'string', 'maxLength' => 128 ),
                    'state' => array( 'required' => true, 'type' => 'string', 'maxLength' => 128 ),
                ),
            ) );
        }

        public function can_receive( WP_REST_Request $request )
        {
            $ticket = $this->ticket( (string) $request->get_param( 'state' ) );

            if ( null === $ticket ) {
                return $this->refusal( 'unknown_purchase', 403 );
            }

            $attempts = (int) get_site_transient( $this->attempts_transient() );

            if ( $attempts >= self::MAX_ATTEMPTS ) {
                return $this->refusal( 'too_many_attempts', 429 );
            }

            set_site_transient( $this->attempts_transient(), $attempts + 1, HOUR_IN_SECONDS );

            if ( ! wp_is_file_mod_allowed( 'wordplus_sdk_install' ) ) {
                return $this->refusal( 'file_mods_disabled', 409 );
            }

            if ( ! $this->can_install( $ticket['user'] ) ) {
                return $this->refusal( 'not_allowed', 403 );
            }

            return true;
        }

        private function ticket( $state )
        {
            $tickets = $this->tickets();
            $hash    = hash( 'sha256', $state );

            return '' !== $state && isset( $tickets[ $hash ] ) ? $tickets[ $hash ] : null;
        }

        private function refusal( $code, $status )
        {
            return new WP_Error( $code, $code, array( 'status' => $status ) );
        }

        public function endpoint()
        {
            return rest_url( trim( (string) $this->product->get( 'rest.install' ), '/' ) );
        }

        public function can_install( $user_id = 0 )
        {
            $user_id = $user_id ? (int) $user_id : get_current_user_id();

            return $user_id > 0
                && wp_is_file_mod_allowed( 'wordplus_sdk_install' )
                && user_can( $user_id, 'install_plugins' )
                && user_can( $user_id, 'activate_plugins' )
                && user_can( $user_id, $this->product->admin()->license_capability() );
        }

        public function remember( $state, $user_id )
        {
            if ( '' === $state || ! $this->can_install( $user_id ) ) {
                return;
            }

            $tickets = $this->tickets();
            $hash    = hash( 'sha256', $state );

            if ( isset( $tickets[ $hash ] ) && $tickets[ $hash ]['time'] > time() - DAY_IN_SECONDS ) {
                return;
            }

            $tickets[ $hash ] = array( 'user' => (int) $user_id, 'time' => time() );
            update_site_option( $this->tickets_option(), $tickets );
        }

        public function rest_install( WP_REST_Request $request )
        {
            $hash    = hash( 'sha256', (string) $request->get_param( 'state' ) );
            $tickets = $this->tickets();
            $user_id = (int) $tickets[ $hash ]['user'];

            $licence = $this->product->licence();
            $answer  = $licence->request( 'api/v1/install-codes/exchange', array(
                'code'    => (string) $request->get_param( 'code' ),
                'url'     => $licence->url(),
                'version' => $this->product->version(),
                'uid'     => $licence->uid(),
                'beta'    => $this->product->wants_beta(),
            ) );

            if ( is_wp_error( $answer ) ) {
                return $this->refuse( $answer->get_error_code(), 400 );
            }

            unset( $tickets[ $hash ] );
            update_site_option( $this->tickets_option(), $tickets );
            delete_user_meta( $user_id, $this->product->admin()->state_meta() );

            $plugin  = isset( $answer['plugin'] ) ? (string) $answer['plugin'] : $this->product->slug();
            $addon   = $this->product->addon( $plugin );
            $package = isset( $answer['download']['package'] ) ? (string) $answer['download']['package'] : '';

            if ( $plugin !== $this->product->slug() && '' === $addon ) {
                return array( 'installed' => false, 'error' => 'unknown_purchase', 'download' => $package );
            }

            if ( '' !== $addon ) {
                WordPlus_SDK::instance()->hand_over( $plugin, $answer, $licence->url() );
            } else {
                $licence->store_purchase( $answer, $this->product->slug() );
            }

            if ( '' === $addon && $this->product->is_paid_build() ) {
                return array( 'installed' => true, 'version' => $this->product->version() );
            }

            wp_set_current_user( $user_id );
            $installed = '' !== $addon ? $this->install_addon( $package, $addon ) : $this->install_paid_build( $package );

            if ( is_wp_error( $installed ) ) {
                $code = in_array( $installed->get_error_code(), self::ERRORS, true ) ? $installed->get_error_code() : 'install_failed';

                return array( 'installed' => false, 'error' => $code, 'download' => $package );
            }

            return array( 'installed' => true, 'version' => isset( $answer['download']['version'] ) ? (string) $answer['download']['version'] : null );
        }

        private function install_paid_build( $package )
        {
            $paid = $this->install_package( $package );

            if ( is_wp_error( $paid ) ) {
                return $paid;
            }

            $free    = $this->product->basename();
            $network = is_multisite() && is_plugin_active_for_network( $free );

            if ( $paid === $free ) {
                return new WP_Error( 'install_failed', 'The paid build could not be installed.' );
            }

            deactivate_plugins( $free, true, $network );
            $activated = activate_plugin( $paid, '', $network, true );

            if ( is_wp_error( $activated ) ) {
                activate_plugin( $free, '', $network, true );

                return new WP_Error( 'activate_failed', $activated->get_error_message() );
            }

            return true;
        }

        private function install_addon( $package, $basename )
        {
            $installed = $this->install_package( $package );

            if ( is_wp_error( $installed ) ) {
                return $installed;
            }

            if ( $installed !== $basename ) {
                return new WP_Error( 'install_failed', 'The download is not the add-on that was bought.' );
            }

            $network   = is_multisite() && is_plugin_active_for_network( $this->product->basename() );
            $activated = activate_plugin( $basename, '', $network );

            return is_wp_error( $activated ) ? new WP_Error( 'activate_failed', $activated->get_error_message() ) : true;
        }

        private function install_package( $package )
        {
            $server = wp_parse_url( $this->product->licence()->server(), PHP_URL_HOST );

            if ( '' === $package || wp_parse_url( $package, PHP_URL_HOST ) !== $server ) {
                return new WP_Error( 'install_failed', 'No download from the licence server.' );
            }

            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/misc.php';
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
            require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

            if ( 'direct' !== get_filesystem_method() || ! WP_Filesystem() ) {
                return new WP_Error( 'filesystem', 'WordPress needs FTP details to install plugins here.' );
            }

            $skin     = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader( $skin );
            $result   = $upgrader->install( $package, array( 'overwrite_package' => true ) );

            if ( is_wp_error( $result ) || ! $result ) {
                return new WP_Error( 'install_failed', is_wp_error( $result ) ? $result->get_error_message() : 'The plugin could not be installed.' );
            }

            $installed = $upgrader->plugin_info();

            return $installed ? (string) $installed : new WP_Error( 'install_failed', 'The plugin could not be installed.' );
        }

        private function tickets()
        {
            $tickets = get_site_option( $this->tickets_option(), array() );
            $tickets = is_array( $tickets ) ? $tickets : array();

            return array_filter( $tickets, function ( $ticket ) {
                return is_array( $ticket ) && isset( $ticket['user'], $ticket['time'] ) && $ticket['time'] > time() - self::TICKET_TTL;
            } );
        }

        private function refuse( $code, $status )
        {
            return new WP_REST_Response( array( 'installed' => false, 'error' => (string) $code ), $status );
        }
    }
}
