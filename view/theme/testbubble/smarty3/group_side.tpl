<div class="widget" id="group-sidebar">
<h3>{{$title}}</h3>

<div id="sidebar-group-list">
	<ul id="sidebar-group-ul">
		{{foreach $groups as $group}}
			<li class="sidebar-group-li">
				{{if $group.cid}}
					<input type="checkbox" 
						class="{{if $group.selected}}ticked{{else}}unticked {{/if}} action" 
						onclick="contactgroupChangeMember('{{$group.id}}','{{$group.cid}}');return true;"
						{{if $group.ismember}}checked="checked"{{/if}}
					/>
				{{/if}}			
				{{if $group.edit}}
					<a class="groupsideedit" href="{{$group.edit.href}}"><span class="icon small-pencil"></span></a>
				{{/if}}
				<a class="sidebar-group-element {{if $group.selected}}group-selected{{/if}}" href="{{$group.href}}">{{$group.text}}</a>
			</li>
		{{/foreach}}
	</ul>
	</div>
  <div id="sidebar-new-group">
  <a href="group/new">{{$createtext}}</a>
  </div>
</div>


