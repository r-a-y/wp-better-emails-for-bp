<?php
/**
 * WP Better Emails for BuddyPress Core
 *
 * @package WPBE_BP
 * @subpackage Core
 */

 // Exit if accessed directly
if ( !defined( 'ABSPATH' ) ) exit;

/**
 * Core class for WP Better Emails for BuddyPress
 *
 * @package WPBE_BP
 * @subpackage Classes
 */
class WPBE_BP {

	/**
	 * Creates an instance of the WPBE_BP class
	 *
	 * @return WPBE_BP object
	 * @static
	 */
	public static function init() {
		static $instance = false;

		if ( !$instance ) {
			$instance = new WPBE_BP;
		}

		return $instance;
	}

	/**
	 * Constructor.
	 */
	function __construct() {
		// if WP Better Emails does not exist, throw a notice with activation or installation link for WPBE
		if ( ! class_exists( 'WP_Better_Emails' ) ) {
			add_action( 'admin_notices',                          array( $this, 'get_wpbe' ) );
			return;
		}

		// add notification settings to BP
		add_action( 'bp_members_notification_settings_before_submit', array( $this, 'screen' ) );

		// filter WP email to get the recipient
		add_filter( 'wp_mail',                                        array( $this, 'wp_mail_filter' ) );

		// hijack WP email content type from WPBE
		add_filter( 'wp_mail_content_type',                           array( $this, 'set_wp_mail_content_type' ), 999 );

		// if we're using an older version of WP Better Emails, we can stop the rest
		// of this plugin!
		if ( ! $this->is_newer_version() )
			return;

		// (hack!) we need to stash the original activity item
		// normal activity items
		add_action( 'bp_activity_after_save',                         array( $this, 'save_activity_content' ),    9 );
		add_action( 'bp_activity_after_save',                         array( $this, 'remove_activity_content' ),  999 );

		// activity comment notifications are sent at bp_activity_comment_posted
		add_action( 'bp_activity_comment_posted', array( $this, 'save_activity_comment' ),    9 );
		add_action( 'bp_activity_comment_posted', array( $this, 'remove_activity_comment' ),  999 );

		// Use the HTML content for the following emails
		// @todo add support for BP Group Email Subscription
		add_filter( 'bp_activity_at_message_notification_message',    array( $this, 'use_html_for_at_message' ),       99, 5 );
		add_filter( 'bp_activity_new_comment_notification_message',   array( $this, 'use_html_for_activity_replies' ), 99, 5 );

		// WPBE - convert HTML to plaintext body
		add_filter( 'wpbe_plaintext_body',                            array( $this, 'convert_html_to_plaintext' ) );

		// Filters we run to convert HTML to plain-text
		add_filter( 'wpbe_html_to_plaintext',                         'stripslashes',               5 );
		add_filter( 'wpbe_html_to_plaintext',                         'wp_kses_normalize_entities', 5 );
		add_filter( 'wpbe_html_to_plaintext',                         'wpautop' );
		add_filter( 'wpbe_html_to_plaintext',                         'wp_specialchars_decode',     99 );
	}

	/**
	 * Custom textdomain loader. Not used at the moment.
	 *
	 * Checks WP_LANG_DIR for the .mo file first, then the plugin's language folder.
	 * Allows for a custom language file other than those packaged with the plugin.
	 *
	 * @uses load_textdomain() Loads a .mo file into WP
	 */
	function localization() {
		$mofile		= sprintf( 'wpbe-bp-%s.mo', get_locale() );
		$mofile_global	= WP_LANG_DIR . '/' . $mofile;
		$mofile_local	= dirname( __FILE__ ) . '/languages/' . $mofile;

		if ( is_readable( $mofile_global ) )
			return load_textdomain( 'wpbe-bp', $mofile_global );
		elseif ( is_readable( $mofile_local ) )
			return load_textdomain( 'wpbe-bp', $mofile_local );
		else
			return false;
	}

	/**
	 * Get the recipient from wp_mail's 'To' email address
	 *
	 * @global object $bp
	 * @param array $args Arguments provided via {@link wp_mail()} filter.
	 * @return array
	 */
	function wp_mail_filter( $args ) {
		global $bp;

		if ( ! empty( $args['to'] ) ) {
			$this->user_id = email_exists( $args['to'] );
		}

		return $args;
	}

