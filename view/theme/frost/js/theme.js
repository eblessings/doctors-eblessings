$j(document).ready(function() {

	window.navMenuTimeout = {
		'#network-menu-list-timeout': null,
		'#contacts-menu-list-timeout': null,
		'#system-menu-list-timeout': null,
		'#network-menu-list-opening': false,
		'#contacts-menu-list-opening': false,
		'#system-menu-list-opening': false,
		'#network-menu-list-closing': false,
		'#contacts-menu-list-closing': false,
		'#system-menu-list-closing': false
	};

/*    $j.ajaxSetup({ 
        cache: false 
    });*/


	/* enable tinymce on focus and click */
	$j("#profile-jot-text").focus(enableOnUser);
	$j("#profile-jot-text").click(enableOnUser);

	$j('.nav-menu-list, .nav-menu-icon').hover(function() {
		showNavMenu($j(this).attr('point'));
	}, function() {
		hideNavMenu($j(this).attr('point'));
	});

/*	$j('html').click(function() { $j("#nav-notifications-menu" ).hide(); });*/

	$j('.group-edit-icon').hover(
		function() {
			$j(this).addClass('icon'); $j(this).removeClass('iconspacer');},
		function() {
			$j(this).removeClass('icon'); $j(this).addClass('iconspacer');}
		);

	$j('.sidebar-group-element').hover(
		function() {
			id = $j(this).attr('id');
			$j('#edit-' + id).addClass('icon'); $j('#edit-' + id).removeClass('iconspacer');},

		function() {
			id = $j(this).attr('id');
			$j('#edit-' + id).removeClass('icon');$j('#edit-' + id).addClass('iconspacer');}
		);


	$j('.savedsearchdrop').hover(
		function() {
			$j(this).addClass('drop'); $j(this).addClass('icon'); $j(this).removeClass('iconspacer');},
		function() {
			$j(this).removeClass('drop'); $j(this).removeClass('icon'); $j(this).addClass('iconspacer');}
	);

	$j('.savedsearchterm').hover(
		function() {
			id = $j(this).attr('id');
			$j('#drop-' + id).addClass('icon'); 	$j('#drop-' + id).addClass('drophide'); $j('#drop-' + id).removeClass('iconspacer');},

		function() {
			id = $j(this).attr('id');
			$j('#drop-' + id).removeClass('icon');$j('#drop-' + id).removeClass('drophide'); $j('#drop-' + id).addClass('iconspacer');}
	);

/*	$j('.nav-load-page-link').click(function() {
		getPageContent( $j(this).attr('href') );
		hideNavMenu( '#' + $j(this).closest('ul').attr('id') );
		return false;
	});*/

	$j('#event-share-checkbox').change(function() {

		if ($j('#event-share-checkbox').is(':checked')) { 
			$j('#acl-wrapper').show();
		}
		else {
			$j('#acl-wrapper').hide();
		}
	}).trigger('change');

// For event_end.tpl
/*		$j('#contact_allow, #contact_deny, #group_allow, #group_deny').change(function() {
			var selstr;
			$j('#contact_allow option:selected, #contact_deny option:selected, #group_allow option:selected, #group_deny option:selected').each( function() {
				selstr = $j(this).text();
				$j('#jot-public').hide();
			});
			if(selstr == null) {
				$j('#jot-public').show();
			}

		}).trigger('change');*/


	if(typeof window.AjaxUpload != "undefined") {
		var uploader = new window.AjaxUpload(
			window.imageUploadButton,
			{ action: 'wall_upload/'+window.nickname,
				name: 'userfile',
				onSubmit: function(file,ext) { $j('#profile-rotator').show(); },
				onComplete: function(file,response) {
					addeditortext(window.jotId, response);
					$j('#profile-rotator').hide();
				}				 
			}
		);

		if($j('#wall-file-upload').length) {
			var file_uploader = new window.AjaxUpload(
				'wall-file-upload',
				{ action: 'wall_attach/'+window.nickname,
					name: 'userfile',
					onSubmit: function(file,ext) { $j('#profile-rotator').show(); },
					onComplete: function(file,response) {
						addeditortext(window.jotId, response);
						$j('#profile-rotator').hide();
					}				 
				}
			);
		}
	}


	if(typeof window.aclInit !="undefined" && typeof acl=="undefined"){
		acl = new ACL(
			baseurl+"/acl",
			[ window.allowCID,window.allowGID,window.denyCID,window.denyGID ]
		);
	}


	if(window.aclType == "settings-head" || window.aclType == "photos_head" || window.aclType == "event_head") {
		$j('#contact_allow, #contact_deny, #group_allow, #group_deny').change(function() {
			var selstr;
			$j('#contact_allow option:selected, #contact_deny option:selected, #group_allow option:selected, #group_deny option:selected').each( function() {
				selstr = $j(this).text();
				$j('#jot-perms-icon').removeClass('unlock').addClass('lock');
				$j('#jot-public').hide();
			});
			if(selstr == null) { 
				$j('#jot-perms-icon').removeClass('lock').addClass('unlock');
				$j('#jot-public').show();
			}

		}).trigger('change');
	}

	if(window.aclType == "event_head") {
		$j('#events-calendar').fullCalendar({
			events: baseurl + '/events/json/',
			header: {
				left: 'prev,next today',
				center: 'title',
				right: 'month,agendaWeek,agendaDay'
			},			
			timeFormat: 'H(:mm)',
			eventClick: function(calEvent, jsEvent, view) {
				showEvent(calEvent.id);
			},
			
			eventRender: function(event, element, view) {
				//console.log(view.name);
				if (event.item['author-name']==null) return;
				switch(view.name){
					case "month":
					element.find(".fc-event-title").html(
						"<img src='{0}' style='height:10px;width:10px'>{1} : {2}".format(
							event.item['author-avatar'],
							event.item['author-name'],
							event.title
					));
					break;
					case "agendaWeek":
					element.find(".fc-event-title").html(
						"<img src='{0}' style='height:12px; width:12px'>{1}<p>{2}</p><p>{3}</p>".format(
							event.item['author-avatar'],
							event.item['author-name'],
							event.item.desc,
							event.item.location
					));
					break;
					case "agendaDay":
					element.find(".fc-event-title").html(
						"<img src='{0}' style='height:24px;width:24px'>{1}<p>{2}</p><p>{3}</p>".format(
							event.item['author-avatar'],
							event.item['author-name'],
							event.item.desc,
							event.item.location
					));
					break;
				}
			}
			
		});
		
		// center on date
		var args=location.href.replace(baseurl,"").split("/");
		if (args.length>=4) {
			$j("#events-calendar").fullCalendar('gotoDate',args[2] , args[3]-1);
		} 
		
		// show event popup
		var hash = location.hash.split("-")
		if (hash.length==2 && hash[0]=="#link") showEvent(hash[1]);
	}	


	switch(window.autocompleteType) {
		case 'msg-header':
			var a = $j("#recip").autocomplete({ 
				serviceUrl: baseurl + '/acl',
				minChars: 2,
				width: 350,
				onSelect: function(value,data) {
					$j("#recip-complete").val(data);
				}			
			});
			break;
		case 'contacts-head':
			var a = $j("#contacts-search").autocomplete({ 
				serviceUrl: baseurl + '/acl',
				minChars: 2,
				width: 350,
			});
			a.setOptions({ params: { type: 'a' }});
			break;
		case 'display-head':
			$j(".comment-wwedit-wrapper textarea").contact_autocomplete(baseurl+"/acl");
			break;
		default:
			break;
	}

/*	if(window.autoCompleteType == "display-head") {
		//$j(".comment-edit-wrapper textarea").contact_autocomplete(baseurl+"/acl");
		// make auto-complete work in more places
		//$j(".wall-item-comment-wrapper textarea").contact_autocomplete(baseurl+"/acl");
		$j(".comment-wwedit-wrapper textarea").contact_autocomplete(baseurl+"/acl");
	}*/

	// Add Colorbox for viewing Network page images
	//var cBoxClasses = new Array();
	$j(".wall-item-body a img").each(function(){
		var aElem = $j(this).parent();
		var imgHref = aElem.attr("href");

		// We need to make sure we only put a Colorbox on links to Friendica images
		// We'll try to do this by looking for links of the form
		// .../photo/ab803d8eg08daf85023adfec08 (with nothing more following), in hopes
		// that that will be unique enough
		if(imgHref.match(/\/photo\/[a-fA-F0-9]+(-[0-9]\.[\w]+?)?$/)) {

			// Add a unique class to all the images of a certain post, to allow scrolling through
			var cBoxClass = $j(this).closest(".wall-item-body").attr("id") + "-lightbox";
			$j(this).addClass(cBoxClass);

//			if( $j.inArray(cBoxClass, cBoxClasses) < 0 ) {
//				cBoxClasses.push(cBoxClass);
//			}

			aElem.colorbox({
				maxHeight: '90%',
				photo: true, // Colorbox doesn't recognize a URL that don't end in .jpg, etc. as a photo
				rel: cBoxClass //$j(this).attr("class").match(/wall-item-body-[\d]+-lightbox/)[0]
			});
		}
	});
	/*$j.each(cBoxClasses, function(){
		$j('.'+this).colorbox({
			maxHeight: '90%',
			photo: true,
			rel: this
		});
	});*/

});


