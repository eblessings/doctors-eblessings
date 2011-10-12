
<div id="profile-jot-wrapper" >
	<div id="profile-jot-banner-wrapper">
		<div id="profile-jot-desc" >&nbsp;</div>
		<div id="character-counter" class="grey"></div>
	</div>
	<div id="profile-jot-banner-end"></div>

	<form id="profile-jot-form" action="$action" method="post" >
		<input type="hidden" name="type" value="$ptyp" />
		<input type="hidden" name="profile_uid" value="$profile_uid" />
		<input type="hidden" name="return" value="$return_path" />
		<input type="hidden" name="location" id="jot-location" value="$defloc" />
		<input type="hidden" name="coord" id="jot-coord" value="" />
		<input type="hidden" name="title" id="jot-title" value="" />
		<input type="hidden" name="post_id" value="$post_id" />

		<img id="profile-jot-text-loading" src="images/rotator.gif" alt="$wait" title="$wait" style="display: none;" />
		<textarea rows="5" cols="64" class="profile-jot-text" id="profile-jot-text" name="body" >{{ if $content }}$content{{ else }}$share{{ endif }}</textarea>

		{{ if $content }}<script>initEditor();</script>{{ endif }}

<div id="profile-jot-submit-wrapper" style="display:none">
	<div id="profile-upload-wrapper" style="display: $visitor;" >
		<div id="wall-image-upload-div" ><a href="#" onclick="return false;" id="wall-image-upload" class="camera" title="$upload">image upload</a></div>
	</div> 
	<div id="profile-attach-wrapper" style="display: $visitor;" >
		<div id="wall-file-upload-div" ><a href="#" onclick="return false;" id="wall-file-upload" class="attach" title="$attach">attach file</a></div>
	</div> 

	<div id="profile-link-wrapper" style="display: $visitor;" ondragenter="linkdropper(event);" ondragover="linkdropper(event);" ondrop="linkdrop(event);" >
		<a id="profile-link" class="link" title="$weblink" ondragenter="return linkdropper(event);" ondragover="return linkdropper(event);" ondrop="linkdrop(event);" onclick="jotGetLink(); return false;">enter link</a>
	</div> 
	<div id="profile-youtube-wrapper" style="display: $visitor;" >
		<a id="profile-youtube" class="youtube" title="$youtube" onclick="jotGetVideo();return false;">youtube</a>
	</div> 
	<div id="profile-video-wrapper" style="display: $visitor;" >
		<a id="profile-video" class="video" title="$video" onclick="jotVideoURL();return false;">video url</a>
	</div> 
	<div id="profile-audio-wrapper" style="display: $visitor;" >
		<a id="profile-audio" class="audio" title="$audio" onclick="jotAudioURL();return false;">audio url</a>
	</div> 
	<div id="profile-location-wrapper" style="display: $visitor;" >
		<a id="profile-location" class="globe" title="$setloc" onclick="jotGetLocation();return false;">set location</a>
	</div> 
	<div id="profile-nolocation-wrapper" style="display: none;" >
		<a id="profile-nolocation" class="noglobe" title="$noloc" onclick="jotClearLocation();return false;">no location</a>
	</div> 
	<div id="profile-title-wrapper" style="display: $visitor;" >
		<a id="profile-title" class="article" title="$title" onclick="jotTitle();return false;">title</a>
	</div> 
	<div id="profile-jot-perms" class="profile-jot-perms" style="display: $pvisit;" >
		<a href="#profile-jot-acl-wrapper" id="jot-perms-icon" class="$lockstate"  title="$permset" >permissions</a>$bang
	</div>
<input type="submit" id="profile-jot-submit" name="submit" value="$share" />
	<div id="profile-jot-perms-end"></div>

	<div id="profile-jot-plugin-wrapper">
  	$jotplugins
	</div>

	<div id="profile-rotator-wrapper" style="display: $visitor;" >
		<img id="profile-rotator" src="images/rotator.gif" alt="$wait" title="$wait" style="display: none;" />
	</div> 
	
	<div style="display: none;">
		<div id="profile-jot-acl-wrapper" style="width:auto;height:auto;overflow:auto;">
			$acl
			<hr style="clear:both"/>
			<div id="profile-jot-email-label">$emailcc</div><input type="text" name="emailcc" id="profile-jot-email" title="$emtitle" />
			<div id="profile-jot-email-end"></div>
			$jotnets
		</div>
	</div>

</div>

<div id="profile-jot-end"></div>
</form>
</div>
