<?php 
	/**
 * Static Sitemap Generator Class
 *
 * @package StaticSitemapGenerator
 */
class Static_Sitemap_Generator {
    /**
     * Singleton instance of the Static Sitemap Generator
     *
     * @var Static_Sitemap_Generator
     */
    public static $instance = null;

    /**
     * Array to store generated sitemaps
     *
     * @var array
     */
    public $sitemaps = array();

    /**
     * Array to store additional pages
     *
     * @var array
     */
    public $pages = array();

    /**
     * Array to store the values and names of change frequencies
     *
     * @var array
     */
    public $freq_names = array();

    /**
     * Array to store unserialized options
     *
     * @var array
     */
    private $options = array();

    /**
     * Indicates whether the options have been loaded
     *
     * @var bool
     */
    private $options_loaded = false;

    /**
     * Number of URLs per sitemap
     *
     * @var int
     */
    public $urls_per_sitemap = 0;

    /**
     * Static_Sitemap_Generator constructor.
     */
    public function __construct() {
		$path = $this->get_plugin_path();
        $this->freq_names = array(
            'always' => 'Always',
            'hourly' => 'Hourly',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            'yearly' => 'Yearly',
            'never' => 'Never'
        );

        $this->load_options();
        self::$instance = $this;
    }

    /**
     * Get the singleton instance of the Static Sitemap Generator
     *
     * @return Static_Sitemap_Generator
     */
    public static function get_instance() {
        if (!self::$instance) {
            self::$instance = new Static_Sitemap_Generator();
        }
        return self::$instance;
    }

