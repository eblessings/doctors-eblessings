
{{$live_update}}

{{foreach $threads as $thread}}
{{if $mode == display}}
{{include file="{{$thread.template}}" item=$thread}}
{{else}}
{{include file="wall_thread_toponly.tpl" item=$thread}}
{{/if}}
{{/foreach}}

<div id="conversation-end"></div>

