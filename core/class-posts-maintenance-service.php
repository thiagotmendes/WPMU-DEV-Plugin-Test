<?php
/**
 * Posts Maintenance service – shared logic for scans, cron and CLI.
 *
 * @package WPMUDEV_PluginTest
 */

namespace WPMUDEV\PluginTest;

use WP_Error;

defined( 'WPINC' ) || die;

/**
 * Handles scheduling, background processing and shared state for posts scans.
 */
class Posts_Maintenance_Service extends Base {

	public const OPTION_JOB          = 'wpmudev_posts_scan_job';
	public const OPTION_LAST_SUMMARY = 'wpmudev_posts_scan_last_summary';
	public const META_KEY            = 'wpmudev_test_last_scan';
	public const PROCESS_HOOK        = 'wpmudev_posts_scan_process';
	public const CRON_HOOK           = 'wpmudev_posts_scan_cron';
	public const DEFAULT_BATCH_SIZE  = 50;

	/**
	 * Boot service hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::PROCESS_HOOK, array( $this, 'handle_async_process' ) );
		add_action( self::CRON_HOOK, array( $this, 'handle_daily_cron' ) );
		add_action( 'init', array( $this, 'maybe_schedule_cron' ) );
	}

	/**
	 * Returns supported post types keyed by slug => label.
	 *
	 * @return array<string,string>
	 */
	public function get_supported_post_types(): array {
		$types = get_post_types(
			array(
				'public'       => true,
				'show_in_rest' => true,
			),
			'objects'
		);

		$options = array();

		foreach ( $types as $name => $object ) {
			$options[ $name ] = $object->labels->singular_name ?? $object->label;
		}

		/**
		 * Filter post types available to the Posts Maintenance scan.
		 *
		 * @param array $options Associative array of slug => label.
		 */
		$options = apply_filters( 'wpmudev_posts_maintenance_post_types', $options );

		return array_filter( $options );
	}

	/**
	 * Default post types used when UI/CLI does not specify any.
	 *
	 * @return array
	 */
	public function get_default_post_types(): array {
		$defaults = array( 'post', 'page' );

		return array_values(
			array_intersect(
				$defaults,
				array_keys( $this->get_supported_post_types() )
			)
		);
	}

	/**
	 * Starts a posts scan job.
	 *
	 * @param array         $args Arguments (post_types, batch_size).
	 * @param string        $origin Origin label (admin|cli|cron).
	 * @param bool          $async Whether the job should run asynchronously.
	 * @param callable|null $progress_callback Optional callback for sync runs.
	 *
	 * @return array|WP_Error
	 */
	public function start_scan( array $args, string $origin = 'admin', bool $async = true, callable $progress_callback = null ) {
		if ( $async && $this->is_running() ) {
			return new WP_Error(
				'wpmudev_posts_scan_running',
				__( 'A Posts Maintenance scan is already running. Please wait for it to finish.', 'wpmudev-plugin-test' )
			);
		}

		$job = $this->prepare_job( $args, $origin );

		if ( is_wp_error( $job ) ) {
			return $job;
		}

		if ( empty( $job['total'] ) ) {
			$job['status']      = 'completed';
			$job['finished_at'] = time();
			$this->finalize_job( $job );

			return $this->format_status( $job );
		}

		if ( ! $async ) {
			$this->process_job_immediately( $job, $progress_callback );

			return $this->format_status( $job );
		}

		$job['status'] = 'queued';
		$this->save_job( $job );
		$this->schedule_next_batch();

		return $this->format_status( $job );
	}

	/**
	 * Process the queue immediately (used by CLI/tests).
	 *
	 * @param array         $job Job data.
	 * @param callable|null $progress_callback Optional callback per processed post.
	 *
	 * @return void
	 */
	public function process_job_immediately( array &$job, callable $progress_callback = null ): void {
		$job['status']     = 'running';
		$job['started_at'] = $job['started_at'] ?? time();
		$this->save_job( $job );

		if ( is_callable( $progress_callback ) ) {
			call_user_func( $progress_callback, 'start', $job );
		}

		while ( ! empty( $job['queue'] ) ) {
			$this->process_batch( $job, $progress_callback );
		}

		$job['finished_at'] = time();
		$this->finalize_job( $job );

		if ( is_callable( $progress_callback ) ) {
			call_user_func( $progress_callback, 'finish', $job );
		}
	}

