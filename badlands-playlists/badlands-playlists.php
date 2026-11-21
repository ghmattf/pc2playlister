<?php
/**
 * Plugin Name:       Badlands Playlists
 * Description:       Builds unofficial, fan-made per-show Podcasting 2.0 playlist feeds (podcast:medium "podcastL") from the Badlands Media feed, refreshes them on a schedule, and pings podping.cloud when a playlist changes.
 * Version:           1.1.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 *
 * HOW IT WORKS
 *   Daily (WP-Cron) the plugin downloads the Badlands Media RSS feed, works out which show each
 *   episode belongs to from the title prefix, and writes one static XML file per show to
 *   wp-content/uploads/playlists/badlands/<show>.xml. Each file contains only podcast:remoteItem
 *   references (original feed GUID + episode GUID). A file is only rewritten, and only pinged,
 *   when its content actually changed.
 *
 * OPTIONAL SETTINGS (put in wp-config.php, above the "That's all, stop editing!" line)
 *   define( 'BADLANDS_PLAYLISTS_PODPING_TOKEN', 'your-podping.cloud-token' ); // keep secrets out of the database
 *   define( 'BADLANDS_PLAYLISTS_CONTACT',  'you@example.com' );  // shown in each feed description (omitted if unset)
 *   define( 'BADLANDS_PLAYLISTS_SCHEDULE', 'daily' );            // 'hourly' | 'twicedaily' | 'daily'
 *   define( 'BADLANDS_PLAYLISTS_MIN_EPISODES', 3 );              // a show needs this many episodes before it gets a playlist
 *   define( 'BADLANDS_PLAYLISTS_EXCLUDE', array( 'badlands-media-special-coverage' ) ); // show slugs to skip
 *   define( 'BADLANDS_PLAYLISTS_BASE_URL', 'https://phafe.com/wp-content/uploads/playlists/badlands/' );
 *   define( 'BADLANDS_PLAYLISTS_LINK', 'https://phafe.com/' );
 *   define( 'BADLANDS_PLAYLISTS_PODPING_MEDIUM', '' );           // leave empty (podping.cloud default)
 *
 * MAPPING TITLE VARIANTS TO ONE SHOW (in a small mu-plugin or your theme):
 *   add_filter( 'badlands_playlists_aliases', function () {
 *       return array( 'Daily Herold' => 'The Daily Herold' );   // variant name => canonical name
 *   } );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Badlands_Playlists {

	const VERSION     = '1.1.0';
	const HOOK        = 'badlands_playlists_run';
	const SOURCE_URL  = 'https://feed.podbean.com/badlandsmedia/feed.xml';
	const SOURCE_NAME = 'Badlands Media';
	const SOURCE_SITE = 'https://badlandsmedia.tv';
	const PODCAST_NS  = 'https://podcastindex.org/namespace/1.0';
	const ITUNES_NS   = 'http://www.itunes.com/dtds/podcast-1.0.dtd';
	const GUID_NS     = 'ead4c236-bf58-58c6-a2c6-a6b28d128cb6'; // podcast namespace UUID for podcast:guid
	const PODPING_URL = 'https://podping.cloud/';
	const OPT_STATE   = 'badlands_playlists_state';
	const OPT_STATUS  = 'badlands_playlists_status';
	const OPT_LOCK    = 'badlands_playlists_lock';
	const MAX_PING_ATTEMPTS = 5;

	/* ------------------------------------------------------------------ */
	/* Configuration                                                       */
	/* ------------------------------------------------------------------ */

	private static function cfg( $name, $default ) {
		return defined( $name ) ? constant( $name ) : $default;
	}

	private static function base_url() {
		return trailingslashit( (string) self::cfg( 'BADLANDS_PLAYLISTS_BASE_URL', 'https://phafe.com/wp-content/uploads/playlists/badlands/' ) );
	}

	private static function feed_url( $slug ) {
		return self::base_url() . $slug . '.xml';
	}

	private static function token() {
		return trim( (string) self::cfg( 'BADLANDS_PLAYLISTS_PODPING_TOKEN', '' ) );
	}

	private static function user_agent() {
		return 'BadlandsPlaylists/' . self::VERSION . ' (+' . (string) self::cfg( 'BADLANDS_PLAYLISTS_LINK', 'https://phafe.com/' ) . ')';
	}

	private static function aliases() {
		$raw = apply_filters( 'badlands_playlists_aliases', array() );
		$map = array();
		if ( is_array( $raw ) ) {
			foreach ( $raw as $variant => $canonical ) {
				$vs = self::slugify( (string) $variant );
				$cs = self::slugify( (string) $canonical );
				if ( '' !== $vs && '' !== $cs ) {
					$map[ $vs ] = array( 'slug' => $cs, 'name' => trim( (string) $canonical ) );
				}
			}
		}
		return $map;
	}

	private static function excluded() {
		$list = self::cfg( 'BADLANDS_PLAYLISTS_EXCLUDE', array() );
		$out  = array();
		foreach ( (array) $list as $s ) {
			$s = self::slugify( (string) $s );
			if ( '' !== $s ) {
				$out[ $s ] = true;
			}
		}
		return $out;
	}

	private static function config_signature() {
		return md5(
			wp_json_encode(
				array(
					self::VERSION,
					self::base_url(),
					(string) self::cfg( 'BADLANDS_PLAYLISTS_LINK', 'https://phafe.com/' ),
					(string) self::cfg( 'BADLANDS_PLAYLISTS_CONTACT', '' ),
					'' !== self::token(),
					(int) self::cfg( 'BADLANDS_PLAYLISTS_MIN_EPISODES', 3 ),
					array_keys( self::excluded() ),
					self::aliases(),
				)
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Scheduling                                                          */
	/* ------------------------------------------------------------------ */

	private static function schedule_name() {
		$want      = (string) self::cfg( 'BADLANDS_PLAYLISTS_SCHEDULE', 'daily' );
		$schedules = wp_get_schedules();
		return isset( $schedules[ $want ] ) ? $want : 'daily';
	}

	/** Runs on every request (cheap): re-creates the cron event if it is ever lost. */
	public static function ensure_schedule() {
		$want  = self::schedule_name();
		$event = wp_get_scheduled_event( self::HOOK );
		if ( $event && $event->schedule === $want ) {
			return;
		}
		if ( $event ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
		wp_schedule_event( time() + 60, $want, self::HOOK );
	}

	public static function activate() {
		self::ensure_schedule();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/* ------------------------------------------------------------------ */
	/* Main job                                                            */
	/* ------------------------------------------------------------------ */

	public static function run() {
		if ( ! self::lock() ) {
			return array( 'ok' => false, 'message' => 'Another run is already in progress.', 'notes' => array() );
		}
		try {
			$result = self::do_run();
		} catch ( Throwable $e ) {
			$result = array( 'ok' => false, 'message' => 'Unexpected error: ' . $e->getMessage(), 'notes' => array() );
		}
		self::unlock();
		$result['time'] = time();
		update_option( self::OPT_STATUS, $result, false );
		return $result;
	}

	private static function lock() {
		$now = time();
		if ( add_option( self::OPT_LOCK, $now, '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( self::OPT_LOCK );
		if ( $held && ( $now - $held ) > 900 ) { // stale lock from a crashed run
			update_option( self::OPT_LOCK, $now, false );
			return true;
		}
		return false;
	}

	private static function unlock() {
		delete_option( self::OPT_LOCK );
	}

	private static function default_state() {
		return array(
			'etag'          => '',
			'last_modified' => '',
			'body_hash'     => '',
			'config_sig'    => '',
			'item_count'    => 0,
			'hashes'        => array(),
			'feeds'         => array(),
			'pending'       => array(),
		);
	}

	private static function do_run() {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$notes = array();
		$state = array_merge( self::default_state(), (array) get_option( self::OPT_STATE, array() ) );
		$sig   = self::config_signature();

		$dir = self::output_dir();
		if ( is_array( $dir ) ) {
			return array( 'ok' => false, 'message' => $dir['error'], 'notes' => $notes );
		}

		// 1. Fetch the source feed (conditional GET when nothing in our own config changed).
		$args = array(
			'timeout'            => 90,
			'redirection'        => 3,
			'user-agent'         => self::user_agent(),
			'limit_response_size' => 30 * 1024 * 1024,
			'headers'            => array( 'Accept' => 'application/rss+xml, application/xml;q=0.9, */*;q=0.8' ),
		);
		if ( $state['config_sig'] === $sig && self::files_exist( $state, $dir ) ) {
			if ( '' !== $state['etag'] ) {
				$args['headers']['If-None-Match'] = $state['etag'];
			}
			if ( '' !== $state['last_modified'] ) {
				$args['headers']['If-Modified-Since'] = $state['last_modified'];
			}
		}
		$res = wp_remote_get( self::SOURCE_URL, $args );
		if ( is_wp_error( $res ) ) {
			self::finish_pings( $state, $notes );
			return array( 'ok' => false, 'message' => 'Could not fetch the Badlands feed: ' . $res->get_error_message(), 'notes' => $notes );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 304 === $code ) {
			self::finish_pings( $state, $notes );
			return array( 'ok' => true, 'message' => 'Source feed not modified (304). Nothing to do.', 'notes' => $notes, 'changed' => array() );
		}
		if ( 200 !== $code ) {
			self::finish_pings( $state, $notes );
			return array( 'ok' => false, 'message' => 'Badlands feed returned HTTP ' . $code . '.', 'notes' => $notes );
		}

		$body = (string) wp_remote_retrieve_body( $res );
		$hash = hash( 'sha256', $body );
		if ( $hash === $state['body_hash'] && $sig === $state['config_sig'] && self::files_exist( $state, $dir ) ) {
			self::finish_pings( $state, $notes );
			return array( 'ok' => true, 'message' => 'Source feed content unchanged. Nothing to do.', 'notes' => $notes, 'changed' => array() );
		}

		// 2. Parse it.
		$parsed = self::parse_feed( $body );
		if ( isset( $parsed['error'] ) ) {
			self::finish_pings( $state, $notes );
			return array( 'ok' => false, 'message' => 'Could not read the Badlands feed: ' . $parsed['error'], 'notes' => $notes );
		}
		$items = $parsed['items'];
		$n     = count( $items );
		$prev  = (int) $state['item_count'];
		if ( $n < 10 || ( $prev > 0 && $n < (int) floor( $prev * 0.5 ) ) ) {
			self::finish_pings( $state, $notes );
			return array(
				'ok'      => false,
				'message' => "Source feed looks incomplete ($n episodes, previously $prev). Existing playlists left untouched.",
				'notes'   => $notes,
			);
		}
		$feed_guid = '' !== $parsed['feed_guid'] ? $parsed['feed_guid'] : self::uuid5( self::GUID_NS, self::guid_name( self::SOURCE_URL ) );

		// 3. Group by show.
		$groups   = self::group_by_show( $items, $unsorted );
		$min      = max( 1, (int) self::cfg( 'BADLANDS_PLAYLISTS_MIN_EPISODES', 3 ) );
		$excluded = self::excluded();
		$use_ping = '' !== self::token();

		// 4. Build & write.
		$changed = array();
		$feeds   = array();
		foreach ( $groups as $slug => $g ) {
			if ( isset( $excluded[ $slug ] ) || count( $g['items'] ) < $min ) {
				continue;
			}
			$name     = self::display_name( $g );
			$guids    = array_keys( $g['items'] );
			$image    = self::latest_image( $g['items'] );
			$url      = self::feed_url( $slug );
			$xml      = self::build_playlist( $name, $url, $feed_guid, $guids, $use_ping, $image );
			$core_md5 = md5( $xml );
			$path     = $dir . $slug . '.xml';

			$feeds[ $slug ] = array( 'name' => $name, 'count' => count( $guids ), 'url' => $url );

			if ( isset( $state['hashes'][ $slug ] ) && $state['hashes'][ $slug ] === $core_md5 && file_exists( $path ) ) {
				continue; // unchanged
			}
			$final = str_replace( '%%LASTBUILD%%', gmdate( 'D, d M Y H:i:s +0000' ), $xml );
			if ( ! self::write_atomic( $path, $final ) ) {
				$notes[] = "Could not write $slug.xml (check permissions on the uploads folder).";
				continue;
			}
			$state['hashes'][ $slug ] = $core_md5;
			$changed[]                 = $slug;
			if ( ! isset( $state['pending'][ $slug ] ) ) {
				$state['pending'][ $slug ] = 0;
			}
		}

		$state['etag']          = (string) wp_remote_retrieve_header( $res, 'etag' );
		$state['last_modified'] = (string) wp_remote_retrieve_header( $res, 'last-modified' );
		$state['body_hash']     = $hash;
		$state['config_sig']    = $sig;
		$state['item_count']    = $n;
		$state['feeds']         = $feeds;

		if ( trailingslashit( self::upload_baseurl() . 'playlists/badlands' ) !== self::base_url() ) {
			$notes[] = 'Note: the public URL used inside the feeds (' . self::base_url() . ') differs from this site\'s uploads URL. Feed GUIDs are derived from that URL, so keep it stable.';
		}

		// 5. Ping whatever changed.
		self::finish_pings( $state, $notes );

		$unsorted_total = count( $unsorted );
		return array(
			'ok'             => true,
			'message'        => sprintf( 'Read %d episodes, %d show playlists, %d updated.', $n, count( $feeds ), count( $changed ) ),
			'notes'          => $notes,
			'changed'        => $changed,
			'feeds'          => $feeds,
			'unsorted_total' => $unsorted_total,
			'unsorted'       => array_slice( $unsorted, 0, 20 ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Parsing & grouping                                                  */
	/* ------------------------------------------------------------------ */

	/** Streams the RSS with XMLReader so memory stays small even for a multi-megabyte feed. */
	public static function parse_feed( $xml ) {
		$feed_guid = '';
		$items     = array();

		$prev_errors = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$r = new XMLReader();
		if ( ! $r->XML( $xml, null, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE ) ) {
			libxml_use_internal_errors( $prev_errors );
			return array( 'error' => 'the XML could not be opened' );
		}

		$ok = $r->read();
		while ( $ok ) {
			if ( XMLReader::ELEMENT === $r->nodeType ) {
				if ( 'item' === $r->localName && '' === (string) $r->namespaceURI ) {
					$node = @$r->expand( new DOMDocument() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a truncated feed is reported below via libxml errors
					if ( $node ) {
						$it = self::read_item( $node );
						if ( $it ) {
							$items[] = $it;
						}
					}
					$ok = $r->next();
					continue;
				}
				if ( '' === $feed_guid && 2 === $r->depth && 'guid' === $r->localName && self::PODCAST_NS === (string) $r->namespaceURI ) {
					$g = strtolower( trim( $r->readString() ) );
					if ( preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $g ) ) {
						$feed_guid = $g;
					}
				}
			}
			$ok = $r->read();
		}
		$r->close();

		$fatal = '';
		foreach ( libxml_get_errors() as $e ) {
			if ( LIBXML_ERR_FATAL === $e->level ) {
				$fatal = trim( $e->message );
				break;
			}
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev_errors );
		if ( '' !== $fatal ) {
			return array( 'error' => 'malformed or truncated XML (' . $fatal . ')' );
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return $b['ts'] <=> $a['ts'];
			}
		);
		return array( 'feed_guid' => $feed_guid, 'items' => $items );
	}

	private static function read_item( DOMNode $node ) {
		$guid  = '';
		$title = '';
		$date  = '';
		$img   = '';
		foreach ( $node->childNodes as $c ) {
			if ( XML_ELEMENT_NODE !== $c->nodeType ) {
				continue;
			}
			if ( self::ITUNES_NS === (string) $c->namespaceURI ) {
				if ( 'image' === $c->localName && '' === $img ) { // episode artwork: <itunes:image href="..."/>
					$h = trim( (string) $c->getAttribute( 'href' ) );
					if ( preg_match( '#^https?://#i', $h ) ) {
						$img = $h;
					}
				}
				continue;
			}
			if ( '' !== (string) $c->namespaceURI ) {
				continue; // ignore other namespaced tags
			}
			switch ( $c->localName ) {
				case 'guid':
					$guid = trim( $c->textContent );
					break;
				case 'title':
					$title = trim( $c->textContent );
					break;
				case 'pubDate':
					$date = trim( $c->textContent );
					break;
			}
		}
		if ( '' === $guid ) {
			return null;
		}
		$ts = '' !== $date ? strtotime( $date ) : 0;
		return array( 'guid' => $guid, 'title' => $title, 'ts' => $ts ? (int) $ts : 0, 'img' => $img );
	}

	/**
	 * "Show Name: 10/20/26 - headline", "Show Name Ep. 12: headline",
	 * "Show Name (S3E7): headline", "Show Name [1.23]: headline"  ->  "Show Name"
	 */
	public static function extract_show( $title ) {
		$t = trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', (string) $title ) );
		if ( ! preg_match( '/^(.*?)\s*(?::|\[|\(?S\d+E\d+\)?|\bEp\.?\s*\d+)/iu', $t, $m ) ) {
			return null;
		}
		$name = trim( $m[1], " \t-\xE2\x80\x93\xE2\x80\x94" ); // spaces, hyphen, en/em dash
		if ( '' === $name || mb_strlen( $name ) > 60 ) {
			return null;
		}
		return $name;
	}

	public static function slugify( $s ) {
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		}
		$s = strtolower( $s );
		$s = preg_replace( '/[^a-z0-9]+/', '-', $s );
		return trim( (string) $s, '-' );
	}

	private static function group_by_show( array $items, &$unsorted ) {
		$unsorted = array();
		$aliases  = self::aliases();
		$groups   = array();
		foreach ( $items as $it ) {
			$name = self::extract_show( $it['title'] );
			$slug = null === $name ? '' : self::slugify( $name );
			if ( '' === $slug ) {
				$unsorted[] = $it['title'];
				continue;
			}
			$canon = null;
			if ( isset( $aliases[ $slug ] ) ) {
				$canon = $aliases[ $slug ]['name'];
				$slug  = $aliases[ $slug ]['slug'];
			}
			if ( ! isset( $groups[ $slug ] ) ) {
				$groups[ $slug ] = array( 'canon' => null, 'variants' => array(), 'items' => array() );
			}
			if ( null !== $canon ) {
				$groups[ $slug ]['canon'] = $canon;
			}
			$groups[ $slug ]['variants'][ $name ] = ( $groups[ $slug ]['variants'][ $name ] ?? 0 ) + 1;
			// Keyed by guid: de-duplicates. Items arrive newest-first, so the newest copy wins.
			if ( ! isset( $groups[ $slug ]['items'][ $it['guid'] ] ) ) {
				$groups[ $slug ]['items'][ $it['guid'] ] = array( 'ts' => $it['ts'], 'img' => $it['img'] );
			}
		}
		ksort( $groups );
		return $groups;
	}

	/** Artwork URL of the show's most recent episode that has one ('' if none do). */
	private static function latest_image( array $items ) {
		$best_ts = null;
		$best    = '';
		foreach ( $items as $it ) {
			if ( '' !== $it['img'] && ( null === $best_ts || $it['ts'] > $best_ts ) ) {
				$best_ts = $it['ts'];
				$best    = $it['img'];
			}
		}
		return $best;
	}

	private static function display_name( array $g ) {
		if ( ! empty( $g['canon'] ) ) {
			return $g['canon'];
		}
		arsort( $g['variants'] ); // most frequent spelling wins
		return (string) key( $g['variants'] );
	}

	/* ------------------------------------------------------------------ */
	/* Playlist XML                                                        */
	/* ------------------------------------------------------------------ */

	private static function x( $s ) {
		$s = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', (string) $s );
		return htmlspecialchars( (string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	public static function playlist_title( $show ) {
		return 'Unofficial ' . $show . ' playlist';
	}

	public static function playlist_description( $show, $has_image = false ) {
		$pub = self::SOURCE_NAME;
		$d   = "An unofficial, fan-made playlist of episodes of \xE2\x80\x9C{$show}\xE2\x80\x9D, a show published by {$pub}. "
			. 'Visit the creators at ' . self::SOURCE_SITE . " \xE2\x80\x94 "
			. "this playlist is not affiliated with, endorsed by or operated by {$pub} or the creators of the show. "
			. 'This feed contains no audio, show notes or other content from the show: it is only a playlist of episodes. '
			. "It uses podcast:remoteItem references to point to the original content, and stores nothing other than the original feed's GUID, "
			. "each episode's GUID, and the show's name for identification. ";
		if ( $has_image ) {
			$d .= "The feed image is not a copy: the feed saves only a link to the artwork of the show's latest episode, "
				. 'which stays hosted by the publisher, and the link is updated whenever a newer episode is published. ';
		}
		$d .= 'Podcast apps look the episodes up and play them directly from the original publisher. '
			. 'All episodes, and all associated names, artwork and other content, remain the property of their respective owners.';
		$contact = trim( (string) self::cfg( 'BADLANDS_PLAYLISTS_CONTACT', '' ) );
		if ( '' !== $contact ) {
			$d .= ' To request changes or removal of this playlist, contact ' . $contact . '.';
		}
		return $d;
	}

	/** Returns the feed with a %%LASTBUILD%% placeholder so the content can be hashed independent of the date. */
	private static function build_playlist( $show, $url, $feed_guid, array $item_guids, $use_podping, $image = '' ) {
		$link = (string) self::cfg( 'BADLANDS_PLAYLISTS_LINK', 'https://phafe.com/' );
		$out  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$out .= '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom" xmlns:itunes="' . self::ITUNES_NS . '" xmlns:podcast="' . self::PODCAST_NS . '">' . "\n";
		$out .= "<channel>\n";
		$out .= '  <title>' . self::x( self::playlist_title( $show ) ) . "</title>\n";
		$out .= '  <link>' . self::x( $link ) . "</link>\n";
		$out .= '  <description>' . self::x( self::playlist_description( $show, '' !== $image ) ) . "</description>\n";
		$out .= "  <language>en</language>\n";
		if ( '' !== $image ) { // a link only: the artwork stays hosted by the publisher
			$out .= '  <itunes:image href="' . self::x( $image ) . "\"/>\n";
			$out .= '  <image><url>' . self::x( $image ) . '</url><title>' . self::x( self::playlist_title( $show ) ) . '</title><link>' . self::x( $link ) . "</link></image>\n";
		}
		$out .= "  <lastBuildDate>%%LASTBUILD%%</lastBuildDate>\n";
		$out .= '  <generator>Badlands Playlists ' . self::VERSION . "</generator>\n";
		$out .= '  <atom:link href="' . self::x( $url ) . '" rel="self" type="application/rss+xml"/>' . "\n";
		$out .= '  <podcast:guid>' . self::uuid5( self::GUID_NS, self::guid_name( $url ) ) . "</podcast:guid>\n";
		$out .= "  <podcast:medium>podcastL</podcast:medium>\n";
		if ( $use_podping ) {
			$out .= "  <podcast:podping usesPodping=\"true\"/>\n";
		}
		foreach ( $item_guids as $g ) {
			$out .= '  <podcast:remoteItem feedGuid="' . self::x( $feed_guid ) . '" itemGuid="' . self::x( $g ) . '"/>' . "\n";
		}
		$out .= "</channel>\n</rss>\n";
		return $out;
	}

	/** Feed URL without scheme and trailing slashes: the input the podcast namespace hashes for podcast:guid. */
	public static function guid_name( $url ) {
		return rtrim( preg_replace( '#^[a-z][a-z0-9+.\-]*://#i', '', (string) $url ), '/' );
	}

	public static function uuid5( $namespace, $name ) {
		$hash = sha1( hex2bin( str_replace( '-', '', $namespace ) ) . $name );
		return sprintf(
			'%s-%s-%04x-%04x-%s',
			substr( $hash, 0, 8 ),
			substr( $hash, 8, 4 ),
			( hexdec( substr( $hash, 12, 4 ) ) & 0x0fff ) | 0x5000,
			( hexdec( substr( $hash, 16, 4 ) ) & 0x3fff ) | 0x8000,
			substr( $hash, 20, 12 )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Files                                                               */
	/* ------------------------------------------------------------------ */

	private static function upload_baseurl() {
		$u = wp_upload_dir();
		return trailingslashit( (string) ( $u['baseurl'] ?? '' ) );
	}

	/** @return string|array Directory path with trailing slash, or array( 'error' => ... ). */
	private static function output_dir() {
		$u = wp_upload_dir();
		if ( ! empty( $u['error'] ) ) {
			return array( 'error' => 'Uploads folder problem: ' . $u['error'] );
		}
		$dir = trailingslashit( $u['basedir'] ) . 'playlists/badlands/';
		if ( ! wp_mkdir_p( $dir ) || ! is_writable( $dir ) ) {
			return array( 'error' => "Cannot create or write to $dir" );
		}
		if ( ! file_exists( $dir . 'index.html' ) ) {
			@file_put_contents( $dir . 'index.html', "<!-- Silence is golden. -->\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $dir;
	}

	private static function files_exist( array $state, $dir ) {
		if ( empty( $state['hashes'] ) || ! is_string( $dir ) ) {
			return false;
		}
		foreach ( array_keys( $state['hashes'] ) as $slug ) {
			if ( ! file_exists( $dir . $slug . '.xml' ) ) {
				return false;
			}
		}
		return true;
	}

	private static function write_atomic( $path, $data ) {
		$tmp = $path . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $data, LOCK_EX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		@chmod( $tmp, 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! @rename( $tmp, $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Podping                                                             */
	/* ------------------------------------------------------------------ */

	/** Sends pings for every playlist flagged as changed, retries failures on later runs, then saves state. */
	private static function finish_pings( array &$state, array &$notes ) {
		$pending = $state['pending'];
		if ( $pending ) {
			$token = self::token();
			if ( '' === $token ) {
				$notes[] = count( $pending ) . ' playlist(s) changed but no podping token is configured, so no pings were sent. Define BADLANDS_PLAYLISTS_PODPING_TOKEN in wp-config.php.';
			} else {
				foreach ( array_keys( $pending ) as $slug ) {
					$q = array( 'url' => self::feed_url( $slug ), 'reason' => 'update' );
					$m = trim( (string) self::cfg( 'BADLANDS_PLAYLISTS_PODPING_MEDIUM', '' ) );
					if ( '' !== $m ) {
						$q['medium'] = $m;
					}
					$res  = wp_remote_get(
						self::PODPING_URL . '?' . http_build_query( $q, '', '&', PHP_QUERY_RFC3986 ),
						array( 'timeout' => 20, 'user-agent' => self::user_agent(), 'headers' => array( 'Authorization' => $token ) )
					);
					$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
					if ( 200 === $code ) {
						unset( $state['pending'][ $slug ] );
					} else {
						$tries = (int) $state['pending'][ $slug ] + 1;
						if ( $tries >= self::MAX_PING_ATTEMPTS ) {
							unset( $state['pending'][ $slug ] );
							$notes[] = "Gave up pinging $slug after $tries attempts (last result: " . ( $code ? "HTTP $code" : 'no response' ) . ').';
						} else {
							$state['pending'][ $slug ] = $tries;
							$notes[]                   = "Podping for $slug failed (" . ( $code ? "HTTP $code" : 'no response' ) . "); will retry next run.";
						}
					}
					usleep( 300000 ); // be gentle with the service
				}
			}
		}
		update_option( self::OPT_STATE, $state, false );
	}

	/* ------------------------------------------------------------------ */
	/* Tools -> Badlands Playlists (status page, optional "Run now")       */
	/* ------------------------------------------------------------------ */

	public static function admin_menu() {
		add_management_page( 'Badlands Playlists', 'Badlands Playlists', 'manage_options', 'badlands-playlists', array( __CLASS__, 'admin_page' ) );
	}

	public static function admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_POST['bp_run_now'] ) && check_admin_referer( 'badlands_playlists_run_now' ) ) {
			self::run();
		}
		$st    = (array) get_option( self::OPT_STATUS, array() );
		$state = array_merge( self::default_state(), (array) get_option( self::OPT_STATE, array() ) );
		$next  = wp_next_scheduled( self::HOOK );

		echo '<div class="wrap"><h1>Badlands Playlists</h1>';
		echo '<p>Next scheduled run: ' . ( $next ? esc_html( wp_date( 'Y-m-d H:i:s', $next ) ) : '<strong>not scheduled</strong>' ) . '. Podping token: ' . ( '' !== self::token() ? 'configured' : '<strong>not configured</strong>' ) . '.</p>';
		echo '<form method="post">';
		wp_nonce_field( 'badlands_playlists_run_now' );
		echo '<p><button class="button button-primary" name="bp_run_now" value="1">Run now</button></p></form>';

		if ( $st ) {
			echo '<h2>Last run</h2><p>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) ( $st['time'] ?? 0 ) ) ) . ' &mdash; ' . ( ! empty( $st['ok'] ) ? 'OK' : '<strong>FAILED</strong>' ) . ': ' . esc_html( (string) ( $st['message'] ?? '' ) ) . '</p>';
			foreach ( (array) ( $st['notes'] ?? array() ) as $note ) {
				echo '<p><em>' . esc_html( $note ) . '</em></p>';
			}
		}
		if ( $state['feeds'] ) {
			echo '<h2>Playlists</h2><table class="widefat striped"><thead><tr><th>Show</th><th>Episodes</th><th>Feed URL</th></tr></thead><tbody>';
			foreach ( $state['feeds'] as $f ) {
				echo '<tr><td>' . esc_html( $f['name'] ) . '</td><td>' . (int) $f['count'] . '</td><td><a href="' . esc_url( $f['url'] ) . '">' . esc_html( $f['url'] ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( ! empty( $st['unsorted'] ) ) {
			echo '<h2>Episodes whose show could not be worked out (' . (int) $st['unsorted_total'] . ')</h2><ul>';
			foreach ( $st['unsorted'] as $t ) {
				echo '<li>' . esc_html( $t ) . '</li>';
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}

register_activation_hook( __FILE__, array( 'Badlands_Playlists', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Badlands_Playlists', 'deactivate' ) );
add_action( 'init', array( 'Badlands_Playlists', 'ensure_schedule' ) );
add_action( Badlands_Playlists::HOOK, array( 'Badlands_Playlists', 'run' ) );
if ( is_admin() ) {
	add_action( 'admin_menu', array( 'Badlands_Playlists', 'admin_menu' ) );
}
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command(
		'badlands-playlists run',
		static function () {
			$r = Badlands_Playlists::run();
			WP_CLI::log( ( ! empty( $r['ok'] ) ? 'OK: ' : 'FAILED: ' ) . $r['message'] );
			foreach ( (array) ( $r['notes'] ?? array() ) as $n ) {
				WP_CLI::log( '  ' . $n );
			}
		}
	);
}
