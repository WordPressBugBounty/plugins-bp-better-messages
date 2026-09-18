<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Mobile_App_Options' ) ):

    class Better_Messages_Mobile_App_Options
    {
        public $settings;
        public $defaults;

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Mobile_App_Options();
            }

            return $instance;
        }

        public function __construct(){
            add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
        }

        public function rest_api_init(){
            register_rest_route( 'better-messages/v1/admin/app', '/getSettings', array(
                'methods' => 'GET',
                'callback' => array( $this, 'get_settings' ),
                'permission_callback' => array($this, 'user_is_admin'),
            ) );

            register_rest_route( 'better-messages/v1/admin/app', '/saveSettings', array(
                'methods' => 'POST',
                'callback' => array( $this, 'save_settings' ),
                'permission_callback' => array($this, 'user_is_admin'),
            ) );

            register_rest_route( 'better-messages/v1/admin/app', '/uploadFile', array(
                'methods' => 'POST',
                'callback' => array( $this, 'upload_file' ),
                'permission_callback' => array($this, 'user_is_admin'),
            ) );

            register_rest_route( 'better-messages/v1/admin/app', '/deleteFile', array(
                'methods' => 'POST',
                'callback' => array( $this, 'delete_file' ),
                'permission_callback' => array($this, 'user_is_admin'),
            ) );
        }

        public function user_is_admin(): bool
        {
            return current_user_can('manage_options');
        }

        public function init(): void
        {

            $this->defaults = array(
                'iosAppName'                => 'My Messenger',
                'iosAppNameDev'             => 'My Messenger Dev',
                'androidAppNameDev'         => 'My Messenger Dev',
                'androidAppNameProd'        => 'My Messenger',
                'androidFirebaseDevAppId'   => '',
                'androidFirebaseProdAppId'  => '',
                'androidFirebaseServiceDev' => '',

                'iosAppTeamId'       => '',
                'termsAndConditions' => '',

                'appIcon'            => '',
                'appSplash'          => '',
                'appSplashColor'    => '',

                'androidNotificationIcon' => '',
                'loginLogo'          => '',
                'loginLogoHeight' => '100',

                'iosCertificateDev'  => '',
                'iosCertificateProd' => '',

                'iosBundleDev'        => '',
                'iosBundleServiceDev' => '',
                'iosBundleProd'       => '',
                'iosBundleService'    => '',

                'iosBundleBroadcast'    => '',
                'iosBundleBroadcastDev' => '',

                'iosProfileDev'      => '',
                'iosProfileServiceDev'  => '',
                'iosProfileProd'     => '',
                'iosProfileService'  => '',
            );

            $args  = get_option( 'better-messages-app-settings', array() );
            $iosApi = get_option('better-messages-app-ios-auth', false);
            $iosPush = get_option('better-messages-app-ios-push-cert', false);
            $androidApi = get_option('better-messages-app-android-auth', false);

            if( $iosApi ){
                if( $iosApi['apiKey'] ) {
                    unset($iosApi['apiKey']);
                }

                $args['iosApi'] = $iosApi;
            } else {
                $args['iosApi'] = (object) [];
            }

            if( $androidApi ){
                if( $androidApi['apiKey'] ) {
                    unset($androidApi['apiKey']);
                }

                $args['androidApi'] = $androidApi;
            } else {
                $args['androidApi'] = (object) [];
            }

            if( $iosPush ){
                $args['iosPush'] = true;
            } else {
                $args['iosPush'] = false;
            }

            $androidKeyStore = get_option('better-messages-app-android-keystore', false);

            if( $androidKeyStore ){
                $args['androidKeyStore'] = $androidKeyStore['info'];
            } else {
                $args['androidKeyStore'] = false;
            }

            $androidFirebaseDev = get_option('better-messages-app-android-firebase-dev', false);

            if( $androidFirebaseDev ){
                $data = json_decode( $androidFirebaseDev, true );

                $availableProjects = [];

                if( isset( $data['client'] ) && is_array( $data['client'] ) && count( $data['client'] ) > 0 ){
                    foreach ( $data['client'] as $client ){
                        if( isset( $client['client_info']['android_client_info']['package_name'] ) && ! empty( $client['client_info']['android_client_info']['package_name'] ) ){
                            $app_id = $client['client_info']['mobilesdk_app_id'];
                            $package_name = $client['client_info']['android_client_info']['package_name'];
                            $availableProjects[] = [ 'app_id' => $app_id, 'package_name' => $package_name ];
                        }
                    }
                }

                if ( count( $availableProjects ) > 0 ) {
                    $args['androidFirebaseDev'] = [
                        'project_info' => $data['project_info'],
                        'packages'     => $availableProjects
                    ];
                } else {
                    $args['androidFirebaseDev'] = false;
                    $args['androidFirebaseDevAppId'] = '';
                }
            } else {
                $args['androidFirebaseDev'] = false;
            }

            $androidFirebaseProd = get_option('better-messages-app-android-firebase-prod', false);

            if( $androidFirebaseProd ){
                $data = json_decode( $androidFirebaseProd, true );

                $availableProjects = [];

                if( isset( $data['client'] ) && is_array( $data['client'] ) && count( $data['client'] ) > 0 ){
                    foreach ( $data['client'] as $client ){
                        if( isset( $client['client_info']['android_client_info']['package_name'] ) && ! empty( $client['client_info']['android_client_info']['package_name'] ) ){
                            $app_id = $client['client_info']['mobilesdk_app_id'];
                            $package_name = $client['client_info']['android_client_info']['package_name'];
                            $availableProjects[] = [ 'app_id' => $app_id, 'package_name' => $package_name ];
                        }
                    }
                }

                if ( count( $availableProjects ) > 0 ) {
                    $args['androidFirebaseProd'] = [
                        'project_info' => $data['project_info'],
                        'packages'     => $availableProjects
                    ];
                } else {
                    $args['androidFirebaseProd'] = false;
                    $args['androidFirebaseProdAppId'] = '';
                }
            } else {
                $args['androidFirebaseProd'] = false;
            }

            $androidFirebaseServiceDev = get_option('better-messages-app-android-firebase-service-dev', false);

            if( $androidFirebaseServiceDev ){
                $args['androidFirebaseServiceDev'] = true;
            } else {
                $args['androidFirebaseServiceDev'] = false;
            }

            $androidFirebaseServiceProd = get_option('better-messages-app-android-firebase-service-prod', false);
            if( $androidFirebaseServiceProd ){
                $args['androidFirebaseServiceProd'] = true;
            } else {
                $args['androidFirebaseServiceProd'] = false;
            }

            $files = [
                'appIcon',
                'appSplash',
                'loginLogo',
                'androidNotificationIcon'
            ];

            foreach ( $files as $file ){
                $args[$file] = get_option('better-messages-app-settings-file-' . $file, '');
                $args[$file . 'Extension'] = get_option('better-messages-app-settings-file-' . $file . '-extension', '');
            }


            $this->settings = wp_parse_args( $args, $this->defaults );
        }

        public function get_settings( ?WP_REST_Request $request = null ): array
        {
            $this->init();
            return $this->settings;
        }


        public function get_page_data(): array
        {
            $settings = $this->get_settings();

            $ios_connected     = ! empty( get_option( 'better-messages-app-ios-auth', false ) );
            $android_connected = ! empty( $settings['androidFirebaseDev'] ) || ! empty( $settings['androidFirebaseProd'] );

            $websocket = function_exists( 'Better_Messages_WebSocket' ) && Better_Messages()->functions->can_use_premium_code_premium_only();

            $site_id = class_exists( 'Better_Messages_WebSocket' ) ? Better_Messages_WebSocket()->site_id : '';

            $can_build = Better_Messages()->mobile_app && Better_Messages()->mobile_app->can_build;

            $is_premium      = function_exists( 'bpbm_fs' ) && bpbm_fs()->is_premium();
            $can_use_premium = Better_Messages()->functions->can_use_premium_code();

            $download_url = ( $can_use_premium && ! $is_premium && function_exists( 'bpbm_fs' ) )
                ? bpbm_fs()->_get_latest_download_local_url()
                : '';

            return array(
                'settings'      => $settings,
                'pluginVersion' => Better_Messages()->version,
                'pluginUrl'     => Better_Messages()->url,
                'adminUrl'      => admin_url(),
                'siteId'        => $site_id,
                'domain'        => Better_Messages()->functions->get_site_domain(),
                'apiUrl'        => get_rest_url(),
                'hasWebSocket'  => $websocket,
                'canBuild'         => $can_build,
                'licenseUrl'       => admin_url( 'admin.php?page=bp-better-messages-pricing' ),
                'downloadUrl'      => $download_url,
                'canUsePremiumCode' => $can_use_premium,
                'iosConnected'     => $ios_connected,
                'androidConnected' => $android_connected,
                'nativeCallsAvailable' => $this->native_calls_available(),
                'requiredCapabilities' => array(
                    'app'       => array( 'PUSH_NOTIFICATIONS', 'USERNOTIFICATIONS_COMMUNICATION', 'USERNOTIFICATIONS_TIMESENSITIVE', 'ASSOCIATED_DOMAINS' ),
                    'broadcast' => array( 'APP_GROUPS' ),
                ),
                'appGroupHint'  => 'group.{bundle}',
                'docsIosUrl'     => 'https://www.better-messages.com/docs/mobile-app/ios-application/',
                'docsAndroidUrl' => 'https://www.better-messages.com/docs/mobile-app/android-application/',
                'portalUrl'     => 'https://developer.apple.com/account/resources/identifiers/list',
                'contactUrl'    => function_exists( 'bpbm_fs' ) && method_exists( bpbm_fs(), 'contact_url' ) ? bpbm_fs()->contact_url() : '',
            );
        }

        public function native_calls_available(): bool
        {
            return (bool) apply_filters( 'better_messages_app_native_calls', true );
        }

        public function save_settings( WP_REST_Request $request ){
            $settings = (array) $request->get_param('settings');

            if( count( $settings ) > 0 ){
                $_settings = get_option('better-messages-app-settings', []);

                foreach( $settings as $key => $value ){
                    $_settings[$key] = $value;
                }

                $this->update_settings( $_settings );
            }
        }

        public function upload_file( WP_REST_Request $request ){
            $key  = $request->get_param('key');
            $files = $request->get_file_params();

            if( ! isset( $files['file'] ) ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            $file = $files['file'];

            $path = $file['tmp_name'];

            $extension = pathinfo( $file['name'], PATHINFO_EXTENSION );

            switch ( $key ){
                case 'appIcon' :
                    $size = getimagesize( $path );
                    if( ! $size ){
                        return new WP_Error(
                            'rest_error',
                            _x( 'Not possible to determine image size', 'Rest API Error', 'bp-better-messages' ),
                            array( 'status' => 406 )
                        );
                    }

                    $width  = $size[0];
                    $height = $size[1];

                    if( $width < 1024 || $height < 1024 || ( $width !== $height ) ){
                        return new WP_Error(
                            'rest_error',
                            sprintf(_x( 'Image must be equal height and at least %s', 'Rest API Error', 'bp-better-messages' ), '1024x1024px'),
                            array( 'status' => 406 )
                        );
                    }
                    break;
                case 'appSplash' :
                    $size = getimagesize( $path );
                    if( ! $size ){
                        return new WP_Error(
                            'rest_error',
                            _x( 'Not possible to determine image size', 'Rest API Error', 'bp-better-messages' ),
                            array( 'status' => 406 )
                        );
                    }

                    $width  = $size[0];
                    $height = $size[1];

                    if( $width < 2732 || $height < 2732 || ( $width !== $height ) ){
                        return new WP_Error(
                            'rest_error',
                            sprintf(_x( 'Image must be equal height and at least %s', 'Rest API Error', 'bp-better-messages' ), '2732x2732px'),
                            array( 'status' => 406 )
                        );
                    }
                    break;
                case 'androidNotificationIcon':
                    $size = getimagesize( $path );
                    if( ! $size ){
                        return new WP_Error(
                            'rest_error',
                            _x( 'Not possible to determine image size', 'Rest API Error', 'bp-better-messages' ),
                            array( 'status' => 406 )
                        );
                    }

                    $width  = $size[0];
                    $height = $size[1];

                    if( $width != 96 || $height != 96 ) {
                        return new WP_Error(
                            'rest_error',
                            sprintf(_x('Image must be exactly %s', 'Rest API Error', 'bp-better-messages'), '96x96px'),
                            array('status' => 406)
                        );
                    }

                    if( ! function_exists('imagecreatefrompng') || ! function_exists('imagesx') || ! function_exists('imagesy') || ! function_exists('imagecolorat') || ! function_exists('imagecolorsforindex') ){
                        return new WP_Error(
                            'rest_error',
                            'GD library is not available in your PHP installation',
                            array( 'status' => 406 )
                        );
                    }

                    $img = imagecreatefrompng($path);

                    if( ! $img ){
                        return new WP_Error(
                            'rest_error',
                            _x( 'Image must be PNG', 'Rest API Error', 'bp-better-messages' ),
                            array( 'status' => 406 )
                        );
                    }

                    $width = imagesx($img);
                    $height = imagesy($img);

                    for ($y = 0; $y < $height; $y++) {
                        for ($x = 0; $x < $width; $x++) {
                            $rgba = imagecolorat($img, $x, $y);
                            $colors = imagecolorsforindex($img, $rgba);

                            // Skip fully transparent pixels
                            if ($colors['alpha'] === 127) {
                                continue;
                            }

                            // Check if non-transparent pixel is white
                            if ($colors['red'] !== 255 || $colors['green'] !== 255 || $colors['blue'] !== 255) {
                                return new WP_Error(
                                    'rest_error',
                                    _x( 'Image must have only white and transparent pixels', 'Rest API Error', 'bp-better-messages' ),
                                    array( 'status' => 406 )
                                );
                            }
                        }
                    }
                    break;
            }

            $file_content = base64_encode(file_get_contents($path));

            update_option('better-messages-app-settings-file-' . $key, $file_content, false );
            update_option('better-messages-app-settings-file-' . $key . '-extension', $extension, false );

            return $file_content;
        }

        public function delete_file( WP_REST_Request $request ){
            $key  = $request->get_param('key');
            delete_option('better-messages-app-settings-file-' . $key);
            delete_option('better-messages-app-settings-file-' . $key . '-extension');
            return true;
        }

        public function update_settings( $_settings ){
            update_option('better-messages-app-settings', $_settings, false);
        }
    }

endif;

function Better_Messages_Mobile_App_Options()
{
    return Better_Messages_Mobile_App_Options::instance();
}
