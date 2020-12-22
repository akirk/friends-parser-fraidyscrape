<?php
/**
 * Friends Fraidyscrape Parser Wrapper
 *
 * With this parser, we can import RSS and Atom Feeds for a friend.
 *
 * @package Friends_Parser_Fraidyscrape
 */

/**
 * This is the class for the Friends Parser Fraidyscrape.
 *
 * @since 1.0
 *
 * @package Friends_Parser_Fraidyscrape
 * @author  Alex Kirk
 */
class Friends_Feed_Parser_Fraidyscrape extends Friends_Feed_Parser {

	const NAME = 'Fraidyscrape';
	const URL  = 'https://github.com/akirk/friends-parser-fraidyscrape';

	/**
	 * Determines if this is a supported feed and to what degree we feel it's supported.
	 *
	 * @param      string $url        The url.
	 * @param      string $mime_type  The mime type.
	 * @param      string $title      The title.
	 *
	 * @return     int  Return 0 if unsupported, a positive value representing the confidence for the feed, use 10 if you're reasonably confident.
	 */
	public function feed_support_confidence( $url, $mime_type, $title ) {
		$f = $this->get_fraidyscrape();
		$tasks = $f->detect( $url );
		if ( empty( $tasks ) ) {
			return 0;
		}
		if ( empty( $tasks->queue ) || ( 1 === count( $tasks->queue ) && 'default' === $tasks->queue[0] ) ){
			return 0;
		}

		return 13;
	}

	private function get_fraidyscrape() {
		static $fraidyscrape = null;

		if ( ! isset( $fraidyscrape ) ) {
			include_once __DIR__ . '/class-fraidyscrape.php';
			$defs = json_decode( file_get_contents( __DIR__ . '/social.json' ), true );
			$fraidyscrape = new \Fraidyscrape\Scraper( $defs );
		}
		return $fraidyscrape;
	}

	/**
	 * Format the feed title and autoselect the posts feed.
	 *
	 * @param array $feed_details The feed details.
	 *
	 * @return array  The (potentially) modified feed details.
	 */
	public function update_feed_details( $feed_details ) {
		if ( ! isset( $feed_details['url'] ) ) {
			return $feed_details;
		}

		$host = parse_url( strtolower( $url ), PHP_URL_HOST );

		switch ( $host ) {
			case 'twitter.com':
				$feed_details['post-format'] = 'status';
				break;
		}

		return $feed_details;
	}

	/**
	 * Fetches a feed and returns the processed items.
	 *
	 * @param string $url The url.
	 *
	 * @return array            An array of feed items.
	 */
	public function fetch_feed( $url ) {
		$f = $this->get_fraidyscrape();
		$tasks = $f->detect( $url );

		$expiration = 3500;

		$req = true;
		$cookies = array();
		while ( true ) {
			$req = $f->nextRequest( $tasks );
			if ( ! $req ) {
				break;
			}

			if ( $req['render'] ) {
				$obj = render( $req, $tasks );
			} else {
				$cache_key = sha1( $req['url'] . serialize( $req['options'] ) );
				$res = get_site_transient( $cache_key );
				if ( ! $res ) {
					$res = wp_safe_remote_request(
						$req['url'],
						array_merge( $req['options'], array( 'cache' => 'no-cache' ) )
					);

					if ( is_wp_error( $res ) ) {
						var_dump( $res );
						exit;
					}

					set_site_transient( $cache_key, $res, $expiration );
				} else {
					echo 'using cache for ', $req['url'], '<br>';
				}

				$cookies = wp_remote_retrieve_cookies( $res );

				$obj = $f->scrape( $tasks, $req, $res );
			}

			$feed = $obj['out'];
		}

		$feed_items = array();
		foreach ( $feed as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}
			$feed_item       = (object) array(
				'permalink'   => $item['url'],
				'title'       => $item['title'] ?? '',
				'title'     => implode( PHP_EOL, $item['text'] ?? array() ),
				'post-format' => 'standard',
			);
			if ( $item['publishedAt'] instanceof DateTime ) {
				$feed_item->date = $item['publishedAt']->format( 'Y-m-d H:i:s' );
			}

			$feed_items[] = $feed_item;
		}

		usort( $feed_items, function( $a, $b ) {
			return $b->date <=> $a->date;
		});

		return $feed_items;
	}

}
