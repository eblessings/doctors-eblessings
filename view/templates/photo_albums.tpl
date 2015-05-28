<div id="side-bar-photos-albums" class="widget">
	<h3>{{$title}}</h3>
	<ul>
		<li><a href="{{$baseurl}}/photos/{{$nick}}" title="{{$title}}" >Recent Photos</a></li>
		{{if $albums}}
		{{foreach $albums as $al}}
		{{if $al.text}}
		<li><a href="{{$baseurl}}/photos/{{$nick}}/album/{{$al.bin2hex}}"><span class="badge pull-right">{{$al.total}}</span>{{$al.text}}</a></li>
		{{/if}}
		{{/foreach}}
		{{/if}}
	</ul>

        {{if $can_post}}
        <div class="photos-upload-link" ><a href="{{$upload.1}}">{{$upload.0}}</a></div>
        {{/if}}
</div>
