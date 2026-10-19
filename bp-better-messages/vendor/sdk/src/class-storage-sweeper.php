<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WordPlus_SDK_Storage_Sweeper' ) ) {
    final class WordPlus_SDK_Storage_Sweeper
    {
        const OPTION  = 'wordplus_storage_sweeps';
        const EVERY   = 604800;
        const MIN_AGE = 604800;
        const CONFIRM = 518400;

        private $storage;

        public function __construct( WordPlus_SDK_Storage $storage )
        {
            $this->storage = $storage;
        }

        public function states()
        {
            $states = get_option( self::OPTION, array() );

            return is_array( $states ) ? $states : array();
        }

        private function blank_state()
        {
            return array(
                'running'  => false,
                'token'    => '',
                'started'  => 0,
                'finished' => 0,
                'listed'   => 0,
                'found'    => 0,
                'removed'  => 0,
                'skipped'  => '',
                'error'    => '',
            );
        }

        public function skip_reason( array $location )
        {
            if ( '' === trim( (string) $location['prefix'], '/' ) ) {
                return 'root';
            }

            $folder = $this->storage->folder_state( $location )['state'];

            return 'own' === $folder ? '' : $folder;
        }

        public function run( $budget = 20 )
        {
            $storage = $this->storage;

            if ( ! $storage->table_exists() || ! apply_filters( 'wordplus_storage_indexed', true ) ) {
                return;
            }

            $started   = microtime( true );
            $locations = $storage->get_settings()['locations'];
            $states    = array_intersect_key( $this->states(), $locations );
            $every     = (int) apply_filters( 'wordplus_storage_sweep_interval', self::EVERY );
            $changed   = false;

            foreach ( $locations as $id => $location ) {
                $state = isset( $states[ $id ] ) ? array_merge( $this->blank_state(), $states[ $id ] ) : $this->blank_state();

                if ( ! $state['running'] && $state['finished'] > time() - $every ) {
                    continue;
                }

                if ( microtime( true ) - $started >= $budget ) {
                    break;
                }

                $location = $storage->get_location( $id );
                $skip     = $this->skip_reason( $location );
                $changed  = true;

                if ( '' !== $skip ) {
                    $state['skipped'] = $skip;
                    $state['running'] = false;
                    $state['token']   = '';

                    if ( 'unknown' !== $skip ) {
                        $state['finished'] = time();
                    }

                    $states[ $id ] = $state;
                    continue;
                }

                if ( ! $state['running'] ) {
                    $state = array_merge( $this->blank_state(), array( 'running' => true, 'started' => time() ) );
                }

                $this->step( $location, $state, $started, $budget );

                $states[ $id ] = $state;
                update_option( self::OPTION, $states, false );
            }

            if ( $changed ) {
                update_option( self::OPTION, $states, false );
            }
        }

        private function step( array $location, array &$state, $started, $budget )
        {
            $client = $this->storage->client( $location );
            $prefix = trim( (string) $location['prefix'], '/' ) . '/';

            while ( microtime( true ) - $started < $budget ) {
                $page = $client->list_objects( $prefix, $state['token'], 1000 );

                if ( empty( $page['ok'] ) ) {
                    $state['error'] = mb_substr( (string) $page['error'], 0, 500 );

                    return;
                }

                $state['error']   = '';
                $state['listed'] += count( $page['objects'] );

                $this->check_page( $location, $prefix, $page['objects'], $state );

                if ( '' === $page['next'] ) {
                    $this->finish( $location, $state );

                    return;
                }

                $state['token'] = $page['next'];
            }
        }

        private function patterns()
        {
            $patterns = array();

            foreach ( $this->storage->plugin_folders() as $folder ) {
                $patterns[] = '#^' . preg_quote( $folder, '#' ) . '/#';
            }

            return array_merge( $patterns, array_map( 'strval', (array) apply_filters( 'wordplus_storage_sweep_patterns', array() ) ) );
        }

        private function check_page( array $location, $prefix, array $objects, array &$state )
        {
            global $wpdb;

            $storage    = $this->storage;
            $table      = $storage->table();
            $now        = time();
            $min_age    = (int) apply_filters( 'wordplus_storage_sweep_min_age', self::MIN_AGE );
            $confirm    = (int) apply_filters( 'wordplus_storage_sweep_confirm', self::CONFIRM );
            $patterns   = $this->patterns();
            $candidates = array();

            foreach ( $objects as $object ) {
                $key = (string) $object['key'];

                if ( 0 !== strpos( $key, $prefix ) ) {
                    continue;
                }

                $relative = substr( $key, strlen( $prefix ) );
                $matches  = false;

                foreach ( $patterns as $pattern ) {
                    if ( preg_match( $pattern, $relative ) ) {
                        $matches = true;
                        break;
                    }
                }

                if ( ! $matches || empty( $object['modified'] ) || (int) $object['modified'] > $now - $min_age ) {
                    continue;
                }

                $candidates[ $key ] = $object;
            }

            if ( empty( $candidates ) ) {
                return;
            }

            $keys = array_map( 'strval', array_keys( $candidates ) );

            $known = array_flip( array_merge(
                $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT object_key FROM {$wpdb->wordplus_storage_objects} WHERE object_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ") AND state <> 'orphan'", $keys ) ),
                array_map( 'strval', (array) apply_filters( 'wordplus_storage_known_keys', array(), $keys ) )
            ) );

            $orphans = array();

            foreach ( $wpdb->get_results( $wpdb->prepare(
                "SELECT id, object_key, created_at FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND location = %s AND state = 'orphan' AND object_key IN (" . implode( ',', array_fill( 0, count( $keys ), '%s' ) ) . ')',
                array_merge( array( get_current_blog_id(), $location['id'] ), $keys )
            ) ) as $row ) {
                $orphans[ $row->object_key ] = $row;
            }

            foreach ( $candidates as $key => $object ) {
                $key = (string) $key;

                if ( isset( $known[ $key ] ) ) {
                    if ( isset( $orphans[ $key ] ) ) {
                        $storage->delete_row( $orphans[ $key ]->id );
                    }

                    continue;
                }

                if ( isset( $orphans[ $key ] ) ) {
                    $row = $orphans[ $key ];

                    if ( (int) $row->created_at <= $now - $confirm ) {
                        $storage->update_row( $row->id, array( 'state' => 'deleting', 'attempts' => 0, 'next_attempt_at' => $now, 'error' => null ) );
                        $state['removed']++;
                    } else {
                        $storage->update_row( $row->id, array() );
                    }

                    continue;
                }

                $wpdb->insert( $table, array(
                    'blog_id'         => get_current_blog_id(),
                    'ref'             => null,
                    'name'            => mb_substr( wp_basename( $key ), 0, 255 ),
                    'size'            => (int) $object['size'],
                    'location'        => $location['id'],
                    'object_key'      => $key,
                    'state'           => 'orphan',
                    'next_attempt_at' => WordPlus_SDK_Storage::NEVER,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ) );

                $state['found']++;
            }
        }

        private function finish( array $location, array &$state )
        {
            global $wpdb;

            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->wordplus_storage_objects} WHERE blog_id = %d AND location = %s AND state = 'orphan' AND updated_at < %d",
                get_current_blog_id(), $location['id'], (int) $state['started']
            ) );

            $state['running']  = false;
            $state['token']    = '';
            $state['skipped']  = '';
            $state['finished'] = time();
        }
    }
}
