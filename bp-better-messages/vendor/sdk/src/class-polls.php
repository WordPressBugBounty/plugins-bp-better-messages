<?php
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WordPlus_SDK_Polls_Store' ) ) {
    interface WordPlus_SDK_Polls_Store
    {
        public function install();

        public function uninstall();

        public function insert( array $poll );

        public function find( $context, $object_id );

        public function transaction( callable $run );

        public function lock( $poll_id );

        public function picks( $poll_id, $user_id );

        public function replace( $poll_id, $user_id, array $option_ids, $now );

        public function results( array $poll );

        public function update( $poll_id, array $fields );

        public function voters( $poll_id, $option_id, $offset, $limit );

        public function due( $context, $now, $limit );

        public function remove( $context, array $object_ids );

        public function remove_scope( $context, $scope_id );

        public function erase_user( $user_id );
    }
}

if ( ! class_exists( 'WordPlus_SDK_Polls' ) ) {
    final class WordPlus_SDK_Polls
    {
        const QUESTION = 255;

        const OPTION = 100;

        const EXPLANATION = 200;

        const MIN_OPTIONS = 2;

        const MAX_OPTIONS = 12;

        const RECENT = 3;

        const SOONEST = 60;

        const FURTHEST = 31622400;

        private $sdk;

        private $store = null;

        private $read = array();

        public function __construct( $sdk = null )
        {
            $this->sdk = $sdk;
        }

        public function store()
        {
            if ( null === $this->store ) {
                require_once __DIR__ . '/class-polls-table.php';

                $store       = new WordPlus_SDK_Polls_Table();
                $filtered    = apply_filters( 'wordplus_polls_store', $store );
                $this->store = $filtered instanceof WordPlus_SDK_Polls_Store ? $filtered : $store;
            }

            return $this->store;
        }

        public function use_store( WordPlus_SDK_Polls_Store $store )
        {
            $this->store = $store;
            $this->read  = array();
        }

        public function install()
        {
            return $this->store()->install();
        }

        public function uninstall()
        {
            $this->store()->uninstall();
            $this->read = array();
        }

        public function check( $definition, $now = null )
        {
            $checked = self::check_definition( $definition, self::now( $now ) );

            if ( ! $checked['ok'] ) {
                $first = $checked['errors'][0];

                return new WP_Error( 'wordplus_poll_invalid', __( 'The poll is not complete', 'wordplus-sdk' ), array_merge(
                    array( 'status' => 400, 'field' => $first['field'] ),
                    isset( $first['index'] ) ? array( 'index' => $first['index'] ) : array(),
                    array( 'errors' => $checked['errors'] )
                ) );
            }

            return $checked['value'];
        }

        public function create( $context, $object_id, $scope_id, $creator_id, $definition, $now = null )
        {
            $now        = self::now( $now );
            $definition = $this->check( $definition, $now );

            if ( is_wp_error( $definition ) ) {
                return $definition;
            }

            if ( null !== $this->get( $context, $object_id ) ) {
                return new WP_Error( 'wordplus_poll_exists', __( 'There is a poll here already', 'wordplus-sdk' ), array( 'status' => 409 ) );
            }

            if ( ! $this->store()->install() ) {
                return new WP_Error( 'wordplus_poll_table', __( 'The poll could not be saved', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            $poll = array_merge( array(
                'id'         => 0,
                'context'    => (string) $context,
                'object_id'  => (int) $object_id,
                'scope_id'   => (int) $scope_id,
                'creator_id' => (int) $creator_id,
                'created_at' => $now,
                'closed_by'  => null,
            ), $definition, array(
                'closedAt' => null,
            ) );

            $poll['results'] = self::count_votes( $poll, array() );
            $poll['id']      = (int) $this->store()->insert( $poll );

            if ( $poll['id'] <= 0 ) {
                return new WP_Error( 'wordplus_poll_table', __( 'The poll could not be saved', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            $this->read[ self::key( $context, $object_id ) ] = $poll;

            return $poll;
        }

        public function get( $context, $object_id )
        {
            $key = self::key( $context, $object_id );

            if ( ! array_key_exists( $key, $this->read ) ) {
                $this->read[ $key ] = $this->store()->find( (string) $context, (int) $object_id );
            }

            return $this->read[ $key ];
        }

        public function state( array $poll, $now = null )
        {
            $results = isset( $poll['results'] ) && is_array( $poll['results'] ) ? $poll['results'] : $this->store()->results( $poll );

            return self::state_of( $poll, $results, self::now( $now ) );
        }

        public function member( array $poll, $user_id )
        {
            return self::member_of( $poll, $this->store()->picks( (int) $poll['id'], (int) $user_id ) );
        }

        public function vote( array $poll, $user_id, $option_ids, $now = null )
        {
            $now     = self::now( $now );
            $user_id = (int) $user_id;
            $store   = $this->store();
            $changed = false;

            $result = $store->transaction( function () use ( $store, $poll, $user_id, $option_ids, $now, &$changed ) {
                $locked = $store->lock( (int) $poll['id'] );

                if ( null === $locked ) {
                    return self::missing();
                }

                $before  = $store->picks( $locked['id'], $user_id );
                $checked = self::check_vote( $locked, $before, $option_ids, $now );

                if ( ! $checked['ok'] ) {
                    return self::refusal( $checked['error'] );
                }

                if ( $before === $checked['value'] ) {
                    return $locked;
                }

                $store->replace( $locked['id'], $user_id, $checked['value'], $now );
                $locked['results'] = $store->results( $locked );
                $store->update( $locked['id'], array( 'results' => $locked['results'] ) );
                $changed = true;

                return $locked;
            } );

            return $this->settled( $result, $changed );
        }

        public function add_option( array $poll, $user_id, $text, $now = null )
        {
            $now     = self::now( $now );
            $user_id = (int) $user_id;
            $store   = $this->store();
            $changed = false;

            if ( ! $store->install() ) {
                return new WP_Error( 'wordplus_poll_table', __( 'The poll could not be saved', 'wordplus-sdk' ), array( 'status' => 500 ) );
            }

            $result = $store->transaction( function () use ( $store, $poll, $user_id, $text, $now, &$changed ) {
                $locked = $store->lock( (int) $poll['id'] );

                if ( null === $locked ) {
                    return self::missing();
                }

                $checked = self::check_option( $locked, $text, $user_id, $now );

                if ( ! $checked['ok'] ) {
                    return self::refusal( $checked['error'] );
                }

                $locked['options'][] = $checked['value'];
                $locked['results']   = $store->results( $locked );
                $store->update( $locked['id'], array( 'options' => $locked['options'], 'results' => $locked['results'] ) );
                $changed = true;

                return $locked;
            } );

            return $this->settled( $result, $changed );
        }

        public function end( array $poll, $user_id, $now = null )
        {
            $now     = self::now( $now );
            $store   = $this->store();
            $changed = false;

            $result = $store->transaction( function () use ( $store, $poll, $user_id, $now, &$changed ) {
                $locked = $store->lock( (int) $poll['id'] );

                if ( null === $locked ) {
                    return self::missing();
                }

                if ( ! self::is_open( $locked, $now ) ) {
                    return self::refusal( 'closed' );
                }

                $store->update( $locked['id'], array( 'closed_at' => $now, 'closed_by' => (int) $user_id ) );
                $locked['closedAt']  = $now;
                $locked['closed_by'] = (int) $user_id;
                $changed             = true;

                return $locked;
            } );

            return $this->settled( $result, $changed );
        }

        public function voters( array $poll, $option_id, $page = 1, $per_page = 50 )
        {
            if ( ! empty( $poll['anonymous'] ) ) {
                return new WP_Error( 'wordplus_poll_anonymous', __( 'Votes in this poll are anonymous', 'wordplus-sdk' ), array( 'status' => 403 ) );
            }

            if ( ! empty( $poll['hideResults'] ) && self::is_open( $poll, time() ) ) {
                return new WP_Error( 'wordplus_poll_hidden', __( 'The results of this poll show when it ends', 'wordplus-sdk' ), array( 'status' => 403 ) );
            }

            $option_id = self::whole_number( $option_id );

            if ( null === $option_id || ! in_array( $option_id, array_column( $poll['options'], 'id' ), true ) ) {
                return self::refusal( 'unknown_option' );
            }

            $page     = max( 1, (int) $page );
            $per_page = min( 100, max( 1, (int) $per_page ) );
            $results  = isset( $poll['results']['counts'] ) ? $poll['results'] : $this->store()->results( $poll );

            return array(
                'users'   => $this->store()->voters( (int) $poll['id'], $option_id, ( $page - 1 ) * $per_page, $per_page ),
                'total'   => isset( $results['counts'][ $option_id ] ) ? (int) $results['counts'][ $option_id ] : 0,
                'page'    => $page,
                'perPage' => $per_page,
            );
        }

        public function end_due( $context, $limit = 100, $now = null )
        {
            $now   = self::now( $now );
            $store = $this->store();
            $ended = array();

            foreach ( $store->due( (string) $context, $now, max( 1, (int) $limit ) ) as $due ) {
                $changed = false;
                $result  = $store->transaction( function () use ( $store, $due, $now, &$changed ) {
                    $locked = $store->lock( (int) $due['id'] );

                    if ( null === $locked || null !== $locked['closedAt'] || null === $locked['closesAt'] || $locked['closesAt'] > $now ) {
                        return $locked;
                    }

                    $store->update( $locked['id'], array( 'closed_at' => $locked['closesAt'], 'closed_by' => null ) );
                    $locked['closedAt'] = $locked['closesAt'];
                    $changed            = true;

                    return $locked;
                } );

                $result = $this->settled( $result, $changed );

                if ( $changed && is_array( $result ) ) {
                    $ended[] = $result;
                }
            }

            return $ended;
        }

        public function delete( $context, $object_ids )
        {
            $object_ids = array_values( array_unique( array_map( 'intval', (array) $object_ids ) ) );

            foreach ( $object_ids as $object_id ) {
                unset( $this->read[ self::key( $context, $object_id ) ] );
            }

            return empty( $object_ids ) ? 0 : $this->store()->remove( (string) $context, $object_ids );
        }

        public function delete_scope( $context, $scope_id )
        {
            $this->read = array();

            return $this->store()->remove_scope( (string) $context, (int) $scope_id );
        }

        public function erase_user( $user_id )
        {
            $store = $this->store();
            $polls = $store->erase_user( (int) $user_id );

            foreach ( $polls as $poll_id ) {
                $changed = false;
                $result  = $store->transaction( function () use ( $store, $poll_id, &$changed ) {
                    $locked = $store->lock( (int) $poll_id );

                    if ( null === $locked ) {
                        return self::missing();
                    }

                    $locked['results'] = $store->results( $locked );
                    $store->update( $locked['id'], array( 'results' => $locked['results'] ) );
                    $changed = true;

                    return $locked;
                } );

                $this->settled( $result, $changed );
            }

            return count( $polls );
        }

        public function routes( $namespace, $context, array $callbacks = array() )
        {
            require_once __DIR__ . '/class-polls-rest.php';

            $rest = new WordPlus_SDK_Polls_Rest( $this, (string) $namespace, (string) $context, $callbacks );
            $rest->register();

            return $rest;
        }

        public static function label( $question )
        {
            return "\u{1F4CA} " . $question;
        }

        private function settled( $result, $changed )
        {
            if ( is_wp_error( $result ) || ! is_array( $result ) ) {
                return is_array( $result ) || is_wp_error( $result ) ? $result : self::missing();
            }

            $this->read[ self::key( $result['context'], $result['object_id'] ) ] = $result;

            if ( $changed ) {
                do_action( 'wordplus_poll_changed', $result['context'], $result['object_id'], $result );
            }

            return $result;
        }

        private static function key( $context, $object_id )
        {
            return (string) $context . '|' . (int) $object_id;
        }

        private static function now( $now )
        {
            return null === $now ? time() : (int) $now;
        }

        private static function missing()
        {
            return new WP_Error( 'wordplus_poll_not_found', __( 'This poll is not there any more', 'wordplus-sdk' ), array( 'status' => 404 ) );
        }

        private static function refusal( $code )
        {
            $messages = array(
                'closed'         => array( __( 'This poll has ended', 'wordplus-sdk' ), 409 ),
                'unknown_option' => array( __( 'That option is not in this poll', 'wordplus-sdk' ), 400 ),
                'one_only'       => array( __( 'This poll takes one answer', 'wordplus-sdk' ), 400 ),
                'answered'       => array( __( 'A quiz answer cannot be changed', 'wordplus-sdk' ), 409 ),
                'invalid'        => array( __( 'The vote is not valid', 'wordplus-sdk' ), 400 ),
                'not_allowed'    => array( __( 'This poll takes no new options', 'wordplus-sdk' ), 403 ),
                'too_many'       => array( __( 'This poll has as many options as it can take', 'wordplus-sdk' ), 409 ),
                'empty'          => array( __( 'Write the option first', 'wordplus-sdk' ), 400 ),
                'too_long'       => array( __( 'The option is too long', 'wordplus-sdk' ), 400 ),
                'duplicate'      => array( __( 'This option is there already', 'wordplus-sdk' ), 409 ),
            );
            $message  = isset( $messages[ $code ] ) ? $messages[ $code ] : $messages['invalid'];

            return new WP_Error( 'wordplus_poll_' . $code, $message[0], array( 'status' => $message[1] ) );
        }

        public static function length( $text )
        {
            return mb_strlen( (string) $text, 'UTF-8' );
        }

        public static function valid_utf8( $value )
        {
            if ( ! is_string( $value ) ) {
                return false;
            }

            if ( function_exists( 'mb_check_encoding' ) ) {
                return mb_check_encoding( $value, 'UTF-8' );
            }

            return 1 === preg_match( '//u', $value );
        }

        public static function lower( $text, $multibyte = null )
        {
            $text = (string) $text;

            if ( null === $multibyte ) {
                $multibyte = function_exists( 'mb_strtolower' );
            }

            return $multibyte ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
        }

        public static function clean( $value )
        {
            if ( ! self::valid_utf8( $value ) ) {
                return '';
            }

            $value = (string) preg_replace( '/[\t\n\r\f\x{0B}]+/u', ' ', $value );
            $value = (string) preg_replace( '/[\x{00}-\x{1F}\x{7F}\x{200B}\x{2060}\x{FEFF}]/u', '', $value );
            $value = (string) preg_replace( '/ {2,}/', ' ', $value );

            return (string) preg_replace( '/^[\s\p{Zs}\x{2028}\x{2029}\x{FEFF}]+|[\s\p{Zs}\x{2028}\x{2029}\x{FEFF}]+$/u', '', $value );
        }

        public static function whole_number( $value )
        {
            if ( is_string( $value ) && preg_match( '/^-?\d+$/', trim( $value ) ) ) {
                $value = trim( $value );

                return abs( (float) $value ) <= 9007199254740991 ? (int) $value : null;
            }

            if ( is_int( $value ) ) {
                return abs( $value ) <= 9007199254740991 ? $value : null;
            }

            if ( is_float( $value ) && is_finite( $value ) && floor( $value ) === $value && abs( $value ) <= 9007199254740991 ) {
                return (int) $value;
            }

            return null;
        }

        private static function truthy( $value, $otherwise )
        {
            if ( null === $value ) {
                return $otherwise;
            }

            return true === $value || 1 === $value || '1' === $value || 'true' === $value;
        }

        private static function is_list( $value )
        {
            return is_array( $value ) && array_values( $value ) === $value;
        }

        public static function check_definition( $input, $now )
        {
            $errors = array();
            $raw    = is_object( $input ) ? get_object_vars( $input ) : ( is_array( $input ) ? $input : array() );

            $question = self::clean( isset( $raw['question'] ) ? $raw['question'] : null );

            if ( '' === $question ) {
                $errors[] = array( 'field' => 'question', 'code' => 'empty' );
            } elseif ( self::length( $question ) > self::QUESTION ) {
                $errors[] = array( 'field' => 'question', 'code' => 'too_long' );
            }

            $given   = isset( $raw['options'] ) && self::is_list( $raw['options'] ) ? $raw['options'] : array();
            $options = array();
            $seen    = array();

            if ( count( $given ) < self::MIN_OPTIONS ) {
                $errors[] = array( 'field' => 'options', 'code' => 'too_few' );
            } elseif ( count( $given ) > self::MAX_OPTIONS ) {
                $errors[] = array( 'field' => 'options', 'code' => 'too_many' );
            } else {
                foreach ( $given as $i => $option ) {
                    $option = is_object( $option ) ? get_object_vars( $option ) : $option;
                    $text   = self::clean( is_array( $option ) ? ( isset( $option['text'] ) ? $option['text'] : null ) : $option );
                    $index  = $i + 1;
                    $folded = self::lower( $text );

                    if ( '' === $text ) {
                        $errors[] = array( 'field' => 'option', 'code' => 'empty', 'index' => $index );
                    } elseif ( self::length( $text ) > self::OPTION ) {
                        $errors[] = array( 'field' => 'option', 'code' => 'too_long', 'index' => $index );
                    } elseif ( isset( $seen[ $folded ] ) ) {
                        $errors[] = array( 'field' => 'option', 'code' => 'duplicate', 'index' => $index );
                    }

                    $seen[ $folded ] = true;
                    $options[]       = array( 'id' => $index, 'text' => $text );
                }
            }

            $quiz = null;

            if ( array_key_exists( 'quiz', $raw ) && null !== $raw['quiz'] && false !== $raw['quiz'] ) {
                $q           = is_object( $raw['quiz'] ) ? get_object_vars( $raw['quiz'] ) : ( is_array( $raw['quiz'] ) ? $raw['quiz'] : array() );
                $answer      = self::whole_number( isset( $q['answer'] ) ? $q['answer'] : null );
                $explanation = self::clean( isset( $q['explanation'] ) ? $q['explanation'] : null );

                if ( ! empty( $options ) && ( null === $answer || $answer < 1 || $answer > count( $options ) ) ) {
                    $errors[] = array( 'field' => 'quiz', 'code' => 'unknown_answer' );
                }

                if ( self::length( $explanation ) > self::EXPLANATION ) {
                    $errors[] = array( 'field' => 'explanation', 'code' => 'too_long' );
                }

                $quiz = array( 'answer' => null === $answer ? 0 : $answer, 'explanation' => $explanation );
            }

            $closes_at = null;
            $given_at  = array_key_exists( 'closesAt', $raw ) ? $raw['closesAt'] : null;

            if ( null !== $given_at && '' !== $given_at && 0 !== $given_at && 0.0 !== $given_at ) {
                $closes_at = self::whole_number( $given_at );

                if ( null === $closes_at ) {
                    $errors[] = array( 'field' => 'closesAt', 'code' => 'invalid' );
                } elseif ( $closes_at < $now + self::SOONEST ) {
                    $errors[] = array( 'field' => 'closesAt', 'code' => 'past' );
                } elseif ( $closes_at > $now + self::FURTHEST ) {
                    $errors[] = array( 'field' => 'closesAt', 'code' => 'too_far' );
                }
            }

            if ( ! empty( $errors ) ) {
                return array( 'ok' => false, 'errors' => $errors );
            }

            return array(
                'ok'    => true,
                'value' => array(
                    'question'    => $question,
                    'options'     => $options,
                    'multiple'    => null === $quiz ? self::truthy( isset( $raw['multiple'] ) ? $raw['multiple'] : null, false ) : false,
                    'anonymous'   => self::truthy( isset( $raw['anonymous'] ) ? $raw['anonymous'] : null, true ),
                    'quiz'        => $quiz,
                    'closesAt'    => $closes_at,
                    'addOptions'  => null === $quiz ? self::truthy( isset( $raw['addOptions'] ) ? $raw['addOptions'] : null, false ) : false,
                    'hideResults' => self::truthy( isset( $raw['hideResults'] ) ? $raw['hideResults'] : null, false ),
                ),
            );
        }

        public static function check_option( array $poll, $text, $user, $now )
        {
            if ( ! self::is_open( $poll, $now ) ) {
                return array( 'ok' => false, 'error' => 'closed' );
            }

            if ( empty( $poll['addOptions'] ) || ! empty( $poll['quiz'] ) ) {
                return array( 'ok' => false, 'error' => 'not_allowed' );
            }

            if ( count( $poll['options'] ) >= self::MAX_OPTIONS ) {
                return array( 'ok' => false, 'error' => 'too_many' );
            }

            $text = self::clean( $text );

            if ( '' === $text ) {
                return array( 'ok' => false, 'error' => 'empty' );
            }

            if ( self::length( $text ) > self::OPTION ) {
                return array( 'ok' => false, 'error' => 'too_long' );
            }

            $folded = self::lower( $text );
            $last   = 0;

            foreach ( $poll['options'] as $option ) {
                if ( self::lower( $option['text'] ) === $folded ) {
                    return array( 'ok' => false, 'error' => 'duplicate' );
                }

                $last = max( $last, (int) $option['id'] );
            }

            return array( 'ok' => true, 'value' => array( 'id' => $last + 1, 'text' => $text, 'by' => $user ) );
        }

        public static function winners( array $state )
        {
            if ( ! empty( $state['hidden'] ) || empty( $state['voters'] ) ) {
                return array();
            }

            $most = 0;

            foreach ( $state['options'] as $option ) {
                $most = max( $most, isset( $state['counts'][ $option['id'] ] ) ? (int) $state['counts'][ $option['id'] ] : 0 );
            }

            if ( 0 === $most ) {
                return array();
            }

            $ids = array();

            foreach ( $state['options'] as $option ) {
                if ( ( isset( $state['counts'][ $option['id'] ] ) ? (int) $state['counts'][ $option['id'] ] : 0 ) === $most ) {
                    $ids[] = (int) $option['id'];
                }
            }

            sort( $ids );

            return $ids;
        }

        public static function is_open( array $poll, $now )
        {
            if ( ! empty( $poll['closed'] ) || ( isset( $poll['closedAt'] ) && null !== $poll['closedAt'] ) ) {
                return false;
            }

            return ! isset( $poll['closesAt'] ) || null === $poll['closesAt'] || $now < $poll['closesAt'];
        }

        public static function picks_of( array $votes, $user )
        {
            $picks = array();

            foreach ( $votes as $vote ) {
                if ( (string) $vote['user'] === (string) $user ) {
                    $picks[] = (int) $vote['option'];
                }
            }

            sort( $picks );

            return $picks;
        }

        public static function check_vote( array $poll, array $before, $picks, $now )
        {
            if ( ! self::is_list( $picks ) ) {
                return array( 'ok' => false, 'error' => 'invalid' );
            }

            if ( ! self::is_open( $poll, $now ) ) {
                return array( 'ok' => false, 'error' => 'closed' );
            }

            $known = array_map( 'intval', array_column( $poll['options'], 'id' ) );
            $ids   = array();

            foreach ( $picks as $pick ) {
                $id = self::whole_number( $pick );

                if ( null === $id ) {
                    return array( 'ok' => false, 'error' => 'invalid' );
                }

                if ( ! in_array( $id, $known, true ) ) {
                    return array( 'ok' => false, 'error' => 'unknown_option' );
                }

                if ( ! in_array( $id, $ids, true ) ) {
                    $ids[] = $id;
                }
            }

            sort( $ids );

            if ( count( $ids ) > 1 && empty( $poll['multiple'] ) ) {
                return array( 'ok' => false, 'error' => 'one_only' );
            }

            sort( $before );

            if ( ! empty( $poll['quiz'] ) && ! empty( $before ) && array_values( $before ) !== $ids ) {
                return array( 'ok' => false, 'error' => 'answered' );
            }

            return array( 'ok' => true, 'value' => $ids );
        }

        public static function apply_vote( array $poll, array $votes, $user, $picks, $now )
        {
            $before  = self::picks_of( $votes, $user );
            $checked = self::check_vote( $poll, $before, $picks, $now );

            if ( ! $checked['ok'] ) {
                return $checked;
            }

            if ( $before === $checked['value'] ) {
                return array( 'ok' => true, 'value' => array_values( $votes ) );
            }

            $after = array();

            foreach ( $votes as $vote ) {
                if ( (string) $vote['user'] !== (string) $user ) {
                    $after[] = $vote;
                }
            }

            foreach ( $checked['value'] as $option ) {
                $after[] = array( 'user' => $user, 'option' => $option, 'at' => $now );
            }

            return array( 'ok' => true, 'value' => $after );
        }

        public static function count_votes( array $poll, array $votes )
        {
            $counts = array();

            foreach ( $poll['options'] as $option ) {
                $counts[ (int) $option['id'] ] = 0;
            }

            $people = array();

            foreach ( $votes as $vote ) {
                if ( ! isset( $counts[ (int) $vote['option'] ] ) ) {
                    continue;
                }

                $counts[ (int) $vote['option'] ]++;
                $people[ (string) $vote['user'] ] = true;
            }

            if ( ! empty( $poll['anonymous'] ) ) {
                return array( 'counts' => $counts, 'voters' => count( $people ) );
            }

            $newest = array();

            foreach ( array_values( $votes ) as $i => $vote ) {
                $newest[] = array( 'vote' => $vote, 'i' => $i );
            }

            usort( $newest, function ( $a, $b ) {
                return ( $b['vote']['at'] <=> $a['vote']['at'] ) ?: ( $b['i'] <=> $a['i'] );
            } );

            $recent = array();

            foreach ( $poll['options'] as $option ) {
                $id            = (int) $option['id'];
                $recent[ $id ] = array();

                foreach ( $newest as $entry ) {
                    if ( (int) $entry['vote']['option'] === $id && count( $recent[ $id ] ) < self::RECENT ) {
                        $recent[ $id ][] = $entry['vote']['user'];
                    }
                }
            }

            return array( 'counts' => $counts, 'voters' => count( $people ), 'recent' => $recent );
        }

        public static function state_of( array $poll, array $results, $now )
        {
            $closed  = ! self::is_open( $poll, $now );
            $hidden  = ! empty( $poll['hideResults'] ) && ! $closed;
            $options = array();

            foreach ( $poll['options'] as $option ) {
                $entry = array( 'id' => (int) $option['id'], 'text' => (string) $option['text'] );

                if ( isset( $option['by'] ) ) {
                    $entry['by'] = $option['by'];
                }

                $options[] = $entry;
            }

            $counts = isset( $results['counts'] ) ? $results['counts'] : array();

            if ( $hidden ) {
                foreach ( array_keys( $counts ) as $id ) {
                    $counts[ $id ] = 0;
                }
            }

            $state = array(
                'question'   => (string) $poll['question'],
                'options'    => $options,
                'multiple'   => (bool) $poll['multiple'],
                'anonymous'  => (bool) $poll['anonymous'],
                'quiz'       => ! empty( $poll['quiz'] ),
                'closesAt'   => isset( $poll['closesAt'] ) ? $poll['closesAt'] : null,
                'closedAt'   => isset( $poll['closedAt'] ) ? $poll['closedAt'] : null,
                'closed'     => $closed,
                'addOptions' => ! empty( $poll['addOptions'] ) && empty( $poll['quiz'] ),
                'hidden'     => $hidden,
                'counts'     => $counts,
                'voters'     => isset( $results['voters'] ) ? (int) $results['voters'] : 0,
            );

            if ( ! $hidden && empty( $poll['anonymous'] ) && isset( $results['recent'] ) ) {
                $state['recent'] = $results['recent'];
            }

            if ( $closed && ! empty( $poll['quiz'] ) ) {
                $state['answer']      = (int) $poll['quiz']['answer'];
                $state['explanation'] = (string) $poll['quiz']['explanation'];
            }

            return $state;
        }

        public static function member_of( array $poll, array $picks )
        {
            sort( $picks );
            $member = array( 'votes' => array_values( array_map( 'intval', $picks ) ) );

            if ( ! empty( $poll['quiz'] ) && ! empty( $member['votes'] ) ) {
                $member['answer']      = (int) $poll['quiz']['answer'];
                $member['explanation'] = (string) $poll['quiz']['explanation'];
            }

            return $member;
        }
    }
}
