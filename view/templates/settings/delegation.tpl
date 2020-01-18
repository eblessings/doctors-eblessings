<h3>{{$header}}</h3>

{{if $parent_user}}
<h4>{{$parent_header}}</h4>
<div id="delegate-parent-desc" class="delegate-parent-desc">{{$parent_desc}}</div>
<div id="delegate-parent" class="delegate-parent">
	<form action="settings/delegation" method="post">
		<input type='hidden' name='form_security_token' value='{{$form_security_token}}'>
        {{include file="field_select.tpl" field=$parent_user}}
        {{include file="field_password.tpl" field=$parent_password}}
		<div class="submit"><input type="submit" name="delegate" value="{{$submit}}"/></div>
	</form>
</div>
{{else}}
<h4>{{$account_header}}</h4>
<div id="add-account-desc" class="add-account-desc">{{$account_desc}}</div>
<a href='register'>{{$add_account}}</a>
{{/if}}

<h4>{{$delegates_header}}</h4>

<div id="delegate-desc" class="delegate-desc">{{$desc nofilter}}</div>

<h4>{{$head_delegates}}</h4>

{{if $delegates}}
    {{foreach $delegates as $x}}
<div class="contact-block-div">
	<a class="contact-block-link" href="settings/delegation/remove/{{$x.uid}}">
		<img class="contact-block-img" src="photo/thumb/{{$x.uid}}" title="{{$x.username}} ({{$x.nickname}})">
	</a>
</div>
    {{/foreach}}
<div class="clear"></div>
{{else}}
    {{$none}}
{{/if}}
<hr/>

<h4>{{$head_potentials}}</h4>
{{if $potentials}}
    {{foreach $potentials as $x}}
<div class="contact-block-div">
	<a class="contact-block-link" href="settings/delegation/add/{{$x.uid}}">
		<img class="contact-block-img" src="photo/thumb/{{$x.uid}}" title="{{$x.username}} ({{$x.nickname}})">
	</a>
</div>
    {{/foreach}}
<div class="clear"></div>
{{else}}
    {{$none}}
{{/if}}
