
<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
<base href="{{$baseurl}}/" />
<meta name="generator" content="{{$generator}}" />
<link rel="stylesheet" href="view/global.css" type="text/css" media="all" />
<link rel="stylesheet" href="library/colorbox/colorbox.css" type="text/css" media="screen" />
<link rel="stylesheet" href="library/jgrowl/jquery.jgrowl.css" type="text/css" media="screen" />
<link rel="stylesheet" href="library/datetimepicker/jquery.datetimepicker.css" type="text/css" media="screen" />
<link rel="stylesheet" href="library/perfect-scrollbar/perfect-scrollbar.min.css" type="text/css" media="screen" />

<link rel="stylesheet" type="text/css" href="{{$stylesheet}}" media="all" />

<!--
<link rel="shortcut icon" href="images/friendica-32.png" />
<link rel="apple-touch-icon" href="images/friendica-128.png"/>
-->
<link rel="shortcut icon" href="{{$shortcut_icon}}" />
<link rel="apple-touch-icon" href="{{$touch_icon}}"/>

<meta name="apple-mobile-web-app-capable" content="yes" /> 


<link rel="search"
         href="{{$baseurl}}/opensearch" 
         type="application/opensearchdescription+xml" 
         title="Search in Friendica" />

<!--[if IE]>
<script type="text/javascript" src="https://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
<![endif]-->
<script type="text/javascript" src="{{$baseurl}}/js/modernizr.js" ></script>
<script type="text/javascript" src="{{$baseurl}}/js/jquery.js" ></script>
<!-- <script type="text/javascript" src="{{$baseurl}}/js/jquery-migrate.js" ></script>-->
<script type="text/javascript" src="{{$baseurl}}/js/jquery-migrate.js" ></script>
<script type="text/javascript" src="{{$baseurl}}/js/jquery.textinputs.js" ></script>
<script type="text/javascript" src="{{$baseurl}}/library/jquery-textcomplete/jquery.textcomplete.js" ></script>
<script type="text/javascript" src="{{$baseurl}}/js/autocomplete.js" ></script>
<!--<script type="text/javascript" src="{{$baseurl}}/js/fk.autocomplete.js" ></script>-->
<script type="text/javascript" src="{{$baseurl}}/library/colorbox/jquery.colorbox-min.js"></script>
<script type="text/javascript" src="{{$baseurl}}/library/jgrowl/jquery.jgrowl_minimized.js"></script>
<script type="text/javascript" src="{{$baseurl}}/library/datetimepicker/jquery.datetimepicker.js"></script>
<script type="text/javascript" src="{{$baseurl}}/library/tinymce/jscripts/tiny_mce/tiny_mce_src.js" ></script>
<script type="text/javascript" src="{{$baseurl}}/library/perfect-scrollbar/perfect-scrollbar.jquery.js" ></script>
<script type="text/javascript" src="{{$baseurl}}/js/acl.js" ></script>
<script type="text/javascript" src="{{$baseurl}}/js/webtoolkit.base64.js" ></script>
<script type="text/javascript" src="{{$baseurl}}/js/main.js" ></script>
<script>

	var updateInterval = {{$update_interval}};
	var localUser = {{if $local_user}}{{$local_user}}{{else}}false{{/if}};

	function confirmDelete() { return confirm("{{$delitem}}"); }
	function commentExpand(id) {
		$("#comment-edit-text-" + id).value = '';
		$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
		$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
		$("#comment-edit-text-" + id).focus();
		$("#mod-cmnt-wrap-" + id).show();
		openMenu("comment-edit-submit-wrapper-" + id);
		return true;
	}
	function commentOpen(obj,id) {
		if(obj.value == '{{$comment}}') {
			obj.value = '';
			$("#comment-edit-text-" + id).addClass("comment-edit-text-full");
			$("#comment-edit-text-" + id).removeClass("comment-edit-text-empty");
			$("#mod-cmnt-wrap-" + id).show();
			openMenu("comment-edit-submit-wrapper-" + id);
			return true;
		}
		return false;
	}
	function commentClose(obj,id) {
		if(obj.value == '') {
			obj.value = '{{$comment}}';
			$("#comment-edit-text-" + id).removeClass("comment-edit-text-full");
			$("#comment-edit-text-" + id).addClass("comment-edit-text-empty");
			$("#mod-cmnt-wrap-" + id).hide();
			closeMenu("comment-edit-submit-wrapper-" + id);
			return true;
		}
		return false;
	}


	function commentInsert(obj,id) {
		var tmpStr = $("#comment-edit-text-" + id).val();
		if(tmpStr == '{{$comment}}') {
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
		if(tmpStr == '{{$comment}}') {
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

	window.showMore = "{{$showmore}}";
	window.showFewer = "{{$showfewer}}";

	function showHideCommentBox(id) {
		if( $('#comment-edit-form-' + id).is(':visible')) {
			$('#comment-edit-form-' + id).hide();
		}
		else {
			$('#comment-edit-form-' + id).show();
		}
	}


</script>


