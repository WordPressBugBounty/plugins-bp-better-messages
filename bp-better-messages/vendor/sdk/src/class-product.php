<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Product' ) ) {
    final class WordPlus_SDK_Product
    {
        private $config;

        private $admin = null;

        private $updater = null;

        private $install = null;

        private $network = null;

        public function __construct( array $config )
        {
            $slug = (string) $config['slug'];

            $defaults = array(
                'slug'             => $slug,
                'file'             => '',
                'version'          => '0',
                'name'             => $slug,
                'icon'             => '',
                'licence'          => WordPlus_SDK::CLOUD,
                'option'           => '',
                'old_option'       => '',
                'beta_option'      => '',
                'paid_build'       => false,
                'paid_folder'      => '',
                'activate_in_free' => false,
                'addons'           => array(),
                'equivalent_plans' => array(),
                'menu'             => array(
                    'parent' => $slug,
                    'pages'  => array( 'account' => $slug . '-licence', 'pricing' => $slug . '-pricing', 'contact' => $slug . '-contact', 'optin' => $slug . '-optin' ),
                ),
                'tracking'         => true,
                'rest'             => array( 'namespace' => 'wordplus/v1', 'base' => $slug, 'install' => 'wordplus/v1/' . $slug . '/install' ),
                'force_key'        => '',
                'force_domain'     => '',
                'freemius'         => array(),
                'support'          => array( $slug => isset( $config['name'] ) ? (string) $config['name'] : $slug ),
                'urls'             => array( 'homepage' => '', 'docs' => '', 'settings' => '', 'buy' => '' ),
                'words'            => array(),
                'site_info'        => null,
                'assistant'        => array(),
                'checkout_url'     => 'https://www.wordplus.cloud/checkout/' . $slug,
                'account_url'      => 'https://www.wordplus.cloud/licenses',
                'password_url'     => 'https://www.wordplus.cloud/forgot-password',
                'staging_url'      => 'https://www.wordplus.cloud/support?about=staging',
            );

            $this->config = array_merge( $defaults, $config );

            foreach ( array( 'menu', 'rest', 'urls' ) as $group ) {
                $this->config[ $group ] = array_merge( $defaults[ $group ], isset( $config[ $group ] ) && is_array( $config[ $group ] ) ? $config[ $group ] : array() );
            }

            $this->config['menu']['pages'] = array_merge( $defaults['menu']['pages'], isset( $config['menu']['pages'] ) && is_array( $config['menu']['pages'] ) ? $config['menu']['pages'] : array() );
        }

        public function can_activate()
        {
            return $this->is_paid_build() || (bool) $this->config['activate_in_free'];
        }

        public function words()
        {
            $words = $this->config['words'];

            if ( is_callable( $words ) ) {
                $words = call_user_func( $words );
            }

            return is_array( $words ) ? $words : array();
        }

        public function page( $page )
        {
            $pages = $this->config['menu']['pages'];

            return isset( $pages[ $page ] ) && false !== $pages[ $page ] ? (string) $pages[ $page ] : '';
        }

        public function page_url( $page )
        {
            $host = 'account' === $page ? $this->host() : null;

            if ( null !== $host && '' !== $host->page( 'account' ) ) {
                return $host->page_url( 'account' ) . '#' . $this->slug();
            }

            $slug = $this->page( $page );

            return '' === $slug ? '' : admin_url( 'admin.php?page=' . $slug );
        }

        public function host()
        {
            return WordPlus_SDK::instance()->host( $this->slug() );
        }

        public function hosted()
        {
            $hosted = array();

            foreach ( array_keys( is_array( $this->config['addons'] ) ? $this->config['addons'] : array() ) as $slug ) {
                $product = WordPlus_SDK::instance()->product( $slug );

                if ( null !== $product && $product->host() === $this ) {
                    $hosted[] = $product;
                }
            }

            return $hosted;
        }

        public function slug()
        {
            return $this->config['slug'];
        }

        public function name()
        {
            return (string) $this->config['name'];
        }

        public function version()
        {
            return (string) $this->config['version'];
        }

        public function file()
        {
            return (string) $this->config['file'];
        }

        public function basename()
        {
            return plugin_basename( $this->file() );
        }

        public function get( $key, $default = null )
        {
            $value = $this->config;

            foreach ( explode( '.', $key ) as $part ) {
                if ( ! is_array( $value ) || ! array_key_exists( $part, $value ) ) {
                    return $default;
                }

                $value = $value[ $part ];
            }

            return $value;
        }

        public function licence_store()
        {
            return WordPlus_SDK::CLOUD === $this->config['licence'] ? WordPlus_SDK::CLOUD : $this->slug();
        }

        public function is_paid_build()
        {
            return (bool) $this->config['paid_build'];
        }

        public function addon( $slug )
        {
            $addons = is_array( $this->config['addons'] ) ? $this->config['addons'] : array();

            return isset( $addons[ $slug ] ) ? (string) $addons[ $slug ] : '';
        }

        public function is_network_active()
        {
            if ( null === $this->network ) {
                $plugins       = is_multisite() && '' !== $this->file() ? get_site_option( 'active_sitewide_plugins', array() ) : array();
                $this->network = is_array( $plugins ) && isset( $plugins[ $this->basename() ] );
            }

            return $this->network;
        }

        public function licence()
        {
            return WordPlus_SDK::instance()->licence( $this );
        }

        public function has_access()
        {
            return $this->licence()->is_active() && null === $this->licence()->refused( $this->slug() );
        }

        public function features()
        {
            $data = $this->licence()->get();

            return isset( $data['features'] ) && is_array( $data['features'] ) ? $data['features'] : array();
        }

        public function beta_option()
        {
            return '' !== (string) $this->get( 'beta_option', '' ) ? (string) $this->get( 'beta_option' ) : 'wordplus_sdk_beta_' . str_replace( '-', '_', $this->slug() );
        }

        public function wants_beta()
        {
            return (bool) ( $this->is_network_active() ? get_site_option( $this->beta_option(), false ) : get_option( $this->beta_option(), false ) );
        }

        public function set_beta( $enabled )
        {
            if ( $this->is_network_active() ) {
                update_site_option( $this->beta_option(), $enabled ? 1 : 0 );
            } else {
                update_option( $this->beta_option(), $enabled ? 1 : 0, false );
            }

            if ( $this->updater() ) {
                $this->updater()->ask_again();
            }
        }

        public function admin()
        {
            if ( null === $this->admin ) {
                require_once __DIR__ . '/class-admin.php';
                $this->admin = new WordPlus_SDK_Admin( $this );
            }

            return $this->admin;
        }

        public function updater()
        {
            if ( null === $this->updater ) {
                $file = $this->is_paid_build() && '' !== $this->file() ? WordPlus_SDK::instance()->locate( 'src/class-updater.php' ) : null;

                if ( null !== $file ) {
                    require_once $file;
                    $this->updater = new WordPlus_SDK_Updater( $this );
                } else {
                    $this->updater = false;
                }
            }

            return $this->updater;
        }

        public function install()
        {
            if ( null === $this->install ) {
                if ( ( ! $this->is_paid_build() && '' !== (string) $this->config['paid_folder'] ) || ! empty( $this->config['addons'] ) ) {
                    require_once __DIR__ . '/class-install.php';
                    $this->install = new WordPlus_SDK_Install( $this );
                } else {
                    $this->install = false;
                }
            }

            return $this->install;
        }
    }
}
