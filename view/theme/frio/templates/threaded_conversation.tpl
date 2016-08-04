<script type="text/javascript" src="view/theme/frio/frameworks/jquery-color/jquery.color.js"></script>

{{$live_update}}

{{foreach $threads as $thread}}
<hr class="sr-only" />
<div id="tread-wrapper-{{$thread.id}}" class="tread-wrapper {{if $thread.threaded}}threaded{{/if}} {{$thread.toplevel}} {{$thread.network}} {{if $thread.thread_level==1}}panel-default panel{{/if}} {{if $thread.thread_level!=1}}comment-wrapper{{/if}}" style="{{if $item.thread_level>2}}margin-left: -15px; margin-right:-16px; margin-bottom:-16px;{{/if}}"><!-- panel -->

		{{* {{if $thread.type == tag}}
			{{include file="wall_item_tag.tpl" item=$thread}}
		{{else}}
			{{include file="{{$thread.template}}" item=$thread}}
		{{/if}} *}} {{include file="{{$thread.template}}" item=$thread}}

</div><!--./tread-wrapper-->
{{/foreach}}

<div id="conversation-end"></div>

{{if $dropping}}
<a id="item-delete-selected" class="" href="#" title="{{$dropping}}" onclick="deleteCheckedItems();return false;" data-toggle="tooltip">
	<i class="fa fa-trash" aria-hidden="true"></i>
</a>
<img id="item-delete-selected-rotator" class="like-rotator" src="images/rotator.gif" style="display: none;" />
{{/if}}

<script>
    var colWhite = {backgroundColor:'#F5F5F5'};
    var colShiny = {backgroundColor:'#FFF176'};
</script>

{{if $mode == display}}
<script>
    var id = window.location.pathname.split("/").pop();
    $(window).scrollTop($('#item-'+id).position().top);
    $('#item-'+id).animate(colWhite, 1000).animate(colShiny).animate(colWhite, 2000);   
</script>
{{/if}}

