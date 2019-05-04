<?xml version="1.0" encoding="UTF-8"?>
<rsd version="1.0" xmlns="http://archipelago.phrasewise.com/rsd">
	<service>
		<engineName>Friendica</engineName>
		<engineLink>http://friendica.com/</engineLink>
		<apis>
			<api name="Twitter" preferred="true" apiLink="{{$baseurl}}/api/" blogID="">
				<settings>
					<docs>http://status.net/wiki/TwitterCompatibleAPI</docs>
					<setting name="OAuth">false</setting>
				</settings>
			</api>
		</apis>
	</service>
</rsd>
