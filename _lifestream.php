<?php
define(LIFESTREAM_PLUGIN_FILE, dirname(__FILE__) . '/lifestream.php');
define(LIFESTREAM_FEEDS_PER_PAGE, 20);
define(LIFESTREAM_EVENTS_PER_PAGE, 50);
define(LIFESTREAM_ERRORS_PER_PAGE, 50);

if (!class_exists('SimplePie'))
{
    require_once(dirname(__FILE__) . '/lib/simplepie.inc');
}

$lifestream_path = trailingslashit(get_bloginfo('wpurl')) . 'wp-content/plugins/lifestream';

if (!function_exists('array_key_pop'))
{
    function array_key_pop($array, $key)
    {
        $value = $array[$key];
        unset($array[$key]);
        return $value;
    }
}
if (!function_exists('str_startswith'))
{
    function str_startswith($string, $chunk)
    {
        return substr($string, 0, strlen($chunk)) == $chunk;
    }
}
if (!function_exists('str_endswith'))
{
    function str_endswith($string, $chunk)
    {
        return substr($string, strlen($chunk)*-1) == $chunk;
    }
}
if (!function_exists('get_class_constant'))
{
    function get_class_constant($class, $const)
    {
        return constant(sprintf('%s::%s', $class, $const));
    }    
}

class LifeStream_Error extends Exception { }
class LifeStream_FeedFetchError extends LifeStream_Error { }

$lifestream_feeds = array();
/**
 * Registers a feed class with LifeStream.
 */
function register_lifestream_feed($class_name)
{
    global $lifestream_feeds;

    $lifestream_feeds[get_class_constant($class_name, 'ID')] = $class_name;
}

function lifestream_file_get_contents($url)
{
    if (function_exists('curl_init'))
    {
        $ch = curl_init();
        $timeout = 5;
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        $file_contents = curl_exec($ch);
        curl_close($ch);
        return $file_contents;
    }
    else
    {
        return file_get_contents($url);
    }
}

/*
 * This is a wrapper function which initiates the callback for the custom tag embedding.
 */
function lifestream_embed_callback($content)
{
    return preg_replace_callback("|[<\[]lifestream(?:\s+([a-z_]+)=[\"']?([a-z0-9_-\s]+)[\"']?)*\s*/?[>\]]|i", 'lifestream_embed_handler', $content);
}

/*
 * This function handles the real meat by handing off the work to helper functions.
 */
function lifestream_embed_handler($matches)
{
    // max_number
    // var_dump($matches);
    $args = array();
    for ($i=1; $i<=count($matches); $i+=2)
    {
        if ($matches[$i]) $args[$matches[$i]] = $matches[$i+1];
    }
    ob_start();
    if ($args['feed_ids']) $args['feed_ids'] = explode(',', $args['feed_ids']);
    if ($args['user_ids']) $args['user_ids'] = explode(',', $args['user_ids']);
    lifestream($args);
    return ob_get_clean();
}

/**
 * Initializes the plug-in upon first activation.
 */
function lifestream_activate()
{
    global $wpdb;
    // Add a feed for this blog
    
    lifestream_install();

    $results =& $wpdb->get_results("SELECT COUNT(*) as `count` FROM `".$wpdb->prefix."lifestream_feeds`");
    if (!$results[0]->count)
    {
        $rss_url = get_bloginfo('rss2_url');
        $options = array('url' => $rss_url);

        $feed = new LifeStream_BlogFeed($options);
        $feed->owner = 'admin';
        $feed->owner_id = 1;
        $feed->save();
        $feed->refresh();
    }
    else
    {
        lifestream_update();
    }
}

function lifestream_credits()
{
    return 'Powered by <a href="http://www.davidcramer.net/my-projects/lifestream">LifeStream</a> from <a href="http://www.ibegin.com/">iBegin</a>.';
}

$lifestream__options = array(
    'lifestream_day_format'     => 'F jS',
    'lifestream_hour_format'    => 'g:ia',
    'lifestream_timezone'       => (string)(date('O')/100),
    'lifestream_number_of_items'=> '50',
    'lifestream_date_interval'  => '1 month',
    'lifestream_digest_title'   => 'Daily Digest for %s',
    'lifestream_digest_body'    => '%1$s',
    'lifestream_digest_category'=> '1',
    'lifestream_digest_author'  => '1',
    'lifestream_daily_digest'   => '0',
    'lifestream_update_interval'=> '15',
    'lifestream__in_digest'     => '0',
    'lifestream_show_owners'    => '0',
    'lifestream_use_ibox'       => '1',
    'lifestream_show_credits'   => '1',
);

/**
 * Adds/updates the options on plug-in activation.
 */
function lifestream_install($allow_database_install=true)
{
    global $lifestream__options;
    
    $version = get_option('lifestream__version');
    
    if (!$version) $version = 0;

    if ($allow_database_install) lifestream_install_database($version);
    
    if ($version == LIFESTREAM_VERSION) return;
    
    // default options and their values
    foreach ($lifestream__options as $key=>$value)
    {
        add_option($key, $value);
    }
    
    update_option('lifestream__version', LIFESTREAM_VERSION);
}

function lifestream_safe_query($sql)
{
    global $wpdb;
    
    $result = $wpdb->query($sql);
    if ($result === false)
    {
        if ($wpdb->error)
        {
            $reason = $wpdb->error->get_error_message();
        }
        else
        {
            $reason = __('Unknown SQL Error', 'lifestream');
        }
        lifestream_log_error($reason);
        throw new LifeStream_Error($reason);
    }
    return $result;
}

/**
 * Initializes the database if it's not already present.
 */
