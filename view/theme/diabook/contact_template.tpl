
<div class="contact-entry-wrapper" id="contact-entry-wrapper-$contact.id" >
	<div class="contact-entry-photo-wrapper" >
		<div class="contact-entry-photo mframe" id="contact-entry-photo-$contact.id"
		onmouseover="if (typeof t$contact.id != 'undefined') clearTimeout(t$contact.id); openMenu('contact-photo-menu-button-$contact.id')" 
		onmouseout="t$contact.id=setTimeout('closeMenu(\'contact-photo-menu-button-$contact.id\'); closeMenu(\'contact-photo-menu-$contact.id\');',200)" >

			<a href="$contact.url" title="$contact.img_hover" /><img src="$contact.thumb" $contact.sparkle alt="$contact.name" /></a>

			{{ if $contact.photo_menu }}
			<span onclick="openClose('contact-photo-menu-$contact.id');" class="fakelink contact-photo-menu-button" id="contact-photo-menu-button-$contact.id">menu</span>
                <div class="contact-photo-menu" id="contact-photo-menu-$contact.id">
                    <ul>
						{{ for $contact.photo_menu as $c }}
						{{ if $c.2 }}
						<li><a target="redir" href="$c.1">$c.0</a></li>
						{{ else }}
						<li><a href="$c.1">$c.0</a></li>
						{{ endif }}
						{{ endfor }}
                    </ul>
                </div>
			{{ endif }}
		</div>
			
	</div>
	<div class="contact-entry-photo-end" ></div>
		<div class="contact-entry-name" id="contact-entry-name-$contact.id" >$contact.name</div>

	<div class="contact-entry-end" ></div>
</div>
