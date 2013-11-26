<?php
/*
 * YOURLS
 * Function library
 */

/**
 * Determine the allowed character set in short URLs
 * 
 */
function yourls_get_shorturl_charset() {
	static $charset = null;
	if( $charset !== null )
		return $charset;
		
	if( !defined('YOURLS_URL_CONVERT') ) {
		$charset = '0123456789abcdefghijklmnopqrstuvwxyz';
	} else {
		switch( YOURLS_URL_CONVERT ) {
			case 36:
				$charset = '0123456789abcdefghijklmnopqrstuvwxyz';
				break;
			case 62:
			case 64: // just because some people get this wrong in their config.php
				$charset = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
				break;
		}
	}
	
	$charset = yourls_apply_filter( 'get_shorturl_charset', $charset );
	return $charset;
}
 
/**
 * Make an optimized regexp pattern from a string of characters
 * 
 */
function yourls_make_regexp_pattern( $string ) {
	$pattern = preg_quote( $string, '-' ); // add - as an escaped characters -- this is fixed in PHP 5.3
	// TODO: replace char sequences by smart sequences such as 0-9, a-z, A-Z ... ?
	return $pattern;
}

/**
 * Is a URL a short URL? Accept either 'http://sho.rt/abc' or 'abc'
 * 
 */
function yourls_is_shorturl( $shorturl ) {
	// TODO: make sure this function evolves with the feature set.
	
	$is_short = false;
	
	// Is $shorturl a URL (http://sho.rt/abc) or a keyword (abc) ?
	if( yourls_get_protocol( $shorturl ) ) {
		$keyword = yourls_get_relative_url( $shorturl );
	} else {
		$keyword = $shorturl;
	}
	
	// Check if it's a valid && used keyword
	if( $keyword && $keyword == yourls_sanitize_string( $keyword ) && yourls_keyword_is_taken( $keyword ) ) {
		$is_short = true;
	}
	
	return yourls_apply_filter( 'is_shorturl', $is_short, $shorturl );
}

/**
 * Check to see if a given keyword is reserved (ie reserved URL or an existing page). Returns bool
 *
 */
function yourls_keyword_is_reserved( $keyword ) {
	global $yourls_reserved_URL;
	$keyword = yourls_sanitize_keyword( $keyword );
	$reserved = false;
	
	if ( in_array( $keyword, $yourls_reserved_URL)
		or file_exists( YOURLS_ABSPATH ."/pages/$keyword.php" )
		or is_dir( YOURLS_ABSPATH ."/$keyword" )
	)
		$reserved = true;
	
	return yourls_apply_filter( 'keyword_is_reserved', $reserved, $keyword );
}

/**
 * Function: Get client IP Address. Returns a DB safe string.
 *
 */
function yourls_get_IP() {
	$ip = '';

	// Precedence: if set, X-Forwarded-For > HTTP_X_FORWARDED_FOR > HTTP_CLIENT_IP > HTTP_VIA > REMOTE_ADDR
	$headers = array( 'X-Forwarded-For', 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'HTTP_VIA', 'REMOTE_ADDR' );
	foreach( $headers as $header ) {
		if ( !empty( $_SERVER[ $header ] ) ) {
			$ip = $_SERVER[ $header ];
			break;
		}
	}
	
	// headers can contain multiple IPs (X-Forwarded-For = client, proxy1, proxy2). Take first one.
	if ( strpos( $ip, ',' ) !== false )
		$ip = substr( $ip, 0, strpos( $ip, ',' ) );
	
	return yourls_apply_filter( 'get_IP', yourls_sanitize_ip( $ip ) );
}

/**
 * Get next id a new link will have if no custom keyword provided
 *
 */
function yourls_get_next_decimal() {
	return yourls_apply_filter( 'get_next_decimal', (int)yourls_get_option( 'next_id' ) );
}

/**
 * Update id for next link with no custom keyword
 *
 */
function yourls_update_next_decimal( $int = '' ) {
	$int = ( $int == '' ) ? yourls_get_next_decimal() + 1 : (int)$int ;
	$update = yourls_update_option( 'next_id', $int );
	yourls_do_action( 'update_next_decimal', $int, $update );
	return $update;
}

/**
 * Delete a link in the DB
 *
 */
function yourls_delete_link_by_keyword( $keyword ) {
	global $ydb;

	$table = YOURLS_DB_TABLE_URL;
	$keyword = yourls_sanitize_string( $keyword );
	$delete = $ydb->query("DELETE FROM `$table` WHERE `keyword` = '$keyword';");
	yourls_do_action( 'delete_link', $keyword, $delete );
	return $delete;
}

/**
 * SQL query to insert a new link in the DB. Returns boolean for success or failure of the inserting
 *
 */
function yourls_insert_link_in_db( $url, $keyword, $title = '' ) {
	global $ydb;
	
	$url     = yourls_escape( yourls_sanitize_url( $url ) );
	$keyword = yourls_escape( yourls_sanitize_keyword( $keyword ) );
	$title   = yourls_escape( yourls_sanitize_title( $title ) );

	$table = YOURLS_DB_TABLE_URL;
	$timestamp = date('Y-m-d H:i:s');
	$ip = yourls_get_IP();
	$insert = $ydb->query("INSERT INTO `$table` (`keyword`, `url`, `title`, `timestamp`, `ip`, `clicks`) VALUES('$keyword', '$url', '$title', '$timestamp', '$ip', 0);");
	
	yourls_do_action( 'insert_link', (bool)$insert, $url, $keyword, $title, $timestamp, $ip );
	
	return (bool)$insert;
}

/**
 * Check if a URL already exists in the DB. Return NULL (doesn't exist) or an object with URL informations.
 *
 */
function yourls_url_exists( $url ) {
	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_url_exists', false, $url );
	if ( false !== $pre )
		return $pre;

	global $ydb;
	$table = YOURLS_DB_TABLE_URL;
	$strip_url = stripslashes($url);
	$url_exists = $ydb->get_row("SELECT * FROM `$table` WHERE `url` = '".$strip_url."';");
	
	return yourls_apply_filter( 'url_exists', $url_exists, $url );
}

/**
 * Find or create a shorturl for a particular long url, with the option to specify keyword and title
 *
 */
function yourls_add_new_link( $url, $keyword = '', $title = '' ) {
	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_add_new_link', false, $url, $keyword, $title );
	if ( false !== $pre )
		return $pre;
	
	// All call use the $run_add_new_link_hooks parameter to ensure -- for
	// backwards compatibility -- that all actions and filters are run
	if ( !yourls_allow_duplicate_longurls() && yourls_url_exists( $url ) ) {
		// Return existing link regardless of keyword status
		// URL must exist (per if condition) and will be returned by the default get operation so no special options are needed
		// The hooks for add_new were added to the !$keyword branch so a blank keyword is sent to ensure this branch is used
		$return = yourls_get_or_create_link( $url, '', $title, false, false, false, true );
		// Despite returning a shortURL, original return codes are 'fail' and 'error:url'
		$return['status']   = 'fail';
		$return['code']     = 'error:url';
	} elseif ( $keyword && (  yourls_allow_duplicate_longurls() || !yourls_url_exists( $url ) ) ) {
		// If duplicate URLs are allowed or the URL does not yet exist
		// Create successfully or return 'fail' with 200 status code
		// The requested object (or an error) is ensured using the options strict_create and strict_keyword
		$return = yourls_get_or_create_link( $url, $keyword, $title, true, true, false, true );
		// The original function provided less detailed error codes (adjusted below)
		// The original function returned a 200 status code under all three (originally two) error states
		if ($return['status'] == 'fail' && ($return['code'] == 'error:keyword_reserved' || $return['code'] == 'error:keyword_taken')) {
			$return['statusCode'] = 200;
			$return['code']    = 'error:keyword';
		} elseif ($return['status'] == 'fail' && $return['code'] == 'error:db') {
			$return['statusCode'] = 200;
		}
	} elseif ( !$keyword && (  yourls_allow_duplicate_longurls() || !yourls_url_exists( $url ) ) ) {
		// If no keyword is provided and duplicate URLs are allowed or
		// If no keyword is provided and the URL does not yet exist then
		// Create a new link using a randomly generated keyword
		// Creation of new link is ensured by calling strict_create
		// Keyword will be automatically generated becuase strict_keyword is defaulted to false
		$return = yourls_get_or_create_link( $url, $keyword, $title, true, false, false, true );
		if ($return['status'] == 'fail' && ($return['code'] == 'error:keyword_reserved' || $return['code'] == 'error:keyword_taken') ) {
			$return['statusCode'] = 200;
			$return['code']    = 'error:keyword';
		} elseif ($return['status'] == 'fail' && $return['code'] == 'error:db') {
			$return['statusCode'] = 200;
		}
	}

	// In the original code, most returns with status 'fail' were not processed
	// by the final action and filter. These returns could be distinguished by 
	// their status code since all were set to 200. Because status codees are 
	// simulated above, we use this to distinguish between errors needing 
	// and not needing processed by the action and filter.
	if ( $return['statusCode'] != '200' ) {
		return $return;
	}
	
	yourls_do_action( 'post_add_new_link', $url, $keyword, $title );
	return yourls_apply_filter( 'add_new_link', $return, $url, $keyword, $title );
}

/**
 * Find or create a shorturl for a particular long url, with the option to specify keyword and title
 * @param string $url long URL target of requested short URL
 * @param string $keyword preferred keyword to be used in shorturl (optional).
 * @param string $title preferred keyword to be used (optional).  If empty, the title of the target URL will be used.
 * @param bool $strict_create indicates that the request must create a new entry in the database (optional, default false).
 * @param bool $strict_keyword indicates that a resource must exist using the keyword provided (optional, default false).  If true, the keyword cannot be blank.
 * @param bool $strict_title indicates that a resource must exist using the title provided (optional, default false).  If true, title cannot be blank.
 * @return array response values (status, code, ...)
 */
