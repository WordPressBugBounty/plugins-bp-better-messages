<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Admin' ) ) {
    final class WordPlus_SDK_Admin
    {
        const SUPPORT_TYPES = array( 'technical', 'billing', 'feature', 'customization', 'pre_sale', 'press', 'bug' );

        const SUPPORT_UPLOADS = array(
            'max_files'       => 5,
            'max_file_bytes'  => 10485760,
            'max_total_bytes' => 20971520,
            'extensions'      => array( 'png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv', 'json', 'har', 'xml', 'zip', 'mp4', 'webm', 'mov' ),
        );

        private $product;

        private $assistant = null;

        public function __construct( WordPlus_SDK_Product $product )
        {
            $this->product = $product;

            add_action( 'admin_menu', array( $this, 'menu' ), 30 );
            add_action( 'network_admin_menu', array( $this, 'menu' ), 30 );
            add_action( 'admin_notices', array( $this, 'copy_notice' ) );
            add_action( 'admin_init', array( $this, 'maybe_welcome' ), 98 );
            $product->trial()->admin_hooks();

            if ( array() !== (array) $product->get( 'assistant', array() ) ) {
                add_action( 'admin_init', function () {
                    $this->assistant()->privacy_content();
                } );
            }

            if ( '' !== $product->file() ) {
                add_filter( 'plugin_action_links_' . $product->basename(), array( $this, 'licence_link' ), 20 );
                add_filter( 'network_admin_plugin_action_links_' . $product->basename(), array( $this, 'licence_link' ), 20 );
            }
        }

        const WELCOME_META = 'wordplus_sdk_welcome';

        public function needs_licence()
        {
            return $this->product->is_paid_build() && ! $this->product->has_access() && '' !== $this->product->page( 'account' );
        }

        public function activated()
        {
            $user_id = get_current_user_id();

            if ( ! $user_id || ! $this->product->is_paid_build() ) {
                return;
            }

            $waiting = get_user_meta( $user_id, self::WELCOME_META, true );
            $waiting = is_array( $waiting ) ? $waiting : array();

            $waiting[ $this->product->slug() ] = time();
            update_user_meta( $user_id, self::WELCOME_META, $waiting );

            add_action( 'shutdown', array( $this, 'forget_welcome_after_bulk' ) );
        }

        public function forget_welcome_after_bulk()
        {
            if ( ! WordPlus_SDK::several_activated() ) {
                return;
            }

            $user_id = get_current_user_id();
            $waiting = get_user_meta( $user_id, self::WELCOME_META, true );

            if ( ! is_array( $waiting ) || ! isset( $waiting[ $this->product->slug() ] ) ) {
                return;
            }

            unset( $waiting[ $this->product->slug() ] );

            if ( array() === $waiting ) {
                delete_user_meta( $user_id, self::WELCOME_META );
            } else {
                update_user_meta( $user_id, self::WELCOME_META, $waiting );
            }
        }

        public function maybe_welcome()
        {
            $user_id = get_current_user_id();

            if ( ! $user_id || wp_doing_ajax() ) {
                return;
            }

            $waiting = get_user_meta( $user_id, self::WELCOME_META, true );
            $slug    = $this->product->slug();

            if ( ! is_array( $waiting ) || ! isset( $waiting[ $slug ] ) ) {
                return;
            }

            $since = (int) $waiting[ $slug ];
            unset( $waiting[ $slug ] );

            if ( array() === $waiting ) {
                delete_user_meta( $user_id, self::WELCOME_META );
            } else {
                update_user_meta( $user_id, self::WELCOME_META, $waiting );
            }

            if ( $since < time() - DAY_IN_SECONDS || ! $this->needs_licence() || ! $this->can_change_license() ) {
                return;
            }

            if ( wp_safe_redirect( $this->welcome_url() ) ) {
                exit;
            }
        }

        public function welcome_url()
        {
            $url   = $this->product->page_url( 'account' );
            $parts = explode( '#', $url, 2 );

            return add_query_arg( 'welcome', $this->product->slug(), $parts[0] ) . ( isset( $parts[1] ) ? '#' . $parts[1] : '' );
        }

        public function licence_link( $links )
        {
            if ( ! $this->needs_licence() || ! $this->can_change_license() ) {
                return $links;
            }

            $links = (array) $links;
            $page  = (string) wp_parse_url( $this->product->page_url( 'account' ), PHP_URL_QUERY );

            foreach ( $links as $link ) {
                if ( '' !== $page && false !== strpos( (string) $link, $page ) ) {
                    return $links;
                }
            }

            $links['wordplus-sdk-licence'] = '<a href="' . esc_url( $this->welcome_url() ) . '"><strong>' . esc_html__( 'Activate licence', 'wordplus-sdk' ) . '</strong></a>';

            return $links;
        }

        public function copy_notice()
        {
            $licence = $this->product->licence();

            if ( $licence->lead() !== $this->product || ! $this->can_change_license() ) {
                return;
            }

            $copy = $licence->copy_state();

            if ( null === $copy ) {
                return;
            }

            $here = (string) wp_parse_url( $licence->url(), PHP_URL_HOST );
            $path = '/' . trim( (string) $this->product->get( 'rest.namespace' ), '/' ) . '/' . trim( (string) $this->product->get( 'rest.base' ), '/' ) . '/copy';
            $id   = 'wordplus-copy-' . $this->product->slug();
            ?>
            <div class="notice notice-warning" id="<?php echo esc_attr( $id ); ?>">
                <p><strong><?php
                    /* translators: 1: this site's address, 2: the site it was copied from */
                    echo esc_html( sprintf( __( '%1$s is a copy of %2$s. What is it?', 'wordplus-sdk' ), $here, $copy['of'] ) );
                ?></strong></p>
                <p><?php
                    echo esc_html( '' !== $copy['refused']
                        ? __( 'The licence can\'t keep it as a staging copy now, so the cloud is off here until you answer.', 'wordplus-sdk' )
                        : __( 'Until you answer, the cloud works here as on a staging copy, with limits.', 'wordplus-sdk' ) );
                ?></p>
                <p>
                    <button type="button" class="button button-primary" data-answer="move"><?php esc_html_e( 'The site moved here', 'wordplus-sdk' ); ?></button>
                    <button type="button" class="button" data-answer="separate"><?php esc_html_e( 'A separate site', 'wordplus-sdk' ); ?></button>
                    <button type="button" class="button" data-answer="staging"><?php esc_html_e( 'A staging copy', 'wordplus-sdk' ); ?></button>
                </p>
                <p class="description"><?php esc_html_e( 'Moved here: the site keeps its cloud data, and its old address stops using the licence. A separate site takes a place on the licence. A staging copy takes none and has the cloud with limits.', 'wordplus-sdk' ); ?></p>
            </div>
            <script>
            ( function () {
                var box = document.getElementById( <?php echo wp_json_encode( $id ); ?> );
                box.querySelectorAll( 'button[data-answer]' ).forEach( function ( button ) {
                    button.addEventListener( 'click', function () {
                        box.querySelectorAll( 'button' ).forEach( function ( each ) { each.disabled = true; } );
                        window.fetch( <?php echo wp_json_encode( rest_url( ltrim( $path, '/' ) ) ); ?>, {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?> },
                            body: JSON.stringify( { answer: button.getAttribute( 'data-answer' ) } )
                        } ).then( function ( response ) {
                            return response.json().then( function ( body ) { return { ok: response.ok, body: body }; } );
                        } ).then( function ( result ) {
                            if ( result.ok ) {
                                window.location.reload();
                                return;
                            }
                            var note = document.createElement( 'p' );
                            note.style.color = '#b32d2e';
                            note.textContent = result.body && result.body.message ? result.body.message : <?php echo wp_json_encode( __( 'WordPlus Cloud could not be reached. Please try again in a minute.', 'wordplus-sdk' ) ); ?>;
                            box.appendChild( note );
                            box.querySelectorAll( 'button' ).forEach( function ( each ) { each.disabled = false; } );
                        } );
                    } );
                } );
            } )();
            </script>
            <?php
        }

        const ROCKET = '&nbsp;<svg class="wordplus-sdk-rocket" width="11" height="11" viewBox="0 0 512 512" fill="currentColor" aria-hidden="true" focusable="false" style="vertical-align:-1px"><path d="M505.12019,19.09375c-1.18945-5.53125-6.65819-11-12.207-12.1875C460.716,0,435.507,0,410.40747,0,307.17523,0,245.26909,55.20312,199.05238,128H94.83772c-16.34763.01562-35.55658,11.875-42.88664,26.48438L2.51562,253.29688A28.4,28.4,0,0,0,0,264a24.00867,24.00867,0,0,0,24.00582,24H127.81618l-22.47457,22.46875c-11.36521,11.36133-12.99607,32.25781,0,45.25L156.24582,406.625c11.15623,11.1875,32.15619,13.15625,45.27726,0l22.47457-22.46875V488a24.00867,24.00867,0,0,0,24.00581,24,28.55934,28.55934,0,0,0,10.707-2.51562l98.72834-49.39063c14.62888-7.29687,26.50776-26.5,26.50776-42.85937V312.79688c72.59753-46.3125,128.03493-108.40626,128.03493-211.09376C512.07526,76.5,512.07526,51.29688,505.12019,19.09375ZM384.04033,168A40,40,0,1,1,424.05,128,40.02322,40.02322,0,0,1,384.04033,168Z"/></svg>';

        public function state_meta()
        {
            return 'wordplus_sdk_state_' . str_replace( '-', '_', $this->product->slug() );
        }

        public function menu()
        {
            $parent    = (string) $this->product->get( 'menu.parent' );
            $render    = array( $this, 'render' );
            $licensing = $this->license_capability();
            $hooks     = array();

            if ( '' !== $this->product->page( 'account' ) ) {
                $label            = _x( 'Licence', 'Admin menu', 'wordplus-sdk' );
                $in_menu          = null === $this->product->host() && ( $this->product->can_activate() || array() !== $this->product->hosted() );
                $hooks['account'] = add_submenu_page( $in_menu ? $parent : '', $label, $label, $licensing, $this->product->page( 'account' ), $render, 90 );
            }

            if ( '' !== $this->product->page( 'contact' ) ) {
                $label            = _x( 'Support', 'Admin menu', 'wordplus-sdk' );
                $hooks['contact'] = add_submenu_page( $parent, $label, $label, 'manage_options', $this->product->page( 'contact' ), $render, 92 );
            }

            if ( '' !== $this->product->page( 'pricing' ) ) {
                $slug  = $this->product->page( 'pricing' );
                $label = $this->product->has_access() ? _x( 'Pricing', 'Admin menu', 'wordplus-sdk' ) : _x( 'Upgrade', 'Admin menu', 'wordplus-sdk' );

                $hooks['pricing'] = add_submenu_page( $parent, $label, $label . ( $this->product->has_access() ? '&nbsp;&#x27a4;' : self::ROCKET ), $licensing, $slug, $render, 93 );

                if ( $this->product->trial()->in_menu() ) {
                    $trial = _x( 'Start trial', 'Admin menu', 'wordplus-sdk' );
                    $link  = 'admin.php?page=' . $slug . '&trial=1';

                    add_submenu_page( $parent, $trial, $trial . self::ROCKET, $licensing, $link, '', 93 );

                    add_action( 'admin_head', function () use ( $parent, $slug ) {
                        remove_submenu_page( $parent, $slug );
                    } );
                    add_filter( 'submenu_file', function ( $submenu_file ) use ( $slug, $link ) {
                        return isset( $GLOBALS['plugin_page'] ) && $slug === $GLOBALS['plugin_page'] ? $link : $submenu_file;
                    } );
                }
            }

            foreach ( $hooks as $page => $hook ) {
                if ( $hook ) {
                    add_action( 'load-' . $hook, function () use ( $page ) {
                        $host = 'account' === $page ? $this->product->host() : null;

                        if ( null !== $host && '' !== $host->page( 'account' ) && wp_safe_redirect( $this->product->page_url( 'account' ) ) ) {
                            exit;
                        }

                        if ( 'account' === $page && empty( $GLOBALS['title'] ) ) {
                            /* translators: %s: the plugin's name */
                            $GLOBALS['title'] = sprintf( _x( '%s Licence', 'Admin page title', 'wordplus-sdk' ), $this->product->name() );
                        }

                        WordPlus_SDK_UI::enqueue_page( $this->page_data( $page ) );

                        if ( 'contact' === $page ) {
                            $this->assistant()->enqueue();
                        }
                    } );

                    if ( 'contact' === $page ) {
                        add_action( 'load-' . $hook, array( $this, 'keep_contact_message' ) );
                    }
                }
            }
        }

        public function keep_contact_message()
        {
            add_filter( 'removable_query_args', function ( $args ) {
                return array_values( array_diff( (array) $args, array( 'message' ) ) );
            } );
        }

        public function is_page_screen( $screen_id )
        {
            foreach ( array( 'account', 'pricing', 'contact' ) as $page ) {
                $slug = $this->product->page( $page );

                if ( '' !== $slug && substr( (string) $screen_id, -strlen( '_page_' . $slug ) ) === '_page_' . $slug ) {
                    return true;
                }
            }

            return false;
        }

        public function render()
        {
            echo '<div id="wordplus-sdk-page" class="wplus-root"></div>';
        }

        public function assistant()
        {
            if ( null === $this->assistant ) {
                require_once __DIR__ . '/class-assistant.php';
                $this->assistant = new WordPlus_SDK_Assistant( $this->product );
            }

            return $this->assistant;
        }

        public function page_data( $page )
        {
            $licence = $this->product->licence();
            $user    = wp_get_current_user();
            $words   = $this->product->words();

            $settings = (string) $this->product->get( 'urls.settings' );
            $support  = array();

            foreach ( (array) $this->product->get( 'support' ) as $slug => $name ) {
                $support[] = array( 'value' => (string) $slug, 'label' => (string) $name );
            }

            return array(
                'page'     => $page,
                'rest'     => array(
                    'root'  => '/' . trim( (string) $this->product->get( 'rest.namespace' ), '/' ) . '/' . trim( (string) $this->product->get( 'rest.base' ), '/' ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                ),
                'product'  => array(
                    'slug'          => $this->product->slug(),
                    'name'          => $this->product->name(),
                    'icon'          => (string) $this->product->get( 'icon' ),
                    'version'       => $this->product->version(),
                    'paidBuild'     => $this->product->is_paid_build(),
                    'canActivate'   => $this->product->can_activate(),
                    'equivalentPlans' => (object) array_map( 'strval', (array) $this->product->get( 'equivalent_plans', array() ) ),
                    'forceConstant' => (string) $this->product->get( 'force_key' ),
                    'words'         => array_map( function ( $word ) {
                        return is_array( $word ) ? array_map( 'strval', $word ) : (string) $word;
                    }, $words ),
                    'support'       => $support,
                ),
                'license'  => $this->summary(),
                'urls'     => array(
                    'account'      => $this->product->page_url( 'account' ),
                    'pricing'      => $this->product->page_url( 'pricing' ),
                    'contact'      => $this->product->page_url( 'contact' ),
                    'settings'     => '' !== $settings ? $settings : admin_url( 'admin.php?page=' . (string) $this->product->get( 'menu.parent' ) ),
                    'plugins'      => admin_url( 'plugins.php' ),
                    'uploadPlugin' => admin_url( 'plugin-install.php?tab=upload' ),
                    'portal'       => (string) $this->product->get( 'account_url' ),
                    'password'     => (string) $this->product->get( 'password_url' ),
                    'staging'      => (string) $this->product->get( 'staging_url' ),
                    'checkout'     => (string) $this->product->get( 'checkout_url' ),
                    'homepage'     => (string) $this->product->get( 'urls.homepage' ),
                    'docs'         => (string) $this->product->get( 'urls.docs' ),
                    'buy'          => (string) $this->product->get( 'urls.buy' ),
                ),
                'checkout' => array(
                    'site'    => $licence->url(),
                    'return'  => $this->product->page_url( 'pricing' ),
                    'state'   => 'pricing' === $page ? $this->checkout_state() : '',
                    'trial'   => false,
                    'install' => ! $this->product->is_paid_build() && $this->product->install() && $this->product->install()->can_install() ? $this->product->install()->endpoint() : '',
                ),
                'install'  => null,
                'needsLicence' => 'account' === $page && $this->needs_licence(),
                'welcome'  => false,
                'support'  => 'contact' === $page ? array(
                    'prefill'  => array( 'topic' => '', 'summary' => '', 'message' => '' ),
                    'siteInfo' => $this->site_info(),
                    'limits'   => $this->support_limits(),
                ) : null,
                'assistant' => 'contact' === $page && $this->assistant()->enabled() ? $this->assistant()->page_data() : null,
                'assistantLink' => $this->assistant()->link(),
                'user'     => array(
                    'email' => $user ? $user->user_email : '',
                    'name'  => $user ? $user->display_name : '',
                ),
                'tracking' => in_array( $page, array( 'account', 'optin' ), true ) ? WordPlus_SDK::instance()->tracking()->summary( $this->product ) : null,
                'addons'   => 'account' === $page ? array_map( function ( WordPlus_SDK_Product $addon ) {
                    return $addon->admin()->page_data( 'account' );
                }, $this->product->hosted() ) : array(),
            );
        }


        public function summary()
        {
            $licence = $this->product->licence();
            $data    = $licence->get();
            $key     = $licence->get_key();
            $site    = $licence->site_credentials();
            $url     = $licence->url();

            return array(
                'siteKey'       => null !== $site ? $site['key'] : null,
                'secretAt'      => null !== $site && ! empty( $data['activations'][ $url ]['secret_received_at'] ) ? (int) $data['activations'][ $url ]['secret_received_at'] : null,
                'secretPending' => null !== $site && $site['effective_at'] > time(),
                'secretRefused' => isset( $data['secret_refused'] ) ? (string) $data['secret_refused'] : null,
                'hasKey'        => '' !== $key,
                'keyHint'       => '' === $key ? '' : substr( $key, 0, 6 ) . '…' . substr( $key, -4 ),
                'forced'        => $licence->is_forced(),
                'active'        => $licence->is_active(),
                'covered'       => null === $licence->refused( $this->product->slug() ),
                'status'        => isset( $data['status'] ) ? $data['status'] : ( '' === $key ? 'none' : 'unknown' ),
                'plan'          => isset( $data['plan'] ) ? $data['plan'] : null,
                'expiresAt'     => isset( $data['expires_at'] ) ? $data['expires_at'] : null,
                'quota'         => isset( $data['quota'] ) ? $data['quota'] : null,
                'used'          => isset( $data['used'] ) ? (int) $data['used'] : null,
                'siteId'        => $licence->get_activation_id(),
                'migrated'      => ! empty( $data['migrated_from'] ),
                'checkedAt'     => isset( $data['checked_at'] ) ? (int) $data['checked_at'] : null,
                'checkDue'      => $this->can_change_license() && $licence->check_due(),
                'trialUsed'     => $this->product->trial()->used(),
                'beta'          => $this->product->wants_beta(),
                'betaOffered'   => false !== $this->product->updater(),
                'staging'       => ! empty( $data['activations'][ $url ]['staging'] ),
                'deactivated'   => ! $licence->is_forced() && ! empty( $data['activations'][ $url ]['deactivated'] ),
                'copy'          => $licence->copy_state(),
                'account'       => '' !== $key && isset( $data['account']['email'] )
                    ? array( 'email' => (string) $data['account']['email'], 'verified' => ! empty( $data['account']['verified'] ) )
                    : null,
                'plugins'       => array_map( function ( WordPlus_SDK_Product $product ) {
                    return array( 'slug' => $product->slug(), 'name' => $product->name(), 'version' => (string) $product->version() );
                }, WordPlus_SDK::instance()->products_on( $this->product->licence_store() ) ),
            );
        }

        public function routes()
        {
            $admin     = array( $this, 'can_manage' );
            $licensing = array( $this, 'can_change_license' );
            $namespace = (string) $this->product->get( 'rest.namespace' );
            $base      = '/' . trim( (string) $this->product->get( 'rest.base' ), '/' );

            register_rest_route( $namespace, $base, array(
                'methods'             => 'GET',
                'callback'            => function () {
                    return $this->summary();
                },
                'permission_callback' => $admin,
            ) );

            register_rest_route( $namespace, $base . '/activate', array(
                'methods'             => 'POST',
                'callback'            => function ( WP_REST_Request $request ) {
                    $given  = trim( (string) $request->get_param( 'key' ) );
                    $key    = '' !== $given ? $given : $this->product->licence()->get_key();
                    $result = '' !== $key ? $this->product->licence()->activate( $key, $this->product->slug() ) : new WP_Error( 'invalid_license', 'invalid_license' );

                    if ( is_wp_error( $result ) ) {
                        return $this->error( $result );
                    }

                    $tracking = $request->get_param( 'tracking' );

                    if ( '' !== $given && $this->product->is_paid_build() ) {
                        WordPlus_SDK::instance()->tracking()->licence_choice( $this->product, is_array( $tracking ) && ! empty( $tracking['extensions'] ) );
                    }

                    return $this->summary();
                },
                'permission_callback' => $licensing,
                'args'                => array(
                    'key'      => array( 'type' => 'string', 'default' => '' ),
                    'tracking' => array( 'type' => 'object' ),
                ),
            ) );

            register_rest_route( $namespace, $base . '/deactivate', array(
                'methods'             => 'POST',
                'callback'            => function () {
                    $this->product->licence()->deactivate();

                    return $this->summary();
                },
                'permission_callback' => $licensing,
            ) );

            register_rest_route( $namespace, $base . '/refresh', array(
                'methods'             => 'POST',
                'callback'            => function () {
                    $this->product->licence()->refresh();

                    return $this->summary();
                },
                'permission_callback' => $licensing,
            ) );

            register_rest_route( $namespace, $base . '/secret', array(
                'methods'             => 'POST',
                'callback'            => function ( WP_REST_Request $request ) {
                    $result = $this->product->licence()->rotate_secret( (bool) $request->get_param( 'endPrevious' ) );

                    return is_wp_error( $result ) ? $this->error( $result ) : $this->summary();
                },
                'permission_callback' => $licensing,
                'args'                => array( 'endPrevious' => array( 'type' => 'boolean', 'default' => false ) ),
            ) );

            register_rest_route( $namespace, $base . '/beta', array(
                'methods'             => 'POST',
                'callback'            => function ( WP_REST_Request $request ) {
                    $this->product->set_beta( (bool) $request->get_param( 'enabled' ) );

                    return $this->summary();
                },
                'permission_callback' => $licensing,
                'args'                => array( 'enabled' => array( 'required' => true, 'type' => 'boolean' ) ),
            ) );

            if ( $this->product->updater() ) {
                register_rest_route( $namespace, $base . '/updates', array(
                    'methods'             => 'POST',
                    'callback'            => function () {
                        $plugins = array();

                        foreach ( WordPlus_SDK::instance()->products_on( $this->product->licence_store() ) as $product ) {
                            if ( $product->updater() ) {
                                $plugins[] = $product->updater()->check_at_once();
                            }
                        }

                        return array( 'plugins' => $plugins );
                    },
                    'permission_callback' => $licensing,
                ) );
            }

            register_rest_route( $namespace, $base . '/sites', array(
                'methods'             => 'GET',
                'callback'            => function () {
                    $answer = $this->product->licence()->sites();

                    return is_wp_error( $answer ) ? $this->error( $answer ) : $answer;
                },
                'permission_callback' => $licensing,
            ) );

            register_rest_route( $namespace, $base . '/pricing', array(
                'methods'             => 'GET',
                'callback'            => function () {
                    $locale  = (string) preg_replace( '/[^A-Za-z_]/', '', determine_locale() );
                    $kept    = 'wordplus_sdk_pricing_' . str_replace( '-', '_', $this->product->slug() ) . ( 'en_US' === $locale ? '' : '_' . strtolower( $locale ) );
                    $pricing = $this->cached_get( 'api/v1/plugins/' . rawurlencode( $this->product->slug() ) . '/pricing?lang=' . rawurlencode( $locale ), $kept, HOUR_IN_SECONDS );

                    $this->product->trial()->remember( $pricing );

                    return $pricing;
                },
                'permission_callback' => $admin,
            ) );

            register_rest_route( $namespace, $base . '/support', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_support' ),
                'permission_callback' => $admin,
                'args'                => array(
                    'email'   => array( 'required' => true, 'type' => 'string' ),
                    'subject' => array( 'required' => true, 'type' => 'string' ),
                    'message' => array( 'required' => true, 'type' => 'string' ),
                ),
            ) );

            register_rest_route( $namespace, $base . '/claim', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_claim' ),
                'permission_callback' => $licensing,
                'args'                => array(
                    'code'  => array( 'required' => true, 'type' => 'string' ),
                    'state' => array( 'required' => true, 'type' => 'string' ),
                ),
            ) );

            register_rest_route( $namespace, $base . '/copy', array(
                'methods'             => 'POST',
                'callback'            => function ( WP_REST_Request $request ) {
                    $result = $this->product->licence()->answer_copy( (string) $request->get_param( 'answer' ) );

                    return is_wp_error( $result ) ? $this->error( $result ) : $this->summary();
                },
                'permission_callback' => $licensing,
                'args'                => array(
                    'answer' => array( 'required' => true, 'type' => 'string', 'enum' => WordPlus_SDK_Licence::COPY_ANSWERS ),
                ),
            ) );

            register_rest_route( $namespace, $base . '/dismiss-waiting', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_dismiss_waiting' ),
                'permission_callback' => $licensing,
                'args'                => array(
                    'plugin' => array( 'required' => true, 'type' => 'string' ),
                ),
            ) );

            if ( $this->assistant()->enabled() ) {
                $this->assistant()->routes( $namespace, $base, $admin );
            }
        }

        public function rest_dismiss_waiting( WP_REST_Request $request )
        {
            $plugin = (string) $request->get_param( 'plugin' );

            if ( '' === $this->product->addon( $plugin ) ) {
                return new WP_Error( 'wrong_plugin', __( 'This plugin is not an add-on of this one.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            WordPlus_SDK::instance()->dismiss_waiting( $plugin );

            return array( 'dismissed' => true );
        }

        public function can_manage()
        {
            return current_user_can( 'manage_options' );
        }

        public function license_capability()
        {
            return $this->product->licence()->is_network() ? 'manage_network_options' : 'manage_options';
        }

        public function can_change_license()
        {
            return current_user_can( $this->license_capability() );
        }

        public function rest_support( WP_REST_Request $request )
        {
            $files = $this->support_files( $request->get_file_params() );

            if ( is_wp_error( $files ) ) {
                return $files;
            }

            $licence  = $this->product->licence();
            $products = array_keys( (array) $this->product->get( 'support' ) );
            $text     = function ( $name ) use ( $request ) {
                return sanitize_text_field( (string) $request->get_param( $name ) );
            };

            $fields = array(
                'plugin'         => $this->product->slug(),
                'product'        => in_array( (string) $request->get_param( 'product' ), array_map( 'strval', $products ), true ) ? (string) $request->get_param( 'product' ) : $this->product->slug(),
                'url'            => $licence->url(),
                'email'          => sanitize_email( (string) $request->get_param( 'email' ) ),
                'name'           => $text( 'name' ),
                'type'           => in_array( (string) $request->get_param( 'type' ), self::SUPPORT_TYPES, true ) ? (string) $request->get_param( 'type' ) : 'technical',
                'subject'        => $text( 'subject' ),
                'message'        => sanitize_textarea_field( (string) $request->get_param( 'message' ) ),
                'page_url'       => esc_url_raw( (string) $request->get_param( 'page_url' ) ),
                'wp_login'       => $text( 'wp_login' ),
                'wp_password'    => (string) $request->get_param( 'wp_password' ),
                'ftp_host'       => $text( 'ftp_host' ),
                'ftp_login'      => $text( 'ftp_login' ),
                'ftp_password'   => (string) $request->get_param( 'ftp_password' ),
                'license_key'    => $licence->get_key(),
                'plugin_version' => $this->product->version(),
                'wp_version'     => get_bloginfo( 'version' ),
                'php_version'    => PHP_VERSION,
                'site_info'      => wp_list_pluck( $this->site_info(), 'value', 'label' ),
            );

            $boundary = 'wplus' . wp_generate_password( 24, false );
            $response = wp_remote_post( $licence->server() . 'api/v1/support', array(
                'timeout' => 60,
                'headers' => array(
                    'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
                    'Accept'       => 'application/json',
                ),
                'body'    => $this->multipart( $fields, $files, $boundary ),
            ) );

            if ( is_wp_error( $response ) ) {
                return $this->error( new WP_Error( 'unreachable', $response->get_error_message() ) );
            }

            $status = (int) wp_remote_retrieve_response_code( $response );
            $data   = json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 === $status ) {
                return array( 'sent' => true );
            }

            if ( 429 === $status ) {
                return new WP_Error( 'too_many', __( 'Several messages were just sent from this site. Please wait a minute and send again.', 'wordplus-sdk' ), array( 'status' => 429 ) );
            }

            if ( 422 === $status && is_array( $data ) && ! empty( $data['message'] ) ) {
                return new WP_Error( 'invalid', (string) $data['message'], array( 'status' => 400 ) );
            }

            return $this->error( new WP_Error( 'unreachable', 'HTTP ' . $status ) );
        }

        private function support_files( $params )
        {
            $upload = isset( $params['attachments'] ) && is_array( $params['attachments'] ) ? $params['attachments'] : array();
            $names  = isset( $upload['name'] ) ? (array) $upload['name'] : array();
            $files  = array();
            $total  = 0;
            $limits = $this->support_limits();

            foreach ( $names as $index => $name ) {
                $error = isset( $upload['error'][ $index ] ) ? (int) $upload['error'][ $index ] : UPLOAD_ERR_NO_FILE;

                if ( UPLOAD_ERR_NO_FILE === $error ) {
                    continue;
                }

                $tmp       = isset( $upload['tmp_name'][ $index ] ) ? (string) $upload['tmp_name'][ $index ] : '';
                $size      = isset( $upload['size'][ $index ] ) ? (int) $upload['size'][ $index ] : 0;
                $extension = strtolower( pathinfo( (string) $name, PATHINFO_EXTENSION ) );

                if ( UPLOAD_ERR_OK !== $error || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
                    /* translators: %s: file name */
                    return new WP_Error( 'upload_failed', sprintf( __( '%s could not be uploaded to this site. It may be larger than the site allows.', 'wordplus-sdk' ), sanitize_file_name( (string) $name ) ), array( 'status' => 400 ) );
                }

                if ( ! in_array( $extension, $limits['extensions'], true ) ) {
                    /* translators: %s: file name */
                    return new WP_Error( 'file_type', sprintf( __( '%s cannot be attached: attach screenshots, recordings, logs, PDFs or zip files.', 'wordplus-sdk' ), sanitize_file_name( (string) $name ) ), array( 'status' => 400 ) );
                }

                if ( $size > $limits['maxFileBytes'] ) {
                    /* translators: 1: file name, 2: size limit such as 10 MB */
                    return new WP_Error( 'file_size', sprintf( __( '%1$s is larger than %2$s.', 'wordplus-sdk' ), sanitize_file_name( (string) $name ), size_format( $limits['maxFileBytes'] ) ), array( 'status' => 400 ) );
                }

                $total  += $size;
                $files[] = array(
                    'name' => sanitize_file_name( (string) $name ),
                    'type' => isset( $upload['type'][ $index ] ) ? sanitize_mime_type( (string) $upload['type'][ $index ] ) : 'application/octet-stream',
                    'path' => $tmp,
                );
            }

            if ( count( $files ) > $limits['maxFiles'] ) {
                /* translators: %d: number of files */
                return new WP_Error( 'file_count', sprintf( __( 'Attach up to %d files.', 'wordplus-sdk' ), $limits['maxFiles'] ), array( 'status' => 400 ) );
            }

            if ( $total > $limits['maxTotalBytes'] ) {
                /* translators: %s: size limit such as 20 MB */
                return new WP_Error( 'files_size', sprintf( __( 'The files come to more than %s together.', 'wordplus-sdk' ), size_format( $limits['maxTotalBytes'] ) ), array( 'status' => 400 ) );
            }

            return $files;
        }

        public function support_limits()
        {
            $post_max = wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) );

            return array(
                'maxFiles'      => self::SUPPORT_UPLOADS['max_files'],
                'maxFileBytes'  => (int) min( self::SUPPORT_UPLOADS['max_file_bytes'], wp_max_upload_size() ),
                'maxTotalBytes' => (int) min( self::SUPPORT_UPLOADS['max_total_bytes'], $post_max > 0 ? max( 0, $post_max - 1024 * 1024 ) : self::SUPPORT_UPLOADS['max_total_bytes'] ),
                'extensions'    => self::SUPPORT_UPLOADS['extensions'],
            );
        }

        public function site_info()
        {
            global $wpdb;

            if ( ! function_exists( 'get_plugins' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }

            $licence = $this->product->licence();
            $data    = $licence->get();
            $plugins = array();

            foreach ( get_plugins() as $file => $plugin ) {
                if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
                    $plugins[] = $plugin['Name'] . ' ' . $plugin['Version'];
                }
            }

            $own   = $this->product->get( 'site_info' );
            $own   = is_callable( $own ) ? (array) call_user_func( $own ) : array();
            $theme = wp_get_theme();

            $info = array( 'Site' => home_url() );

            if ( empty( $own ) ) {
                $info[ $this->product->name() ] = $this->product->version() . ', ' . ( $this->product->is_paid_build() ? 'paid build' : 'free build' );
            }

            $info = array_merge( $info, $own, array(
                'Licence'        => '' === $licence->get_key() ? 'none' : trim( ( isset( $data['status'] ) ? $data['status'] : 'unknown' ) . ', ' . ( isset( $data['plan'] ) ? $data['plan'] : '' ) . ( ! empty( $data['license_id'] ) ? ', licence ' . $data['license_id'] : '' ), ', ' ),
                'WordPress'      => get_bloginfo( 'version' ) . ( is_multisite() ? ', multisite' : '' ) . ', ' . get_locale(),
                'PHP'            => PHP_VERSION . ', memory ' . ( '-1' === (string) ini_get( 'memory_limit' ) ? 'unlimited' : ini_get( 'memory_limit' ) ) . ', uploads ' . size_format( wp_max_upload_size() ),
                'Database'       => method_exists( $wpdb, 'db_server_info' ) ? (string) $wpdb->db_server_info() : (string) $wpdb->db_version(),
                'Web server'     => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '',
                'Browser'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '',
                'Theme'        => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $theme->parent() ? ' (child of ' . $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' ) . ')' : '' ),
                'Active plugins' => implode( ', ', $plugins ),
            ) );

            $list = array();

            foreach ( $info as $label => $value ) {
                if ( '' !== trim( (string) $value ) ) {
                    $list[] = array( 'label' => (string) $label, 'value' => (string) $value );
                }
            }

            return $list;
        }

        private function multipart( $fields, $files, $boundary )
        {
            $body = '';
            $part = function ( $name, $value ) use ( &$body, $boundary ) {
                $body .= '--' . $boundary . "\r\n" . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n" . $value . "\r\n";
            };

            foreach ( $fields as $name => $value ) {
                if ( is_array( $value ) ) {
                    foreach ( $value as $key => $item ) {
                        $part( $name . '[' . str_replace( array( '[', ']', '"', "\r", "\n" ), '', (string) $key ) . ']', (string) $item );
                    }
                } elseif ( '' !== (string) $value ) {
                    $part( $name, (string) $value );
                }
            }

            foreach ( $files as $file ) {
                $body .= '--' . $boundary . "\r\n"
                    . 'Content-Disposition: form-data; name="attachments[]"; filename="' . str_replace( array( '"', "\r", "\n" ), '', $file['name'] ) . '"' . "\r\n"
                    . 'Content-Type: ' . ( '' !== $file['type'] ? $file['type'] : 'application/octet-stream' ) . "\r\n\r\n"
                    . file_get_contents( $file['path'] ) . "\r\n";
            }

            return $body . '--' . $boundary . "--\r\n";
        }

        public function rest_claim( WP_REST_Request $request )
        {
            $user_id = get_current_user_id();
            $state   = (string) get_user_meta( $user_id, $this->state_meta(), true );

            if ( '' === $state || ! hash_equals( $state, (string) $request->get_param( 'state' ) ) ) {
                return new WP_Error( 'invalid_state', __( 'This purchase was not started from this site\'s admin. The licence key is in the email from WordPlus Cloud.', 'wordplus-sdk' ), array( 'status' => 400 ) );
            }

            $licence = $this->product->licence();
            $answer  = $licence->request( 'api/v1/install-codes/exchange', array(
                'code'    => (string) $request->get_param( 'code' ),
                'url'     => $licence->url(),
                'version' => $this->product->version(),
                'uid'     => $licence->uid(),
                'beta'    => $this->product->wants_beta(),
            ) );

            if ( is_wp_error( $answer ) ) {
                return $this->error( $answer );
            }

            delete_user_meta( $user_id, $this->state_meta() );

            $plugin = isset( $answer['plugin'] ) ? (string) $answer['plugin'] : $this->product->slug();

            if ( $plugin !== $this->product->slug() && '' === $this->product->addon( $plugin ) ) {
                return $this->error( new WP_Error( 'wrong_plugin' ) );
            }

            if ( $plugin === $this->product->slug() ) {
                $licence->store_purchase( $answer, $plugin );
            } else {
                WordPlus_SDK::instance()->hand_over( $plugin, $answer, $licence->url() );
            }

            return array(
                'license'  => $this->summary(),
                'plugin'   => $plugin,
                'download' => isset( $answer['download']['package'] ) ? (string) $answer['download']['package'] : '',
            );
        }

        public function addon_checkout_url( $checkout_url, $return, $install = true )
        {
            $args = array(
                'site'   => $this->product->licence()->url(),
                'return' => (string) $return,
                'state'  => $this->checkout_state(),
            );

            if ( $install && $this->product->install() && $this->product->install()->can_install() ) {
                $args['install'] = $this->product->install()->endpoint();
            }

            return add_query_arg( array_map( 'rawurlencode', $args ), (string) $checkout_url );
        }

        private function checkout_state()
        {
            $user_id = get_current_user_id();
            $state   = (string) get_user_meta( $user_id, $this->state_meta(), true );

            if ( '' === $state ) {
                $state = wp_generate_password( 24, false );
                update_user_meta( $user_id, $this->state_meta(), $state );
            }

            if ( $this->product->install() ) {
                $this->product->install()->remember( $state, $user_id );
            }

            return $state;
        }

        private function cached_get( $path, $transient, $ttl )
        {
            $cached = get_site_transient( $transient );

            if ( is_array( $cached ) ) {
                return $cached;
            }

            $response = wp_remote_get( $this->product->licence()->server() . $path, array( 'timeout' => 15 ) );
            $data     = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );

            if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) || ! is_array( $data ) ) {
                return new WP_Error( 'unreachable', __( 'WordPlus Cloud could not be reached. Please try again in a minute.', 'wordplus-sdk' ), array( 'status' => 503 ) );
            }

            set_site_transient( $transient, $data, $ttl );

            return $data;
        }

        public function error( WP_Error $error )
        {
            $messages = array(
                'invalid_license'      => __( 'This licence key is not valid.', 'wordplus-sdk' ),
                'wrong_plugin'         => __( 'This licence key is for another plugin.', 'wordplus-sdk' ),
                'license_expired'      => __( 'This licence has expired. Renew it to use it on a new site.', 'wordplus-sdk' ),
                'license_cancelled'    => __( 'This licence was cancelled.', 'wordplus-sdk' ),
                'license_utilized'     => __( 'This licence is already used on all the sites it covers. Deactivate one of them in your WordPlus Cloud account, or add sites to it.', 'wordplus-sdk' ),
                'install_mismatch'     => __( 'This site\'s earlier activation belongs to another licence.', 'wordplus-sdk' ),
                'site_secret_required' => __( 'This site is already active with a secret it no longer has. Reset the site\'s secret in your WordPlus Cloud account, under Sites, then activate again.', 'wordplus-sdk' ),
                'site_secret_revoked'  => __( 'This site\'s secret was reset in your WordPlus Cloud account. Activate the licence again to give the site a new one.', 'wordplus-sdk' ),
                'no_site_secret'       => __( 'This site has no secret of its own yet. Refresh the licence to get one.', 'wordplus-sdk' ),
                'invalid_code'         => __( 'This purchase link has expired or was already used. The licence key is in the email from WordPlus Cloud.', 'wordplus-sdk' ),
                'staging_limit'        => __( 'The licence has all the staging copies it can have. Ask support for more, or deactivate one in your WordPlus account.', 'wordplus-sdk' ),
                'site_exists'          => __( 'Another site uses this address on the licence. Deactivate it in your WordPlus account first.', 'wordplus-sdk' ),
                'trial_used'           => __( 'A trial can\'t be used on a site that had a licence before. Once the trial\'s first payment is made, the licence works here.', 'wordplus-sdk' ),
                'unreachable'          => __( 'WordPlus Cloud could not be reached. Please try again in a minute.', 'wordplus-sdk' ),
                'try_later'            => __( 'WordPlus Cloud could not check this right now. Please try again in a minute.', 'wordplus-sdk' ),
            );
            $code     = $error->get_error_code();
            $data     = array( 'status' => 400 );

            if ( 'license_utilized' === $code ) {
                $refusal            = $error->get_error_data();
                $data['accountUrl'] = is_array( $refusal ) && isset( $refusal['account_url'] ) && is_string( $refusal['account_url'] ) && 0 === strpos( $refusal['account_url'], 'https://' )
                    ? esc_url_raw( $refusal['account_url'] )
                    : (string) $this->product->get( 'account_url' );
            }

            if ( 'staging_limit' === $code ) {
                $refusal            = $error->get_error_data();
                $data['supportUrl'] = is_array( $refusal ) && isset( $refusal['support_url'] ) && is_string( $refusal['support_url'] ) && 0 === strpos( $refusal['support_url'], 'https://' )
                    ? esc_url_raw( $refusal['support_url'] )
                    : (string) $this->product->get( 'staging_url' );
            }

            return new WP_Error( $code, isset( $messages[ $code ] ) ? $messages[ $code ] : $error->get_error_message(), $data );
        }
    }
}
