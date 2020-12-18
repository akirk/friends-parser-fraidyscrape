<?php
/**
 * This is a PHP port of Fraidyscrape
 *
 * https://github.com/kickscondor/fraidyscrape
 */

namespace Fraidyscrape;
use Sabre\Uri;
use JsonPath\JsonObject;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

function varr( $vars, $x ) {
	foreach ( explode( ':', $x ) as $var ) {
		if ( isset( $vars[ $var ] ) ) {
			$vars = &$vars[ $var ];
		} else {
			return '';
		}
	}
	return empty( $vars ) ? '' : $vars;
}

function varx( $str, $vars ) {
	if ( ! is_string( $str ) ) {
		return $str;
	}

	$str = preg_replace_callback(
		'/\${(.+)}/',
		function ( $x ) use ( $vars ) {
			$v = varx( $x[1], $vars );
			return varr( $vars, $v );
		},
		$str
	);

	$str = preg_replace_callback(
		'/\$([:\w]+)/',
		function ( $x ) use ( $vars ) {
			return varr( $vars, $x[1] );
		},
		$str
	);
	return $str;
}

function endsWith( $haystack, $needle ) {
    return substr( $haystack, - strlen( $needle ) ) === $needle;
}

function transformXpath( $path ) {
	if ( endsWith( $path, 'text()' ) ) {
		$path = rtrim( substr( $path, 0, -6 ), '/' );
	}
	return $path;
}

function jsonPath( $obj, $path, $asText ) {
	$jsonObject = new jsonObject( $obj );
	$r = $jsonObject->get( $path );

	if ( $asText ) {
		return array_shift( $r );
	}

	return $r;
}

class Scraper {
	private $defs;
	function __construct( $defs ) {
		$this->defs = $defs;
	}

	private function normalizeUrl( $link ) {
		$url = Uri\normalize( $link );
		$protocol = strpos( $url, '://' );

		// strip the protocol
		$url = substr( $url, $protocol + 3 );

		return $url;
	}

	private function assign( $options, $additions, $vars, $mods = null, $plain_value = false ) {
		foreach ( $additions as $id => $val ) {
			$id = varx( $id, $vars );

			if ( ! $val ) {
				unset( $options[ $id ] );
				continue;
			}

			if ( ! $plain_value ) {
				$val = varx( $val, $vars );
			}

			if ( is_array( $mods ) ) {
				foreach ( $mods as $trans ) {
					if ( 'date' === $trans ) {
						if ( is_string( $val ) ) {
							if ( preg_match( '/^\d{14,}/', $val ) ) {
								$val = substr( $val, 0, 4 ) . '-' . substr( $val, 4, 2 ) . '-' . substr( $val, 6, 2 ) . ' ' . substr( $val, 8, 2 ) . ':' . substr( $val, 10, 2 ) . ':' . substr( $val, 12, 2 ) . 'Z';
							} elseif ( preg_match( '/^\w+\s+\d{1,2}[a-z]*$/', $val ) ) {
								$val = $val . ', ' . date( 'Y' );
							}
						}
						if ( $val ) {
							$val = new \DateTime( $val );
						}
					} elseif ( 'int' === $trans ) {
						$val = intval( $val );
					} elseif ( 'slug' === $trans ) {
						$val = '#' . urlencode( $trans );
					} elseif ( 'url' === $trans ) {
						$val = Uri\resolve( $vars['url'], $val );
					} elseif ( 'decode-uri' === $trans ) {
						$val = urldecode( $val );
					} elseif ( 'encode-uri' === $trans ) {
						$val = urlencode( $val );
					} elseif ( 'html-to-text' === $trans ) {
						$val = html_entity_decode( $val );
					} elseif ( 0 === strpos( '*', $trans ) ) {
						$val *= intval( substr( $trans, 1 ) );
					} elseif ( 'lowercase' === $trans ) {
						$val = strtolower( $val );
					} elseif ( 'uppercase' === $trans ) {
						$val = strtoupper( $val );
					}
				}
			}

			$node = &$options;
			if ( false !== strpos( $id, ':' ) ) {
				$subkeys = array_reverse( explode( ':', $id ) );
				$id = array_shift( $subkeys );
				foreach ( $subkeys as $key ) {
					if ( ! isset( $node[ $key ] )  ) {
						$node[ $key ] = array();
					}
					$node = &$node[ $key ];
				}
			}

			$node[ $id ] = $val;
		}

		return $options;
	}

