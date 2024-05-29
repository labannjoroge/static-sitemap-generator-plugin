<?php
class Static_Sitemap_Cron {

    /**
     * Register the cron job
     */
    public static function register_cron_job() {
        add_action('generate_sitemap_cron_event', array(__CLASS__, 'generate_sitemap_cron_job'));
    }

    /**
     * Schedule the sitemap cron job
     */
    public static function schedule_sitemap_cron() {
        $sg = Static_Sitemap_Generator::get_instance();
        $interval = !empty($sg->get_option('prox_cron_interval')) ? $sg->get_option('prox_cron_interval') * 60 : 86400; // Default to 24 hours if not set

        if (wp_next_scheduled('generate_sitemap_cron')) {
            wp_clear_scheduled_hook('generate_sitemap_cron');
        }

        wp_schedule_event(time(), $interval, 'generate_sitemap_cron');
    }

    /**
     * Cron job function to generate the sitemap
     */
    public static function generate_sitemap_cron_job() {
        $sg = Static_Sitemap_Generator::get_instance();
        $sg->generate_sitemap();
    }

    /**
     * Clear the sitemap cron job
     */
    public static function clear_sitemap_cron() {
        wp_clear_scheduled_hook('generate_sitemap_cron');
    }
}

// Hook the sitemap generation function to our cron event
add_action('generate_sitemap_cron', array('Static_Sitemap_Generator', 'generate_sitemap'));
