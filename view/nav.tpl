{# $langselector #}

<div id="site-location">$sitelocation</div>


<div id="nav">
	<div id="nav-user-menu-wrapper">
		<div class="left nav-menu-icon"><a href="#" rel="#nav-user-menu" title="$sitelocation"><img src="http://localhost/~fabio/friendika/photo/profile/4.jpg"></a>
			<ul id="nav-user-menu" class="menu-popup">
				<li><a href="profile">Profile</a></li>
				<li><a href="photos">Photos</a></li>
				{{ if $nav.notifications }}<li><a class="$nav.notifications.2" href="$nav.notifications.0" title="$nav.notifications.3" >$nav.notifications.1</a></li>{{ endif }}
				{{ if $nav.messages }}<li><a class="$nav.messages.2" href="$nav.messages.0" title="$nav.messages.3" >$nav.messages.1</a></li>{{ endif }}
			</ul>
		</div>
		
		{{ if $nav.community }}
			<div id="nav-community-link" class="left nav-menu">
				<a class="$nav.community.2" href="$nav.community.0" title="$nav.community.3" >$nav.community.1</a>
			</div>
		{{ endif }}
		
		{{ if $nav.network }}
			<div id="nav-network-link" class="left nav-menu">
				<a class="$nav.network.2" href="$nav.network.0" title="$nav.network.3" >$nav.network.1</a>
				<span id="net-update" class="nav-notify">12</span>
			</div>
		{{ endif }}
		{{ if $nav.network }}
			<div id="nav-home-link" class="left nav-menu selected">
				<a class="$nav.home.2" href="$nav.home.0" title="$nav.home.3" >$nav.home.1</a>
				<span id="home-update" class="nav-notify">2</span>
			</div>
		{{ endif }}
		
		{{ if $nav.notifications }}
			<div class="left nav-menu-icon"><a href="#" rel="#nav-notifications-menu" title=""><span class="icon s22 notify_off"></span></a>
				<ul id="nav-notifications-menu" class="menu-popup">
				</ul>
			</div>		
		{{ endif }}
		
		
		
		<div class="right nav-menu-icon"><a href="#" rel="#nav-site-menu"><img src="http://localhost/~fabio/friendika/images/icons/gear_22.png"></a>
			<ul id="nav-site-menu" class="menu-popup" ">
				{{ if $nav.settings }}<li><a class="$nav.settings.2" href="$nav.settings.0" title="$nav.settings.3">$nav.settings.1</a></li>{{ endif }}
				{{ if $nav.admin }}<li><a class="$nav.admin.2" href="$nav.admin.0" title="$nav.admin.3" >$nav.admin.1</a></li>{{ endif }}

				{{ if $nav.logout }}<li><a class="menu-sep $nav.logout.2" href="$nav.logout.0" title="$nav.logout.3" >$nav.logout.1</a></li>{{ endif }}
				{{ if $nav.login }}<li><a class="$nav.login.2" href="$nav.login.0" title="$nav.login.3" >$nav.login.1</a><li>{{ endif }}
			</ul>		
		</div>
	</div>

</div>


{#

{{ if $nav.logout }}<a id="nav-logout-link" class="nav-link $nav.logout.2" href="$nav.logout.0" title="$nav.logout.3" >$nav.logout.1</a> {{ endif }}
{{ if $nav.login }}<a id="nav-login-link" class="nav-login-link $nav.login.2" href="$nav.login.0" title="$nav.login.3" >$nav.login.1</a> {{ endif }}

<span id="nav-link-wrapper" >

{{ if $nav.register }}<a id="nav-register-link" class="nav-commlink $nav.register.2" href="$nav.register.0" title="$nav.register.3" >$nav.register.1</a>{{ endif }}
	
<a id="nav-help-link" class="nav-link $nav.help.2" target="friendika-help" href="$nav.help.0" title="$nav.help.3" >$nav.help.1</a>
	
{{ if $nav.apps }}<a id="nav-apps-link" class="nav-link $nav.apps.2" href="$nav.apps.0" title="$nav.apps.3" >$nav.apps.1</a>{{ endif }}

<a id="nav-search-link" class="nav-link $nav.search.2" href="$nav.search.0" title="$nav.search.3" >$nav.search.1</a>
<a id="nav-directory-link" class="nav-link $nav.directory.2" href="$nav.directory.0" title="$nav.directory.3" >$nav.directory.1</a>

{{ if $nav.admin }}<a id="nav-admin-link" class="nav-link $nav.admin.2" href="$nav.admin.0" title="$nav.admin.3" >$nav.admin.1</a>{{ endif }}

{{ if $nav.notifications }}
<a id="nav-notify-link" class="nav-commlink $nav.notifications.2" href="$nav.notifications.0" title="$nav.notifications.3" >$nav.notifications.1</a>
<span id="notify-update" class="nav-ajax-left"></span>
{{ endif }}
{{ if $nav.messages }}
<a id="nav-messages-link" class="nav-commlink $nav.messages.2" href="$nav.messages.0" title="$nav.messages.3" >$nav.messages.1</a>
<span id="mail-update" class="nav-ajax-left"></span>
{{ endif }}

{{ if $nav.manage }}<a id="nav-manage-link" class="nav-commlink $nav.manage.2" href="$nav.manage.0" title="$nav.manage.3">$nav.manage.1</a>{{ endif }}

{{ if $nav.settings }}<a id="nav-settings-link" class="nav-link $nav.settings.2" href="$nav.settings.0" title="$nav.settings.3">$nav.settings.1</a>{{ endif }}
{{ if $nav.profiles }}<a id="nav-profiles-link" class="nav-link $nav.profiles.2" href="$nav.profiles.0" title="$nav.profiles.3" >$nav.profiles.1</a>{{ endif }}

{{ if $nav.contacts }}<a id="nav-contacts-link" class="nav-link $nav.contacts.2" href="$nav.contacts.0" title="$nav.contacts.3" >$nav.contacts.1</a>{{ endif }}
</span>
<span id="nav-end"></span>
<span id="banner">$banner</span>
#}
