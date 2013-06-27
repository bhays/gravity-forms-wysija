<?php
/*
Plugin Name: Gravity Forms Wysija Add-on
Plugin URI: https://github.com/bhays/gravity-forms-wysija
Description: Integrates the Gravity Forms plugin with the Wysija plugin, creating a menage-a-plugin.
Version: 1.1
Author: Ben Hays
Author URI: http://benhays.com
Text Domain: gravity-forms-wysija
Domain Path: /languages/

------------------------------------------------------------------------
Copyright 2013 Ben Hays

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

add_action('init',  array('GFWysija', 'init'));
register_activation_hook( __FILE__, array("GFWysija", "add_permissions"));

class GFWysija {

    private static $path = "gravity-forms-wysija/gravity-forms-wysija.php";
    private static $url = "http://www.gravityforms.com";
    private static $slug = "gravity-forms-wysija";
    private static $version = "1.1";
    private static $min_gravityforms_version = "1.6.10";
    private static $supported_fields = array(
	    				"checkbox", "radio", "select", "text", "website", "textarea", "email", 
	    				"hidden", "number", "phone", "multiselect", "post_title",
	                    "post_tags", "post_custom_field", "post_content", "post_excerpt"
					);

    //Plugin starting point. Will load appropriate files
    public static function init(){
		//supports logging
		add_filter("gform_logging_supported", array("GFWysija", "set_logging_supported"));

		if(basename($_SERVER['PHP_SELF']) == "plugins.php") {

            //loading translations
            load_plugin_textdomain( 'gravity-forms-wysija', FALSE, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			//force new remote request for version info on the plugin page
			//self::flush_version_info();
        }

        add_action('after_plugin_row_' . self::$path, array('GFWysija', 'plugin_row') );

        if(!self::is_gravityforms_supported() || !self::is_wysija_installed() ){
           return;
        }

        if(is_admin()){
            //loading translations
            load_plugin_textdomain( 'gravity-forms-wysija', FALSE, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

            add_filter("transient_update_plugins", array('GFWysija', 'check_update'));
            add_filter("site_transient_update_plugins", array('GFWysija', 'check_update'));

            add_action('install_plugins_pre_plugin-information', array('GFWysija', 'display_changelog'));
        }

        //integrating with Members plugin
        if(function_exists('members_get_capabilities'))
            add_filter('members_get_capabilities', array("GFWysija", "members_get_capabilities"));

        //creates the subnav left menu
        add_filter("gform_addon_navigation", array('GFWysija', 'create_menu'));

        if(self::is_wysija_page()){

            //enqueueing sack for AJAX requests
            wp_enqueue_script(array("sack"));

            //loading data lib
            require_once(self::get_base_path() . "/inc/data.php");

            //loading Gravity Forms tooltips
            require_once(GFCommon::get_base_path() . "/tooltips.php");
            add_filter('gform_tooltips', array('GFWysija', 'tooltips'));

            //runs the setup when version changes
            self::setup();

         }
         else if(in_array(RG_CURRENT_PAGE, array("admin-ajax.php"))){

            //loading data class
            require_once(self::get_base_path() . "/inc/data.php");

            add_action('wp_ajax_rg_update_feed_active', array('GFWysija', 'update_feed_active'));
            add_action('wp_ajax_gf_select_wysija_form', array('GFWysija', 'select_wysija_form'));

        }
        else{
             //handling post submission.
            add_action("gform_after_submission", array('GFWysija', 'export'), 10, 2);
        }
    }

    public static function update_feed_active(){
        check_ajax_referer('rg_update_feed_active','rg_update_feed_active');
        $id = $_POST["feed_id"];
        $feed = GFWysijaData::get_feed($id);
        GFWysijaData::update_feed($id, $feed["form_id"], $_POST["is_active"], $feed["meta"]);
    }

    //Displays current version details on Plugin's page
    public static function display_changelog(){
        if($_REQUEST["plugin"] != self::$slug)
            return;

        RGWysijaUpgrade::display_changelog(self::$slug, self::get_key(), self::$version);
    }

    public static function check_update($update_plugins_option){
		if ( get_option( 'gf_wysija_version' ) != self::$version ) {
			require_once( 'inc/data.php' );
			GFWysijaData::update_table();
		}

		update_option( 'gf_wysija_version', self::$version );
    }

    private static function get_key(){
        if(self::is_gravityforms_supported())
            return GFCommon::get_key();
        else
            return "";
    }
    //---------------------------------------------------------------------------------------

    //Returns true if the current page is an Feed pages. Returns false if not
    private static function is_wysija_page(){
        $current_page = trim(strtolower(rgget("page")));
        $wysija_pages = array("gf_wysija");

        return in_array($current_page, $wysija_pages);
    }

    //Creates or updates database tables. Will only run when version changes
    private static function setup(){

        if(get_option("gf_wysija_version") != self::$version)
            GFWysijaData::update_table();

        update_option("gf_wysija_version", self::$version);
    }

    //Adds feed tooltips to the list of tooltips
    public static function tooltips($tooltips){
        $wysija_tooltips = array(
            "wysija_contact_list" => "<h6>" . __("Wysija Lists", "gravity-forms-wysija") . "</h6>" . __("Select the Wysija lists you would like to add your contacts to.", "gravity-forms-wysija"),
            "wysija_gravity_form" => "<h6>" . __("Gravity Form", "gravity-forms-wysija") . "</h6>" . __("Select the Gravity Form you would like to integrate with Wysija. Contacts generated by this form will be automatically added the selected Wysija lists.", "gravity-forms-wysija"),
            "wysija_welcome" => "<h6>" . __("Send Welcome Email", "gravity-forms-wysija") . "</h6>" . __("When this option is enabled, users will receive an automatic welcome email from Wysija upon being added to your Wysija list.", "gravity-forms-wysija"),
            "wysija_map_fields" => "<h6>" . __("Map Fields", "gravity-forms-wysija") . "</h6>" . __("Associate your Wysija newsletter questions to the appropriate Gravity Form fields by selecting.", "gravity-forms-wysija"),
            "wysija_optin_condition" => "<h6>" . __("Opt-In Condition", "gravity-forms-wysija") . "</h6>" . __("When the opt-in condition is enabled, form submissions will only be exported to Wysija when the condition is met. When disabled all form submissions will be exported.", "gravity-forms-wysija"),
            "wysija_double_optin" => "<h6>" . __("Double Opt-In", "gravity-forms-wysija") . "</h6>" . __("When the double opt-in option is enabled, Wysija will send a confirmation email to the user and will only add them to your Wysija list upon confirmation.", "gravity-forms-wysija")
        );
        return array_merge($tooltips, $wysija_tooltips);
    }

    //Creates Wysija left nav menu under Forms
    public static function create_menu($menus){

        // Adding submenu if user has access
        $permission = self::has_access("gravityforms_wysija");
        if(!empty($permission))
            $menus[] = array("name" => "gf_wysija", "label" => __("Wysija", "gravity-forms-wysija"), "callback" =>  array("GFWysija", "wysija_page"), "permission" => $permission);

        return $menus;
    }

    public static function wysija_page(){
        $view = rgar($_GET, 'view');
        if( $view == 'edit' )
            self::edit_page($_GET['id']);
        else
            self::list_page();
    }

    //Displays the wysija feeds list page
    private static function list_page(){
        if(!self::is_gravityforms_supported()){
            die(__(sprintf("Wysija Add-On requires Gravity Forms %s. Upgrade automatically on the %sPlugin page%s.", self::$min_gravityforms_version, "<a href='plugins.php'>", "</a>"), "gravity-forms-wysija"));
        }

        if(rgpost("action") == "delete"){
            check_admin_referer("list_action", "gf_wysija_list");

            $id = absint($_POST["action_argument"]);
            GFWysijaData::delete_feed($id);
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feed deleted.", "gravity-forms-wysija") ?></div>
            <?php
        }
        else if (!empty($_POST["bulk_action"])){
            check_admin_referer("list_action", "gf_wysija_list");
            $selected_feeds = $_POST["feed"];
            if(is_array($selected_feeds)){
                foreach($selected_feeds as $feed_id)
                    GFWysijaData::delete_feed($feed_id);
            }
            ?>
            <div class="updated fade" style="padding:6px"><?php _e("Feeds deleted.", "gravity-forms-wysija") ?></div>
            <?php
        }

        ?>
        <div class="wrap">
            
            <h2><?php _e("Wysija Newsletter Feeds", "gravity-forms-wysija"); ?>
            <a class="add-new-h2" href="admin.php?page=gf_wysija&view=edit&id=0"><?php _e("Add New", "gravity-forms-wysija") ?></a>
            </h2>


            <form id="feed_form" method="post">
                <?php wp_nonce_field('list_action', 'gf_wysija_list') ?>
                <input type="hidden" id="action" name="action"/>
                <input type="hidden" id="action_argument" name="action_argument"/>

                <div class="tablenav">
                    <div class="alignleft actions" style="padding:8px 0 7px 0;">
                        <label class="hidden" for="bulk_action"><?php _e("Bulk action", "gravity-forms-wysija") ?></label>
                        <select name="bulk_action" id="bulk_action">
                            <option value=''> <?php _e("Bulk action", "gravity-forms-wysija") ?> </option>
                            <option value='delete'><?php _e("Delete", "gravity-forms-wysija") ?></option>
                        </select>
                        <?php
                        echo '<input type="submit" class="button" value="' . __("Apply", "gravity-forms-wysija") . '" onclick="if( jQuery(\'#bulk_action\').val() == \'delete\' && !confirm(\'' . __("Delete selected feeds? ", "gravity-forms-wysija") . __("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-wysija") .'\')) { return false; } return true;"/>';
                        ?>
                    </div>
                </div>
                <table class="widefat fixed" cellspacing="0">
                    <thead>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-wysija") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Wysija Lists", "gravity-forms-wysija") ?></th>
                        </tr>
                    </thead>

                    <tfoot>
                        <tr>
                            <th scope="col" id="cb" class="manage-column column-cb check-column" style=""><input type="checkbox" /></th>
                            <th scope="col" id="active" class="manage-column check-column"></th>
                            <th scope="col" class="manage-column"><?php _e("Form", "gravity-forms-wysija") ?></th>
                            <th scope="col" class="manage-column"><?php _e("Wysija Lists", "gravity-forms-wysija") ?></th>
                        </tr>
                    </tfoot>

                    <tbody class="list:user user-list">
                        <?php

                        $settings = GFWysijaData::get_feeds();
                        if(is_array($settings) && sizeof($settings) > 0){
                            foreach($settings as $setting){
                                ?>
                                <tr class='author-self status-inherit' valign="top">
                                    <th scope="row" class="check-column"><input type="checkbox" name="feed[]" value="<?php echo $setting["id"] ?>"/></th>
                                    <td><img src="<?php echo self::get_base_url() ?>/images/active<?php echo intval($setting["is_active"]) ?>.png" alt="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-wysija") : __("Inactive", "gravity-forms-wysija");?>" title="<?php echo $setting["is_active"] ? __("Active", "gravity-forms-wysija") : __("Inactive", "gravity-forms-wysija");?>" onclick="ToggleActive(this, <?php echo $setting['id'] ?>); " /></td>
                                    <td class="column-title">
                                        <a href="admin.php?page=gf_wysija&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-wysija") ?>"><?php echo $setting["form_title"] ?></a>
                                        <div class="row-actions">
                                            <span class="edit">
                                            <a href="admin.php?page=gf_wysija&view=edit&id=<?php echo $setting["id"] ?>" title="<?php _e("Edit", "gravity-forms-wysija") ?>"><?php _e("Edit", "gravity-forms-wysija") ?></a>
                                            |
                                            </span>

                                            <span class="trash">
                                            <a title="<?php _e("Delete", "gravity-forms-wysija") ?>" href="javascript: if(confirm('<?php _e("Delete this feed? ", "gravity-forms-wysija") ?> <?php _e("\'Cancel\' to stop, \'OK\' to delete.", "gravity-forms-wysija") ?>')){ DeleteSetting(<?php echo $setting["id"] ?>);}"><?php _e("Delete", "gravity-forms-wysija")?></a>

                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-date">
                                    <?php 
                                    	$str = '';
                                    	$lists = self::get_wysija_lists();
                                    	foreach( $lists as $l )
                                    	{
	                                    	if( in_array($l['list_id'], $setting['meta']['lists']) )
	                                    	{
		                                    	$str .= $l['name'].", ";
	                                    	}
                                    	}
                                    	echo rtrim($str, ', ');
                                    ?>
                                    
                                    </td>
                                </tr>
                                <?php
                            }
                        }
                        else{
                            ?>
                            <tr>
                                <td colspan="4" style="padding:20px;">
                                    <?php _e(sprintf("You don't have any Wysija Newsletter feeds configured. Let's go %screate one%s!", '<a href="admin.php?page=gf_wysija&view=edit&id=0">', "</a>"), "gravity-forms-wysija"); ?>
                                </td>
                            </tr>
                            <?php
                        }
                        ?>
                    </tbody>
                </table>
            </form>
        </div>
        <script type="text/javascript">
            function DeleteSetting(id){
                jQuery("#action_argument").val(id);
                jQuery("#action").val("delete");
                jQuery("#feed_form")[0].submit();
            }
            function ToggleActive(img, feed_id){
                var is_active = img.src.indexOf("active1.png") >=0
                if(is_active){
                    img.src = img.src.replace("active1.png", "active0.png");
                    jQuery(img).attr('title','<?php _e("Inactive", "gravity-forms-wysija") ?>').attr('alt', '<?php _e("Inactive", "gravity-forms-wysija") ?>');
                }
                else{
                    img.src = img.src.replace("active0.png", "active1.png");
                    jQuery(img).attr('title','<?php _e("Active", "gravity-forms-wysija") ?>').attr('alt', '<?php _e("Active", "gravity-forms-wysija") ?>');
                }

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "rg_update_feed_active" );
                mysack.setVar( "rg_update_feed_active", "<?php echo wp_create_nonce("rg_update_feed_active") ?>" );
                mysack.setVar( "feed_id", feed_id );
                mysack.setVar( "is_active", is_active ? 0 : 1 );
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() { alert('<?php _e("Ajax error while updating feed", "gravity-forms-wysija" ) ?>' )};
                mysack.runAJAX();

                return true;
            }
        </script>
        <?php
    }

    private static function edit_page(){
        ?>
        <style>
            .wysija_col_heading{padding-bottom:2px; border-bottom: 1px solid #ccc; font-weight:bold;}
            .wysija_field_cell {min-width: 120px; padding: 6px 20px 0 0; margin-right:25px;}
            .gfield_required{color:red;}

            .feeds_validation_error{ background-color:#FFDFDF;}
            .feeds_validation_error td{ margin-top:4px; margin-bottom:6px; padding-top:6px; padding-bottom:6px; border-top:1px dotted #C89797; border-bottom:1px dotted #C89797}

            .left_header{float:left; width:200px;}
            .margin_vertical_10{margin: 10px 0;}
            #wysija_doubleoptin_warning{padding-left: 5px; padding-bottom:4px; font-size: 10px;}
            .wysija_group_condition{padding-bottom:6px; padding-left:20px;}
            .wysija_checkbox { margin-left: 203px; display: block; margin-bottom: 3px;}
        </style>
        <script type="text/javascript">
            var form = Array();
        </script>
        <div class="wrap">
            <h2><?php _e("Wysija Newsletter Feed", "gravity-forms-wysija") ?></h2>
        <?php
		//getting setting id (0 when creating a new one)
        $id = !empty($_POST["wysija_setting_id"]) ? $_POST["wysija_setting_id"] : absint($_GET["id"]);
        $default = array(
        	'meta' => array(
	        	'double_optin' => true,
	        	'is_active' => true,
	        	'lists' => array(),
	        ),
	        'is_active' => true,
        );
        $config = empty($id) ? $default : GFWysijaData::get_feed($id);
        
        //self::log_debug("Feed is set to: ".print_r($config, true));

		// Get details from survey if we have one
        if (rgempty("list_id", $config["meta"]))
        {
			$merge_vars = array();
        }
        else
        {
            $details = self::get_wysija_details();
        }

        //updating meta information
        if(rgpost("gf_wysija_submit")){
			//self::log_debug('Posting up on the block: '.print_r($_POST, true));

            $config["form_id"] = absint($_POST["gf_wysija_form"]);
            $config["meta"]["lists"] = rgpost('gf_wysija_lists');

            $is_valid = true;
            $details = self::get_wysija_details();

        	$field_map = array();
			foreach($details as $k=>$v){
				$field_name = "wysija_map_field_".$k;
				$mapped_field = stripslashes($_POST[$field_name]);
				
				if(!empty($mapped_field)){
					$field_map[$k] = $mapped_field;
				}
				else{
					unset($field_map[$k]);
					if( isset($v['required']) ){
						$is_valid = false;                 
					}
				}
            }

            $config["meta"]["field_map"] = $field_map;
            $config["meta"]["double_optin"] = rgpost("wysija_double_optin") ? true : false;
            $config["meta"]["welcome_email"] = rgpost("wysija_welcome_email") ? true : false;

            $config["meta"]["optin_enabled"] = rgpost("wysija_optin_enable") ? true : false;
            $config["meta"]["optin_field_id"] = $config["meta"]["optin_enabled"] ? rgpost("wysija_optin_field_id") : "";
            $config["meta"]["optin_operator"] = $config["meta"]["optin_enabled"] ? rgpost("wysija_optin_operator") : "";
            $config["meta"]["optin_value"] = $config["meta"]["optin_enabled"] ? rgpost("wysija_optin_value") : "";

            if($is_valid){
                $id = GFWysijaData::update_feed($id, $config["form_id"], $config["is_active"], $config["meta"]);
                ?>
                <div class="updated fade" style="padding:6px"><?php echo sprintf(__("Feed Updated. %sback to list%s", "gravity-forms-wysija"), "<a href='?page=gf_wysija'>", "</a>") ?></div>
                <input type="hidden" name="wysija_setting_id" value="<?php echo $id ?>"/>
                <?php
            }
            else{
                ?>
                <div class="error" style="padding:6px"><?php echo __("Feed could not be updated. Please enter all required information below.", "gravity-forms-wysija") ?></div>
                <?php
            }
        }

        ?>
        <form method="post" action="">
            <input type="hidden" name="wysija_setting_id" value="<?php echo $id ?>"/>

            <div id="wysija_form_container" valign="top" class="margin_vertical_10">
                <label for="gf_wysija_form" class="left_header"><?php _e("Gravity Form", "gravity-forms-wysija"); ?> <?php gform_tooltip("wysija_gravity_form") ?></label>

                <select id="gf_wysija_form" name="gf_wysija_form" onchange="SelectForm(jQuery('#gf_wysija_list').val(), jQuery(this).val());">
                <option value=""><?php _e("Select a form", "gravity-forms-wysija"); ?> </option>
                <?php
                $forms = RGFormsModel::get_forms();
                foreach($forms as $form){
                    $selected = absint($form->id) == rgar($config,"form_id") ? "selected='selected'" : "";
                    ?>
                    <option value="<?php echo absint($form->id) ?>"  <?php echo $selected ?>><?php echo esc_html($form->title) ?></option>
                    <?php
                }
                ?>
                </select>
                &nbsp;&nbsp;
                <img src="<?php echo GFWysija::get_base_url() ?>/images/loading.gif" id="wysija_wait" style="display: none;"/>
            </div>
            <div id="wysija_field_group" valign="top" <?php echo empty($config["meta"]["lists"]) || empty($config["form_id"]) ? "style='display:none;'" : "" ?>>
                <div id="wysija_field_container" valign="top" class="margin_vertical_10" >
                    <label for="wysija_fields" class="left_header"><?php _e("Map Fields", "gravity-forms-wysija"); ?> <?php gform_tooltip("wysija_map_fields") ?></label>

                    <div id="wysija_field_list">
                    <?php
                    if(!empty($config["form_id"])){

                        //getting list of all Wysija details for the selected newsletter
                    	$details = self::get_wysija_details();

                        //getting field map UI
                        echo self::get_field_mapping($config, $config["form_id"], $details);

                        //getting list of selection fields to be used by the optin
                        $form_meta = RGFormsModel::get_form_meta($config["form_id"]);
                    }
                    ?>
                    </div>
                </div>
	            <div class="margin_vertical_10">
	                <label for="gf_wysija_list" class="left_header"><?php _e("Wysija Lists", "gravity-forms-wysija"); ?> <?php gform_tooltip("wysija_contact_list") ?></label>
	                <?php
	
	                //global wysija settings
	                $settings = get_option("gf_wysija_settings");
	
	                //getting all Wysija Newsletters
	                $lists = self::get_wysija_lists();
	             
	                if (!$lists):
	                    echo __("Could not load Wysija lists.", "gravity-forms-wysija");
	                    self::log_debug("Could not load Wysija lists.");
	                else:
	                    foreach ($lists as $l):
	                        $checked = in_array($l['list_id'], $config['meta']['lists']) ? "checked='checked'" : "";
	                        ?>
	                        <label class="wysija_checkbox"><input type="checkbox" name="gf_wysija_lists[]" value="<?php echo $l['list_id'] ?>" <?php echo $checked ?>> <?php echo $l['name'] ?></label>
	                        <?php
	                    endforeach;
	                endif;
	                ?>
	            </div>

                <div id="wysija_optin_container" valign="top" class="margin_vertical_10">
                    <label for="wysija_optin" class="left_header"><?php _e("Opt-In Condition", "gravity-forms-wysija"); ?> <?php gform_tooltip("wysija_optin_condition") ?></label>
                    <div id="wysija_optin">
                        <table>
                            <tr>
                                <td>
                                    <input type="checkbox" id="wysija_optin_enable" name="wysija_optin_enable" value="1" onclick="if(this.checked){jQuery('#wysija_optin_condition_field_container').show('slow');} else{jQuery('#wysija_optin_condition_field_container').hide('slow');}" <?php echo rgar($config["meta"],"optin_enabled") ? "checked='checked'" : ""?>/>
                                    <label for="wysija_optin_enable"><?php _e("Enable", "gravity-forms-wysija"); ?></label>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <div id="wysija_optin_condition_field_container" <?php echo !rgar($config["meta"],"optin_enabled") ? "style='display:none'" : ""?>>
                                        <div id="wysija_optin_condition_fields" style="display:none">
                                            <?php _e("Export to Wysija if ", "gravity-forms-wysija") ?>
                                            <select id="wysija_optin_field_id" name="wysija_optin_field_id" class='optin_select' onchange='jQuery("#wysija_optin_value_container").html(GetFieldValues(jQuery(this).val(), "", 20));'></select>
                                            <select id="wysija_optin_operator" name="wysija_optin_operator" >
                                                <option value="is" <?php echo rgar($config["meta"], "optin_operator") == "is" ? "selected='selected'" : "" ?>><?php _e("is", "gravity-forms-wysija") ?></option>
                                                <option value="isnot" <?php echo rgar($config["meta"], "optin_operator") == "isnot" ? "selected='selected'" : "" ?>><?php _e("is not", "gravity-forms-wysija") ?></option>
                                                <option value=">" <?php echo rgar($config['meta'], 'optin_operator') == ">" ? "selected='selected'" : "" ?>><?php _e("greater than", "gravity-forms-wysija") ?></option>
                                                <option value="<" <?php echo rgar($config['meta'], 'optin_operator') == "<" ? "selected='selected'" : "" ?>><?php _e("less than", "gravity-forms-wysija") ?></option>
                                                <option value="contains" <?php echo rgar($config['meta'], 'optin_operator') == "contains" ? "selected='selected'" : "" ?>><?php _e("contains", "gravity-forms-wysija") ?></option>
                                                <option value="starts_with" <?php echo rgar($config['meta'], 'optin_operator') == "starts_with" ? "selected='selected'" : "" ?>><?php _e("starts with", "gravity-forms-wysija") ?></option>
                                                <option value="ends_with" <?php echo rgar($config['meta'], 'optin_operator') == "ends_with" ? "selected='selected'" : "" ?>><?php _e("ends with", "gravity-forms-wysija") ?></option>
                                            </select>
                                            <div id="wysija_optin_value_container" name="wysija_optin_value_container" style="display:inline;"></div>
                                        </div>
                                        <div id="wysija_optin_condition_message" style="display:none">
                                            <?php _e( 'To create an Opt-In condition, your form must have a field supported by conditional logic.', 'gravity-forms-wysija' ) ?>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <script type="text/javascript">
                        <?php
                        if(!empty($config["form_id"])){
                            ?>
                            //creating Javascript form object
                            form = <?php echo GFCommon::json_encode($form_meta)?> ;

                            //initializing drop downs
                            jQuery(document).ready(function(){
                                var selectedField = "<?php echo str_replace('"', '\"', $config["meta"]["optin_field_id"])?>";
                                var selectedValue = "<?php echo str_replace('"', '\"', $config["meta"]["optin_value"])?>";
                                SetOptin(selectedField, selectedValue);

                            });
                        <?php
                        }
                        ?>
                    </script>
                </div>
                <?php /* Hide Options for now
                <div id="wysija_options_container" valign="top" class="margin_vertical_10">
                    <label for="wysija_options" class="left_header"><?php _e("Options", "gravity-forms-wysija"); ?></label>
                    <div id="wysija_options">
                        <table>
                            <tr><td><input type="checkbox" name="wysija_double_optin" id="wysija_double_optin" value="1" <?php echo rgar($config["meta"],"double_optin") ? "checked='checked'" : "" ?> onclick="var element = jQuery('#wysija_doubleoptin_warning'); if(this.checked){element.hide('slow');} else{element.show('slow');}"/> <?php _e("Double Opt-In" , "gravity-forms-wysija") ?>  <?php gform_tooltip("wysija_double_optin") ?> <br/><span id='wysija_doubleoptin_warning' <?php echo rgar($config["meta"], "double_optin") ? "style='display:none'" : "" ?>>(<?php _e("Abusing this may cause your Wysija account to be suspended.", "gravity-forms-wysija") ?>)</span></td></tr>
                            <tr><td><input type="checkbox" name="wysija_welcome_email" id="wysija_welcome_email" value="1" <?php echo rgar($config["meta"],"welcome_email") ? "checked='checked'" : "" ?>/> <?php _e("Send Welcome Email" , "gravity-forms-wysija") ?> <?php gform_tooltip("wysija_welcome") ?></td></tr>
                        </table>
                    </div>
                </div>
                */?>
                <div id="wysija_submit_container" class="margin_vertical_10">
                    <input type="submit" name="gf_wysija_submit" value="<?php echo empty($id) ? __("Save", "gravity-forms-wysija") : __("Update", "gravity-forms-wysija"); ?>" class="button-primary"/>
                    <input type="button" value="<?php _e("Cancel", "gravity-forms-wysija"); ?>" class="button" onclick="javascript:document.location='admin.php?page=gf_wysija'" />
                </div>
            </div>
        </form>
        </div>
        <script type="text/javascript">

            function SelectList(listId){
                if(listId){
                    jQuery("#wysija_form_container").slideDown();
                    jQuery("#gf_wysija_form").val("");
                }
                else{
                    jQuery("#wysija_form_container").slideUp();
                    EndSelectForm("");
                }
            }

            function SelectForm(listId, formId){
                if(!formId){
                    jQuery("#wysija_field_group").slideUp();
                    return;
                }

                jQuery("#wysija_wait").show();
                jQuery("#wysija_field_group").slideUp();

                var mysack = new sack(ajaxurl);
                mysack.execute = 1;
                mysack.method = 'POST';
                mysack.setVar( "action", "gf_select_wysija_form" );
                mysack.setVar( "gf_select_wysija_form", "<?php echo wp_create_nonce("gf_select_wysija_form") ?>" );
                mysack.setVar( "list_id", listId);
                mysack.setVar( "form_id", formId);
                mysack.encVar( "cookie", document.cookie, false );
                mysack.onError = function() {jQuery("#wysija_wait").hide(); alert('<?php _e("Ajax error while selecting a form", "gravity-forms-wysija") ?>' )};
                mysack.runAJAX();

                return true;
            }

            function SetOptin(selectedField, selectedValue){

                //load form fields
                jQuery("#wysija_optin_field_id").html(GetSelectableFields(selectedField, 20));
                var optinConditionField = jQuery("#wysija_optin_field_id").val();

                if(optinConditionField){
                    jQuery("#wysija_optin_condition_message").hide();
                    jQuery("#wysija_optin_condition_fields").show();
                    jQuery("#wysija_optin_value_container").html(GetFieldValues(optinConditionField, selectedValue, 20));
                    jQuery("#wysija_optin_value").val(selectedValue);
                }
                else{
                    jQuery("#wysija_optin_condition_message").show();
                    jQuery("#wysija_optin_condition_fields").hide();
                }
            }

            function EndSelectForm(fieldList, form_meta, grouping, groups){
                //setting global form object
                form = form_meta;
                if(fieldList){

                    SetOptin("","");

                    jQuery("#wysija_field_list").html(fieldList);
                    jQuery("#wysija_groupings").html(grouping);

                    for(var i in groups)
                        SetGroupCondition(groups[i]["main"], groups[i]["sub"],"","");

                    //initializing wysija group tooltip
                    jQuery('.tooltip_wysija_groups').qtip({
                         content: jQuery('.tooltip_wysija_groups').attr('tooltip'), // Use the tooltip attribute of the element for the content
                         show: { delay: 500, solo: true },
                         hide: { when: 'mouseout', fixed: true, delay: 200, effect: 'fade' },
                         style: "gformsstyle",
                         position: {
                          corner: {
                               target: "topRight",
                               tooltip: "bottomLeft"
                               }
                          }
                      });

                    jQuery("#wysija_field_group").slideDown();

                }
                else{
                    jQuery("#wysija_field_group").slideUp();
                    jQuery("#wysija_field_list").html("");
                }
                jQuery("#wysija_wait").hide();
            }

            function GetFieldValues(fieldId, selectedValue, labelMaxCharacters, inputName){
                if(!inputName){
                    inputName = 'wysija_optin_value';
                }

                if(!fieldId)
                    return "";

                var str = "";
                var field = GetFieldById(fieldId);
                if(!field)
                    return "";

                var isAnySelected = false;

                if(field["type"] == "post_category" && field["displayAllCategories"]){
					str += '<?php $dd = wp_dropdown_categories(array("class"=>"optin_select", "orderby"=> "name", "id"=> "wysija_optin_value", "name"=> "wysija_optin_value", "hierarchical"=>true, "hide_empty"=>0, "echo"=>false)); echo str_replace("\n","", str_replace("'","\\'",$dd)); ?>';
				}
				else if(field.choices){
					str += '<select id="' + inputName +'" name="' + inputName +'" class="optin_select">';

	                for(var i=0; i<field.choices.length; i++){
	                    var fieldValue = field.choices[i].value ? field.choices[i].value : field.choices[i].text;
	                    var isSelected = fieldValue == selectedValue;
	                    var selected = isSelected ? "selected='selected'" : "";
	                    if(isSelected)
	                        isAnySelected = true;

	                    str += "<option value='" + fieldValue.replace(/'/g, "&#039;") + "' " + selected + ">" + TruncateMiddle(field.choices[i].text, labelMaxCharacters) + "</option>";
	                }

	                if(!isAnySelected && selectedValue){
	                    str += "<option value='" + selectedValue.replace(/'/g, "&#039;") + "' selected='selected'>" + TruncateMiddle(selectedValue, labelMaxCharacters) + "</option>";
	                }
	            	str += "</select>";
				}
				else
				{
					selectedValue = selectedValue ? selectedValue.replace(/'/g, "&#039;") : "";
					//create a text field for fields that don't have choices (i.e text, textarea, number, email, etc...)
					str += "<input type='text' placeholder='<?php _e( "Enter value", "gravity-forms-wysija" ); ?>' id='" + inputName + "' name='" + inputName +"' value='" + selectedValue.replace(/'/g, "&#039;") + "'>";
				}

                return str;
            }

            function GetFieldById(fieldId){
                for(var i=0; i<form.fields.length; i++){
                    if(form.fields[i].id == fieldId)
                        return form.fields[i];
                }
                return null;
            }

            function TruncateMiddle(text, maxCharacters){
                if(text.length <= maxCharacters)
                    return text;
                var middle = parseInt(maxCharacters / 2);
                return text.substr(0, middle) + "..." + text.substr(text.length - middle, middle);
            }

            function GetSelectableFields(selectedFieldId, labelMaxCharacters){
                var str = "";
                var inputType;

                for(var i=0; i<form.fields.length; i++){
                    fieldLabel = form.fields[i].adminLabel ? form.fields[i].adminLabel : form.fields[i].label;
                    inputType = form.fields[i].inputType ? form.fields[i].inputType : form.fields[i].type;
                    if (IsConditionalLogicField(form.fields[i])) {
                        var selected = form.fields[i].id == selectedFieldId ? "selected='selected'" : "";
                        str += "<option value='" + form.fields[i].id + "' " + selected + ">" + TruncateMiddle(fieldLabel, labelMaxCharacters) + "</option>";
                    }
                }
                return str;
            }

            function IsConditionalLogicField(field){
			    inputType = field.inputType ? field.inputType : field.type;
			    var supported_fields = ["checkbox", "radio", "select", "text", "website", "textarea", 
			    "email", "hidden", "number", "phone", "multiselect", "post_title",
			                            "post_tags", "post_custom_field", "post_content", "post_excerpt"];

			    var index = jQuery.inArray(inputType, supported_fields);

			    return index >= 0;
			}

        </script>

        <?php

    }
	public static function get_wysija_lists()
	{
		$data = array('name','list_id');
		$conditions = array('is_enabled'=>1);
		$modelList = &WYSIJA::get('list','model');

		return $modelList->get($data, $conditions);
	}

    public static function add_permissions(){
        global $wp_roles;
        $wp_roles->add_cap("administrator", "gravityforms_wysija");
        $wp_roles->add_cap("administrator", "gravityforms_wysija_uninstall");
    }

    public static function selected($selected, $current){
        return $selected === $current ? " selected='selected'" : "";
    }

    //Target of Member plugin filter. Provides the plugin with Gravity Forms lists of capabilities
    public static function members_get_capabilities( $caps ) {
        return array_merge($caps, array("gravityforms_wysija", "gravityforms_wysija_uninstall"));
    }

    public static function disable_wysija(){
        delete_option("gf_wysija_settings");
    }

    public static function select_wysija_form(){

        check_ajax_referer("gf_select_wysija_form", "gf_select_wysija_form");
        $form_id =  intval(rgpost("form_id"));
        $setting_id =  intval(rgpost("setting_id"));

        //getting list of all Wysija details for the selected contact list
        $details = self::get_wysija_details();

        //getting configuration
        $config = GFWysijaData::get_feed($setting_id);

        //getting field map UI
        $field_map = self::get_field_mapping($config, $form_id, $details);
        
        // Escape quotes and strip extra whitespace and line breaks
        $field_map = str_replace("'","\'",$field_map);
		//$field_map = preg_replace('/[ \t]+/', ' ', preg_replace('/\s*$^\s*/m', "\n", $field_map));
        
		//self::log_debug("Field map is set to: " . $field_map);
        
        //getting list of selection fields to be used by the optin
        $form_meta = RGFormsModel::get_form_meta($form_id);
        $selection_fields = GFCommon::get_selection_fields($form_meta, rgars($config, "meta/optin_field_id"));
        $group_condition = array();
        $group_names = array();
        $grouping = '';
        
        //fields meta
        $form = RGFormsModel::get_form_meta($form_id);
        die("EndSelectForm('".$field_map."', ".GFCommon::json_encode($form).", '" . str_replace("'", "\'", $grouping) . "', " . json_encode($group_names) . " );");
    }

    private static function get_field_mapping($config, $form_id, $details){
	    	    
        //getting list of all fields for the selected form
        $form_fields = self::get_form_fields($form_id);

        $str = "<table cellpadding='0' cellspacing='0'><tr><td class='wysija_col_heading'>" . __("Wysija Fields", "gravity-forms-wysija") . "</td><td class='wysija_col_heading'>" . __("Form Fields", "gravity-forms-wysija") . "</td></tr>";
        
        if(!isset($config["meta"]))
            $config["meta"] = array("field_map" => "", 'list_id'=>'');
		
		foreach( $details as $k=>$v )
		{
			$selected_field = rgar($config["meta"]["field_map"], $k);
			$required = isset($v['required']) ? "<span class='gfield_required'>*</span>" : '';
			$error_class = isset($v['required']) && empty($selected_field) && !empty($_POST["gf_wysija_submit"]) ? " feeds_validation_error" : "";
			$str .= "<tr class='$error_class'><td class='wysija_field_cell'>".$v['name']." $required</td><td class='wysija_field_cell'>".self::get_mapped_field_list($k, $selected_field, $form_fields)."</td></tr>";
		}
		
        $str .= "</table>";

        return $str;
    }

    public static function get_form_fields($form_id){
        $form = RGFormsModel::get_form_meta($form_id);
        $fields = array();

        //Adding default fields
        array_push($form["fields"],array("id" => "date_created" , "label" => __("Entry Date", "gravity-forms-wysija")));
        array_push($form["fields"],array("id" => "ip" , "label" => __("User IP", "gravity-forms-wysija")));
        array_push($form["fields"],array("id" => "source_url" , "label" => __("Source Url", "gravity-forms-wysija")));
        array_push($form["fields"],array("id" => "form_title" , "label" => __("Form Title", "gravity-forms-wysija")));
        $form = self::get_entry_meta($form);
        if(is_array($form["fields"])){
            foreach($form["fields"] as $field){
                if(is_array(rgar($field, "inputs"))){

                    //If this is an address field, add full name to the list
                    if(RGFormsModel::get_input_type($field) == "address")
                        $fields[] =  array($field["id"], GFCommon::get_label($field) . " (" . __("Full" , "gravity-forms-wysija") . ")");

                    foreach($field["inputs"] as $input)
                        $fields[] =  array($input["id"], GFCommon::get_label($field, $input["id"]));
                }
                else if(!rgar($field,"displayOnly")){
                    $fields[] =  array($field["id"], GFCommon::get_label($field));
                }
            }
        }
        return $fields;
    }

    private static function get_entry_meta($form){
        $entry_meta = GFFormsModel::get_entry_meta($form["id"]);
        $keys = array_keys($entry_meta);
        foreach ($keys as $key){
            array_push($form["fields"],array("id" => $key , "label" => $entry_meta[$key]['label']));
        }
        return $form;
    }

    private static function get_address($entry, $field_id){
        $street_value = str_replace("  ", " ", trim($entry[$field_id . ".1"]));
        $street2_value = str_replace("  ", " ", trim($entry[$field_id . ".2"]));
        $city_value = str_replace("  ", " ", trim($entry[$field_id . ".3"]));
        $state_value = str_replace("  ", " ", trim($entry[$field_id . ".4"]));
        $zip_value = trim($entry[$field_id . ".5"]);
        $country_value = GFCommon::get_country_code(trim($entry[$field_id . ".6"]));

        $address = $street_value;
        $address .= !empty($address) && !empty($street2_value) ? "  $street2_value" : $street2_value;
        $address .= !empty($address) && (!empty($city_value) || !empty($state_value)) ? "  $city_value" : $city_value;
        $address .= !empty($address) && !empty($city_value) && !empty($state_value) ? "  $state_value" : $state_value;
        $address .= !empty($address) && !empty($zip_value) ? "  $zip_value" : $zip_value;
        $address .= !empty($address) && !empty($country_value) ? "  $country_value" : $country_value;

        return $address;
    }

    public static function get_mapped_field_list($variable_name, $selected_field, $fields){
        $field_name = "wysija_map_field_" . $variable_name;
        $str = "<select name='$field_name' id='$field_name'><option value=''></option>";
        foreach($fields as $field){
            $field_id = $field[0];
            $field_label = esc_html(GFCommon::truncate_middle($field[1], 40));

            $selected = $field_id == $selected_field ? "selected='selected'" : "";
            $str .= "<option value='" . $field_id . "' ". $selected . ">" . $field_label . "</option>";
        }
        $str .= "</select>";
        return $str;
    }

    public static function export($entry, $form, $is_fulfilled = false){

        //Make sure Wysija exists
        if( !class_exists("WYSIJA") )
            return;

        //loading data class
        require_once(self::get_base_path() . "/inc/data.php");

        //getting all active feeds
        $feeds = GFWysijaData::get_feed_by_form($form["id"], true);
        foreach($feeds as $feed){
            //only export if user has opted in
            if(self::is_optin($form, $feed, $entry))
            {
				self::export_feed($entry, $form, $feed);
                //updating meta to indicate this entry has already been subscribed to Wysija. This will be used to prevent duplicate subscriptions.
        		self::log_debug("Marking entry " . $entry["id"] . " as subscribed");
        		gform_update_meta($entry["id"], "wysija_is_subscribed", true);
			}
			else
			{
				self::log_debug("Opt-in condition not met; not subscribing entry " . $entry["id"] . " to list");
			}
        }
    }

    public static function has_wysija($form_id){
        if(!class_exists("GFWysijaData"))
            require_once(self::get_base_path() . "/inc/data.php");

        //Getting settings associated with this form
        $config = GFWysijaData::get_feed_by_form($form_id);

        if(!$config)
            return false;

        return true;
    }
    
    // Magic goes here
    public static function export_feed($entry, $form, $feed){
	    
		$double_optin = $feed["meta"]["double_optin"] ? true : false;
        $send_welcome = $feed["meta"]["welcome_email"] ? true : false;
        $email_field_id = $feed["meta"]["field_map"]["email"];

        // Build parameter list of questions and values
		$params = array(
			'lists' => $feed['meta']['lists'],
		);
        
        foreach( $feed['meta']['field_map'] as $k => $v ){
    		$field = RGFormsModel::get_field($form, $v);
			$params[$k] = apply_filters("gform_wysija_field_value", rgar($entry, $v), $form['id'], $v, $entry);
        }
        
        //self::log_debug('Params are: '.print_r($params, true));
        
        // Send info to Wysija
		$data = array(
			'user'      => array(
				'email' => $params['email'],
				'firstname' => $params['first_name'],
				'lastname' => $params['last_name'],
			),
			'user_list' => array(
				'list_ids' => $params['lists'],
			)
		);
		
		$create_user = &WYSIJA::get('user','helper');
		$create_user->addSubscriber($data);
		
		// Should be done now
    }

	public static function plugin_row()
	{
		if ( ! self::is_gravityforms_supported() || ! self::is_wysija_installed() )
		{
			$message = sprintf( __( '%sGravity Forms%s 1.6.10 is required. Activate it now or %spurchase it today!%s', 'gravity-forms-wysija' ), '<a href="http://benjaminhays.com/gravityforms">', '</a>', '<a href="http://benjaminhays.com/gravityforms">', '</a>' );
			$message .= '<br/>'.sprintf( __( 'Wysija Newsletters plugin is required for this to work. %sDownload it now.%s', 'gravity-forms-wysija' ), '<a href="http://wordpress.org/extend/plugins/wysija-newsletters/">', '</a>' );
			self::display_plugin_message( $message, true );
		}
    }

	public static function display_plugin_message($message, $is_error = false)
	{
		$style = '';
		if($is_error)
		{
			$style = 'style="background-color: #ffebe8;"';
		}
		echo '</tr><tr class="plugin-update-tr"><td colspan="5" class="plugin-update"><div class="update-message" ' . $style . '>' . $message . '</div></td>';
	}

    public static function uninstall(){

        //loading data lib
        require_once(self::get_base_path() . "/inc/data.php");

        if(!GFWysija::has_access("gravityforms_wysija_uninstall"))
            die(__("You don't have adequate permission to uninstall Wysija Add-On.", "gravity-forms-wysija"));

        //droping all tables
        GFWysijaData::drop_tables();

        //removing options
        delete_option("gf_wysija_settings");
        delete_option("gf_wysija_version");

        //Deactivating plugin
        $plugin = "gravity-forms-wysija/wysija.php";
        deactivate_plugins($plugin);
        update_option('recently_activated', array($plugin => time()) + (array)get_option('recently_activated'));
    }

    public static function is_optin($form, $settings, $entry){
        $config = $settings["meta"];

        $field = RGFormsModel::get_field($form, $config["optin_field_id"]);

        if(empty($field) || !$config["optin_enabled"])
            return true;

        $operator = isset($config["optin_operator"]) ? $config["optin_operator"] : "";
        $field_value = RGFormsModel::get_field_value($field, array());
        $is_value_match = RGFormsModel::is_value_match($field_value, $config["optin_value"], $operator);
        $is_visible = !RGFormsModel::is_field_hidden($form, $field, array(), $entry);

        $is_optin = $is_value_match && $is_visible;

        return $is_optin;

    }
    
    private static function get_wysija_details()
    {
		$ret = array(
			'first_name' => array('name' => 'First Name'),
			'last_name'  => array('name' => 'Last Name'),
			'email'      => array('name' => 'Email', 'required' => 1),
		);

		return $ret;
    }
    
    private static function is_gravityforms_installed(){
        return class_exists("RGForms");
    }

    private static function is_gravityforms_supported(){
        if(class_exists("GFCommon")){
            $is_correct_version = version_compare(GFCommon::$version, self::$min_gravityforms_version, ">=");
            return $is_correct_version;
        }
        else{
            return false;
        }
    }

    private static function is_wysija_installed(){
        return class_exists("WYSIJA");
    }

    protected static function has_access($required_permission){
        $has_members_plugin = function_exists('members_get_capabilities');
        $has_access = $has_members_plugin ? current_user_can($required_permission) : current_user_can("level_7");
        if($has_access)
            return $has_members_plugin ? $required_permission : "level_7";
        else
            return false;
    }
	
	// Clean strings from Wysija, we don't need any HTML or line breaks 
    protected function ws_clean($string){
	    $chars = array("
", "\n", "\r", "chr(13)",  "\t", "\0", "\x0B");
	    $string = str_replace($chars, '', trim(strip_tags($string)));
	    return $string;
    }
    
    //Returns the url of the plugin's root folder
    protected function get_base_url(){
        return plugins_url(null, __FILE__);
    }

    //Returns the physical path of the plugin's root folder
    protected function get_base_path(){
        $folder = basename(dirname(__FILE__));
        return WP_PLUGIN_DIR . "/" . $folder;
    }

    function set_logging_supported($plugins)
	{
		$plugins[self::$slug] = "Wysija";
		return $plugins;
	}

    private static function log_error($message){
        if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::ERROR);
        }
    }

    private static function log_debug($message){
		if(class_exists("GFLogging"))
        {
            GFLogging::include_logger();
            GFLogging::log_message(self::$slug, $message, KLogger::DEBUG);
        }
    }
}

if(!function_exists("rgget")){
function rgget($name, $array=null){
    if(!isset($array))
        $array = $_GET;

    if(isset($array[$name]))
        return $array[$name];

    return "";
}
}

if(!function_exists("rgpost")){
function rgpost($name, $do_stripslashes=true){
    if(isset($_POST[$name]))
        return $do_stripslashes ? stripslashes_deep($_POST[$name]) : $_POST[$name];

    return "";
}
}

if(!function_exists("rgar")){
function rgar($array, $name){
    if(isset($array[$name]))
        return $array[$name];

    return '';
}
}

if(!function_exists("rgempty")){
function rgempty($name, $array = null){
    if(!$array)
        $array = $_POST;

    $val = rgget($name, $array);
    return empty($val);
}
}

if(!function_exists("rgblank")){
function rgblank($text){
    return empty($text) && strval($text) != "0";
}
}

?>