<?php
defined( 'ABSPATH' ) || exit;

class Better_Messages_Translations {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private $upload_dir;
    private $upload_url;
    private $inline_fallback = array();
    private $file_versions = array();

    public function __construct() {
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/better-messages/i18n/';
        $this->upload_url = $upload['baseurl'] . '/better-messages/i18n/';

        // Inject WordPress.org translations into WP update system for premium installs
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_translation_updates' ) );
    }

    // The JS translation path is gone. It located a JED .json by md5 of a
    // script path, which needed three generated stub files in assets/js
    // that existed for no other reason. Every enqueue now calls
    // get_php_translation_file_url() below, which builds the same map from
    // inc/translations/frontend-strings.php and the PHP catalogue.

    private function in_script_locale( $script_handle, $resolver ) {
        if ( ! has_filter( 'better_messages_i18n_locale' ) ) {
            return call_user_func( $resolver, $script_handle );
        }

        $current_locale = determine_locale();
        $locale         = apply_filters( 'better_messages_i18n_locale', $current_locale, $script_handle );
        $switched       = $locale !== $current_locale && switch_to_locale( $locale );

        $result = call_user_func( $resolver, $script_handle );

        if ( $switched ) {
            restore_previous_locale();
        }

        return $result;
    }

        /**
     * Get inline translation data when file caching failed.
     */
    public function get_inline_translations( $script_handle ) {
        return isset( $this->inline_fallback[ $script_handle ] ) ? $this->inline_fallback[ $script_handle ] : false;
    }

    public function get_translation_file_version( $script_handle ) {
        return isset( $this->file_versions[ $script_handle ] ) ? $this->file_versions[ $script_handle ] : null;
    }

    /**
     * Cache a translations array as an external JS file for browser caching.
     *
     * @param string $script_handle Registered WP script handle
     * @param array  $translations  Translation map (key→value)
     * @return string|false URL of the cached JS file, or false on failure
     */
    private function cache_translations_js( $script_handle, $translations ) {
        $locale     = determine_locale();
        $js_content = '(function(){window.Better_Messages_i18n=' . wp_json_encode( $translations, JSON_UNESCAPED_UNICODE ) . '})();';
        $hash       = substr( md5( Better_Messages()->version . $js_content ), 0, 8 );
        $cache_key  = $script_handle . '-' . $locale;

        $this->file_versions[ $script_handle ] = $hash;
        $file_name  = 'bm-i18n-' . $cache_key . '-' . $hash . '.js';
        $file_path  = $this->upload_dir . $file_name;
        $file_url   = $this->upload_url . $file_name;

        if ( file_exists( $file_path ) ) {
            return $file_url;
        }

        if ( wp_mkdir_p( $this->upload_dir ) && @file_put_contents( $file_path, $js_content ) !== false ) {
            // Clean old versions
            $old_pattern = $this->upload_dir . 'bm-i18n-' . $cache_key . '-*.js';
            foreach ( glob( $old_pattern ) as $old_file ) {
                if ( $old_file !== $file_path ) {
                    @unlink( $old_file );
                }
            }
            return $file_url;
        }

        // File write failed -- store for inline fallback
        $this->inline_fallback[ $script_handle ] = $translations;
        return false;
    }

    /**
     * Inject WordPress.org translation updates into the WP update system.
     * This ensures premium installs get translations even when the plugin
     * directory name differs from the WordPress.org slug.
     *
     * @param object $transient The update_plugins transient data.
     * @return object
     */
    public function inject_translation_updates( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        // Skip if translations for our slug are already present (free version installed or Freemius handled it)
        if ( ! empty( $transient->translations ) ) {
            foreach ( $transient->translations as $tr ) {
                if ( isset( $tr['slug'] ) && $tr['slug'] === 'bp-better-messages' ) {
                    return $transient;
                }
            }
        }

        // translations_api() is an admin-only function
        if ( ! function_exists( 'translations_api' ) ) {
            $file = ABSPATH . 'wp-admin/includes/translation-install.php';
            if ( ! file_exists( $file ) ) {
                return $transient;
            }
            require_once $file;
        }

        $api = translations_api( 'plugins', array(
            'slug'    => 'bp-better-messages',
            'version' => Better_Messages()->version,
        ) );

        if ( is_wp_error( $api ) || empty( $api['translations'] ) ) {
            return $transient;
        }

        // Only download translations for languages installed on this site
        $site_locales = get_available_languages();
        $site_locales[] = determine_locale();
        $site_locales = array_unique( $site_locales );

        $installed = wp_get_installed_translations( 'plugins' );
        $installed_locales = isset( $installed['bp-better-messages'] ) ? $installed['bp-better-messages'] : array();

        if ( ! isset( $transient->translations ) ) {
            $transient->translations = array();
        }

        foreach ( $api['translations'] as $translation ) {
            $language = $translation['language'];

            // Only install for languages this site uses
            if ( ! in_array( $language, $site_locales, true ) ) {
                continue;
            }

            // Skip if already installed and up to date
            if ( isset( $installed_locales[ $language ] ) ) {
                $local  = strtotime( $installed_locales[ $language ]['PO-Revision-Date'] );
                $remote = strtotime( $translation['updated'] );
                if ( $local >= $remote ) {
                    continue;
                }
            }

            $transient->translations[] = array(
                'type'       => 'plugin',
                'slug'       => 'bp-better-messages',
                'language'   => $language,
                'version'    => $translation['version'],
                'updated'    => $translation['updated'],
                'package'    => $translation['package'],
                'autoupdate' => true,
            );
        }

        return $transient;
    }

