<?xml version="1.0" encoding="UTF-8"?>
<OpenSearchDescription xmlns="http://a9.com/-/spec/opensearch/1.1/">
	<ShortName>Friendica@$nodename</ShortName>
	<Description>Search in Friendica@$nodename</Description>
	<Contact>http://bugs.friendica.com/</Contact>
	<Image height="16" width="16" type="image/png">$baseurl/images/friendica-16.png</Image>
	<Image height="64" width="64" type="image/png">$baseurl/images/friendica-64.png</Image>
	<Url type="text/html" 
        template="$baseurl/search?search={searchTerms}"/>
	<Url type="application/opensearchdescription+xml"
      	rel="self"
      	template="$baseurl/opensearch" />        
</OpenSearchDescription>