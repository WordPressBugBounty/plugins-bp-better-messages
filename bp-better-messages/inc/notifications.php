<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Notifications' ) ):

    class Better_Messages_Notifications
    {
        private $is_sending_notifications = false;

        private $sending_thread_id = false;

        public static function instance()
        {

            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Notifications();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'init', array( $this, 'register_event' ) );

            $notifications_interval = (int) Better_Messages()->settings['notificationsInterval'];

            if( $notifications_interval > 0 ) {
                add_action( 'bp_send_email', array( $this, 'bp_on_send_email' ), 10, 4 );
                add_action( 'better_messages_send_notifications', array($this, 'notifications_sender'));
                add_filter( 'bp_get_email_args', array( $this, 'suppress_post_type_filters' ), 10, 2 );
            }

            add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

            add_action( 'wp_footer', array( $this, 'display_unsubscribe_message' ) );
        }

        public function suppress_post_type_filters($args, $email_type){
            if( $email_type === 'messages-unread-group' ){
                $args['suppress_filters'] = true;
            }

            return $args;
        }
        public function is_user_emails_enabled( $user_id ){
            $enabled = Better_Messages()->functions->get_user_meta( $user_id, 'notification_messages_new_message', true ) != 'no';
            return apply_filters( 'better_messages_is_user_emails_enabled', $enabled, $user_id );
        }

        public function user_emails_enabled_update( $user_id, $enabled ){
            Better_Messages()->functions->update_user_meta( $user_id, 'notification_messages_new_message', $enabled );
            do_action('better_messages_user_emails_enabled_update', $user_id, $enabled);
        }

        public function user_web_push_enabled( $user_id ){
            return apply_filters( 'better_messages_is_user_web_push_enabled', true, $user_id );
        }

        public function mark_notification_as_read( $target_thread_id, $user_id ){
            if( ! function_exists( 'bp_notifications_delete_notification' ) ) return false;

            global $wpdb;

            $notifications = $wpdb->get_results($wpdb->prepare("
            SELECT * FROM `" . bm_get_table('notifications') . "` 
            WHERE `user_id` = %d
            AND `component_name` = 'messages' 
            AND `component_action` = 'new_message' 
            AND `is_new` = 1 
            ORDER BY `id` DESC", $user_id ));


            $notifications_ids = array();
            foreach($notifications as $notification){
                $thread_id = $wpdb->get_var($wpdb->prepare("SELECT thread_id FROM `" . bm_get_table('messages') . "` WHERE `id` = %d", $notification->item_id));
                if($thread_id === NULL)
                {
                    bp_notifications_delete_notification($notification->id);
                    continue;
                } else {
                    if($thread_id == $target_thread_id) $notifications_ids[] = $notification->id;
                }
            }

            if( count($notifications_ids) > 0){
                $notifications_ids = array_unique($notifications_ids);
                foreach($notifications_ids as $notification_id){
                    BP_Notifications_Notification::update(
                        array( 'is_new' => false ),
                        array( 'id'     => $notification_id )
                    );
                }
            }
        }

        public function register_event()
        {
            $notifications_interval = (int) Better_Messages()->settings['notificationsInterval'];
            if( $notifications_interval > 0 ) {
                Better_Messages_Worker()->add_task( 'better_messages_send_notifications', $notifications_interval * MINUTE_IN_SECONDS );
            }
        }

        public function install_template_if_missing(){
            if( ! function_exists('bp_get_email_post_type') ) return false;
            if( ! apply_filters('bp_better_message_fix_missing_email_template', true ) ) return false;
            if( Better_Messages()->settings['createEmailTemplate'] !== '1' ) return false;

            $defaults = array(
                'post_status' => 'publish',
                'post_type'   => bp_get_email_post_type(),
            );

            $emails = array(
                'messages-unread-group' => array(
                    /* translators: do not remove {} brackets or translate its contents. */
                    'post_title'   => __( '[{{{site.name}}}] You have unread messages: {{subject}}', 'bp-better-messages' ),
                    /* translators: do not remove {} brackets or translate its contents. */
                    'post_content' => __( "You have unread messages: &quot;{{subject}}&quot;\n\n{{{messages.html}}}\n\n<a href=\"{{{thread.url}}}\">Go to the discussion</a> to reply or catch up on the conversation.", 'bp-better-messages' ),
                    /* translators: do not remove {} brackets or translate its contents. */
                    'post_excerpt' => __( "You have unread messages: \"{{subject}}\"\n\n{{messages.raw}}\n\nGo to the discussion to reply or catch up on the conversation: {{{thread.url}}}", 'bp-better-messages' ),
                )
            );

            $descriptions[ 'messages-unread-group' ] = __( 'A member has unread private messages.', 'bp-better-messages' );

            foreach ( $emails as $id => $email ) {
                $post_args = bp_parse_args( $email, $defaults, 'install_email_' . $id );

                $template = $this->get_page_by_title( $post_args[ 'post_title' ], OBJECT, bp_get_email_post_type() );

                if ( $template ){

                    if( $template->post_status === 'publish' ){
                        continue;
                    }
                }

                $post_id = wp_insert_post( $post_args );

                if ( !$post_id ) {
                    continue;
                }

                $tt_ids = wp_set_object_terms( $post_id, $id, bp_get_email_tax_type() );
                foreach ( $tt_ids as $tt_id ) {
                    $term = get_term_by( 'term_taxonomy_id', (int)$tt_id, bp_get_email_tax_type() );
                    wp_update_term( (int)$term->term_id, bp_get_email_tax_type(), array(
                        'description' => $descriptions[ $id ],
                    ) );
                }
            }
        }

        public function  get_page_by_title( $page_title, $output = OBJECT, $post_type = 'page' ) {
            global $wpdb;

            if ( is_array( $post_type ) ) {
                $post_type           = esc_sql( $post_type );
                $post_type_in_string = "'" . implode( "','", $post_type ) . "'";
                $sql                 = $wpdb->prepare(
                    "SELECT ID
                    FROM $wpdb->posts
                    WHERE post_title = %s
                    AND post_type IN ($post_type_in_string)",
                    $page_title
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT ID
                        FROM $wpdb->posts
                        WHERE post_title = %s
                        AND post_type = %s",
                    $page_title,
                    $post_type
                );
            }

            $page = $wpdb->get_var( $sql );

            if ( $page ) {
                return get_post( $page, $output );
            }

            return null;
        }

        public function bp_on_send_email(&$email, $email_type, $to, $args){
            if( $email_type !== 'messages-unread-group' ) {
                return false;
            }

            $tokens = $email->get_tokens();

            if( isset( $tokens['subject'] ) ){
                $subject = $tokens['subject'];

                if( $subject === '' ){
                    $email->set_subject( trim( $this->without_subject_placeholder( $email->get_subject() ) ) );
                    $email->set_content_plaintext( trim( $this->without_subject_placeholder( $email->get_content_plaintext() ) ) );
                    $email->set_content_html( trim( $this->without_subject_placeholder( $email->get_content_html() ) ) );
                }
            }
        }

        private function without_subject_placeholder( $text )
        {
            $text  = (string) $text;
            $space = '[ \t\x{00A0}]*';
            $token = '\{\{\{?subject\}\}\}?';
            $quote = '(?:&(?:quot|ldquo|rdquo|bdquo|laquo|raquo|#0*34|#0*171|#0*187|#822[0-2]|#x0*22);|["\x{201C}\x{201D}\x{201E}\x{00AB}\x{00BB}\x{300C}\x{300D}\x{300E}\x{300F}\x{FF02}])';

            $result = preg_replace( '/' . $space . '[:\x{FF1A}]?' . $space . '(?:(?<!=)' . $quote . $space . $token . $space . $quote . '|' . $token . ')/u', '', $text );

            return $result === null ? $text : $result;
        }

        public function email_candidate_where(){
            $mentions    = bm_get_table( 'mentions' );
            $threadsmeta = bm_get_table( 'threadsmeta' );
            $types       = "'" . implode( "','", Better_Messages_Mentions::NOTIFY_TYPES ) . "'";

            $where = "(
                `recipients`.`is_muted` = 3
                OR ( `recipients`.`is_muted` IN (0, 2) AND `recipients`.`thread_id` NOT IN ( SELECT `bm_thread_id` FROM `{$threadsmeta}` WHERE `meta_key` = 'email_disabled' AND `meta_value` = '1' ) )
                OR EXISTS ( SELECT 1 FROM `{$mentions}` `m` WHERE `m`.`user_id` = `recipients`.`user_id` AND `m`.`thread_id` = `recipients`.`thread_id` AND `m`.`type` IN ({$types}) )
            )";

            return apply_filters( 'better_messages_email_candidate_where', $where );
        }

        public function email_mode( $thread_id, $user_id ){
            $levels = Better_Messages()->notification_levels;

            $scope = $levels->get_user_email_scope( $user_id );

            if ( $scope === 'off' || $levels->is_thread_locked( $thread_id ) ) {
                $mode = false;
            } else {
                $level = $levels->get_effective_level( $thread_id, $user_id );

                if ( $level === 'none' || ! $levels->email_channel_open( $thread_id ) ) {
                    $mode = $levels->force_enabled() ? 'personal' : false;
                } else if ( $level === 'mentions' || ( $scope === 'direct' && ! $levels->is_one_to_one( $thread_id ) ) ) {
                    $mode = 'mentions';
                } else {
                    $mode = 'all';
                }
            }

            return apply_filters( 'better_messages_email_mode', $mode, $thread_id, $user_id, $scope );
        }

        public function email_mode_filter( $mode, $thread_id, $user_id ){
            global $wpdb;

            if ( $mode === 'all' ) {
                return '';
            }

            $types = $mode === 'personal' ? Better_Messages_Mentions::PERSONAL_TYPES : Better_Messages_Mentions::NOTIFY_TYPES;

            $rows = $wpdb->prepare(
                "`messages`.`id` IN (SELECT `message_id` FROM `" . bm_get_table( 'mentions' ) . "` WHERE `thread_id` = %d AND `user_id` = %d AND `type` IN ('" . implode( "','", $types ) . "'))",
                $thread_id, $user_id
            );

            return "AND {$rows}";
        }

        public function update_last_email( $user_id, $thread_id, $time ){
            global $wpdb;

            if( ! $time ) $time = '1970-01-01 00:00:00';

            $sql = $wpdb->prepare("
            UPDATE `" . bm_get_table('recipients') . "`
            SET last_email = %s
            WHERE thread_id = %d AND user_id = %d", $time, $thread_id, $user_id );

            $wpdb->query( $sql );
        }

        public function notifications_sender()
        {
            try{
                $this->is_sending_notifications = true;

                global $wpdb;

                set_time_limit(0);

                $this->install_template_if_missing();
                $this->migrate_from_user_meta();

                $minutes = Better_Messages()->settings['notificationsOfflineDelay'];

                if( $minutes > 0 ) {
                    $time = gmdate('Y-m-d H:i:s', (strtotime(bp_core_current_time()) - (60 * $minutes)));
                } else {
                    $time = gmdate('Y-m-d H:i:s', strtotime(bp_core_current_time()) + 2629800 );
                }

                $select = [];
                $from = [];
                $where = [];
                $group_by = [];
                $having = [];

                $select[] = "`user_index`.`ID` as `user_id`";
                $select[] = "`recipients`.`thread_id`";
                $select[] = "`recipients`.`unread_count`";
                $select[] = "`recipients`.`last_email`";
                $select[] = "(SELECT IFNULL(MAX(m2.date_sent), '1970-01-01') FROM `" . bm_get_table('messages') . "` `m2` WHERE `m2`.thread_id = `recipients`.thread_id) `last_date`";

                $from[] = "`" . bm_get_table('recipients') . "` `recipients` INNER JOIN `" . bm_get_table('users') . "` as `user_index` ON `recipients`.`user_id` = `user_index`.`ID`";

                $where[] = "`user_index`.`last_activity` < " . $wpdb->prepare('%s', $time);
                $where[] = "AND `recipients`.`unread_count` > 0";
                $where[] = "AND `recipients`.`is_deleted` = 0";
                $where[] = "AND " . apply_filters( 'better_messages_email_recipients_where', "`recipients`.`user_id` > 0" );
                $where[] = "AND " . $this->email_candidate_where();

                $group_by[] = "`user_index`.`ID`";
                $group_by[] = "`recipients`.`thread_id`";

                $having[] = "( `recipients`.`last_email` IS NULL OR `recipients`.`last_email` < `last_date` )";
                $order_by = "`recipients`.`last_email` ASC";
                $limit = "0, 100";


                $select = apply_filters( 'better_messages_notifications_threads_select_sql', $select );
                $from = apply_filters( 'better_messages_notifications_threads_from_sql', $from );
                $where = apply_filters( 'better_messages_notifications_threads_where_sql', $where );
                $group_by = apply_filters( 'better_messages_notifications_threads_group_by_sql', $group_by );
                $having = apply_filters( 'better_messages_notifications_threads_having_sql', $having );
                $order_by = apply_filters( 'better_messages_notifications_threads_order_by_sql', $order_by );
                $limit = apply_filters( 'better_messages_notifications_threads_limit_sql', $limit );

                $sql = apply_filters( 'better_messages_notifications_threads_full_sql', 'SELECT ' . join( ', ', $select ) . ' FROM ' . join( ', ', $from ) . ' WHERE ' . join( ' ', $where ) . ' GROUP BY ' . join( ', ', $group_by ) . ' HAVING ' . join( ' ', $having ) . ' ORDER BY ' .$order_by . ' LIMIT ' . $limit );

                $unread_threads = $wpdb->get_results( $sql );

                $floor = get_option( 'better_messages_notify_defaults_changed_at', false );

                if ( $floor === false ) {
                    $floor = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
                    add_option( 'better_messages_notify_defaults_changed_at', $floor, '', false );
                }

                $floor = (string) $floor;
                $cap   = max( 1, (int) apply_filters( 'better_messages_email_digest_max_messages', 50 ) );
                $seen  = array();

                while ( is_array( $unread_threads ) && count( $unread_threads ) > 0 ){
                    $gmt_offset = get_option('gmt_offset') * 3600;
                    $repeated   = false;

                    foreach ( $unread_threads as $thread ) {
                        $user_id       = (int) $thread->user_id;
                        $thread_id     = (int) $thread->thread_id;
                        $last_notified = $thread->last_email;
                        $last_date     = $thread->last_date;

                        $seen_key = $user_id . '|' . $thread_id . '|' . $last_date;

                        if ( isset( $seen[ $seen_key ] ) ) {
                            $repeated = true;
                            continue;
                        }

                        $seen[ $seen_key ] = true;

                        $this->sending_thread_id = $thread_id;

                        $type = Better_Messages()->functions->get_thread_type( $thread_id );

                        try {
                            $mode = $this->email_mode( $thread_id, $user_id );
                        } catch ( Throwable $e ) {
                            $mode = false;
                        }

                        if ( $mode === false ) {
                            $this->update_last_email( $user_id, $thread_id, $last_date );
                            continue;
                        }

                        if ( $floor !== '' && ( ! $last_notified || $last_notified < $floor ) ) {
                            $last_notified = $floor;
                        }

                        if ( $last_notified && $last_date <= $last_notified ) {
                            $this->update_last_email( $user_id, $thread_id, $last_date );
                            continue;
                        }

                        if ( ! $last_notified || ( $last_date > $last_notified ) ) {

                            if( ! $last_notified ) $last_notified = gmdate('Y-m-d H:i:s', 0 );

                            $ud = get_userdata( $user_id );

                            $mention_filter = $this->email_mode_filter( $mode, $thread_id, $user_id );

                            $query = $wpdb->prepare( "
                                SELECT
                                  `messages`.id,
                                  `messages`.message,
                                  `messages`.sender_id,
                                  `threads`.subject,
                                  `messages`.date_sent
                                FROM " . bm_get_table('messages') . " as messages
                                LEFT JOIN " . bm_get_table('threads') . " as threads ON
                                    threads.id = messages.thread_id
                                LEFT JOIN " . bm_get_table('meta') . " messagesmeta ON
                                ( messagesmeta.`bm_message_id` = `messages`.`id` AND messagesmeta.meta_key = 'bpbm_call_accepted' )
                                WHERE `messages`.`thread_id` = %d
                                AND `messages`.`date_sent` > %s
                                AND `messages`.message != '<!-- BM-DELETED-MESSAGE -->'
                                AND `messages`.sender_id != %d
                                AND `messages`.is_pending = 0
                                AND ( messagesmeta.meta_id IS NULL )
                                {$mention_filter}
                                ORDER BY id DESC
                                LIMIT 0, %d
                            ", $thread->thread_id, $last_notified, $user_id, min( max( 1, (int) $thread->unread_count ), $cap ) );

                            $messages = array_reverse( $wpdb->get_results( $query ) );

                            foreach ( $messages as $stored ) {
                                $stored->message = Better_Messages()->encryption->open_message( $stored->message, $thread->thread_id );
                                $stored->subject = Better_Messages()->encryption->open_subject( $stored->subject, $thread->thread_id );
                            }

                            if ( empty( $messages ) ) {
                                $this->update_last_email( $user_id, $thread_id, $last_date );
                                continue;
                            }

                            foreach($messages as $index => $message){
                                if( $message->message ){
                                    if( class_exists( 'Better_Messages_E2E_Encryption' ) && strpos( $message->message, Better_Messages_E2E_Encryption::E2E_PREFIX ) === 0 ){
                                        $message->message = _x('Encrypted message', 'Email notification', 'bp-better-messages');
                                    }

                                    $is_sticker = ( strpos( $message->message, '<span class="bm-sticker">' ) !== false || strpos( $message->message, '<span class="bpbm-sticker">' ) !== false );
                                    if( $is_sticker ){
                                        $message->message = __('Sticker', 'bp-better-messages');
                                    }

                                    $is_gif = ( strpos( $message->message, '<span class="bm-gif">' ) !== false || strpos( $message->message, '<span class="bpbm-gif">' ) !== false );
                                    if( $is_gif ){
                                        $message->message = __('GIF', 'bp-better-messages');
                                    }
                                }
                            }

                            $email_overwritten = apply_filters( 'bp_better_messages_overwrite_email', false, $user_id, $thread_id, $messages );

                            if( $email_overwritten === false ) {
                                $messageRaw = '';
                                $messageHtml = '<table style="margin:1rem 0!important;width:100%;table-layout: auto !important;"><tbody>';
                                $email_blocks = array();
                                $last_sender_id = 0;
                                $last_message_id = 0;

                                foreach ($messages as $message) {
                                    if ( (int) $message->sender_id === 0 ) {
                                        $_message = Better_Messages()->functions->format_message( $message->message, (int) $message->id, 'email', $user_id );
                                        $messageHtml .= '<tr><td colspan="2" style="text-align:center;color:#888;font-style:italic;padding:4px 0;">' . esc_html($_message) . '</td></tr>';
                                        $email_blocks[] = array( 'type' => 'system', 'text' => $this->email_plain_text( $_message ) );
                                        $messageRaw .= $_message . "\n\n";
                                        $last_sender_id = 0;
                                        $last_message_id = (int) $message->id;
                                        continue;
                                    }

                                    $bm_user = Better_Messages()->functions->rest_user_item( $message->sender_id, false );

                                    $timestamp = strtotime($message->date_sent) + $gmt_offset;
                                    $time_format = get_option('time_format');

                                    if (gmdate('Ymd') != gmdate('Ymd', $timestamp)) {
                                        $time_format .= ' ' . get_option('date_format');
                                    }

                                    $time    = apply_filters( 'better_messages_email_notification_time', wp_strip_all_tags( stripslashes(date_i18n($time_format, $timestamp)) ), $message, $user_id );

                                    $author  = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags(stripslashes(sprintf( __('%s wrote:', 'bp-better-messages'), $bm_user['name'] ))) ) );

                                    $_message = nl2br( Better_Messages()->functions->markdown_to_plain_text( stripslashes( $message->message ) ) );
                                    $_message = str_replace(['<p>', '</p>'], ['<br>', ''], $_message );
                                    $_message = Better_Messages()->functions->format_message( $_message, $message->id, 'email', $user_id );
                                    $_message = htmlspecialchars_decode(Better_Messages()->functions->strip_all_tags(Better_Messages_Mentions::mentions_as_names($_message), '<br>'));

                                    if ($last_sender_id == 0 || $last_sender_id != $message->sender_id) {
                                        $messageHtml .= '<tr><td colspan="2"><b>' . $author . '</b></td></tr>';
                                        $messageRaw .= "$author\n";
                                    }

                                    $_message_raw = preg_replace( '#<br\s*/?>\r?\n?#i', "\n", $_message );
                                    $messageRaw .= "$time\n$_message_raw\n\n";

                                    $email_blocks = $this->email_message_block( $email_blocks, $bm_user, (int) $message->sender_id, (int) $last_sender_id, $time, $_message_raw );

                                    $messageHtml .= '<tr>';
                                    $messageHtml .= '<td style="padding-right: 10px;">' . $_message . '</td>';
                                    $messageHtml .= '<td style="width:1px;white-space:nowrap;vertical-align:top;text-align:right;text-overflow:ellipsis;overflow:hidden;"><i>' . $time . '</i></td>';
                                    $messageHtml .= '</tr>';

                                    $last_sender_id = $message->sender_id;
                                    $last_message_id = $message->id;
                                }

                                $messageHtml .= '</tbody></table>';

                                if( Better_Messages()->settings['disableSubject'] === '1' && $type === 'thread' ) {
                                    $subject = '';
                                } else {
                                    $subject = Better_Messages()->functions->remove_re(sanitize_text_field(html_entity_decode(stripslashes($messages[0]->subject), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
                                    $subject = Better_Messages()->functions->clean_no_subject($subject);

                                    if( class_exists( 'Better_Messages_E2E_Encryption' ) && strpos( $subject, Better_Messages_E2E_Encryption::E2E_PREFIX ) === 0 ){
                                        $subject = '';
                                    }
                                }

                                $withheld = $this->email_with_counts( $this->email_withhold( array(
                                    'subject'       => $subject,
                                    'blocks'        => $email_blocks,
                                    'messages_html' => $messageHtml,
                                    'messages_raw'  => $messageRaw,
                                ), $this->email_content_level() ), $this->email_counts( $user_id ) );

                                $subject      = $withheld['subject'];
                                $email_blocks = $withheld['blocks'];
                                $messageHtml  = $withheld['messages_html'];
                                $messageRaw   = $withheld['messages_raw'];

                                $use_buddypress_email = $user_id > 0 && function_exists( 'bp_send_email' ) && Better_Messages()->settings['emailTemplateSource'] === 'buddypress';

                                $sender = false;

                                if ( $use_buddypress_email ) {
                                    $email_sender_id = 0;
                                    foreach ( array_reverse($messages) as $_msg ) {
                                        if ( (int) $_msg->sender_id > 0 ) {
                                            $email_sender_id = (int) $_msg->sender_id;
                                            break;
                                        }
                                    }

                                    if ( $email_sender_id === 0 ) {
                                        foreach ( array_reverse($messages) as $_msg ) {
                                            $system_data = Better_Messages()->functions->get_message_meta( (int) $_msg->id, 'system_data', true );

                                            if ( is_array( $system_data ) && isset( $system_data['user_id'] ) && (int) $system_data['user_id'] > 0 ) {
                                                $email_sender_id = (int) $system_data['user_id'];
                                                break;
                                            }
                                        }
                                    }

                                    $sender = $email_sender_id > 0 ? get_userdata( $email_sender_id ) : false;

                                    if ( ! is_object( $sender ) ){
                                        $use_buddypress_email = false;
                                    }
                                }

                                if ( $use_buddypress_email ) {
                                    $args = array(
                                        'tokens' =>
                                            apply_filters('bp_better_messages_notification_tokens', array(
                                                'messages.html' => $messageHtml,
                                                'messages.raw' => $messageRaw,
                                                'sender.name' => isset( $withheld['content_level'] ) && $withheld['content_level'] === 'notice' ? '' : trim( wp_strip_all_tags( Better_Messages()->functions->rest_user_item( $email_sender_id, false )['name'] ) ),
                                                'thread.id' => $thread_id,
                                                'unread.count' => $withheld['counts']['unread'],
                                                'missed_calls.count' => $withheld['counts']['missed_calls'],
                                                'thread.url' => sanitize_url( Better_Messages()->functions->add_hash_arg( 'conversation/' . $thread_id, [], Better_Messages()->functions->get_link($user_id) ) ),
                                                'subject' => $subject,
                                                'unsubscribe' => sanitize_url(bp_email_get_unsubscribe_link(array(
                                                    'user_id' => $user_id,
                                                    'notification_type' => 'messages-unread',
                                                )))
                                            ),
                                                $ud,
                                                $sender,
                                                $thread_id
                                            ),
                                    );

                                    bp_send_email('messages-unread-group', $ud, $args);
                                } else {
                                    $user = $this->email_recipient( $user_id );

                                    if ( ! $user ) {
                                        $this->update_last_email( $user_id, $thread_id, $last_date );
                                        continue;
                                    }

                                    $thread_url    = sanitize_url( apply_filters( 'better_messages_email_thread_url', Better_Messages()->functions->add_hash_arg('conversation/' . $thread_id, [], Better_Messages()->functions->get_link($user_id) ), $user_id, $thread_id ) );

                                    if( $subject !== '' ) {
                                        $email_subject = sprintf(_x('You have unread messages: "%s"', 'Email notification header for non BuddyPress websites', 'bp-better-messages'), $subject);
                                    } else {
                                        $email_subject = _x('You have unread messages', 'Email notification header for non BuddyPress websites', 'bp-better-messages');
                                    }

                                    $template_data = array_merge( $withheld, array(
                                        'user_name'       => $user->display_name,
                                        'email_subject'   => $email_subject,
                                        'unread_footer'   => true,
                                        'thread_url'      => $thread_url,
                                        'unsubscribe_url' => Better_Messages()->settings['emailUnsubscribeLink'] === '1' ? $this->get_unsubscribe_url( $user_id ) : '',
                                    ) );

                                    $this->send_email( $user->user_email, $email_subject, $this->email_content( $template_data ) );
                                }
                            } else {
                                $last_sender_id = 0;
                                $last_message_id = 0;
                                foreach ($messages as $message) {
                                    $last_sender_id = $message->sender_id;
                                    $last_message_id = $message->id;
                                }
                            }

                            $this->update_last_email( $user_id, $thread_id, $last_date );

                            do_action('better_messages_send_unread_notification', $user_id, $thread_id );

                            if ( $user_id > 0 && function_exists('bp_notifications_add_notification') ) {
                                if( Better_Messages()->settings['stopBPNotifications'] === '0' ) {
                                    if( $type === 'thread' ) {
                                        $notification_id = bp_notifications_add_notification(array(
                                            'user_id'           => $user_id,
                                            'item_id'           => $last_message_id,
                                            'secondary_item_id' => $last_sender_id,
                                            'component_name'    => buddypress()->messages->id,
                                            'component_action'  => 'new_message',
                                            'date_notified'     => bp_core_current_time(),
                                            'is_new'            => 1
                                        ));

                                        bp_notifications_add_meta($notification_id, 'thread_id', $thread_id);
                                    }
                                }
                            }

                        }
                    }

                    if ( $repeated ) {
                        break;
                    }

                    $unread_threads = $wpdb->get_results( $sql );
                }
            } finally {
                $this->is_sending_notifications = false;
                $this->sending_thread_id = false;
            }
        }

        private function email_recipient( $user_id ) {
            if ( (int) $user_id > 0 ) {
                $user = get_userdata( (int) $user_id );

                return $user ? $user : null;
            }

            $guest = Better_Messages()->guests->get_guest_user( (int) $user_id );

            if ( ! $guest || empty( $guest->email ) || ! Better_Messages()->guests->email_mailable( $guest ) ) {
                return null;
            }

            if ( Better_Messages()->functions->get_user_meta( (int) $user_id, 'bm_email_unread', true ) === 'no' ) {
                return null;
            }

            return (object) array(
                'display_name' => (string) $guest->name,
                'user_email'   => (string) $guest->email,
            );
        }

        public function is_sending_notifications(): bool
        {
            return $this->is_sending_notifications;
        }

        public function get_sending_thread_id()
        {
            return $this->sending_thread_id;
        }

        public function migrate_from_user_meta(){
            set_time_limit(0);
            ignore_user_abort(true);
            global $wpdb;

            $number_of_records = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$wpdb->usermeta}` WHERE `meta_key` = 'bp-better-messages-last-notified'");

            if( $number_of_records === 0 ) return;

            $per_page = 200;

            $pages = ceil($number_of_records / $per_page);

            for ($page = 1; $page <= $pages; $page++){
                $offset = ($page - 1) * $per_page;

                $rows = $wpdb->get_results("SELECT user_id, meta_value FROM `{$wpdb->usermeta}` WHERE `meta_key` = 'bp-better-messages-last-notified' ORDER BY user_id LIMIT $offset, $per_page");

                if( count( $rows ) > 0 ){
                    foreach ( $rows as $row ){
                        $user_id = $row->user_id;
                        $threads = maybe_unserialize($row->meta_value);

                        if( is_array( $threads ) && count( $threads ) > 0 ){
                            foreach( $threads as $thread_id => $last_id ){
                                $last_time = $wpdb->get_var( $wpdb->prepare(  "SELECT created_at FROM `" . bm_get_table('messages') . "` WHERE `thread_id` = %d AND `id` = %d", $thread_id, $last_id ) );
                                if( $last_time ) {
                                    $last_time = gmdate( 'Y-m-d H:i:s', substr( $last_time, 0, 10 ) );
                                }

                                if( ! $last_time ) {
                                    $last_time = gmdate('Y-m-d H:i:s');
                                }

                                $sql = $wpdb->prepare("
                                UPDATE `" . bm_get_table('recipients') . "`
                                SET last_email = %s
                                WHERE thread_id = %d AND user_id = %d
                                ", $last_time, $thread_id, $user_id );

                                $wpdb->query( $sql );
                            }
                        }

                        $sql = $wpdb->prepare("DELETE FROM `{$wpdb->usermeta}` WHERE `meta_key` = 'bp-better-messages-last-notified' AND `user_id` = %d", $user_id);

                        $wpdb->query( $sql );
                    }
                }
            }
        }

        public function email_content_type() {
            return 'text/html';
        }

        public function register_rest_routes() {
            register_rest_route( 'better-messages/v1', '/sendTestEmail', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_send_test_email' ),
                'permission_callback' => array( $this, 'can_manage_settings' ),
                'args'                => array(
                    'email' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_email',
                        'validate_callback' => function( $param ) {
                            return is_email( $param );
                        },
                    ),
                ),
            ) );

            register_rest_route( 'better-messages/v1', '/previewEmail', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_preview_email' ),
                'permission_callback' => array( $this, 'can_manage_settings' ),
            ) );

            register_rest_route( 'better-messages/v1', '/emailDefaultTemplate', array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'rest_email_default_template' ),
                'permission_callback' => array( $this, 'can_manage_settings' ),
            ) );

            register_rest_route( 'better-messages/v1', '/unsubscribe', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_unsubscribe' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'user_id' => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'intval',
                    ),
                    'token' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            ) );
        }

        public function can_manage_settings() {
            return current_user_can( 'manage_options' );
        }

        public function rest_send_test_email( WP_REST_Request $request ) {
            $email = $request->get_param( 'email' );

            if ( ! is_email( $email ) ) {
                return new WP_Error(
                    'invalid_email',
                    __( 'Please enter a valid email address.', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            $current_user  = wp_get_current_user();
            $template_data = $this->test_email_data( $current_user, Better_Messages()->settings['emailUnsubscribeLink'] === '1' ? $this->get_unsubscribe_url( $current_user->ID ) : '' );
            $withheld      = isset( $template_data['content_level'] );

            $use_buddypress = function_exists( 'bp_send_email' ) && Better_Messages()->settings['emailTemplateSource'] === 'buddypress';

            if ( $use_buddypress ) {
                $args = array(
                    'tokens' => array(
                        'messages.html'      => $template_data['messages_html'],
                        'messages.raw'       => $template_data['messages_raw'],
                        'sender.name'        => $withheld && $template_data['content_level'] === 'notice' ? '' : $current_user->display_name,
                        'thread.id'          => 0,
                        'unread.count'       => $template_data['counts']['unread'],
                        'missed_calls.count' => $template_data['counts']['missed_calls'],
                        'thread.url'    => home_url(),
                        'subject'       => $template_data['subject'],
                        'unsubscribe'   => '#',
                    ),
                );

                try {
                    bp_send_email( 'messages-unread-group', $email, $args );
                    $sent = true;
                } catch ( Exception $e ) {
                    $sent = false;
                }
            } else {
                $sent = $this->send_email( $email, $template_data['email_subject'], $this->email_content( $template_data ) );
            }

            if ( $sent ) {
                $message = $use_buddypress
                    ? sprintf( __( 'Test email sent to %s via BuddyPress', 'bp-better-messages' ), $email )
                    : sprintf( __( 'Test email sent to %s', 'bp-better-messages' ), $email );

                return new WP_REST_Response( array(
                    'success' => true,
                    'message' => $message,
                ), 200 );
            } else {
                return new WP_Error(
                    'email_failed',
                    __( 'Failed to send test email. Please check your WordPress email configuration.', 'bp-better-messages' ),
                    array( 'status' => 500 )
                );
            }
        }

        public function rest_preview_email( WP_REST_Request $request ) {
            $settings = $this->email_draft_settings( $request->get_param( 'settings' ) );
            $data     = $this->test_email_data( wp_get_current_user(), $settings['emailUnsubscribeLink'] === '1' ? home_url( '#unsubscribe' ) : '', $settings );
            $template = $request->get_param( 'html' );

            if ( $settings['emailTemplateMode'] === 'custom' && is_string( $template ) && trim( $template ) !== '' ) {
                $html = $this->render_custom_email_template( $data, $settings, $template );
            } else {
                $html = $this->email_content( $data, array_merge( $settings, array( 'emailTemplateMode' => 'simple' ) ) )['html'];
            }

            return new WP_REST_Response( array( 'html' => $html ), 200 );
        }

        public function rest_email_default_template( WP_REST_Request $request ) {
            return new WP_REST_Response( array( 'html' => $this->default_custom_template( $this->email_draft_settings( $request->get_param( 'settings' ) ) ) ), 200 );
        }

        private function email_draft_settings( $draft ) {
            $settings = Better_Messages()->settings;

            if ( ! is_array( $draft ) ) {
                return $settings;
            }

            if ( isset( $draft['emailTemplateMode'] ) && in_array( $draft['emailTemplateMode'], array( 'simple', 'custom' ), true ) ) {
                $settings['emailTemplateMode'] = $draft['emailTemplateMode'];
            }

            foreach ( array( 'emailPrimaryColor', 'emailBackgroundColor', 'emailContentBgColor', 'emailTextColor' ) as $key ) {
                if ( isset( $draft[ $key ] ) && is_string( $draft[ $key ] ) && ( $draft[ $key ] === '' || $this->email_hex( $draft[ $key ] ) !== '' ) ) {
                    $settings[ $key ] = $draft[ $key ];
                }
            }

            foreach ( array( 'emailHeaderText', 'emailFooterText', 'emailButtonText' ) as $key ) {
                if ( isset( $draft[ $key ] ) && is_string( $draft[ $key ] ) ) {
                    $settings[ $key ] = sanitize_text_field( $draft[ $key ] );
                }
            }

            if ( isset( $draft['emailLogoUrl'] ) && is_string( $draft['emailLogoUrl'] ) ) {
                $settings['emailLogoUrl'] = esc_url_raw( $draft['emailLogoUrl'] );
                $settings['emailLogoId']  = isset( $draft['emailLogoId'] ) ? absint( $draft['emailLogoId'] ) : 0;
            }

            if ( isset( $draft['emailUnsubscribeLink'] ) ) {
                $settings['emailUnsubscribeLink'] = in_array( $draft['emailUnsubscribeLink'], array( '1', 1, true ), true ) ? '1' : '0';
            }

            if ( isset( $draft['emailContent'] ) && in_array( $draft['emailContent'], array( 'full', 'senders', 'notice' ), true ) ) {
                $settings['emailContent'] = $draft['emailContent'];
            }

            return $settings;
        }

        public function send_email( $to, $subject, array $content ) {
            $text = isset( $content['text'] ) ? (string) $content['text'] : '';

            $plain_part = function ( $phpmailer ) use ( $text ) {
                if ( $text !== '' && isset( $phpmailer->ContentType ) && $phpmailer->ContentType === 'text/html' ) {
                    $phpmailer->AltBody = $text;
                }
            };

            add_filter( 'wp_mail_content_type', array( $this, 'email_content_type' ) );
            add_action( 'phpmailer_init', $plain_part );

            $sent = wp_mail( $to, $subject, $content['html'] );

            remove_action( 'phpmailer_init', $plain_part );
            remove_filter( 'wp_mail_content_type', array( $this, 'email_content_type' ) );

            return $sent;
        }

        public function moderator_email( array $to, $subject, array $layout ) {
            $email = function_exists( 'wordplus_email' ) ? wordplus_email() : null;

            if ( ! $email || empty( $to ) ) {
                return false;
            }

            $settings = Better_Messages()->settings;
            $site     = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
            $colors   = $this->email_colors( $settings );

            $content = $email->render( array_merge( array(
                'title'  => (string) $subject,
                'brand'  => array(
                    'name'       => $site,
                    'url'        => home_url(),
                    'logo'       => ! empty( $settings['emailLogoUrl'] ) ? (string) $settings['emailLogoUrl'] : '',
                    'logo_width' => $this->email_logo_width( $settings ),
                ),
                'footer' => array(
                    array( 'text' => sprintf( _x( 'You are receiving this email because your address is in the Notification Emails of Better Messages on %s', 'Moderation email', 'bp-better-messages' ), $site ) ),
                ),
                'colors' => $colors,
                'dark'   => $colors['dark'],
            ), $layout ) );

            $sent = true;

            foreach ( $to as $address ) {
                $sent = $this->send_email( $address, $subject, $content ) && $sent;
            }

            return $sent;
        }

        public function moderator_message_block( $message, array $extra = array() ) {
            $sender_id = (int) $message->sender_id;
            $bm_user   = Better_Messages()->functions->rest_user_item( $sender_id, false );
            $text      = html_entity_decode( (string) apply_filters( 'better_messages_moderation_message_content',
                wp_trim_words( wp_strip_all_tags( Better_Messages_Mentions::mentions_as_names( $message->message ) ), 50, '...' ),
                $message
            ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            $lines = array_values( array_filter( array_merge( array( $text ), $extra ), 'strlen' ) );

            return array(
                'type'   => 'message',
                'name'   => $this->email_plain_text( is_array( $bm_user ) && isset( $bm_user['name'] ) ? $bm_user['name'] : '' ),
                'avatar' => $this->email_avatar( is_array( $bm_user ) && isset( $bm_user['avatar'] ) ? $bm_user['avatar'] : '' ),
                'time'   => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) floor( (int) $message->created_at / 10000 ) ),
                'lines'  => $lines ? $lines : array( '…' ),
            );
        }

        public function rest_unsubscribe( WP_REST_Request $request ) {
            $user_id = $request->get_param( 'user_id' );
            $token   = $request->get_param( 'token' );

            if ( ! $this->verify_unsubscribe_token( $user_id, $token ) ) {
                wp_redirect( add_query_arg( 'bm-unsubscribe', 'invalid', home_url() ) );
                exit;
            }

            $this->user_emails_enabled_update( $user_id, 'no' );

            wp_redirect( add_query_arg( 'bm-unsubscribe', 'success', home_url() ) );
            exit;
        }

        public function generate_unsubscribe_token( $user_id ) {
            $secret = wp_salt( 'auth' );
            $data   = $user_id . '|bm_unsubscribe';

            return hash_hmac( 'sha256', $data, $secret );
        }

        public function verify_unsubscribe_token( $user_id, $token ) {
            $expected_token = $this->generate_unsubscribe_token( $user_id );

            return hash_equals( $expected_token, $token );
        }

        public function get_unsubscribe_url( $user_id ) {
            $token = $this->generate_unsubscribe_token( $user_id );

            return rest_url( 'better-messages/v1/unsubscribe' ) . '?' . http_build_query( array(
                'user_id' => $user_id,
                'token'   => $token,
            ) );
        }

        public function display_unsubscribe_message() {
            if ( ! isset( $_GET['bm-unsubscribe'] ) ) {
                return;
            }

            $status = sanitize_text_field( $_GET['bm-unsubscribe'] );

            if ( $status === 'success' ) {
                $message = __( 'You have been successfully unsubscribed from message email notifications.', 'bp-better-messages' );
                $type = 'success';
            } elseif ( $status === 'invalid' ) {
                $message = __( 'Invalid or expired unsubscribe link.', 'bp-better-messages' );
                $type = 'error';
            } else {
                return;
            }

            $bg_color = $type === 'success' ? '#d4edda' : '#f8d7da';
            $border_color = $type === 'success' ? '#c3e6cb' : '#f5c6cb';
            $text_color = $type === 'success' ? '#155724' : '#721c24';

            ?>
            <div id="bm-unsubscribe-notice" style="position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 999999; padding: 15px 30px; background: <?php echo $bg_color; ?>; border: 1px solid <?php echo $border_color; ?>; border-radius: 5px; color: <?php echo $text_color; ?>; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif; font-size: 14px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                <?php echo esc_html( $message ); ?>
                <span onclick="this.parentElement.remove();" style="margin-left: 15px; cursor: pointer; font-weight: bold;">&times;</span>
            </div>
            <script>
                setTimeout(function() {
                    var notice = document.getElementById('bm-unsubscribe-notice');
                    if (notice) notice.remove();
                }, 5000);
            </script>
            <?php
        }

        private function get_test_messages_html() {
            $messages_html = '<table style="margin:1rem 0!important;width:100%;table-layout: auto !important;"><tbody>';

            $messages_html .= '<tr><td colspan="2"><b>' . sprintf( __( '%s wrote:', 'bp-better-messages' ), 'Jane Smith' ) . '</b></td></tr>';
            $messages_html .= '<tr>';
            $messages_html .= '<td style="padding-right: 10px;">' . __( 'Hello! This is a sample message to test how your email notifications will look.', 'bp-better-messages' ) . '</td>';
            $messages_html .= '<td style="width:1px;white-space:nowrap;vertical-align:top;text-align:right;text-overflow:ellipsis;overflow:hidden;"><i>' . date_i18n( get_option( 'time_format' ), strtotime( '-5 minutes' ) ) . '</i></td>';
            $messages_html .= '</tr>';

            $messages_html .= '<tr><td colspan="2"><b>' . sprintf( __( '%s wrote:', 'bp-better-messages' ), 'John Doe' ) . '</b></td></tr>';
            $messages_html .= '<tr>';
            $messages_html .= '<td style="padding-right: 10px;">' . __( 'Thanks for the message! Here is another sample to show how multiple messages appear in the email.', 'bp-better-messages' ) . '</td>';
            $messages_html .= '<td style="width:1px;white-space:nowrap;vertical-align:top;text-align:right;text-overflow:ellipsis;overflow:hidden;"><i>' . date_i18n( get_option( 'time_format' ), strtotime( '-2 minutes' ) ) . '</i></td>';
            $messages_html .= '</tr>';

            $messages_html .= '</tbody></table>';

            return $messages_html;
        }

        private function test_email_data( $user, $unsubscribe_url, $settings = null ) {
            $subject     = __( 'Test Conversation', 'bp-better-messages' );
            $time_format = get_option( 'time_format' );

            return $this->email_with_counts( $this->email_withhold( array(
                'user_name'       => $user->display_name,
                'subject'         => $subject,
                'email_subject'   => sprintf( _x( 'You have unread messages: "%s"', 'Email notification header for non BuddyPress websites', 'bp-better-messages' ), $subject ),
                'messages_html'   => $this->get_test_messages_html(),
                'messages_raw'    => __( 'Hello! This is a test message to preview how your emails will look.', 'bp-better-messages' ),
                'blocks'          => array(
                    array(
                        'type'   => 'message',
                        'name'   => 'Jane Smith',
                        'avatar' => '',
                        'time'   => wp_date( $time_format, time() - 5 * MINUTE_IN_SECONDS ),
                        'lines'  => array( __( 'Hello! This is a sample message to test how your email notifications will look.', 'bp-better-messages' ) ),
                    ),
                    array(
                        'type'   => 'message',
                        'name'   => 'John Doe',
                        'avatar' => '',
                        'time'   => wp_date( $time_format, time() - 2 * MINUTE_IN_SECONDS ),
                        'lines'  => array( __( 'Thanks for the message! Here is another sample to show how multiple messages appear in the email.', 'bp-better-messages' ) ),
                    ),
                ),
                'unread_footer'   => true,
                'thread_url'      => home_url(),
                'unsubscribe_url' => $unsubscribe_url,
            ), $this->email_content_level( $settings ) ), array( 'unread' => 5, 'missed_calls' => empty( Better_Messages()->calls ) ? 0 : 1 ) );
        }

        public function get_email_template( $data ) {
            return $this->email_content( $data )['html'];
        }

        public function email_content( $data, $settings = null ) {
            $settings = null === $settings ? Better_Messages()->settings : $settings;
            $mode     = isset( $settings['emailTemplateMode'] ) ? $settings['emailTemplateMode'] : 'simple';

            if ( $mode === 'custom' && ! empty( Better_Messages_Options()->get_email_custom_html() ) ) {
                return array( 'html' => $this->render_custom_email_template( $data, $settings ), 'text' => '' );
            }

            $email = function_exists( 'wordplus_email' ) ? wordplus_email() : null;

            if ( ! $email ) {
                return array( 'html' => $this->render_classic_email_template( $data ), 'text' => '' );
            }

            return $email->render( $this->email_layout( $data, $settings ) );
        }

        public function render_simple_email_template( $data ) {
            $email = function_exists( 'wordplus_email' ) ? wordplus_email() : null;

            if ( ! $email ) {
                return $this->render_classic_email_template( $data );
            }

            return $email->render( $this->email_layout( $data, Better_Messages()->settings ) )['html'];
        }

        private function email_layout( $data, $settings ) {
            $site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
            $colors = $this->email_colors( $settings );
            $blocks = $this->email_blocks( $data );
            $intro  = $this->email_intro( $data, $blocks );
            $stats  = $this->email_stats_block( $data );

            if ( $stats ) {
                $blocks[] = $stats;
            }

            if ( ! empty( $data['button_text'] ) ) {
                $button_text = (string) $data['button_text'];
            } else {
                $button_text = ! empty( $settings['emailButtonText'] ) ? $settings['emailButtonText'] : __( 'View Conversation', 'bp-better-messages' );
            }

            $footer = array();

            if ( ! empty( $data['unread_footer'] ) ) {
                $footer[] = array( 'text' => sprintf( _x( 'You are receiving this email because you have unread messages on %s', 'Email footer', 'bp-better-messages' ), $site ) );
            }

            $links = array( array( 'label' => $this->email_footer_text( $settings ), 'url' => home_url() ) );

            if ( ! empty( $data['unsubscribe_url'] ) ) {
                $links[] = array( 'label' => _x( 'Unsubscribe', 'Email footer', 'bp-better-messages' ), 'url' => $data['unsubscribe_url'] );
            }

            $footer[] = array( 'links' => $links );

            if ( ! empty( $data['footer_html'] ) ) {
                $footer[] = array( 'html' => $data['footer_html'] );
            }

            return array(
                'title'      => isset( $data['email_subject'] ) ? (string) $data['email_subject'] : '',
                'preheader'  => isset( $data['preheader'] ) ? (string) $data['preheader'] : $this->email_preheader( $blocks ),
                'brand'      => array(
                    'name'       => $site,
                    'url'        => home_url(),
                    'logo'       => ! empty( $settings['emailLogoUrl'] ) ? (string) $settings['emailLogoUrl'] : '',
                    'logo_width' => $this->email_logo_width( $settings ),
                ),
                'greeting'   => $this->email_header_text( $data, $settings ),
                'heading'    => $intro[0],
                'subheading' => $intro[1],
                'blocks'     => $blocks,
                'button'     => array( 'label' => $button_text, 'url' => isset( $data['thread_url'] ) ? (string) $data['thread_url'] : '' ),
                'footer'     => $footer,
                'colors'     => $colors,
                'dark'       => $colors['dark'],
            );
        }

        private function email_logo_width( $settings ) {
            if ( empty( $settings['emailLogoUrl'] ) || empty( $settings['emailLogoId'] ) ) {
                return 0;
            }

            $image = wp_get_attachment_image_src( (int) $settings['emailLogoId'], 'full' );

            return is_array( $image ) && ! empty( $image[1] ) && $image[0] === $settings['emailLogoUrl'] ? (int) $image[1] : 0;
        }

        private function email_blocks( $data ) {
            if ( isset( $data['blocks'] ) && is_array( $data['blocks'] ) && ( ! empty( $data['blocks'] ) || isset( $data['content_level'] ) ) ) {
                return $data['blocks'];
            }

            if ( isset( $data['text'] ) && $data['text'] !== '' ) {
                return array( array( 'type' => 'text', 'text' => (string) $data['text'] ) );
            }

            if ( ! empty( $data['messages_html'] ) ) {
                return array( array( 'type' => 'html', 'html' => (string) $data['messages_html'] ) );
            }

            return array();
        }

        private function email_intro( $data, $blocks ) {
            if ( isset( $data['heading'] ) ) {
                return array( (string) $data['heading'], isset( $data['subheading'] ) ? (string) $data['subheading'] : '' );
            }

            $count = 0;
            $names = array();

            foreach ( $blocks as $block ) {
                if ( isset( $block['type'] ) && $block['type'] === 'message' ) {
                    $count += count( $block['lines'] );

                    if ( ! in_array( (string) $block['name'], $names, true ) ) {
                        $names[] = (string) $block['name'];
                    }
                }
            }

            if ( $count === 0 ) {
                return array( isset( $data['email_subject'] ) ? (string) $data['email_subject'] : '', '' );
            }

            $number  = number_format_i18n( $count );
            $subject = isset( $data['subject'] ) ? (string) $data['subject'] : '';
            /* translators: %s: the names of who wrote the messages, as "Jane Smith and Mark Lee" */
            $from    = sprintf( _x( 'From %s', 'Email notification', 'bp-better-messages' ), $this->email_names( $names ) );

            if ( $subject !== '' ) {
                /* translators: 1: the number of messages, 2: the subject of the conversation */
                return array( sprintf( _n( '%1$s new message in “%2$s”', '%1$s new messages in “%2$s”', $count, 'bp-better-messages' ), $number, $subject ), $from );
            }

            if ( count( $names ) === 1 ) {
                /* translators: 1: the number of messages, 2: the name of who wrote them */
                return array( sprintf( _n( '%1$s new message from %2$s', '%1$s new messages from %2$s', $count, 'bp-better-messages' ), $number, $names[0] ), '' );
            }

            /* translators: %s: the number of messages */
            return array( sprintf( _n( '%s new message', '%s new messages', $count, 'bp-better-messages' ), $number ), $from );
        }

        private function email_names( $names ) {
            if ( count( $names ) > 3 ) {
                $rest  = count( $names ) - 2;
                $names = array_slice( $names, 0, 2 );
                /* translators: %s: how many more people wrote */
                $names[] = sprintf( _n( '%s other', '%s others', $rest, 'bp-better-messages' ), number_format_i18n( $rest ) );
            }

            return wp_sprintf( '%l', $names );
        }

        private function email_preheader( $blocks ) {
            foreach ( $blocks as $block ) {
                $type = isset( $block['type'] ) ? $block['type'] : '';

                if ( $type === 'message' && ! empty( $block['lines'] ) ) {
                    $line = $block['name'] . ': ' . reset( $block['lines'] );
                } elseif ( $type === 'text' ) {
                    $line = (string) $block['text'];
                } else {
                    continue;
                }

                $line = trim( preg_replace( '/\s+/u', ' ', $line ) );

                return function_exists( 'mb_strimwidth' ) ? mb_strimwidth( $line, 0, 140, '…', 'UTF-8' ) : substr( $line, 0, 140 );
            }

            return '';
        }

        private function email_header_text( $data, $settings ) {
            $header_text = ! empty( $settings['emailHeaderText'] ) ? $settings['emailHeaderText'] : '';
            $user_name   = isset( $data['user_name'] ) ? (string) $data['user_name'] : '';

            if ( empty( $header_text ) ) {
                return sprintf( __( 'Hi %s,', 'bp-better-messages' ), $user_name );
            }

            return str_replace( '{{user_name}}', $user_name, $header_text );
        }

        private function email_footer_text( $settings ) {
            $footer_text = ! empty( $settings['emailFooterText'] ) ? $settings['emailFooterText'] : '';

            if ( empty( $footer_text ) ) {
                return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
            }

            return str_replace(
                array( '{{site_name}}', '{{site_url}}' ),
                array( get_bloginfo( 'name' ), home_url() ),
                $footer_text
            );
        }

        const EMAIL_COLORS = array(
            'emailBackgroundColor' => '#f4f4f5',
            'emailContentBgColor'  => '#ffffff',
            'emailTextColor'       => '#18181b',
        );

        public function email_accent_color() {
            if ( class_exists( 'Better_Messages_Design' ) ) {
                return Better_Messages_Design::instance()->get_accent_color_hex();
            }

            return '#2563eb';
        }

        private function email_colors( $settings ) {
            $accent = isset( $settings['emailPrimaryColor'] ) ? $this->email_hex( $settings['emailPrimaryColor'] ) : '';
            $chosen = array();
            $dark   = true;

            foreach ( self::EMAIL_COLORS as $key => $default ) {
                $hex            = isset( $settings[ $key ] ) ? $this->email_hex( $settings[ $key ] ) : '';
                $chosen[ $key ] = $hex === '' ? $default : $hex;

                if ( $chosen[ $key ] !== $default ) {
                    $dark = false;
                }
            }

            return array(
                'accent' => $accent === '' ? $this->email_accent_color() : $accent,
                'page'   => $chosen['emailBackgroundColor'],
                'card'   => $chosen['emailContentBgColor'],
                'text'   => $chosen['emailTextColor'],
                'dark'   => $dark,
            );
        }

        private function email_hex( $color ) {
            $color = strtolower( trim( (string) $color ) );

            if ( preg_match( '/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $color, $short ) ) {
                return '#' . $short[1] . $short[1] . $short[2] . $short[2] . $short[3] . $short[3];
            }

            return preg_match( '/^#[0-9a-f]{6}$/', $color ) ? $color : '';
        }

        private function email_message_block( array $blocks, $bm_user, $sender_id, $last_sender_id, $time, $text ) {
            $text = trim( (string) $text );

            if ( $text === '' ) {
                return $blocks;
            }

            $last = count( $blocks ) - 1;

            if ( $last >= 0 && $last_sender_id === $sender_id && $blocks[ $last ]['type'] === 'message' ) {
                $blocks[ $last ]['lines'][] = $text;

                return $blocks;
            }

            $blocks[] = array(
                'type'   => 'message',
                'sender' => (int) $sender_id,
                'name'   => $this->email_plain_text( is_array( $bm_user ) && isset( $bm_user['name'] ) ? $bm_user['name'] : '' ),
                'avatar' => $this->email_avatar( is_array( $bm_user ) && isset( $bm_user['avatar'] ) ? $bm_user['avatar'] : '' ),
                'time'   => (string) $time,
                'lines'  => array( $text ),
            );

            return $blocks;
        }

        public function email_counts( $user_id ) {
            global $wpdb;

            $recipients = bm_get_table( 'recipients' );
            $unread     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM(unread_count) FROM `{$recipients}` WHERE user_id = %d AND is_deleted = 0 AND unread_count > 0 AND is_muted NOT IN (4, 5)", $user_id ) );
            $missed     = 0;

            if ( $unread > 0 ) {
                $missed = (int) $wpdb->get_var( $wpdb->prepare( "
                    SELECT COUNT(*)
                    FROM `{$recipients}` `recipients`
                    INNER JOIN `" . bm_get_table( 'messages' ) . "` `messages`
                        ON `messages`.`thread_id` = `recipients`.`thread_id`
                        AND `messages`.`date_sent` > `recipients`.`last_read`
                        AND `messages`.`sender_id` != `recipients`.`user_id`
                    INNER JOIN `" . bm_get_table( 'meta' ) . "` `meta`
                        ON `meta`.`bm_message_id` = `messages`.`id`
                        AND `meta`.`meta_key` = 'bpbm_missed_call'
                        AND `meta`.`meta_value` = '1'
                    WHERE `recipients`.`user_id` = %d
                    AND `recipients`.`is_deleted` = 0
                    AND `recipients`.`unread_count` > 0", $user_id ) );
            }

            $missed = min( $missed, $unread );

            return array( 'unread' => $unread - $missed, 'missed_calls' => $missed );
        }

        private function email_with_counts( array $data, array $counts ) {
            $data['counts'] = array(
                'unread'       => isset( $counts['unread'] ) ? max( 0, (int) $counts['unread'] ) : 0,
                'missed_calls' => isset( $counts['missed_calls'] ) ? max( 0, (int) $counts['missed_calls'] ) : 0,
            );

            $parts = array();

            if ( $data['counts']['unread'] > 0 ) {
                /* translators: %s: the number of unread messages */
                $parts[] = sprintf( _n( '%s unread message', '%s unread messages', $data['counts']['unread'], 'bp-better-messages' ), number_format_i18n( $data['counts']['unread'] ) );
            }

            if ( $data['counts']['missed_calls'] > 0 ) {
                /* translators: %s: the number of missed calls */
                $parts[] = sprintf( _n( '%s missed call', '%s missed calls', $data['counts']['missed_calls'], 'bp-better-messages' ), number_format_i18n( $data['counts']['missed_calls'] ) );
            }

            if ( empty( $parts ) ) {
                return $data;
            }

            /* translators: %s: the unread messages and missed calls, as "12 unread messages and 2 missed calls" */
            $line = sprintf( _x( 'In all your conversations: %s', 'Email notification', 'bp-better-messages' ), wp_sprintf( '%l', $parts ) );

            $data['messages_html'] = ( isset( $data['messages_html'] ) ? (string) $data['messages_html'] : '' ) . '<p style="margin:1rem 0;">' . esc_html( $line ) . '</p>';
            $data['messages_raw']  = ( isset( $data['messages_raw'] ) && trim( (string) $data['messages_raw'] ) !== '' ? rtrim( (string) $data['messages_raw'] ) . "\n\n" : '' ) . $line;

            return $data;
        }

        private function email_stats_block( $data ) {
            if ( empty( $data['counts'] ) ) {
                return null;
            }

            $items = array();

            if ( $data['counts']['unread'] > 0 ) {
                $items[] = array( 'value' => number_format_i18n( $data['counts']['unread'] ), 'label' => _n( 'Unread message', 'Unread messages', $data['counts']['unread'], 'bp-better-messages' ) );
            }

            if ( $data['counts']['missed_calls'] > 0 ) {
                $items[] = array( 'value' => number_format_i18n( $data['counts']['missed_calls'] ), 'label' => _n( 'Missed call', 'Missed calls', $data['counts']['missed_calls'], 'bp-better-messages' ) );
            }

            if ( empty( $items ) ) {
                return null;
            }

            return array( 'type' => 'stats', 'title' => _x( 'In all your conversations', 'Email notification', 'bp-better-messages' ), 'items' => $items );
        }

        public function email_content_level( $settings = null ) {
            $settings = null === $settings ? Better_Messages()->settings : $settings;
            $level    = isset( $settings['emailContent'] ) ? (string) $settings['emailContent'] : 'full';

            return in_array( $level, array( 'full', 'senders', 'notice' ), true ) ? $level : 'full';
        }

        private function email_withhold( array $data, $level ) {
            if ( $level !== 'senders' && $level !== 'notice' ) {
                return $data;
            }

            $data['content_level'] = $level;

            if ( $level === 'notice' ) {
                /* translators: %s: the name of the site */
                $line = sprintf( _x( 'You have new messages on %s', 'Email notification', 'bp-better-messages' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );

                return array_merge( $data, array(
                    'subject'       => '',
                    'email_subject' => _x( 'You have unread messages', 'Email notification header for non BuddyPress websites', 'bp-better-messages' ),
                    'heading'       => $line,
                    'subheading'    => '',
                    'preheader'     => $line,
                    'blocks'        => array(),
                    'messages_html' => '<p style="margin:1rem 0;">' . esc_html( $line ) . '</p>',
                    'messages_raw'  => $line,
                ) );
            }

            $people = array();
            $count  = 0;

            foreach ( isset( $data['blocks'] ) && is_array( $data['blocks'] ) ? $data['blocks'] : array() as $block ) {
                if ( ! isset( $block['type'] ) || $block['type'] !== 'message' ) {
                    continue;
                }

                $key    = isset( $block['sender'] ) ? 'id' . (int) $block['sender'] : 'name' . $block['name'];
                $count += count( $block['lines'] );

                if ( ! isset( $people[ $key ] ) ) {
                    $people[ $key ] = array( 'name' => (string) $block['name'], 'avatar' => isset( $block['avatar'] ) ? (string) $block['avatar'] : '' );
                }
            }

            if ( empty( $people ) ) {
                return $this->email_withhold( $data, 'notice' );
            }

            $subject = isset( $data['subject'] ) ? (string) $data['subject'] : '';
            /* translators: %s: the names of who wrote the messages, as "Jane Smith and Mark Lee" */
            $line    = sprintf( _n( 'You have a new message from %s', 'You have new messages from %s', $count, 'bp-better-messages' ), $this->email_names( wp_list_pluck( $people, 'name' ) ) );

            return array_merge( $data, array(
                'heading'       => $line,
                /* translators: %s: the subject of the conversation */
                'subheading'    => $subject !== '' ? sprintf( _x( 'In “%s”', 'Email notification', 'bp-better-messages' ), $subject ) : '',
                'preheader'     => $line,
                'blocks'        => array( array( 'type' => 'faces', 'people' => array_values( $people ) ) ),
                'messages_html' => '<p style="margin:1rem 0;">' . esc_html( $line ) . '</p>',
                'messages_raw'  => $line,
            ) );
        }

        private function email_plain_text( $value ) {
            return trim( preg_replace( '/\s+/u', ' ', wp_specialchars_decode( wp_strip_all_tags( stripslashes( (string) $value ) ), ENT_QUOTES ) ) );
        }

        private function email_avatar( $url ) {
            $url = trim( (string) $url );

            if ( strpos( $url, '//' ) === 0 ) {
                $url = 'https:' . $url;
            }

            if ( ! preg_match( '#^https?://#i', $url ) ) {
                return '';
            }

            $path = (string) wp_parse_url( $url, PHP_URL_PATH );

            if ( preg_match( '/\.svg$/i', $path ) || substr( $path, -strlen( '/assets/images/avatar.png' ) ) === '/assets/images/avatar.png' ) {
                return '';
            }

            return $url;
        }

        public function default_custom_template( $settings = null ) {
            $settings = null === $settings ? Better_Messages()->settings : $settings;
            $email    = function_exists( 'wordplus_email' ) ? wordplus_email() : null;

            if ( ! $email ) {
                return '';
            }

            $slot = 'https://bm-template.invalid/';

            $layout = $this->email_layout( array(
                'email_subject' => '{{email_subject}}',
                'preheader'     => '{{preheader}}',
                'heading'       => '{{heading}}',
                'subheading'    => '{{subheading}}',
                'blocks'        => array( array( 'type' => 'html', 'html' => 'BM-TEMPLATE-MESSAGES' ), array( 'type' => 'html', 'html' => 'BM-TEMPLATE-STATS' ) ),
                'thread_url'    => $slot . 'thread_url',
                'unread_footer' => true,
                'footer_html'   => 'BM-TEMPLATE-UNSUBSCRIBE',
            ), array_merge( $settings, array(
                'emailHeaderText' => '{{header_text}}',
                'emailFooterText' => '{{footer_text}}',
                'emailButtonText' => '{{button_text}}',
            ) ) );

            return str_replace(
                array( '<div style="margin:0 0 24px;">BM-TEMPLATE-MESSAGES</div>', '<div style="margin:0 0 24px;">BM-TEMPLATE-STATS</div>', esc_url( $slot . 'thread_url' ), 'BM-TEMPLATE-UNSUBSCRIBE', 'href="' . esc_url( home_url() ) . '"' ),
                array( '{{messages_chat_html}}', '{{stats_html}}', '{{thread_url}}', '{{unsubscribe_link}}', 'href="{{site_url}}"' ),
                $email->render( $layout )['html']
            );
        }

        private function render_classic_email_template( $data ) {
            $settings = Better_Messages()->settings;

            $primary_color    = ! empty( $settings['emailPrimaryColor'] ) ? $settings['emailPrimaryColor'] : '#21759b';
            $background_color = ! empty( $settings['emailBackgroundColor'] ) ? $settings['emailBackgroundColor'] : '#f6f6f6';
            $content_bg_color = ! empty( $settings['emailContentBgColor'] ) ? $settings['emailContentBgColor'] : '#ffffff';
            $text_color       = ! empty( $settings['emailTextColor'] ) ? $settings['emailTextColor'] : '#333333';

            $header_text = ! empty( $settings['emailHeaderText'] ) ? $settings['emailHeaderText'] : '';
            $footer_text = ! empty( $settings['emailFooterText'] ) ? $settings['emailFooterText'] : '';
            $button_text = ! empty( $settings['emailButtonText'] ) ? $settings['emailButtonText'] : __( 'View Conversation', 'bp-better-messages' );

            if ( ! empty( $data['button_text'] ) ) {
                $button_text = (string) $data['button_text'];
            }

            if ( empty( $header_text ) ) {
                $header_text = sprintf( __( 'Hi %s,', 'bp-better-messages' ), $data['user_name'] );
            } else {
                $header_text = str_replace( '{{user_name}}', $data['user_name'], $header_text );
            }

            if ( empty( $footer_text ) ) {
                $footer_text = get_bloginfo( 'name' );
            } else {
                $footer_text = str_replace(
                    array( '{{site_name}}', '{{site_url}}' ),
                    array( get_bloginfo( 'name' ), home_url() ),
                    $footer_text
                );
            }

            $logo_html = '';
            if ( ! empty( $settings['emailLogoUrl'] ) ) {
                $logo_html = '<tr><td style="text-align: center; padding: 20px 0 10px;"><img src="' . esc_url( $settings['emailLogoUrl'] ) . '" style="max-width: 100%; height: auto;" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '"></td></tr>';
            }

            ob_start();
            ?>
            <!doctype html>
            <html>
            <head>
                <meta name="viewport" content="width=device-width">
                <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
                <title><?php echo esc_html( $data['email_subject'] ); ?></title>
                <style>
                    @media only screen and (max-width: 620px) {
                        table[class=body] h1 { font-size: 28px !important; margin-bottom: 10px !important; }
                        table[class=body] p, table[class=body] ul, table[class=body] ol, table[class=body] td, table[class=body] span, table[class=body] a { font-size: 16px !important; }
                        table[class=body] .wrapper, table[class=body] .article { padding: 10px !important; }
                        table[class=body] .content { padding: 0 !important; }
                        table[class=body] .container { padding: 0 !important; width: 100% !important; }
                        table[class=body] .main { border-left-width: 0 !important; border-radius: 0 !important; border-right-width: 0 !important; }
                        table[class=body] .btn table { width: 100% !important; }
                        table[class=body] .btn a { width: 100% !important; }
                        table[class=body] .img-responsive { height: auto !important; max-width: 100% !important; width: auto !important; }
                    }
                    @media all {
                        .ExternalClass { width: 100%; }
                        .ExternalClass, .ExternalClass p, .ExternalClass span, .ExternalClass font, .ExternalClass td, .ExternalClass div { line-height: 100%; }
                        .apple-link a { color: inherit !important; font-family: inherit !important; font-size: inherit !important; font-weight: inherit !important; line-height: inherit !important; text-decoration: none !important; }
                        #MessageViewBody a { color: inherit; text-decoration: none; font-size: inherit; font-family: inherit; font-weight: inherit; line-height: inherit; }
                        .btn-primary table td:hover { background-color: <?php echo esc_attr( $this->adjust_brightness( $primary_color, -20 ) ); ?> !important; }
                        .btn-primary a:hover { background-color: <?php echo esc_attr( $this->adjust_brightness( $primary_color, -20 ) ); ?> !important; border-color: <?php echo esc_attr( $this->adjust_brightness( $primary_color, -20 ) ); ?> !important; }
                    }
                </style>
            </head>
            <body class="" style="background-color: <?php echo esc_attr( $background_color ); ?>; font-family: sans-serif; -webkit-font-smoothing: antialiased; font-size: 14px; line-height: 1.4; margin: 0; padding: 0; -ms-text-size-adjust: 100%; -webkit-text-size-adjust: 100%;">
            <table border="0" cellpadding="0" cellspacing="0" class="body" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background-color: <?php echo esc_attr( $background_color ); ?>;">
                <tr>
                    <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                    <td class="container" style="font-family: sans-serif; font-size: 14px; vertical-align: top; display: block; Margin: 0 auto; max-width: 580px; padding: 10px; width: 580px;">
                        <div class="content" style="box-sizing: border-box; display: block; Margin: 0 auto; max-width: 580px; padding: 10px;">
                            <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; width: 100%;">
                                <?php echo $logo_html; ?>
                            </table>
                            <table class="main" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; background: <?php echo esc_attr( $content_bg_color ); ?>; border-radius: 3px;">
                                <tr>
                                    <td class="wrapper" style="font-family: sans-serif; font-size: 14px; vertical-align: top; box-sizing: border-box; padding: 20px;">
                                        <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                            <tr>
                                                <td style="font-family: sans-serif; font-size: 14px; vertical-align: top; color: <?php echo esc_attr( $text_color ); ?>;">
                                                    <p style="font-family: sans-serif; font-size: 16px; font-weight: bold; margin: 0; Margin-bottom: 15px; color: <?php echo esc_attr( $text_color ); ?>;"><?php echo esc_html( $header_text ); ?></p>
                                                    <p style="font-family: sans-serif; font-size: 14px; font-weight: normal; margin: 0; Margin-bottom: 15px; color: <?php echo esc_attr( $text_color ); ?>;"><?php echo esc_html( $data['email_subject'] ); ?></p>
                                                    <?php echo $data['messages_html']; ?>
                                                    <table border="0" cellpadding="0" cellspacing="0" class="btn btn-primary" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%; box-sizing: border-box; margin-top: 20px;">
                                                        <tbody>
                                                        <tr>
                                                            <td align="center" style="font-family: sans-serif; font-size: 14px; vertical-align: top; padding-bottom: 15px;">
                                                                <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt;">
                                                                    <tbody>
                                                                    <tr>
                                                                        <td style="font-family: sans-serif; font-size: 14px; vertical-align: top; background-color: <?php echo esc_attr( $primary_color ); ?>; border-radius: 5px; text-align: center;">
                                                                            <a href="<?php echo esc_url( $data['thread_url'] ); ?>" target="_blank" style="display: inline-block; color: #ffffff; background-color: <?php echo esc_attr( $primary_color ); ?>; border: solid 1px <?php echo esc_attr( $primary_color ); ?>; border-radius: 5px; box-sizing: border-box; cursor: pointer; text-decoration: none; font-size: 14px; font-weight: bold; margin: 0; padding: 12px 25px; text-transform: capitalize; border-color: <?php echo esc_attr( $primary_color ); ?>;"><?php echo esc_html( $button_text ); ?></a>
                                                                        </td>
                                                                    </tr>
                                                                    </tbody>
                                                                </table>
                                                            </td>
                                                        </tr>
                                                        </tbody>
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            <div class="footer" style="clear: both; Margin-top: 10px; text-align: center; width: 100%;">
                                <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: separate; mso-table-lspace: 0pt; mso-table-rspace: 0pt; width: 100%;">
                                    <tr>
                                        <td class="content-block" style="font-family: sans-serif; vertical-align: top; padding-bottom: 10px; padding-top: 10px; font-size: 12px; color: #999999; text-align: center;">
                                            <span class="apple-link" style="color: #999999; font-size: 12px; text-align: center;"><a href="<?php echo esc_url( home_url() ); ?>" style="color: <?php echo esc_attr( $primary_color ); ?>; text-decoration: none;"><?php echo esc_html( $footer_text ); ?></a></span>
                                            <?php if ( ! empty( $data['unsubscribe_url'] ) ) : ?>
                                            <br>
                                            <a href="<?php echo esc_url( $data['unsubscribe_url'] ); ?>" style="color: #999999; font-size: 11px; text-decoration: underline;"><?php _ex( 'Unsubscribe from email notifications about unread messages', 'Email footer', 'bp-better-messages' ); ?></a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </td>
                    <td style="font-family: sans-serif; font-size: 14px; vertical-align: top;">&nbsp;</td>
                </tr>
            </table>
            </body>
            </html>
            <?php
            return ob_get_clean();
        }

        public function render_custom_email_template( $data, $settings = null, $template = null ) {
            $template = null === $template ? Better_Messages_Options()->get_email_custom_html() : (string) $template;
            $settings = null === $settings ? Better_Messages()->settings : $settings;

            if ( $data['subject'] === '' ) {
                $template = $this->without_subject_placeholder( $template );
            }

            $colors           = $this->email_colors( $settings );
            $primary_color    = $colors['accent'];
            $background_color = $colors['page'];
            $content_bg_color = $colors['card'];
            $text_color       = $colors['text'];
            $button_text      = ! empty( $settings['emailButtonText'] ) ? $settings['emailButtonText'] : __( 'View Conversation', 'bp-better-messages' );

            $blocks  = $this->email_blocks( $data );
            $intro   = $this->email_intro( $data, $blocks );
            $stats   = $this->email_stats_block( $data );
            $email   = function_exists( 'wordplus_email' ) ? wordplus_email() : null;
            $palette = array( 'accent' => $primary_color, 'page' => $background_color, 'card' => $content_bg_color, 'text' => $text_color );

            $unsubscribe_link = '';
            if ( ! empty( $data['unsubscribe_url'] ) ) {
                $unsubscribe_link = '<a href="' . esc_url( $data['unsubscribe_url'] ) . '" style="color: inherit; text-decoration: underline;">' . esc_html_x( 'Unsubscribe', 'Email footer', 'bp-better-messages' ) . '</a>';
            }

            $header_text = ! empty( $settings['emailHeaderText'] ) ? $settings['emailHeaderText'] : '';
            if ( empty( $header_text ) ) {
                $header_text = sprintf( __( 'Hi %s,', 'bp-better-messages' ), $data['user_name'] );
            } else {
                $header_text = str_replace( '{{user_name}}', $data['user_name'], $header_text );
            }

            $footer_text = ! empty( $settings['emailFooterText'] ) ? $settings['emailFooterText'] : '';
            if ( empty( $footer_text ) ) {
                $footer_text = get_bloginfo( 'name' );
            } else {
                $footer_text = str_replace(
                    array( '{{site_name}}', '{{site_url}}' ),
                    array( get_bloginfo( 'name' ), home_url() ),
                    $footer_text
                );
            }

            $logo_html = '';
            if ( ! empty( $settings['emailLogoUrl'] ) ) {
                $logo_html = '<tr><td style="text-align: center; padding: 20px 0 10px;"><img src="' . esc_url( $settings['emailLogoUrl'] ) . '" style="max-width: 100%; height: auto;" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '"></td></tr>';
            }

            $unsubscribe_html = '';
            if ( ! empty( $data['unsubscribe_url'] ) ) {
                $unsubscribe_html = '<br><a href="' . esc_url( $data['unsubscribe_url'] ) . '" style="color: #999999; font-size: 11px; text-decoration: underline;">' . esc_html_x( 'Unsubscribe from email notifications about unread messages', 'Email footer', 'bp-better-messages' ) . '</a>';
            }

            $replacements = array(
                '{{site_name}}'        => get_bloginfo( 'name' ),
                '{{site_url}}'         => home_url(),
                '{{user_name}}'        => $data['user_name'],
                '{{subject}}'          => $data['subject'],
                '{{email_subject}}'    => $data['email_subject'],
                '{{messages_html}}'    => $data['messages_html'],
                '{{thread_url}}'       => $data['thread_url'],
                '{{unsubscribe_url}}'  => isset( $data['unsubscribe_url'] ) ? $data['unsubscribe_url'] : '',
                '{{primary_color}}'    => $primary_color,
                '{{background_color}}' => $background_color,
                '{{content_bg_color}}' => $content_bg_color,
                '{{text_color}}'       => $text_color,
                '{{button_text}}'      => $button_text,
                '{{header_text}}'      => $header_text,
                '{{footer_text}}'      => $footer_text,
                '{{logo_html}}'        => $logo_html,
                '{{unsubscribe_html}}' => $unsubscribe_html,
                '{{unsubscribe_link}}' => $unsubscribe_link,
                '{{messages_chat_html}}' => $email ? $email->blocks_html( $blocks, $palette ) : ( isset( $data['messages_html'] ) ? $data['messages_html'] : '' ),
                '{{stats_html}}'       => $email && $stats ? $email->blocks_html( array( $stats ), $palette ) : '',
                '{{unread_count}}'     => isset( $data['counts'] ) ? number_format_i18n( $data['counts']['unread'] ) : '0',
                '{{missed_calls_count}}' => isset( $data['counts'] ) ? number_format_i18n( $data['counts']['missed_calls'] ) : '0',
                '{{heading}}'          => esc_html( $intro[0] ),
                '{{subheading}}'       => esc_html( $intro[1] ),
                '{{preheader}}'        => esc_html( isset( $data['preheader'] ) ? (string) $data['preheader'] : $this->email_preheader( $blocks ) ),
            );

            return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
        }

        private function adjust_brightness( $hex, $steps ) {
            $hex = ltrim( $hex, '#' );

            $r = hexdec( substr( $hex, 0, 2 ) );
            $g = hexdec( substr( $hex, 2, 2 ) );
            $b = hexdec( substr( $hex, 4, 2 ) );

            $r = max( 0, min( 255, $r + $steps ) );
            $g = max( 0, min( 255, $g + $steps ) );
            $b = max( 0, min( 255, $b + $steps ) );

            return '#' . sprintf( '%02x%02x%02x', $r, $g, $b );
        }
    }

endif;

function Better_Messages_Notifications()
{
    return Better_Messages_Notifications::instance();
}