	public function detect( $url ) {
		$norm = $this->normalizeUrl( $url );
		$queue = array( 'default' );
		$vars = array(
			'url' => $url,
		);

		foreach ( $this->defs as $id => $site ) {
			$site = (object) $site;
			if ( ! isset( $site->match ) ) {
				continue;
			}

			if ( ! preg_match( '#' . $site->match . '#', $norm, $match ) ) {
				continue;
			}
			if ( isset( $site->arguments ) ) {
				foreach ( $site->arguments as $i => $argument ) {
					if ( is_string( $argument ) ) {
						$vars[ $argument ] = $match[ $i ];
					} elseif ( is_array( $argument ) ) {
						$vars = $this->assign(
							$vars,
							array(
								$argument['var'] => $match[ $i ],
							),
							$vars,
							isset( $argument->mod ) ? $argument->mod : null,
							true
						);
					}
				}
			}

			$queue = array();
			if ( isset( $site->depends ) ) {
				$queue = $site->depends;
			}
			$queue[] = $id;
			break;
		}

		return (object) array(
			'queue' => $queue,
			'vars' => $vars,
		);
	}

	public function nextRequest( $tasks ) {
		if ( empty( $tasks->queue ) ) {
			return;
		}

		$id = array_shift( $tasks->queue );
		$req = $this->setupRequest( $tasks, $this->defs[ $id ] );
		$req['id'] = $id;

		return $req;
	}

	public function setupRequest( $tasks, $req ) {
		$options = $this->assign(
			array(),
			array(
				'url' => ! empty( $req['url'] ) ? $req['url'] : $tasks->vars['url'],
				'headers' => array(),
				'credentials' => 'omit',
			),
			$tasks->vars,
		);
		$hostname = parse_url( $options['url'], PHP_URL_HOST );
		if ( isset( $this->defs['domains'][ $hostname ] ) ) {
			$options = $this->assign( $options, $this->defs['domains'][ $hostname ], $tasks->vars );
		}

		if ( ! empty( $req['request'] ) ) {
			$options = $this->assign( $options, $req['request'], $tasks->vars );
		}

		$url = parse_url( $options['url'] );
		if ( isset( $options['query'] ) ) {
			$url['query'] = array();
			foreach ( $options['query'] as $key => $val ) {
				$url['query'][] = urlencode( $key ) . '=' . urlencode( $val );
			}
			$url['query'] = implode( '&', $url['query'] );
			unset( $options['query'] );
		}

		return 	array(
			'url' => Uri\build( $url ),
			'options' => $options,
			'render' => ! empty( $req['render'] ) ? $req['render'] : null,
		);
	}

	public function parseHtml( $str, $mime_type ) {
		$dom = new \DOMDocument();
		if ( false !== strpos( $mime_type, 'html' ) ) {
			@$dom->loadHtml( $str );
		} else {
			$dom->loadXml( $str );
		}
		return $dom;
	}

	public function scrape( $tasks, $req, $res ) {
		$site = $this->defs[ $req['id'] ];
		$vars = $this->scrapeRule( $tasks, $res, $site );
		$vars['rule'] = $req['id'];
		return $vars;
	}

	public function scrapeRule( $tasks, $res, $site ) {
		$body = wp_remote_retrieve_body( $res );
		$mime = wp_remote_retrieve_header( $res, 'content-type' );

		if ( preg_match( '/^\s*[{\[]/', $body ) ) {
			$tasks->vars['doc'] = json_decode( $body );
			if ( is_array( $tasks->vars['doc'] ) ) {
				$tasks->vars['doc'] = array( 'list' => $tasks->vars['doc'] );
			}
			$mime = 'application/json';
		} elseif ( preg_match( '/^\s*</m',  $body ) ) {
		    // The [\s\S] matches ANY char - while the dot (,) doesn't match newlines
			if ( preg_match( '/^\s*<\?xml\s+[\s\S]+<(rss|atom)/i', $body ) ) {
				$mime = 'text/xml';
			}
			$tasks->vars['doc'] = $this->parseHtml( $body, $mime );
		} else {
			$mime = 'text/plain';
			$tasks->vars['doc'] = $body;
		}
		$tasks->vars['mime'] = $mime;


		$vars = $this->scanSite( $tasks->vars, $site, $tasks->vars['doc'] );
		unset( $tasks->vars['doc'] );
		return $vars;
	}

