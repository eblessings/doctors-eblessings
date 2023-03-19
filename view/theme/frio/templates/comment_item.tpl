
{{if $threaded}}
<div class="comment-wwedit-wrapper threaded dropzone" id="comment-edit-wrapper-{{$id}}">
{{else}}
<div class="comment-wwedit-wrapper dropzone" id="comment-edit-wrapper-{{$id}}">
{{/if}}
	<form class="comment-edit-form" data-item-id="{{$id}}" id="comment-edit-form-{{$id}}" action="item" method="post">
		<input type="hidden" name="profile_uid" value="{{$profile_uid}}" />
		<input type="hidden" name="parent" value="{{$parent}}" />
		{{*<!--<input type="hidden" name="return" value="{{$return_path}}" />-->*}}
		<input type="hidden" name="jsreload" value="{{$jsreload}}" />
		<input type="hidden" name="post_id_random" value="{{$rand_num}}" />

		<p class="comment-edit-bb-{{$id}} comment-icon-list">
			<span>
				<button type="button" class="btn btn-sm template-icon bb-img" style="cursor: pointer;" aria-label="{{$edimg}}" title="{{$edimg}}" data-role="insert-formatting" data-bbcode="img" data-id="{{$id}}">
					<i class="fa fa-picture-o"></i>
				</button>
				<button type="button" class="btn btn-sm template-icon bb-attach" style="cursor: pointer;" aria-label="{{$edattach}}" title="{{$edattach}}" ondragenter="return commentLinkDrop(event, {{$id}});" ondragover="return commentLinkDrop(event, {{$id}});" ondrop="commentLinkDropper(event);" onclick="commentGetLink({{$id}}, '{{$prompttext}}');">
					<i class="fa fa-paperclip"></i>
				</button>
			</span>
			<span>
				<button type="button" class="btn btn-sm template-icon bb-url" style="cursor: pointer;" aria-label="{{$edurl}}" title="{{$edurl}}" onclick="insertFormatting('url',{{$id}});">
					<i class="fa fa-link"></i>
				</button>
				<button type="button" class="btn btn-sm template-icon underline" style="cursor: pointer;" aria-label="{{$eduline}}" title="{{$eduline}}" onclick="insertFormatting('u',{{$id}});">
					<i class="fa fa-underline"></i>
				</button>
				<button type="button" class="btn btn-sm template-icon italic" style="cursor: pointer;" aria-label="{{$editalic}}" title="{{$editalic}}" onclick="insertFormatting('i',{{$id}});">
					<i class="fa fa-italic"></i>
				</button>
				<button type="button" class="btn btn-sm template-icon bold" style="cursor: pointer;" aria-label="{{$edbold}}" title="{{$edbold}}" onclick="insertFormatting('b',{{$id}});">
					<i class="fa fa-bold"></i>
				</button>
				<button type="button" class="btn btn-sm template-icon quote" style="cursor: pointer;" aria-label="{{$edquote}}" title="{{$edquote}}" onclick="insertFormatting('quote',{{$id}});">
					<i class="fa fa-quote-left"></i>
				</button>
			</span>
		</p>
		<p>
			<textarea id="comment-edit-text-{{$id}}" class="comment-edit-text-empty form-control text-autosize" name="body" placeholder="{{$comment}}" rows="3" data-default="{{$default}}" dir="auto">{{$default}}</textarea>
		</p>
{{if $qcomment}}
		<p>
			<select id="qcomment-select-{{$id}}" name="qcomment-{{$id}}" class="qcomment" onchange="qCommentInsert(this,{{$id}});">
				<option value=""></option>
	{{foreach $qcomment as $qc}}
				<option value="{{$qc}}">{{$qc}}</option>
	{{/foreach}}
			</select>
		</p>
{{/if}}

		<p class="comment-edit-submit-wrapper">
{{if $preview}}
			<button type="button" class="btn btn-default comment-edit-preview" onclick="preview_comment({{$id}});" id="comment-edit-preview-link-{{$id}}"><i class="fa fa-eye"></i> {{$preview}}</button>
{{/if}}
			<button type="submit" class="btn btn-primary comment-edit-submit" id="comment-edit-submit-{{$id}}" name="submit" data-loading-text="{{$loading}}"><i class="fa fa-envelope"></i> {{$submit}}</button>
		</p>

		<div class="comment-edit-end clear"></div>
	</form>
        <div id="dz-preview-{{$id}}" class="dropzone-preview"></div>
	<div id="comment-edit-preview-{{$id}}" class="comment-edit-preview" style="display:none;"></div>
</div>

<script>
  Dropzone.autoDiscover = false;
  var dropzone{{$id}} = new Dropzone( '#comment-edit-wrapper-{{$id}}', {
  //var dropzone{{$id}} = new Dropzone( document.body, {
    paramName: "userfile", // The name that will be used to transfer the file
    maxFilesize: 6, // MB
    previewsContainer: '#dz-preview-{{$id}}',
    preventDuplicates: true,
    clickable: true,
    thumbnailWidth: 100,
    thumbnailHeight: 100,
    url: "/media/photo/upload?response=url&album=",
    accept: function(file, done) {
      done();
    },
    init: function() {
      this.on("success", function(file, serverResponse) {
        var target = $('#comment-edit-text-{{$id}}')
        var resp = $(serverResponse).find('div#content').text()
        if (target.setRangeText) {
          //if setRangeText function is supported by current browser
          target.setRangeText(" " + $.trim(resp) + " ")
        } else {
          target.focus()
          document.execCommand('insertText', false /*no UI*/, " " + $.trim(resp) + " ");
        }
      });
    },
  });

  $('#comment-edit-wrapper-{{$id}}').on('paste', function(event){
    const items = (event.clipboardData || event.originalEvent.clipboardData).items;
    items.forEach((item) => {
      if (item.kind === 'file') {
        // adds the file to your dropzone instance
        console.log(item);
        dropzone{{$id}}.addFile(item.getAsFile())
      }
    })
  })


</script>
