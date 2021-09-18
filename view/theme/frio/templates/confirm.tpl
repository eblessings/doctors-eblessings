
<form action="{{$confirm_url}}" id="confirm-form" method="{{$method}}" class="generic-page-wrapper">
	<div id="confirm-message">{{$message}}</div>

	<div class="form-group pull-right settings-submit-wrapper">
		<button type="submit" name="{{$confirm_name}}" value="{{$confirm_value}}" id="confirm-submit-button" class="btn btn-primary confirm-button" value="{{$confirm_value}}">{{$confirm}}</button>
		<button type="submit" name="canceled" value="{{$cancel}} id="confirm-cancel-button" class="btn confirm-button" data-dismiss="modal">{{$cancel}}</button>
	</div>
</form>
