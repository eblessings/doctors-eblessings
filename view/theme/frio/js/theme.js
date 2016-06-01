$(document).ready(function(){
	//fade in/out based on scrollTop value
	$(window).scroll(function () {
		if ($(this).scrollTop() > 1000) {
			$("#back-to-top").fadeIn();
		} else {
			$("#back-to-top").fadeOut();
		}
	});
 
	// scroll body to 0px on click
	$("#back-to-top").click(function () {
		$("body,html").animate({
			scrollTop: 0
		}, 400);
		return false;
	});

	// add the class "selected" to group widges li if li > a does have the class group-selected
	if( $("#sidebar-group-ul li a").hasClass("group-selected")) {
		$("#sidebar-group-ul li a.group-selected").parent("li").addClass("selected");
	}

	// add the class "selected" to forums widges li if li > a does have the class forum-selected
	if( $("#forumlist-sidbar-ul li a").hasClass("forum-selected")) {
		$("#forumlist-sidbar-ul li a.forum-selected").parent("li").addClass("selected");
	}

	// add the class "active" to tabmenuli if li > a does have the class active
	if( $("#tabmenu ul li a").hasClass("active")) {
		$("#tabmenu ul li a.active").parent("li").addClass("active");
	}

	// give select fields an boostrap classes
	// @todo: this needs to be changed in friendica core
	$(".field.select, .field.custom").addClass("form-group");
	$(".field.select > select, .field.custom > select").addClass("form-control");

	// move the tabbar to the second nav bar
	if( $("ul.tabbar")) {
		$("ul.tabbar").appendTo("#topbar-second > .container > #tabmenu");
	}

	// add mask css url to the logo-img container
	//
	// This is for firefox - we use a mask which looks like the friendica logo to apply user collers
	// to the friendica logo (the mask is in nav.tpl at the botom). To make it work we need to apply the
	// correct url. The only way which comes to my mind was to do this with js
	// So we apply the correct url (with the link to the id of the mask) after the page is loaded.
	if($("#logo-img")) {
		var pageurl = "url('" + window.location.href + "#logo-mask')";
		$("#logo-img").css({"mask": pageurl});
	}

	// make responsive tabmenu with flexmenu.js
	// the menupoints which doesn't fit in the second nav bar will moved to a 
	// dropdown menu. Look at common_tabs.tpl
	$("ul.tabs.flex-nav").flexMenu({
		'cutoff': 2,
		'popupClass': "dropdown-menu pull-right",
		'popupAbsolute': false,
		'target': ".flex-target"
	});

	// add Jot botton to the scecond navbar
	if( $("section #jotOpen")) {
		$("section #jotOpen").appendTo("#topbar-second > .container > #navbar-button");
		if( $("#jot-popup").is(":hidden")) $("#topbar-second > .container > #navbar-button #jotOpen").hide();
	}

	// move shared content in it's own DIV (so we can style it better)
	$('.wall-item-body .shared_content').each(function() {
		// create a DIV after ".shared_content" where we will putt in the shared_header
		// and the "shared_content"
		$(this).after('<div class="shared-content-wrapper content-card"></div>');
		// get the shared_header
		var sheader = $(this).prev();
		// get the shared-content-wrapper which we have created above
		var swrapper = $(this).next();
		// move the "shared_header into the new shared_content DIV
		$(swrapper).append(sheader);
		// move the "shared_content" into the new DIV
		$(swrapper).append(this);
	});


	// show bulk deletion button at network page if checkbox is checked
	$('input.item-select').change(function(){
		var checked = false;

		// We need to get all checked items, so it would close the delete button
		// if we uncheck one item and others are still checked.
		// So return checked = true if there is any checked item
		$('input.item-select').each( function() {
			if($(this).is(':checked')) {
				checked = true;
				return false;
			}
		});
		
		if(checked == true) {
			$("a#item-delete-selected").fadeTo(400, 1);
			$("a#item-delete-selected").show();
		} else {
			$("a#item-delete-selected").fadeTo(400, 0, function(){
				$("a#item-delete-selected").hide();
			});	
		}
	});

	// add search-heading to the scecond navbar
	if( $(".search-heading")) {
		$(".search-heading").appendTo("#topbar-second > .container > #tabmenu");
	}

	
		
	//$('ul.flex-nav').flexMenu();

	// initialize the bootstrap tooltips
	$('body').tooltip({
		selector: '[data-toggle="tooltip"]',
		animation: true,
		html: true,
		placement: 'auto',
		trigger: 'hover',
		delay: {
			show: 500,
			hide: 100
		}
	});


});
//function commentOpenUI(obj, id) {
//	$(document).unbind( "click.commentOpen", handler );
//
//	var handler = function() {
//		if(obj.value == '{{$comment}}') {
//			obj.value = '';
//			$("#comment-edit-text-" + id).addClass("comment-edit-text-full").removeClass("comment-edit-text-empty");
//			// Choose an arbitrary tab index that's greater than what we're using in jot (3 of them)
//			// The submit button gets tabindex + 1
//			$("#comment-edit-text-" + id).attr('tabindex','9');
//			$("#comment-edit-submit-" + id).attr('tabindex','10');
//			$("#comment-edit-submit-wrapper-" + id).show();
//		}
//	};
//
//	$(document).bind( "click.commentOpen", handler );
//}
//
//function commentCloseUI(obj, id) {
//	$(document).unbind( "click.commentClose", handler );
//
//	var handler = function() {
//		if(obj.value === '') {
//		obj.value = '{{$comment}}';
//			$("#comment-edit-text-" + id).removeClass("comment-edit-text-full").addClass("comment-edit-text-empty");
//			$("#comment-edit-text-" + id).removeAttr('tabindex');
//			$("#comment-edit-submit-" + id).removeAttr('tabindex');
//			$("#comment-edit-submit-wrapper-" + id).hide();
//		}
//	};
//
//	$(document).bind( "click.commentClose", handler );
//}

