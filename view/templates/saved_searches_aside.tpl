
<div class="widget" id="saved-search-list">
	<h3 id="search">{{$title}}</h3>
	{{$searchbox nofilter}}
	
	<ul role="menu" id="saved-search-ul">
		{{foreach $saved as $search}}
		<li role="menuitem" class="saved-search-li clear">
			<a title="{{$search.delete}}" onclick="return confirmDelete();" id="drop-saved-search-term-{{$search.id}}" class="iconspacer savedsearchdrop " href="network/?f=&amp;remove=1&amp;search={{$search.encodedterm}}"></a>
			<a id="saved-search-term-{{$search.id}}" class="savedsearchterm" href="search?search={{$search.encodedterm}}">{{$search.term}}</a>
		</li>
		{{/foreach}}
	</ul>
	<div class="clear"></div>
</div>
