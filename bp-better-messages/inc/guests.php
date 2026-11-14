<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Guests' ) ):

    class Better_Messages_Guests
    {
        private $table;
        private $current_guest_id = false;
        private $guest_authorization_failed = false;
        private $guest_authorization_failed_reason = '';

        private $handed_guest = 0;

        const HANDOVER_VAR = 'bm-guest-handover';

        const HANDOVER_LIFETIME = 5 * MINUTE_IN_SECONDS;

        const HANDOVER_KEPT = 5;

        const REGISTRY_DB_VERSION = 2.96;

        const REGISTRY_OPTION = 'better_messages_guests_registry';

        const ADOPT_BATCH = 5000;

        const COUNTER_FLOOR = 2000000000;

        const LINK_QUERY_VAR = 'bm-guest-link';

        const LINK_RESULT_VAR = 'bm-guest-link-result';

        const CLEANUP_CURSOR = 'better_messages_guests_cleanup_after';

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Guests();
            }

            return $instance;
        }

        public function __construct()
        {
            $this->table = bm_get_table('guests');

            add_filter( 'wordplus_geo_keep_default', '__return_false' );

            $this->check_guest_auth();

            add_action('wp_enqueue_scripts', array($this, 'load_scripts'));
            add_action('rest_api_init', array($this, 'rest_api_init'));
            add_filter('better_messages_rest_is_user_authorized', array($this, 'guest_auth'), 10, 2);
            add_filter('better_messages_rest_user_item', array($this, 'guest_user_item'), 30, 3);
            add_filter('better_messages_guest_user_id', array( $this, 'guest_user_id' ) );
            add_filter('better_messages_guest_display_name', array( $this, 'guest_display_name' ), 10, 2 );

            add_action( 'init', array( $this, 'registry_tasks' ) );
            add_action( 'better_messages_guests_registry_job', array( $this, 'adopt_into_registry' ) );

            add_action( 'template_redirect', array( $this, 'link_landing' ), -1 );
            add_action( 'init', array( $this, 'expire_old_cookies' ) );
            add_action( 'send_headers', array( $this, 'handover_headers' ) );
            add_action( 'wp_head', array( $this, 'login_redirect_script' ), 1 );
            add_filter( 'better_messages_can_send_message', array( $this, 'confirm_before_reply' ), 10, 3 );

            add_action( 'init', array( $this, 'cleanup_task' ) );
            add_action( 'better_messages_guests_cleanup', array( $this, 'clean_inactive_guests' ) );
        }

        public function cleanup_task(){
            if( (int) Better_Messages()->settings['guestDeleteInactiveDays'] > 0 && function_exists( 'Better_Messages_Worker' ) ){
                Better_Messages_Worker()->add_task( 'better_messages_guests_cleanup', DAY_IN_SECONDS, HOUR_IN_SECONDS );
            }
        }

        public function guests_filter_sql( string $search = '', int $inactive_days = 0, int $max_id = 0, bool $spare_kept = false ){
            global $wpdb;

            $where = "`guests`.`deleted_at` IS NULL AND `guests`.`bot_id` = 0 AND `guests`.`secret` <> ''" . $this->blog_sql( 'guests' );

            if( '' !== $search ){
                $where .= " AND ( " . Better_Messages()->encryption->guest_search_sql( $search, 'guests' ) . $wpdb->prepare( " OR `guests`.`id` = %d )", absint( $search ) );
            }

            if( $inactive_days > 0 ){
                $cutoff = gmdate( 'Y-m-d H:i:s', time() - $inactive_days * DAY_IN_SECONDS );
                $where .= $wpdb->prepare( " AND `guests`.`created_at` < NOW() - INTERVAL %d DAY AND ( `bmu`.`last_activity` IS NULL OR `bmu`.`last_activity` < %s )", $inactive_days, $cutoff );
            }

            if( $max_id > 0 ){
                $where .= $wpdb->prepare( " AND `guests`.`id` <= %d", $max_id );
            }

            if( $spare_kept ){
                $kept = "`guests`.`email` IS NULL OR `guests`.`email` = '' OR `guests`.`meta` LIKE '%\"email_from\":\"typed\"%'";

                if( Better_Messages()->settings['guestDeleteConfirmed'] === '1' ){
                    $kept .= " OR `guests`.`meta` LIKE '%\"email_from\":\"confirmed\"%'";
                }

                $where .= " AND ( {$kept} )";
            }

            return $where;
        }

        public function guests_from_sql(){
            return "`" . $this->table . "` `guests` LEFT JOIN `" . bm_get_table('users') . "` `bmu` ON `bmu`.`ID` = -1 * `guests`.`id`";
        }

        public function clean_inactive_guests(){
            global $wpdb;

            $days = (int) Better_Messages()->settings['guestDeleteInactiveDays'];

            if( $days <= 0 ){
                return 0;
            }

            $batch = max( 1, min( 500, (int) apply_filters( 'better_messages_delete_inactive_guests_batch_size', 50 ) ) );

            $ids  = array_map( 'intval', (array) $wpdb->get_col( "SELECT `guests`.`id` FROM " . $this->guests_from_sql() . " WHERE " . $this->guests_filter_sql( '', $days, 0, true ) . " ORDER BY `guests`.`id` ASC LIMIT " . $batch ) );
            $more = count( $ids ) === $batch;

            if( ! $more ){
                $sealed = $this->sealed_inactive_guests( $days, $batch - count( $ids ) );
                $ids    = array_merge( $ids, $sealed['ids'] );
                $more   = $sealed['more'];
            }

            foreach( $ids as $id ){
                $this->delete_guest_user( $id, true );
            }

            if( $more && function_exists( 'Better_Messages_Worker' ) ){
                Better_Messages_Worker()->run_soon( 'better_messages_guests_cleanup' );
            }

            return count( $ids );
        }

        private function sealed_inactive_guests( int $days, int $limit ){
            global $wpdb;

            $found = array( 'ids' => array(), 'more' => false );

            if( ! Better_Messages()->encryption->ever_used() ){
                return $found;
            }

            $page  = 200;
            $after = (int) get_option( self::CLEANUP_CURSOR, 0 );
            $rows  = (array) $wpdb->get_results( "SELECT `guests`.`id`, `guests`.`email`, `guests`.`meta` FROM " . $this->guests_from_sql() . " WHERE " . $this->guests_filter_sql( '', $days ) . " AND `guests`.`email` LIKE 'wpe1.%' AND `guests`.`id` > " . $after . " ORDER BY `guests`.`id` ASC LIMIT " . $page );
            $read  = 0;
            $last  = $after;

            $confirmed = Better_Messages()->settings['guestDeleteConfirmed'] === '1';

            foreach( $rows as $row ){
                if( count( $found['ids'] ) >= $limit ){
                    break;
                }

                $read++;
                $last = (int) $row->id;
                $from = $this->email_from( Better_Messages()->encryption->open_guest_row( $row ) );

                if( 'typed' === $from || ( $confirmed && 'confirmed' === $from ) ){
                    $found['ids'][] = $last;
                }
            }

            $found['more'] = $read < count( $rows ) || count( $rows ) === $page;

            if( $found['more'] ){
                update_option( self::CLEANUP_CURSOR, $last, false );
            } else if( $after > 0 ){
                delete_option( self::CLEANUP_CURSOR );
            }

            return $found;
        }

        public function awaits_confirmation( $user_id ){
            if( (int) $user_id >= 0 || ! $this->confirm_before_chat() ){
                return false;
            }

            $guest = $this->get_guest_user( (int) $user_id );

            return $guest && $this->guest_bot_id( $guest ) === 0 && '' !== (string) $guest->email && 'typed' === $this->email_from( $guest );
        }

        public function confirm_before_reply( $allowed, $user_id, $thread_id ){
            if( ! $allowed || ! $this->awaits_confirmation( $user_id ) ){
                return $allowed;
            }

            global $bp_better_messages_restrict_send_message;
            $bp_better_messages_restrict_send_message['guest_email_unconfirmed'] = _x( 'Please confirm your email to send messages. Open the link that was sent to it.', 'Guest email', 'bp-better-messages' );

            return false;
        }

        public function guest_display_name( $retval, $user_id )
        {
            $guest_user = $this->get_guest_user( $user_id );

            if( $guest_user ){
                return $guest_user->name;
            }

            return $retval;
        }


        private function preferences( int $user_id ){
            $functions = Better_Messages()->functions;
            $sounds    = Better_Messages()->settings['allowSoundDisable'] === '1';

            return array(
                'enableSound'        => $sounds && $functions->get_user_meta( $user_id, 'bpbm_disable_sound_notification', true ) === 'yes' ? '0' : '1',
                'enableSentSound'    => $sounds && $functions->get_user_meta( $user_id, 'bm_disable_sent_sound', true ) === 'yes' ? '0' : '1',
                'onSiteNotification' => $functions->is_onsite_notification_enabled( $user_id ) ? '1' : '0',
                'disableEnter'       => $functions->is_enter_to_send_enabled( $user_id ) ? '0' : '1',
            );
        }

        public function guest_access_enabled(){
            return Better_Messages()->settings['guestChat'] === '1';
        }

        public function page_access(){
            return $this->guest_access_enabled() || $this->booking_guests_only();
        }

        public function booking_guests_only(){
            if( $this->guest_access_enabled() || ! function_exists( 'Better_Messages_Bookings' ) || ! function_exists( 'wordplus_bookings' ) || ! wordplus_bookings() || ! $this->takes_registry_ids() ){
                return false;
            }

            foreach ( Better_Messages_Bookings()->enabled_plugins() as $slug ) {
                if( null !== wordplus_bookings()->adapter( $slug ) ){
                    return true;
                }
            }

            return false;
        }

        public function on_messages_page(){
            $ids = array();

            foreach ( array( Better_Messages()->settings['chatPage'], $this->guest_chat_page_id() ) as $id ) {
                if( is_numeric( $id ) && (int) $id > 0 ){
                    $ids[] = defined( 'ICL_LANGUAGE_CODE' ) ? (int) apply_filters( 'wpml_object_id', (int) $id, 'page', true, ICL_LANGUAGE_CODE ) : (int) $id;
                }
            }

            return ! empty( $ids ) && is_page( $ids );
        }

        public function login_redirect(){
            if( is_user_logged_in() || ! $this->page_access() || ! $this->on_messages_page() ){
                return '';
            }

            if( function_exists( 'Better_Messages_Bookings' ) && ! empty( $_GET[ Better_Messages_Bookings::OPEN_VAR ] ) ){
                $call = isset( $_GET[ Better_Messages_Bookings::CALL_VAR ] ) ? sanitize_key( wp_unslash( $_GET[ Better_Messages_Bookings::CALL_VAR ] ) ) : '';

                return Better_Messages_Bookings()->login_url( sanitize_text_field( wp_unslash( $_GET[ Better_Messages_Bookings::OPEN_VAR ] ) ), $call );
            }

            $page_id = Better_Messages()->settings['chatPage'];

            if( ! $this->booking_guests_only() || Better_Messages()->settings['redirectUnlogged'] !== '1' || ! is_numeric( $page_id ) || (int) $page_id < 1 || ! is_page( (int) $page_id ) ){
                return '';
            }

            return add_query_arg( 'redirect', urlencode( get_permalink( (int) $page_id ) ), apply_filters( 'better_messages_login_url', wp_login_url( remove_query_arg( array( self::HANDOVER_VAR, self::LINK_RESULT_VAR ) ) ) ) );
        }

        public function login_redirect_script(){
            $url = $this->login_redirect();

            if( '' === $url ){
                return;
            }

            wp_print_inline_script_tag( '(function(u){try{var m=location.search.match(/[?&]' . self::HANDOVER_VAR . '=([^&]*)/);if(m){window.bmGuestHandover=decodeURIComponent(m[1]);history.replaceState(history.state,"",location.pathname+location.search.replace(/([?&])' . self::HANDOVER_VAR . '=[^&]*&?/,"$1").replace(/[?&]$/,"")+location.hash);return;}if(window.localStorage.getItem("bm-guest-auth"))return;location.replace(u);}catch(e){}})(' . wp_json_encode( $url ) . ');' );
        }

        public function handover_headers(){
            if( ! isset( $_GET[ self::HANDOVER_VAR ] ) || headers_sent() ){
                return;
            }

            nocache_headers();
            header( 'Referrer-Policy: same-origin' );
        }

        public function embedded_for_visitors( string $html ){
            if( is_user_logged_in() || ! $this->booking_guests_only() ){
                return $html;
            }

            Better_Messages()->enqueue_js();
            Better_Messages()->enqueue_css();

            return $html . '<noscript>' . Better_Messages()->functions->render_login_form() . '</noscript>';
        }

        public function handover_url( int $guest_id, string $url ){
            $id = abs( $guest_id );

            if( ! $this->get_guest_user( $id ) ){
                return $url;
            }

            $code = bin2hex( random_bytes( 16 ) );

            $kept = Better_Messages()->functions->with_lock( 'guest_handover|' . $id, function () use ( $id, $code ) {
                $meta = $this->stored_meta( $id );

                if( null === $meta ){
                    return false;
                }

                $live   = $this->live_handovers( $meta );
                $live[] = array( 'hash' => hash( 'sha256', $code ), 'expires' => time() + self::HANDOVER_LIFETIME );

                return $this->write_meta( $id, array( 'handover' => array_slice( $live, -1 * self::HANDOVER_KEPT ) ) );
            } );

            return $kept ? add_query_arg( self::HANDOVER_VAR, $id . '.' . $code, $url ) : $url;
        }

        private function live_handovers( array $meta ){
            $live = isset( $meta['handover'] ) && is_array( $meta['handover'] ) ? $meta['handover'] : array();

            return array_values( array_filter( $live, function ( $entry ) {
                return is_array( $entry ) && isset( $entry['hash'] ) && (int) ( $entry['expires'] ?? 0 ) >= time();
            } ) );
        }

        public function rest_handover( WP_REST_Request $request ){
            $refused = new WP_Error( 'bm_guest_handover_refused', _x( 'This sign-in is no longer valid. Please open the link again.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 403 ) );
            $parts   = explode( '.', (string) $request->get_param( 'code' ) );

            if( count( $parts ) !== 2 || ! ctype_digit( $parts[0] ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $parts[1] ) ){
                return $refused;
            }

            $id   = (int) $parts[0];
            $hash = hash( 'sha256', $parts[1] );
            $lock = Better_Messages()->functions->lock( 'guest_handover|' . $id );

            if( ! $lock ){
                return $refused;
            }

            try {
                $meta  = $this->stored_meta( $id );
                $taken = false;

                if( is_array( $meta ) && isset( $meta['handover'] ) && is_array( $meta['handover'] ) ){
                    $left = array();

                    foreach ( $meta['handover'] as $entry ) {
                        if( is_array( $entry ) && isset( $entry['hash'] ) && hash_equals( (string) $entry['hash'], $hash ) ){
                            $taken = (int) ( $entry['expires'] ?? 0 ) >= time();
                            continue;
                        }

                        $left[] = $entry;
                    }

                    $left = $this->live_handovers( array( 'handover' => $left ) );

                    $this->write_meta( $id, array( 'handover' => empty( $left ) ? null : $left ) );
                }
            } finally {
                Better_Messages()->functions->unlock( $lock );
            }

            $guest = $taken ? $this->get_guest_user( $id ) : false;

            if( ! $guest || '' === (string) $guest->secret || $this->guest_bot_id( $guest ) > 0 ){
                return $refused;
            }

            if( ! is_user_logged_in() ){
                $this->current_guest_id = $id;
            }

            $response = rest_ensure_response( $this->registration_answer( $id, (string) $guest->secret, (string) $guest->name, (string) $guest->email ) );

            foreach ( wp_get_nocache_headers() as $header => $value ) {
                if( ! empty( $value ) ){
                    $response->header( $header, $value );
                }
            }

            return $response;
        }

        public function expire_old_cookies(){
            if( headers_sent() ){
                return;
            }

            $path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
            $domain = defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';

            foreach ( array( 'bm_booking_guest' => true, 'bm-guest-auth' => false ) as $cookie => $http_only ) {
                if( ! isset( $_COOKIE[ $cookie ] ) ){
                    continue;
                }

                setcookie( $cookie, '', array(
                    'expires'  => time() - DAY_IN_SECONDS,
                    'path'     => $path,
                    'domain'   => $domain,
                    'secure'   => is_ssl(),
                    'httponly' => $http_only,
                    'samesite' => 'Lax',
                ) );
            }
        }

        public function guest_for_email( string $email, string $name = '' ){
            $email    = strtolower( trim( $email ) );
            $bookings = function_exists( 'wordplus_bookings' ) ? wordplus_bookings() : null;

            if( ! is_email( $email ) || ! $bookings || ! $this->takes_registry_ids() ){
                return 0;
            }

            return (int) Better_Messages()->functions->with_lock( 'booking_guest|' . $email, function () use ( $bookings, $email, $name ) {
                global $wpdb;

                for( $attempt = 0; $attempt < 3; $attempt++ ){
                    $visitor = $bookings->visitor_for_email( $email );

                    if( is_wp_error( $visitor ) ){
                        return 0;
                    }

                    $id     = abs( (int) $visitor['id'] );
                    $dot    = strpos( (string) $visitor['token'], '.' );
                    $secret = false === $dot ? '' : substr( (string) $visitor['token'], $dot + 1 );
                    $row    = $wpdb->get_row( $wpdb->prepare( "SELECT `id`, `secret`, `bot_id`, `deleted_at` FROM `{$this->table}` WHERE `id` = %d", $id ) );

                    if( ! $row ){
                        $inserted = $wpdb->insert( $this->table, Better_Messages()->encryption->seal_guest_row( array_merge( array(
                            'id'     => $id,
                            'secret' => $secret,
                            'name'    => $this->booking_guest_name( $name, $email ),
                            'email'   => $email,
                            'ip'      => '',
                            'meta'    => wp_json_encode( array( 'email_from' => 'booking' ) ),
                        ), $this->blog_fields() ), $id ) );

                        if( ! $inserted ){
                            return 0;
                        }

                        do_action( 'better_messages_guest_registered', $id );

                        return -1 * $id;
                    }

                    if( null === $row->deleted_at && (int) $row->bot_id === 0 && hash_equals( (string) $row->secret, $secret ) ){
                        return -1 * $id;
                    }

                    $bookings->forget_visitor_of_email( $email );

                    if( null === $row->deleted_at ){
                        $this->skip_taken_numbers( $this->registry(), $id );
                    }
                }

                return 0;
            } );
        }

        private function booking_guest_name( string $name, string $email ){
            $name = trim( wp_strip_all_tags( $name ) );

            if( '' !== $name ){
                return $name;
            }

            $at = strpos( $email, '@' );

            return false === $at ? $email : substr( $email, 0, $at );
        }

        public function chat_page_is_public_wp_page(){
            $chat_page = Better_Messages()->settings['chatPage'];
            return is_numeric( $chat_page ) && (int) $chat_page > 0;
        }

        public function guest_chat_page_id(){
            if ( ! $this->page_access() ) return 0;
            if ( $this->chat_page_is_public_wp_page() ) return 0;
            $id = (int) Better_Messages()->settings['guestChatPage'];
            return $id > 0 ? $id : 0;
        }

        public function guest_has_entry_point(){
            if ( ! $this->guest_access_enabled() ) return false;
            if ( $this->chat_page_is_public_wp_page() ) return true;
            if ( $this->guest_chat_page_id() ) return true;
            return Better_Messages()->settings['miniChatsEnable'] === '1';
        }

        public function guest_user_id( $user_id ){
            if( is_user_logged_in() ){
                return $user_id;
            }

            if( $this->current_guest_id ){
                return -1 * abs( $this->current_guest_id );
            }

            return $user_id;
        }

        public function check_guest_auth(){
            if( ! isset( $_SERVER['HTTP_BM_GUEST_ID'] ) || ! isset( $_SERVER['HTTP_BM_GUEST_SECRET'] ) ){
                return false;
            }

            $guest_id     = intval( $_SERVER['HTTP_BM_GUEST_ID'] );
            $guest_secret = strval( $_SERVER['HTTP_BM_GUEST_SECRET'] );

            $guest = $this->get_guest_user( $guest_id );

            if( $guest ) {
                if ( ! empty( $guest_secret ) && ! empty( $guest->secret ) && $guest_secret === $guest->secret) {
                    $this->current_guest_id = $guest_id;
                    $this->update_last_active( $guest_id );
                    $this->seen_address( $guest );
                    return true;
                } else {
                    $this->guest_authorization_failed = true;
                    $this->guest_authorization_failed_reason = 'secret_key_mismatch';

                    return false;
                }
            } else {
                $this->guest_authorization_failed = true;
                $this->guest_authorization_failed_reason = 'not_found';

                return false;
            }
        }

        private function seen_address( $guest ){
            if( ! function_exists( 'wordplus_addresses' ) || $this->guest_bot_id( $guest ) > 0 ){
                return;
            }

            $ip = $this->get_client_ip();

            if( '' === $ip ){
                return;
            }

            $meta = json_decode( (string) $guest->meta, true );
            $geo  = function_exists( 'wordplus_geo' ) ? wordplus_geo() : null;

            if( ! $geo || ! $geo->keeps_addresses() ){
                if( is_array( $meta ) && isset( $meta['address_seen'] ) ){
                    $this->write_meta( (int) $guest->id, array( 'address_seen' => null ) );
                }

                return;
            }

            $last = is_array( $meta ) && isset( $meta['address_seen'] ) ? explode( '|', (string) $meta['address_seen'], 2 ) : array( '', 0 );

            if( $last[0] === $ip && (int) ( $last[1] ?? 0 ) > time() - HOUR_IN_SECONDS ){
                return;
            }

            $this->write_meta( (int) $guest->id, array( 'address_seen' => $ip . '|' . time() ) );

            if( wordplus_addresses() ){
                wordplus_addresses()->seen( -1 * abs( (int) $guest->id ), $ip );
            }
        }

        public function update_last_active( int $guest_id ){
            Better_Messages()->users->update_last_activity( -1 * abs( $guest_id ) );
        }

        public function guest_auth($authorized, WP_REST_Request $request ){
            if( is_user_logged_in() ) {
                return $authorized;
            } else if( $this->current_guest_id ) {
                return true;
            } else {
                if( $this->guest_authorization_failed ){
                    $message = _x( 'Guest authorization failed', 'Rest API Error', 'bp-better-messages' );
                    return new WP_Error(
                        'guest_' . $this->guest_authorization_failed_reason,
                        $message,
                        array( 'status' => rest_authorization_required_code() )
                    );
                }

                return $authorized;
            }
        }


        public function is_guest_exists( int $user_id ){
            $user_id = abs( $user_id );

            global $wpdb;

            $query = $wpdb->prepare( "SELECT COUNT(*) FROM `" . bm_get_table('guests') . "` WHERE `id` = %d", $user_id );

            return (bool) $wpdb->get_var($query);
        }

        public function get_guest_user( int $user_id ) {
            $user_id = abs( $user_id );

            $guest_user = wp_cache_get( 'guest_user_' . $user_id, 'bm_messages' );

            if( $guest_user ){
                return Better_Messages()->encryption->open_guest_row( clone $guest_user );
            }

            global $wpdb;

            $query = $wpdb->prepare( "SELECT * FROM `" . bm_get_table('guests') . "` WHERE `id` = %d AND `deleted_at` IS NULL", $user_id );

            $guest_user = $wpdb->get_row( $query );

            if( $guest_user ){
                if( empty( $guest_user->name ) ){
                    $guest_user->name = _x('Anonymous User', 'Not logged-in user', 'bp-better-messages');
                }

                wp_cache_set( 'guest_user_' . $user_id, $guest_user, 'bm_messages' );

                return Better_Messages()->encryption->open_guest_row( clone $guest_user );
            } else {
                return false;
            }
        }

        public function guest_bot_id( $guest_user ){
            if( ! $guest_user || empty( $guest_user->bot_id ) ) return 0;

            return (int) $guest_user->bot_id;
        }

        public function get_bot_id( $user_id ){
            $user_id = (int) $user_id;

            if( $user_id === 0 ) return 0;

            return $this->guest_bot_id( $this->get_guest_user( $user_id ) );
        }

        public function get_all_guest_ids(){

            global $wpdb;

            $ids = (array) $wpdb->get_col( "SELECT id FROM `" . bm_get_table('guests') . "` WHERE `deleted_at` IS NULL" );

            $ids = array_map(function($guest_id): int {
                return -1 * abs( $guest_id );
            }, $ids);


            return $ids;
        }

        public function authenticated_guest_id(){
            return $this->current_guest_id ? -1 * abs( (int) $this->current_guest_id ) : 0;
        }

        public function delete_guest_user( int $user_id, bool $silent = false ){
            global $wpdb;

            $guest_user_id = -1 * abs( $user_id );
            $guest = $this->get_guest_user( $user_id );

            if( $guest ){
                $hashes = Better_Messages_Encryption::widened() ? ", `email_hash` = NULL, `ip_hash` = NULL" : '';

                $wpdb->query( $wpdb->prepare( "UPDATE `" . bm_get_table('guests') . "` SET `deleted_at` = current_timestamp(), `email` = '', `ip` = '', `secret` = ''{$hashes} WHERE `id` = %d", $guest->id ) );
                Better_Messages()->users->update_last_changed( $guest_user_id );

                wp_cache_delete( 'guest_user_' . $guest->id, 'bm_messages' );

                do_action( 'better_messages_revoke_user', $guest_user_id );

                if( function_exists( 'wordplus_profiles' ) && wordplus_profiles() ){
                    wordplus_profiles()->forget( $guest_user_id );
                }

                if( function_exists( 'wordplus_addresses' ) && wordplus_addresses() ){
                    wordplus_addresses()->forget( $guest_user_id );
                }

                $sql = $wpdb->prepare("
                SELECT `threads`.`id`
                FROM `" .  bm_get_table('threads') . "` `threads`
                RIGHT JOIN `" . bm_get_table('recipients') . "` `recipients`
                ON `recipients`.`thread_id` = `threads`.`id`
                    WHERE `threads`.`type` = 'chat-room'
                    AND `recipients`.`user_id` = %d", $guest_user_id);

                $chat_rooms_participant = array_map( 'intval', $wpdb->get_col( $sql ) );

                if( count( $chat_rooms_participant ) > 0 ){
                    foreach( $chat_rooms_participant as $thread_id ){
                        if( $silent ){
                            Better_Messages()->system_messages->suppress_user_left( $thread_id, $guest_user_id );
                        }

                        Better_Messages()->functions->remove_participant_from_thread( $thread_id, $guest_user_id );
                    }
                }

                do_action( 'better_messages_guest_deleted', $user_id );
                do_action( 'better_messages_user_updated', $guest_user_id );

                return true;
            }

            return false;
        }

        public function guest_user_item( $item, $user_id, $include_personal ){
            if( $user_id >= 0 ) {
                return $item;
            }

            $item['url']      = false;
            $item['canBlock'] = 0;
            $item['verified'] = 0;

            if( $include_personal && Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                $item['canVideo'] = 0;
                $item['canAudio'] = 0;
            }

            $item['avatar'] = Better_Messages()->url . 'assets/images/avatar.png';

            $guest_user = $this->get_guest_user( $user_id );

            if( $guest_user ){
                $item['name'] = sanitize_user( $guest_user->name );

                $bot_id = $this->guest_bot_id( $guest_user );

                if( $bot_id > 0 ){
                    $item['is_bot'] = 1;
                    $item['botActive'] = function_exists( 'Better_Messages_AI' ) && Better_Messages_AI()->bot_can_answer( $bot_id ) ? 1 : 0;

                    if( has_post_thumbnail( $bot_id ) ) {
                        $image_id = get_post_thumbnail_id($bot_id);

                        if ($image_id) {
                            $image_src = wp_get_attachment_image_src($image_id, [100, 100]);
                            if ($image_src) {
                                $item['avatar'] = $image_src[0];
                            }
                        }
                    }
                } else {
                    $photo = ( function_exists( 'wordplus_profiles' ) && wordplus_profiles() && Better_Messages()->settings['guestPhotos'] === '1' ) ? wordplus_profiles()->photo( $user_id ) : null;

                    if( $photo ){
                        $item['avatar'] = $photo['small'];
                    } else if( ! empty( $guest_user->email ) ){
                        $avatar = Better_Messages()->functions->avatar_url( get_avatar_url( $guest_user->email ) );
                        if( $avatar ){
                            $item['avatar'] = $avatar;
                        }
                    }
                }
            } else {
                $item['name'] = __('Deleted Guest', 'bp-better-messages');
                $item['is_bot']    = 0;
                $item['botActive'] = 0;
            }

            return $item;
        }

        public function rest_api_init(){
            register_rest_route( 'better-messages/v1', '/guests/register', array(
                'methods' => 'POST',
                'callback' => array( $this, 'register' ),
                'permission_callback' => function() {
                    return ! is_user_logged_in();
                },
            ) );

            register_rest_route( 'better-messages/v1', '/guests/update', array(
                'methods' => 'POST',
                'callback' => array( $this, 'update' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' )
            ) );

            register_rest_route( 'better-messages/v1', '/guests/confirm/resend', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_resend_confirmation' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'is_user_authorized' )
            ) );

            register_rest_route( 'better-messages/v1', '/guests/sign-in-link', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_sign_in_link' ),
                'permission_callback' => function() {
                    return ! is_user_logged_in();
                },
            ) );

            register_rest_route( 'better-messages/v1', '/guests/handover', array(
                'methods' => 'POST',
                'callback' => array( $this, 'rest_handover' ),
                'permission_callback' => '__return_true',
            ) );

            register_rest_route( 'better-messages/v1', '/guests/of-my-email', array(
                array(
                    'methods' => 'GET',
                    'callback' => array( $this, 'rest_guest_of_my_email' ),
                    'permission_callback' => 'is_user_logged_in',
                ),
                array(
                    'methods' => 'POST',
                    'callback' => array( $this, 'rest_mail_move_link' ),
                    'permission_callback' => 'is_user_logged_in',
                ),
            ) );
        }

        const OF_EMAIL_META = 'bm_guest_of_email';

        public function guest_of_member( int $user_id ){
            global $wpdb;

            $user  = $user_id > 0 ? get_userdata( $user_id ) : false;
            $move  = function_exists( 'wordplus_visitor_move' ) ? wordplus_visitor_move() : null;
            $email = $user ? strtolower( trim( (string) $user->user_email ) ) : '';

            if( ! $move || ! is_email( $email ) || Better_Messages()->settings['guestMoveOffer'] !== '1' ){
                return 0;
            }

            $ids = array_map( 'intval', (array) $wpdb->get_col(
                "SELECT `id` FROM `{$this->table}` WHERE " . Better_Messages()->encryption->guest_email_sql( $email ) . " AND `deleted_at` IS NULL AND `bot_id` = 0 AND `secret` <> '' " . $this->blog_sql() . " ORDER BY `id` ASC LIMIT 20"
            ) );

            $registry = $this->takes_registry_ids() ? $this->registry() : null;

            foreach ( $ids as $id ) {
                $guest = $this->get_guest_user( $id );

                if( ! $guest || 'confirmed' !== $this->email_from( $guest ) ){
                    continue;
                }

                $known = $registry && method_exists( $registry, 'store' ) ? $registry->store()->find( $id ) : null;

                if( is_array( $known ) && ! empty( $known['user_id'] ) && (int) $known['user_id'] !== $user_id ){
                    continue;
                }

                if( count( $move->items( -1 * $id, $user_id ) ) > 0 ){
                    return -1 * $id;
                }
            }

            return 0;
        }

        public function guest_of_member_kept( int $user_id ){
            $user = $user_id > 0 ? get_userdata( $user_id ) : false;

            if( ! $user || Better_Messages()->settings['guestMoveOffer'] !== '1' ){
                return 0;
            }

            $mark = md5( strtolower( trim( (string) $user->user_email ) ) );
            $kept = explode( ':', (string) get_user_meta( $user_id, self::OF_EMAIL_META, true ), 2 );

            if( $kept[0] === $mark && isset( $kept[1] ) && 0 === (int) $kept[1] ){
                return 0;
            }

            $guest = $this->guest_of_member( $user_id );

            if( $kept[0] !== $mark || (int) ( $kept[1] ?? 0 ) !== abs( $guest ) ){
                update_user_meta( $user_id, self::OF_EMAIL_META, $mark . ':' . abs( $guest ) );
            }

            return $guest;
        }

        public function rest_guest_of_my_email(){
            $user_id = get_current_user_id();
            $guest   = $this->guest_of_member( $user_id );
            $user    = get_userdata( $user_id );

            return array( 'visitor' => $guest, 'email' => $guest < 0 && $user ? (string) $user->user_email : '' );
        }

        public function rest_mail_move_link(){
            $user_id  = get_current_user_id();
            $guest_id = $this->guest_of_member( $user_id );
            $guest    = $guest_id < 0 ? $this->get_guest_user( $guest_id ) : null;
            $user     = get_userdata( $user_id );

            if( ! $guest || ! $user ){
                return new WP_Error( 'bm_guest_none_to_move', _x( 'There is no guest to move.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 404 ) );
            }

            $meta = $this->get_guest_meta( $guest->id );
            $meta = is_array( $meta ) ? $meta : array();
            $sent = $this->send_limited( $guest, $meta );

            if( is_wp_error( $sent ) ){
                return $sent;
            }

            $this->write_meta( (int) $guest->id, array( 'email_sent' => $sent ) );

            $token  = wordplus_visitor_move()->link( $guest_id, $user_id );
            $mailed = $this->mail(
                (string) $guest->email,
                (string) $user->display_name,
                sprintf( _x( 'Move your guest conversations to your account on %s', 'Guest email', 'bp-better-messages' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
                _x( 'You chatted on this site as a guest with this email address, and you are now signed in to an account with the same email. Open this link while signed in to move your guest conversations to your account or to forget the guest. The link works once and expires in 24 hours. If you did not request it, you can ignore this email.', 'Guest email', 'bp-better-messages' ),
                _x( 'Move my conversations', 'Guest email', 'bp-better-messages' ),
                add_query_arg( self::LINK_RESULT_VAR, 'move-' . $token, $this->messages_page_url() )
            );

            return array( 'sent' => (bool) $mailed );
        }

        public function update( WP_REST_Request $request ){
            global $wpdb;

            $user_id = Better_Messages()->functions->get_current_user_id();

            if( $user_id >= 0 ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $guest_user = $this->get_guest_user( $user_id );

            if( ! $guest_user ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $data          = [];
            $meta_changes  = [];
            $send_confirm  = false;

            $raw_name = (string) $request->get_param('name');

            if( '' !== trim( $raw_name ) && $this->clean_name( $raw_name ) !== (string) $guest_user->name ){
                $name = $this->validate_name( $raw_name, absint( $user_id ) );

                if( is_wp_error( $name ) ){
                    return $name;
                }

                if( '' !== $name ){
                    $data['name'] = $name;
                }
            }

            if( $request->has_param('email') && $this->email_mode() !== 'off' && 'booking' !== $this->email_from( $guest_user ) ){
                $email = $this->validate_email( $request->get_param('email'), $this->email_mode() === 'required' );

                if( is_wp_error( $email ) ){
                    return $email;
                }

                if( $email !== strtolower( (string) $guest_user->email ) ){
                    $data['email'] = $email;
                    $meta_changes  = array( 'email_from' => '' === $email ? null : 'typed', 'email_confirmed_at' => null, 'email_link' => null );
                    $send_confirm  = '' !== $email;
                }
            }

            if( $request->has_param('emailUnread') && $this->email_mailable( $guest_user ) ){
                Better_Messages()->functions->update_user_meta( $user_id, 'bm_email_unread', rest_sanitize_boolean( $request->get_param('emailUnread') ) ? 'yes' : 'no' );
            }

            if( count( $meta_changes ) > 0 ){
                $this->write_meta( absint( $user_id ), $meta_changes );
            }

            if( count( $data ) > 0 ) {
                $result = $wpdb->update( bm_get_table('guests'), Better_Messages()->encryption->seal_guest_row( $data, absint( $user_id ) ), [ 'id' => absint($user_id) ] );
                if ( false !== $result ) {
                    Better_Messages()->users->update_last_changed( absint($user_id) * -1 );
                    wp_cache_delete( 'guest_user_' . absint($user_id), 'bm_messages' );
                    $guest_user = $this->get_guest_user( $user_id );

                    if( isset( $data['name'] ) && function_exists( 'wordplus_profiles' ) && wordplus_profiles() ){
                        wordplus_profiles()->set_name( -1 * absint( $user_id ), $data['name'] );
                    }

                    do_action( 'better_messages_guest_updated', $user_id );
                    do_action( 'better_messages_user_updated', $user_id );
                }
            }

            if( $send_confirm ){
                $this->send_confirmation( absint( $user_id ) );
                $guest_user = $this->get_guest_user( $user_id );
            }

            return $this->registration_answer( (int) $guest_user->id, (string) $guest_user->secret, (string) $guest_user->name, (string) $guest_user->email );
        }

        public function check_if_name_already_used( $name, $guest_id = 0 )
        {
            global $wpdb;
            $sql = $wpdb->prepare("SELECT COUNT(*) FROM `{$this->table}` WHERE `name` LIKE %s AND `id` != %d", $wpdb->esc_like( $name ), absint( $guest_id ) );
            return (bool) $wpdb->get_var( $sql );
        }

        public function is_name_taken( $name, $guest_id = 0 )
        {
            global $wpdb;

            $taken = $this->check_if_name_already_used( $name, $guest_id );

            if( ! $taken ){
                $taken = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM `{$wpdb->users}` WHERE `user_login` = %s OR `display_name` = %s LIMIT 1", $name, $name ) );
            }

            return (bool) apply_filters( 'better_messages_guest_name_taken', $taken, $name, absint( $guest_id ) );
        }

        public function clean_name( $name )
        {
            $name = (string) preg_replace( '/\p{Cf}+/u', '', (string) $name );
            $name = (string) preg_replace( '/[\p{Z}\s]+/u', ' ', $name );

            return sanitize_user( trim( sanitize_text_field( $name ) ) );
        }

        public function names_must_be_unique()
        {
            return Better_Messages()->settings['guestUniqueNames'] === '1';
        }

        public function register( WP_REST_Request $request ){

            global $wpdb;

            $secret = Better_Messages()->functions->random_string( 30 );

            $registerData = $request->has_param('registerData') ? $request->get_param('registerData') : null;

            $guest_access_enabled = $this->guest_access_enabled();

            $is_allowed = apply_filters( 'better_messages_guest_register_allowed', $guest_access_enabled, $registerData );

            if( ! $is_allowed ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $returning = $this->returning_guest();

            if( $returning ){
                return $this->registration_answer( (int) $returning->id, (string) $returning->secret, (string) $returning->name, (string) $returning->email );
            }

            $registerData = is_array( $registerData ) ? $registerData : array();

            if( $this->captcha_asked() ){
                $captcha = wordplus_captcha()->check( (string) ( $registerData['captchaToken'] ?? '' ), 'better-messages:guest-signup' );

                if( is_wp_error( $captcha ) ){
                    return $this->captcha_error( $captcha );
                }
            }

            $limited = Better_Messages()->anti_spam->guest_signup_allowed();

            if( is_wp_error( $limited ) ){
                return $limited;
            }

            $name = $this->validate_name( $registerData['name'] ?? '', 0 );

            if( is_wp_error( $name ) ){
                return $name;
            }

            $email = '';

            if( $this->email_mode() !== 'off' ){
                $email = $this->validate_email( $registerData['email'] ?? '', $this->email_mode() === 'required' );

                if( is_wp_error( $email ) ){
                    return $email;
                }
            }

            $consent = $this->consent_text();

            if( '' !== $consent && empty( $registerData['consent'] ) ){
                return new WP_Error( 'bm_guest_consent_required', _x( 'Please accept the terms to continue.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $meta = array();

            if( '' !== $consent ){
                $meta['bm_consent_at']   = time();
                $meta['bm_consent_text'] = $consent;
            }

            if( '' !== $email ){
                $meta['email_from'] = 'typed';
            }

            if( '' === $name ) {
                $generator = new \BetterMessages\RandomNameGenerator\Alliteration();
                $generated_name = apply_filters('better_messages_generated_guest_name', $generator->getName());

                $name = $generated_name;
                $index = 0;

                while ( $this->check_if_name_already_used( $name ) ){
                    $index++;
                    $name = $generated_name . ' ' . $index;
                }
            }

            $row = array_merge( [
                'secret'  => $secret,
                'name'    => sanitize_user($name),
                'email'   => $email,
                'ip'      => $this->get_client_ip(),
                'meta'    => empty( $meta ) ? '' : wp_json_encode( $meta ),
            ], $this->blog_fields() );

            if( $this->takes_registry_ids() ){
                $credentials = $this->registry_credentials();

                if( is_wp_error( $credentials ) ){
                    return $credentials;
                }

                $row['id']     = $credentials['id'];
                $row['secret'] = $secret = $credentials['secret'];
            }

            $plain = $row;

            if( isset( $row['id'] ) ){
                $row = Better_Messages()->encryption->seal_guest_row( $row, (int) $row['id'] );
            } else if( Better_Messages()->encryption->on() ){
                $row['ip'] = '';
            }

            $result = $wpdb->insert( $this->table, $row );

            if( $result ) {
                $guest_id = isset( $row['id'] ) ? (int) $row['id'] : $wpdb->insert_id;

                if( ! isset( $row['id'] ) && Better_Messages()->encryption->on() && '' !== (string) $plain['ip'] ){
                    $wpdb->update( $this->table, Better_Messages()->encryption->seal_guest_row( array( 'ip' => $plain['ip'] ), $guest_id ), array( 'id' => $guest_id ) );
                }

                Better_Messages()->anti_spam->guest_signed_up();

                do_action( 'better_messages_guest_registered', $guest_id );

                if( '' !== $email ){
                    $this->send_confirmation( $guest_id );
                }

                return $this->registration_answer( $guest_id, $secret, $name, $email );
            }

            return new WP_Error( 'bm_guest_register_failed', _x( 'Sorry, the registration could not be completed. Please try again.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 500 ) );
        }

        public function name_mode(){
            return Better_Messages()->settings['guestNameField'] === 'required' ? 'required' : 'optional';
        }

        public function email_mode(){
            $mode = Better_Messages()->settings['guestEmailField'];

            return in_array( $mode, array( 'optional', 'required' ), true ) ? $mode : 'off';
        }

        public function consent_text(){
            return trim( (string) Better_Messages()->settings['guestConsentText'] );
        }

        public function captcha_asked(){
            return Better_Messages()->settings['guestCaptcha'] === '1' && function_exists( 'wordplus_captcha' ) && wordplus_captcha() && wordplus_captcha()->ready();
        }

        public function confirm_before_chat(){
            return $this->email_mode() !== 'off' && Better_Messages()->settings['guestConfirmBeforeChat'] === '1';
        }

        public function form_config(){
            $captcha = $this->captcha_asked();

            return array(
                'name'       => $this->name_mode(),
                'email'      => $this->email_mode(),
                'confirm'    => $this->confirm_before_chat() ? '1' : '0',
                'consent'    => $this->consent_text(),
                'captcha'    => $captcha ? wordplus_captcha()->client( 'better-messages:guest-signup' ) : null,
                'captchaIn'  => $captcha ? wordplus_captcha()->client( 'better-messages:sign-in-link' ) : null,
                'signInLink' => ( $this->email_mode() !== 'off' || class_exists( 'Better_Messages_Bookings' ) ) ? '1' : '0',
                'photos'     => Better_Messages()->settings['guestPhotos'] === '1' ? '1' : '0',
            );
        }

        private function captcha_error( WP_Error $error ){
            $codes = array(
                'wordplus_captcha_required'    => 'bm_guest_captcha_required',
                'wordplus_captcha_failed'      => 'bm_guest_captcha_failed',
                'wordplus_captcha_unavailable' => 'bm_guest_captcha_unavailable',
            );

            $code = $error->get_error_code();

            return new WP_Error( $codes[ $code ] ?? $code, $error->get_error_message(), $error->get_error_data() );
        }

        public function validate_name( $raw, $exclude_id ){
            $name = $this->clean_name( (string) $raw );

            if( '' === $name ){
                if( $this->name_mode() === 'required' ){
                    return new WP_Error( 'bm_guest_name_required', _x( 'Please type your name.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 400 ) );
                }

                return '';
            }

            if( mb_strlen( $name ) > 100 ){
                return new WP_Error( 'bm_guest_name_too_long', _x( 'The name can be up to 100 characters long.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            if( $this->names_must_be_unique() && $this->is_name_taken( $name, absint( $exclude_id ) ) ){
                return new WP_Error(
                    'bm_guest_name_taken',
                    _x( 'Sorry, such this name already used by someone else. Please try to use other name.', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => 403 )
                );
            }

            return $name;
        }

        public function validate_email( $raw, bool $required ){
            $email = strtolower( trim( (string) $raw ) );

            if( '' === $email ){
                if( $required ){
                    return new WP_Error( 'bm_guest_email_required', _x( 'Please type your email.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 400 ) );
                }

                return '';
            }

            if( strlen( $email ) > 100 || ! is_email( $email ) ){
                return new WP_Error( 'bm_guest_email_invalid', _x( 'Please type a valid email.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 400 ) );
            }

            $email = sanitize_email( $email );

            if( email_exists( $email ) ){
                return new WP_Error( 'bm_guest_email_member', _x( 'This email belongs to an account on this site. Please log in to use it.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 400, 'login' => wp_login_url() ) );
            }

            return $email;
        }

        private function write_meta( int $guest_id, array $changes ){
            global $wpdb;

            $guest_id = abs( $guest_id );
            $meta     = $this->stored_meta( $guest_id );

            if( null === $meta ){
                return false;
            }

            foreach ( $changes as $key => $value ) {
                if( null === $value ){
                    unset( $meta[ $key ] );
                } else {
                    $meta[ $key ] = $value;
                }
            }

            $wpdb->update( $this->table, array( 'meta' => Better_Messages()->encryption->seal_guest( $guest_id, 'meta', wp_json_encode( $meta ) ) ), array( 'id' => $guest_id ), array( '%s' ), array( '%d' ) );
            wp_cache_delete( 'guest_user_' . $guest_id, 'bm_messages' );

            return true;
        }

        public function email_from( $guest ){
            if( ! $guest || '' === (string) $guest->email ){
                return '';
            }

            $meta = json_decode( (string) $guest->meta, true );
            $from = is_array( $meta ) && isset( $meta['email_from'] ) ? (string) $meta['email_from'] : '';

            return in_array( $from, array( 'typed', 'confirmed', 'booking' ), true ) ? $from : 'booking';
        }

        public function email_mailable( $guest ){
            return in_array( $this->email_from( $guest ), array( 'confirmed', 'booking' ), true );
        }

        public function messages_page_url(){
            foreach ( array( 'chatPage', 'guestChatPage' ) as $setting ) {
                $page = Better_Messages()->settings[ $setting ] ?? 0;

                if ( is_numeric( $page ) && (int) $page > 0 && 'publish' === get_post_status( (int) $page ) ) {
                    return (string) get_permalink( (int) $page );
                }
            }

            return home_url( '/' );
        }

        private function link_url( int $guest_id, string $token ){
            return add_query_arg( self::LINK_QUERY_VAR, abs( $guest_id ) . '.' . $token, home_url( '/' ) );
        }

        private function send_limited( $guest, array $meta ){
            $sent = isset( $meta['email_sent'] ) && is_array( $meta['email_sent'] ) ? $meta['email_sent'] : array();
            $sent = array_values( array_filter( array_map( 'intval', $sent ), function ( $time ) {
                return $time > time() - HOUR_IN_SECONDS;
            } ) );

            if( count( $sent ) >= 3 ){
                return new WP_Error( 'bm_guest_too_many_emails', _x( 'Too many emails were sent. Please try again later.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 429 ) );
            }

            $daily = 'bm_guest_mail_' . md5( strtolower( (string) $guest->email ) );

            if( (int) get_transient( $daily ) >= 5 ){
                return new WP_Error( 'bm_guest_too_many_emails', _x( 'Too many emails were sent. Please try again later.', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 429 ) );
            }

            set_transient( $daily, (int) get_transient( $daily ) + 1, DAY_IN_SECONDS );

            $sent[] = time();

            return $sent;
        }

        public function send_confirmation( int $guest_id ){
            $guest = $this->get_guest_user( $guest_id );

            if( ! $guest || '' === (string) $guest->email || 'typed' !== $this->email_from( $guest ) ){
                return false;
            }

            $meta = $this->get_guest_meta( $guest->id );
            $meta = is_array( $meta ) ? $meta : array();
            $sent = $this->send_limited( $guest, $meta );

            if( is_wp_error( $sent ) ){
                return $sent;
            }

            $token = bin2hex( random_bytes( 16 ) );

            $this->write_meta( (int) $guest->id, array(
                'email_link' => array( 'hash' => hash( 'sha256', $token ), 'kind' => 'confirm', 'email' => strtolower( (string) $guest->email ), 'expires' => time() + DAY_IN_SECONDS ),
                'email_sent' => $sent,
            ) );

            return $this->mail(
                (string) $guest->email,
                (string) $guest->name,
                sprintf( _x( 'Confirm your email for %s', 'Guest email', 'bp-better-messages' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
                _x( 'Please confirm that this is your email address. After that you will get an email when someone replies while you are away, and you will be able to sign in again from any browser.', 'Guest email', 'bp-better-messages' ),
                _x( 'Confirm my email', 'Guest email', 'bp-better-messages' ),
                $this->link_url( (int) $guest->id, $token )
            );
        }

        public function send_sign_in_link( string $email ){
            global $wpdb;

            $email = strtolower( trim( $email ) );

            if( ! is_email( $email ) ){
                return true;
            }

            $ids = array_map( 'intval', (array) $wpdb->get_col(
                "SELECT `id` FROM `{$this->table}` WHERE " . Better_Messages()->encryption->guest_email_sql( $email ) . " AND `deleted_at` IS NULL AND `bot_id` = 0 AND `secret` <> '' " . $this->blog_sql() . " ORDER BY `id` ASC LIMIT 20"
            ) );

            foreach ( $ids as $id ) {
                $guest = $this->get_guest_user( $id );

                if( ! $guest || ! $this->email_mailable( $guest ) ){
                    continue;
                }

                $meta = $this->get_guest_meta( $guest->id );
                $meta = is_array( $meta ) ? $meta : array();
                $sent = $this->send_limited( $guest, $meta );

                if( is_wp_error( $sent ) ){
                    return true;
                }

                $token = bin2hex( random_bytes( 16 ) );

                $this->write_meta( (int) $guest->id, array(
                    'sign_in_link' => array( 'hash' => hash( 'sha256', $token ), 'expires' => time() + 30 * MINUTE_IN_SECONDS ),
                    'email_sent'   => $sent,
                ) );

                $this->mail(
                    (string) $guest->email,
                    (string) $guest->name,
                    sprintf( _x( 'Your sign-in link for %s', 'Guest email', 'bp-better-messages' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
                    _x( 'Use this link to sign in and open your conversations again. The link works once and expires in 30 minutes. If you did not request it, you can ignore this email.', 'Guest email', 'bp-better-messages' ),
                    _x( 'Sign in', 'Guest email', 'bp-better-messages' ),
                    $this->link_url( (int) $guest->id, $token )
                );

                return true;
            }

            return true;
        }

        private function mail( string $email, string $name, string $subject, string $text, string $button, string $url ){
            $notifications = Better_Messages()->notifications;

            $content = $notifications->email_content( array(
                'user_name'       => $name,
                'subject'         => $subject,
                'email_subject'   => $subject,
                'heading'         => $subject,
                'text'            => $text,
                'messages_html'   => '<p style="font-family: sans-serif; font-size: 14px; margin: 0 0 15px;">' . esc_html( $text ) . '</p>',
                'thread_url'      => $url,
                'button_text'     => $button,
                'unsubscribe_url' => '',
            ) );

            return (bool) $notifications->send_email( $email, $subject, $content );
        }

        private $blog_column = null;

        public function blog_column(){
            if( ! is_multisite() ){
                return false;
            }

            if( null === $this->blog_column ){
                global $wpdb;

                $this->blog_column = ! empty( $wpdb->get_var( "SHOW COLUMNS FROM `{$this->table}` LIKE 'blog_id'" ) );
            }

            return $this->blog_column;
        }

        private function blog_fields(){
            return $this->blog_column() ? array( 'blog_id' => get_current_blog_id() ) : array();
        }

        public function blog_sql( string $alias = '' ){
            if( ! $this->blog_column() ){
                return '';
            }

            global $wpdb;

            $column = '' === $alias ? '`blog_id`' : '`' . $alias . '`.`blog_id`';

            return $wpdb->prepare( " AND {$column} IN ( 0, %d )", get_current_blog_id() );
        }

        public function link_landing(){
            if( empty( $_GET[ self::LINK_QUERY_VAR ] ) ){
                return;
            }

            nocache_headers();

            $raw    = sanitize_text_field( wp_unslash( $_GET[ self::LINK_QUERY_VAR ] ) );
            $page   = $this->messages_page_url();
            $parts  = explode( '.', $raw );
            $result = 'expired';

            if( count( $parts ) === 2 && ctype_digit( $parts[0] ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $parts[1] ) ){
                $guest  = $this->get_guest_user( (int) $parts[0] );
                $result = $guest ? $this->take_link( $guest, $parts[1] ) : 'expired';
            }

            $url = add_query_arg( self::LINK_RESULT_VAR, $result, $page );

            wp_safe_redirect( $this->handed_guest > 0 ? $this->handover_url( $this->handed_guest, $url ) : $url );
            exit;
        }

        private function take_link( $guest, string $token ){
            global $wpdb;

            $meta = json_decode( (string) $guest->meta, true );
            $meta = is_array( $meta ) ? $meta : array();
            $hash = hash( 'sha256', $token );

            $confirm = $meta['email_link'] ?? null;

            if( is_array( $confirm ) && hash_equals( (string) $confirm['hash'], $hash ) ){
                if( (int) $confirm['expires'] < time() || strtolower( (string) $guest->email ) !== (string) $confirm['email'] ){
                    return 'expired';
                }

                $other = 0;
                $same  = array_map( 'intval', (array) $wpdb->get_col(
                    "SELECT `id` FROM `{$this->table}` WHERE " . Better_Messages()->encryption->guest_email_sql( (string) $guest->email ) . " AND `id` <> " . (int) $guest->id . " AND `deleted_at` IS NULL AND `bot_id` = 0 AND `secret` <> '' " . $this->blog_sql() . " ORDER BY `id` ASC LIMIT 20"
                ) );

                foreach( $same as $candidate ){
                    if( 'confirmed' === $this->email_from( $this->get_guest_user( $candidate ) ) ){
                        $other = $candidate;
                        break;
                    }
                }

                if( $other > 0 && ! is_user_logged_in() ){
                    Better_Messages_Profiles()->move_person( -1 * (int) $guest->id, -1 * $other );
                    $this->delete_guest_user( (int) $guest->id, true );
                    $this->handed_guest = $other;

                    return 'merged';
                }

                $this->write_meta( (int) $guest->id, array( 'email_from' => 'confirmed', 'email_confirmed_at' => time(), 'email_link' => null ) );
                Better_Messages()->users->update_last_changed( -1 * (int) $guest->id );

                if( ! is_user_logged_in() ){
                    $this->handed_guest = (int) $guest->id;
                }

                return 'confirmed';
            }

            $sign_in = $meta['sign_in_link'] ?? null;

            if( is_array( $sign_in ) && hash_equals( (string) $sign_in['hash'], $hash ) ){
                $this->write_meta( (int) $guest->id, array( 'sign_in_link' => null ) );

                if( (int) $sign_in['expires'] < time() ){
                    return 'expired';
                }

                if( is_user_logged_in() ){
                    $move = function_exists( 'wordplus_visitor_move' ) ? wordplus_visitor_move() : null;

                    return $move ? 'move-' . $move->link( -1 * (int) $guest->id, get_current_user_id() ) : 'expired';
                }

                $this->handed_guest = (int) $guest->id;

                return 'signed-in';
            }

            return 'expired';
        }

        public function rest_resend_confirmation(){
            $user_id = Better_Messages()->functions->get_current_user_id();

            if( $user_id >= 0 ){
                return new WP_Error( 'rest_forbidden', _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ), array( 'status' => rest_authorization_required_code() ) );
            }

            $sent = $this->send_confirmation( $user_id );

            if( is_wp_error( $sent ) ){
                return $sent;
            }

            return array( 'sent' => (bool) $sent );
        }

        public function rest_sign_in_link( WP_REST_Request $request ){
            if( $this->captcha_asked() ){
                $captcha = wordplus_captcha()->check( (string) $request->get_param( 'captchaToken' ), 'better-messages:sign-in-link' );

                if( is_wp_error( $captcha ) ){
                    return $this->captcha_error( $captcha );
                }
            }

            $this->send_sign_in_link( (string) $request->get_param( 'email' ) );

            return array( 'sent' => true );
        }

        private function registration_answer( int $guest_id, string $secret, string $name, string $email ){
            $user_item = Better_Messages()->functions->rest_user_item( -$guest_id );

            $return = [
                'id'          => $guest_id,
                'secret'      => $secret,
                'name'        => $name,
                'email'       => $email,
                'emailState'  => $this->email_from( $this->get_guest_user( $guest_id ) ),
                'emailUnread' => Better_Messages()->functions->get_user_meta( -$guest_id, 'bm_email_unread', true ) === 'no' ? '0' : '1',
                'user'        => $user_item,
                'cjn'    => ( Better_Messages()->functions->get_user_meta( -$guest_id, 'bm_call_join_notice', true ) === 'no' ) ? '0' : '1',
                'crn'    => ( Better_Messages()->functions->get_user_meta( -$guest_id, 'bm_call_recording_notice', true ) === 'no' ) ? '0' : '1',
                'prefs'  => $this->preferences( -$guest_id ),
            ];

            if ( Better_Messages()->websocket ) {
                $ws_profile = Better_Messages()->functions->build_ws_profile( -$guest_id, $user_item );
                $return['pd']  = $ws_profile['pd'];
                $return['pdh'] = $ws_profile['pdh'];
                $return['pds'] = $ws_profile['pds'];
                $return['role_hashes']     = $ws_profile['role_hashes'];
                $return['role_hashes_sig'] = $ws_profile['role_hashes_sig'];
                $return['gk'] = Better_Messages_WebSocket()->user_socket_key( -$guest_id );
                $return['ak'] = Better_Messages_WebSocket()->user_socket_key( 0 );
            }

            return $return;
        }

        private function returning_guest(){
            $registry = $this->takes_registry_ids() ? $this->registry() : null;
            $known    = $registry ? (int) $registry->current() : 0;

            if( $known >= 0 ){
                return null;
            }

            $token = (string) $registry->token();
            $dot   = strpos( $token, '.' );
            $guest = $this->get_guest_user( $known );

            if( false === $dot || ! $guest || $this->guest_bot_id( $guest ) > 0 || '' === (string) $guest->secret ){
                return null;
            }

            return hash_equals( (string) $guest->secret, substr( $token, $dot + 1 ) ) ? $guest : null;
        }

        public function takes_registry_ids(){
            return (float) get_option( 'better_messages_2_db_version', 0 ) >= self::REGISTRY_DB_VERSION;
        }

        public function registry(){
            $visitors = function_exists( 'wordplus_visitors' ) ? wordplus_visitors() : null;

            return ( $visitors && method_exists( $visitors, 'adopt_many' ) ) ? $visitors : null;
        }

        private function registry_credentials(){
            $registry = $this->registry();
            $failed   = new WP_Error( 'guest_registry', _x( 'Something went wrong', 'Rest API Error', 'bp-better-messages' ), array( 'status' => 503 ) );

            if( ! $registry ){
                return $failed;
            }

            $known = (int) $registry->current();
            $token = '';

            if( $known < 0 && ! $this->is_guest_exists( $known ) ){
                $token = (string) $registry->token();
            } else {
                for( $attempt = 0; $attempt < 3 && '' === $token; $attempt++ ){
                    $made = $registry->create();

                    if( is_wp_error( $made ) ){
                        return $failed;
                    }

                    if( ! $this->is_guest_exists( (int) $made['id'] ) ){
                        $known = (int) $made['id'];
                        $token = (string) $made['token'];
                    } else {
                        $this->skip_taken_numbers( $registry, (int) $made['id'] );
                    }
                }
            }

            $dot = strpos( $token, '.' );

            if( $known >= 0 || false === $dot ){
                return $failed;
            }

            return array( 'id' => abs( $known ), 'secret' => substr( $token, $dot + 1 ) );
        }

        public function registry_bot_id(){
            $registry = $this->registry();

            for( $attempt = 0; $registry && $attempt < 3; $attempt++ ){
                $made = $registry->create( 'bot' );

                if( ! is_array( $made ) || (int) $made['id'] >= 0 ){
                    return 0;
                }

                if( ! $this->is_guest_exists( (int) $made['id'] ) ){
                    return abs( (int) $made['id'] );
                }

                $this->skip_taken_numbers( $registry, (int) $made['id'] );
            }

            return 0;
        }

        private function skip_taken_numbers( $registry, $taken_id ){
            global $wpdb;

            if( ! method_exists( $registry, 'skip_past' ) ){
                return;
            }

            $highest = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT MAX(`id`) FROM `{$this->table}` WHERE `id` >= %d AND `id` < %d",
                abs( (int) $taken_id ),
                self::COUNTER_FLOOR - 1
            ) );

            if( $highest > 0 ){
                $registry->skip_past( $highest );
            }
        }

        public function adopt_into_registry(){
            $done_to = get_option( self::REGISTRY_OPTION, '0' );

            if( 'done' === $done_to || ! $this->takes_registry_ids() ){
                return;
            }

            $registry = $this->registry();

            if( ! $registry ){
                return;
            }

            global $wpdb;

            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT `id`, `secret`, `bot_id` FROM `{$this->table}` WHERE `id` > %d AND `id` < %d AND `deleted_at` IS NULL ORDER BY `id` ASC LIMIT %d",
                (int) $done_to,
                (int) WordPlus_SDK_Visitors::START,
                self::ADOPT_BATCH
            ) );

            if( empty( $rows ) ){
                update_option( self::REGISTRY_OPTION, 'done', false );
                return;
            }

            $guests = array();

            foreach( $rows as $row ){
                $guests[] = array(
                    'id'     => (int) $row->id,
                    'secret' => (string) $row->secret,
                    'kind'   => (int) $row->bot_id > 0 ? 'bot' : 'visitor',
                );
            }

            $registry->adopt_many( $guests );

            if( count( $rows ) < self::ADOPT_BATCH ){
                update_option( self::REGISTRY_OPTION, 'done', false );
                return;
            }

            update_option( self::REGISTRY_OPTION, (string) (int) end( $rows )->id, false );
            Better_Messages_Worker()->run_soon( 'better_messages_guests_registry_job' );
        }

        public function registry_tasks(){
            if( function_exists( 'Better_Messages_Worker' ) ){
                Better_Messages_Worker()->add_task( 'better_messages_guests_registry_job', 5 * MINUTE_IN_SECONDS );
            }
        }

        public function load_scripts()
        {
            if( is_user_logged_in() ) return;

        }

        public function html_element(){
            if( is_user_logged_in() ) return;
            echo '<div id="bm-support-chat-container"></div>';
        }

        public function register_new_guest(){

        }

        private function stored_meta( $guest_id ){
            global $wpdb;

            $meta = $wpdb->get_var($wpdb->prepare( "SELECT meta FROM {$this->table} WHERE id = %d", $guest_id ));

            if( Better_Messages_Encryption::is_sealed( $meta ) ){
                $meta = Better_Messages()->encryption->open_guest( $guest_id, 'meta', $meta );

                if( '' === $meta ){
                    return null;
                }
            }

            $result = $meta ? json_decode( $meta, true ) : [];

            return is_array( $result ) ? $result : [];
        }

        public function get_guest_meta( $guest_id, $meta_key = false ){
            $guest_id = abs( $guest_id );

            $result = $this->stored_meta( $guest_id );

            if( null === $result ){
                $result = [];
            }

            if( $meta_key ) {
                return $result[$meta_key] ?? null;
            }

            return $result;
        }

        public function update_guest_meta( $guest_id, $meta_key, $meta_value ){
            global $wpdb;
            $guest_id = abs( $guest_id );
            $meta = $this->stored_meta( $guest_id );

            if( null === $meta ){
                return false;
            }

            if( isset( $meta[$meta_key] ) && $meta[$meta_key] === $meta_value ){
                return true;
            }

            $meta[$meta_key] = $meta_value;

            $result = $wpdb->update( $this->table, ['meta' => Better_Messages()->encryption->seal_guest( $guest_id, 'meta', json_encode( $meta ) ) ], [ 'id' => $guest_id ], ['%s'], ['%d'] );

            if( $result ){
                if( Better_Messages()->websocket ){
                    Better_Messages()->websocket->on_usermeta_update(0, $guest_id  * -1, $meta_key, $meta_value);
                }
            }

            return $result;
        }

        public function delete_guest_meta( $guest_id, $meta_key ){
            global $wpdb;
            $guest_id = abs( $guest_id );
            $meta = $this->stored_meta( $guest_id );

            if( null === $meta ){
                return false;
            }

            if( isset( $meta[$meta_key] ) ){
                unset( $meta[$meta_key] );
                $wpdb->update( $this->table, ['meta' => Better_Messages()->encryption->seal_guest( $guest_id, 'meta', json_encode( $meta ) ) ], [ 'id' => $guest_id ], ['%s'], ['%d'] );
            }

            return true;
        }

        public function get_client_ip(){
            if( class_exists( 'WordPlus_SDK' ) && '' !== WordPlus_SDK::instance()->path() && is_readable( WordPlus_SDK::instance()->path( 'src/class-client.php' ) ) ){
                require_once WordPlus_SDK::instance()->path( 'src/class-client.php' );

                return WordPlus_SDK_Client::ip();
            }

            $sources = [ 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];

            foreach( $sources as $source ){
                if( empty( $_SERVER[ $source ] ) ) continue;

                $ip = trim( (string) $_SERVER[ $source ] );

                if( str_contains( $ip, ',' ) ) continue;

                if( filter_var( $ip, FILTER_VALIDATE_IP ) !== false ){
                    return $ip;
                }
            }

            return '';
        }
    }

endif;

function Better_Messages_Guests(){
    return Better_Messages_Guests::instance();
}
