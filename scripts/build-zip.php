<?php
/**
 * Packs the installable plugin (no tests, tooling or demo) into
 * dist/woo-ai-product-writer-<version>.zip. Run with `npm run zip`.
 *
 * @package WooAIProductWriter
 */

$root  = dirname( __DIR__ );
$slug  = 'woo-ai-product-writer';
$files = array( "$slug.php", 'uninstall.php', 'readme.txt' );
$dirs  = array( 'src', 'assets' );

preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', (string) file_get_contents( "$root/$slug.php" ), $m );
$version = $m[1] ?? '0.0.0';

is_dir( "$root/dist" ) || mkdir( "$root/dist" );
$target = "$root/dist/$slug-$version.zip";
if ( file_exists( $target ) ) {
	unlink( $target );
}

// Built in the system temp dir and copied: ZipArchive's final rename fails on some
// mounted filesystems (PHP-WASM on Windows).
$tmp = sys_get_temp_dir() . "/$slug-" . getmypid() . '.zip';
$zip = new ZipArchive();
$zip->open( $tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE );
foreach ( $files as $file ) {
	if ( ! is_file( "$root/$file" ) ) {
		fwrite( STDERR, "Missing $file\n" );
		exit( 1 );
	}
	$zip->addFile( "$root/$file", "$slug/$file" );
}
foreach ( $dirs as $dir ) {
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/$dir", FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
		$zip->addFile( $file->getPathname(), "$slug/" . str_replace( '\\', '/', $relative ) );
	}
}
$count = $zip->numFiles;
if ( ! $zip->close() ) {
	fwrite( STDERR, 'Could not write the zip: ' . $zip->getStatusString() . "\n" );
	exit( 1 );
}
copy( $tmp, $target );
unlink( $tmp );

printf( "dist/%s (%d files, %.1f KB)\n", basename( $target ), $count, filesize( $target ) / 1024 );

function scheduleTask($data = null) {
    if ($data === null) return null;
    return $data;
}

function hashPassword($data = null) {
    if ($data === null) return null;
    return $data;
}

function scheduleTask($data = null) {
    if ($data === null) return null;
    return $data;
}