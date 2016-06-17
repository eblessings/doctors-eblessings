/* 
 * @brief The file contains functions for text editing and commenting
 */


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

function commentExpand(id) {
	$("#comment-edit-text-" + id).value = '';
	$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
	$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
	$("#comment-edit-text-" + id).focus();
	$("#mod-cmnt-wrap-" + id).show();
	openMenu("comment-edit-submit-wrapper-" + id);
	return true;
}

function commentClose(obj,id) {
	if(obj.value == '') {
		obj.value = aStr.comment;
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).addClass("comment-edit-text-empty");
		$("#mod-cmnt-wrap-" + id).hide();
		closeMenu("comment-edit-submit-wrapper-" + id);
		return true;
	}
	return false;
}

function showHideCommentBox(id) {
	if( $('#comment-edit-form-' + id).is(':visible')) {
		$('#comment-edit-form-' + id).hide();
	}
	else {
		$('#comment-edit-form-' + id).show();
	}
}

function commentOpenUI(obj, id) {
	$(document).unbind( "click.commentOpen", handler );

	var handler = function() {
		if(obj.value == aStr.comment) {
			obj.value = '';
			$("#comment-edit-text-" + id).addClass("comment-edit-text-full").removeClass("comment-edit-text-empty");
			// Choose an arbitrary tab index that's greater than what we're using in jot (3 of them)
			// The submit button gets tabindex + 1
			$("#comment-edit-text-" + id).attr('tabindex','9');
			$("#comment-edit-submit-" + id).attr('tabindex','10');
			$("#comment-edit-submit-wrapper-" + id).show();
		}
	};

	$(document).bind( "click.commentOpen", handler );
}

function commentCloseUI(obj, id) {
	$(document).unbind( "click.commentClose", handler );

	var handler = function() {
		if(obj.value === '') {
		obj.value = aStr.comment;
			$("#comment-edit-text-" + id).removeClass("comment-edit-text-full").addClass("comment-edit-text-empty");
			$("#comment-edit-text-" + id).removeAttr('tabindex');
			$("#comment-edit-submit-" + id).removeAttr('tabindex');
			$("#comment-edit-submit-wrapper-" + id).hide();
		}
	};

	$(document).bind( "click.commentClose", handler );
}

// test if there is default content in the jot text box and remove it
function jotTextOpenUI(obj) {
	if(obj.value == aStr.share) {
		obj.value = '';
		$(".modal-body #profile-jot-text").addClass("profile-jot-text-full").removeClass("profile-jot-text-empty");
	}
}

// insert default content into the jot text box
// if it's empty
function jotTextCloseUI(obj) {
	if(obj.value === '') {
	obj.value = aStr.share;
		$(".modal-body #profile-jot-text").removeClass("profile-jot-text-full").addClass("profile-jot-text-empty");
	}
}

function commentOpen(obj,id) {
	if(obj.value == aStr.comment) {
		obj.value = '';
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		$("#mod-cmnt-wrap-" + id).show();
		openMenu("comment-edit-submit-wrapper-" + id);
		return true;
	}
	return false;
}

function commentInsert(obj,id) {
	var tmpStr = $("#comment-edit-text-" + id).val();
	if(tmpStr == aStr.comment) {
		tmpStr = '';
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
	}
	var ins = $(obj).html();
	ins = ins.replace('&lt;','<');
	ins = ins.replace('&gt;','>');
	ins = ins.replace('&amp;','&');
	ins = ins.replace('&quot;','"');
	$("#comment-edit-text-" + id).val(tmpStr + ins);
}

function qCommentInsert(obj,id) {
	var tmpStr = $("#comment-edit-text-" + id).val();
	if(tmpStr == aStr.comment) {
		tmpStr = '';
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
	}
	var ins = $(obj).val();
	ins = ins.replace('&lt;','<');
	ins = ins.replace('&gt;','>');
	ins = ins.replace('&amp;','&');
	ins = ins.replace('&quot;','"');
	$("#comment-edit-text-" + id).val(tmpStr + ins);
	$(obj).val('');
}

function confirmDelete() { return confirm(aStr.delitem); }

function dropItem(url, object) {
	var confirm = confirmDelete();
	if(confirm) {
		$('body').css('cursor', 'wait');
		$(object).fadeTo('fast', 0.33, function () {
			$.get(url).done(function() {
				$(object).remove();
				$('body').css('cursor', 'auto');
			});
		});
	}
}

	