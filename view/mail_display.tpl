
{{ for $mails as $mail_item }}
	{{ inc mail_conv.tpl with $mail=$mail_item }}{{endinc}}
{{ endfor }}

{{ if $canreply }}
{{ inc prv_message.tpl with $reply=$reply_info }}{{ endinc }}
{{ else }}
$unknown_text
{{endif }}
