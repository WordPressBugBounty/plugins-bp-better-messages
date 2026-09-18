<?php
defined('ABSPATH') || exit;

if ( ! class_exists('Better_Messages_Mobile_App_Builds') ) {
    class Better_Messages_Mobile_App_Builds
    {
        public $settings;

        public $defaults;

        public static function instance(): ?Better_Messages_Mobile_App_Builds
        {
            // Store the instance locally to avoid private static replication
            static $instance = null;
            // Only run these methods if they haven't been run previously

            if (null === $instance) {
                $instance = new Better_Messages_Mobile_App_Builds();
            }

            // Always return the instance
            return $instance;
            // The last metroid is in captivity. The galaxy is at peace.
        }

        public function __construct(){
            add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );

            add_action('init', array( $this, 'builds_checker' ) );
            add_action('ba_check_build_statuses', array( $this, 'check_build_statuses' ) );
            add_action('better_messages_app_refresh_core_version', array( $this, 'refresh_core_version' ) );
        }

        public function builds_checker()
        {
            if ( ! wp_next_scheduled('ba_check_build_statuses') ) {
                wp_schedule_event( time(), 'ba_every_five_minutes', 'ba_check_build_statuses' );
            }

            $has_builds = Better_Messages_Mobile_App()->can_build;

            if ( $has_builds && ! wp_next_scheduled('better_messages_app_refresh_core_version') ) {
                wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'better_messages_app_refresh_core_version' );
            }
        }

        public function refresh_core_version()
        {
            $this->get_core_version( true );
        }

        public function apply_build_status( array $build, array $response )
        {
            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            if( ! isset( $response['status'] ) ){
                return;
            }

            $update = array();

            if( $response['status'] !== $build['status'] ){
                $update['status'] = $response['status'];
            }

            $finished = in_array( $response['status'], array( 'built', 'failed' ), true );

            if( $finished && isset( $response['core_revision'] ) && (int) $response['core_revision'] > 0 ){
                $row = $wpdb->get_row( $wpdb->prepare( "SELECT build_info FROM $table WHERE `id` = %d", $build['id'] ), ARRAY_A );
                $build_info = $row ? json_decode( $row['build_info'], true ) : null;

                if( is_array( $build_info ) ){
                    $build_info['core_revision'] = (int) $response['core_revision'];

                    if( isset( $response['channel'] ) && $response['channel'] !== null && $response['channel'] !== '' ){
                        $build_info['core_channel'] = (string) $response['channel'];
                    }

                    $update['build_info'] = wp_json_encode( $build_info );
                }
            }

            if( count( $update ) > 0 ){
                $wpdb->update( $table, $update, array( 'id' => $build['id'] ) );
            }

            if( $finished && $response['status'] === 'built' ){
                $this->refresh_outdated_builds();
            }
        }

        public function build_currency( array $build_info, array $core ): string
        {
            if( empty( $core['revision'] ) || (int) $core['revision'] <= 0 ){
                return 'unverifiable';
            }

            $shipped = isset( $build_info['core_revision'] ) ? (int) $build_info['core_revision'] : 0;

            if( $shipped > 0 ){
                return $shipped >= (int) $core['revision'] ? 'current' : 'outdated';
            }

            $built_at    = isset( $build_info['created_at'] ) ? (int) $build_info['created_at'] : 0;
            $released_at = ! empty( $core['released_at'] ) ? strtotime( $core['released_at'] . ' 23:59:59 UTC' ) : false;

            if( ! $built_at || ! $released_at ){
                return 'unrecorded';
            }

            return $built_at > $released_at ? 'current' : 'outdated';
        }

        public function refresh_outdated_builds()
        {
            $core = get_option( 'better-messages-app-core-version', false );

            if( ! is_array( $core ) || empty( $core['revision'] ) ){
                delete_option( 'better-messages-app-outdated-builds' );
                return;
            }

            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $outdated = array();

            foreach( array( 'ios', 'android' ) as $platform ){
                foreach( array( 'production', 'development' ) as $type ){
                    $row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT id, build_info FROM $table WHERE status = 'built' AND platform = %s AND type = %s ORDER BY id DESC LIMIT 1",
                        $platform,
                        $type
                    ), ARRAY_A );

                    if( ! $row ){
                        continue;
                    }

                    $build_info = json_decode( $row['build_info'], true );

                    if( ! is_array( $build_info ) ){
                        continue;
                    }

                    if( $this->build_currency( $build_info, $core ) === 'outdated' ){
                        $outdated[] = array(
                            'id'       => (int) $row['id'],
                            'platform' => $platform,
                            'type'     => $type,
                        );
                    }
                }
            }

            update_option( 'better-messages-app-outdated-builds', array(
                'revision'    => (int) $core['revision'],
                'released_at' => isset( $core['released_at'] ) ? (string) $core['released_at'] : '',
                'notes'       => isset( $core['notes'] ) ? (string) $core['notes'] : '',
                'builds'      => $outdated,
                'checked_at'  => time(),
            ), false );
        }

        public function core_requires( string $feature ): bool
        {
            $core = $this->get_core_version();

            return is_array( $core ) && isset( $core['requires'] ) && is_array( $core['requires'] ) && in_array( $feature, $core['requires'], true );
        }

        public function check_build_statuses()
        {
            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $builds = $wpdb->get_results( "SELECT id, status, site_id, secret FROM $table WHERE status = 'in-queue' OR status = 'building'", ARRAY_A );

            if( count( $builds ) > 0 ) {
                $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

                foreach ($builds as $build) {
                    $request = wp_remote_get(add_query_arg( [
                        'id' => $build['id'],
                        'site_id' => $build['site_id'],
                        'secret' => $build['secret'],
                    ], $builder_server . '/api/getBuildStatus'), array(
                        'timeout' => 30
                    ));

                    if( Better_Messages()->functions->is_response_good( $request ) !== true ){
                        continue;
                    }

                    $response = json_decode($request['body'], true );

                    if ( wp_remote_retrieve_response_code($request) != 200 ) {
                        continue;
                    }

                    $this->apply_build_status( $build, $response );
                }
            }
        }

        public function rest_api_init(): void
        {
            register_rest_route('better-messages/v1/admin/app', '/prepareBuild', array(
                'methods' => 'POST',
                'callback' => array($this, 'prepare_build'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/updateBuild', array(
                'methods' => 'POST',
                'callback' => array($this, 'update_build'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/createBuild', array(
                'methods' => 'POST',
                'callback' => array($this, 'create_build'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/coreVersion', array(
                'methods' => 'GET',
                'callback' => array($this, 'core_version'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/getBuilds', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_builds'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/getBuild', array(
                'methods' => 'GET',
                'callback' => array($this, 'get_build'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/deleteBuild', array(
                'methods' => 'DELETE',
                'callback' => array($this, 'delete_build'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/releaseBuild', array(
                'methods' => 'POST',
                'callback' => array($this, 'release_build'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/uploadStatus', array(
                'methods' => 'GET',
                'callback' => array($this, 'upload_status'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));

            register_rest_route('better-messages/v1/admin/app', '/buildStatus', array(
                'methods' => 'GET',
                'callback' => array($this, 'build_status'),
                'permission_callback' => array($this, 'user_is_admin'),
            ));
        }

        public function user_is_admin(): bool
        {
            return current_user_can('manage_options');
        }

        public function build_status( WP_REST_Request $request )
        {
            $build_id = (int) $request->get_param( 'build_id' );

            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $build = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status, site_id, secret FROM $table WHERE `id` = %d",
                    $build_id
                ),
                ARRAY_A
            );

            if( ! $build ){
                return new WP_Error('invalid_build', 'The build does not exist');
            }

            if( $build['status'] === 'built' || $build['status'] === 'failed' ){
                return $build['status'];
            }

            $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

            $request = wp_remote_get(add_query_arg( [
                'id' => $build['id'],
                'site_id' => $build['site_id'],
                'secret' => $build['secret'],
            ], $builder_server . '/api/getBuildStatus'), array(
                'timeout' => 30
            ));

            if( Better_Messages()->functions->is_response_good( $request ) !== true ){
                return Better_Messages()->functions->is_response_good( $request );
            }

            $response = json_decode($request['body'], true );

            if ( wp_remote_retrieve_response_code($request) != 200 ) {
                return new WP_Error('request_failed', 'The network request failed.');
            }

            $this->apply_build_status( $build, $response );

            return $response['status'];
        }

        public function upload_status( WP_REST_Request $request )
        {
            $build_id = (int) $request->get_param( 'build_id' );

            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $build = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, status, site_id, secret FROM $table WHERE `id` = %d",
                    $build_id )
            );

            if( ! $build ){
                return new WP_Error('invalid_build', 'The build does not exist');
            }

            if( $build->status !== 'built' ){
                return new WP_Error('invalid_status', 'The build must be built');
            }

            $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

            $url = add_query_arg( [
                'build_id' => $build->id,
                'site_id'  => $build->site_id,
                'secret'   => $build->secret,
            ], $builder_server . '/api/getUploadStatus');

            $request = wp_remote_get( $url, array(
                'timeout' => 30
            ));

            if( Better_Messages()->functions->is_response_good( $request ) !== true ){
                return Better_Messages()->functions->is_response_good( $request );
            }

            $result = json_decode($request['body']);

            $status = $result->status;

            $response = [
                'status' => $status,
            ];

            if( $result->errors ){
                $response['errors'] = $result->errors;
            }

            return $response;
        }

        public function delete_build( WP_REST_Request $request )
        {
            $build_id = (int) $request->get_param( 'build_id' );

            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $build = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT id, platform, type, status, site_id, secret FROM $table WHERE `id` = %d",
                    $build_id )
            );

            if( ! $build ){
                return new WP_Error('invalid_build', 'The build does not exist');
            }

            $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

            $request = wp_remote_request($builder_server . '/api/deleteBuild', array(
                'method' => 'DELETE',
                'timeout' => 30,
                'body' => [
                    'id' => $build->id,
                    'site_id' => $build->site_id,
                    'secret' => $build->secret,
                ]
            ));

            if( Better_Messages()->functions->is_response_good( $request ) !== true ){
                return Better_Messages()->functions->is_response_good( $request );
            }

            $response = json_decode($request['body'], true );

            if ( wp_remote_retrieve_response_code($request) != 200 ) {
                if( isset( $response['error'] ) ){
                    return new WP_Error('request_failed', $response['error']);
                } else {
                    return new WP_Error('request_failed', 'The network request failed.');
                }
            }

            if( $response === 'deleted' ){
                $wpdb->delete( $table, [
                    'id' => $build->id,
                ], [ '%d' ] );
            }

            return true;
        }

        public function get_build( WP_REST_Request $request )
        {
            $id = (int) $request->get_param('id');

            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $build = $wpdb->get_row(
                $wpdb->prepare("SELECT id, platform, type, status, site_id, secret, build_info FROM $table WHERE `id` = %d", $id),
            ARRAY_A );

            if( ! $build ){
                return new WP_Error('invalid_build', 'The build does not exist');
            }

            $build_info = json_decode($build['build_info'], true);

            $app_name = $build_info['app_name'];
            $app_icon = $build_info['app_icon'];

            unset($build['build_info']);

            $build['app_name'] = $app_name;
            $build['app_icon'] = $app_icon;
            $build['built_at'] = isset( $build_info['created_at'] ) ? (int) $build_info['created_at'] : 0;
            $build['core_revision'] = isset( $build_info['core_revision'] ) ? (int) $build_info['core_revision'] : 0;

            if( $build['type'] === 'production' ){
                $build['version'] = $build_info['version'];
                $build['mkt_version'] = $build_info['mkt_version'];
            }

            if( $build['type'] === 'development' ){
                $build['devices'] = $build_info['devices'];
            }

            $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

            if( $build['platform'] === 'ios' ) {
                $download_url = add_query_arg(array(
                    'id' => $build['id'],
                    'site_id' => $build['site_id'],
                    'secret' => $build['secret']
                ), $builder_server . '/api/getIpa/');

                $build['download_url'] = $download_url;

                $install_destination = add_query_arg(array(
                    'id' => $build['id'],
                    'site_id' => $build['site_id'],
                    'secret' => $build['secret']
                ), $builder_server . '/api/getDevelopmentManifest/');

                $install_url = 'itms-services://?action=download-manifest&amp;url=' . urlencode($install_destination);

                $build['install_url'] = $install_url;
            }

            if( $build['platform'] === 'android' ) {
                $endpoint = $build['type'] === 'production' ? 'getAab' : 'getApk';

                $download_url = add_query_arg(array(
                    'id' => $build['id'],
                    'site_id' => $build['site_id'],
                    'secret' => $build['secret']
                ), $builder_server . '/api/' . $endpoint . '/');

                $build['download_url'] = $download_url;
                $build['install_url'] = $download_url;

                $build['package_name'] = $build_info['package_name'];
            }

            return $build;
        }
        public function get_builds( WP_REST_Request $request )
        {
            $this->check_build_statuses();

            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $builds = $wpdb->get_results( "SELECT id, platform, type, status, site_id, secret, build_info, created_at FROM $table ORDER BY id DESC", ARRAY_A );

            if( count( $builds ) > 0 ) {
                foreach ($builds as $key => $build) {
                    $build_info = json_decode($build['build_info'], true);

                    unset($builds[$key]['build_info']);

                    $builds[$key]['app_name']       = isset( $build_info['app_name'] ) ? $build_info['app_name'] : '';
                    $builds[$key]['app_icon']       = isset( $build_info['app_icon'] ) ? $build_info['app_icon'] : '';
                    $builds[$key]['version']        = isset( $build_info['version'] ) ? (string) $build_info['version'] : '';
                    $builds[$key]['mkt_version']    = isset( $build_info['mkt_version'] ) ? (string) $build_info['mkt_version'] : '';
                    $builds[$key]['plugin_version'] = isset( $build_info['plugin_version'] ) ? (string) $build_info['plugin_version'] : '';
                    $builds[$key]['core_revision']  = isset( $build_info['core_revision'] ) ? (int) $build_info['core_revision'] : 0;
                    $builds[$key]['built_at']       = isset( $build_info['created_at'] ) ? (int) $build_info['created_at'] : 0;
                }
            }

            return $builds;
        }

        public function core_version( WP_REST_Request $request )
        {
            $core = $this->get_core_version( $request->get_param('refresh') === '1' );

            if( is_wp_error( $core ) ){
                return $core;
            }

            return $core;
        }

        public function get_core_version( bool $force = false )
        {
            $transient = 'better_messages_app_core_version';
            $backoff   = 'better_messages_app_core_version_backoff';

            if( ! $force ){
                $cached = get_transient( $transient );

                if( is_array( $cached ) ){
                    return $cached;
                }

                if( get_transient( $backoff ) ){
                    return $this->last_known_core_version();
                }
            }

            $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

            $request = wp_remote_get( add_query_arg( array( 'plugin_version' => Better_Messages()->version ), $builder_server . '/api/getCoreVersion' ), array( 'timeout' => 8 ) );

            $core = false;

            if( ! is_wp_error( $request ) && wp_remote_retrieve_response_code( $request ) === 200 ){
                $decoded = json_decode( wp_remote_retrieve_body( $request ), true );

                if( is_array( $decoded ) && isset( $decoded['revision'] ) ){
                    $core = array(
                        'revision'    => (int) $decoded['revision'],
                        'released_at' => isset( $decoded['released_at'] ) ? (string) $decoded['released_at'] : '',
                        'notes'       => isset( $decoded['notes'] ) ? (string) $decoded['notes'] : '',
                        'channel'     => isset( $decoded['channel'] ) ? (string) $decoded['channel'] : '',
                        'requires'    => isset( $decoded['requires'] ) && is_array( $decoded['requires'] ) ? array_values( array_filter( $decoded['requires'], 'is_string' ) ) : array(),
                        'retention_days' => isset( $decoded['retention_days'] ) ? max( 0, (int) $decoded['retention_days'] ) : 0,
                        'stale'       => false
                    );
                }
            }

            if( $core === false ){
                set_transient( $backoff, 1, 15 * MINUTE_IN_SECONDS );

                return $this->last_known_core_version();
            }

            delete_transient( $backoff );

            $previous = get_option( 'better-messages-app-core-version', false );

            set_transient( $transient, $core, 6 * HOUR_IN_SECONDS );
            update_option( 'better-messages-app-core-version', $core, false );

            if( ! is_array( $previous ) || (int) $previous['revision'] !== $core['revision'] ){
                $this->refresh_outdated_builds();
            }

            return $core;
        }

        private function last_known_core_version()
        {
            $last_known = get_option( 'better-messages-app-core-version', false );

            if( is_array( $last_known ) ){
                $last_known['stale'] = true;
                return $last_known;
            }

            return new WP_Error( 'core_version_unavailable', 'The build server could not be reached', array( 'status' => 503 ) );
        }

        public function update_build( WP_REST_Request $request )
        {
                $key = $request->get_param('key');

                switch ( $key ){
                    case 'androidBuildVersion':
                        $version = $request->get_param('version');

                        if ( empty( $version ) || !ctype_digit( $version ) || (int) $version <= 0 ) {
                            return new WP_Error('invalid_version', 'Version must be a number higher than 0');
                        }

                        update_option('better-messages-app-android-last-release-version', (int) $version - 1, false);

                        break;
                    case 'androidMarketingVersion':
                        $version = $request->get_param('version');

                        if ( empty( $version ) || ! preg_match('/^\d+\.\d+(\.\d+)*$/', $version) ) {
                            return new WP_Error('invalid_version', 'Version must be in the format X.X or X.X.X');
                        }

                        update_option('better-messages-app-android-marketing-version', $version, false);
                        break;
                    default:
                        return new WP_Error('invalid_key', 'The key is not valid');
                }
        }

        public function prepare_build( WP_REST_Request $request )
        {
            $platform = $request->get_param('platform');

            if ( $platform !== 'ios' && $platform !== 'android' ) {
                return new WP_Error('invalid_platform', 'Platform must be ios or android');
            }

            $type     = $request->get_param('type');
            if ( $type !== 'development' && $type !== 'production' ) {
                return new WP_Error('invalid_type', 'Type must be development or production');
            }

            $fs_site = bpbm_fs()->get_site();

            if( ! $fs_site ){
                return new WP_Error('invalid_site', 'The site is not valid');
            }

            if ($platform === 'ios' && $type === 'development') {
                // Code for iOS development
                return $this->get_ios_dev_build_info();
            } else if ($platform === 'ios' && $type === 'production') {
                // Code for iOS production
                return $this->get_ios_dist_build_info();
            } else if ($platform === 'android' && $type === 'development') {
                return $this->get_android_dev_build_info();
            } else if ($platform === 'android' && $type === 'production') {
                return $this->get_android_prod_build_info();
            } else {
                // Code for other cases
                return new WP_Error('not_implemented', 'This type of application is not implemented yet');
            }
        }

        public function release_build( WP_REST_Request $request )
        {
            $allowed = $this->build_permission_check();

            if( is_wp_error( $allowed ) ){
                return $allowed;
            }

            $build_id = (int) $request->get_param( 'build_id' );
            $retry = $request->get_param( 'retry' ) === '1' ? true : false;

            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $build = $wpdb->get_row(
                $wpdb->prepare( "SELECT id, platform, type, status, site_id, secret FROM $table WHERE `id` = %d", $build_id )
            );

            if( ! $build ){
                return new WP_Error('invalid_build', 'The build does not exist');
            }

            $type = $build->type;

            if( $type !== 'production' ){
                return new WP_Error('invalid_type', 'The build type must be production');
            }

            if( $build->status !== 'built' ){
                return new WP_Error('invalid_status', 'The build must be built');
            }

            if( $build->platform === 'ios' ){

                $credentials = get_option('better-messages-app-ios-auth', false);

                $data = [
                    'id'          => $build->id,
                    'site_id'     => $build->site_id,
                    'secret'      => $build->secret,
                    'credentials' => $credentials,
                ];

                if( $retry ) {
                    $data['retry'] = true;
                }

                $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

                $request = wp_remote_post($builder_server. '/api/releaseBuild', array(
                    'body'    => $data,
                    'timeout' => 30
                ));

                if( Better_Messages()->functions->is_response_good( $request ) !== true ){
                    return Better_Messages()->functions->is_response_good( $request );
                }

                return json_decode($request['body'], true );

                /*
                $response = json_decode($request['body'], true );

                if ( wp_remote_retrieve_response_code($request) != 200 ) {
                    if( isset( $response['error'] ) ){
                        return new WP_Error('request_failed', $response['error']);
                    } else {
                        return new WP_Error('request_failed', 'The network request failed.');
                    }
                }*/

            } else if( $build->platform === 'android' ){
                return new WP_Error('not_implemented', 'Android builds are not implemented yet');
            } else {
                return new WP_Error('not_implemented', 'This type of application is not implemented yet');
            }
        }

        public function build_permission_check()
        {
            if( Better_Messages_Mobile_App()->can_build ){
                return true;
            }

            return new WP_Error(
                'websocket_required',
                _x( 'Building the app needs the WebSocket version. Everything else on this page is yours to set up now — your settings are kept.', 'Rest API Error', 'bp-better-messages' ),
                array( 'status' => 402 )
            );
        }

        public function create_build( WP_REST_Request $request )
        {
            $allowed = $this->build_permission_check();

            if( is_wp_error( $allowed ) ){
                return $allowed;
            }

            $platform = $request->get_param('platform');

            if ( $platform !== 'ios' && $platform !== 'android' ) {
                return new WP_Error('invalid_platform', 'Platform must be ios or android');
            }

            $type     = $request->get_param('type');
            if ( $type !== 'development' && $type !== 'production' ) {
                return new WP_Error('invalid_type', 'Type must be development or production');
            }

            if ($platform === 'ios' && $type === 'development') {
                // Code for iOS development
                $info = $this->get_ios_dev_build_info(true);

                if( is_wp_error( $info ) ){
                    return $info;
                }

                return $this->process_build_request( $info );
            } else if ($platform === 'ios' && $type === 'production') {
                // Code for iOS production
                $info = $this->get_ios_dist_build_info(true);

                if( is_wp_error( $info ) ){
                    return $info;
                }

                return $this->process_build_request( $info );
            } else if ($platform === 'android' && $type === 'development') {
                $info = $this->get_android_dev_build_info(true);

                if( is_wp_error( $info ) ){
                    return $info;
                }

                return $this->process_build_request( $info );
            } else if ($platform === 'android' && $type === 'production') {
                $info = $this->get_android_prod_build_info( true );

                if( is_wp_error( $info ) ){
                    return $info;
                }

                return $this->process_build_request( $info );
            } else {
                // Code for other cases
                return new WP_Error('not_implemented', 'This type of application is not implemented yet');
            }
        }

        public function get_android_prod_build_info( $build_info = false )
        {
            $settings = Better_Messages()->mobile_app->settings->get_settings();

            $androidFirebaseProd = $settings['androidFirebaseProd'];

            if( ! $androidFirebaseProd ){
                return new WP_Error('no_firebase', 'The Firebase configuration is not set');
            }

            $site_url = get_site_url();

            $parse = parse_url($site_url);

            $domain = $parse['host'];

            $fs_site = bpbm_fs()->get_site();

            if( ! $fs_site ){
                return new WP_Error('site_not_connected', 'The site is not connected');
            }

            if( ! bpbm_fs()->has_features_enabled_license() ){
                return new WP_Error( 'site_not_licensed', 'The website has no active WebSocket license' );
            }

            $androidFirebaseProd = get_option('better-messages-app-android-firebase-prod', false);

            $selected_app_id = $settings['androidFirebaseProdAppId'];

            if( ! $selected_app_id ){
                return new WP_Error('no_app_id', 'The selected Firebase App ID is not set');
            }

            if( ! $androidFirebaseProd ){
                return new WP_Error('no_firebase', 'The Firebase configuration is not set');
            }

            $selected_client = false;

            try {
                $androidFirebaseProd = json_decode($androidFirebaseProd, true);

                if (isset($androidFirebaseProd['client']) && is_array($androidFirebaseProd['client']) && count($androidFirebaseProd['client']) > 0) {
                    foreach ($androidFirebaseProd['client'] as $key => $value) {
                        $client_info = $value['client_info'];
                        $app_id = $client_info['mobilesdk_app_id'];

                        if ( $selected_app_id === $app_id ) {
                            $selected_client = $value;
                        } else {
                            unset( $androidFirebaseProd['client'][$key] );
                        }
                    }
                }
            } catch (Exception $e) {
                return new WP_Error('invalid_firebase', 'The Firebase configuration is invalid');
            }

            if( ! $selected_client ) {
                return new WP_Error('no_app_id', 'The selected Firebase App ID is not found in the Firebase configuration');
            }

            if( ! $settings['appIcon'] ){
                return new WP_Error('no_app_icon', 'The Application Icon is not set');
            }

            if( ! $settings['appSplash'] ){
                return new WP_Error('no_app_splash', 'The Application Splash Screen is not set');
            }

            if( ! $settings['appSplashColor'] ){
                return new WP_Error('no_app_splash_color', 'The Application Splash Screen Color is not set');
            }

            if( ! $settings['loginLogo'] ){
                return new WP_Error('no_login_logo', 'The Application Login Logo is not set');
            }

            if( ! $settings['androidNotificationIcon'] ){
                return new WP_Error('no_android_notification_icon', 'The Android Notification Icon is not set');
            }

            $api_url = esc_url_raw(get_rest_url(null, '/better-messages/v1/'));

            $androidFirebaseProd['client'] = array_values($androidFirebaseProd['client']);

            $firebase_json = json_encode($androidFirebaseProd);

            $package_name = $selected_client['client_info']['android_client_info']['package_name'];

            $androidKeyStore = get_option('better-messages-app-android-keystore', false);

            if( ! $androidKeyStore ){
                return new WP_Error('no_keystore', 'The Android Keystore is not set');
            }

            $last_version      = get_option('better-messages-app-android-last-release-version', 0);
            $marketing_version = get_option('better-messages-app-android-marketing-version', '1.0');

            $return = [
                'platform' => 'android',
                'type' => 'production',
                'site_id' => $fs_site->id,
                'build_info' => [
                    'domain'                    => $domain,
                    'app_name'                  => $settings['androidAppNameProd'],
                    'app_icon'                  => $settings['appIcon'],
                    'app_splash'                => $settings['appSplash'],
                    'app_splash_color'          => $settings['appSplashColor'],
                    'android_notification_icon' => $settings['androidNotificationIcon'],
                    'api_url'                   => $api_url,
                    'package_name'              => $package_name,
                    'version'                   => $last_version + 1,
                    'mkt_version'               => $marketing_version,
                    'plugin_version'            => Better_Messages()->version,
                    'created_at'                => time()
                ]
            ];

            if( $build_info ){
                $return['build_info']['firebase_json'] = $firebase_json;
                $return['build_info']['keystore']      = $androidKeyStore;
                $return['build_info']                  = json_encode( $return['build_info'] );
            }

            return $return;
        }

        public function get_android_dev_build_info( $build_info = false )
        {
            $settings = Better_Messages()->mobile_app->settings->get_settings();

            $androidFirebaseDev = $settings['androidFirebaseDev'];

            if( ! $androidFirebaseDev ){
                return new WP_Error('no_firebase', 'The Firebase configuration is not set');
            }

            $site_url = get_site_url();
            $parse = parse_url($site_url);
            $domain = $parse['host'];

            $fs_site = bpbm_fs()->get_site();

            if( ! $fs_site ){
                return new WP_Error('site_not_connected', 'The site is not connected');
            }

            if( ! bpbm_fs()->has_features_enabled_license() ){
                return new WP_Error( 'site_not_licensed', 'The website has no active WebSocket license' );
            }

            $androidFirebaseDev = get_option('better-messages-app-android-firebase-dev', false);

            $selected_app_id = $settings['androidFirebaseDevAppId'];

            if( ! $selected_app_id ){
                return new WP_Error('no_app_id', 'The selected Firebase App ID is not set');
            }

            if( ! $androidFirebaseDev ){
                return new WP_Error('no_firebase', 'The Firebase configuration is not set');
            }

            $selected_client = false;

            try {
                $androidFirebaseDev = json_decode($androidFirebaseDev, true);

                if (isset($androidFirebaseDev['client']) && is_array($androidFirebaseDev['client']) && count($androidFirebaseDev['client']) > 0) {
                    foreach ($androidFirebaseDev['client'] as $key => $value) {
                        $client_info = $value['client_info'];
                        $app_id = $client_info['mobilesdk_app_id'];

                        if ( $selected_app_id === $app_id ) {
                            $selected_client = $value;
                        } else {
                            unset( $androidFirebaseDev['client'][$key] );
                        }
                    }
                }
            } catch (Exception $e) {
                return new WP_Error('invalid_firebase', 'The Firebase configuration is invalid');
            }

            if( ! $selected_client ) {
                return new WP_Error('no_app_id', 'The selected Firebase App ID is not found in the Firebase configuration');
            }

            if( ! $settings['appIcon'] ){
                return new WP_Error('no_app_icon', 'The Application Icon is not set');
            }

            if( ! $settings['appSplash'] ){
                return new WP_Error('no_app_splash', 'The Application Splash Screen is not set');
            }

            if( ! $settings['appSplashColor'] ){
                return new WP_Error('no_app_splash_color', 'The Application Splash Screen Color is not set');
            }

            if( ! $settings['loginLogo'] ){
                return new WP_Error('no_login_logo', 'The Application Login Logo is not set');
            }

            if( ! $settings['androidNotificationIcon'] ){
                return new WP_Error('no_android_notification_icon', 'The Android Notification Icon is not set');
            }

            $api_url = esc_url_raw(get_rest_url(null, '/better-messages/v1/'));

            $androidFirebaseDev['client'] = array_values($androidFirebaseDev['client']);

            $firebase_json = json_encode($androidFirebaseDev);

            $package_name = $selected_client['client_info']['android_client_info']['package_name'];

            $return = [
                'platform' => 'android',
                'type' => 'development',
                'site_id' => $fs_site->id,
                'build_info' => [
                    'domain'                    => $domain,
                    'app_name'                  => $settings['androidAppNameDev'],
                    'app_icon'                  => $settings['appIcon'],
                    'app_splash'                => $settings['appSplash'],
                    'app_splash_color'          => $settings['appSplashColor'],
                    'android_notification_icon' => $settings['androidNotificationIcon'],
                    'api_url'                   => $api_url,
                    'package_name'              => $package_name,
                    'plugin_version'            => Better_Messages()->version,
                    'created_at'                => time()
                ]
            ];

            if( $build_info ){
                $return['build_info']['firebase_json'] = $firebase_json;
                $return['build_info'] = json_encode( $return['build_info'] );
            }

            return $return;
        }

        public function get_ios_broadcast_bundle( $bundle_id, $app_capabilities, $type = 'development' )
        {
            if( empty( $bundle_id ) ){
                if( $this->core_requires( 'broadcast' ) ){
                    return new WP_Error(
                        'no_broadcast_bundle',
                        'The ' . ( $type === 'production' ? 'Distribution' : 'Development' ) . ' screen sharing App ID is not selected. The app this site would build carries a screen sharing extension, which needs its own App ID and an App Group shared with the app.'
                    );
                }

                return null;
            }

            $bundle = Better_Messages()->mobile_app->ios->get_bundle( $bundle_id );

            if( is_wp_error( $bundle ) ){
                return $bundle;
            }

            $missing = array();

            if( ! in_array( 'APP_GROUPS', $bundle['capabilities'], true ) ){
                $missing[] = $bundle['identifier'];
            }

            if( ! in_array( 'APP_GROUPS', $app_capabilities, true ) ){
                $missing[] = 'the app';
            }

            if( count( $missing ) > 0 ){
                return new WP_Error(
                    'missing_app_groups',
                    'App Groups is missing on: ' . implode( ', ', $missing ) . '. Register the App Group in the Apple Developer portal and add the capability to both App IDs.'
                );
            }

            return $bundle;
        }

        private function check_extension_bundle( $app_bundle, $extension_bundle, string $label )
        {
            if( ! is_array( $app_bundle ) || ! is_array( $extension_bundle ) || empty( $app_bundle['identifier'] ) || empty( $extension_bundle['identifier'] ) ){
                return true;
            }

            if( strpos( $extension_bundle['identifier'], $app_bundle['identifier'] . '.' ) !== 0 ){
                return new WP_Error( 'extension_bundle_prefix', sprintf( 'The %1$s App ID %2$s has to start with the app\'s bundle ID %3$s, or Xcode refuses to embed the extension', $label, $extension_bundle['identifier'], $app_bundle['identifier'] ) );
            }

            return true;
        }

        public function get_ios_dev_build_info($build_info = false)
        {
            $settings = Better_Messages()->mobile_app->settings->get_settings();

            $team_id     = trim($settings['iosAppTeamId']);
            $bundle_id   = $settings['iosBundleDev'];
            $notification_bundle_id = $settings['iosBundleServiceDev'];

            $site_url = get_site_url();
            $parse = parse_url($site_url);
            $domain = $parse['host'];

            $fs_site = bpbm_fs()->get_site();

            if( ! $fs_site ){
                return new WP_Error('site_not_connected', 'The site is not connected');
            }

            $jwt = Better_Messages()->mobile_app->ios->get_jwt();

            if( empty( $jwt ) ){
                return new WP_Error('invalid_access', 'Connection to Apple Developer Account is not configured');
            }

            if( ! bpbm_fs()->has_features_enabled_license() ){
                return new WP_Error( 'site_not_licensed', 'The website has no active WebSocket license' );
            }

            if( ! $team_id ){
                return new WP_Error('no_team_id', 'The Team ID is not set');
            }

            /* $certificate = Better_Messages()->mobile_app->ios->get_certificate( $certificate_id );

            if( is_wp_error( $certificate ) ){
                return $certificate;
            }

            $allowed_cert_types = [
                'DEVELOPMENT',
                'IOS_DEVELOPMENT'
            ];

            if( !in_array( $certificate['certificateType'], $allowed_cert_types ) ){
                return new WP_Error('invalid_certificate', 'The certificate is not a development certificate');
            } */

            if( empty( $bundle_id ) ){
                return new WP_Error('no_bundle', 'The Development App ID is not selected');
            }

            $bundle = Better_Messages()->mobile_app->ios->get_bundle( $bundle_id );

            if( is_wp_error( $bundle ) ){
                return $bundle;
            }

            $existing_capabilities = $bundle['capabilities'];

            $required_capabilities = [
                'PUSH_NOTIFICATIONS',
                'USERNOTIFICATIONS_COMMUNICATION',
                'USERNOTIFICATIONS_TIMESENSITIVE',
                'ASSOCIATED_DOMAINS'
            ];

            $missing_capabilities = array_diff( $required_capabilities, $existing_capabilities );

            if( count( $missing_capabilities ) > 0 ){
                return new WP_Error('missing_capabilities', 'The bundle is missing required capabilities: ' . implode(', ', $missing_capabilities) );
            }

            $devices = Better_Messages()->mobile_app->ios->get_devices();

            if( is_wp_error( $devices ) ){
                return $devices;
            }

            if( count( $devices ) === 0 ){
                return new WP_Error('no_devices', 'There are no devices registered. Please ensure to add at least one device to the Apple Developer Account');
            }

            /*$profile = Better_Messages()->mobile_app->ios->get_provisioning_profile( $profile_id );

            if( is_wp_error( $profile ) ){
                return $profile;
            }

            if( $profile['profileState'] !== 'ACTIVE' ){
                return new WP_Error('invalid_profile', 'The provisioning profile is not active');
            }*/

            if( empty( $notification_bundle_id ) ){
                return new WP_Error('no_notification_bundle', 'The Development notification service App ID is not selected');
            }

            $notification_bundle = Better_Messages()->mobile_app->ios->get_bundle( $notification_bundle_id );

            if( is_wp_error( $notification_bundle ) ){
                return $notification_bundle;
            }

            $prefix_check = $this->check_extension_bundle( $bundle, $notification_bundle, 'notification service' );

            if( is_wp_error( $prefix_check ) ){
                return $prefix_check;
            }

            $broadcast_bundle = $this->get_ios_broadcast_bundle( $settings['iosBundleBroadcastDev'], $existing_capabilities, 'development' );

            if( is_wp_error( $broadcast_bundle ) ){
                return $broadcast_bundle;
            }

            $prefix_check = $this->check_extension_bundle( $bundle, $broadcast_bundle, 'screen sharing' );

            if( is_wp_error( $prefix_check ) ){
                return $prefix_check;
            }

            /*$notification_profile = Better_Messages()->mobile_app->ios->get_provisioning_profile( $notification_profile_id );

            if( is_wp_error( $notification_profile ) ){
                return $notification_profile;
            }

            if( $notification_profile['profileState'] !== 'ACTIVE' ){
                return new WP_Error('invalid_notification_profile', 'The notification provisioning profile is not active');
            }*/

            if( ! $build_info ) {
                //unset($profile['profileContent']);
                //unset($certificate['certificateContent']);

                /*if ($profile['certificates'] && count($profile['certificates']) > 0) {
                    $profile['certificates'] = array_map(function ($certificate) {
                        unset($certificate['certificateContent']);
                        return $certificate;
                    }, $profile['certificates']);
                }*/
            }

            if( ! $settings['iosAppNameDev'] ){
                return new WP_Error('no_app_name', 'The Application Name is not set');
            }

            if( ! $settings['appIcon'] ){
                return new WP_Error('no_app_icon', 'The Application Icon is not set');
            }

            if( ! $settings['appSplash'] ){
                return new WP_Error('no_app_splash', 'The Application Splash Screen is not set');
            }

            if( ! $settings['appSplashColor'] ){
                return new WP_Error('no_app_splash_color', 'The Application Splash Screen Color is not set');
            }

            if( ! $settings['loginLogo'] ){
                return new WP_Error('no_login_logo', 'The Application Login Logo is not set');
            }

            $api_url = esc_url_raw(get_rest_url(null, '/better-messages/v1/'));

            $credentials = get_option('better-messages-app-ios-auth', false);

            $return = [
                'platform' => 'ios',
                'type' => 'development',
                'site_id' => $fs_site->id,
                'build_info' => [
                    'domain'              => $domain,
                    'app_name'            => $settings['iosAppNameDev'],
                    'app_icon'            => $settings['appIcon'],
                    'app_splash'          => $settings['appSplash'],
                    'app_splash_color'    => $settings['appSplashColor'],
                    'api_url'             => $api_url,
                    'bundle'              => $bundle,
                    'team_id'             => $team_id,
                    'devices'             => $devices,
                    'notification_bundle' => $notification_bundle,
                    'broadcast_bundle'    => $broadcast_bundle,
                    'credentials'         => $credentials,
                    'plugin_version'      => Better_Messages()->version,
                    'created_at'          => time()
                ]
            ];

            if( $build_info ){
                $return['build_info'] = json_encode( $return['build_info'] );
            }

            return $return;
        }

        public function get_ios_dist_build_info($build_info = false)
        {
            $settings = Better_Messages()->mobile_app->settings->get_settings();

            $bundle_id   = $settings['iosBundleProd'];
            $team_id     = trim($settings['iosAppTeamId']);

            $notification_bundle_id   = $settings['iosBundleService'];

            $site_url = get_site_url();
            $parse = parse_url($site_url);
            $domain = $parse['host'];

            $fs_site = bpbm_fs()->get_site();

            if( ! $fs_site ){
                return new WP_Error('site_not_connected', 'The site is not connected');
            }

            if( ! bpbm_fs()->has_features_enabled_license() ){
                return new WP_Error( 'site_not_licensed', 'The website has no active WebSocket license' );
            }

            $jwt = Better_Messages()->mobile_app->ios->get_jwt();

            if( empty( $jwt ) ){
                return new WP_Error('invalid_access', 'Connection to Apple Developer Account is not configured');
            }

            if( ! $team_id ){
                return new WP_Error('no_team_id', 'The Team ID is not set');
            }

            if( empty( $bundle_id ) ){
                return new WP_Error('no_bundle', 'The Distribution App ID is not selected');
            }

            $bundle = Better_Messages()->mobile_app->ios->get_bundle( $bundle_id );

            if( is_wp_error( $bundle ) ){
                return $bundle;
            }

            $existing_capabilities = $bundle['capabilities'];

            $required_capabilities = [
                'PUSH_NOTIFICATIONS',
                'USERNOTIFICATIONS_COMMUNICATION',
                'USERNOTIFICATIONS_TIMESENSITIVE',
                'ASSOCIATED_DOMAINS'
            ];

            $missing_capabilities = array_diff( $required_capabilities, $existing_capabilities );

            if( count( $missing_capabilities ) > 0 ){
                return new WP_Error('missing_capabilities', 'The bundle is missing required capabilities: ' . implode(', ', $missing_capabilities) );
            }

            $devices = Better_Messages()->mobile_app->ios->get_devices();

            if( is_wp_error( $devices ) ){
                return $devices;
            }

            if( count( $devices ) === 0 ){
                return new WP_Error('no_devices', 'There are no devices registered. Please ensure to add at least one device to the Apple Developer Account');
            }

            if( empty( $notification_bundle_id ) ){
                return new WP_Error('no_notification_bundle', 'The Distribution notification service App ID is not selected');
            }

            $notification_bundle = Better_Messages()->mobile_app->ios->get_bundle( $notification_bundle_id );

            if( is_wp_error( $notification_bundle ) ){
                return $notification_bundle;
            }

            $prefix_check = $this->check_extension_bundle( $bundle, $notification_bundle, 'notification service' );

            if( is_wp_error( $prefix_check ) ){
                return $prefix_check;
            }

            $broadcast_bundle = $this->get_ios_broadcast_bundle( $settings['iosBundleBroadcast'], $existing_capabilities, 'production' );

            if( is_wp_error( $broadcast_bundle ) ){
                return $broadcast_bundle;
            }

            $prefix_check = $this->check_extension_bundle( $bundle, $broadcast_bundle, 'screen sharing' );

            if( is_wp_error( $prefix_check ) ){
                return $prefix_check;
            }

            if( ! $settings['iosAppName'] ){
                return new WP_Error('no_app_name', 'The Application Name is not set');
            }

            if( ! $settings['appIcon'] ){
                return new WP_Error('no_app_icon', 'The Application Icon is not set');
            }

            if( ! $settings['appSplash'] ){
                return new WP_Error('no_app_splash', 'The Application Splash Screen is not set');
            }

            if( ! $settings['appSplashColor'] ){
                return new WP_Error('no_app_splash_color', 'The Application Splash Screen Color is not set');
            }

            if( ! $settings['loginLogo'] ){
                return new WP_Error('no_login_logo', 'The Application Login Logo is not set');
            }

            $app_id = Better_Messages()->mobile_app->ios->get_app_id_from_bundle( $bundle['identifier'] );

            if( is_wp_error( $app_id ) ){
                return $app_id;
            }

            $last_build = Better_Messages()->mobile_app->ios->get_last_build( $app_id );

            if( is_wp_error( $last_build ) ){
                if( $last_build->get_error_code() !== 'build_not_found' ){
                    return $last_build;
                } else {
                    $last_version = 0;
                }
            } else {
                $last_version = (int) $last_build['version'];
            }

            $last_release = Better_Messages()->mobile_app->ios->get_last_version( $app_id );

            $invalid_states = [
                'READY_FOR_DISTRIBUTION'
            ];

            if( is_wp_error( $last_release ) ){
                if( $last_release->get_error_code() !== 'version_not_found' ){
                    return $last_release;
                } else {
                    $last_marketing_version = '1.0';
                }
            } else {
                if( in_array( $last_release['state'], $invalid_states ) ){
                    $last_marketing_version = $this->incrementMinorVersion( $last_release['versionString'] );
                } else {
                    $last_marketing_version = $last_release['versionString'];
                }
            }

            $api_url = esc_url_raw(get_rest_url(null, '/better-messages/v1/'));

            $credentials = get_option('better-messages-app-ios-auth', false);

            $return = [
                'platform' => 'ios',
                'type'     => 'production',
                'site_id'  => $fs_site->id,
                'build_info' => [
                    'domain'              => $domain,
                    'app_name'            => $settings['iosAppName'],
                    'app_icon'            => $settings['appIcon'],
                    'app_splash'          => $settings['appSplash'],
                    'app_splash_color'    => $settings['appSplashColor'],
                    'version'             => $last_version + 1,
                    'mkt_version'         => $last_marketing_version,
                    'api_url'             => $api_url,
                    'bundle'              => $bundle,
                    'team_id'             => $team_id,
                    'notification_bundle' => $notification_bundle,
                    'broadcast_bundle'    => $broadcast_bundle,
                    'credentials'         => $credentials,
                    'plugin_version'      => Better_Messages()->version,
                    'created_at'          => time()
                ]
            ];

            if( $build_info ){
                $return['build_info']['certificate'] = get_option('better-messages-app-ios-certificate-DISTRIBUTION');
                $return['build_info'] = json_encode( $return['build_info'] );
            }

            return $return;
        }

        public function incrementMinorVersion($version) {
            // Split the version string into major and minor parts
            list($major, $minor) = explode('.', $version);

            // Increment the minor version
            $minor++;

            // Return the new version string
            return $major . '.' . $minor;
        }

        public function process_build_request( $data )
        {
            global $wpdb;

            $table = Better_Messages_Mobile_App()->builds_table;

            $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

            $request = wp_remote_post($builder_server . '/api/requestBuild', array(
                'body'    => $data,
                'timeout' => 30
            ));

            if( Better_Messages()->functions->is_response_good( $request ) !== true ){
                return Better_Messages()->functions->is_response_good( $request );
            }

            $response = json_decode($request['body'], true );

            if ( wp_remote_retrieve_response_code($request) != 200 ) {
                if( isset( $response['error'] ) ){
                    return new WP_Error('request_failed', $response['error']);
                } else {
                    return new WP_Error('request_failed', 'The network request failed.');
                }
            }

            if( $data['platform'] === 'android' && $data['type'] === 'production' ){
                $last_version = get_option('better-messages-app-android-last-release-version', 0);
                update_option( 'better-messages-app-android-last-release-version', $last_version + 1, false );
            }

            $build_info = json_decode( $data['build_info'], true );

            if( is_array( $build_info ) && isset( $response['core']['revision'] ) ){
                $build_info['core_revision'] = (int) $response['core']['revision'];

                if( isset( $response['core']['channel'] ) && $response['core']['channel'] !== null && $response['core']['channel'] !== '' ){
                    $build_info['core_channel'] = (string) $response['core']['channel'];
                }

                $data['build_info'] = wp_json_encode( $build_info );
            }

            $wpdb->insert( $table, [
                'id'         => $response['build']['id'],
                'site_id'    => $data['site_id'],
                'platform'   => $data['platform'],
                'type'       => $data['type'],
                'status'     => 'in-queue',
                'secret'     => $response['build']['secret'],
                'build_info' => $data['build_info'],
            ] );

            return $response['message'];
        }
    }


}

