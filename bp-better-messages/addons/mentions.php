<?php
defined( 'ABSPATH' ) || exit;

if ( !class_exists( 'Better_Messages_Mentions' ) ) {

    class Better_Messages_Mentions
    {
        const NOTIFY_TYPES = array( 'mention', 'reply', 'everyone' );

        const PERSONAL_TYPES = array( 'mention', 'reply' );

        const MAX_PERSONAL = 100;

        const MAX_PER_THREAD = 50;

        private $targets = array();

        public static function instance()
        {

            static $instance = null;

            if (null === $instance) {
                $instance = new Better_Messages_Mentions();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'bp_better_messages_message_deleted', array( $this, 'message_deleted' ), 10, 1 );
            add_filter( 'better_messages_rest_api_update_data', array( $this, 'add_mentions_to_update' ), 10, 3 );
            add_action( 'better_messages_mark_thread_read', array( $this, 'clean_mentions_on_thread_read' ), 10, 2 );
            add_action( 'better_messages_mark_all_read', array( $this, 'clean_mentions_on_all_read' ), 10, 1 );
            add_action( 'better_messages_participant_removed', array( $this, 'participant_removed' ), 10, 2 );
            add_action( 'better_messages_participants_removed', array( $this, 'participants_removed' ), 10, 2 );
            add_action( 'better_messages_thread_erased', array( $this, 'thread_erased' ), 10, 1 );
            add_action( 'better_messages_message_before_save', array( $this, 'enforce_mention_all_on_send' ), 20, 1 );
            add_filter( 'better_messages_message_content_before_save', array( $this, 'enforce_mention_all_on_edit' ), 20, 2 );
        }

        public function mention_all_permission( $thread_id )
        {
            $permission = Better_Messages()->functions->get_thread_meta( $thread_id, 'mention_all' );

            if ( ! in_array( $permission, array( 'nobody', 'moderators', 'everyone' ), true ) ) {
                $permission = isset( Better_Messages()->settings['mentionAllPermission'] ) ? Better_Messages()->settings['mentionAllPermission'] : 'moderators';
            }

            return in_array( $permission, array( 'nobody', 'moderators', 'everyone' ), true ) ? $permission : 'moderators';
        }

        public function can_mention_all( $thread_id, $user_id )
        {
            $thread_id = (int) $thread_id;
            $user_id   = (int) $user_id;
            $levels    = Better_Messages()->notification_levels;

            $allowed = false;

            if ( $thread_id > 0 && $user_id !== 0 && ! Better_Messages()->functions->is_ai_bot_user( $user_id )
                && ! $levels->is_one_to_one( $thread_id ) && ! $levels->is_thread_locked( $thread_id )
                && ! ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_e2e_thread( $thread_id ) ) ) {

                $max = (int) apply_filters( 'better_messages_mention_all_max_participants', 5000, $thread_id );

                if ( $max <= 0 || count( Better_Messages()->functions->get_recipients( $thread_id ) ) <= $max ) {
                    switch ( $this->mention_all_permission( $thread_id ) ) {
                        case 'everyone':
                            $allowed = Better_Messages()->functions->is_thread_participant( $user_id, $thread_id, true ) || $this->is_mention_all_moderator( $thread_id, $user_id );
                            break;
                        case 'moderators':
                            $allowed = $this->is_mention_all_moderator( $thread_id, $user_id );
                            break;
                    }
                }
            }

            return (bool) apply_filters( 'better_messages_can_mention_all', $allowed, $thread_id, $user_id );
        }

        private function is_mention_all_moderator( $thread_id, $user_id )
        {
            if ( $user_id > 0 && user_can( $user_id, 'bm_can_administrate' ) ) {
                return true;
            }

            return Better_Messages()->functions->can_moderate_thread( $thread_id, $user_id );
        }

        public static function strip_mention_all( $content )
        {
            $content = preg_replace( '/&lt;span class=&quot;bm-mention&quot; data-user-id=&quot;all&quot;&gt;(.*?)&lt;\/span&gt;/s', '$1', (string) $content );

            return str_replace( 'data-user-id=&quot;all&quot;', '', $content );
        }

        private function contains_mention_all( $content )
        {
            return is_string( $content ) && strpos( $content, 'data-user-id=&quot;all&quot;' ) !== false;
        }

        public function enforce_mention_all_on_send( $message )
        {
            if ( ! is_object( $message ) || ! $this->contains_mention_all( $message->message ) ) {
                return;
            }

            $thread_id = (int) $message->thread_id;
            $sender_id = (int) $message->sender_id;

            if ( ! $this->can_mention_all( $thread_id, $sender_id ) ) {
                $message->message = self::strip_mention_all( $message->message );
                return;
            }

            if ( $this->is_mention_all_moderator( $thread_id, $sender_id ) ) {
                return;
            }

            $cooldown = (int) apply_filters( 'better_messages_mention_all_cooldown_seconds', 600, $thread_id );
            $last     = (int) Better_Messages()->functions->get_thread_meta( $thread_id, '_bm_mention_all_sent' );

            if ( $cooldown > 0 && $last > 0 && ( time() - $last ) < $cooldown ) {
                $message->message = self::strip_mention_all( $message->message );
                return;
            }

            Better_Messages()->functions->update_thread_meta( $thread_id, '_bm_mention_all_sent', time() );
        }

        public function enforce_mention_all_on_edit( $content, $message_id = null )
        {
            $message_id = (int) $message_id;

            if ( $message_id <= 0 || ! $this->contains_mention_all( $content ) ) {
                return $content;
            }

            $message = Better_Messages()->functions->get_message( $message_id );

            if ( ! $message ) {
                return self::strip_mention_all( $content );
            }

            if ( strpos( (string) $message->message, 'data-user-id=&quot;all&quot;' ) === false && ! $this->can_mention_all( (int) $message->thread_id, (int) $message->sender_id ) ) {
                return self::strip_mention_all( $content );
            }

            return $content;
        }

        public static function token_pattern()
        {
            return '/&lt;span class=&quot;bm-mention&quot; data-user-id=&quot;(all|-?[0-9]{1,19})&quot;/';
        }

        public static function types_sql( $types )
        {
            return "'" . implode( "','", array_map( 'esc_sql', (array) $types ) ) . "'";
        }

        public function everyone_rows_supported()
        {
            return (float) get_option( 'better_messages_2_db_version', 0 ) >= 2.94;
        }

        public function parse_tokens( $content )
        {
            $ids      = array();
            $everyone = false;

            if ( is_string( $content ) && $content !== '' && preg_match_all( self::token_pattern(), $content, $matches ) ) {
                foreach ( $matches[1] as $value ) {
                    if ( $value === 'all' ) {
                        $everyone = true;
                        continue;
                    }

                    $id = (int) $value;

                    if ( $id !== 0 ) {
                        $ids[] = $id;
                    }
                }
            }

            return array(
                'ids'      => array_values( array_unique( $ids ) ),
                'everyone' => $everyone,
            );
        }

        public function forget_message( $message_id )
        {
            unset( $this->targets[ (int) $message_id ] );
        }

        public function get_message_targets( $message )
        {
            $message_id = isset( $message->id ) ? (int) $message->id : 0;

            if ( $message_id > 0 && isset( $this->targets[ $message_id ] ) ) {
                return $this->targets[ $message_id ];
            }

            $thread_id = isset( $message->thread_id ) ? (int) $message->thread_id : 0;
            $sender_id = isset( $message->sender_id ) ? (int) $message->sender_id : 0;
            $content   = isset( $message->message ) ? (string) $message->message : '';

            $result = array(
                'personal'  => array(),
                'mentioned' => array(),
                'reply'     => 0,
                'everyone'  => false,
            );

            $forwarded = $message_id > 0 && ! empty( Better_Messages()->functions->get_message_meta( $message_id, 'forwarded_from_message', true ) );

            if ( ! $forwarded && strpos( $content, '<!-- BM-DELETED-MESSAGE -->' ) === false ) {
                $tokens = $this->parse_tokens( $content );

                $result['mentioned'] = array_slice( array_values( array_diff( $tokens['ids'], array( $sender_id ) ) ), 0, self::MAX_PERSONAL );
                $result['everyone']  = $tokens['everyone'];

                if ( $message_id > 0 && $thread_id > 0 && ! Better_Messages()->notification_levels->is_one_to_one( $thread_id ) ) {
                    $reply_target = $this->get_reply_target( $message_id, $thread_id, $sender_id );

                    if ( $reply_target !== 0 ) {
                        $result['reply'] = $reply_target;
                    }
                }
            }

            $personal = $result['mentioned'];

            if ( $result['reply'] !== 0 && ! in_array( $result['reply'], $personal, true ) ) {
                $personal[] = $result['reply'];
            }

            $result['personal'] = $personal;

            $result = apply_filters( 'better_messages_message_targets', $result, $message );

            if ( $message_id > 0 ) {
                $this->targets[ $message_id ] = $result;
            }

            return $result;
        }

        private function get_reply_target( $message_id, $thread_id, $sender_id )
        {
            $reply_to = (int) Better_Messages()->functions->get_message_meta( $message_id, 'reply_to', true );

            if ( $reply_to <= 0 ) {
                $reply_to = (int) Better_Messages()->functions->get_message_meta( $message_id, 'ai_response_for', true );
            }

            if ( $reply_to <= 0 ) {
                return 0;
            }

            $replied = Better_Messages()->functions->get_message( $reply_to );

            if ( ! $replied || (int) $replied->thread_id !== (int) $thread_id ) {
                return 0;
            }

            $target = (int) $replied->sender_id;

            if ( $target === 0 || $target === (int) $sender_id || Better_Messages()->functions->is_ai_bot_user( $target ) ) {
                return 0;
            }

            return $target;
        }

        private function not_a_bot_sql()
        {
            return "( `r`.`user_id` > 0 OR NOT EXISTS ( SELECT 1 FROM `" . bm_get_table( 'guests' ) . "` `g` WHERE `g`.`id` = -`r`.`user_id` AND `g`.`bot_id` > 0 ) )";
        }

        private function insert_rows( $thread_id, $message_id, $user_ids, $type, $sender_id, $extra_where = '' )
        {
            global $wpdb;

            $user_ids = array_values( array_filter( array_unique( array_map( 'intval', (array) $user_ids ) ) ) );

            if ( empty( $user_ids ) ) {
                return 0;
            }

            return (int) $wpdb->query( $wpdb->prepare(
                "INSERT INTO `" . bm_get_table( 'mentions' ) . "` (`thread_id`, `message_id`, `user_id`, `type`)
                SELECT `r`.`thread_id`, %d, `r`.`user_id`, %s
                FROM `" . bm_get_table( 'recipients' ) . "` `r`
                WHERE `r`.`thread_id` = %d
                AND `r`.`user_id` IN (" . implode( ',', $user_ids ) . ")
                AND `r`.`user_id` NOT IN (0, %d)
                AND " . $this->not_a_bot_sql() . "
                {$extra_where}",
                $message_id, $type, $thread_id, $sender_id
            ) );
        }

        private function insert_everyone_rows( $thread_id, $message_id, $exclude_ids, $sender_id, $extra_where = '' )
        {
            global $wpdb;

            if ( ! $this->everyone_rows_supported() ) {
                return 0;
            }

            $levels = Better_Messages()->notification_levels;

            if ( $levels->is_thread_locked( $thread_id ) ) {
                return 0;
            }

            $muted = $levels->get_thread_default_level( $thread_id ) === 'none' ? '(2, 3)' : '(0, 2, 3)';

            $exclude = array_values( array_filter( array_unique( array_map( 'intval', array_merge( (array) $exclude_ids, array( $sender_id ) ) ) ) ) );
            $exclude[] = 0;

            return (int) $wpdb->query( $wpdb->prepare(
                "INSERT INTO `" . bm_get_table( 'mentions' ) . "` (`thread_id`, `message_id`, `user_id`, `type`)
                SELECT `r`.`thread_id`, %d, `r`.`user_id`, 'everyone'
                FROM `" . bm_get_table( 'recipients' ) . "` `r`
                WHERE `r`.`thread_id` = %d
                AND `r`.`is_deleted` = 0
                AND `r`.`is_muted` IN {$muted}
                AND `r`.`user_id` NOT IN (" . implode( ',', $exclude ) . ")
                AND " . $this->not_a_bot_sql() . "
                {$extra_where}",
                $message_id, $thread_id
            ) );
        }

        public function process_mentions( $thread_id, $message_id, $str, $is_edit = false )
        {
            global $wpdb;

            $thread_id  = (int) $thread_id;
            $message_id = (int) $message_id;
            $table      = bm_get_table( 'mentions' );
            $types      = self::types_sql( self::NOTIFY_TYPES );

            $message = Better_Messages()->functions->get_message( $message_id );

            if ( ! $message ) {
                return false;
            }

            $this->forget_message( $message_id );

            $before = array();

            if ( $is_edit ) {
                $before = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT `user_id` FROM `{$table}` WHERE `message_id` = %d AND `type` IN ({$types})",
                    $message_id
                ) ) );
            }

            $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE `message_id` = %d AND `type` IN ({$types})", $message_id ) );

            if ( (int) $message->is_pending === 1 || strpos( (string) $str, '<!-- BM-DELETED-MESSAGE -->' ) !== false ) {
                return true;
            }

            $sender_id = (int) $message->sender_id;

            $targets = $this->get_message_targets( (object) array(
                'id'        => $message_id,
                'thread_id' => $thread_id,
                'sender_id' => $sender_id,
                'message'   => (string) $str,
            ) );

            $extra_where = $is_edit ? $wpdb->prepare( " AND `r`.`last_read` < %s", $message->date_sent ) : '';

            if ( ! empty( $targets['mentioned'] ) ) {
                $this->insert_rows( $thread_id, $message_id, $targets['mentioned'], 'mention', $sender_id, $extra_where );
            }

            if ( $targets['reply'] !== 0 && ! in_array( $targets['reply'], $targets['mentioned'], true ) ) {
                $this->insert_rows( $thread_id, $message_id, array( $targets['reply'] ), 'reply', $sender_id, $extra_where );
            }

            if ( $targets['everyone'] ) {
                $this->insert_everyone_rows( $thread_id, $message_id, $targets['personal'], $sender_id, $extra_where );
            }

            if ( $is_edit ) {
                $after = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                    "SELECT `user_id` FROM `{$table}` WHERE `message_id` = %d AND `type` IN ({$types})",
                    $message_id
                ) ) );

                $added = array_slice( array_values( array_diff( $after, $before ) ), 0, self::MAX_PER_THREAD );

                foreach ( $added as $user_id ) {
                    do_action( 'better_messages_thread_self_update', $thread_id, $user_id );
                }
            }

            return true;
        }

        public function clean_mentions_on_thread_read( $thread_id, $user_id )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `" . bm_get_table( 'mentions' ) . "` WHERE `user_id` = %d AND `thread_id` = %d AND `type` IN (" . self::types_sql( self::NOTIFY_TYPES ) . ")",
                $user_id, $thread_id
            ) );
        }

        public function clean_mentions_on_all_read( $user_id )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `" . bm_get_table( 'mentions' ) . "` WHERE `user_id` = %d AND `type` IN (" . self::types_sql( array_merge( self::NOTIFY_TYPES, array( 'reaction' ) ) ) . ")",
                $user_id
            ) );
        }

        public function participant_removed( $thread_id, $user_id )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `" . bm_get_table( 'mentions' ) . "` WHERE `thread_id` = %d AND `user_id` = %d",
                $thread_id, $user_id
            ) );
        }

        public function participants_removed( $thread_id, $user_ids = array() )
        {
            global $wpdb;

            $user_ids = array_values( array_filter( array_map( 'intval', (array) $user_ids ) ) );

            if ( empty( $user_ids ) ) {
                return;
            }

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `" . bm_get_table( 'mentions' ) . "` WHERE `thread_id` = %d AND `user_id` IN (" . implode( ',', $user_ids ) . ")",
                $thread_id
            ) );
        }

        public function thread_erased( $thread_id )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare( "DELETE FROM `" . bm_get_table( 'mentions' ) . "` WHERE `thread_id` = %d", $thread_id ) );
        }

        public function add_mentions_to_update( $data, $user_id, $last_time ){
            global $wpdb;

            if( isset( $data['threads']) && is_array( $data['threads'] ) && count( $data['threads'] ) > 0 ){
                $mentions_map = [];

                foreach( $data['threads'] as $thread_index => $thread ){
                    if( ! is_array( $thread ) || ! array_key_exists( 'mentions', $thread ) ) continue;
                    $mentions_map[ (int) $thread['thread_id'] ] = $thread_index ;
                }

                if( count( $mentions_map ) === 0 ){
                    return $data;
                }

                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT `thread_id`, `message_id`, `type`
                    FROM `" . bm_get_table( 'mentions' ) . "`
                    WHERE `user_id` = %d
                    AND `thread_id` IN (" . implode( ',', array_map( 'intval', array_keys( $mentions_map ) ) ) . ")
                    AND `type` IN (" . self::types_sql( self::NOTIFY_TYPES ) . ")
                    ORDER BY `message_id` DESC",
                    $user_id
                ), ARRAY_A );

                $per_thread = array();

                foreach ( (array) $rows as $row ) {
                    $thread_id = (int) $row['thread_id'];

                    if ( ! isset( $per_thread[ $thread_id ] ) ) {
                        $per_thread[ $thread_id ] = array();
                    }

                    if ( count( $per_thread[ $thread_id ] ) >= self::MAX_PER_THREAD ) {
                        continue;
                    }

                    $per_thread[ $thread_id ][] = array(
                        'message_id' => (int) $row['message_id'],
                        'type'       => $row['type'],
                        'seen'       => false,
                    );
                }

                foreach ( $per_thread as $thread_id => $mentions ) {
                    $data['threads'][ $mentions_map[ $thread_id ] ]['mentions'] = array_reverse( $mentions );
                }
            }

            return $data;
        }

        public function message_deleted( $message_id ){
            global $wpdb;

            $this->forget_message( $message_id );

            $wpdb->query( $wpdb->prepare( "DELETE FROM " . bm_get_table('mentions') . " WHERE message_id = %d", $message_id ) );
        }

        public function get_mentions_for_message( $thread_id, $message_id ){
            global $wpdb;

            $return = [];
            $mentions =  $wpdb->get_results($wpdb->prepare("
            SELECT user_id, type
            FROM `" . bm_get_table('mentions') . "`
            WHERE `thread_id`  = %d
            AND   `message_id` = %d
            AND   `type` IN (" . self::types_sql( self::NOTIFY_TYPES ) . ")
            ", $thread_id, $message_id ), ARRAY_A);

            if( count( $mentions ) > 0 ){
                foreach ( $mentions as $mention ){
                    $user_id = intval($mention['user_id']);
                    if( ! isset($return[ $user_id ]) ) {
                        $return[ $user_id ] = [];
                    }

                    $return[ $user_id ][] = $mention['type'];
                }
            }

            return $return;
        }
    }

    function Better_Messages_Mentions(){
        return Better_Messages_Mentions::instance();
    }
}