function yourls_get_or_create_link( $url, $keyword = '', $title = '', $strict_create = false, $strict_keyword = false, $strict_title = false, $run_add_new_link_hooks = false) {
	// Clean and validate parameters (url, keyword, title)
	$url = yourls_encodeURI( $url );
	$url = yourls_escape( yourls_sanitize_url( $url ) );
	if ( !$url || $url == 'http://' || $url == 'https://' ) {
		$return = array(
					'statusCode' => 400,
					'status' => 'fail',
					'code' => 'error:url',
					'message'  => yourls__( 'Missing or malformed URL' ),
					);
		if ($run_add_new_link_hooks) {
			return yourls_apply_filter( 'add_new_link_fail_nourl', $return, $url, $keyword, $title );
		} else {
			return $return;
		}
	}
	$keyword_sanitized = yourls_escape( yourls_sanitize_string( $keyword ) );
	if ($strict_keyword && empty($keyword_sanitized)) {
		// Must have a keyword to enforce strict usage
		return array(
					'statusCode' => 400,
					'status' => 'fail',
					'code' => 'error:keyword',
					'message' => yourls_s( 'Valid keyword must be provided when $strict_keyword is enabled.' ),
					);
	}
	$title_sanitized = yourls_sanitize_title( $title );
	// Allow caller to enforce a strict (but empty) title
	if ( !$strict_title && empty( $title_sanitized ) ) {
		// Sanitized inside yourls_get_remote_title()
		$title_sanitized = yourls_get_remote_title( $url );
	}
	
	// Prevent DB flood
	$ip = yourls_get_IP();
	yourls_check_IP_flood( $ip );
	
	// Prevent internal redirection loops: cannot shorten a shortened URL
	if( yourls_get_relative_url( $url ) ) {
		if( yourls_is_shorturl( $url ) ) {
			$return = array(
						'statusCode' => 403,
						'status' => 'fail',
						'code' => 'error:noloop',
						'message' => yourls__( 'URL is a short URL' ),
						);
			if ($run_add_new_link_hooks) {
				return yourls_apply_filter( 'add_new_link_fail_noloop', $return, $url, $keyword, $title );
			} else {
				return $return;
			}
		}
	}
	
	if ($run_add_new_link_hooks) {
		yourls_do_action( 'pre_add_new_link', $url, $keyword, $title );
	}
	
	$return = false;
	
	// Even if it is not strictly required, prefer a shorturl using the caller's keyword
	if ( $keyword_sanitized || $strict_keyword ) { // $strict_keyword is implicit in $keyword_sanitized but included for clarity
		if ( !$strict_create && yourls_keyword_is_taken( $keyword_sanitized ) ) {
			// If create is not strict, see if the existing entry for the requested keyword is acceptable
			$info = yourls_get_keyword_infos ( $keyword );
			if ( $info['url'] == $url && ( !$strict_title || $title_sanitized = $info['title'] ) ) {
				// Valid match exists
				return array(
							'statusCode' => 200,
							'status' => 'success',
							'title' =>  $info['title'],
							'message'  => /* //translators: eg "http://someurl/ already exists" */ yourls_s( '%s already exists in database', yourls_trim_long_string( stripslashes($url) ) ),
							'url' => array('keyword' => $keyword, 'url' => stripslashes($url), 'title' =>  $info['title'], 'date' => $info['timestamp'] , 'ip' => $info['ip'], 'clicks' => $info['clicks'] ),
							'html' => yourls_table_add_row( $keyword, $url, $info['title'],  $info['ip'], $info['clicks'], $info['timestamp'] ),
							'shorturl' => YOURLS_SITE .'/'. $keyword,
							);
			} elseif ($strict_keyword) {
				// Must use caller's keyword and valid match does not exist
				return array(
							'statusCode' => 403,
							'status' => 'fail',
							'code' => 'error:keyword_taken',
							'message'  => yourls_s( 'Short URL %s already exists but does not match requested values.', $keyword ),
							);
			}
		} else {
			// Create is strict or keyword is available
			//  - If create is strict, the response to this call is authoritative
			//  - If keyword is available, we should be able to grab it
			if ($run_add_new_link_hooks) {
				yourls_do_action( 'add_new_link_custom_keyword', $url, $keyword, $title_sanitized );
				$keyword = yourls_apply_filter( 'custom_keyword', $keyword, $url, $title_sanitized );
			}
			return yourls_create_link ($url, $keyword, $title_sanitized, $run_add_new_link_hooks);
			// This could theoretically be reached under a race condition (i.e.
			// the keyword was taken since the availability check).  This 
			// function assumes that an error at this point is fatal, as did 
			// the previous implementation.
		}
	}

	// $return should only be false if the following are true:
	// - the keyword is not strict
	// - either:
	//   - the keyword is not provided or
	//   - the provided keyword was unacceptable
	if ( !$return && !$strict_create ) {
		// If create is not strict, see if an acceptable keyword already exists
		$keywords = yourls_get_longurl_keywords ( $url );
		foreach ( $keywords as $keyword ) {
			$info = yourls_get_keyword_infos ( $keyword );
			// URL must be correct and keyword cannot be strict
			if ( !$strict_title || $title_sanetized = $info['title'] ) {
				if ( $run_add_new_link_hooks ) {
					yourls_do_action( 'add_new_link_already_stored', $url, $keyword, $title );
				}
				return array(
							'statusCode' => 200,
							'status' => 'success',
							'title' =>  $info['title'],
							'message' => yourls_s( 'Keyword already exists', $keyword ),
							'url' => array('keyword' => $keyword, 'url' => stripslashes($url), 'title' =>  $info['title'], 'date' => $info['timestamp'] , 'ip' => $info['ip'], 'clicks' => $info['clicks'] ),
							'html' => yourls_table_add_row( $keyword, $url, $info['title'], $info['ip'], $info['clicks'], $info['timestamp'] ),
							'shorturl' => YOURLS_SITE .'/'. $keyword,
							);
			}
		}
	}
	
	// $return should only be false if the following are true:
	// - the keyword is not strict
	// - either:
	//   - the keyword is not provided or
	//   - the provided keyword was unacceptable
	// - no existing shorturls meet the caller's requirements
	if (!$return) {
		// Try to create an acceptable keyword
		if ($run_add_new_link_hooks) {
			yourls_do_action( 'add_new_link_create_keyword', $url, $keyword, $title_sanitized );
		}
		$id = yourls_get_next_decimal();
		$ok = false;
		do {
			$keyword = yourls_int2string( $id );
			$keyword = yourls_apply_filter( 'random_keyword', $keyword, $url, $title_sanitized );
			$return = yourls_create_link ($url, $keyword, $title_sanitized, $run_add_new_link_hooks);
			$id++;
			// Only loop on resolvable keyword issues.  All other issues (illegal duplicate url, db error, success) returned to caller.
		} while ( $return['status'] == 'fail' && ($return['code'] == 'error:keyword_reserved' || $return['code'] == 'error:keyword_taken' ) );
		@yourls_update_next_decimal( $id );
		return $return;
	}
	
	// This shouldn't happen, but ensures a response
	if (!$return) {
		return array(
					'statusCode' => 500,
					'status' => 'fail',
					'code' => 'error:unknown',
					'message'  => yourls_s( 'YOURLS function reached an invalid location' ),
					);
	}
}

/**
 * Add a shorturl to the database using the specified url, keyword, and title
 * @param string $url long URL target of requested short URL
 * @param string $strict_keyword keyword to be used in shorturl.
 * @param string $strict_title title to be used.
 * @return array response values (status, code, ...)
 */
function yourls_create_link ($url, $strict_keyword, $strict_title, $run_add_new_link_hooks = false) {
	if ( yourls_keyword_is_reserved( $strict_keyword ) ) {
		// Keyword Reserved
		return array(
					'statusCode' => 403,
					'status' => 'fail',
					'code' => 'error:keyword_reserved',
					'message' => yourls_s( 'Short URL %s is reserved', $strict_keyword ),
					);
	} elseif ( yourls_keyword_is_taken( $strict_keyword ) ) {
		// Keyword Taken
		return array(
					'statusCode' => 403,
					'status' => 'fail',
					'code' => 'error:keyword_taken',
					'message' => yourls_s( 'Short URL %s already exists', $strict_keyword ),
					);
	} elseif ( !yourls_allow_duplicate_longurls() && ( $url_exists = yourls_url_exists( $url ) ) ) {
		// Illegal Duplicate
		if ($run_add_new_link_hooks) {
			yourls_do_action( 'add_new_link_already_stored', $url, $strict_keyword, $strict_title );
		}
		return array(
					'statusCode' => 403,
					'status' => 'fail',
					'code' => 'error:url_illegalduplicate',
					'message' => yourls_s( 'Keyword already exists', $strict_keyword ),
					);
	} else {
		// Run actions and filters
		if ($run_add_new_link_hooks) {
			$strict_title = yourls_apply_filter( 'add_new_title', $strict_title, $url, $strict_keyword );
		}
		if( @yourls_insert_link_in_db( $url, $strict_keyword, $strict_title ) ){
			// Success
			return array(
						'statusCode' => 200,
						'status' => 'success',
						'title' => $strict_title,
						'message' => /* //translators: eg "http://someurl/ added to DB" */ yourls_s( '%s added to database', yourls_trim_long_string( stripslashes($url) ) ),
						'url' => array('keyword' => $strict_keyword, 'url' => stripslashes($url), 'title' => stripslashes($strict_title), 'date' => date( 'Y-m-d H:i:s' ), 'ip' => yourls_get_IP() ),
						'html' => yourls_table_add_row( $strict_keyword, $url, $strict_title, yourls_get_IP(), 0, time() ),
						'shorturl' => YOURLS_SITE .'/'. $strict_keyword,
						);
		} else {
			// Database Error
			return array(
						'statusCode' => 500,
						'status' => 'fail',
						'code' => 'error:db',
						'message' => yourls_s( 'Error saving url to database' ),
						);
		}
	}
}

