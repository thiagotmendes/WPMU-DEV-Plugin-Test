import { createRoot, StrictMode, useState, useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	CheckboxControl,
	Notice,
	Spinner,
	TextControl,
	Card,
	CardBody,
	CardHeader,
	CardFooter,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

import './posts-maintenance.scss';

const appData = window.wpmudevPostsMaintenance || {};

if ( appData.nonce ) {
	apiFetch.use( apiFetch.createNonceMiddleware( appData.nonce ) );
}

const endpoints = appData.endpoints || {};
const availableTypes = appData.postTypes || [];
const initialStatus = appData.jobStatus || { status: 'idle' };
const defaultPostTypes = appData.defaultPostTypes || availableTypes.map( ( type ) => type.value );
const defaultBatchSize = appData.defaultBatch || 50;

const statusIsActive = ( status ) => [ 'queued', 'running' ].includes( status );

const formatDate = ( timestamp ) => {
	if ( ! timestamp ) {
		return __( 'Not available', 'wpmudev-plugin-test' );
	}

	try {
		return new Date( timestamp * 1000 ).toLocaleString();
	} catch ( error ) {
		return __( 'Not available', 'wpmudev-plugin-test' );
	}
};

const guardResponse = ( response ) => {
	if ( response && 'wp-auth-check' in response ) {
		throw new Error( __( 'Your session expired. Refresh the page and try again.', 'wpmudev-plugin-test' ) );
	}

	return response;
};

const PostsMaintenanceApp = () => {
	const [ selectedTypes, setSelectedTypes ] = useState(
		initialStatus?.post_types?.length ? initialStatus.post_types : defaultPostTypes
	);
	const [ batchSize, setBatchSize ] = useState( initialStatus?.batch_size || defaultBatchSize );
	const [ status, setStatus ] = useState( initialStatus );
	const [ notice, setNotice ] = useState( null );
	const [ isSubmitting, setIsSubmitting ] = useState( false );
	const [ isRefreshing, setIsRefreshing ] = useState( false );

	const pollRef = useRef();

	const isActive = statusIsActive( status?.status );

	useEffect( () => {
		if ( isActive && ! pollRef.current ) {
			pollRef.current = setInterval( () => fetchStatus( false ), 5000 );
		}

		if ( ! isActive && pollRef.current ) {
			clearInterval( pollRef.current );
			pollRef.current = null;
		}

		return () => {
			if ( pollRef.current ) {
				clearInterval( pollRef.current );
			}
		};
	}, [ isActive ] );

	const showNotice = ( message, type = 'success' ) => {
		if ( ! message ) {
			setNotice( null );
			return;
		}

		setNotice( { message, type } );
	};

	const togglePostType = ( slug, checked ) => {
		setSelectedTypes( ( current ) => {
			if ( checked ) {
				return Array.from( new Set( [ ...current, slug ] ) );
			}

			return current.filter( ( type ) => type !== slug );
		} );
	};

	const fetchStatus = async ( manual = true ) => {
		if ( ! endpoints.status ) {
			return;
		}

		if ( manual ) {
			setIsRefreshing( true );
		}

		try {
			const response = guardResponse(
				await apiFetch( {
					path: endpoints.status,
					method: 'GET',
				} )
			);

			setStatus( response );
		} catch ( error ) {
			showNotice( error?.message || __( 'Unable to fetch current scan status.', 'wpmudev-plugin-test' ), 'error' );
		} finally {
			if ( manual ) {
				setIsRefreshing( false );
			}
		}
	};

	const handleStartScan = async () => {
		if ( selectedTypes.length === 0 ) {
			showNotice( __( 'Select at least one post type to scan.', 'wpmudev-plugin-test' ), 'error' );
			return;
		}

		setIsSubmitting( true );
		showNotice( null );

		try {
			const response = guardResponse(
				await apiFetch( {
					path: endpoints.run,
					method: 'POST',
					data: {
						post_types: selectedTypes,
						batch_size: batchSize,
					},
				} )
			);

			setStatus( response );
			showNotice( __( 'Scan started successfully.', 'wpmudev-plugin-test' ) );
		} catch ( error ) {
			showNotice(
				error?.message || __( 'Unable to start Posts Maintenance scan.', 'wpmudev-plugin-test' ),
				'error'
			);
		} finally {
			setIsSubmitting( false );
		}
	};

	const renderPostTypes = () => {
		if ( ! availableTypes.length ) {
			return <p>{ __( 'No eligible public post types were detected.', 'wpmudev-plugin-test' ) }</p>;
		}

		return availableTypes.map( ( type ) => (
			<CheckboxControl
				key={ type.value }
				label={ type.label }
				checked={ selectedTypes.includes( type.value ) }
				onChange={ ( value ) => togglePostType( type.value, value ) }
				disabled={ isSubmitting || isActive }
			/>
		) );
	};

	const updateBatchSize = ( value ) => {
		const parsed = Number( value );
		if ( Number.isNaN( parsed ) ) {
			setBatchSize( defaultBatchSize );
			return;
		}

		setBatchSize( Math.max( 10, Math.min( 200, parsed ) ) );
	};

	const progressPercent = status?.percent || 0;
	const processed = status?.processed || 0;
	const total = status?.total || 0;
	const remaining = status?.remaining || Math.max( total - processed, 0 );

	return (
		<div className="wpmudev-pm-app">
			<div className="sui-header">
				<h1 className="sui-header-title">{ appData?.strings?.pageTitle }</h1>
				<p className="sui-description">{ appData?.strings?.pageDescription }</p>
			</div>

			{ notice?.message && (
				<Notice
					status={ notice.type }
					isDismissible
					onRemove={ () => showNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }

			<div className="wpmudev-pm-grid">
				<Card>
					<CardHeader>
						<strong>{ __( 'Scan Options', 'wpmudev-plugin-test' ) }</strong>
					</CardHeader>
					<CardBody>
						<div className="wpmudev-pm-section">
							<p className="wpmudev-pm-section__title">
								{ __( 'Post types to include', 'wpmudev-plugin-test' ) }
							</p>
							{ renderPostTypes() }
						</div>

						<div className="wpmudev-pm-section">
							<TextControl
								label={ __( 'Batch size', 'wpmudev-plugin-test' ) }
								help={ __( 'Number of posts processed per batch (10–200).', 'wpmudev-plugin-test' ) }
								type="number"
								min={ 10 }
								max={ 200 }
								value={ batchSize }
								onChange={ updateBatchSize }
								disabled={ isSubmitting || isActive }
							/>
						</div>
					</CardBody>
					<CardFooter>
						<Button
							variant="primary"
							onClick={ handleStartScan }
							disabled={ isSubmitting || isActive }
						>
							{ isSubmitting ? <Spinner /> : __( 'Scan Posts', 'wpmudev-plugin-test' ) }
						</Button>
					</CardFooter>
				</Card>

				<Card>
					<CardHeader>
						<strong>{ __( 'Scan Status', 'wpmudev-plugin-test' ) }</strong>
					</CardHeader>
					<CardBody>
						<p className="wpmudev-pm-status">
							{ __( 'Current status:', 'wpmudev-plugin-test' ) }{' '}
							<strong className={`status-${ status?.status || 'idle' }`}>
								{ status?.status ? status?.status : __( 'idle', 'wpmudev-plugin-test' ) }
							</strong>
						</p>

						<div className="wpmudev-pm-progress">
							<div className="wpmudev-pm-progress__bar">
								<div
									className="wpmudev-pm-progress__indicator"
									style={ { width: `${ progressPercent }%` } }
								/>
							</div>
							<p>
								{ sprintf(
									/* translators: 1: processed posts 2: total posts */
									__( '%1$d of %2$d posts processed', 'wpmudev-plugin-test' ),
									processed,
									total
								) }
							</p>
							<p>
								{ __( 'Remaining:', 'wpmudev-plugin-test' ) } { remaining }
							</p>
						</div>

						<div className="wpmudev-pm-meta">
							<p>
								{ __( 'Selected types:', 'wpmudev-plugin-test' ) }{' '}
								{ ( status?.post_types || selectedTypes ).join( ', ' ) || '—' }
							</p>
							<p>
								{ __( 'Next scheduled run:', 'wpmudev-plugin-test' ) } { formatDate( status?.nextRun ) }
							</p>
							<p>
								{ __( 'Last automatic run:', 'wpmudev-plugin-test' ) }{' '}
								{ formatDate( status?.lastRun?.finished_at ) }
							</p>
						</div>

						{ status?.last_error && (
							<Notice status="error" isDismissible={ false }>
								{ status.last_error }
							</Notice>
						) }
					</CardBody>
					<CardFooter>
						<Button
							variant="secondary"
							onClick={ () => fetchStatus( true ) }
							disabled={ isRefreshing }
						>
							{ isRefreshing ? <Spinner /> : __( 'Refresh status', 'wpmudev-plugin-test' ) }
						</Button>
					</CardFooter>
				</Card>
			</div>
		</div>
	);
};

const rootElement = document.getElementById( appData.domId || 'wpmudev_posts_maintenance_root' );

if ( rootElement ) {
	createRoot( rootElement ).render(
		<StrictMode>
			<PostsMaintenanceApp />
		</StrictMode>
	);
}
