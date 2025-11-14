import { createRoot, render, StrictMode, useState, useEffect, useRef, createInterpolateElement } from '@wordpress/element';
import { Button, TextControl, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import './scss/style.scss';

const appData = window.wpmudevDriveTest || {};

if ( appData.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( appData.nonce ) );
}

const endpoints = {
	save: appData.restEndpointSave || 'wpmudev/v1/drive/save-credentials',
	auth: appData.restEndpointAuth || 'wpmudev/v1/drive/auth',
	files: appData.restEndpointFiles || 'wpmudev/v1/drive/files',
	upload: appData.restEndpointUpload || 'wpmudev/v1/drive/upload',
	download: appData.restEndpointDownload || 'wpmudev/v1/drive/download',
	createFolder: appData.restEndpointCreate || 'wpmudev/v1/drive/create-folder',
};

const guardRestResponse = ( response ) => {
	if ( response && 'wp-auth-check' in response ) {
		throw new Error( __( 'Your WordPress session expired. Please refresh the page and try again.', 'wpmudev-plugin-test' ) );
	}

	return response;
};

const formatBytes = ( bytes ) => {
	if ( ! bytes ) {
		return __( 'Unknown size', 'wpmudev-plugin-test' );
	}
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	const exponent = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
	return `${ ( bytes / 1024 ** exponent ).toFixed( exponent === 0 ? 0 : 1 ) } ${ units[ exponent ] }`;
};

const domElement = document.getElementById( appData.dom_element_id );

