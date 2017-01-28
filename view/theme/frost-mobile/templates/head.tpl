<meta http-equiv="Content-Type" content="text/html;charset=utf-8" />
<meta name="viewport" content="width=device-width; initial-scale = 1.0;" />

<base href="{{$baseurl}}/" />
<meta name="generator" content="{{$generator}}" />
<link rel="stylesheet" href="{{$baseurl}}/library/jgrowl/jquery.jgrowl.css" type="text/css" media="screen" />
<link rel="stylesheet" href="{{$baseurl}}/library/datetimepicker/jquery.datetimepicker.css" type="text/css" media="screen" />
<script type="text/javascript" src="{{$baseurl}}/js/jquery.js"></script>

<link rel="stylesheet" type="text/css" href="{{$stylesheet}}" media="all" />

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