// update pending count //
$j(function(){

	$j("nav").bind('nav-update',  function(e,data){
		var elm = $j('#pending-update');
		var register = $j(data).find('register').text();
		if (register=="0") { register=""; elm.hide();} else { elm.show(); }
		elm.html(register);
	});
});


$j(function(){
	
	$j("#cnftheme").click(function(){
		$.colorbox({
			width: 800,
			height: '90%',
			href: baseurl + "/admin/themes/" + $("#id_theme :selected").val(),
			onComplete: function(){
				$j("div#fancybox-content form").submit(function(e){
					var url = $j(this).attr('action');
					// can't get .serialize() to work...
					var data={};
					$j(this).find("input").each(function(){
						data[$j(this).attr('name')] = $j(this).val();
					});
					$j(this).find("select").each(function(){
						data[$j(this).attr('name')] = $j(this).children(":selected").val();
					});
					console.log(":)", url, data);
				
					$j.post(url, data, function(data) {
						if(timer) clearTimeout(timer);
						NavUpdate();
						$j.colorbox.close();
					})
				
					return false;
				});
			
			}
		});
		return false;
	});
});


function homeRedirect() {
	$j('html').fadeOut('slow', function(){
		window.location = baseurl + "/login";
	});
}


if(typeof window.photoEdit != 'undefined') {

	$j(document).keydown(function(event) {

			if(window.prevLink != '') { if(event.ctrlKey && event.keyCode == 37) { event.preventDefault(); window.location.href = window.prevLink; }}
			if(window.nextLink != '') { if(event.ctrlKey && event.keyCode == 39) { event.preventDefault(); window.location.href = window.nextLink; }}

	});
}

