
<div id='adminpage'>
	<h1>{{$title}} - {{$page}}</h1>
		{{if $pcount eq 0}}
		    <div class="error-message">
		    {{$noplugshint}}
		    </div>
		{{else}}
		<a class="btn" href="{{$baseurl}}/admin/{{$function}}?a=r&amp;t={{$form_security_token}}">{{$reload}}</a>
		<ul id='pluginslist'>
		{{foreach $addons as $p}}
			<li class="plugin {{$p.1}}">
				<span class="offset-anchor" id="{{$p.0}}"></span>
				<a class='toggleplugin' href='{{$baseurl}}/admin/{{$function}}/{{$p.0}}?a=t&amp;t={{$form_security_token}}#{{$p.0}}' title="{{if $p.1==on}}Disable{{else}}Enable{{/if}}" ><span class='icon {{$p.1}}'></span></a>
				<a href='{{$baseurl}}/admin/{{$function}}/{{$p.0}}'><span class='name'>{{$p.2.name}}</span></a> - <span class="version">{{$p.2.version}}</span>
				{{if $p.2.experimental}} {{$experimental}} {{/if}}{{if $p.2.unsupported}} {{$unsupported}} {{/if}}
				<div class='desc'>{{$p.2.description}}</div>
			</li>
		{{/foreach}}
		</ul>
		{{/if}}
</div>
