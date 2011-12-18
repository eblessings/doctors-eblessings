<entry>
{{ if $entry.id }}<id>$entry.id</id>{{ endif }}
{{ if $entry.displayName }}<displayName>$entry.displayName</displayName>{{ endif }}
{{ if $entry.preferredUsername }}<preferredUsername>$entry.preferredUsername</preferredUsername>{{ endif }}
{{ if $entry.urls }}
{{for $entry.urls as $url }}
<urls><value>$url.value</value><type>$url.type</type></urls>
{{endfor}}
{{ endif }}
{{ if $entry.photos }}<photos><value>$entry.photos.value</value><type>$entry.photos.type</type></photos>{{ endif }}
</entry>
