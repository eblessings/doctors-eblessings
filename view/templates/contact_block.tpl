
<div id="contact-block">
<h3 class="contact-block-h4">{{$contacts}}</h3>
{{if $micropro}}
		<a class="allcontact-link" href="viewcontacts/{{$nickname}}">{{$viewcontacts}}</a>
		<div class='contact-block-content'>
		{{foreach $micropro as $m}}
			{{$m nofilter}}
		{{/foreach}}
		</div>
{{/if}}
</div>
<div class="clear"></div>
