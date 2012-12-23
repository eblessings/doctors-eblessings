
<div class="login-form">
<form action="$dest_url" method="post" >
	<input type="hidden" name="auth-params" value="login" />

	<div id="login_standard">
	{{ inc $field_input with $field=$lname }}{{ endinc }}
	{{ inc $field_password with $field=$lpassword }}{{ endinc }}
	</div>
	
	{{ if $openid }}
			<br /><br />
			<div id="login_openid">
			{{ inc $field_openid with $field=$lopenid }}{{ endinc }}
			</div>
	{{ endif }}

<!--	<br /><br />
	<div class="login-extra-links">
	By signing in you agree to the latest <a href="tos.html" title="$tostitle" id="terms-of-service-link" >$toslink</a> and <a href="privacy.html" title="$privacytitle" id="privacy-link" >$privacylink</a>
	</div>-->

	<br /><br />
	{{ inc $field_checkbox with $field=$lremember }}{{ endinc }}

	<div id="login-submit-wrapper" >
		<input type="submit" name="submit" id="login-submit-button" value="$login" />
	</div>

	<br /><br />

	<div class="login-extra-links">
		{{ if $register }}<a href="register" title="$register.title" id="register-link">$register.desc</a>{{ endif }}
        <a href="lostpass" title="$lostpass" id="lost-password-link" >$lostlink</a>
	</div>
	
	{{ for $hiddens as $k=>$v }}
		<input type="hidden" name="$k" value="$v" />
	{{ endfor }}
	
	
</form>
</div>

<script type="text/javascript">window.loginName = "$lname.0";</script>
