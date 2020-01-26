<?php
/**
 * @file src/Util/Emailer.php
 */
namespace Friendica\Util;

use Friendica\App;
use Friendica\Core\Config\IConfig;
use Friendica\Core\Hook;
use Friendica\Core\PConfig\IPConfig;
use Friendica\Network\HTTPException\InternalServerErrorException;
use Friendica\Object\EMail\IEmail;
use Friendica\Protocol\Email;
use Psr\Log\LoggerInterface;

/**
 * class to handle emailing
 */
class Emailer
{
	/** @var IConfig */
	private $config;
	/** @var IPConfig */
	private $pConfig;
	/** @var LoggerInterface */
	private $logger;
	/** @var App\BaseURL */
	private $baseUrl;

	public function __construct(IConfig $config, IPConfig $pConfig, App\BaseURL $baseURL, LoggerInterface $logger)
	{
		$this->config      = $config;
		$this->pConfig     = $pConfig;
		$this->logger      = $logger;
		$this->baseUrl     = $baseURL;
	}

	/**
	 * Send a multipart/alternative message with Text and HTML versions
	 *
	 * @param IEmail $email The email to send
	 *
	 * @return bool
	 * @throws InternalServerErrorException
	 */
	public function send(IEmail $email)
	{
		$params['sent'] = false;

		Hook::callAll('emailer_send_prepare', $params);

		if ($params['sent']) {
			return true;
		}

		$email_textonly = false;
		if (!empty($email->getRecipientUid())) {
			$email_textonly = $this->pConfig->get($email->getRecipientUid(), 'system', 'email_textonly');
		}

		$fromName       = Email::encodeHeader(html_entity_decode($email->getFromName(), ENT_QUOTES, 'UTF-8'), 'UTF-8');
		$fromAddress      = $email->getFromAddress();
		$replyTo        = $email->getReplyTo();
		$messageSubject = Email::encodeHeader(html_entity_decode($email->getSubject(), ENT_QUOTES, 'UTF-8'), 'UTF-8');

		// generate a mime boundary
		$mimeBoundary = rand(0, 9) . '-'
		                . rand(100000000, 999999999) . '-'
		                . rand(100000000, 999999999) . '=:'
		                . rand(10000, 99999);

		// generate a multipart/alternative message header
		$messageHeader = $email->getAdditionalMailHeader() .
		                 "From: $fromName <{$fromAddress}>\n" .
		                 "Reply-To: $fromName <{$replyTo}>\n" .
		                 "MIME-Version: 1.0\n" .
		                 "Content-Type: multipart/alternative; boundary=\"{$mimeBoundary}\"";

		// assemble the final multipart message body with the text and html types included
		$textBody             = chunk_split(base64_encode($email->getMessage(true)));
		$htmlBody             = chunk_split(base64_encode($email->getMessage()));
		$multipartMessageBody = "--" . $mimeBoundary . "\n" .                    // plain text section
		                        "Content-Type: text/plain; charset=UTF-8\n" .
		                        "Content-Transfer-Encoding: base64\n\n" .
		                        $textBody . "\n";

		if (!$email_textonly && !is_null($email->getMessage())) {
			$multipartMessageBody .=
				"--" . $mimeBoundary . "\n" .                // text/html section
				"Content-Type: text/html; charset=UTF-8\n" .
				"Content-Transfer-Encoding: base64\n\n" .
				$htmlBody . "\n";
		}
		$multipartMessageBody .=
			"--" . $mimeBoundary . "--\n";                    // message ending

		if ($this->config->get('system', 'sendmail_params', true)) {
			$sendmail_params = '-f ' . $fromAddress;
		} else {
			$sendmail_params = null;
		}

		// send the message
		$hookdata = [
			'to'         => $email->getToAddress(),
			'subject'    => $messageSubject,
			'body'       => $multipartMessageBody,
			'headers'    => $messageHeader,
			'parameters' => $sendmail_params,
			'sent'       => false,
		];

		Hook::callAll('emailer_send', $hookdata);

		if ($hookdata['sent']) {
			return true;
		}

		$res = mail(
			$hookdata['to'],
			$hookdata['subject'],
			$hookdata['body'],
			$hookdata['headers'],
			$hookdata['parameters']
		);
		$this->logger->debug('header ' . 'To: ' . $email->getToAddress() . '\n' . $messageHeader);
		$this->logger->debug('return value ' . (($res) ? 'true' : 'false'));
		return $res;
	}
}