/**
 * Edit a link
 *
 */
function yourls_edit_link( $url, $keyword, $newkeyword='', $title='' ) {
	global $ydb;

	$table = YOURLS_DB_TABLE_URL;
	$url = yourls_escape (yourls_sanitize_url( $url ) );
	$keyword = yourls_escape( yourls_sanitize_string( $keyword ) );
	$title = yourls_escape( yourls_sanitize_title( $title ) );
	$newkeyword = yourls_escape( yourls_sanitize_string( $newkeyword ) );
	$strip_url = stripslashes( $url );
	$strip_title = stripslashes( $title );
	$old_url = $ydb->get_var( "SELECT `url` FROM `$table` WHERE `keyword` = '$keyword';" );
	
	// Check if new URL is not here already
	if ( $old_url != $url && !yourls_allow_duplicate_longurls() ) {
		$new_url_already_there = intval($ydb->get_var("SELECT COUNT(keyword) FROM `$table` WHERE `url` = '$strip_url';"));
	} else {
		$new_url_already_there = false;
	}
	
	// Check if the new keyword is not here already
	if ( $newkeyword != $keyword ) {
		$keyword_is_ok = yourls_keyword_is_free( $newkeyword );
	} else {
		$keyword_is_ok = true;
	}
	
	yourls_do_action( 'pre_edit_link', $url, $keyword, $newkeyword, $new_url_already_there, $keyword_is_ok );
	
	// All clear, update
	if ( ( !$new_url_already_there || yourls_allow_duplicate_longurls() ) && $keyword_is_ok ) {
			$update_url = $ydb->query( "UPDATE `$table` SET `url` = '$url', `keyword` = '$newkeyword', `title` = '$title' WHERE `keyword` = '$keyword';" );
		if( $update_url ) {
			$return['url']     = array( 'keyword' => $newkeyword, 'shorturl' => YOURLS_SITE.'/'.$newkeyword, 'url' => $strip_url, 'display_url' => yourls_trim_long_string( $strip_url ), 'title' => $strip_title, 'display_title' => yourls_trim_long_string( $strip_title ) );
			$return['status']  = 'success';
			$return['message'] = yourls__( 'Link updated in database' );
		} else {
			$return['status']  = 'fail';
			$return['message'] = /* //translators: "Error updating http://someurl/ (Shorturl: http://sho.rt/blah)" */ yourls_s( 'Error updating %s (Short URL: %s)', yourls_trim_long_string( $strip_url ), $keyword ) ;
		}
	
	// Nope
	} else {
		$return['status']  = 'fail';
		$return['message'] = yourls__( 'URL or keyword already exists in database' );
	}
	
	return yourls_apply_filter( 'edit_link', $return, $url, $keyword, $newkeyword, $title, $new_url_already_there, $keyword_is_ok );
}

/**
 * Update a title link (no checks for duplicates etc..)
 *
 */
function yourls_edit_link_title( $keyword, $title ) {
	global $ydb;
	
	$keyword = yourls_escape( yourls_sanitize_keyword( $keyword ) );
	$title = yourls_escape( yourls_sanitize_title( $title ) );
	
	$table = YOURLS_DB_TABLE_URL;
	$update = $ydb->query("UPDATE `$table` SET `title` = '$title' WHERE `keyword` = '$keyword';");

	return $update;
}


/**
 * Check if keyword id is free (ie not already taken, and not reserved). Return bool.
 *
 */
function yourls_keyword_is_free( $keyword ) {
	$free = true;
	if ( yourls_keyword_is_reserved( $keyword ) or yourls_keyword_is_taken( $keyword ) )
		$free = false;
		
	return yourls_apply_filter( 'keyword_is_free', $free, $keyword );
}

/**
 * Check if a keyword is taken (ie there is already a short URL with this id). Return bool.		
 *
 */
function yourls_keyword_is_taken( $keyword ) {

	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_keyword_is_taken', false, $keyword );
	if ( false !== $pre )
		return $pre;
	
	global $ydb;
	$keyword = yourls_sanitize_keyword( $keyword );
	$taken = false;
	$table = YOURLS_DB_TABLE_URL;
	$already_exists = $ydb->get_var( "SELECT COUNT(`keyword`) FROM `$table` WHERE `keyword` = '$keyword';" );
	if ( $already_exists )
		$taken = true;

	return yourls_apply_filter( 'keyword_is_taken', $taken, $keyword );
}


/**
 * Connect to DB
 *
 */
function yourls_db_connect() {
	global $ydb;

	if (   !defined( 'YOURLS_DB_USER' )
		or !defined( 'YOURLS_DB_PASS' )
		or !defined( 'YOURLS_DB_NAME' )
		or !defined( 'YOURLS_DB_HOST' )
	) yourls_die ( yourls__( 'Incorrect DB config, or could not connect to DB' ), yourls__( 'Fatal error' ), 503 );	

	// Are we standalone or in the WordPress environment?
	if ( class_exists( 'wpdb', false ) ) {
		/* TODO: should we deprecate this? Follow WP dev in that area */
		$ydb =  new wpdb( YOURLS_DB_USER, YOURLS_DB_PASS, YOURLS_DB_NAME, YOURLS_DB_HOST );
	} else {
		yourls_set_DB_driver();
	}
	
	// Check if connection attempt raised an error. It seems that only PDO does, though.
	if ( $ydb->last_error )
		yourls_die( $ydb->last_error, yourls__( 'Fatal error' ), 503 );
	
	if ( defined( 'YOURLS_DEBUG' ) && YOURLS_DEBUG === true )
		$ydb->show_errors = true;
	
	return $ydb;
}

/**
 * Return XML output.
 *
 */
function yourls_xml_encode( $array ) {
	require_once( YOURLS_INC.'/functions-xml.php' );
	$converter= new yourls_array2xml;
	return $converter->array2xml( $array );
}

/**
 * Return array of all information associated with keyword. Returns false if keyword not found. Set optional $use_cache to false to force fetching from DB
 *
 */
function yourls_get_keyword_infos( $keyword, $use_cache = true ) {
	global $ydb;
	$keyword = yourls_sanitize_string( $keyword );

	yourls_do_action( 'pre_get_keyword', $keyword, $use_cache );

	if( isset( $ydb->infos[$keyword] ) && $use_cache == true ) {
		return yourls_apply_filter( 'get_keyword_infos', $ydb->infos[$keyword], $keyword );
	}
	
	yourls_do_action( 'get_keyword_not_cached', $keyword );
	
	$table = YOURLS_DB_TABLE_URL;
	$infos = $ydb->get_row( "SELECT * FROM `$table` WHERE `keyword` = '$keyword'" );
	
	if( $infos ) {
		$infos = (array)$infos;
		$ydb->infos[ $keyword ] = $infos;
	} else {
		$ydb->infos[ $keyword ] = false;
	}
		
	return yourls_apply_filter( 'get_keyword_infos', $ydb->infos[$keyword], $keyword );
}

/**
 * Return (string) selected information associated with a keyword. Optional $notfound = string default message if nothing found
 *
 */
function yourls_get_keyword_info( $keyword, $field, $notfound = false ) {

	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_get_keyword_info', false, $keyword, $field, $notfound );
	if ( false !== $pre )
		return $pre;

	$keyword = yourls_sanitize_string( $keyword );
	$infos = yourls_get_keyword_infos( $keyword );
	
	$return = $notfound;
	if ( isset( $infos[ $field ] ) && $infos[ $field ] !== false )
		$return = $infos[ $field ];

	return yourls_apply_filter( 'get_keyword_info', $return, $keyword, $field, $notfound );	
}

/**
 * Return title associated with keyword. Optional $notfound = string default message if nothing found
 *
 */
function yourls_get_keyword_title( $keyword, $notfound = false ) {
	return yourls_get_keyword_info( $keyword, 'title', $notfound );
}

/**
 * Return long URL associated with keyword. Optional $notfound = string default message if nothing found
 *
 */
function yourls_get_keyword_longurl( $keyword, $notfound = false ) {
	return yourls_get_keyword_info( $keyword, 'url', $notfound );
}

/**
 * Return number of clicks on a keyword. Optional $notfound = string default message if nothing found
 *
 */
function yourls_get_keyword_clicks( $keyword, $notfound = false ) {
	return yourls_get_keyword_info( $keyword, 'clicks', $notfound );
}

/**
 * Return IP that added a keyword. Optional $notfound = string default message if nothing found
 *
 */
function yourls_get_keyword_IP( $keyword, $notfound = false ) {
	return yourls_get_keyword_info( $keyword, 'ip', $notfound );
}

/**
 * Return timestamp associated with a keyword. Optional $notfound = string default message if nothing found
 *
 */
function yourls_get_keyword_timestamp( $keyword, $notfound = false ) {
	return yourls_get_keyword_info( $keyword, 'timestamp', $notfound );
}

/**
 * Update click count on a short URL. Return 0/1 for error/success.
 *
 */
function yourls_update_clicks( $keyword, $clicks = false ) {
	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_update_clicks', false, $keyword, $clicks );
	if ( false !== $pre )
		return $pre;

	global $ydb;
	$keyword = yourls_sanitize_string( $keyword );
	$table = YOURLS_DB_TABLE_URL;
	if ( $clicks !== false && is_int( $clicks ) && $clicks >= 0 )
		$update = $ydb->query( "UPDATE `$table` SET `clicks` = $clicks WHERE `keyword` = '$keyword'" );
	else
		$update = $ydb->query( "UPDATE `$table` SET `clicks` = clicks + 1 WHERE `keyword` = '$keyword'" );

	yourls_do_action( 'update_clicks', $keyword, $update, $clicks );
	return $update;
}

/**
 * Return array of stats. (string)$filter is 'bottom', 'last', 'rand' or 'top'. (int)$limit is the number of links to return
 *
 */