function openClose(theID) {
	var elem = document.getElementById(theID);

	if( $(elem).is(':visible')) {
		$(elem).slideUp(200);
	}
	else {
		$(elem).slideDown(200);
	}
}

function showHide(theID) {
	if(document.getElementById(theID).style.display == "block") {
		document.getElementById(theID).style.display = "none"
	}
	else {
		document.getElementById(theID).style.display = "block"
	}
}


function showHideComments(id) {
	if( $('#collapsed-comments-' + id).is(':visible')) {
		$('#collapsed-comments-' + id).slideUp();
		$('#hide-comments-' + id).html(window.showMore);
		$('#hide-comments-total-' + id).show();
	}
	else {
		$('#collapsed-comments-' + id).slideDown();
		$('#hide-comments-' + id).html(window.showFewer);
		$('#hide-comments-total-' + id).hide();
	}
}


function justifyPhotos() {
	justifiedGalleryActive = true;
	$('#photo-album-contents').justifiedGallery({
		margins: 3,
		border: 0,
		sizeRangeSuffixes: {
			'lt100': '-2',
			'lt240': '-2',
			'lt320': '-2',
			'lt500': '',
			'lt640': '-1',
			'lt1024': '-0'
		}
	}).on('jg.complete', function(e){ justifiedGalleryActive = false; });
}

function justifyPhotosAjax() {
	justifiedGalleryActive = true;
	$('#photo-album-contents').justifiedGallery('norewind').on('jg.complete', function(e){ justifiedGalleryActive = false; });
}

function loadScript(url, callback) {
	// Adding the script tag to the head as suggested before
	var head = document.getElementsByTagName('head')[0];
	var script = document.createElement('script');
	script.type = 'text/javascript';
	script.src = url;

	// Then bind the event to the callback function.
	// There are several events for cross browser compatibility.
	script.onreadystatechange = callback;
	script.onload = callback;

	// Fire the loading
	head.appendChild(script);
}

