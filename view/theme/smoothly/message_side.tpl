<div id="message-sidebar" class="widget">
	<br />
	<div id="side-invite-link" class="side-link" ><a href="$new.url" class="{{ if $new.sel }}newmessage-selected{{ endif }}">$new.label</a> </div>
	
	<ul class="message-ul">
		{{ for $tabs as $t }}
			<li class="tool">
			<a href="$t.url" class="message-link{{ if $t.sel }}message-selected{{ endif }}">$t.label</a>
			</li>
		{{ endfor }}
	</ul>
	
</div>
