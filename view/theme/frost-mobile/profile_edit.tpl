$default

<h1>$banner</h1>

<div id="profile-edit-links">
<ul>
<li><a href="profile/$profile_id/view?tab=profile" id="profile-edit-view-link" title="$viewprof">$viewprof</a></li>
<li><a href="$profile_clone_link" id="profile-edit-clone-link" title="$cr_prof">$cl_prof</a></li>
<li></li>
<li><a href="$profile_drop_link" id="profile-edit-drop-link" title="$del_prof" $disabled >$del_prof</a></li>

</ul>
</div>

<div id="profile-edit-links-end"></div>


<div id="profile-edit-wrapper" >
<form id="profile-edit-form" name="form1" action="profiles/$profile_id" method="post" >
<input type='hidden' name='form_security_token' value='$form_security_token'>

<div id="profile-edit-profile-name-wrapper" >
<label id="profile-edit-profile-name-label" for="profile-edit-profile-name" >$lbl_profname </label>
<input type="text" size="28" name="profile_name" id="profile-edit-profile-name" value="$profile_name" /><div class="required">*</div>
</div>
<div id="profile-edit-profile-name-end"></div>

<div id="profile-edit-name-wrapper" >
<label id="profile-edit-name-label" for="profile-edit-name" >$lbl_fullname </label>
<input type="text" size="28" name="name" id="profile-edit-name" value="$name" />
</div>
<div id="profile-edit-name-end"></div>

<div id="profile-edit-pdesc-wrapper" >
<label id="profile-edit-pdesc-label" for="profile-edit-pdesc" >$lbl_title </label>
<input type="text" size="28" name="pdesc" id="profile-edit-pdesc" value="$pdesc" />
</div>
<div id="profile-edit-pdesc-end"></div>


<div id="profile-edit-gender-wrapper" >
<label id="profile-edit-gender-label" for="gender-select" >$lbl_gender </label>
$gender
</div>
<div id="profile-edit-gender-end"></div>

<div id="profile-edit-dob-wrapper" >
<label id="profile-edit-dob-label" for="dob-select" >$lbl_bd </label>
<div id="profile-edit-dob" >
$dob $age
</div>
</div>
<div id="profile-edit-dob-end"></div>

$hide_friends

<div class="profile-edit-submit-wrapper" >
<input type="submit" name="submit" class="profile-edit-submit-button" value="$submit" />
</div>
<div class="profile-edit-submit-end"></div>


<div id="profile-edit-address-wrapper" >
<label id="profile-edit-address-label" for="profile-edit-address" >$lbl_address </label>
<input type="text" size="28" name="address" id="profile-edit-address" value="$address" />
</div>
<div id="profile-edit-address-end"></div>

<div id="profile-edit-locality-wrapper" >
<label id="profile-edit-locality-label" for="profile-edit-locality" >$lbl_city </label>
<input type="text" size="28" name="locality" id="profile-edit-locality" value="$locality" />
</div>
<div id="profile-edit-locality-end"></div>


<div id="profile-edit-postal-code-wrapper" >
<label id="profile-edit-postal-code-label" for="profile-edit-postal-code" >$lbl_zip </label>
<input type="text" size="28" name="postal_code" id="profile-edit-postal-code" value="$postal_code" />
</div>
<div id="profile-edit-postal-code-end"></div>

<div id="profile-edit-country-name-wrapper" >
<label id="profile-edit-country-name-label" for="profile-edit-country-name" >$lbl_country </label>
<select name="country_name" id="profile-edit-country-name" onChange="Fill_States('$region');">
<option selected="selected" >$country_name</option>
<option>temp</option>
</select>
</div>
<div id="profile-edit-country-name-end"></div>

<div id="profile-edit-region-wrapper" >
<label id="profile-edit-region-label" for="profile-edit-region" >$lbl_region </label>
<select name="region" id="profile-edit-region" onChange="Update_Globals();" >
<option selected="selected" >$region</option>
<option>temp</option>
</select>
</div>
<div id="profile-edit-region-end"></div>