	/**
	 * See if the user in question wants HTML or plain-text email.
	 */
	function set_wp_mail_content_type( $content_type ) {
		$is_email_html = bp_get_user_meta( $this->user_id, 'notification_html_email', true );

		// if user wants plain text, let's set the email to send in plain text and
		// disable WP Better Emails' custom PHPMailer sendout and add our own hook
		if ( $is_email_html == 'no' ) {
			global $wp_better_emails;

			// remove WP Better Emails' default HTML hook
			remove_action( 'phpmailer_init', array( $wp_better_emails, 'send_html' ) );

			// add our own custom plain-text hook only if we're using a newer version
			// of WP Better Emails
			if ( $this->is_newer_version() ) {
				add_action( 'phpmailer_init', array( $this, 'send_plaintext_only' ) );
			}

			// make sure we return the content type as plain-text
			return 'text/plain';
		}

		return $content_type;
	}

	/**
	 * Temporarily save the full activity content.
	 *
	 * The reason why this is done is currently, activity notification emails
	 * strip all HTML before sending.
	 *
	 * However, we don't want HTML stripped from emails.  So we have to resort to
	 * this method until BP fixes this in core.
	 *
	 * @param obj $activity The BP activity object
	 */
	public static function save_activity_content( $activity ) {
		global $bp;

		$bp->activity->temp = $activity;
	}

	/**
	 * Remove our locally-cached activity object.
	 *
	 * Clear up any remnants from our hack.
	 *
	 * @see WPBE_BP::save_activity_content()
	 *
	 * @param obj $activity The BP activity object
	 */
	public static function remove_activity_content( $activity ) {
		global $bp;

		// remove temporary saved activity content
		if ( ! empty( $bp->activity->temp ) ) {
			unset( $bp->activity->temp );
		}

	}

	/**
	 * Temporarily save the full activity content after activity comments.
	 *
	 * Wrapper for self::save_activity_content().
	 *
	 * @param obj $comment_id The ID of the activity comment.
	 */
	static function save_activity_comment( $comment_id ) {
		$comment = new BP_Activity_Activity( $comment_id );
		self::save_activity_content( $comment );
	}

	/**
	 * Remove temporarily stashed activity content after activity comments.
	 *
	 * Wrapper for self::remove_activity_content().
	 *
	 * @param obj $comment_id The ID of the activity comment.
	 */
	static function remove_activity_comment( $comment_id ) {
		$comment = new BP_Activity_Activity( $comment_id );
		self::remove_activity_content( $comment );
	}