function showEvent(eventid) {
	$j.get(
		baseurl + '/events/?id='+eventid,
		function(data){
			$j.colorbox({html:data});
			$j.colorbox.resize();
		}
	);			
}

function initCrop() {
	function onEndCrop( coords, dimensions ) {
		$( 'x1' ).value = coords.x1;
		$( 'y1' ).value = coords.y1;
		$( 'x2' ).value = coords.x2;
		$( 'y2' ).value = coords.y2;
		$( 'width' ).value = dimensions.width;
		$( 'height' ).value = dimensions.height;
	}

	Event.observe( window, 'load', function() {
		new Cropper.ImgWithPreview(
		'croppa',
		{
			previewWrap: 'previewWrap',
			minWidth: 175,
			minHeight: 175,
			maxWidth: 640,
			maxHeight: 640,
			ratioDim: { x: 100, y:100 },
			displayOnInit: true,
			onEndCrop: onEndCrop
		});
	});
}


/*
$j(document).mouseup(function (clickPos) {

	var sysMenu = $j("#system-menu-list");
	var sysMenuLink = $j(".system-menu-link");
	var contactsMenu = $j("#contacts-menu-list");
	var contactsMenuLink = $j(".contacts-menu-link");
	var networkMenu = $j("#network-menu-list");
	var networkMenuLink = $j(".network-menu-link");

	if( !sysMenu.is(clickPos.target) && !sysMenuLink.is(clickPos.target) && sysMenu.has(clickPos.target).length === 0) {
		hideNavMenu("#system-menu-list");
	}
	if( !contactsMenu.is(clickPos.target) && !contactsMenuLink.is(clickPos.target) && contactsMenu.has(clickPos.target).length === 0) {
		hideNavMenu("#contacts-menu-list");
	}
	if( !networkMenu.is(clickPos.target) && !networkMenuLink.is(clickPos.target) && networkMenu.has(clickPos.target).length === 0) {
		hideNavMenu("#network-menu-list");
	}
});


function getPageContent(url) {

	var pos = $j('.main-container').position();

	$j('.main-container').css('margin-left', pos.left);	
	$j('.main-content-container').hide(0, function () {
		$j('.main-content-loading').show(0);
	});

	$j.get(url, function(html) {
		console.log($j('.main-content-container').html());
		$j('.main-content-container').html( $j('.main-content-container', html).html() );
		console.log($j('.main-content-container').html());
		$j('.main-content-loading').hide(function() {
			$j('.main-content-container').fadeIn(800,function() {
				$j('.main-container').css('margin-left', 'auto'); // This sucks -- if the CSS specification changes, this will be wrong
			});
		});
	});
}
*/

