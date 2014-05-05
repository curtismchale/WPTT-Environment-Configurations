<?php
/*
Plugin Name: Environment Configs
Plugin URI: http://wpthemetutorial.com
Description: Changes configuration items based on our site location.
Version: 1.0
Author: SFNdesign, Curtis McHale
Author URI: http://sfndesign.ca
License: GPLv2 or later
*/

/*
This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
as published by the Free Software Foundation; either version 2
of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

class WPTT_Env_Configs{

	function __construct(){

		add_filter( 'query_vars', array( $this, 'add_query_vars' ), 0 );
		add_action( 'parse_request', array( $this, 'sniff_requests' ), 0 );
		add_action( 'init', array( $this, 'add_endpoint' ), 0 );

		add_action( 'wptt_config_check_plugins', array( $this, 'check_plugin_activation' ) );

	} // construct

	/**
	 * Does our configuration setup
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access public
	 *
	 * @uses $this->is_allowed_ip()            Returns true if the IP hitting the config is allowed
	 * @uses wptt_is_local()                  Returns true if it's the local environment
	 * @uses $this->setup_local()              Calls our local configuration functions
	 */
	public function config(){

		if ( ! $this->is_allowed_ip() ) return;

		if ( wptt_is_local() ){
			$this->setup_local();
		} // if wptt_is_local

		if ( wptt_is_staging() ){
			$this->setup_staging();
		} // if wptt_is_staging

		if ( wptt_is_live() ){
			$this->setup_live();
		} // if wptt_is_live

	} // config

	/**
	 * Makes configuration changes for the live environments
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access private
	 *
	 */
	private function setup_live(){

		do_action( 'wptt_config_live' );

	} // setup_live


	/**
	 * Makes configuration changes for the staging environments
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access private
	 *
	 * @uses $this->schedule_single_plugin_activation_event()       Schedules a cron event to check plugin activation
	 * @uses $this->restrict_site_access()                          Makes sure that the site is locked down from outside access
	 */
	private function setup_staging(){

		do_action( 'wptt_config_staging' );

	} // setup_staging

	/**
	 * Makes configuration changes for the local environments
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access private
	 *
	 * @uses $this->schedule_single_plugin_activation_event()       Schedules a cron event to check plugin activation
	 */
	private function setup_local(){

		add_filter( 'cron_request', array( $this, 'wp_cron_timeout' ), 10, 1 );

		do_action( 'wptt_config_local' );

	}// setup_staging

	/**
	 * WP Cron
	 *
	 * Alter the timeout on cron requests from 0.01 to 0.5. Something about
	 * the Vagrant and/or Ubuntu setup doesn't like these self requests
	 * happening so quickly.
	 *
	 * @since 1.0
	 * @author Kevin Leary
	 */
	private function wp_cron_timeout( $cron_request ) {

		$cron_request['args']['timeout'] = (float) 0.5;
		return $cron_request;

	} // wp_cron_timeout

	/**
	 * Schedules a single event which checks to make sure our plugins are active on our environments.
	 * Checking right away on init doesn't work because this is an mu-plugin which runs before
	 * the real plugins are actually running.
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 *
	 * @uses wp_clear_scheduled_hook()      Clears the event if it's already scheduled
	 * @uses wp_schedule_single_event()     Schedules a single hook call when cron runs next
	 */
	private function schedule_single_plugin_activation_event(){

		wp_clear_scheduled_hook( 'wptt_config_check_plugins' );
		wp_schedule_single_event( time(), 'wptt_config_check_plugins' );

	} // schedule_single_plugin_activation_event

	/**
	 * Checks what plugins we have active and makes sure that some of our specific ones are in fact active
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access public
	 *
	 * @uses get_option()                         Returns the option from the DB given the key
	 * @uses $this->get_plugins_to_activate()     Returns an array of plugins to activate
	 * @uses update_option()                      Updates option in DB given key and value
	 */
	public function check_plugin_activation(){

		$active_plugins = FALSE;
		$active_plugins = get_option( 'active_plugins' );

		if ( $active_plugins ){

			$to_activate = apply_filters( 'wptt_plugins_to_activate', array( array( 'name' => '', 'network' => false ) ) );

			foreach ( $to_activate as $p ){
				activate_plugin( $p['name'], '', $p['network'] );
			} // foreach

		} // if $plugins

	} // check_plugin_activation

	/**
	 * Returns true if we have a valid IP address
	 *
	 * If an IP that is allowed to configure the site hits then this will
	 * return true since we have a valid IP address.
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access private
	 *
	 * @return bool             Returns true if we have a valid IP address
	 *
	 */
	private function is_allowed_ip(){

		$ip = getenv("REMOTE_ADDR");

		// true if it's a developer's IP address
		$dev_ip = apply_filters( 'wptt_developer_ip', '' );
		if ( in_array( $ip, $dev_ip ) ) return true;

		// verifies ip ranges
		if ( $this->is_in_ip_range( $ip ) ) return true;

		return false;

	} // is_allowed_ip

	/**
	 * This is used to check IP ranges to make sure that they are allowed ranges
	 * for site configuration.
	 *
	 * I use this to check things like the range of IP addys that Beanstalk could use
	 * during it's deployment.
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access private
	 *
	 * @param int     $ip     required     The IP we are checking
	 *
	 * @return bool           True if the IP is in an accepted range
	 */
	private function is_in_ip_range( $ip ){

		// changing format so it matches our needs
		$ip = ip2long( $ip );
		$range = apply_filters( 'wptt_ip_range', array( array( 'low' => '', 'high' => '' ) ) );

		foreach ( $range as $r ){

			$low  = ip2long( $r['low'] );
			$high = ip2long( $r['high'] );

			if ( $ip >= $low && $ip <= $high ){
				return true;
			}

		} // foreach

		return false;

	} // is_in_ip_range

	/**
	 * Add public query vars
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access public
	 *
	 * @param array     $vars     required     The original query_vars
	 *
	 * @return array    $vars     Our modified query vars
	 */
	public function add_query_vars( $vars ){

		$vars[] = '__deploy';

		return $vars;

	} // add_query_vars

	/**
	 * Adds our endpoint and calls the deploy URL
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access public
	 *
	 * @uses add_rewrite_rule()       Matches a URL and tells WP what to do with it
	 */
	public function add_endpoint(){
		add_rewrite_rule( '^api/deploy/?([a-zA-z])?/?', 'index.php?__deploy=1', 'top' );
	} // add_endpoint

	/**
	 * Checks for our requests
	 *
	 * @since 1.0
	 * @author SFNdesign, Curtis McHale
	 * @access public
	 *
	 * @uses $wp->query_vars      The query vars in the URL
	 */
	public function sniff_requests(){
		global $wp;

		if ( isset( $wp->query_vars['__deploy'] ) ){
			$this->config();
			exit;

		} // if

	} // sniff_requests

} // WPTT_Env_Configs

new WPTT_Env_Configs();
