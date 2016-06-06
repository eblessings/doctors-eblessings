
<div id="peoplefind-sidebar" class="widget">
	<h3>{{$findpeople}}</h3>
	<div id="peoplefind-desc">{{$desc}}</div>
	<form action="dirfind" method="get" />
		<div class="form-group form-group-search">
			<input id="side-peoplefind-url" class="search-input form-control form-search" type="text" name="search" data-toggle="tooltip" title="{{$hint|escape:'html'}}" />
			<button id="side-peoplefind-submit" class="btn btn-default btn-sm form-button-search" type="submit" name="submit" value="{{$findthem|escape:'html'}}">{{$findthem}}</button>
		</div>
	</form>
	<div class="side-link" id="side-match-link"><a href="match" >{{$similar}}</a></div>
	<div class="side-link" id="side-suggest-link"><a href="suggest" >{{$suggest}}</a></div>

	{{if $inv}} 
	<div class="side-link" id="side-invite-link" ><a href="invite" >{{$inv}}</a></div>
	{{/if}}
</div>

