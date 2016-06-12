
<div id="id_{{$field.0}}_wrapper" class="form-group field input password">
	<label for="id_{{$field.0}}" id="label_{{$field.0}}">{{$field.1}}{{if $field.4}}<span class="required"> {{$field.4}}</span>{{/if}}</label>
	<input class="form-control" name="{{$field.0}}" id="id_{{$field.0}}" type="text" value="{{$field.2|escape:'html'}}" {{if $field.4 eq "required"}} required{{/if}}{{if $field.5 eq "autofocus"}} autofocus{{elseif $field.5}} {{$field.5}}{{/if}} aria-describedby="{{$field.0}}_tip">
	<span id="{{$field.0}}_tip" class="help-block" role="tooltip">{{$field.3}}</span>
	<div class="clear"></div>
</div>