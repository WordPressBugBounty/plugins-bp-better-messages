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

    public function __construct() {
        $upload = wp_upload_dir();
        $this->upload_dir = $upload['basedir'] . '/better-messages/i18n/';
        $this->upload_url = $upload['baseurl'] . '/better-messages/i18n/';

        // Inject WordPress.org translations into WP update system for premium installs
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_translation_updates' ) );
    }

    /**
     * Get the URL of a cached translation JS file.
     * Must be called AFTER wp_register_script() and wp_set_script_translations().
     *
     * Uses WordPress's own load_script_textdomain() to find translations
     * the same way WordPress would for inline injection, then caches the
     * result as an external JS file for browser caching.
     *
     * @param string $script_handle Registered WP script handle
     * @return string|false URL of the cached JS file, or false if not needed
     */
    public function get_translation_file_url( $script_handle ) {
        // No early skip for en_US — translation plugins like Loco Translate
        // may have customized strings even for English sites.

        $domain    = 'bp-better-messages';
        $lang_path = plugin_dir_path( dirname( __FILE__, 2 ) ) . 'languages/';

        // Register translations so WordPress knows where to look
        wp_set_script_translations( $script_handle, $domain, $lang_path );

        // WordPress uses md5(relative_path) to find .json files.
        // In dev mode the script src differs from production, so we override the path.
        $json_data = load_script_textdomain( $script_handle, $domain, $lang_path );

        // If no result (e.g. dev mode), try production paths
        if ( ! $json_data ) {
            $fallback_paths = array(
                'better-messages'       => array( 'assets/js/bp-messages-premium.js', 'assets/js/bp-messages-free.js' ),
                'better-messages-app'   => array( 'assets/js/bp-messages-app.js' ),
            );

            if ( isset( $fallback_paths[ $script_handle ] ) ) {
                foreach ( $fallback_paths[ $script_handle ] as $try_path ) {
                    $filter = function() use ( $try_path ) { return $try_path; };
                    add_filter( 'load_script_textdomain_relative_path', $filter, 999 );
                    $json_data = load_script_textdomain( $script_handle, $domain, $lang_path );
                    remove_filter( 'load_script_textdomain_relative_path', $filter, 999 );
                    if ( $json_data ) break;
                }
            }
        }

        // Remove textdomain from script so WordPress doesn't also inline it
        if ( isset( wp_scripts()->registered[ $script_handle ] ) ) {
            unset( wp_scripts()->registered[ $script_handle ]->textdomain );
            unset( wp_scripts()->registered[ $script_handle ]->translations_path );
        }

        if ( ! $json_data ) {
            return false;
        }

        // Build the JS: parse WordPress's JED format into a simple key→value object
        $translations = $this->parse_jed_translations( $json_data );

        if ( empty( $translations ) ) {
            return false;
        }

        return $this->cache_translations_js( $script_handle, $translations );
    }

    /**
     * Get inline translation data when file caching failed.
     */
    public function get_inline_translations( $script_handle ) {
        return isset( $this->inline_fallback[ $script_handle ] ) ? $this->inline_fallback[ $script_handle ] : false;
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
     * Parse WordPress JED-format JSON into a simple key→value map.
     * Keys use context\x04msgid format for context-aware strings.
     */
    private function parse_jed_translations( $json_data ) {
        $data = json_decode( $json_data, true );

        // WordPress.org uses 'messages', Loco Translate uses the domain name
        $messages = null;
        if ( ! empty( $data['locale_data']['messages'] ) ) {
            $messages = $data['locale_data']['messages'];
        } elseif ( ! empty( $data['locale_data'] ) ) {
            $messages = reset( $data['locale_data'] );
        }

        if ( empty( $messages ) ) {
            return array();
        }

        $translations = array();

        // Include plural-forms header for JS plural rule evaluation
        if ( isset( $messages[''] ) && is_array( $messages[''] ) ) {
            $plural_forms = $messages['']['plural-forms'] ?? $messages['']['Plural-Forms'] ?? null;
            if ( $plural_forms ) {
                $translations[''] = array( 'plural-forms' => $plural_forms );
            }
        }

        foreach ( $messages as $key => $value ) {
            if ( $key === '' || empty( $value ) ) {
                continue;
            }
            $translations[ $key ] = ( is_array( $value ) && count( $value ) === 1 )
                ? $value[0]
                : $value;
        }

        return $translations;
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

    /**
     * Get the URL of a cached admin translation JS file built from PHP.
     *
     * Unlike get_translation_file_url() which relies on WordPress JSON files
     * (that WordPress.org fails to generate for the admin bundle), this method
     * builds translations by calling PHP __()/_x() against the loaded MO file.
     *
     * @param string $script_handle Registered WP script handle
     * @return string|false URL of the cached JS file, or false if not needed
     */
    public function get_php_translation_file_url( $script_handle ) {
        $translations = $this->build_translations_from_php();

        if ( empty( $translations ) ) {
            return false;
        }

        return $this->cache_translations_js( $script_handle, $translations );
    }

    /**
     * Build admin translations from the loaded MO file using PHP translation functions.
     *
     * @return array Translation map in the same format as parse_jed_translations()
     */
    private function build_translations_from_php() {
        $map_file = __DIR__ . '/admin-strings.php';
        if ( ! file_exists( $map_file ) ) {
            return array();
        }

        require_once $map_file;
        if ( ! function_exists( '_bm_admin_translations_map' ) ) {
            return array();
        }

        $domain  = 'bp-better-messages';
        $strings = _bm_admin_translations_map();

        // Ensure text domain is loaded
        load_plugin_textdomain( $domain, false, basename( plugin_dir_path( dirname( __FILE__, 2 ) ) ) . '/languages/' );

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

                $forms = $this->get_all_plural_forms( $singular, $plural, $context, $domain );
                if ( $forms !== false ) {
                    $translations[ $key ] = $forms;
                }
            }
        }

        if ( empty( $translations ) ) {
            return array();
        }

        // Add plural-forms header for JS plural rule evaluation
        $plural_forms = $this->get_plural_forms_header( $domain );
        if ( $plural_forms ) {
            $translations[''] = array( 'plural-forms' => $plural_forms );
        }

        return $translations;
    }

    /**
     * Get all plural form translations for a _nx string.
     *
     * @return array|false Array of plural forms, or false if not translated
     */
    private function get_all_plural_forms( $singular, $plural, $context, $domain ) {
        $mo = get_translations_for_domain( $domain );

        if ( method_exists( $mo, 'translate_entry' ) ) {
            $entry = new Translation_Entry( array(
                'singular' => $singular,
                'plural'   => $plural,
                'context'  => $context,
            ) );
            $translated = $mo->translate_entry( $entry );
            if ( $translated && ! empty( $translated->translations ) ) {
                return $translated->translations;
            }
        }

        return false;
    }

    /**
     * Get the Plural-Forms header from the loaded translation domain.
     *
     * @return string|false Plural-Forms header string, or false
     */
    private function get_plural_forms_header( $domain ) {
        $mo = get_translations_for_domain( $domain );

        if ( method_exists( $mo, 'get_header' ) ) {
            $header = $mo->get_header( 'Plural-Forms' );
            if ( $header ) {
                return $header;
            }
        }

        // WP 6.5+ compat: check headers property
        if ( isset( $mo->headers ) && is_array( $mo->headers ) ) {
            return $mo->headers['Plural-Forms'] ?? $mo->headers['plural-forms'] ?? false;
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
