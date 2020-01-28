<?php

namespace Friendica\Module\Notifications;

use Friendica\BaseModule;
use Friendica\Core\System;
use Friendica\DI;
use Friendica\Network\HTTPException;

/**
 * Interacting with the /notification command
 */
class Notification extends BaseModule
{
	public static function init(array $parameters = [])
	{
		if (!local_user()) {
			throw new HTTPException\UnauthorizedException(DI::l10n()->t('Permission denied.'));
		}
	}

	public static function post(array $parameters = [])
	{
		$request_id = $parameters['id'] ?? false;

		if (DI::args()->get(1) == 'action' && $request_id) {
			$intro = DI::intro()->selectFirst(['id' => $request_id, 'uid' => local_user()]);

			switch ($_POST['submit']) {
				case DI::l10n()->t('Discard'):
					$intro->discard();
					break;
				case DI::l10n()->t('Ignore'):
					$intro->ignore();
					break;
			}

			DI::baseUrl()->redirect('notifications/intros');
		}
	}

	public static function rawContent(array $parameters = [])
	{
		// @TODO: Replace with parameter from router
		if (DI::args()->get(1) === 'mark' && DI::args()->get(2) === 'all') {
			try {
				$success = DI::notify()->setAllSeen();
			}catch (\Exception $e) {
				$success = false;
			}

			header('Content-type: application/json; charset=utf-8');
			echo json_encode([
				'result' => ($success) ? 'success' : 'fail',
			]);
			exit();
		}
	}

	/**
	 * Redirect to the notifications main page or to the url for the chosen notifications
	 *
	 * @return string|void
	 * @throws HTTPException\InternalServerErrorException
	 */
	public static function content(array $parameters = [])
	{
		// @TODO: Replace with parameter from router
		if (DI::args()->getArgc() > 2 && DI::args()->get(1) === 'view' && intval(DI::args()->get(2))) {
			try {
				$notification = DI::notify()->getByID(DI::args()->get(2));
				$notification->setSeen();

				if (!empty($notification->link)) {
					System::externalRedirect($notification->link);
				}

			} catch (HTTPException\NotFoundException $e) {
				info(DI::l10n()->t('Invalid notification.'));
			}

			DI::baseUrl()->redirect();
		}

		// @TODO: Replace with parameter from router
		DI::baseUrl()->redirect('notifications/system');
	}
}
