Config values that can only be set in .htconfig.php
===================================================

* [Home](help)

There are some config values that haven't found their way into the administration page.
This has several reasons.
Maybe they are part of a current development that isn't considered stable and will be added later in the administration page when it is considered safe.
Or it triggers something that isn't expected to be of public interest.
Or it is for testing purposes only.

**Attention:** Please be warned that you shouldn't use one of these values without the knowledge what it could trigger.
Especially don't do that with undocumented values.

The header of the section describes the category, the value is the parameter.
Example: To set the directory value please add this line to your .htconfig.php:

    $a->config['system']['directory'] = 'http://dir.friendica.social';

## jabber ##
* **debug** (Boolean) - Enable debug level for the jabber account synchronisation.

## system ##

* **allowed_link_protocols** (Array) - Allowed protocols in links URLs, add at your own risk. http is always allowed.
* **birthday_input_format** - Default value is "ymd".
* **block_local_dir** (Boolean) - Blocks the access to the directory of the local users.
* **auth_cookie_lifetime** (Integer) - Number of days that should pass without any activity before a user who chose "Remember me" when logging in is considered logged out. Defaults to 7.
* **curl_range_bytes** - Maximum number of bytes that should be fetched. Default is 0, which mean "no limit".
* **db_log** - Name of a logfile to log slow database queries
* **db_loglimit** - If a database call lasts longer than this value it is logged
* **db_log_index** - Name of a logfile to log queries with bad indexes
* **db_log_index_watch** - Watchlist of indexes to watch
* **db_loglimit_index** - Number of index rows needed to be logged for indexes on the watchlist
* **db_loglimit_index_high** - Number of index rows to be logged anyway (for any index)
* **db_log_index_blacklist** - Blacklist of indexes that shouldn't be watched
* **dbclean** (Boolean) - Enable the automatic database cleanup process
* **dbclean-expire-days** (Integer) - Days after which remote items will be deleted. Own items, and marked or filed items are kept.
* **diaspora_test** (Boolean) - For development only. Disables the message transfer.
* **directory** - The path to global directory. If not set then "http://dir.friendica.social" is used.
* **disable_email_validation** (Boolean) - Disables the check if a mail address is in a valid format and can be resolved via DNS.
* **disable_url_validation** (Boolean) - Disables the DNS lookup of an URL.
* **dlogfile - location of the developer log file
* **event_input_format** - Default value is "ymd".
* **frontend_worker_timeout** - Value in minutes after we think that a frontend task was killed by the webserver. Default value is 10.
* **hsts** (Boolean) - Enables the sending of HTTP Strict Transport Security headers
* **ignore_cache** (Boolean) - For development only. Disables the item cache.
* **ipv4_resolve** (Boolean) - Resolve IPV4 addresses only. Don't resolve to IPV6. Default value is false.
* **like_no_comment** (Boolean) - Don't update the "commented" value of an item when it is liked.
* **local_block** (Boolean) - Used in conjunction with "block_public".
* **local_search** (Boolean) - Blocks search for users who are not logged in to prevent crawlers from blocking your system.
* **local_tags** (Boolean) - If activated, all hashtags will point to the local server.
* **max_connections** - The maximum number of database connections which can be in use before the poller process is deferred to it's next interval.  When the system can't detect the maximum numbers of connection then this value can be used.
* **max_connections_level** - The maximum level of connections that are allowed to let the poller start. It is a percentage value. Default value is 75.
* **max_contact_queue** - Default value is 500.
* **max_batch_queue** - Default value is 1000.
* **max_processes_backend** - Maximum number of concurrent database processes for background tasks. Default value is 5.
* **max_processes_frontend** - Maximum number of concurrent database processes for foreground tasks. Default value is 20.
* **min_poll_interval** - minimal distance in minutes between two polls for a contact. Default is 1. Reasonable values are between 1 and 59.
* **memcache** (Boolean) - Use memcache. To use memcache the PECL extension "memcache" has to be installed and activated.
* **memcache_host** - Hostname of the memcache daemon. Default is '127.0.0.1'.
* **memcache_port** - Portnumber of the memcache daemon. Default is 11211.
* **no_count** (Boolean) - Don't do count calculations (currently only when showing albums)
* **no_oembed** (Boolean) - Don't use OEmbed to fetch more information about a link.
* **no_oembed_rich_content** (Boolean) - Don't show the rich content (e.g. embedded PDF).
* **no_smilies** (Boolean) - Don't show smilies.
* **no_view_full_size** (Boolean) - Don't add the link "View full size" under a resized image.
* **optimize_items** (Boolean) - Triggers an SQL command to optimize the item table before expiring items.
* **ostatus_poll_timeframe** - Defines how old an item can be to try to complete the conversation with it.
* **paranoia** (Boolean) - Log out users if their IP address changed.
* **permit_crawling** (Boolean) - Restricts the search for not logged in users to one search per minute.
* **worker_debug** (Boolean) - If enabled, it prints out the number of running processes split by priority.
* **worker_fetch_limit** - Number of worker tasks that are fetched in a single query. Default is 1.
* **profiler** (Boolean) - Enable internal timings to help optimize code. Needed for "rendertime" addon. Default is false.
* **free_crawls** - Number of "free" searches when "permit_crawling" is activated (Default value is 10)
* **crawl_permit_period** - Period in seconds between allowed searches when the number of free searches is reached and "permit_crawling" is activated (Default value is 60)
* **png_quality** - Default value is 8.
* **proc_windows** (Boolean) - Should be enabled if Friendica is running under Windows.
* **proxy_cache_time** - Time after which the cache is cleared. Default value is one day.
* **pushpoll_frequency** -
* **qsearch_limit** - Default value is 100.
* **relay_server** - Experimental Diaspora feature. Address of the relay server where public posts should be send to. For example https://podrelay.net
* **relay_subscribe** (Boolean) - Enables the receiving of public posts from the relay. They will be included in the search and on the community page when it is set up to show all public items.
* **relay_scope** - Can be "all" or "tags". "all" means that every public post should be received. "tags" means that only posts with selected tags should be received.
* **relay_server_tags** - Comma separated list of tags for the "tags" subscription (see "relay_scrope")
* **relay_user_tags** (Boolean) - If enabled, the tags from the saved searches will used for the "tags" subscription in addition to the "relay_server_tags".
* **remove_multiplicated_lines** (Boolean) - If enabled, multiple linefeeds in items are stripped to a single one.
* **show_unsupported_addons** (Boolean) - Show all addons including the unsupported ones.
* **show_unsupported_themes** (Boolean) - Show all themes including the unsupported ones.
* **throttle_limit_day** - Maximum number of posts that a user can send per day with the API.
* **throttle_limit_week** - Maximum number of posts that a user can send per week with the API.
* **throttle_limit_month** - Maximum number of posts that a user can send per month with the API.
* **wall-to-wall_share** (Boolean) - Displays forwarded posts like "wall-to-wall" posts.
* **worker_cooldown** - Cooldown time after each worker function call. Default value is 0 seconds.
* **xrd_timeout** - Timeout for fetching the XRD links. Default value is 20 seconds.

## experimental ##

* **exp_themes** (Boolean) - Show experimental themes as well.

## theme ##

* **hide_eventlist** (Boolean) - Don't show the birthdays and events on the profile and network page

# Administrator Options #

Enabling the admin panel for an account, and thus making the account holder admin of the node, is done by setting the variable

    $a->config['admin_email'] = "someone@example.com";

Where you have to match the email address used for the account with the one you enter to the .htconfig file.
If more then one account should be able to access the admin panel, seperate the email addresses with a comma.

    $a->config['admin_email'] = "someone@example.com,someonelese@example.com";

If you want to have a more personalized closing line for the notification emails you can set a variable for the admin_name.

    $a->config['admin_name'] = "Marvin";

## Database Settings

The configuration variables db_host, db_user, db_pass and db_data are holding your credentials for the database connection.
If you need to specify a port to access the database, you can do so by appending ":portnumber" to the db_host variable.

    $db_host = 'your.mysqlhost.com:123456';