	/**
	 * True when a job is processing or queued.
	 *
	 * @return bool
	 */
	public function is_running(): bool {
		$job = $this->get_job();

		if ( empty( $job ) ) {
			return false;
		}

		if ( empty( $job['queue'] ) ) {
			return false;
		}

		return in_array( $job['status'], array( 'queued', 'running' ), true );
	}

	/**
	 * Returns current job state formatted for APIs.
	 *
	 * @return array
	 */
	public function get_status(): array {
		$job = $this->get_job();

		if ( empty( $job ) ) {
			return array(
				'status'     => 'idle',
				'lastRun'    => $this->get_last_summary(),
				'nextRun'    => $this->get_next_cron(),
				'post_types' => $this->get_default_post_types(),
			);
		}

		return $this->format_status( $job );
	}

	/**
	 * Handles scheduled batches.
	 *
	 * @return void
	 */
	public function handle_async_process(): void {
		$job = $this->get_job();

		if ( empty( $job ) || empty( $job['queue'] ) ) {
			return;
		}

		$job['status']     = 'running';
		$job['started_at'] = $job['started_at'] ?? time();
		$this->process_batch( $job );

		if ( empty( $job['queue'] ) ) {
			$job['finished_at'] = time();
			$this->finalize_job( $job );

			return;
		}

		$this->save_job( $job );
		$this->schedule_next_batch();
	}

	/**
	 * Cron callback that triggers the daily scan.
	 *
	 * @return void
	 */
	public function handle_daily_cron(): void {
		if ( $this->is_running() ) {
			return;
		}

		$this->start_scan(
			array(
				'post_types' => $this->get_default_post_types(),
				'batch_size' => self::DEFAULT_BATCH_SIZE,
			),
			'cron',
			true
		);
	}

	/**
	 * Returns the last run summary.
	 *
	 * @return array
	 */
	public function get_last_summary(): array {
		$summary = get_option( self::OPTION_LAST_SUMMARY, array() );

		return is_array( $summary ) ? $summary : array();
	}

	/**
	 * Resets the current job.
	 *
	 * @return void
	 */
	public function clear_job(): void {
		delete_option( self::OPTION_JOB );
		wp_clear_scheduled_hook( self::PROCESS_HOOK );
	}

	/**
	 * Ensures the daily cron is registered.
	 *
	 * @return void
	 */
	public function maybe_schedule_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Returns timestamp for next cron run.
	 *
	 * @return int|null
	 */
	private function get_next_cron(): ?int {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		return $scheduled ? (int) $scheduled : null;
	}

