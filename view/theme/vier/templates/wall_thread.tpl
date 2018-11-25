{{if $mode == display}}
{{else}}
{{if $item.comment_firstcollapsed}}
	{{if $item.thread_level<3}}
		<div class="hide-comments-outer">
			<span id="hide-comments-total-{{$item.id}}"
				class="hide-comments-total">{{$item.num_comments}}</span>
			<span id="hide-comments-{{$item.id}}"
				class="hide-comments fakelink"
				onclick="showHideComments({{$item.id}});">{{$item.hide_text}}</span>
		</div>
		<div id="collapsed-comments-{{$item.id}}" class="collapsed-comments" style="display: none;">
	{{else}}
		<div id="collapsed-comments-{{$item.id}}" class="collapsed-comments" style="display: block;">
	{{/if}}
{{/if}}
{{/if}}

{{if $item.thread_level!=1}}<div class="children u-comment h-cite">{{/if}}

<div aria-hidden="true" class="wall-item-decor">
	<img id="like-rotator-{{$item.id}}" class="like-rotator" src="images/rotator.gif" alt="{{$item.wait|escape}}" title="{{$item.wait|escape}}" style="display: none;" />
</div>

{{if $item.thread_level<7}}
	<div class="wall-item-container {{$item.indent}} {{$item.shiny}} {{$item.network}} thread_level_{{$item.thread_level}}" id="item-{{$item.guid}}">
{{else}}
	<div class="wall-item-container {{$item.indent}} {{$item.shiny}} {{$item.network}} thread_level_7" id="item-{{$item.guid}}">
{{/if}}
{{if $item.thread_level==1}}
<span class="commented" style="display: none;">{{$item.commented}}</span>
<span class="received" style="display: none;">{{$item.received}}</span>
<span class="created" style="display: none;">{{$item.created_date}}</span>
<span class="id" style="display: none;">{{$item.id}}</span>
{{/if}}
	<div class="wall-item-item">
		<div class="wall-item-info">
			<div class="contact-photo-wrapper mframe{{if $item.owner_url}} wwfrom{{/if}} p-author h-card">
				<!-- <a aria-hidden="true" href="{{$item.profile_url}}" target="redir" title="{{$item.linktitle|escape}}" class="contact-photo-link u-url" id="wall-item-photo-link-{{$item.id}}"></a> -->
					<img src="{{$item.thumb}}" class="contact-photo {{$item.sparkle}} p-name u-photo" id="wall-item-photo-{{$item.id}}" alt="{{$item.name|escape}}" />
				<ul role="menu" aria-haspopup="true" class="contact-menu menu-popup" id="wall-item-photo-menu-{{$item.id}}">
				{{$item.item_photo_menu}}
				</ul>

			</div>
			{{if $item.owner_url}}
			<div aria-hidden="true" class="contact-photo-wrapper mframe wwto" id="wall-item-ownerphoto-wrapper-{{$item.id}}" >
				<a href="{{$item.owner_url}}" target="redir" title="{{$item.olinktitle|escape}}" class="contact-photo-link u-url" id="wall-item-ownerphoto-link-{{$item.id}}">
					<img src="{{$item.owner_photo}}" class="contact-photo {{$item.osparkle}} p-name u-photo" id="wall-item-ownerphoto-{{$item.id}}" alt="{{$item.owner_name|escape}}" />
				</a>
			</div>
			{{/if}}
		</div>
		<div role="heading" aria-level="{{$item.thread_level}}" class="wall-item-actions-author">
			<a href="{{$item.profile_url}}" target="redir" title="{{$item.linktitle|escape}}" class="wall-item-name-link"><span class="wall-item-name{{$item.sparkle}}">{{$item.name|escape}}</span></a>
			{{if $item.owner_url}}{{$item.via}} <a href="{{$item.owner_url}}" target="redir" title="{{$item.olinktitle|escape}}" class="wall-item-name-link"><span class="wall-item-name{{$item.osparkle}}" id="wall-item-ownername-{{$item.id}}">{{$item.owner_name|escape}}</span></a>{{/if}}
			<span class="wall-item-ago">
				{{if $item.plink}}<a title="{{$item.plink.title|escape}}" href="{{$item.plink.href}}" class="u-url" style="color: #999"><time class="dt-published" datetime="{{$item.localtime}}">{{$item.created}}</time></a>{{else}} <time class="dt-published" datetime="{{$item.localtime}}">{{$item.created}}</time> {{/if}}
			</span>
			{{if $item.lock}}<span class="icon s10 lock fakelink" onclick="lockview(event,{{$item.id}});" title="{{$item.lock|escape}}">{{$item.lock}}</span>{{/if}}
			<span class="wall-item-network" title="{{$item.app|escape}}">
				{{$item.network_name}}
			</span>
			<div class="wall-item-network-end"></div>
		</div>

		<div itemprop="description" class="wall-item-content">
			{{if $item.title}}<h2><a href="{{$item.plink.href}}" class="{{$item.sparkle}} p-name">{{$item.title|escape}}</a></h2>{{/if}}
			<span class="wall-item-body e-content {{if !$item.title}}p-name{{/if}}">{{$item.body}}</span>
		</div>
	</div>
	<div class="wall-item-bottom">
		<div class="wall-item-links">
		</div>
		<div class="wall-item-tags">
		{{if !$item.suppress_tags}}
			{{foreach $item.hashtags as $tag}}
				<span class="tag">{{$tag}}</span>
			{{/foreach}}
			{{foreach $item.mentions as $tag}}
				<span class="mention">{{$tag}}</span>
			{{/foreach}}
		{{/if}}
			{{foreach $item.folders as $cat}}
				<span class="folder p-category">{{$cat.name|escape}}</a>{{if $cat.removeurl}} (<a href="{{$cat.removeurl}}" title="{{$remove|escape}}">x</a>) {{/if}} </span>
			{{/foreach}}
			{{foreach $item.categories as $cat}}
				<span class="category p-category">{{$cat.name|escape}}</a>{{if $cat.removeurl}} (<a href="{{$cat.removeurl}}" title="{{$remove|escape}}">x</a>) {{/if}} </span>
			{{/foreach}}
		</div>
	</div>
	<div class="wall-item-bottom">
		<div class="wall-item-links">
			{{if $item.plink}}<a role="button" title="{{$item.plink.orig_title|escape}}" href="{{$item.plink.orig}}"><i class="icon-link icon-large"><span class="sr-only">{{$item.plink.orig_title|escape}}</span></i></a>{{/if}}
		</div>
		<div class="wall-item-actions">
			<div class="wall-item-actions-social">
			{{if $item.threaded}}
			{{/if}}
			{{if $item.comment}}
				<a role="button" id="comment-{{$item.id}}" class="fakelink togglecomment" onclick="openClose('item-comments-{{$item.id}}'); commentExpand({{$item.id}});" title="{{$item.switchcomment|escape}}"><i class="icon-commenting"><span class="sr-only">{{$item.switchcomment}}</span></i></a>
			{{/if}}

			{{if $item.isevent}}
				<a role="button" id="attendyes-{{$item.id}}"{{if $item.responses.attendyes.self}} class="active"{{/if}} title="{{$item.attend.0|escape}}" onclick="dolike({{$item.id}},'attendyes'); return false;"><i class="icon-ok icon-large"><span class="sr-only">{{$item.attend.0}}</span></i></a>
				<a role="button" id="attendno-{{$item.id}}"{{if $item.responses.attendno.self}} class="active"{{/if}} title="{{$item.attend.1|escape}}" onclick="dolike({{$item.id}},'attendno'); return false;"><i class="icon-remove icon-large"><span class="sr-only">{{$item.attend.1}}</span></i></a>
				<a role="button" id="attendmaybe-{{$item.id}}"{{if $item.responses.attendmaybe.self}} class="active"{{/if}} title="{{$item.attend.2|escape}}" onclick="dolike({{$item.id}},'attendmaybe'); return false;"><i class="icon-question icon-large"><span class="sr-only">{{$item.attend.2}}</span></i></a>
			{{/if}}

			{{if $item.vote}}
				{{if $item.vote.like}}
				<a role="button" id="like-{{$item.id}}"{{if $item.responses.like.self}} class="active"{{/if}} title="{{$item.vote.like.0|escape}}" onclick="dolike({{$item.id}},'like'); return false"><i class="icon-thumbs-up icon-large"><span class="sr-only">{{$item.vote.like.0}}</span></i></a>
				{{/if}}{{if $item.vote.dislike}}
				<a role="button" id="dislike-{{$item.id}}"{{if $item.responses.dislike.self}} class="active"{{/if}} title="{{$item.vote.dislike.0|escape}}" onclick="dolike({{$item.id}},'dislike'); return false"><i class="icon-thumbs-down icon-large"><span class="sr-only">{{$item.vote.dislike.0}}</span></i></a>
				{{/if}}
			    {{if $item.vote.share}}
				    <a role="button" id="share-{{$item.id}}" title="{{$item.vote.share.0|escape}}" onclick="jotShare({{$item.id}}); return false"><i class="icon-retweet icon-large"><span class="sr-only">{{$item.vote.share.0}}</span></i></a>
			    {{/if}}
			{{/if}}

			{{if $item.star}}
				<a role="button" id="star-{{$item.id}}" onclick="dostar({{$item.id}}); return false;"  class="{{$item.star.classdo}}" title="{{$item.star.do|escape}}"><i class="icon-star icon-large"><span class="sr-only">{{$item.star.do}}</span></i></a>
				<a role="button" id="unstar-{{$item.id}}" onclick="dostar({{$item.id}}); return false;"  class="{{$item.star.classundo}}"  title="{{$item.star.undo|escape}}"><i class="icon-star-empty icon-large"><span class="sr-only">{{$item.star.undo}}</span></i></a>
			{{/if}}
			{{if $item.ignore}}
				<a role="button" id="ignore-{{$item.id}}" onclick="doignore({{$item.id}}); return false;"  class="{{$item.ignore.classdo}}"  title="{{$item.ignore.do|escape}}"><i class="icon-bell-slash icon-large"><span class="sr-only">{{$item.ignore.do}}</span></i></a>
				<a role="button" id="unignore-{{$item.id}}" onclick="doignore({{$item.id}}); return false;"  class="{{$item.ignore.classundo}}"  title="{{$item.ignore.undo|escape}}"><i class="icon-bell-slash-o icon-large"><span class="sr-only">{{$item.ignore.undo}}</span></i></a>
			{{/if}}
			{{if $item.tagger}}
				<a role="button" id="tagger-{{$item.id}}" onclick="itemTag({{$item.id}}); return false;" class="{{$item.tagger.class}}" title="{{$item.tagger.add|escape}}"><i class="icon-tags icon-large"><span class="sr-only">{{$item.tagger.add}}</span></i></a>
			{{/if}}
			{{if $item.filer}}
                                <a role="button" id="filer-{{$item.id}}" onclick="itemFiler({{$item.id}}); return false;" class="filer-item filer-icon" title="{{$item.filer|escape}}"><i class="icon-folder-close icon-large"><span class="sr-only">{{$item.filer}}</span></i></a>
			{{/if}}
			</div>

			<div class="wall-item-location">{{$item.location}} {{$item.postopts}}</div>

			<div class="wall-item-actions-isevent">
			</div>

			<div class="wall-item-actions-tools">

				{{if $item.drop.pagedrop}}
					<input type="checkbox" title="{{$item.drop.select|escape}}" name="itemselected[]" class="item-select" value="{{$item.id}}" />
				{{/if}}
				{{if $item.drop.dropping}}
					<a role="button" href="item/drop/{{$item.id}}/{{$item.return}}" onclick="return confirmDelete();" title="{{$item.drop.delete|escape}}"><i class="icon-trash icon-large"><span class="sr-only">{{$item.drop.delete}}</span></i></a>
				{{/if}}
				{{if $item.edpost}}
					<a role="button" href="{{$item.edpost.0}}" title="{{$item.edpost.1|escape}}"><i class="icon-edit icon-large"><span class="sr-only">{{$item.edpost.1}}</span></i></a>
				{{/if}}
			</div>

		</div>
	</div>
	<div class="wall-item-bottom">
		<div class="wall-item-links">
		</div>
		{{if $item.responses}}
			{{foreach $item.responses as $verb=>$response}}
				<div class="wall-item-{{$verb}}" id="wall-item-{{$verb}}-{{$item.id}}">{{$response.output}}</div>
			{{/foreach}}
		{{/if}}

	</div>

	{{if $item.threaded}}{{if $item.comment}}
	<div class="wall-item-bottom">
		<div class="wall-item-links">
		</div>
		<div class="wall-item-comment-wrapper" id="item-comments-{{$item.id}}" style="display: none;">
					{{$item.comment}}
		</div>
	</div>
	{{/if}}{{/if}}
