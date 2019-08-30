// @license magnet:?xt=urn:btih:d3d9a9a6595521f9666a5e94cc830dab83b65699&dn=expat.txt Expat
// @license magnet:?xt=urn:btih:cf05388f2679ee054f2beb29a391d25f4e673ac3&dn=gpl-2.0.txt GPL
/**
 * Copyright (c) 2014 Leonardo Cardoso (http://leocardz.com)
 * Dual licensed under the MIT (http://www.opensource.org/licenses/mit-license.php)
 * and GPL (http://www.opensource.org/licenses/gpl-license.php) licenses.
 * 
 * Restructured by Rabzuarus (https://friendica.kommune4.de/profile/rabuzarus)
 * to use it in the decentralized social network Friendica (https://friendi.ca).
 * 
 * Version: 1.4.0
 */
(function ($) {
	$.fn.linkPreview = function (options) {
		var opts = jQuery.extend({}, $.fn.linkPreview.defaults, options);

		var selector = $(this).selector;
		selector = selector.substr(1);

		var previewTpl = '\
			<div id="preview_' + selector + '" class="preview {0}">\
				{1}\
				<input type="hidden" name="has_attachment" id="hasAttachment_' + selector + '" value="{2}" />\
				<input type="hidden" name="attachment_url" id="attachmentUrl_' + selector + '" value="{3}" />\
				<input type="hidden" name="attachment_type" id="attachmentType_' + selector + '" value="{4}" />\
			</div>';

		var attachmentTpl = '\
			<hr class="previewseparator">\
			<div id="closePreview_' + selector + '" title="Remove" class="closePreview" >\
				<button type="button" class="previewActionBtn">×</button>\
			</div>\
			<div id="previewImages_' + selector + '" class="previewImages">\
				<div id="previewImgBtn_' + selector + '" class="previewImgBtn">\
					<button type="button" id="previewChangeImg_' + selector + '" class="buttonChangeDeactive previewActionBtn" style="display: none">\
						<i class="fa fa-exchange" aria-hidden="true"></i>\
					</button>\
				</div>\
				<div id="previewImage_' + selector + '" class="previewImage">\
				</div>\
				<input type="hidden" id="photoNumber_' + selector + '" class="photoNumber" value="0" />\
				<input type="hidden" name="attachment_img_src" id="attachmentImageSrc_' + selector + '" value="" />\
				<input type="hidden" name="attachment_img_width" id="attachmentImageWidth_' + selector + '" value="0" />\
				<input type="hidden" name="attachment_img_height" id="attachmentImageHeight_' + selector + '" value="0" />\
			</div>\
			<div id="previewContent_' + selector + '" class="previewContent">\
				<h4 id="previewTitle_' + selector + '" class="previewTitle"></h4>\
				<blockquote id="previewDescription_' + selector + '" class="previewDescription"></blockquote>\
				<div id="hiddenDescription_' + selector + '" class="hiddenDescription"></div>\
				<sup id="previewUrl_' + selector + '" class="previewUrl"></sup>\
			</div>\
			<div class="clear"></div>\
			<hr class="previewseparator">';
		var text;
		var urlRegex = /^(?:https?\:\/\/|\s)[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})(?:\/+[a-z0-9_.\:\;-]*)*(?:\?[\&\%\|\+a-z0-9_=,\.\:\;-]*)?(?:[\&\%\|\+&a-z0-9_=,\:\;\.-]*)(?:[\!\#\/\&\%\|\+a-z0-9_=,\:\;\.-]*)}*$/i;
		var binurl;
		var block = false;
		var blockTitle = false;
		var blockDescription = false;
		var cache = {};
		var images = "";
		var isExtern = false;
		var photoNumber = 0;
		var firstPosted = false;
		var isActive = false;
		var isCrawling = false;
		var defaultTitle = opts.defaultTitle;
		var defaultDescription = opts.defaultDescription;

		/**
		 * Initialize the plugin
		 * 
		 * @returns {void}
		 */
		var init = function() {
			$('#' + selector).bind({
				paste: function () {
					setTimeout(function () {
						crawlText();
					}, 100);
				},
				keyup: function (e) {
					// on enter, space, ctrl
					if ((e.which === 13 || e.which === 32 || e.which === 17)) {
						crawlText();
					}
				}
			});

			// Check if we have already attachment bbcode in the textarea
			// and add it to the attachment preview.
			var content = $('#' + selector).val();
			addBBCodeToPreview(content);
		};

		/**
		 * Reset some values.
		 * 
		 * @returns {void}
		 */
		var resetPreview = function() {
			$('#hasAttachment_' + selector).val(0);
			photoNumber = 0;
			images = "";
		};

		/**
		 * Crawl a text string if it contains an url and try
		 * to attach it.
		 * 
		 * If no text is passed to crawlText() we take
		 * the previous word before the cursor of the textarea.
		 * 
		 * @param {string} text (optional)
		 * @returns {void}
		 */
		var crawlText = function (text) {
			block = false;
			images = '';
			isExtern = false;

			// If no text is passed to crawlText() we 
			// take the previous word before the cursor.
			if (typeof text === 'undefined') {
				text = getPrevWord(selector);
			} else {
				isExtern = true;
			}

			// Don't procces the textarea input if we have already
			// an attachment preview.
			if (!isExtern && isActive) {
				return;
			}

			if (trim(text) !== "" && block === false && urlRegex.test(text)) {
				binurl = bin2hex(text);
				block = true;

				isCrawling = true;
				$('#profile-rotator').show();

				if (binurl in cache) {
					isCrawling = false;
					processContentData(cache[binurl]);
				} else {
					getContentData(binurl, processContentData);
				}
			}
		};

		/**
		 * Process the attachment data according to
		 * its content type (image, audio, video, attachment)
		 * 
		 * @param {object} result
		 * @returns {void}
		 */
		var processContentData = function(result) {
			if (result.contentType === 'image') {
				insertImage(result.data);
			}
			if (result.contentType === 'audio') {
				insertAudio(result.data);
			}
			if (result.contentType === 'video') {
				insertVideo(result.data);
			}
			if (result.contentType === 'attachment') {
				insertAttachment(result.data);
			}
			$('#profile-rotator').hide();
		};

		/**
		 * Fetch the content of link which should be attached.
		 * 
		 * @param {string} binurl Link which should be attached as hexadecimal string.
		 * @param {type} callback
		 * @returns {void}
		 */
		var getContentData = function(binurl, callback) {
			$.get('parse_url?binurl='+ binurl + '&format=json', function (answer) {
				obj = sanitizeInputData(answer);

				// Put the data into a cache
				cache[binurl] = obj;

				callback(obj);

				isCrawling = false;
			});
		};

		/*
		 * Add a [img] bbtag with the image url to the jot editor.
		 * 
		 * @param {type} data
		 * @returns {void}
		 */
		var insertImage = function(data) {
			if (!isExtern) {
				return;
			}
			var bbcode = '\n[img]' + data.url + '[/img]\n';
			addeditortext(bbcode);
		};

		/*
		 * Add a [audio] bbtag with the audio url to the jot editor.
		 * 
		 * @param {type} data
		 * @returns {void}
		 */
		var insertAudio = function(data) {
			if (!isExtern) {
				return;
			}
			var bbcode = '\n[audio]' + data.url + '[/audio]\n';
			addeditortext(bbcode);
		};

		/*
		 * Add a [video] bbtag with the video url to the jot editor.
		 * 
		 * @param {type} data
		 * @returns {void}
		 */
		var insertVideo = function(data) {
			if (!isExtern) {
				return;
			}
			var bbcode = '\n[video]' + data.url + '[/video]\n';
			addeditortext(bbcode);
		};

		/**
		 * Proccess all attachment data and show up a html
		 * attachment preview.
		 * 
		 * @param {obj} data Attachment data.
		 * @returns {void}
		 */
		var insertAttachment = function(data) {
			// If we have already a preview, leaver here.
			// Note: if we finish the Preview of other media content type,
			// we can move this condition to the beggining of crawlText();
			if (isActive) {
				$('#profile-rotator').hide();
				return;
			}

			if (data.type !== 'link' && data.type !== 'video' && data.type !== 'photo' || data.url === data.title) {
				$('#profile-rotator').hide();
				return;
			}

			$('#photoNumber_' + selector).val(0);
			resetPreview();

			processAttachmentTpl(data, 'type-' + data.type);
			addTitleDescription(data);
			addHostToAttachment(data.url);
			addImagesToAttachment(data.images);

			processEventListener();
			$('#profile-rotator').hide();
		};

		/**
		 * Construct the attachment html from the attachment template and
		 * add it to the DOM.
		 * 
		 * @param {object} data Attachment data.
		 * @returns {void}
		 */
		var processAttachmentTpl = function(data) {
			// Load and add the template if it isn't allready loaded.
			if ($('#preview_' + selector).length === 0) {
				var tpl = previewTpl.format(
					'type-' + data.type,
					attachmentTpl,
					1,
					bin2hex(data.url),
					data.type
				);
				$('#' + selector).after(tpl);
			}

			isActive = true;
		};

		/**
		 * Add the attachment title and the description
		 * to the attachment preview.
		 * 
		 * @param {object} data Attachment data.
		 * @returns {void}
		 */
		var addTitleDescription = function(data) {
			var description = data.text;

			if (description === '') {
				description = defaultDescription;
			}

			$('#previewTitle_' + selector).html("\
				<span id='previewSpanTitle_" + selector + "' class='previewSpanTitle' >" + escapeHTML(data.title) + "</span>\
				<input type='text' name='attachment_title' value='" + escapeHTML(data.title) + "' id='previewInputTitle_" + selector + "' class='previewInputTitle inputPreview' style='display: none;'/>"
			);

			$('#previewDescription_' + selector).html("\
				<span id='previewSpanDescription_" + selector + "' class='previewSpanDescription' >" + escapeHTML(description) + "</span>\n\
				<textarea id='previewInputDescription_" + selector + "' name='attachment_text' class='previewInputDescription' style='display: none;' class='inputPreview' >" + escapeHTML(data.text) + "</textarea>"
			);
		};

		/**
		 * Add the host to the attachment preview.
		 * 
		 * @param {string} url The url of the link attachment.
		 * @returns {void}
		 */
		var addHostToAttachment = function(url) {
			if (url) {
				var regexpr = "(https?://)([^:^/]*)(:\\d*)?(.*)?";
				var regResult = url.match(regexpr);
				var urlHost = regResult[1] + regResult[2];
				$('#previewUrl_' + selector).html("<a href='" + url + "'>" + urlHost + "</a>");
			}
		};

		/**
		 * Add preview images to the attachment.
		 * 
		 * @param {array} images
		 * 
		 * @returns {void}
		 */
		var addImagesToAttachment = function(images) {
			var imageClass = 'attachment-preview';
	
			if (Array.isArray(images)) {
				$('#previewImages_' + selector).show();
				$('#attachmentImageSrc_' + selector).val(bin2hex(images[photoNumber].src));
				$('#attachmentImageWidth_' + selector).val(images[photoNumber].width);
				$('#attachmentImageHeight_' + selector).val(images[photoNumber].height);
			} else {
				$('#previewImages_' + selector).hide();
			}

			images.length = parseInt(images.length);
			var appendImage = "";

			for (i = 0; i < images.length; i++) {
				// For small preview images we use a smaller attachment format.
				///@todo here we need to add a check for !Config::get('system', 'always_show_preview').
				if (images[i].width >= 500 && images[i].width >= images[i].height) {
						imageClass = 'attachment-image';
				}

				if (i === 0) {
					appendImage += "<img id='imagePreview_" + selector + "_" + i + "' src='" + images[i].src + "' class='" + imageClass + "' ></img>";
				} else {
					appendImage += "<img id='imagePreview_" + selector + "_" + i + "' src='" + images[i].src + "' class='" + imageClass + "' style='display: none;'></img>";
				}
			}

			$('#previewImage_' + selector).html(appendImage + "<div id='whiteImage' style='color: transparent; display:none;'>...</div>");

			// More than just one image.
			if (images.length > 1) {
				// Enable the the button to change the preview pictures.
				$('#previewChangeImg_' + selector).show();

				if (firstPosted === false) {
					firstPosted = true;

					$('#previewChangeImg_' + selector).unbind('click').click(function (e) {
						e.stopPropagation();
						if (images.length > 1) {
							$('#imagePreview_' + selector + '_' + photoNumber).css({
								'display': 'none'
							});
							photoNumber += 1;

							// If have reached the last image, begin with the first image.
							if (photoNumber === images.length) {
								photoNumber = 0;
							}

							$('#imagePreview_' + selector + '_' + photoNumber).css({
								'display': 'block'
							});
							$('#photoNumber_' + selector).val(photoNumber);
							$('#attachmentImageSrc_' + selector).val(bin2hex(images[photoNumber].src));
							$('#attachmentImageWidth_' + selector).val(images[photoNumber].width);
							$('#attachmentImageHeight_' + selector).val(images[photoNumber].height);
						}
					});
				}
			}
		};

		/**
		 * Add event listener to control the attachment preview.
		 * 
		 * @returns {void}
		 */
		var processEventListener = function() {
			$('#previewSpanTitle_' + selector).unbind('click').click(function (e) {
				e.stopPropagation();
				if (blockTitle === false) {
					blockTitle = true;
					$('#previewSpanTitle_' + selector).hide();
					$('#previewInputTitle_' + selector).show();
					$('#previewInputTitle_' + selector).val($('#previewInputTitle_' + selector).val());
					$('#previewInputTitle_' + selector).focus().select();
				}
			});

			$('#previewInputTitle_' + selector).blur(function () {
				blockTitle = false;
				$('#previewSpanTitle_' + selector).html($('#previewInputTitle_' + selector).val());
				$('#previewSpanTitle_' + selector).show();
				$('#previewInputTitle_' + selector).hide();
			});

			$('#previewInputTitle_' + selector).keypress(function (e) {
				if (e.which === 13) {
					blockTitle = false;
					$('#previewSpanTitle_' + selector).html($('#previewInputTitle_' + selector).val());
					$('#previewSpanTitle_' + selector).show();
					$('#previewInputTitle_' + selector).hide();
				}
			});

			$('#previewSpanDescription_' + selector).unbind('click').click(function (e) {
				e.stopPropagation();
				if (blockDescription === false) {
					blockDescription = true;
					$('#previewSpanDescription_' + selector).hide();
					$('#previewInputDescription_' + selector).show();
					$('#previewInputDescription_' + selector).val($('#previewInputDescription_' + selector).val());
					$('#previewInputDescription_' + selector).focus().select();
				}
			});

			$('#previewInputDescription_' + selector).blur(function () {
				blockDescription = false;
				$('#previewSpanDescription_' + selector).html($('#previewInputDescription_' + selector).val());
				$('#previewSpanDescription_' + selector).show();
				$('#previewInputDescription_' + selector).hide();
			});

			$('#previewInputDescription_' + selector).keypress(function (e) {
				if (e.which === 13) {
					blockDescription = false;
					$('#previewSpanDescription_' + selector).html($('#previewInputDescription_' + selector).val());
					$('#previewSpanDescription_' + selector).show();
					$('#previewInputDescription_' + selector).hide();
				}
			});

			$('#previewSpanTitle_' + selector).mouseover(function () {
				$('#previewSpanTitle_' + selector).css({
					"background-color": "#ff9"
				});
			});

			$('#previewSpanTitle_' + selector).mouseout(function () {
				$('#previewSpanTitle_' + selector).css({
					"background-color": "transparent"
				});
			});

			$('#previewSpanDescription_' + selector).mouseover(function () {
				$('#previewSpanDescription_' + selector).css({
					"background-color": "#ff9"
				});
			});

			$('#previewSpanDescription_' + selector).mouseout(function () {
				$('#previewSpanDescription_' + selector).css({
					"background-color": "transparent"
				});
			});

			$('#closePreview_' + selector).unbind('click').click(function (e) {
				e.stopPropagation();
				block = false;
				images = '';
				isActive = false;
				firstPosted = false;
				$('#preview_' + selector).fadeOut("fast", function () {
					$('#preview_' + selector).remove();
					$('#profile-rotator').hide();
					$('#' + selector).focus();
				});

			});
		};

		/**
		 * Convert attachmant bbcode into an array.
		 * 
		 * @param {string} content Text content with the attachment bbcode.
		 * @returns {object || null}
		 */
		var getAttachmentData = function(content) {
			var data = {};

			var match = content.match(/([\s\S]*)\[attachment([\s\S]*?)\]([\s\S]*?)\[\/attachment\]([\s\S]*)/im);
			if (match === null || match.length < 5) {
				return null;
			}

			var attributes = match[2];
			data.text = trim(match[1]);

			var type = '';
			var matches = attributes.match(/type='([\s\S]*?)'/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				type = matches[1].toLowerCase();
			}

			matches = attributes.match(/type="([\s\S]*?)"/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				type = matches[1].toLowerCase();
			}

			if (type === '') {
				return null;
			}

			if (
				type !== 'link'
				&& type !== 'audio'
				&& type !== 'photo'
				&& type !== 'video')
			{
				return null;
			}

			if (type !== '') {
				data.type = type;
			}

			var url = '';

			matches = attributes.match(/url='([\s\S]*?)'/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				url = matches[1].toLowerCase();
			}

			matches = attributes.match(/url="([\s\S]*?)"/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				url = matches[1].toLowerCase();
			}

			if(url !== '') {
				data.url = escapeHTML(url);
			}

			var title = '';

			matches = attributes.match(/title='([\s\S]*?)'/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				title = matches[1].toLowerCase();
			}

			matches = attributes.match(/title="([\s\S]*?)"/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				title = matches[1].toLowerCase();
			}

			if (title !== '') {
				data.title = escapeHTML(title);
			}

			var image = '';

			matches = attributes.match(/image='([\s\S]*?)'/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				image = matches[1].toLowerCase();
			}

			matches = attributes.match(/image="([\s\S]*?)"/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				image = matches[1].toLowerCase();
			}

			if (image !== '') {
				data.image = escapeHTML(image);
			}

			var preview = '';

			matches = attributes.match(/preview='([\s\S]*?)'/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				preview = matches[1].toLowerCase();
			}

			matches = attributes.match(/preview="([\s\S]*?)"/im);
			if (matches !== null && typeof matches[1] !== 'undefined') {
				preview = matches[1].toLowerCase();
			}

			if (preview !== '') {
				data.preview = escapeHTML(preview);
			}

			data.text = trim(match[3]);
			data.after = trim(match[4]);

			return data;
		};

		/**
		 * Process txt content and if it contains attachment bbcode
		 * add it to the attachment preview .
		 * 
		 * @param {string} content
		 * @returns {void}
		 */
		var addBBCodeToPreview =function(content) {
			var attachmentData = getAttachmentData(content);
			if (attachmentData) {
				reAddAttachment(attachmentData);
				// Remove the attachment bbcode from the textarea.
				var content = content.replace(/\[attachment[\s\S]*\[\/attachment]/im, '');
				$('#' + selector).val(content);
				$('#' + selector).focus();
			}
		};

		/**
		 * Add an Attachment with data from an old bbcode
		 * generated attachment.
		 * 
		 * @param {object} json The attachment data.
		 * @returns {void}
		 */
		var reAddAttachment = function(json) {
			if (isActive) {
				$('#profile-rotator').hide();
				return;
			}

			if (json.type !== 'link' && json.type !== 'video' && json.type !== 'photo' || json.url === json.title) {
				$('#profile-rotator').hide();
				return;
			}

			var obj = {data: json};
			obj = sanitizeInputData(obj);

			var data = obj.data;

			resetPreview();

			processAttachmentTpl(data);
			addTitleDescription(data);
			addHostToAttachment(data.url);

			// Since we don't have an array of image data,
			// we need to add the preview images in a different way
			// than in function addImagesToAttachment().
			var imageClass = 'attachment-preview';
			var image = '';

			if (data.image !== '') {
				imageClass = 'attachment-image';
				image = data.image;
			} else {
				image = data.preview;
			}

			if (image !== '') {
				var appendImage = "<img id='imagePreview_" + selector + "' src='" + image + "' class='" + imageClass + "' ></img>"
				$('#previewImage_' + selector).html(appendImage);
				$('#attachmentImageSrc_' + selector).val(bin2hex(image));

				// We need to add the image widht and height when it is 
				// loaded.
				$('<img/>' ,{
					load : function(){
						$('#attachmentImageWidth_' + selector).val(this.width);
						$('#attachmentImageHeight_' + selector).val(this.height);
					},
					src  : image
				});
			}

			processEventListener();
			$('#profile-rotator').hide();
		};

		/**
		 * Add missing default properties to the input data object.
		 * 
		 * @param {object} obj Input data.
		 * @returns {object}
		 */
		var sanitizeInputData = function(obj) {
			if (typeof obj.contentType === 'undefined'
				|| obj.contentType === null)
			{
				obj.contentType = "";
			}
			if (typeof obj.data.url === 'undefined'
				|| obj.data.url === null)
			{
				obj.data.url = "";
			}
			if (typeof obj.data.title === 'undefined'
				|| obj.data.title === null
				|| obj.data.title === "")
			{
				obj.data.title = defaultTitle;
			}
			if (typeof obj.data.text === 'undefined'
				|| obj.data.text === null
				|| obj.data.text === "")
			{
				obj.data.text = "";
			}
			if (typeof obj.data.images === 'undefined'
				|| obj.data.images === null)
			{
				obj.data.images = "";
			}

			if (typeof obj.data.image === 'undefined'
				|| obj.data.image === null)
			{
				obj.data.image = "";
			}

			if (typeof obj.data.preview === 'undefined'
				|| obj.data.preview === null)
			{
				obj.data.preview = "";
			}

			return obj;
		};

		/**
		 * Destroy the plugin.
		 * 
		 * @returns {void}
		 */
		var destroy = function() {
			$('#' + selector).unbind();
			$('#preview_' + selector).remove();
			binurl;
			block = false;
			blockTitle = false;
			blockDescription = false;
			cache = {};
			images = "";
			isExtern = false;
			photoNumber = 0;
			firstPosted = false;
			isActive = false;
			isCrawling = false;
			selector = "";
		};

		var trim = function(str) {
			return str.replace(/^\s+|\s+$/g, "");
		};
		var escapeHTML = function(unsafe_str) {
			return unsafe_str
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/\"/g, '&quot;')
				.replace(/\[/g, '&#91;')
				.replace(/\]/g, '&#93;')
				.replace(/\'/g, '&#39;'); // '&apos;' is not valid HTML 4
		};

		// Initialize LinkPreview 
		init();

		return {
			// make crawlText() accessable from the outside.
			crawlText: function(text) {
				crawlText(text);
			},
			addBBCodeToPreview: function(content) {
				addBBCodeToPreview(content);
			},
			destroy: function() {
				destroy();
			}
		};
	};

	$.fn.linkPreview.defaults = {
		defaultDescription: "Enter a description",
		defaultTitle: "Enter a title"
	};

	/**
	* Get in a textarea the previous word before the cursor.
	* 
	* @param {object} text Textarea elemet.
	* @param {integer} caretPos Cursor position.
	* 
	* @returns {string} Previous word.
	*/
	function returnWord(text, caretPos) {
		var index = text.indexOf(caretPos);
		var preText = text.substring(0, caretPos);
		// If the last charachter is a space or enter remove it
		// We need this in friendica for the url  preview.
		var lastChar = preText.slice(-1)
		if ( lastChar === " "
			|| lastChar === "\n"
			|| lastChar === "\r"
			)
		{
			preText = preText.substring(0, preText.length -1);
		}

		// Replace new line with space.
		preText = preText.replace(/\n/g, " ");

		if (preText.indexOf(" ") > 0) {
			var words = preText.split(" ");
			return words[words.length - 1]; //return last word
		}
		else {
			return preText;
		}
	}

	/**
	 * Get in a textarea the previous word before the cursor.
	 * 
	 * @param {string} id The ID of a textarea element.
	 * @returns {sting|null} Previous word or null if no word is available.
	 */
	function getPrevWord(id) {
		var text = document.getElementById(id);
		var caretPos = getCaretPosition(text);
		var word = returnWord(text.value, caretPos);
		if (word != null) {
			return word
		}

	}

	/**
	 * Get the cursor posiotion in an text element.
	 * 
	 * @param {object} ctrl Textarea elemet.
	 * @returns {integer} Position of the cursor.
	 */
	function getCaretPosition(ctrl) {
		var CaretPos = 0;   // IE Support
		if (document.selection) {
			ctrl.focus();
			var Sel = document.selection.createRange();
			Sel.moveStart('character', -ctrl.value.length);
			CaretPos = Sel.text.length;
		}
		// Firefox support
		else if (ctrl.selectionStart || ctrl.selectionStart == '0') {
			CaretPos = ctrl.selectionStart;
		}
		return (CaretPos);
	}
})(jQuery);

// @license-end
