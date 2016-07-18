<?php
/*
Plugin Name: Gravity Forms MailPoet Add-on
Plugin URI: https://github.com/bhays/gravity-forms-wysija
Description: Integrates the Gravity Forms plugin with the MailPoet Newsletters plugin, creating a menage-a-plugin.
Version: 2.0
Author: Ben Hays
Author URI: http://benhays.com
Text Domain: gravity-forms-wysija
Domain Path: /languages/

------------------------------------------------------------------------
Copyright 2016 Ben Hays

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

define( 'GF_WYSIJA_ADDON_VERSION', '2.0' );

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