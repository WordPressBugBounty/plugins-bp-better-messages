<?php
defined( 'ABSPATH' ) || exit;

if ( ! interface_exists( 'WordPlus_SDK_Addresses_Store' ) ) {
    interface WordPlus_SDK_Addresses_Store
    {
        public function find( $person, $ip );

        public function insert( array $row );

        public function touch( $person, $ip, $time, array $location );

        public function of( $person, $limit );

        public function last_of( array $persons );

        public function others_on( $ip, $person, $limit );

        public function trim( $person, $keep );

        public function prune( $before );

        public function forget( $person );

        public function clear();

        public function replace( $person, $ip, array $row );
    }
}

if ( ! class_exists( 'WordPlus_SDK_Addresses_Table' ) ) {
    final class WordPlus_SDK_Addresses_Table implements WordPlus_SDK_Addresses_Store
    {
        const SCHEMA_OPTION = 'wordplus_addresses_schema';

        const SCHEMA = 2;

        public function table()
        {
            global $wpdb;

            return $wpdb->prefix . 'wordplus_addresses';
        }

        public function ready()
        {
            return $this->exists() && $this->ensure();
        }

        private function ensure()
        {
            global $wpdb;

            if ( ! is_object( $wpdb ) ) {
                return false;
            }

            if ( (int) get_option( self::SCHEMA_OPTION, 0 ) >= self::SCHEMA ) {
                return true;
            }

            if ( ! function_exists( 'dbDelta' ) ) {
                if ( ! is_readable( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
                    return false;
                }

                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }

            $table = $this->table();

            dbDelta( "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  person bigint(20) NOT NULL,
  ip varbinary(16) NOT NULL,
  country char(2) NOT NULL DEFAULT '',
  region varchar(80) NOT NULL DEFAULT '',
  city varchar(80) NOT NULL DEFAULT '',
  first_seen datetime NOT NULL,
  last_seen datetime NOT NULL,
  seen int(10) unsigned NOT NULL DEFAULT 1,
  sealed text,
  PRIMARY KEY  (id),
  UNIQUE KEY person_ip (person,ip),
  KEY ip (ip),
  KEY last_seen (last_seen)
) " . $wpdb->get_charset_collate() . ';' );

            if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
                return false;
            }

            update_option( self::SCHEMA_OPTION, self::SCHEMA, false );

            return true;
        }

        private function exists()
        {
            global $wpdb;

            if ( ! is_object( $wpdb ) ) {
                return false;
            }

            return (int) get_option( self::SCHEMA_OPTION, 0 ) >= 1;
        }

        private static function row( $raw )
        {
            if ( ! is_array( $raw ) ) {
                return null;
            }

            return array(
                'person'     => (int) $raw['person'],
                'ip'         => (string) @inet_ntop( $raw['ip'] ), // phpcs:ignore
                'country'    => (string) $raw['country'],
                'region'     => (string) $raw['region'],
                'city'       => (string) $raw['city'],
                'first_seen' => (int) strtotime( $raw['first_seen'] . ' UTC' ),
                'last_seen'  => (int) strtotime( $raw['last_seen'] . ' UTC' ),
                'seen'       => (int) $raw['seen'],
                'sealed'     => isset( $raw['sealed'] ) ? (string) $raw['sealed'] : '',
            );
        }

        public function find( $person, $ip )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return null;
            }

            $raw = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE person = %d AND ip = %s", (int) $person, inet_pton( $ip ) ), ARRAY_A );

            return self::row( $raw );
        }

        public function insert( array $row )
        {
            global $wpdb;

            if ( ! $this->ensure() ) {
                return false;
            }

            $sealed = isset( $row['sealed'] ) ? (string) $row['sealed'] : '';

            return false !== $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$this->table()} ( person, ip, country, region, city, first_seen, last_seen, seen, sealed ) VALUES ( %d, %s, %s, %s, %s, %s, %s, 1, NULLIF( %s, '' ) )",
                (int) $row['person'],
                inet_pton( $row['ip'] ),
                (string) $row['country'],
                (string) $row['region'],
                (string) $row['city'],
                gmdate( 'Y-m-d H:i:s', (int) $row['first_seen'] ),
                gmdate( 'Y-m-d H:i:s', (int) $row['last_seen'] ),
                $sealed
            ) );
        }

        public function replace( $person, $ip, array $row )
        {
            global $wpdb;

            $old = @inet_pton( (string) $ip ); // phpcs:ignore
            $new = @inet_pton( (string) $row['ip'] ); // phpcs:ignore

            if ( false === $old || false === $new || ! $this->ready() ) {
                return false;
            }

            if ( $new !== $old && null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE person = %d AND ip = %s", (int) $person, $new ) ) ) {
                return false !== $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE person = %d AND ip = %s", (int) $person, $old ) );
            }

            return false !== $wpdb->query( $wpdb->prepare(
                "UPDATE {$this->table()} SET ip = %s, country = %s, region = %s, city = %s, sealed = NULLIF( %s, '' ) WHERE person = %d AND ip = %s",
                $new,
                (string) $row['country'],
                (string) $row['region'],
                (string) $row['city'],
                isset( $row['sealed'] ) ? (string) $row['sealed'] : '',
                (int) $person,
                $old
            ) );
        }

        public function touch( $person, $ip, $time, array $location )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "UPDATE {$this->table()} SET last_seen = %s, seen = seen + 1,
                 country = IF( country = '', %s, country ), region = IF( country = '' OR region = '', %s, region ), city = IF( country = '' OR city = '', %s, city )
                 WHERE person = %d AND ip = %s",
                gmdate( 'Y-m-d H:i:s', (int) $time ),
                (string) $location['country'],
                (string) $location['region'],
                (string) $location['city'],
                (int) $person,
                inet_pton( $ip )
            ) );
        }

        public function of( $person, $limit )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array();
            }

            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE person = %d ORDER BY last_seen DESC LIMIT %d", (int) $person, (int) $limit ), ARRAY_A );

            return array_values( array_filter( array_map( array( __CLASS__, 'row' ), (array) $rows ) ) );
        }

        public function last_of( array $persons )
        {
            global $wpdb;

            $persons = array_values( array_unique( array_map( 'intval', $persons ) ) );

            if ( ! $this->exists() || array() === $persons ) {
                return array();
            }

            $in   = implode( ',', $persons );
            $rows = $wpdb->get_results(
                "SELECT a.* FROM {$this->table()} a
                 INNER JOIN ( SELECT person, MAX( last_seen ) AS newest FROM {$this->table()} WHERE person IN ( {$in} ) GROUP BY person ) b
                 ON a.person = b.person AND a.last_seen = b.newest",
                ARRAY_A
            ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

            $last = array();

            foreach ( (array) $rows as $raw ) {
                $row = self::row( $raw );

                if ( $row && ! isset( $last[ $row['person'] ] ) ) {
                    $last[ $row['person'] ] = $row;
                }
            }

            return $last;
        }

        public function others_on( $ip, $person, $limit )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return array();
            }

            return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT person FROM {$this->table()} WHERE ip = %s AND person <> %d ORDER BY last_seen DESC LIMIT %d", inet_pton( $ip ), (int) $person, (int) $limit ) ) );
        }

        public function trim( $person, $keep )
        {
            global $wpdb;

            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$this->table()} WHERE person = %d ORDER BY last_seen DESC LIMIT 1000 OFFSET %d", (int) $person, (int) $keep ) );

            if ( ! empty( $ids ) ) {
                $wpdb->query( "DELETE FROM {$this->table()} WHERE id IN ( " . implode( ',', array_map( 'intval', $ids ) ) . ' )' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            }
        }

        public function prune( $before )
        {
            global $wpdb;

            if ( ! $this->exists() ) {
                return 0;
            }

            return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE last_seen < %s LIMIT 5000", gmdate( 'Y-m-d H:i:s', (int) $before ) ) );
        }

        public function forget( $person )
        {
            global $wpdb;

            if ( $this->exists() ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE person = %d", (int) $person ) );
            }
        }

        public function clear()
        {
            global $wpdb;

            if ( $this->exists() ) {
                $wpdb->query( "TRUNCATE TABLE {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            }
        }

        public function uninstall()
        {
            global $wpdb;

            $wpdb->query( "DROP TABLE IF EXISTS {$this->table()}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            delete_option( self::SCHEMA_OPTION );
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Addresses_Memory' ) ) {
    final class WordPlus_SDK_Addresses_Memory implements WordPlus_SDK_Addresses_Store
    {
        public $rows = array();

        private static function key( $person, $ip )
        {
            return (int) $person . '|' . $ip;
        }

        public function find( $person, $ip )
        {
            $key = self::key( $person, $ip );

            return isset( $this->rows[ $key ] ) ? $this->rows[ $key ] : null;
        }

        public function insert( array $row )
        {
            $key = self::key( $row['person'], $row['ip'] );

            if ( isset( $this->rows[ $key ] ) ) {
                return true;
            }

            $this->rows[ $key ] = array_merge( $row, array( 'person' => (int) $row['person'], 'seen' => 1 ) );

            return true;
        }

        public function touch( $person, $ip, $time, array $location )
        {
            $key = self::key( $person, $ip );

            if ( ! isset( $this->rows[ $key ] ) ) {
                return;
            }

            $this->rows[ $key ]['last_seen'] = (int) $time;
            $this->rows[ $key ]['seen']++;

            if ( '' === $this->rows[ $key ]['country'] ) {
                $this->rows[ $key ] = array_merge( $this->rows[ $key ], array_intersect_key( $location, array_flip( array( 'country', 'region', 'city' ) ) ) );
            }
        }

        private function sorted( array $rows )
        {
            usort( $rows, function ( $a, $b ) {
                return $b['last_seen'] <=> $a['last_seen'];
            } );

            return $rows;
        }

        public function of( $person, $limit )
        {
            return array_slice( $this->sorted( array_values( array_filter( $this->rows, function ( $row ) use ( $person ) {
                return (int) $row['person'] === (int) $person;
            } ) ) ), 0, (int) $limit );
        }

        public function last_of( array $persons )
        {
            $last = array();

            foreach ( $persons as $person ) {
                $rows = $this->of( $person, 1 );

                if ( $rows ) {
                    $last[ (int) $person ] = $rows[0];
                }
            }

            return $last;
        }

        public function others_on( $ip, $person, $limit )
        {
            $others = array_filter( $this->rows, function ( $row ) use ( $ip, $person ) {
                return $row['ip'] === $ip && (int) $row['person'] !== (int) $person;
            } );

            return array_slice( array_map( function ( $row ) {
                return (int) $row['person'];
            }, $this->sorted( array_values( $others ) ) ), 0, (int) $limit );
        }

        public function trim( $person, $keep )
        {
            foreach ( array_slice( $this->of( $person, PHP_INT_MAX ), (int) $keep ) as $row ) {
                unset( $this->rows[ self::key( $row['person'], $row['ip'] ) ] );
            }
        }

        public function prune( $before )
        {
            $count = 0;

            foreach ( $this->rows as $key => $row ) {
                if ( $row['last_seen'] < $before ) {
                    unset( $this->rows[ $key ] );
                    $count++;
                }
            }

            return $count;
        }

        public function forget( $person )
        {
            foreach ( $this->rows as $key => $row ) {
                if ( (int) $row['person'] === (int) $person ) {
                    unset( $this->rows[ $key ] );
                }
            }
        }

        public function clear()
        {
            $this->rows = array();
        }

        public function replace( $person, $ip, array $row )
        {
            $old = self::key( $person, $ip );
            $new = self::key( $person, $row['ip'] );

            if ( ! isset( $this->rows[ $old ] ) ) {
                return true;
            }

            $kept = $this->rows[ $old ];

            unset( $this->rows[ $old ] );

            if ( ! isset( $this->rows[ $new ] ) ) {
                $this->rows[ $new ] = array_merge( $kept, array_intersect_key( $row, array_flip( array( 'ip', 'country', 'region', 'city', 'sealed' ) ) ) );
            }

            return true;
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Addresses_Sealed' ) ) {
    final class WordPlus_SDK_Addresses_Sealed implements WordPlus_SDK_Addresses_Store
    {
        const PURPOSE = 'address';

        private $store;

        private $encryption;

        public function __construct( WordPlus_SDK_Addresses_Store $store, $encryption = null )
        {
            $this->store      = $store;
            $this->encryption = is_object( $encryption ) ? $encryption : null;
        }

        public function inner()
        {
            return $this->store;
        }

        public function sealing()
        {
            return null !== $this->encryption && $this->encryption->shares();
        }

        private static function canonical( $ip )
        {
            $binary = @inet_pton( (string) $ip ); // phpcs:ignore

            return false === $binary ? '' : (string) inet_ntop( $binary );
        }

        public function alias( $ip )
        {
            $ip = self::canonical( $ip );

            if ( '' === $ip || null === $this->encryption ) {
                return '';
            }

            $hash = (string) $this->encryption->exact( WordPlus_SDK_Encryption::SHARED, $ip, self::PURPOSE );

            return 32 === strlen( $hash ) ? (string) inet_ntop( (string) hex2bin( $hash ) ) : '';
        }

        public function mark( $ip )
        {
            $alias = $this->sealing() ? $this->alias( $ip ) : '';

            return '' === $alias ? (string) $ip : $alias;
        }

        private static function context( $person )
        {
            return 'address:' . (int) $person;
        }

        private function pack( $person, array $row )
        {
            if ( null === $this->encryption ) {
                return null;
            }

            return $this->encryption->seal( WordPlus_SDK_Encryption::SHARED, (string) wp_json_encode( array(
                'ip'      => self::canonical( $row['ip'] ),
                'country' => (string) $row['country'],
                'region'  => (string) $row['region'],
                'city'    => (string) $row['city'],
            ) ), self::context( $person ) );
        }

        private function unpack( $person, $sealed )
        {
            if ( null === $this->encryption || ! WordPlus_SDK_Encryption::is_sealed( $sealed ) ) {
                return null;
            }

            $plain = $this->encryption->open( WordPlus_SDK_Encryption::SHARED, $sealed, self::context( $person ) );
            $data  = is_string( $plain ) ? json_decode( $plain, true ) : null;

            if ( ! is_array( $data ) || empty( $data['ip'] ) || '' === self::canonical( $data['ip'] ) ) {
                return null;
            }

            return array(
                'ip'      => self::canonical( $data['ip'] ),
                'country' => isset( $data['country'] ) ? (string) $data['country'] : '',
                'region'  => isset( $data['region'] ) ? (string) $data['region'] : '',
                'city'    => isset( $data['city'] ) ? (string) $data['city'] : '',
            );
        }

        private static function is_row_sealed( $row )
        {
            return is_array( $row ) && isset( $row['sealed'] ) && '' !== (string) $row['sealed'];
        }

        private function opened( $row )
        {
            if ( ! is_array( $row ) ) {
                return null;
            }

            if ( self::is_row_sealed( $row ) ) {
                $held = $this->unpack( $row['person'], $row['sealed'] );

                if ( null === $held ) {
                    return null;
                }

                $row = array_merge( $row, $held );
            }

            unset( $row['sealed'] );

            return $row;
        }

        private function sealed_row( $person, $ip )
        {
            $alias = $this->alias( $ip );
            $row   = '' === $alias ? null : $this->store->find( $person, $alias );

            return self::is_row_sealed( $row ) ? $row : null;
        }

        public function find( $person, $ip )
        {
            $sealed = $this->sealed_row( $person, $ip );

            if ( null !== $sealed ) {
                $row = $this->opened( $sealed );

                if ( null !== $row ) {
                    return $row;
                }
            }

            $row = $this->store->find( $person, $ip );

            return self::is_row_sealed( $row ) ? null : $this->opened( $row );
        }

        public function insert( array $row )
        {
            if ( $this->sealing() ) {
                $alias  = $this->alias( $row['ip'] );
                $sealed = '' === $alias ? null : $this->pack( $row['person'], $row );

                if ( null !== $sealed ) {
                    return $this->store->insert( array_merge( $row, array( 'ip' => $alias, 'country' => '', 'region' => '', 'city' => '', 'sealed' => $sealed ) ) );
                }
            }

            return $this->store->insert( $row );
        }

        public function touch( $person, $ip, $time, array $location )
        {
            $sealed = $this->sealed_row( $person, $ip );

            if ( null === $sealed ) {
                $this->store->touch( $person, $ip, $time, $location );

                return;
            }

            $alias = $sealed['ip'];

            $this->store->touch( $person, $alias, $time, array( 'country' => '', 'region' => '', 'city' => '' ) );

            if ( '' === (string) $location['country'] ) {
                return;
            }

            $held = $this->unpack( $person, $sealed['sealed'] );

            if ( null === $held || '' !== $held['country'] ) {
                return;
            }

            $again = $this->pack( $person, array_merge( $held, array(
                'country' => (string) $location['country'],
                'region'  => (string) $location['region'],
                'city'    => (string) $location['city'],
            ) ) );

            if ( null !== $again ) {
                $this->store->replace( $person, $alias, array( 'ip' => $alias, 'country' => '', 'region' => '', 'city' => '', 'sealed' => $again ) );
            }
        }

        public function of( $person, $limit )
        {
            return array_values( array_filter( array_map( array( $this, 'opened' ), (array) $this->store->of( $person, $limit ) ) ) );
        }

        public function last_of( array $persons )
        {
            return array_filter( array_map( array( $this, 'opened' ), (array) $this->store->last_of( $persons ) ) );
        }

        public function others_on( $ip, $person, $limit )
        {
            $alias  = $this->alias( $ip );
            $others = '' === $alias ? array() : (array) $this->store->others_on( $alias, $person, $limit );

            foreach ( (array) $this->store->others_on( $ip, $person, $limit ) as $other ) {
                if ( ! in_array( $other, $others, true ) ) {
                    $others[] = $other;
                }
            }

            return array_slice( $others, 0, max( 0, (int) $limit ) );
        }

        public function trim( $person, $keep )
        {
            $this->store->trim( $person, $keep );
        }

        public function prune( $before )
        {
            return $this->store->prune( $before );
        }

        public function forget( $person )
        {
            $this->store->forget( $person );
        }

        public function clear()
        {
            $this->store->clear();
        }

        public function replace( $person, $ip, array $row )
        {
            return $this->store->replace( $person, $ip, $row );
        }

        public function convert( $mode, $person, $ip, array $row )
        {
            $sealed = isset( $row['sealed'] ) ? (string) $row['sealed'] : '';
            $ip     = self::canonical( $ip );

            if ( '' === $ip || null === $this->encryption ) {
                return 'failed';
            }

            if ( 'seal' === $mode ) {
                if ( '' === $sealed ) {
                    $held = array( 'ip' => $ip, 'country' => (string) $row['country'], 'region' => (string) $row['region'], 'city' => (string) $row['city'] );
                } else {
                    if ( WordPlus_SDK_Encryption::sealed_with( $sealed ) === $this->encryption->key_id() ) {
                        return 'skipped';
                    }

                    $held = $this->unpack( $person, $sealed );
                }

                $alias = null === $held ? '' : $this->alias( $held['ip'] );
                $again = '' === $alias ? null : $this->pack( $person, $held );

                if ( null === $again ) {
                    return 'failed';
                }

                return $this->store->replace( $person, $ip, array( 'ip' => $alias, 'country' => '', 'region' => '', 'city' => '', 'sealed' => $again ) ) ? 'changed' : 'failed';
            }

            if ( '' === $sealed ) {
                return 'skipped';
            }

            $held = $this->unpack( $person, $sealed );

            if ( null === $held ) {
                return 'failed';
            }

            return $this->store->replace( $person, $ip, array_merge( $held, array( 'sealed' => '' ) ) ) ? 'changed' : 'failed';
        }
    }
}

if ( ! class_exists( 'WordPlus_SDK_Addresses' ) ) {
    final class WordPlus_SDK_Addresses
    {
        const KEEP = 20;

        const EVERY = 3600;

        const META = 'wordplus_address_seen';

        private $sdk;

        private $store = null;

        private $listed = null;

        public function __construct( WordPlus_SDK $sdk )
        {
            $this->sdk = $sdk;
        }

        public function use_store( WordPlus_SDK_Addresses_Store $store )
        {
            $this->store = new WordPlus_SDK_Addresses_Sealed( $store, $this->sdk->encryption() );
        }

        private function store()
        {
            if ( null === $this->store ) {
                $this->store = new WordPlus_SDK_Addresses_Sealed( new WordPlus_SDK_Addresses_Table(), $this->sdk->encryption() );
            }

            return $this->store;
        }

        public function encryption_collection()
        {
            $table = $this->store()->inner();

            if ( ! $table instanceof WordPlus_SDK_Addresses_Table || ! $table->ready() ) {
                return null;
            }

            return array(
                'label'  => _x( 'IP addresses of guests and members with their locations', 'Encryption', 'wordplus-sdk' ),
                'table'  => $table->table(),
                'key'    => 'id',
                'select' => array( 'person', 'ip', 'country', 'region', 'city', 'sealed' ),
                'handle' => array( $this, 'encryption_row' ),
            );
        }

        public function encryption_row( $mode, $row )
        {
            $ip = (string) @inet_ntop( (string) $row->ip ); // phpcs:ignore

            if ( '' === $ip ) {
                return 'failed';
            }

            $result = $this->store()->convert( $mode, (int) $row->person, $ip, array(
                'country' => (string) $row->country,
                'region'  => (string) $row->region,
                'city'    => (string) $row->city,
                'sealed'  => (string) $row->sealed,
            ) );

            if ( 'changed' === $result && (int) $row->person > 0 ) {
                delete_user_meta( (int) $row->person, self::META );
            }

            return $result;
        }

        private function geo()
        {
            return $this->sdk->geo();
        }

        public function seen( $person, $ip = null, $now = null )
        {
            $person = (int) $person;
            $now    = null === $now ? time() : (int) $now;

            if ( 0 === $person || ! $this->geo() ) {
                return false;
            }

            require_once $this->sdk->path( 'src/class-client.php' );

            $ip = WordPlus_SDK_Client::address( null === $ip ? WordPlus_SDK_Client::ip() : $ip );

            if ( '' === $ip ) {
                return false;
            }

            if ( ! $this->geo()->keeps_addresses() ) {
                return false;
            }

            if ( $person > 0 ) {
                $last  = (string) get_user_meta( $person, self::META, true );
                $named = $this->store()->mark( $ip );
                $mark  = $named . '|' . $now;

                if ( '' !== $last ) {
                    list( $last_ip, $last_time ) = array_pad( explode( '|', $last, 2 ), 2, 0 );

                    if ( $last_ip === $named && (int) $last_time > $now - self::EVERY ) {
                        return false;
                    }
                }

                update_user_meta( $person, self::META, $mark );
            }

            $row = $this->store()->find( $person, $ip );

            if ( null !== $row ) {
                if ( $row['last_seen'] > $now - self::EVERY ) {
                    return false;
                }

                $this->store()->touch( $person, $ip, $now, '' === $row['country'] ? $this->geo()->locate( $ip ) : array( 'country' => '', 'region' => '', 'city' => '' ) );

                return true;
            }

            $location = $this->geo()->locate( $ip );

            $kept = $this->store()->insert( array(
                'person'     => $person,
                'ip'         => $ip,
                'country'    => $location['country'],
                'region'     => $location['region'],
                'city'       => $location['city'],
                'first_seen' => $now,
                'last_seen'  => $now,
            ) );

            if ( $kept ) {
                $this->store()->trim( $person, self::KEEP );
            }

            return (bool) $kept;
        }

        public function rest_request( $response = null )
        {
            $user = get_current_user_id();

            if ( $user > 0 ) {
                $this->seen( $user );
            }

            return $response;
        }

        public function signed_in( $login, $user = null )
        {
            if ( is_object( $user ) && ! empty( $user->ID ) ) {
                $this->seen( (int) $user->ID );
            }
        }

        public function of( $person, $limit = self::KEEP )
        {
            $rows = $this->store()->of( (int) $person, max( 1, min( self::KEEP, (int) $limit ) ) );

            foreach ( $rows as $index => $row ) {
                $rows[ $index ]['others'] = $this->store()->others_on( $row['ip'], (int) $person, 10 );
            }

            return $rows;
        }

        public function last_of( array $persons )
        {
            return $this->store()->last_of( $persons );
        }

        public function last( $person )
        {
            $last = $this->last_of( array( (int) $person ) );

            return isset( $last[ (int) $person ] ) ? $last[ (int) $person ] : null;
        }

        public function others_on( $ip, $person = 0, $limit = 10 )
        {
            return $this->store()->others_on( (string) $ip, (int) $person, (int) $limit );
        }

        public function prune( $now = null )
        {
            $now = null === $now ? time() : (int) $now;
            $geo = $this->geo();

            return $this->store()->prune( $now - ( $geo ? $geo->days() : WordPlus_SDK_Geo::DAYS ) * DAY_IN_SECONDS );
        }

        public function forget( $person )
        {
            $this->store()->forget( (int) $person );
        }

        public function empty_all()
        {
            $this->store()->clear();
        }

        public function forget_marks()
        {
            delete_metadata( 'user', 0, self::META, '', true );
        }

        public function can_view()
        {
            return (bool) apply_filters( 'wordplus_addresses_can_view', current_user_can( 'list_users' ) && current_user_can( 'manage_options' ) );
        }

        public function users_columns( $columns )
        {
            if ( ! $this->can_view() || ! $this->geo() || ! $this->geo()->keeps_addresses() ) {
                return $columns;
            }

            $columns['wordplus_location'] = __( 'Location', 'wordplus-sdk' );
            $columns['wordplus_address']  = __( 'Last address', 'wordplus-sdk' );

            return $columns;
        }

        public function users_column( $output, $column, $user_id )
        {
            if ( 'wordplus_location' !== $column && 'wordplus_address' !== $column ) {
                return $output;
            }

            if ( null === $this->listed ) {
                $ids = array( (int) $user_id );

                if ( isset( $GLOBALS['wp_list_table'] ) && is_object( $GLOBALS['wp_list_table'] ) && ! empty( $GLOBALS['wp_list_table']->items ) ) {
                    foreach ( (array) $GLOBALS['wp_list_table']->items as $item ) {
                        $ids[] = is_object( $item ) ? (int) $item->ID : (int) $item;
                    }
                }

                $this->listed = $this->last_of( $ids );
            }

            $row = isset( $this->listed[ (int) $user_id ] ) ? $this->listed[ (int) $user_id ] : $this->last( (int) $user_id );

            if ( null === $row ) {
                return '—';
            }

            if ( 'wordplus_address' === $column ) {
                return '<code>' . esc_html( $row['ip'] ) . '</code>';
            }

            return $this->location_html( $row );
        }

        private function country_name( $code )
        {
            $code = strtoupper( (string) $code );

            if ( ! class_exists( 'Locale' ) || 1 !== preg_match( '/^[A-Z]{2}$/', $code ) ) {
                return $code;
            }

            $locale = function_exists( 'determine_locale' ) ? (string) determine_locale() : 'en_US';
            $name   = (string) Locale::getDisplayRegion( '-' . $code, str_replace( '_', '-', '' === $locale ? 'en_US' : $locale ) );

            return '' === $name || $name === $code ? $code : $name;
        }

        public function location_html( array $row )
        {
            if ( '' === $row['country'] ) {
                return '—';
            }

            $place = implode( ', ', array_filter( array( $row['city'], $this->country_name( $row['country'] ) ) ) );

            return '<img src="' . esc_url( $this->geo()->flags_url() . strtolower( $row['country'] ) . '.svg' ) . '" alt="" width="16" height="12" style="vertical-align:middle;margin-right:6px;border-radius:2px" loading="lazy" /> ' . esc_html( $place );
        }

        private function draws_profile( $user )
        {
            if ( ! is_object( $user ) || ! $this->can_view() || ! $this->geo() || ! $this->geo()->keeps_addresses() ) {
                return false;
            }

            return (bool) apply_filters( 'wordplus_addresses_profile_box', true, $user );
        }

        public function profile_summary( $user )
        {
            if ( ! $this->draws_profile( $user ) ) {
                return;
            }

            $last = $this->last( (int) $user->ID );
            $all  = ' <a href="#wordplus-addresses">' . esc_html__( 'All addresses', 'wordplus-sdk' ) . '</a>';

            echo '<tr class="wordplus-profile-location"><th scope="row">' . esc_html__( 'Location', 'wordplus-sdk' ) . '</th><td>' . ( $last ? $this->location_html( $last ) : '—' ) . '<p class="description">' . esc_html__( 'Where this user last connected from. Visible to administrators only', 'wordplus-sdk' ) . '</p></td></tr>';

            if ( ! $last ) {
                echo '<tr class="wordplus-profile-address"><th scope="row">' . esc_html__( 'Last address', 'wordplus-sdk' ) . '</th><td>—</td></tr>';

                return;
            }

            /* translators: %s: a date and time */
            $seen = sprintf( __( 'last seen %s', 'wordplus-sdk' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['last_seen'] ) );

            echo '<tr class="wordplus-profile-address"><th scope="row">' . esc_html__( 'Last address', 'wordplus-sdk' ) . '</th><td><code>' . esc_html( $last['ip'] ) . '</code> <span class="description">' . esc_html( $seen ) . '</span>' . $all . '</td></tr>';
        }

        public function profile_box( $user )
        {
            if ( ! $this->draws_profile( $user ) ) {
                return;
            }

            $rows = $this->of( (int) $user->ID );

            echo '<h2 id="wordplus-addresses">' . esc_html__( 'Addresses', 'wordplus-sdk' ) . '</h2>';
            echo '<p class="description">' . esc_html__( 'The IP addresses this user connected from, newest first. Visible to administrators only', 'wordplus-sdk' ) . '</p>';

            if ( array() === $rows ) {
                echo '<p>' . esc_html__( 'No addresses are stored yet.', 'wordplus-sdk' ) . '</p>';

                return;
            }

            echo '<table class="widefat striped" style="max-width:900px"><thead><tr><th>' . esc_html__( 'Address', 'wordplus-sdk' ) . '</th><th>' . esc_html__( 'Location', 'wordplus-sdk' ) . '</th><th>' . esc_html__( 'First seen', 'wordplus-sdk' ) . '</th><th>' . esc_html__( 'Last seen', 'wordplus-sdk' ) . '</th><th>' . esc_html__( 'Also used by', 'wordplus-sdk' ) . '</th></tr></thead><tbody>';

            $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

            foreach ( $rows as $row ) {
                $others = array();

                foreach ( $row['others'] as $other ) {
                    if ( $other > 0 ) {
                        $data     = get_userdata( $other );
                        $others[] = $data ? '<a href="' . esc_url( get_edit_user_link( $other ) ) . '">' . esc_html( $data->display_name ) . '</a>' : esc_html( '#' . $other );
                    } else {
                        $others[] = esc_html__( 'a guest', 'wordplus-sdk' );
                    }
                }

                echo '<tr><td><code>' . esc_html( $row['ip'] ) . '</code></td><td>' . $this->location_html( $row ) . '</td><td>' . esc_html( wp_date( $format, $row['first_seen'] ) ) . '</td><td>' . esc_html( wp_date( $format, $row['last_seen'] ) ) . '</td><td>' . ( $others ? implode( ', ', $others ) : '—' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
            }

            echo '</tbody></table>';

            $attribution = WordPlus_SDK_Geo::ATTRIBUTION;
            echo '<p class="description"><a href="' . esc_url( $attribution['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $attribution['text'] ) . '</a></p>';
        }

        public function routes()
        {
            register_rest_route( WordPlus_SDK_Geo::REST_NAMESPACE, '/addresses/(?P<person>-?\d+)', array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'rest_of' ),
                'permission_callback' => array( $this, 'can_view' ),
            ) );
        }

        public function rest_of( $request )
        {
            return array( 'addresses' => $this->of( (int) $request->get_param( 'person' ) ), 'flags' => $this->geo()->flags_url(), 'attribution' => WordPlus_SDK_Geo::ATTRIBUTION );
        }
    }
}
