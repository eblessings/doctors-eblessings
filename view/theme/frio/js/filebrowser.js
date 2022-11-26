// @license magnet:?xt=urn:btih:0b31508aeb0634b347b8270c7bee4d411b5d4109&dn=agpl-3.0.txt AGPLv3-or-later
/**
 * Filebrowser - Friendica Communications Server
 *
 * Copyright (c) 2010-2021, the Friendica project
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This code handle user interaction for photo/file upload/browser dialog.
 * Is loaded from filebrowser_plain.tpl
 *
 * To load filebrowser in colorbox, call
 *
 *      Dialog.doImageBrowser(eventname, id);
 *
 * or
 *
 *      Dialog.doFileBrowser(eventname, id);
 *
 * where:
 *
 * 		eventname: event name to catch return value
 * 		id: id returned to event handler
 *
 * When user select an item, an event in fired in parent page, on body element
 * The event is named
 *
 * 		fbrowser.<type>.[<eventname>]
 *
 * <type> will be one of "image" or "file", and the event handler will
 * get the following params:
 *
 * 		filename: filename of item chosen by user
 * 		embed: bbcode to embed element into posts
 * 		id: id from caller code
 *
 * example:
 *
 * 		// open dialog for select an image for a textarea with id "myeditor"
 * 		var id="myeditor";
 * 		Dialog.doImageBrowser("example", id);
 *
 *		// setup event handler to get user selection
 *		$("body").on("fbrowser.image.example", function(event, filename, bbcode, id) {
 *			// close colorbox
 *			$.colorbox.close();
 *			// replace textarea text with bbcode
 *			$(id).value = bbcode;
 *		});
 **/

/*
 * IMPORTANT
 *
 *  This is a modified version to work with
 *  the frio theme.and bootstrap modals
 *
 *  The original file is under:
 *  js/filebrowser.js
 *
 */

