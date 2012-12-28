function ACL(backend_url, preset){
	that = this;
	
	that.url = backend_url;
	
	that.kp_timer = null;
	
	if (preset==undefined) preset = [];
	that.allow_cid = (preset[0] || []);
	that.allow_gid = (preset[1] || []);
	that.deny_cid  = (preset[2] || []);
	that.deny_gid  = (preset[3] || []);
	that.group_uids = [];
	that.nw = 3; //items per row. should be calulated from #acl-list.width
	
	that.list_content = $j("#acl-list-content");
	that.item_tpl = unescape($j(".acl-list-item[rel=acl-template]").html());
	that.showall = $j("#acl-showall");

	if (preset.length==0) that.showall.addClass("selected");
	
	/*events*/
	that.showall.click(that.on_showall);
	$j(".acl-button-show").live('click', that.on_button_show);
	$j(".acl-button-hide").live('click', that.on_button_hide);
	$j("#acl-search").keypress(that.on_search);
	$j("#acl-wrapper").parents("form").submit(that.on_submit);
	
	/* startup! */
	that.get(0,100);
}

ACL.prototype.on_submit = function(){
	aclfileds = $j("#acl-fields").html("");
	$j(that.allow_gid).each(function(i,v){
		aclfileds.append("<input type='hidden' name='group_allow[]' value='"+v+"'>");
	});
	$j(that.allow_cid).each(function(i,v){
		aclfileds.append("<input type='hidden' name='contact_allow[]' value='"+v+"'>");
	});
	$j(that.deny_gid).each(function(i,v){
		aclfileds.append("<input type='hidden' name='group_deny[]' value='"+v+"'>");
	});
	$j(that.deny_cid).each(function(i,v){
		aclfileds.append("<input type='hidden' name='contact_deny[]' value='"+v+"'>");
	});	
}

ACL.prototype.search = function(){
	var srcstr = $j("#acl-search").val();
	that.list_content.html("");
	that.get(0,100, srcstr);
}

ACL.prototype.on_search = function(event){
	if (that.kp_timer) clearTimeout(that.kp_timer);
	that.kp_timer = setTimeout( that.search, 1000);
}

ACL.prototype.on_showall = function(event){
	event.preventDefault()
	event.stopPropagation();
	
	if (that.showall.hasClass("selected")){
		return false;
	}
	that.showall.addClass("selected");
	
	that.allow_cid = [];
	that.allow_gid = [];
	that.deny_cid  = [];
	that.deny_gid  = [];
	
	that.update_view();
	
	return false;
}

ACL.prototype.on_button_show = function(event){
	event.preventDefault()
	event.stopImmediatePropagation()
	event.stopPropagation();

	/*that.showall.removeClass("selected");
	$j(this).siblings(".acl-button-hide").removeClass("selected");
	$j(this).toggleClass("selected");*/

	that.set_allow($j(this).parent().attr('id'));

	return false;
}
ACL.prototype.on_button_hide = function(event){
	event.preventDefault()
	event.stopImmediatePropagation()
	event.stopPropagation();

	/*that.showall.removeClass("selected");
	$j(this).siblings(".acl-button-show").removeClass("selected");
	$j(this).toggleClass("selected");*/

	that.set_deny($j(this).parent().attr('id'));

	return false;
}

ACL.prototype.set_allow = function(itemid){
	type = itemid[0];
	id 	 = parseInt(itemid.substr(1));
	switch(type){
		case "g":
			if (that.allow_gid.indexOf(id)<0){
				that.allow_gid.push(id)
			}else {
				that.allow_gid.remove(id);
			}
			if (that.deny_gid.indexOf(id)>=0) that.deny_gid.remove(id);
			break;
		case "c":
			if (that.allow_cid.indexOf(id)<0){
				that.allow_cid.push(id)
			} else {
				that.allow_cid.remove(id);
			}
			if (that.deny_cid.indexOf(id)>=0) that.deny_cid.remove(id);			
			break;
	}
	that.update_view();
}

