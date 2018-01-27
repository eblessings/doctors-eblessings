
{{* This content will be added to the html page <head> *}}

<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
<base href="{{$baseurl}}/" />
<meta name="generator" content="{{$generator}}" />
<meta name="viewport" content="initial-scale=1.0">

{{* All needed css files - Note: css must be inserted before js files *}}
<link rel="stylesheet" href="view/global.css" type="text/css" media="all" />
<link rel="stylesheet" href="vendor/asset/jquery-colorbox/example5/colorbox.css" type="text/css" media="screen" />
<link rel="stylesheet" href="library/jgrowl/jquery.jgrowl.css" type="text/css" media="screen" />
<link rel="stylesheet" href="vendor/asset/jquery-datetimepicker/build/jquery.datetimepicker.min.css" type="text/css" media="screen" />
<link rel="stylesheet" href="library/perfect-scrollbar/perfect-scrollbar.min.css" type="text/css" media="screen" />
<link rel="stylesheet" href="vendor/pear/text_highlighter/sample.css" type="text/css" media="screen" />

<link rel="stylesheet" href="view/theme/frio/frameworks/bootstrap/css/bootstrap.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/bootstrap/css/bootstrap-theme.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/font-awesome/css/font-awesome.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/jasny/css/jasny-bootstrap.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/bootstrap-select/css/bootstrap-select.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/ekko-lightbox/ekko-lightbox.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/awesome-bootstrap-checkbox/awesome-bootstrap-checkbox.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/justifiedGallery/justifiedGallery.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/bootstrap-colorpicker/css/bootstrap-colorpicker.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/frameworks/bootstrap-toggle/css/bootstrap-toggle.min.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/font/open_sans/open-sans.css" type="text/css" media="screen"/>

{{* The own style.css *}}
<link rel="stylesheet" type="text/css" href="{{$stylesheet}}" media="all" />

{{* own css files *}}
<link rel="stylesheet" href="view/theme/frio/css/hovercard.css" type="text/css" media="screen"/>
<link rel="stylesheet" href="view/theme/frio/css/font-awesome.custom.css" type="text/css" media="screen"/>

<!--
<link rel="shortcut icon" href="images/friendica-32.png" />
<link rel="apple-touch-icon" href="images/friendica-128.png"/>
-->
<link rel="shortcut icon" href="{{$shortcut_icon}}" />
<link rel="apple-touch-icon" href="{{$touch_icon}}"/>

<meta name="apple-mobile-web-app-capable" content="yes" />
<link rel="manifest" href="{{$baseurl}}/manifest" />
<script type="text/javascript">
// Prevents links to switch to Safari in a home screen app - see https://gist.github.com/irae/1042167
(function(a,b,c){if(c in b&&b[c]){var d,e=a.location,f=/^(a|html)$/i;a.addEventListener("click",function(a){d=a.target;while(!f.test(d.nodeName))d=d.parentNode;"href"in d&&(chref=d.href).replace(e.href,"").indexOf("#")&&(!/^[a-z\+\.\-]+:/i.test(chref)||chref.indexOf(e.protocol+"//"+e.host)===0)&&(a.preventDefault(),e.href=d.href)},!1)}})(document,window.navigator,"standalone");
</script>

<link rel="search"
         href="{{$baseurl}}/opensearch"
         type="application/opensearchdescription+xml"
         title="Search in Friendica" />


{{* The js files we use *}}
<!--[if IE]>
<script type="text/javascript" src="https://html5shiv.googlecode.com/svn/trunk/html5.js"></script>
<![endif]-->
<script type="text/javascript" src="js/modernizr.js" ></script>
<script type="text/javascript" src="vendor/asset/jquery/dist/jquery.min.js"></script>
<script type="text/javascript" src="js/jquery-migrate.js" ></script>
<script type="text/javascript" src="js/jquery.textinputs.js" ></script>
<script type="text/javascript" src="library/jquery-textcomplete/jquery.textcomplete.min.js" ></script>
<script type="text/javascript" src="js/autocomplete.js" ></script>
<script type="text/javascript" src="vendor/asset/jquery-colorbox/jquery.colorbox-min.js"></script>
<script type="text/javascript" src="library/jgrowl/jquery.jgrowl_minimized.js"></script>
<script type="text/javascript" src="vendor/asset/jquery-datetimepicker/build/jquery.datetimepicker.full.min.js"></script>
<script type="text/javascript" src="library/perfect-scrollbar/perfect-scrollbar.jquery.min.js" ></script>
<script type="text/javascript" src="js/acl.js" ></script>
<script type="text/javascript" src="vendor/asset/base64/base64.min.js" ></script>
<script type="text/javascript" src="js/main.js" ></script>

<script type="text/javascript" src="view/theme/frio/frameworks/bootstrap/js/bootstrap.min.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/jasny/js/jasny-bootstrap.custom.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/bootstrap-select/js/bootstrap-select.min.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/ekko-lightbox/ekko-lightbox.min.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/justifiedGallery/jquery.justifiedGallery.min.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/bootstrap-colorpicker/js/bootstrap-colorpicker.min.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/flexMenu/flexmenu.custom.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/jsmart/jsmart.custom.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/jquery-scrollspy/jquery-scrollspy.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/autosize/autosize.min.js"></script>
<script type="text/javascript" src="view/theme/frio/frameworks/sticky-kit/jquery.sticky-kit.min.js"></script>

{{* own js files *}}
<script type="text/javascript" src="view/theme/frio/js/theme.js"></script>
<script type="text/javascript" src="view/theme/frio/js/modal.js"></script>
<script type="text/javascript" src="view/theme/frio/js/hovercard.js"></script>
<script type="text/javascript" src="view/theme/frio/js/textedit.js"></script>

<script type="text/javascript">
	window.showMore = "{{$showmore}}";
	window.showFewer = "{{$showfewer}}";
</script>

{{* Include the strings which are needed for some js functions (e.g. translation)
They are loaded into the html <head> so that js functions can use them *}}
{{include file="js_strings.tpl"}}
