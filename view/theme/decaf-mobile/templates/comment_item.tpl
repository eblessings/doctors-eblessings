
{{*<!--		<script>
		$(document).ready( function () {
			$(document).mouseup(function(e) {
				var container = $("#comment-edit-wrapper-{{$id}}");
				if( container.has(e.target).length === 0) {
					commentClose(document.getElementById('comment-edit-text-{{$id}}'),{{$id}});
					cmtBbClose({{$id}});
				}
			});
		});
		</script>-->*}}

		<div class="comment-wwedit-wrapper {{$indent}}" id="comment-edit-wrapper-{{$id}}" style="display: block;" >
			<a name="comment-wwedit-wrapper-pos"></a>
			<form class="comment-edit-form {{$indent}}" id="comment-edit-form-{{$id}}" action="item" method="post" >
{{*<!--			<span id="hide-commentbox-{{$id}}" class="hide-commentbox fakelink" onclick="showHideCommentBox({{$id}});">{{$comment}}</span>
			<form class="comment-edit-form" style="display: none;" id="comment-edit-form-{{$id}}" action="item" method="post" onsubmit="post_comment({{$id}}); return false;">-->*}}
				<input type="hidden" name="type" value="{{$type}}" />
				<input type="hidden" name="source" value="{{$sourceapp}}" />
				<input type="hidden" name="profile_uid" value="{{$profile_uid}}" />
				<input type="hidden" name="parent" value="{{$parent}}" />
				<input type="hidden" name="return" value="{{$return_path}}#comment-wwedit-wrapper-pos" />
				<input type="hidden" name="jsreload" value="{{$jsreload}}" />
				<input type="hidden" name="preview" id="comment-preview-inp-{{$id}}" value="0" />
				<input type="hidden" name="post_id_random" value="{{$rand_num}}" />

				{{*<!--<div class="comment-edit-photo" id="comment-edit-photo-{{$id}}" >-->*}}
					<a class="comment-edit-photo comment-edit-photo-link" id="comment-edit-photo-{{$id}}" href="{{$mylink}}" title="{{$mytitle}}"><img class="my-comment-photo" src="{{$myphoto}}" alt="{{$mytitle}}" title="{{$mytitle}}" /></a>
				{{*<!--</div>-->*}}
				{{*<!--<div class="comment-edit-photo-end"></div>-->*}}
				{{*<!--<ul class="comment-edit-bb-{{$id}}">
					<li><a class="editicon boldbb shadow"
						style="cursor: pointer;" title="{{$edbold}}"
						onclick="insertFormatting('{{$comment}}','b', {{$id}});"></a></li>
					<li><a class="editicon italicbb shadow"
						style="cursor: pointer;" title="{{$editalic}}"
						onclick="insertFormatting('{{$comment}}','i', {{$id}});"></a></li>
					<li><a class="editicon underlinebb shadow"
						style="cursor: pointer;" title="{{$eduline}}"
						onclick="insertFormatting('{{$comment}}','u', {{$id}});"></a></li>
					<li><a class="editicon quotebb shadow"
						style="cursor: pointer;" title="{{$edquote}}"
						onclick="insertFormatting('{{$comment}}','quote', {{$id}});"></a></li>
					<li><a class="editicon codebb shadow"
						style="cursor: pointer;" title="{{$edcode}}"
						onclick="insertFormatting('{{$comment}}','code', {{$id}});"></a></li>-->*}}
{{*<!--					<li><a class="editicon imagebb shadow"
						style="cursor: pointer;" title="{{$edimg}}"
						onclick="insertFormatting('{{$comment}}','img', {{$id}});"></a></li>
					<li><a class="editicon urlbb shadow"
						style="cursor: pointer;" title="{{$edurl}}"
						onclick="insertFormatting('{{$comment}}','url', {{$id}});"></a></li>
					<li><a class="editicon videobb shadow"
						style="cursor: pointer;" title="{{$edvideo}}"
						onclick="insertFormatting('{{$comment}}','video', {{$id}});"></a></li>-->*}}
				{{*<!--</ul>	-->*}}
				{{*<!--<div class="comment-edit-bb-end"></div>-->*}}
{{*<!--				<textarea id="comment-edit-text-{{$id}}" class="comment-edit-text-empty" name="body" onFocus="commentOpen(this,{{$id}});cmtBbOpen({{$id}});" onBlur="commentClose(this,{{$id}});cmtBbClose({{$id}});" >{{$comment}}</textarea>-->*}}
				<textarea id="comment-edit-text-{{$id}}" class="comment-edit-text-full" name="body" ></textarea>
				{{*<!--{{if $qcomment}}
					<select id="qcomment-select-{{$id}}" name="qcomment-{{$id}}" class="qcomment" onchange="qCommentInsert(this,{{$id}});" >
					<option value=""></option>
				{{foreach $qcomment as $qc}}
					<option value="{{$qc}}">{{$qc}}</option>				
				{{/foreach}}
					</select>
				{{/if}}-->*}}

				<div class="comment-edit-text-end"></div>
				<div class="comment-edit-submit-wrapper" id="comment-edit-submit-wrapper-{{$id}}" >
					<input type="submit" id="comment-edit-submit-{{$id}}" class="comment-edit-submit" name="submit" value="{{$submit}}" />
					{{*<!--<span onclick="preview_comment({{$id}});" id="comment-edit-preview-link-{{$id}}" class="preview-link fakelink">{{$preview}}</span>
					<div id="comment-edit-preview-{{$id}}" class="comment-edit-preview" style="display:none;"></div>-->*}}
				</div>

				{{*<!--<div class="comment-edit-end"></div>-->*}}
			</form>

		</div>
