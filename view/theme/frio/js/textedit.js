/*
 * @brief The file contains functions for text editing and commenting
 */

// Lifted from https://css-tricks.com/snippets/jquery/move-cursor-to-end-of-textarea-or-input/
jQuery.fn.putCursorAtEnd = function() {
	return this.each(function() {
		// Cache references
		var $el = $(this),
			el = this;

		// Only focus if input isn't already
		if (!$el.is(":focus")) {
			$el.focus();
		}

		// If this function exists... (IE 9+)
		if (el.setSelectionRange) {
			// Double the length because Opera is inconsistent about whether a carriage return is one character or two.
			var len = $el.val().length * 2;

			// Timeout seems to be required for Blink
			setTimeout(function() {
				el.setSelectionRange(len, len);
			}, 1);
		} else {
			// As a fallback, replace the contents with itself
			// Doesn't work in Chrome, but Chrome supports setSelectionRange
			$el.val($el.val());
		}

		// Scroll to the bottom, in case we're in a tall textarea
		// (Necessary for Firefox and Chrome)
		this.scrollTop = 999999;
	});
};

function commentGetLink(id, prompttext) {
	reply = prompt(prompttext);
	if(reply && reply.length) {
		reply = bin2hex(reply);
		$.get('parse_url?noAttachment=1&binurl=' + reply, function(data) {
			addCommentText(data, id);
		});
	}
}

function addCommentText(data, id) {
	// get the textfield
	var textfield = document.getElementById("comment-edit-text-" + id);
	// check if the textfield does have the default-value
	commentOpenUI(textfield, id);
	// save already existent content
	var currentText = $("#comment-edit-text-" + id).val();
	//insert the data as new value
	textfield.value = currentText + data;
	autosize.update($("#comment-edit-text-" + id));
}

function commentLinkDrop(event, id) {
	var reply = event.dataTransfer.getData("text/uri-list");
	event.target.textContent = reply;
	event.preventDefault();
	if (reply && reply.length) {
		reply = bin2hex(reply);
		$.get('parse_url?noAttachment=1&binurl=' + reply, function(data) {
			addCommentText(data, id);
		});
	}
}

function commentLinkDropper(event) {
	var linkFound = event.dataTransfer.types.contains("text/uri-list");
	if (linkFound) {
		event.preventDefault();
	}
}


function insertFormatting(BBcode, id) {
	var tmpStr = $("#comment-edit-text-" + id).val();
	if (tmpStr == '') {
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
	}

	textarea = document.getElementById("comment-edit-text-" + id);
	if (document.selection) {
		textarea.focus();
		selected = document.selection.createRange();
		selected.text = "[" + BBcode + "]" + selected.text + "[/" + BBcode + "]";
	} else if (textarea.selectionStart || textarea.selectionStart == "0") {
		var start = textarea.selectionStart;
		var end = textarea.selectionEnd;
		textarea.value = textarea.value.substring(0, start) + "[" + BBcode + "]" + textarea.value.substring(start, end) + "[/" + BBcode + "]" + textarea.value.substring(end, textarea.value.length);
	}

	$(textarea).trigger('change');

	return true;
}

function insertFormattingToPost(BBcode) {
	textarea = document.getElementById("profile-jot-text");
	if (document.selection) {
		textarea.focus();
		selected = document.selection.createRange();
		selected.text = "[" + BBcode + "]" + selected.text + "[/" + BBcode + "]";
	} else if (textarea.selectionStart || textarea.selectionStart == "0") {
		var start = textarea.selectionStart;
		var end = textarea.selectionEnd;
		textarea.value = textarea.value.substring(0, start) + "[" + BBcode + "]" + textarea.value.substring(start, end) + "[/" + BBcode + "]" + textarea.value.substring(end, textarea.value.length);
	}

	$(textarea).trigger('change');

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
	$("#comment-edit-text-" + id).putCursorAtEnd();
	$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
	$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
	$("#comment-edit-text-" + id).focus();
	$("#mod-cmnt-wrap-" + id).show();
	openMenu("comment-edit-submit-wrapper-" + id);
	return true;
}

