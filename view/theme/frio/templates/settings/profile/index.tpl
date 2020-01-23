
<div class="generic-page-wrapper">
	{{include file="section_title.tpl" title=$banner}}

	{{* The actions dropdown which can performed to the current profile *}}
	<div id="profile-edit-links">
		<ul class="nav nav-pills preferences">
			<li class="dropdown pull-right">
				<button type="button" class="btn btn-link btn-sm dropdown-toggle" id="profile-edit-links-dropdown" data-toggle="dropdown" aria-expanded="false">
					<i class="fa fa-angle-down" aria-hidden="true"></i>&nbsp;{{$profile_action}}
				</button>
				<ul class="dropdown-menu pull-right" role="menu" aria-labelledby="profile-edit-links-dropdown">
					<li role="presentation"><a role="menuitem" href="{{$profpiclink}}" id="profile-photo_upload-link" title="{{$profpic}}"><i class="fa fa-user" aria-hidden="true"></i>&nbsp;{{$profpic}}</a></li>
					<li role="presentation"><button role="menuitem" type="button" class="btn-link" id="profile-photo_upload-link-new" title="{{$lbl_profile_photo}}" onclick="openClose('profile-photo-upload-section');"><i class="fa fa-user" aria-hidden="true"></i>&nbsp;{{$lbl_profile_photo}}</button></li>
					<li role="presentation" class="divider"></li>
					<li role="presentation"><a role="menuitem" href="profile/{{$nickname}}" id="profile-edit-view-link" title="{{$viewprof}}">{{$viewprof}}</a></li>
				</ul>
			</li>
		</ul>
	</div>

	<div id="profile-edit-links-end"></div>

	<form enctype="multipart/form-data" action="settings/profile/photo" method="post">
		<input type="hidden" name="form_security_token" value="{{$form_security_token_photo}}">

		<div id="profile-photo-upload-section" class="panel">
			<a id="profile-photo-upload-close" class="close pull-right" onclick="openClose('profile-photo-upload-section');"><i class="fa fa-times" aria-hidden="true"></i></a>
			<div id="profile-photo-upload-wrapper">
				<label id="profile-photo-upload-label" for="profile-photo-upload">{{$lbl_profile_photo}}:</label>
				<input name="userfile" type="file" id="profile-photo-upload" size="48" />
			</div>

			<div class="profile-edit-submit-wrapper pull-right">
				<button type="submit" name="submit" class="profile-edit-submit-button btn btn-primary" value="{{$submit}}">{{$submit}}</button>
			</div>
			<div class="clear"></div>
		</div>
	</form>

	{{* Most of the Variables used below are arrays in the following style
		0 => Some kind of identifier (e.g. for the ID)
		1 => The label description
		2 => The input values
		3 => The additional help text (if available)
	*}}

	<form id="profile-edit-form" name="form1" action="" method="post">
		<input type="hidden" name="form_security_token" value="{{$form_security_token}}">

		{{* Some hints to characteristics of the current profile (if available) *}}
		<div class="section-content-info-wrapper">{{$default nofilter}}</div>

    {{if $personal_account}}
	    <p>{{include file="field_yesno.tpl" field=$details}}</p>
    {{/if}}

		{{* friendica differs in $detailled_profile (all fields available and a short Version if this is variable false *}}
	{{if $detailed_profile}}
		<div class="panel-group panel-group-settings" id="profile-edit-wrapper" role="tablist" aria-multiselectable="true">
			{{* The personal settings *}}
			<div class="panel">
				<div class="section-subtitle-wrapper" role="tab" id="personal">
					<h4>
						<a class="accordion-toggle" data-toggle="collapse" data-parent="#profile-edit-wrapper" href="#personal-collapse" aria-expanded="true" aria-controls="personal-collapse">
							{{$lbl_personal_section}}
						</a>
					</h4>
				</div>
				{{* for the $detailed_profile we use bootstraps collapsable panel-groups to have expandable groups *}}
				<div id="personal-collapse" class="panel-collapse collapse in" role="tabpanel" aria-labelledby="personal">
					<div class="section-content-tools-wrapper">
						{{include file="field_input.tpl" field=$name}}

						{{include file="field_input.tpl" field=$pdesc}}

						<div id="profile-edit-gender-wrapper" class="form-group field select">
							<label id="profile-edit-gender-label" for="gender-select">{{$lbl_gender}} </label>
							{{$gender nofilter}}
						</div>
						<div class="clear"></div>

						{{$dob nofilter}}

						{{$hide_friends nofilter}}

						<div class="form-group pull-right">
							<button type="submit" name="submit" class="btn btn-primary" value="{{$submit}}">{{$submit}}</button>
						</div>
						<div class="clear"></div>
					</div>
				</div>
			</div>

			{{* The location settings *}}
			<div class="panel">
				<div class="section-subtitle-wrapper" role="tab" id="location">
					<h4>
						<a class="accordion-toggle collapsed" data-toggle="collapse" data-parent="#profile-edit-wrapper" href="#location-collapse" aria-expanded="false" aria-controls="location-collapse">
							{{$lbl_location_section}}
						</a>
					</h4>
				</div>
				<div id="location-collapse" class="panel-collapse collapse" role="tabpanel" aria-labelledby="location">
					<div class="section-content-tools-wrapper">
						{{include file="field_input.tpl" field=$address}}

						{{include file="field_input.tpl" field=$locality}}


						{{include file="field_input.tpl" field=$postal_code}}

						<div id="profile-edit-country-name-wrapper" class="form-group field select">
							<label id="profile-edit-country-name-label" for="profile-edit-country-name">{{$country_name.1}} </label>
							<select name="country_name" id="profile-edit-country-name" class="form-control" onChange="Fill_States('{{$region.2}}');">
								<option selected="selected">{{$country_name.2}}</option>
								<option>temp</option>
							</select>
						</div>
						<div class="clear"></div>

						<div id="profile-edit-region-wrapper" class="form-group field select">
							<label id="profile-edit-region-label" for="profile-edit-region">{{$region.1}} </label>
							<select name="region" id="profile-edit-region" class="form-control" onChange="Update_Globals();">
								<option selected="selected">{{$region.2}}</option>
								<option>temp</option>
							</select>
						</div>
						<div class="clear"></div>

						{{include file="field_input.tpl" field=$hometown}}

						<div class="form-group pull-right">
							<button type="submit" name="submit" class="btn btn-primary" value="{{$submit}}">{{$submit}}</button>
						</div>
						<div class="clear"></div>
					</div>
				</div>
			</div>

			{{* The settings for relations *}}
			<div class="panel">
				<div class="section-subtitle-wrapper" role="tab" id="relation">
					<h4>
						<a class="accordion-toggle collapsed" data-toggle="collapse" data-parent="#profile-edit-wrapper" href="#relation-collapse" aria-expanded="false" aria-controls="relation-collapse">
							{{$lbl_relation_section}}
						</a>
					</h4>
				</div>
				<div id="relation-collapse" class="panel-collapse collapse" role="tabpanel" aria-labelledby="relation">
					<div class="section-content-tools-wrapper">
						<div id="profile-edit-marital-wrapper" class="form-group field select">
								<label id="profile-edit-marital-label" for="profile-edit-marital">{{$lbl_marital nofilter}}</label>
								{{$marital.selector nofilter}}
						</div>
						<div class="clear"></div>

						{{include file="field_input.tpl" field=$with}}

						{{include file="field_input.tpl" field=$howlong}}

						<div id="profile-edit-sexual-wrapper" class="form-group field select">
							<label id="profile-edit-sexual-label" for="sexual-select">{{$lbl_sexual}}</label>
							{{$sexual.selector nofilter}}
						</div>
						<div class="clear"></div>

						<div class="form-group pull-right">
							<button type="submit" name="submit" class="btn btn-primary" value="{{$submit}}">{{$submit}}</button>
						</div>
						<div class="clear"></div>
					</div>
				</div>
			</div>

			{{* The miscellanous other settings *}}
			<div class="panel">
				<div class="section-subtitle-wrapper" role="tab" id="miscellaneous">
					<h4>
						<a class="accordion-toggle collapsed" data-toggle="collapse" data-parent="#profile-edit-wrapper" href="#miscellaneous-collapse" aria-expanded="false" aria-controls="miscellaneous-collapse">
							{{$lbl_miscellaneous_section}}
						</a>
					</h4>
				</div>
				<div id="miscellaneous-collapse" class="panel-collapse collapse" role="tabpanel" aria-labelledby="miscellaneous">
					<div class="section-content-tools-wrapper">
						{{include file="field_input.tpl" field=$homepage}}

						{{include file="field_input.tpl" field=$xmpp}}

						{{include file="field_input.tpl" field=$pub_keywords}}

						{{include file="field_input.tpl" field=$prv_keywords}}

						{{include file="field_input.tpl" field=$politic}}

						{{include file="field_input.tpl" field=$religion}}


						{{include file="field_textarea.tpl" field=$about}}

						{{include file="field_textarea.tpl" field=$contact}}

						{{include file="field_textarea.tpl" field=$interest}}

						{{include file="field_textarea.tpl" field=$likes}}

						{{include file="field_textarea.tpl" field=$dislikes}}

						{{include file="field_textarea.tpl" field=$music}}

						{{include file="field_textarea.tpl" field=$book}}

						{{include file="field_textarea.tpl" field=$tv}}

						{{include file="field_textarea.tpl" field=$film}}

						{{include file="field_textarea.tpl" field=$romance}}

						{{include file="field_textarea.tpl" field=$work}}

						{{include file="field_textarea.tpl" field=$education}}

						<div class="form-group pull-right">
							<button type="submit" name="submit" class="btn btn-primary" value="{{$submit}}">{{$submit}}</button>
						</div>
						<div class="clear"></div>
					</div>
				</div>
			</div>
		</div>

	{{else}}
		{{* if $detailed_profile not available a short version of the setting page is displayed *}}
		{{include file="field_input.tpl" field=$name}}

		{{if $personal_account}}
		<div id="profile-edit-gender-wrapper" class="form-group field select">
			<label id="profile-edit-gender-label" for="gender-select">{{$lbl_gender}} </label>
			{{$gender nofilter}}
		</div>
		<div class="clear"></div>

		{{$dob nofilter}}

		{{/if}}

		{{include file="field_input.tpl" field=$homepage}}

		{{include file="field_input.tpl" field=$xmpp}}

		{{$hide_friends nofilter}}

		{{include file="field_input.tpl" field=$address}}

		{{include file="field_input.tpl" field=$locality}}

		{{include file="field_input.tpl" field=$postal_code}}

		<div id="profile-edit-country-name-wrapper" class="form-group field select">
			<label id="profile-edit-country-name-label" for="profile-edit-country-name">{{$country_name.1}} </label>
			<select name="country_name" id="profile-edit-country-name" class="form-control" onChange="Fill_States('{{$region.2}}');">
				<option selected="selected">{{$country_name.2}}</option>
				<option>temp</option>
			</select>
		</div>
		<div class="clear"></div>

		<div id="profile-edit-region-wrapper" class="form-group field select">
			<label id="profile-edit-region-label" for="profile-edit-region">{{$region.1}} </label>
			<select name="region" id="profile-edit-region" class="form-control" onChange="Update_Globals();">
				<option selected="selected">{{$region.2}}</option>
				<option>temp</option>
			</select>
		</div>
		<div class="clear"></div>

		{{include file="field_input.tpl" field=$pub_keywords}}

		{{include file="field_input.tpl" field=$prv_keywords}}

		{{include file="field_textarea.tpl" field=$about}}

		<div class="form-group pull-right">
			<button type="submit" name="submit" class="btn btn-primary" value="{{$submit}}">{{$submit}}</button>
		</div>
		<div class="clear"></div>

		<input type="hidden" name="pdesc" id="profile-edit-pdesc" value="{{$pdesc.2}}" />
		<input type="hidden" id="contact-jot-text" name="contact" value="{{$contact.2}}" />
		<input type="hidden" name="hometown" id="profile-edit-hometown" value="{{$hometown.2}}" />
		<input type="hidden" name="politic" id="profile-edit-politic" value="{{$politic.2}}" />
		<input type="hidden" name="religion" id="profile-edit-religion" value="{{$religion.2}}" />
		<input type="hidden" id="likes-jot-text" name="likes" value="{{$likes.2}}" />
		<input type="hidden" id="dislikes-jot-text" name="dislikes" value="{{$dislikes.2}}" />
		<input type="hidden" name="marital" id="profile-edit-marital" value="{{$marital.value}}" />
		<input type="hidden" name="with" id="profile-edit-with" value="{{$with.2}}" />
		<input type="hidden" name="howlong" id="profile-edit-howlong" value="{{$howlong.2}}" />
		<input type="hidden" name="sexual" id="profile-edit-sexual" value="{{$sexual.value}}" />
		<input type="hidden" id="romance-jot-text" name="romance" value="{{$romance.2}}" />
		<input type="hidden" id="work-jot-text" name="work" value="{{$work.2}}" />
		<input type="hidden" id="education-jot-text" name="education" value="{{$education.2}}" />
		<input type="hidden" id="interest-jot-text" name="interest" value="{{$interest.2}}" />
		<input type="hidden" id="music-jot-text" name="music" value="{{$music.2}}" />
		<input type="hidden" id="book-jot-text" name="book" value="{{$book.2}}" />
		<input type="hidden" id="tv-jot-text" name="tv" value="{{$tv.2}}" />
		<input type="hidden" id="film-jot-text" name="film" value="{{$film.2}}" />

		{{/if}}
	</form>
</div>

<script type="text/javascript">
	Fill_Country('{{$country_name.2}}');
	Fill_States('{{$region.2}}');

	// initiale autosize for the textareas
	autosize($("textarea.text-autosize"));
</script>
