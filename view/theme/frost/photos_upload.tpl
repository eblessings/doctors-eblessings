<h3>$pagename</h3>

<div id="photos-usage-message">$usage</div>

<form action="photos/$nickname" enctype="multipart/form-data" method="post" name="photos-upload-form" id="photos-upload-form" >
	<div id="photos-upload-new-wrapper" >
		<div id="photos-upload-newalbum-div">
			<label id="photos-upload-newalbum-text" for="photos-upload-newalbum" >$newalbum</label>
		</div>
		<input id="photos-upload-newalbum" type="text" name="newalbum" />
	</div>
	<div id="photos-upload-new-end"></div>
	<div id="photos-upload-exist-wrapper">
		<div id="photos-upload-existing-album-text">$existalbumtext</div>
		$albumselect
	</div>
	<div id="photos-upload-exist-end"></div>

	<div id="photos-upload-choosefile-outer-wrapper">
	<div id="photos-upload-noshare-div" class="photos-upload-noshare-div" >
		<input id="photos-upload-noshare" type="checkbox" name="not_visible" value="1" checked />
		<div id="photos-upload-noshare-label">
		<label id="photos-upload-noshare-text" for="photos-upload-noshare" >$nosharetext</label>
		</div>
	</div>

	<div id="photos-upload-perms" class="photos-upload-perms" >
		<a href="#photos-upload-permissions-wrapper" id="photos-upload-perms-menu" class="popupbox button" />
		<span id="jot-perms-icon" class="icon $lockstate  photo-perms-icon" ></span><div class="photo-jot-perms-text">$permissions</div>
		</a>
	<div id="photos-upload-perms-end"></div>

	<div style="display: none;">
		<div id="photos-upload-permissions-wrapper">
			$aclselect
		</div>
	</div>

	<div id="photos-upload-spacer"></div>

	$uploader

	$default

	<div class="photos-upload-end" ></div>
	</div>
</form>

<!--
<script>
	$("a#photos-upload-perms-menu").fancybox({
		'transitionIn' : 'none',
		'transitionOut' : 'none'
	}); 
</script>-->