function yourls_get_stats( $filter = 'top', $limit = 10, $start = 0 ) {
	global $ydb;

	switch( $filter ) {
		case 'bottom':
			$sort_by    = 'clicks';
			$sort_order = 'asc';
			break;
		case 'last':
			$sort_by    = 'timestamp';
			$sort_order = 'desc';
			break;
		case 'rand':
		case 'random':
			$sort_by    = 'RAND()';
			$sort_order = '';
			break;
		case 'top':
		default:
			$sort_by    = 'clicks';
			$sort_order = 'desc';
			break;
	}
	
	// Fetch links
	$limit = intval( $limit );
	$start = intval( $start );
	if ( $limit > 0 ) {

		$table_url = YOURLS_DB_TABLE_URL;
		$results = $ydb->get_results( "SELECT * FROM `$table_url` WHERE 1=1 ORDER BY `$sort_by` $sort_order LIMIT $start, $limit;" );
		
		$return = array();
		$i = 1;
		
		foreach ( (array)$results as $res ) {
			$return['links']['link_'.$i++] = array(
				'shorturl' => YOURLS_SITE .'/'. $res->keyword,
				'url'      => $res->url,
				'title'    => $res->title,
				'timestamp'=> $res->timestamp,
				'ip'       => $res->ip,
				'clicks'   => $res->clicks,
			);
		}
	}

	$return['stats'] = yourls_get_db_stats();
	
	$return['statusCode'] = 200;

	return yourls_apply_filter( 'get_stats', $return, $filter, $limit, $start );
}

/**
 * Return array of stats. (string)$filter is 'bottom', 'last', 'rand' or 'top'. (int)$limit is the number of links to return
 *
 */
function yourls_get_link_stats( $shorturl ) {
	global $ydb;

	$table_url = YOURLS_DB_TABLE_URL;
	$res = $ydb->get_row( "SELECT * FROM `$table_url` WHERE keyword = '$shorturl';" );
	$return = array();

	if( !$res ) {
		// non existent link
		$return = array(
			'statusCode' => 404,
			'message'    => 'Error: short URL not found',
		);
	} else {
		$return = array(
			'statusCode' => 200,
			'message'    => 'success',
			'link'       => array(
				'shorturl' => YOURLS_SITE .'/'. $res->keyword,
				'url'      => $res->url,
				'title'    => $res->title,
				'timestamp'=> $res->timestamp,
				'ip'       => $res->ip,
				'clicks'   => $res->clicks,
			)
		);
	}

	return yourls_apply_filter( 'get_link_stats', $return, $shorturl );
}

/**
 * Get total number of URLs and sum of clicks. Input: optional "AND WHERE" clause. Returns array
 *
 */
function yourls_get_db_stats( $where = '' ) {
	global $ydb;
	$table_url = YOURLS_DB_TABLE_URL;

	$totals = $ydb->get_row( "SELECT COUNT(keyword) as count, SUM(clicks) as sum FROM `$table_url` WHERE 1=1 $where" );
	$return = array( 'total_links' => $totals->count, 'total_clicks' => $totals->sum );
	
	return yourls_apply_filter( 'get_db_stats', $return, $where );
}

/**
 * Get number of SQL queries performed
 *
 */
function yourls_get_num_queries() {
	global $ydb;

	return yourls_apply_filter( 'get_num_queries', $ydb->num_queries );
}

/**
 * Returns a sanitized a user agent string. Given what I found on http://www.user-agents.org/ it should be OK.
 *
 */
function yourls_get_user_agent() {
	if ( !isset( $_SERVER['HTTP_USER_AGENT'] ) )
		return '-';
	
	$ua = strip_tags( html_entity_decode( $_SERVER['HTTP_USER_AGENT'] ));
	$ua = preg_replace('![^0-9a-zA-Z\':., /{}\(\)\[\]\+@&\!\?;_\-=~\*\#]!', '', $ua );
		
	return yourls_apply_filter( 'get_user_agent', substr( $ua, 0, 254 ) );
}

/**
 * Redirect to another page
 *
 */
function yourls_redirect( $location, $code = 301 ) {
	yourls_do_action( 'pre_redirect', $location, $code );
	$location = yourls_apply_filter( 'redirect_location', $location, $code );
	$code     = yourls_apply_filter( 'redirect_code', $code, $location );
	// Redirect, either properly if possible, or via Javascript otherwise
	if( !headers_sent() ) {
		yourls_status_header( $code );
		header( "Location: $location" );
	} else {
		yourls_redirect_javascript( $location );
	}
	die();
}

/**
 * Set HTTP status header
 *
 */
function yourls_status_header( $code = 200 ) {
	if( headers_sent() )
		return;
		
	$protocol = $_SERVER['SERVER_PROTOCOL'];
	if ( 'HTTP/1.1' != $protocol && 'HTTP/1.0' != $protocol )
		$protocol = 'HTTP/1.0';

	$code = intval( $code );
	$desc = yourls_get_HTTP_status( $code );

	@header ("$protocol $code $desc"); // This causes problems on IIS and some FastCGI setups
	yourls_do_action( 'status_header', $code );
}

/**
 * Redirect to another page using Javascript. Set optional (bool)$dontwait to false to force manual redirection (make sure a message has been read by user)
 *
 */
function yourls_redirect_javascript( $location, $dontwait = true ) {
	yourls_do_action( 'pre_redirect_javascript', $location, $dontwait );
	$location = yourls_apply_filter( 'redirect_javascript', $location, $dontwait );
	if( $dontwait ) {
		$message = yourls_s( 'if you are not redirected after 10 seconds, please <a href="%s">click here</a>', $location );
		echo <<<REDIR
		<script type="text/javascript">
		window.location="$location";
		</script>
		<small>($message)</small>
REDIR;
	} else {
		echo '<p>' . yourls_s( 'Please <a href="%s">click here</a>', $location ) . '</p>';
	}
	yourls_do_action( 'post_redirect_javascript', $location );
}

/**
 * Return a HTTP status code
 *
 */
function yourls_get_HTTP_status( $code ) {
	$code = intval( $code );
	$headers_desc = array(
		100 => 'Continue',
		101 => 'Switching Protocols',
		102 => 'Processing',

		200 => 'OK',
		201 => 'Created',
		202 => 'Accepted',
		203 => 'Non-Authoritative Information',
		204 => 'No Content',
		205 => 'Reset Content',
		206 => 'Partial Content',
		207 => 'Multi-Status',
		226 => 'IM Used',

		300 => 'Multiple Choices',
		301 => 'Moved Permanently',
		302 => 'Found',
		303 => 'See Other',
		304 => 'Not Modified',
		305 => 'Use Proxy',
		306 => 'Reserved',
		307 => 'Temporary Redirect',

		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Request Entity Too Large',
		414 => 'Request-URI Too Long',
		415 => 'Unsupported Media Type',
		416 => 'Requested Range Not Satisfiable',
		417 => 'Expectation Failed',
		422 => 'Unprocessable Entity',
		423 => 'Locked',
		424 => 'Failed Dependency',
		426 => 'Upgrade Required',

		500 => 'Internal Server Error',
		501 => 'Not Implemented',
		502 => 'Bad Gateway',
		503 => 'Service Unavailable',
		504 => 'Gateway Timeout',
		505 => 'HTTP Version Not Supported',
		506 => 'Variant Also Negotiates',
		507 => 'Insufficient Storage',
		510 => 'Not Extended'
	);

	if ( isset( $headers_desc[$code] ) )
		return $headers_desc[$code];
	else
		return '';
}


/**
 * Log a redirect (for stats)
 *
 */
function yourls_log_redirect( $keyword ) {
	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_log_redirect', false, $keyword );
	if ( false !== $pre )
		return $pre;

	if ( !yourls_do_log_redirect() )
		return true;

	global $ydb;
	$table = YOURLS_DB_TABLE_LOG;
	
	$keyword = yourls_sanitize_string( $keyword );
	$referrer = ( isset( $_SERVER['HTTP_REFERER'] ) ? yourls_sanitize_url( $_SERVER['HTTP_REFERER'] ) : 'direct' );
	$ua = yourls_get_user_agent();
	$ip = yourls_get_IP();
	$location = yourls_geo_ip_to_countrycode( $ip );
	
	return $ydb->query( "INSERT INTO `$table` (click_time, shorturl, referrer, user_agent, ip_address, country_code) VALUES (NOW(), '$keyword', '$referrer', '$ua', '$ip', '$location')" );
}

/**
 * Check if we want to not log redirects (for stats)
 *
 */
function yourls_do_log_redirect() {
	return ( !defined( 'YOURLS_NOSTATS' ) || YOURLS_NOSTATS != true );
}

/**
 * Converts an IP to a 2 letter country code, using GeoIP database if available in includes/geo/
 *
 * @since 1.4
 * @param string $ip IP or, if empty string, will be current user IP
 * @param string $defaut Default string to return if IP doesn't resolve to a country (malformed, private IP...)
 * @return string 2 letter country code (eg 'US') or $default
 */
function yourls_geo_ip_to_countrycode( $ip = '', $default = '' ) {
	// Allow plugins to short-circuit the Geo IP API
	$location = yourls_apply_filter( 'shunt_geo_ip_to_countrycode', false, $ip, $default ); // at this point $ip can be '', check if your plugin hooks in here
	if ( false !== $location )
		return $location;
	
	if ( $ip == '' )
		$ip = yourls_get_IP();
	
	// Use IPv4 or IPv6 DB & functions
	if( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
		$db   = 'GeoIP.dat';
		$func = 'geoip_country_code_by_addr';
	} else {
		$db   = 'GeoIPv6.dat';
		$func = 'geoip_country_code_by_addr_v6';
	}
	
	if ( !file_exists( YOURLS_INC . '/geo/' . $db ) || !file_exists( YOURLS_INC .'/geo/geoip.inc' ) )
		return $default;

	require_once( YOURLS_INC . '/geo/geoip.inc' );
	$gi = geoip_open( YOURLS_INC . '/geo/' . $db, GEOIP_STANDARD );
	try {
		$location = call_user_func( $func, $gi, $ip );
	} catch ( Exception $e ) {
		$location = '';
	}
	geoip_close( $gi );
	
	if( '' == $location )
		$location = $default;

	return yourls_apply_filter( 'geo_ip_to_countrycode', $location, $ip, $default );
}

