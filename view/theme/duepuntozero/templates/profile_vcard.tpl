
<div class="vcard h-card">

	<div class="fn label p-name">{{$profile.name|escape}}</div>
	
	{{if $profile.addr}}<div class="p-addr">{{$profile.addr}}</div>{{/if}}
	
	{{if $profile.pdesc}}<div class="title">{{$profile.pdesc}}</div>{{/if}}
	<div id="profile-photo-wrapper"><img class="photo u-photo" width="175" height="175" src="{{$profile.photo}}?rev={{$profile.picdate}}" alt="{{$profile.name|escape}}"></div>

	{{if $account_type}}<div class="account-type">{{$account_type}}</div>{{/if}}

	{{if $profile.network_name}}<dl class="network"><dt class="network-label">{{$network}}</dt><dd class="x-network">{{$profile.network_name}}</dd></dl>{{/if}}

	{{if $location}}
		<dl class="location"><dt class="location-label">{{$location}}</dt> 
		<dd class="adr h-adr">
			{{if $profile.address}}<div class="street-address p-street-address">{{$profile.address}}</div>{{/if}}
			<span class="city-state-zip">
				<span class="locality p-locality">{{$profile.locality}}</span>{{if $profile.locality}}, {{/if}}
				<span class="region p-region">{{$profile.region}}</span>
				<span class="postal-code p-postal-code">{{$profile.postal_code}}</span>
			</span>
			{{if $profile.country_name}}<span class="country-name p-country-name">{{$profile.country_name}}</span>{{/if}}
		</dd>
		</dl>
	{{/if}}

	{{if $gender}}<dl class="mf"><dt class="gender-label">{{$gender}}</dt> <dd class="p-gender">{{$profile.gender}}</dd></dl>{{/if}}
	
	{{if $profile.pubkey}}<div class="key" style="display:none;">{{$profile.pubkey}}</div>{{/if}}

	{{if $marital}}<dl class="marital"><dt class="marital-label"><span class="heart">&hearts;</span>{{$marital}}</dt><dd class="marital-text">{{$profile.marital}}</dd></dl>{{/if}}

	{{if $homepage}}<dl class="homepage"><dt class="homepage-label">{{$homepage}}</dt><dd class="homepage-url"><a href="{{$profile.homepage}}" class="u-url" rel="me" target="external-link">{{$profile.homepage}}</a></dd></dl>{{/if}}

	{{include file="diaspora_vcard.tpl"}}

	<div id="profile-vcard-break"></div>	
	<div id="profile-extra-links">
		<ul>
			{{if $connect}}
				{{if $remoteconnect}}
					<li><a id="dfrn-request-link" href="{{$remoteconnect}}">{{$connect}}</a></li>
				{{else}}
					<li><a id="dfrn-request-link" href="dfrn_request/{{$profile.nickname}}">{{$connect}}</a></li>
				{{/if}}
			{{/if}}
			{{if $wallmessage}}
				<li><a id="wallmessage-link" href="{{$wallmessage_link}}">{{$wallmessage}}</a></li>
			{{/if}}
			{{if $subscribe_feed}}
				<li><a id="subscribe-feed-link" href="dfrn_poll/{{$profile.nickname}}">{{$subscribe_feed}}</a></li>
			{{/if}}
		</ul>
	</div>
</div>

{{$contact_block}}


