<form action="$action" method="post">
	<div id="jot">
		<div id="profile-jot-desc" class="jothidden" >&nbsp;</div>
		<input name="title" id="jot-title" type="text" placeholder="$placeholdertitle" value="$title" class="jothidden" style="display:none">
		<div id="character-counter" class="grey jothidden"></div>
		
		<input type="hidden" name="type" value="$ptyp" />
		<input type="hidden" name="profile_uid" value="$profile_uid" />
		<input type="hidden" name="return" value="$return_path" />
		<input type="hidden" name="location" id="jot-location" value="$defloc" />
		<input type="hidden" name="coord" id="jot-coord" value="" />
		<input type="hidden" name="post_id" value="$post_id" />
		
		<textarea rows="5" cols="64" class="profile-jot-text" id="profile-jot-text" name="body" >{{ if $content }}$content{{ else }}$share{{ endif }}</textarea>		
		{{ if $content }}<script>initEditor();</script>{{ endif }}
		
		
		<ul id="jot-tools" class="jothidden" style="display:none">
			<li><a href="#" onclick="return false;" id="wall-image-upload">$upload</a></li>
			<li><a href="#" onclick="return false;" id="wall-file-upload" >$attach</a></li>
			<li><a id="profile-link"  ondragenter="return linkdropper(event);" ondragover="return linkdropper(event);" ondrop="linkdrop(event);" onclick="jotGetLink(); return false;">$weblink</a></li>
			<li><a id="profile-video" onclick="jotVideoURL();return false;">$video</a></li>
			<li><a id="profile-audio" onclick="jotAudioURL();return false;">$audio</a></li>
			<li><a id="profile-location" onclick="jotGetLocation();return false;">$setloc</a></li>
			<li><a id="profile-nolocation" onclick="jotClearLocation();return false;">$noloc</a></li>
			$jotplugins

			<li class="perms"><a id="jot-perms-icon" href="#profile-jot-acl-wrapper" class="icon s22 $lockstate $bang"  title="$permset" ></a></li>
			<li class="submit"><input type="submit" id="profile-jot-submit" name="submit" value="$share" /></li>
			<li id="profile-rotator" class="loading" style="display: none"><img src="images/rotator.gif" alt="$wait" title="$wait"  /></li>
		</ul>
	</div>
	
</form>

<div style="display: none;">
	<div id="profile-jot-acl-wrapper" style="width:auto;height:auto;overflow:auto;">
		$acl
		<hr style="clear:both"/>
		<div id="profile-jot-email-label">$emailcc</div><input type="text" name="emailcc" id="profile-jot-email" title="$emtitle" />
		<div id="profile-jot-email-end"></div>
		$jotnets
	</div>
</div>	