/**
 * Converts a 2 letter country code to long name (ie AU -> Australia)
 *
 */
function yourls_geo_countrycode_to_countryname( $code ) {
	// Allow plugins to short-circuit the Geo IP API
	$country = yourls_apply_filter( 'shunt_geo_countrycode_to_countryname', false, $code );
	if ( false !== $country )
		return $country;

	// Load the Geo class if not already done
	if( !class_exists( 'GeoIP', false ) ) {
		$temp = yourls_geo_ip_to_countrycode( '127.0.0.1' );
	}
	
	if( class_exists( 'GeoIP', false ) ) {
		$geo  = new GeoIP;
		$id   = $geo->GEOIP_COUNTRY_CODE_TO_NUMBER[ $code ];
		$long = $geo->GEOIP_COUNTRY_NAMES[ $id ];
		return $long;
	} else {
		return false;
	}
}

/**
 * Return flag URL from 2 letter country code
 *
 */
function yourls_geo_get_flag( $code ) {
	if( file_exists( YOURLS_INC.'/geo/flags/flag_'.strtolower($code).'.gif' ) ) {
		$img = yourls_match_current_protocol( YOURLS_SITE.'/includes/geo/flags/flag_'.( strtolower( $code ) ).'.gif' );
	} else {
		$img = false;
	}
	return yourls_apply_filter( 'geo_get_flag', $img, $code );
}


/**
 * Check if an upgrade is needed
 *
 */
function yourls_upgrade_is_needed() {
	// check YOURLS_DB_VERSION exist && match values stored in YOURLS_DB_TABLE_OPTIONS
	list( $currentver, $currentsql ) = yourls_get_current_version_from_sql();
	if( $currentsql < YOURLS_DB_VERSION )
		return true;
		
	return false;
}

/**
 * Get current version & db version as stored in the options DB. Prior to 1.4 there's no option table.
 *
 */
function yourls_get_current_version_from_sql() {
	$currentver = yourls_get_option( 'version' );
	$currentsql = yourls_get_option( 'db_version' );

	// Values if version is 1.3
	if( !$currentver )
		$currentver = '1.3';
	if( !$currentsql )
		$currentsql = '100';
		
	return array( $currentver, $currentsql);
}

/**
 * Read an option from DB (or from cache if available). Return value or $default if not found
 *
 */
function yourls_get_option( $option_name, $default = false ) {
	global $ydb;
	
	// Allow plugins to short-circuit options
	$pre = yourls_apply_filter( 'shunt_option_'.$option_name, false );
	if ( false !== $pre )
		return $pre;

	// If option not cached already, get its value from the DB
	if ( !isset( $ydb->option[$option_name] ) ) {
		$table = YOURLS_DB_TABLE_OPTIONS;
		$option_name = yourls_escape( $option_name );
		$row = $ydb->get_row( "SELECT `option_value` FROM `$table` WHERE `option_name` = '$option_name' LIMIT 1" );
		if ( is_object( $row) ) { // Has to be get_row instead of get_var because of funkiness with 0, false, null values
			$value = $row->option_value;
		} else { // option does not exist, so we must cache its non-existence
			$value = $default;
		}
		$ydb->option[ $option_name ] = yourls_maybe_unserialize( $value );
	}

	return yourls_apply_filter( 'get_option_'.$option_name, $ydb->option[$option_name] );
}

/**
 * Read all options from DB at once
 *
 */
function yourls_get_all_options() {
	global $ydb;

	// Allow plugins to short-circuit all options. (Note: regular plugins are loaded after all options)
	$pre = yourls_apply_filter( 'shunt_all_options', false );
	if ( false !== $pre )
		return $pre;

	$table = YOURLS_DB_TABLE_OPTIONS;
	
	$allopt = $ydb->get_results( "SELECT `option_name`, `option_value` FROM `$table` WHERE 1=1" );
	
	foreach( (array)$allopt as $option ) {
		$ydb->option[ $option->option_name ] = yourls_maybe_unserialize( $option->option_value );
	}

	if( property_exists( $ydb, 'option' ) ) {
		$ydb->option = yourls_apply_filter( 'get_all_options', $ydb->option );
		$ydb->installed = true;
	} else {
		// Zero option found: assume YOURLS is not installed
		$ydb->installed = false;
	}
}

/**
 * Update (add if doesn't exist) an option to DB
 *
 */
function yourls_update_option( $option_name, $newvalue ) {
	global $ydb;
	$table = YOURLS_DB_TABLE_OPTIONS;

	$safe_option_name = yourls_escape( $option_name );

	$oldvalue = yourls_get_option( $safe_option_name );

	// If the new and old values are the same, no need to update.
	if ( $newvalue === $oldvalue )
		return false;

	if ( false === $oldvalue ) {
		yourls_add_option( $option_name, $newvalue );
		return true;
	}

	$_newvalue = yourls_escape( yourls_maybe_serialize( $newvalue ) );
	
	yourls_do_action( 'update_option', $option_name, $oldvalue, $newvalue );

	$ydb->query( "UPDATE `$table` SET `option_value` = '$_newvalue' WHERE `option_name` = '$option_name'" );

	if ( $ydb->rows_affected == 1 ) {
		$ydb->option[ $option_name ] = $newvalue;
		return true;
	}
	return false;
}

/**
 * Add an option to the DB
 *
 */
function yourls_add_option( $name, $value = '' ) {
	global $ydb;
	$table = YOURLS_DB_TABLE_OPTIONS;
	$safe_name = yourls_escape( $name );

	// Make sure the option doesn't already exist
	if ( false !== yourls_get_option( $safe_name ) )
		return;

	$_value = yourls_escape( yourls_maybe_serialize( $value ) );

	yourls_do_action( 'add_option', $safe_name, $_value );

	$ydb->query( "INSERT INTO `$table` (`option_name`, `option_value`) VALUES ('$name', '$_value')" );
	$ydb->option[ $name ] = $value;
	return;
}


/**
 * Delete an option from the DB
 *
 */
function yourls_delete_option( $name ) {
	global $ydb;
	$table = YOURLS_DB_TABLE_OPTIONS;
	$name = yourls_escape( $name );

	// Get the ID, if no ID then return
	$option = $ydb->get_row( "SELECT option_id FROM `$table` WHERE `option_name` = '$name'" );
	if ( is_null( $option ) || !$option->option_id )
		return false;
		
	yourls_do_action( 'delete_option', $name );
		
	$ydb->query( "DELETE FROM `$table` WHERE `option_name` = '$name'" );
	return true;
}



/**
 * Serialize data if needed. Stolen from WordPress
 *
 */
function yourls_maybe_serialize( $data ) {
	if ( is_array( $data ) || is_object( $data ) )
		return serialize( $data );

	if ( yourls_is_serialized( $data ) )
		return serialize( $data );

	return $data;
}

/**
 * Check value to find if it was serialized. Stolen from WordPress
 *
 */
function yourls_is_serialized( $data ) {
	// if it isn't a string, it isn't serialized
	if ( !is_string( $data ) )
		return false;
	$data = trim( $data );
	if ( 'N;' == $data )
		return true;
	if ( !preg_match( '/^([adObis]):/', $data, $badions ) )
		return false;
	switch ( $badions[1] ) {
		case 'a' :
		case 'O' :
		case 's' :
			if ( preg_match( "/^{$badions[1]}:[0-9]+:.*[;}]\$/s", $data ) )
				return true;
			break;
		case 'b' :
		case 'i' :
		case 'd' :
			if ( preg_match( "/^{$badions[1]}:[0-9.E-]+;\$/", $data ) )
				return true;
			break;
	}
	return false;
}

/**
 * Unserialize value only if it was serialized. Stolen from WP
 *
 */
function yourls_maybe_unserialize( $original ) {
	if ( yourls_is_serialized( $original ) ) // don't attempt to unserialize data that wasn't serialized going in
		return @unserialize( $original );
	return $original;
}

/**
 * Determine if the current page is private
 *
 */
function yourls_is_private() {
	$private = false;

	if ( defined('YOURLS_PRIVATE') && YOURLS_PRIVATE == true ) {

		// Allow overruling for particular pages:
		
		// API
		if( yourls_is_API() ) {
			if( !defined('YOURLS_PRIVATE_API') || YOURLS_PRIVATE_API != false )
				$private = true;		

		// Infos
		} elseif( yourls_is_infos() ) {
			if( !defined('YOURLS_PRIVATE_INFOS') || YOURLS_PRIVATE_INFOS !== false )
				$private = true;
		
		// Others
		} else {
			$private = true;
		}
		
	}
			
	return yourls_apply_filter( 'is_private', $private );
}

/**
 * Show login form if required
 *
 */
function yourls_maybe_require_auth() {
	if( yourls_is_private() ) {
		yourls_do_action( 'require_auth' );
		require_once( YOURLS_INC.'/auth.php' );
	} else {
		yourls_do_action( 'require_no_auth' );
	}
}

/**
 * Allow several short URLs for the same long URL ?
 *
 */
function yourls_allow_duplicate_longurls() {
	// special treatment if API to check for WordPress plugin requests
	if( yourls_is_API() ) {
		if ( isset($_REQUEST['source']) && $_REQUEST['source'] == 'plugin' ) 
			return false;
	}
	return ( defined( 'YOURLS_UNIQUE_URLS' ) && YOURLS_UNIQUE_URLS == false );
}