var FileBrowser = {
	nickname: '',
	type: '',
	event: '',
	folder: '',
	id: null,

	init: function (nickname, type, hash) {
		FileBrowser.nickname = nickname;
		FileBrowser.type = type;
		FileBrowser.event = 'fbrowser.' + type;

		if (hash !== '') {
			const h = hash.replace('#', '');
			const destination = h.split('-')[0];
			FileBrowser.id = h.split('-')[1];
			FileBrowser.event = FileBrowser.event + '.' + destination;
			if (destination === 'comment') {
				// Get the comment textinput field
				var commentElm = document.getElementById('comment-edit-text-' + FileBrowser.id);
			}
		}

		console.log('FileBrowser: ' + nickname, type, FileBrowser.event, FileBrowser.id);

		FileBrowser.postLoad();

		$('.error .close').on('click', function (e) {
			e.preventDefault();
			$('.error').addClass('hidden');
		});

		// Click on album link
		$('.fbrowser').on('click', '.folders button, .path button', function (e) {
			e.preventDefault();
			let url = FileBrowser._getUrl("none", this.dataset.folder);
			FileBrowser.folder = this.dataset.folder;

			FileBrowser.loadContent(url);
		});

		//Embed on click
		$('.fbrowser').on('click', '.photo-album-photo-link', function (e) {
			e.preventDefault();

			let embed = '';
			if (FileBrowser.type === 'photos') {
				embed = '[url=' + this.dataset.link + '][img=' + this.dataset.img + ']' + this.dataset.alt + '[/img][/url]';
			}
			if (FileBrowser.type === 'attachment') {
				embed = '[attachment]' + this.dataset.link + '[/attachment]';
			}

			// Delete prefilled Text of the comment input
			// Note: not the best solution but function commentOpenUI don't
			// work as expected (we need a way to wait until commentOpenUI would be finished).
			// As for now we insert pieces of this function here
			if (commentElm !== null && typeof commentElm !== 'undefined') {
				if (commentElm.value === '') {
					$('#comment-edit-text-' + FileBrowser.id)
						.addClass('comment-edit-text-full')
						.removeClass('comment-edit-text-empty');
					$('#comment-edit-submit-wrapper-' + FileBrowser.id).show();
					$('#comment-edit-text-' + FileBrowser.id).attr('tabindex', '9');
					$('#comment-edit-submit-' + FileBrowser.id).attr('tabindex', '10');
				}
			}

			console.log(FileBrowser.event, this.dataset.filename, embed, FileBrowser.id);

			$('body').trigger(FileBrowser.event, [this.dataset.filename, embed, FileBrowser.id, this.dataset.img]);

			// Close model
			$('#modal').modal('hide');
			// Update autosize for this textarea
			autosize.update($('.text-autosize'));
		});

		// EventListener for switching between photo and file mode
		$('.fbrowser').on('click', '.fbswitcher .btn', function (e) {
			e.preventDefault();
			FileBrowser.type = this.getAttribute('data-mode');
			$('.fbrowser')
				.removeClass()
				.addClass('fbrowser ' + FileBrowser.type);

			FileBrowser.loadContent(FileBrowser._getUrl("none"));
		});
	},

	// Initialize the AjaxUpload for the upload buttons
	uploadButtons: function () {
		if ($('#upload-photos').length) {
			//AjaxUpload for photos
			new window.AjaxUpload(
				'upload-photos',
				{
					action: 'profile/' + FileBrowser.nickname + '/photos/upload?response=json&album=' + encodeURIComponent(FileBrowser.folder),
					name: 'userfile',
					responseType: 'json',
					onSubmit: function (file, ext) {
						$('.fbrowser-content').hide();
						$('.fbrowser .profile-rotator-wrapper').show();
						$('.error').addClass('hidden');
					},
					onComplete: function (file, response) {
						if (response['error'] !== undefined) {
							$('.error span').html(response['error']);
							$('.error').removeClass('hidden');
							$('.fbrowser .profile-rotator-wrapper').hide();
							$('.fbrowser-content').show();
							return;
						}
						// load new content to fbrowser window
						FileBrowser.loadContent(FileBrowser._getUrl("none"));
					},
				});
		}

		if ($('#upload-attachment').length) {
			//AjaxUpload for files
			new window.AjaxUpload(
				'upload-attachment',
				{
					action: 'profile/' + FileBrowser.nickname + '/attachment/upload?response=json',
					name: 'userfile',
					responseType: 'json',
					onSubmit: function (file, ext) {
						$('.fbrowser-content').hide();
						$('.fbrowser .profile-rotator-wrapper').show();
						$('.error').addClass('hidden');
					},
					onComplete: function (file, response) {
						if (response["error"] !== undefined) {
							$('.error span').html(response['error']);
							$('.error').removeClass('hidden');
							$('.fbrowser .profile-rotator-wrapper').hide();
							$('.fbrowser-content').show();
							return;
						}
						// Load new content to fbrowser window
						FileBrowser.loadContent(FileBrowser._getUrl("none"));
					},
				});
		}
	},

	// Stuff which should be executed if no content was loaded
	postLoad: function () {
		FileBrowser.initGallery();
		$('.fbrowser .fbswitcher .btn').removeClass('active');
		$('.fbrowser .fbswitcher [data-mode=' + FileBrowser.type + ']').addClass('active');
		// We need to add the AjaxUpload to the button
		FileBrowser.uploadButtons();
	},

	// Load new content (e.g. change photo album)
	loadContent: function (url) {
		$('.fbrowser-content').hide();
		$('.fbrowser .profile-rotator-wrapper').show();

		// load new content to fbrowser window
		$('.fbrowser').load(url, function (responseText, textStatus) {
			$('.profile-rotator-wrapper').hide();
			if (textStatus === 'success') {
				$(".fbrowser_content").show();
				FileBrowser.postLoad();
			}
		});
	},

	// Initialize justified Gallery
	initGallery: function () {
		$('.fbrowser.photos .fbrowser-content-container').justifiedGallery({
			rowHeight: 80,
			margins: 4,
			border: 0,
		});
	},

	_getUrl: function (mode, folder) {
		let folderValue = folder !== undefined ? folder : FileBrowser.folder;
		let folderUrl = folderValue !== undefined ? '/' + encodeURIComponent(folderValue) : '';
		return 'profile/' + FileBrowser.nickname + '/' + FileBrowser.type + '/browser' + folderUrl + '?mode=' + mode + "&theme=frio";
	}
};
// @license-end
