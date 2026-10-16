<?php
defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Better_Messages_Worker' ) ) :

    class Better_Messages_Worker
    {
        const HOOK     = 'better_messages_worker_job';
        const OPTION   = 'better_messages_worker_runs';
        const MIGRATED = 'better_messages_worker_migrated';
        const BUDGET   = 45;
        const SLACK    = 10;

        const LEGACY = array(
            'better_messages_sync_unread',
            'better_messages_bulk_sender_job',
            'better_messages_storage_job',
            'better_messages_cleaner_job',
            'better_messages_ai_ensure_completion_job',
            'ba_check_build_statuses',
            'better_messages_send_notifications',
            'bm_cron_summarization',
            'better_messages_app_refresh_core_version',
            'better_messages_sync_user_index_weekly',
        );

        private $tasks   = array();
        private $running = false;
        private $again   = false;

        public static function instance()
        {
            static $instance = null;

            if ( null === $instance ) {
                $instance = new Better_Messages_Worker();
            }

            return $instance;
        }

        public function __construct()
        {
            add_action( 'init', array( $this, 'schedule' ), 100 );
            add_action( self::HOOK, array( $this, 'run' ), 10, 0 );
        }

        public function add_task( $action, $interval, $first_delay = 0 )
        {
            $this->tasks[ (string) $action ] = array(
                'interval' => max( MINUTE_IN_SECONDS, (int) $interval ),
                'delay'    => max( 0, (int) $first_delay ),
            );
        }

        public function has_task( $action )
        {
            return isset( $this->tasks[ (string) $action ] );
        }

        public function tasks()
        {
            return $this->tasks;
        }

        public function runs()
        {
            wp_cache_delete( self::OPTION, 'options' );

            $runs = get_option( self::OPTION, array() );

            return is_array( $runs ) ? $runs : array();
        }

        public function mark_run( $action, $time = null )
        {
            $runs = $this->runs();
            $runs[ (string) $action ] = $time === null ? time() : (int) $time;

            update_option( self::OPTION, $runs, false );
        }

        public function last_run( $action )
        {
            $runs = $this->runs();

            return isset( $runs[ $action ] ) ? (int) $runs[ $action ] : 0;
        }

        public function next_run( $action )
        {
            if ( ! $this->has_task( $action ) ) {
                return 0;
            }

            $last = $this->last_run( $action );
            $next = wp_next_scheduled( self::HOOK );

            if ( $last <= 0 ) {
                return $next ? (int) $next : 0;
            }

            return max( $last + $this->tasks[ $action ]['interval'], $next ? (int) $next : 0 );
        }

        public function schedule()
        {
            if ( ! wp_next_scheduled( self::HOOK ) ) {
                wp_schedule_event( time() + MINUTE_IN_SECONDS, 'one_minute', self::HOOK );
            }

            if ( get_option( self::MIGRATED ) !== '1' ) {
                $this->migrate();
            }
        }

        public function migrate()
        {
            $runs = $this->runs();

            foreach ( self::LEGACY as $hook ) {
                $next = wp_next_scheduled( $hook );

                if ( $next && isset( $this->tasks[ $hook ] ) && ! isset( $runs[ $hook ] ) ) {
                    $runs[ $hook ] = (int) $next - $this->tasks[ $hook ]['interval'];
                }

                wp_clear_scheduled_hook( $hook );
            }

            update_option( self::OPTION, $runs, false );
            update_option( self::MIGRATED, '1', false );
        }

        public function run_soon( $action = '' )
        {
            if ( $action !== '' ) {
                $this->mark_run( $action, 0 );
            }

            if ( $this->running ) {
                $this->again = true;
                return;
            }

            if ( ! wp_next_scheduled( self::HOOK, array( 'soon' ) ) ) {
                wp_schedule_single_event( time(), self::HOOK, array( 'soon' ) );
            }

            if ( ! wp_doing_cron() && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
                spawn_cron();
            }
        }

        private function lock_name()
        {
            global $wpdb;

            return 'bm_worker_' . md5( DB_NAME . '|' . $wpdb->prefix . '|' . get_current_blog_id() );
        }

        private function lock()
        {
            global $wpdb;

            $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $this->lock_name() ) );

            return $got === null || (int) $got === 1;
        }

        private function unlock()
        {
            global $wpdb;

            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name() ) );
        }

        private function due_tasks()
        {
            $runs    = $this->runs();
            $now     = time();
            $due     = array();
            $changed = false;

            foreach ( $this->tasks as $action => $task ) {
                if ( ! isset( $runs[ $action ] ) ) {
                    $runs[ $action ] = $now - $task['interval'] + $task['delay'];
                    $changed = true;
                }

                if ( $now - (int) $runs[ $action ] >= $task['interval'] - self::SLACK ) {
                    $due[ $action ] = (int) $runs[ $action ];
                }
            }

            if ( $changed ) {
                update_option( self::OPTION, $runs, false );
            }

            asort( $due );

            return array_keys( $due );
        }

        public function run()
        {
            if ( $this->running || ! $this->lock() ) {
                return;
            }

            $this->running = true;
            $this->again   = false;

            @set_time_limit( self::BUDGET + 120 );
            ignore_user_abort( true );

            $started = microtime( true );

            try {
                do {
                    $this->again = false;

                    foreach ( $this->due_tasks() as $action ) {
                        if ( microtime( true ) - $started > self::BUDGET ) {
                            $this->again = true;
                            break;
                        }

                        $this->mark_run( $action );
                        do_action_ref_array( $action, array() );
                    }
                } while ( $this->again && microtime( true ) - $started < self::BUDGET - 10 );
            } finally {
                $this->running = false;
                $this->unlock();
            }

            if ( $this->again ) {
                $this->again = false;
                $this->run_soon();
            }
        }

        public function status()
        {
            $now         = time();
            $next        = wp_next_scheduled( self::HOOK );
            $worker_late = ! $next || $next < $now - 10 * MINUTE_IN_SECONDS;
            $runs        = $this->runs();
            $status      = array();

            $status[ self::HOOK ] = array(
                'is_late'   => $worker_late,
                'time_diff' => $next ? human_time_diff( $next, $now ) . ( $next < $now ? ' ago' : '' ) : '-',
            );

            foreach ( $this->tasks as $action => $task ) {
                $last = isset( $runs[ $action ] ) ? (int) $runs[ $action ] : 0;
                $due  = $last > 0 ? $last + $task['interval'] : ( $next ? (int) $next : $now );
                $late = $worker_late || ( $last > 0 && $due < $now - 10 * MINUTE_IN_SECONDS );

                $status[ $action ] = array(
                    'is_late'   => $late,
                    'time_diff' => human_time_diff( $due, $now ) . ( $late ? ' ago' : '' ),
                );
            }

            return $status;
        }
    }

endif;

function Better_Messages_Worker()
{
    return Better_Messages_Worker::instance();
}
