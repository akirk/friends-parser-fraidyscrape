<?php
/**
 * Friends Fraidyscrape Parser Wrapper
 *
 * With this parser, we can import RSS and Atom Feeds for a friend.
 *
 * @package Friends_Parser_Fraidyscrape
 */

namespace Friends;

/**
 * This is the class for the Friends Parser Fraidyscrape.
 *
 * @since 1.0
 *
 * @package Friends_Parser_Fraidyscrape
 * @author  Alex Kirk
 */
class Feed_Parser_Fraidyscrape extends Feed_Parser_V2 {

	const NAME = 'Fraidyscrape';
	const URL  = 'https://github.com/akirk/friends-parser-fraidyscrape';

	/**
	 * Determines if this is a supported feed and to what degree we feel it's supported.
	 *
	 * @param      string      $url        The url.
	 * @param      string      $mime_type  The mime type.
	 * @param      string      $title      The title.
	 * @param      string|null $content    The content, it can't be assumed that it's always available.
	 *
	 * @return     int  Return 0 if unsupported, a positive value representing the confidence for the feed, use 10 if you're reasonably confident.
	 */
	public function feed_support_confidence( $url, $mime_type, $title, $content = null ) {
		$f = $this->get_fraidyscrape();
		$tasks = $f->detect( $url );

		if ( empty( $tasks ) ) {
			return 0;
		}
		if ( empty( $tasks->queue ) || ( 1 === count( $tasks->queue ) && 'default' === $tasks->queue[0] ) ) {
			return 0;
		}

		return get_option( 'friends-parser-fraidyscrape_confidence', 10 );
	}

	private function get_fraidyscrape() {
		static $fraidyscrape = null;

		if ( ! isset( $fraidyscrape ) ) {
			include_once __DIR__ . '/class-fraidyscrape.php';
			$social_json = file_get_contents( __DIR__ . '/social.json' );
			$defs = json_decode( $social_json, true );
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

		$host = parse_url( strtolower( $feed_details['url'] ), PHP_URL_HOST );

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
	 * @param      string    $url        The url.
	 * @param      User_Feed $user_feed  The user feed.
	 *
	 * @return     array            An array of feed items.
	 */
	public function fetch_feed( $url, User_Feed $user_feed = null ) {
		$f = $this->get_fraidyscrape();
		$tasks = $f->detect( $url );

		$expiration = 3500;

		$req = true;
		$feed = array();
		while ( true ) {
			$req = $f->next_request( $tasks );
			if ( ! $req ) {
				break;
			}

			if ( $req['render'] ) {
				// $obj = render( $req, $tasks );
			} else {
				$cache_key = sha1( $req['url'] . serialize( $req['options'] ) );
				$res = get_site_transient( $cache_key );
				if ( ! $res ) {
					$res = wp_safe_remote_request(
						$req['url'],
						array_merge( $req['options'], array( 'cache' => 'no-cache' ) )
					);

					if ( is_wp_error( $res ) ) {
						return $res;
					}

					set_site_transient( $cache_key, $res, $expiration );
				} elseif ( apply_filters( 'friends_debug', false ) ) {
					echo 'using cache for ', esc_html( $req['url'] ), '<br/>';
				}

				$cookies = wp_remote_retrieve_cookies( $res );

				$obj = $f->scrape( $tasks, $req, $res );
			}

			if ( isset( $obj['out'] ) ) {
				$feed = $obj['out'];
			}
		}
		if ( isset( $feed['posts'] ) ) {
			$feed = $feed['posts'];
		}
		$feed_items = array();
		foreach ( $feed as $item ) {
			if ( empty( $item['url'] ) ) {
				continue;
			}
			$content = $item['html'] ?? $item['text'] ?? array();
			if ( is_array( $content ) ) {
				$content = implode( PHP_EOL, $content );
			}
			$feed_item = new Feed_Item(
				array(
					'permalink' => $item['url'],
					'title'     => $item['title'] ?? '',
					'author'    => $item['author'] ?? '',
					'content'   => $content,
				)
			);

			if ( isset( $item['publishedAt'] ) && $item['publishedAt'] instanceof \DateTime ) {
				$feed_item->date = $item['publishedAt']->format( 'Y-m-d H:i:s' );
			}

			$feed_items[] = $feed_item;
		}

		usort(
			$feed_items,
			function( $a, $b ) {
				return $b->date <=> $a->date;
			}
		);

		return $feed_items;
	}

}
