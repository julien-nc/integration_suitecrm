<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 * @copyright Julien Veyssier 2020
 */

namespace OCA\SuiteCRM\Controller;

use OCP\AppFramework\Http\DataDisplayResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\AppInfo\Application;

class SuiteCRMAPIController extends Controller {

	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var SuiteCRMAPIService
	 */
	private $suitecrmAPIService;
	/**
	 * @var string|null
	 */
	private $userId;
	/**
	 * @var string
	 */
	private $accessToken;
	/**
	 * @var string
	 */
	private $refreshToken;
	/**
	 * @var string
	 */
	private $clientID;
	/**
	 * @var string
	 */
	private $clientSecret;
	/**
	 * @var string
	 */
	private $suitecrmUrl;

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								SuiteCRMAPIService $suitecrmAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->suitecrmAPIService = $suitecrmAPIService;
		$this->userId = $userId;
		$this->accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
		$this->refreshToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'refresh_token');
		$this->clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$this->clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
		$this->suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
	}

	/**
	 * get suitecrm instance URL
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 */
	public function getSuiteCRMUrl(): DataResponse {
		return new DataResponse($this->suitecrmUrl);
	}

	/**
	 * get suitecrm user avatar
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 *
	 * @param string $suiteUserId
	 * @return DataDisplayResponse
	 */
	public function getSuiteCRMAvatar(string $suiteUserId = ''): DataDisplayResponse {
		$response = new DataDisplayResponse(
			$this->suitecrmAPIService->getSuiteCRMAvatar(
				$this->suitecrmUrl, $this->accessToken, $suiteUserId
			)
		);
		$response->cacheFor(60*60*24);
		return $response;
	}

	/**
	 * get reminder list for future events
	 * @NoAdminRequired
	 *
	 * @param int|null $eventSinceTimestamp
	 * @param int|null $limit
	 * @return DataResponse
	 */
	public function getReminders(int $eventSinceTimestamp = null, ?int $limit = null): DataResponse {
		if ($this->accessToken === '') {
			return new DataResponse('', 400);
		}
		$result = $this->suitecrmAPIService->getReminders(
			$this->suitecrmUrl, $this->accessToken, $this->userId, null, null, $eventSinceTimestamp, null, $limit
		);
		if (!isset($result['error'])) {
			$response = new DataResponse($result);
		} else {
			$response = new DataResponse($result, 401);
		}
		return $response;
	}

}
