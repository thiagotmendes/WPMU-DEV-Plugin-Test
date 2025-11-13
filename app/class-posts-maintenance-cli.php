<?php
/**
 * WP-CLI command for Posts Maintenance.
 *
 * @package WPMUDEV_PluginTest
 */

namespace WPMUDEV\PluginTest\CLI;

use WP_CLI;
use WP_CLI_Command;
use WP_CLI\Utils;
use WPMUDEV\PluginTest\Posts_Maintenance_Service;

defined( 'WPINC' ) || die;

/**
 * Provides `wp wpmudev scan-posts`.
 */
class Posts_Maintenance_Command extends WP_CLI_Command {

	/**
	 * Register the command with WP-CLI.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! class_exists( '\WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'wpmudev', self::class );
	}

	/**
	 * Scan public posts/pages and update maintenance metadata.
	 *
	 * ## OPTIONS
	 *
	 * [--post_types=<post_types>]
	 * : Comma-separated list of post types. Defaults to "post,page".
	 *
	 * [--batch-size=<number>]
	 * : Number of posts to process per batch (between 10 and 200). Default 50.
	 *
	 * ## EXAMPLES
	 *
	 *     wp wpmudev scan-posts
	 *     wp wpmudev scan-posts --post_types=post,page,product --batch-size=75
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 *
	 * @return void
	 */
	public function scan_posts( $args, $assoc_args ): void {
		unset( $args );

		$service = Posts_Maintenance_Service::instance();

		if ( $service->is_running() ) {
			WP_CLI::error( __( 'A scan is already running. Retry once it has completed.', 'wpmudev-plugin-test' ) );
		}

		$post_types = array();
		if ( ! empty( $assoc_args['post_types'] ) ) {
			$post_types = Utils\split_comma_separated_list( $assoc_args['post_types'] );
		}

		$batch_size = isset( $assoc_args['batch-size'] ) ? absint( $assoc_args['batch-size'] ) : Posts_Maintenance_Service::DEFAULT_BATCH_SIZE;

		WP_CLI::log( __( 'Starting Posts Maintenance scan…', 'wpmudev-plugin-test' ) );

		$progress = null;
		$callback = function ( string $event, array $job ) use ( &$progress ) {
			if ( 'start' === $event ) {
				$total    = max( 1, (int) ( $job['total'] ?? 0 ) );
				$progress = Utils\make_progress_bar( __( 'Scanning posts', 'wpmudev-plugin-test' ), $total );
				return;
			}

			if ( 'processed' === $event && $progress ) {
				$progress->tick();
				return;
			}

			if ( 'finish' === $event && $progress ) {
				$progress->finish();
			}
		};

		$result = $service->start_scan(
			array(
				'post_types' => $post_types,
				'batch_size' => $batch_size,
			),
			'cli',
			false,
			$callback
		);

		if ( is_wp_error( $result ) ) {
			if ( $progress ) {
				$progress->finish();
			}

			WP_CLI::error( $result->get_error_message() );
		}

		$total     = (int) ( $result['total'] ?? 0 );
		$processed = (int) ( $result['processed'] ?? 0 );
		$types     = implode( ', ', (array) ( $result['post_types'] ?? array() ) );

		WP_CLI::success(
			sprintf(
				/* translators: 1: processed count 2: total count 3: post types */
				__( 'Scan completed: %1$d/%2$d posts updated (%3$s).', 'wpmudev-plugin-test' ),
				$processed,
				$total,
				$types
			)
		);
	}
}
