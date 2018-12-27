
<div id="adminpage">
	<h1>{{$title}}</h1>

	<form action="admin/features" method="post" autocomplete="off">
	<input type='hidden' name='form_security_token' value='{{$form_security_token}}'>

	{{foreach $features as $g => $f}}
	<h3 class="settings-heading"><a href="javascript:;">{{$f.0}}</a></h3>

	<div class="settings-content-block">
		{{foreach $f.1 as $fcat}}
			<div class="settings-block">
			{{include file="field_yesno.tpl" field=$fcat.0}}
			{{include file="field_yesno.tpl" field=$fcat.1}}
			</div>
		{{/foreach}}

		<div class="settings-submit-wrapper" >
			<input type="submit" name="submit" class="settings-features-submit" value="{{$submit}}" />
		</div>
	</div>
	{{/foreach}}

	</form>
</div>
