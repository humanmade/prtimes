<?php
/**
 * HM Story Feed
 *
 * @package HM
 */

namespace HM\PR_Times\Story;

use Altis;
use HM\PR_Times;
use SimplePie;
use SimplePie_Item;
use WP_Error;

/**
 * Fetch PR Times Feed.
 *
 * @return bool|WP_Error
 */
function fetch( $admin_story_url ) {
	if ( ! Altis\get_config()['hm']['pr-times']['feed']['story'] ) {
		return false;
	}

	$rss = fetch_feed( $admin_story_url );

	if ( is_wp_error( $rss ) ) {
		return new WP_Error( 'story', $rss->get_error_message() );
	}

	if ( ! check_last_update( $rss ) ) {
		return false;
	}

	$items = $rss->get_items();

	if ( is_wp_error( $items ) ) {
		return false;
	}

	return parse( $items );
}

/**
 * Check last time the feed was updated.
 *
 * @param SimplePie $rss Feed.
 *
 * @return bool
 */
function check_last_update( SimplePie $rss ) : bool {
	$data = $rss->get_channel_tags( '', 'pubDate' );

	if ( ! isset( $data[0]['data'] ) ) {
		return false;
	}

	$date         = strtotime( $data[0]['data'] );
	$last_updated = get_option( 'hm_story_last_updated', null );

	if ( $date === $last_updated ) {
		return false;
	}

	update_option( 'hm_story_last_updated', $date );

	return true;
}

/**
 * Parse over items in feed.
 *
 * @param array $items Press Event Item List.
 *
 * @return bool
 */
function parse( array $items ) : bool {
	$author    = get_user_by( 'slug', PR_Times\STORY_AUTHOR_SLUG );
	$author_id = ! empty( $author ) ? $author->ID : '';
	$time      = 0;

	$scheduled = false;

	foreach ( $items as $item ) {
		$post = [
			'ID'           => 0,
			'post_title'   => (string) $item->get_title(),
			'post_author'  => $author_id,
			'post_date'    => (string) $item->get_date( 'Y-m-d H:i:s' ),
			'post_status'  => 'publish',
			'post_content' => (string) $item->get_content(),
			'post_type'    => 'post',
			'meta_input'   => [
				'prtimes_ref_id'           => (string) $item->get_link(),
				'prtimes_last_updated'     => (string) $item->get_date( 'Y-m-d H:i:s' ),
				'image_url'                => (string) get_thumbnail( $item ),
			],
		];

		if ( ! wp_next_scheduled( 'hm_story_post_upsert', [ $post ] ) ) {
			$scheduled = (bool) wp_schedule_single_event( time() + $time, 'hm_story_post_upsert', [ $post ] );
			$time += 15;
		}
	}

	return $scheduled;
}

/**
 * Get Thumbnail Image URL.
 *
 * @param SimplePie_Item $item RSS Item.
 *
 * @return string|null
 */
function get_thumbnail( SimplePie_Item $item ) : ?string {
	$data = $item->get_item_tags( 'http://search.yahoo.com/mrss/', 'thumbnail' );

	return $data[0]['attribs']['']['url'] ?? null;
}


