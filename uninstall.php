<?php
/**
 * Uninstall routine: removes all plugin data for the current site.
 *
 * @package External_API_Page_Content
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

$eapc_transient_index = get_option( 'eapc_transient_index', array() );

if ( is_array( $eapc_transient_index ) ) {
    foreach ( array_keys( $eapc_transient_index ) as $eapc_cache_key ) {
        delete_transient( $eapc_cache_key );
    }
}

delete_option( 'eapc_transient_index' );
delete_option( 'eapc_settings' );
delete_option( 'eapc_cache_generation' );

global $wpdb;

// Remove fallback options and transients left behind by earlier versions.
$eapc_like_patterns = array(
    $wpdb->esc_like( 'eapc_last_good_' ) . '%',
    $wpdb->esc_like( '_transient_eapc_' ) . '%',
    $wpdb->esc_like( '_transient_timeout_eapc_' ) . '%',
);

foreach ( $eapc_like_patterns as $eapc_like_pattern ) {
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup during uninstall.
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $eapc_like_pattern ) );
}
