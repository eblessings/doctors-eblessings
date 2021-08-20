<div id="adminpage">
	<h1>{{$title}} - {{$page}}</h1>

	<h2>{{$logname}}</h2>
	{{if $error }}
		<div id="admin-error-message-wrapper" class="alert alert-warning">
			<p>{{$error nofilter}}</p>
		</div>
	{{else}}
		<form>
			<p>
				<input type="search" name="q" value="{{$q}}" placeholder="search"></input>
				<input type="Submit" value="search">
				<a href="/admin/logs/view">clear</a>
			</p>


			<table>
				<thead>
					<tr>
						<th>Date</th>
						<th>
							<select name="level" onchange="this.form.submit()">
								{{foreach $filtersvalues.level as $v }}
								<option {{if $filters.level == $v}}selected{{/if}} value="{{$v}}">
									{{if $v == ""}}Level{{/if}}
									{{$v}}
								</option>
								{{/foreach}}
							</select>
						</th>
						<th>
							<select name="context" onchange="this.form.submit()">
								{{foreach $filtersvalues.context as $v }}
								<option {{if $filters.context == $v}}selected{{/if}} value="{{$v}}">
									{{if $v == ""}}Context{{/if}}
									{{$v}}
								</option>
								{{/foreach}}
							</select>
						</th>
						<th>Message</th>
					</tr>
				</thead>
				<tbody>
					{{foreach $data as $row}}
						<tr id="ev-{{$row->id}}" class="log-event"
						 role="button" tabIndex="0"
						 aria-label="View details" aria-haspopup="true" aria-expanded="false"
						 style="cursor:pointer;"
						 title="Click to view details">
							<td>{{$row->date}}</td>
							<td>{{$row->level}}</td>
							<td>{{$row->context}}</td>
							<td>{{$row->message}}</td>
						</tr>
						<tr class="hidden" data-id="ev-{{$row->id}}"><th colspan="4">Data</th></tr>
						{{foreach $row->getData() as $k=>$v}}
							<tr class="hidden" data-id="ev-{{$row->id}}">
								<th>{{$k}}</th>
								<td colspan="3">
									<pre>{{$v nofilter}}</pre>
								</td>
							</tr>
						{{/foreach}}
						<tr class="hidden" data-id="ev-{{$row->id}}"><th colspan="4">Source</th></tr>
						{{foreach $row->getSource() as $k=>$v}}
							<tr class="hidden" data-id="ev-{{$row->id}}">
								<th>{{$k}}</th>
								<td colspan="3">{{$v}}</td>
							</tr>
						{{/foreach}}
					{{/foreach}}
				</tbody>
			</table>
		</form>
	{{/if}}
</div>