function showNavMenu(menuID) {

	if(window.navMenuTimeout[menuID + '-closing']) {
		window.navMenuTimeout[menuID + '-closing'] = false;
		clearTimeout(window.navMenuTimeout[menuID + '-timeout']);
	}
	else {
		window.navMenuTimeout[menuID + '-opening'] = true;
		
		window.navMenuTimeout[menuID + '-timeout'] = setTimeout( function () {
			$j(menuID).slideDown('fast').show();
			window.navMenuTimeout[menuID + '-opening'] = false;
		}, 200);
	}
}

function hideNavMenu(menuID) {

	if(window.navMenuTimeout[menuID + '-opening']) {
		window.navMenuTimeout[menuID + '-opening'] = false;
		clearTimeout(window.navMenuTimeout[menuID + '-timeout']);
	}
	else {
		window.navMenuTimeout[menuID + '-closing'] = true;
		
		window.navMenuTimeout[menuID + '-timeout'] = setTimeout( function () {
			$j(menuID).slideUp('fast');
			window.navMenuTimeout[menuID + '-closing'] = false;
		}, 500);
	}
}



/*
 * TinyMCE/Editor
 */

function InitMCEEditor(editorData) {
	var tinyMCEInitConfig = {
		theme : "advanced",
		//mode : // SPECIFIC
		//editor_selector: // SPECIFIC
		//elements: // SPECIFIC
		plugins : "bbcode,paste,autoresize,inlinepopups",
		theme_advanced_buttons1 : "bold,italic,underline,undo,redo,link,unlink,image,forecolor,formatselect,code",
		theme_advanced_buttons2 : "",
		theme_advanced_buttons3 : "",
		theme_advanced_toolbar_location : "top",
		theme_advanced_toolbar_align : "center",
		theme_advanced_blockformats : "blockquote,code",
		gecko_spellcheck : true,
		paste_text_sticky : true, // COUPLED WITH paste PLUGIN
		entity_encoding : "raw",
		add_unload_trigger : false,
		remove_linebreaks : false,
		//force_p_newlines : false,
		//force_br_newlines : true,
		forced_root_block : 'div',
		//convert_urls: false, //SPECIFIC?
		content_css: baseurl + "/view/custom_tinymce.css",
		theme_advanced_path : false,
		file_browser_callback : "fcFileBrowser",
		//setup : // SPECIFIC
	};

	if(window.editSelect != 'none') {
		$j.extend(tinyMCEInitConfig, editorData);
		tinyMCE.init(tinyMCEInitConfig);
	}
	else if(typeof editorData.plaintextFn == 'function') {
		(editorData.plaintextFn)();
	}
}

