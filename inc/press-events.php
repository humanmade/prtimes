<?php
/**
 * HM Press Events
 *
 * @package HM
 */

namespace HM\PR_Times\Press_Events;

use function HM\PR_Times\create_and_get_author_user_id;
use function HM\PR_Times\get_press_events_category_id;
use HM\PR_Times;
use SimplePie;
use SimplePie_Item;
use WP_Error;

const RSS_NAMESPACE = 'https://prtimes.jp/tv/rss/1.0/';

/**
 * Fetch PR Times Feed.
 *
 * @param string $press_events_url Press Events URL.
 *
 * @return bool|WP_Error
 */
function fetch( $press_events_url ) {
	$rss = fetch_feed( $press_events_url );

	if ( is_wp_error( $rss ) ) {
		return new WP_Error( 'press-events', $rss->get_error_message() );
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
function check_last_update( SimplePie $rss ) :bool {
	$data = $rss->get_channel_tags( '', 'lastBuildDate' );
	if ( ! isset( $data[0]['data'] ) ) {
		return false;
	}

	$date         = strtotime( $data[0]['data'] );
	$last_updated = get_option( 'hm_press_events_last_updated', null );

	if ( $date === $last_updated ) {
		return false;
	}

	update_option( 'hm_press_events_last_updated', $date );

	return true;
}

/**
 * Parse over items in feed.
 *
 * @param array $items Press Event Item List.
 *
 * @return bool
 */
function parse( array $items ) :bool {
	$author    = get_user_by( 'slug', PR_Times\RSS_AUTHOR_SLUG );
	$author_id = ! empty( $author ) ? $author->ID : create_and_get_author_user_id( PR_Times\RSS_AUTHOR_SLUG );
	$time      = 0;

	$scheduled = false;

	foreach ( $items as $item ) {
		$post = [
			'ID'           => 0,
			'post_title'   => (string) $item->get_title(),
			'post_author'  => $author_id,
			'post_date'    => (string) get_created_date( $item ),
			'post_status'  => 'publish',
			'post_content' => (string) $item->get_content(),
			'post_type'    => 'post',
			'meta_input'   => [
				'prtimes_ref_id'           => (string) $item->get_link(),
				'prtimes_last_updated'     => (string) $item->get_date( 'Y-m-d H:i:s' ),
				'press_events_youtube_url' => (string) get_youtube( $item ),
				'press_events_duration'    => (string) get_duration( $item ),
				'press_events_resolution'  => (string) get_resolution( $item ),
				'image_url'                => (string) get_thumbnail( $item ),
			],
		];

		// assign specific category.
		$category_id = get_press_events_category_id();
		if ( ! empty( $category_id ) ) {
			$post['post_category'] = [ $category_id ];
		}

		if ( ! wp_next_scheduled( 'hm_press_events_post_upsert', [ $post ] ) ) {
			$scheduled = (bool) wp_schedule_single_event( time() + $time, 'hm_press_events_post_upsert', [ $post ] );
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
function get_thumbnail( SimplePie_Item $item ) :?string {
	$data = $item->get_item_tags( RSS_NAMESPACE, 'thumbnail' );

	return $data[0]['data'] ?? null;
}

/**
 * Get Youtube URL.
 *
 * @param SimplePie_Item $item RSS Item.
 *
 * @return string|null
 */
function get_youtube( SimplePie_Item $item ) :?string {
	$data = $item->get_item_tags( RSS_NAMESPACE, 'youtube' );

	return $data[0]['data'] ?? null;
}

/**
 * Get Video Duration.
 *
 * @param SimplePie_Item $item RSS Item.
 *
 * @return string|null
 */
function get_duration( SimplePie_Item $item ) :?string {
	$data = $item->get_item_tags( RSS_NAMESPACE, 'duration' );

	return $data[0]['data'] ?? null;
}

/**
 * Get Video Resolution.
 *
 * @param SimplePie_Item $item RSS Item.
 *
 * @return string|null
 */
function get_resolution( SimplePie_Item $item ) :?string {
	$data = $item->get_item_tags( RSS_NAMESPACE, 'resolution' );

	return $data[0]['data'] ?? null;
}

/**
 * Get Video Resolution.
 *
 * @param SimplePie_Item $item RSS Item.
 *
 * @return string|null
 */
function get_created_date( SimplePie_Item $item ) :?string {
	$data = $item->get_item_tags( RSS_NAMESPACE, 'createdDate' );

	return  date( 'Y-m-d H:i:s', strtotime( $data[0]['data'] ?? null ) );
}
