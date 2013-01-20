{{*
 *	AUTOMATICALLY GENERATED TEMPLATE
 *	DO NOT EDIT THIS FILE, CHANGES WILL BE OVERWRITTEN
 *
 *}}

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
	
	<div id="login-submit-wrapper" >
		<input type="submit" name="submit" id="login-submit-button" value="{{$login}}" />
	</div>
	
   <div id="login-extra-links">
		{{if $register}}<a href="register" title="{{$register.title}}" id="register-link">{{$register.desc}}</a>{{/if}}
        <a href="lostpass" title="{{$lostpass}}" id="lost-password-link" >{{$lostlink}}</a>
	</div>	
	
	{{foreach $hiddens as $k=>$v}}
		<input type="hidden" name="{{$k}}" value="{{$v}}" />
	{{/foreach}}
	
	
</form>


<script type="text/javascript"> $(document).ready(function() { $("#id_{{$lname.0}}").focus();} );</script>