var editor = false;
var textlen = 0;

function initEditor(cb){
	if(editor==false) {
		editor = true;
		$j("#profile-jot-text-loading").show();

		var editorData = {
			mode : "specific_textareas",
			editor_selector : "profile-jot-text",
			auto_focus : "profile-jot-text",
			//plugins : "bbcode,paste,autoresize,inlinepopups",
			//paste_text_sticky : true,
			convert_urls : false,
			setup : function(ed) {
				cPopup = null;
				ed.onKeyDown.add(function(ed,e) {
					if(cPopup !== null)
						cPopup.onkey(e);
				});

				ed.onKeyUp.add(function(ed, e) {
					var txt = tinyMCE.activeEditor.getContent();
					match = txt.match(/@([^ \n]+)$/);
					if(match!==null) {
						if(cPopup === null) {
							cPopup = new ACPopup(this,baseurl+"/acl");
						}
						if(cPopup.ready && match[1]!==cPopup.searchText) cPopup.search(match[1]);
						if(! cPopup.ready) cPopup = null;
					}
					else {
						if(cPopup !== null) { cPopup.close(); cPopup = null; }
					}

					textlen = txt.length;
					if(textlen != 0 && $j('#jot-perms-icon').is('.unlock')) {
						$j('#profile-jot-desc').html(window.isPublic);
					}
					else {
						$j('#profile-jot-desc').html('&nbsp;');
					}	 

				 //Character count

					if(textlen <= 140) {
						$j('#character-counter').removeClass('red');
						$j('#character-counter').removeClass('orange');
						$j('#character-counter').addClass('grey');
					}
					if((textlen > 140) && (textlen <= 420)) {
						$j('#character-counter').removeClass('grey');
						$j('#character-counter').removeClass('red');
						$j('#character-counter').addClass('orange');
					}
					if(textlen > 420) {
						$j('#character-counter').removeClass('grey');
						$j('#character-counter').removeClass('orange');
						$j('#character-counter').addClass('red');
					}
					$j('#character-counter').text(textlen);
				});

				ed.onInit.add(function(ed) {
					ed.pasteAsPlainText = true;
					$j("#profile-jot-text-loading").hide();
					$j(".jothidden").show();
					if (typeof cb!="undefined") cb();
				});

			},
			plaintextFn : function() {
				$j("#profile-jot-text-loading").hide();
				$j("#profile-jot-text").css({ 'height': 200, 'color': '#000' });
				$j("#profile-jot-text").contact_autocomplete(baseurl+"/acl");
				$j(".jothidden").show();
				if (typeof cb!="undefined") cb();
			}
		};
		InitMCEEditor(editorData);

		// setup acl popup
		$j("a#jot-perms-icon").colorbox({
			'inline' : true,
			'transition' : 'elastic'
		}); 
	} else {
		if (typeof cb!="undefined") cb();
	}
}

function enableOnUser(){
	if (editor) return;
	$j(this).val("");
	initEditor();
}