	/**
	 * Prepare a job payload.
	 *
	 * @param array  $args Arguments.
	 * @param string $origin Origin.
	 *
	 * @return array|WP_Error
	 */
	private function prepare_job( array $args, string $origin ) {
		$post_types = $this->sanitize_post_types( $args['post_types'] ?? array() );
		$batch_size = $this->sanitize_batch_size( $args['batch_size'] ?? self::DEFAULT_BATCH_SIZE );

		if ( empty( $post_types ) ) {
			$post_types = $this->get_default_post_types();
		}

		if ( empty( $post_types ) ) {
			return new WP_Error(
				'wpmudev_posts_scan_no_types',
				__( 'No valid post types were provided for the scan.', 'wpmudev-plugin-test' )
			);
		}

		$post_ids = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'nopaging'       => true,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'suppress_filters' => false,
			)
		);

		$job = array(
			'id'          => uniqid( 'wpmudev_scan_', true ),
			'status'      => 'queued',
			'post_types'  => $post_types,
			'batch_size'  => $batch_size,
			'total'       => count( $post_ids ),
			'processed'   => 0,
			'queue'       => $post_ids,
			'created_at'  => time(),
			'started_at'  => null,
			'finished_at' => null,
			'origin'      => $origin,
			'initiated_by'=> get_current_user_id(),
			'last_error'  => '',
		);

		return $job;
	}

	/**
	 * Persist job payload.
	 *
	 * @param array $job Job data.
	 *
	 * @return void
	 */
	private function save_job( array $job ): void {
		update_option( self::OPTION_JOB, $job, false );
	}

	/**
	 * Fetch job payload.
	 *
	 * @return array
	 */
	private function get_job(): array {
		$job = get_option( self::OPTION_JOB, array() );

		return is_array( $job ) ? $job : array();
	}

	/**
	 * Format job for responses.
	 *
	 * @param array $job Job data.
	 *
	 * @return array
	 */
	private function format_status( array $job ): array {
		$status = $job;
		unset( $status['queue'] );

		$total     = (int) ( $status['total'] ?? 0 );
		$processed = (int) ( $status['processed'] ?? 0 );

		$status['remaining'] = max( 0, $total - $processed );
		$status['percent']   = $total > 0 ? (int) floor( ( $processed / $total ) * 100 ) : 0;
		$status['lastRun']   = $this->get_last_summary();
		$status['nextRun']   = $this->get_next_cron();

		return $status;
	}

	/**
	 * Schedule the next async batch.
	 *
	 * @return void
	 */
	private function schedule_next_batch(): void {
		if ( ! wp_next_scheduled( self::PROCESS_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::PROCESS_HOOK );
		}
	}

	/**
	 * Process a batch of posts.
	 *
	 * @param array         $job Job data passed by reference.
	 * @param callable|null $progress_callback Optional callback.
	 *
	 * @return void
	 */
	private function process_batch( array &$job, callable $progress_callback = null ): void {
		$batch = array_splice( $job['queue'], 0, (int) $job['batch_size'] );

		if ( empty( $batch ) ) {
			return;
		}

		foreach ( $batch as $post_id ) {
			if ( ! $post_id ) {
				continue;
			}

			update_post_meta( $post_id, self::META_KEY, current_time( 'mysql', true ) );
			$job['processed']++;

			if ( is_callable( $progress_callback ) ) {
				call_user_func( $progress_callback, 'processed', $job, $post_id );
			}
		}
	}

	/**
	 * Finalize job and persist last summary.
	 *
	 * @param array $job Job data passed by reference.
	 *
	 * @return void
	 */
	private function finalize_job( array &$job ): void {
		$job['status']      = 'completed';
		$job['finished_at'] = $job['finished_at'] ?? time();
		$job['queue']       = array();
		$this->save_job( $job );

		$summary = array(
			'total'       => $job['total'],
			'processed'   => $job['processed'],
			'post_types'  => $job['post_types'],
			'finished_at' => $job['finished_at'],
			'origin'      => $job['origin'],
		);

		update_option( self::OPTION_LAST_SUMMARY, $summary, false );
	}

	/**
	 * Sanitize list of requested post types.
	 *
	 * @param array|string $raw List or comma-separated string.
	 *
	 * @return array
	 */
	private function sanitize_post_types( $raw ): array {
		$available = $this->get_supported_post_types();
		$selected  = array();

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		foreach ( $raw as $type ) {
			$type = sanitize_key( $type );

			if ( isset( $available[ $type ] ) ) {
				$selected[] = $type;
			}
		}

		return array_values( array_unique( $selected ) );
	}

	/**
	 * Sanitize batch size.
	 *
	 * @param mixed $value Input value.
	 *
	 * @return int
	 */
	private function sanitize_batch_size( $value ): int {
		$size = absint( $value );

		if ( $size < 10 ) {
			return 10;
		}

		if ( $size > 200 ) {
			return 200;
		}

		return $size;
	}
}
