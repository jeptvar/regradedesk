<?php
/** Build an installable plugin ZIP containing only runtime files and documentation. */
$root = dirname( __DIR__ );
if ( ! is_dir( "$root/dist" ) ) { mkdir( "$root/dist", 0777, true ); }
$zip = new ZipArchive();
$path = "$root/dist/regradedesk-0.1.2.zip";
if ( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
    throw new RuntimeException( 'Cannot open release ZIP.' );
}
$files = array( 'regradedesk.php', 'readme.txt', 'README.md', 'LICENSE', 'includes/class-workbench.php', 'includes/class-admin.php', 'includes/views/dashboard.php', 'includes/views/inspection.php', 'assets/admin.js', 'assets/admin.css' );
foreach ( $files as $file ) {
    if ( ! is_file( "$root/$file" ) ) { throw new RuntimeException( "Missing $file" ); }
    $zip->addFile( "$root/$file", "regradedesk/$file" );
}
$zip->close();
echo "$path\nSHA256 " . hash_file( 'sha256', $path ) . "\n";
