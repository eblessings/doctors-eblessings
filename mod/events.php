<?php
/**
 * @file mod/events.php
 * @brief The events module
 */
use Friendica\App;
use Friendica\Core\Config;
use Friendica\Core\System;
use Friendica\Core\Worker;
use Friendica\Database\DBM;
use Friendica\Model\Profile;

require_once 'include/bbcode.php';
require_once 'include/datetime.php';
require_once 'include/event.php';
require_once 'include/items.php';

function events_init(App $a) {
	if (! local_user()) {
		return;
	}

	if ($a->argc > 1) {
		// If it's a json request abort here because we don't
		// need the widget data
		if ($a->argv[1] === 'json') {
			return;
		}

		$cal_widget = widget_events();

		if (! x($a->page,'aside')) {
			$a->page['aside'] = '';
		}

		$a->page['aside'] .= $cal_widget;
	}

	return;
}

function events_post(App $a) {

	logger('post: ' . print_r($_REQUEST, true), LOGGER_DATA);

	if (! local_user()) {
		return;
	}

	$event_id = ((x($_POST, 'event_id')) ? intval($_POST['event_id']) : 0);
	$cid = ((x($_POST, 'cid')) ? intval($_POST['cid']) : 0);
	$uid = local_user();

	$start_text  = escape_tags($_REQUEST['start_text']);
	$finish_text = escape_tags($_REQUEST['finish_text']);

	$adjust   = intval($_POST['adjust']);
	$nofinish = intval($_POST['nofinish']);

	// The default setting for the `private` field in event_store() is false, so mirror that
	$private_event = false;

	$start  = NULL_DATE;
	$finish = NULL_DATE;

	if ($start_text) {
		$start = $start_text;
	}

	if ($finish_text) {
		$finish = $finish_text;
	}

	if ($adjust) {
		$start = datetime_convert(date_default_timezone_get(), 'UTC', $start);
		if (! $nofinish) {
			$finish = datetime_convert(date_default_timezone_get(), 'UTC', $finish);
		}
	} else {
		$start = datetime_convert('UTC', 'UTC', $start);
		if (! $nofinish) {
			$finish = datetime_convert('UTC', 'UTC', $finish);
		}
	}

	// Don't allow the event to finish before it begins.
	// It won't hurt anything, but somebody will file a bug report
	// and we'll waste a bunch of time responding to it. Time that
	// could've been spent doing something else.

	$summary  = escape_tags(trim($_POST['summary']));
	$desc     = escape_tags(trim($_POST['desc']));
	$location = escape_tags(trim($_POST['location']));
	$type     = 'event';

	$action = ($event_id == '') ? 'new' : "event/" . $event_id;
	$onerror_url = System::baseUrl() . "/events/" . $action . "?summary=$summary&description=$desc&location=$location&start=$start_text&finish=$finish_text&adjust=$adjust&nofinish=$nofinish";

	if (strcmp($finish, $start) < 0 && !$nofinish) {
		notice(t('Event can not end before it has started.') . EOL);
		if (intval($_REQUEST['preview'])) {
			echo t('Event can not end before it has started.');
			killme();
		}
		goaway($onerror_url);
	}

	if ((! $summary) || ($start === NULL_DATE)) {
		notice(t('Event title and start time are required.') . EOL);
		if (intval($_REQUEST['preview'])) {
			echo t('Event title and start time are required.');
			killme();
		}
		goaway($onerror_url);
	}

	$share = ((intval($_POST['share'])) ? intval($_POST['share']) : 0);

	$c = q("SELECT `id` FROM `contact` WHERE `uid` = %d AND `self` LIMIT 1",
		intval(local_user())
	);
	if (count($c)) {
		$self = $c[0]['id'];
	} else {
		$self = 0;
	}


	if ($share) {
		$str_group_allow   = perms2str($_POST['group_allow']);
		$str_contact_allow = perms2str($_POST['contact_allow']);
		$str_group_deny    = perms2str($_POST['group_deny']);
		$str_contact_deny  = perms2str($_POST['contact_deny']);

		// Undo the pseudo-contact of self, since there are real contacts now
		if (strpos($str_contact_allow, '<' . $self . '>') !== false ) {
			$str_contact_allow = str_replace('<' . $self . '>', '', $str_contact_allow);
		}
		// Make sure to set the `private` field as true. This is necessary to
		// have the posts show up correctly in Diaspora if an event is created
		// as visible only to self at first, but then edited to display to others.
		if (strlen($str_group_allow) || strlen($str_contact_allow) || strlen($str_group_deny) || strlen($str_contact_deny)) {
			$private_event = true;
		}
	} else {
		// Note: do not set `private` field for self-only events. It will
		// keep even you from seeing them!
		$str_contact_allow = '<' . $self . '>';
		$str_group_allow = $str_contact_deny = $str_group_deny = '';
	}


	$datarray = [];
	$datarray['guid']      = get_guid(32);
	$datarray['start']     = $start;
	$datarray['finish']    = $finish;
	$datarray['summary']   = $summary;
	$datarray['desc']      = $desc;
	$datarray['location']  = $location;
	$datarray['type']      = $type;
	$datarray['adjust']    = $adjust;
	$datarray['nofinish']  = $nofinish;
	$datarray['uid']       = $uid;
	$datarray['cid']       = $cid;
	$datarray['allow_cid'] = $str_contact_allow;
	$datarray['allow_gid'] = $str_group_allow;
	$datarray['deny_cid']  = $str_contact_deny;
	$datarray['deny_gid']  = $str_group_deny;
	$datarray['private']   = (($private_event) ? 1 : 0);
	$datarray['id']        = $event_id;
	$datarray['created']   = $created;
	$datarray['edited']    = $edited;

	if (intval($_REQUEST['preview'])) {
		$html = format_event_html($datarray);
		echo $html;
		killme();
	}

	$item_id = event_store($datarray);

	if (! $cid) {
		Worker::add(PRIORITY_HIGH, "Notifier", "event", $item_id);
	}

	goaway($_SESSION['return_url']);
}

