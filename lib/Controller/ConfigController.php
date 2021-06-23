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

use OCP\IConfig;
use OCP\IRequest;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Controller;

use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\AppInfo\Application;

class ConfigController extends Controller {

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

	public function __construct(string $appName,
								IRequest $request,
								IConfig $config,
								SuiteCRMAPIService $suitecrmAPIService,
								?string $userId) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->suitecrmAPIService = $suitecrmAPIService;
		$this->userId = $userId;
	}

	/**
	 * set config values
	 * @NoAdminRequired
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setUserValue($this->userId, Application::APP_ID, $key, $value);
		}
		$result = [];

		if (isset($values['user_name']) && $values['user_name'] === '') {
			$accessToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'token');
//			$refreshToken = $this->config->getUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
			$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
//			$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
//			$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
			$this->suitecrmAPIService->request(
				$suitecrmUrl, $accessToken, $this->userId, 'logout', [], 'POST'
			);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'token', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', '');
			$this->config->setUserValue($this->userId, Application::APP_ID, 'last_reminder_check', '');
			$result = [
				'user_name' => '',
			];
		}

		return new DataResponse($result);
	}

	/**
	 * set admin config values
	 *
	 * @param array $values
	 * @return DataResponse
	 */
	public function setAdminConfig(array $values): DataResponse {
		foreach ($values as $key => $value) {
			$this->config->setAppValue(Application::APP_ID, $key, $value);
		}
		return new DataResponse(1);
	}

	/**
	 * @NoAdminRequired
	 *
	 * @param string $login
	 * @param string $password
	 * @return DataResponse
	 * @throws \OCP\PreConditionNotMetException
	 */
	public function oauthConnect(string $login = '', string $password = ''): DataResponse {
		$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
		$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');

		$result = $this->suitecrmAPIService->requestOAuthAccessToken($suitecrmUrl, [
			'client_id' => $clientID,
			'client_secret' => $clientSecret,
			'username' => $login,
			'password' => $password,
			'grant_type' => 'password'
		], 'POST');
		if (isset($result['access_token'], $result['refresh_token'])) {
			$accessToken = $result['access_token'];
			$this->config->setUserValue($this->userId, Application::APP_ID, 'token', $accessToken);
			$refreshToken = $result['refresh_token'];
			$this->config->setUserValue($this->userId, Application::APP_ID, 'refresh_token', $refreshToken);

			$filter = urlencode('filter[user_name][eq]') . '=' . urlencode($login);
			$info = $this->suitecrmAPIService->request(
				$suitecrmUrl, $accessToken, $this->userId, 'module/Users?' . $filter
			);
			$userName = $login;
			$userId = '';
			if (isset($info['data'])) {
				foreach ($info['data'] as $user) {
					if (isset($user['attributes'], $user['attributes']['user_name'], $user['attributes']['full_name'])
						&& $user['attributes']['user_name'] === $login) {
						$userName = $user['attributes']['full_name'];
						$userId = $user['id'];
						break;
					}
				}
			}
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_name', $userName);
			$this->config->setUserValue($this->userId, Application::APP_ID, 'user_id', $userId);
			return new DataResponse(['user_name' => $userName]);
		} else {
			return new DataResponse(['error' => 'Invalid login/password'], 401);
		}
	}
}
