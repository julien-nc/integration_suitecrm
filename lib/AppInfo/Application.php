<?php
/**
 * Nextcloud - SuiteCRM
 *
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\SuiteCRM\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\Notification\IManager as INotificationManager;

use OCA\SuiteCRM\Dashboard\SuiteCRMWidget;
use OCA\SuiteCRM\Search\SuiteCRMSearchProvider;
use OCA\SuiteCRM\Notification\Notifier;

/**
 * Class Application
 *
 * @package OCA\SuiteCRM\AppInfo
 */
class Application extends App implements IBootstrap {

	public const APP_ID = 'integration_suitecrm';

	/**
	 * Constructor
	 *
	 * @param array $urlParams
	 */
	public function __construct(array $urlParams = []) {
		parent::__construct(self::APP_ID, $urlParams);

		$container = $this->getContainer();
		$manager = $container->get(INotificationManager::class);
		$manager->registerNotifierService(Notifier::class);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(SuiteCRMWidget::class);
		$context->registerSearchProvider(SuiteCRMSearchProvider::class);
	}

	public function boot(IBootContext $context): void {
	}
}

