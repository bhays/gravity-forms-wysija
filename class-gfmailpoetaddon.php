<?php

GFForms::include_feed_addon_framework();

class GFMailPoetAddOn extends GFFeedAddOn {
	protected $_version = GF_WYSIJA_ADDON_VERSION;
	protected $_min_gravityforms_version = '1.9.16';
	protected $_slug = 'gravity-forms-wysija';
	protected $_path = 'gravity-forms-wysija/gravity-forms-wysija.php';
	protected $_full_path = __FILE__;
	protected $_title = 'Gravity Forms MailPoet Add-On';
	protected $_short_title = 'MailPoet';

	private static $_instance = null;

	/**
	 * Get an instance of this class.
	 *
	 * @return GFSimpleFeedAddOn
	 */
	public static function get_instance() {
		if ( self::$_instance == null ) {
			self::$_instance = new GFMailPoetAddOn();
		}

		return self::$_instance;
	}

	public function init() {

		parent::init();

		// Supports logging
		add_filter('gform_logging_supported', array($this, 'set_logging_supported'));

		if( basename($_SERVER['PHP_SELF']) == "plugins.php" ) {
            //loading translations
            load_plugin_textdomain('gravity-forms-wysija', FALSE, dirname( plugin_basename( __FILE__ ) ) . '/languages');
        }

        // Hide plugin_page if already shown
        if( get_option('gf_mailpoet_plugin_page') ){
			add_filter('gform_addon_navigation', array($this, 'remove_plugin_page_menu'));
		}
	}

	/**
	 * Create a custom page to explain the upgrade process
	 */
	public function plugin_page() {
		// Set option to only display plugin page once
		update_option('gf_mailpoet_plugin_page', true);
		echo '<h3>'.__('Where did my feeds go?', 'gravity-forms-wysija').'</h3>';
		echo '<p>'.__('Your feeds for MailPoet can now be found under each form, under <strong>Form Settings -> MailPoet</strong>.', 'gravity-forms-wysija'). '</p>';
		echo '<p><a class="button-primary" href="'.admin_url('?page=gf_edit_forms&view=settings&subview=gravity-forms-wysija').'">'.__('Add your feeds now', 'gravity-forms-wysija').'</a></p>';
	}

	/**
	 * Remove plugin page from menu
	 */
	public function remove_plugin_page_menu($menu){
		foreach( $menu as $k=>$v ){
			if( $v['name'] == 'gravity-forms-wysija' ){
				unset($menu[$k]);
				return $menu;
			}
		}
		return $menu;
	}

	/**
	 * Add subscriber info to the desired lists when submission is complete.
	 */
	public function process_feed( $feed, $entry, $form ) {
		if( !$this->is_mailpoet_installed() ){
			return;
		}

		$feedName  = $feed['meta']['feedname'];

		// Get out of here if no lists are specified
		if( !is_array($feed['meta']['mailpoetlist']) ){
			return;
		}
		$mailpoetlists = array_keys(array_filter($feed['meta']['mailpoetlist']));

		// Retrieve the name => value pairs for all fields mapped in the 'mappedfields' field map.
		$field_map = $this->get_field_map_fields( $feed, 'mappedfields' );

		// Loop through the fields from the field map setting building an array of values to be passed to the third-party service.
		$merge_vars = array();
		foreach ( $field_map as $name => $field_id ) {

			// Get the field value for the specified field id
			$merge_vars[ $name ] = $this->get_field_value( $form, $entry, $field_id );

		}

		if( empty($merge_vars['email']) ){
			return;
		}

		// Send info to MailPoet
		$data = array(
			'user'      => array(
				'email'     => $merge_vars['email'],
				'firstname' => $merge_vars['first_name'],
				'lastname'  => $merge_vars['last_name'],
			),
			'user_list' => array(
				'list_ids' => $mailpoetlists,
			)
		);

		$user_id = WYSIJA::get('user', 'helper')->addSubscriber($data);
	}

