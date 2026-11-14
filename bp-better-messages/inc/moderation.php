<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Moderation' ) ):

    class Better_Messages_Moderation
    {
        const HELD_DIGEST = 'better_messages_held_digest';

        private $site_admin_ids = null;

        private $messages_to_review = null;

        private $review_signal_queued = false;

        private $ids_under_review = null;

        private $restricted_batch = array();

        private $own_batch = array( 'user' => 0, 'threads' => array() );

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Moderation();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action('better_messages_cleaner_job', array( $this, 'clean_expired_bans') );
            add_filter('better_messages_can_send_message', array( $this, 'can_send_reply' ), 20, 3 );
            add_filter('better_messages_chat_user_can_join', array( $this, 'restrict_join' ), 10, 4 );
            add_filter('better_messages_group_call_join_custom_error', array( $this, 'restrict_group_call_join' ), 10, 3 );
            add_filter('better_messages_rest_thread_item', array( $this, 'thread_item_restrictions' ), 20, 5 );
            add_action('rest_api_init',  array( $this, 'rest_api_init' ) );
            add_action('better_messages_clean_expired_ban', array( $this, 'clean_expired_ban'), 10, 2 );
            add_action('better_messages_message_sent', array( $this, 'mark_user_as_approved_sender' ), 10, 1 );
            add_action('better_messages_message_pending', array( $this, 'notify_pending_message' ), 10, 1 );
            add_action('init', array( $this, 'schedule_held_digest' ), 20 );
            add_action('better_messages_held_digest', array( $this, 'send_held_digest' ), 10, 0 );
            add_action('better_messages_message_reported', array( $this, 'notify_reported_message' ), 10, 6 );

            foreach( array( 'set_user_role', 'add_user_role', 'remove_user_role', 'deleted_user', 'granted_super_admin', 'revoked_super_admin', 'bp_better_chat_settings_updated' ) as $hook ){
                add_action( $hook, array( $this, 'forget_site_admins' ), 10, 0 );
            }

            add_action( 'show_user_profile', array( $this, 'render_user_section' ) );
            add_action( 'edit_user_profile', array( $this, 'render_user_section' ) );
            add_filter( 'wordplus_addresses_profile_box', array( $this, 'addresses_in_user_section' ) );

            add_filter( 'bp_better_messages_script_variable', array( $this, 'review_script_variables' ), 1000 );

            foreach( array( 'added_bm_message_meta', 'updated_bm_message_meta', 'deleted_bm_message_meta' ) as $hook ){
                add_action( $hook, array( $this, 'review_meta_changed' ), 10, 4 );
            }

            add_action( 'better_messages_message_pending', array( $this, 'review_queue_changed' ), 20, 0 );
            add_action( 'better_messages_before_message_delete', array( $this, 'review_message_deleting' ), 10, 1 );
        }

        public function can_review_messages(): bool
        {
            return current_user_can( 'bm_can_administrate' )
                && ! defined( 'BM_DISABLE_MESSAGES_VIEWER' )
                && Better_Messages()->settings['messagesViewer'] !== '0';
        }

        public function get_messages_to_review(): ?array
        {
            if ( ! $this->can_review_messages() || $this->is_mobile_app_request() ) {
                return null;
            }

            if ( $this->messages_to_review === null ) {
                $this->messages_to_review = array(
                    'reported' => function_exists( 'Better_Messages_Rest_Api_Admin' ) ? (int) Better_Messages_Rest_Api_Admin()->get_reported_count() : 0,
                    'pending'  => Better_Messages()->functions->get_pending_messages_count(),
                );
            }

            return $this->messages_to_review;
        }

        public function review_script_variables( $vars )
        {
            if ( ! empty( $vars['designPreview'] ) || ! empty( $vars['assistantMode'] ) ) {
                return $vars;
            }

            $to_review = $this->get_messages_to_review();

            if ( $to_review !== null ) {
                $vars['toReview'] = array_merge( $to_review, array(
                    'url' => admin_url( 'admin.php?page=better-messages-viewer' ),
                ) );
            }

            return $vars;
        }

        public function messages_to_review_api( WP_REST_Request $request )
        {
            $to_review = $this->get_messages_to_review();

            if ( $to_review === null ) {
                return new WP_Error( 'rest_forbidden', _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ), array( 'status' => rest_authorization_required_code() ) );
            }

            return $to_review;
        }

        private function is_mobile_app_request(): bool
        {
            return isset( Better_Messages()->mobile_app->auth ) && Better_Messages()->mobile_app->auth->is_mobile_app();
        }

        private function review_signal_available(): bool
        {
            return Better_Messages()->realtime
                && isset( Better_Messages()->websocket )
                && is_object( Better_Messages()->websocket )
                && method_exists( Better_Messages()->websocket, 'send_realtime_event' )
                && ! defined( 'BM_DISABLE_MESSAGES_VIEWER' )
                && Better_Messages()->settings['messagesViewer'] !== '0';
        }

        public function review_queue_changed(): void
        {
            if ( $this->review_signal_queued || ! $this->review_signal_available() ) {
                return;
            }

            $this->review_signal_queued = true;

            add_action( 'shutdown', array( $this, 'send_review_signal' ) );
        }

        public function review_meta_changed( $meta_ids, $message_id, $meta_key, $meta_value ): void
        {
            if ( 'user_reports' === $meta_key ) {
                $this->review_queue_changed();
            } elseif ( 'ai_moderation_flagged' === $meta_key && ( 'deleted_bm_message_meta' === current_action() || '1' === (string) $meta_value ) ) {
                $this->review_queue_changed();
            }

            if ( ( 'user_reports' === $meta_key && 'deleted_bm_message_meta' === current_action() ) || ( 'ai_moderation_flagged' === $meta_key && ( 'deleted_bm_message_meta' === current_action() || '1' !== (string) $meta_value ) ) ) {
                $this->settle_review_copy( (int) $message_id );
            }
        }

        const REVIEW_COPY = 'bm_review_copy';

        public function waits_for_review( int $message_id ): bool
        {
            $message = Better_Messages()->functions->get_message( $message_id );

            if ( ! $message ) {
                return false;
            }

            if ( (int) $message->is_pending === 1 ) {
                return true;
            }

            if ( '1' === (string) Better_Messages()->functions->get_message_meta( $message_id, 'ai_moderation_flagged', true ) ) {
                return true;
            }

            $reports = Better_Messages()->functions->get_message_meta( $message_id, 'user_reports', true );

            return is_array( $reports ) && count( $reports ) > 0;
        }

        public function keep_for_review( $message, int $deleted_by ): bool
        {
            $message_id = (int) $message->id;

            if ( (int) $message->sender_id === 0 || ! $this->waits_for_review( $message_id ) ) {
                return false;
            }

            if ( $this->review_copy( $message_id ) ) {
                return true;
            }

            $files       = array();
            $attachments = Better_Messages()->functions->get_message_meta( $message_id, 'attachments', true );

            if ( is_array( $attachments ) ) {
                $files = array_keys( $attachments );
            }

            foreach ( array( 'bpbm_voice_messages', 'bpbm_video_messages' ) as $meta_key ) {
                $files[] = Better_Messages()->functions->get_message_meta( $message_id, $meta_key, true );
            }

            $files = array_values( array_unique( array_filter( array_map( 'intval', $files ), function ( $id ) {
                return $id > 0;
            } ) ) );

            Better_Messages()->functions->update_message_meta( $message_id, self::REVIEW_COPY, array(
                'message' => (string) $message->message,
                'files'   => $files,
                'by'      => $deleted_by,
                'at'      => time(),
            ) );

            foreach ( array( 'attachments', 'bpbm_voice_messages', 'bpbm_video_messages' ) as $meta_key ) {
                Better_Messages()->functions->delete_message_meta( $message_id, $meta_key );
            }

            return true;
        }

        public function hide_kept_message( int $message_id, int $thread_id ): void
        {
            global $wpdb;

            $message = Better_Messages()->functions->get_message( $message_id );

            if ( $message && (int) $message->is_pending === 1 ) {
                $wpdb->update( bm_get_table( 'messages' ), array(
                    'message' => Better_Messages()->encryption->seal_message( '<!-- BM-DELETED-MESSAGE -->', $thread_id ),
                ), array( 'id' => $message_id ), array( '%s' ), array( '%d' ) );

                Better_Messages()->functions->update_message_update_time( $message_id );

                return;
            }

            Better_Messages()->functions->delete_message( $message_id, $thread_id, true, 'replace' );
        }

        public function review_copy( int $message_id ): ?array
        {
            $copy = Better_Messages()->functions->get_message_meta( $message_id, self::REVIEW_COPY, true );

            return is_array( $copy ) && isset( $copy['message'] ) ? $copy : null;
        }

        public function review_copy_files( int $message_id ): array
        {
            $copy = $this->review_copy( $message_id );

            return $copy ? array_map( 'intval', (array) ( $copy['files'] ?? array() ) ) : array();
        }

        public function settle_review_copy( int $message_id ): void
        {
            $copy = $this->review_copy( $message_id );

            if ( ! $copy || $this->waits_for_review( $message_id ) ) {
                return;
            }

            Better_Messages()->functions->delete_message_files( $message_id, (array) ( $copy['files'] ?? array() ) );
            Better_Messages()->functions->delete_message_meta( $message_id, self::REVIEW_COPY );
        }

        public function review_message_deleting( $message_id ): void
        {
            if ( $this->review_signal_queued || ! $this->review_signal_available() ) {
                return;
            }

            if ( isset( $this->get_ids_under_review()[ (int) $message_id ] ) ) {
                $this->review_queue_changed();
            }
        }

        private function get_ids_under_review(): array
        {
            global $wpdb;

            if ( $this->ids_under_review === null ) {
                $candidates = function_exists( 'Better_Messages_Rest_Api_Admin' ) ? Better_Messages_Rest_Api_Admin()->get_reported_candidate_ids() : array( 'all_ids' => array() );
                $held       = $wpdb->get_col( "SELECT `id` FROM `" . bm_get_table( 'messages' ) . "` WHERE `is_pending` = 1" );

                $this->ids_under_review = array_fill_keys( array_map( 'intval', array_merge( $candidates['all_ids'], $held ) ), true );
            }

            return $this->ids_under_review;
        }

        public function send_review_signal(): void
        {
            if ( ! $this->review_signal_available() ) {
                return;
            }

            $user_ids = $this->get_reviewer_ids();

            if ( empty( $user_ids ) ) {
                return;
            }

            Better_Messages()->websocket->send_realtime_event( $user_ids, array( 'type' => 'review_queue' ) );
        }

        private function get_reviewer_ids(): array
        {
            global $wpdb;

            $max = (int) apply_filters( 'better_messages_review_signal_max_users', 50 );
            $ids = array_values( array_filter( array_map( 'intval', $this->get_site_admin_ids() ) ) );

            if ( $max <= 0 || empty( $ids ) ) {
                return array();
            }

            if ( count( $ids ) <= $max ) {
                return $ids;
            }

            $active = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
                "SELECT `ID` FROM `" . bm_get_table( 'users' ) . "` WHERE `ID` IN (" . implode( ',', $ids ) . ") ORDER BY `last_activity` DESC LIMIT %d",
                $max
            ) ) );

            return array_slice( array_values( array_unique( array_merge( $active, $ids ) ) ), 0, $max );
        }

        public function render_user_section( $user )
        {
            if ( ! current_user_can( 'bm_can_administrate' ) ) {
                return;
            }

            $user_id = $user->ID;
            ?>
            <div class="bm-user-profile-section" id="bm-user-profile-data" data-user-id="<?php echo $user_id; ?>">
                <h3><?php _e( 'Better Messages', 'bp-better-messages' ); ?></h3>

                <div id="bm-user-profile-section-content">

                </div>
            </div>
            <?php
        }

        public function addresses_in_user_section( $draws )
        {
            return current_user_can( 'bm_can_administrate' ) ? false : $draws;
        }

        public function is_moderation_enabled( $user_id, ?int $thread_id = null, $is_new_conversation = false )
        {
            if( user_can( $user_id, 'bm_can_administrate' ) ){
                return false;
            }

            $result = false;

            if( Better_Messages()->settings['messagesPremoderation'] === '1' ) {
                if( Better_Messages()->settings['messagesModerateFirstTimeSenders'] === '1' ) {
                    if( $this->is_first_time_sender( $user_id ) ) {
                        $result = true;
                    }
                }

                if( ! $result ) {
                    $moderated_roles = $is_new_conversation
                        ? Better_Messages()->settings['messagesPremoderationRolesNewConv']
                        : Better_Messages()->settings['messagesPremoderationRolesReplies'];

                    if( ! empty( $moderated_roles ) ){
                        $user_roles = Better_Messages()->functions->get_user_roles( $user_id );
                        if( ! empty( $user_roles ) ){
                            foreach( $user_roles as $role ){
                                if( in_array( $role, $moderated_roles ) ){
                                    $result = true;
                                    break;
                                }
                            }
                        }
                    }
                }

                $moderation_status = $this->get_user_moderation_status( $user_id, $thread_id );

                if( $moderation_status !== null ){
                    $result = $moderation_status;
                }
            }

            return apply_filters( 'better_messages_is_moderation_enabled', $result, $user_id, $thread_id, $is_new_conversation );
        }

        public function is_first_time_sender( $user_id )
        {
            $has_approved_message = Better_Messages()->functions->get_user_meta( $user_id, 'bm_has_approved_message', true );

            if( $has_approved_message === '1' ){
                return false;
            }

            global $wpdb;
            $table = bm_get_table('messages');

            $checked_since = (int) get_option( 'better_messages_first_time_senders_since', 0 );

            if( $checked_since === 0 ){
                $checked_since = Better_Messages()->functions->get_microtime();
                add_option( 'better_messages_first_time_senders_since', $checked_since, '', false );
            }

            $has_messages = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE sender_id = %d AND is_pending = 0 AND created_at < %d LIMIT 1",
                $user_id,
                $checked_since
            ));

            if( $has_messages ){
                Better_Messages()->functions->update_user_meta( $user_id, 'bm_has_approved_message', '1' );
                return false;
            }

            return true;
        }

        public function mark_user_as_approved_sender( $message )
        {
            if( ! isset( $message->sender_id ) || ! empty( $message->skip_moderation ) ){
                return;
            }

            Better_Messages()->functions->update_user_meta( $message->sender_id, 'bm_has_approved_message', '1' );
        }

        public function get_user_moderation_status( int $user_id, ?int $thread_id = null ): ?bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            $cache_key = $thread_id !== null
                ? 'bm_mod_status_' . $user_id . '_' . $thread_id
                : 'bm_mod_status_global_' . $user_id;

            $cached = wp_cache_get( $cache_key, 'bm_messages' );

            if( $cached !== false ){
                if( $cached === 'whitelist' ) return false;
                if( $cached === 'blacklist' ) return true;
                return null;
            }

            if( $thread_id !== null ){
                $query = $wpdb->prepare(
                    "SELECT `type`,
                        CASE
                            WHEN `type` = 'bypass_moderation' AND `thread_id` = %d THEN 1
                            WHEN `type` = 'force_moderation' AND `thread_id` = %d THEN 2
                            WHEN `type` = 'bypass_moderation' AND (`thread_id` = 0 OR `thread_id` IS NULL) THEN 3
                            WHEN `type` = 'force_moderation' AND (`thread_id` = 0 OR `thread_id` IS NULL) THEN 4
                        END as priority
                    FROM {$table}
                    WHERE `user_id` = %d
                    AND (
                        (`type` = 'bypass_moderation' AND (`thread_id` = %d OR `thread_id` = 0 OR `thread_id` IS NULL))
                        OR (`type` = 'force_moderation' AND (`thread_id` = %d OR `thread_id` = 0 OR `thread_id` IS NULL))
                    )
                    AND (`expiration` IS NULL OR `expiration` > NOW())
                    ORDER BY priority ASC
                    LIMIT 1",
                    $thread_id,
                    $thread_id,
                    $user_id,
                    $thread_id,
                    $thread_id
                );
            } else {
                $query = $wpdb->prepare(
                    "SELECT `type`,
                        CASE
                            WHEN `type` = 'bypass_moderation' THEN 1
                            WHEN `type` = 'force_moderation' THEN 2
                        END as priority
                    FROM {$table}
                    WHERE `user_id` = %d
                    AND `type` IN ('bypass_moderation', 'force_moderation')
                    AND (`thread_id` = 0 OR `thread_id` IS NULL)
                    AND (`expiration` IS NULL OR `expiration` > NOW())
                    ORDER BY priority ASC
                    LIMIT 1",
                    $user_id
                );
            }

            $row = $wpdb->get_row( $query );

            $cache_value = 'none';
            $result = null;

            if( $row ){
                if( $row->type === 'bypass_moderation' ){
                    $cache_value = 'whitelist';
                    $result = false;
                } else if( $row->type === 'force_moderation' ){
                    $cache_value = 'blacklist';
                    $result = true;
                }
            }

            wp_cache_set( $cache_key, $cache_value, 'bm_messages', 300 );

            return $result;
        }

        public function is_user_whitelisted( int $user_id, ?int $thread_id = null ): bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            $cache_key = $thread_id !== null
                ? 'bm_whitelist_' . $user_id . '_' . $thread_id
                : 'bm_whitelist_global_' . $user_id;

            $cached = wp_cache_get( $cache_key, 'bm_messages' );

            if( $cached !== false ){
                return (bool) $cached;
            }

            if( $thread_id !== null ){
                $query = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                    WHERE `user_id` = %d
                    AND `type` = 'bypass_moderation'
                    AND `thread_id` = %d
                    AND (`expiration` IS NULL OR `expiration` > NOW())",
                    $user_id,
                    $thread_id
                );
            } else {
                $query = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                    WHERE `user_id` = %d
                    AND `type` = 'bypass_moderation'
                    AND (`thread_id` = 0 OR `thread_id` IS NULL)
                    AND (`expiration` IS NULL OR `expiration` > NOW())",
                    $user_id
                );
            }

            $count = (int) $wpdb->get_var( $query );
            $is_whitelisted = $count > 0;

            wp_cache_set( $cache_key, $is_whitelisted ? 1 : 0, 'bm_messages', 300 );

            return $is_whitelisted;
        }

        public function whitelist_user( int $user_id, ?int $thread_id = null, ?int $duration = null ): bool
        {
            global $wpdb;

            if( user_can( $user_id, 'bm_can_administrate' ) ){
                return false;
            }

            $admin_id = Better_Messages()->functions->get_current_user_id();
            $table = bm_get_table('moderation');

            if( $thread_id === null ){
                $thread_id = 0;
            }

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE `type` = 'force_moderation'
                AND `user_id` = %d
                AND `thread_id` = %d",
                $user_id,
                $thread_id
            ));

            if( $duration === null || $duration <= 0 ){
                $query = $wpdb->prepare(
                    "INSERT INTO {$table}
                    (user_id, thread_id, type, expiration, admin_id)
                    VALUES (%d, %d, 'bypass_moderation', NULL, %d)
                    ON DUPLICATE KEY
                    UPDATE expiration = NULL, admin_id = %d",
                    $user_id,
                    $thread_id,
                    $admin_id,
                    $admin_id
                );
            } else {
                $seconds = $duration * 60;
                $query = $wpdb->prepare(
                    "INSERT INTO {$table}
                    (user_id, thread_id, type, expiration, admin_id)
                    VALUES (%d, %d, 'bypass_moderation', DATE_ADD(NOW(), INTERVAL %d SECOND), %d)
                    ON DUPLICATE KEY
                    UPDATE expiration = DATE_ADD(NOW(), INTERVAL %d SECOND), admin_id = %d",
                    $user_id,
                    $thread_id,
                    $seconds,
                    $admin_id,
                    $seconds,
                    $admin_id
                );
            }

            $wpdb->query( $query );

            if( $thread_id > 0 ){
                wp_cache_delete( 'bm_whitelist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_blacklist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_mod_status_' . $user_id . '_' . $thread_id, 'bm_messages' );
            }
            wp_cache_delete( 'bm_whitelist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_blacklist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_mod_status_global_' . $user_id, 'bm_messages' );

            if( $duration !== null && $duration > 0 ){
                $seconds = $duration * 60;
                if( ! wp_get_scheduled_event( 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] ) ){
                    wp_schedule_single_event( time() + $seconds + 1, 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] );
                }
            }

            return true;
        }

        public function unwhitelist_user( int $user_id, ?int $thread_id = null ): bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            if( $thread_id === null ){
                $thread_id = 0;
            }

            $query = $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE `type` = 'bypass_moderation'
                AND `user_id` = %d
                AND `thread_id` = %d",
                $user_id,
                $thread_id
            );

            $wpdb->query( $query );

            if( $thread_id > 0 ){
                wp_cache_delete( 'bm_whitelist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_mod_status_' . $user_id . '_' . $thread_id, 'bm_messages' );
            }
            wp_cache_delete( 'bm_whitelist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_mod_status_global_' . $user_id, 'bm_messages' );

            return true;
        }

        public function get_whitelisted_users( ?int $thread_id = null ): array
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            if( $thread_id === null ){
                $thread_id = 0;
            }

            $query = $wpdb->prepare(
                "SELECT user_id, CONVERT_TZ(expiration, @@session.time_zone, '+0:00') as expiration, admin_id
                FROM {$table}
                WHERE `type` = 'bypass_moderation'
                AND `thread_id` = %d
                AND (`expiration` IS NULL OR `expiration` > NOW())
                ORDER BY user_id ASC",
                $thread_id
            );

            $results = $wpdb->get_results( $query, ARRAY_A );

            return $results ?: [];
        }

        public function is_user_blacklisted( int $user_id, ?int $thread_id = null ): bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            $cache_key = $thread_id !== null
                ? 'bm_blacklist_' . $user_id . '_' . $thread_id
                : 'bm_blacklist_global_' . $user_id;

            $cached = wp_cache_get( $cache_key, 'bm_messages' );

            if( $cached !== false ){
                return (bool) $cached;
            }

            if( $thread_id !== null ){
                $query = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                    WHERE `user_id` = %d
                    AND `type` = 'force_moderation'
                    AND (`thread_id` = %d OR `thread_id` = 0 OR `thread_id` IS NULL)
                    AND (`expiration` IS NULL OR `expiration` > NOW())",
                    $user_id,
                    $thread_id
                );
            } else {
                $query = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table}
                    WHERE `user_id` = %d
                    AND `type` = 'force_moderation'
                    AND (`thread_id` = 0 OR `thread_id` IS NULL)
                    AND (`expiration` IS NULL OR `expiration` > NOW())",
                    $user_id
                );
            }

            $count = (int) $wpdb->get_var( $query );
            $is_blacklisted = $count > 0;

            wp_cache_set( $cache_key, $is_blacklisted ? 1 : 0, 'bm_messages', 300 );

            return $is_blacklisted;
        }

        public function blacklist_user( int $user_id, ?int $thread_id = null, ?int $duration = null, ?int $admin_id = null ): bool
        {
            global $wpdb;

            if( user_can( $user_id, 'bm_can_administrate' ) ){
                return false;
            }

            if( $admin_id === null ){
                $admin_id = Better_Messages()->functions->get_current_user_id();
            }
            $table = bm_get_table('moderation');

            if( $thread_id === null ){
                $thread_id = 0;
            }

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE `type` = 'bypass_moderation'
                AND `user_id` = %d
                AND `thread_id` = %d",
                $user_id,
                $thread_id
            ));

            if( $duration === null || $duration <= 0 ){
                $query = $wpdb->prepare(
                    "INSERT INTO {$table}
                    (user_id, thread_id, type, expiration, admin_id)
                    VALUES (%d, %d, 'force_moderation', NULL, %d)
                    ON DUPLICATE KEY
                    UPDATE expiration = NULL, admin_id = %d",
                    $user_id,
                    $thread_id,
                    $admin_id,
                    $admin_id
                );
            } else {
                $seconds = $duration * 60;
                $query = $wpdb->prepare(
                    "INSERT INTO {$table}
                    (user_id, thread_id, type, expiration, admin_id)
                    VALUES (%d, %d, 'force_moderation', DATE_ADD(NOW(), INTERVAL %d SECOND), %d)
                    ON DUPLICATE KEY
                    UPDATE expiration = DATE_ADD(NOW(), INTERVAL %d SECOND), admin_id = %d",
                    $user_id,
                    $thread_id,
                    $seconds,
                    $admin_id,
                    $seconds,
                    $admin_id
                );
            }

            $wpdb->query( $query );

            if( $thread_id > 0 ){
                wp_cache_delete( 'bm_blacklist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_whitelist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_mod_status_' . $user_id . '_' . $thread_id, 'bm_messages' );
            }
            wp_cache_delete( 'bm_blacklist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_whitelist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_mod_status_global_' . $user_id, 'bm_messages' );

            if( $duration !== null && $duration > 0 ){
                $seconds = $duration * 60;
                if( ! wp_get_scheduled_event( 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] ) ){
                    wp_schedule_single_event( time() + $seconds + 1, 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] );
                }
            }

            return true;
        }

        public function unblacklist_user( int $user_id, ?int $thread_id = null ): bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            if( $thread_id === null ){
                $thread_id = 0;
            }

            $query = $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE `type` = 'force_moderation'
                AND `user_id` = %d
                AND `thread_id` = %d",
                $user_id,
                $thread_id
            );

            $wpdb->query( $query );

            if( $thread_id > 0 ){
                wp_cache_delete( 'bm_blacklist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_mod_status_' . $user_id . '_' . $thread_id, 'bm_messages' );
            }
            wp_cache_delete( 'bm_blacklist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_mod_status_global_' . $user_id, 'bm_messages' );

            return true;
        }

        public function get_blacklisted_users( ?int $thread_id = null ): array
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            if( $thread_id === null ){
                $thread_id = 0;
            }

            $query = $wpdb->prepare(
                "SELECT user_id, CONVERT_TZ(expiration, @@session.time_zone, '+0:00') as expiration, admin_id
                FROM {$table}
                WHERE `type` = 'force_moderation'
                AND `thread_id` = %d
                AND (`expiration` IS NULL OR `expiration` > NOW())
                ORDER BY user_id ASC",
                $thread_id
            );

            $results = $wpdb->get_results( $query, ARRAY_A );

            return $results ?: [];
        }

        public function restrict_join( $has_access, $user_id, $chat_id, $thread_id ){
            $restrictions = $this->is_user_restricted( $thread_id, $user_id );

            if( isset( $restrictions['ban'] ) ){
                $has_access = false;
            }

            return $has_access;
        }

        public function is_ephemeral_thread( $thread_id ){
            if( ! isset( Better_Messages()->chats ) ){
                return false;
            }

            if( Better_Messages()->functions->get_thread_type( $thread_id ) !== 'chat-room' ){
                return false;
            }

            $chat_id = (int) Better_Messages()->functions->get_thread_meta( $thread_id, 'chat_id' );

            if( ! $chat_id ){
                return false;
            }

            return Better_Messages()->chats->is_ephemeral_chat( $chat_id );
        }

        public function can_moderate_user( $thread_id, $user_id ){
            if( Better_Messages()->functions->is_thread_participant( $user_id, $thread_id, true ) ){
                return true;
            }

            if( ! $this->is_ephemeral_thread( $thread_id ) ){
                return false;
            }

            return Better_Messages()->functions->is_user_exists( $user_id );
        }

        public function is_protected_user( int $thread_id, int $actor_id, int $user_id ){
            if( Better_Messages()->functions->is_thread_super_moderator( $actor_id, $thread_id ) ){
                return false;
            }

            return Better_Messages()->functions->is_thread_super_moderator( $user_id, $thread_id );
        }

        public function get_protected_users( int $thread_id, int $actor_id ){
            if( Better_Messages()->functions->is_thread_super_moderator( $actor_id, $thread_id ) ){
                return [];
            }

            $admin_ids = $this->get_site_admin_ids();

            if( $this->is_ephemeral_thread( $thread_id ) ){
                return $admin_ids;
            }

            return array_values( array_filter( $admin_ids, function( $admin_id ) use ( $thread_id ){
                return Better_Messages()->functions->is_thread_participant( $admin_id, $thread_id, true );
            } ) );
        }

        private function get_site_admin_ids(){
            if( $this->site_admin_ids !== null ){
                return $this->site_admin_ids;
            }

            $ids = get_transient( 'bm_site_admin_ids' );

            if( ! is_array( $ids ) ){
                $ids = get_users( array(
                    'capability__in' => array( 'manage_options', 'bm_can_administrate' ),
                    'fields'         => 'ID'
                ) );

                if( is_multisite() ){
                    foreach( get_super_admins() as $login ){
                        $user = get_user_by( 'login', $login );
                        if( $user ) $ids[] = $user->ID;
                    }
                }

                $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
                set_transient( 'bm_site_admin_ids', $ids, HOUR_IN_SECONDS );
            }

            $this->site_admin_ids = $ids;

            return $ids;
        }

        public function forget_site_admins(){
            $this->site_admin_ids = null;
            delete_transient( 'bm_site_admin_ids' );
        }

        public function restrict_group_call_join( $error, $thread_id, $user_id ){
            if( ! empty( $error ) ) return $error;

            $restrictions = $this->is_user_restricted( (int) $thread_id, (int) $user_id );

            if( isset( $restrictions['ban'] ) ){
                $error = sprintf( _x('You were banned in this conversation until %s', 'Message when user was banned in conversation', 'bp-better-messages'), $this->format_time( $restrictions['ban'] ) );
            } else if( isset( $restrictions['call_ban'] ) ){
                $error = sprintf( _x( 'A moderator removed you from the calls of this conversation until %s', 'Message when user was banned from group calls', 'bp-better-messages' ), $this->format_time( $restrictions['call_ban'] ) );
            }

            return $error;
        }

        public function can_ban_in_chat( int $thread_id ){
            return in_array( Better_Messages()->functions->get_thread_type( $thread_id ), array( 'thread', 'chat-room' ), true );
        }

        public function ban_user( int $thread_id, int $user_id, int $duration ){
            $result = $this->restrict_user( 'ban', $user_id, $thread_id, $duration );

            if( $result ){
                if ( isset( Better_Messages()->system_messages ) ) {
                    Better_Messages()->system_messages->suppress_user_left( $thread_id, $user_id );
                }
                Better_Messages()->functions->remove_participant_from_thread( $thread_id, $user_id );
            }

            return $result;
        }

        public function call_ban_user( int $thread_id, int $user_id, int $duration, bool $chat_too ){
            $current_user_id = Better_Messages()->functions->get_current_user_id();

            if( $user_id === 0 || $user_id === $current_user_id || ! Better_Messages()->functions->can_moderate_thread( $thread_id, $current_user_id ) || ! $this->can_moderate_user( $thread_id, $user_id ) || $this->is_protected_user( $thread_id, $current_user_id, $user_id ) ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $result = $this->restrict_user( 'call_ban', $user_id, $thread_id, $duration );

            if( $chat_too && $this->can_ban_in_chat( $thread_id ) ){
                $result = $this->ban_user( $thread_id, $user_id, $duration ) && $result;
            }

            return (bool) $result;
        }

        public function thread_item_restrictions( $thread_item, $thread_id, $thread_type, $include_personal, $user_id ){
            if( ! $include_personal || $thread_type === 'chat-room' || isset( $thread_item['restricted'] ) ) return $thread_item;

            if( $thread_type === 'thread' && (int) ( $thread_item['participantsCount'] ?? 0 ) <= 2 ) return $thread_item;

            if( empty( $thread_item['permissions']['isModerator'] ) ) return $thread_item;

            $thread_item['restricted'] = $this->get_restricted_users( (int) $thread_id );

            return $thread_item;
        }

        public function rest_api_init(){
            register_rest_route( 'better-messages/v1', '/moderation/toReview', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'messages_to_review_api' ),
                'permission_callback' => array( $this, 'can_review_messages' ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/muteUser', array(
                'methods' => 'POST',
                'callback' => array( $this, 'mute_user_api' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/unmuteUser', array(
                'methods' => 'POST',
                'callback' => array( $this, 'unmute_user_api' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ) );


            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/banUser', array(
                'methods' => 'POST',
                'callback' => array( $this, 'ban_user_api' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/unbanUser', array(
                'methods' => 'POST',
                'callback' => array( $this, 'un_ban_user_api' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ) );

            register_rest_route( 'better-messages/v1', '/thread/(?P<id>\d+)/callUnbanUser', array(
                'methods' => 'POST',
                'callback' => array( $this, 'call_unban_user_api' ),
                'permission_callback' => array( Better_Messages_Rest_Api(), 'check_thread_access' ),
                'args' => array(
                    'id' => array(
                        'validate_callback' => function($param, $request, $key) {
                            return is_numeric( $param );
                        }
                    ),
                ),
            ) );
        }

        public function call_unban_user_api( WP_REST_Request $request ){
            $thread_id       = intval( $request->get_param('id') );
            $user_id         = intval( $request->get_param('user_id') );
            $current_user_id = Better_Messages()->functions->get_current_user_id();

            if( ! Better_Messages()->functions->can_moderate_thread( $thread_id, $current_user_id ) ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $this->un_restrict_user( 'call_ban', $user_id, $thread_id );

            return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
        }

        public function ban_user_api( WP_REST_Request $request ){
            $thread_id       = intval( $request->get_param('id') );
            $user_id         = intval( $request->get_param('user_id') );
            $duration        = intval( $request->get_param('duration') );
            $current_user_id = Better_Messages()->functions->get_current_user_id();

            $can_mute = Better_Messages()->functions->can_moderate_thread( $thread_id, $current_user_id );
            $is_participant = $this->can_moderate_user( $thread_id, $user_id );

            if( ! $can_mute || ! $is_participant || $this->is_protected_user( $thread_id, $current_user_id, $user_id ) ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $result = $this->ban_user( $thread_id, $user_id, $duration );

            if( $result ){
                return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
            }

            return false;
        }

        public function un_ban_user_api( WP_REST_Request $request ){
            $thread_id       = intval( $request->get_param('id') );
            $user_id         = intval( $request->get_param('user_id') );
            $current_user_id = Better_Messages()->functions->get_current_user_id();

            $can_mute = Better_Messages()->functions->can_moderate_thread( $thread_id, $current_user_id );

            if( ! $can_mute ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $this->un_restrict_user( 'ban', $user_id, $thread_id );

            return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
        }

        public function mute_user_api( WP_REST_Request $request ){
            $thread_id       = intval( $request->get_param('id') );
            $user_id         = intval( $request->get_param('user_id') );
            $duration        = intval( $request->get_param('duration') );
            $current_user_id = Better_Messages()->functions->get_current_user_id();

            $can_mute = Better_Messages()->functions->can_moderate_thread( $thread_id, $current_user_id );
            $is_participant = $this->can_moderate_user( $thread_id, $user_id );

            if( ! $can_mute || ! $is_participant || $this->is_protected_user( $thread_id, $current_user_id, $user_id ) ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $result = $this->restrict_user( 'mute', $user_id, $thread_id, $duration );

            if( $result ){
                return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
            }

            return false;
        }

        public function unmute_user_api( WP_REST_Request $request ){
            $current_user_id = Better_Messages()->functions->get_current_user_id();
            $thread_id  = intval( $request->get_param('id') );
            $user_id    = intval( $request->get_param('user_id') );

            $can_mute = Better_Messages()->functions->can_moderate_thread( $thread_id, $current_user_id );
            $is_participant = $this->can_moderate_user( $thread_id, $user_id );

            if( ! $can_mute || ! $is_participant ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $this->un_restrict_user( 'mute', $user_id, $thread_id );

            return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
        }

        public function can_send_reply( $allowed, $user_id, $thread_id ){
            $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );

            if( $thread_type === 'chat-room' ){
                $restrictions = $this->is_user_restricted( $thread_id, $user_id );

                if( isset( $restrictions['ban'] ) && $this->is_ephemeral_thread( $thread_id ) ){
                    $allowed = false;

                    $time = $this->format_time( $restrictions['ban'] );

                    global $bp_better_messages_restrict_send_message;
                    $bp_better_messages_restrict_send_message['bm_user_banned'] = sprintf(_x('You were banned in this conversation until %s', 'Message when user was banned in conversation', 'bp-better-messages'), $time);
                } elseif( isset( $restrictions['mute'] ) ){
                    $allowed = false;

                    $expiration = $restrictions['mute'];

                    $time = $this->format_time($expiration);

                    global $bp_better_messages_restrict_send_message;
                    $bp_better_messages_restrict_send_message['bm_user_muted'] = sprintf(_x('You were muted in this conversation until %s', 'Message when user was muted in conversation', 'bp-better-messages'), $time);

                }
            }

            return $allowed;
        }

        public function format_time( $expiration ){
            return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime( $expiration ) );
        }

        private function user_thread_cache_key( int $thread_id, int $user_id ): string
        {
            return 'bm_restriction_' . $thread_id . '_' . $user_id;
        }

        private function thread_cache_key( int $thread_id ): string
        {
            return 'bm_restriction_' . $thread_id;
        }


        public function clean_expired_bans(){
            global $wpdb;

            $table = bm_get_table('moderation');

            $results = $wpdb->get_results("
            SELECT id, thread_id, user_id, type
            FROM  {$table}
            WHERE `expiration` <= NOW()");

            if( ! empty( $results ) ) {
                foreach ($results as $result) {
                    $wpdb->query($wpdb->prepare("
                DELETE
                FROM  {$table}
                WHERE `id` = %d", $result->id));

                    $this->cache_delete($result->thread_id, $result->user_id);
                    Better_Messages()->functions->thread_updated_for_user($result->thread_id, $result->user_id);

                    do_action( 'better_messages_user_unrestricted', (int) $result->thread_id, (int) $result->user_id, $result->type );
                }
            }
        }

        public function clean_expired_ban( $thread_id, $user_id ){
            global $wpdb;

            $table = bm_get_table('moderation');

            $results = $wpdb->get_results( $wpdb->prepare("
            SELECT id, thread_id, user_id, type
            FROM  {$table}
            WHERE `thread_id` = %d
            AND `user_id` = %d
            AND `expiration` <= NOW()", $thread_id, $user_id) );

            if( ! empty( $results ) ) {
                foreach ($results as $result) {
                    $wpdb->query($wpdb->prepare("
                    DELETE
                    FROM  {$table}
                    WHERE `id` = %d", $result->id));

                    $this->cache_delete($result->thread_id, $result->user_id);
                    Better_Messages()->functions->thread_updated_for_user($result->thread_id, $result->user_id);

                    do_action( 'better_messages_user_unrestricted', (int) $result->thread_id, (int) $result->user_id, $result->type );
                }
            }
        }

        public function expect_restricted_users( array $thread_ids, int $user_id = 0 ){
            $this->restricted_batch = array_values( array_unique( array_filter( array_map( 'intval', $thread_ids ) ) ) );
            $this->own_batch        = array( 'user' => $user_id, 'threads' => $user_id !== 0 ? $this->restricted_batch : array() );
        }

        public function get_restricted_users( int $thread_id ){
            $key = $this->thread_cache_key( $thread_id );

            $restricted_users = wp_cache_get( $key, 'bm_messages' );

            if( ! is_array( $restricted_users ) && count( $this->restricted_batch ) > 1 && in_array( $thread_id, $this->restricted_batch, true ) ){
                $batch = $this->restricted_batch;
                $this->restricted_batch = array();
                $this->prime_restricted_users( $batch );
                $restricted_users = wp_cache_get( $key, 'bm_messages' );
            }

            if( ! is_array( $restricted_users ) ){
                $restricted_users = $this->read_restricted_users( array( $thread_id ), $lives )[ $thread_id ];
                wp_cache_set( $key, $restricted_users, 'bm_messages', $lives[ $thread_id ] );
            }

            if( empty( $restricted_users ) ) return (object) [];

            return $restricted_users;
        }

        public function prime_restricted_users( array $thread_ids ){
            $pending = array();

            foreach ( array_unique( array_map( 'intval', $thread_ids ) ) as $thread_id ) {
                if ( $thread_id > 0 && ! is_array( wp_cache_get( $this->thread_cache_key( $thread_id ), 'bm_messages' ) ) ) {
                    $pending[] = $thread_id;
                }
            }

            if ( empty( $pending ) ) return;

            foreach ( $this->read_restricted_users( $pending, $lives ) as $thread_id => $restricted ) {
                wp_cache_set( $this->thread_cache_key( $thread_id ), $restricted, 'bm_messages', $lives[ $thread_id ] );
            }
        }

        private function cache_life( array $seconds_left ): int {
            if( empty( $seconds_left ) ) return 0;

            return max( 1, (int) min( $seconds_left ) );
        }

        private function read_restricted_users( array $thread_ids, &$lives = null ){
            global $wpdb;

            $thread_ids = array_values( array_unique( array_map( 'intval', $thread_ids ) ) );
            $ephemeral  = array_values( array_filter( $thread_ids, array( $this, 'is_ephemeral_thread' ) ) );

            $in           = implode( ',', $thread_ids );
            $in_ephemeral = empty( $ephemeral ) ? '0' : implode( ',', $ephemeral );

            $results = $wpdb->get_results( "
            SELECT `moderation`.`thread_id`, `moderation`.`user_id`, `moderation`.`type`, CONVERT_TZ(`moderation`.`expiration`, @@session.time_zone, '+0:00') as expiration, TIMESTAMPDIFF(SECOND, NOW(), `moderation`.`expiration`) as seconds_left
            FROM " . bm_get_table('moderation') . " `moderation`
            WHERE `moderation`.`thread_id` IN ({$in})
            AND `moderation`.`expiration` > NOW()
            AND (
                `moderation`.`type` IN ( 'ban', 'call_ban' )
                OR ( `moderation`.`type` = 'mute' AND (
                    `moderation`.`thread_id` IN ({$in_ephemeral})
                    OR EXISTS ( SELECT 1 FROM " . bm_get_table('recipients') . " `recipients` WHERE `recipients`.`thread_id` = `moderation`.`thread_id` AND `recipients`.`user_id` = `moderation`.`user_id` AND `recipients`.`is_deleted` = 0 )
                ) )
            )" );

            $by_thread = array_fill_keys( $thread_ids, array() );
            $left      = array_fill_keys( $thread_ids, array() );

            foreach( (array) $results as $item ){
                $by_thread[ (int) $item->thread_id ][ $item->type ][ $item->user_id ] = strtotime( $item->expiration );
                $left[ (int) $item->thread_id ][] = (int) $item->seconds_left;
            }

            $lives = array_map( array( $this, 'cache_life' ), $left );

            foreach( $by_thread as $thread_id => $result ){
                if( isset( $result['ban'] ) && isset( $result['mute'] ) ){
                    foreach( $result['ban'] as $user_id => $until ){
                        unset( $result['mute'][ $user_id ] );
                    }

                    if( empty( $result['mute'] ) ) unset( $result['mute'] );

                    $by_thread[ $thread_id ] = $result;
                }
            }

            return $by_thread;
        }

        public function is_user_restricted( int $thread_id, int $user_id ){
            $key = $this->user_thread_cache_key( $thread_id, $user_id );

            $restrictions = wp_cache_get( $key, 'bm_messages' );

            if( ! is_array( $restrictions ) && $user_id === $this->own_batch['user'] && count( $this->own_batch['threads'] ) > 1 && in_array( $thread_id, $this->own_batch['threads'], true ) ){
                $batch = $this->own_batch['threads'];
                $this->own_batch = array( 'user' => 0, 'threads' => array() );
                $this->prime_user_restrictions( $batch, $user_id );
                $restrictions = wp_cache_get( $key, 'bm_messages' );
            }

            if( is_array( $restrictions ) ){
                return $restrictions;
            }

            global $wpdb;

            $table = bm_get_table('moderation');
            $query = $wpdb->prepare("SELECT type, expiration, TIMESTAMPDIFF(SECOND, NOW(), expiration) AS seconds_left FROM {$table} WHERE `thread_id` = %d AND `user_id` = %d AND `expiration` > NOW()", $thread_id, $user_id);

            $array = [];
            $left  = [];
            $results = $wpdb->get_results( $query );

            if( count( $results ) > 0 ){
                foreach ( $results as $result ){
                    $array[$result->type] = $result->expiration;
                    $left[] = (int) $result->seconds_left;
                }
            }

            wp_cache_set($key, $array, 'bm_messages', $this->cache_life( $left ));

            return $array;
        }

        public function get_ban_expiration( int $thread_id, int $user_id ): int {
            return $this->get_restriction_expiration( $thread_id, $user_id, 'ban' );
        }

        public function get_call_ban_expiration( int $thread_id, int $user_id ): int {
            return $this->get_restriction_expiration( $thread_id, $user_id, 'call_ban' );
        }

        private function get_restriction_expiration( int $thread_id, int $user_id, string $type ): int {
            $restrictions = $this->is_user_restricted( $thread_id, $user_id );

            if( ! isset( $restrictions[ $type ] ) ) return 0;

            global $wpdb;

            $table = bm_get_table('moderation');

            $until = (int) $wpdb->get_var( $wpdb->prepare( "SELECT UNIX_TIMESTAMP(`expiration`) FROM {$table} WHERE `thread_id` = %d AND `user_id` = %d AND `type` = %s AND `expiration` > NOW()", $thread_id, $user_id, $type ) );

            if( $until <= 0 ){
                $this->cache_delete( $thread_id, $user_id );
                return 0;
            }

            return $until;
        }

        public function prime_user_restrictions( array $thread_ids, int $user_id ){
            $pending = array();

            foreach ( array_unique( array_map( 'intval', $thread_ids ) ) as $thread_id ) {
                if ( $thread_id < 1 ) continue;

                $cached = wp_cache_get( $this->user_thread_cache_key( $thread_id, $user_id ), 'bm_messages' );
                if ( ! is_array( $cached ) ) {
                    $pending[] = $thread_id;
                }
            }

            if ( empty( $pending ) ) return;

            global $wpdb;

            $placeholders = implode( ',', array_fill( 0, count( $pending ), '%d' ) );

            $params   = $pending;
            $params[] = $user_id;

            $results = $wpdb->get_results( $wpdb->prepare(
                "SELECT `thread_id`, `type`, `expiration`, TIMESTAMPDIFF(SECOND, NOW(), `expiration`) AS seconds_left
                 FROM `" . bm_get_table( 'moderation' ) . "`
                 WHERE `thread_id` IN ({$placeholders})
                 AND `user_id` = %d
                 AND `expiration` > NOW()",
                $params
            ) );

            $grouped = array();
            $left    = array();
            foreach ( $results as $result ) {
                $grouped[ (int) $result->thread_id ][ $result->type ] = $result->expiration;
                $left[ (int) $result->thread_id ][] = (int) $result->seconds_left;
            }

            foreach ( $pending as $thread_id ) {
                wp_cache_set(
                    $this->user_thread_cache_key( $thread_id, $user_id ),
                    isset( $grouped[ $thread_id ] ) ? $grouped[ $thread_id ] : array(),
                    'bm_messages',
                    $this->cache_life( isset( $left[ $thread_id ] ) ? $left[ $thread_id ] : array() )
                );
            }
        }

        public function restrict_user( string $type, int $user_id, int $thread_id, int $time = 1 ){
            global $wpdb;

            $admin_id = (int) Better_Messages()->functions->get_current_user_id();
            if( $time < 1 ) $time = 1;

            $seconds = $time * 60;

            $table = bm_get_table('moderation');

            $query = $wpdb->prepare("INSERT INTO {$table}
            (user_id, thread_id, type, expiration, admin_id)
            VALUES (%d, %d, %s, DATE_ADD(NOW(), INTERVAL %d SECOND), %d)
            ON DUPLICATE KEY
            UPDATE expiration = DATE_ADD(NOW(), INTERVAL %d SECOND), admin_id = %d", $user_id, $thread_id, $type, $seconds, $admin_id, $seconds, $admin_id);

            $result = $wpdb->query( $query );

            $this->cache_delete( $thread_id, $user_id );

            if( $result ){
                Better_Messages()->functions->thread_updated_for_user( $thread_id, $user_id );

                if( ! wp_get_scheduled_event( 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] ) ){
                    wp_schedule_single_event( time() + $seconds + 1, 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] );
                }

                if ( $type === 'mute' ) {
                    do_action( 'better_messages_user_muted', $thread_id, $user_id, $admin_id, $seconds );
                } elseif ( $type === 'ban' ) {
                    do_action( 'better_messages_user_banned', $thread_id, $user_id, $admin_id, $seconds );
                }
            }

            return $result;
        }

        public function un_restrict_user( string $type, int $user_id, int $thread_id ){
            global $wpdb;
            $table = bm_get_table('moderation');
            $query = $wpdb->prepare("DELETE FROM {$table} WHERE `type` = %s AND `thread_id` = %d AND `user_id` = %d", $type, $thread_id, $user_id);
            $result = $wpdb->query( $query );

            $this->cache_delete( $thread_id, $user_id );

            if( $result ){
                Better_Messages()->functions->thread_updated_for_user( $thread_id, $user_id );

                if( wp_get_scheduled_event( 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] ) ){
                    wp_clear_scheduled_hook( 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] );
                }

                do_action( 'better_messages_user_unrestricted', $thread_id, $user_id, $type );
            }

            return $result;
        }

        private function cache_delete( $thread_id, $user_id ){
            wp_cache_delete( $this->user_thread_cache_key( $thread_id, $user_id ), 'bm_messages' );
            wp_cache_delete( $this->thread_cache_key( $thread_id ), 'bm_messages' );
        }

        public function approve_message( int $message_id, ?int $approver_user_id = null ): bool
        {
            global $wpdb;

            $message = Better_Messages()->functions->get_message( $message_id );

            if( ! $message ){
                return false;
            }

            if( $message->is_pending === 0 ){
                return false;
            }

            if( $this->review_copy( $message_id ) ){
                return false;
            }

            if( Better_Messages()->anti_spam->restore_message( $message_id ) ){
                return true;
            }

            if( $approver_user_id === null ){
                $approver_user_id = Better_Messages()->functions->get_current_user_id();
            }

            $table = bm_get_table( 'messages');
            $new_time = Better_Messages()->functions->get_microtime();

            $sql = $wpdb->prepare( "UPDATE $table SET is_pending = 0, created_at = %d, updated_at = %d WHERE id = %d", $new_time, $new_time, $message_id );
            $wpdb->query( $sql );

            Better_Messages()->functions->update_message_meta( $message->id, 'original_sent_time', $message->created_at );
            Better_Messages()->functions->update_message_meta( $message->id, 'approver_user_id', $approver_user_id );

            $saved_message = Better_Messages()->functions->get_message_meta( $message->id, 'pending_args' );
            Better_Messages()->functions->delete_message_meta( $message->id, 'pending_args' );

            Better_Messages()->functions->delete_message_meta( $message->id, 'ai_moderation_flagged' );
            Better_Messages()->functions->delete_message_meta( $message->id, 'ai_moderation_categories' );
            Better_Messages()->functions->delete_message_meta( $message->id, 'ai_moderation_result' );

            if( is_a( $saved_message, 'BM_Messages_Message' ) ){
                $saved_message->created_at    = $new_time;
                $saved_message->is_pending    = 0;
                $saved_message->push_deferred = false;

                if( isset( $saved_message->push_intent ) ){
                    $saved_message->send_push = (bool) $saved_message->push_intent;
                }

                if( isset( $saved_message->mobile_push_intent ) ){
                    $saved_message->mobile_push = (bool) $saved_message->mobile_push_intent;
                }

                Better_Messages()->mentions->process_mentions( (int) $message->thread_id, (int) $message->id, $message->message );

                do_action_ref_array( 'better_messages_message_sent', array( &$saved_message ) );
            }

            Better_Messages()->functions->update_user_meta( $message->sender_id, 'bm_has_approved_message', '1' );

            do_action( 'better_messages_thread_updated', $message->thread_id );

            if( (int) $message->is_pending === 1 ){
                $this->review_queue_changed();
            }

            return true;
        }

        public function approve_all_pending_messages_from_user( int $user_id, ?int $approver_user_id = null ): int
        {
            global $wpdb;

            $table = bm_get_table( 'messages');

            $message_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE sender_id = %d AND is_pending = 1",
                $user_id
            ));

            if( empty( $message_ids ) ){
                return 0;
            }

            $approved_count = 0;

            foreach( $message_ids as $message_id ){
                if( $this->approve_message( (int) $message_id, $approver_user_id ) ){
                    $approved_count++;
                }
            }

            return $approved_count;
        }

        public function delete_all_pending_messages_from_user( int $user_id ): int
        {
            global $wpdb;

            $table = bm_get_table( 'messages' );

            $message_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT id FROM {$table} WHERE sender_id = %d AND is_pending = 1",
                $user_id
            ));

            if( empty( $message_ids ) ){
                return 0;
            }

            $deleted_count = 0;

            foreach( $message_ids as $message_id ){
                $result = Better_Messages()->functions->delete_message( (int) $message_id );
                if( $result ){
                    $deleted_count++;
                }
            }

            return $deleted_count;
        }

        public function get_notification_emails()
        {
            $emails_raw = Better_Messages()->settings['messagesModerationNotificationEmails'];

            if( empty( $emails_raw ) ){
                return [];
            }

            $emails = array_filter( array_map( 'trim', explode( "\n", $emails_raw ) ) );

            $valid_emails = array_filter( $emails, 'is_email' );

            return $valid_emails;
        }

        public function send_plain_email( $emails, $subject, $body )
        {
            $subject = html_entity_decode( (string) $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $body    = html_entity_decode( (string) $body, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            $plain_text = function() {
                return 'text/plain';
            };

            add_filter( 'wp_mail_content_type', $plain_text, PHP_INT_MAX );

            foreach( $emails as $email ){
                wp_mail( $email, $subject, $body );
            }

            remove_filter( 'wp_mail_content_type', $plain_text, PHP_INT_MAX );
        }

        public function notify_pending_message( $message )
        {
            if( ! isset( $message->id ) || ! isset( $message->sender_id ) || ! isset( $message->thread_id ) ){
                return;
            }

            if( empty( $this->get_notification_emails() ) ){
                return;
            }

            $digest = $this->held_digest();

            $digest['held'][] = array(
                'id'  => (int) $message->id,
                'why' => $this->pending_reasons( $message ),
            );

            $digest['held'] = array_slice( $digest['held'], -100 );

            update_option( self::HELD_DIGEST, $digest, false );

            $this->send_held_digest( $message );
        }

        public function schedule_held_digest()
        {
            if ( function_exists( 'Better_Messages_Worker' ) ) {
                Better_Messages_Worker()->add_task( 'better_messages_held_digest', 5 * MINUTE_IN_SECONDS );
            }
        }

        private function held_digest()
        {
            $digest = get_option( self::HELD_DIGEST, array() );

            return array(
                'held' => isset( $digest['held'] ) && is_array( $digest['held'] ) ? array_values( $digest['held'] ) : array(),
                'sent' => isset( $digest['sent'] ) ? (int) $digest['sent'] : 0,
            );
        }

        public function held_digest_interval()
        {
            return max( 0, (int) apply_filters( 'better_messages_held_digest_interval', max( 0, (int) ( Better_Messages()->settings['spamDigestHours'] ?? 3 ) ) * HOUR_IN_SECONDS ) );
        }

        public function send_held_digest( $fresh = null )
        {
            $digest = $this->held_digest();

            if ( empty( $digest['held'] ) || time() - $digest['sent'] < $this->held_digest_interval() ) {
                return false;
            }

            $emails   = $this->get_notification_emails();
            $held     = array();
            $fresh_id = is_object( $fresh ) && isset( $fresh->id ) ? (int) $fresh->id : 0;

            foreach ( $digest['held'] as $item ) {
                $id = isset( $item['id'] ) ? (int) $item['id'] : 0;

                if ( $id !== 0 && $id === $fresh_id ) {
                    $held[ $id ] = array( 'message' => $fresh, 'why' => isset( $item['why'] ) ? (array) $item['why'] : array() );
                    continue;
                }

                $message = $id !== 0 ? Better_Messages()->functions->get_message( $id ) : null;

                if ( $message && (int) $message->is_pending === 1 ) {
                    $held[ (int) $message->id ] = array( 'message' => $message, 'why' => isset( $item['why'] ) ? (array) $item['why'] : array() );
                }
            }

            if ( empty( $emails ) || empty( $held ) ) {
                update_option( self::HELD_DIGEST, array( 'held' => array(), 'sent' => $digest['sent'] ), false );
                return false;
            }

            update_option( self::HELD_DIGEST, array( 'held' => array(), 'sent' => time() ), false );

            $count   = count( $held );
            $heading = sprintf( _nx( '%d message is waiting for approval', '%d messages are waiting for approval', $count, 'Moderation email', 'bp-better-messages' ), $count );
            $subject = html_entity_decode( sprintf( '[%s] %s', get_bloginfo( 'name' ), $heading ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $blocks  = array();

            foreach ( array_slice( $held, 0, 20 ) as $item ) {
                $blocks[] = Better_Messages()->notifications->moderator_message_block( $item['message'], $this->attachment_lines( $item['message'] ) );

                $blocks[] = array(
                    'type' => 'system',
                    'text' => implode( ' · ', array_merge(
                        array( sprintf( _x( 'Conversation #%d', 'Moderation email', 'bp-better-messages' ), (int) $item['message']->thread_id ) ),
                        $item['why']
                    ) ),
                );
            }

            if ( $count > 20 ) {
                $blocks[] = array( 'type' => 'text', 'text' => sprintf( _x( 'And %d more in Administration', 'Moderation email', 'bp-better-messages' ), $count - 20 ) );
            }

            return Better_Messages()->notifications->moderator_email( $emails, $subject, array(
                'preheader' => _x( 'Held for a moderator, and not delivered until approved', 'Moderation email', 'bp-better-messages' ),
                'heading'   => $heading,
                'subheading'=> _x( 'They are not delivered until a moderator approves them', 'Moderation email', 'bp-better-messages' ),
                'blocks'    => $blocks,
                'button'    => array( 'label' => _x( 'Review messages', 'Moderation email', 'bp-better-messages' ), 'url' => admin_url( 'admin.php?page=better-messages-viewer#/review?reason=held' ) ),
            ) );
        }

        private function pending_reasons( $message )
        {
            $sender_id = (int) $message->sender_id;
            $why       = array();
            $spam      = Better_Messages()->anti_spam;

            foreach ( $spam->message_rules( (int) $message->id ) as $rule ) {
                $why[] = $spam->rule_words( $rule );
            }

            if ( ! empty( $message->ai_moderation_result ) ) {
                $why[] = _x( 'AI Content Moderation', 'Moderation email', 'bp-better-messages' );

                if ( ! empty( $message->ai_moderation_result['flagged_categories'] ) ) {
                    $why[] = sprintf( _x( 'AI Flagged Categories: %s', 'Moderation email', 'bp-better-messages' ), implode( ', ', (array) $message->ai_moderation_result['flagged_categories'] ) );
                }

                if ( ! empty( $message->ai_moderation_result['reason'] ) ) {
                    $why[] = sprintf( _x( 'AI Reason: %s', 'Moderation email', 'bp-better-messages' ), $message->ai_moderation_result['reason'] );
                }
            }

            $review = empty( $why ) && $spam->is_under_review( $sender_id ) ? $spam->review_rules( $sender_id ) : array();

            if ( $review ) {
                $why[] = _x( 'The account is under review', 'Moderation email', 'bp-better-messages' );

                foreach ( $review as $rule ) {
                    $why[] = $spam->rule_words( $rule );
                }
            }

            if ( empty( $why ) && Better_Messages()->settings['messagesModerateFirstTimeSenders'] === '1' && $this->is_first_time_sender( $sender_id ) ) {
                $why[] = _x( 'First-time sender', 'Moderation email', 'bp-better-messages' );
            }

            if ( empty( $why ) && $this->get_user_moderation_status( $sender_id, (int) $message->thread_id ) === true ) {
                $why[] = _x( 'User is blacklisted', 'Moderation email', 'bp-better-messages' );
            }

            if ( empty( $why ) ) {
                $why[] = _x( 'Pre-moderation rules', 'Moderation email', 'bp-better-messages' );
            }

            return array_values( array_unique( $why ) );
        }

        private function attachment_lines( $message )
        {
            $lines       = array();
            $attachments = Better_Messages()->functions->get_message_meta( (int) $message->id, 'attachments', true );

            if ( ! is_array( $attachments ) ) {
                return $lines;
            }

            foreach ( array_keys( $attachments ) as $att_id ) {
                if ( Better_Messages()->encryption->files()->is_encrypted( $att_id ) ) {
                    $lines[] = Better_Messages_Storage()->original_name( $att_id );
                    continue;
                }

                $url = Better_Messages_Storage()->get_file_url( $att_id, 'email' );

                if ( ! $url ) {
                    $url = wp_get_attachment_url( $att_id );
                }

                if ( $url ) {
                    $lines[] = $url;
                }
            }

            return $lines;
        }

        public function notify_reported_message( $message_id, $thread_id, $reporter_user_id, $category, $description, $reports )
        {
            $emails = $this->get_notification_emails();

            if( empty( $emails ) ){
                return;
            }

            $message = Better_Messages()->functions->get_message( $message_id );
            if( ! $message ){
                return;
            }

            $reporter_name = Better_Messages()->functions->get_plain_name( $reporter_user_id );

            $sender_id = $message->sender_id;
            $sender_name = Better_Messages()->functions->get_plain_name( $sender_id );

            $moderation_url = admin_url( 'admin.php?page=better-messages-viewer#/review?reason=reported' );

            $category_label = $category;

            if ( class_exists( 'Better_Messages_User_Reports' ) ) {
                $categories = Better_Messages_User_Reports::instance()->get_categories( $message_id, $thread_id );

                if ( isset( $categories[ $category ] ) ) {
                    $category_label = $categories[ $category ];
                }
            }

            $subject = sprintf(
                _x( '[%s] Message reported', 'Moderation email subject', 'bp-better-messages' ),
                get_bloginfo( 'name' )
            );

            $message_content = apply_filters( 'better_messages_moderation_message_content',
                wp_trim_words( wp_strip_all_tags( Better_Messages_Mentions::mentions_as_names( $message->message ) ), 50, '...' ),
                $message
            );
            $report_count = count( $reports );

            $email_body = sprintf(
                _x( "A message has been reported on %s\n\nReported by: %s\nReason: %s\nDescription: %s\n\nOriginal message from: %s\nMessage: %s\n\nTotal reports for this message: %d\n\nModeration panel: %s", 'Moderation email body', 'bp-better-messages' ),
                get_bloginfo( 'name' ),
                $reporter_name,
                $category_label,
                $description,
                $sender_name,
                $message_content,
                $report_count,
                $moderation_url
            );

            $this->send_plain_email( $emails, $subject, $email_body );
        }

    }

endif;

function Better_Messages_Moderation(){
    return Better_Messages_Moderation::instance();
}
