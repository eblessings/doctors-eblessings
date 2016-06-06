
<div id="follow-sidebar" class="widget">
	<h3>{{$connect}}</h3>
	<div id="connect-desc">{{$desc}}</div>
	<form action="follow" method="get" >
		<div class="form-group form-group-search">
			<input id="side-follow-url" class="search-input form-control form-search" type="text" name="url" value="{{$value|escape:'html'}}" placeholder="{{$hint|escape:'html'}}" data-toggle="tooltip" title="{{$hint|escape:'html'}}" />
			<button id="side-follow-submit" class="btn btn-default btn-sm form-button-search" type="submit" name="submit" value="{{$follow|escape:'html'}}">{{$follow}}</button>
		</div>
	</form>
</div>

