
<div class="vcard h-card">

	<div class="tool">
		<div class="fn label p-name">{{$profile.name}}</div>
		{{if $profile.edit}}
			<div class="action">
			<a class="icon s16 edit ttright" href="#" rel="#profiles-menu" title="{{$profile.edit.3}}"><span>{{$profile.edit.1}}</span></a>
			<ul id="profiles-menu" class="menu-popup">
				{{foreach $profile.menu.entries as $e}}
				<li>
					<a href="profiles/{{$e.id}}"><img class="u-photo" src='{{$e.photo}}'>{{$e.profile_name}}</a>
				</li>
				{{/foreach}}
				<li><a href="profile_photo" >{{$profile.menu.chg_photo}}</a></li>
				<li><a href="profiles/new" id="profile-listing-new-link">{{$profile.menu.cr_new}}</a></li>
				<li><a href="profiles" >{{$profile.edit.3}}</a></li>
								
			</ul>
			</div>
		{{else}}
			{{if $profile.menu}}
				<div class="profile-edit-side-div"><a class="profile-edit-side-link icon edit" title="{{$editprofile}}" href="profiles" ></a></div>
			{{/if}}
		{{/if}}
	</div>

	{{if $profile.picdate}}
		<div id="profile-photo-wrapper"><img class="photo" src="{{$profile.photo}}?rev={{$profile.picdate}}" alt="{{$profile.name}}" /></div>
	{{else}}
		<div id="profile-photo-wrapper"><img class="photo" src="{{$profile.photo}}" alt="{{$profile.name}}" /></div>
	{{/if}}
	{{if $pdesc}}<div class="title">{{$profile.pdesc}}</div>{{/if}}

	{{if $profile.network_name}}<dl class="network"><dt class="network-label">{{$network}}</dt><dd class="x-network">{{$profile.network_name}}</dd></dl>{{/if}}
	{{if $location}}
		<dl class="location"><dt class="location-label">{{$location}}</dt><br> 
		<dd class="adr">
			{{if $profile.address}}<div class="street-address p-street-address">{{$profile.address}}</div>{{/if}}
			<span class="city-state-zip">
				<span class="locality p-locality">{{$profile.locality}}</span>{{if $profile.locality}}, {{/if}}
				<span class="region p-region">{{$profile.region}}</span>
				<span class="postal-code p-postal-code">{{$profile.postal_code}}</span>
			</span>
			{{if $profile.country_name}}<span class="country-name">{{$profile.country_name}}</span>{{/if}}
		</dd>
		</dl>
	{{/if}}

	{{if $gender}}<dl class="mf"><dt class="gender-label">{{$gender}}</dt> <dd class="x-gender">{{$profile.gender}}</dd></dl>{{/if}}
	
	{{if $profile.pubkey}}<div class="key" style="display:none;">{{$profile.pubkey}}</div>{{/if}}

	{{if $marital}}<dl class="marital"><dt class="marital-label"><span class="heart">&hearts;</span>{{$marital}}</dt><dd class="marital-text">{{$profile.marital}}</dd></dl>{{/if}}

	{{if $homepage}}<dl class="homepage"><dt class="homepage-label">{{$homepage}}</dt><dd class="homepage-url"><a href="{{$profile.homepage}}" class="u-url" rel="me" target="_blank">{{$profile.homepage}}</a></dd></dl>{{/if}}

	{{include file="diaspora_vcard.tpl"}}
	
	<div id="profile-extra-links">
		<ul>
			{{if $connect}}
				{{if $remoteconnect}}
					<li><a id="dfrn-request-link" href="{{$remoteconnect}}">{{$connect}}</a></li>
				{{else}}
					<li><a id="dfrn-request-link" href="dfrn_request/{{$profile.nickname}}">{{$connect}}</a></li>
				{{/if}}
			{{/if}}
		</ul>
	</div>
</div>

{{$contact_block}}


