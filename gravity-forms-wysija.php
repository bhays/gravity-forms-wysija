<?php
/*
Plugin Name: Gravity Forms MailPoet Add-on
Plugin URI: https://github.com/bhays/gravity-forms-wysija
Description: Integrates the Gravity Forms plugin with the MailPoet Newsletters plugin, creating a menage-a-plugin.
Version: 2.0.1
Author: Ben Hays
Author URI: http://benhays.com
Text Domain: gravity-forms-wysija
Domain Path: /languages/

------------------------------------------------------------------------
Copyright 2017 Ben Hays

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
*/

define( 'GF_WYSIJA_ADDON_VERSION', '2.0.1' );

add_action( 'gform_loaded', array( 'GF_MailPoet_Startup', 'load' ), 5 );

class GF_MailPoet_Startup {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		require_once( 'class-gfmailpoetaddon.php' );

		GFAddOn::register( 'GFMailPoetAddon' );
	}

}

function gf_mailpoet_feed_addon() {
	return GFMailPoetAddOn::get_instance();
}

register_activation_hook(__FILE__, 'gf_mailpoet_plugin_activation');
function gf_mailpoet_plugin_activation() {
	$notices= get_option('gf_mailpoet_plugin_deferred_admin_notices', array());
	$notices[]= __("<strong>Gravity Forms MailPoet Add-on notice</strong> <br/><br/><strong>Update all your MailPoet feeds</strong> after upgrading to version 2.0.</br><br/> This add-on now uses the updated Gravity Forms feed settings, which allows you to set feeds individually for each form. Go to Form Settings -> MailPoet to create your feeds for a given form.", 'gravity-forms-wysija');
	update_option('gf_mailpoet_plugin_deferred_admin_notices', $notices);
}

add_action('admin_notices', 'gf_mailpoet_plugin_admin_notices');
function gf_mailpoet_plugin_admin_notices() {
	if ($notices = get_option('gf_mailpoet_plugin_deferred_admin_notices')) {
		foreach ($notices as $notice) {
			echo "<div class='notice notice-warning is-dismissable'><p>$notice</p></div>";
		}
		delete_option('gf_mailpoet_plugin_deferred_admin_notices');
	}
}

register_deactivation_hook(__FILE__, 'gf_mailpoet_plugin_deactivation');
function gf_mailpoet_plugin_deactivation() {
	delete_option('gf_mailpoet_plugin_deferred_admin_notices');
}