function events_content(App $a) {

	if (! local_user()) {
		notice(t('Permission denied.') . EOL);
		return;
	}

	if ($a->argc == 1) {
		$_SESSION['return_url'] = System::baseUrl() . '/' . $a->cmd;
	}

	if (($a->argc > 2) && ($a->argv[1] === 'ignore') && intval($a->argv[2])) {
		$r = q("UPDATE `event` SET `ignore` = 1 WHERE `id` = %d AND `uid` = %d",
			intval($a->argv[2]),
			intval(local_user())
		);
	}

	if (($a->argc > 2) && ($a->argv[1] === 'unignore') && intval($a->argv[2])) {
		$r = q("UPDATE `event` SET `ignore` = 0 WHERE `id` = %d AND `uid` = %d",
			intval($a->argv[2]),
			intval(local_user())
		);
	}

	if ($a->theme_events_in_profile) {
		nav_set_selected('home');
	} else {
		nav_set_selected('events');
	}

	// get the translation strings for the callendar
	$i18n = get_event_strings();

	$htpl = get_markup_template('event_head.tpl');
	$a->page['htmlhead'] .= replace_macros($htpl, [
		'$baseurl' => System::baseUrl(),
		'$module_url' => '/events',
		'$modparams' => 1,
		'$i18n' => $i18n,
	]);

	$etpl = get_markup_template('event_end.tpl');
	$a->page['end'] .= replace_macros($etpl, [
		'$baseurl' => System::baseUrl(),
	]);

	$o = '';
	$tabs = '';
	// tabs
	if ($a->theme_events_in_profile) {
		$tabs = Profile::getTabs($a, true);
	}

	$mode = 'view';
	$y = 0;
	$m = 0;
	$ignored = ((x($_REQUEST, 'ignored')) ? intval($_REQUEST['ignored']) : 0);

	if ($a->argc > 1) {
		if ($a->argc > 2 && $a->argv[1] == 'event') {
			$mode = 'edit';
			$event_id = intval($a->argv[2]);
		}
		if ($a->argc > 2 && $a->argv[1] == 'drop') {
			$mode = 'drop';
			$event_id = intval($a->argv[2]);
		}
		if ($a->argc > 2 && $a->argv[1] == 'copy') {
			$mode = 'copy';
			$event_id = intval($a->argv[2]);
		}
		if ($a->argv[1] === 'new') {
			$mode = 'new';
			$event_id = 0;
		}
		if ($a->argc > 2 && intval($a->argv[1]) && intval($a->argv[2])) {
			$mode = 'view';
			$y = intval($a->argv[1]);
			$m = intval($a->argv[2]);
		}
	}

	// The view mode part is similiar to /mod/cal.php
	if ($mode == 'view') {

		$thisyear  = datetime_convert('UTC', date_default_timezone_get(), 'now', 'Y');
		$thismonth = datetime_convert('UTC', date_default_timezone_get(), 'now', 'm');
		if (! $y) {
			$y = intval($thisyear);
		}
		if (! $m) {
			$m = intval($thismonth);
		}

		// Put some limits on dates. The PHP date functions don't seem to do so well before 1900.
		// An upper limit was chosen to keep search engines from exploring links millions of years in the future.

		if ($y < 1901) {
			$y = 1900;
		}
		if ($y > 2099) {
			$y = 2100;
		}

		$nextyear = $y;
		$nextmonth = $m + 1;
		if ($nextmonth > 12) {
			$nextmonth = 1;
			$nextyear ++;
		}

		$prevyear = $y;
		if ($m > 1) {
			$prevmonth = $m - 1;
		} else {
			$prevmonth = 12;
			$prevyear --;
		}

		$dim    = get_dim($y, $m);
		$start  = sprintf('%d-%d-%d %d:%d:%d', $y, $m, 1, 0, 0, 0);
		$finish = sprintf('%d-%d-%d %d:%d:%d', $y, $m, $dim, 23, 59, 59);

		if ($a->argc > 1 && $a->argv[1] === 'json') {
			if (x($_GET, 'start')) {
				$start  = $_GET['start'];
			}
			if (x($_GET, 'end'))   {
				$finish = $_GET['end'];
			}
		}

		$start  = datetime_convert('UTC', 'UTC', $start);
		$finish = datetime_convert('UTC', 'UTC', $finish);

		$adjust_start  = datetime_convert('UTC', date_default_timezone_get(), $start);
		$adjust_finish = datetime_convert('UTC', date_default_timezone_get(), $finish);

		// put the event parametes in an array so we can better transmit them
		$event_params = [
			'event_id'      => (x($_GET, 'id') ? $_GET['id'] : 0),
			'start'         => $start,
			'finish'        => $finish,
			'adjust_start'  => $adjust_start,
			'adjust_finish' => $adjust_finish,
			'ignored'       => $ignored,
		];

		// get events by id or by date
		if (x($_GET, 'id')) {
			$r = event_by_id(local_user(), $event_params);
		} else {
			$r = events_by_date(local_user(), $event_params);
		}

		$links = [];

		if (DBM::is_result($r)) {
			$r = sort_by_date($r);
			foreach ($r as $rr) {
				$j = (($rr['adjust']) ? datetime_convert('UTC', date_default_timezone_get(), $rr['start'], 'j') : datetime_convert('UTC', 'UTC', $rr['start'], 'j'));
				if (! x($links,$j)) {
					$links[$j] = System::baseUrl() . '/' . $a->cmd . '#link-' . $j;
				}
			}
		}

		$events = [];

		// transform the event in a usable array
		if (DBM::is_result($r)) {
			$r = sort_by_date($r);
			$events = process_events($r);
		}

		if ($a->argc > 1 && $a->argv[1] === 'json'){
			echo json_encode($events);
			killme();
		}

		if (x($_GET, 'id')) {
			$tpl =  get_markup_template("event.tpl");
		} else {
			$tpl = get_markup_template("events_js.tpl");
		}

		// Get rid of dashes in key names, Smarty3 can't handle them
		foreach ($events as $key => $event) {
			$event_item = [];
			foreach ($event['item'] as $k => $v) {
				$k = str_replace('-' ,'_', $k);
				$event_item[$k] = $v;
			}
			$events[$key]['item'] = $event_item;
		}

		$o = replace_macros($tpl, [
			'$baseurl'   => System::baseUrl(),
			'$tabs'      => $tabs,
			'$title'     => t('Events'),
			'$view'      => t('View'),
			'$new_event' => [System::baseUrl() . '/events/new', t('Create New Event'), '', ''],
			'$previous'  => [System::baseUrl() . '/events/$prevyear/$prevmonth', t('Previous'), '', ''],
			'$next'      => [System::baseUrl() . '/events/$nextyear/$nextmonth', t('Next'), '', ''],
			'$calendar'  => cal($y, $m, $links, ' eventcal'),

			'$events'    => $events,

			'$today' => t('today'),
			'$month' => t('month'),
			'$week'  => t('week'),
			'$day'   => t('day'),
			'$list'  => t('list'),
		]);

		if (x($_GET, 'id')) {
			echo $o;
			killme();
		}

		return $o;
	}

	if (($mode === 'edit' || $mode === 'copy') && $event_id) {
		$r = q("SELECT * FROM `event` WHERE `id` = %d AND `uid` = %d LIMIT 1",
			intval($event_id),
			intval(local_user())
		);
		if (DBM::is_result($r)) {
			$orig_event = $r[0];
		}
	}

	// Passed parameters overrides anything found in the DB
	if (in_array($mode, ['edit', 'new', 'copy'])) {
		if (!x($orig_event)) {$orig_event = [];}
		// In case of an error the browser is redirected back here, with these parameters filled in with the previous values
		if (x($_REQUEST, 'nofinish'))    {$orig_event['nofinish']    = $_REQUEST['nofinish'];}
		if (x($_REQUEST, 'adjust'))      {$orig_event['adjust']      = $_REQUEST['adjust'];}
		if (x($_REQUEST, 'summary'))     {$orig_event['summary']     = $_REQUEST['summary'];}
		if (x($_REQUEST, 'description')) {$orig_event['description'] = $_REQUEST['description'];}
		if (x($_REQUEST, 'location'))    {$orig_event['location']    = $_REQUEST['location'];}
		if (x($_REQUEST, 'start'))       {$orig_event['start']       = $_REQUEST['start'];}
		if (x($_REQUEST, 'finish'))      {$orig_event['finish']      = $_REQUEST['finish'];}

		$n_checked = ((x($orig_event) && $orig_event['nofinish']) ? ' checked="checked" ' : '');
		$a_checked = ((x($orig_event) && $orig_event['adjust'])   ? ' checked="checked" ' : '');

		$t_orig = ((x($orig_event)) ? $orig_event['summary']  : '');
		$d_orig = ((x($orig_event)) ? $orig_event['desc']     : '');
		$l_orig = ((x($orig_event)) ? $orig_event['location'] : '');
		$eid    = ((x($orig_event)) ? $orig_event['id']       : 0);
		$cid    = ((x($orig_event)) ? $orig_event['cid']      : 0);
		$uri    = ((x($orig_event)) ? $orig_event['uri']      : '');

		$sh_disabled = '';
		$sh_checked  = '';

		if (x($orig_event)) {
			$sh_checked = (($orig_event['allow_cid'] === '<' . local_user() . '>' && (! $orig_event['allow_gid']) && (! $orig_event['deny_cid']) && (! $orig_event['deny_gid'])) ? '' : ' checked="checked" ');
		}

		if ($cid || $mode === 'edit') {
			$sh_disabled = 'disabled="disabled"';
		}

		$sdt = ((x($orig_event)) ? $orig_event['start']  : 'now');
		$fdt = ((x($orig_event)) ? $orig_event['finish'] : 'now');

		$tz = date_default_timezone_get();
		if (x($orig_event)) {
			$tz = (($orig_event['adjust']) ? date_default_timezone_get() : 'UTC');
		}

		$syear  = datetime_convert('UTC', $tz, $sdt, 'Y');
		$smonth = datetime_convert('UTC', $tz, $sdt, 'm');
		$sday   = datetime_convert('UTC', $tz, $sdt, 'd');

		$shour   = ((x($orig_event)) ? datetime_convert('UTC', $tz, $sdt, 'H') : 0);
		$sminute = ((x($orig_event)) ? datetime_convert('UTC', $tz, $sdt, 'i') : 0);

		$fyear  = datetime_convert('UTC', $tz, $fdt, 'Y');
		$fmonth = datetime_convert('UTC', $tz, $fdt, 'm');
		$fday   = datetime_convert('UTC', $tz, $fdt, 'd');

		$fhour   = ((x($orig_event)) ? datetime_convert('UTC', $tz, $fdt, 'H') : 0);
		$fminute = ((x($orig_event)) ? datetime_convert('UTC', $tz, $fdt, 'i') : 0);

		require_once 'include/acl_selectors.php' ;

		$perms = get_acl_permissions($orig_event);

		if ($mode === 'new' || $mode === 'copy') {
			$acl = (($cid) ? '' : populate_acl(((x($orig_event)) ? $orig_event : $a->user)));
		}

		// If we copy an old event, we need to remove the ID and URI
		// from the original event.
		if ($mode === 'copy') {
			$eid = 0;
			$uri = '';
		}

		$tpl = get_markup_template('event_form.tpl');

		$o .= replace_macros($tpl,[
			'$post' => System::baseUrl() . '/events',
			'$eid'  => $eid,
			'$cid'  => $cid,
			'$uri'  => $uri,

			'$allow_cid' => json_encode($perms['allow_cid']),
			'$allow_gid' => json_encode($perms['allow_gid']),
			'$deny_cid'  => json_encode($perms['deny_cid']),
			'$deny_gid'  => json_encode($perms['deny_gid']),

			'$title' => t('Event details'),
			'$desc' => t('Starting date and Title are required.'),
			'$s_text' => t('Event Starts:') . ' <span class="required" title="' . t('Required') . '">*</span>',
			'$s_dsel' => datetimesel(new DateTime(), DateTime::createFromFormat('Y', $syear+5), DateTime::createFromFormat('Y-m-d H:i', "$syear-$smonth-$sday $shour:$sminute"), t('Event Starts:'), 'start_text', true, true, '', '', true),
			'$n_text' => t('Finish date/time is not known or not relevant'),
			'$n_checked' => $n_checked,
			'$f_text' => t('Event Finishes:'),
			'$f_dsel' => datetimesel(new DateTime(), DateTime::createFromFormat('Y', $fyear+5), DateTime::createFromFormat('Y-m-d H:i', "$fyear-$fmonth-$fday $fhour:$fminute"), t('Event Finishes:'), 'finish_text', true, true, 'start_text'),
			'$a_text' => t('Adjust for viewer timezone'),
			'$a_checked' => $a_checked,
			'$d_text' => t('Description:'),
			'$d_orig' => $d_orig,
			'$l_text' => t('Location:'),
			'$l_orig' => $l_orig,
			'$t_text' => t('Title:') . ' <span class="required" title="' . t('Required') . '">*</span>',
			'$t_orig' => $t_orig,
			'$summary' => ['summary', t('Title:'), $t_orig, '', '*'],
			'$sh_text' => t('Share this event'),
			'$share' => ['share', t('Share this event'), $sh_checked, '', $sh_disabled],
			'$sh_checked' => $sh_checked,
			'$nofinish' => ['nofinish', t('Finish date/time is not known or not relevant'), $n_checked],
			'$adjust' => ['adjust', t('Adjust for viewer timezone'), $a_checked],
			'$preview' => t('Preview'),
			'$acl' => $acl,
			'$submit' => t('Submit'),
			'$basic' => t('Basic'),
			'$advanced' => t('Advanced'),
			'$permissions' => t('Permissions'),

		]);

		return $o;
	}

	// Remove an event from the calendar and its related items
	if ($mode === 'drop' && $event_id) {
		$del = 0;

		$params = ['event_id' => ($event_id)];
		$ev = event_by_id(local_user(), $params);

		// Delete only real events (no birthdays)
		if (DBM::is_result($ev) && $ev[0]['type'] == 'event') {
			$del = drop_item($ev[0]['itemid'], false);
		}

		if ($del == 0) {
			notice(t('Failed to remove event' ) . EOL);
		} else {
			info(t('Event removed') . EOL);
		}

		goaway(System::baseUrl() . '/events');
	}
}
