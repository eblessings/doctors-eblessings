

<form action="{{$dest_url}}" method="post" >
	<input type="hidden" name="auth-params" value="login" />

	<div id="login_standard">
	{{include file="field_input.tpl" field=$lname}}
	{{include file="field_password.tpl" field=$lpassword}}
	</div>
	
	{{if $openid}}
			<div id="login_openid">
			{{include file="field_openid.tpl" field=$lopenid}}
			</div>
	{{/if}}

	{{include file="field_checkbox.tpl" field=$lremember}}

	<div id="login-extra-links">
		{{if $register}}<a href="register" title="{{$register.title|escape:'html'}}" id="register-link">{{$register.desc}}</a>{{/if}}
        <a href="lostpass" title="{{$lostpass|escape:'html'}}" id="lost-password-link" >{{$lostlink}}</a>
	</div>
	
	<div id="login-submit-wrapper" >
		<input type="submit" name="submit" id="login-submit-button" value="{{$login|escape:'html'}}" />
	</div>
	
	{{foreach $hiddens as $k=>$v}}
		<input type="hidden" name="{{$k}}" value="{{$v|escape:'html'}}" />
	{{/foreach}}
	
	
</form>


<script type="text/javascript"> $(document).ready(function() { $("#id_{{$lname.0}}").focus();} );</script>
