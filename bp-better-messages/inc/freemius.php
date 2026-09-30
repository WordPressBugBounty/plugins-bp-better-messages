<?php
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'bpbm_fs' ) ) {
    // Create a helper function for easy SDK access.
    function bpbm_fs()
    {
        global  $bbm_fs;

        if ( ! isset( $bbm_fs ) ) {
            if ( !defined( 'WP_FS__PRODUCT_1557_MULTISITE' ) ) {
                define( 'WP_FS__PRODUCT_1557_MULTISITE', true );
            }

            // Include Freemius SDK.
            require_once dirname( __DIR__ ) . '/vendor/freemius/start.php';

            $bbm_fs = fs_dynamic_init( array(
                'id'                  => '1557',
                'slug'                => 'bp-better-messages',
                'premium_slug'        => 'bp-better-messages-websocket',
                'type'                => 'plugin',
                'public_key'          => 'pk_8af54172153e9907893f32a4706e2',
                'is_premium'          => false,
                'premium_suffix'      => '- WebSocket Version',
                'has_addons'          => true,
                'has_paid_plans'      => true,
                'trial'               => array(
                    'days'               => 3,
                    'is_require_payment' => true,
                ),
                'menu'                => array(
                    'slug'           => 'bp-better-messages',
                    'support'        => false,
                ),
                'is_live'             => true,
                'is_org_compliant'    => true,
            ) );
        }

        return $bbm_fs;
    }

    // Init Freemius.
    bpbm_fs();
    // Signal that SDK was initiated.
    do_action( 'bbm_fs_loaded' );

    bpbm_fs()->add_filter( 'templates/checkout.php', function ( $template ) {
        if ( false !== strpos( $template, '&billing_cycle=annual' ) ) {
            $template = str_replace( '&billing_cycle=annual', '&billing_cycle=annual&show_monthly_switch=true', $template );
        }

        return $template;
    } );

    bpbm_fs()->add_filter( 'pricing/discounts_model', function () {
        return 'relative';
    } );

    bpbm_fs()->add_filter( 'templates/pricing.php', function ( $html ) {
        $link = array(
            'url'  => 'https://www.better-messages.com/pricing/?utm_source=wp-admin&utm_medium=pricing-page&utm_content=more-sites',
            'text' => _x( 'Need more sites? Pick any number up to 100 on better-messages.com', 'Pricing page', 'bp-better-messages' ),
        );

        $script = '(function () {
            var link = ' . wp_json_encode( $link ) . ';
            function addLinks() {
                var tables = document.querySelectorAll( "#fs_pricing_app .fs-package:not(.fs-free-plan) .fs-license-quantities" );
                for ( var i = 0; i < tables.length; i++ ) {
                    var next = tables[ i ].nextElementSibling;
                    if ( next && next.className === "bm-pricing-more-sites" ) {
                        continue;
                    }
                    var paragraph = document.createElement( "p" );
                    var anchor = document.createElement( "a" );
                    paragraph.className = "bm-pricing-more-sites";
                    anchor.href = link.url;
                    anchor.target = "_blank";
                    anchor.rel = "noopener";
                    anchor.textContent = link.text;
                    paragraph.appendChild( anchor );
                    tables[ i ].parentNode.insertBefore( paragraph, tables[ i ].nextSibling );
                }
            }
            addLinks();
            new MutationObserver( addLinks ).observe( document.body, { childList: true, subtree: true } );
        })();';

        return $html . wp_get_inline_script_tag( $script );
    } );
}
