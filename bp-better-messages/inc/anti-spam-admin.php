<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Anti_Spam_Admin' ) ):

    class Better_Messages_Anti_Spam_Admin
    {
        const QUEUE = 'better_messages_spam_cleanups';

        const TASK = 'better_messages_spam_cleanup';

        const WATCH_DAYS = 7;

        const MAILED_META = 'bm_spam_review_mailed';

        private $review_mail = array();

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Anti_Spam_Admin();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'rest_api_init', array( $this, 'rest_api_init' ) );
            add_action( 'init', array( $this, 'schedule' ), 20 );
            add_action( self::TASK, array( $this, 'run_cleanups' ), 10, 0 );
            add_action( 'better_messages_account_state_changed', array( $this, 'state_changed' ), 10, 3 );
            add_action( 'admin_init', array( $this, 'dismiss_week_notice' ) );
            add_action( 'admin_notices', array( $this, 'week_notice' ) );
        }

        public function monitor_report()
        {
            if ( Better_Messages()->anti_spam->level() !== 'monitor' ) {
                return null;
            }

            $since = $this->monitor_since();
            $days  = (int) floor( ( time() - $since ) / DAY_IN_SECONDS );
            $found = get_transient( 'better_messages_spam_monitor_report' );

            if ( ! is_array( $found ) || (int) ( $found['since'] ?? 0 ) !== $since ) {
                $engine = $this->engine();
                $rows   = $engine ? (array) $engine->flagged( Better_Messages_Anti_Spam::PLUGIN, max( DAY_IN_SECONDS, min( time() - $since, 30 * DAY_IN_SECONDS ) ), 1000 ) : array();
                $found  = array( 'since' => $since, 'actions' => 0, 'accounts' => 0 );

                foreach ( $rows as $row ) {
                    if ( (int) $row['monitored'] > 0 ) {
                        $found['actions'] += (int) $row['monitored'];
                        $found['accounts']++;
                    }
                }

                set_transient( 'better_messages_spam_monitor_report', $found, HOUR_IN_SECONDS );
            }

            return array(
                'since'    => $since,
                'days'     => $days,
                'actions'  => (int) $found['actions'],
                'accounts' => (int) $found['accounts'],
            );
        }

        private function monitor_since()
        {
            $since = (int) get_option( 'better_messages_spam_monitor_since', 0 );

            if ( $since === 0 ) {
                $since = time();
                update_option( 'better_messages_spam_monitor_since', $since, false );
            }

            return $since;
        }

        public function week_notice()
        {
            if ( ! current_user_can( 'manage_options' ) || get_option( 'better_messages_spam_week_notice' ) === 'dismissed' ) {
                return;
            }

            if ( Better_Messages()->anti_spam->level() !== 'monitor' || time() - $this->monitor_since() < 7 * DAY_IN_SECONDS ) {
                return;
            }

            $report = $this->monitor_report();

            if ( ! $report ) {
                return;
            }

            $tab     = admin_url( 'admin.php?page=better-messages#/moderation' );
            $dismiss = wp_nonce_url( add_query_arg( 'bm-spam-week-notice', 'dismiss' ), 'bm-spam-week-notice' );

            echo '<div class="notice notice-info bm-spam-week-notice"><p><strong>' . esc_html_x( 'Better Messages Spam Protection', 'Spam Protection', 'bp-better-messages' ) . '</strong></p><p>';

            if ( $report['actions'] > 0 ) {
                echo esc_html( sprintf( _x( 'In Monitor only for %1$d days, it would have stopped %2$d actions from %3$d accounts. Choose a level that acts on them in Settings, Moderation.', 'Spam Protection', 'bp-better-messages' ), $report['days'], $report['actions'], $report['accounts'] ) );
            } else {
                echo esc_html( sprintf( _x( 'In Monitor only for %d days, it found nothing to stop. Choose a level that acts in Settings, Moderation when you are ready.', 'Spam Protection', 'bp-better-messages' ), $report['days'] ) );
            }

            echo '</p><p><a class="button button-primary" href="' . esc_url( $tab ) . '">' . esc_html_x( 'Open Moderation', 'Spam Protection', 'bp-better-messages' ) . '</a> <a class="button-link" href="' . esc_url( $dismiss ) . '">' . esc_html_x( 'Dismiss', 'Spam Protection', 'bp-better-messages' ) . '</a></p></div>';
        }

        public function dismiss_week_notice()
        {
            if ( ! isset( $_GET['bm-spam-week-notice'] ) || ! current_user_can( 'manage_options' ) ) {
                return;
            }

            check_admin_referer( 'bm-spam-week-notice' );

            update_option( 'better_messages_spam_week_notice', 'dismissed', false );

            wp_safe_redirect( remove_query_arg( array( 'bm-spam-week-notice', '_wpnonce' ) ) );
            exit;
        }

        public function state_changed( $user_id, $state, $rules = array() )
        {
            if ( $state !== 'review' ) {
                return;
            }

            if ( empty( $this->review_mail ) ) {
                add_action( 'shutdown', array( $this, 'send_review_emails' ) );
            }

            $this->review_mail[ (int) $user_id ] = array_values( array_map( 'strval', (array) $rules ) );
        }

        public function send_review_emails()
        {
            $emails = Better_Messages()->moderation->get_notification_emails();

            foreach ( $this->review_mail as $user_id => $rules ) {
                $this->send_review_email( (int) $user_id, $rules, $emails );
            }

            $this->review_mail = array();
        }

        public function send_review_email( $user_id, array $rules, array $emails )
        {
            global $wpdb;

            if ( empty( $emails ) || $user_id === 0 || ( Better_Messages()->settings['spamReviewEmails'] ?? '1' ) !== '1' ) {
                return false;
            }

            $last = (int) Better_Messages()->functions->get_user_meta( $user_id, self::MAILED_META, true );

            if ( $last > time() - DAY_IN_SECONDS ) {
                return false;
            }

            Better_Messages()->functions->update_user_meta( $user_id, self::MAILED_META, time() );

            $spam    = Better_Messages()->anti_spam;
            $heading = sprintf( _x( '%s is under review', 'Moderation email', 'bp-better-messages' ), Better_Messages()->functions->get_plain_name( $user_id ) );
            $subject = html_entity_decode( sprintf( '[%s] %s', get_bloginfo( 'name' ), $heading ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $blocks  = array();
            $why     = array_map( array( $spam, 'rule_words' ), $rules );

            if ( $why ) {
                $blocks[] = array( 'type' => 'text', 'text' => sprintf( _x( 'Why: %s', 'Moderation email', 'bp-better-messages' ), implode( ', ', array_unique( $why ) ) ) );
            }

            $held = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT `id` FROM " . bm_get_table( 'messages' ) . " WHERE `sender_id` = %d AND `is_pending` = 1 ORDER BY `id` DESC LIMIT 3",
                $user_id
            ) ) );

            foreach ( array_reverse( $held ) as $id ) {
                $message = Better_Messages()->functions->get_message( $id );

                if ( $message ) {
                    $blocks[] = Better_Messages()->notifications->moderator_message_block( $message );
                }
            }

            return Better_Messages()->notifications->moderator_email( $emails, $subject, array(
                'preheader'  => _x( 'Everything this account sends is held until a moderator looks at it', 'Moderation email', 'bp-better-messages' ),
                'heading'    => $heading,
                'subheading' => _x( 'Everything this account sends is held until a moderator looks at it', 'Moderation email', 'bp-better-messages' ),
                'blocks'     => $blocks,
                'button'     => array( 'label' => _x( 'Open Review', 'Moderation email', 'bp-better-messages' ), 'url' => admin_url( 'admin.php?page=better-messages-viewer#/review?view=accounts' ) ),
            ) );
        }

        public function schedule()
        {
            if ( function_exists( 'Better_Messages_Worker' ) ) {
                Better_Messages_Worker()->add_task( self::TASK, MINUTE_IN_SECONDS );
            }
        }

        public function can_review()
        {
            return current_user_can( 'bm_can_administrate' );
        }

        public function rest_api_init()
        {
            register_rest_route( 'better-messages/v1/admin', '/spam/review', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'review_api' ),
                'permission_callback' => array( $this, 'can_review' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/spam/spammer', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'spammer_api' ),
                'permission_callback' => array( $this, 'can_review' ),
            ) );

            register_rest_route( 'better-messages/v1/admin', '/spam/action', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'action_api' ),
                'permission_callback' => array( $this, 'can_review' ),
            ) );
        }

        private function engine()
        {
            return Better_Messages()->anti_spam->engine();
        }

        public function accounts_to_review()
        {
            global $wpdb;

            $table = bm_get_table( 'moderation' );

            return (int) $wpdb->get_var(
                "SELECT COUNT(DISTINCT `review`.`user_id`)
                FROM `{$table}` `review`
                WHERE `review`.`type` = 'force_moderation' AND `review`.`thread_id` = 0 AND ( `review`.`expiration` IS NULL OR `review`.`expiration` > NOW() )
                AND NOT EXISTS ( SELECT 1 FROM `{$table}` `suspended` WHERE `suspended`.`user_id` = `review`.`user_id` AND `suspended`.`type` = 'suspend' AND `suspended`.`thread_id` = 0 AND ( `suspended`.`expiration` IS NULL OR `suspended`.`expiration` > NOW() ) )"
            );
        }

        private function states( $type )
        {
            global $wpdb;

            return (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT `user_id`, `admin_id`, UNIX_TIMESTAMP(`created_at`) AS `since`
                FROM " . bm_get_table( 'moderation' ) . "
                WHERE `type` = %s AND `thread_id` = 0 AND ( `expiration` IS NULL OR `expiration` > NOW() )
                ORDER BY `created_at` DESC
                LIMIT 100",
                $type
            ), ARRAY_A );
        }

        public function held_messages( $user_id, $limit = 5 )
        {
            global $wpdb;

            $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT `id` FROM " . bm_get_table( 'messages' ) . " WHERE `sender_id` = %d AND `is_pending` = 1 ORDER BY `id` DESC LIMIT %d",
                $user_id,
                $limit
            ) ) );

            $held = array();

            foreach ( $ids as $id ) {
                $message = Better_Messages()->functions->get_message( $id );

                if ( ! $message ) {
                    continue;
                }

                $spam = Better_Messages()->functions->get_message_meta( $id, 'bm_spam', true );
                $text = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( (string) $message->message ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );

                $held[] = array(
                    'id'       => $id,
                    'threadId' => (int) $message->thread_id,
                    'text'     => mb_substr( $text, 0, 160 ),
                    'rules'    => is_array( $spam ) && isset( $spam['rules'] ) ? array_values( (array) $spam['rules'] ) : array(),
                    'at'       => (int) floor( (int) $message->created_at / 10000 ),
                );
            }

            return $held;
        }

        private function count_held( $user_id )
        {
            global $wpdb;

            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . bm_get_table( 'messages' ) . " WHERE `sender_id` = %d AND `is_pending` = 1", $user_id ) );
        }

        private function account( $user_id, $state, $since = 0 )
        {
            $engine = $this->engine();
            $item   = Better_Messages()->functions->rest_user_item( $user_id );
            $meta   = Better_Messages()->functions->get_user_meta( $user_id, $state === 'suspended' ? 'bm_spam_suspended' : Better_Messages_Anti_Spam::REVIEW_META, true );
            $rules  = is_array( $meta ) && isset( $meta['rules'] ) ? (array) $meta['rules'] : array();
            $counts = array();

            if ( $engine ) {
                foreach ( $engine->history( Better_Messages_Anti_Spam::PLUGIN, $user_id, self::WATCH_DAYS * DAY_IN_SECONDS, 200 ) as $row ) {
                    foreach ( array_filter( explode( ',', (string) $row['rules'] ) ) as $rule ) {
                        $counts[ $rule ] = ( $counts[ $rule ] ?? 0 ) + 1;
                    }
                }
            }

            foreach ( $rules as $rule ) {
                if ( ! isset( $counts[ $rule ] ) ) {
                    $counts[ $rule ] = 0;
                }
            }

            $registered = 0;

            if ( $user_id > 0 ) {
                $user       = get_userdata( $user_id );
                $registered = $user ? (int) strtotime( $user->user_registered . ' UTC' ) : 0;
            }

            return array(
                'id'         => (int) $user_id,
                'name'       => is_array( $item ) && isset( $item['name'] ) ? (string) $item['name'] : '',
                'avatar'     => is_array( $item ) && isset( $item['avatar'] ) ? (string) $item['avatar'] : '',
                'url'        => is_array( $item ) && isset( $item['url'] ) ? (string) $item['url'] : '',
                'guest'      => $user_id < 0,
                'state'      => $state,
                'since'      => (int) $since,
                'registered' => $registered,
                'sinceText'      => $since > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $since ) : '',
                'registeredText' => $registered > 0 ? wp_date( get_option( 'date_format' ), $registered ) : '',
                'trust'      => Better_Messages()->anti_spam->trust( $user_id ),
                'strikes'    => $engine ? $engine->strikes( Better_Messages_Anti_Spam::PLUGIN, $user_id ) : 0,
                'rules'      => $counts,
                'heldCount'  => $this->count_held( $user_id ),
                'held'       => $this->held_messages( $user_id ),
            );
        }

        public function review()
        {
            global $wpdb;

            $engine   = $this->engine();
            $accounts = array();
            $seen     = array();

            foreach ( $this->states( 'suspend' ) as $row ) {
                $seen[ (int) $row['user_id'] ] = true;
                $accounts[] = $this->account( (int) $row['user_id'], 'suspended', (int) $row['since'] );
            }

            foreach ( $this->states( 'force_moderation' ) as $row ) {
                if ( isset( $seen[ (int) $row['user_id'] ] ) ) {
                    continue;
                }

                $seen[ (int) $row['user_id'] ] = true;
                $accounts[] = $this->account( (int) $row['user_id'], 'review', (int) $row['since'] );
            }

            $watched = array();

            if ( $engine ) {
                foreach ( $engine->flagged( Better_Messages_Anti_Spam::PLUGIN, self::WATCH_DAYS * DAY_IN_SECONDS, 50 ) as $row ) {
                    if ( isset( $seen[ $row['actor'] ] ) || Better_Messages()->anti_spam->trust( $row['actor'] ) === 'trusted' ) {
                        continue;
                    }

                    $account              = $this->account( $row['actor'], 'watched', $row['last'] );
                    $account['monitored'] = $row['monitored'];
                    $watched[]            = $account;
                }
            }

            $monitor = null;

            if ( Better_Messages()->anti_spam->level() === 'monitor' ) {
                $table  = $wpdb->prefix . 'wordplus_spam';
                $exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

                $row = $exists ? $wpdb->get_row( $wpdb->prepare(
                    "SELECT COUNT(*) AS messages, COUNT(DISTINCT actor) AS accounts FROM {$table} WHERE plugin = %s AND created_at > %d AND would IN ('hold', 'refuse') AND action IN ('start', 'send')",
                    Better_Messages_Anti_Spam::PLUGIN,
                    time() - self::WATCH_DAYS * DAY_IN_SECONDS
                ), ARRAY_A ) : null;

                $monitor = array(
                    'messages' => $row ? (int) $row['messages'] : 0,
                    'accounts' => $row ? (int) $row['accounts'] : 0,
                    'days'     => self::WATCH_DAYS,
                );
            }

            return array(
                'level'     => Better_Messages()->anti_spam->level(),
                'accounts'  => $accounts,
                'watched'   => $watched,
                'monitor'   => $monitor,
                'canDelete' => current_user_can( 'delete_users' ),
                'cleanups'  => array_map( 'intval', array_keys( $this->queue() ) ),
            );
        }

        public function review_api( WP_REST_Request $request )
        {
            return $this->review();
        }

        public function spammer_counts( $user_id )
        {
            global $wpdb;

            $messages = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM " . bm_get_table( 'messages' ) . " WHERE `sender_id` = %d", $user_id ) );

            return array(
                'messages'      => $messages,
                'conversations' => count( $this->started_without_replies( $user_id, 1000 ) ),
                'recipients'    => count( $this->recipients_of( $user_id, 1000 ) ),
            );
        }

        public function spammer_api( WP_REST_Request $request )
        {
            $user_id = (int) $request->get_param( 'userId' );

            if ( $user_id === 0 ) {
                return new WP_Error( 'no_user', 'No user', array( 'status' => 400 ) );
            }

            return $this->spammer_counts( $user_id );
        }

        public function started_without_replies( $user_id, $limit = 50 )
        {
            global $wpdb;

            $messages = bm_get_table( 'messages' );
            $threads  = bm_get_table( 'threads' );
            $meta     = bm_get_table( 'threadsmeta' );

            return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT `mine`.`thread_id`
                FROM `{$messages}` `mine`
                INNER JOIN `{$threads}` `conversation` ON `conversation`.`id` = `mine`.`thread_id` AND `conversation`.`type` = 'thread'
                WHERE `mine`.`sender_id` = %d
                AND NOT EXISTS ( SELECT 1 FROM `{$messages}` `other` WHERE `other`.`thread_id` = `mine`.`thread_id` AND `other`.`sender_id` NOT IN ( %d, 0 ) )
                AND NOT EXISTS ( SELECT 1 FROM `{$meta}` `started` WHERE `started`.`bm_thread_id` = `mine`.`thread_id` AND ( ( `started`.`meta_key` = 'thread_starter_user_id' AND `started`.`meta_value` <> %s ) OR `started`.`meta_key` = %s ) )
                GROUP BY `mine`.`thread_id`
                LIMIT %d",
                $user_id,
                $user_id,
                (string) $user_id,
                class_exists( 'Better_Messages_Bookings' ) ? Better_Messages_Bookings::META_KEY : 'booking_key',
                $limit
            ) ) );
        }

        public function recipients_of( $user_id, $limit = 200 )
        {
            global $wpdb;

            return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT `recipients`.`user_id`
                FROM " . bm_get_table( 'recipients' ) . " `recipients`
                WHERE `recipients`.`thread_id` IN ( SELECT DISTINCT `thread_id` FROM " . bm_get_table( 'messages' ) . " WHERE `sender_id` = %d )
                AND `recipients`.`user_id` <> %d
                LIMIT %d",
                $user_id,
                $user_id,
                $limit
            ) ) );
        }

        public function queue()
        {
            $queue = get_option( self::QUEUE, array() );

            return is_array( $queue ) ? $queue : array();
        }

        private function save_queue( array $queue )
        {
            if ( empty( $queue ) ) {
                delete_option( self::QUEUE );
            } else {
                update_option( self::QUEUE, $queue, false );
            }
        }

        public function queue_cleanup( $user_id, array $options )
        {
            $queue = $this->queue();

            $queue[ (int) $user_id ] = array(
                'messages'      => ! empty( $options['messages'] ),
                'conversations' => ! empty( $options['conversations'] ),
                'account'       => ! empty( $options['account'] ),
                'at'            => time(),
            );

            $this->save_queue( $queue );
        }

        public function mark_spammer( $user_id, array $options )
        {
            $user_id = (int) $user_id;

            if ( $user_id === 0 || Better_Messages()->anti_spam->trust( $user_id ) === 'trusted' ) {
                return new WP_Error( 'not_allowed', _x( 'This account cannot be marked as a spammer.', 'Spam Protection', 'bp-better-messages' ), array( 'status' => 403 ) );
            }

            if ( ! empty( $options['account'] ) && $user_id > 0 && ! current_user_can( 'delete_users' ) ) {
                $options['account'] = false;
            }

            $counts     = $this->spammer_counts( $user_id );
            $recipients = $this->recipients_of( $user_id, 200 );

            if ( ! Better_Messages()->anti_spam->suspend( $user_id, array( 'spammer' ) ) ) {
                return new WP_Error( 'not_suspended', _x( 'The account could not be suspended. Open Better Messages → Administration once to finish updating the database, then try again.', 'Spam Protection', 'bp-better-messages' ), array( 'status' => 409 ) );
            }

            $this->queue_cleanup( $user_id, array(
                'messages'      => ! isset( $options['messages'] ) || ! empty( $options['messages'] ),
                'conversations' => ! isset( $options['conversations'] ) || ! empty( $options['conversations'] ),
                'account'       => ! empty( $options['account'] ),
            ) );

            $this->run_cleanups( 5 );

            do_action( 'better_messages_marked_as_spammer', $user_id, $options, $recipients );

            return array(
                'counts'     => $counts,
                'recipients' => array_map( function ( $id ) {
                    $item = Better_Messages()->functions->rest_user_item( $id );

                    return array( 'id' => $id, 'name' => is_array( $item ) && isset( $item['name'] ) ? (string) $item['name'] : '' );
                }, array_slice( $recipients, 0, 50 ) ),
                'done'       => ! isset( $this->queue()[ $user_id ] ),
            );
        }

        public function run_cleanups( $budget = 30 )
        {
            global $wpdb;

            $queue = $this->queue();

            if ( empty( $queue ) ) {
                return;
            }

            $deadline = microtime( true ) + max( 1, (int) $budget );

            foreach ( $queue as $user_id => $job ) {
                $user_id = (int) $user_id;

                while ( microtime( true ) < $deadline ) {
                    if ( ! empty( $job['conversations'] ) ) {
                        $threads = $this->started_without_replies( $user_id, 20 );

                        if ( ! empty( $threads ) ) {
                            foreach ( $threads as $thread_id ) {
                                Better_Messages()->functions->erase_thread( $thread_id );
                            }
                            continue;
                        }

                        $job['conversations'] = false;
                    }

                    if ( ! empty( $job['messages'] ) ) {
                        $rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT `id`, `thread_id` FROM " . bm_get_table( 'messages' ) . " WHERE `sender_id` = %d ORDER BY `id` ASC LIMIT 100", $user_id ) );

                        if ( ! empty( $rows ) ) {
                            foreach ( $rows as $row ) {
                                Better_Messages()->functions->delete_message( (int) $row->id, (int) $row->thread_id, true, 'delete' );
                            }
                            continue;
                        }

                        $job['messages'] = false;
                    }

                    if ( ! empty( $job['account'] ) ) {
                        if ( $user_id > 0 ) {
                            require_once ABSPATH . 'wp-admin/includes/user.php';
                            wp_delete_user( $user_id );
                        } else {
                            Better_Messages()->guests->delete_guest_user( $user_id, true );
                        }

                        $engine = $this->engine();

                        if ( $engine ) {
                            $engine->forget( Better_Messages_Anti_Spam::PLUGIN, $user_id );
                        }

                        $job['account'] = false;
                    }

                    unset( $queue[ $user_id ] );
                    continue 2;
                }

                $queue[ $user_id ] = $job;
                break;
            }

            $this->save_queue( $queue );
        }

        public function action_api( WP_REST_Request $request )
        {
            $user_id = (int) $request->get_param( 'userId' );
            $action  = (string) $request->get_param( 'action' );
            $admin   = (int) Better_Messages()->functions->get_current_user_id();
            $spam    = Better_Messages()->anti_spam;

            if ( $user_id === 0 ) {
                return new WP_Error( 'no_user', 'No user', array( 'status' => 400 ) );
            }

            switch ( $action ) {
                case 'approve':
                    $count = Better_Messages()->moderation->approve_all_pending_messages_from_user( $user_id, $admin );
                    break;
                case 'reject':
                    $count = Better_Messages()->moderation->delete_all_pending_messages_from_user( $user_id );
                    break;
                case 'trust':
                    Better_Messages()->moderation->whitelist_user( $user_id );
                    Better_Messages()->functions->delete_user_meta( $user_id, Better_Messages_Anti_Spam::REVIEW_META );
                    Better_Messages()->functions->delete_user_meta( $user_id, 'bm_spam_trust' );
                    do_action( 'better_messages_account_state_changed', $user_id, 'trusted', array() );
                    break;
                case 'release':
                    Better_Messages()->moderation->unblacklist_user( $user_id );
                    Better_Messages()->functions->delete_user_meta( $user_id, Better_Messages_Anti_Spam::REVIEW_META );

                    if ( $this->engine() ) {
                        $this->engine()->forget( Better_Messages_Anti_Spam::PLUGIN, $user_id );
                    }

                    do_action( 'better_messages_account_state_changed', $user_id, 'normal', array() );
                    break;
                case 'suspend':
                    if ( ! $spam->suspend( $user_id, array( 'moderator' ) ) ) {
                        return new WP_Error( 'not_suspended', _x( 'This account cannot be suspended.', 'Spam Protection', 'bp-better-messages' ), array( 'status' => 409 ) );
                    }
                    break;
                case 'unsuspend':
                    $spam->unsuspend( $user_id );
                    break;
                case 'spammer':
                    $result = $this->mark_spammer( $user_id, array(
                        'messages'      => $request->has_param( 'messages' ) ? (bool) $request->get_param( 'messages' ) : true,
                        'conversations' => $request->has_param( 'conversations' ) ? (bool) $request->get_param( 'conversations' ) : true,
                        'account'       => (bool) $request->get_param( 'account' ),
                    ) );

                    if ( is_wp_error( $result ) ) {
                        return $result;
                    }

                    return array_merge( array( 'result' => true ), $result, array( 'review' => $this->review() ) );
                default:
                    return new WP_Error( 'unknown_action', 'Unknown action', array( 'status' => 400 ) );
            }

            return array(
                'result' => true,
                'count'  => isset( $count ) ? (int) $count : 0,
                'review' => $this->review(),
            );
        }
    }

endif;

function Better_Messages_Anti_Spam_Admin(){
    return Better_Messages_Anti_Spam_Admin::instance();
}
