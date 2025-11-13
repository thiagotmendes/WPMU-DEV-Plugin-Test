<?php
/**
 * Google Drive API endpoints using Google Client Library.
 *
 * @link          https://wpmudev.com/
 * @since         1.0.0
 *
 * @author        WPMUDEV (https://wpmudev.com)
 * @package       WPMUDEV\PluginTest
 *
 * @copyright (c) 2025, Incsub (http://incsub.com)
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

// Abort if called directly.
defined( 'WPINC' ) || die;

use WPMUDEV\PluginTest\Base;
use WPMUDEV\PluginTest\Drive_Service;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Exception;

class Drive_API extends Base {

	/**
	 * Google Client instance.
	 *
	 * @var Google_Client
	 */
	private $client;

	/**
	 * Drive service helper.
	 *
	 * @var Drive_Service
	 */
	private $service;

	/**
	 * Google Drive service.
	 *
	 * @var Google_Service_Drive
	 */
	private $drive_service;

	/**
	 * OAuth redirect URI.
	 *
	 * @var string
	 */
	private $redirect_uri;

	/**
	 * Google Drive API scopes.
	 *
	 * @var array
	 */
	private $scopes = array(
		Google_Service_Drive::DRIVE_FILE,
		Google_Service_Drive::DRIVE_READONLY,
	);

	/**
	 * Initialize the class.
	 */
	public function init() {
		$this->service      = Drive_Service::instance();
		$this->redirect_uri = $this->service->get_redirect_uri();
		$this->setup_google_client();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Outputs an error message for the OAuth callback and stops execution.
	 *
	 * @param string $message Message to show.
	 *
	 * @return void
	 */
	private function render_callback_error( string $message ): void {
		wp_die( wp_kses_post( $message ), __( 'Google Drive Authentication', 'wpmudev-plugin-test' ), array( 'response' => 400 ) );
	}

	/**
	 * Setup Google Client.
	 */
	private function setup_google_client() {
		$client = $this->service ? $this->service->get_client() : null;

		if ( is_wp_error( $client ) || ! $client instanceof Google_Client ) {
			$this->client = null;
			return;
		}

		$this->client = $client;
		$this->client->setRedirectUri( $this->redirect_uri );
		$this->client->setScopes( $this->scopes );
		$this->client->setAccessType( 'offline' );
		$this->client->setPrompt( 'consent' );
		$this->drive_service = new Google_Service_Drive( $this->client );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Save credentials endpoint
		register_rest_route( 'wpmudev/v1/drive', '/save-credentials', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'save_credentials' ),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );

		// Authentication endpoint
		register_rest_route( 'wpmudev/v1/drive', '/auth', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'start_auth' ),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );

		// OAuth callback
		register_rest_route( 'wpmudev/v1/drive', '/callback', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_callback' ),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );

		// List files
		register_rest_route( 'wpmudev/v1/drive', '/files', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'list_files' ),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );

		// Upload file
		register_rest_route( 'wpmudev/v1/drive', '/upload', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'upload_file' ),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );

		// Download file
		register_rest_route( 'wpmudev/v1/drive', '/download', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'download_file' ),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );

		// Create folder
		register_rest_route( 'wpmudev/v1/drive', '/create-folder', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'create_folder' ),
			'permission_callback' => array( $this, 'permissions_check' ),
		) );
	}

	/**
	 * Permission callback for Drive routes.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You are not allowed to perform this action.', 'wpmudev-plugin-test' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Save Google OAuth credentials.
	 */
	public function save_credentials( WP_REST_Request $request ) {
		$params        = $request->get_json_params();
		$client_id     = isset( $params['client_id'] ) ? sanitize_text_field( wp_unslash( $params['client_id'] ) ) : '';
		$client_secret = isset( $params['client_secret'] ) ? sanitize_text_field( wp_unslash( $params['client_secret'] ) ) : '';

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error(
				'wpmudev_drive_missing_credentials',
				__( 'Client ID and Client Secret are required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$saved = $this->service->save_credentials( $client_id, $client_secret );

		if ( ! $saved ) {
			return new WP_Error(
				'wpmudev_drive_credentials_save_failed',
				__( 'Unable to store the provided credentials. Please try again.', 'wpmudev-plugin-test' ),
				array( 'status' => 500 )
			);
		}

		// Reinitialize Google Client with new credentials.
		$this->setup_google_client();

		return new WP_REST_Response(
			array(
				'success'         => true,
				'hasCredentials'  => true,
				'message'         => __( 'Credentials saved. Continue with Google authentication.', 'wpmudev-plugin-test' ),
			),
			200
		);
	}

	/**
	 * Start Google OAuth flow.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_auth( WP_REST_Request $request ) {
		unset( $request );

		if ( ! $this->client ) {
			return new WP_Error(
				'wpmudev_drive_missing_credentials',
				__( 'Google OAuth credentials are not configured yet.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$state = $this->service->generate_state();
		$this->client->setState( $state );

		return new WP_REST_Response(
			array(
				'success'  => true,
				'authUrl'  => $this->client->createAuthUrl(),
				'state'    => $state,
				'redirect' => $this->redirect_uri,
			),
			200
		);
	}

	/**
	 * Handle OAuth callback.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return void
	 */
	public function handle_callback( WP_REST_Request $request ) {
		$code  = $request->get_param( 'code' );
		$code  = is_string( $code ) ? trim( wp_unslash( $code ) ) : '';
		$state = sanitize_text_field( wp_unslash( $request->get_param( 'state' ) ) );

		if ( empty( $code ) ) {
			$this->render_callback_error( __( 'Authorization code not received. Please try again.', 'wpmudev-plugin-test' ) );
		}

		if ( empty( $state ) || ! $this->service->validate_state( $state ) ) {
			$this->render_callback_error( __( 'The authorization state is invalid or expired. Please restart the process.', 'wpmudev-plugin-test' ) );
		}

		if ( ! $this->client ) {
			$this->render_callback_error( __( 'Google OAuth credentials are missing.', 'wpmudev-plugin-test' ) );
		}

		try {
			$token = $this->client->fetchAccessTokenWithAuthCode( $code );
		} catch ( Exception $e ) {
			$this->render_callback_error( sprintf( /* translators: %s error message */ __( 'Failed to get access token: %s', 'wpmudev-plugin-test' ), esc_html( $e->getMessage() ) ) );
		}

		if ( isset( $token['error'] ) ) {
			$message = $token['error_description'] ?? $token['error'];
			$this->render_callback_error( sprintf( /* translators: %s error message */ __( 'Google API returned an error: %s', 'wpmudev-plugin-test' ), esc_html( $message ) ) );
		}

		$this->service->save_tokens( $token );

		$redirect = add_query_arg(
			array(
				'page' => 'wpmudev_plugintest_drive',
				'auth' => 'success',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Ensure we have a ready-to-use Google Drive service.
	 *
	 * @return true|WP_Error
	 */
	private function ensure_drive_ready() {
		if ( ! $this->client || ! $this->drive_service ) {
			return new WP_Error(
				'wpmudev_drive_not_configured',
				__( 'Google Drive is not configured yet.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->service->ensure_valid_token( $this->client );

		if ( is_wp_error( $result ) ) {
			$result->add_data(
				array(
					'status' => 401,
				)
			);

			return $result;
		}

		// Refresh Drive service to ensure it has the latest access token.
		$this->drive_service = new Google_Service_Drive( $this->client );

		return true;
	}

	/**
	 * List files in Google Drive.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( WP_REST_Request $request ) {
		$ready = $this->ensure_drive_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$page_size = absint( $request->get_param( 'page_size' ) );
		$page_size = $page_size > 0 ? min( $page_size, 100 ) : 20;

		$page_token = sanitize_text_field( wp_unslash( $request->get_param( 'page_token' ) ?? '' ) );
		$query      = sanitize_text_field( wp_unslash( $request->get_param( 'query' ) ?? '' ) );

		if ( empty( $query ) ) {
			$query = 'trashed=false';
		}

		try {
			$options = array(
				'pageSize' => $page_size,
				'q'        => $query,
				'fields'   => 'nextPageToken, files(id,name,mimeType,size,modifiedTime,webViewLink,iconLink)',
				'spaces'   => 'drive',
				'orderBy'  => 'modifiedTime desc',
			);

			if ( ! empty( $page_token ) ) {
				$options['pageToken'] = $page_token;
			}

			$results = $this->drive_service->files->listFiles( $options );

			$file_list = array();
			foreach ( (array) $results->getFiles() as $file ) {
				$file_list[] = array(
					'id'           => $file->getId(),
					'name'         => $file->getName(),
					'mimeType'     => $file->getMimeType(),
					'size'         => $file->getSize(),
					'modifiedTime' => $file->getModifiedTime(),
					'webViewLink'  => $file->getWebViewLink(),
					'iconLink'     => $file->getIconLink(),
					'isFolder'     => 'application/vnd.google-apps.folder' === $file->getMimeType(),
				);
			}

			return new WP_REST_Response(
				array(
					'success'       => true,
					'files'         => $file_list,
					'nextPageToken' => $results->getNextPageToken(),
					'query'         => $query,
				),
				200
			);
		} catch ( Exception $e ) {
			return new WP_Error(
				'wpmudev_drive_list_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Upload file to Google Drive.
	 */
	public function upload_file( WP_REST_Request $request ) {
		$ready = $this->ensure_drive_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( ! current_user_can( 'upload_files' ) ) {
			return new WP_Error(
				'wpmudev_drive_no_cap',
				__( 'You are not allowed to upload files.', 'wpmudev-plugin-test' ),
				array( 'status' => 403 )
			);
		}

		$files = $request->get_file_params();
		
		if ( empty( $files['file'] ) ) {
			return new WP_Error(
				'wpmudev_drive_no_file',
				__( 'No file provided.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$file = $files['file'];
		
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error(
				'wpmudev_drive_upload_error',
				__( 'An error occurred while uploading the file.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$max_size = wp_max_upload_size();
		if ( $max_size && (int) $file['size'] > $max_size ) {
			return new WP_Error(
				'wpmudev_drive_file_too_big',
				__( 'The selected file is too large.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$parent_id = sanitize_text_field( wp_unslash( $request->get_param( 'parent_id' ) ?? '' ) );

		try {
			// Create file metadata
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( sanitize_file_name( $file['name'] ) );

			if ( ! empty( $parent_id ) ) {
				$drive_file->setParents( array( $parent_id ) );
			}

			// Upload file
			$result = $this->drive_service->files->create(
				$drive_file,
				array(
					'data'       => file_get_contents( $file['tmp_name'] ),
					'mimeType'   => $file['type'] ?: 'application/octet-stream',
					'uploadType' => 'multipart',
					'fields'     => 'id,name,mimeType,size,webViewLink,modifiedTime',
				)
			);

			if ( file_exists( $file['tmp_name'] ) ) {
				wp_delete_file( $file['tmp_name'] );
			}

			return new WP_REST_Response(
				array(
					'success' => true,
					'file'    => array(
						'id'           => $result->getId(),
						'name'         => $result->getName(),
						'mimeType'     => $result->getMimeType(),
						'size'         => $result->getSize(),
						'webViewLink'  => $result->getWebViewLink(),
						'modifiedTime' => $result->getModifiedTime(),
					),
				),
				201
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'wpmudev_drive_upload_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Download file from Google Drive.
	 */
	public function download_file( WP_REST_Request $request ) {
		$ready = $this->ensure_drive_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$file_id = sanitize_text_field( wp_unslash( $request->get_param( 'file_id' ) ) );
		
		if ( empty( $file_id ) ) {
			return new WP_Error(
				'wpmudev_drive_missing_file_id',
				__( 'File ID is required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		try {
			// Get file metadata
			$file = $this->drive_service->files->get( $file_id, array(
				'fields' => 'id,name,mimeType,size',
			) );

			// Download file content
			$response = $this->drive_service->files->get( $file_id, array(
				'alt' => 'media',
			) );

			$content = $response->getBody()->getContents();

			// Return file content as base64 for JSON response
			return new WP_REST_Response(
				array(
					'success'  => true,
					'content'  => base64_encode( $content ),
					'filename' => $file->getName(),
					'mimeType' => $file->getMimeType(),
					'size'     => $file->getSize(),
				)
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'wpmudev_drive_download_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Create folder in Google Drive.
	 */
	public function create_folder( WP_REST_Request $request ) {
		$ready = $this->ensure_drive_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$name = sanitize_text_field( wp_unslash( $request->get_param( 'name' ) ) );
		
		if ( empty( $name ) ) {
			return new WP_Error(
				'wpmudev_drive_missing_name',
				__( 'Folder name is required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$parent_id = sanitize_text_field( wp_unslash( $request->get_param( 'parent_id' ) ?? '' ) );

		try {
			$folder = new Google_Service_Drive_DriveFile();
			$folder->setName( $name );
			$folder->setMimeType( 'application/vnd.google-apps.folder' );

			if ( ! empty( $parent_id ) ) {
				$folder->setParents( array( $parent_id ) );
			}

			$result = $this->drive_service->files->create( $folder, array(
				'fields' => 'id,name,mimeType,webViewLink',
			) );

			return new WP_REST_Response(
				array(
					'success' => true,
					'folder'  => array(
						'id'          => $result->getId(),
						'name'        => $result->getName(),
						'mimeType'    => $result->getMimeType(),
						'webViewLink' => $result->getWebViewLink(),
					),
				),
				201
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'wpmudev_drive_create_failed',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}
}
