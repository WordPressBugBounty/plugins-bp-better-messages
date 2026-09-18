<?php
defined('ABSPATH') || exit;

if (!class_exists('Better_Messages_Mobile_App_Android')):

    class Better_Messages_Mobile_App_Android
    {
        public static function instance(): ?Better_Messages_Mobile_App_Android
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Mobile_App_Android();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init',  array( $this, 'rest_api_init' ) );
        }

        public function rest_api_init(){
            register_rest_route( 'better-messages/v1/admin/app', '/android/uploadFirebaseFile', array(
                'methods' => 'POST',
                'callback' => array( $this, 'upload_firebase_file' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                }
            ) );

            register_rest_route( 'better-messages/v1/admin/app', '/android/deleteFirebaseFile', array(
                'methods' => 'POST',
                'callback' => array( $this, 'delete_firebase_file' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                }
            ) );

            register_rest_route( 'better-messages/v1/admin/app', '/android/generateKeyStore', array(
                'methods' => 'POST',
                'callback' => array( $this, 'generate_key_store' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                }
            ) );

            register_rest_route( 'better-messages/v1/admin/app', '/android/uploadKeyStoreFile', array(
                'methods' => 'POST',
                'callback' => array( $this, 'upload_key_store' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                }
            ) );

            register_rest_route( 'better-messages/v1/admin/app', '/android/deleteKeyStoreFile', array(
                'methods' => 'POST',
                'callback' => array( $this, 'delete_key_store' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                }
            ) );

            register_rest_route( 'better-messages/v1/admin/app', '/android/downloadKeyStoreFile', array(
                'methods' => 'GET',
                'callback' => array( $this, 'download_key_store' ),
                'permission_callback' => function () {
                    return current_user_can( 'manage_options' );
                }
            ) );
        }

        public function delete_key_store( WP_REST_Request $request )
        {
            delete_option('better-messages-app-android-keystore');

            return true;
        }

        public function download_key_store( WP_REST_Request $request )
        {
            if( ! class_exists('ZipArchive') ){
                return new WP_Error(
                    'rest_error',
                    _x( 'ZipArchive class is not available', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }

            $androidKeyStore = get_option('better-messages-app-android-keystore', false);

            if ($androidKeyStore && isset($androidKeyStore['keystore'], $androidKeyStore['info'])) {
                $jksData = base64_decode($androidKeyStore['keystore']);
                $jksFile = sys_get_temp_dir() . '/keystore.jks';
                file_put_contents($jksFile, $jksData);

                $info = $androidKeyStore['info'];
                $infoText = '';
                foreach ($info as $k => $v) {
                    $infoText .= "$k: $v\n";
                }
                $infoFile = sys_get_temp_dir() . '/info.txt';
                file_put_contents($infoFile, $infoText);

                $zipFile = sys_get_temp_dir() . '/keystore_bundle.zip';

                $zip = new ZipArchive();

                if ($zip->open($zipFile, ZipArchive::CREATE) === TRUE) {

                    $zip->addFile($jksFile, 'keystore.jks');
                    $zip->addFile($infoFile, 'info.txt');
                    $zip->close();

                    header('Content-Type: application/zip');
                    header('Content-Disposition: attachment; filename="keystore_bundle.zip"');
                    header('Content-Length: ' . filesize($zipFile));
                    readfile($zipFile);

                    // Clean up temporary files
                    unlink($jksFile);
                    unlink($infoFile);
                } else {
                    return new WP_Error(
                        'rest_error',
                        _x( 'Error creating ZIP file', 'Rest API Error', 'bp-better-messages' ),
                        array( 'status' => 500 )
                    );
                }
            } else {
                return new WP_Error(
                    'rest_error',
                    _x( 'Keystore information is not available', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }
        }

        public function upload_key_store( WP_REST_Request $request )
        {
            $files    = $request->get_file_params();

            if ( ! isset( $files['file'] ) ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'No file was uploaded', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            $file = $files['file'];

            if ( $file['error'] !== UPLOAD_ERR_OK ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'File upload error: ' . $file['error'], 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            $keyStorePassword = $request->get_param('keyStorePassword');
            $keyAlias = $request->get_param('keyAlias');
            $keyPassword = $request->get_param('keyPassword');

            if( ! $keyStorePassword ){
                return new WP_Error(
                    'rest_error',
                    _x( 'Key store password is required', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            if( ! $keyAlias ){
                return new WP_Error(
                    'rest_error',
                    _x( 'Key alias is required', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            if( ! $keyPassword ){
                return new WP_Error(
                    'rest_error',
                    _x( 'Key password is required', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            $file_path = $file['tmp_name'];
            $file_type = $file['type'];
            $file_name = $file['name'];

            if( ! function_exists('curl_file_create') || ! function_exists('curl_init')  ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'cURL is not available', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }

            $file_data = curl_file_create($file_path, $file_type, $file_name);

            $post_fields = [
                'keyStore'         => $file_data,
                'keyStorePassword' => $request->get_param('keyStorePassword'),
                'keyAlias'         => $request->get_param('keyAlias'),
                'keyPassword'      => $request->get_param('keyPassword'),
            ];

            $ch = curl_init();

            $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

            curl_setopt_array($ch, [
                CURLOPT_URL            => $builder_server . '/api/validateKeyStore',
                CURLOPT_POST           => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS     => $post_fields,
            ]);

            $response = curl_exec($ch);
            $error    = curl_error($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            curl_close($ch);

            if( $error ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'Failed to connect to the key store validation service: ' . $error, 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }

            $response = json_decode($response, true);

            $valid = $response['valid'] ?? false;

            if( ! $valid ) {
                if( $response['error'] ){
                    return new WP_Error(
                        'rest_error',
                        $response['error'],
                        array( 'status' => 406 )
                    );
                }

                return new WP_Error(
                    'rest_error',
                    _x( 'Invalid key store file', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            $keyStore = file_get_contents($file['tmp_name']);

            $info = [
                'keyStorePassword' => $keyStorePassword,
                'keyAlias' => $keyAlias,
                'keyPassword' => $keyPassword
            ];

            // Save the keystore to a file
            update_option('better-messages-app-android-keystore', [
                'keystore' => base64_encode($keyStore),
                'info' => $info
            ], false );

            return $info;
        }

        public function generate_key_store( WP_REST_Request $request )
        {
            $keyStorePassword = $request->get_param('keyStorePassword');
            $keyAlias = $request->get_param('keyAlias');
            $keyPassword = $request->get_param('keyPassword');

            if( ! $keyStorePassword ){
                $keyStorePassword = Better_Messages()->functions->generateRandomString( 20 );
            }

            if( strlen($keyStorePassword) < 8 ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'Key store password must be at least 8 characters', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            if( ! $keyAlias ){
                return new WP_Error(
                    'rest_error',
                    _x( 'Key alias is required', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            if( ! $keyPassword ){
                $keyPassword = Better_Messages()->functions->generateRandomString( 20 );
            }

            if( strlen($keyPassword) < 8 ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'Key password must be at least 8 characters', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            $certificateName = $request->get_param('certificateName');

            if( ! $certificateName ){
                return new WP_Error(
                    'rest_error',
                    _x( 'Certificate First and Last Name is required', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            $certificateCountry = $request->get_param('certificateCountry');

            if( ! $certificateCountry ){
                return new WP_Error(
                    'rest_error',
                    _x( 'Certificate Country Code is required', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            if( strlen($certificateCountry) != 2 ){
                return new WP_Error(
                    'rest_error',
                    _x( 'Certificate Country Code must be 2 characters', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 406 )
                );
            }

            $certificateOrganization = $request->get_param('certificateOrganization');
            $certificateOrganizationUnit = $request->get_param('certificateOrganizationUnit');
            $certificateCity = $request->get_param('certificateCity');
            $certificateState = $request->get_param('certificateState');

            $args = [
                'keyStorePassword' => $keyStorePassword,
                'keyAlias' => $keyAlias,
                'keyPassword' => $keyPassword,
                'certificateName' => $certificateName,
                'certificateCountry' => $certificateCountry,
                'certificateOrganization' => $certificateOrganization,
                'certificateOrganizationUnit' => $certificateOrganizationUnit,
                'certificateCity' => $certificateCity,
                'certificateState' => $certificateState
            ];

            $builder_server = apply_filters('better_messages_mobile_app_builder_server', 'https://builder.better-messages.com');

            $request = wp_remote_post($builder_server . '/api/generateKeyStore', [
                'body' => $args,
                'timeout' => 60,
            ]);

            if ( is_wp_error( $request ) ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'Failed to connect to the key store generation service', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }

            $response = json_decode( wp_remote_retrieve_body( $request ), true );

            $keystoreBase64 = $response['keystore'];

            if ( ! $keystoreBase64 ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'Failed to generate key store', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }

            $keystore = base64_decode( $keystoreBase64 );

            if ( ! $keystore ) {
                return new WP_Error(
                    'rest_error',
                    _x( 'Failed to decode key store', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }

            // Save the keystore to a file
            update_option('better-messages-app-android-keystore', [
                'keystore' => $keystoreBase64,
                'info' => $args
            ], false );

            return $args;
        }

        public function upload_firebase_file( WP_REST_Request $request )
        {
            if ( ! isset($_FILES['file']) ) {
                // Handle the error
                return new WP_Error('no_file_uploaded', 'No file was uploaded.');
            }

            // Access the uploaded file
            $file = $request->get_file_params()['file'];

            $key = $request->get_param('key');

            switch ( $key ){
                case 'androidFirebaseDev':
                case 'androidFirebaseProd':
                    $file_content = file_get_contents($file['tmp_name']);

                    $option_key = ( $key === 'androidFirebaseDev' ) ? 'better-messages-app-android-firebase-dev' : 'better-messages-app-android-firebase-prod';
                    $transient_key = ( $key === 'androidFirebaseDev' ) ? 'better-messages-app-android-push-token-dev' : 'better-messages-app-android-push-token-prod';

                    try{
                        $data = json_decode($file_content, true);

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
                            update_option( $option_key, $file_content, false );
                            return [
                                'project_info' => $data['project_info'],
                                'packages'     => $availableProjects
                            ];
                        } else {
                            return new WP_Error(
                                'rest_error',
                                _x( 'Incorrect JSON file', 'Rest API Error', 'bp-better-messages' ),
                                array( 'status' => 406 )
                            );
                        }
                    } catch (Exception $e) {
                        return new WP_Error(
                            'rest_error',
                            _x( 'Invalid JSON file', 'Rest API Error', 'bp-better-messages' ),
                            array( 'status' => 406 )
                        );
                    } finally {
                        delete_transient($transient_key);
                    }
                    break;

                    case 'androidFirebaseServiceDev':
                    case 'androidFirebaseServiceProd':

                        $option_key = ( $key === 'androidFirebaseServiceDev' ) ? 'better-messages-app-android-firebase-service-dev' : 'better-messages-app-android-firebase-service-prod';
                        $transient_key = ( $key === 'androidFirebaseServiceDev' ) ? 'better-messages-app-android-push-token-dev' : 'better-messages-app-android-push-token-prod';

                        $file_content = file_get_contents($file['tmp_name']);

                        try {
                            $serviceAccount = json_decode($file_content, true);

                            $required = [
                                'type', 'project_id', 'private_key_id', 'private_key',
                                'client_email', 'client_id', 'auth_uri', 'token_uri',
                                'auth_provider_x509_cert_url', 'client_x509_cert_url'
                            ];

                            foreach ($required as $key) {
                                if (empty($serviceAccount[$key])) {
                                    return new WP_Error(
                                        'rest_error',
                                        _x( "Incorrect JSON file", 'Rest API Error', 'bp-better-messages' ),
                                        array( 'status' => 406 )
                                    );
                                }
                            }

                            $now = time();

                            $payload = [
                                "iss" => $serviceAccount['client_email'],
                                "sub" => $serviceAccount['client_email'],
                                "aud" => $serviceAccount['token_uri'],
                                "iat" => $now,
                                "exp" => $now + 3600,
                                "scope" => "https://www.googleapis.com/auth/cloud-platform"
                            ];

                            try {
                                \BetterMessages\Firebase\JWT\JWT::encode($payload, $serviceAccount['private_key'], "RS256");
                                update_option( $option_key, $file_content, false );
                                return true;
                            } catch (\Exception $e) {
                                return new WP_Error(
                                    'rest_error',
                                    _x( 'JWT encode error: ' . $e->getMessage(), 'Rest API Error', 'bp-better-messages' ),
                                    array( 'status' => 406 )
                                );
                            }
                        } catch (Exception $e) {
                            return new WP_Error(
                                'rest_error',
                                _x( 'Invalid JSON file', 'Rest API Error', 'bp-better-messages' ),
                                array( 'status' => 406 )
                            );
                        } finally {
                            delete_transient($transient_key);
                        }

                        break;

                default:
                    return new WP_Error(
                        'rest_error',
                        _x( 'Invalid key', 'Rest API Error', 'bp-better-messages' ),
                        array( 'status' => 406 )
                    );
            }
        }

        public function delete_firebase_file( WP_REST_Request $request )
        {
            $key = $request->get_param('key');

            switch ( $key ){
                case 'androidFirebaseDev':
                    delete_option( 'better-messages-app-android-firebase-dev' );
                    delete_transient('better-messages-app-android-push-token-dev');
                    break;

                case 'androidFirebaseProd':
                    delete_option( 'better-messages-app-android-firebase-prod' );
                    delete_transient('better-messages-app-android-push-token-prod');
                    break;

                case 'androidFirebaseServiceDev':
                    delete_option( 'better-messages-app-android-firebase-service-dev' );
                    delete_transient('better-messages-app-android-push-token-dev');
                    break;

                case 'androidFirebaseServiceProd':
                    delete_option( 'better-messages-app-android-firebase-service-prod' );
                    delete_transient('better-messages-app-android-push-token-prod');
                    break;

                default:
                    return new WP_Error(
                        'rest_error',
                        _x( 'Invalid key', 'Rest API Error', 'bp-better-messages' ),
                        array( 'status' => 406 )
                    );
            }
        }

        public function get_push_jwt( $production = true ){
            if( $production ){
                $firebaseFile = get_option('better-messages-app-android-firebase-service-prod', false);
                $transientKey = "better-messages-app-android-push-token-prod";
            } else {
                $firebaseFile = get_option('better-messages-app-android-firebase-service-dev', false);
                $transientKey = "better-messages-app-android-push-token-dev";
            }

            if( ! $firebaseFile ){
                return false;
            }

            $token = get_transient( $transientKey );

            if( $token ) return $token;

            $serviceAccount = json_decode( $firebaseFile, true );

            // create a JWT
            $now_seconds = time();

            $payload = array(
                "iss" => $serviceAccount['client_email'],
                "sub" => $serviceAccount['client_email'],
                "aud" => "https://www.googleapis.com/oauth2/v4/token",
                "iat" => $now_seconds,
                "exp" => $now_seconds+(60*60),  // Maximum expiration time is one hour
                "scope" => "https://www.googleapis.com/auth/cloud-platform"
            );

            $jwt = \BetterMessages\Firebase\JWT\JWT::encode($payload, $serviceAccount['private_key'], "RS256");

            // create a POST request to the Google OAuth2.0 server
            $data = array(
                "grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
                "assertion" => $jwt
            );
            $options = array(
                'http' => array(
                    'header'  => "Content-type: application/x-www-form-urlencoded\r\n",
                    'method'  => 'POST',
                    'content' => http_build_query($data)
                )
            );
            $context  = stream_context_create($options);
            $result = file_get_contents("https://www.googleapis.com/oauth2/v4/token", false, $context);
            $response = json_decode($result, true);

            $token = $response['access_token'];
            $expiration = 60 * 45; // 45 minutes

            $result = [
                'project_id' => $serviceAccount['project_id'],
                'token' => $token
            ];

            set_transient($transientKey, $result, $expiration);

            return [
                'project_id' => $serviceAccount['project_id'],
                'token' => $token
            ];
        }


        public function generate_jwt( $private_key, $private_key_id, $client_email, $scopes, $audience ): string
        {

            $claim_set = [
                "iss" => $client_email,
                "scope" => implode(' ', $scopes),
                "aud" => $audience,
                "exp" => time() + 3600,
                "iat" => time()
            ];

            return \BetterMessages\Firebase\JWT\JWT::encode($claim_set, $private_key, 'RS256', $private_key_id );
        }

    }

endif;
