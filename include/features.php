<?php

/*
 * Features management
 */


function feature_enabled($uid,$feature) {
	//return true;

	$x = get_pconfig($uid,'feature',$feature);
	$arr = array('uid' => $uid, 'feature' => $feature, 'enabled' => $x);
	call_hooks('feature_enabled',$arr);
	return($arr['enabled']);
}

function get_features() {

	$arr = array(

		// General
		'general' => array(
			t('General Features'),
			//array('expire',         t('Content Expiration'),		t('Remove old posts/comments after a period of time')),
			array('multi_profiles', t('Multiple Profiles'),			t('Ability to create multiple profiles')),
			array('photo_location', t('Photo Location'),			t('Photo metadata is normally stripped. This extracts the location (if present) prior to stripping metadata and links it to a map.'),false),
		),

		// Post composition
		'composition' => array(
			t('Post Composition Features'),
			array('richtext',	t('Richtext Editor'),			t('Enable richtext editor')),
			array('preview',	t('Post Preview'),			t('Allow previewing posts and comments before publishing them')),
			array('aclautomention',	t('Auto-mention Forums'),		t('Add/remove mention when a fourm page is selected/deselected in ACL window.')),
		),

		// Network sidebar widgets
		'widgets' => array(
			t('Network Sidebar Widgets'),
			array('archives',	t('Search by Date'),			t('Ability to select posts by date ranges')),
			array('forumlist_widget', t('List Forums'),			t('Enable widget to display the forums your are connected with')),
			array('groups',		t('Group Filter'),			t('Enable widget to display Network posts only from selected group')),
			array('networks',	t('Network Filter'),			t('Enable widget to display Network posts only from selected network')),
			array('savedsearch',	t('Saved Searches'),			t('Save search terms for re-use')),
		),

		// Network tabs
		'net_tabs' => array(
			t('Network Tabs'),
			array('personal_tab',	t('Network Personal Tab'),		t('Enable tab to display only Network posts that you\'ve interacted on')),
			array('new_tab',	t('Network New Tab'),			t('Enable tab to display only new Network posts (from the last 12 hours)')),
			array('link_tab',	t('Network Shared Links Tab'),		t('Enable tab to display only Network posts with links in them')),
		),

		// Item tools
		'tools' => array(
			t('Post/Comment Tools'),
			array('multi_delete',	t('Multiple Deletion'),			t('Select and delete multiple posts/comments at once')),
			array('edit_posts',	t('Edit Sent Posts'),			t('Edit and correct posts and comments after sending')),
			array('commtag',	t('Tagging'),				t('Ability to tag existing posts')),
			array('categories',	t('Post Categories'),			t('Add categories to your posts')),
			array('filing',		t('Saved Folders'),			t('Ability to file posts under folders')),
			array('dislike',	t('Dislike Posts'),			t('Ability to dislike posts/comments')),
			array('star_posts',	t('Star Posts'),			t('Ability to mark special posts with a star indicator')),
			array('ignore_posts',	t('Mute Post Notifications'),		t('Ability to mute notifications for a thread')),
		),

		// Advanced Profile Settings
		'advanced_profile' => array(
			t('Advanced Profile Settings'),
			array('forumlist_profile', t('List Forums'),			t('Show visitors public community forums at the Advanced Profile Page')),
		),
	);

	call_hooks('get_features',$arr);
	return $arr;
}