/**
 * @deprecated Return list of all shorturls associated to the same long URL. Returns NULL or array of keywords.
 *
 */
function yourls_get_duplicate_keywords( $longurl ) {
	yourls_deprecated_function( __FUNCTION__, 1.7, 'yourls_get_longurl_keywords' );
	if( !yourls_allow_duplicate_longurls() )
		return NULL;
	return yourls_apply_filter( 'get_duplicate_keywords', yourls_get_longurl_keywords ( $longurl ), $longurl );
}

/**
 * Return list of keywords that redirect to the submitted long URL. Returns array of keywords.
 *
 */
function yourls_get_longurl_keywords( $longurl, $sort = 'none', $order = 'ASC' ) {
	global $ydb;
	$longurl = yourls_escape( yourls_sanitize_url($longurl) );
	$table = YOURLS_DB_TABLE_URL;
	
	$query = "SELECT `keyword` FROM `$table` WHERE `url` = '$longurl'";
	// Ensure sort is a column in database (update verification array if database changes)
	if ( in_array( $sort, array('keyword','title','timestamp','clicks') ) ) {
		$query .= " ORDER BY '".$sort."'";
		if ( in_array( $order, array('ASC','DESC') ) ) $query .= " ".$order;
	}
	return yourls_apply_filter( 'get_longurl_keywords', $ydb->get_col( $query ), $longurl );
}

/**
 * Check if an IP shortens URL too fast to prevent DB flood. Return true, or die.
 *
 */
function yourls_check_IP_flood( $ip = '' ) {

	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_check_IP_flood', false, $ip );
	if ( false !== $pre )
		return $pre;

	yourls_do_action( 'pre_check_ip_flood', $ip ); // at this point $ip can be '', check it if your plugin hooks in here

	if(
		( defined('YOURLS_FLOOD_DELAY_SECONDS') && YOURLS_FLOOD_DELAY_SECONDS === 0 ) ||
		!defined('YOURLS_FLOOD_DELAY_SECONDS')
	)
		return true;

	$ip = ( $ip ? yourls_sanitize_ip( $ip ) : yourls_get_IP() );

	// Don't throttle whitelist IPs
	if( defined( 'YOURLS_FLOOD_IP_WHITELIST' ) && YOURLS_FLOOD_IP_WHITELIST ) {
		$whitelist_ips = explode( ',', YOURLS_FLOOD_IP_WHITELIST );
		foreach( (array)$whitelist_ips as $whitelist_ip ) {
			$whitelist_ip = trim( $whitelist_ip );
			if ( $whitelist_ip == $ip )
				return true;
		}
	}
	
	// Don't throttle logged in users
	if( yourls_is_private() ) {
		 if( yourls_is_valid_user() === true )
			return true;
	}
	
	yourls_do_action( 'check_ip_flood', $ip );
	
	global $ydb;
	$table = YOURLS_DB_TABLE_URL;
	
	$lasttime = $ydb->get_var( "SELECT `timestamp` FROM $table WHERE `ip` = '$ip' ORDER BY `timestamp` DESC LIMIT 1" );
	if( $lasttime ) {
		$now = date( 'U' );
		$then = date( 'U', strtotime( $lasttime ) );
		if( ( $now - $then ) <= YOURLS_FLOOD_DELAY_SECONDS ) {
			// Flood!
			yourls_do_action( 'ip_flood', $ip, $now - $then );
			yourls_die( yourls__( 'Too many URLs added too fast. Slow down please.' ), yourls__( 'Forbidden' ), 403 );
		}
	}
	
	return true;
}

/**
 * Check if YOURLS is installing
 *
 * @return bool
 * @since 1.6
 */
function yourls_is_installing() {
	$installing = defined( 'YOURLS_INSTALLING' ) && YOURLS_INSTALLING == true;
	return yourls_apply_filter( 'is_installing', $installing );
}

/**
 * Check if YOURLS is upgrading
 *
 * @return bool
 * @since 1.6
 */
function yourls_is_upgrading() {
	$upgrading = defined( 'YOURLS_UPGRADING' ) && YOURLS_UPGRADING == true;
	return yourls_apply_filter( 'is_upgrading', $upgrading );
}


/**
 * Check if YOURLS is installed
 *
 * Checks property $ydb->installed that is created by yourls_get_all_options()
 *
 * See inline comment for updating from 1.3 or prior.
 *
 */
function yourls_is_installed() {
	global $ydb;
	$is_installed = ( property_exists( $ydb, 'installed' ) && $ydb->installed == true );
	return yourls_apply_filter( 'is_installed', $is_installed );
	
	/* Note: this test won't work on YOURLS 1.3 or older (Aug 2009...)
	   Should someone complain that they cannot upgrade directly from
	   1.3 to 1.7: first, laugh at them, then ask them to install 1.6 first.
	*/
}

/**
 * Generate random string of (int)$length length and type $type (see function for details)
 *
 */
function yourls_rnd_string ( $length = 5, $type = 0, $charlist = '' ) {
	$str = '';
	$length = intval( $length );

	// define possible characters
	switch ( $type ) {

		// custom char list, or comply to charset as defined in config
		case '0':
			$possible = $charlist ? $charlist : yourls_get_shorturl_charset() ;
			break;
	
		// no vowels to make no offending word, no 0/1/o/l to avoid confusion between letters & digits. Perfect for passwords.
		case '1':
			$possible = "23456789bcdfghjkmnpqrstvwxyz";
			break;
		
		// Same, with lower + upper
		case '2':
			$possible = "23456789bcdfghjkmnpqrstvwxyzBCDFGHJKMNPQRSTVWXYZ";
			break;
		
		// all letters, lowercase
		case '3':
			$possible = "abcdefghijklmnopqrstuvwxyz";
			break;
		
		// all letters, lowercase + uppercase
		case '4':
			$possible = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
			break;
		
		// all digits & letters lowercase 
		case '5':
			$possible = "0123456789abcdefghijklmnopqrstuvwxyz";
			break;
		
		// all digits & letters lowercase + uppercase
		case '6':
			$possible = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ";
			break;
		
	}

	$i = 0;
	while ($i < $length) {
		$str .= substr( $possible, mt_rand( 0, strlen( $possible )-1 ), 1 );
		$i++;
	}
	
	return yourls_apply_filter( 'rnd_string', $str, $length, $type, $charlist );
}

/**
 * Return salted string
 *
 */
function yourls_salt( $string ) {
	$salt = defined('YOURLS_COOKIEKEY') ? YOURLS_COOKIEKEY : md5(__FILE__) ;
	return yourls_apply_filter( 'yourls_salt', md5 ($string . $salt), $string );
}

/**
 * Add a query var to a URL and return URL. Completely stolen from WP.
 * 
 * Works with one of these parameter patterns:
 *     array( 'var' => 'value' )
 *     array( 'var' => 'value' ), $url
 *     'var', 'value'
 *     'var', 'value', $url 
 * If $url omitted, uses $_SERVER['REQUEST_URI']
 *
 */
function yourls_add_query_arg() {
	$ret = '';
	if ( is_array( func_get_arg(0) ) ) {
		if ( @func_num_args() < 2 || false === @func_get_arg( 1 ) )
			$uri = $_SERVER['REQUEST_URI'];
		else
			$uri = @func_get_arg( 1 );
	} else {
		if ( @func_num_args() < 3 || false === @func_get_arg( 2 ) )
			$uri = $_SERVER['REQUEST_URI'];
		else
			$uri = @func_get_arg( 2 );
	}
	
	$uri = str_replace( '&amp;', '&', $uri );

	
	if ( $frag = strstr( $uri, '#' ) )
		$uri = substr( $uri, 0, -strlen( $frag ) );
	else
		$frag = '';

	if ( preg_match( '|^https?://|i', $uri, $matches ) ) {
		$protocol = $matches[0];
		$uri = substr( $uri, strlen( $protocol ) );
	} else {
		$protocol = '';
	}

	if ( strpos( $uri, '?' ) !== false ) {
		$parts = explode( '?', $uri, 2 );
		if ( 1 == count( $parts ) ) {
			$base = '?';
			$query = $parts[0];
		} else {
			$base = $parts[0] . '?';
			$query = $parts[1];
		}
	} elseif ( !empty( $protocol ) || strpos( $uri, '=' ) === false ) {
		$base = $uri . '?';
		$query = '';
	} else {
		$base = '';
		$query = $uri;
	}

	parse_str( $query, $qs );
	$qs = yourls_urlencode_deep( $qs ); // this re-URL-encodes things that were already in the query string
	if ( is_array( func_get_arg( 0 ) ) ) {
		$kayvees = func_get_arg( 0 );
		$qs = array_merge( $qs, $kayvees );
	} else {
		$qs[func_get_arg( 0 )] = func_get_arg( 1 );
	}

	foreach ( (array) $qs as $k => $v ) {
		if ( $v === false )
			unset( $qs[$k] );
	}

	$ret = http_build_query( $qs );
	$ret = trim( $ret, '?' );
	$ret = preg_replace( '#=(&|$)#', '$1', $ret );
	$ret = $protocol . $base . $ret . $frag;
	$ret = rtrim( $ret, '?' );
	return $ret;
}

/**
 * Navigates through an array and encodes the values to be used in a URL. Stolen from WP, used in yourls_add_query_arg()
 *
 */
function yourls_urlencode_deep( $value ) {
	$value = is_array( $value ) ? array_map( 'yourls_urlencode_deep', $value ) : urlencode( $value );
	return $value;
}

/**
 * Remove arg from query. Opposite of yourls_add_query_arg. Stolen from WP.
 *
 */
function yourls_remove_query_arg( $key, $query = false ) {
	if ( is_array( $key ) ) { // removing multiple keys
		foreach ( $key as $k )
			$query = yourls_add_query_arg( $k, false, $query );
		return $query;
	}
	return yourls_add_query_arg( $key, false, $query );
}

