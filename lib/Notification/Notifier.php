<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2019
 */

namespace OCA\SuiteCRM\Notification;


use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCA\SuiteCRM\AppInfo\Application;

class Notifier implements INotifier {

	/** @var IFactory */
	protected $factory;

	/** @var IUserManager */
	protected $userManager;

	/** @var INotificationManager */
	protected $notificationManager;

	/** @var IURLGenerator */
	protected $url;

	/**
	 * @param IFactory $factory
	 * @param IUserManager $userManager
	 * @param INotificationManager $notificationManager
	 * @param IURLGenerator $urlGenerator
	 */
	public function __construct(IFactory $factory,
								IUserManager $userManager,
								INotificationManager $notificationManager,
								IURLGenerator $urlGenerator) {
		$this->factory = $factory;
		$this->userManager = $userManager;
		$this->notificationManager = $notificationManager;
		$this->url = $urlGenerator;
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getID(): string {
		return 'integration_suitecrm';
	}
	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	public function getName(): string {
		return $this->lFactory->get('integration_suitecrm')->t('SuiteCRM');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 * @return INotification
	 * @throws \InvalidArgumentException When the notification was not prepared by a notifier
	 * @since 9.0.0
	 */
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== 'integration_suitecrm') {
			// Not my app => throw
			throw new \InvalidArgumentException();
		}

		$l = $this->factory->get('integration_suitecrm', $languageCode);

		switch ($notification->getSubject()) {
		case 'reminder':
			$p = $notification->getSubjectParameters();
			$type = $p['type'];
			$title = $p['title'];
			$link = $p['link'] ?? '';
			$date = new \DateTime();
			$date->setTimestamp($p['event_timestamp']);
			//$formattedDate = $date->format('Y-m-d H:i Z');
			$formattedDate = $date->format('r');

			if ($type === 'Calls') {
				$content = $l->t('SuiteCRM call: %s, Time: %s', [$title, $formattedDate]);
			} elseif ($type === 'Meetings') {
				$content = $l->t('SuiteCRM meeting: %s, Time: %s', [$title, $formattedDate]);
			}

			$notification->setParsedSubject($content)
				->setLink($link)
				->setIcon($this->url->getAbsoluteURL($this->url->imagePath(Application::APP_ID, 'app-dark.svg')));
				//->setIcon($this->url->getAbsoluteURL($iconUrl));
			return $notification;

		default:
			// Unknown subject => Unknown notification => throw
			throw new \InvalidArgumentException();
		}
	}
}
