
<div id="id_{{$field.0}}_wrapper" class="form-group field input">
	<label for="id_{{$field.0}}" id="label_{{$field.0}}">{{$field.1}}{{if $field.4}}<span class="required"> {{$field.4}}</span>{{/if}}</label>
	<input class="form-control" name="{{$field.0}}" id="id_{{$field.0}}" {{if $field.6 eq "email"}} type="email"{{elseif $field.6 eq "url"}} type="url"{{else}}type="text"{{/if}} value="{{$field.2|escape:'html'}}" {{if $field.4 eq "required"}} required{{/if}}{{if $field.5 eq "autofocus"}} autofocus{{/if}} aria-describedby="{{$field.0}}_tip">
	<span id="{{$field.0}}_tip" class="help-block" role="tooltip">{{$field.3}}</span>
	<div class="clear"></div>
</div>
