<?php

namespace Friendica\Util\EMailer;

use Exception;
use Friendica\App\BaseURL;
use Friendica\Core\Config\IConfig;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;
use Friendica\Model\User;
use Friendica\Network\HTTPException\InternalServerErrorException;
use Friendica\Object\Email;
use Friendica\Object\EMail\IEmail;

/**
 * A base class for building new emails
 */
abstract class MailBuilder
{
	/** @var L10n */
	protected $l10n;
	/** @var IConfig */
	protected $config;
	/** @var BaseURL */
	protected $baseUrl;

	/** @var string */
	protected $headers;

	/** @var string */
	protected $senderName = null;
	/** @var string */
	protected $senderAddress = null;
	/** @var string */
	protected $senderNoReply = null;

	/** @var string */
	protected $recipientAddress = null;
	/** @var int */
	protected $recipientUid = null;

	public function __construct(L10n $l10n, BaseURL $baseUrl, IConfig $config)
	{
		$this->l10n    = $l10n;
		$this->baseUrl = $baseUrl;
		$this->config  = $config;

		$hostname = $baseUrl->getHostname();
		if (strpos($hostname, ':')) {
			$hostname = substr($hostname, 0, strpos($hostname, ':'));
		}

		$this->headers = "";
		$this->headers .= "Precedence: list\n";
		$this->headers .= "X-Friendica-Host: " . $hostname . "\n";
		$this->headers .= "X-Friendica-Platform: " . FRIENDICA_PLATFORM . "\n";
		$this->headers .= "X-Friendica-Version: " . FRIENDICA_VERSION . "\n";
		$this->headers .= "List-ID: <notification." . $hostname . ">\n";
		$this->headers .= "List-Archive: <" . $baseUrl->get() . "/notifications/system>\n";
	}

	/**
	 * Gets the subject of the concrete builder, which inherits this base class
	 *
	 * @return string
	 */
	abstract protected function getSubject();

	/**
	 * Gets the HTML version of the body of the concrete builder, which inherits this base class
	 *
	 * @return string
	 */
	abstract protected function getHtmlMessage();

	/**
	 * Gets the Plaintext version of the body of the concrete builder, which inherits this base class
	 *
	 * @return string
	 */
	abstract protected function getPlaintextMessage();

	/**
	 * Adds the User ID to the email in case the mail sending needs additional properties of this user
	 *
	 * @param int $uid The User ID
	 *
	 * @return static
	 */
	public function forUser(int $uid)
	{
		$this->recipientUid = $uid;

		return $this;
	}

	/**
	 * Adds the sender to the email (if not called/set, the sender will get loaded with the help of the user id)
	 *
	 * @param string      $name    The name of the sender
	 * @param string      $address The (email) address of the sender
	 * @param string|null $noReply Optional "no-reply" (email) address (if not set, it's the same as the address)
	 *
	 * @return static
	 */
	public function withSender(string $name, string $address, string $noReply = null)
	{
		$this->senderName    = $name;
		$this->senderAddress = $address;
		$this->senderNoReply = $noReply ?? $this->senderNoReply;

		return $this;
	}

	/**
	 * Adds a recipient to the email
	 *
	 * @param string $address The (email) address of the recipient
	 *
	 * @return static
	 */
	public function withRecipient(string $address)
	{
		$this->recipientAddress = $address;

		return $this;
	}

	/**
	 * Build a email based on the given attributes
	 *
	 * @param bool $raw True, if the email shouldn't get extended by the default email-template
	 *
	 * @return IEmail A new generated email
	 *
	 * @throws InternalServerErrorException
	 * @throws Exception
	 */
	public function build(bool $raw = false)
	{
		if ((empty($this->recipientAddress)) &&
		    !empty($this->recipientUid)) {
			$user = User::getById($this->recipientUid, ['email']);

			if (!empty($user['email'])) {
				$this->recipientAddress = $user['email'];
			}
		}

		if (empty($this->recipientAddress)) {
			throw new InternalServerErrorException('Recipient address is missing.');
		}

		if (empty($this->senderAddress) || empty($this->senderName)) {
			throw new InternalServerErrorException('Sender address or name is missing.');
		}

		$this->senderNoReply = $this->senderNoReply ?? $this->senderAddress;

		$msgHtml = $this->getHtmlMessage() ?? '';

		if (!$raw) {
			// load the template for private message notifications
			$tpl     = Renderer::getMarkupTemplate('email/html.tpl');
			$msgHtml = Renderer::replaceMacros($tpl, [
				'$banner'      => $this->l10n->t('Friendica Notification'),
				'$product'     => FRIENDICA_PLATFORM,
				'$htmlversion' => $msgHtml,
				'$sitename'    => $this->config->get('config', 'sitename'),
				'$siteurl'     => $this->baseUrl->get(true),
			]);
		}

		return new Email(
			$this->senderName,
			$this->senderAddress,
			$this->senderNoReply,
			$this->recipientAddress,
			$this->getSubject() ?? '',
			$msgHtml,
			$this->getPlaintextMessage() ?? '',
			$this->headers,
			$this->recipientUid ?? null);
	}
}