/**
 * Return a time-dependent string for nonce creation
 *
 */
function yourls_tick() {
	return ceil( time() / YOURLS_NONCE_LIFE );
}

/**
 * Create a time limited, action limited and user limited token
 *
 */
function yourls_create_nonce( $action, $user = false ) {
	if( false == $user )
		$user = defined( 'YOURLS_USER' ) ? YOURLS_USER : '-1';
	$tick = yourls_tick();
	return substr( yourls_salt($tick . $action . $user), 0, 10 );
}

/**
 * Create a nonce field for inclusion into a form
 *
 */
function yourls_nonce_field( $action, $name = 'nonce', $user = false, $echo = true ) {
	$field = '<input type="hidden" id="'.$name.'" name="'.$name.'" value="'.yourls_create_nonce( $action, $user ).'" />';
	if( $echo )
		echo $field."\n";
	return $field;
}

/**
 * Add a nonce to a URL. If URL omitted, adds nonce to current URL
 *
 */
function yourls_nonce_url( $action, $url = false, $name = 'nonce', $user = false ) {
	$nonce = yourls_create_nonce( $action, $user );
	return yourls_add_query_arg( $name, $nonce, $url );
}

/**
 * Check validity of a nonce (ie time span, user and action match).
 * 
 * Returns true if valid, dies otherwise (yourls_die() or die($return) if defined)
 * if $nonce is false or unspecified, it will use $_REQUEST['nonce']
 *
 */
function yourls_verify_nonce( $action, $nonce = false, $user = false, $return = '' ) {
	// get user
	if( false == $user )
		$user = defined( 'YOURLS_USER' ) ? YOURLS_USER : '-1';
		
	// get current nonce value
	if( false == $nonce && isset( $_REQUEST['nonce'] ) )
		$nonce = $_REQUEST['nonce'];

	// what nonce should be
	$valid = yourls_create_nonce( $action, $user );
	
	if( $nonce == $valid ) {
		return true;
	} else {
		if( $return )
			die( $return );
		yourls_die( yourls__( 'Unauthorized action or expired link' ), yourls__( 'Error' ), 403 );
	}
}

/**
 * Converts keyword into short link (prepend with YOURLS base URL)
 *
 */
function yourls_link( $keyword = '' ) {
	$link = YOURLS_SITE . '/' . yourls_sanitize_keyword( $keyword );
	return yourls_apply_filter( 'yourls_link', $link, $keyword );
}

/**
 * Converts keyword into stat link (prepend with YOURLS base URL, append +)
 *
 */
function yourls_statlink( $keyword = '' ) {
	$link = YOURLS_SITE . '/' . yourls_sanitize_keyword( $keyword ) . '+';
	if( yourls_is_ssl() )
		$link = str_replace( 'http://', 'https://', $link );
	return yourls_apply_filter( 'yourls_statlink', $link, $keyword );
}

/**
 * Check if we're in API mode. Returns bool
 *
 */
function yourls_is_API() {
	if ( defined( 'YOURLS_API' ) && YOURLS_API == true )
		return true;
	return false;
}

/**
 * Check if we're in Ajax mode. Returns bool
 *
 */
function yourls_is_Ajax() {
	if ( defined( 'YOURLS_AJAX' ) && YOURLS_AJAX == true )
		return true;
	return false;
}

/**
 * Check if we're in GO mode (yourls-go.php). Returns bool
 *
 */
function yourls_is_GO() {
	if ( defined( 'YOURLS_GO' ) && YOURLS_GO == true )
		return true;
	return false;
}

/**
 * Check if we're displaying stats infos (yourls-infos.php). Returns bool
 *
 */
function yourls_is_infos() {
	if ( defined( 'YOURLS_INFOS' ) && YOURLS_INFOS == true )
		return true;
	return false;
}

/**
 * Check if we'll need interface display function (ie not API or redirection)
 *
 */
function yourls_has_interface() {
	if( yourls_is_API() or yourls_is_GO() )
		return false;
	return true;
}

/**
 * Check if we're in the admin area. Returns bool
 *
 */
function yourls_is_admin() {
	if ( defined( 'YOURLS_ADMIN' ) && YOURLS_ADMIN == true )
		return true;
	return false;
}

/**
 * Check if the server seems to be running on Windows. Not exactly sure how reliable this is.
 *
 */
function yourls_is_windows() {
	return defined( 'DIRECTORY_SEPARATOR' ) && DIRECTORY_SEPARATOR == '\\';
}

/**
 * Check if SSL is required. Returns bool.
 *
 */
function yourls_needs_ssl() {
	if ( defined('YOURLS_ADMIN_SSL') && YOURLS_ADMIN_SSL == true )
		return true;
	return false;
}

/**
 * Return admin link, with SSL preference if applicable.
 *
 */
function yourls_admin_url( $page = '' ) {
	$admin = YOURLS_SITE . '/admin/' . $page;
	if( yourls_is_ssl() or yourls_needs_ssl() )
		$admin = str_replace('http://', 'https://', $admin);
	return yourls_apply_filter( 'admin_url', $admin, $page );
}

/**
 * Return YOURLS_SITE or URL under YOURLS setup, with SSL preference
 *
 */
function yourls_site_url( $echo = true, $url = '' ) {
	$url = yourls_get_relative_url( $url );
	$url = trim( YOURLS_SITE . '/' . $url, '/' );
	
	// Do not enforce (checking yourls_need_ssl() ) but check current usage so it won't force SSL on non-admin pages
	if( yourls_is_ssl() )
		$url = str_replace( 'http://', 'https://', $url );
	$url = yourls_apply_filter( 'site_url', $url );
	if( $echo )
		echo $url;
	return $url;
}

/**
 * Check if SSL is used, returns bool. Stolen from WP.
 *
 */
function yourls_is_ssl() {
	$is_ssl = false;
	if ( isset( $_SERVER['HTTPS'] ) ) {
		if ( 'on' == strtolower( $_SERVER['HTTPS'] ) )
			$is_ssl = true;
		if ( '1' == $_SERVER['HTTPS'] )
			$is_ssl = true;
	} elseif ( isset( $_SERVER['SERVER_PORT'] ) && ( '443' == $_SERVER['SERVER_PORT'] ) ) {
		$is_ssl = true;
	}
	return yourls_apply_filter( 'is_ssl', $is_ssl );
}


/**
 * Get a remote page <title>, return a string (either title or url)
 *
 */
function yourls_get_remote_title( $url ) {
	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_get_remote_title', false, $url );
	if ( false !== $pre )
		return $pre;

	require_once( YOURLS_INC.'/functions-http.php' );

	$url = yourls_sanitize_url( $url );

	$title = $charset = false;
	
	$content = yourls_get_remote_content( $url );
	
	// If false, return url as title.
	// Todo: improve this with temporary title when shorturl_meta available?
	if( false === $content )
		return $url;

	if( $content !== false ) {
		// look for <title>
		if ( preg_match('/<title>(.*?)<\/title>/is', $content, $found ) ) {
			$title = $found[1];
			unset( $found );
		}

		// look for charset
		// <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
		if ( preg_match('/<meta[^>]*?charset=([^>]*?)\/?>/is', $content, $found ) ) {
			$charset = trim($found[1], '"\' ');
			unset( $found );
		}
	}
	
	// if title not found, guess if returned content was actually an error message
	if( $title == false && strpos( $content, 'Error' ) === 0 ) {
		$title = $content;
	}
	
	if( $title == false )
		$title = $url;
	
	/*
	if( !yourls_seems_utf8( $title ) )
		$title = utf8_encode( $title );
	*/
	
	// Charset conversion. We use @ to remove warnings (mb_ functions are easily bitching about illegal chars)
	if( function_exists( 'mb_convert_encoding' ) ) {
		if( $charset ) {
			$title = @mb_convert_encoding( $title, 'UTF-8', $charset );
		} else {
			$title = @mb_convert_encoding( $title, 'UTF-8' );
		}
	}
	
	// Remove HTML entities
	$title = html_entity_decode( $title, ENT_QUOTES, 'UTF-8' );
	
	// Strip out evil things
	$title = yourls_sanitize_title( $title );
	
	return yourls_apply_filter( 'get_remote_title', $title, $url );
}

/**
 * Quick UA check for mobile devices. Return boolean.
 *
 */
function yourls_is_mobile_device() {
	// Strings searched
	$mobiles = array(
		'android', 'blackberry', 'blazer',
		'compal', 'elaine', 'fennec', 'hiptop',
		'iemobile', 'iphone', 'ipod', 'ipad',
		'iris', 'kindle', 'opera mobi', 'opera mini',
		'palm', 'phone', 'pocket', 'psp', 'symbian',
		'treo', 'wap', 'windows ce', 'windows phone'
	);
	
	// Current user-agent
	$current = strtolower( $_SERVER['HTTP_USER_AGENT'] );
	
	// Check and return
	$is_mobile = ( str_replace( $mobiles, '', $current ) != $current );
	return yourls_apply_filter( 'is_mobile_device', $is_mobile );
}

/**
 * Get request in YOURLS base (eg in 'http://site.com/yourls/abcd' get 'abdc')
 *
 */
function yourls_get_request() {
	// Allow plugins to short-circuit the whole function
	$pre = yourls_apply_filter( 'shunt_get_request', false );
	if ( false !== $pre )
		return $pre;
		
	static $request = null;

	yourls_do_action( 'pre_get_request', $request );
	
	if( $request !== null )
		return $request;
	
	// Ignore protocol & www. prefix
	$root = str_replace( array( 'https://', 'http://', 'https://www.', 'http://www.' ), '', YOURLS_SITE );
	// Case insensitive comparison of the YOURLS root to match both http://Sho.rt/blah and http://sho.rt/blah
	$request = preg_replace( "!$root/!i", '', $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], 1 );

	// Unless request looks like a full URL (ie request is a simple keyword) strip query string
	if( !preg_match( "@^[a-zA-Z]+://.+@", $request ) ) {
		$request = current( explode( '?', $request ) );
	}
	
	return yourls_apply_filter( 'get_request', $request );
}

