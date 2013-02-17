<div class="widget{{ if $class }} $class{{ endif }}">
{#<!--	{{if $title}}<h3>$title</h3>{{endif}}-->#}
	{{if $desc}}<div class="desc">$desc</div>{{endif}}
	
	<ul class="tabs links-widget">
		{{ for $items as $item }}
			<li class="tool"><a href="$item.url" class="tab {{ if $item.selected }}selected{{ endif }}">$item.label</a></li>
		{{ endfor }}
		<div id="tabs-end"></div>
	</ul>
	
</div>