</div>


{{foreach $item.children as $child}}
	{{if $item.type == tag}}
		{{include file="wall_item_tag.tpl" item=$child}}
	{{else}}
		{{include file="{{$item.template}}" item=$child}}
	{{/if}}
{{/foreach}}

{{if $item.thread_level!=1}}</div>{{/if}}


{{if $mode == display}}
{{else}}
{{if $item.comment_lastcollapsed}}</div>{{/if}}
{{/if}}

{{if $item.total_comments_num}}
	{{if $item.threaded}}{{if $item.comment}}{{if $item.thread_level==1}}
		<div class="wall-item-comment-wrapper" id="item-comments-{{$item.id}}">{{$item.comment}}</div>
	{{/if}}{{/if}}{{/if}}

	{{if $item.flatten}}
		<div class="wall-item-comment-wrapper" id="item-comments-{{$item.id}}">{{$item.comment}}</div>
	{{/if}}
{{else}}
	{{if $item.threaded}}{{if $item.comment}}{{if $item.thread_level==1}}
		<div class="wall-item-comment-wrapper" id="item-comments-{{$item.id}}" style="display: none;">{{$item.comment}}</div>
	{{/if}}{{/if}}{{/if}}

	{{if $item.flatten}}
		<div class="wall-item-comment-wrapper" id="item-comments-{{$item.id}}" style="display: none;">{{$item.comment}}</div>
	{{/if}}
{{/if}}