/**
 * Change protocol to match current scheme used (http or https)
 *
 */
function yourls_match_current_protocol( $url, $normal = 'http', $ssl = 'https' ) {
	if( yourls_is_ssl() )
		$url = str_replace( $normal, $ssl, $url );
	return yourls_apply_filter( 'match_current_protocol', $url );
}

/**
 * Fix $_SERVER['REQUEST_URI'] variable for various setups. Stolen from WP.
 *
 */
function yourls_fix_request_uri() {

	$default_server_values = array(
		'SERVER_SOFTWARE' => '',
		'REQUEST_URI' => '',
	);
	$_SERVER = array_merge( $default_server_values, $_SERVER );

	// Fix for IIS when running with PHP ISAPI
	if ( empty( $_SERVER['REQUEST_URI'] ) || ( php_sapi_name() != 'cgi-fcgi' && preg_match( '/^Microsoft-IIS\//', $_SERVER['SERVER_SOFTWARE'] ) ) ) {

		// IIS Mod-Rewrite
		if ( isset( $_SERVER['HTTP_X_ORIGINAL_URL'] ) ) {
			$_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_ORIGINAL_URL'];
		}
		// IIS Isapi_Rewrite
		else if ( isset( $_SERVER['HTTP_X_REWRITE_URL'] ) ) {
			$_SERVER['REQUEST_URI'] = $_SERVER['HTTP_X_REWRITE_URL'];
		} else {
			// Use ORIG_PATH_INFO if there is no PATH_INFO
			if ( !isset( $_SERVER['PATH_INFO'] ) && isset( $_SERVER['ORIG_PATH_INFO'] ) )
				$_SERVER['PATH_INFO'] = $_SERVER['ORIG_PATH_INFO'];

			// Some IIS + PHP configurations puts the script-name in the path-info (No need to append it twice)
			if ( isset( $_SERVER['PATH_INFO'] ) ) {
				if ( $_SERVER['PATH_INFO'] == $_SERVER['SCRIPT_NAME'] )
					$_SERVER['REQUEST_URI'] = $_SERVER['PATH_INFO'];
				else
					$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'] . $_SERVER['PATH_INFO'];
			}

			// Append the query string if it exists and isn't null
			if ( ! empty( $_SERVER['QUERY_STRING'] ) ) {
				$_SERVER['REQUEST_URI'] .= '?' . $_SERVER['QUERY_STRING'];
			}
		}
	}
}

/**
 * Shutdown function, runs just before PHP shuts down execution. Stolen from WP
 *
 */
function yourls_shutdown() {
	yourls_do_action( 'shutdown' );
}

/**
 * Auto detect custom favicon in /user directory, fallback to YOURLS favicon, and echo/return its URL
 *
 */
function yourls_favicon( $echo = true ) {
	static $favicon = null;
	if( $favicon !== null )
		return $favicon;
	
	$custom = null;
	// search for favicon.(gif|ico|png|jpg|svg)
	foreach( array( 'gif', 'ico', 'png', 'jpg', 'svg' ) as $ext ) {
		if( file_exists( YOURLS_USERDIR. '/favicon.' . $ext ) ) {
			$custom = 'favicon.' . $ext;
			break;
		}
	}
	
	if( $custom ) {
		$favicon = yourls_site_url( false, YOURLS_USERURL . '/' . $custom );
	} else {
		$favicon = yourls_site_url( false ) . '/images/favicon.gif';
	}
	if( $echo )
		echo $favicon;
	return $favicon;
}

/**
 * Check for maintenance mode. If yes, die. See yourls_maintenance_mode(). Stolen from WP.
 *
 */
function yourls_check_maintenance_mode() {

	$file = YOURLS_ABSPATH . '/.maintenance' ;
	if ( !file_exists( $file ) || yourls_is_upgrading() || yourls_is_installing() )
		return;
	
	global $maintenance_start;

	include_once( $file );
	// If the $maintenance_start timestamp is older than 10 minutes, don't die.
	if ( ( time() - $maintenance_start ) >= 600 )
		return;

	// Use any /user/maintenance.php file
	if( file_exists( YOURLS_USERDIR.'/maintenance.php' ) ) {
		include_once( YOURLS_USERDIR.'/maintenance.php' );
		die();
	}
	
	// https://www.youtube.com/watch?v=Xw-m4jEY-Ns
	$title   = yourls__( 'Service temporarily unavailable' );
	$message = yourls__( 'Our service is currently undergoing scheduled maintenance.' ) . "</p>\n<p>" .
	yourls__( 'Things should not last very long, thank you for your patience and please excuse the inconvenience' );
	yourls_die( $message, $title , 503 );

}

/**
 * Return current admin page, or null if not an admin page
 *
 * @return mixed string if admin page, null if not an admin page
 * @since 1.6
 */
function yourls_current_admin_page() {
	if( yourls_is_admin() ) {
		$current = substr( yourls_get_request(), 6 );
		if( $current === false ) 
			$current = 'index.php'; // if current page is http://sho.rt/admin/ instead of http://sho.rt/admin/index.php
			
		return $current;
	}
	return null;
}

/**
 * Check if a URL protocol is allowed
 *
 * Checks a URL against a list of whitelisted protocols. Protocols must be defined with
 * their complete scheme name, ie 'stuff:' or 'stuff://' (for instance, 'mailto:' is a valid
 * protocol, 'mailto://' isn't, and 'http:' with no double slashed isn't either
 *
 * @since 1.6
 *
 * @param string $url URL to be check
 * @param array $protocols Optional. Array of protocols, defaults to global $yourls_allowedprotocols
 * @return boolean true if protocol allowed, false otherwise
 */
function yourls_is_allowed_protocol( $url, $protocols = array() ) {
	if( ! $protocols ) {
		global $yourls_allowedprotocols;
		$protocols = $yourls_allowedprotocols;
	}
	
	$protocol = yourls_get_protocol( $url );
	return yourls_apply_filter( 'is_allowed_protocol', in_array( $protocol, $protocols ), $url, $protocols );
}

/**
 * Get protocol from a URL (eg mailto:, http:// ...)
 *
 * @since 1.6
 *
 * @param string $url URL to be check
 * @return string Protocol, with slash slash if applicable. Empty string if no protocol
 */
function yourls_get_protocol( $url ) {
	preg_match( '!^[a-zA-Z0-9\+\.-]+:(//)?!', $url, $matches );
	/*
	http://en.wikipedia.org/wiki/URI_scheme#Generic_syntax
	The scheme name consists of a sequence of characters beginning with a letter and followed by any
	combination of letters, digits, plus ("+"), period ("."), or hyphen ("-"). Although schemes are
	case-insensitive, the canonical form is lowercase and documents that specify schemes must do so
	with lowercase letters. It is followed by a colon (":").
	*/
	$protocol = ( isset( $matches[0] ) ? $matches[0] : '' );
	return yourls_apply_filter( 'get_protocol', $protocol, $url );
}

/**
 * Get relative URL (eg 'abc' from 'http://sho.rt/abc')
 *
 * Treat indifferently http & https. If a URL isn't relative to the YOURLS install, return it as is
 * or return empty string if $strict is true
 *
 * @since 1.6
 * @param string $url URL to relativize
 * @param bool $strict if true and if URL isn't relative to YOURLS install, return empty string
 * @return string URL 
 */
function yourls_get_relative_url( $url, $strict = true ) {
	$url = yourls_sanitize_url( $url );
	
	// Remove protocols to make it easier
	$noproto_url  = str_replace( 'https:', 'http:', $url );
	$noproto_site = str_replace( 'https:', 'http:', YOURLS_SITE );
	
	// Trim URL from YOURLS root URL : if no modification made, URL wasn't relative
	$_url = str_replace( $noproto_site . '/', '', $noproto_url );
	if( $_url == $noproto_url )
		$_url = ( $strict ? '' : $url );

	return yourls_apply_filter( 'get_relative_url', $_url, $url );
}

/**
 * Marks a function as deprecated and informs when it has been used. Stolen from WP.
 *
 * There is a hook deprecated_function that will be called that can be used
 * to get the backtrace up to what file and function called the deprecated
 * function.
 *
 * The current behavior is to trigger a user error if YOURLS_DEBUG is true.
 *
 * This function is to be used in every function that is deprecated.
 *
 * @since 1.6
 * @uses yourls_do_action() Calls 'deprecated_function' and passes the function name, what to use instead,
 *   and the version the function was deprecated in.
 * @uses yourls_apply_filters() Calls 'deprecated_function_trigger_error' and expects boolean value of true to do
 *   trigger or false to not trigger error.
 *
 * @param string $function The function that was called
 * @param string $version The version of WordPress that deprecated the function
 * @param string $replacement Optional. The function that should have been called
 */
function yourls_deprecated_function( $function, $version, $replacement = null ) {

	yourls_do_action( 'deprecated_function', $function, $replacement, $version );

	// Allow plugin to filter the output error trigger
	if ( YOURLS_DEBUG && yourls_apply_filters( 'deprecated_function_trigger_error', true ) ) {
		if ( ! is_null( $replacement ) )
			trigger_error( sprintf( yourls__('%1$s is <strong>deprecated</strong> since version %2$s! Use %3$s instead.'), $function, $version, $replacement ) );
		else
			trigger_error( sprintf( yourls__('%1$s is <strong>deprecated</strong> since version %2$s with no alternative available.'), $function, $version ) );
	}
}

/**
 * Return the value if not an empty string
 *
 * Used with array_filter(), to remove empty keys but not keys with value 0 or false
 *
 * @since 1.6
 * @param mixed $val Value to test against ''
 * @return bool True if not an empty string
 */
function yourls_return_if_not_empty_string( $val ) {
	return( $val !== '' );
}