function random_digits(digits) {
	var rn = "";
	var rnd = "";

	for(var i = 0; i < digits; i++) {
		var rn = Math.round(Math.random() * (9));
		rnd += rn;
	}

	return rnd;
}

// Does we need a ? or a & to append values to a url
function qOrAmp(url) {
	if(url.search('\\?') < 0) {
		return '?';
	} else {
		return '&';
	}
}

function contact_filter(item) {
	// get the html content from the js template of the contact-wrapper
	contact_tpl = unescape($(".javascript-template[rel=contact-template]").html());

	var variables = {
			id:		item.id,
			name:		item.name,
			username:	item.username,
			thumb:		item.thumb,
			img_hover:	item.img_hover,
			edit_hover:	item.edit_hover,
			account_type:	item.account_type,
			photo_menu:	item.photo_menu,
			alt_text:	item.alt_text,
			dir_icon:	item.dir_icon,
			sparkle:	item.sparkle,
			itemurl:	item.itemurl,
			url:		item.url,
			network:	item.network,
			tags:		item.tags,
			details:	item.details,
	};

	// open a new jSmart instance with the template
	var tpl = new jSmart (contact_tpl);

	// replace the variable with the values
	var html = tpl.fetch(variables);

	return html;
}

function filter_replace(item) {

	return item.name;
}

(function( $ ) {
	$.fn.contact_filter = function(backend_url, typ, autosubmit, onselect) {
		if(typeof typ === 'undefined') typ = '';
		if(typeof autosubmit === 'undefined') autosubmit = false;

		// Autocomplete contacts
		contacts = {
			match: /(^)([^\n]+)$/,
			index: 2,
			search: function(term, callback) { contact_search(term, callback, backend_url, typ); },
			replace: filter_replace,
			template: contact_filter,
		};

		this.attr('autocomplete','off');
		var a = this.textcomplete([contacts], {className:'accontacts', appendTo: '#contact-list'});

		a.on('textComplete:select', function(e, value, strategy) { $(".dropdown-menu.textcomplete-dropdown.media-list").show(); });
	};
})( jQuery );


// current time in milliseconds, to send each request to make sure
// we 're not getting 304 response
function timeNow() {
	return new Date().getTime();
}

String.prototype.normalizeLink = function () {
	var ret = this.replace('https:', 'http:');
	var ret = ret.replace('//www', '//');
	return ret.rtrim();
};

function cleanContactUrl(url) {
	var parts = parseUrl(url);

	if(! ("scheme" in parts) || ! ("host" in parts)) {
		return url;
	}

	var newUrl =parts["scheme"] + "://" + parts["host"];

	if("port" in parts) {
		newUrl += ":" + parts["port"];
	}

	if("path" in parts) {
		newUrl += parts["path"];
	}

//	if(url != newUrl) {
//		console.log("Cleaned contact url " + url + " to " + newUrl);
//	}

	return newUrl;
}

