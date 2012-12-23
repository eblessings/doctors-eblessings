		{{ if $threaded }}
		<div class="comment-wwedit-wrapper threaded" id="comment-edit-wrapper-$id" style="display: block;">
		{{ else }}
		<div class="comment-wwedit-wrapper" id="comment-edit-wrapper-$id" style="display: block;">
		{{ endif }}
			<form class="comment-edit-form" style="display: block;" id="comment-edit-form-$id" action="item" method="post" onsubmit="post_comment($id); return false;">
				<input type="hidden" name="type" value="$type" />
				<input type="hidden" name="profile_uid" value="$profile_uid" />
				<input type="hidden" name="parent" value="$parent" />
				<input type="hidden" name="return" value="$return_path" />
				<input type="hidden" name="jsreload" value="$jsreload" />
				<input type="hidden" name="preview" id="comment-preview-inp-$id" value="0" />
				<input type="hidden" name="post_id_random" value="$rand_num" />

				<div class="comment-edit-photo" id="comment-edit-photo-$id" >
					<a class="comment-edit-photo-link" href="$mylink" title="$mytitle"><img class="my-comment-photo" src="$myphoto" alt="$mytitle" title="$mytitle" /></a>
				</div>
				<div class="comment-edit-photo-end"></div>
				<textarea id="comment-edit-text-$id" class="comment-edit-text-empty" name="body" onFocus="commentOpen(this,$id);">$comment</textarea>
				{{ if $qcomment }}
					<select id="qcomment-select-$id" name="qcomment-$id" class="qcomment" onchange="qCommentInsert(this,$id);" >
					<option value=""></option>
				{{ for $qcomment as $qc }}
					<option value="$qc">$qc</option>				
				{{ endfor }}
					</select>
				{{ endif }}

				<div class="comment-edit-text-end"></div>
				<div class="comment-edit-submit-wrapper" id="comment-edit-submit-wrapper-$id" style="display: none;" >

				<div class="comment-edit-bb-$id">
	                                <a class="icon bb-image" style="cursor: pointer;" title="$edimg" onclick="insertFormatting('$comment','img',$id);">img</a>      
	                                <a class="icon bb-url" style="cursor: pointer;" title="$edurl" onclick="insertFormatting('$comment','url',$id);">url</a>
	                                <a class="icon bb-video" style="cursor: pointer;" title="$edvideo" onclick="insertFormatting('$comment','video',$id);">video</a>                                
                                                                                
	                                <a class="icon underline" style="cursor: pointer;" title="$eduline" onclick="insertFormatting('$comment','u',$id);">u</a>
	                                <a class="icon italic" style="cursor: pointer;" title="$editalic" onclick="insertFormatting('$comment','i',$id);">i</a>
	                                <a class="icon bold" style="cursor: pointer;"  title="$edbold" onclick="insertFormatting('$comment','b',$id);">b</a>
	                                <a class="icon quote" style="cursor: pointer;" title="$edquote" onclick="insertFormatting('$comment','quote',$id);">quote</a>
                                </div>
					<input type="submit" onclick="post_comment($id); return false;" id="comment-edit-submit-$id" class="comment-edit-submit" name="submit" value="$submit" />
					<span onclick="preview_comment($id);" id="comment-edit-preview-link-$id" class="fakelink">$preview</span>
					<div id="comment-edit-preview-$id" class="comment-edit-preview" style="display:none;"></div>
				</div>

				<div class="comment-edit-end"></div>
			</form>

		</div>
