
{{include file="field_select.tpl" field=$color}}

{{include file="field_select.tpl" field=$font_size}}

{{include file="field_select.tpl" field=$line_height}}

{{include file="field_select.tpl" field=$resolution}}

<div class="settings-submit-wrapper">
	<input type="submit" value="{{$submit}}" class="settings-submit" name="diabook-settings-submit" />
</div>
<br>
<h3>Show/hide boxes at right-hand column</h3>
{{include file="field_select.tpl" field=$close_pages}}
{{include file="field_select.tpl" field=$close_profiles}}
{{include file="field_select.tpl" field=$close_helpers}}
{{include file="field_select.tpl" field=$close_services}}
{{include file="field_select.tpl" field=$close_friends}}
{{include file="field_select.tpl" field=$close_lastusers}}
{{include file="field_select.tpl" field=$close_lastphotos}}
{{include file="field_select.tpl" field=$close_lastlikes}}
{{include file="field_select.tpl" field=$close_mapquery}}

{{include file="field_input.tpl" field=$ELPosX}}

{{include file="field_input.tpl" field=$ELPosY}}

{{include file="field_input.tpl" field=$ELZoom}}

<div class="settings-submit-wrapper">
	<input type="submit" value="{{$submit}}" class="settings-submit" name="diabook-settings-submit" />
</div>

<br>

<div class="field select">
<a onClick="restore_boxes()" title="Restore boxorder at right-hand column" style="cursor: pointer;">Restore boxorder at right-hand column</a>
</div>