    /**
     * Get the scheme (https or http)
     *
     * @return string
     */
    public function get_scheme() {
        $is_secure = false;
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') {
            $is_secure = true;
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' || !empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] == 'on') {
            $is_secure = true;
        }
        return $is_secure ? 'https' : 'http';
    }

    /**
     * Get the host
     *
     * @return string
     */
    public function get_host() {
        return rtrim($_SERVER['HTTP_HOST'], '/');
    }

    /**
     * Get the URL (scheme + host [+ port if non-standard])
     *
     * @return string
     */
    public function get_url() {
        $url = $this->get_scheme() . '://' . $this->get_host();
        return $url;
    }

    /**
     * Generate the sitemap
     */
    public function generate_sitemap() {
        // Raise memory and time limits
        if ($this->get_option('b_memory') != '') {
            @ini_set('memory_limit', $this->get_option('b_memory'));
        }

        if ($this->get_option('b_time') != -1) {
            @set_time_limit($this->get_option('b_time'));
        }

        $this->build_pages();
        $this->render_sitemap();
    }


	/**
	 * Builds the pages for the sitemap.
	 */
	public function build_pages() {
		/** @var $wpdb wpdb */
		global $wpdb;

		// Query to get published posts and pages
		$query = "
			SELECT
				p.ID,
				p.post_author,
				p.post_status,
				p.post_name,
				p.post_parent,
				p.post_type,
				p.post_date,
				p.post_date_gmt,
				p.post_modified,
				p.post_modified_gmt,
				p.comment_count
			FROM
				{$wpdb->posts} p
			WHERE
				p.post_password = ''
				AND p.post_status = 'publish'
				AND (
					p.post_type = 'post' OR
					p.post_type = 'page'
				)
			ORDER BY
				p.post_date_gmt DESC
		";

		$posts = $wpdb->get_results($query);

		if (($post_count = count($posts)) > 0) {
			// Default priorities
			$default_priority_posts = $this->get_option('pr_posts');
			$default_priority_pages = $this->get_option('pr_pages');

			// Minimum priority
			$minimum_priority = $this->get_option('pr_posts_min');

			// Change frequencies
			$change_freq_posts = $this->get_option('cf_posts');
			$change_freq_pages = $this->get_option('cf_pages');

			// Home page handling
			$home_pid = 0;
			$home_url = get_home_url();
			if ('page' == $this->get_option('show_on_front') && $this->get_option('page_on_front')) {
				$page_on_front = $this->get_option('page_on_front');
				$page = get_post($page_on_front);
				if ($page) {
					$home_pid = $page->ID;
				}
			}

			foreach ($posts as $post) {
				// Full URL to the post
				$permalink = get_permalink($post);

				// Exclude the home page and placeholder items by some plugins. Include only internal links.
				if (!empty($permalink) && $permalink != $home_url && $post->ID != $home_pid && strpos($permalink, $home_url) !== false) 
				{
					$post_type = $post->post_type;
					$priority = ($post_type == 'page') ? $default_priority_pages : $default_priority_posts;

					// Ensure the minimum priority
					if ($post_type == 'post' && $minimum_priority > 0 && $priority < $minimum_priority) {
						$priority = $minimum_priority;
					}

					$this->add_url(
						$permalink,
						$this->get_timestamp_from_mysql($post->post_modified_gmt && $post->post_modified_gmt != '0000-00-00 00:00:00' ? $post->post_modified_gmt : $post->post_date_gmt),
						($post_type == 'page' ? $change_freq_pages : $change_freq_posts),
						$priority
					);
				}
				unset($post);
			}
			unset($posts);
		}
	}

	/**
	 * Adds a URL to the sitemap.
	 *
	 * @param string $loc The location (URL) of the page.
	 * @param int $last_mod The last modification time as a UNIX timestamp.
	 * @param string $change_freq The change frequency of the page.
	 * @param float $priority The priority of the page, between 0.0 and 1.0.
	 */
	public function add_url($loc, $last_mod = 0, $change_freq = 'monthly', $priority = 0.5) {
		$this->pages[] = array(
			'loc' => $this->escape_xml(esc_url_raw($loc)),
			'lastmod' => $last_mod > 0 ? date('Y-m-d\TH:i:s+00:00', $last_mod) : false,
			'changefreq' => !empty($change_freq) ? $change_freq : false,
			'priority' => $priority !== false ? number_format($priority, 1) : false
		);
	}

	/**
	 * Adds a sitemap URL to the sitemap index.
	 *
	 * @param string $loc The location (URL) of the sitemap.
	 * @param int $last_mod The last modification time as a UNIX timestamp.
	 */
	public function add_sitemap($loc, $last_mod = 0) {
		$this->sitemaps[] = array(
			'loc' => $this->escape_xml(esc_url_raw($loc)),
			'lastmod' => $last_mod > 0 ? date('Y-m-d\TH:i:s+00:00', $last_mod) : null
		);
	}

	/**
	 * Partitions an array into smaller arrays of equal size.
	 *
	 * @param array $list The array to partition.
	 * @param int $partitions The number of partitions.
	 * @return array The partitioned array.
	 */
	public function partition($list, $partitions) {
		$list_len = count($list);
		$part_len = floor($list_len / $partitions);
		$part_rem = $list_len % $partitions;
		$partition = array();
		$mark = 0;
		for ($px = 0; $px < $partitions; $px++) {
			$incr = ($px < $part_rem) ? $part_len + 1 : $part_len;
			$partition[$px] = array_slice($list, $mark, $incr);
			$mark += $incr;
		}
		return $partition;
	}

	/**
	 * Renders the sitemap.
	 */
	public function render_sitemap() {
		$pages = $this->pages;
		$items_per_sitemap = (int) $this->get_option('items_per_sitemap');
		$sitemaps_folder = $this->get_option('sitemaps_folder');

		if (is_array($pages) && count($pages) > 0) {
			error_log('Pages found: ' . count($pages));
			$sitemap_items = array_chunk($pages, $items_per_sitemap);
			$sitemaps_count = count($sitemap_items);
		
			$base_url = home_url();
			$blog_update = strtotime(get_lastpostmodified('gmt'));
		
			$sitemaps_dir = ABSPATH . $sitemaps_folder;
		
			if (is_dir($sitemaps_dir)) {
				$this->delete_dir($sitemaps_dir);
			}
		
			mkdir($sitemaps_dir, 0755, true);
			chmod($sitemaps_dir, 0755);
		
			$xls_url = $this->get_default_style();
		
			if ($sitemaps_count > 1) {
				$i = 1;
				foreach ($sitemap_items as $sitemap) {
					error_log('Generating sitemap ' . $i);
					$urlset_template = '<?xml version="1.0" encoding="UTF-8"?><?xml-stylesheet type="text/xsl" href="' . $xls_url . '"?><urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		
					foreach ($sitemap as $url) {
						$urlset_template .= "\t<url>\n";
						$urlset_template .= "\t\t<loc>" . $url['loc'] . "</loc>\n";
						if ($url['lastmod']) {
							$urlset_template .= "\t\t<lastmod>" . $url['lastmod'] . "</lastmod>\n";
						}
						if (!empty($url['changefreq'])) {
							$urlset_template .= "\t\t<changefreq>" . $url['changefreq'] . "</changefreq>\n";
						}
						if ($url['priority']) {
							$urlset_template .= "\t\t<priority>" . $url['priority'] . "</priority>\n";
						}
						$urlset_template .= "\t</url>\n";
					}
		
					$urlset_template .= "</urlset>";
		
					$sitemap_name = 'sitemap-' . $i . '.xml';
		
					$sitemap_file = $sitemaps_dir . '/' . $sitemap_name;
					$handle = fopen($sitemap_file, 'w');
					fwrite($handle, $urlset_template);
					fclose($handle);
		
					// Debug: Log the sitemap file content
					error_log('Sitemap content for ' . $sitemap_name . ': ' . file_get_contents($sitemap_file));
		
					$this->add_sitemap(
						$base_url . '/' . $sitemaps_folder . '/' . $sitemap_name,
						$blog_update
					);
					$i++;
				}
		
				error_log('Generating sitemap index.');
				$sitemap_index_template = '<?xml version="1.0" encoding="UTF-8"?><?xml-stylesheet type="text/xsl" href="' . $xls_url . '"?><sitemapindex xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/siteindex.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		
				foreach ($this->sitemaps as $sitemap) {
					$sitemap_index_template .= "\t<sitemap>\n";
					$sitemap_index_template .= "\t\t<loc>" . $sitemap['loc'] . "</loc>\n";
					if ($sitemap['lastmod']) {
						$sitemap_index_template .= "\t\t<lastmod>" . $sitemap['lastmod'] . "</lastmod>\n";
					}
					$sitemap_index_template .= "\t</sitemap>\n";
				}
		
				$sitemap_index_template .= "</sitemapindex>";
		
				$handle = fopen($sitemaps_dir . '/sitemap.xml', 'w');
				fwrite($handle, $sitemap_index_template);
				fclose($handle);
		
				// Debug: Log the sitemap index content
				error_log('Sitemap index content: ' . file_get_contents($sitemaps_dir . '/sitemap.xml'));
			} else {
				foreach ($sitemap_items as $sitemap) {
					error_log('Generating single sitemap.');
					$urlset_template = '<?xml version="1.0" encoding="UTF-8"?><?xml-stylesheet type="text/xsl" href="' . $xls_url . '"?><urlset xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
		
					foreach ($sitemap as $url) {
						$urlset_template .= "\t<url>\n";
						$urlset_template .= "\t\t<loc>" . $url['loc'] . "</loc>\n";
						if ($url['lastmod']) {
							$urlset_template .= "\t\t<lastmod>" . $url['lastmod'] . "</lastmod>\n";
						}
						if (!empty($url['changefreq'])) {
							$urlset_template .= "\t\t<changefreq>" . $url['changefreq'] . "</changefreq>\n";
						}
						if ($url['priority']) {
							$urlset_template .= "\t\t<priority>" . $url['priority'] . "</priority>\n";
						}
						$urlset_template .= "\t</url>\n";
					}
		
					$urlset_template .= "</urlset>";
		
					$handle = fopen($sitemaps_dir . '/sitemap.xml', 'w');
					fwrite($handle, $urlset_template);
					fclose($handle);
		
					// Debug: Log the sitemap file content
					error_log('Sitemap content: ' . file_get_contents($sitemaps_dir . '/sitemap.xml'));
				}
			}
		} else {
			error_log('No pages found to include in the sitemap.');
		}
		
	}

		/**
	 * Converts a MySQL datetime value into a Unix timestamp.
	 *
	 * @param string $mysql_date_time The timestamp in the MySQL datetime format.
	 * @return int The time in seconds.
	 */
	public function get_timestamp_from_mysql($mysql_date_time) {
		list($date, $hours) = explode(' ', $mysql_date_time);
		list($year, $month, $day) = explode('-', $date);
		list($hour, $min, $sec) = explode(':', $hours);
		return mktime(intval($hour), intval($min), intval($sec), intval($month), intval($day), intval($year));
	}

	/**
	 * Returns the URL to the directory where the plugin file is located.
	 *
	 * @since 3.0b5
	 * @return string The URL to the plugin directory.
	 */
	public function get_plugin_url() {
		$url = trailingslashit(plugins_url("", __FILE__));
		return $url;
	}

	/**
	 * Returns the path to the directory where the plugin file is located.
	 *
	 * @since 3.0b5
	 * @return string The path to the plugin directory.
	 */
	public function get_plugin_path() {
		$path = dirname(__FILE__);
		return trailingslashit(str_replace("\\", "/", $path));
	}

	/**
	 * Returns the URL to the default XSLT style if it exists.
	 *
	 * @since 3.0b5
	 * @return string The URL to the default stylesheet, empty string if not available.
	 */
	public function get_default_style() {
		$path = $this->get_plugin_path();
		if (file_exists($path . "sitemap.xsl")) {
			$url = $this->get_plugin_url();
			// If called over the admin area using HTTPS, the stylesheet would also be an HTTPS URL, even if the site frontend is not.
			if (substr(home_url(), 0, 5) != "https" && substr($url, 0, 5) == "https") {
				$url = "http" . substr($url, 5);
			}
			return $url . 'sitemap.xsl';
		}
		return '';
	}

	/**
	 * Checks if permalinks are used.
	 *
	 * @return bool True if permalinks are used, false otherwise.
	 */
	public function is_using_permalinks() {
		global $wp_rewrite;
		return $wp_rewrite->using_mod_rewrite_permalinks();
	}

	/**
	 * Returns the URL for the sitemap file.
	 *
	 * @since 3.0
	 * @return string The URL to the Sitemap file.
	 */
	public function get_xml_url() {
		$base_url = home_url();
		$sitemaps_folder = $this->get_option('sitemaps_folder');
		return trailingslashit($base_url) . $sitemaps_folder . "/sitemap.xml";
	}

	/**
	 * Checks if there is still an old sitemap file in the site directory.
	 *
	 * @return bool True if an old sitemap file still exists, false otherwise.
	 */
	public function old_file_exists() {
		$path = trailingslashit(get_home_path());
		$sitemaps_folder = $this->get_option('sitemaps_folder');
		return (file_exists($path . $sitemaps_folder . "/sitemap.xml") || file_exists($path . $sitemaps_folder . "/sitemap.xml.gz"));
	}

	/**
	 * Escapes special XML characters.
	 *
	 * @param string $string The string to escape.
	 * @return string The escaped string.
	 */
	protected function escape_xml($string) {
		return str_replace(array('&', '"', "'", '<', '>'), array('&amp;', '&quot;', '&apos;', '&lt;', '&gt;'), $string);
	}

	/**
	 * Deletes a directory and its contents.
	 *
	 * @param string $dir The directory to delete.
	 * @return bool True on success, false on failure.
	 */
	protected function delete_dir($dir) {
		if (!file_exists($dir)) {
			return true;
		}
		if (!is_dir($dir)) {
			return unlink($dir);
		}
		foreach (scandir($dir, SCANDIR_SORT_NONE) as $item) {
			if ($item == '.' || $item == '..') {
				continue;
			}
			if (!$this->delete_dir($dir . DIRECTORY_SEPARATOR . $item)) {
				return false;
			}
		}
		return rmdir($dir);
	}

	/**
	 * Returns the names for the frequency values.
	 *
	 * @return array The frequency names.
	 */
	public function get_freq_names() {
		return $this->freq_names;
	}
	/**
     * Generates the HTML options for priority dropdown.
     *
     * @param string $selected The selected priority value.
     */
    public function html_get_prio_names($selected) {
        $prio_values = array(
            '1.0' => '1.0',
            '0.9' => '0.9',
            '0.8' => '0.8',
            '0.7' => '0.7',
            '0.6' => '0.6',
            '0.5' => '0.5',
            '0.4' => '0.4',
            '0.3' => '0.3',
            '0.2' => '0.2',
            '0.1' => '0.1',
            '0.0' => '0.0',
        );
        foreach ($prio_values as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . selected($selected, $value, false) . '>' . esc_html($label) . '</option>';
        }
    }
	/**
	 * Echoes option fields for a select field containing the valid change frequencies.
	 *
	 * @since 4.0
	 * @param mixed $current_val The value which should be selected.
	 */
	public function html_get_freq_names($current_val) {
		foreach ($this->get_freq_names() as $key => $value) {
			echo "<option value=\"" . esc_attr($key) . "\" " . selected($key, $current_val, false) . ">" . esc_attr($value) . "</option>";
		}
	}


		/**
	 * Echoes option fields for a select field containing the valid priorities (0-1.0).
	 *
	 * @param string $current_val The value which should be selected.
	 * @return void
	 */
	public static function html_get_priority_values($current_val) {
		$current_val = (float) $current_val;
		for ($i = 0.0; $i <= 1.0; $i += 0.1) {
			$value = number_format($i, 1, ".", "");
			echo '<option value="' . esc_attr($value) . '" ' . self::html_get_selected("$i", "$current_val") . '>';
			echo esc_attr(number_format_i18n($i, 1));
			echo '</option>';
		}
	}

	/**
	 * Returns the checked attribute if the given values match.
	 *
	 * @param string $val The current value.
	 * @param string $equals The value to match.
	 * @return string The checked attribute if the given values match, an empty string if not.
	 */
	public static function html_get_checked($val, $equals) {
		return ($val == $equals) ? self::html_get_attribute("checked") : "";
	}

	/**
	 * Returns the selected attribute if the given values match.
	 *
	 * @param string $val The current value.
	 * @param string $equals The value to match.
	 * @return string The selected attribute if the given values match, an empty string if not.
	 */
	public static function html_get_selected($val, $equals) {
		return ($val == $equals) ? self::html_get_attribute("selected") : "";
	}

	/**
	 * Returns a formatted attribute. If the value is NULL, the name will be used.
	 *
	 * @param string $attr The attribute name.
	 * @param string|null $value The attribute value.
	 * @return string The formatted attribute.
	 */
	public static function html_get_attribute($attr, $value = null) {
		if ($value === null) {
			$value = $attr;
		}
		return ' ' . esc_attr($attr) . '="' . esc_attr($value) . '" ';
	}

	/**
	 * Sets up the default configuration.
	 *
	 * @since 3.0
	 */
	public function init_options() {
		$this->options = array();

		$this->options["prox_sitemaps_folder"] = 'sitemaps'; // Sitemap Folder
		$this->options["prox_items_per_sitemap"] = 1000; // Items per sitemap
		$this->options["prox_b_memory"] = ''; // Set Memory Limit (e.g. 16M)
		$this->options["prox_b_time"] = -1; // Set time limit in seconds, 0 for unlimited, -1 for disabled

		$this->options["prox_in_home"] = true; // Include homepage
		$this->options["prox_in_posts"] = true; // Include posts
		$this->options["prox_in_posts_sub"] = false; // Include post pages (<!--nextpage--> tag)
		$this->options["prox_in_pages"] = true; // Include static pages
		$this->options["prox_in_cats"] = false; // Include categories
		$this->options["prox_in_arch"] = false; // Include archives
		$this->options["prox_in_auth"] = false; // Include author pages
		$this->options["prox_in_tags"] = false; // Include tag pages
		$this->options["prox_in_tax"] = array(); // Include additional taxonomies
		$this->options["prox_in_customtypes"] = array(); // Include custom post types
		$this->options["prox_in_lastmod"] = true; // Include the last modification date

		$this->options["prox_cf_home"] = "daily"; // Change frequency of the homepage
		$this->options["prox_cf_posts"] = "monthly"; // Change frequency of posts
		$this->options["prox_cf_pages"] = "weekly"; // Change frequency of static pages
		$this->options["prox_cf_cats"] = "weekly"; // Change frequency of categories
		$this->options["prox_cf_auth"] = "weekly"; // Change frequency of author pages
		$this->options["prox_cf_arch_curr"] = "daily"; // Change frequency of the current archive (this month)
		$this->options["prox_cf_arch_old"] = "yearly"; // Change frequency of older archives
		$this->options["prox_cf_tags"] = "weekly"; // Change frequency of tags

		$this->options["prox_pr_home"] = 1.0; // Priority of the homepage
		$this->options["prox_pr_posts"] = 0.6; // Priority of posts (if auto prio is disabled)
		$this->options["prox_pr_posts_min"] = 0.2; // Minimum Priority of posts, even if autocalc is enabled
		$this->options["prox_pr_pages"] = 0.6; // Priority of static pages
		$this->options["prox_pr_cats"] = 0.3; // Priority of categories
		$this->options["prox_pr_arch"] = 0.3; // Priority of archives
		$this->options["prox_pr_auth"] = 0.3; // Priority of author pages
		$this->options["prox_pr_tags"] = 0.3; // Priority of tags

		$this->options["prox_i_install_date"] = time(); // The installation date
	}

	/**
	 * Checks for standard name directory validity.
	 *
	 * @param string $dir Directory to validate.
	 * @return bool Validity is OK or not.
	 */
	public static function is_dir_name($dir) {
		return (bool) preg_match('/^[a-zA-Z0-9_.-]*$/', $dir);
	}

	/**
	 * Loads the configuration from the database.
	 *
	 * @since 3.0
	 */
	private function load_options() {
		if ($this->options_loaded) {
			return;
		}

		$this->init_options();

		// First, init default values, then overwrite them with stored values so we can add default
		// values with an update which get stored by the next edit.
		$stored_options = get_option("prox_options");

		if ($stored_options && is_array($stored_options)) {
			foreach ($stored_options as $key => $value) {
				if (array_key_exists($key, $this->options)) {
					$this->options[$key] = $value;
				}
			}
		} else {
			update_option("prox_options", $this->options); // First time use, store default values
		}

		$this->options_loaded = true;
	}

	/**
	 * Returns the option value for the given key.
	 *
	 * @since 3.0
	 * @param string $key The configuration key.
	 * @return mixed The value.
	 */
	public function get_option($key) {
		$key = "prox_" . $key;

		if (array_key_exists($key, $this->options)) {
			return $this->options[$key];
		}
		return null;
	}

	/**
	 * Returns all options.
	 *
	 * @return array The options array.
	 */
	public function get_options() {
		return $this->options;
	}

	/**
	 * Sets an option to a new value.
	 *
	 * @since 3.0
	 * @param string $key The configuration key.
	 * @param mixed $value The new value.
	 */
	public function set_option($key, $value) {
		if (strpos($key, "prox_") !== 0) {
			$key = "prox_" . $key;
		}

		$this->options[$key] = $value;
	}

	/**
	 * Saves the options back to the database.
	 *
	 * @since 3.0
	 * @return bool True on success.
	 */
	public function save_options() {
		$old_value = get_option("prox_options");
		if ($old_value == $this->options) {
			return true;
		}
		return update_option("prox_options", $this->options);
	}

	/**
	 * Render the form for configuring the sitemap generator.
	*/
	public function render_form() {
		$message = $error = "";
		
		if (!empty($_POST['prox_update']) && check_admin_referer('sitemap_options', 'sitemap_nonce')) {
			foreach ($this->get_options() as $key => $value) {
				// Check for values and convert them into their types, based on the category they are in
				if (!isset($_POST[$key])) $_POST[$key] = ""; // Empty string will get false on 2bool and 0 on 2float
	
				if ($key == "prox_items_per_sitemap") {
					if ($_POST[$key] == '') $_POST[$key] = 1000;
					$this->set_option($key, intval($_POST[$key]));
				} elseif ($key == "prox_b_time") {
					if ($_POST[$key] == '') $_POST[$key] = -1;
					$this->set_option($key, intval($_POST[$key]));
				} elseif ($key == "prox_sitemaps_folder") {
					if ($_POST[$key] == '') $_POST[$key] = "sitemaps";
					if ($this->is_dir_name($_POST[$key])) {
						$this->set_option($key, sanitize_text_field($_POST[$key]));
					} else {
						$error .= 'Folder name cannot contain any of these characters: /:*?"<>|';
					}
				} elseif ($key == "prox_cron_interval") {
					if ($_POST[$key] == '') $_POST[$key] = 'daily';
					$this->set_option($key, sanitize_text_field($_POST[$key]));
					Static_Sitemap_Cron::schedule_sitemap_cron($_POST[$key]);
				} else {
					$this->set_option($key, sanitize_text_field($_POST[$key]));
				}
			}
	
			if (empty($error) && $this->save_options()) {
				$message .= "Configuration updated";
			} else {
				$error .= "Error while saving options";
			}
		} elseif (!empty($_POST['prox_reset_config']) && check_admin_referer('sitemap_options', 'sitemap_nonce')) {
			$this->init_options();
			$this->save_options();
		} elseif (!empty($_POST['prox_generate_sitemap']) && check_admin_referer('sitemap_options', 'sitemap_nonce')) {
			$this->generate_sitemap();
			$message .= "Sitemap generated successfully";
		}
		?>
	
		<style type="text/css">
			li.prox_hint {
				color: green;
			}
			li.prox_optimize {
				color: orange;
			}
			li.prox_error {
				color: red;
			}
			input.prox_warning:hover {
				background: #ce0000;
				color: #fff;
			}
			a.prox_button {
				padding: 4px;
				display: block;
				padding-left: 25px;
				background-repeat: no-repeat;
				background-position: 5px 50%;
				text-decoration: none;
				border: none;
			}
			a.prox_button:hover {
				border-bottom-width: 1px;
			}
			.hndle {
				font-size: 14px;
				padding: 8px 12px;
				margin: 0;
				line-height: 1.4;
				cursor: auto !important;
				-webkit-user-select: auto !important;
				-moz-user-select: auto !important;
				-ms-user-select: auto !important;
				user-select: auto !important;
			}
		</style>
	
		<div class="wrap" id="proxim_div">
			<form method="post" action="<?php echo esc_url(Static_Sitemap_Generator_Loader::get_back_link()); ?>">
				<?php wp_nonce_field('sitemap_options', 'sitemap_nonce'); ?>
				<h2><?php esc_html_e('Static XML Sitemap Generator for WordPress'); ?></h2>
	
				<div id="prox_basic_options" class="postbox">
					<h3 class="hndle"><span><?php esc_html_e('Basic Options'); ?></span></h3>
					<div class="inside">
						<ul>
							<?php if ($this->old_file_exists()) { ?>
								<li><?php echo str_replace("%s", esc_url($this->get_xml_url()), __('The URL to your sitemap index file is: <a target="_blank" href="%s">%s</a>', 'sitemap')); ?></li>
							<?php } ?>
							<li>
								<label for="prox_items_per_sitemap"><?php esc_html_e('Items per sitemap:', 'sitemap'); ?>
									<input type="text" name="prox_items_per_sitemap" id="prox_items_per_sitemap" style="width:100px;" value="<?php echo esc_attr($this->get_option('prox_items_per_sitemap')); ?>" />
								</label> (e.g. 1,000)
							</li>
							<li>
								<label for="prox_b_memory"><?php esc_html_e('Try to increase the memory limit to:', 'sitemap'); ?>
									<input type="text" name="prox_b_memory" id="prox_b_memory" style="width:50px;" value="<?php echo esc_attr($this->get_option('prox_b_memory')); ?>" />
								</label> (<?php echo htmlspecialchars('e.g. "4M", "16M"'); ?>)
							</li>
							<li>
								<label for="prox_b_time"><?php esc_html_e('Try to increase the execution time limit to:', 'sitemap'); ?>
									<input type="text" name="prox_b_time" id="prox_b_time" style="width:50px;" value="<?php echo esc_attr(($this->get_option("prox_b_time") === -1 ? '' : $this->get_option("prox_b_time"))); ?>" />
								</label> (<?php echo htmlspecialchars('in seconds, e.g. "60" or "0" for unlimited'); ?>)
							</li>
							<li>
								<label for="prox_sitemaps_folder"><?php esc_html_e('Sitemaps folder name:', 'sitemap'); ?>
									<input type="text" name="prox_sitemaps_folder" id="prox_sitemaps_folder" style="width:150px;" value="<?php echo esc_attr($this->get_option('prox_sitemaps_folder')); ?>" />
								</label> (<?php echo htmlspecialchars('Folder name cannot contain any of these characters: /:*?"<>|'); ?>)
							</li>
							<li>
								<label for="prox_cron_interval"><?php esc_html_e('Cron job interval:', 'sitemap'); ?>
									<select name="prox_cron_interval" id="prox_cron_interval">
										<option value="hourly" <?php selected($this->get_option('prox_cron_interval'), 'hourly'); ?>><?php esc_html_e('Hourly', 'sitemap'); ?></option>
										<option value="twicedaily" <?php selected($this->get_option('prox_cron_interval'), 'twicedaily'); ?>><?php esc_html_e('Twice Daily', 'sitemap'); ?></option>
										<option value="daily" <?php selected($this->get_option('prox_cron_interval'), 'daily'); ?>><?php esc_html_e('Daily', 'sitemap'); ?></option>
									</select>
								</label>
							</li>
						</ul>
					</div>
				</div>
	
				<!-- Change frequencies -->
				<div id="prox_change_frequencies" class="postbox">
					<h3 class="hndle"><span><?php esc_html_e('Change Frequencies', 'sitemap'); ?></span></h3>
					<div class="inside">
						<p>
							<b><?php esc_html_e('Note:', 'sitemap'); ?></b> <?php esc_html_e('Please note that the value of this tag is considered a hint and not a command. Even though search engine crawlers consider this information when making decisions, they may crawl pages marked "hourly" less frequently than that, and they may crawl pages marked "yearly" more frequently than that. It is also likely that crawlers will periodically crawl pages marked "never" so that they can handle unexpected changes to those pages.', 'sitemap'); ?>
						</p>
						<ul>
							<li>
								<label for="prox_cf_home">
									<select id="prox_cf_home" name="prox_cf_home">
										<?php $this->html_get_freq_names($this->get_option('prox_cf_home')); ?>
									</select>
									<?php esc_html_e('Homepage', 'sitemap'); ?>
								</label>
							</li>
							<li>
								<label for="prox_cf_posts">
									<select id="prox_cf_posts" name="prox_cf_posts">
										<?php $this->html_get_freq_names($this->get_option('prox_cf_posts')); ?>
									</select>
									<?php esc_html_e('Posts', 'sitemap'); ?>
								</label>
							</li>
							<li>
								<label for="prox_cf_pages">
									<select id="prox_cf_pages" name="prox_cf_pages">
										<?php $this->html_get_freq_names($this->get_option('prox_cf_pages')); ?>
									</select>
									<?php esc_html_e('Static pages', 'sitemap'); ?>
								</label>
							</li>
							<li>
								<label for="prox_cf_cats">
									<select id="prox_cf_cats" name="prox_cf_cats">
										<?php $this->html_get_freq_names($this->get_option('prox_cf_cats')); ?>
									</select>
									<?php esc_html_e('Categories', 'sitemap'); ?>
								</label>
							</li>
							<li>
								<label for="prox_cf_archs">
									<select id="prox_cf_archs" name="prox_cf_archs">
										<?php $this->html_get_freq_names($this->get_option('prox_cf_archs')); ?>
									</select>
									<?php esc_html_e('Archives', 'sitemap'); ?>
								</label>
							</li>
						</ul>
					</div>
				</div>
	
				<!-- Priorities -->
				<div id="prox_priorities" class="postbox">
					<h3 class="hndle"><span><?php esc_html_e('Priorities', 'sitemap'); ?></span></h3>
					<div class="inside">
						<p>
							<b><?php esc_html_e('Note:', 'sitemap'); ?></b> <?php esc_html_e('The priority of a particular URL relative to other pages on the same site. Valid values range from 0.0 to 1.0. This value does not affect how your pages are compared to pages on other sites - it only lets the search engines know which pages you deem most important for the crawlers.', 'sitemap'); ?>
						</p>
						<ul>
							<li>
								<label for="prox_pr_home">
									<select id="prox_pr_home" name="prox_pr_home">
										<?php $this->html_get_prio_names($this->get_option('prox_pr_home')); ?>
									</select>
									<?php esc_html_e('Homepage', 'sitemap'); ?>
								</label>
							</li>
							<li>
								<label for="prox_pr_posts">
									<select id="prox_pr_posts" name="prox_pr_posts">
										<?php $this->html_get_prio_names($this->get_option('prox_pr_posts')); ?>
									</select>
									<?php esc_html_e('Posts', 'sitemap'); ?>
								</label>
							</li>
							<li>
								<label for="prox_pr_pages">
									<select id="prox_pr_pages" name="prox_pr_pages">
										<?php $this->html_get_prio_names($this->get_option('prox_pr_pages')); ?>
									</select>
									<?php esc_html_e('Static pages', 'sitemap'); ?>
								</label>
							</li>
							<li>
								<label for="prox_pr_cats">
									<select id="prox_pr_cats" name="prox_pr_cats">
										<?php $this->html_get_prio_names($this->get_option('prox_pr_cats')); ?>
									</select>
									<?php esc_html_e('Categories', 'sitemap'); ?>
								</label>
							</li>
							<li>
								<label for="prox_pr_archs">
									<select id="prox_pr_archs" name="prox_pr_archs">
										<?php $this->html_get_prio_names($this->get_option('prox_pr_archs')); ?>
									</select>
									<?php esc_html_e('Archives', 'sitemap'); ?>
								</label>
							</li>
						</ul>
					</div>
				</div>
	
				<p class="submit">
					<?php wp_nonce_field('sitemap_options', 'sitemap_nonce'); ?>
					<input type="submit" class="button-primary" name="prox_update" value="<?php esc_attr_e('Update options', 'sitemap'); ?>" />
					<input type="submit" onclick='return confirm("<?php echo esc_js(__('Do you really want to generate the sitemap?', 'sitemap')); ?>");' class="prox_warning" name="prox_generate_sitemap" value="<?php esc_attr_e('Generate sitemap', 'sitemap'); ?>" />
					<input type="submit" onclick='return confirm("<?php echo esc_js(__('Do you really want to reset your configuration?', 'sitemap')); ?>");' class="prox_warning" name="prox_reset_config" value="<?php esc_attr_e('Reset options', 'sitemap'); ?>" />
				</p>
			</form>
		</div>
	
		<?php if ($message) : ?>
			<div id="message" class="updated notice notice-success is-dismissible">
				<p><?php echo esc_html($message); ?></p>
			</div>
		<?php endif; ?>
	
		<?php if ($error) : ?>
			<div id="message" class="error notice notice-error is-dismissible">
				<p><?php echo esc_html($error); ?></p>
			</div>
		<?php endif; ?>
		
		<?php
	}

}
 

