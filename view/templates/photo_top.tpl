<div class="photo-top-image-wrapper lframe" id="photo-top-image-wrapper-{{$photo.id}}">
	<a href="{{$photo.link}}" class="photo-top-photo-link" id="photo-top-photo-link-{{$photo.id}}" title="{{$photo.title|escape}}">
		<img src="{{$photo.src}}" alt="{{$photo.alt|escape}}" title="{{$photo.title|escape}}" class="photo-top-photo{{$photo.twist}}" id="photo-top-photo-{{$photo.id}}" />
	</a>
	<div class="photo-top-album-name"><a href="{{$photo.album.link}}" class="photo-top-album-link" title="{{$photo.album.alt|escape}}" >{{$photo.album.name|escape}}</a></div>
</div>

