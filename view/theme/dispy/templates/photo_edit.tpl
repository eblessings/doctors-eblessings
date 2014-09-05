

<form action="photos/{{$nickname}}/{{$resource_id}}" method="post" id="photo_edit_form" >

	<input type="hidden" name="item_id" value="{{$item_id}}" />

	<label id="photo-edit-albumname-label" for="photo-edit-albumname">{{$newalbum}}</label>
	<input id="photo-edit-albumname" type="text" name="albname" value="{{$album}}" />

	<div id="photo-edit-albumname-end"></div>

	<label id="photo-edit-caption-label" for="photo-edit-caption">{{$capt_label}}</label>
	<input id="photo-edit-caption" type="text" name="desc" value="{{$caption}}" />

	<div id="photo-edit-caption-end"></div>

	<label id="photo-edit-tags-label" for="photo-edit-newtag" >{{$tag_label}}</label>
	<input name="newtag" id="photo-edit-newtag" title="{{$help_tags}}" type="text" />

	<div id="photo-edit-tags-end"></div>
	<div id="photo-edit-rotate-wrapper">
		<div id="photo-edit-rotate-label">{{$rotate}}</div>
		<input type="checkbox" name="rotate" value="1" />
	</div>
	<div id="photo-edit-rotate-end"></div>

	<div id="photo-edit-perms" class="photo-edit-perms" >
		<a href="#photo-edit-perms-select"
			id="photo-edit-perms-menu"
			class="button"
			title="{{$permissions}}"/><span id="jot-perms-icon"
			class="icon {{$lockstate}}" ></span>{{$permissions}}</a>
		<div id="photo-edit-perms-menu-end"></div>

		<div style="display: none;">
			<div id="photo-edit-perms-select" >
				{{$aclselect}}
			</div>
		</div>
	</div>
	<div id="photo-edit-perms-end"></div>

	<input id="photo-edit-submit-button" type="submit" name="submit" value="{{$submit}}" />
	<input id="photo-edit-delete-button" type="submit" name="delete" value="{{$delete}}" onclick="return confirmDelete()"; />

	<div id="photo-edit-end"></div>
</form>

<script type="text/javascript">
	$("a#photo-edit-perms-menu").colorbox({
		'inline' : true,
		'transition' : 'none'
	}); 
</script>
