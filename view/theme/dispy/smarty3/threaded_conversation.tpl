{{$live_update}}

{{foreach $threads as $thread}}
{{include file="{{$thread.template}}" item=$thread}}
{{/foreach}}

<div id="conversation-end"></div>

{{if $dropping}}
<div id="item-delete-selected" class="fakelink" onclick="deleteCheckedItems(); return false;">
  <div id="item-delete-selected-icon" class="icon drophide" title="{{$dropping}}" onmouseover="imgbright(this);" onmouseout="imgdull(this);" ></div>
  <div id="item-delete-selected-desc" >{{$dropping}}</div>
</div>
<div id="item-delete-selected-end"></div>
{{/if}}
