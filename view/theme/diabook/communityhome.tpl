<div id="pos_null" style="margin-bottom:-30px;">
</div>

<div id="sortable_boxes">

<div id="close_pages" style="margin-top:30px;">
{{ if $page }}
<div>$page</div>
{{ endif }}

<div id="close_profiles">
{{ if $comunity_profilest_title }}
<h3>$comunity_profilest_title<a id="close_comunity_profiles_icon" onClick="close_profiles()" class="icon close_box" title="$close"></a></h3>
<div id='lastusers-wrapper' class='items-wrapper'>
{{ for $comunity_profiles_items as $i }}
	$i
{{ endfor }}
</div>
{{ endif }}
</div>

<div id="close_helpers">
{{ if $helpers }}
<h3>$helpers.title.1<a id="close_helpers_icon"  onClick="close_helpers()" class="icon close_box" title="$close"></a></h3>
<a href="http://kakste.com/profile/newhere" title="@NewHere" style="margin-left: 10px; " target="blank">NewHere</a><br>
<a href="https://helpers.pyxis.uberspace.de/profile/helpers" style="margin-left: 10px; " title="Friendica Support" target="blank">Friendica Support</a><br>
<a href="https://letstalk.pyxis.uberspace.de/profile/letstalk" style="margin-left: 10px; " title="Let's talk" target="blank">Let's talk</a><br>
<a href="http://newzot.hydra.uberspace.de/profile/newzot" title="Local Friendica" style="margin-left: 10px; " target="blank">Local Friendica</a>
{{ endif }}
</div>

<div id="close_services">
{{ if $con_services }}
<h3>$con_services.title.1<a id="close_services_icon" onClick="close_services()" class="icon close_box" title="$close"></a></h3>
<div id="right_service_icons" style="margin-left: 16px; margin-top: 5px;">
<a href="$url/facebook"><img alt="Facebook" src="view/theme/diabook/icons/facebook.png" title="Facebook"></a>
<a href="$url/settings/connectors"><img alt="StatusNet" src="view/theme/diabook/icons/StatusNet.png?" title="StatusNet"></a>
<a href="$url/settings/connectors"><img alt="LiveJournal" src="view/theme/diabook/icons/livejournal.png?" title="LiveJournal"></a>
<a href="$url/settings/connectors"><img alt="Posterous" src="view/theme/diabook/icons/posterous.png?" title="Posterous"></a>
<a href="$url/settings/connectors"><img alt="Tumblr" src="view/theme/diabook/icons/tumblr.png?" title="Tumblr"></a>
<a href="$url/settings/connectors"><img alt="Twitter" src="view/theme/diabook/icons/twitter.png?" title="Twitter"></a>
<a href="$url/settings/connectors"><img alt="WordPress" src="view/theme/diabook/icons/wordpress.png?" title="WordPress"></a>
<a href="$url/settings/connectors"><img alt="E-Mail" src="view/theme/diabook/icons/email.png?" title="E-Mail"></a>
</div>
{{ endif }}
</div>

<div id="close_friends" style="margin-bottom:53px;">
{{ if $nv }}
<h3>$nv.title.1<a id="close_friends_icon" onClick="close_friends()"  class="icon close_box" title="$close"></a></h3>
<a class="$nv.directory.2" href="$nv.directory.0" style="margin-left: 10px; " title="$nv.directory.3" >$nv.directory.1</a><br>
<a class="$nv.global_directory.2" href="$nv.global_directory.0" target="blank" style="margin-left: 10px; " title="$nv.global_directory.3" >$nv.global_directory.1</a><br>
<a class="$nv.match.2" href="$nv.match.0" style="margin-left: 10px; " title="$nv.match.3" >$nv.match.1</a><br>
<a class="$nv.suggest.2" href="$nv.suggest.0" style="margin-left: 10px; " title="$nv.suggest.3" >$nv.suggest.1</a><br>
<a class="$nv.invite.2" href="$nv.invite.0" style="margin-left: 10px; " title="$nv.invite.3" >$nv.invite.1</a>
$nv.search
{{ endif }}
</div>

<div id="close_lastusers">
{{ if $lastusers_title }}
<h3>$lastusers_title<a id="close_lastusers_icon" onClick="close_lastusers()" class="icon close_box" title="$close"></a></h3>
<div id='lastusers-wrapper' class='items-wrapper'>
{{ for $lastusers_items as $i }}
	$i
{{ endfor }}
</div>
{{ endif }}
</div>

{{ if $activeusers_title }}
<h3>$activeusers_title</h3>
<div class='items-wrapper'>
{{ for $activeusers_items as $i }}
	$i
{{ endfor }}
</div>
{{ endif }}

<div id="close_lastphotos">
{{ if $photos_title }}
<h3>$photos_title<a id="close_photos_icon" onClick="close_lastphotos()"  class="icon close_box" title="$close"></a></h3>
<div id='ra-photos-wrapper' class='items-wrapper'>
{{ for $photos_items as $i }}
	$i
{{ endfor }}
</div>
{{ endif }}
</div>

<div id="close_lastlikes">
{{ if $like_title }}
<h3>$like_title<a id="close_lastlikes_icon" onClick="close_lastlikes()" class="icon close_box" title="$close"></a></h3>
<ul id='likes'>
{{ for $like_items as $i }}
	<li id='ra-photos-wrapper'>$i</li>
{{ endfor }}
</ul>
{{ endif }}
</div>

<div id="twitter">
</div>

<div id="close_mapquery">
{{ if $mapquery }}
<h3>$mapquery.title.1<a id="close_mapquery_icon"  onClick="close_mapquery()" class="icon close_box" title="$close"></a></h3>
<div id="map" style="height:170px;width:170px;margin-top:3px;margin-bottom:1px;">
</div>
<div style="font-size:9px;">Data CC-By-SA by <a href="http://openstreetmap.org/">OpenStreetMap</a></div>
<div id="#mapcontrol" style="display:none;" class="lightbox">
hallo welt
<div id="map2" style="height:370px;width:370px;margin-top:3px;margin-bottom:1px;">
</div>
</div>
{{ endif }}
</div>
</div>