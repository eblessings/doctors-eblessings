
	{{if $field.5}}<script>$(function(){ previewTheme($("#id_{{$field.0}}")[0]); });</script>{{/if}}
	<div class='field select'>
		<label for='id_{{$field.0}}'>{{$field.1}}</label>
		<select name='{{$field.0}}' id='id_{{$field.0}}' {{if $field.5}}onchange="previewTheme(this);"{{/if}} >
			{{foreach $field.4 as $opt=>$val}}<option value="{{$opt|escape:'html'}}" {{if $opt==$field.2}}selected="selected"{{/if}}>{{$val}}</option>{{/foreach}}
		</select>
		<span class='field_help'>{{$field.3}}</span>
		{{if $field.5}}<div id="theme-preview"></div>{{/if}}
	</div>