	/**
	 * Modify the @mention email to use HTML instead of plain-text.
	 *
	 * Uses our locally-cached activity content, which contains HTML from
	 * {@link WPBE_BP::save_activity_content()}.
	 *
	 * @param str $retval The original email message
	 * @param stt $poster_name The person mentioning the email recipient
	 * @param str $content The original email content
	 * @param str $message_link The activity permalink
	 * @param str $settings_link The settings link for the email recipient
	 *
	 * @return str The modified email content containing HTML if available.
	 */
	function use_html_for_at_message( $retval, $poster_name, $content, $message_link, $settings_link ) {
		global $bp;

		// sanity check!
		if ( empty( $bp->activity->temp->content ) ) {
			return $retval;

		// grab our activity content from our locally-cached variable
		} else {
			$activity = $bp->activity->temp;
		}

		$poster_link = bp_core_get_userlink( $activity->user_id );
		$content = stripslashes( $activity->content );
		$reply_link = sprintf( '<a href="%s">%s</a>', $message_link, __( 'View/Reply', 'buddypress' ) );

		if ( bp_is_active( 'groups' ) && bp_is_group() ) {
			$group = groups_get_current_group();
			$group_link = '<a href="' . bp_get_group_permalink( $group ) . '">' . $group->name . '</a>';
			$message = sprintf( __(
'%1$s mentioned you in the group %2$s:

<blockquote>%3$s</blockquote>

%4$s ', 'buddypress' ), $poster_link, $group_link, $content, $reply_link );
		} else {
			$message = sprintf( __(
'%1$s mentioned you in an update:

<blockquote>%2$s</blockquote>

%3$s', 'buddypress' ), $poster_link, $content, $reply_link );
		}

		$message .= sprintf( ' &middot; <a href="%s">%s</a>', $settings_link, __( 'Notification Settings', 'buddypress' ) );

		return $message;
	}

	/**
	 * Modify the activity reply email to use HTML instead of plain-text.
	 *
	 * Uses our locally-cached activity content, which contains HTML in
	 * {@link WPBE_BP::save_activity_content()}.
	 *
	 * @param str $retval The original email message
	 * @param stt $poster_name The person mentioning the email recipient
	 * @param str $content The original email content
	 * @param str $message_link The activity permalink
	 * @param str $settings_link The settings link for the email recipient
	 *
	 * @return str The modified email content containing HTML if available.
	 */
	function use_html_for_activity_replies( $retval, $poster_name, $content, $thread_link, $settings_link ) {
		global $bp;

		// sanity check!
		if ( empty( $bp->activity->temp->content ) ) {
			return $retval;

		// grab our activity content from our locally-cached variable
		} else {
			$activity = $bp->activity->temp;
		}

		// the following is basically a copy of bp_activity_new_comment_notification()
		$sender_link = bp_core_get_userlink( $activity->user_id );
		$reply_link = sprintf( '<a href="%s">%s</a>', $thread_link, __( 'View/Reply', 'buddypress' ) );

		$message = sprintf( __( '%1$s replied to one of your updates:

<blockquote>%2$s</blockquote>

%3$s', 'buddypress' ), $sender_link, $content, $reply_link );

		$message .= sprintf( ' &middot; <a href="%s">%s</a>', $settings_link, __( 'Notification Settings', 'buddypress' ) );

		return $message;
	}

	/**
	 * In WP Better Emails, we still need to generate a plain-text body.
	 *
	 * Since we're now using HTML for emails, we should convert the HTML over to
	 * plain-text.
	 *
	 * Uses the html2text functions from the {@link http://openiaml.org/ IAML Modelling Platform}
	 * by {@link mailto:j.m.wright@massey.ac.nz Jevon Wright}.
	 *
	 * Licensed under the Eclipse Public License v1.0:
	 * {@link http://www.eclipse.org/legal/epl-v10.html}
	 *
	 * The functions have been modified by me to better support other elements
	 * like <img> and <li>.
	 *
	 * @param str $content The original email content
	 *
	 * @return str The modified email content converted to plain-text.
	 */
	function convert_html_to_plaintext( $content ) {
		// @todo perhaps load this library at load time instead of during sendouts?
		if ( ! function_exists( 'convert_html_to_text' ) ) {
			require( dirname( __FILE__ ) . '/functions.html2text.php' );
		}

		add_filter( 'wpbe_html_to_plaintext', 'convert_html_to_text' );

		// 'wpbe_html_to_plaintext' is a custom filter by this plugin
		return apply_filters( 'wpbe_html_to_plaintext', $content );
	}

	/**
	 * If a user in BuddyPress has selected to receive emails in plain-text only,
	 * set up PHPMailer to use plain-text.
	 *
	 * @see WPBE_BP::set_wp_mail_content_type()
	 *
	 * @param obj $phpmailer The PHPMailer object
	 */
	function send_plaintext_only( $phpmailer ) {
		global $wp_better_emails;

		// Add plain-text template to message
		$phpmailer->Body = $wp_better_emails->set_email_template( $phpmailer->Body, 'plaintext_template' );

		// The 'wpbe_plaintext_body' filter does the actual conversion to plain-text
		$phpmailer->Body = apply_filters( 'wpbe_plaintext_body', $wp_better_emails->template_vars_replacement( $phpmailer->Body ) );

		// wipe out the alt body
		$phpmailer->AltBody = '';

		// set HTML to false to be extra-safe!
		$phpmailer->IsHTML( false );
	}

	/**
	 * Renders notification fields in BuddyPress' settings area
	 */
	function screen() {
		if ( !$type = bp_get_user_meta( bp_displayed_user_id(), 'notification_html_email', true ) )
			$type = 'yes';
	?>

		<div id="email-type-notification">

			<h3><?php _e( 'Email Type', 'wpbe-bp' ); ?></h3>

			<p><?php _e( 'Choose between HTML or plain-text when receiving email notifications.', 'wpbe-bp' ); ?></p>

			<table class="notification-settings">
				<thead>
					<tr>
						<th class="icon">&nbsp;</th>
						<th class="title">&nbsp;</th>
						<th class="yes"><?php _e( 'Yes', 'buddypress' ) ?></th>
						<th class="no"><?php _e( 'No', 'buddypress' )?></th>
					</tr>
				</thead>

				<tbody>
					<tr>
						<td>&nbsp;</td>
						<td><?php _e( 'Use HTML email?', 'wpbe-bp' ); ?></td>
						<td class="yes"><input type="radio" name="notifications[notification_html_email]" value="yes" <?php checked( $type, 'yes', true ); ?>/></td>
						<td class="no"><input type="radio" name="notifications[notification_html_email]" value="no" <?php checked( $type, 'no', true ); ?>/></td>
					</tr>
				</tbody>
			</table>
		</div>
	<?php
	}

	/**
	 * Add a notice saying that WP Better Emails doesn't exist.
	 *
	 * We also add a quick activation / installation link for WP Better Emails depending on that plugin's state.
	 *
	 * @see Theme_Plugin_Dependency
	 */
	function get_wpbe() {
		$wpbe = new Theme_Plugin_Dependency( 'wp-better-emails', 'http://wordpress.org/extend/plugins/wp-better-emails/' );
	?>
		<div class="error">
			<p><?php printf( __( '%s requires the %s plugin to be activated.', 'wpbe-bp' ), '<strong>WP Better Emails for BuddyPress</strong>', '<strong>WP Better Emails</strong>' ); ?></p>

			<?php if ( $wpbe->check() ) : ?>
				<p><a href="<?php echo $wpbe->activate_link(); ?>"><?php _e( 'Activate it now!', 'wpbe-bp' ); ?></a></p>
			<?php elseif ( $install_link = $wpbe->install_link() ) : ?>
				<p><a href="<?php echo $install_link; ?>"><?php _e( 'Intsall it now!', 'wpbe-bp' ); ?></a></p>
			<?php endif; ?>
		</div>
	<?php
	}

	/**
	 * Determine if we're using the newer version of WP Better Emails.
	 */
	function is_newer_version() {
		global $wp_better_emails;

		if ( is_callable( array( $wp_better_emails, 'plaintext_template_editor' ) ) )
			return true;

		return false;
	}
}

// Wind it up!
add_action( 'bp_init', array( 'WPBE_BP', 'init' ) );

if ( ! class_exists( 'Theme_Plugin_Dependency' ) ) {
	/**
	 * Simple class to let themes add dependencies on plugins in ways they might find useful.
	 *
	 * Thanks Otto!
	 *
	 * @link http://ottopress.com/2012/themeplugin-dependencies/
	 */
	class Theme_Plugin_Dependency {
		// input information from the theme
		var $slug;
		var $uri;

		// installed plugins and uris of them
		private $plugins; // holds the list of plugins and their info
		private $uris; // holds just the URIs for quick and easy searching

		// both slug and PluginURI are required for checking things
		function __construct( $slug, $uri ) {
			$this->slug = $slug;
			$this->uri = $uri;
			if ( empty( $this->plugins ) )
				$this->plugins = get_plugins();
			if ( empty( $this->uris ) )
				$this->uris = wp_list_pluck($this->plugins, 'PluginURI');
		}

		// return true if installed, false if not
		function check() {
			return in_array($this->uri, $this->uris);
		}

		// return true if installed and activated, false if not
		function check_active() {
			$plugin_file = $this->get_plugin_file();
			if ($plugin_file) return is_plugin_active($plugin_file);
			return false;
		}

		// gives a link to activate the plugin
		function activate_link() {
			$plugin_file = $this->get_plugin_file();
			if ($plugin_file) return wp_nonce_url(self_admin_url('plugins.php?action=activate&plugin='.$plugin_file), 'activate-plugin_'.$plugin_file);
			return false;
		}

		// return a nonced installation link for the plugin. checks wordpress.org to make sure it's there first.
		function install_link() {
			include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

			$info = plugins_api('plugin_information', array('slug' => $this->slug ));

			if ( is_wp_error( $info ) )
				return false; // plugin not available from wordpress.org

			return wp_nonce_url(self_admin_url('update.php?action=install-plugin&plugin=' . $this->slug), 'install-plugin_' . $this->slug);
		}

		// return array key of plugin if installed, false if not, private because this isn't needed for themes, generally
		private function get_plugin_file() {
			return array_search($this->uri, $this->uris);
		}
	}
}

?>