    public function get_php_translation_file_url( $script_handle ) {
        return $this->in_script_locale( $script_handle, array( $this, 'resolve_php_translation_file_url' ) );
    }

    private function resolve_php_translation_file_url( $script_handle ) {
        $translations = $this->build_translations_from_php( $script_handle );

        if ( empty( $translations ) ) {
            return false;
        }

        return $this->cache_translations_js( $script_handle, $translations );
    }

    private function translations_map( $script_handle ) {
        $maps = array(
            'better-messages-admin' => array( 'admin-strings.php',    '_bm_admin_translations_map' ),
            'better-messages'       => array( 'frontend-strings.php', '_bm_frontend_translations_map' ),
            'better-messages-app'   => array( 'frontend-strings.php', '_bm_frontend_translations_map' ),
        );

        if ( ! isset( $maps[ $script_handle ] ) ) {
            return array();
        }

        list( $file, $function ) = $maps[ $script_handle ];

        $map_file = __DIR__ . '/' . $file;

        if ( ! file_exists( $map_file ) ) {
            return array();
        }

        require_once $map_file;

        if ( ! function_exists( $function ) ) {
            return array();
        }

        return call_user_func( $function );
    }

    private function build_translations_from_php( $script_handle ) {
        $strings = $this->translations_map( $script_handle );

        if ( empty( $strings ) ) {
            return array();
        }

        $domain = 'bp-better-messages';

        load_plugin_textdomain( $domain, false, basename( plugin_dir_path( dirname( __FILE__, 2 ) ) ) . '/languages/' );

        $plural_forms   = $this->get_plural_forms_header( $domain );
        $plural_samples = $this->plural_form_samples( $plural_forms );

        $translations = array();

        foreach ( $strings as $def ) {
            $type = $def[0];

            if ( $type === '__' ) {
                $msgid      = $def[1];
                $translated = __( $msgid, $domain );
                if ( $translated !== $msgid ) {
                    $translations[ $msgid ] = $translated;
                }
            } elseif ( $type === '_x' ) {
                $msgid   = $def[1];
                $context = $def[2];
                $key     = $context . "\x04" . $msgid;

                $translated = _x( $msgid, $context, $domain );
                if ( $translated !== $msgid ) {
                    $translations[ $key ] = $translated;
                }
            } elseif ( $type === '_nx' ) {
                $singular = $def[1];
                $plural   = $def[2];
                $context  = $def[3];
                $key      = $context . "\x04" . $singular;

                $forms = $this->get_all_plural_forms( $singular, $plural, $context, $domain, $plural_samples );
                if ( $forms !== false ) {
                    $translations[ $key ] = $forms;
                }
            }
        }

        if ( empty( $translations ) ) {
            return array();
        }

        if ( $plural_forms ) {
            $translations[''] = array( 'plural-forms' => $plural_forms );
        }

        return $translations;
    }

    private function plural_form_samples( $plural_forms ) {
        $nplurals   = 2;
        $expression = 'n != 1';

        if ( $plural_forms && preg_match( '/^\s*nplurals\s*=\s*(\d+)\s*;\s*plural\s*=\s*(.+?)\s*;?\s*$/', $plural_forms, $matches ) ) {
            $nplurals   = max( 1, (int) $matches[1] );
            $expression = $matches[2];
        }

        $samples = array();

        try {
            $forms = new Plural_Forms( $expression );

            for ( $n = 0; $n <= 1000 && count( $samples ) < $nplurals; $n++ ) {
                $index = (int) $forms->get( $n );

                if ( ! isset( $samples[ $index ] ) ) {
                    $samples[ $index ] = $n;
                }
            }
        } catch ( Exception $e ) {
            $samples = array();
        }

        if ( empty( $samples ) ) {
            $samples = array( 0 => 1, 1 => 2 );
        }

        ksort( $samples );

        return $samples;
    }

    private function get_all_plural_forms( $singular, $plural, $context, $domain, $samples ) {
        $forms      = array();
        $translated = false;

        foreach ( $samples as $index => $n ) {
            $text = _nx( $singular, $plural, $n, $context, $domain );

            if ( $text !== ( $n === 1 ? $singular : $plural ) ) {
                $translated = true;
            }

            $forms[ $index ] = $text;
        }

        if ( ! $translated ) {
            return false;
        }

        return array_values( $forms );
    }

    private function get_plural_forms_header( $domain ) {
        $mo = get_translations_for_domain( $domain );

        if ( method_exists( $mo, 'get_header' ) ) {
            $header = $mo->get_header( 'Plural-Forms' );
            if ( $header ) {
                return $header;
            }
        }

        $headers = ( property_exists( $mo, 'headers' ) || method_exists( $mo, '__get' ) ) ? $mo->headers : null;

        if ( is_array( $headers ) ) {
            if ( ! empty( $headers['Plural-Forms'] ) ) {
                return $headers['Plural-Forms'];
            }
            if ( ! empty( $headers['plural-forms'] ) ) {
                return $headers['plural-forms'];
            }
        }

        return false;
    }

    /**
     * Clear cached translation files for the current locale.
     */
    public function clear_cache() {
        if ( ! is_dir( $this->upload_dir ) ) {
            return;
        }
        $locale = determine_locale();
        $files = glob( $this->upload_dir . 'bm-i18n-*-' . $locale . '-*.js' );
        if ( $files ) {
            foreach ( $files as $file ) {
                @unlink( $file );
            }
        }
    }
}

function Better_Messages_Translations() {
    return Better_Messages_Translations::instance();
}

// Initialize immediately so the update hook is registered early
Better_Messages_Translations();
