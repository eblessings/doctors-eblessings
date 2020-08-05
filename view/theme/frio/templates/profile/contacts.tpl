<div class="generic-page-wrapper">
	{{include file="section_title.tpl"}}

{{if $desc}}
	<p>{{$desc nofilter}}</p>
{{/if}}

	<ul class="nav nav-tabs">
		<li role="presentation"{{if !$type || $type == 'all'}} class="active"{{/if}}>
			<a href="profile/{{$nickname}}/contacts">{{$all_label}}</a>
		</li>
		<li role="presentation"{{if $type == 'followers'}} class="active"{{/if}}>
			<a href="profile/{{$nickname}}/contacts/followers">{{$followers_label}}</a>
		</li>
		<li role="presentation"{{if $type == 'following'}} class="active"{{/if}}>
			<a href="profile/{{$nickname}}/contacts/following">{{$following_label}}</a>
		</li>
		<li role="presentation"{{if $type == 'mutuals'}} class="active"{{/if}}>
			<a href="profile/{{$nickname}}/contacts/mutuals">{{$mutuals_label}}</a>
		</li>
	</ul>
{{if $contacts}}
	<ul id="viewcontact_wrapper{{if $id}}-{{$id}}{{/if}}" class="viewcontact_wrapper media-list">
	{{foreach $contacts as $contact}}
		<li>{{include file="contact_template.tpl"}}</li>
	{{/foreach}}
	</ul>
{{else}}
	<div class="alert alert-info" role="alert">{{$noresult_label}}</div>
{{/if}}
	<div class="clear"></div>
	<div id="view-contact-end"></div>

	{{$paginate nofilter}}
</div>
