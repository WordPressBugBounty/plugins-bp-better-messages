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

            // Add REST API endpoint for test email and unsubscribe
            add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

            // Display unsubscribe confirmation message
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
                if ( ! wp_next_scheduled('better_messages_send_notifications') ) {
                    wp_schedule_event(time(), 'bp_better_messages_notifications', 'better_messages_send_notifications');
                }
            } else {
                if ( wp_next_scheduled('better_messages_send_notifications') ) {
                    wp_unschedule_event( wp_next_scheduled( 'better_messages_send_notifications' ), 'better_messages_send_notifications' );
                }
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

            // Add these emails to the database.
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
                    $email_subject   = $email->get_subject();
                    $email_plaintext = $email->get_content_plaintext();
                    $email_html      = $email->get_content_html();

                    $to_remove = [ '&quot;{{subject}}&quot;', '"{{subject}}"', '{{subject}}' ];

                    foreach ( $to_remove as $str ){
                        $email_subject   = trim(str_replace( $str, '', $email_subject ) );
                        $email_plaintext = trim(str_replace( $str, '', $email_plaintext ) );
                        $email_html      = trim(str_replace( $str, '', $email_html ) );
                    }


                    if(substr($email_subject, -1, 1) === ':'){
                        $email_subject = substr($email_subject, 0, strlen($email_subject) - 1);
                    }

                    $email->set_subject( $email_subject );
                    $email->set_content_plaintext( $email_plaintext );
                    $email->set_content_html( $email_html );
                }
            }
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
                $where[] = "AND `recipients`.`is_muted` = 0";
                $where[] = "AND `recipients`.`user_id` > 0";
                $where[] = "AND `recipients`.`thread_id` NOT IN( SELECT `bm_thread_id` FROM `" . bm_get_table('threadsmeta') . "` WHERE `meta_key` = 'email_disabled' AND `meta_value` = '1' )";

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

                while ( is_array( $unread_threads ) && count( $unread_threads ) > 0 ){
                    $gmt_offset = get_option('gmt_offset') * 3600;

                    foreach ( $unread_threads as $thread ) {
                        $user_id       = $thread->user_id;
                        $thread_id     = $thread->thread_id;
                        $last_notified = $thread->last_email;
                        $last_date     = $thread->last_date;

                        $this->sending_thread_id = $thread_id;

                        $chat_id = null;

                        $type = Better_Messages()->functions->get_thread_type( $thread_id );

                        if( $type === 'group' ) {
                            if ( Better_Messages()->settings['enableGroupsEmails'] !== '1' ) {
                                $group_id = Better_Messages()->functions->get_thread_meta($thread_id, 'group_id');

                                if (!empty($group_id)) {
                                    $this->update_last_email( $user_id, $thread_id, $thread->last_date );
                                    continue;
                                }
                            }

                            if ( Better_Messages()->settings['PSenableGroupsEmails'] !== '1' ) {
                                $group_id = Better_Messages()->functions->get_thread_meta($thread_id, 'peepso_group_id');

                                if (!empty($group_id)) {
                                    $this->update_last_email( $user_id, $thread_id, $thread->last_date );
                                    continue;
                                }
                            }

                            if ( Better_Messages()->settings['UMenableGroupsEmails'] !== '1' ) {
                                $group_id = Better_Messages()->functions->get_thread_meta($thread_id, 'um_group_id');

                                if (!empty($group_id)) {
                                    $this->update_last_email( $user_id, $thread_id, $thread->last_date );
                                    continue;
                                }
                            }

                            if( Better_Messages()->settings['FCenableGroupsEmails'] !== '1' ){
                                $group_id = Better_Messages()->functions->get_thread_meta($thread_id, 'fluentcommunity_group_id');

                                if ( ! empty( $group_id) ) {
                                    $this->update_last_email( $user_id, $thread_id, $thread->last_date );
                                    continue;
                                }
                            }
                        }

                        if( $type === 'chat-room' ) {
                            $chat_id = Better_Messages()->functions->get_thread_meta($thread_id, 'chat_id');

                            if (!empty($chat_id)) {
                                $is_excluded_from_threads_list = Better_Messages()->functions->get_thread_meta($thread_id, 'exclude_from_threads_list');
                                if ($is_excluded_from_threads_list === '1') {
                                    Better_Messages()->functions->update_thread_meta($thread_id, 'email_disabled', '1');
                                    $this->update_last_email( $user_id, $thread_id, $thread->last_date );
                                    continue;
                                }

                                $notifications_enabled = Better_Messages()->functions->get_thread_meta($thread_id, 'enable_notifications');
                                if ($notifications_enabled !== '1') {
                                    Better_Messages()->functions->update_thread_meta($thread_id, 'email_disabled', '1');
                                    $this->update_last_email( $user_id, $thread_id, $thread->last_date );
                                    continue;
                                }
                            }
                        }

                        if ( ! $this->is_user_emails_enabled( $user_id )  ) {
                            $this->update_last_email( $user_id, $thread_id, $thread->last_date );
                            continue;
                        }

                        if ( ! $last_notified || ( $last_date > $last_notified ) ) {

                            if( ! $last_notified ) $last_notified = gmdate('Y-m-d H:i:s', 0 );

                            $ud = get_userdata( $user_id );

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
                                AND `messages`.sender_id != 0
                                AND `messages`.is_pending = 0
                                AND ( messagesmeta.meta_id IS NULL )
                                ORDER BY id DESC
                                LIMIT 0, %d
                            ", $thread->thread_id, $last_notified, $user_id, $thread->unread_count );

                            $messages = array_reverse( $wpdb->get_results( $query ) );

                            if ( empty( $messages ) ) {
                                $this->update_last_email( $user_id, $thread_id, gmdate('Y-m-d H:i:s') );
                                continue;
                            }

                            foreach($messages as $index => $message){
                                if( $message->message ){
                                    $is_sticker = strpos( $message->message, '<span class="bpbm-sticker">' ) !== false;
                                    if( $is_sticker ){
                                        $message->message = __('Sticker', 'bp-better-messages');
                                    }

                                    $is_gif = strpos( $message->message, '<span class="bpbm-gif">' ) !== false;
                                    if( $is_gif ){
                                        $message->message = __('GIF', 'bp-better-messages');
                                    }
                                }
                            }

                            $email_overwritten = apply_filters( 'bp_better_messages_overwrite_email', false, $user_id, $thread_id, $messages );

                            if( $email_overwritten === false ) {
                                $messageRaw = '';
                                $messageHtml = '<table style="margin:1rem 0!important;width:100%;table-layout: auto !important;"><tbody>';
                                $last_sender_id = 0;
                                $last_message_id = 0;

                                foreach ($messages as $message) {
                                    $bm_user = Better_Messages()->functions->rest_user_item( $message->sender_id, false );

                                    $timestamp = strtotime($message->date_sent) + $gmt_offset;
                                    $time_format = get_option('time_format');

                                    if (gmdate('Ymd') != gmdate('Ymd', $timestamp)) {
                                        $time_format .= ' ' . get_option('date_format');
                                    }

                                    $time    = apply_filters( 'better_messages_email_notification_time', wp_strip_all_tags( stripslashes(date_i18n($time_format, $timestamp)) ), $message, $user_id );

                                    $author  = wp_strip_all_tags(stripslashes(sprintf( __('%s wrote:', 'bp-better-messages'), $bm_user['name'] )));

                                    $_message = nl2br(stripslashes($message->message));
                                    $_message = str_replace(['<p>', '</p>'], ['<br>', ''], $_message );
                                    $_message = Better_Messages()->functions->format_message( $_message, $message->id, 'email', $user_id );
                                    $_message = htmlspecialchars_decode(Better_Messages()->functions->strip_all_tags($_message, '<br>'));

                                    if ($last_sender_id == 0 || $last_sender_id != $message->sender_id) {
                                        $messageHtml .= '<tr><td colspan="2"><b>' . $author . '</b></td></tr>';
                                        $messageRaw .= "$author\n";
                                    }

                                    $_message_raw = str_replace(["<br>", "<br/>", '<br />'], "\n", $_message );
                                    $messageRaw .= "$time\n$_message_raw\n\n";

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
                                    $subject = Better_Messages()->functions->remove_re(sanitize_text_field(stripslashes($messages[0]->subject)));
                                    $subject = Better_Messages()->functions->clean_no_subject($subject);
                                }

                                // Check if BuddyPress email should be used
                                $use_buddypress_email = function_exists( 'bp_send_email' ) && Better_Messages()->settings['emailTemplateSource'] === 'buddypress';

                                if ( $use_buddypress_email ) {
                                    $sender = get_userdata( $message->sender_id );

                                    if ( ! is_object( $sender ) ){
                                        $this->update_last_email( $user_id, $thread_id, $last_date );
                                        continue;
                                    }

                                    $args = array(
                                        'tokens' =>
                                            apply_filters('bp_better_messages_notification_tokens', array(
                                                'messages.html' => $messageHtml,
                                                'messages.raw' => $messageRaw,
                                                'sender.name' => $bm_user['name'],
                                                'thread.id' => $thread_id,
                                                'thread.url' => sanitize_url( Better_Messages()->functions->add_hash_arg( 'conversation/' . $thread_id, [], Better_Messages()->functions->get_link($user_id) ) ),
                                                'subject' => $subject,
                                                'unsubscribe' => sanitize_url(bp_email_get_unsubscribe_link(array(
                                                    'user_id' => $user_id,
                                                    'notification_type' => 'messages-unread',
                                                )))
                                            ),
                                                $ud, // userdata object of receiver
                                                $sender, // userdata object of sender
                                                $thread_id
                                            ),
                                    );

                                    bp_send_email('messages-unread-group', $ud, $args);
                                } else {
                                    $user = get_userdata($user_id);
                                    $thread_url    = sanitize_url( Better_Messages()->functions->add_hash_arg('conversation/' . $thread_id, [], Better_Messages()->functions->get_link($user_id) ) );

                                    if( $subject !== '' ) {
                                        $email_subject = sprintf(_x('You have unread messages: "%s"', 'Email notification header for non BuddyPress websites', 'bp-better-messages'), $subject);
                                    } else {
                                        $email_subject = _x('You have unread messages', 'Email notification header for non BuddyPress websites', 'bp-better-messages');
                                    }

                                    // Prepare template data
                                    $template_data = array(
                                        'user_name'       => $user->display_name,
                                        'subject'         => $subject,
                                        'email_subject'   => $email_subject,
                                        'messages_html'   => $messageHtml,
                                        'thread_url'      => $thread_url,
                                        'unsubscribe_url' => Better_Messages()->settings['emailUnsubscribeLink'] === '1' ? $this->get_unsubscribe_url( $user_id ) : '',
                                    );

                                    // Get the email content using the customizable template system
                                    $content = $this->get_email_template( $template_data );

                                    add_filter( 'wp_mail_content_type', array( $this, 'email_content_type' ) );
                                    wp_mail( $user->user_email, $email_subject, $content );
                                    remove_filter( 'wp_mail_content_type', array( $this, 'email_content_type' ) );
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

                            if (function_exists('bp_notifications_add_notification')) {
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

                    $unread_threads = $wpdb->get_results( $sql );
                }
            } finally {
                $this->is_sending_notifications = false;
                $this->sending_thread_id = false;
            }
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
                // code to repeat here
                $offset = ($page - 1) * $per_page;

                $rows = $wpdb->get_results("SELECT user_id, meta_value FROM `{$wpdb->usermeta}` WHERE `meta_key` = 'bp-better-messages-last-notified' ORDER BY user_id LIMIT $offset, $per_page");

                if( count( $rows ) > 0 ){
                    foreach ( $rows as $row ){
                        $user_id = $row->user_id;
                        $threads = unserialize($row->meta_value);

                        if( is_array( $threads ) && count( $threads ) > 0 ){
                            foreach( $threads as $thread_id => $last_id ){
                                $last_time = $wpdb->get_var( $wpdb->prepare(  "SELECT created_at FROM `" . bm_get_table('messages') . "'` WHERE `thread_id` = %d AND `id` = %d", $thread_id, $last_id ) );
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

        /**
         * Register REST API routes
         */
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

            register_rest_route( 'better-messages/v1', '/unsubscribe', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_unsubscribe' ),
                'permission_callback' => '__return_true',
                'args'                => array(
                    'user_id' => array(
                        'required'          => true,
                        'type'              => 'integer',
                        'sanitize_callback' => 'absint',
                    ),
                    'token' => array(
                        'required'          => true,
                        'type'              => 'string',
                        'sanitize_callback' => 'sanitize_text_field',
                    ),
                ),
            ) );
        }

        /**
         * Permission callback for settings management
         */
        public function can_manage_settings() {
            return current_user_can( 'manage_options' );
        }

        /**
         * REST API handler for sending test email
         *
         * @param WP_REST_Request $request
         * @return WP_REST_Response|WP_Error
         */
        public function rest_send_test_email( WP_REST_Request $request ) {
            $email = $request->get_param( 'email' );

            if ( ! is_email( $email ) ) {
                return new WP_Error(
                    'invalid_email',
                    __( 'Please enter a valid email address.', 'bp-better-messages' ),
                    array( 'status' => 400 )
                );
            }

            $current_user = wp_get_current_user();
            $subject      = __( 'Test Conversation', 'bp-better-messages' );
            $email_subject = sprintf( _x( 'You have unread messages: "%s"', 'Email notification header for non BuddyPress websites', 'bp-better-messages' ), $subject );

            // Check if BuddyPress email should be used
            $use_buddypress = function_exists( 'bp_send_email' ) && Better_Messages()->settings['emailTemplateSource'] === 'buddypress';

            if ( $use_buddypress ) {
                // Send test email via BuddyPress email system
                $args = array(
                    'tokens' => array(
                        'messages.html' => $this->get_test_messages_html(),
                        'messages.raw'  => __( 'Hello! This is a test message to preview how your emails will look.', 'bp-better-messages' ),
                        'sender.name'   => $current_user->display_name,
                        'thread.id'     => 0,
                        'thread.url'    => home_url(),
                        'subject'       => $subject,
                        'unsubscribe'   => '#',
                    ),
                );

                try {
                    // bp_send_email can accept user ID, WP_User object, or email address
                    bp_send_email( 'messages-unread-group', $email, $args );
                    $sent = true;
                } catch ( Exception $e ) {
                    $sent = false;
                }
            } else {
                // Use custom template system
                $template_data = array(
                    'user_name'       => $current_user->display_name,
                    'subject'         => $subject,
                    'email_subject'   => $email_subject,
                    'messages_html'   => $this->get_test_messages_html(),
                    'thread_url'      => home_url(),
                    'unsubscribe_url' => Better_Messages()->settings['emailUnsubscribeLink'] === '1' ? $this->get_unsubscribe_url( $current_user->ID ) : '',
                );

                // Get the email content using the customizable template system
                $content = $this->get_email_template( $template_data );

                // Send the test email
                add_filter( 'wp_mail_content_type', array( $this, 'email_content_type' ) );
                $sent = wp_mail( $email, $email_subject, $content );
                remove_filter( 'wp_mail_content_type', array( $this, 'email_content_type' ) );
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

        /**
         * REST API handler for unsubscribing from message email notifications
         *
         * @param WP_REST_Request $request
         * @return WP_REST_Response|WP_Error
         */
        public function rest_unsubscribe( WP_REST_Request $request ) {
            $user_id = $request->get_param( 'user_id' );
            $token   = $request->get_param( 'token' );

            // Verify the token
            if ( ! $this->verify_unsubscribe_token( $user_id, $token ) ) {
                // Redirect to home with error
                wp_redirect( add_query_arg( 'bm-unsubscribe', 'invalid', home_url() ) );
                exit;
            }

            // Disable message email notifications for this user using existing setting
            $this->user_emails_enabled_update( $user_id, 'no' );

            // Redirect to confirmation page
            wp_redirect( add_query_arg( 'bm-unsubscribe', 'success', home_url() ) );
            exit;
        }

        /**
         * Generate unsubscribe token for a user
         *
         * @param int $user_id User ID
         * @return string Token
         */
        public function generate_unsubscribe_token( $user_id ) {
            $secret = wp_salt( 'auth' );
            $data   = $user_id . '|bm_unsubscribe';

            return hash_hmac( 'sha256', $data, $secret );
        }

        /**
         * Verify unsubscribe token
         *
         * @param int $user_id User ID
         * @param string $token Token to verify
         * @return bool True if valid
         */
        public function verify_unsubscribe_token( $user_id, $token ) {
            $expected_token = $this->generate_unsubscribe_token( $user_id );

            return hash_equals( $expected_token, $token );
        }

        /**
         * Generate unsubscribe URL for a user
         *
         * @param int $user_id User ID
         * @return string Unsubscribe URL
         */
        public function get_unsubscribe_url( $user_id ) {
            $token = $this->generate_unsubscribe_token( $user_id );

            return rest_url( 'better-messages/v1/unsubscribe' ) . '?' . http_build_query( array(
                'user_id' => $user_id,
                'token'   => $token,
            ) );
        }

        /**
         * Display unsubscribe confirmation message in footer
         */
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

        /**
         * Generate sample messages HTML for test email
         *
         * @return string Sample messages HTML
         */
        private function get_test_messages_html() {
            $messages_html = '<table style="margin:1rem 0!important;width:100%;table-layout: auto !important;"><tbody>';

            // Sample message 1
            $messages_html .= '<tr><td colspan="2"><b>' . sprintf( __( '%s wrote:', 'bp-better-messages' ), 'Jane Smith' ) . '</b></td></tr>';
            $messages_html .= '<tr>';
            $messages_html .= '<td style="padding-right: 10px;">' . __( 'Hello! This is a sample message to test how your email notifications will look.', 'bp-better-messages' ) . '</td>';
            $messages_html .= '<td style="width:1px;white-space:nowrap;vertical-align:top;text-align:right;text-overflow:ellipsis;overflow:hidden;"><i>' . date_i18n( get_option( 'time_format' ), strtotime( '-5 minutes' ) ) . '</i></td>';
            $messages_html .= '</tr>';

            // Sample message 2
            $messages_html .= '<tr><td colspan="2"><b>' . sprintf( __( '%s wrote:', 'bp-better-messages' ), 'John Doe' ) . '</b></td></tr>';
            $messages_html .= '<tr>';
            $messages_html .= '<td style="padding-right: 10px;">' . __( 'Thanks for the message! Here is another sample to show how multiple messages appear in the email.', 'bp-better-messages' ) . '</td>';
            $messages_html .= '<td style="width:1px;white-space:nowrap;vertical-align:top;text-align:right;text-overflow:ellipsis;overflow:hidden;"><i>' . date_i18n( get_option( 'time_format' ), strtotime( '-2 minutes' ) ) . '</i></td>';
            $messages_html .= '</tr>';

            $messages_html .= '</tbody></table>';

            return $messages_html;
        }

        /**
         * Get the email template based on settings
         *
         * @param array $data Template data with placeholders
         * @return string Rendered HTML email
         */
        public function get_email_template( $data ) {
            $settings = Better_Messages()->settings;
            $mode = isset( $settings['emailTemplateMode'] ) ? $settings['emailTemplateMode'] : 'simple';

            if ( $mode === 'custom' && ! empty( Better_Messages_Options()->get_email_custom_html() ) ) {
                return $this->render_custom_email_template( $data );
            }

            return $this->render_simple_email_template( $data );
        }

        /**
         * Render email using simple mode settings
         *
         * @param array $data Template data
         * @return string Rendered HTML
         */
        public function render_simple_email_template( $data ) {
            $settings = Better_Messages()->settings;

            // Get colors from settings or use defaults
            $primary_color    = ! empty( $settings['emailPrimaryColor'] ) ? $settings['emailPrimaryColor'] : '#21759b';
            $background_color = ! empty( $settings['emailBackgroundColor'] ) ? $settings['emailBackgroundColor'] : '#f6f6f6';
            $content_bg_color = ! empty( $settings['emailContentBgColor'] ) ? $settings['emailContentBgColor'] : '#ffffff';
            $text_color       = ! empty( $settings['emailTextColor'] ) ? $settings['emailTextColor'] : '#333333';

            // Get custom texts or use defaults
            $header_text = ! empty( $settings['emailHeaderText'] ) ? $settings['emailHeaderText'] : '';
            $footer_text = ! empty( $settings['emailFooterText'] ) ? $settings['emailFooterText'] : '';
            $button_text = ! empty( $settings['emailButtonText'] ) ? $settings['emailButtonText'] : __( 'View Conversation', 'bp-better-messages' );

            // Process header text placeholder
            if ( empty( $header_text ) ) {
                $header_text = sprintf( __( 'Hi %s,', 'bp-better-messages' ), $data['user_name'] );
            } else {
                $header_text = str_replace( '{{user_name}}', $data['user_name'], $header_text );
            }

            // Process footer text placeholders
            if ( empty( $footer_text ) ) {
                $footer_text = get_bloginfo( 'name' );
            } else {
                $footer_text = str_replace(
                    array( '{{site_name}}', '{{site_url}}' ),
                    array( get_bloginfo( 'name' ), home_url() ),
                    $footer_text
                );
            }

            // Get logo HTML if logo is set
            $logo_html = '';
            if ( ! empty( $settings['emailLogoUrl'] ) ) {
                $logo_html = '<tr><td style="text-align: center; padding: 20px 0 10px;"><img src="' . esc_url( $settings['emailLogoUrl'] ) . '" style="max-width: 200px; max-height: 60px;" alt="' . esc_attr( get_bloginfo( 'name' ) ) . '"></td></tr>';
            }

            // Build the email HTML
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

        /**
         * Render email using custom HTML template
         *
         * @param array $data Template data
         * @return string Rendered HTML
         */
        public function render_custom_email_template( $data ) {
            $template = Better_Messages_Options()->get_email_custom_html();
            $settings = Better_Messages()->settings;

            // Get colors from settings or use defaults
            $primary_color    = ! empty( $settings['emailPrimaryColor'] ) ? $settings['emailPrimaryColor'] : '#21759b';
            $background_color = ! empty( $settings['emailBackgroundColor'] ) ? $settings['emailBackgroundColor'] : '#f6f6f6';
            $content_bg_color = ! empty( $settings['emailContentBgColor'] ) ? $settings['emailContentBgColor'] : '#ffffff';
            $text_color       = ! empty( $settings['emailTextColor'] ) ? $settings['emailTextColor'] : '#333333';
            $button_text      = ! empty( $settings['emailButtonText'] ) ? $settings['emailButtonText'] : __( 'View Conversation', 'bp-better-messages' );

            // Replace all placeholders
            $replacements = array(
                '{{site_name}}'       => get_bloginfo( 'name' ),
                '{{site_url}}'        => home_url(),
                '{{user_name}}'       => $data['user_name'],
                '{{subject}}'         => $data['subject'],
                '{{email_subject}}'   => $data['email_subject'],
                '{{messages_html}}'   => $data['messages_html'],
                '{{thread_url}}'      => $data['thread_url'],
                '{{unsubscribe_url}}' => isset( $data['unsubscribe_url'] ) ? $data['unsubscribe_url'] : '',
                '{{primary_color}}'   => $primary_color,
                '{{background_color}}' => $background_color,
                '{{content_bg_color}}' => $content_bg_color,
                '{{text_color}}'      => $text_color,
                '{{button_text}}'     => $button_text,
            );

            return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
        }

        /**
         * Adjust color brightness
         *
         * @param string $hex Hex color
         * @param int $steps Steps to adjust (-255 to 255)
         * @return string Adjusted hex color
         */
        private function adjust_brightness( $hex, $steps ) {
            // Remove # if present
            $hex = ltrim( $hex, '#' );

            // Convert to RGB
            $r = hexdec( substr( $hex, 0, 2 ) );
            $g = hexdec( substr( $hex, 2, 2 ) );
            $b = hexdec( substr( $hex, 4, 2 ) );

            // Adjust
            $r = max( 0, min( 255, $r + $steps ) );
            $g = max( 0, min( 255, $g + $steps ) );
            $b = max( 0, min( 255, $b + $steps ) );

            // Convert back to hex
            return '#' . sprintf( '%02x%02x%02x', $r, $g, $b );
        }
    }

endif;

function Better_Messages_Notifications()
{
    return Better_Messages_Notifications::instance();
}
