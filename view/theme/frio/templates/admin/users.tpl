<script>
	function confirm_delete(uname){
		return confirm( "{{$confirm_delete}}".format(uname));
	}
	function confirm_delete_multi(){
		return confirm("{{$confirm_delete_multi}}");
	}
	function selectall(cls){
		$("."+cls).prop("checked", true);
		return false;
	}
	function unselectall(cls){
		$("."+cls).prop("checked", false);
		return false;
	}
	function details(uid) {
		$("#user-"+uid+"-detail").toggleClass("hidden");
		return false;
	}
</script>
<style>
	tr.details td,
	tr.details th
		{ border-top: 0!important; }
</style>

<div class="panel panel-default">
	<div class="panel-body"><h1>{{$title}} - {{$page}}</h1></div>
</div>

	<form action="{{$baseurl}}/admin/users" method="post">
		<input type='hidden' name='form_security_token' value='{{$form_security_token}}'>


		<!--
			**
			*
			*		PENDING Users table
			*
			**
		-->
		<div class="panel panel-default">
			<div class="panel-heading"><h3 class="panel-title">{{$h_pending}}</h3></div>

			{{if $pending}}
				<table id='pending' class="table table-hover">
					<thead>
					<tr>
						{{foreach $th_pending as $th}}<th>{{$th}}</th>{{/foreach}}
						<th>
							<a href='#' onclick="return selectall('pending_ckbx');"><i class="fa fa-check-square-o" aria-hidden="true"></i></a>
							<a href='#' onclick="return unselectall('pending_ckbx');"><i class="fa fa-square-o" aria-hidden="true"></i></a>
						</th>
						<th></th>
					</tr>
					</thead>
					<tbody>
				{{foreach $pending as $u}}
					<tr>
						<td>{{$u.created}}</td>
						<td >{{$u.name}}</td>
						<td>{{$u.email}}</td>
						<td ><input type="checkbox" class="pending_ckbx" id="id_pending_{{$u.hash}}" name="pending[]" value="{{$u.hash}}" /></td>
						<td>
							<a href="{{$baseurl}}/regmod/allow/{{$u.hash}}" title='{{$approve}}'><i class="fa fa-thumbs-up" aria-hidden="true"></i></a>
							<a href="{{$baseurl}}/regmod/deny/{{$u.hash}}" title='{{$deny}}'><i class="fa fa-thumbs-down" aria-hidden="true"></i></a>
						</td>
					</tr>
					<tr class="details">
						<th>{{$pendingnotetext}}</th>
						<td colspan="4">{{$u.note}}</td>
					</tr>
				{{/foreach}}
					</tbody>
				</table>
				<div class="panel-footer text-right">
					<button type="submit" name="page_users_deny" class="btn btn-primary"><i class="fa fa-thumbs-down" aria-hidden="true"></i> {{$deny}}</button>
					<button type="submit" name="page_users_approve" class="btn btn-warinig"><i class="fa fa-thumbs-up" aria-hidden="true"></i> {{$approve}}</button>
				</div>
			{{else}}
				<div class="panel-body text-center text-muted">{{$no_pending}}</div>
			{{/if}}
		</div>

<!--
	**
	*
	*		USERS Table
	*
	**