ACL.prototype.set_deny = function(itemid){
	type = itemid[0];
	id 	 = parseInt(itemid.substr(1));
	switch(type){
		case "g":
			if (that.deny_gid.indexOf(id)<0){
				that.deny_gid.push(id)
			} else {
				that.deny_gid.remove(id);
			}
			if (that.allow_gid.indexOf(id)>=0) that.allow_gid.remove(id);
			break;
		case "c":
			if (that.deny_cid.indexOf(id)<0){
				that.deny_cid.push(id)
			} else {
				that.deny_cid.remove(id);
			}
			if (that.allow_cid.indexOf(id)>=0) that.allow_cid.remove(id);
			break;
	}
	that.update_view();
}

ACL.prototype.update_view = function(){
	if (that.allow_gid.length==0 && that.allow_cid.length==0 &&
		that.deny_gid.length==0 && that.deny_cid.length==0){
			that.showall.addClass("selected");
			/* jot acl */
				$j('#jot-perms-icon').removeClass('lock').addClass('unlock');
				$j('#jot-public').show();
				$j('.profile-jot-net input').attr('disabled', false);			
				if(typeof editor != 'undefined' && editor != false) {
					$j('#profile-jot-desc').html(ispublic);
				}
			
	} else {
			that.showall.removeClass("selected");
			/* jot acl */
				$j('#jot-perms-icon').removeClass('unlock').addClass('lock');
				$j('#jot-public').hide();
				$j('.profile-jot-net input').attr('disabled', 'disabled');			
				$j('#profile-jot-desc').html('&nbsp;');
	}
	$j("#acl-list-content .acl-list-item").each(function(){
		$j(this).removeClass("groupshow grouphide");
	});
	
	$j("#acl-list-content .acl-list-item").each(function(){
		itemid = $j(this).attr('id');
		type = itemid[0];
		id 	 = parseInt(itemid.substr(1));
		
		btshow = $j(this).children(".acl-button-show").removeClass("selected");
		bthide = $j(this).children(".acl-button-hide").removeClass("selected");	
		
		switch(type){
			case "g":
				var uclass = "";
				if (that.allow_gid.indexOf(id)>=0){
					btshow.addClass("selected");
					bthide.removeClass("selected");
					uclass="groupshow";
				}
				if (that.deny_gid.indexOf(id)>=0){
					btshow.removeClass("selected");
					bthide.addClass("selected");
					uclass="grouphide";
				}
				
				$j(that.group_uids[id]).each(function(i,v) {
					if(uclass == "grouphide")
						$j("#c"+v).removeClass("groupshow");
					if(uclass != "") {
						var cls = $j("#c"+v).attr('class');
						if( cls == undefined)
							return true;
						var hiding = cls.indexOf('grouphide');
						if(hiding == -1)
							$j("#c"+v).addClass(uclass);
					}
				});
				
				break;
			case "c":
				if (that.allow_cid.indexOf(id)>=0){
					btshow.addClass("selected");
					bthide.removeClass("selected");
				}
				if (that.deny_cid.indexOf(id)>=0){
					btshow.removeClass("selected");
					bthide.addClass("selected");
				}			
		}
		
	});
	
}


ACL.prototype.get = function(start,count, search){
	var postdata = {
		start:start,
		count:count,
		search:search,
	}
	
	$j.ajax({
		type:'POST',
		url: that.url,
		data: postdata,
		dataType: 'json',
		success:that.populate
	});
}

ACL.prototype.populate = function(data){
	var height = Math.ceil(data.tot / that.nw) * 42;
	that.list_content.height(height);
	$j(data.items).each(function(){
		html = "<div class='acl-list-item {4} {5}' title='{6}' id='{2}{3}'><img src='{0}'/><p>{1}</p>"+that.item_tpl+"</div>";
		html = html.format( this.photo, this.name, this.type, this.id, '', this.network, this.link );
		if (this.uids!=undefined) that.group_uids[this.id] = this.uids;
		//console.log(html);
		that.list_content.append(html);
	});
	that.update_view();
}