function lifestream_install_database($version)
{
    global $wpdb, $userdata;
    
    get_currentuserinfo();
    
    lifestream_safe_query("CREATE TABLE IF NOT EXISTS `".$wpdb->prefix."lifestream_event` (
      `id` int(11) NOT NULL auto_increment,
      `feed_id` int(11) NOT NULL,
      `feed` varchar(32) NOT NULL,
      `link` varchar(200) NOT NULL,
      `data` blob NOT NULL,
      `visible` tinyint(1) default 1 NOT NULL,
      `timestamp` int(11) NOT NULL,
      `version` int(11) default 0 NOT NULL,
      `key` char(16) NOT NULL,
      `owner` varchar(128) NOT NULL,
      `owner_id` int(11) NOT NULL,
      PRIMARY KEY  (`id`),
      UNIQUE `feed_id` (`feed_id`, `key`, `owner_id`, `link`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");


    lifestream_safe_query("CREATE TABLE IF NOT EXISTS `".$wpdb->prefix."lifestream_event_group` (
      `id` int(11) NOT NULL auto_increment,
      `feed_id` int(11) NOT NULL,
      `event_id` int(11) NULL,
      `feed` varchar(32) NOT NULL,
      `data` blob NOT NULL,
      `total` int(11) default 1 NOT NULL,
      `updated` tinyint(1) default 0 NOT NULL,
      `visible` tinyint(1) default 1 NOT NULL,
      `timestamp` int(11) NOT NULL,
      `version` int(11) default 0 NOT NULL,
      `key` char(16) NOT NULL,
      `owner` varchar(128) NOT NULL,
      `owner_id` int(11) NOT NULL,
      PRIMARY KEY  (`id`),
      INDEX `feed_id` (`feed_id`, `key`, `owner_id`, `timestamp`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
    
    lifestream_safe_query("CREATE TABLE IF NOT EXISTS `".$wpdb->prefix."lifestream_feeds` (
      `id` int(11) NOT NULL auto_increment,
      `feed` varchar(32) NOT NULL,
      `options` text default NULL,
      `timestamp` int(11) NOT NULL,
      `owner` varchar(128) NOT NULL,
      `owner_id` int(11) NOT NULL,
      INDEX `owner_id` (`owner_id`),
      PRIMARY KEY  (`id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
    
    lifestream_safe_query("CREATE TABLE IF NOT EXISTS `".$wpdb->prefix."lifestream_error_log` (
      `id` int(11) NOT NULL auto_increment,
      `message` varchar(255) NOT NULL,
      `trace` text NULL,
      `feed_id` int(11) NULL,
      `timestamp` int(11) NOT NULL,
      `has_viewed` tinyint(1) default 0 NOT NULL,
      INDEX `feed_id` (`feed_id`, `has_viewed`),
      INDEX `has_viewed` (`has_viewed`),
      PRIMARY KEY  (`id`)
    ) ENGINE=MyISAM DEFAULT CHARSET=utf8;");
    
    
    if (!$version) return;

    if ($version < 0.5)
    {
        // Old wp-cron built-in stuff
        wp_clear_scheduled_hook('LifeStream_Hourly');

        // Upgrade them to version 0.5
        lifestream_safe_query("ALTER IGNORE TABLE `".$wpdb->prefix."lifestream_event_group` ADD `version` INT(11) NOT NULL DEFAULT '0' AFTER `timestamp`, ADD `key` CHAR( 16 ) NOT NULL AFTER `version`;");
        lifestream_safe_query("ALTER IGNORE TABLE `".$wpdb->prefix."lifestream_event` ADD `version` INT(11) NOT NULL DEFAULT '0' AFTER `timestamp`, ADD `key` CHAR( 16 ) NOT NULL AFTER `version`;");
    }
    if ($version < 0.6)
    {
        lifestream_safe_query("ALTER IGNORE TABLE `".$wpdb->prefix."lifestream_event_group` ADD `owner` VARCHAR(128) NOT NULL AFTER `key`, ADD `owner_id` INT(11) NOT NULL AFTER `owner`;");
        lifestream_safe_query("ALTER IGNORE TABLE `".$wpdb->prefix."lifestream_event` ADD `owner` VARCHAR(128) NOT NULL AFTER `key`, ADD `owner_id` INT(11) NOT NULL AFTER `owner`;");
        lifestream_safe_query("ALTER IGNORE TABLE `".$wpdb->prefix."lifestream_feeds` ADD `owner` VARCHAR(128) NOT NULL AFTER `timestamp`, ADD `owner_id` INT(11) NOT NULL AFTER `owner`;");
        lifestream_safe_query("ALTER IGNORE TABLE `".$wpdb->prefix."lifestream_event` DROP INDEX `feed_id`, ADD UNIQUE `feed_id` (`feed_id` , `key` , `owner_id` , `link` );");
        lifestream_safe_query("ALTER IGNORE TABLE `".$wpdb->prefix."lifestream_event_group` DROP INDEX `feed_id`, ADD INDEX `feed_id` (`feed_id` , `key` , `timestamp` , `owner_id`);");
        lifestream_safe_query("ALTER TABLE `".$wpdb->prefix."lifestream_feeds` ADD INDEX `owner_id` (`owner_id`);");
        lifestream_safe_query(sprintf("UPDATE `".$wpdb->prefix."lifestream_feeds` SET `owner` = '%s', `owner_id` = %d", $userdata->user_nicename, $userdata->ID));
        lifestream_safe_query(sprintf("UPDATE `".$wpdb->prefix."lifestream_event` SET `owner` = '%s', `owner_id` = %d", $userdata->user_nicename, $userdata->ID));
        lifestream_safe_query(sprintf("UPDATE `".$wpdb->prefix."lifestream_event_group` SET `owner` = '%s', `owner_id` = %d", $userdata->user_nicename, $userdata->ID));
    }
    if ($version < 0.81)
    {
        lifestream_safe_query("ALTER IGNORE TABLE `".$wpdb->prefix."lifestream_event` ADD `feed` VARCHAR(32) NOT NULL AFTER `feed_id`");
        lifestream_safe_query("UPDATE IGNORE `".$wpdb->prefix."lifestream_event` as t1 set t1.`feed` = (SELECT t2.`feed` FROM `".$wpdb->prefix."lifestream_feeds` as t2 WHERE t1.`feed_id` = t2.`id`)");
    }
}

class LifeStream_Event
{
    /**
     * Represents a single event in the database.
     */
     
     function __construct($row)
     {
         global $lifestream_feeds;
         
         $this->date = $row->timestamp;
         $this->data = unserialize($row->data);
         $this->id = $row->id;
         $this->timestamp = $row->timestamp;
         $this->total = 1;
         $this->is_grouped = false;
         $this->key = $row->key;
         $this->version = $row->version;
         $this->owner = $row->owner;
         $this->owner_id = $row->owner_id;
         $this->visible = $row->visible;
         $this->link = ($this->data['link'] ? $this->data['link'] : $row->link);
         $this->feed = new $lifestream_feeds[$row->feed](unserialize($row->options), $row->feed_id);
     }
     
     function get_date()
     {
         return $this->date + LIFESTREAM_DATE_OFFSET*60*60;
     }
     
     function render($options)
     {
        /**
         * Returns an HTML-ready string.
         */
        return $this->feed->render($this, $options);
     }
}

class LifeStream_EventGroup extends LifeStream_Event
{
    /**
     * Represents a grouped event in the database.
     */
     
     function __construct($row)
     {
         parent::__construct($row);
         $this->total = $row->total ? $row->total : 1;
         $this->is_grouped = true;
     }
}
class LifeStream_Feed
{
    /**
     * Represents a feed object in the database.
     */
    
    public $options;
    
    // The ID must be a-z, 0-9, _, and - characters. It also must be unique.
    const ID            = 'generic';
    const NAME          = 'Generic';
    const AUTHOR        = 'David Cramer';
    const URL           = '';
    const DESCRIPTION   = '';
    // Can this feed be grouped?
    const CAN_GROUP     = true;
    // Labels used in rendering each event
    // params: feed url, feed name
    const LABEL_SINGLE  = 'Posted an item on <a href="%s">%s</a>.';
    // params: number of items, feed url, feed name
    const LABEL_PLURAL  = 'Posted %d items on <a href="%s">%s</a>.';
    // params: author url, author name, feed url, feed name
    const LABEL_SINGLE_USER = '<a href="%s">%s</a> posted an item on <a href="%s">%s</a>.';
    // params: author url, author name, number of items, feed url, feed name
    const LABEL_PLURAL_USER = '<a href="%s">%s</a> posted %d items on <a href="%s">%s</a>.';
    // The version is so you can manage data in the database for old versions.
    const VERSION       = 0;
    
    /**
     * Instantiates this object through a feed database object.
     */
    public static function construct_from_query_result($row)
    {
        global $lifestream_feeds;
        
        $class = $lifestream_feeds[$row->feed];
        if (!$class) return false;
        
        if (!empty($row->options)) $options = unserialize($row->options);
        else $options = null;
        
        $instance = new $class($options, $row->id, $row);
        if ($row->feed != $instance->get_constant('ID')) throw new Exception('This shouldnt be happening...');
        # $instance->options = unserialize($row['options']);
        return $instance;
    }
    
    // End of Static Methods

    function __construct($options=array(), $id=null, $row=null)
    {
        $this->options = $options;
        $this->id = $id;
        if ($row)
        {
            $this->owner = $row->owner;
            $this->owner_id = $row->owner_id;
            $this->_owner_id = $row->owner_id;
        }
    }
    
    function __toInt()
    {
        return $this->id;
    }
    
    function __toString()
    {
        return $this->get_url();
    }
    
    function get_feed_display()
    {
        return $this->__toString();
    }

    function get_public_url()
    {
        return $this->get_constant('URL');
    }

    function get_public_name()
    {
        return $this->get_constant('NAME');
    }
    
    function get_label_single($key, $event)
    {
        return $this->get_constant('LABEL_SINGLE');
    }
    
    function get_label_plural($key, $event)
    {
        return $this->get_constant('LABEL_PLURAL');
    }
    
    function get_label_single_user($key, $event)
    {
        return $this->get_constant('LABEL_SINGLE_USER');
    }
    
    function get_label_plural_user($key, $event)
    {
        return $this->get_constant('LABEL_PLURAL_USER');
    }
    
    /**
     * Returns a constant attached to this class.
     * @param {string} $constant
     * @return {string | integer} $value
     */
    function get_constant($constant)
    {
        return constant(sprintf('%s::%s', get_class($this), $constant));
    }
    
    /**
     * Returns an array of available options.
     * @return {array} Available options.
     */
    function get_options()
    {        
        return array(
            // key => array(label, required, default value, choices)
            'url' => array('Feed URL:', true, '', ''),
        );
    }
    
    function save()
    {
        global $wpdb;

        // If it has an ID it means it already exists.
        if ($this->id)
        {
            $result = $wpdb->query(sprintf("UPDATE `".$wpdb->prefix."lifestream_feeds` set `options` = '%s', `owner` = '%s', `owner_id` = %d WHERE `id` = %d", $wpdb->escape(serialize($this->options)), $wpdb->escape($this->owner), $this->owner_id, $this->id));
            if ($this->_owner_id && $this->_owner_id != $this->owner_id)
            {
                $wpdb->query(sprintf("UPDATE `".$wpdb->prefix."lifestream_event` SET `owner` = '%s', `owner_id` = %d WHERE `feed_id` = %d", $wpdb->escape($this->owner), $this->owner_id, $this->id));
                $wpdb->query(sprintf("UPDATE `".$wpdb->prefix."lifestream_event_group` SET `owner` = '%s', `owner_id` = %d WHERE `feed_id` = %d", $wpdb->escape($this->owner), $this->owner_id, $this->id));
            }
        }
        else
        {
            $result = $wpdb->query(sprintf("INSERT INTO `".$wpdb->prefix."lifestream_feeds` (`feed`, `options`, `timestamp`, `owner`, `owner_id`) VALUES ('%s', '%s', %d, '%s', %d)", $wpdb->escape($this->get_constant('ID')), $wpdb->escape(serialize($this->options)), time(), $wpdb->escape($this->owner), $this->owner_id));
            $this->id = $wpdb->insert_id;
        }
        return $result;
    }
    
    function delete()
    {
        global $wpdb;

        $wpdb->query(sprintf("DELETE FROM `".$wpdb->prefix."lifestream_feeds` WHERE `id` = %d", $this->id));
        $wpdb->query(sprintf("DELETE FROM `".$wpdb->prefix."lifestream_event` WHERE `feed_id` = %d", $this->id));
        $wpdb->query(sprintf("DELETE FROM `".$wpdb->prefix."lifestream_event_group` WHERE `feed_id` = %d", $this->id));
        
        $this->id = null;
    }
    
    function test()
    {
        try
        {
            $this->fetch();
        }
        catch (LifeStream_Error $ex)
        {
            return $ex->getMessage();
        }
    }
    
    function refresh($urls=null)
    {
        global $wpdb;
        
        date_default_timezone_set('UTC');

        if (!$this->id) return false;

        $inserted = array();
        $total = 0;
        $items = $this->fetch($urls);
        if (!$items) return false;
        foreach ($items as $item_key=>$item)
        {
            $link = array_key_pop($item, 'link');
            $date = array_key_pop($item, 'date');
            $key = array_key_pop($item, 'key');
            
            $affected = $wpdb->query(sprintf("INSERT IGNORE INTO `".$wpdb->prefix."lifestream_event` (`feed_id`, `feed`, `link`, `data`, `timestamp`, `version`, `key`, `owner`, `owner_id`) VALUES (%d, '%s', '%s', '%s', %d, %d, '%s', '%s', %d)", $this->id, $this->get_constant('ID'), $wpdb->escape($link), $wpdb->escape(serialize($item)), $date, $this->get_constant('VERSION'), $wpdb->escape($key), $wpdb->escape($this->owner), $this->owner_id));
            if ($affected)
            {
                $item['id'] = $wpdb->insert_id;
                $items[$item_key] = $item;
                if (!array_key_exists($key, $inserted)) $inserted[$key] = array();
                $total += 1;
                $inserted[$key][date('m d Y', $date)] = $date;
            }
            else
            {
                unset($items[$item_key]);
            }
        }
        if (count($inserted))
        {
            // Rows were inserted so we need to handle the grouped events
            
            if ($this->options['grouped'] && $this->get_constant('CAN_GROUP'))
            {
                // Grouping them by key
                foreach ($inserted as $key=>$dates)
                {
                    // Grouping them by date
                    foreach ($dates as $date_key=>$date)
                    {
                        // Get all of the current events for this date
                        // (including the one we affected just now)
                        $results =& $wpdb->get_results(sprintf("SELECT `data`, `link` FROM `".$wpdb->prefix."lifestream_event` WHERE `feed_id` = %d AND `visible` = 1 AND DATE(FROM_UNIXTIME(`timestamp`)) = DATE(FROM_UNIXTIME(%d)) AND `key` = '%s'", $this->id, $date, $wpdb->escape($key)));
                        $events = array();
                        foreach ($results as &$result)
                        {
                            $result->data = unserialize($result->data);
                            $result->data['link'] = $result->link;
                            $events[] = $result->data;
                        }

                        // First let's see if the group already exists in the database
                        $group =& $wpdb->get_results(sprintf("SELECT `id` FROM `".$wpdb->prefix."lifestream_event_group` WHERE `feed_id` = %d AND DATE(FROM_UNIXTIME(`timestamp`)) = DATE(FROM_UNIXTIME(%d)) AND `key` = '%s' LIMIT 0, 1", $this->id, $date, $wpdb->escape($key)));
                        if (count($group) == 1)
                        {
                            $group =& $group[0];
                            $wpdb->query(sprintf("UPDATE `".$wpdb->prefix."lifestream_event_group` SET `data` = '%s', `total` = %d, `updated` = 1, `timestamp` = %d WHERE `id` = %d", $wpdb->escape(serialize($events)), count($events), $date, $group->id));
                        }
                        else
                        {
                            $wpdb->query(sprintf("INSERT INTO `".$wpdb->prefix."lifestream_event_group` (`feed_id`, `feed`, `data`, `total`, `timestamp`, `version`, `key`, `owner`, `owner_id`) VALUES(%d, '%s', '%s', %d, %d, %d, '%s', '%s', %d)", $this->id, $wpdb->escape($this->get_constant('ID')), $wpdb->escape(serialize($events)), count($events), $date, $this->get_constant('VERSION'), $wpdb->escape($key), $wpdb->escape($this->owner), $this->owner_id));
                        }
                    }
                }
            }
            else
            {
                foreach ($items as $item)
                {
                    $date = array_key_pop($item, 'date');
                    $key = array_key_pop($item, 'key');
                    $wpdb->query(sprintf("INSERT INTO `".$wpdb->prefix."lifestream_event_group` (`feed_id`, `feed`, `event_id`, `data`, `timestamp`, `total`, `version`, `key`, `owner`, `owner_id`) VALUES(%d, '%s', %d, '%s', %d, 1, %d, '%s', '%s', %d)", $this->id, $wpdb->escape($this->get_constant('ID')), $item['id'], $wpdb->escape(serialize(array($item))), $date, $this->get_constant('VERSION'), $wpdb->escape($key), $wpdb->escape($this->owner), $this->owner_id));
                }
            }
        }
        return $total;
    }
    
    function get_events($limit=50, $offset=0)
    {
        global $wpdb;

        if (!$this->id) return false;
        
        if (!($limit > 0) || !($offset >= 0)) return false;

        $results =& $wpdb->get_results(sprintf("SELECT t1.*, t2.`feed`, t2.`options` FROM `".$wpdb->prefix."lifestream_event` as t1 JOIN `".$wpdb->prefix."lifestream_feeds` as t2 ON t1.`feed_id` = t2.`id` WHERE t1.`feed_id` = %d ORDER BY t1.`timestamp` DESC LIMIT %d, %d", $this->id, $offset, $limit));
        $events = array();
        foreach ($results as &$result)
        {
            $events[] = new LifeStream_EventGroup($result);
        }
        return $events;
    }
    function fetch($urls=null)
    {
        // kind of an ugly hack for now so we can extend twitter
        if (!$urls) $urls = $this->get_url();
        if (!is_array($urls)) $urls = array($urls);
        $items = array();
        foreach ($urls as $url_data)
        {
            if (is_array($url_data))
            {
                // url, key
                list($url, $key) = $url_data;
            }
            else
            {
                $url = $url_data;
                $key = '';
            }
            $feed = new SimplePie();
            $feed->enable_cache(false);
            $feed->set_feed_url($url);
            $feed->enable_order_by_date(false);
            $feed->force_feed(true); 
            
            $success = $feed->init();
            if (!$success)
            {
                throw new LifeStream_FeedFetchError('Error fetching feed from ' . $url . ' ('. $feed->error() . ')');
            }
            $feed->handle_content_type();
            foreach ($feed->get_items() as $row)
            {
                $row =& $this->yield($row, $url);
                if (!$row) continue;
                if (!$row['key']) $row['key'] = $key;
                if (!($row['date'] > 0)) $row['date'] = time();
                if (count($row)) $items[] = $row;
            }
        }
        return $items;
    }

    function yield($item)
    {
        // date and link are required
        // the rest of the data will be serialized into a `data` field
        // and is pulled out and used on the render($row) method

        $title = $item->get_title();
        if (!$title) return false;
        $data = array(
            'date'      =>  $item->get_date('U'),
            'link'      =>  html_entity_decode($item->get_link()),
            'title'     =>  html_entity_decode($title),
            'key'       =>  '',
        );
        
        if ($enclosure = $item->get_enclosure())
        {
            if ($thumbnail = $enclosure->get_thumbnail())
            {
                $data['thumbnail'] = $thumbnail;
            }
            $data['key'] = 'photo';
        }
        return $data;
    }
    
    function render_item($row, $item)
    {
        // XXX: should we change this to use $event->key ?
        if (!empty($item['thumbnail']))
        {
            // if (get_option('lifestream_use_ibox') == '1') $ibox = ' rel="ibox"';
            // else $ibox = '';
            
            $ibox = '';
            
            return sprintf('<a href="%s" '.$ibox.'class="photo" title="%s""><img src="%s" width="50"/></a>', htmlspecialchars($item['link']), $item['title'], $item['thumbnail']);
        }
        return sprintf('<a href="%s">%s</a>', $item['link'], $item['title']);
        
    }
    
    function render_group_items($id, $output, $event)
    {
        if (!empty($event->data[0]['thumbnail']))
        {
            return sprintf('<div id="%s" style="display:none;">%s</div>', $id, implode(' ', $output));
        }
        return sprintf('<ul id="%s" style="display:none;"><li>%s</li></ul>', $id, implode('</li><li>', $output));
        
    }
    
    function get_render_output($event)
    {
        $label = '';
        $rows = array();

        if ($event->is_grouped)
        {
            foreach ($event->data as $row)
            {
                $rows[] = $this->render_item($event, $row);
            }
        }
        else
        {
            $rows[] = $this->render_item($event, $event->data);
        }
        if (count($rows) > 1)
        {
            if (get_option('lifestream_show_owners'))
            {
                $label = sprintf(__($this->get_label_plural_user($event->key, $event), 'lifestream'), '#', $event->owner, $event->total, $this->get_public_url(), $this->get_public_name());
            }
            else
            {
                $label = sprintf(__($this->get_label_plural($event->key, $event), 'lifestream'), $event->total, $this->get_public_url(), $this->get_public_name());
            }
        }
        else
        {
            if (get_option('lifestream_show_owners'))
            {
                $label = sprintf(__($this->get_label_single_user($event->key, $event), 'lifestream'), '#', $event->owner, $this->get_public_url(), $this->get_public_name());
            }
            else
            {
                $label = sprintf(__($this->get_label_single($event->key, $event), 'lifestream'), $this->get_public_url(), $this->get_public_name());
            }
        }
        return array($label, $rows);
    }
    
    function render($event, $options)
    {
        list($label, $rows) = $this->get_render_output($event);
        if (count($rows) > 1)
        {
            return sprintf('%1$s <small class="lifestream_more">(<span onclick="lifestream_toggle(this, \'lwg_%2$d\', \'%3$s\', \'%4$s\');return false;">%3$s</span>)</small><div class="lifestream_events">%5$s</div>', $label, $event->id, __('Show Details', 'lifestream'), __('Hide Details', 'lifestream'), $this->render_group_items('lwg_'.$event->id, $rows, $event));
        }
        elseif ($this->options['show_label'] && !$options['hide_label'])
        {
            return sprintf('%s<div class="lifestream_events">%s', $label, $rows[0]);
        }
        else
        {
            return $rows[0];
        }
    }
    
    function get_url()
    {
        return $this->options['url'];
    }
    
    function parse_urls($text)
    {
        if (get_option('lifestream_use_ibox') == '1') $ibox = ' rel="ibox"';
        else $ibox = '';
    
        # match http(s):// urls
        $text = preg_replace('@(https?://([-\w\.]+)+(:\d+)?(/([\w/\~_\.]*(\?\S+)?)?)?)@', '<a href="$1"'.$ibox.'>$1</a>', $text);
        # match www urls
        $text = preg_replace('@((?<!http://)www\.([-\w\.]+)+(:\d+)?(/([\w/\~_\.]*(\?\S+)?)?)?)@', '<a href="http://$1">$1</a>', $text);
        # match email@address
        $text = preg_replace('/\b([A-Z0-9._%+-]+@(?:[A-Z0-9-]+\.)+[A-Z]{2,4})\b/i', '<a href="mailto:$1">$1</a>', $text);
        return $text;
    }
}
/**
 * You need to pass a thumbnail item in yield() for PhotoFeed item's
 */
class LifeStream_PhotoFeed extends LifeStream_Feed
{
    const LABEL_SINGLE  = 'Posted a photo on <a href="%s">%s</a>.';
    const LABEL_PLURAL  = 'Posted %d photos on <a href="%s">%s</a>.';
    const LABEL_SINGLE_USER = '<a href="%s">%s</a> posted a photo on <a href="%s">%s</a>.';
    const LABEL_PLURAL_USER = '<a href="%s">%s</a> posted %d photos on <a href="%s">%s</a>.';
}

class LifeStream_GenericFeed extends LifeStream_Feed {
    const TEXT_LABEL_SINGLE  = 'Posted an item';
    const TEXT_LABEL_PLURAL  = 'Posted %d items';
    const TEXT_LABEL_SINGLE_USER = '<a href="%s">%s</a> posted an item.';
    const TEXT_LABEL_PLURAL_USER = '<a href="%s">%s</a> posted %d items.';
    
    const PHOTO_LABEL_SINGLE  = 'Posted a photo';
    const PHOTO_LABEL_PLURAL  = 'Posted %d photos';
    const PHOTO_LABEL_SINGLE_USER = '<a href="%s">%s</a> posted a photo.';
    const PHOTO_LABEL_PLURAL_USER = '<a href="%s">%s</a> posted %d photos.';
    
    const DESCRIPTION = 'The generic feed can handle both feeds with images (in enclosures), as well as your standard text based RSS and Atom feeds.';
    
    function get_options()
    {        
        return array(
            'url' => array('Feed URL:', true, '', ''),
            'name' => array('Feed Name:', false, '', ''),
        );
    }

    function get_public_name()
    {
        return $this->options['name'];
    }

    function get_public_url()
    {
        return $this->options['url'];
    }
    
    function get_label_single($key)
    {
        if ($key == 'photo')
        {
            if ($this->options['name']) return LifeStream_PhotoFeed::LABEL_SINGLE;
            return $this->get_constant('PHOTO_LABEL_SINGLE');
        }
        if ($this->options['name']) return parent::LABEL_SINGLE;
        return $this->get_constant('TEXT_LABEL_SINGLE');
    }
    
    function get_label_plural($key)
    {
        if ($key == 'photo')
        {
            if ($this->options['name']) return LifeStream_PhotoFeed::LABEL_PLURAL;
            return $this->get_constant('PHOTO_LABEL_PLURAL');
        }
        if ($this->options['name']) return parent::LABEL_PLURAL;
        return $this->get_constant('TEXT_LABEL_PLURAL');
    }
    
    function get_label_single_user($key)
    {
        if ($key == 'photo')
        {
            if ($this->options['name']) return LifeStream_PhotoFeed::LABEL_SINGLE_USER;
            return $this->get_constant('PHOTO_LABEL_SINGLE_USER');
        }
        if ($this->options['name']) return parent::LABEL_SINGLE_USER;
        return $this->get_constant('TEXT_LABEL_SINGLE_USER');
    }
    
    function get_label_plural_user($key)
    {
        if ($key == 'photo')
        {
            if ($this->options['name']) return LifeStream_PhotoFeed::LABEL_PLURAL_USER;
            return $this->get_constant('PHOTO_LABEL_PLURAL_USER');
        }
        
        if ($this->options['name']) return parent::LABEL_PLURAL_USER;
        return $this->get_constant('TEXT_LABEL_PLURAL_USER');
    }
}
register_lifestream_feed('LifeStream_GenericFeed');

/**
 * Outputs the recent lifestream events.
 * @param {Array} $args An array of keyword args.
 */
function lifestream($args=array())
{
    global $lifestream_path;

    setlocale(LC_ALL, WPLANG);

    $_ = func_get_args();

    $defaults = array(
        'hide_labels'       => false,
    );

    if (!is_array($_[0]))
    {
        // old style
        $_ = array(
            'number_of_results' => $_[0],
            'feed_ids'          => $_[1],
            'date_interval'     => $_[2],
            'user_ids'          => $_[4],
        );
        foreach ($_ as $key=>$value)
        {
            if ($value == null) unset($_[$key]);
        }
    }
    else
    {
        $_ = $args;
    }
    
    $_ = array_merge($defaults, $_);
    
    // TODO: offset
    //$offset = get_option('lifestream_timezone');
    $hour_format = get_option('lifestream_hour_format');
    $day_format = get_option('lifestream_day_format');
    
    $events = call_user_func('lifestream_get_events', $_);
    
    include(dirname(__FILE__) . '/pages/lifestream-table.inc.php');

    echo '<!-- Powered by iBegin LifeStream '.LIFESTREAM_VERSION.' -->';

    if (get_option('lifestream_show_credits') == '1')
    {
        echo '<p class="lifestream_credits"><small>'.lifestream_credits().'</small></p>';
    }
}

function lifestream_sidebar_widget($_=array())
{
    global $lifestream_path;
    
    setlocale(LC_ALL, WPLANG);
    
    $defaults = array(
        'number_of_results' => 10,
        'event_total_max'   => 1,
        'hide_labels'       => false,
        'break_groups'      => false,
    );
    
    $_ = array_merge($defaults, $_);
    
    // TODO: offset
    //$offset = get_option('lifestream_timezone');
    $hour_format = get_option('lifestream_hour_format');
    $day_format = get_option('lifestream_day_format');
    
    $events = call_user_func('lifestream_get_events', $_);
    
    include(dirname(__FILE__) . '/pages/lifestream-list.inc.php');
}

/**
 * Gets recent events from the lifestream.
 * @param {Array} $_ An array of keyword args.
 */
function lifestream_get_events($_=array())
{
    global $wpdb;
    
    setlocale(LC_ALL, WPLANG);
    
    $defaults = array(
        'number_of_results' => get_option('lifestream_number_of_items'),
        'offset'            => 0,
        'feed_ids'          => array(),
        'user_ids'          => array(),
        'date_interval'     => get_option('lifestream_date_interval'),
        'event_total_min'   => -1,
        'event_total_max'   => -1,
        'break_groups'      => false,
    );
    
    $_ = array_merge($defaults, $_);
    
    # If any arguments are invalid we bail out
    
    if (!((int)$_['number_of_results'] > 0)) return;
    if (!((int)$_['offset'] >= 0)) return;

    if (!preg_match('/[\d]+ (month|day|year|hour|second|microsecond|week|quarter)s?/', $_['date_interval'])) $_['date_interval'] = -1;
    else $_['date_interval'] = rtrim($_['date_interval'], 's');

    $_['feed_ids'] = (array)$_['feed_ids'];
    $_['user_ids'] = (array)$_['user_ids'];
    
    $where = array('t1.`visible` = 1');
    if (count($_['feed_ids']))
    {
        foreach ($_['feed_ids'] as $key=>$value)
        {
            $_['feed_ids'][$key] = $wpdb->escape($value);
        }
        $where[] = 't1.`feed_id` IN ('.implode(', ', $_['feed_ids']).')';
    }
    if (count($_['user_ids']))
    {
        foreach ($_['user_ids'] as $key=>$value)
        {
            $_['user_ids'][$key] = $wpdb->escape($value);
        }
        $where[] = 't1.`owner_id` IN ('.implode(', ', $_['user_ids']).')';
    }
    if ($_['event_total_max'] > -1)
    {
        $where[] = sprintf('t1.`total` <= %d', $_['event_total_max']);
    }
    if ($_['event_total_min'] > -1)
    {
        $where[] = sprintf('t1.`total` >= %d', $_['event_total_min']);
    }
    if ($_['date_interval'] !== -1)
    {
        $where[] = sprintf('t1.`timestamp` > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL %s))', $_['date_interval']);
    }
    
    
    if ($_['break_groups'])
    {
        // we select from lifestream_event vs grouped
        $table = 'lifestream_event';
        $cls = 'LifeStream_Event';
    }
    else
    {
        $table = 'lifestream_event_group';
        $cls = 'LifeStream_EventGroup';
    }
    $sql = sprintf("SELECT t1.*, t2.`options` FROM `".$wpdb->prefix.$table."` as `t1` INNER JOIN `".$wpdb->prefix."lifestream_feeds` as t2 ON t1.`feed_id` = t2.`id` WHERE (%s) ORDER BY t1.`timestamp` DESC LIMIT %d, %d", implode(') AND (', $where), $_['offset'], $_['number_of_results']);


    $results =& $wpdb->get_results($sql);
    $events = array();
    foreach ($results as &$result)
    {
        $events[] = new $cls($result);
    }
    return $events;
}

function lifestream_options()
{
    global $lifestream_feeds, $lifestream_path, $lifestream__options, $wpdb, $userdata;

    $wpdb->show_errors();
    
    ksort($lifestream_feeds);
    
    lifestream_install();
    
    get_currentuserinfo();
    
    $date_format = sprintf('%s @ %s', get_option('lifestream_day_format'), get_option('lifestream_hour_format'));
    $basename = basename(LIFESTREAM_PLUGIN_FILE);
    
    $errors = array();
    $message = null;
   
    switch ($_GET['page'])
    {
        case 'lifestream-events.php':
            switch (strtolower($_REQUEST['op']))
            {
                case 'delete':
                    if (!$_REQUEST['id']) break;
                    foreach ($_REQUEST['id'] as $id)
                    {
                        $result =& $wpdb->get_results(sprintf("SELECT `id`, `feed_id`, `timestamp`, `owner_id` FROM `".$wpdb->prefix."lifestream_event` WHERE `id` = %d", $id));
                        if (!$result)
                        {
                            $errors[] = __('The selected feed was not found.', 'lifestream');
                        }
                        elseif (!current_user_can('manage_options') && $result[0]->owner_id != $userdata->ID)
                        {
                            $errors[] = __('You do not have permission to do that.', 'lifestream');
                        }
                        else
                        {
                            $result =& $result[0];
                            $wpdb->query(sprintf("UPDATE `".$wpdb->prefix."lifestream_event` SET `visible` = 0 WHERE `id` = %d", $result->id));
                            $wpdb->query(sprintf("UPDATE `".$wpdb->prefix."lifestream_event_group` SET `visible` = 0 WHERE `event_id` = %d", $result->id));
                        
                            // Now we have to update the batch if it exists.
                            $group =& $wpdb->get_results(sprintf("SELECT `id` FROM `".$wpdb->prefix."lifestream_event_group` WHERE `event_id` IS NULL AND DATE(FROM_UNIXTIME(`timestamp`)) = DATE(FROM_UNIXTIME(%d)) AND `feed_id` = %d LIMIT 0, 1", $result->timestamp, $result->feed_id));
                            if (count($group) == 1)
                            {
                                $group =& $group[0];
                                $results =& $wpdb->get_results(sprintf("SELECT `data`, `link` FROM `".$wpdb->prefix."lifestream_event` WHERE `feed_id` = %d AND `visible` = 1 AND DATE(FROM_UNIXTIME(`timestamp`)) = DATE(FROM_UNIXTIME(%d))", $result->feed_id, $result->timestamp));
                                if (count($results))
                                {
                                    $events = array();
                                    foreach ($results as &$result)
                                    {
                                        $result->data = unserialize($result->data);
                                        $result->data['link'] = $result->link;
                                        $events[] = $result->data;
                                    }
                                    $wpdb->query(sprintf("UPDATE `".$wpdb->prefix."lifestream_event_group` SET `data` = '%s', `total` = %d, `updated` = 1 WHERE `id` = %d", $wpdb->escape(serialize($events)), count($events), $group->id));
                                }
                                else
                                {
                                    $wpdb->query(sprintf("DELETE FROM `".$wpdb->prefix."lifestream_event_group` WHERE `id` = %d", $group->id));
                                }
                            }
                            else
                            {
                                $wpdb->query(sprintf("DELETE FROM `".$wpdb->prefix."lifestream_event_group` WHERE `event_id` = %d", $result->id));
                            }
                        }
                        $message = __('The selected events were hidden.', 'lifestream');
                    }
                break;
            }
        break;
        case 'lifestream-settings.php':
            if ($_POST['save'])
            {
                foreach (array_keys($lifestream__options) as $value)
                {
                    update_option($value, $_POST[$value] ? stripslashes($_POST[$value]) : '');
                }
            }
        break;
        default:
            switch (strtolower($_REQUEST['op']))
            {
                case 'refreshall':
                    $events = lifestream_update($userdata->ID);
                    $message = __('All of your feeds have been refreshed.', 'lifestream');
                    break;
                case 'refresh':
                    if (!$_REQUEST['id']) break;
                    foreach ($_REQUEST['id'] as $id)
                    {
                        $result =& $wpdb->get_results(sprintf("SELECT * FROM `".$wpdb->prefix."lifestream_feeds` WHERE `id` = %d LIMIT 0, 1", $id));
                        if (!$result)
                        {
                            $errors[] = __('The selected feed was not found.', 'lifestream');
                        }
                        elseif (!current_user_can('manage_options') && $result[0]->owner_id != $userdata->ID)
                        {
                            $errors[] = __('You do not have permission to do that.', 'lifestream');
                        }
                        else
                        {
                            $instance = LifeStream_Feed::construct_from_query_result($result[0]);
                            $instance->refresh();
                            $message = __('The selected feeds and their events have been refreshed.', 'lifestream');
                        }
                    }
                break;
                case 'delete':
                    if (!$_REQUEST['id']) break;
                    foreach ($_REQUEST['id'] as $id)
                    {
                        $result =& $wpdb->get_results(sprintf("SELECT * FROM `".$wpdb->prefix."lifestream_feeds` WHERE `id` = %d LIMIT 0, 1", $id));
                        if (!$result)
                        {
                            $errors[] = __('The selected feed was not found.', 'lifestream');
                        }
                        elseif (!current_user_can('manage_options') && $result[0]->owner_id != $userdata->ID)
                        {
                            $errors[] = __('You do not have permission to do that.', 'lifestream');
                        }
                        else
                        {
                            $instance = LifeStream_Feed::construct_from_query_result($result[0]);
                            $instance->delete();
                            $message = __('The selected feeds and all related events has been removed.', 'lifestream');
                        }
                    }
                break;
                case 'edit':
                    $result =& $wpdb->get_results(sprintf("SELECT * FROM `".$wpdb->prefix."lifestream_feeds` WHERE `id` = %d LIMIT 0, 1", $_GET['id']));
                    if (!$result)
                    {
                        $errors[] = __('The selected feed was not found.', 'lifestream');
                    }
                    elseif (!current_user_can('manage_options') && $result[0]->owner_id != $userdata->ID)
                    {
                        $errors[] = __('You do not have permission to do that.', 'lifestream');
                    }
                    else
                    {
                        $instance = LifeStream_Feed::construct_from_query_result($result[0]);

                        $options = $instance->get_options();

                        if ($_POST['save'])
                        {
                            $values = array();
                            foreach ($options as $option=>$option_meta)
                            {
                                if ($option_meta[1] && !$_POST[$option])
                                {
                                    $errors[] = $option_meta[0].' is required.';
                                }
                                else
                                {
                                    $values[$option] = stripslashes($_POST[$option]);
                                }
                            }
                            if ($instance->get_constant('CAN_GROUP'))
                            {
                                $values['grouped'] = $_POST['grouped'];
                            }
                            if ($_POST['owner'] != $instance->owner_id && current_user_can('manage_options'))
                            {
                                $instance->owner_id = $_POST['owner'];
                                $usero = new WP_User($author->user_id);
                                $owner = $usero->data;
                                $instance->owner = $owner->user_nicename;
                            }
                            $values['show_label'] = $_POST['show_label'];
                            if (!count($errors))
                            {
                                $instance->options = $values;
                                $instance->save();
                            }
                        }
                    }
                break;
                case 'add':
                    if ($_POST)
                    {
                        $class_name = $lifestream_feeds[$_GET['feed']];
                        if (!$class_name) break;
                        $feed = new $class_name();
                        $values = array();
                        $options = $feed->get_options();
                        foreach ($options as $option=>$option_meta)
                        {
                            if ($option_meta[1] && !$_POST[$option])
                            {
                                $errors[] = $option_meta[0].' is required.';
                            }
                            else
                            {
                                $values[$option] = stripslashes($_POST[$option]);
                            }
                        }
                        if ($feed->get_constant('CAN_GROUP'))
                        {
                            $values['grouped'] = $_POST['grouped'];
                        }
                        if (current_user_can('manage_options'))
                        {
                            $feed->owner_id = $_POST['owner'];
                            $usero = new WP_User($feed->owner_id);
                            $owner = $usero->data;
                            $feed->owner = $owner->user_nicename;
                        }
                        else
                        {
                            $feed->owner_id = $userdata->ID;
                            $feed->owner = $userdata->user_nicename;
                        }
                        $values['show_label'] = $_POST['show_label'];
                        $feed->options = $values;
                        if (!count($errors))
                        {
                            if (!($error = $feed->test()))
                            {
                                $result = $feed->save();
                                if ($result !== false)
                                {
                                    unset($_POST);
                                    unset($_REQUEST['op']);
                                    $events = $feed->refresh();
                                    if ($events !== false)
                                    {
                                        $message = sprintf(__('A new %s feed was added to your LifeStream.', 'lifestream'), $feed->get_constant('NAME'));
                                    }
                                }
                            }
                            else
                            {
                                $errors[] = $error;
                            }
                        }
                    }
                break;
            }
        break;
    }
    
    ob_start();
    ?>
    <style type="text/css">
    .feedlist { margin: 0; padding: 0; }
    .feedlist li { list-style: none; display: inline; }
    .feedlist li a { width: 16px; height: 16px; float: left; display: block; border: 1px solid #ccc; padding: 2px; margin: 1px; }
    .feedlist li a:hover { border-color: #999; }
    td.icon { padding: 7px 0 9px 10px; }
    </style>
    <br />
    <?php
    if (count($errors)) { ?>
    <div id="message" class="error"><p><strong><?php _e('Please correct the following errors:', 'lifestream') ?></strong></p><ul>
        <?php foreach ($errors as $error) { ?>
            <li><?php echo LifeStream_Feed::parse_urls(htmlspecialchars($error)); ?></li>
        <?php } ?>
    </ul></div>
    <?php } elseif ($message) { ?>
    <div id="message" class="updated fade"><p><strong><?php echo $message; ?></strong></p></div>
    <?php } ?>
    <div class="wrap">
        <?php
        switch ($_GET['page'])
        {
            case 'lifestream-errors.php':
                $page = $_GET['paged'] ? $_GET['paged'] : 1;
                $start = ($page-1)*LIFESTREAM_ERRORS_PER_PAGE;
                $end = $page*LIFESTREAM_ERRORS_PER_PAGE;
                
                $wpdb->query("UPDATE `".$wpdb->prefix."lifestream_error_log` SET has_viewed = 1");
                
                $results =& $wpdb->get_results("SELECT COUNT(*) as `count` FROM `".$wpdb->prefix."lifestream_error_log`");
                $number_of_pages = ceil($results[0]->count/LIFESTREAM_EVENTS_PER_PAGE);
                $results =& $wpdb->get_results(sprintf("SELECT t1.*, t2.`feed`, t2.`options` FROM `".$wpdb->prefix."lifestream_error_log` as t1 LEFT JOIN `".$wpdb->prefix."lifestream_feeds` as t2 ON t1.`feed_id` = t2.`id` ORDER BY t1.`timestamp` DESC LIMIT %d, %d", $start, $end));
                
                include(dirname(__FILE__) . '/pages/errors.inc.php');
            break;
            case 'lifestream-changelog.php':
                include(dirname(__FILE__) . '/pages/changelog.inc.php');
            break;
            case 'lifestream-forums.php':
                include(dirname(__FILE__) . '/pages/forums.inc.php');
            break;
            case 'lifestream-settings.php':
                include(dirname(__FILE__) . '/pages/settings.inc.php');
            break;
            case 'lifestream-events.php':
                $page = $_GET['paged'] ? $_GET['paged'] : 1;
                $start = ($page-1)*LIFESTREAM_EVENTS_PER_PAGE;
                $end = $page*LIFESTREAM_EVENTS_PER_PAGE;
                
                if (!current_user_can('manage_options'))
                {
                    $results =& $wpdb->get_results(sprintf("SELECT COUNT(*) as `count` FROM `".$wpdb->prefix."lifestream_event` WHERE `owner_id` = %d", $userdata->ID));
                    $number_of_pages = ceil($results[0]->count/LIFESTREAM_EVENTS_PER_PAGE);
                    $results =& $wpdb->get_results(sprintf("SELECT t1.*, t2.`feed`, t2.`options` FROM `".$wpdb->prefix."lifestream_event` as t1 JOIN `".$wpdb->prefix."lifestream_feeds` as t2 ON t1.`feed_id` = t2.`id` WHERE t1.`owner_id` = %d ORDER BY t1.`timestamp` DESC LIMIT %d, %d", $userdata->ID, $start, $end));
                }
                else
                {
                    $results =& $wpdb->get_results("SELECT COUNT(*) as `count` FROM `".$wpdb->prefix."lifestream_event`");
                    $number_of_pages = ceil($results[0]->count/LIFESTREAM_EVENTS_PER_PAGE);
                    $results =& $wpdb->get_results(sprintf("SELECT t1.*, t2.`feed`, t2.`options` FROM `".$wpdb->prefix."lifestream_event` as t1 JOIN `".$wpdb->prefix."lifestream_feeds` as t2 ON t1.`feed_id` = t2.`id` ORDER BY t1.`timestamp` DESC LIMIT %d, %d", $start, $end));
                }
                include(dirname(__FILE__) . '/pages/events.inc.php');
            break;
            default:
                switch ($_REQUEST['op'])
                {
                    case 'edit':
                        include(dirname(__FILE__) . '/pages/edit-feed.inc.php');
                    break;
                    case 'add':
                        $identifier = $_GET['feed'];
                        $class_name = $lifestream_feeds[$identifier];
                        if (!$class_name) break;
                        $feed = new $class_name();
                        $options = $feed->get_options();
                        include(dirname(__FILE__) . '/pages/add-feed.inc.php');
                    break;
                    default:
                        $page = $_GET['paged'] ? $_GET['paged'] : 1;
                        $start = ($page-1)*LIFESTREAM_FEEDS_PER_PAGE;
                        $end = $page*LIFESTREAM_FEEDS_PER_PAGE;
                        if (!current_user_can('manage_options'))
                        {
                            $results =& $wpdb->get_results(sprintf("SELECT COUNT(*) as `count` FROM `".$wpdb->prefix."lifestream_feeds` WHERE `owner_id` = %d", $userdata->ID));
                            $number_of_pages = ceil($results[0]->count/LIFESTREAM_FEEDS_PER_PAGE);
                            $results =& $wpdb->get_results(sprintf("SELECT t1.*, (SELECT COUNT(1) FROM `".$wpdb->prefix."lifestream_event` WHERE `feed_id` = t1.`id`) as `events` FROM `".$wpdb->prefix."lifestream_feeds` as t1 WHERE t1.`owner_id` = %d ORDER BY `id` LIMIT %d, %d", $userdata->ID, $start, $end));
                        }
                        else
                        {
                            $results =& $wpdb->get_results("SELECT COUNT(*) as `count` FROM `".$wpdb->prefix."lifestream_feeds`");
                            $number_of_pages = ceil($results[0]->count/LIFESTREAM_FEEDS_PER_PAGE);
                            $results =& $wpdb->get_results(sprintf("SELECT t1.*, (SELECT COUNT(1) FROM `".$wpdb->prefix."lifestream_event` WHERE `feed_id` = t1.`id`) as `events` FROM `".$wpdb->prefix."lifestream_feeds` as t1 ORDER BY `id` LIMIT %d, %d", $start, $end));
                        }
                        if ($results !== false)
                        {
                            include(dirname(__FILE__) . '/pages/feeds.inc.php');
                        }
                    break;
                }
            break;
        }
        ?>
    </div>
    <?php
    ob_end_flush();
}

function lifestream_options_menu()
{
    global $wpdb;
    
    if (function_exists('add_menu_page'))
    {
        $basename = basename(LIFESTREAM_PLUGIN_FILE);
        
        $results =& $wpdb->get_results("SELECT COUNT(*) as `count` FROM `".$wpdb->prefix."lifestream_error_log` WHERE has_viewed = 0");
        $errors = $results[0]->count;
        
        add_menu_page('LifeStream', 'LifeStream', 'edit_posts', $basename, 'lifestream_options');
        add_submenu_page($basename, __('LifeStream Feeds', 'lifestream'), __('Feeds', 'lifestream'), 'edit_posts', $basename, 'lifestream_options');
        add_submenu_page($basename, __('LifeStream Events', 'lifestream'), __('Events', 'lifestream'), 'edit_posts', 'lifestream-events.php', 'lifestream_options');
        add_submenu_page($basename, __('LifeStream Settings', 'lifestream'), __('Settings', 'lifestream'), 'manage_options', 'lifestream-settings.php', 'lifestream_options');
        add_submenu_page($basename, __('LifeStream Change Log', 'lifestream'), __('Change Log', 'lifestream'), 'manage_options', 'lifestream-changelog.php', 'lifestream_options');
        add_submenu_page($basename, __('LifeStream Errors', 'lifestream'), sprintf(__('Errors (%d)', 'lifestream'), $errors), 'manage_options', 'lifestream-errors.php', 'lifestream_options');
        add_submenu_page($basename, __('LifeStream Support Forums', 'lifestream'), __('Support Forums', 'lifestream'), 'manage_options', 'lifestream-forums.php', 'lifestream_options');
    }
}

function lifestream_header()
{
    global $lifestream_path;
    
    echo '<link rel="stylesheet" type="text/css" media="screen" href="'.$lifestream_path.'/lifestream.css"/>';
    echo '<script type="text/javascript" src="'.$lifestream_path.'/lifestream.js"></script>';
}

include(dirname(__FILE__) . '/feeds.inc.php');

/**
 * Attempts to update all feeds
 */
function lifestream_update()
{
    global $wpdb;
    update_option('lifestream__last_update', time());
    $events = 0;
    $results =& $wpdb->get_results("SELECT * FROM `".$wpdb->prefix."lifestream_feeds`");
    foreach ($results as $result)
    {
        $instance = LifeStream_Feed::construct_from_query_result($result);
        try
        {
            $events += $instance->refresh();
        }
        catch (LifeStream_FeedFetchError $ex)
        {
            lifestream_log_error($ex, $instance->id);
        }
    }
    return $events;
}

// digest code based on Twitter Tools by Alex King
function lifestream_do_digest()
{
    global $wpdb, $lifestream_path;
    
    $hour_format = get_option('lifestream_hour_format');
    $day_format = get_option('lifestream_day_format');
    
    // thread locking
    if (get_option('lifestream__in_digest') == '1') return;
    update_option('lifestream__in_digest', '1');

    $now = time();
    $yesterday = strtotime('-1 day', $now);
    $last_post = get_option('lifestream__last_digest');
    
    if ($last_post && date('Y-m-d 00:00:00', $last_post) != date('Y-m-d 00:00:00', $yesterday))
    {
        $days = ceil((strtotime(date('Y-m-d 00:00:00', $yesterday)) - $last_post) / (3600 * 24));
    }
    else
    {
        $days = 1;
    }
    
    for ($i=0; $i<$days; $i++)
    {
        // make sure the post doesn't exist
        $digest_day = strtotime('-'.($days - $i).' days', $now);
        $digest_day = strtotime(date('Y-m-d 23:59:59', $digest_day));

        $results = $wpdb->get_results(sprintf("SELECT `post_id` FROM `".$wpdb->prefix."postmeta` WHERE `meta_key` = '_lifestream_digest_date' AND `meta_value` = %d LIMIT 0, 1", $digest_day));
        if ($results) continue;

        $sql = sprintf("SELECT t1.*, t2.`options` FROM `".$wpdb->prefix."lifestream_event_group` as `t1` INNER JOIN `".$wpdb->prefix."lifestream_feeds` as t2 ON t1.`feed_id` = t2.`id` WHERE t1.`timestamp` > '%s' AND t1.`timestamp` < '%s' ORDER BY t1.`timestamp` ASC", strtotime(date('Y-m-d 00:00:00', $digest_day)), strtotime(date('Y-m-d 23:59:59', $digest_day)));
        
        $results =& $wpdb->get_results($sql);
        $events = array();
        foreach ($results as &$result)
        {
            $events[] = new LifeStream_EventGroup($result);
        }

        if (count($events))
        {
            ob_start();
            include('pages/lifestream-table.inc.php');
            $content = sprintf(get_option('lifestream_digest_body'), ob_get_clean(), date(get_option('lifestream_day_format'), $digest_day), count($events));

            $data = array(
                'post_content' => $wpdb->escape($content),
                'post_title' => $wpdb->escape(sprintf(get_option('lifestream_digest_title'), date('Y-m-d', $digest_day))),
                'post_date' => date('Y-m-d 23:59:59', $digest_day),
                'post_category' => array(get_option('lifestream_digest_category')),
                'post_status' => 'publish',
                'post_author' => $wpdb->escape(get_option('lifestream_digest_author')),
            );
            $post_id = wp_insert_post($data);
            add_post_meta($post_id, '_lifestream_digest_date', $digest_day, true);
        }
    }
    update_option('lifestream__last_digest', $now);
    update_option('lifestream__in_digest', '0');
}

function lifestream_log_error($message, $feed_id=null)
{
    global $wpdb;
    
    if ($feed_id)
    {
        $result = $wpdb->query(sprintf("INSERT INTO `".$wpdb->prefix."lifestream_error_log` (`feed_id`, `message`, `timestamp`) VALUES ('%s', '%s', %d)", $wpdb->escape($feed_id), $wpdb->escape($message), time()));
    }
    else
    {
        $result = $wpdb->query(sprintf("INSERT INTO `".$wpdb->prefix."lifestream_error_log` (`feed_id`, `message`, `timestamp`) VALUES (NULL, '%s', %d)", $wpdb->escape($message), time()));
    }
}

function lifestream_init()
{
    global $wpdb;
    
    if (isset($_GET['activate']) || isset($_GET['activate-multi']))
    {
        lifestream_activate();
    }
    
    $offset = get_option('lifestream_timezone');
    define(LIFESTREAM_DATE_OFFSET, $offset);
    
    // wp cron is too limited, make our own
    $time = get_option('lifestream__last_update');
    if (!$time || ($time + (get_option('lifestream_update_interval') * 60) < time()))
    {
        add_action('shutdown', 'lifestream_update');
    }
    if (get_option('lifestream_daily_digest') == '1')
    {
        $time = get_option('lifestream__last_digest');
        if ($time < strtotime(date('Y-m-d 00:00:00', time())))
        {
            add_action('shutdown', 'lifestream_do_digest');
        }
    }
    load_plugin_textdomain('lifestream', 'wp-content/plugins/lifestream/locales');
    
    if (is_admin() && str_startswith($_GET['page'], 'lifestream'))
    {
        wp_enqueue_script('jquery');
        wp_enqueue_script('admin-forms');
    }
}

// Require more of the codebase
require_once(dirname(__FILE__) . '/inc/widget.php');
require_once(dirname(__FILE__) . '/inc/syndicate.php');

// function lifestream_cron_schedules($cron)
// {
//     $cron['lifestream'] = array( 'interval' => get_option('lifestream_update_interval') * 60, 'display' => __('On LifeStream update', 'lifestream') );
// }
// 
// add_filter('cron_schedules', 'lifestream_cron_schedules');

add_action('admin_menu', 'lifestream_options_menu');
add_action('wp_head', 'lifestream_header');
add_filter('the_content', 'lifestream_embed_callback');
add_action('init', 'lifestream_init');

?>