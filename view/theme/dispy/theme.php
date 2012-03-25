<?php

/*
 * Name: Dispy
 * Description: Dispy, Friendica theme
 * Version: 1.0
 * Author: unknown
 * Maintainer: Simon <http://simon.kisikew.org/>
 * Screenshot: <a href="screenshot.png">Screenshot</a>
 */


$a->theme_info = array(
	'extends' => 'dispy'
);

$a->page['htmlhead'] .= <<< EOT
<script>
$(document).ready(function() {
    $('.group-edit-icon').hover(
        function() {
            $(this).addClass('icon');
            $(this).removeClass('iconspacer'); },

        function() {
            $(this).removeClass('icon');
            $(this).addClass('iconspacer'); }
    );

    $('.sidebar-group-element').hover(
        function() {
            id = $(this).attr('id');
            $('#edit-' + id).addClass('icon');
            $('#edit-' + id).removeClass('iconspacer'); },

        function() {
            id = $(this).attr('id');
            $('#edit-' + id).removeClass('icon');
            $('#edit-' + id).addClass('iconspacer'); }
    );

    $('.savedsearchdrop').hover(
        function() {
            $(this).addClass('drop');
            $(this).addClass('icon');
            $(this).removeClass('iconspacer'); },

        function() {
            $(this).removeClass('drop');
            $(this).removeClass('icon');
            $(this).addClass('iconspacer'); }
    );

    $('.savedsearchterm').hover(
        function() {
            id = $(this).attr('id');
            $('#drop-' + id).addClass('icon');
            $('#drop-' + id).addClass('drophide');
            $('#drop-' + id).removeClass('iconspacer'); },

        function() {
            id = $(this).attr('id');
            $('#drop-' + id).removeClass('icon');
            $('#drop-' + id).removeClass('drophide');
            $('#drop-' + id).addClass('iconspacer'); }
        );

	// click outside notifications menu closes it
	$('html').click(function() {
		$('#nav-notifications-linkmenu').removeClass('selected');
		document.getElementById("nav-notifications-menu").style.display = "none";
	});

	$('#nav-notifications-linkmenu').click(function(event) {
		event.stopPropagation();
	});
	// click outside profiles menu closes it
	$('html').click(function() {
		$('#profiles-menu-trigger').removeClass('selected');
		document.getElementById("profiles-menu").style.display = "none";
	});

	$('#profiles-menu').click(function(event) {
		event.stopPropagation();
	});

	// main function in toolbar functioning
    function toggleToolbar() {
        if ( $('#nav-floater').is(':visible') ) {
            $('#nav-floater').slideUp('fast');
            $('.floaterflip').css({
                backgroundPosition: '-210px -60px' 
            });
        } else {
            $('#nav-floater').slideDown('fast');
            $('.floaterflip').css({
                backgroundPosition: '-190px -60px'
            });
        }
    };
	// our trigger for the toolbar button
    $('.floaterflip').click(function() {
        toggleToolbar();
        return false;
    });

	// (attempt) to change the text colour in a top post
	$('#profile-jot-text').focusin(function() {
		$(this).css({color: '#eec'});
	});

/*	$('#profile-photo-wrapper').mouseover(function() {
		$('.profile-edit-side-div').css({display: 'block'});
	}).mouseout(function() {
		$('.profile-edit-side-div').css({display: 'none'});
		return false;
	});

	$('img.photo').mouseover(function() {
		$('.profile-edit-side-div').css({display: 'block'});
	}).mouseout(function() {
		$('.profile-edit-side-div').css({display: 'none'});
		return false;
	});*/

});
</script>
EOT;

function dispy_community_info() {
	$a = get_app();

	$fostitJS = "javascript: (function() {
		the_url = '".$a->get_baseurl($ssl_state)."/view/theme/dispy/fpostit/fpostit.php?url=' +
		encodeURIComponent(window.location.href) + '&title=' + encodeURIComponent(document.title) + '&text=' +
		encodeURIComponent(''+(window.getSelection ? window.getSelection() : document.getSelection ?
		document.getSelection() : document.selection.createRange().text));
		a_funct = function() {
			if (!window.open(the_url, 'fpostit', 'location=yes,links=no,scrollbars=no,toolbar=no,width=600,height=300')) {
				location.href = the_url;
			}
			if (/Firefox/.test(navigator.userAgent)) {
				setTimeout(a_funct, 0)
			} else {
				a_funct();
			}
		})();" ;

	$aside['$fostitJS'] = $fostitJS;
	$url = $a->get_baseurl($ssl_state);
	$aside['$url'] = $url;

    $tpl = file_get_contents(dirname(__file__).'/communityhome.tpl');
	$a->page['right_aside'] = replace_macros($tpl, $aside);
}

