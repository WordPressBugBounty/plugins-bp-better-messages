<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_AI_Provider' ) ) {
    abstract class WordPlus_SDK_AI_Provider
    {
        const ROUNDS = 4;

        const MAX_FILE = 20971520;

        const RETRY_AFTER_MAX = 20;

        protected $ai;

        protected $key;

        public function __construct( WordPlus_SDK_AI $ai, $key )
        {
            $this->ai  = $ai;
            $this->key = (string) $key;
        }

        abstract public function id();

        abstract public function name();

        abstract public function features();

        abstract protected function fetch_models( array $options );

        abstract public function classify( $status, $json, $raw );

        abstract protected function begin( array $request );

        abstract protected function round( array $state, array $request, $last );

        abstract protected function follow( array $state, array $turn, array $results, array $request );

        public function key()
        {
            return $this->key;
        }

        public function supports( $feature )
        {
            return in_array( $feature, $this->features(), true );
        }

        abstract public function input_rules( $model, array $context = array() );

        public function rules( $model )
        {
            return $this->ai->catalogue()->model( $this->id(), $model );
        }

        public function models( array $options = array() )
        {
            $name   = 'wordplus_ai_models_' . $this->id() . '_' . substr( hash( 'sha256', $this->key ), 0, 12 );
            $cached = empty( $options['fresh'] ) ? get_transient( $name ) : false;

            if ( false === $cached ) {
                $cached = $this->all_models();

                if ( is_wp_error( $cached ) ) {
                    return $cached;
                }

                set_transient( $name, $cached, DAY_IN_SECONDS );
            }

            return $this->chat_models( (array) $cached, $options );
        }

        protected function all_models()
        {
            return $this->fetch_models( array() );
        }

        protected function chat_models( array $all, array $options )
        {
            return $all;
        }

        abstract public function check();

        public function options( $available = null )
        {
            $options = array();

            foreach ( $this->ai->catalogue()->tiers( $this->id() ) as $tier => $names ) {
                $model = self::pick( $names, $available );

                if ( '' !== $model ) {
                    $options[] = array( 'value' => $model, 'label' => $this->ai->catalogue()->label( $this->id(), $model ), 'tier' => (string) $tier );
                }
            }

            return $options;
        }

        private static function pick( array $names, $available )
        {
            if ( empty( $available ) || ! is_array( $available ) ) {
                return (string) ( isset( $names[0] ) ? $names[0] : '' );
            }

            foreach ( $names as $name ) {
                if ( in_array( $name, $available, true ) ) {
                    return $name;
                }

                $found = preg_grep( '/^' . preg_quote( $name, '/' ) . '-(\d{4}-\d{2}-\d{2}|\d{8}|\d{3}|latest|preview(-[\d-]+)?)$/', $available );
                if ( ! empty( $found ) ) {
                    sort( $found );

                    return (string) end( $found );
                }
            }

            return (string) ( isset( $names[0] ) ? $names[0] : '' );
        }

        protected function request( array $request )
        {
            $request = array_merge( array(
                'model'            => '',
                'system'           => '',
                'messages'         => array(),
                'stream'           => true,
                'max_tokens'       => 0,
                'temperature'      => null,
                'thinking'         => array(),
                'effort'           => '',
                'service_tier'     => '',
                'web_search'       => null,
                'file_search'      => null,
                'image_generation' => null,
                'tools'            => array(),
                'call_tool'        => null,
                'conversation'     => '',
                'store'            => null,
                'user'             => '',
                'timeout'          => 3600,
                'retries'          => 3,
                'retry_delays'     => array( 5, 15, 30 ),
                'retry_after_max'  => self::RETRY_AFTER_MAX,
                'wait'             => null,
                'on_image'         => null,
                'on_status'        => null,
                'context_limit'    => 0,
            ), $request );

            if ( ! is_callable( $request['call_tool'] ) ) {
                $request['tools'] = array();
            }

            $request['thinking'] = is_array( $request['thinking'] ) ? array_merge( array( 'on' => false, 'budget' => 0 ), $request['thinking'] ) : array( 'on' => (bool) $request['thinking'], 'budget' => 0 );

            return $request;
        }

        public function respond( array $request )
        {
            $request = $this->request( $request );
            $retries = max( 0, (int) $request['retries'] );
            $delays  = array_values( (array) $request['retry_delays'] );

            for ( $attempt = 0; ; $attempt++ ) {
                $answer = $this->answer( $request );
                $first  = $answer->valid() ? $answer->current() : null;

                if ( is_array( $first ) && 'error' === $first[0] && $attempt < $retries && WordPlus_SDK_AI_Http::retryable( isset( $first[2] ) ? $first[2] : '' ) ) {
                    $after = isset( $first[3]['retry_after'] ) ? (int) $first[3]['retry_after'] : 0;
                    $delay = $after > 0
                        ? min( $after, max( 0, (int) $request['retry_after_max'] ) )
                        : ( isset( $delays[ $attempt ] ) ? (int) $delays[ $attempt ] : (int) end( $delays ) );

                    if ( $delay > 0 ) {
                        call_user_func( is_callable( $request['wait'] ) ? $request['wait'] : 'sleep', $delay );
                    }
                    continue;
                }

                return self::replay( $first, $answer );
            }
        }

        private static function replay( $first, \Generator $answer )
        {
            if ( null === $first ) {
                return;
            }

            yield $first;
            $answer->next();

            while ( $answer->valid() ) {
                yield $answer->current();
                $answer->next();
            }
        }

        private function answer( array $request )
        {
            $state = $this->begin( $request );

            if ( is_wp_error( $state ) ) {
                yield $this->error_with( array( 'error', $state->get_error_message(), WordPlus_SDK_AI_Http::kind_of( $state ) ), array(), $request, 0 );
                return;
            }

            $usage = null;
            $each  = array();
            $meta  = array();
            $tools = ! empty( $request['tools'] );

            for ( $round = 1; $round <= self::ROUNDS; $round++ ) {
                $turn = $this->round( $state, $request, ! $tools || self::ROUNDS === $round );

                foreach ( $turn as $part ) {
                    if ( is_array( $part ) && isset( $part[0] ) && 'error' === $part[0] ) {
                        yield $this->error_with( $part, null === $usage ? array() : $usage, $request, $round - 1 );
                        return;
                    }

                    yield $part;
                }

                $turn = $turn->getReturn();

                if ( null === $turn ) {
                    return;
                }

                $meta   = $turn['meta'];
                $each[] = isset( $meta['usage'] ) ? $meta['usage'] : array();
                $usage  = null === $usage ? ( isset( $meta['usage'] ) ? $meta['usage'] : array() ) : self::sum( $usage, isset( $meta['usage'] ) ? $meta['usage'] : array() );

                if ( ! $tools || empty( $turn['calls'] ) ) {
                    break;
                }

                $results = array();
                foreach ( $turn['calls'] as $call ) {
                    $results[] = array(
                        'id'      => (string) $call['id'],
                        'name'    => (string) $call['name'],
                        'content' => (string) call_user_func( $request['call_tool'], (string) $call['name'], is_array( $call['args'] ) ? $call['args'] : array() ),
                    );
                }

                yield array( 'tick' );

                $state = $this->follow( $state, $turn, $results, $request );
            }

            if ( null !== $usage && array() !== $usage ) {
                $meta['usage'] = $usage;
            }
            if ( $tools && $round > 1 ) {
                $meta['rounds'] = min( $round, self::ROUNDS );
                $meta['each_round'] = $each;
            }

            yield array( 'finish', $meta );
        }

        protected static function sum( $a, $b )
        {
            if ( ! is_array( $a ) || ! is_array( $b ) ) {
                return is_numeric( $a ) && is_numeric( $b ) ? $a + $b : ( null === $b ? $a : $b );
            }

            foreach ( $b as $key => $value ) {
                $a[ $key ] = array_key_exists( $key, $a ) ? self::sum( $a[ $key ], $value ) : $value;
            }

            return $a;
        }

        protected function error_with( array $part, array $before, array $request, $rounds )
        {
            $data  = isset( $part[3] ) && is_array( $part[3] ) ? $part[3] : array();
            $own   = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();
            $usage = array() === $before ? $own : ( array() === $own ? $before : self::sum( $before, $own ) );
            $kind  = isset( $part[2] ) && in_array( $part[2], WordPlus_SDK_AI_Http::KINDS, true ) ? (string) $part[2] : WordPlus_SDK_AI_Http::kind( isset( $data['status'] ) ? $data['status'] : 0, isset( $part[1] ) ? $part[1] : '' );

            $data = array_merge( array( 'status' => 0, 'retry_after' => 0 ), $data, array(
                'usage' => $usage,
                'meta'  => array( 'provider' => $this->id(), 'model' => (string) $request['model'], 'usage' => $usage, 'rounds' => (int) $rounds ),
            ) );

            return array( 'error', isset( $part[1] ) ? (string) $part[1] : '', $kind, $data );
        }

        public function complete( $system, $content, $model, $max_tokens = 3000, array $options = array() )
        {
            $request = array(
                'model'      => (string) $model,
                'system'     => (string) $system,
                'messages'   => array( array( 'role' => 'user', 'content' => (string) $content ) ),
                'max_tokens' => (int) $max_tokens,
                'stream'     => false,
                'retries'    => 0,
                'timeout'    => isset( $options['timeout'] ) ? (int) $options['timeout'] : 120,
                'complete'   => true,
            );

            if ( ! empty( $options['webSearch'] ) ) {
                $request['web_search'] = array( 'context_size' => isset( $options['webSearchContextSize'] ) ? (string) $options['webSearchContextSize'] : 'medium' );
            }

            $text = '';

            foreach ( $this->respond( $request ) as $part ) {
                if ( is_string( $part ) ) {
                    $text .= $part;
                } elseif ( 'error' === $part[0] ) {
                    return WordPlus_SDK_AI_Http::error( isset( $part[2] ) ? $part[2] : 'request', $part[1], isset( $part[3] ) && is_array( $part[3] ) ? $part[3] : array() );
                } elseif ( 'finish' === $part[0] ) {
                    return array( 'text' => $text, 'usage' => isset( $part[1]['usage'] ) ? $part[1]['usage'] : array() );
                }
            }

            return WordPlus_SDK_AI_Http::error( 'empty', 'No output in response' );
        }

        public function moderate( $text = '', $image_data_uris = array() )
        {
            return new WP_Error( 'not_supported', 'Moderation not supported by this provider' );
        }

        public function transcribe( $file, array $options = array() )
        {
            return new WP_Error( 'not_supported', 'Transcription not supported by this provider' );
        }

        public function transcription_models()
        {
            return array();
        }

        public function cancel( $id )
        {
            return true;
        }

        protected function client( $base, array $headers, $json = true )
        {
            return WordPlus_SDK_AI_Http::client( $base, $headers, $this->id(), $json );
        }

        protected function failure( $e )
        {
            $status = WordPlus_SDK_AI_Http::status( $e );
            $raw    = WordPlus_SDK_AI_Http::body( $e );
            $json   = json_decode( $raw, true );
            $kind   = WordPlus_SDK_AI_Http::unanswered( $e ) ? 'down' : $this->classify( $status, is_array( $json ) ? $json : null, $raw );

            return WordPlus_SDK_AI_Http::error( $kind, WordPlus_SDK_AI_Http::message( $e ), array( 'status' => $status, 'retry_after' => WordPlus_SDK_AI_Http::retry_after( $e ) ) );
        }

        protected function error_part( $e, $usage = array() )
        {
            $error = $e instanceof WP_Error ? $e : $this->failure( $e );
            $data  = (array) $error->get_error_data();

            return array( 'error', $error->get_error_message(), WordPlus_SDK_AI_Http::kind_of( $error ), array(
                'status'      => isset( $data['status'] ) ? (int) $data['status'] : 0,
                'retry_after' => isset( $data['retry_after'] ) ? (int) $data['retry_after'] : 0,
                'usage'       => is_array( $usage ) ? $usage : array(),
            ) );
        }

        protected static function failed( $message, $kind, $usage = array(), array $data = array() )
        {
            return array( 'error', (string) $message, (string) $kind, array_merge( array( 'status' => 0, 'retry_after' => 0, 'usage' => is_array( $usage ) ? $usage : array() ), $data ) );
        }

        protected static function events( $body )
        {
            $buffer = '';

            while ( ! $body->eof() ) {
                $chunk = $body->read( 1024 );

                if ( '' === $chunk ) {
                    continue;
                }

                $buffer .= $chunk;

                while ( false !== ( $at = strpos( $buffer, "\n" ) ) ) {
                    $line   = trim( substr( $buffer, 0, $at ) );
                    $buffer = substr( $buffer, $at + 1 );

                    if ( 0 !== strpos( $line, 'data:' ) ) {
                        continue;
                    }

                    $json = ltrim( substr( $line, 5 ) );

                    if ( '[DONE]' === $json ) {
                        yield '[DONE]';
                        continue;
                    }

                    $data = json_decode( $json, true );
                    if ( is_array( $data ) ) {
                        yield $data;
                    }
                }
            }

            $line = trim( $buffer );
            if ( 0 === strpos( $line, 'data:' ) ) {
                $data = json_decode( ltrim( substr( $line, 5 ) ), true );
                if ( is_array( $data ) ) {
                    yield $data;
                }
            }
        }

        protected static function parts( $content )
        {
            if ( is_string( $content ) ) {
                return array( array( 'type' => 'text', 'text' => $content ) );
            }

            return array_values( array_filter( is_array( $content ) ? $content : array(), 'is_array' ) );
        }

        protected static function turns( array $messages, $start = '...' )
        {
            $out = array();

            foreach ( $messages as $message ) {
                $role  = isset( $message['role'] ) && 'assistant' === $message['role'] ? 'assistant' : 'user';
                $parts = self::parts( isset( $message['content'] ) ? $message['content'] : '' );

                $thoughts = isset( $message['thoughts'] ) && is_array( $message['thoughts'] ) ? $message['thoughts'] : array();

                if ( empty( $parts ) && empty( $thoughts ) ) {
                    continue;
                }

                $last = count( $out ) - 1;
                if ( $last >= 0 && $out[ $last ]['role'] === $role ) {
                    $out[ $last ]['parts']    = array_merge( $out[ $last ]['parts'], $parts );
                    $out[ $last ]['thoughts'] = array_merge( $out[ $last ]['thoughts'], $thoughts );
                } else {
                    $out[] = array( 'role' => $role, 'parts' => $parts, 'thoughts' => $thoughts );
                }
            }

            if ( ! empty( $out ) && 'user' !== $out[0]['role'] ) {
                array_unshift( $out, array( 'role' => 'user', 'parts' => array( array( 'type' => 'text', 'text' => $start ) ), 'thoughts' => array() ) );
            }

            return $out;
        }

        protected static function trim_turns( array $turns, $limit, callable $tokens )
        {
            $limit = (int) ( $limit * 0.85 );
            $total = 0;

            foreach ( $turns as $turn ) {
                $total += $tokens( $turn );
            }

            while ( $total > $limit && count( $turns ) > 2 ) {
                $total -= $tokens( array_shift( $turns ) );
            }

            while ( ! empty( $turns ) && 'user' !== $turns[0]['role'] ) {
                array_shift( $turns );
            }

            return $turns;
        }

        protected static function schema( array $schema )
        {
            $schema = array_merge( array( 'type' => 'object' ), $schema );

            if ( empty( $schema['properties'] ) ) {
                $schema['properties'] = new \stdClass();
            }

            return $schema;
        }

        protected static function args( $json )
        {
            if ( is_array( $json ) ) {
                return $json;
            }

            $decoded = json_decode( (string) $json, true );

            return is_array( $decoded ) ? $decoded : array();
        }

        protected static function effort_for( $asked, $budget, array $levels )
        {
            if ( '' !== (string) $asked && in_array( $asked, $levels, true ) ) {
                return (string) $asked;
            }

            if ( empty( $levels ) ) {
                return '';
            }

            $budget = (int) $budget;
            $want   = $budget <= 0 ? '' : ( $budget <= 4096 ? 'low' : ( $budget <= 16384 ? 'medium' : ( $budget <= 32768 ? 'high' : 'xhigh' ) ) );

            if ( '' === $want ) {
                return '';
            }

            $order = WordPlus_SDK_AI_Catalogue::LEVELS;
            $at    = array_search( $want, $order, true );

            for ( $i = $at; $i >= 0; $i-- ) {
                if ( in_array( $order[ $i ], $levels, true ) ) {
                    return $order[ $i ];
                }
            }

            for ( $i = $at; $i < count( $order ); $i++ ) {
                if ( in_array( $order[ $i ], $levels, true ) ) {
                    return $order[ $i ];
                }
            }

            return '';
        }

        protected static function image( array $request, array $image )
        {
            $record = array_diff_key( $image, array( 'data' => true ) );

            if ( is_callable( $request['on_image'] ) ) {
                $more = call_user_func( $request['on_image'], $image );

                if ( is_array( $more ) ) {
                    $record = array_merge( $record, $more );
                }
            }

            return $record;
        }
    }
}