function commentClose(obj, id) {
	if (obj.value == '') {
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).addClass("comment-edit-text-empty");
		$("#mod-cmnt-wrap-" + id).hide();
		closeMenu("comment-edit-submit-wrapper-" + id);
		return true;
	}
	return false;
}

function showHideCommentBox(id) {
	if ($('#comment-edit-form-' + id).is(':visible')) {
		$('#comment-edit-form-' + id).hide();
	} else {
		$('#comment-edit-form-' + id).show();
	}
}

function commentOpenUI(obj, id) {
	$("#comment-edit-text-" + id).addClass("comment-edit-text-full").removeClass("comment-edit-text-empty");
	// Choose an arbitrary tab index that's greater than what we're using in jot (3 of them)
	// The submit button gets tabindex + 1
	$("#comment-edit-text-" + id).attr('tabindex', '9');
	$("#comment-edit-submit-" + id).attr('tabindex', '10');
	$("#comment-edit-submit-wrapper-" + id).show();
	// initialize autosize for this comment
	autosize($("#comment-edit-text-" + id + ".text-autosize"));
}

function commentCloseUI(obj, id) {
	if (obj.value === '') {
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-full").addClass("comment-edit-text-empty");
		$("#comment-edit-text-" + id).removeAttr('tabindex');
		$("#comment-edit-submit-" + id).removeAttr('tabindex');
		$("#comment-edit-submit-wrapper-" + id).hide();
		// destroy the automatic textarea resizing
		autosize.destroy($("#comment-edit-text-" + id + ".text-autosize"));
	}
}

function jotTextOpenUI(obj) {
	if (obj.value == '') {
		$(".modal-body #profile-jot-text").addClass("profile-jot-text-full").removeClass("profile-jot-text-empty");
		// initiale autosize for the jot
		autosize($(".modal-body #profile-jot-text"));
	}
}

function jotTextCloseUI(obj) {
	if (obj.value === '') {
		$(".modal-body #profile-jot-text").removeClass("profile-jot-text-full").addClass("profile-jot-text-empty");
		// destroy the automatic textarea resizing
		autosize.destroy($(".modal-body #profile-jot-text"));
	}
}

function commentOpen(obj, id) {
	if (obj.value == '') {
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		$("#mod-cmnt-wrap-" + id).show();
		openMenu("comment-edit-submit-wrapper-" + id);
		return true;
	}
	return false;
}

function commentInsert(obj, id) {
	var tmpStr = $("#comment-edit-text-" + id).val();
	if (tmpStr == '') {
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
	}
	var ins = $(obj).html();
	ins = ins.replace('&lt;', '<');
	ins = ins.replace('&gt;', '>');
	ins = ins.replace('&amp;', '&');
	ins = ins.replace('&quot;', '"');
	$("#comment-edit-text-" + id).val(tmpStr + ins);
}

function qCommentInsert(obj, id) {
	var tmpStr = $("#comment-edit-text-" + id).val();
	if (tmpStr == '') {
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
	}
	var ins = $(obj).val();
	ins = ins.replace('&lt;', '<');
	ins = ins.replace('&gt;', '>');
	ins = ins.replace('&amp;', '&');
	ins = ins.replace('&quot;', '"');
	$("#comment-edit-text-" + id).val(tmpStr + ins);
	$(obj).val('');
}

function confirmDelete() {
	return confirm(aStr.delitem);
}

/**
 * Hide and removes an item element from the DOM after the deletion url is
 * successful, restore it else.
 *
 * @param {string} url The item removal URL
 * @param {string} elementId The DOM id of the item element
 * @returns {undefined}
 */
function dropItem(url, elementId) {
	var confirm = confirmDelete();

	if (confirm) {
		$('body').css('cursor', 'wait');

		var $el = $(document.getElementById(elementId));

		$el.fadeTo('fast', 0.33, function () {
			$.get(url).then(function() {
				$el.remove();
			}).fail(function() {
				// @todo Show related error message
				$el.show();
			}).always(function() {
				$('body').css('cursor', 'auto');
			});
		});
	}
}