	public function scanSite( &$vars, $site, $obj ) {
		$oldNs = $vars['namespaces'];
		$oldRules = $vars['rules'];

		$vars['namespaces'] = $site['namespaces'];
		$vars['rules'] = $site['rules'];

		$v = $this->scan( $vars, $site, $obj );

		$site['namespaces'] = $oldNs;
		$site['rules'] = $oldRules;

		return $v;
	}

	public function scan( &$vars, $site, $obj ) {
		$script = null;
		$fn = function ( $path, $asText ) use ( $obj ) {
			$path = str_replace( array( '===', '!==' ), array( '==', '!=' ), $path );
			return jsonPath( $obj, $path, $asText );
		};

		if ( isset( $site['accept'] ) ) {
			foreach ( $site['accept'] as $accept ) {
				$this->scanSite( $vars, $this->defs[ $accept ], $obj );
				if ( isset( $vars['out'] ) ) {
					break;
				}
			}
		}
		if ( isset( $site['acceptJson'] ) ) {
			if ( is_string( $obj ) ) {
				$vars['mime'] = 'application/json';
				$obj = json_decode( $obj );
			} elseif ( ! isset( $vars['mime'] ) ) {
				$vars['mime'] = 'application/json';
			} elseif ( $vars['mime'] !== 'application/json' ) {
				return $vars;
			}
			$script = $site['acceptJson'];
		} elseif ( isset( $site['acceptText'] ) ) {
			// if (obj.innerText) {
			// 	obj = obj.innerText
			// }
			if ( is_string( $obj ) && ! isset( $vars['mime'] ) ) {
				$vars['mime'] = 'text/plain';
			} elseif ( $vars['mime'] !== 'text/plain' ) {
				return $vars;
			}
			$obj = strval( $obj );
			$script = $site['acceptText'];
			$fn = function ( $path, $asText ) use ( $obj ) {
				if ( $asText ) {
					if ( preg_match( '/' . preg_quote( $path, '/' ) . '/m', $match ) ) {
						return isset( $match[1] ) ? $match[1] : $obj;
					}
					return null;
				}

				preg_match_all( '/' . preg_quote( $path, '/' ) . '/m', $matches, PREG_SET_ORDER );
				return $matches;
			};
		} elseif ( isset( $site['acceptHtml'] ) || isset( $site['acceptXml'] ) ) {
			if ( is_string( $obj ) || ! isset( $vars['mime' ]) ) {
				$vars['mime'] = isset( $site['acceptHtml'] ) ? 'text/html' : 'text/xml';
			} elseif ( $vars['mime'] === 'application/json' ) {
				return $vars;
			}
			$script = isset( $site['acceptHtml'] ) ? $site['acceptHtml'] : $site['acceptXml'];
			$fn = function ( $path, $asText ) use ( $obj, $vars ) {
				if ( ! is_array( $path ) ) {
				  $path = array( $path );
				}

				$xpath = new \DomXPath( $vars['doc'] );
				if ( is_array( $vars['namespaces'] ) ) {
					foreach ( $vars['namespaces'] as $prefix => $namespace ) {
						$xpath->registerNamespace( $prefix, $namespace );
					}
				}

				foreach ( $path as $p ) {
					$p = transformXpath( $p );
					$list = array(); // Reset to array in case it was an empty DOMNodeList
					if ( $obj instanceof \DOMNodeList ) {
						foreach ( $obj as $node ) {
							$domnodelist = $xpath->query( $p, $node );
							if ( count( $domnodelist ) ) {
								foreach ( $domnodelist as $domnode ) {
									$list[] = $domnode;
								}
							}
						}
					} else {
						$list = $xpath->query( $p, $obj );
					}

					if ( count( $list ) > 0 ) {
						break;
					}
				}

				if ( 0 === count( $list ) ) {
					return $asText ? '' : array();
				}

				if ( $asText ) {
					$domlist = $list;
					$list = array();
					foreach ( $domlist as $v ) {
						if ( $v instanceof \DOMAttr ) {
							$list[] = $v->value;
						} elseif ( $v instanceof \DOMElement ) {
							$list[] = $v->textContent;
						} else {
							$list[] = $v;
						}
					}

					return trim( implode( '', $list ) );
				}

				return $list;
			};
		} elseif ( isset( $site['patch'] ) ) {
			$script = $site['patch'];
			if ( ! $site['op'] ) {
				$obj = $vars['out'];
			}
		}

		if ( $script ) {
			if ( is_array( $obj ) && isset( $obj[0] ) ) {
				$out = array();
				if ( ! isset( $site['var'] ) || '*' !== $site['var'] ) {
					unset( $vars['out'] );
				}
				foreach ( $obj as $i => $el ) {
					$vars['index'] = $i;
					$this->scan( $vars, $site, $el );
					if ( ! isset( $site['var'] ) || '*' !== $site['var'] ) {
						$out[] = $vars['out'];
					}
				}
				if ( ! isset( $site['var'] ) || '*' !== $site['var'] ) {
					$vars['out'] = $out;
				}

			} elseif ( $fn ) {
				$this->scanScript( $vars, $script, $obj, $fn );
			}
		}

		return $vars;
	}

