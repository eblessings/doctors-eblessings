
{{foreach $events as $event}}
	<div class="event-wrapper">
		<div class="event">
			<div class="media">
				<div class="event-owner media-left">
					{{if $event.item.author_name}}
					<a href="{{$event.item.author_link}}" ><img src="{{$event.item.author_avatar}}" /></a>
					<a href="{{$event.item.author_link}}" >{{$event.item.author_name|escape}}</a>
					{{/if}}
				</div>
				<div class="media-body">
					{{$event.html}}
				</div>
			</div>

			<div class="event-buttons pull-right">
				{{if $event.edit}}<button type="button" class="btn" onclick="eventEdit('{{$event.edit.0}}')" title="{{$event.edit.1|escape}}"><i class="fa fa-pencil" aria-hidden="true"></i></button>{{/if}}
				{{if $event.copy}}<button type="button" class="btn" onclick="eventEdit('{{$event.copy.0}}')" title="{{$event.copy.1|escape}}"><i class="fa fa-files-o" aria-hidden="true"></i></button>{{/if}}
				{{if $event.drop}}<a href="{{$event.drop.0}}" onclick="return confirmDelete();" title="{{$event.drop.1|escape}}" class="drop-event-link btn"><i class="fa fa-trash-o" aria-hidden="true"></i></a>{{/if}}
				{{if $event.item.plink}}<a href="{{$event.plink.0}}" title="{{$event.plink.1|escape}}" class="plink-event-link btn "><i class="fa fa-external-link" aria-hidden="true"></i></a>{{/if}}
			</div>
			<div class="clear"></div>
		</div>
	</div>
{{/foreach}}
