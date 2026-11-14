<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Anti_Spam' ) ):

    class Better_Messages_Anti_Spam
    {
        const PLUGIN = 'better-messages';

        const LEVELS = array( 'off', 'monitor', 'balanced', 'strict', 'custom' );

        const TRUST_CACHE = HOUR_IN_SECONDS;

        const REVIEW_META = 'bm_spam_review';

        const HIDDEN_META = 'bm_spam_hidden';

        private $decisions = array();

        private $marks = array();

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Anti_Spam();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'init', array( $this, 'register' ), 5 );
        }

        public function register()
        {
            add_filter( 'better_messages_can_send_message', array( $this, 'suspended_cannot_send' ), 30, 3 );
            add_filter( 'better_messages_can_send_message', array( $this, 'guest_cannot_send' ), 31, 3 );
            add_action( 'better_messages_before_new_thread', array( $this, 'suspended_cannot_act' ), 4, 2 );
            add_action( 'better_messages_before_add_participants', array( $this, 'suspended_cannot_act' ), 4, 2 );
            add_filter( 'better_messages_call_create_custom_error', array( $this, 'suspended_cannot_call' ), 4, 1 );
            add_filter( 'better_messages_private_call_allowed_error', array( $this, 'suspended_cannot_call' ), 4, 1 );
            add_filter( 'better_messages_user_can_connect', array( $this, 'suspended_cannot_connect' ), 10, 2 );

            if ( ! $this->enabled() ) {
                return;
            }

            add_action( 'better_messages_before_new_thread', array( $this, 'before_new_thread' ), 50, 2 );
            add_action( 'better_messages_before_message_send', array( $this, 'before_message_send' ), 50, 2 );
            add_action( 'better_messages_before_message_edit', array( $this, 'before_message_edit' ), 50, 2 );
            add_action( 'better_messages_before_subject_change', array( $this, 'before_subject_change' ), 50, 2 );
            add_action( 'better_messages_before_add_participants', array( $this, 'before_add_participants' ), 50, 2 );
            add_filter( 'better_messages_call_create_custom_error', array( $this, 'before_call' ), 50, 2 );
            add_filter( 'better_messages_private_call_allowed_error', array( $this, 'before_call' ), 50, 2 );

            add_action( 'better_messages_message_sent', array( $this, 'message_saved' ), 5 );
            add_action( 'better_messages_message_pending', array( $this, 'message_saved' ), 5 );

            add_action( 'better_messages_message_reported', array( $this, 'message_reported' ), 10, 6 );
            add_action( 'better_messages_user_blocked', array( $this, 'user_blocked' ), 10, 2 );

            add_filter( 'better_messages_is_moderation_enabled', array( $this, 'hold_under_review' ), 20, 2 );
        }

        public function hold_under_review( $enabled, $user_id )
        {
            if ( $enabled || $this->level() === 'monitor' || ! $this->enforcing() ) {
                return $enabled;
            }

            return $this->is_under_review( (int) $user_id ) ? true : $enabled;
        }

        public function engine()
        {
            return function_exists( 'wordplus_spam' ) ? wordplus_spam() : null;
        }

        public function level()
        {
            $level = (string) Better_Messages()->settings['spamLevel'];

            return in_array( $level, self::LEVELS, true ) ? $level : 'monitor';
        }

        public function enabled()
        {
            return $this->level() !== 'off' && (bool) $this->engine();
        }

        public function enforcing()
        {
            return (bool) apply_filters( 'better_messages_spam_enforce', in_array( $this->level(), array( 'balanced', 'strict', 'custom' ), true ) );
        }

        const CUSTOM_CODES = array( 'new_contacts_hour', 'new_contacts_day', 'starts_hour', 'same_text_scopes', 'same_text_actors', 'speed', 'calls', 'links', 'first_contact' );

        const CUSTOM_OUTCOMES = array( 'off', 'flag', 'hold', 'refuse' );

        public function rules( $level = null )
        {
            $level = null === $level ? $this->level() : $level;

            if ( $level === 'off' ) {
                return array();
            }

            $rules = $this->table( $level === 'strict' );

            if ( $level === 'custom' ) {
                $rules = $this->customize( $rules );
            }

            $rule = function ( $limit, $window, $outcome ) {
                return array( 'limit' => $limit, 'window' => $window, 'outcome' => $outcome );
            };

            $details = (string) Better_Messages()->settings['spamContactDetails'];

            if ( in_array( $details, array( 'mask', 'hold', 'refuse' ), true ) ) {
                $rules['details'] = array(
                    'new'         => $rule( 0, HOUR_IN_SECONDS, $details ),
                    'established' => $rule( 0, HOUR_IN_SECONDS, $details ),
                );
            }

            $monitor = $level === 'monitor' || ! $this->enforcing();

            foreach ( $rules as $code => $levels ) {
                if ( $code === 'strikes' ) {
                    $rules[ $code ]['monitor'] = $monitor;
                    continue;
                }

                foreach ( $levels as $trust => $spec ) {
                    $rules[ $code ][ $trust ]['monitor'] = $monitor;
                }
            }

            return apply_filters( 'better_messages_spam_rules', $rules, $level );
        }

        public function table( $strict = false )
        {
            $rule = function ( $limit, $window, $outcome ) {
                return array( 'limit' => $limit, 'window' => $window, 'outcome' => $outcome );
            };

            $rules = array(
                'new_contacts_hour' => array(
                    'new'         => $rule( $strict ? 5 : 10, HOUR_IN_SECONDS, 'hold' ),
                    'established' => $rule( $strict ? 20 : 40, HOUR_IN_SECONDS, 'hold' ),
                ),
                'new_contacts_day'  => array(
                    'new'         => $rule( $strict ? 15 : 30, DAY_IN_SECONDS, 'hold' ),
                    'established' => $rule( $strict ? 60 : 150, DAY_IN_SECONDS, 'hold' ),
                ),
                'starts_hour'       => array(
                    'new'         => $rule( $strict ? 5 : 10, HOUR_IN_SECONDS, 'refuse' ),
                    'established' => $rule( $strict ? 15 : 30, HOUR_IN_SECONDS, 'refuse' ),
                ),
                'same_text_scopes'  => array(
                    'new'         => $rule( $strict ? 2 : 3, HOUR_IN_SECONDS, 'hold' ),
                    'established' => $rule( $strict ? 3 : 5, HOUR_IN_SECONDS, $strict ? 'hold' : 'flag' ),
                ),
                'same_text_actors'  => array(
                    'new' => $rule( $strict ? 2 : 3, DAY_IN_SECONDS, 'hold' ),
                ),
                'speed'             => array(
                    'new'         => $rule( $strict ? 5 : 10, MINUTE_IN_SECONDS, 'refuse' ),
                    'established' => $rule( $strict ? 15 : 30, MINUTE_IN_SECONDS, 'refuse' ),
                ),
                'calls'             => array(
                    'new'         => $rule( $strict ? 3 : 5, 10 * MINUTE_IN_SECONDS, 'refuse' ),
                    'established' => $rule( $strict ? 8 : 15, 10 * MINUTE_IN_SECONDS, 'refuse' ),
                ),
                'links'             => array(
                    'new' => $rule( 0, HOUR_IN_SECONDS, 'hold' ),
                ),
                'strikes'           => array(
                    'limit'  => $strict ? 2 : 3,
                    'window' => DAY_IN_SECONDS,
                ),
            );

            if ( $strict ) {
                $rules['links']['established'] = $rule( 0, HOUR_IN_SECONDS, 'flag' );
                $rules['first_contact']        = array( 'new' => $rule( 0, HOUR_IN_SECONDS, 'hold' ) );
            }

            return $rules;
        }

        public function custom_overrides()
        {
            $overrides = Better_Messages()->settings['spamRules'] ?? array();

            return is_array( $overrides ) ? $overrides : array();
        }

        private function customize( array $rules )
        {
            $windows = array(
                'new_contacts_hour' => HOUR_IN_SECONDS,
                'new_contacts_day'  => DAY_IN_SECONDS,
                'starts_hour'       => HOUR_IN_SECONDS,
                'same_text_scopes'  => HOUR_IN_SECONDS,
                'same_text_actors'  => DAY_IN_SECONDS,
                'speed'             => MINUTE_IN_SECONDS,
                'calls'             => 10 * MINUTE_IN_SECONDS,
                'links'             => HOUR_IN_SECONDS,
                'first_contact'     => HOUR_IN_SECONDS,
            );

            foreach ( $this->custom_overrides() as $code => $trusts ) {
                if ( $code === 'strikes' ) {
                    if ( isset( $trusts['limit'] ) ) {
                        $rules['strikes']['limit'] = max( 1, (int) $trusts['limit'] );
                    }
                    continue;
                }

                if ( ! in_array( $code, self::CUSTOM_CODES, true ) || ! is_array( $trusts ) ) {
                    continue;
                }

                foreach ( array( 'new', 'established' ) as $trust ) {
                    if ( ! isset( $trusts[ $trust ] ) || ! is_array( $trusts[ $trust ] ) ) {
                        continue;
                    }

                    if ( $trust === 'established' && in_array( $code, array( 'same_text_actors', 'first_contact' ), true ) ) {
                        continue;
                    }

                    $outcome = (string) ( $trusts[ $trust ]['outcome'] ?? '' );

                    if ( ! in_array( $outcome, self::CUSTOM_OUTCOMES, true ) ) {
                        continue;
                    }

                    if ( $outcome === 'off' ) {
                        unset( $rules[ $code ][ $trust ] );
                        continue;
                    }

                    $rules[ $code ][ $trust ] = array(
                        'limit'   => max( 0, (int) ( $trusts[ $trust ]['limit'] ?? 0 ) ),
                        'window'  => $windows[ $code ],
                        'outcome' => $outcome,
                    );
                }

                if ( isset( $rules[ $code ] ) && empty( $rules[ $code ] ) ) {
                    unset( $rules[ $code ] );
                }
            }

            return $rules;
        }

        public function reaction_limits( $level = null )
        {
            $level  = null === $level ? $this->level() : $level;
            $strict = $level === 'strict';

            $limits = array(
                'reported' => array( 'limit' => $strict ? 1 : 2, 'window' => 30 * DAY_IN_SECONDS ),
                'blocked'  => array( 'limit' => $strict ? 2 : 3, 'window' => DAY_IN_SECONDS ),
                'declined' => array( 'limit' => $strict ? 2 : 3, 'window' => DAY_IN_SECONDS ),
            );

            if ( $level === 'custom' ) {
                foreach ( $this->custom_overrides() as $code => $override ) {
                    if ( isset( $limits[ $code ] ) && isset( $override['limit'] ) ) {
                        $limits[ $code ]['limit'] = max( 1, (int) $override['limit'] );
                    }
                }
            }

            return $limits;
        }

        public function rule_tables()
        {
            return array(
                'balanced' => array( 'rules' => $this->table( false ), 'reactions' => $this->reaction_limits( 'balanced' ) ),
                'strict'   => array( 'rules' => $this->table( true ), 'reactions' => $this->reaction_limits( 'strict' ) ),
            );
        }

        public function trust( $user_id )
        {
            $user_id = (int) $user_id;

            if ( $user_id === 0 ) {
                return 'new';
            }

            if ( $user_id > 0 && user_can( $user_id, 'bm_can_administrate' ) ) {
                return 'trusted';
            }

            if ( $user_id < 0 && Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                return 'trusted';
            }

            if ( Better_Messages()->moderation->is_user_whitelisted( $user_id ) ) {
                return 'trusted';
            }

            $cached = Better_Messages()->functions->get_user_meta( $user_id, 'bm_spam_trust', true );

            if ( is_array( $cached ) && isset( $cached['level'], $cached['at'] ) && (int) $cached['at'] > time() - self::TRUST_CACHE && in_array( $cached['level'], array( 'new', 'established' ), true ) ) {
                return $cached['level'];
            }

            $level = $this->is_new( $user_id ) ? 'new' : 'established';

            Better_Messages()->functions->update_user_meta( $user_id, 'bm_spam_trust', array( 'level' => $level, 'at' => time() ) );

            return $level;
        }

        private function is_new( $user_id )
        {
            global $wpdb;

            $days     = max( 0, (int) Better_Messages()->settings['spamNewDays'] );
            $messages = max( 0, (int) Better_Messages()->settings['spamNewMessages'] );

            if ( $days > 0 ) {
                if ( $user_id > 0 ) {
                    $user       = get_userdata( $user_id );
                    $registered = $user ? strtotime( $user->user_registered . ' UTC' ) : 0;
                } else {
                    $registered = (int) strtotime( (string) $wpdb->get_var( $wpdb->prepare( "SELECT created_at FROM " . bm_get_table( 'guests' ) . " WHERE id = %d", abs( $user_id ) ) ) . ' UTC' );
                }

                if ( ! $registered || $registered > time() - $days * DAY_IN_SECONDS ) {
                    return true;
                }
            }

            if ( $messages > 0 ) {
                $delivered = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM ( SELECT 1 FROM " . bm_get_table( 'messages' ) . " WHERE sender_id = %d AND is_pending = 0 LIMIT %d ) delivered",
                    $user_id,
                    $messages
                ) );

                if ( $delivered < $messages ) {
                    return true;
                }
            }

            return false;
        }

        public function new_contacts( $user_id, array $user_ids )
        {
            global $wpdb;

            $ids = Better_Messages()->functions->new_participant_ids( $user_ids, array( $user_id ) );

            if ( empty( $ids ) ) {
                return array();
            }

            $table   = bm_get_table( 'recipients' );
            $threads = bm_get_table( 'threads' );
            $holder  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $known   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT `theirs`.`user_id`
                FROM `{$table}` `mine`
                INNER JOIN `{$threads}` `conversation` ON `conversation`.`id` = `mine`.`thread_id` AND `conversation`.`type` IN ('thread', 'booking')
                INNER JOIN `{$table}` `theirs` ON `theirs`.`thread_id` = `mine`.`thread_id`
                WHERE `mine`.`user_id` = %d AND `theirs`.`user_id` IN ({$holder})",
                array_merge( array( (int) $user_id ), $ids )
            ) ) );

            return array_values( array_diff( $ids, $known ) );
        }

        private function readable( $content )
        {
            $content = (string) $content;

            if ( $content === '' || strpos( $content, '<!-- BM-' ) === 0 ) {
                return '';
            }

            if ( class_exists( 'Better_Messages_E2E_Encryption' ) && Better_Messages_E2E_Encryption::is_valid_e2e_ciphertext( $content ) ) {
                return '';
            }

            return $content;
        }

        private function signals( array $args, array $errors )
        {
            $signals = array();

            if ( isset( $errors['restrictBadWord'] ) ) {
                $signals['blocked_words'] = 'refuse';
            }

            $ai = isset( $args['ai_moderation_result'] ) && is_array( $args['ai_moderation_result'] ) ? $args['ai_moderation_result'] : array();

            if ( ! empty( $ai['flagged'] ) && array_intersect( (array) ( $ai['flagged_categories'] ?? array() ), array( 'spam', 'scam_phishing' ) ) ) {
                $signals['ai_spam'] = 'hold';
            }

            if ( ! $this->enforcing() || $this->level() === 'monitor' ) {
                foreach ( $signals as $code => $outcome ) {
                    $signals[ $code ] = array( 'outcome' => $outcome, 'monitor' => true );
                }
            }

            return $signals;
        }

        public function check( $action, $scope, $content, array $reached, array $args, array $errors, $record = true )
        {
            $engine = $this->engine();
            $actor  = (int) Better_Messages()->functions->get_current_user_id();

            if ( ! $engine || $actor === 0 ) {
                return null;
            }

            $trust = $this->trust( $actor );

            if ( $trust === 'trusted' ) {
                return null;
            }

            $html = $this->readable( $content );
            $text = $html === '' ? '' : $engine->text( $html );

            $allowed = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) Better_Messages()->settings['spamLinksAllowed'] ) ) );

            $decision = $engine->check( array(
                'plugin'      => self::PLUGIN,
                'actor'       => $actor,
                'action'      => $action,
                'trust'       => $trust,
                'scope'       => (string) $scope,
                'contacts'    => count( $reached ),
                'fingerprint' => in_array( $action, array( 'start', 'send' ), true ) && $text !== '' ? $engine->fingerprint( $text ) : '',
                'links'       => $html === '' ? 0 : count( $engine->links( $html, $allowed ) ),
                'details'     => $text === '' ? array() : $engine->contacts( $text ),
                'signals'     => $this->signals( $args, $errors ),
                'record'      => $record,
            ), $this->rules() );

            $this->decisions[] = $decision;

            do_action( 'better_messages_spam_checked', $decision, $action, $actor, $scope );

            return $decision;
        }

        public function last_decision()
        {
            return empty( $this->decisions ) ? null : end( $this->decisions );
        }

        private function apply( $decision, $action, &$args, &$errors )
        {
            if ( ! is_array( $decision ) ) {
                return;
            }

            if ( ! is_array( $errors ) ) {
                $errors = array();
            }

            $actor = (int) Better_Messages()->functions->get_current_user_id();

            if ( ! empty( $decision['review'] ) ) {
                $this->put_under_review( $actor, $this->enforced_rules( $decision ) );
            }

            foreach ( $decision['reasons'] as $reason ) {
                if ( empty( $reason['monitor'] ) && ! empty( $reason['actors'] ) ) {
                    foreach ( (array) $reason['actors'] as $other ) {
                        if ( (int) $other !== $actor ) {
                            $this->put_under_review( (int) $other, array( $reason['rule'] ) );
                        }
                    }
                }
            }

            if ( ! empty( $decision['mask'] ) && isset( $args['content'] ) && in_array( $action, array( 'start', 'send', 'edit' ), true ) ) {
                $args['content'] = $this->engine()->mask( (string) $args['content'] );
            }

            if ( $decision['outcome'] === 'refuse' || ( $decision['outcome'] === 'hold' && ! in_array( $action, array( 'start', 'send' ), true ) ) ) {
                $errors['spamProtection'] = $this->words( $decision );
                return;
            }

            if ( $decision['outcome'] === 'hold' ) {
                $args['is_pending'] = 1;
            }

            if ( in_array( $decision['outcome'], array( 'hold', 'flag' ), true ) && in_array( $action, array( 'start', 'send' ), true ) && isset( $args['content'] ) && '' !== trim( (string) $args['content'] ) ) {
                $this->marks[] = array( 'outcome' => $decision['outcome'], 'rules' => $this->enforced_rules( $decision ) );
            }
        }

        private function enforced_rules( $decision )
        {
            $rules = array();

            foreach ( (array) $decision['reasons'] as $reason ) {
                if ( empty( $reason['monitor'] ) ) {
                    $rules[] = (string) $reason['rule'];
                }
            }

            return array_values( array_unique( $rules ) );
        }

        public function words( $decision )
        {
            $reason = null;

            foreach ( (array) $decision['reasons'] as $candidate ) {
                if ( empty( $candidate['monitor'] ) && in_array( $candidate['outcome'], array( 'refuse', 'hold' ), true ) ) {
                    $reason = $candidate;
                    break;
                }
            }

            $wait = $reason && ! empty( $reason['retry_after'] ) ? human_time_diff( time(), time() + (int) $reason['retry_after'] ) : '';

            switch ( $reason ? $reason['rule'] : '' ) {
                case 'starts_hour':
                case 'starts_day':
                    $text = $wait !== ''
                        ? sprintf( _x( 'You have started many conversations in a short time. You can start a new one in %s.', 'Spam Protection', 'bp-better-messages' ), $wait )
                        : _x( 'You have started many conversations in a short time. Please try again later.', 'Spam Protection', 'bp-better-messages' );
                    break;
                case 'speed':
                    $text = $wait !== ''
                        ? sprintf( _x( 'You are sending messages too fast. You can send again in %s.', 'Spam Protection', 'bp-better-messages' ), $wait )
                        : _x( 'You are sending messages too fast. Please try again later.', 'Spam Protection', 'bp-better-messages' );
                    break;
                case 'calls':
                    $text = $wait !== ''
                        ? sprintf( _x( 'You have started many calls in a short time. You can call again in %s.', 'Spam Protection', 'bp-better-messages' ), $wait )
                        : _x( 'You have started many calls in a short time. Please try again later.', 'Spam Protection', 'bp-better-messages' );
                    break;
                case 'new_contacts_hour':
                case 'new_contacts_day':
                case 'first_contact':
                    $text = _x( 'You have written to many new people in a short time. Please try again later.', 'Spam Protection', 'bp-better-messages' );
                    break;
                case 'links':
                    $text = _x( 'Links to other sites cannot be sent here.', 'Spam Protection', 'bp-better-messages' );
                    break;
                case 'details':
                    $text = _x( 'Contact details cannot be shared here.', 'Spam Protection', 'bp-better-messages' );
                    break;
                case 'same_text_scopes':
                case 'same_text_actors':
                    $text = _x( 'This message was already sent to many conversations.', 'Spam Protection', 'bp-better-messages' );
                    break;
                default:
                    $text = _x( 'This cannot be sent right now. Please try again later.', 'Spam Protection', 'bp-better-messages' );
            }

            return apply_filters( 'better_messages_spam_refusal', $text, $decision, $reason );
        }

        public function guest_limits()
        {
            $settings = Better_Messages()->settings;

            return (array) apply_filters( 'better_messages_guest_signup_limits', array(
                'site'    => max( 0, (int) ( $settings['spamGuestsPerHour'] ?? 0 ) ),
                'address' => max( 0, (int) ( $settings['spamGuestsPerAddress'] ?? 0 ) ),
                'window'  => HOUR_IN_SECONDS,
            ) );
        }

        private function address_actor( $ip )
        {
            return -3000000000 - (int) hexdec( substr( hash_hmac( 'sha256', (string) $ip, wp_salt( 'nonce' ) ), 0, 7 ) );
        }

        public function guest_signup_allowed()
        {
            $engine = $this->engine();

            if ( ! $engine || $this->level() === 'off' ) {
                return true;
            }

            $limits = $this->guest_limits();
            $since  = time() - (int) $limits['window'];
            $ip     = (string) Better_Messages()->guests->get_client_ip();
            $over   = '';

            if ( (int) $limits['site'] > 0 && (int) $engine->store()->count( self::PLUGIN, 0, array( 'guest_signup' ), $since ) >= (int) $limits['site'] ) {
                $over = 'site';
            } elseif ( $ip !== '' && (int) $limits['address'] > 0 && (int) $engine->store()->count( self::PLUGIN, $this->address_actor( $ip ), array( 'guest_signup_address' ), $since ) >= (int) $limits['address'] ) {
                $over = 'address';
            }

            if ( $over !== '' ) {
                do_action( 'better_messages_guest_signup_limited', $over, $this->enforcing() );

                if ( $this->level() !== 'monitor' && $this->enforcing() ) {
                    return new WP_Error( 'bm_guest_signup_limited', _x( 'Too many new guests right now. Please try again later.', 'Spam Protection', 'bp-better-messages' ), array( 'status' => 429 ) );
                }
            }

            return true;
        }

        public function guest_signed_up()
        {
            $engine = $this->engine();

            if ( ! $engine || $this->level() === 'off' ) {
                return;
            }

            $ip = (string) Better_Messages()->guests->get_client_ip();

            $engine->note( self::PLUGIN, 0, 'guest_signup' );

            if ( $ip !== '' ) {
                $engine->note( self::PLUGIN, $this->address_actor( $ip ), 'guest_signup_address' );
            }
        }

        public function rule_words( $rule )
        {
            switch ( (string) $rule ) {
                case 'new_contacts_hour': return _x( 'Wrote to many people they had never talked to, within an hour', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'new_contacts_day':  return _x( 'Wrote to many people they had never talked to, within a day', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'starts_hour':       return _x( 'Started many conversations within an hour', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'starts_day':        return _x( 'Started many conversations within a day', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'same_text_scopes':  return _x( 'Sent the same text to many conversations', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'same_text_actors':  return _x( 'Sent the same text as other new accounts', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'speed':             return _x( 'Sent messages very fast', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'calls':             return _x( 'Started many calls in a short time', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'links':             return _x( 'Sent links to other sites', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'details':           return _x( 'Shared contact details', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'first_contact':     return _x( 'Wrote first to someone new, as a new account', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'blocked_words':     return _x( 'Used words from the blocked list', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'ai_spam':           return _x( 'AI moderation found spam or a scam', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'reports':           return _x( 'Reported by members', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'blocks':            return _x( 'Blocked by several members', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'declines':          return _x( 'Several of its message requests were declined', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'spammer':           return _x( 'Marked as a spammer', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                case 'moderator':         return _x( 'Suspended by a moderator', 'Spam Protection (WP Admin)', 'bp-better-messages' );
                default:                  return (string) $rule;
            }
        }

        public function message_rules( $message_id )
        {
            $meta = Better_Messages()->functions->get_message_meta( (int) $message_id, 'bm_spam', true );

            return is_array( $meta ) && ! empty( $meta['rules'] ) ? array_values( array_map( 'strval', (array) $meta['rules'] ) ) : array();
        }

        public function review_rules( $user_id )
        {
            $meta = Better_Messages()->functions->get_user_meta( (int) $user_id, self::REVIEW_META, true );

            return is_array( $meta ) && ! empty( $meta['rules'] ) ? array_values( array_map( 'strval', (array) $meta['rules'] ) ) : array();
        }

        public function is_under_review( $user_id )
        {
            return Better_Messages()->moderation->is_user_blacklisted( (int) $user_id );
        }

        public function put_under_review( $user_id, array $rules = array() )
        {
            $user_id = (int) $user_id;

            if ( $user_id === 0 || $this->trust( $user_id ) === 'trusted' || $this->is_under_review( $user_id ) ) {
                return false;
            }

            if ( ! Better_Messages()->moderation->blacklist_user( $user_id, null, null, 0 ) ) {
                return false;
            }

            Better_Messages()->functions->update_user_meta( $user_id, self::REVIEW_META, array(
                'at'    => time(),
                'rules' => array_values( array_unique( array_map( 'strval', $rules ) ) ),
            ) );

            do_action( 'better_messages_account_state_changed', $user_id, 'review', $rules );

            return true;
        }

        public function can_suspend()
        {
            return (float) get_option( 'better_messages_2_db_version', 0 ) >= 3.02;
        }

        public function is_suspended( $user_id )
        {
            global $wpdb;

            $user_id = (int) $user_id;

            if ( $user_id === 0 ) {
                return false;
            }

            $cached = wp_cache_get( 'bm_suspended_' . $user_id, 'bm_messages' );

            if ( $cached !== false ) {
                return $cached === 'yes';
            }

            $suspended = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM " . bm_get_table( 'moderation' ) . " WHERE `user_id` = %d AND `thread_id` = 0 AND `type` = 'suspend' AND ( `expiration` IS NULL OR `expiration` > NOW() )",
                $user_id
            ) );

            wp_cache_set( 'bm_suspended_' . $user_id, $suspended ? 'yes' : 'no', 'bm_messages' );

            return $suspended;
        }

        public function suspend( $user_id, array $rules = array(), $admin_id = null )
        {
            global $wpdb;

            $user_id = (int) $user_id;

            if ( $user_id === 0 || ! $this->can_suspend() || $this->trust( $user_id ) === 'trusted' ) {
                return false;
            }

            $admin_id = null === $admin_id ? (int) Better_Messages()->functions->get_current_user_id() : (int) $admin_id;

            $wpdb->query( $wpdb->prepare(
                "INSERT INTO " . bm_get_table( 'moderation' ) . " (user_id, thread_id, type, expiration, admin_id) VALUES (%d, 0, 'suspend', NULL, %d) ON DUPLICATE KEY UPDATE expiration = NULL, admin_id = %d",
                $user_id,
                $admin_id,
                $admin_id
            ) );

            wp_cache_delete( 'bm_suspended_' . $user_id, 'bm_messages' );

            Better_Messages()->functions->update_user_meta( $user_id, 'bm_spam_suspended', array(
                'at'    => time(),
                'by'    => $admin_id,
                'rules' => array_values( array_unique( array_map( 'strval', $rules ) ) ),
            ) );

            do_action( 'better_messages_revoke_user', $user_id );
            do_action( 'better_messages_account_state_changed', $user_id, 'suspended', $rules );

            return true;
        }

        public function unsuspend( $user_id )
        {
            global $wpdb;

            $user_id = (int) $user_id;

            if ( ! $this->can_suspend() ) {
                return false;
            }

            $wpdb->query( $wpdb->prepare( "DELETE FROM " . bm_get_table( 'moderation' ) . " WHERE `user_id` = %d AND `thread_id` = 0 AND `type` = 'suspend'", $user_id ) );

            wp_cache_delete( 'bm_suspended_' . $user_id, 'bm_messages' );
            Better_Messages()->functions->delete_user_meta( $user_id, 'bm_spam_suspended' );

            do_action( 'better_messages_restore_user', $user_id );
            do_action( 'better_messages_account_state_changed', $user_id, 'normal', array() );

            return true;
        }

        public function suspended_words()
        {
            return _x( 'Your account cannot send messages right now. A moderator suspended it.', 'Spam Protection', 'bp-better-messages' );
        }

        public function suspended_cannot_send( $allowed, $user_id, $thread_id )
        {
            if ( $allowed && $this->is_suspended( $user_id ) ) {
                global $bp_better_messages_restrict_send_message;
                $bp_better_messages_restrict_send_message['spamSuspended'] = $this->suspended_words();

                return false;
            }

            return $allowed;
        }

        public function suspended_cannot_act( &$args, &$errors )
        {
            $actor = (int) Better_Messages()->functions->get_current_user_id();

            if ( $this->is_suspended( $actor ) ) {
                if ( ! is_array( $errors ) ) {
                    $errors = array();
                }

                $errors['spamSuspended'] = $this->suspended_words();
            }

            if ( $this->guest_shut_out( $actor ) ) {
                if ( ! is_array( $errors ) ) {
                    $errors = array();
                }

                $errors['guestChatOff'] = $this->guest_shut_out_words();
            }
        }

        public function guest_shut_out( $user_id, $thread_id = 0 )
        {
            $user_id = (int) $user_id;

            if ( $user_id >= 0 || Better_Messages()->guests->guest_access_enabled() || Better_Messages()->functions->is_ai_bot_user( $user_id ) ) {
                return false;
            }

            if ( (int) $thread_id > 0 && in_array( Better_Messages()->functions->get_thread_type( (int) $thread_id ), array( 'chat-room', 'booking' ), true ) ) {
                return false;
            }

            if ( (int) $thread_id > 0 && class_exists( 'Better_Messages_Bookings' ) && '' !== (string) Better_Messages()->functions->get_thread_meta( (int) $thread_id, Better_Messages_Bookings::META_KEY ) ) {
                return false;
            }

            return (bool) apply_filters( 'better_messages_guest_shut_out', true, $user_id, (int) $thread_id );
        }

        public function guest_shut_out_words()
        {
            return _x( 'Guests cannot send messages here right now.', 'Spam Protection', 'bp-better-messages' );
        }

        public function guest_cannot_send( $allowed, $user_id, $thread_id )
        {
            if ( $allowed && $this->guest_shut_out( $user_id, $thread_id ) ) {
                global $bp_better_messages_restrict_send_message;
                $bp_better_messages_restrict_send_message['guestChatOff'] = $this->guest_shut_out_words();

                return false;
            }

            return $allowed;
        }

        public function suspended_cannot_call( $error )
        {
            if ( empty( $error ) && $this->is_suspended( Better_Messages()->functions->get_current_user_id() ) ) {
                return $this->suspended_words();
            }

            return $error;
        }

        public function suspended_cannot_connect( $can, $user_id )
        {
            return $can && $this->is_suspended( $user_id ) ? false : $can;
        }

        public function message_saved( $message )
        {
            if ( empty( $this->marks ) || ! is_object( $message ) || empty( $message->id ) ) {
                return;
            }

            if ( (int) $message->sender_id !== (int) Better_Messages()->functions->get_current_user_id() ) {
                return;
            }

            $mark = array_shift( $this->marks );

            Better_Messages()->functions->update_message_meta( (int) $message->id, 'bm_spam', array(
                'outcome' => $mark['outcome'],
                'rules'   => $mark['rules'],
                'at'      => time(),
            ) );

            if ( $mark['outcome'] === 'hold' ) {
                do_action( 'better_messages_message_held', $message, $mark['rules'] );
            }
        }

        public function message_reported( $message_id, $thread_id, $reporter_id, $category = '', $description = '', $reports = array() )
        {
            $message = Better_Messages()->functions->get_message( (int) $message_id );

            if ( ! $message || $this->trust( (int) $reporter_id ) === 'new' ) {
                return;
            }

            $this->reaction( (int) $message->sender_id, 'reported', (int) $reporter_id );

            if ( $this->level() === 'monitor' || ! $this->enforcing() || (int) $message->is_pending !== 0 || (int) $message->sender_id === 0 ) {
                return;
            }

            if ( $this->trust( (int) $message->sender_id ) === 'trusted' ) {
                return;
            }

            $reporters = array();

            foreach ( array_keys( (array) $reports ) as $id ) {
                if ( (int) $id !== 0 && (int) $id !== (int) $message->sender_id && $this->trust( (int) $id ) !== 'new' ) {
                    $reporters[] = (int) $id;
                }
            }

            $limits = $this->reaction_limits();

            if ( count( $reporters ) >= $limits['reported']['limit'] ) {
                $this->hide_message( $message, $reporters );
            }
        }

        public function hide_message( $message, array $reporters = array() )
        {
            global $wpdb;

            $id        = (int) $message->id;
            $thread_id = (int) $message->thread_id;

            $wpdb->update( bm_get_table( 'messages' ), array( 'is_pending' => 1 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );

            $wpdb->query( $wpdb->prepare(
                "UPDATE `" . bm_get_table( 'recipients' ) . "`
                SET unread_count = IF( unread_count < 0, unread_count, GREATEST( unread_count - 1, 0 ) ), last_update = %d
                WHERE `thread_id` = %d AND `user_id` <> %d AND `last_read` < ( SELECT date_sent FROM `" . bm_get_table( 'messages' ) . "` WHERE `id` = %d )",
                Better_Messages()->functions->get_microtime(),
                $thread_id,
                (int) $message->sender_id,
                $id
            ) );

            Better_Messages()->functions->update_message_meta( $id, self::HIDDEN_META, array( 'at' => time(), 'by' => array_values( $reporters ) ) );
            Better_Messages()->functions->update_message_update_time( $id, false, true );

            if ( function_exists( 'Better_Messages_WebSocket' ) ) {
                Better_Messages_WebSocket()->on_message_deleted( $id );
            }

            do_action( 'better_messages_thread_updated', $thread_id );

            $message->is_pending = 1;

            do_action( 'better_messages_message_held', $message, array( 'reports' ) );

            return true;
        }

        public function pending_meta( array $meta, $message_id, $sender_id )
        {
            if ( ! $this->is_hidden( $message_id ) && $this->is_under_review( $sender_id ) && $this->review_rules( $sender_id ) ) {
                $meta['heldFor'] = 'review';
            }

            return $meta;
        }

        public function is_hidden( $message_id )
        {
            return is_array( Better_Messages()->functions->get_message_meta( (int) $message_id, self::HIDDEN_META, true ) );
        }

        public function restore_message( $message_id )
        {
            global $wpdb;

            $message_id = (int) $message_id;

            if ( ! $this->is_hidden( $message_id ) ) {
                return false;
            }

            $wpdb->update( bm_get_table( 'messages' ), array( 'is_pending' => 0 ), array( 'id' => $message_id ), array( '%d' ), array( '%d' ) );

            Better_Messages()->functions->delete_message_meta( $message_id, self::HIDDEN_META );
            Better_Messages()->functions->delete_message_meta( $message_id, 'bm_deleted_time' );
            Better_Messages()->functions->update_message_update_time( $message_id );

            $message = new BM_Messages_Message( $message_id );

            if ( empty( $message->id ) ) {
                return false;
            }

            if ( function_exists( 'Better_Messages_WebSocket' ) ) {
                $message->recipients   = Better_Messages()->functions->get_recipients( $message->thread_id );
                $message->count_unread = false;
                $message->send_push    = false;
                $message->mobile_push  = false;
                $message->show_on_site = false;
                $message->notification = false;
                $message->is_update    = true;
                $message->meta         = array();

                Better_Messages_WebSocket()->on_message_sent( $message );
            }

            do_action( 'better_messages_thread_updated', (int) $message->thread_id );

            return true;
        }

        public function user_blocked( $user_id, $blocked_id )
        {
            if ( $this->trust( (int) $user_id ) === 'new' ) {
                return;
            }

            $this->reaction( (int) $blocked_id, 'blocked', (int) $user_id );
        }

        public function reaction( $user_id, $action, $by )
        {
            $engine = $this->engine();

            if ( ! $engine || ! $this->enabled() || $user_id === 0 || $this->trust( $user_id ) === 'trusted' ) {
                return;
            }

            $engine->note( self::PLUGIN, $user_id, $action, (string) $by );

            $limits = $this->reaction_limits();
            $spec   = $limits[ $action ];

            if ( $this->level() === 'monitor' || ! $this->enforcing() ) {
                return;
            }

            if ( $engine->distinct( self::PLUGIN, $user_id, $action, $spec['window'] ) >= $spec['limit'] ) {
                $this->put_under_review( $user_id, array( $action === 'reported' ? 'reports' : ( $action === 'declined' ? 'declines' : 'blocks' ) ) );
            }
        }

        public function before_new_thread( &$args, &$errors )
        {
            $action     = isset( $args['action'] ) && $args['action'] === 'probe' ? 'probe' : 'start';
            $actor      = (int) Better_Messages()->functions->get_current_user_id();
            $recipients = Better_Messages()->functions->new_participant_ids( isset( $args['recipients'] ) ? $args['recipients'] : array(), array( $actor ) );

            sort( $recipients );

            $content = isset( $args['content'] ) ? (string) $args['content'] : '';
            $subject = isset( $args['subject'] ) ? (string) $args['subject'] : '';

            if ( $subject !== '' && strpos( $content, '<!-- BM-' ) !== 0 ) {
                $content = $subject . "\n" . $content;
            }

            $decision = $this->check(
                'start',
                'new:' . md5( implode( ',', $recipients ) ),
                $content,
                $this->new_contacts( $actor, $recipients ),
                $args,
                (array) $errors,
                $action !== 'probe'
            );

            if ( $action === 'probe' ) {
                if ( is_array( $decision ) && $decision['outcome'] === 'refuse' ) {
                    $errors['spamProtection'] = $this->words( $decision );
                }
                return;
            }

            $this->apply( $decision, 'start', $args, $errors );
        }

        public function before_message_send( &$args, &$errors )
        {
            $thread_id = isset( $args['thread_id'] ) ? (int) $args['thread_id'] : 0;

            if ( $thread_id === 0 ) {
                return;
            }

            if ( Better_Messages()->functions->get_thread_type( $thread_id ) === 'chat-room' && $this->trust( Better_Messages()->functions->get_current_user_id() ) !== 'new' ) {
                return;
            }

            $decision = $this->check( 'send', (string) $thread_id, isset( $args['content'] ) ? $args['content'] : '', array(), $args, (array) $errors );

            $this->apply( $decision, 'send', $args, $errors );
        }

        public function before_message_edit( &$args, &$errors )
        {
            $decision = $this->check( 'edit', (string) ( isset( $args['thread_id'] ) ? (int) $args['thread_id'] : 0 ), isset( $args['content'] ) ? $args['content'] : '', array(), $args, (array) $errors );

            $this->apply( $decision, 'edit', $args, $errors );
        }

        public function before_subject_change( &$args, &$errors )
        {
            $decision = $this->check( 'subject', (string) ( isset( $args['thread_id'] ) ? (int) $args['thread_id'] : 0 ), isset( $args['subject'] ) ? $args['subject'] : '', array(), $args, (array) $errors );

            $this->apply( $decision, 'subject', $args, $errors );
        }

        public function before_add_participants( &$args, &$errors )
        {
            $actor = (int) Better_Messages()->functions->get_current_user_id();
            $ids   = isset( $args['user_ids'] ) ? (array) $args['user_ids'] : array();

            $decision = $this->check( 'add', (string) ( isset( $args['thread_id'] ) ? (int) $args['thread_id'] : 0 ), '', $this->new_contacts( $actor, $ids ), $args, (array) $errors );

            $this->apply( $decision, 'add', $args, $errors );
        }

        public function before_call( $error, $thread_id )
        {
            if ( ! empty( $error ) ) {
                return $error;
            }

            $args     = array();
            $errors   = array();
            $decision = $this->check( 'call', (string) (int) $thread_id, '', array(), $args, $errors );

            $this->apply( $decision, 'call', $args, $errors );

            return isset( $errors['spamProtection'] ) ? $errors['spamProtection'] : $error;
        }
    }

endif;

function Better_Messages_Anti_Spam(){
    return Better_Messages_Anti_Spam::instance();
}
