
<div id='adminpage'>
	<h1>{{$title}} - {{$page}}</h1>
{{if $showwarning}}
	<div id="admin-warning-message-wrapper">
		<p id="admin-warning-message" class="warning-message">{{$warningtext}}</p>
	</div>
{{/if}}

	<dl>
		<dt>{{$queues.label}}</dt>
		<dd>{{$queues.deliverq}} - <a href="{{$baseurl}}/admin/queue">{{$queues.queue}}</a>{{if $workeractive}} - {{$queues.workerq}}{{/if}}</dd>
	</dl>
	<dl>
		<dt>{{$pending.0}}</dt>
		<dd>{{$pending.1}}</dt>
	</dl>

	<dl>
		<dt>{{$users.0}}</dt>
		<dd>{{$users.1}}</dd>
	</dl>
	{{foreach $accounts as $p}}
		<dl>
			<dt>{{$p.0}}</dt>
			<dd>{{if $p.1}}{{$p.1}}{{else}}0{{/if}}</dd>
		</dl>
	{{/foreach}}


	<dl>
		<dt>{{$plugins.0}}</dt>
		
		{{foreach $plugins.1 as $p}}
			<dd><a href="/admin/plugins/{{$p}}/">{{$p}}</a></dd>
		{{/foreach}}
		
	</dl>

	<dl>
		<dt>{{$version.0}}</dt>
		<dd> {{$platform}} '{{$codename}}' {{$version.1}} - {{$build}}</dt>
	</dl>


</div>
