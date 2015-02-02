<?php
/**
 * Name: Vier
 * Version: 1.2
 * Author: Fabio <http://kirgroup.com/profile/fabrixxm>
 * Author: Ike <http://pirati.ca/profile/heluecht>
 * Maintainer: Ike <http://pirati.ca/profile/heluecht>
 * Description: "Vier" is a very compact and modern theme. It uses the font awesome font library: http://fortawesome.github.com/Font-Awesome/
 */

function vier_init(&$a) {
set_template_engine($a, 'smarty3');

$baseurl = $a->get_baseurl();

$a->theme_info = array();

$style = get_pconfig(local_user(), 'vier', 'style');

if ($style == "")
	$style = get_config('vier', 'style');

if ($style == "flat")
	$a->page['htmlhead'] .= '<link rel="stylesheet" href="view/theme/vier/flat.css" type="text/css" media="screen"/>'."\n";
else if ($style == "netcolour")
	$a->page['htmlhead'] .= '<link rel="stylesheet" href="view/theme/vier/netcolour.css" type="text/css" media="screen"/>'."\n";
else if ($style == "breathe")
	$a->page['htmlhead'] .= '<link rel="stylesheet" href="view/theme/vier/breathe.css" type="text/css" media="screen"/>'."\n";
else if ($style == "plus")
	$a->page['htmlhead'] .= '<link rel="stylesheet" href="view/theme/vier/plus.css" type="text/css" media="screen"/>'."\n";
else if ($style == "dark")
	$a->page['htmlhead'] .= '<link rel="stylesheet" href="view/theme/vier/dark.css" type="text/css" media="screen"/>'."\n";

$a->page['htmlhead'] .= <<< EOT
<script type="text/javascript">

function insertFormatting(comment,BBcode,id) {

		var tmpStr = $("#comment-edit-text-" + id).val();
		if(tmpStr == comment) {
			tmpStr = "";
			$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
			$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
			openMenu("comment-edit-submit-wrapper-" + id);
			$("#comment-edit-text-" + id).val(tmpStr);
		}

	textarea = document.getElementById("comment-edit-text-" +id);
	if (document.selection) {
		textarea.focus();
		selected = document.selection.createRange();
		if (BBcode == "url"){
			selected.text = "["+BBcode+"]" + "http://" +  selected.text + "[/"+BBcode+"]";
			} else
		selected.text = "["+BBcode+"]" + selected.text + "[/"+BBcode+"]";
	} else if (textarea.selectionStart || textarea.selectionStart == "0") {
		var start = textarea.selectionStart;
		var end = textarea.selectionEnd;
		if (BBcode == "url"){
			textarea.value = textarea.value.substring(0, start) + "["+BBcode+"]" + "http://" + textarea.value.substring(start, end) + "[/"+BBcode+"]" + textarea.value.substring(end, textarea.value.length);
			} else
		textarea.value = textarea.value.substring(0, start) + "["+BBcode+"]" + textarea.value.substring(start, end) + "[/"+BBcode+"]" + textarea.value.substring(end, textarea.value.length);
	}
	return true;
}


function showThread(id) {
	$("#collapsed-comments-" + id).show()
	$("#collapsed-comments-" + id + " .collapsed-comments").show()
}
function hideThread(id) {
	$("#collapsed-comments-" + id).hide()
	$("#collapsed-comments-" + id + " .collapsed-comments").hide()
}


function cmtBbOpen(id) {
	$("#comment-edit-bb-" + id).show();
}
function cmtBbClose(id) {
	$("#comment-edit-bb-" + id).hide();
}
</script>
EOT;
}

