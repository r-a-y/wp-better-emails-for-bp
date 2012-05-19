<?php
/*
Plugin Name: WP Better Emails for BuddyPress
Description: Allow users to enable or disable HTML-formatted emails created by the WP Better Emails plugin.
Author: r-a-y
Author URI: http://buddypress.org/community/members/r-a-y/
Version: 0.1
*/

/**
 * WP Better Emails for BuddyPress
 *
 * @package WPBE_BP
 * @subpackage Loader
 */
 
 // Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * Loads the plugin only if BuddyPress is activated
 */
function wpbe_bp_init() {
	require( dirname( __FILE__ ) . '/wpbe-bp.php' );
}
add_action( 'bp_include', 'wpbe_bp_init' );

?>