<div id="profile-edit-hometown-wrapper" >
<label id="profile-edit-hometown-label" for="profile-edit-hometown" >$lbl_hometown </label>
<input type="text" size="28" name="hometown" id="profile-edit-hometown" value="$hometown" />
</div>
<div id="profile-edit-hometown-end"></div>

<div class="profile-edit-submit-wrapper" >
<input type="submit" name="submit" class="profile-edit-submit-button" value="$submit" />
</div>
<div class="profile-edit-submit-end"></div>

<div id="profile-edit-marital-wrapper" >
<label id="profile-edit-marital-label" for="profile-edit-marital" >$lbl_marital </label>
$marital
</div>
<label id="profile-edit-with-label" for="profile-edit-with" > $lbl_with </label>
<input type="text" size="28" name="with" id="profile-edit-with" title="$lbl_ex1" value="$with" />
<label id="profile-edit-howlong-label" for="profile-edit-howlong" > $lbl_howlong </label>
<input type="text" size="28" name="howlong" id="profile-edit-howlong" title="$lbl_howlong" value="$howlong" />

<div id="profile-edit-marital-end"></div>

<div id="profile-edit-sexual-wrapper" >
<label id="profile-edit-sexual-label" for="sexual-select" >$lbl_sexual </label>
$sexual
</div>
<div id="profile-edit-sexual-end"></div>



<div id="profile-edit-homepage-wrapper" >
<label id="profile-edit-homepage-label" for="profile-edit-homepage" >$lbl_homepage </label>
<input type="text" size="28" name="homepage" id="profile-edit-homepage" value="$homepage" />
</div>
<div id="profile-edit-homepage-end"></div>

<div id="profile-edit-politic-wrapper" >
<label id="profile-edit-politic-label" for="profile-edit-politic" >$lbl_politic </label>
<input type="text" size="28" name="politic" id="profile-edit-politic" value="$politic" />
</div>
<div id="profile-edit-politic-end"></div>

<div id="profile-edit-religion-wrapper" >
<label id="profile-edit-religion-label" for="profile-edit-religion" >$lbl_religion </label>
<input type="text" size="28" name="religion" id="profile-edit-religion" value="$religion" />
</div>
<div id="profile-edit-religion-end"></div>

<div id="profile-edit-pubkeywords-wrapper" >
<label id="profile-edit-pubkeywords-label" for="profile-edit-pubkeywords" >$lbl_pubkey </label>
<input type="text" size="28" name="pub_keywords" id="profile-edit-pubkeywords" title="$lbl_ex2" value="$pub_keywords" />
</div><div id="profile-edit-pubkeywords-desc">$lbl_pubdsc</div>
<div id="profile-edit-pubkeywords-end"></div>

<div id="profile-edit-prvkeywords-wrapper" >
<label id="profile-edit-prvkeywords-label" for="profile-edit-prvkeywords" >$lbl_prvkey </label>
<input type="text" size="28" name="prv_keywords" id="profile-edit-prvkeywords" title="$lbl_ex2" value="$prv_keywords" />
</div><div id="profile-edit-prvkeywords-desc">$lbl_prvdsc</div>
<div id="profile-edit-prvkeywords-end"></div>


<div class="profile-edit-submit-wrapper" >
<input type="submit" name="submit" class="profile-edit-submit-button" value="$submit" />
</div>
<div class="profile-edit-submit-end"></div>

<div id="about-jot-wrapper" class="profile-jot-box">
<p id="about-jot-desc" >
$lbl_about
</p>

<textarea rows="10" cols="30" id="profile-about-text" class="profile-edit-textarea" name="about" >$about</textarea>

</div>
<div id="about-jot-end"></div>


<div id="interest-jot-wrapper" class="profile-jot-box" >
<p id="interest-jot-desc" >
$lbl_hobbies
</p>

