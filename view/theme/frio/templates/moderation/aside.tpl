<script type="text/javascript">
	// update pending count //
	$(function(){
		$("nav").bind('nav-update', function(e,data){
			var elm = $('#pending-update');
			var register = parseInt($(data).find('register').text());
			if (register > 0) {
				elm.html(register);
			}
		});
	});
</script>

{{foreach $subpages as $page}}
<div class="widget">
	<h3>{{$page.0}}</h3>
	<ul role="menu">
		{{foreach $page.1 as $item}}
		<li role="menuitem" class="{{$item.2}}">
			<a href="{{$item.0}}" {{if $item.accesskey}}accesskey="{{$item.accesskey}}"{{/if}}>
				{{$item.1}}
				{{if $name == "users"}}
				 <span id="pending-update" class="badge pull-right"></span>
				{{/if}}
			</a>
		</li>
		{{/foreach}}
	</ul>
</div>
{{/foreach}}
