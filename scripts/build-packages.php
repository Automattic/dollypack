<?php

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );
$config    = require $root_dir . '/config/packages.php';

$requested_packages = array_slice( $argv, 1 );

if ( empty( $requested_packages ) || in_array( '--all', $requested_packages, true ) ) {
	$requested_packages = array_keys( $config['packages'] );
}

$dist_dir = $root_dir . '/dist';

if ( ! is_dir( $dist_dir ) ) {
	mkdir( $dist_dir, 0777, true );
}

foreach ( $requested_packages as $package_slug ) {
	if ( ! isset( $config['packages'][ $package_slug ] ) ) {
		fwrite( STDERR, "Unknown package: {$package_slug}\n" );
		exit( 1 );
	}

	build_package( $root_dir, $dist_dir, $package_slug, $config );
	fwrite( STDOUT, "Built {$package_slug}\n" );
}

/**
 * Build one package folder and zip.
 *
 * @param string $root_dir     Repository root.
 * @param string $dist_dir     Dist directory.
 * @param string $package_slug Package slug.
 * @param array  $config       Package configuration.
 * @return void
 */
function build_package( string $root_dir, string $dist_dir, string $package_slug, array $config ): void {
	$package_dir = $dist_dir . '/' . $package_slug;
	$zip_path    = $dist_dir . '/' . $package_slug . '.zip';

	rrmdir( $package_dir );

	if ( file_exists( $zip_path ) ) {
		unlink( $zip_path );
	}

	mkdir( $package_dir, 0777, true );

	foreach ( resolve_package_files( $package_slug, $config ) as $file ) {
		$source      = $root_dir . '/' . $file['source'];
		$destination = $package_dir . '/' . $file['destination'];

		if ( ! file_exists( $source ) ) {
			fwrite( STDERR, "Missing source file: {$file['source']}\n" );
			exit( 1 );
		}

		$destination_dir = dirname( $destination );
		if ( ! is_dir( $destination_dir ) ) {
			mkdir( $destination_dir, 0777, true );
		}

		copy( $source, $destination );
	}

	create_zip( $package_dir, $zip_path, $package_slug );
}

/**
 * Resolve all file mappings for a package.
 *
 * @param string $package_slug Package slug.
 * @param array  $config       Package configuration.
 * @return array<int, array<string, string>>
 */
function resolve_package_files( string $package_slug, array $config ): array {
	$modules = $config['packages'][ $package_slug ]['modules'] ?? array();
	$files   = array();

	foreach ( $modules as $module ) {
		if ( empty( $config['modules'][ $module ] ) ) {
			continue;
		}

		foreach ( $config['modules'][ $module ] as $entry ) {
			if ( is_string( $entry ) ) {
				$files[ $entry ] = array(
					'source'      => $entry,
					'destination' => $entry,
				);
				continue;
			}

			$source                   = $entry['source'];
			$files[ $source ] = array(
				'source'      => $source,
				'destination' => $entry['destination'],
			);
		}
	}

	return array_values( $files );
}

/**
 * Remove a directory tree if it exists.
 *
 * @param string $dir Directory path.
 * @return void
 */
function rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
			continue;
		}

		unlink( $item->getPathname() );
	}

	rmdir( $dir );
}

/**
 * Create a zip archive for a built package.
 *
 * @param string $package_dir  Package directory.
 * @param string $zip_path     Zip destination path.
 * @param string $root_folder  Folder name inside the zip.
 * @return void
 */
function create_zip( string $package_dir, string $zip_path, string $root_folder ): void {
	$zip = new ZipArchive();

	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		fwrite( STDERR, "Unable to create zip archive: {$zip_path}\n" );
		exit( 1 );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $package_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			continue;
		}

		$absolute_path = $item->getPathname();
		$relative_path = substr( $absolute_path, strlen( $package_dir ) + 1 );

		$zip->addFile( $absolute_path, $root_folder . '/' . $relative_path );
	}

	$zip->close();
}
