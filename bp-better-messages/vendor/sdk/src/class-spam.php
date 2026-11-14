<?php
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WordPlus_SDK_Spam_Store' ) ) {
    interface WordPlus_SDK_Spam_Store
    {
        public function install();

        public function uninstall();

        public function insert( array $row );

        public function count( $plugin, $actor, array $actions, $since );

        public function contacts( $plugin, $actor, array $actions, $since );

        public function scopes( $plugin, $actor, $fingerprint, $since );

        public function actors( $plugin, $fingerprint, $since, $trust );

        public function times( $plugin, $actor, array $actions, $since, $limit );

        public function strikes( $plugin, $actor, $since );

        public function distinct( $plugin, $actor, $action, $since );

        public function recent( $plugin, $actor, $since, $limit );

        public function flagged( $plugin, $since, $limit );

        public function forget( $plugin, $actor );

        public function prune( $before );
    }
}

if ( ! class_exists( 'WordPlus_SDK_Spam' ) ) {
    final class WordPlus_SDK_Spam
    {
        const API = 1;

        const DAYS = 30;

        const PRUNE = 'wordplus_spam_prune';

        const OUTCOMES = array( 'deliver' => 0, 'flag' => 1, 'hold' => 2, 'refuse' => 3 );

        const FINGERPRINT_MIN = 16;

        const SENDS = array( 'start', 'send' );

        const REACHES = array( 'start', 'send', 'add' );

        const LOCK_WAIT = 5;

        private $sdk;

        private $store = null;

        private $word_patterns = array();

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function api()
        {
            return self::API;
        }

        public function use_store( WordPlus_SDK_Spam_Store $store )
        {
            $this->store = $store;
        }

        public function store()
        {
            if ( null === $this->store ) {
                require_once __DIR__ . '/class-spam-table.php';
                $this->store = new WordPlus_SDK_Spam_Table();
            }

            return $this->store;
        }

        public function text( $html )
        {
            $text = wp_strip_all_tags( (string) $html );
            $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

            return $this->normalize( $text );
        }

        public function normalize( $text )
        {
            $text = (string) $text;

            if ( class_exists( 'Normalizer' ) ) {
                $normalized = Normalizer::normalize( $text, Normalizer::FORM_KC );

                if ( is_string( $normalized ) ) {
                    $text = $normalized;
                }
            }

            $stripped = preg_replace( '/[\x{00AD}\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}]/u', '', $text );
            $text     = is_string( $stripped ) ? $stripped : $text;

            $collapsed = preg_replace( '/\s+/u', ' ', $text );

            return trim( is_string( $collapsed ) ? $collapsed : $text );
        }

        public function fingerprint( $text )
        {
            $text    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $this->normalize( $text ), 'UTF-8' ) : strtolower( $this->normalize( $text ) );
            $letters = preg_replace( '/[^\p{L}\p{N}]+/u', '', $text );

            if ( ! is_string( $letters ) ) {
                return '';
            }

            $length = function_exists( 'mb_strlen' ) ? mb_strlen( $letters, 'UTF-8' ) : strlen( $letters );
            $min    = (int) apply_filters( 'wordplus_spam_fingerprint_min', self::FINGERPRINT_MIN );

            if ( '' === $letters || $length < max( 1, $min ) ) {
                return '';
            }

            return substr( hash_hmac( 'sha256', $letters, 'wordplus-sdk-spam|' . wp_salt( 'nonce' ) ), 0, 32 );
        }

        public function words( $text, $list )
        {
            $text = $this->normalize( $text );

            if ( '' === $text ) {
                return false;
            }

            foreach ( $this->word_patterns( $list ) as $pattern ) {
                if ( 1 === preg_match( $pattern, $text ) ) {
                    return true;
                }
            }

            return false;
        }

        private function word_patterns( $list )
        {
            $lines = is_array( $list ) ? $list : explode( "\n", (string) $list );
            $key   = md5( implode( "\n", array_map( 'strval', $lines ) ) );

            if ( isset( $this->word_patterns[ $key ] ) ) {
                return $this->word_patterns[ $key ];
            }

            $letter   = '[\p{L}\p{N}_]';
            $unspaced = '/^[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]$/u';
            $pieces   = array();

            foreach ( $lines as $word ) {
                $word = $this->normalize( (string) $word );

                if ( '' === $word ) {
                    continue;
                }

                $piece = preg_quote( $word, '/' );
                $first = mb_substr( $word, 0, 1, 'UTF-8' );
                $last  = mb_substr( $word, -1, 1, 'UTF-8' );

                if ( 1 === preg_match( '/^' . $letter . '$/u', $first ) && 1 !== preg_match( $unspaced, $first ) ) {
                    $piece = '(?<!' . $letter . ')' . $piece;
                }

                if ( 1 === preg_match( '/^' . $letter . '$/u', $last ) && 1 !== preg_match( $unspaced, $last ) ) {
                    $piece .= '(?!' . $letter . ')';
                }

                $pieces[] = $piece;
            }

            $patterns = array();

            foreach ( array_chunk( $pieces, 200 ) as $chunk ) {
                $patterns[] = '/' . implode( '|', $chunk ) . '/iu';
            }

            $this->word_patterns[ $key ] = $patterns;

            return $patterns;
        }

        public function links( $html, array $allowed = array() )
        {
            $html  = (string) $html;
            $hosts = array();

            if ( preg_match_all( '/href\s*=\s*["\']([^"\']+)["\']/i', $html, $found ) ) {
                foreach ( $found[1] as $url ) {
                    $hosts[] = $this->host( html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
                }
            }

            $text = $this->text( $html );

            if ( preg_match_all( '~(?:https?://|www\.)[^\s<>"\']+~iu', $text, $found ) ) {
                foreach ( $found[0] as $url ) {
                    $hosts[] = $this->host( $url );
                }
            }

            $tlds = 'com|net|org|info|biz|io|co|me|app|dev|xyz|top|site|online|shop|store|link|click|live|club|vip|pro|cc|tk|ml|ga|cf|gq|ru|ua|uk|de|fr|es|it|nl|pl|in|us|ca|au|br|cn|jp|tv|ly|to|gg';

            if ( preg_match_all( '~(?<![\p{L}\p{N}@./_-])((?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:' . $tlds . '))(?![\p{L}\p{N}_-])~iu', $text, $found ) ) {
                foreach ( $found[1] as $domain ) {
                    $hosts[] = $this->host( $domain );
                }
            }

            $own   = $this->host( home_url() );
            $allow = array_filter( array_map( array( $this, 'host' ), array_merge( array( $own ), $allowed ) ) );
            $other = array();

            foreach ( $hosts as $host ) {
                if ( '' === $host || isset( $other[ $host ] ) ) {
                    continue;
                }

                foreach ( $allow as $fine ) {
                    if ( $host === $fine || substr( $host, -strlen( '.' . $fine ) ) === '.' . $fine ) {
                        continue 2;
                    }
                }

                $other[ $host ] = $host;
            }

            return array_values( $other );
        }

        private function host( $url )
        {
            $url = trim( (string) $url );

            if ( '' === $url ) {
                return '';
            }

            if ( ! preg_match( '~^[a-z][a-z0-9+.-]*://~i', $url ) ) {
                if ( 0 === strpos( $url, '//' ) ) {
                    $url = 'https:' . $url;
                } elseif ( preg_match( '~^(?:mailto|tel|javascript|data):~i', $url ) || '/' === $url[0] || '#' === $url[0] || '?' === $url[0] ) {
                    return '';
                } else {
                    $url = 'https://' . $url;
                }
            }

            $host = wp_parse_url( $url, PHP_URL_HOST );

            if ( ! is_string( $host ) || '' === $host ) {
                return '';
            }

            $host = strtolower( rtrim( $host, '.' ) );

            return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
        }

        public function contacts( $text )
        {
            $text  = $this->normalize( $text );
            $kinds = array();

            foreach ( $this->contact_patterns() as $kind => $patterns ) {
                $subject = 'phone' === $kind ? $this->without_dates( $text ) : $text;

                foreach ( $patterns as $pattern ) {
                    if ( 1 === preg_match( $pattern, $subject ) ) {
                        $kinds[] = $kind;
                        break;
                    }
                }
            }

            return $kinds;
        }

        public function mask( $text )
        {
            $text = (string) $text;

            foreach ( $this->contact_patterns() as $kind => $patterns ) {
                foreach ( $patterns as $pattern ) {
                    if ( 'phone' === $kind ) {
                        $text = $this->mask_phones( $text, $pattern );
                        continue;
                    }

                    $masked = preg_replace( $pattern, '•••', $text );
                    $text   = is_string( $masked ) ? $masked : $text;
                }
            }

            return $text;
        }

        private function mask_phones( $text, $pattern )
        {
            $dates = $this->without_dates( $text );

            if ( strlen( $dates ) !== strlen( $text ) || ! preg_match_all( $pattern, $dates, $found, PREG_OFFSET_CAPTURE ) ) {
                return $text;
            }

            foreach ( array_reverse( $found[0] ) as $match ) {
                $text = substr( $text, 0, $match[1] ) . '•••' . substr( $text, $match[1] + strlen( $match[0] ) );
            }

            return $text;
        }

        private function without_dates( $text )
        {
            $text = preg_replace_callback( '/\b\d{1,4}[.\/-]\d{1,2}[.\/-]\d{1,4}\b|\b\d{1,2}:\d{2}(?::\d{2})?\b/u', function ( $match ) {
                return str_repeat( ' ', strlen( $match[0] ) );
            }, (string) $text );

            return is_string( $text ) ? $text : '';
        }

        private function contact_patterns()
        {
            $at  = '(?:\s*@\s*|\s*[\(\[\{]\s*at\s*[\)\]\}]\s*|\s+at\s+)';
            $dot = '(?:\s*\.\s*|\s*[\(\[\{]\s*dot\s*[\)\]\}]\s*|\s+dot\s+)';

            $messengers = 'whats\s*app|telegram|signal|viber|we\s*chat|kik|snap(?:chat)?|skype|discord|insta(?:gram)?|line|tg';

            return array(
                'email'  => array(
                    '/[\p{L}\p{N}._%+-]+' . $at . '[\p{L}\p{N}-]+(?:' . $dot . '[\p{L}\p{N}-]+)*' . $dot . '\p{L}{2,24}(?![\p{L}\p{N}])/iu',
                ),
                'phone'  => array(
                    '/(?<![\p{N}])(?:\+|00)?\p{N}(?:[\s.()\/-]{0,3}\p{N}){8,14}(?![\p{N}])/u',
                ),
                'handle' => array(
                    '~(?<![\p{L}\p{N}])(?:t\.me|wa\.me|telegram\.me)/[\p{L}\p{N}_+]{3,}~iu',
                    '/(?<![\p{L}\p{N}])(?:' . $messengers . ')(?![\p{L}\p{N}])(?:\s+\p{L}{1,6}){0,2}\s*[:=-]?\s*@[\p{L}\p{N}._]{3,}/iu',
                ),
            );
        }

        public function check( array $action, array $rules )
        {
            $a = array_merge( array(
                'plugin'      => '',
                'actor'       => 0,
                'action'      => 'send',
                'trust'       => 'new',
                'scope'       => '',
                'contacts'    => 0,
                'fingerprint' => '',
                'links'       => 0,
                'details'     => array(),
                'signals'     => array(),
                'record'      => true,
                'now'         => time(),
            ), $action );

            $a['plugin']   = (string) $a['plugin'];
            $a['actor']    = (int) $a['actor'];
            $a['action']   = (string) $a['action'];
            $a['scope']    = (string) $a['scope'];
            $a['contacts'] = max( 0, (int) $a['contacts'] );
            $a['links']    = max( 0, (int) $a['links'] );
            $a['now']      = (int) $a['now'];
            $a['trust']    = in_array( $a['trust'], array( 'new', 'established', 'trusted' ), true ) ? $a['trust'] : 'new';

            $answer = array(
                'outcome'      => 'deliver',
                'would'        => 'deliver',
                'mask'         => false,
                'reasons'      => array(),
                'strikes'      => 0,
                'review'       => false,
                'would_review' => false,
                'id'           => 0,
            );

            if ( '' === $a['plugin'] || 'trusted' === $a['trust'] ) {
                return $answer;
            }

            $lock = $a['record'] ? $this->lock( 'spam|' . $a['plugin'] . '|' . $a['actor'], self::LOCK_WAIT ) : null;

            try {
                $reasons = $this->tripped( $a, $rules );

                foreach ( $reasons as $reason ) {
                    $rank = isset( self::OUTCOMES[ $reason['outcome'] ] ) ? self::OUTCOMES[ $reason['outcome'] ] : 0;

                    if ( 'mask' === $reason['outcome'] ) {
                        $answer['mask'] = $answer['mask'] || ! $reason['monitor'];
                    }

                    if ( $rank > self::OUTCOMES[ $answer['would'] ] ) {
                        $answer['would'] = $reason['outcome'];
                    }

                    if ( ! $reason['monitor'] && $rank > self::OUTCOMES[ $answer['outcome'] ] ) {
                        $answer['outcome'] = $reason['outcome'];
                    }
                }

                $answer['reasons'] = $reasons;

                $stops     = array_filter( $reasons, function ( $reason ) { return in_array( $reason['outcome'], array( 'hold', 'refuse' ), true ); } );
                $strikes   = count( array_filter( $stops, function ( $reason ) { return ! $reason['monitor']; } ) );
                $monitored = count( $stops ) - $strikes;

                if ( $a['record'] ) {
                    $answer['id'] = $this->store()->insert( array(
                        'plugin'      => $a['plugin'],
                        'actor'       => $a['actor'],
                        'action'      => $a['action'],
                        'scope'       => $a['scope'],
                        'contacts'    => $a['contacts'],
                        'fingerprint' => (string) $a['fingerprint'],
                        'rules'       => implode( ',', array_unique( array_column( $reasons, 'rule' ) ) ),
                        'strikes'     => $strikes,
                        'monitored'   => $monitored,
                        'decision'    => $answer['outcome'],
                        'would'       => $answer['would'],
                        'trust'       => $a['trust'],
                        'created_at'  => $a['now'],
                    ) );
                }

                $spec = isset( $rules['strikes'] ) && is_array( $rules['strikes'] ) ? $rules['strikes'] : null;

                if ( $spec ) {
                    $window = max( 60, (int) ( $spec['window'] ?? DAY_IN_SECONDS ) );
                    $total  = $this->store()->strikes( $a['plugin'], $a['actor'], $a['now'] - $window );

                    if ( ! $a['record'] ) {
                        $total['strikes']   += $strikes;
                        $total['monitored'] += $monitored;
                    }

                    $limit = max( 1, (int) ( $spec['limit'] ?? 3 ) );

                    $answer['strikes']      = (int) $total['strikes'];
                    $answer['review']       = empty( $spec['monitor'] ) && $total['strikes'] >= $limit && $strikes > 0;
                    $answer['would_review'] = ( $total['strikes'] + $total['monitored'] ) >= $limit && ( $strikes + $monitored ) > 0;
                }

                foreach ( $reasons as $reason ) {
                    if ( ! empty( $reason['review'] ) ) {
                        if ( $reason['monitor'] ) {
                            $answer['would_review'] = true;
                        } else {
                            $answer['review'] = true;
                        }
                    }
                }
            } finally {
                $this->unlock( $lock );
            }

            return apply_filters( 'wordplus_spam_decision', $answer, $a, $rules );
        }

        private function tripped( array $a, array $rules )
        {
            $tripped = array();
            $store   = $this->store();
            $rules   = apply_filters( 'wordplus_spam_rules', $rules, $a );

            foreach ( $rules as $code => $levels ) {
                if ( 'strikes' === $code || ! is_array( $levels ) || empty( $levels[ $a['trust'] ] ) || ! is_array( $levels[ $a['trust'] ] ) ) {
                    continue;
                }

                $spec = array_merge( array( 'limit' => 0, 'window' => HOUR_IN_SECONDS, 'outcome' => 'hold', 'monitor' => false ), $levels[ $a['trust'] ] );

                if ( 'deliver' === $spec['outcome'] ) {
                    continue;
                }

                $limit  = max( 0, (int) $spec['limit'] );
                $window = max( 1, (int) $spec['window'] );
                $since  = $a['now'] - $window;
                $value  = null;

                switch ( $code ) {
                    case 'new_contacts_hour':
                    case 'new_contacts_day':
                        if ( $a['contacts'] > 0 && in_array( $a['action'], self::REACHES, true ) ) {
                            $value = $store->contacts( $a['plugin'], $a['actor'], self::REACHES, $since ) + $a['contacts'];
                        }
                        break;

                    case 'starts_hour':
                    case 'starts_day':
                        if ( 'start' === $a['action'] ) {
                            $value = $store->count( $a['plugin'], $a['actor'], array( 'start' ), $since ) + 1;
                        }
                        break;

                    case 'speed':
                        if ( in_array( $a['action'], self::SENDS, true ) ) {
                            $value = $store->count( $a['plugin'], $a['actor'], self::SENDS, $since ) + 1;
                        }
                        break;

                    case 'calls':
                        if ( 'call' === $a['action'] ) {
                            $value = $store->count( $a['plugin'], $a['actor'], array( 'call' ), $since ) + 1;
                        }
                        break;

                    case 'same_text_scopes':
                        if ( '' !== $a['fingerprint'] && in_array( $a['action'], self::SENDS, true ) ) {
                            $scopes = $store->scopes( $a['plugin'], $a['actor'], $a['fingerprint'], $since );
                            $scopes[] = $a['scope'];
                            $value  = count( array_unique( array_map( 'strval', $scopes ) ) );
                        }
                        break;

                    case 'same_text_actors':
                        if ( '' !== $a['fingerprint'] && 'new' === $a['trust'] && in_array( $a['action'], self::SENDS, true ) ) {
                            $actors   = $store->actors( $a['plugin'], $a['fingerprint'], $since, 'new' );
                            $actors[] = $a['actor'];
                            $actors   = array_values( array_unique( array_map( 'intval', $actors ) ) );
                            $value    = count( $actors );
                        }
                        break;

                    case 'links':
                        if ( $a['links'] > 0 ) {
                            $value = $a['links'];
                        }
                        break;

                    case 'details':
                        if ( ! empty( $a['details'] ) ) {
                            $value = count( (array) $a['details'] );
                        }
                        break;

                    case 'first_contact':
                        if ( $a['contacts'] > 0 && 'new' === $a['trust'] && in_array( $a['action'], self::REACHES, true ) ) {
                            $value = $a['contacts'];
                        }
                        break;

                    default:
                        $value = null;
                }

                if ( null === $value || $value <= $limit ) {
                    continue;
                }

                $reason = array(
                    'rule'    => (string) $code,
                    'outcome' => $this->outcome( $spec['outcome'], 'details' === $code ),
                    'monitor' => (bool) $spec['monitor'],
                    'value'   => (int) $value,
                    'limit'   => $limit,
                    'window'  => $window,
                );

                if ( 'same_text_actors' === $code ) {
                    $reason['actors'] = $actors;
                    $reason['review'] = true;
                }

                if ( 'refuse' === $reason['outcome'] && in_array( $code, array( 'starts_hour', 'starts_day', 'speed', 'calls' ), true ) ) {
                    $actions = 'speed' === $code ? self::SENDS : array( 'starts_hour' === $code || 'starts_day' === $code ? 'start' : 'call' );
                    $times   = $store->times( $a['plugin'], $a['actor'], $actions, $since, $value - $limit );
                    $oldest  = $times ? (int) end( $times ) : $a['now'];

                    $reason['retry_after'] = max( 1, $oldest + $window - $a['now'] );
                } elseif ( 'refuse' === $reason['outcome'] ) {
                    $reason['retry_after'] = $window;
                }

                $tripped[] = $reason;
            }

            foreach ( (array) $a['signals'] as $code => $signal ) {
                $signal = is_array( $signal ) ? $signal : array( 'outcome' => $signal );

                $tripped[] = array(
                    'rule'    => (string) $code,
                    'outcome' => $this->outcome( $signal['outcome'] ?? 'hold', false ),
                    'monitor' => ! empty( $signal['monitor'] ),
                    'value'   => 1,
                    'limit'   => 0,
                    'window'  => 0,
                );
            }

            return $tripped;
        }

        private function outcome( $outcome, $may_mask )
        {
            $outcome = (string) $outcome;

            if ( $may_mask && 'mask' === $outcome ) {
                return 'mask';
            }

            return isset( self::OUTCOMES[ $outcome ] ) ? $outcome : 'hold';
        }

        public function note( $plugin, $actor, $action, $scope = '', $now = null )
        {
            return $this->store()->insert( array(
                'plugin'      => (string) $plugin,
                'actor'       => (int) $actor,
                'action'      => (string) $action,
                'scope'       => (string) $scope,
                'contacts'    => 0,
                'fingerprint' => '',
                'rules'       => '',
                'strikes'     => 0,
                'monitored'   => 0,
                'decision'    => 'deliver',
                'would'       => 'deliver',
                'trust'       => '',
                'created_at'  => null === $now ? time() : (int) $now,
            ) );
        }

        public function distinct( $plugin, $actor, $action, $seconds, $now = null )
        {
            $now = null === $now ? time() : (int) $now;

            return (int) $this->store()->distinct( (string) $plugin, (int) $actor, (string) $action, $now - (int) $seconds );
        }

        public function strikes( $plugin, $actor, $seconds = DAY_IN_SECONDS, $now = null )
        {
            $now   = null === $now ? time() : (int) $now;
            $total = $this->store()->strikes( (string) $plugin, (int) $actor, $now - (int) $seconds );

            return (int) $total['strikes'];
        }

        public function history( $plugin, $actor, $seconds = 0, $limit = 50, $now = null )
        {
            $now     = null === $now ? time() : (int) $now;
            $seconds = $seconds > 0 ? $seconds : 7 * DAY_IN_SECONDS;

            return $this->store()->recent( (string) $plugin, (int) $actor, $now - (int) $seconds, max( 1, (int) $limit ) );
        }

        public function flagged( $plugin, $seconds = DAY_IN_SECONDS, $limit = 50, $now = null )
        {
            $now = null === $now ? time() : (int) $now;

            return $this->store()->flagged( (string) $plugin, $now - (int) $seconds, max( 1, (int) $limit ) );
        }

        public function forget( $plugin, $actor )
        {
            return $this->store()->forget( (string) $plugin, (int) $actor );
        }

        public function prune( $now = null )
        {
            $now = null === $now ? time() : (int) $now;

            return $this->store()->prune( $now - self::DAYS * DAY_IN_SECONDS );
        }

        public function lock( $key, $timeout = self::LOCK_WAIT )
        {
            global $wpdb;

            if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
                return null;
            }

            $name = 'wplus_' . md5( ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . $wpdb->base_prefix . '|' . $key );
            $got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, (int) $timeout ) );

            if ( null === $got ) {
                return null;
            }

            return 1 === (int) $got ? $name : false;
        }

        public function unlock( $lock )
        {
            global $wpdb;

            if ( is_string( $lock ) && '' !== $lock && is_object( $wpdb ) ) {
                $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
            }
        }
    }
}
