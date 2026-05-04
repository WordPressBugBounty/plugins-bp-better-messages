<?php
if ( !class_exists( 'Better_Messages_Rest_Api_DB_Migrate' ) ):

    class Better_Messages_Rest_Api_DB_Migrate
    {

        private $db_version = 2.1;

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Rest_Api_DB_Migrate();
            }

            return $instance;
        }

        public function __construct(){
            add_action( 'wp_ajax_bp_messages_admin_import_options', array( $this, 'import_admin_options' ) );
            add_action( 'wp_ajax_bp_messages_admin_export_options', array( $this, 'export_admin_options' ) );
            add_action( 'wp_ajax_better_messages_admin_reset_database', array( $this, 'reset_database' ) );
            add_action( 'wp_ajax_better_messages_admin_sync_users', array( $this, 'sync_users' ) );
        }

        public function sync_users(){
            $nonce    = $_POST['nonce'];
            if ( ! wp_verify_nonce($nonce, 'bm-sync-users') ){
                exit;
            }

            if( ! current_user_can('manage_options') ){
                exit;
            }

            Better_Messages()->users->sync_all_users();

            wp_send_json("User synchronization is finished");
        }

        public function reset_database(){
            $nonce    = $_POST['nonce'];
            if ( ! wp_verify_nonce($nonce, 'bm-reset-database') ){
                exit;
            }

            if( ! current_user_can('manage_options') ){
                exit;
            }

            $this->drop_tables();
            $this->delete_bulk_reports();
            $this->first_install();

            $settings = get_option( 'bp-better-chat-settings', array() );
            $settings['updateTime'] = time();
            update_option( 'bp-better-chat-settings', $settings );

            do_action('better_messages_reset_database');

            wp_send_json("Database was reset");
        }

        public function export_admin_options(){

            $nonce    = $_POST['nonce'];
            if ( ! wp_verify_nonce($nonce, 'bpbm-import-options') ){
                exit;
            }

            if( ! current_user_can('manage_options') ){
                exit;
            }

            $options = get_option( 'bp-better-chat-settings', array() );
            wp_send_json(base64_encode(json_encode($options)));
        }

        public function import_admin_options(){

            $nonce    = $_POST['nonce'];
            if ( ! wp_verify_nonce($nonce, 'bpbm-import-options') ){
                exit;
            }

            if( ! current_user_can('manage_options') ){
                exit;
            }

            $settings = sanitize_text_field($_POST['settings']);

            $options  = base64_decode( $settings );
            $options  = json_decode( $options, true );

            if( is_null( $options ) ){
                wp_send_json_error('Error to decode data');
            } else {
                update_option( 'bp-better-chat-settings', $options );
                wp_send_json_success('Succesfully imported');
            }
        }

        public function get_tables(){
            return [
                bm_get_table('threads'),
                bm_get_table('threadsmeta'),
                bm_get_table('mentions'),
                bm_get_table('messages'),
                bm_get_table('meta'),
                bm_get_table('recipients'),
                bm_get_table('moderation'),
                bm_get_table('guests'),
                bm_get_table('users'),
                bm_get_table('roles'),
                bm_get_table('bulk_jobs'),
                bm_get_table('bulk_job_threads'),
                bm_get_table('ai_usage'),
            ];
        }

        public function update_collate(){
            global $wpdb;

            $charset   = $wpdb->charset ? $wpdb->charset : 'utf8mb4';
            $collation = $wpdb->collate ? $wpdb->collate : 'utf8mb4_unicode_ci';

            $tables = [
                bm_get_table('mentions'),
                bm_get_table('messages'),
                bm_get_table('meta'),
                bm_get_table('recipients'),
                bm_get_table('threadsmeta'),
                bm_get_table('threads'),
                bm_get_table('moderation'),
                bm_get_table('guests'),
                bm_get_table('users'),
                bm_get_table('roles'),
                bm_get_table('bulk_jobs'),
                bm_get_table('bulk_job_threads'),
                bm_get_table('ai_usage'),
            ];

            foreach( $tables as $table ){
                $wpdb->query( "ALTER TABLE `{$table}` CONVERT TO CHARACTER SET {$charset} COLLATE {$collation};" );
            }

            return null;
        }

        public function delete_bulk_reports(){
            global $wpdb;

            $reports = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE `post_type` = 'bpbm-bulk-report'");

            if( count($reports) > 0 ){
                foreach ( $reports as $report ){
                    wp_delete_post( $report, true );
                }
            }
        }

        public function drop_tables(){
            global $wpdb;
            $drop_tables = $this->get_tables();

            foreach ( $drop_tables as $table ){
                $wpdb->query("DROP TABLE IF EXISTS {$table}");
            }

            delete_option('better_messages_2_db_version');
        }

        public function first_install(){
            set_time_limit(0);
            ignore_user_abort(true);
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

            $sql = [
                "CREATE TABLE `" . bm_get_table('mentions') ."` (
                       `id` bigint(20) NOT NULL AUTO_INCREMENT,
                       `thread_id` bigint(20) NOT NULL,
                       `message_id` bigint(20) NOT NULL,
                       `user_id` bigint(20) NOT NULL,
                       `type` enum('mention','reply','reaction') NOT NULL,
                       PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('messages') ."` (
                      `id` bigint(20) NOT NULL AUTO_INCREMENT,
                      `thread_id` bigint(20) NOT NULL,
                      `sender_id` bigint(20) NOT NULL,
                      `message` longtext NOT NULL,
                      `date_sent` datetime NOT NULL,
                      `created_at` bigint(20) NOT NULL DEFAULT '0',
                      `updated_at` bigint(20) NOT NULL DEFAULT '0',
                      `temp_id` varchar(50) DEFAULT NULL,
                      `is_pending` tinyint(1) NOT NULL DEFAULT '0',
                      PRIMARY KEY (`id`),
                      KEY `sender_id` (`sender_id`),
                      KEY `thread_id` (`thread_id`),
                      KEY `created_at` (`created_at`),
                      KEY `updated_at` (`updated_at`),
                      KEY `temp_id` (`temp_id`),
                      KEY `thread_id_created_at` (`thread_id`, `created_at`),
                      KEY `is_pending_index` (`is_pending`)
                    ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('meta') ."` (
                      `meta_id` bigint(20) NOT NULL AUTO_INCREMENT,
                      `bm_message_id` bigint(20) NOT NULL,
                      `meta_key` varchar(255) DEFAULT NULL,
                      `meta_value` longtext,
                      PRIMARY KEY (`meta_id`),
                      KEY `bm_message_id` (`bm_message_id`),
                      KEY `meta_key` (`meta_key`(191))
                    ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('recipients') ."` (
                      `id` bigint(20) NOT NULL AUTO_INCREMENT,
                      `user_id` bigint(20) NOT NULL,
                      `thread_id` bigint(20) NOT NULL,
                      `unread_count` int(10) NOT NULL DEFAULT '0',
                      `last_read` datetime NOT NULL DEFAULT '1970-01-01',
                      `last_delivered` datetime NOT NULL DEFAULT '1970-01-01',
                      `last_email` datetime NOT NULL DEFAULT '1970-01-01',
                      `is_muted` tinyint(1) NOT NULL DEFAULT '0',
                      `is_pinned` tinyint(1) NOT NULL DEFAULT '0',
                      `is_deleted` tinyint(1) NOT NULL DEFAULT '0',
                      `last_update` bigint(20) NOT NULL DEFAULT '0',
                      PRIMARY KEY (`id`),
                      UNIQUE KEY `user_thread` (`user_id`,`thread_id`),
                      KEY `user_id` (`user_id`),
                      KEY `thread_id` (`thread_id`),
                      KEY `is_deleted` (`is_deleted`),
                      KEY `unread_count` (`unread_count`),
                      KEY `is_pinned` (`is_pinned`),
                      KEY `unread_count_index` (`user_id`, `is_deleted`, `unread_count`)
                    ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('threadsmeta') ."` (
                      `meta_id` bigint(20) NOT NULL AUTO_INCREMENT,
                      `bm_thread_id` bigint(20) NOT NULL,
                      `meta_key` varchar(255) DEFAULT NULL,
                      `meta_value` longtext,
                      PRIMARY KEY (`meta_id`),
                      KEY `meta_key` (`meta_key`(191)),
                      KEY `thread_id` (`bm_thread_id`)
                    ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('threads') ."` (
                      `id` bigint(20) NOT NULL AUTO_INCREMENT,
                      `subject` varchar(255) NOT NULL,
                      `type` enum('thread','group','chat-room','course') NOT NULL DEFAULT 'thread',
                      PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('moderation') ."` (
                  `id` bigint(20) NOT NULL AUTO_INCREMENT,
                  `user_id` bigint(20) NOT NULL,
                  `thread_id` bigint(20) NOT NULL,
                  `type` enum('ban','mute','bypass_moderation','force_moderation') NOT NULL,
                  `expiration` datetime NULL DEFAULT NULL,
                  `admin_id` bigint(20) NOT NULL,
                  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                   PRIMARY KEY (`id`),
                   UNIQUE KEY `user_thread_type` (`user_id`,`thread_id`,`type`)
                ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('guests') . "` (
                 `id` bigint(20) NOT NULL AUTO_INCREMENT,
                 `secret` varchar(30) NOT NULL,
                 `name` varchar(255) NOT NULL,
                 `email` varchar(100) DEFAULT NULL,
                 `ip` varchar(40) NOT NULL,
                 `meta` longtext NOT NULL,
                 `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                 `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                 `deleted_at` datetime DEFAULT NULL,
                 PRIMARY KEY (`id`)
                ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('roles') . "` (
                    `user_id` bigint(20) NOT NULL,
                    `role` varchar(50) NOT NULL,
                    UNIQUE KEY `user_role_unique` (`user_id`,`role`),
                    KEY `roles_index` (`user_id`)
                ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('users') . "` (
                    `ID` bigint(20) NOT NULL,
                    `user_nicename` varchar(50) NOT NULL DEFAULT '',
                    `display_name` varchar(250) NOT NULL DEFAULT '',
                    `nickname` varchar(255) DEFAULT NULL,
                    `first_name` varchar(255) DEFAULT NULL,
                    `last_name` varchar(255) DEFAULT NULL,
                    `last_activity` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
                    `last_changed` bigint(20) DEFAULT NULL,
                     PRIMARY KEY (`ID`),
                    KEY `last_activity_index` (`last_activity`),
                    KEY `last_changed_index` (`last_changed`)
                ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('bulk_jobs') . "` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `sender_id` bigint(20) NOT NULL,
                    `subject` varchar(255) NOT NULL DEFAULT '',
                    `message` longtext NOT NULL,
                    `selectors` longtext NOT NULL,
                    `attachment_ids` text NOT NULL DEFAULT '',
                    `status` varchar(20) NOT NULL DEFAULT 'pending',
                    `disable_reply` tinyint(1) NOT NULL DEFAULT 0,
                    `use_existing_thread` tinyint(1) NOT NULL DEFAULT 0,
                    `hide_thread` tinyint(1) NOT NULL DEFAULT 0,
                    `single_thread` tinyint(1) NOT NULL DEFAULT 0,
                    `parent_job_id` bigint(20) NOT NULL DEFAULT 0,
                    `total_users` int(11) NOT NULL DEFAULT 0,
                    `processed_count` int(11) NOT NULL DEFAULT 0,
                    `error_count` int(11) NOT NULL DEFAULT 0,
                    `current_page` int(11) NOT NULL DEFAULT 1,
                    `scheduled_at` datetime DEFAULT NULL,
                    `batch_size` int(11) NOT NULL DEFAULT 0,
                    `error_log` longtext DEFAULT NULL,
                    `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `started_at` datetime DEFAULT NULL,
                    `completed_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `status_index` (`status`)
                ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('bulk_job_threads') . "` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `job_id` bigint(20) NOT NULL,
                    `thread_id` bigint(20) NOT NULL,
                    `message_id` bigint(20) NOT NULL DEFAULT 0,
                    `user_id` bigint(20) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `job_id_index` (`job_id`),
                    KEY `thread_id_index` (`thread_id`)
                ) ENGINE=InnoDB;",

                "CREATE TABLE `" . bm_get_table('ai_usage') . "` (
                    `id` bigint(20) NOT NULL AUTO_INCREMENT,
                    `bot_id` bigint(20) NOT NULL,
                    `message_id` bigint(20) NOT NULL DEFAULT 0,
                    `thread_id` bigint(20) NOT NULL DEFAULT 0,
                    `user_id` bigint(20) NOT NULL DEFAULT 0,
                    `is_summary` tinyint(1) NOT NULL DEFAULT 0,
                    `points_charged` int(11) NOT NULL DEFAULT 0,
                    `cost_data` longtext NOT NULL,
                    `created_at` bigint(20) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `bot_id_index` (`bot_id`),
                    KEY `bot_id_created_at` (`bot_id`, `created_at`),
                    KEY `message_id_index` (`message_id`)
                ) ENGINE=InnoDB;"
            ];

            dbDelta($sql);

            $this->update_collate();

            Better_Messages_Users()->schedule_sync_all_users();
            Better_Messages_Capabilities()->register_capabilities();

            update_option( 'better_messages_2_db_version', $this->db_version, false );
        }

        public function upgrade( $current_version ){
            set_time_limit(0);
            ignore_user_abort(true);

            global $wpdb;

            $sqls = [
                '0.2' => [
                    "ALTER TABLE `" . bm_get_table('recipients') . "` ADD `is_pinned` TINYINT(1) NOT NULL DEFAULT '0' AFTER `is_muted`;",
                    "ALTER TABLE `" . bm_get_table('recipients') . "` ADD INDEX `is_pinned` (`is_pinned`);",
                    "ALTER TABLE `" . bm_get_table('recipients') . "` DROP INDEX `last_delivered`;",
                    "ALTER TABLE `" . bm_get_table('recipients') . "` DROP INDEX `last_read`;",
                ],
                '0.3' => [
                    function (){
                        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

                        dbDelta(["CREATE TABLE `" . bm_get_table('moderation') ."` (
                          `id` bigint(20) NOT NULL AUTO_INCREMENT,
                          `user_id` bigint(20) NOT NULL,
                          `thread_id` bigint(20) NOT NULL,
                          `type` enum('ban','mute') NOT NULL,
                          `expiration` datetime NULL DEFAULT NULL,
                          `admin_id` bigint(20) NOT NULL,
                          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                           PRIMARY KEY (`id`),
                           UNIQUE KEY `user_thread_type` (`user_id`,`thread_id`,`type`)
                        ) ENGINE=InnoDB;"]);
                    }
                ],
                '0.4' => [
                    function (){
                        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
                        global $wpdb;
                        dbDelta(["CREATE TABLE `" . bm_get_table('guests') . "` (
                         `id` bigint(20) NOT NULL AUTO_INCREMENT,
                         `secret` varchar(30) NOT NULL,
                         `name` varchar(255) NOT NULL,
                         `email` varchar(100) DEFAULT NULL,
                         `ip` varchar(40) NOT NULL,
                         `meta` longtext NOT NULL,
                         `last_active` datetime DEFAULT NULL,
                         `last_changed` bigint(20) DEFAULT NULL,
                         `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                         `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                         PRIMARY KEY (`id`)
                        ) ENGINE=InnoDB;"]);
                    }
                ],
                '0.5' => [
                    function(){
                        Better_Messages_Rest_Api_DB_Migrate()->update_collate();
                    }
                ],
                '0.6' => [
                    "ALTER TABLE `" . bm_get_table('guests') . "` ADD `deleted_at` DATETIME NULL DEFAULT NULL AFTER `updated_at`;"
                ],
                '0.7' =>[
                    function (){
                        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
                        dbDelta([
                            "CREATE TABLE `" . bm_get_table('roles') . "` (
                              `user_id` bigint(20) NOT NULL,
                              `role` varchar(50) NOT NULL,
                              UNIQUE KEY `user_role_unique` (`user_id`,`role`),
                              KEY `roles_index` (`user_id`)
                            ) ENGINE=InnoDB;",
                            "CREATE TABLE `" . bm_get_table('users') . "` (
                              `ID` bigint(20) NOT NULL,
                              `user_nicename` varchar(50) NOT NULL DEFAULT '',
                              `display_name` varchar(250) NOT NULL DEFAULT '',
                              `nickname` varchar(255) DEFAULT NULL,
                              `first_name` varchar(255) DEFAULT NULL,
                              `last_name` varchar(255) DEFAULT NULL,
                              `last_activity` datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
                              `last_changed` bigint(20) DEFAULT NULL,
                              PRIMARY KEY (`ID`)
                            ) ENGINE=InnoDB;"
                        ]);
                        global $wpdb;

                        $wpdb->query("ALTER TABLE `" . bm_get_table('recipients') . "` ADD `last_email` DATETIME NULL DEFAULT NULL AFTER `last_delivered`;");

                        Better_Messages_Users()->schedule_sync_all_users();

                        // Migrating data from usermeta to new table
                        $wpdb->query("
                        INSERT INTO `" . bm_get_table('users') . "` (ID, last_activity)
                        SELECT `user_id` as `ID`, `meta_value` as `last_activity`
                        FROM  `{$wpdb->usermeta}`
                        WHERE `meta_key` = 'bpbm_last_activity'
                        ON DUPLICATE KEY UPDATE last_activity=last_activity;");

                        $wpdb->query("
                        INSERT INTO `" . bm_get_table('users') . "` ( ID,  last_activity )
                            SELECT (-1 * id) as ID, 
                            last_active as last_activity
                        FROM `" . bm_get_table('guests') . "` `guests`
                            WHERE `deleted_at` IS NULL
                        ON DUPLICATE KEY 
                        UPDATE last_activity = `guests`.`last_active`");

                        // Deleting old user meta to clean up
                        $wpdb->query("DELETE FROM  `{$wpdb->usermeta}` WHERE `meta_key` = 'bpbm_last_activity'");
                        $wpdb->query("ALTER TABLE `" . bm_get_table('guests') . "` DROP `last_active`;");
                    }
                ],
                '0.8' => [
                    "ALTER TABLE `" . bm_get_table('users') . "` ADD INDEX `last_activity_index` (`last_activity`);",
                    "ALTER TABLE `" . bm_get_table('users') . "` ADD INDEX `last_changed_index` (`last_changed`);",
                ],
                '0.9' => [
                    "DELETE FROM `" . bm_get_table('mentions') . "`;",
                    "UPDATE `" . bm_get_table('recipients') . "` SET last_delivered = '1970-01-01' WHERE last_delivered IS NULL;",
                    "UPDATE `" . bm_get_table('recipients') . "` SET last_read = '1970-01-01' WHERE last_read IS NULL;",
                    "UPDATE `" . bm_get_table('recipients') . "` SET last_email = '1970-01-01' WHERE last_email IS NULL;",
                    "ALTER TABLE `" . bm_get_table('recipients') . "` MODIFY last_delivered DATETIME DEFAULT '1970-01-01' NOT NULL;",
                    "ALTER TABLE `" . bm_get_table('recipients') . "` MODIFY last_read DATETIME DEFAULT '1970-01-01' NOT NULL;",
                    "ALTER TABLE `" . bm_get_table('recipients') . "` MODIFY last_email DATETIME DEFAULT '1970-01-01' NOT NULL;",
                ],
                '1.0' => [
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD `created_at` BIGINT NOT NULL DEFAULT '0' AFTER `date_sent`;",
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD `updated_at` BIGINT NOT NULL DEFAULT '0' AFTER `created_at`;",
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD `temp_id` VARCHAR(50) NULL AFTER `updated_at`;",
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD INDEX `created_at` (`created_at`);",
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD INDEX `updated_at` (`updated_at`);",
                    "UPDATE `" . bm_get_table('messages') ."` `messages`
                     INNER JOIN (
                        SELECT bm_message_id as message_id, meta_value as last_update
                        FROM `" . bm_get_table('meta') ."`
                        WHERE `meta_key` = 'bm_last_update'
                     ) AS meta_table ON `messages`.`id` = `meta_table`.`message_id`
                     SET `messages`.`updated_at` = `meta_table`.last_update;",
                    "UPDATE `" . bm_get_table('messages') ."` `messages`
                     INNER JOIN (
                        SELECT bm_message_id as message_id, meta_value as created_time
                        FROM `" . bm_get_table('meta') ."`
                        WHERE `meta_key` = 'bm_created_time'
                     ) AS meta_table ON `messages`.`id` = `meta_table`.`message_id`
                     SET `messages`.`created_at` = `meta_table`.created_time;",
                    "DELETE FROM `" . bm_get_table('meta') ."` WHERE `meta_key` = 'bm_last_update';",
                    "DELETE FROM `" . bm_get_table('meta') ."` WHERE `meta_key` = 'bm_created_time';",
                    "DELETE FROM `" . bm_get_table('meta') ."` WHERE `meta_key` = 'bm_tmp_id';",
                    "UPDATE `" . bm_get_table('messages') ."`
                    SET `created_at` = (
                        SELECT CONCAT(UNIX_TIMESTAMP(date_sent), '0000')
                        FROM (SELECT * FROM `" . bm_get_table('messages') ."`) AS sub
                        WHERE sub.`id` = `" . bm_get_table('messages') ."`.`id` AND date_sent > '1970-01-01'
                    )
                    WHERE `created_at` = 0;",
                    "UPDATE `" . bm_get_table('messages') ."`
                    SET `updated_at` = `created_at`
                    WHERE `updated_at` = 0 AND `created_at` > 0;"
                ],
                '1.1' => [
                    "ALTER TABLE `" . bm_get_table('messages') ."` CHANGE `temp_id` `temp_id` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL;"
                ],
                '1.2' => [
                    "UPDATE `" . bm_get_table('messages') ."`
                    SET `created_at` = (
                        SELECT CONCAT(UNIX_TIMESTAMP(date_sent), '0000')
                        FROM (SELECT * FROM `" . bm_get_table('messages') ."`) AS sub
                        WHERE sub.`id` = `" . bm_get_table('messages') ."`.`id` AND date_sent > '1970-01-01'
                    )
                    WHERE `created_at` = 0;",
                    "UPDATE `" . bm_get_table('messages') ."`
                    SET `updated_at` = `created_at`
                    WHERE `updated_at` = 0 AND `created_at` > 0;"
                ],
                '1.3' => [
                    "ALTER TABLE `" . bm_get_table('recipients') ."` ADD INDEX `unread_count_index` (`user_id`, `is_deleted`, `unread_count`);"
                ],
                '1.4' => [
                    function (){
                        if( Better_Messages()->files ) {
                            Better_Messages_Files()->create_index_file();
                        }
                    }
                ],
                '1.5' => [
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD INDEX `temp_id` (`temp_id`);",
                ],
                '1.6' => [
                    function () {
                        Better_Messages_Capabilities()->register_capabilities();
                    }
                ],
                '1.7' => [
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD INDEX `thread_id_created_at` (`thread_id`, `created_at`);",
                ],
                '1.8' => [
                    "ALTER TABLE `" . bm_get_table('moderation') ."` MODIFY COLUMN `type` enum('ban','mute','bypass_moderation','force_moderation') NOT NULL;",
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD COLUMN `is_pending` tinyint(1) NOT NULL DEFAULT '0';",
                    "ALTER TABLE `" . bm_get_table('messages') ."` ADD INDEX `is_pending_index` (`is_pending`);",
                ],
                '1.9' => [
                    function (){
                        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
                        global $wpdb;

                        dbDelta([
                            "CREATE TABLE `" . bm_get_table('bulk_jobs') . "` (
                                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                                `sender_id` bigint(20) NOT NULL,
                                `subject` varchar(255) NOT NULL DEFAULT '',
                                `message` longtext NOT NULL,
                                `selectors` longtext NOT NULL,
                                `attachment_ids` text NOT NULL DEFAULT '',
                                `status` varchar(20) NOT NULL DEFAULT 'pending',
                                `disable_reply` tinyint(1) NOT NULL DEFAULT 0,
                                `use_existing_thread` tinyint(1) NOT NULL DEFAULT 0,
                                `hide_thread` tinyint(1) NOT NULL DEFAULT 0,
                                `single_thread` tinyint(1) NOT NULL DEFAULT 0,
                                `parent_job_id` bigint(20) NOT NULL DEFAULT 0,
                                `total_users` int(11) NOT NULL DEFAULT 0,
                                `processed_count` int(11) NOT NULL DEFAULT 0,
                                `error_count` int(11) NOT NULL DEFAULT 0,
                                `current_page` int(11) NOT NULL DEFAULT 1,
                                `scheduled_at` datetime DEFAULT NULL,
                                `batch_size` int(11) NOT NULL DEFAULT 0,
                                `error_log` longtext DEFAULT NULL,
                                `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                                `started_at` datetime DEFAULT NULL,
                                `completed_at` datetime DEFAULT NULL,
                                PRIMARY KEY (`id`),
                                KEY `status_index` (`status`)
                            ) ENGINE=InnoDB;",
                            "CREATE TABLE `" . bm_get_table('bulk_job_threads') . "` (
                                `id` bigint(20) NOT NULL AUTO_INCREMENT,
                                `job_id` bigint(20) NOT NULL,
                                `thread_id` bigint(20) NOT NULL,
                                `message_id` bigint(20) NOT NULL DEFAULT 0,
                                `user_id` bigint(20) NOT NULL DEFAULT 0,
                                PRIMARY KEY (`id`),
                                KEY `job_id_index` (`job_id`),
                                KEY `thread_id_index` (`thread_id`)
                            ) ENGINE=InnoDB;"
                        ]);

                        // Migrate old bpbm-bulk-report posts to new table
                        $reports = get_posts([
                            'post_type'      => 'bpbm-bulk-report',
                            'post_status'    => 'any',
                            'posts_per_page' => -1
                        ]);

                        if ( count( $reports ) > 0 ) {
                            $bulk_jobs_table = bm_get_table('bulk_jobs');
                            $bulk_job_threads_table = bm_get_table('bulk_job_threads');

                            foreach ( $reports as $report ) {
                                $selectors = get_post_meta( $report->ID, 'selectors', true );
                                $message   = get_post_meta( $report->ID, 'message', true );
                                $subject   = get_post_meta( $report->ID, 'subject', true );
                                $disable_reply = get_post_meta( $report->ID, 'disableReply', true ) === '1' ? 1 : 0;
                                $use_existing  = get_post_meta( $report->ID, 'useExistingThread', true ) === '1' ? 1 : 0;
                                $hide_thread   = get_post_meta( $report->ID, 'hideThread', true ) === '1' ? 1 : 0;

                                $thread_ids  = get_post_meta( $report->ID, 'thread_ids' );
                                $message_ids = get_post_meta( $report->ID, 'message_ids' );

                                $total = count( $thread_ids );

                                $wpdb->insert( $bulk_jobs_table, [
                                    'sender_id'            => (int) $report->post_author,
                                    'subject'              => $subject ?: '',
                                    'message'              => $message ?: '',
                                    'selectors'            => is_array( $selectors ) ? wp_json_encode( $selectors ) : '{}',
                                    'attachment_ids'       => '[]',
                                    'status'               => 'completed',
                                    'disable_reply'        => $disable_reply,
                                    'use_existing_thread'  => $use_existing,
                                    'hide_thread'          => $hide_thread,
                                    'single_thread'        => 0,
                                    'total_users'          => $total,
                                    'processed_count'      => $total,
                                    'error_count'          => 0,
                                    'current_page'         => 1,
                                    'created_at'           => $report->post_date,
                                    'started_at'           => $report->post_date,
                                    'completed_at'         => $report->post_date,
                                ]);

                                $job_id = $wpdb->insert_id;

                                if ( $job_id && count( $thread_ids ) > 0 ) {
                                    foreach ( $thread_ids as $i => $thread_id ) {
                                        if ( ! is_numeric( $thread_id ) ) continue;
                                        $msg_id = isset( $message_ids[ $i ] ) && is_numeric( $message_ids[ $i ] ) ? (int) $message_ids[ $i ] : 0;
                                        $wpdb->insert( $bulk_job_threads_table, [
                                            'job_id'     => $job_id,
                                            'thread_id'  => (int) $thread_id,
                                            'message_id' => $msg_id,
                                            'user_id'    => 0,
                                        ]);
                                    }
                                }

                                // Delete old post and its meta
                                wp_delete_post( $report->ID, true );
                            }
                        }
                    },
                    function (){
                        global $wpdb;
                        $table = bm_get_table('bulk_jobs');
                        $column_exists = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'parent_job_id'" );
                        if ( empty( $column_exists ) ) {
                            $wpdb->query( "ALTER TABLE `{$table}` ADD `parent_job_id` bigint(20) NOT NULL DEFAULT 0 AFTER `single_thread`" );
                        }
                    },
                    function (){
                        global $wpdb;
                        $table = bm_get_table('bulk_jobs');
                        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}` LIKE 'scheduled_at'" );
                        if ( empty( $col ) ) {
                            $wpdb->query( "ALTER TABLE `{$table}` ADD `scheduled_at` datetime DEFAULT NULL AFTER `current_page`" );
                            $wpdb->query( "ALTER TABLE `{$table}` ADD `batch_size` int(11) NOT NULL DEFAULT 0 AFTER `scheduled_at`" );
                        }
                    }
                ],
                '2.0' => [
                    function () {
                        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

                        dbDelta(["CREATE TABLE `" . bm_get_table('ai_usage') . "` (
                            `id` bigint(20) NOT NULL AUTO_INCREMENT,
                            `bot_id` bigint(20) NOT NULL,
                            `message_id` bigint(20) NOT NULL DEFAULT 0,
                            `thread_id` bigint(20) NOT NULL DEFAULT 0,
                            `user_id` bigint(20) NOT NULL DEFAULT 0,
                            `is_summary` tinyint(1) NOT NULL DEFAULT 0,
                            `points_charged` int(11) NOT NULL DEFAULT 0,
                            `cost_data` longtext NOT NULL,
                            `created_at` bigint(20) NOT NULL DEFAULT 0,
                            PRIMARY KEY (`id`),
                            KEY `bot_id_index` (`bot_id`),
                            KEY `bot_id_created_at` (`bot_id`, `created_at`),
                            KEY `message_id_index` (`message_id`)
                        ) ENGINE=InnoDB;"]);
                    },
                    function () {
                        // Migrate points system: auto-detect provider for existing installs
                        $stored = get_option( 'bp-better-chat-settings', [] );
                        $current = $stored['pointsSystem'] ?? 'none';
                        if ( $current !== 'none' ) return;

                        $detected = 'none';
                        $prefixes = [
                            'mycred'    => 'myCred',
                            'gamipress' => 'GamiPress',
                        ];
                        $classes = [
                            'mycred'    => 'myCRED_Core',
                            'gamipress' => 'GamiPress',
                        ];

                        foreach ( $prefixes as $provider_id => $prefix ) {
                            if ( ! class_exists( $classes[ $provider_id ] ) ) continue;

                            foreach ( [ 'NewMessageCharge', 'NewThreadCharge', 'CallPricing' ] as $key ) {
                                $values = $stored[ $prefix . $key ] ?? [];
                                if ( is_array( $values ) ) {
                                    foreach ( $values as $role_data ) {
                                        if ( isset( $role_data['value'] ) && $role_data['value'] > 0 ) {
                                            $detected = $provider_id;
                                            break 3;
                                        }
                                    }
                                }
                            }
                        }

                        if ( $detected !== 'none' ) {
                            $stored['pointsSystem'] = $detected;
                            update_option( 'bp-better-chat-settings', $stored );
                            Better_Messages()->settings['pointsSystem'] = $detected;
                        }
                    }
                ],
                '2.1' => [
                    function () {
                        global $wpdb;
                        $threads_table     = bm_get_table('threads');
                        $threadsmeta_table = bm_get_table('threadsmeta');

                        $wpdb->query( "ALTER TABLE `{$threads_table}` MODIFY `type` ENUM('thread','group','chat-room','course') NOT NULL DEFAULT 'thread'" );

                        $course_meta_keys = "'learnpress_course_id', 'tutorlms_course_id', 'learndash_course_id', 'learndash_group_id', 'fluentcommunity_course_id'";
                        $wpdb->query( "
                            UPDATE `{$threads_table}` t
                            INNER JOIN `{$threadsmeta_table}` tm ON tm.bm_thread_id = t.id
                            SET t.type = 'course'
                            WHERE t.type IN ('group', '')
                              AND tm.meta_key IN ({$course_meta_keys})
                        " );
                    },
                    function () {
                        global $wpdb;
                        $guests_table = bm_get_table('guests');
                        $col = $wpdb->get_results( "SHOW COLUMNS FROM `{$guests_table}` LIKE 'last_changed'" );
                        if ( ! empty( $col ) ) {
                            $wpdb->query( "ALTER TABLE `{$guests_table}` DROP COLUMN `last_changed`" );
                        }
                    }
                ]
            ];

            $sql = [];

            foreach ($sqls as $version => $queries) {
                if ($version > $current_version) {
                    foreach ($queries as $query) {
                        $sql[] = $query;
                    }
                }
            }

            if( count( $sql ) > 0 ){
                foreach ( $sql as $query ) {
                    if( is_string( $query ) ) {
                        $wpdb->query($query);
                    }
                    if( is_callable( $query) ) {
                        $query();
                    }
                }

                $this->update_collate();
            }

            update_option( 'better_messages_2_db_version', $this->db_version, false );
        }

        public function get_target_db_version(){
            return (string) $this->db_version;
        }

        public function get_installed_db_version(){
            return (string) get_option( 'better_messages_2_db_version', '0' );
        }

        public function install_tables(){
            $db_2_version = get_option( 'better_messages_2_db_version', 0 );

            if( $db_2_version === 0 ){
                $this->first_install();
            } else if( $db_2_version != $this->db_version) {
                $this->upgrade( $db_2_version );
            }
        }

        public function migrations(){
            global $wpdb;

            $db_migrated = get_option('better_messages_db_migrated', false);

            if( ! $db_migrated ) {
                set_time_limit(0);
                require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

                $time = Better_Messages()->functions->get_microtime();

                $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . bm_get_table('messages') );

                if( $count === 0 ){
                    $exists = $wpdb->get_var("SHOW TABLES LIKE '" . $wpdb->prefix . "bp_messages_recipients';");

                    if( $exists ) {
                        $wpdb->query("TRUNCATE " . bm_get_table('threads') . ";");
                        $wpdb->query("TRUNCATE " . bm_get_table('recipients') . ";");
                        $wpdb->query("TRUNCATE " . bm_get_table('messages') . ";");
                        $wpdb->query("TRUNCATE " . bm_get_table('threadsmeta') . ";");
                        $wpdb->query("TRUNCATE " . bm_get_table('meta') . ";");


                        $thread_ids = array_map('intval', $wpdb->get_col("SELECT thread_id
                        FROM " . $wpdb->prefix . "bp_messages_recipients recipients
                        GROUP BY thread_id"));

                        foreach ($thread_ids as $thread_id) {
                            $type = $this->get_thread_type($thread_id);
                            $subject = Better_Messages()->functions->remove_re($wpdb->get_var($wpdb->prepare("SELECT subject
                            FROM {$wpdb->prefix}bp_messages_messages
                            WHERE thread_id = %d
                            ORDER BY date_sent DESC
                            LIMIT 0, 1", $thread_id)));

                            $wpdb->insert(bm_get_table('threads'), [
                                'id' => $thread_id,
                                'subject' => $subject,
                                'type' => $type
                            ]);
                        }

                        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO " . bm_get_table('recipients') . "
                        (user_id,thread_id,unread_count,is_deleted, last_update, is_muted)
                        SELECT user_id, thread_id, unread_count, is_deleted, %d, 0
                        FROM " . $wpdb->prefix . "bp_messages_recipients", $time));

                        $wpdb->query("INSERT IGNORE INTO " . bm_get_table('messages') . "
                        (id,thread_id,sender_id,message,date_sent)
                        SELECT id,thread_id,sender_id,message,date_sent
                        FROM " . $wpdb->prefix . "bp_messages_messages
                        WHERE date_sent != '0000-00-00 00:00:00'");

                        $wpdb->query("INSERT IGNORE INTO " . bm_get_table('threadsmeta') . "
                        (bm_thread_id, meta_key, meta_value)
                        SELECT bpbm_threads_id, meta_key, meta_value
                        FROM " . $wpdb->prefix . "bpbm_threadsmeta");

                        $wpdb->query("INSERT IGNORE INTO " . bm_get_table('meta') . "
                        (bm_message_id, meta_key, meta_value)
                        SELECT message_id, meta_key, meta_value
                        FROM " . $wpdb->prefix . "bp_messages_meta");

                        $wpdb->query("UPDATE `" . bm_get_table('messages') ."`
                        SET `created_at` = (
                            SELECT CONCAT(UNIX_TIMESTAMP(date_sent), '0000')
                            FROM (SELECT * FROM `" . bm_get_table('messages') ."`) AS sub
                            WHERE sub.`id` = `" . bm_get_table('messages') ."`.`id` AND date_sent > '1970-01-01'
                        )
                        WHERE `created_at` = 0;");

                        $wpdb->query("UPDATE `" . bm_get_table('messages') ."`
                        SET `updated_at` = `created_at`
                        WHERE `updated_at` = 0 AND `created_at` > 0;");
                    }
                }

                update_option( 'better_messages_db_migrated', true, false );
            }
        }

        public function get_schemas(){
            $schemas = [
                'mentions' => [
                    'columns' => [
                        'id'         => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'thread_id'  => "bigint(20) NOT NULL",
                        'message_id' => "bigint(20) NOT NULL",
                        'user_id'    => "bigint(20) NOT NULL",
                        'type'       => "enum('mention','reply','reaction') NOT NULL",
                    ],
                    'primary_key' => 'id',
                ],
                'messages' => [
                    'columns' => [
                        'id'         => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'thread_id'  => "bigint(20) NOT NULL",
                        'sender_id'  => "bigint(20) NOT NULL",
                        'message'    => "longtext NOT NULL",
                        'date_sent'  => "datetime NOT NULL",
                        'created_at' => "bigint(20) NOT NULL DEFAULT '0'",
                        'updated_at' => "bigint(20) NOT NULL DEFAULT '0'",
                        'temp_id'    => "varchar(50) DEFAULT NULL",
                        'is_pending' => "tinyint(1) NOT NULL DEFAULT '0'",
                    ],
                    'primary_key' => 'id',
                    'keys' => [
                        'sender_id'            => 'sender_id',
                        'thread_id'            => 'thread_id',
                        'created_at'           => 'created_at',
                        'updated_at'           => 'updated_at',
                        'temp_id'              => 'temp_id',
                        'thread_id_created_at' => 'thread_id, created_at',
                        'is_pending_index'     => 'is_pending',
                    ],
                ],
                'meta' => [
                    'columns' => [
                        'meta_id'       => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'bm_message_id' => "bigint(20) NOT NULL",
                        'meta_key'      => "varchar(255) DEFAULT NULL",
                        'meta_value'    => "longtext",
                    ],
                    'primary_key' => 'meta_id',
                    'keys' => [
                        'bm_message_id' => 'bm_message_id',
                        'meta_key'      => 'meta_key(191)',
                    ],
                ],
                'recipients' => [
                    'columns' => [
                        'id'             => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'user_id'        => "bigint(20) NOT NULL",
                        'thread_id'      => "bigint(20) NOT NULL",
                        'unread_count'   => "int(10) NOT NULL DEFAULT '0'",
                        'last_read'      => "datetime NOT NULL DEFAULT '1970-01-01'",
                        'last_delivered' => "datetime NOT NULL DEFAULT '1970-01-01'",
                        'last_email'     => "datetime NOT NULL DEFAULT '1970-01-01'",
                        'is_muted'       => "tinyint(1) NOT NULL DEFAULT '0'",
                        'is_pinned'      => "tinyint(1) NOT NULL DEFAULT '0'",
                        'is_deleted'     => "tinyint(1) NOT NULL DEFAULT '0'",
                        'last_update'    => "bigint(20) NOT NULL DEFAULT '0'",
                    ],
                    'primary_key' => 'id',
                    'unique_keys' => [
                        'user_thread' => 'user_id, thread_id',
                    ],
                    'keys' => [
                        'user_id'            => 'user_id',
                        'thread_id'          => 'thread_id',
                        'is_deleted'         => 'is_deleted',
                        'unread_count'       => 'unread_count',
                        'is_pinned'          => 'is_pinned',
                        'unread_count_index' => 'user_id, is_deleted, unread_count',
                    ],
                ],
                'threadsmeta' => [
                    'columns' => [
                        'meta_id'      => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'bm_thread_id' => "bigint(20) NOT NULL",
                        'meta_key'     => "varchar(255) DEFAULT NULL",
                        'meta_value'   => "longtext",
                    ],
                    'primary_key' => 'meta_id',
                    'keys' => [
                        'meta_key'  => 'meta_key(191)',
                        'thread_id' => 'bm_thread_id',
                    ],
                ],
                'threads' => [
                    'columns' => [
                        'id'      => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'subject' => "varchar(255) NOT NULL",
                        'type'    => "enum('thread','group','chat-room','course') NOT NULL DEFAULT 'thread'",
                    ],
                    'primary_key' => 'id',
                ],
                'moderation' => [
                    'columns' => [
                        'id'         => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'user_id'    => "bigint(20) NOT NULL",
                        'thread_id'  => "bigint(20) NOT NULL",
                        'type'       => "enum('ban','mute','bypass_moderation','force_moderation') NOT NULL",
                        'expiration' => "datetime NULL DEFAULT NULL",
                        'admin_id'   => "bigint(20) NOT NULL",
                        'created_at' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                        'updated_at' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                    ],
                    'primary_key' => 'id',
                    'unique_keys' => [
                        'user_thread_type' => 'user_id, thread_id, type',
                    ],
                ],
                'guests' => [
                    'columns' => [
                        'id'         => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'secret'     => "varchar(30) NOT NULL",
                        'name'       => "varchar(255) NOT NULL",
                        'email'      => "varchar(100) DEFAULT NULL",
                        'ip'         => "varchar(40) NOT NULL",
                        'meta'       => "longtext NOT NULL",
                        'created_at' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                        'updated_at' => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
                        'deleted_at' => "datetime DEFAULT NULL",
                    ],
                    'primary_key' => 'id',
                ],
                'roles' => [
                    'columns' => [
                        'user_id' => "bigint(20) NOT NULL",
                        'role'    => "varchar(50) NOT NULL",
                    ],
                    'primary_key' => null,
                    'unique_keys' => [
                        'user_role_unique' => 'user_id, role',
                    ],
                    'keys' => [
                        'roles_index' => 'user_id',
                    ],
                ],
                'users' => [
                    'columns' => [
                        'ID'            => "bigint(20) NOT NULL",
                        'user_nicename' => "varchar(50) NOT NULL DEFAULT ''",
                        'display_name'  => "varchar(250) NOT NULL DEFAULT ''",
                        'nickname'      => "varchar(255) DEFAULT NULL",
                        'first_name'    => "varchar(255) DEFAULT NULL",
                        'last_name'     => "varchar(255) DEFAULT NULL",
                        'last_activity' => "datetime NOT NULL DEFAULT '1970-01-01 00:00:00'",
                        'last_changed'  => "bigint(20) DEFAULT NULL",
                    ],
                    'primary_key' => 'ID',
                    'keys' => [
                        'last_activity_index' => 'last_activity',
                        'last_changed_index'  => 'last_changed',
                    ],
                ],
                'bulk_jobs' => [
                    'columns' => [
                        'id'                  => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'sender_id'           => "bigint(20) NOT NULL",
                        'subject'             => "varchar(255) NOT NULL DEFAULT ''",
                        'message'             => "longtext NOT NULL",
                        'selectors'           => "longtext NOT NULL",
                        'attachment_ids'      => "text NOT NULL DEFAULT ''",
                        'status'              => "varchar(20) NOT NULL DEFAULT 'pending'",
                        'disable_reply'       => "tinyint(1) NOT NULL DEFAULT 0",
                        'use_existing_thread' => "tinyint(1) NOT NULL DEFAULT 0",
                        'hide_thread'         => "tinyint(1) NOT NULL DEFAULT 0",
                        'single_thread'       => "tinyint(1) NOT NULL DEFAULT 0",
                        'parent_job_id'       => "bigint(20) NOT NULL DEFAULT 0",
                        'total_users'         => "int(11) NOT NULL DEFAULT 0",
                        'processed_count'     => "int(11) NOT NULL DEFAULT 0",
                        'error_count'         => "int(11) NOT NULL DEFAULT 0",
                        'current_page'        => "int(11) NOT NULL DEFAULT 1",
                        'scheduled_at'        => "datetime DEFAULT NULL",
                        'batch_size'          => "int(11) NOT NULL DEFAULT 0",
                        'error_log'           => "longtext DEFAULT NULL",
                        'created_at'          => "datetime NOT NULL DEFAULT CURRENT_TIMESTAMP",
                        'started_at'          => "datetime DEFAULT NULL",
                        'completed_at'        => "datetime DEFAULT NULL",
                    ],
                    'primary_key' => 'id',
                    'keys' => [
                        'status_index' => 'status',
                    ],
                ],
                'bulk_job_threads' => [
                    'columns' => [
                        'id'         => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'job_id'     => "bigint(20) NOT NULL",
                        'thread_id'  => "bigint(20) NOT NULL",
                        'message_id' => "bigint(20) NOT NULL DEFAULT 0",
                        'user_id'    => "bigint(20) NOT NULL DEFAULT 0",
                    ],
                    'primary_key' => 'id',
                    'keys' => [
                        'job_id_index'    => 'job_id',
                        'thread_id_index' => 'thread_id',
                    ],
                ],
                'ai_usage' => [
                    'columns' => [
                        'id'             => "bigint(20) NOT NULL AUTO_INCREMENT",
                        'bot_id'         => "bigint(20) NOT NULL",
                        'message_id'     => "bigint(20) NOT NULL DEFAULT 0",
                        'thread_id'      => "bigint(20) NOT NULL DEFAULT 0",
                        'user_id'        => "bigint(20) NOT NULL DEFAULT 0",
                        'is_summary'     => "tinyint(1) NOT NULL DEFAULT 0",
                        'points_charged' => "int(11) NOT NULL DEFAULT 0",
                        'cost_data'      => "longtext NOT NULL",
                        'created_at'     => "bigint(20) NOT NULL DEFAULT 0",
                    ],
                    'primary_key' => 'id',
                    'keys' => [
                        'bot_id_index'      => 'bot_id',
                        'bot_id_created_at' => 'bot_id, created_at',
                        'message_id_index'  => 'message_id',
                    ],
                ],
            ];

            return apply_filters( 'better_messages_db_schemas', $schemas );
        }

        public function build_create_sql( $table_name, $schema ){
            $lines = [];

            foreach ( $schema['columns'] as $col_name => $col_def ) {
                $lines[] = "{$col_name} {$col_def}";
            }

            $pk = isset( $schema['primary_key'] ) ? $schema['primary_key'] : null;
            if ( ! empty( $pk ) ) {
                if ( is_array( $pk ) ) {
                    $lines[] = 'PRIMARY KEY (' . implode( ', ', $pk ) . ')';
                } else {
                    $lines[] = "PRIMARY KEY ({$pk})";
                }
            }

            if ( ! empty( $schema['unique_keys'] ) ) {
                foreach ( $schema['unique_keys'] as $key_name => $key_cols ) {
                    $lines[] = "UNIQUE KEY {$key_name} ({$key_cols})";
                }
            }

            if ( ! empty( $schema['keys'] ) ) {
                foreach ( $schema['keys'] as $key_name => $key_cols ) {
                    $lines[] = "KEY {$key_name} ({$key_cols})";
                }
            }

            $body = implode( ",\n  ", $lines );

            return "CREATE TABLE {$table_name} (\n  {$body}\n) ENGINE=InnoDB";
        }

        public function resolve_table_name( $logical, $schema = null ){
            if ( $schema === null ) {
                $schemas = $this->get_schemas();
                $schema  = isset( $schemas[ $logical ] ) ? $schemas[ $logical ] : null;
            }
            if ( is_array( $schema ) && ! empty( $schema['table_name'] ) ) {
                return $schema['table_name'];
            }
            return bm_get_table( $logical );
        }

        private function get_logical_to_table_map(){
            $schemas = $this->get_schemas();
            $map = [];
            foreach ( $schemas as $logical => $schema ) {
                $map[ $logical ] = $this->resolve_table_name( $logical, $schema );
            }
            return $map;
        }

        public function get_actual_schema(){
            global $wpdb;

            $logical_to_table = $this->get_logical_to_table_map();
            $table_names      = array_values( $logical_to_table );

            if ( empty( $table_names ) ) {
                return [];
            }

            $placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT TABLE_NAME, COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$placeholders}) ORDER BY TABLE_NAME, ORDINAL_POSITION",
                    array_merge( [ DB_NAME ], $table_names )
                )
            );

            $table_to_logical = array_flip( $logical_to_table );
            $result = [];

            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( isset( $table_to_logical[ $row->TABLE_NAME ] ) ) {
                        $logical = $table_to_logical[ $row->TABLE_NAME ];
                        if ( ! isset( $result[ $logical ] ) ) {
                            $result[ $logical ] = [];
                        }
                        $result[ $logical ][] = $row->COLUMN_NAME;
                    }
                }
            }

            return $result;
        }

        public function compare_schemas(){
            $expected         = $this->get_schemas();
            $actual           = $this->get_actual_schema();
            $actual_enums     = $this->get_actual_enum_types();
            $actual_indexes   = $this->get_actual_indexes();
            $result           = [];

            foreach ( $expected as $logical => $schema ) {
                $expected_cols = array_keys( $schema['columns'] );
                $actual_cols   = isset( $actual[ $logical ] ) ? $actual[ $logical ] : [];

                if ( empty( $actual_cols ) ) {
                    $result[ $logical ] = [
                        'exists'           => false,
                        'status'           => 'missing',
                        'expected_columns' => $expected_cols,
                        'actual_columns'   => [],
                        'missing_columns'  => $expected_cols,
                        'extra_columns'    => [],
                        'enum_mismatches'  => [],
                        'missing_indexes'  => [],
                        'extra_indexes'    => [],
                    ];
                    continue;
                }

                $missing = array_values( array_diff( $expected_cols, $actual_cols ) );
                $extra   = array_values( array_diff( $actual_cols, $expected_cols ) );

                $enum_mismatches = [];
                foreach ( $schema['columns'] as $col_name => $col_def ) {
                    $expected_values = self::parse_enum_values( $col_def );
                    if ( $expected_values === null ) {
                        continue;
                    }
                    $actual_type   = isset( $actual_enums[ $logical ][ $col_name ] ) ? $actual_enums[ $logical ][ $col_name ] : null;
                    $actual_values = $actual_type !== null ? self::parse_enum_values( $actual_type ) : [];
                    if ( ! is_array( $actual_values ) ) {
                        $actual_values = [];
                    }
                    $miss_v = array_values( array_diff( $expected_values, $actual_values ) );
                    $extra_v = array_values( array_diff( $actual_values, $expected_values ) );
                    if ( ! empty( $miss_v ) || ! empty( $extra_v ) ) {
                        $enum_mismatches[] = [
                            'column'          => $col_name,
                            'expected_values' => $expected_values,
                            'actual_values'   => $actual_values,
                            'missing_values'  => $miss_v,
                            'extra_values'    => $extra_v,
                        ];
                    }
                }

                $expected_indexes = [];
                if ( ! empty( $schema['primary_key'] ) ) {
                    $pk_cols = is_array( $schema['primary_key'] ) ? $schema['primary_key'] : [ $schema['primary_key'] ];
                    $expected_indexes['PRIMARY'] = [
                        'unique'  => true,
                        'columns' => self::normalize_index_columns( $pk_cols ),
                    ];
                }
                if ( ! empty( $schema['unique_keys'] ) ) {
                    foreach ( $schema['unique_keys'] as $key_name => $cols ) {
                        $expected_indexes[ $key_name ] = [
                            'unique'  => true,
                            'columns' => self::normalize_index_columns( $cols ),
                        ];
                    }
                }
                if ( ! empty( $schema['keys'] ) ) {
                    foreach ( $schema['keys'] as $key_name => $cols ) {
                        $expected_indexes[ $key_name ] = [
                            'unique'  => false,
                            'columns' => self::normalize_index_columns( $cols ),
                        ];
                    }
                }

                $actual_idx_normalized = [];
                if ( isset( $actual_indexes[ $logical ] ) ) {
                    foreach ( $actual_indexes[ $logical ] as $idx_name => $info ) {
                        $actual_idx_normalized[ $idx_name ] = [
                            'unique'  => $info['unique'],
                            'columns' => self::normalize_index_columns( $info['columns'] ),
                        ];
                    }
                }

                $missing_indexes = [];
                $extra_indexes   = [];
                foreach ( $expected_indexes as $name => $exp ) {
                    if ( ! isset( $actual_idx_normalized[ $name ] )
                         || $actual_idx_normalized[ $name ]['columns'] !== $exp['columns']
                         || $actual_idx_normalized[ $name ]['unique'] !== $exp['unique'] ) {
                        $missing_indexes[] = [
                            'name'    => $name,
                            'unique'  => $exp['unique'],
                            'columns' => $exp['columns'],
                        ];
                    }
                }
                foreach ( $actual_idx_normalized as $name => $act ) {
                    if ( ! isset( $expected_indexes[ $name ] ) ) {
                        $extra_indexes[] = [
                            'name'    => $name,
                            'unique'  => $act['unique'],
                            'columns' => $act['columns'],
                        ];
                    }
                }

                $status = ( empty( $missing ) && empty( $extra ) && empty( $enum_mismatches ) && empty( $missing_indexes ) && empty( $extra_indexes ) ) ? 'ok' : 'mismatch';

                $result[ $logical ] = [
                    'exists'           => true,
                    'status'           => $status,
                    'expected_columns' => $expected_cols,
                    'actual_columns'   => $actual_cols,
                    'missing_columns'  => $missing,
                    'extra_columns'    => $extra,
                    'enum_mismatches'  => $enum_mismatches,
                    'missing_indexes'  => $missing_indexes,
                    'extra_indexes'    => $extra_indexes,
                ];
            }

            return $result;
        }

        public static function parse_enum_values( $col_def ){
            if ( ! preg_match( '/^\s*enum\s*\(([^)]+)\)/i', $col_def, $m ) ) {
                return null;
            }
            $parts  = explode( ',', $m[1] );
            $values = [];
            foreach ( $parts as $p ) {
                $values[] = trim( $p, " '\"" );
            }
            return $values;
        }

        public function get_actual_indexes(){
            global $wpdb;

            $logical_to_table = $this->get_logical_to_table_map();
            $table_names      = array_values( $logical_to_table );

            if ( empty( $table_names ) ) {
                return [];
            }

            $placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE, SUB_PART FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$placeholders}) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
                    array_merge( [ DB_NAME ], $table_names )
                )
            );

            $table_to_logical = array_flip( $logical_to_table );
            $result = [];

            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( ! isset( $table_to_logical[ $row->TABLE_NAME ] ) ) {
                        continue;
                    }
                    $logical = $table_to_logical[ $row->TABLE_NAME ];
                    $index   = $row->INDEX_NAME;
                    if ( ! isset( $result[ $logical ][ $index ] ) ) {
                        $result[ $logical ][ $index ] = [
                            'unique'  => (int) $row->NON_UNIQUE === 0,
                            'columns' => [],
                        ];
                    }
                    $col = $row->COLUMN_NAME;
                    if ( $row->SUB_PART !== null ) {
                        $col .= '(' . (int) $row->SUB_PART . ')';
                    }
                    $result[ $logical ][ $index ]['columns'][] = $col;
                }
            }

            return $result;
        }

        public static function normalize_index_columns( $cols ){
            $parts = is_array( $cols ) ? $cols : explode( ',', (string) $cols );
            $out   = [];
            foreach ( $parts as $p ) {
                $out[] = strtolower( trim( $p ) );
            }
            return implode( ',', $out );
        }

        public function get_actual_enum_types(){
            global $wpdb;

            $logical_to_table = $this->get_logical_to_table_map();
            $table_names      = array_values( $logical_to_table );

            if ( empty( $table_names ) ) {
                return [];
            }

            $placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$placeholders}) AND DATA_TYPE = 'enum'",
                    array_merge( [ DB_NAME ], $table_names )
                )
            );

            $table_to_logical = array_flip( $logical_to_table );
            $result = [];

            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( isset( $table_to_logical[ $row->TABLE_NAME ] ) ) {
                        $logical = $table_to_logical[ $row->TABLE_NAME ];
                        if ( ! isset( $result[ $logical ] ) ) {
                            $result[ $logical ] = [];
                        }
                        $result[ $logical ][ $row->COLUMN_NAME ] = $row->COLUMN_TYPE;
                    }
                }
            }

            return $result;
        }

        public function get_row_counts(){
            global $wpdb;

            $logical_to_table = $this->get_logical_to_table_map();
            $table_names      = array_values( $logical_to_table );

            $result = [];
            foreach ( array_keys( $logical_to_table ) as $logical ) {
                $result[ $logical ] = 0;
            }

            if ( empty( $table_names ) ) {
                return $result;
            }

            $placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );

            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$placeholders})",
                    array_merge( [ DB_NAME ], $table_names )
                )
            );

            $table_to_logical = array_flip( $logical_to_table );
            if ( is_array( $rows ) ) {
                foreach ( $rows as $row ) {
                    if ( isset( $table_to_logical[ $row->TABLE_NAME ] ) ) {
                        $logical = $table_to_logical[ $row->TABLE_NAME ];
                        $result[ $logical ] = (int) $row->TABLE_ROWS;
                    }
                }
            }

            return $result;
        }

        public function get_collation_info(){
            global $wpdb;

            $expected_collation = $wpdb->collate ? $wpdb->collate : 'utf8mb4_unicode_ci';
            $expected_charset   = $wpdb->charset ? $wpdb->charset : 'utf8mb4';

            $logical_to_table = $this->get_logical_to_table_map();
            $table_names      = array_values( $logical_to_table );

            $tables_result = [];
            foreach ( array_keys( $logical_to_table ) as $logical ) {
                $tables_result[ $logical ] = [
                    'table_collation'    => null,
                    'collation_mismatch' => false,
                    'mismatched_columns' => [],
                ];
            }

            if ( empty( $table_names ) ) {
                return [
                    'expected_collation' => $expected_collation,
                    'expected_charset'   => $expected_charset,
                    'tables'             => $tables_result,
                ];
            }

            $placeholders = implode( ', ', array_fill( 0, count( $table_names ), '%s' ) );

            $table_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT TABLE_NAME, TABLE_COLLATION FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$placeholders})",
                    array_merge( [ DB_NAME ], $table_names )
                )
            );

            $col_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME IN ({$placeholders}) AND COLLATION_NAME IS NOT NULL ORDER BY TABLE_NAME, ORDINAL_POSITION",
                    array_merge( [ DB_NAME ], $table_names )
                )
            );

            $table_to_logical = array_flip( $logical_to_table );

            $table_collations = [];
            if ( is_array( $table_rows ) ) {
                foreach ( $table_rows as $row ) {
                    if ( isset( $table_to_logical[ $row->TABLE_NAME ] ) ) {
                        $table_collations[ $table_to_logical[ $row->TABLE_NAME ] ] = $row->TABLE_COLLATION;
                    }
                }
            }

            $col_mismatches = [];
            if ( is_array( $col_rows ) ) {
                foreach ( $col_rows as $row ) {
                    if ( isset( $table_to_logical[ $row->TABLE_NAME ] ) && $row->COLLATION_NAME !== $expected_collation ) {
                        $logical = $table_to_logical[ $row->TABLE_NAME ];
                        if ( ! isset( $col_mismatches[ $logical ] ) ) {
                            $col_mismatches[ $logical ] = [];
                        }
                        $col_mismatches[ $logical ][] = [
                            'column'    => $row->COLUMN_NAME,
                            'collation' => $row->COLLATION_NAME,
                        ];
                    }
                }
            }

            foreach ( array_keys( $logical_to_table ) as $logical ) {
                $table_collation     = isset( $table_collations[ $logical ] ) ? $table_collations[ $logical ] : null;
                $mismatched_cols     = isset( $col_mismatches[ $logical ] ) ? $col_mismatches[ $logical ] : [];
                $table_level_mismatch = ( $table_collation !== null && $table_collation !== $expected_collation );

                $tables_result[ $logical ] = [
                    'table_collation'    => $table_collation,
                    'collation_mismatch' => $table_level_mismatch || ! empty( $mismatched_cols ),
                    'mismatched_columns' => $mismatched_cols,
                ];
            }

            return [
                'expected_collation' => $expected_collation,
                'expected_charset'   => $expected_charset,
                'tables'             => $tables_result,
            ];
        }

        public function repair_table( $logical ){
            global $wpdb;

            $schemas = $this->get_schemas();
            if ( ! isset( $schemas[ $logical ] ) ) {
                return false;
            }

            set_time_limit( 0 );
            ignore_user_abort( true );
            require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

            $table_name      = $this->resolve_table_name( $logical, $schemas[ $logical ] );
            $charset_collate = $wpdb->get_charset_collate();

            $cmp = $this->compare_schemas();
            if ( isset( $cmp[ $logical ]['missing_indexes'] ) && ! empty( $cmp[ $logical ]['missing_indexes'] ) ) {
                $actual_indexes = $this->get_actual_indexes();
                foreach ( $cmp[ $logical ]['missing_indexes'] as $mi ) {
                    $name = $mi['name'];
                    if ( $name === 'PRIMARY' ) {
                        continue;
                    }
                    if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $name ) ) {
                        continue;
                    }
                    if ( isset( $actual_indexes[ $logical ][ $name ] ) ) {
                        $wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `{$name}`" );
                    }
                }
            }

            $sql = $this->build_create_sql( $table_name, $schemas[ $logical ] ) . " {$charset_collate};";

            dbDelta( $sql );

            return $wpdb->last_error === '';
        }

        public function fix_collation( $logical ){
            global $wpdb;

            $schemas = $this->get_schemas();
            if ( ! isset( $schemas[ $logical ] ) ) {
                return false;
            }

            $charset    = $wpdb->charset ? $wpdb->charset : 'utf8mb4';
            $collation  = $wpdb->collate ? $wpdb->collate : 'utf8mb4_unicode_ci';
            $table_name = $this->resolve_table_name( $logical, $schemas[ $logical ] );

            $wpdb->query( "ALTER TABLE `{$table_name}` CONVERT TO CHARACTER SET {$charset} COLLATE {$collation}" );

            return $wpdb->last_error === '';
        }

        public function drop_indexes( $logical, $indexes ){
            global $wpdb;

            $schemas = $this->get_schemas();
            if ( ! isset( $schemas[ $logical ] ) ) {
                return [ 'dropped' => [], 'errors' => [ "Unknown table: {$logical}" ] ];
            }

            $expected_index_names = [ 'PRIMARY' ];
            if ( ! empty( $schemas[ $logical ]['unique_keys'] ) ) {
                $expected_index_names = array_merge( $expected_index_names, array_keys( $schemas[ $logical ]['unique_keys'] ) );
            }
            if ( ! empty( $schemas[ $logical ]['keys'] ) ) {
                $expected_index_names = array_merge( $expected_index_names, array_keys( $schemas[ $logical ]['keys'] ) );
            }

            $table_name = $this->resolve_table_name( $logical, $schemas[ $logical ] );
            $dropped    = [];
            $errors     = [];

            foreach ( $indexes as $idx ) {
                if ( ! is_string( $idx ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $idx ) ) {
                    $errors[] = "Invalid index name: '{$idx}'";
                    continue;
                }

                if ( in_array( $idx, $expected_index_names, true ) ) {
                    $errors[] = "Index '{$idx}' is part of the expected schema and cannot be dropped.";
                    continue;
                }

                $wpdb->query( "ALTER TABLE `{$table_name}` DROP INDEX `{$idx}`" );

                if ( $wpdb->last_error ) {
                    $errors[] = "Failed to drop index '{$idx}': " . $wpdb->last_error;
                } else {
                    $dropped[] = $idx;
                }
            }

            return [ 'dropped' => $dropped, 'errors' => $errors ];
        }

        public function drop_columns( $logical, $columns ){
            global $wpdb;

            $schemas = $this->get_schemas();
            if ( ! isset( $schemas[ $logical ] ) ) {
                return [ 'dropped' => [], 'errors' => [ "Unknown table: {$logical}" ] ];
            }

            $expected_cols = array_keys( $schemas[ $logical ]['columns'] );
            $table_name    = $this->resolve_table_name( $logical, $schemas[ $logical ] );
            $dropped       = [];
            $errors        = [];

            foreach ( $columns as $col ) {
                if ( ! is_string( $col ) || ! preg_match( '/^[a-zA-Z0-9_]+$/', $col ) ) {
                    $errors[] = "Invalid column name: '{$col}'";
                    continue;
                }

                if ( in_array( $col, $expected_cols, true ) ) {
                    $errors[] = "Column '{$col}' is part of the expected schema and cannot be dropped.";
                    continue;
                }

                $wpdb->query( "ALTER TABLE `{$table_name}` DROP COLUMN `{$col}`" );

                if ( $wpdb->last_error ) {
                    $errors[] = "Failed to drop '{$col}': " . $wpdb->last_error;
                } else {
                    $dropped[] = $col;
                }
            }

            return [ 'dropped' => $dropped, 'errors' => $errors ];
        }

        public function repair_all(){
            set_time_limit( 0 );
            ignore_user_abort( true );

            $compare   = $this->compare_schemas();
            $collation = $this->get_collation_info();

            $repaired = [];
            foreach ( $compare as $logical => $info ) {
                if ( $info['status'] !== 'ok' ) {
                    $this->repair_table( $logical );
                    $repaired[] = $logical;
                }
            }

            $collation_fixed = [];
            foreach ( $collation['tables'] as $logical => $info ) {
                if ( $info['collation_mismatch'] ) {
                    $this->fix_collation( $logical );
                    $collation_fixed[] = $logical;
                }
            }

            return [
                'repaired'        => $repaired,
                'collation_fixed' => $collation_fixed,
            ];
        }

        public function get_full_inspect(){
            $schemas   = $this->get_schemas();
            $compare   = $this->compare_schemas();
            $row_counts = $this->get_row_counts();
            $coll      = $this->get_collation_info();

            $tables = [];
            $totals = [ 'ok' => 0, 'mismatch' => 0, 'missing' => 0, 'collation_mismatch' => 0 ];

            foreach ( $schemas as $logical => $schema ) {
                $cmp = isset( $compare[ $logical ] ) ? $compare[ $logical ] : null;
                $col = isset( $coll['tables'][ $logical ] ) ? $coll['tables'][ $logical ] : [
                    'table_collation'    => null,
                    'collation_mismatch' => false,
                    'mismatched_columns' => [],
                ];

                $expected_cols = array_keys( $schema['columns'] );

                $tables[] = [
                    'name'               => $logical,
                    'table_name'         => $this->resolve_table_name( $logical, $schema ),
                    'status'             => $cmp ? $cmp['status'] : 'missing',
                    'exists'             => $cmp ? $cmp['exists'] : false,
                    'expected_columns'   => $expected_cols,
                    'actual_columns'     => $cmp ? $cmp['actual_columns'] : [],
                    'missing_columns'    => $cmp ? $cmp['missing_columns'] : $expected_cols,
                    'extra_columns'      => $cmp ? $cmp['extra_columns'] : [],
                    'enum_mismatches'    => $cmp ? $cmp['enum_mismatches'] : [],
                    'missing_indexes'    => $cmp ? $cmp['missing_indexes'] : [],
                    'extra_indexes'      => $cmp ? $cmp['extra_indexes'] : [],
                    'row_count'          => isset( $row_counts[ $logical ] ) ? $row_counts[ $logical ] : 0,
                    'table_collation'    => $col['table_collation'],
                    'collation_mismatch' => $col['collation_mismatch'],
                    'mismatched_columns' => $col['mismatched_columns'],
                ];

                $status = $cmp ? $cmp['status'] : 'missing';
                if ( isset( $totals[ $status ] ) ) {
                    $totals[ $status ]++;
                }
                if ( $col['collation_mismatch'] ) {
                    $totals['collation_mismatch']++;
                }
            }

            return [
                'tables'               => $tables,
                'expected_collation'   => $coll['expected_collation'],
                'expected_charset'     => $coll['expected_charset'],
                'db_version'           => (string) $this->db_version,
                'installed_db_version' => (string) get_option( 'better_messages_2_db_version', '0' ),
                'totals'               => $totals,
                'utf8mb4_supported'    => $this->is_utf8mb4_supported(),
            ];
        }

        private function is_utf8mb4_supported(){
            global $wpdb;
            return $wpdb->has_cap( 'utf8mb4' );
        }

        public function get_thread_type( $thread_id ){
            global $wpdb;

            if( Better_Messages()->settings['enableGroups'] === '1' ) {
                $group_id = $wpdb->get_var( $wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}bpbm_threadsmeta WHERE `bpbm_threads_id` = %d AND `meta_key` = 'group_id'", $thread_id ) );
                if ( !! $group_id && bm_bp_is_active('groups') ) {
                    if (Better_Messages()->groups->is_group_messages_enabled($group_id) === 'enabled') {
                        return 'group';
                    }
                }
            }

            if( Better_Messages()->settings['PSenableGroups'] === '1' ) {
                $group_id = $wpdb->get_var( $wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}bpbm_threadsmeta WHERE `bpbm_threads_id` = %d AND `meta_key` = 'peepso_group_id'", $thread_id ) );

                if ( !! $group_id ){
                    return 'group';
                }
            }

            if( function_exists('UM') && Better_Messages()->settings['UMenableGroups'] === '1' ) {
                $group_id = $wpdb->get_var( $wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}bpbm_threadsmeta WHERE `bpbm_threads_id` = %d AND `meta_key` = 'um_group_id'", $thread_id ) );


                if ( !! $group_id ){
                    return 'group';
                }
            }

            $chat_id = $wpdb->get_var( $wpdb->prepare("SELECT meta_value FROM {$wpdb->prefix}bpbm_threadsmeta WHERE `bpbm_threads_id` = %d AND `meta_key` = 'chat_id'", $thread_id ) );

            if( ! empty( $chat_id ) ) {
                return 'chat-room';
            }

            return 'thread';
        }
    }


    function Better_Messages_Rest_Api_DB_Migrate(){
        return Better_Messages_Rest_Api_DB_Migrate::instance();
    }
endif;
