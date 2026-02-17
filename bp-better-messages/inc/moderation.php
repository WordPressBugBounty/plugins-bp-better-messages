<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Moderation' ) ):

    class Better_Messages_Moderation
    {
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
            add_action('rest_api_init',  array( $this, 'rest_api_init' ) );
            add_action('better_messages_clean_expired_ban', array( $this, 'clean_expired_ban'), 10, 2 );
            add_action('better_messages_message_sent', array( $this, 'mark_user_as_approved_sender' ), 10, 1 );
            add_action('better_messages_message_pending', array( $this, 'notify_pending_message' ), 10, 1 );
            add_action('better_messages_message_reported', array( $this, 'notify_reported_message' ), 10, 6 );

            add_action( 'show_user_profile', array( $this, 'render_user_section' ) );
            add_action( 'edit_user_profile', array( $this, 'render_user_section' ) );
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

        /*
         * Check if a message must be pre moderated for this user in this thread
         *
         * @param int $user_id User ID to check
         * @param int|null $thread_id Thread ID (optional)
         * @param bool $is_new_conversation Whether this is a new conversation (true) or a reply (false)
         * @return bool True if moderation is required, false otherwise
         */
        public function is_moderation_enabled( $user_id, ?int $thread_id = null, $is_new_conversation = false )
        {
            // Administrators are always exempt from moderation
            if( user_can( $user_id, 'bm_can_administrate' ) ){
                return false;
            }

            $result = false;

            if( Better_Messages()->settings['messagesPremoderation'] === '1' ) {
                // Check if user is sending their first message ever
                if( Better_Messages()->settings['messagesModerateFirstTimeSenders'] === '1' ) {
                    if( $this->is_first_time_sender( $user_id ) ) {
                        $result = true;
                    }
                }

                // Check role-based moderation only if not already flagged for moderation
                if( ! $result ) {
                    // Determine which roles list to check based on conversation type
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

                // Check blacklist/whitelist status in single query
                $moderation_status = $this->get_user_moderation_status( $user_id, $thread_id );

                if( $moderation_status !== null ){
                    $result = $moderation_status;
                }
            }

            return apply_filters( 'better_messages_is_moderation_enabled', $result, $user_id, $thread_id, $is_new_conversation );
        }

        /**
         * Check if user is sending their first message
         *
         * @param int $user_id User ID to check
         * @return bool True if user has never had an approved message before, false otherwise
         */
        public function is_first_time_sender( $user_id )
        {
            // Check user meta to see if they've had an approved message
            $has_approved_message = Better_Messages()->functions->get_user_meta( $user_id, 'bm_has_approved_message', true );

            // If meta is set to '1', user has had at least one approved message
            if( $has_approved_message === '1' ){
                return false;
            }

            // Meta not set - check if user has any sent messages in the database
            // This handles users who sent messages before moderation was enabled
            global $wpdb;
            $table = bm_get_table('messages');
            $has_messages = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$table} WHERE sender_id = %d AND is_pending = 0 LIMIT 1",
                $user_id
            ));

            if( $has_messages ){
                // User has sent messages before, mark them as approved sender for future checks
                Better_Messages()->functions->update_user_meta( $user_id, 'bm_has_approved_message', '1' );
                return false;
            }

            // No messages found, they are a first-time sender
            return true;
        }

        /**
         * Mark user as having sent an approved message
         * Called when a message is sent without moderation or when a pending message is approved
         *
         * @param BM_Messages_Message $message Message object
         */
        public function mark_user_as_approved_sender( $message )
        {
            if( ! isset( $message->sender_id ) ){
                return;
            }

            // Mark user as having an approved message
            Better_Messages()->functions->update_user_meta( $message->sender_id, 'bm_has_approved_message', '1' );
        }

        /**
         * Get user moderation status (blacklist/whitelist) in a single query
         *
         * Checks both thread-specific and global moderation status.
         * Priority: thread-specific whitelist > thread-specific blacklist > global blacklist
         * (when thread_id is provided) or global whitelist > global blacklist (when thread_id is null)
         *
         * @param int $user_id User ID to check
         * @param int|null $thread_id Thread ID for conversation-specific check (optional)
         * @return bool|null True if blacklisted (force moderation), false if whitelisted (bypass), null if no match
         */
        public function get_user_moderation_status( int $user_id, ?int $thread_id = null ): ?bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            // Build cache key
            $cache_key = $thread_id !== null
                ? 'bm_mod_status_' . $user_id . '_' . $thread_id
                : 'bm_mod_status_global_' . $user_id;

            $cached = wp_cache_get( $cache_key, 'bm_messages' );

            if( $cached !== false ){
                if( $cached === 'whitelist' ) return false;
                if( $cached === 'blacklist' ) return true;
                return null; // 'none'
            }

            if( $thread_id !== null ){
                // When thread_id is provided:
                // - Whitelist: check thread-specific OR global
                // - Blacklist: check thread-specific OR global
                // Priority order: thread whitelist (1) > global whitelist (2) > thread blacklist (3) > global blacklist (4)
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
                // When thread_id is null: check only global entries
                // Priority: whitelist (1) > blacklist (2)
                $query = $wpdb->prepare(
                    "SELECT `type`,
                        CASE
                            WHEN `type` = 'bypass_moderation' THEN 1
                            WHEN `type` = 'force_moderation' THEN 2
                        END as priority
                    FROM {$table}
                    WHERE `user_id` = %d
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

        /**
         * Check if user is whitelisted from premoderation
         *
         * @param int $user_id User ID to check
         * @param int|null $thread_id Thread ID for conversation-specific whitelist (optional)
         * @return bool True if user is whitelisted and doesn't need premoderation
         */
        public function is_user_whitelisted( int $user_id, ?int $thread_id = null ): bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            // Build cache key based on context
            $cache_key = $thread_id !== null
                ? 'bm_whitelist_' . $user_id . '_' . $thread_id
                : 'bm_whitelist_global_' . $user_id;

            $cached = wp_cache_get( $cache_key, 'bm_messages' );

            if( $cached !== false ){
                return (bool) $cached;
            }

            if( $thread_id !== null ){
                // Check thread-specific only
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
                // Check only global
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

        /**
         * Whitelist user from premoderation
         *
         * @param int $user_id User ID to whitelist
         * @param int|null $thread_id Thread ID for conversation-specific whitelist (null or 0 for global)
         * @param int|null $duration Duration in minutes (null for permanent)
         * @return bool True on success, false on failure
         */
        public function whitelist_user( int $user_id, ?int $thread_id = null, ?int $duration = null ): bool
        {
            global $wpdb;

            // Administrators cannot be whitelisted - they are already exempt from moderation
            if( user_can( $user_id, 'bm_can_administrate' ) ){
                return false;
            }

            $admin_id = Better_Messages()->functions->get_current_user_id();
            $table = bm_get_table('moderation');

            // Use 0 for global whitelist
            if( $thread_id === null ){
                $thread_id = 0;
            }

            // Remove from blacklist first (mutual exclusivity)
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE `type` = 'force_moderation'
                AND `user_id` = %d
                AND `thread_id` = %d",
                $user_id,
                $thread_id
            ));

            // Prepare expiration
            if( $duration === null || $duration <= 0 ){
                // Permanent whitelist
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
                // Time-limited whitelist
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

            // Clear caches
            if( $thread_id > 0 ){
                wp_cache_delete( 'bm_whitelist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_blacklist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_mod_status_' . $user_id . '_' . $thread_id, 'bm_messages' );
            }
            wp_cache_delete( 'bm_whitelist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_blacklist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_mod_status_global_' . $user_id, 'bm_messages' );

            // Schedule cleanup if time-limited
            if( $duration !== null && $duration > 0 ){
                $seconds = $duration * 60;
                if( ! wp_get_scheduled_event( 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] ) ){
                    wp_schedule_single_event( time() + $seconds + 1, 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] );
                }
            }

            // Always return true - idempotent operation (already whitelisted is still success)
            return true;
        }

        /**
         * Remove user from the whitelist
         *
         * @param int $user_id User ID to remove from whitelist
         * @param int|null $thread_id Thread ID for conversation-specific whitelist (null or 0 for global)
         * @return bool True on success, false on failure
         */
        public function unwhitelist_user( int $user_id, ?int $thread_id = null ): bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            // Use 0 for global whitelist
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

            // Clear caches
            if( $thread_id > 0 ){
                wp_cache_delete( 'bm_whitelist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_mod_status_' . $user_id . '_' . $thread_id, 'bm_messages' );
            }
            wp_cache_delete( 'bm_whitelist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_mod_status_global_' . $user_id, 'bm_messages' );

            // Always return true - idempotent operation (not whitelisted is still success)
            return true;
        }

        /**
         * Get whitelisted users
         *
         * @param int|null $thread_id Thread ID for conversation-specific whitelist (null or 0 for global)
         * @return array Array of whitelisted user data
         */
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

        /**
         * Check if user is blacklisted for premoderation
         *
         * @param int $user_id User ID to check
         * @param int|null $thread_id Thread ID for conversation-specific blacklist (optional)
         * @return bool True if user is blacklisted and must be premoderated
         */
        public function is_user_blacklisted( int $user_id, ?int $thread_id = null ): bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            // Build cache key based on context
            $cache_key = $thread_id !== null
                ? 'bm_blacklist_' . $user_id . '_' . $thread_id
                : 'bm_blacklist_global_' . $user_id;

            $cached = wp_cache_get( $cache_key, 'bm_messages' );

            if( $cached !== false ){
                return (bool) $cached;
            }

            if( $thread_id !== null ){
                // Check thread-specific OR global in one query
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
                // Check only global
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

        /**
         * Blacklist user for premoderation
         *
         * @param int $user_id User ID to blacklist
         * @param int|null $thread_id Thread ID for conversation-specific blacklist (null or 0 for global)
         * @param int|null $duration Duration in minutes (null for permanent)
         * @return bool True on success, false on failure
         */
        public function blacklist_user( int $user_id, ?int $thread_id = null, ?int $duration = null ): bool
        {
            global $wpdb;

            // Administrators cannot be blacklisted
            if( user_can( $user_id, 'bm_can_administrate' ) ){
                return false;
            }

            $admin_id = Better_Messages()->functions->get_current_user_id();
            $table = bm_get_table('moderation');

            // Use 0 for global blacklist
            if( $thread_id === null ){
                $thread_id = 0;
            }

            // Remove from whitelist first (mutual exclusivity)
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table}
                WHERE `type` = 'bypass_moderation'
                AND `user_id` = %d
                AND `thread_id` = %d",
                $user_id,
                $thread_id
            ));

            // Prepare expiration
            if( $duration === null || $duration <= 0 ){
                // Permanent blacklist
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
                // Time-limited blacklist
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

            // Clear caches
            if( $thread_id > 0 ){
                wp_cache_delete( 'bm_blacklist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_whitelist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_mod_status_' . $user_id . '_' . $thread_id, 'bm_messages' );
            }
            wp_cache_delete( 'bm_blacklist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_whitelist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_mod_status_global_' . $user_id, 'bm_messages' );

            // Schedule cleanup if time-limited
            if( $duration !== null && $duration > 0 ){
                $seconds = $duration * 60;
                if( ! wp_get_scheduled_event( 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] ) ){
                    wp_schedule_single_event( time() + $seconds + 1, 'better_messages_clean_expired_ban', [ $thread_id, $user_id ] );
                }
            }

            // Always return true - idempotent operation (already blacklisted is still success)
            return true;
        }

        /**
         * Remove user from blacklist
         *
         * @param int $user_id User ID to remove from blacklist
         * @param int|null $thread_id Thread ID for conversation-specific blacklist (null or 0 for global)
         * @return bool True on success, false on failure
         */
        public function unblacklist_user( int $user_id, ?int $thread_id = null ): bool
        {
            global $wpdb;

            $table = bm_get_table('moderation');

            // Use 0 for global blacklist
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

            // Clear caches
            if( $thread_id > 0 ){
                wp_cache_delete( 'bm_blacklist_' . $user_id . '_' . $thread_id, 'bm_messages' );
                wp_cache_delete( 'bm_mod_status_' . $user_id . '_' . $thread_id, 'bm_messages' );
            }
            wp_cache_delete( 'bm_blacklist_global_' . $user_id, 'bm_messages' );
            wp_cache_delete( 'bm_mod_status_global_' . $user_id, 'bm_messages' );

            // Always return true - idempotent operation (not blacklisted is still success)
            return true;
        }

        /**
         * Get blacklisted users
         *
         * @param int|null $thread_id Thread ID for conversation-specific blacklist (null or 0 for global)
         * @return array Array of blacklisted user data
         */
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

        public function rest_api_init(){
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
        }

        public function ban_user_api( WP_REST_Request $request ){
            $thread_id       = intval( $request->get_param('id') );
            $user_id         = intval( $request->get_param('user_id') );
            $duration        = intval( $request->get_param('duration') );
            $current_user_id = Better_Messages()->functions->get_current_user_id();

            $can_mute = Better_Messages()->functions->can_moderate_thread( $thread_id, $current_user_id );
            $is_participant = Better_Messages()->functions->is_thread_participant( $user_id, $thread_id, true );

            if( ! $can_mute || ! $is_participant ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $result = $this->restrict_user( 'ban', $user_id, $thread_id, $duration );

            if( $result ){
                Better_Messages()->functions->remove_participant_from_thread( $thread_id, $user_id );
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

            $result = $this->un_restrict_user( 'ban', $user_id, $thread_id );

            if( $result ){
                return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
            }

            return false;
        }

        public function mute_user_api( WP_REST_Request $request ){
            $thread_id       = intval( $request->get_param('id') );
            $user_id         = intval( $request->get_param('user_id') );
            $duration        = intval( $request->get_param('duration') );
            $current_user_id = Better_Messages()->functions->get_current_user_id();

            $can_mute = Better_Messages()->functions->can_moderate_thread( $thread_id, $current_user_id );
            $is_participant = Better_Messages()->functions->is_thread_participant( $user_id, $thread_id, true );

            if( ! $can_mute || ! $is_participant ){
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
            $is_participant = Better_Messages()->functions->is_thread_participant( $user_id, $thread_id, true );

            if( ! $can_mute || ! $is_participant ){
                return new WP_Error(
                    'rest_forbidden',
                    _x( 'Sorry, you are not allowed to do that', 'Rest API Error', 'bp-better-messages' ),
                    array( 'status' => rest_authorization_required_code() )
                );
            }

            $result = $this->un_restrict_user( 'mute', $user_id, $thread_id );

            if( $result ){
                return Better_Messages()->api->get_threads( [ $thread_id ], false, false );
            }

            return false;
        }

        public function can_send_reply( $allowed, $user_id, $thread_id ){
            $thread_type = Better_Messages()->functions->get_thread_type( $thread_id );

            if( $thread_type === 'chat-room' ){
                $restrictions = $this->is_user_restricted( $thread_id, $user_id );

                if( isset( $restrictions['mute'] ) ){
                    $allowed = false;

                    $expiration = $restrictions['mute'];

                    $time = $this->format_time($expiration);

                    /**
                     * With this global variable you can add extra message which will replace editor field
                     */
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
            SELECT id, thread_id, user_id
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
                }
            }
        }

        public function clean_expired_ban( $thread_id, $user_id ){
            global $wpdb;

            $table = bm_get_table('moderation');

            $results = $wpdb->get_results( $wpdb->prepare("
            SELECT id, thread_id, user_id
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
                }
            }
        }

        public function get_restricted_users( int $thread_id ){
            $key = $this->thread_cache_key( $thread_id );

            $restricted_users = wp_cache_get( $key, 'bm_messages' );

            if( $restricted_users ){
                return $restricted_users;
            }

            global $wpdb;

            $table = bm_get_table('moderation');

            $query = $wpdb->prepare("
            SELECT user_id, type, CONVERT_TZ(expiration, @@session.time_zone, '+0:00') as expiration 
            FROM  {$table} 
            WHERE `thread_id` = %d
            AND `type` = 'ban' OR ( `type` = 'mute' AND `user_id` IN ( SELECT user_id FROM " . bm_get_table('recipients') . " WHERE thread_id = %d AND is_deleted = 0 ) )
            AND `expiration` > NOW()", $thread_id, $thread_id);

            $results = $wpdb->get_results( $query );

            $result = [];

            if( count( $results ) > 0 ){
                foreach( $results as $item ){
                    $type = $item->type;
                    if( ! isset( $result[$type] ) ) $result[$type] = [];
                    $result[$type][ $item->user_id ] = strtotime( $item->expiration );
                }
            }

            if( isset( $result['ban'] ) && isset( $result['mute'] ) ){
                foreach( $result['ban'] as $key => $value ){
                    if( isset( $result['mute'][$key] ) ) unset(  $result['mute'][$key] );
                }

                if( empty( $result['mute'] ) ) unset($result['mute']);
            }

            wp_cache_set( $key, $result, 'bm_messages' );

            if( empty( $result ) ) return (object) [];

            return $result;
        }

        public function is_user_restricted( int $thread_id, int $user_id ){
            $key = $this->user_thread_cache_key( $thread_id, $user_id );

            $restrictions = wp_cache_get( $key, 'bm_messages' );

            if( $restrictions ){
                return $restrictions;
            }

            global $wpdb;

            $table = bm_get_table('moderation');
            $query = $wpdb->prepare("SELECT type, expiration FROM {$table} WHERE `thread_id` = %d AND `user_id` = %d AND `expiration` > NOW()", $thread_id, $user_id);

            $array = [];
            $results = $wpdb->get_results( $query );

            if( count( $results ) > 0 ){
                foreach ( $results as $result ){
                    $array[$result->type] = $result->expiration;
                }
            }

            wp_cache_set($key, $array, 'bm_messages');

            return $array;
        }

        public function restrict_user( string $type, int $user_id, int $thread_id, int $time = 1 ){
            global $wpdb;

            $admin_id = Better_Messages()->functions->get_current_user_id();
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
            }

            return $result;
        }

        private function cache_delete( $thread_id, $user_id ){
            wp_cache_delete( $this->user_thread_cache_key( $thread_id, $user_id ), 'bm_messages' );
            wp_cache_delete( $this->thread_cache_key( $thread_id ), 'bm_messages' );
        }

        /**
         * Approve a pending message
         *
         * @param int $message_id Message ID to approve
         * @param int|null $approver_user_id User ID who approved (defaults to current user)
         * @return bool True on success, false on failure
         */
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

            if( $approver_user_id === null ){
                $approver_user_id = Better_Messages()->functions->get_current_user_id();
            }

            $table = bm_get_table( 'messages');
            $new_time = Better_Messages()->functions->get_microtime();

            // Set the message to not pending anymore and update the time it was sent so it appears just like a normal message with current time.
            $sql = $wpdb->prepare( "UPDATE $table SET is_pending = 0, created_at = %d, updated_at = %d WHERE id = %d", $new_time, $new_time, $message_id );
            $wpdb->query( $sql );

            // When was the message originally sent?
            Better_Messages()->functions->update_message_meta( $message->id, 'original_sent_time', $message->created_at );
            // Who approved the message?
            Better_Messages()->functions->update_message_meta( $message->id, 'approver_user_id', $approver_user_id );

            $saved_message = Better_Messages()->functions->get_message_meta( $message->id, 'pending_args' );
            Better_Messages()->functions->delete_message_meta( $message->id, 'pending_args' );

            // Clear AI moderation flags since admin has approved the message
            Better_Messages()->functions->delete_message_meta( $message->id, 'ai_moderation_flagged' );
            Better_Messages()->functions->delete_message_meta( $message->id, 'ai_moderation_categories' );
            Better_Messages()->functions->delete_message_meta( $message->id, 'ai_moderation_result' );

            if( is_a( $saved_message, 'BM_Messages_Message' ) ){
                $saved_message->created_at = $new_time;
                do_action_ref_array( 'better_messages_message_sent', array( &$saved_message ) );
            }

            // Mark user as having an approved message so they are no longer a first-time sender
            Better_Messages()->functions->update_user_meta( $message->sender_id, 'bm_has_approved_message', '1' );

            // Update thread timestamp for AJAX mode
            do_action( 'better_messages_thread_updated', $message->thread_id );

            return true;
        }

        /**
         * Approve all pending messages from a user
         *
         * @param int $user_id User ID whose messages to approve
         * @param int|null $approver_user_id User ID who approved (defaults to current user)
         * @return int Number of messages approved
         */
        public function approve_all_pending_messages_from_user( int $user_id, ?int $approver_user_id = null ): int
        {
            global $wpdb;

            $table = bm_get_table( 'messages');

            // Get all pending message IDs from this user
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

        /**
         * Delete all pending messages from a user
         *
         * @param int $user_id User ID whose pending messages to delete
         * @return int Number of messages deleted
         */
        public function delete_all_pending_messages_from_user( int $user_id ): int
        {
            global $wpdb;

            $table = bm_get_table( 'messages' );

            // Get all pending message IDs from this user
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

        /**
         * Get list of email addresses for moderation notifications
         *
         * @return array Array of valid email addresses
         */
        private function get_notification_emails()
        {
            $emails_raw = Better_Messages()->settings['messagesModerationNotificationEmails'];

            if( empty( $emails_raw ) ){
                return [];
            }

            // Split by newlines and filter out empty lines
            $emails = array_filter( array_map( 'trim', explode( "\n", $emails_raw ) ) );

            // Validate email addresses
            $valid_emails = array_filter( $emails, 'is_email' );

            return $valid_emails;
        }

        /**
         * Send email notification when a message is pending moderation
         *
         * @param BM_Messages_Message $message Message object
         */
        public function notify_pending_message( $message )
        {
            if( ! isset( $message->sender_id ) || ! isset( $message->thread_id ) ){
                return;
            }

            $emails = $this->get_notification_emails();

            if( empty( $emails ) ){
                return;
            }

            // Get sender information using rest_user_item (handles both regular users and guests)
            $sender_id = $message->sender_id;
            $sender_item = Better_Messages()->functions->rest_user_item( $sender_id, false );
            $sender_name = $sender_item['name'];

            $thread_id = $message->thread_id;
            $moderation_url = admin_url( 'admin.php?page=better-messages-viewer' );

            $subject = sprintf(
                _x( '[%s] New message pending moderation', 'Moderation email subject', 'bp-better-messages' ),
                get_bloginfo( 'name' )
            );

            $message_content = wp_trim_words( wp_strip_all_tags( $message->message ), 50, '...' );

            // Determine reason for pending
            $reason = _x( 'Pre-moderation rules', 'Moderation email', 'bp-better-messages' );

            if( ! empty( $message->ai_moderation_result ) ) {
                $reason = _x( 'AI Content Moderation', 'Moderation email', 'bp-better-messages' );
            } else if( Better_Messages()->settings['messagesModerateFirstTimeSenders'] === '1' && $this->is_first_time_sender( $sender_id ) ) {
                $reason = _x( 'First-time sender', 'Moderation email', 'bp-better-messages' );
            } else {
                $moderation_status = $this->get_user_moderation_status( $sender_id, $thread_id );
                if( $moderation_status === true ) {
                    $reason = _x( 'User is blacklisted', 'Moderation email', 'bp-better-messages' );
                }
            }

            // Build email body
            $email_body  = sprintf( _x( 'Sender: %s (ID: %d)', 'Moderation email', 'bp-better-messages' ), $sender_name, $sender_id ) . "\n";
            $email_body .= sprintf( _x( 'Conversation: #%d', 'Moderation email', 'bp-better-messages' ), $thread_id ) . "\n";
            $email_body .= sprintf( _x( 'Reason: %s', 'Moderation email', 'bp-better-messages' ), $reason ) . "\n";

            if( ! empty( $message->ai_moderation_result ) && ! empty( $message->ai_moderation_result['flagged_categories'] ) ) {
                $categories = implode( ', ', $message->ai_moderation_result['flagged_categories'] );
                $email_body .= sprintf( _x( 'AI Flagged Categories: %s', 'Moderation email', 'bp-better-messages' ), $categories ) . "\n";
            }

            $email_body .= "\n" . sprintf( _x( 'Message: %s', 'Moderation email', 'bp-better-messages' ), $message_content ) . "\n\n";
            $email_body .= sprintf( _x( 'Review in moderation panel: %s', 'Moderation email', 'bp-better-messages' ), $moderation_url );

            foreach( $emails as $email ){
                wp_mail( $email, $subject, $email_body );
            }
        }

        /**
         * Send email notification when a message is reported
         *
         * @param int $message_id Message ID
         * @param int $thread_id Thread ID
         * @param int $reporter_user_id User ID who reported
         * @param string $category Report category
         * @param string $description Report description
         * @param array $reports All reports for this message
         */
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

            // Get reporter information using rest_user_item (handles both regular users and guests)
            $reporter_item = Better_Messages()->functions->rest_user_item( $reporter_user_id, false );
            $reporter_name = $reporter_item['name'];

            // Get message sender information using rest_user_item (handles both regular users and guests)
            $sender_id = $message->sender_id;
            $sender_item = Better_Messages()->functions->rest_user_item( $sender_id, false );
            $sender_name = $sender_item['name'];

            $moderation_url = admin_url( 'admin.php?page=better-messages-viewer' );

            $subject = sprintf(
                _x( '[%s] Message reported', 'Moderation email subject', 'bp-better-messages' ),
                get_bloginfo( 'name' )
            );

            $message_content = wp_trim_words( wp_strip_all_tags( $message->message ), 50, '...' );
            $report_count = count( $reports );

            $email_body = sprintf(
                _x( "A message has been reported on %s\n\nReported by: %s\nReason: %s\nDescription: %s\n\nOriginal message from: %s\nMessage: %s\n\nTotal reports for this message: %d\n\nModeration panel: %s", 'Moderation email body', 'bp-better-messages' ),
                get_bloginfo( 'name' ),
                $reporter_name,
                $category,
                $description,
                $sender_name,
                $message_content,
                $report_count,
                $moderation_url
            );

            foreach( $emails as $email ){
                wp_mail( $email, $subject, $email_body );
            }
        }

    }

endif;

function Better_Messages_Moderation(){
    return Better_Messages_Moderation::instance();
}