const WPMUDEV_DriveTest = () => {
	const [ isAuthenticated, setIsAuthenticated ] = useState( !! appData.authStatus );
	const [ hasCredentials, setHasCredentials ] = useState( !! appData.hasCredentials );
	const [ showCredentials, setShowCredentials ] = useState( ! appData.hasCredentials );
	const [ isLoading, setIsLoading ] = useState( false );
	const [ filesLoading, setFilesLoading ] = useState( false );
	const [ files, setFiles ] = useState( [] );
	const [ uploadFile, setUploadFile ] = useState( null );
	const [ folderName, setFolderName ] = useState( '' );
	const [ notice, setNotice ] = useState( { message: '', type: 'success' } );
	const [ credentials, setCredentials ] = useState( { clientId: '', clientSecret: '' } );
	const [ nextPageToken, setNextPageToken ] = useState( null );
	const noticeTimeoutRef = useRef();

	useEffect( () => () => {
		if ( noticeTimeoutRef.current ) {
			clearTimeout( noticeTimeoutRef.current );
		}
	}, [] );

	useEffect( () => {
		if ( isAuthenticated ) {
			loadFiles();
		} else {
			setFiles( [] );
			setNextPageToken( null );
		}
	}, [ isAuthenticated ] );

	const showNotice = ( message, type = 'success' ) => {
		setNotice( { message, type } );

		if ( noticeTimeoutRef.current ) {
			clearTimeout( noticeTimeoutRef.current );
		}

		if ( message ) {
			noticeTimeoutRef.current = setTimeout( () => {
				setNotice( { message: '', type: 'success' } );
			}, 6000 );
		}
	};

	const handleSaveCredentials = async () => {
		if ( ! credentials.clientId.trim() || ! credentials.clientSecret.trim() ) {
			showNotice( __( 'Both Client ID and Client Secret are required.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		setIsLoading( true );

		try {
			const response = guardRestResponse( await apiFetch( {
				path: endpoints.save,
				method: 'POST',
				data: {
					client_id: credentials.clientId.trim(),
					client_secret: credentials.clientSecret.trim(),
				},
			} ) );

			setHasCredentials( true );
			setShowCredentials( false );
			showNotice(
				response?.message || __( 'Credentials saved. Please authenticate with Google Drive.', 'wpmudev-plugin-test' ),
				'success'
			);
		} catch ( error ) {
			showNotice(
				error?.message || __( 'Unable to save credentials. Please double-check the values and try again.', 'wpmudev-plugin-test' ),
				'error'
			);
		} finally {
			setIsLoading( false );
		}
	};

	const handleAuth = async () => {
		setIsLoading( true );

		try {
			const response = guardRestResponse( await apiFetch( {
				path: endpoints.auth,
				method: 'POST',
			} ) );

			if ( response?.authUrl ) {
				window.location.href = response.authUrl;
				return;
			}

			showNotice( __( 'Unable to start the Google authentication flow.', 'wpmudev-plugin-test' ), 'error' );
		} catch ( error ) {
			showNotice(
				error?.message || __( 'Unable to start the Google authentication flow.', 'wpmudev-plugin-test' ),
				'error'
			);
		} finally {
			setIsLoading( false );
		}
	};

	const loadFiles = async ( { append = false, pageToken = null } = {} ) => {
		if ( ! isAuthenticated ) {
			return;
		}

		setFilesLoading( true );

		try {
			let path = `${ endpoints.files }?page_size=20`;

			if ( pageToken ) {
				path += `&page_token=${ encodeURIComponent( pageToken ) }`;
			}

			const response = guardRestResponse( await apiFetch( { path } ) );
			const list = Array.isArray( response?.files ) ? response.files : [];

			setFiles( ( current ) => ( append ? [ ...current, ...list ] : list ) );
			setNextPageToken( response?.nextPageToken || null );
			setIsAuthenticated( true );
		} catch ( error ) {
			if ( error?.code === 'wpmudev_drive_missing_credentials' || error?.code === 'wpmudev_drive_not_configured' ) {
				setIsAuthenticated( false );
				setShowCredentials( true );
			}
			showNotice(
				error?.message || __( 'Unable to load Google Drive files.', 'wpmudev-plugin-test' ),
				'error'
			);
		} finally {
			setFilesLoading( false );
		}
	};

	const handleUpload = async () => {
		if ( ! uploadFile ) {
			showNotice( __( 'Please choose a file to upload.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		setIsLoading( true );

		try {
			const formData = new window.FormData();
			formData.append( 'file', uploadFile );

			guardRestResponse( await apiFetch( {
				path: endpoints.upload,
				method: 'POST',
				body: formData,
			} ) );

			setUploadFile( null );
			showNotice( __( 'File uploaded successfully.', 'wpmudev-plugin-test' ) );
			loadFiles();
		} catch ( error ) {
			showNotice(
				error?.message || __( 'Unable to upload the selected file.', 'wpmudev-plugin-test' ),
				'error'
			);
		} finally {
			setIsLoading( false );
		}
	};

	const handleDownload = async ( fileId, fallbackName ) => {
		setIsLoading( true );

		try {
			const response = guardRestResponse( await apiFetch( {
				path: `${ endpoints.download }?file_id=${ encodeURIComponent( fileId ) }`,
			} ) );

			if ( ! response?.content ) {
				throw new Error( __( 'Unable to download the requested file.', 'wpmudev-plugin-test' ) );
			}

			const anchor = document.createElement( 'a' );
			anchor.href = `data:${ response.mimeType || 'application/octet-stream' };base64,${ response.content }`;
			anchor.download = response.filename || fallbackName || 'drive-file';
			document.body.appendChild( anchor );
			anchor.click();
			document.body.removeChild( anchor );

			showNotice( __( 'Download started in your browser.', 'wpmudev-plugin-test' ), 'success' );
		} catch ( error ) {
			showNotice(
				error?.message || __( 'Unable to download the requested file.', 'wpmudev-plugin-test' ),
				'error'
			);
		} finally {
			setIsLoading( false );
		}
	};

	const handleCreateFolder = async () => {
		if ( ! folderName.trim() ) {
			showNotice( __( 'Please provide a name for the new folder.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		setIsLoading( true );

		try {
			guardRestResponse( await apiFetch( {
				path: endpoints.createFolder,
				method: 'POST',
				data: {
					name: folderName.trim(),
				},
			} ) );

			setFolderName( '' );
			showNotice( __( 'Folder created successfully.', 'wpmudev-plugin-test' ) );
			loadFiles();
		} catch ( error ) {
			showNotice(
				error?.message || __( 'Unable to create the folder.', 'wpmudev-plugin-test' ),
				'error'
			);
		} finally {
			setIsLoading( false );
		}
	};

	const authInstructions = (
		<div className="sui-box-settings-row drive-auth-instructions">
			<p>{ __( 'Please authenticate with Google Drive to proceed with the test.', 'wpmudev-plugin-test' ) }</p>
			<p><strong>{ __( 'This test will require the following permissions:', 'wpmudev-plugin-test' ) }</strong></p>
			<ul>
				<li>{ __( 'View and manage Google Drive files', 'wpmudev-plugin-test' ) }</li>
				<li>{ __( 'Upload new files to Drive', 'wpmudev-plugin-test' ) }</li>
				<li>{ __( 'Create folders in Drive', 'wpmudev-plugin-test' ) }</li>
			</ul>
		</div>
	);

	const renderFiles = () => {
		if ( filesLoading ) {
			return (
				<div className="drive-loading">
					<Spinner />
					<p>{ __( 'Loading files...', 'wpmudev-plugin-test' ) }</p>
				</div>
			);
		}

		if ( files.length === 0 ) {
			return (
				<div className="sui-box-settings-row">
					<p>{ __( 'No files found in your Drive. Upload a file or create a folder to get started.', 'wpmudev-plugin-test' ) }</p>
				</div>
			);
		}

		return (
			<>
				<div className="drive-files-grid">
					{ files.map( ( file ) => (
						<div key={ file.id } className="drive-file-item">
							<div className="file-info">
								<strong>{ file.name }</strong>
								<small>
									{ file.modifiedTime
										? new Date( file.modifiedTime ).toLocaleString()
										: __( 'Unknown date', 'wpmudev-plugin-test' ) }
								</small>
								<small>
									{ file.isFolder ? __( 'Folder', 'wpmudev-plugin-test' ) : ( file.mimeType || __( 'File', 'wpmudev-plugin-test' ) ) }
								</small>
								{ ! file.isFolder && (
									<small>{ formatBytes( file.size ) }</small>
								) }
							</div>
							<div className="file-actions">
								{ file.webViewLink && (
									<Button
										variant="link"
										size="small"
										href={ file.webViewLink }
										target="_blank"
										rel="noreferrer"
									>
										{ __( 'View in Drive', 'wpmudev-plugin-test' ) }
									</Button>
								) }

								{ ! file.isFolder && (
									<Button
										variant="secondary"
										size="small"
										onClick={ () => handleDownload( file.id, file.name ) }
										disabled={ isLoading }
									>
										{ __( 'Download', 'wpmudev-plugin-test' ) }
									</Button>
								) }
							</div>
						</div>
					) ) }
				</div>

				{ nextPageToken && (
					<div className="sui-actions-right">
						<Button
							variant="secondary"
							onClick={ () => loadFiles( { append: true, pageToken: nextPageToken } ) }
							disabled={ filesLoading }
						>
							{ filesLoading ? <Spinner /> : __( 'Load more files', 'wpmudev-plugin-test' ) }
						</Button>
					</div>
				) }
			</>
		);
	};

	return (
		<>
			<div className="sui-header">
				<h1 className="sui-header-title">
					{ __( 'Google Drive Test', 'wpmudev-plugin-test' ) }
				</h1>
				<p className="sui-description">
					{ __( 'Test Google Drive API integration for applicant assessment.', 'wpmudev-plugin-test' ) }
				</p>
			</div>

			{ notice.message && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => setNotice( { message: '', type: 'success' } ) }
				>
					{ notice.message }
				</Notice>
			) }

			{ showCredentials ? (
				<div className="sui-box">
					<div className="sui-box-header">
						<h2 className="sui-box-title">{ __( 'Set Google Drive Credentials', 'wpmudev-plugin-test' ) }</h2>
					</div>
					<div className="sui-box-body">
						<div className="sui-box-settings-row">
							<TextControl
								help={ createInterpolateElement(
									__( 'You can get Client ID from <a>Google Cloud Console</a>. Make sure to enable Google Drive API.', 'wpmudev-plugin-test' ),
									{
										a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" />,
									}
								) }
								label={ __( 'Client ID', 'wpmudev-plugin-test' ) }
								value={ credentials.clientId }
								onChange={ ( value ) => setCredentials( { ...credentials, clientId: value } ) }
							/>
						</div>

						<div className="sui-box-settings-row">
							<TextControl
								help={ createInterpolateElement(
									__( 'You can get Client Secret from <a>Google Cloud Console</a>.', 'wpmudev-plugin-test' ),
									{
										a: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer" />,
									}
								) }
								label={ __( 'Client Secret', 'wpmudev-plugin-test' ) }
								value={ credentials.clientSecret }
								onChange={ ( value ) => setCredentials( { ...credentials, clientSecret: value } ) }
								type="password"
							/>
						</div>

						<div className="sui-box-settings-row">
								<span>
									{ createInterpolateElement(
										__( 'Please use this URL <em>%s</em> in your Google API\'s Authorized redirect URIs field.', 'wpmudev-plugin-test' ).replace( '%s', appData.redirectUri || '' ),
										{
											em: <em />,
										}
									) }
								</span>
							</div>

						<div className="sui-box-settings-row">
							<p><strong>{ __( 'Required scopes for Google Drive API:', 'wpmudev-plugin-test' ) }</strong></p>
							<ul>
								<li>https://www.googleapis.com/auth/drive.file</li>
								<li>https://www.googleapis.com/auth/drive.readonly</li>
							</ul>
						</div>
					</div>
					<div className="sui-box-footer">
						<div className="sui-actions-right">
							<Button
								variant="primary"
								onClick={ handleSaveCredentials }
								disabled={ isLoading }
							>
								{ isLoading ? <Spinner /> : __( 'Save Credentials', 'wpmudev-plugin-test' ) }
							</Button>
						</div>
					</div>
				</div>
			) : ! isAuthenticated ? (
				<div className="sui-box">
					<div className="sui-box-header">
						<h2 className="sui-box-title">{ __( 'Authenticate with Google Drive', 'wpmudev-plugin-test' ) }</h2>
					</div>
					<div className="sui-box-body">{ authInstructions }</div>
					<div className="sui-box-footer">
						<div className="sui-actions-left">
							<Button
								variant="secondary"
								onClick={ () => setShowCredentials( true ) }
							>
								{ __( 'Change Credentials', 'wpmudev-plugin-test' ) }
							</Button>
						</div>
						<div className="sui-actions-right">
							<Button
								variant="primary"
								onClick={ handleAuth }
								disabled={ isLoading }
							>
								{ isLoading ? <Spinner /> : __( 'Authenticate with Google Drive', 'wpmudev-plugin-test' ) }
							</Button>
						</div>
					</div>
				</div>
			) : (
				<>
					<div className="sui-box">
						<div className="sui-box-header">
							<h2 className="sui-box-title">{ __( 'Upload File to Drive', 'wpmudev-plugin-test' ) }</h2>
						</div>
						<div className="sui-box-body">
							<div className="sui-box-settings-row">
								<input
									type="file"
									onChange={ ( event ) => setUploadFile( event.target.files?.[ 0 ] || null ) }
									className="drive-file-input"
								/>
								{ uploadFile && (
									<p>
										<strong>{ __( 'Selected:', 'wpmudev-plugin-test' ) }</strong>
										{ ` ${ uploadFile.name } (${ formatBytes( uploadFile.size ) })` }
									</p>
								) }
							</div>
						</div>
						<div className="sui-box-footer">
							<div className="sui-actions-right">
								<Button
									variant="primary"
									onClick={ handleUpload }
									disabled={ isLoading || ! uploadFile }
								>
									{ isLoading ? <Spinner /> : __( 'Upload to Drive', 'wpmudev-plugin-test' ) }
								</Button>
							</div>
						</div>
					</div>

					<div className="sui-box">
						<div className="sui-box-header">
							<h2 className="sui-box-title">{ __( 'Create New Folder', 'wpmudev-plugin-test' ) }</h2>
						</div>
						<div className="sui-box-body">
							<div className="sui-box-settings-row">
								<TextControl
									label={ __( 'Folder Name', 'wpmudev-plugin-test' ) }
									value={ folderName }
									onChange={ setFolderName }
									placeholder={ __( 'Enter folder name', 'wpmudev-plugin-test' ) }
								/>
							</div>
						</div>
						<div className="sui-box-footer">
							<div className="sui-actions-right">
								<Button
									variant="secondary"
									onClick={ handleCreateFolder }
									disabled={ isLoading || ! folderName.trim() }
								>
									{ isLoading ? <Spinner /> : __( 'Create Folder', 'wpmudev-plugin-test' ) }
								</Button>
							</div>
						</div>
					</div>

					<div className="sui-box">
						<div className="sui-box-header">
							<h2 className="sui-box-title">{ __( 'Your Drive Files', 'wpmudev-plugin-test' ) }</h2>
							<div className="sui-actions-right">
								<Button
									variant="secondary"
									onClick={ () => loadFiles( { append: false } ) }
									disabled={ filesLoading }
								>
									{ filesLoading ? <Spinner /> : __( 'Refresh Files', 'wpmudev-plugin-test' ) }
								</Button>
							</div>
						</div>
						<div className="sui-box-body">
							{ renderFiles() }
						</div>
					</div>
				</>
			) }
		</>
	);
};

if ( domElement ) {
	if ( createRoot ) {
		createRoot( domElement ).render( <StrictMode><WPMUDEV_DriveTest /></StrictMode> );
	} else {
		render( <StrictMode><WPMUDEV_DriveTest /></StrictMode>, domElement );
	}
}