-->
	<div class="panel panel-default">
		<div class="panel-heading"><h3 class="panel-title">{{$h_users}}</h3></div>
		{{if $users}}

			<table id='users' class="table table-hover">
				<thead>
				<tr>
					<th></th>
					{{foreach $th_users as $k=>$th}}
					{{if $k < 2 || $order_users == $th.1 || ($k==5 && !in_array($order_users,[$th_users.2.1, $th_users.3.1, $th_users.4.1])) }}
					<th>
						<a href="{{$baseurl}}/admin/users/?o={{if $order_direction_users == "+"}}-{{/if}}{{$th.1}}">
							{{if $order_users == $th.1}}
								{{if $order_direction_users == "+"}}
								&#8595;
								{{else}}
								&#8593;
								{{/if}}
							{{else}}
								&#8597;
							{{/if}}
						{{$th.0}}</a>
					</th>
					{{/if}}
					{{/foreach}}
					<th>
						<a href='#' onclick="return selectall('users_ckbx');"><i class="fa fa-check-square-o" aria-hidden="true"></i></a>
						<a href='#' onclick="return unselectall('users_ckbx');"><i class="fa fa-square-o" aria-hidden="true"></i></a>
					</th>
					<th></th>
				</tr>
				</thead>
				<tbody>
				{{foreach $users as $u}}
					<tr id="user-{{$u.uid}}">
						<td><img class='icon' src="{{$u.micro}}" title="{{$u.nickname}}"></td>
						<td><a href="{{$u.url}}" title="{{$u.nickname}}"> {{$u.name}}</a></td>
						<td>{{$u.email}}</td>
						{{if $order_users == $th_users.2.1}}
						<td class='register_date'>{{$u.register_date}}</td>
						{{/if}}

						{{if $order_users == $th_users.3.1}}
						<td class='login_date'>{{$u.login_date}}</td>
						{{/if}}

						{{if $order_users == $th_users.4.1}}
						<td class='lastitem_date'>{{$u.lastitem_date}}</td>
						{{/if}}

						{{if !in_array($order_users,[$th_users.2.1, $th_users.3.1, $th_users.4.1]) }}
						<td>{{$u.page_flags}} {{if $u.is_admin}}({{$siteadmin}}){{/if}} {{if $u.account_expired}}({{$accountexpired}}){{/if}}</td>
						{{/if}}
						<td>
						{{if $u.is_deletable}}
							<input type="checkbox" class="users_ckbx" id="id_user_{{$u.uid}}" name="user[]" value="{{$u.uid}}"/></td>
						{{else}}
							&nbsp;
						{{/if}}
						<td class="text-right">
							<a href="#" onclick="return details({{$u.uid}})"><i class="fa fa-bars" aria-hidden="true"></i>
						</td>
					</tr>
					<tr id="user-{{$u.uid}}-detail" class="hidden details">
						<td>&nbsp;</td>
						<td colspan="4">
							{{if $order_users != $th_users.2.1}}
								<p><a href="{{$baseurl}}/admin/users/?o={{if $order_direction_users == "+"}}-{{/if}}{{$th_users.2.1}}">
									&#8597; {{$th_users.2.0}}</a> : {{$u.register_date}}</p>
							{{/if}}

							{{if $order_users != $th_users.3.1}}
								<p><a href="{{$baseurl}}/admin/users/?o={{if $order_direction_users == "+"}}-{{/if}}{{$th_users.3.1}}">
										&#8597; {{$th_users.3.0}}</a> : {{$u.login_date}}</p>
							{{/if}}

							{{if $order_users != $th_users.4.1}}
								<p><a href="{{$baseurl}}/admin/users/?o={{if $order_direction_users == "+"}}-{{/if}}{{$th_users.4.1}}">
										&#8597; {{$th_users.4.0}}</a> : {{$u.lastitem_date}}</p>
							{{/if}}

							{{if in_array($order_users,[$th_users.2.1, $th_users.3.1, $th_users.4.1]) }}
								<p><a href="{{$baseurl}}/admin/users/?o={{if $order_direction_users == "+"}}-{{/if}}{{$th_users.5.1}}">
										&#8597; {{$th_users.5.0}}</a> : {{$u.page_flags}} {{if $u.is_admin}}({{$siteadmin}}){{/if}} {{if $u.account_expired}}({{$accountexpired}}){{/if}}</p>
							{{/if}}

						</td>
						<td class="text-right">
							{{if $u.is_deletable}}
								<a href="{{$baseurl}}/admin/users/block/{{$u.uid}}?t={{$form_security_token}}" title='{{if $u.blocked}}{{$unblock}}{{else}}{{$block}}{{/if}}'>
									{{if $u.blocked==0}}
									<i class="fa fa-ban" aria-hidden="true"></i>
									{{else}}
									<i class="fa fa-circle-o" aria-hidden="true"></i>
									{{/if}}
								</a>
								<a href="{{$baseurl}}/admin/users/delete/{{$u.uid}}?t={{$form_security_token}}" title='{{$delete}}' onclick="return confirm_delete('{{$u.name}}')"><i class="fa fa-trash" aria-hidden="true"></i></a>
							{{else}}
								&nbsp;
							{{/if}}
						</td>
					</tr>
				{{/foreach}}
				</tbody>
			</table>
			<div class="panel-footer text-right">
						<button type="submit" name="page_users_block" class="btn btn-warning">	<i class="fa fa-ban" aria-hidden="true"></i> {{$block}} / <i class="fa fa-circle-o" aria-hidden="true"></i> {{$unblock}}</button>
						<button type="submit" name="page_users_delete" class="btn btn-danger" onclick="return confirm_delete_multi()"><i class="fa fa-trash" aria-hidden="true"></i> {{$delete}}</button>
			</div>
		{{else}}
			<div class="panel-body text-center bg-danger">NO USERS?!?</div>
		{{/if}}
		</div>



	</form>





<!--
	**
	*
	*		DELETED Users table
	*
	**
-->
	{{if $deleted}}
	<div class="panel panel-default">
		<div class="panel-heading"><h3 class="panel-title">{{$h_deleted}}</h3></div>
		<table id='deleted' class="table table-hover">
			<thead>
			<tr>
				<th></th>
				{{foreach $th_deleted as $k=>$th}}
					{{if in_array($k,[0,1,5])}}
					<th>{{$th}}</th>
					{{/if}}
				{{/foreach}}
			</tr>
			</thead>
			<tbody>
			{{foreach $deleted as $u}}
				<tr>
					<td><img src="{{$u.micro}}" title="{{$u.nickname}}"></td>
					<td><a href="{{$u.url}}" title="{{$u.nickname}}" >{{$u.name}}</a></td>
					<td>{{$u.email}}</td>
					<td>{{$u.deleted}}</td>
				</tr>
			{{/foreach}}
			</tbody>
		</table>
	</div>
{{/if}}



<!--
	**
	*
	*		NEW USER Form
	*
	**
-->
<form action="{{$baseurl}}/admin/users" method="post">
	<input type='hidden' name='form_security_token' value='{{$form_security_token}}'>

	<div class="panel panel-default">
		<div class="panel-heading"><h3 class="panel-title">{{$h_newuser}}</h3></div>
		<div class="panel-body">
			{{include file="field_input.tpl" field=$newusername}}
			{{include file="field_input.tpl" field=$newusernickname}}
			{{include file="field_input.tpl" field=$newuseremail}}
		</div>
		<div class="panel-footer text-right">
		  <button type="submit" class="btn btn-primary">{{$submit}}</button>
	  </form>
	</div>
</form>
