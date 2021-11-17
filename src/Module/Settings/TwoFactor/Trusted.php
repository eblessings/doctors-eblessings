<?php

namespace Friendica\Module\Settings\TwoFactor;

use Friendica\App\BaseURL;
use Friendica\Core\L10n;
use Friendica\Core\PConfig\Capability\IManagePersonalConfigValues;
use Friendica\Core\Renderer;
use Friendica\Module\BaseSettings;
use Friendica\Security\TwoFactor;
use Friendica\Util\Temporal;
use UAParser\Parser;

/**
 * Manages users' two-factor trusted browsers in the 2fa_trusted_browsers table
 */
class Trusted extends BaseSettings
{
	/** @var IManagePersonalConfigValues */
	protected $pConfig;
	/** @var BaseURL */
	protected $baseUrl;
	/** @var TwoFactor\Repository\TrustedBrowser */
	protected $trustedBrowserRepo;

	public function __construct(IManagePersonalConfigValues $pConfig, BaseURL $baseUrl, TwoFactor\Repository\TrustedBrowser $trustedBrowserRepo, L10n $l10n, array $parameters = [])
	{
		parent::__construct($l10n, $parameters);

		$this->pConfig            = $pConfig;
		$this->baseUrl            = $baseUrl;
		$this->trustedBrowserRepo = $trustedBrowserRepo;

		if (!local_user()) {
			return;
		}

		$verified = $this->pConfig->get(local_user(), '2fa', 'verified');

		if (!$verified) {
			$this->baseUrl->redirect('settings/2fa');
		}

		if (!self::checkFormSecurityToken('settings_2fa_password', 't')) {
			notice($this->l10n->t('Please enter your password to access this page.'));
			$this->baseUrl->redirect('settings/2fa');
		}
	}

	public function post()
	{
		if (!local_user()) {
			return;
		}

		if (!empty($_POST['action'])) {
			self::checkFormSecurityTokenRedirectOnError('settings/2fa/trusted', 'settings_2fa_trusted');

			switch ($_POST['action']) {
				case 'remove_all' :
					$this->trustedBrowserRepo->removeAllForUser(local_user());
					info($this->l10n->t('Trusted browsers successfully removed.'));
					$this->baseUrl->redirect('settings/2fa/trusted?t=' . self::getFormSecurityToken('settings_2fa_password'));
					break;
			}
		}

		if (!empty($_POST['remove_id'])) {
			self::checkFormSecurityTokenRedirectOnError('settings/2fa/trusted', 'settings_2fa_trusted');

			if ($this->trustedBrowserRepo->removeForUser(local_user(), $_POST['remove_id'])) {
				info($this->l10n->t('Trusted browser successfully removed.'));
			}

			$this->baseUrl->redirect('settings/2fa/trusted?t=' . self::getFormSecurityToken('settings_2fa_password'));
		}
	}


	public function content(): string
	{
		parent::content();

		$trustedBrowsers = $this->trustedBrowserRepo->selectAllByUid(local_user());

		$parser = Parser::create();

		$trustedBrowserDisplay = array_map(function (TwoFactor\Model\TrustedBrowser $trustedBrowser) use ($parser) {
			$dates = [
				'created_ago' => Temporal::getRelativeDate($trustedBrowser->created),
				'last_used_ago' => Temporal::getRelativeDate($trustedBrowser->last_used),
			];

			$result = $parser->parse($trustedBrowser->user_agent);

			$uaData = [
				'os' => $result->os->family,
				'device' => $result->device->family,
				'browser' => $result->ua->family,
			];

			return $trustedBrowser->toArray() + $dates + $uaData;
		}, $trustedBrowsers->getArrayCopy());

		return Renderer::replaceMacros(Renderer::getMarkupTemplate('settings/twofactor/trusted_browsers.tpl'), [
			'$form_security_token' => self::getFormSecurityToken('settings_2fa_trusted'),
			'$password_security_token' => self::getFormSecurityToken('settings_2fa_password'),

			'$title'               => $this->l10n->t('Two-factor Trusted Browsers'),
			'$message'             => $this->l10n->t('Trusted browsers are individual browsers you chose to skip two-factor authentication to access Friendica. Please use this feature sparingly, as it can negate the benefit of two-factor authentication.'),
			'$device_label'        => $this->l10n->t('Device'),
			'$os_label'            => $this->l10n->t('OS'),
			'$browser_label'       => $this->l10n->t('Browser'),
			'$created_label'       => $this->l10n->t('Trusted'),
			'$last_used_label'     => $this->l10n->t('Last Use'),
			'$remove_label'        => $this->l10n->t('Remove'),
			'$remove_all_label'    => $this->l10n->t('Remove All'),

			'$trusted_browsers'    => $trustedBrowserDisplay,
		]);
	}
}