function msgInitEditor() {
	var editorData = {
		mode : "specific_textareas",
		editor_selector : "prvmail-text",
		//plugins : "bbcode,paste",
		//paste_text_sticky : true,
		convert_urls : false,
		//theme_advanced_path : false,
		setup : function(ed) {
			cPopup = null;
			ed.onKeyDown.add(function(ed,e) {
				if(cPopup !== null)
					cPopup.onkey(e);
			});

			ed.onKeyUp.add(function(ed, e) {
				var txt = tinyMCE.activeEditor.getContent();
				match = txt.match(/@([^ \n]+)$/);
				if(match!==null) {
					if(cPopup === null) {
						cPopup = new ACPopup(this,baseurl+"/acl");
					}
					if(cPopup.ready && match[1]!==cPopup.searchText) cPopup.search(match[1]);
					if(! cPopup.ready) cPopup = null;
				}
				else {
					if(cPopup !== null) { cPopup.close(); cPopup = null; }
				}

				textlen = txt.length;
				if(textlen != 0 && $j('#jot-perms-icon').is('.unlock')) {
					$j('#profile-jot-desc').html(window.isPublic);
				}
				else {
					$j('#profile-jot-desc').html('&nbsp;');
				}	 
			});

			ed.onInit.add(function(ed) {
				ed.pasteAsPlainText = true;
				var editorId = ed.editorId;
				var textarea = $j('#'+editorId);
				if (typeof(textarea.attr('tabindex')) != "undefined") {
					$j('#'+editorId+'_ifr').attr('tabindex', textarea.attr('tabindex'));
					textarea.attr('tabindex', null);
				}
			});
		},
		plaintextFn : function() {
			$j("#prvmail-text").contact_autocomplete(baseurl+"/acl");
		}
	}
	InitMCEEditor(editorData);
}


function contactInitEditor() {
	var editorData = {
		mode : "exact",
		elements : "contact-edit-info",
		//plugins : "bbcode"
	}
	InitMCEEditor(editorData);
}


function eventInitEditor() {
	var editorData = {
		mode : "textareas",
		//plugins : "bbcode,paste",
		//paste_text_sticky : true,
		//theme_advanced_path : false,
		setup : function(ed) {
			ed.onInit.add(function(ed) {
				ed.pasteAsPlainText = true;
			});
		}
	}
	InitMCEEditor(editorData);
}


function profInitEditor() {
	var editorData = {
		mode : "textareas",
		//plugins : "bbcode,paste",
		//paste_text_sticky : true,
		//theme_advanced_path : false,
		setup : function(ed) {
			ed.onInit.add(function(ed) {
				ed.pasteAsPlainText = true;
			});
		}
	}
	InitMCEEditor(editorData);
}


/*
 * Jot
 */

function addeditortext(textElem, data) {
	if(window.editSelect == 'none') {
		var currentText = $j(textElem).val();
		$j(textElem).val(currentText + data);
	}
	else
		tinyMCE.execCommand('mceInsertRawHTML',false,data);
}

function jotVideoURL() {
	reply = prompt(window.vidURL);
	if(reply && reply.length) {
		addeditortext("#profile-jot-text", '[video]' + reply + '[/video]');
	}
}

function jotAudioURL() {
	reply = prompt(window.audURL);
	if(reply && reply.length) {
		addeditortext("#profile-jot-text", '[audio]' + reply + '[/audio]');
	}
}


function jotGetLocation() {

/*	if(navigator.geolocation) {

		navigator.geolocation.getCurrentPosition(function(position) {
			var lat = position.coords.latitude;
			var lng = position.coords.longitude;

			$j.ajax({
				type: 'GET',
				url: 'http://nominatim.openstreetmap.org/reverse?format=json&lat='+lat+'&lon='+lng,
				jsonp: 'json_callback',
				contentType: 'application/json',
				dataType: 'jsonp',
				success: function(json) {
					console.log(json);
					var locationDisplay = json.address.building+', '+json.address.city+', '+json.address.state;
					$j('#jot-location').val(locationDisplay);
					$j('#jot-display-location').html('Location: '+locationDisplay);
					$j('#jot-display-location').show();
				}
			});
		});

	}
	else {
		reply = prompt(window.whereAreU, $j('#jot-location').val());
		if(reply && reply.length) {
			$j('#jot-location').val(reply);
		}
	}*/

	reply = prompt(window.whereAreU, $j('#jot-location').val());
	if(reply && reply.length) {
		$j('#jot-location').val(reply);
	}
}

