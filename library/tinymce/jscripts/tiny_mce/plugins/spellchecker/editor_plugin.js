(function(){var a=tinymce.util.JSONRequest,c=tinymce.each,b=tinymce.DOM;tinymce.create("tinymce.plugins.SpellcheckerPlugin",{getInfo:function(){return{longname:"Spellchecker",author:"Moxiecode Systems AB",authorurl:"http://tinymce.moxiecode.com",infourl:"http://wiki.moxiecode.com/index.php/TinyMCE:Plugins/spellchecker",version:tinymce.majorVersion+"."+tinymce.minorVersion}},init:function(e,f){var g=this,d;g.url=f;g.editor=e;g.rpcUrl=e.getParam("spellchecker_rpc_url","{backend}");if(g.rpcUrl=="{backend}"){if(tinymce.isIE){return}g.hasSupport=true;e.onContextMenu.addToTop(function(h,i){if(g.active){return false}})}e.addCommand("mceSpellCheck",function(){if(g.rpcUrl=="{backend}"){g.editor.getBody().spellcheck=g.active=!g.active;return}if(!g.active){e.setProgressState(1);g._sendRPC("checkWords",[g.selectedLang,g._getWords()],function(h){if(h.length>0){g.active=1;g._markWords(h);e.setProgressState(0);e.nodeChanged()}else{e.setProgressState(0);if(e.getParam("spellchecker_report_no_misspellings",true)){e.windowManager.alert("spellchecker.no_mpell")}}})}else{g._done()}});if(e.settings.content_css!==false){e.contentCSS.push(f+"/css/content.css")}e.onClick.add(g._showMenu,g);e.onContextMenu.add(g._showMenu,g);e.onBeforeGetContent.add(function(){if(g.active){g._removeWords()}});e.onNodeChange.add(function(i,h){h.setActive("spellchecker",g.active)});e.onSetContent.add(function(){g._done()});e.onBeforeGetContent.add(function(){g._done()});e.onBeforeExecCommand.add(function(h,i){if(i=="mceFullScreen"){g._done()}});g.languages={};c(e.getParam("spellchecker_languages","+English=en,Danish=da,Dutch=nl,Finnish=fi,French=fr,German=de,Italian=it,Polish=pl,Portuguese=pt,Spanish=es,Swedish=sv","hash"),function(i,h){if(h.indexOf("+")===0){h=h.substring(1);g.selectedLang=i}g.languages[h]=i})},createControl:function(h,d){var f=this,g,e=f.editor;if(h=="spellchecker"){if(f.rpcUrl=="{backend}"){if(f.hasSupport){g=d.createButton(h,{title:"spellchecker.desc",cmd:"mceSpellCheck",scope:f})}return g}g=d.createSplitButton(h,{title:"spellchecker.desc",cmd:"mceSpellCheck",scope:f});g.onRenderMenu.add(function(j,i){i.add({title:"spellchecker.langs","class":"mceMenuItemTitle"}).setDisabled(1);c(f.languages,function(n,m){var p={icon:1},l;p.onclick=function(){if(n==f.selectedLang){return}l.setSelected(1);f.selectedItem.setSelected(0);f.selectedItem=l;f.selectedLang=n};p.title=m;l=i.add(p);l.setSelected(n==f.selectedLang);if(n==f.selectedLang){f.selectedItem=l}})});return g}},_walk:function(i,g){var h=this.editor.getDoc(),e;if(h.createTreeWalker){e=h.createTreeWalker(i,NodeFilter.SHOW_TEXT,null,false);while((i=e.nextNode())!=null){g.call(this,i)}}else{tinymce.walk(i,g,"childNodes")}},_getSeparators:function(){var e="",d,f=this.editor.getParam("spellchecker_word_separator_chars",'\\s!"#$%&()*+,-./:;<=>?@[]^_{|}����������������\u201d\u201c');for(d=0;d<f.length;d++){e+="\\"+f.charAt(d)}return e},_getWords:function(){var e=this.editor,g=[],d="",f={},h=[];this._walk(e.getBody(),function(i){if(i.nodeType==3){d+=i.nodeValue+" "}});if(e.getParam("spellchecker_word_pattern")){h=d.match("("+e.getParam("spellchecker_word_pattern")+")","gi")}else{d=d.replace(new RegExp("([0-9]|["+this._getSeparators()+"])","g")," ");d=tinymce.trim(d.replace(/(\s+)/g," "));h=d.split(" ")}c(h,function(i){if(!f[i]){g.push(i);f[i]=1}});return g},_removeWords:function(d){var e=this.editor,h=e.dom,g=e.selection,f=g.getRng(true);c(h.select("span").reverse(),function(i){if(i&&(h.hasClass(i,"mceItemHiddenSpellWord")||h.hasClass(i,"mceItemHidden"))){if(!d||h.decode(i.innerHTML)==d){h.remove(i,1)}}});g.setRng(f)},_markWords:function(l){var h=this.editor,g=h.dom,j=h.getDoc(),i=h.selection,d=i.getRng(true),e=[],k=l.join("|"),m=this._getSeparators(),f=new RegExp("(^|["+m+"])("+k+")(?=["+m+"]|$)","g");this._walk(h.getBody(),function(o){if(o.nodeType==3){e.push(o)}});c(e,function(t){var r,q,o,s,p=t.nodeValue;if(f.test(p)){p=g.encode(p);q=g.create("span",{"class":"mceItemHidden"});if(tinymce.isIE){p=p.replace(f,"$1<mcespell>$2</mcespell>");while((s=p.indexOf("<mcespell>"))!=-1){o=p.substring(0,s);if(o.length){r=j.createTextNode(g.decode(o));q.appendChild(r)}p=p.substring(s+10);s=p.indexOf("</mcespell>");o=p.substring(0,s);p=p.substring(s+11);q.appendChild(g.create("span",{"class":"mceItemHiddenSpellWord"},o))}if(p.length){r=j.createTextNode(g.decode(p));q.appendChild(r)}}else{q.innerHTML=p.replace(f,'$1<span class="mceItemHiddenSpellWord">$2</span>')}g.replace(q,t)}});i.setRng(d)},_showMenu:function(h,j){var i=this,h=i.editor,d=i._menu,l,k=h.dom,g=k.getViewPort(h.getWin()),f=j.target;j=0;if(!d){d=h.controlManager.createDropMenu("spellcheckermenu",{"class":"mceNoIcons"});i._menu=d}if(k.hasClass(f,"mceItemHiddenSpellWord")){d.removeAll();d.add({title:"spellchecker.wait","class":"mceMenuItemTitle"}).setDisabled(1);i._sendRPC("getSuggestions",[i.selectedLang,k.decode(f.innerHTML)],function(m){var e;d.removeAll();if(m.length>0){d.add({title:"spellchecker.sug","class":"mceMenuItemTitle"}).setDisabled(1);c(m,function(n){d.add({title:n,onclick:function(){k.replace(h.getDoc().createTextNode(n),f);i._checkDone()}})});d.addSeparator()}else{d.add({title:"spellchecker.no_sug","class":"mceMenuItemTitle"}).setDisabled(1)}if(h.getParam("show_ignore_words",true)){e=i.editor.getParam("spellchecker_enable_ignore_rpc","");d.add({title:"spellchecker.ignore_word",onclick:function(){var n=f.innerHTML;k.remove(f,1);i._checkDone();if(e){h.setProgressState(1);i._sendRPC("ignoreWord",[i.selectedLang,n],function(o){h.setProgressState(0)})}}});d.add({title:"spellchecker.ignore_words",onclick:function(){var n=f.innerHTML;i._removeWords(k.decode(n));i._checkDone();if(e){h.setProgressState(1);i._sendRPC("ignoreWords",[i.selectedLang,n],function(o){h.setProgressState(0)})}}})}if(i.editor.getParam("spellchecker_enable_learn_rpc")){d.add({title:"spellchecker.learn_word",onclick:function(){var n=f.innerHTML;k.remove(f,1);i._checkDone();h.setProgressState(1);i._sendRPC("learnWord",[i.selectedLang,n],function(o){h.setProgressState(0)})}})}d.update()});l=b.getPos(h.getContentAreaContainer());d.settings.offset_x=l.x;d.settings.offset_y=l.y;h.selection.select(f);l=k.getPos(f);d.showMenu(l.x,l.y+f.offsetHeight-g.y);return tinymce.dom.Event.cancel(j)}else{d.hideMenu()}},_checkDone:function(){var e=this,d=e.editor,g=d.dom,f;c(g.select("span"),function(h){if(h&&g.hasClass(h,"mceItemHiddenSpellWord")){f=true;return false}});if(!f){e._done()}},_done:function(){var d=this,e=d.active;if(d.active){d.active=0;d._removeWords();if(d._menu){d._menu.hideMenu()}if(e){d.editor.nodeChanged()}}},_sendRPC:function(e,g,d){var f=this;a.sendRPC({url:f.rpcUrl,method:e,params:g,success:d,error:function(i,h){f.editor.setProgressState(0);f.editor.windowManager.alert(i.errstr||("Error response: "+h.responseText))}})}});tinymce.PluginManager.add("spellchecker",tinymce.plugins.SpellcheckerPlugin)})();