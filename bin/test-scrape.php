<?php
/**
 * Script to test the scraper.
 *
 * @package Friends
 */

if ( empty( $_SERVER['argv'][1] ) || ! filter_var( $_SERVER['argv'][1], FILTER_VALIDATE_URL ) ) {
	echo 'Usage: ', $_SERVER['PHP_SELF'], ' <url>', PHP_EOL;
	echo
	exit;
}
$url = $_SERVER['argv'][1];

// Load WordPress.
include dirname( __DIR__, 4 ) . '/wp-load.php';
include dirname( __DIR__ ) . '/class-fraidyscrape.php';

$defs = json_decode( file_get_contents( dirname( __DIR__ ) . '/social.json' ), true );
$f = new \Fraidyscrape\Scraper( $defs );
$tasks = $f->detect( $url );
if ( empty( $tasks ) ) {
	echo 'No scraper found for this URL.', PHP_EOL;

}
$req = true;
$cookies = array();
while ( true ) {
	$req = $f->next_request( $tasks );
	if ( ! $req ) {
		break;
	}

	if ( $req['render'] ) {
		$obj = render( $req, $tasks );
	} else {
		$res = wp_safe_remote_request(
			$req['url'],
			array_merge( $req['options'], array( 'cache' => 'no-cache' ) )
		);

		if ( is_wp_error( $res ) ) {
			var_dump( $res );
			exit;
		}
		$cookies = wp_remote_retrieve_cookies( $res );

		$obj = $f->scrape( $tasks, $req, $res );
		// echo 'scraped';
		// var_dump( $obj );
	}

	$feed = $obj['out'];
}
echo json_encode( $feed, JSON_PRETTY_PRINT ), PHP_EOL;