function jotShare(id) {
	if ($j('#jot-popup').length != 0) $j('#jot-popup').show();

	$j('#like-rotator-' + id).show();
	$j.get('share/' + id, function(data) {
		if (!editor) $j("#profile-jot-text").val("");
		initEditor(function(){
			addeditortext("#profile-jot-text", data);
			$j('#like-rotator-' + id).hide();
			$j(window).scrollTop(0);
		});

	});
}

function jotClearLocation() {
	$j('#jot-coord').val('');
	$j('#profile-nolocation-wrapper').hide();
}


function jotGetLink() {
	reply = prompt(window.linkURL);
	if(reply && reply.length) {
		reply = bin2hex(reply);
		$j('#profile-rotator').show();
		$j.get('parse_url?binurl=' + reply, function(data) {
			addeditortext(window.jotId, data);
			$j('#profile-rotator').hide();
		});
	}
}


function linkdropper(event) {
	var linkFound = event.dataTransfer.types.contains("text/uri-list");
	if(linkFound)
		event.preventDefault();
}


function linkdrop(event) {
	var reply = event.dataTransfer.getData("text/uri-list");
	//event.target.textContent = reply;
	event.preventDefault();
	if(reply && reply.length) {
		reply = bin2hex(reply);
		$j('#profile-rotator').show();
		$j.get('parse_url?binurl=' + reply, function(data) {
/*			if(window.jotId == "#profile-jot-text") {
				if (!editor) $j("#profile-jot-text").val("");
				initEditor(function(){
					addeditortext(window.jotId, data);
					$j('#profile-rotator').hide();
				});
			}
			else {*/
			addeditortext(window.jotId, data);
			$j('#profile-rotator').hide();
//			}
		});
	}
}


if(typeof window.geoTag === 'function') window.geoTag();


/*
 * Items
 */

function confirmDelete() { return confirm(window.delItem); }

function deleteCheckedItems(delID) {
	if(confirm(window.delItems)) {
		var checkedstr = '';

		$j(delID).hide();
		$j(delID + '-rotator').show();
		$j('.item-select').each( function() {
			if($j(this).is(':checked')) {
				if(checkedstr.length != 0) {
					checkedstr = checkedstr + ',' + $j(this).val();
				}
				else {
					checkedstr = $j(this).val();
				}
			}	
		});
		$j.post('item', { dropitems: checkedstr }, function(data) {
			window.location.reload();
		});
	}
}

function itemTag(id) {
	reply = prompt(window.term);
	if(reply && reply.length) {
		reply = reply.replace('#','');
		if(reply.length) {

			commentBusy = true;
			$j('body').css('cursor', 'wait');

			$j.get('tagger/' + id + '?term=' + reply, NavUpdate);
			/*if(timer) clearTimeout(timer);
			timer = setTimeout(NavUpdate,3000);*/
			liking = 1;
		}
	}
}

function itemFiler(id) {
	
	var bordercolor = $j("input").css("border-color");
	
	$j.get('filer/', function(data){
		$j.colorbox({html:data});
		$j.colorbox.resize();
		$j("#id_term").keypress(function(){
			$j(this).css("border-color",bordercolor);
		})
		$j("#select_term").change(function(){
			$j("#id_term").css("border-color",bordercolor);
		})
		
		$j("#filer_save").click(function(e){
			e.preventDefault();
			reply = $j("#id_term").val();
			if(reply && reply.length) {
				commentBusy = true;
				$j('body').css('cursor', 'wait');
				$j.get('filer/' + id + '?term=' + reply, NavUpdate);
/*					if(timer) clearTimeout(timer);
				timer = setTimeout(NavUpdate,3000);*/
				liking = 1;
				$j.colorbox.close();
			} else {
				$j("#id_term").css("border-color","#FF0000");
			}
			return false;
		});
	});
	
}