	/**
	 * Configures the settings which should be rendered on the feed edit page in the Form Settings > Simple Feed Add-On area.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$lists = $this->setup_mailpoet_lists_array();

		return array(
			array(
				'title'  => esc_html__( 'MailPoet Feed Settings', 'gravity-forms-wysija' ),
				'fields' => array(
					array(
						'label'   => esc_html__( 'Feed name', 'gravity-forms-wysija' ),
						'type'    => 'text',
						'name'    => 'feedname',
						'class'   => '',
					),
					array(
						'name'      => 'mappedfields',
						'label'     => esc_html__( 'Map Fields', 'gravity-forms-wysija' ),
						'type'      => 'field_map',
						'tooltip'   => esc_html__( 'Associate your MailPoet newsletter questions to the appropriate Gravity Form fields by selecting.', 'gravity-forms-wysija'),
						'field_map' => array(
							array(
								'name'     => 'first_name',
								'label'    => esc_html__( 'First Name', 'gravity-forms-wysija' ),
								'required' => 0,
							),
							array(
								'name'       => 'last_name',
								'label'      => esc_html__( 'Last Name', 'gravity-forms-wysija' ),
								'required'   => 0,
							),
							array(
								'name'       => 'email',
								'label'      => esc_html__( 'Email', 'gravity-forms-wysija' ),
								'required'   => 0,
								'field_type' => array('email', 'hidden'),
							),
						),
					),
					$lists,
					array(
						'name'           => 'condition',
						'label'          => esc_html__( 'Condition', 'gravity-forms-wysija' ),
						'type'           => 'feed_condition',
						'checkbox_label' => esc_html__( 'Enable Condition', 'gravity-forms-wysija' ),
						'instructions'   => esc_html__( 'Process this feed if', 'gravity-forms-wysija' ),
					),
				),
			),
		);
	}

	/**
	 * Configures which columns should be displayed on the feed list page.
	 *
	 * @return array
	 */
	public function feed_list_columns() {
		return array(
			'feedname'       => esc_html__( 'Name', 'gravity-forms-wysija' ),
			'mailpoetlists'  => esc_html__( 'MailPoet Lists', 'gravity-forms-wysija' ),
		);
	}

	/**
	 * Format the value to be displayed in the mailpoetlists column.
	 *
	 * @param array $feed The feed being included in the feed list.
	 *
	 * @return string
	 */
	public function get_column_value_mailpoetlists( $feed ) {
		$feed_list = rgars($feed, 'meta/mailpoetlist');
		$lists = $this->get_mailpoet_lists();
		$list_names = array();
		foreach( $lists as $l ){
			if( array_key_exists($l['list_id'], $feed_list) && $feed_list[$l['list_id']] == 1 ) {
				$list_names[] = $l['name'];
			}
		}
		return implode(', ', $list_names);
	}

	public function get_mailpoet_lists() {
		$data = array('name','list_id');
		$conditions = array('is_enabled' => 1);
		$modelList = WYSIJA::get('list','model');

		return $modelList->get($data, $conditions);
	}

	private function setup_mailpoet_lists_array() {
		$lists = $this->get_mailpoet_lists();

		$list_array = array(
			'name'    => 'mailpoetlists',
			'label'   => esc_html__( 'MailPoet Lists', 'gravity-forms-wysija' ),
			'type'    => 'checkbox',
			'tooltip' => esc_html__( 'Select the MailPoet lists you would like to add your contacts to.', 'gravity-forms-wysija' ),
			'choices' => array(),
		);
		if( !$lists ) {
			self::log_debug("Could not load MailPoet lists.");
			$list_array['choices'][] = array('label' => esc_html__('Could not load MailPoet lists.', 'gravity-forms-wysija'));

		} else {
			foreach ($lists as $l){
				$list_array['choices'][] = array(
					'label' => $l['name'],
					'name' => 'mailpoetlist['.$l['list_id'].']',
				);
			}
		}
		return $list_array;
	}

    private function is_mailpoet_installed(){
        return class_exists('WYSIJA');
    }

}