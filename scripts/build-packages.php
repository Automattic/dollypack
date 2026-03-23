<?php

declare( strict_types=1 );

$root_dir = dirname( __DIR__ );
$config   = require $root_dir . '/config/packages.php';

$options            = parse_cli_arguments( array_slice( $argv, 1 ) );
$requested_packages = $options['packages'];
$version            = determine_build_version( $root_dir, $options['version'] );

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

	build_package( $root_dir, $dist_dir, $package_slug, $config, $version );
	fwrite( STDOUT, "Built {$package_slug} ({$version})\n" );
}

/**
 * Build one package folder and zip.
 *
 * @param string $root_dir     Repository root.
 * @param string $dist_dir     Dist directory.
 * @param string $package_slug Package slug.
 * @param array  $config       Package configuration.
 * @param string $version      Build version.
 * @return void
 */
function build_package( string $root_dir, string $dist_dir, string $package_slug, array $config, string $version ): void {
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

	inject_package_version( $package_dir, $package_slug, $config, $version );
	validate_package( $package_dir );
	create_zip( $package_dir, $zip_path, $package_slug );
}

/**
 * Parse CLI arguments.
 *
 * @param array<int, string> $args CLI arguments.
 * @return array{packages: array<int, string>, version: string}
 */
function parse_cli_arguments( array $args ): array {
	$packages = array();
	$version  = '';

	for ( $index = 0, $count = count( $args ); $index < $count; $index++ ) {
		$argument = $args[ $index ];

		if ( 0 === strpos( $argument, '--version=' ) ) {
			$version = substr( $argument, 10 );
			continue;
		}

		if ( '--version' === $argument ) {
			$index++;
			$version = $args[ $index ] ?? '';
			continue;
		}

		$packages[] = $argument;
	}

	return array(
		'packages' => $packages,
		'version'  => $version,
	);
}

/**
 * Resolve the version to inject into packaged plugins.
 *
 * @param string $root_dir         Repository root.
 * @param string $requested_version Explicitly requested version.
 * @return string
 */
function determine_build_version( string $root_dir, string $requested_version ): string {
	$requested_version = trim( $requested_version );
	if ( '' !== $requested_version ) {
		return normalize_version_string( $requested_version );
	}

	$environment_version = trim( (string) getenv( 'DOLLYPACK_VERSION' ) );
	if ( '' !== $environment_version ) {
		return normalize_version_string( $environment_version );
	}

	$github_ref_type = trim( (string) getenv( 'GITHUB_REF_TYPE' ) );
	$github_ref_name = trim( (string) getenv( 'GITHUB_REF_NAME' ) );
	if ( 'tag' === $github_ref_type && '' !== $github_ref_name ) {
		return normalize_version_string( $github_ref_name );
	}

	return extract_source_version( $root_dir . '/dollypack.php' );
}

/**
 * Normalize a version string from CLI or tag input.
 *
 * @param string $version Version input.
 * @return string
 */
function normalize_version_string( string $version ): string {
	$version = trim( $version );

	if ( 0 === strpos( $version, 'v' ) || 0 === strpos( $version, 'V' ) ) {
		$version = substr( $version, 1 );
	}

	if ( ! preg_match( '/^[0-9A-Za-z][0-9A-Za-z.+-]*$/', $version ) ) {
		fwrite( STDERR, "Invalid version string: {$version}\n" );
		exit( 1 );
	}

	return $version;
}

/**
 * Extract the source plugin version from a plugin header.
 *
 * @param string $file Plugin file.
 * @return string
 */
function extract_source_version( string $file ): string {
	$contents = file_get_contents( $file );

	if ( false === $contents ) {
		fwrite( STDERR, "Unable to read plugin header: {$file}\n" );
		exit( 1 );
	}

	if ( ! preg_match( '/^[ \t\/*#@]*Version:\s*(.+)$/mi', $contents, $matches ) ) {
		fwrite( STDERR, "Unable to find Version header in {$file}\n" );
		exit( 1 );
	}

	return trim( $matches[1] );
}

/**
 * Inject the build version into the packaged plugin header.
 *
 * @param string $package_dir  Built package directory.
 * @param string $package_slug Package slug.
 * @param array  $config       Package configuration.
 * @param string $version      Build version.
 * @return void
 */
function inject_package_version( string $package_dir, string $package_slug, array $config, string $version ): void {
	$main_file = $config['packages'][ $package_slug ]['main_file'] ?? '';

	if ( '' === $main_file ) {
		fwrite( STDERR, "Missing main_file for package: {$package_slug}\n" );
		exit( 1 );
	}

	$main_file_path = $package_dir . '/' . $main_file;

	if ( ! file_exists( $main_file_path ) ) {
		fwrite( STDERR, "Missing main plugin file: {$main_file_path}\n" );
		exit( 1 );
	}

	$contents = file_get_contents( $main_file_path );

	if ( false === $contents ) {
		fwrite( STDERR, "Unable to read main plugin file: {$main_file_path}\n" );
		exit( 1 );
	}

	$updated_contents = preg_replace(
		'/^( \* Version:\s*).+$/m',
		'${1}' . $version,
		$contents,
		1,
		$count
	);

	if ( null === $updated_contents || 1 !== $count ) {
		fwrite( STDERR, "Unable to update Version header in {$main_file_path}\n" );
		exit( 1 );
	}

	file_put_contents( $main_file_path, $updated_contents );
}

/**
 * Validate a built package before zipping it.
 *
 * @param string $package_dir Built package directory.
 * @return void
 */
function validate_package( string $package_dir ): void {
	if ( ! class_exists( 'ZipArchive' ) ) {
		fwrite( STDERR, "ZipArchive is not available.\n" );
		exit( 1 );
	}

	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $package_dir, FilesystemIterator::SKIP_DOTS )
	);

	foreach ( $iterator as $item ) {
		if ( $item->isDir() || 'php' !== strtolower( $item->getExtension() ) ) {
			continue;
		}

		lint_php_file( $item->getPathname() );
	}
}

/**
 * Lint one PHP file using the current PHP binary.
 *
 * @param string $file PHP file path.
 * @return void
 */
function lint_php_file( string $file ): void {
	$command = escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file ) . ' 2>&1';
	$output  = array();
	$status  = 0;

	exec( $command, $output, $status );

	if ( 0 === $status ) {
		return;
	}

	fwrite(
		STDERR,
		sprintf(
			"PHP lint failed for %s\n%s\n",
			$file,
			implode( "\n", $output )
		)
	);
	exit( 1 );
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
