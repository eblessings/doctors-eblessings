
<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
<base href="{{$baseurl}}/" />
<meta name="generator" content="{{$generator}}" />
<link rel="stylesheet" href="{{$baseurl}}/vendor/asset/jquery-colorbox/example5/colorbox.css" type="text/css" media="screen" />
<link rel="stylesheet" href="{{$baseurl}}/vendor/asset/jgrowl/jquery.jgrowl.min.css" type="text/css" media="screen" />
<link rel="stylesheet" href="{{$baseurl}}/vendor/asset/jquery-datetimepicker/build/jquery.datetimepicker.min.css" type="text/css" media="screen" />

<link rel="stylesheet" type="text/css" href="{{$stylesheet}}" media="all" />

<script type="text/javascript" src="{{$baseurl}}/vendor/asset/jquery/dist/jquery.min.js" ></script>

<link rel="shortcut icon" href="{{$baseurl}}/images/friendica-32.png" />
<link rel="search"
         href="{{$baseurl}}/opensearch"
         type="application/opensearchdescription+xml"
         title="Search in Friendica" />

<script>
	window.delItem = "{{$delitem}}";
	window.showMore = "{{$showmore}}";
	window.showFewer = "{{$showfewer}}";
	var updateInterval = {{$update_interval}};
	var localUser = {{if $local_user}}{{$local_user}}{{else}}false{{/if}};
</script>