function parseUrl (str, component) { // eslint-disable-line camelcase
	//       discuss at: http://locutusjs.io/php/parse_url/
	//      original by: Steven Levithan (http://blog.stevenlevithan.com)
	// reimplemented by: Brett Zamir (http://brett-zamir.me)
	//         input by: Lorenzo Pisani
	//         input by: Tony
	//      improved by: Brett Zamir (http://brett-zamir.me)
	//           note 1: original by http://stevenlevithan.com/demo/parseuri/js/assets/parseuri.js
	//           note 1: blog post at http://blog.stevenlevithan.com/archives/parseuri
	//           note 1: demo at http://stevenlevithan.com/demo/parseuri/js/assets/parseuri.js
	//           note 1: Does not replace invalid characters with '_' as in PHP,
	//           note 1: nor does it return false with
	//           note 1: a seriously malformed URL.
	//           note 1: Besides function name, is essentially the same as parseUri as
	//           note 1: well as our allowing
	//           note 1: an extra slash after the scheme/protocol (to allow file:/// as in PHP)
	//        example 1: parse_url('http://user:pass@host/path?a=v#a')
	//        returns 1: {scheme: 'http', host: 'host', user: 'user', pass: 'pass', path: '/path', query: 'a=v', fragment: 'a'}
	//        example 2: parse_url('http://en.wikipedia.org/wiki/%22@%22_%28album%29')
	//        returns 2: {scheme: 'http', host: 'en.wikipedia.org', path: '/wiki/%22@%22_%28album%29'}
	//        example 3: parse_url('https://host.domain.tld/a@b.c/folder')
	//        returns 3: {scheme: 'https', host: 'host.domain.tld', path: '/a@b.c/folder'}
	//        example 4: parse_url('https://gooduser:secretpassword@www.example.com/a@b.c/folder?foo=bar')
	//        returns 4: { scheme: 'https', host: 'www.example.com', path: '/a@b.c/folder', query: 'foo=bar', user: 'gooduser', pass: 'secretpassword' }

	var query

	var mode = (typeof require !== 'undefined' ? require('../info/ini_get')('locutus.parse_url.mode') : undefined) || 'php'

	var key = [
		'source',
		'scheme',
		'authority',
		'userInfo',
		'user',
		'pass',
		'host',
		'port',
		'relative',
		'path',
		'directory',
		'file',
		'query',
		'fragment'
	]

	// For loose we added one optional slash to post-scheme to catch file:/// (should restrict this)
	var parser = {
		php: new RegExp([
			'(?:([^:\\/?#]+):)?',
			'(?:\\/\\/()(?:(?:()(?:([^:@\\/]*):?([^:@\\/]*))?@)?([^:\\/?#]*)(?::(\\d*))?))?',
			'()',
			'(?:(()(?:(?:[^?#\\/]*\\/)*)()(?:[^?#]*))(?:\\?([^#]*))?(?:#(.*))?)'
		].join('')),
		strict: new RegExp([
			'(?:([^:\\/?#]+):)?',
			'(?:\\/\\/((?:(([^:@\\/]*):?([^:@\\/]*))?@)?([^:\\/?#]*)(?::(\\d*))?))?',
			'((((?:[^?#\\/]*\\/)*)([^?#]*))(?:\\?([^#]*))?(?:#(.*))?)'
		].join('')),
		loose: new RegExp([
			'(?:(?![^:@]+:[^:@\\/]*@)([^:\\/?#.]+):)?',
			'(?:\\/\\/\\/?)?',
			'((?:(([^:@\\/]*):?([^:@\\/]*))?@)?([^:\\/?#]*)(?::(\\d*))?)',
			'(((\\/(?:[^?#](?![^?#\\/]*\\.[^?#\\/.]+(?:[?#]|$)))*\\/?)?([^?#\\/]*))',
			'(?:\\?([^#]*))?(?:#(.*))?)'
		].join(''))
	}

	var m = parser[mode].exec(str)
	var uri = {}
	var i = 14

	while (i--) {
		if (m[i]) {
			uri[key[i]] = m[i]
		}
	}

	if (component) {
		return uri[component.replace('PHP_URL_', '').toLowerCase()]
	}

	if (mode !== 'php') {
		var name = (typeof require !== 'undefined' ? require('../info/ini_get')('locutus.parse_url.queryKey') : undefined) || 'queryKey'
		parser = /(?:^|&)([^&=]*)=?([^&]*)/g
		uri[name] = {}
		query = uri[key[12]] || ''
		query.replace(parser, function ($0, $1, $2) {
			if ($1) {
				uri[name][$1] = $2
			}
		})
	}

	delete uri.source
	return uri
}

// trim function to replace whithespace after the string
String.prototype.rtrim = function() {
	var trimmed = this.replace(/\s+$/g, '');
	return trimmed;
};

