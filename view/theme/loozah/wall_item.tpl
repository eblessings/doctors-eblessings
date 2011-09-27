<div class="wall-item-outside-wrapper$indent" id="wall-item-outside-wrapper-$id" >
	<div class="wall-item-content-wrapper$indent" id="wall-item-content-wrapper-$id" >
		<div class="wall-item-info" id="wall-item-info-$id">
			<div class="wall-item-photo-wrapper" id="wall-item-photo-wrapper-$id" 
				 onmouseover="if (typeof t$id != 'undefined') clearTimeout(t$id); openMenu('wall-item-photo-menu-button-$id')" 
				 onmouseout="t$id=setTimeout('closeMenu(\'wall-item-photo-menu-button-$id\'); closeMenu(\'wall-item-photo-menu-$id\');',200)">
				<a href="$profile_url" target="redir" title="$linktitle" class="wall-item-photo-link" id="wall-item-photo-link-$id">
					<img src="$thumb" class="wall-item-photo$sparkle" id="wall-item-photo-$id" style="height: 80px; width: 80px;" alt="$name" />
				</a>
				<span onclick="openClose('wall-item-photo-menu-$id');" class="fakelink wall-item-photo-menu-button" id="wall-item-photo-menu-button-$id">menu</span>
				<div class="wall-item-photo-menu" id="wall-item-photo-menu-$id">
					<ul>
						$item_photo_menu
					</ul>
				</div>
			</div>
			<div class="wall-item-photo-end"></div>
			<div class="wall-item-wrapper" id="wall-item-wrapper-$id" >
				$lock
				<div class="wall-item-location" id="wall-item-location-$id">$location</div>				
			</div>
		</div>
		<div class="wall-item-author">
				<a href="$profile_url" target="redir" title="$linktitle" class="wall-item-name-link"><span class="wall-item-name$sparkle" id="wall-item-name-$id" >$name</span></a>
				<div class="wall-item-ago"  id="wall-item-ago-$id">$ago</div>
				
		</div>	
		<div class="wall-item-content" id="wall-item-content-$id" >
			<div class="wall-item-title" id="wall-item-title-$id">$title</div>
			<div class="wall-item-title-end"></div>
			<div class="wall-item-body" id="wall-item-body-$id" >$body</div>
		</div>
		<div class="wall-item-tools" id="wall-item-tools-$id">
			{{ if $vote }}
			<div class="wall-item-like-buttons" id="wall-item-like-buttons-$id">
				<a href="#" class="icon like" title="$vote.like.0" onclick="dolike($id,'like'); return false"></a>
				<a href="#" class="icon dislike" title="$vote.dislike.0" onclick="dolike($id,'dislike'); return false"></a>
				{{ if $vote.share }}<a href="#" class="icon recycle wall-item-share-buttons" title=""$vote.share.0" onclick="jotShare($id); return false"></a>{{ endif }}
				<img id="like-rotator-$id" class="like-rotator" src="images/rotator.gif" alt="$wait" title="$wait" style="display: none;" />
			</div>
			{{ endif }}
			{{ if $plink }}
				<div class="wall-item-links-wrapper"><a href="$plink.href" title="$plink.title" target="external-link" class="icon remote-link"></a></div>
			{{ endif }}
			{{ if $edpost }}
				<a class="editpost icon pencil" href="$edpost.0" title="$edpost.1"></a>
			{{ endif }}
			 
			{{ if $star }}
			<a href="#" id="starred-$id" onclick="dostar($id); return false;" class="star-item icon $isstarred" title="$star.toggle"></a>
			{{ endif }}
			
			<div class="wall-item-delete-wrapper" id="wall-item-delete-wrapper-$id" >
				{{ if $drop.dropping }}<a href="item/drop/$id" onclick="return confirmDelete();" class="icon drophide" title="$drop.delete" onmouseover="imgbright(this);" onmouseout="imgdull(this);" ></a>{{ endif }}
			</div>
				{{ if $drop.dropping }}<input type="checkbox" onclick="checkboxhighlight(this);" title="$drop.select" class="item-select" name="itemselected[]" value="$id" />{{ endif }}
			<div class="wall-item-delete-end"></div>
		</div>
	</div>
	<div class="wall-item-wrapper-end"></div>
	<div class="wall-item-like" id="wall-item-like-$id">$like</div>
	<div class="wall-item-dislike" id="wall-item-dislike-$id">$dislike</div>
	<div class="wall-item-comment-wrapper" >
	$comment
	</div>

<div class="wall-item-outside-wrapper-end$indent" ></div>
</div>