	public function scanScript( & $vars, $script, $node, $pathFn ) {
		foreach ( $script as $cmd ) {
			if ( isset( $cmd['rule'] ) ) {
				$rule = isset( $vars['rules'][ $cmd['rule'] ] ) ? $vars['rules'][ $cmd['rule'] ] : null;
				if ( $rule ) {
					$this->scanScript( $vars, $rule, $node, $pathFn );
				}
			}

			$ops = $cmd['op'];
			$val = null;
			if ( ! is_array( $ops ) ) {
				$ops = array( $ops );
			}

			foreach ( $ops as $op ) {
				$op = varx( $op, $vars );
				if ( ! $op ) {
					continue;
				}

				$hasChildren = isset( $cmd['acceptJson'] ) || isset( $cmd['acceptText'] ) || isset( $cmd['acceptHtml'] ) || isset( $cmd['acceptXml'] ) || isset( $cmd['patch'] ) || isset( $cmd['use'] );
				$asText = ! $hasChildren && ! ( is_array( $cmd ) && isset( $cmd['match'] ) );

				if ( '=' === $op[0] ) {
					$val = substr( $op, 1 );
				} elseif ( '&' === $op[0] ) {
					$val = jsonPath( $vars, '$' . substr( $op, 1 ), $asText );
				} else {
					$val = $pathFn( $op, $asText );
				}

				if ( is_array( $cmd ) && isset( $cmd['match'] ) ) {
					if ( is_array( $val ) && $val['match'] && preg_match( '/' . preg_quote( $cmd['match'], '/' ) . '/', $val, $match ) ) {
						var_dump($match);exit;
						$val = isset( $match[1] ) ? $match[1] : $val;
					} else {
						continue;
					}
				}

				if ( isset( $this->defs[ $cmd['use'] ] ) && strlen( $val ) > 0 ) {
					$use = $this->defs[ $cmd['use'] ];
					return $this->scanSite( $vars, $use, $node );
				}

				// If there is a nested ruleset, process it.
				if ( $hasChildren ) {
					if ( isset( $cmd['var'] ) ) {
						if ( '*' !== $cmd['var'] ) {
							unset( $vars['out'] );
						}
					} elseif ( is_array( $val ) ) {
						$val = array_shift( $val );
					}

					if ( $val ) {
						$this->scan( $vars, $cmd, $val );
					}

					if ( isset( $cmd['var'] ) && '*' !== $cmd['var'] ) {
						$val = $vars['out'];
					}
				}

				// If object contains anything at all, no need to run
				// further ops in a chain.
				if ( $val ) {
					break;
				}
			}

			// See 'assign' method above.
			if ( isset( $cmd['var'] ) && '*' !== $cmd['var'] ) {
				$vars = $this->assign( $vars, array( $cmd['var'] => $val ), $vars, isset( $cmd['mod'] ) ? $cmd['mod'] : null, true );
			}
		}
	}
}
