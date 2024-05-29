<?php

class Static_Sitemap_Generator_Loader {

    /**
     * Enable the sitemap plugin with registering all required hooks
     */
    public static function enable() {

        // Register the sitemap creator to WordPress.
        add_action('admin_menu', array(__CLASS__, 'register_admin_page'));

        // Add a widget to the dashboard.
        add_action('wp_dashboard_setup', array(__CLASS__, 'wp_dashboard_setup'));

        // Additional links on the plugin page.
        add_filter('plugin_row_meta', array(__CLASS__, 'register_plugin_links'), 10, 2);

        // Register the cron job
        Static_Sitemap_Cron::register_cron_job();

        $sg = new Static_Sitemap_Generator();
    }	

    /**
     * Handle the plugin activation on installation.
     */
    public static function activate_plugin() {
        Static_Sitemap_Cron::schedule_sitemap_cron();
        // any other Activation code here
    }

    /**
     * Handle the plugin deactivation.
     */
    public static function deactivate_plugin() {
        Static_Sitemap_Cron::clear_sitemap_cron();
        // any other Deactivation code here
    }
    

    /**
     * Registers the plugin in the admin menu system
     */
    public static function register_admin_page() {
        add_options_page(
            __('Static XML Sitemap', 'sitemap'),
            __('Static XML Sitemap', 'sitemap'),
            'administrator',
            self::get_base_name(),
            array(__CLASS__, 'call_html_show_options_page')
        );
    }

    /**
     * Add a widget to the dashboard.
     */
    public static function wp_dashboard_setup() {
        self::load_plugin();
    }
	
    /**
     * Return a link pointing back to the plugin page in WordPress.
     *
     * @param string $extra Optional extra query string.
     * @return string The full URL.
     */
    public static function get_back_link($extra = '') {
        $url = admin_url("options-general.php?page=" . self::get_base_name() . $extra);
        return $url;
    }

    /**
     * Invoke the HtmlShowOptionsPage method of the generator.
     */
    public static function call_html_show_options_page() {
        if (self::load_plugin()) {
            $sg = Static_Sitemap_Generator::get_instance();
            $sg->render_form();
        }
    }

    /**
     * Load the actual generator class and try to raise the memory and time limits if not already done by WP.
     *
     * @return boolean True if run successfully.
     */
    public static function load_plugin() {

        if (!class_exists("Static_Sitemap_Generator")) {
            $mem = absint(@ini_get('memory_limit'));
            if ($mem && $mem < 128) {
                @ini_set('memory_limit', '128M');
            }

            $time = absint(@ini_get("max_execution_time"));
            if ($time != 0 && $time < 120) {
                @set_time_limit(120);
            }

            $path = trailingslashit(dirname(__FILE__));
        }

        Static_Sitemap_Generator::get_instance();
        return true;
    }

    

    /**
     * Register additional links for the sitemap plugin on the WP plugin configuration page.
     *
     * Registers the links if the $file param equals to the sitemap plugin.
     *
     * @param array $links An array with the existing links.
     * @param string $file The file to compare to.
     * @return array Modified array of links.
     */
    public static function register_plugin_links($links, $file) {
        $base = self::get_base_name();
        if ($file == $base) {
            $links[] = '<a href="options-general.php?page=' . self::get_base_name() . '">' . __('Settings', 'sitemap') . '</a>';
        }
        return $links;
    }

    /**
     * Return the plugin basename of the plugin (using __FILE__).
     *
     * @return string The plugin basename.
     */
    public static function get_base_name() {
        return plugin_basename(prox_get_init_file());
    }


}
//Enable the plugin for the init hook, but only if WP is loaded. Calling this php file directly will do nothing.
if(defined('ABSPATH') && defined('WPINC')) {
	add_action("init", array("Static_Sitemap_Generator_Loader", "Enable"), 15, 0);
	register_activation_hook(prox_get_init_file(), array('Static_Sitemap_Generator_Loader', 'activate_plugin'));
	register_deactivation_hook(prox_get_init_file(), array('Static_Sitemap_Generator_Loader', 'deactivate_plugin'));
}