/*
 * Comments
 */

function insertFormatting(comment,BBcode,id) {
	
	var tmpStr = $j("#comment-edit-text-" + id).val();
	if(tmpStr == comment) {
		tmpStr = "";
		$j("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$j("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
		$j("#comment-edit-text-" + id).val(tmpStr);
	}

	textarea = document.getElementById("comment-edit-text-" +id);
	if (document.selection) {
		textarea.focus();
		selected = document.selection.createRange();
		if (BBcode == "url"){
			selected.text = "["+BBcode+"=http://]" +  selected.text + "[/"+BBcode+"]";
			} else			
		selected.text = "["+BBcode+"]" + selected.text + "[/"+BBcode+"]";
	} else if (textarea.selectionStart || textarea.selectionStart == "0") {
		var start = textarea.selectionStart;
		var end = textarea.selectionEnd;
		if (BBcode == "url"){
			textarea.value = textarea.value.substring(0, start) + "["+BBcode+"=http://]" + textarea.value.substring(start, end) + "[/"+BBcode+"]" + textarea.value.substring(end, textarea.value.length);
			} else
		textarea.value = textarea.value.substring(0, start) + "["+BBcode+"]" + textarea.value.substring(start, end) + "[/"+BBcode+"]" + textarea.value.substring(end, textarea.value.length);
	}
	return true;
}

function cmtBbOpen(id) {
	$j("#comment-edit-bb-" + id).show();
}
function cmtBbClose(id) {
	$j("#comment-edit-bb-" + id).hide();
}

function commentOpen(obj,id) {
	if(obj.value == window.commentEmptyText) {
		obj.value = "";
		$j("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$j("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		$j("#mod-cmnt-wrap-" + id).show();
		openMenu("comment-edit-submit-wrapper-" + id);
	}
}
function commentClose(obj,id) {
	if(obj.value == "") {
		obj.value = window.commentEmptyText;
		$j("#comment-edit-text-" + id).removeClass("comment-edit-text-full");
		$j("#comment-edit-text-" + id).addClass("comment-edit-text-empty");
		$j("#mod-cmnt-wrap-" + id).hide();
		closeMenu("comment-edit-submit-wrapper-" + id);
	}
}


function commentInsert(obj,id) {
	var tmpStr = $j("#comment-edit-text-" + id).val();
	if(tmpStr == window.commentEmptyText) {
		tmpStr = "";
		$j("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$j("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
	}
	var ins = $j(obj).html();
	ins = ins.replace("&lt;","<");
	ins = ins.replace("&gt;",">");
	ins = ins.replace("&amp;","&");
	ins = ins.replace("&quot;",'"');
	$j("#comment-edit-text-" + id).val(tmpStr + ins);
}

function qCommentInsert(obj,id) {
	var tmpStr = $j("#comment-edit-text-" + id).val();
	if(tmpStr == window.commentEmptyText) {
		tmpStr = "";
		$j("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$j("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		openMenu("comment-edit-submit-wrapper-" + id);
	}
	var ins = $j(obj).val();
	ins = ins.replace("&lt;","<");
	ins = ins.replace("&gt;",">");
	ins = ins.replace("&amp;","&");
	ins = ins.replace("&quot;",'"');
	$j("#comment-edit-text-" + id).val(tmpStr + ins);
	$j(obj).val("");
}

/*function showHideCommentBox(id) {
	if( $j('#comment-edit-form-' + id).is(':visible')) {
		$j('#comment-edit-form-' + id).hide();
	}
	else {
		$j('#comment-edit-form-' + id).show();
	}
}*/