<textarea rows="10" cols="30" id="interest-jot-text" class="profile-edit-textarea" name="interest" >$interest</textarea>

</div>
<div id="interest-jot-end"></div>


<div id="likes-jot-wrapper" class="profile-jot-box" >
<p id="likes-jot-desc" >
$lbl_likes
</p>

<textarea rows="10" cols="30" id="likes-jot-text" class="profile-edit-textarea" name="likes" >$likes</textarea>

</div>
<div id="likes-jot-end"></div>


<div id="dislikes-jot-wrapper" class="profile-jot-box" >
<p id="dislikes-jot-desc" >
$lbl_dislikes
</p>

<textarea rows="10" cols="30" id="dislikes-jot-text" class="profile-edit-textarea" name="dislikes" >$dislikes</textarea>

</div>
<div id="dislikes-jot-end"></div>


<div id="contact-jot-wrapper" class="profile-jot-box" >
<p id="contact-jot-desc" >
$lbl_social
</p>

<textarea rows="10" cols="30" id="contact-jot-text" class="profile-edit-textarea" name="contact" >$contact</textarea>

</div>
<div id="contact-jot-end"></div>


<div class="profile-edit-submit-wrapper" >
<input type="submit" name="submit" class="profile-edit-submit-button" value="$submit" />
</div>
<div class="profile-edit-submit-end"></div>


<div id="music-jot-wrapper" class="profile-jot-box" >
<p id="music-jot-desc" >
$lbl_music
</p>

<textarea rows="10" cols="30" id="music-jot-text" class="profile-edit-textarea" name="music" >$music</textarea>

</div>
<div id="music-jot-end"></div>

<div id="book-jot-wrapper" class="profile-jot-box" >
<p id="book-jot-desc" >
$lbl_book
</p>

<textarea rows="10" cols="30" id="book-jot-text" class="profile-edit-textarea" name="book" >$book</textarea>

</div>
<div id="book-jot-end"></div>



<div id="tv-jot-wrapper" class="profile-jot-box" >
<p id="tv-jot-desc" >
$lbl_tv 
</p>

<textarea rows="10" cols="30" id="tv-jot-text" class="profile-edit-textarea" name="tv" >$tv</textarea>

</div>
<div id="tv-jot-end"></div>



<div id="film-jot-wrapper" class="profile-jot-box" >
<p id="film-jot-desc" >
$lbl_film
</p>

<textarea rows="10" cols="30" id="film-jot-text" class="profile-edit-textarea" name="film" >$film</textarea>

</div>
<div id="film-jot-end"></div>


<div class="profile-edit-submit-wrapper" >
<input type="submit" name="submit" class="profile-edit-submit-button" value="$submit" />
</div>
<div class="profile-edit-submit-end"></div>


<div id="romance-jot-wrapper" class="profile-jot-box" >
<p id="romance-jot-desc" >
$lbl_love
</p>

<textarea rows="10" cols="30" id="romance-jot-text" class="profile-edit-textarea" name="romance" >$romance</textarea>

</div>
<div id="romance-jot-end"></div>



<div id="work-jot-wrapper" class="profile-jot-box" >
<p id="work-jot-desc" >
$lbl_work
</p>

<textarea rows="10" cols="30" id="work-jot-text" class="profile-edit-textarea" name="work" >$work</textarea>

</div>
<div id="work-jot-end"></div>



<div id="education-jot-wrapper" class="profile-jot-box" >
<p id="education-jot-desc" >
$lbl_school 
</p>

<textarea rows="10" cols="30" id="education-jot-text" class="profile-edit-textarea" name="education" >$education</textarea>

</div>
<div id="education-jot-end"></div>



<div class="profile-edit-submit-wrapper" >
<input type="submit" name="submit" class="profile-edit-submit-button" value="$submit" />
</div>
<div class="profile-edit-submit-end"></div>


</form>
</div>
<script type="text/javascript">Fill_Country('$country_name');Fill_States('$region');</script>
