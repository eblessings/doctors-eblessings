{{if $delivery.queue_count == 0}}
	<i class="fa fa-hourglass-o" aria-hidden="true" title="{{$delivery.notifier_pending|escape}}"></i>
	<span class="sr-only">{{$delivery.notifier_pending|escape}}</span>
{{elseif $delivery.queue_done == 0}}
	<i class="fa fa-hourglass" aria-hidden="true" title="{{$delivery.delivery_pending|escape}} {{$item.delivery.queue_done}}/{{$item.delivery.queue_count}}"></i>
	<span class="sr-only">{{$delivery.delivery_pending|escape}}</span>
{{elseif $delivery.queue_done / $delivery.queue_count < 0.75}}
	<i class="fa fa-paper-plane-o" aria-hidden="true" title="{{$delivery.delivery_underway|escape}} {{$item.delivery.queue_done}}/{{$item.delivery.queue_count}}"></i>
	<span class="sr-only">{{$delivery.delivery_underway|escape}}</span>
{{else}}
	<i class="fa fa-paper-plane" aria-hidden="true" title="{{$delivery.delivery_almost|escape}} {{$item.delivery.queue_done}}/{{$item.delivery.queue_count}}"></i>
	<span class="sr-only">{{$delivery.delivery_almost|escape}}</span>
{{/if}}
