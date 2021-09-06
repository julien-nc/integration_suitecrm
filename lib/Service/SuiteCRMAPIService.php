<?php
/**
 * Nextcloud - suitecrm
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Julien Veyssier
 * @copyright Julien Veyssier 2020
 */

namespace OCA\SuiteCRM\Service;

use DateInterval;
use DateTime;
use Exception;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\IUser;
use OCP\Http\Client\IClientService;
use OCP\Notification\IManager as INotificationManager;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;

use OCA\SuiteCRM\AppInfo\Application;

class SuiteCRMAPIService {
	/**
	 * @var string
	 */
	private $appName;
	/**
	 * @var IUserManager
	 */
	private $userManager;
	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var IL10N
	 */
	private $l10n;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var INotificationManager
	 */
	private $notificationManager;
	/**
	 * @var \OCP\Http\Client\IClient
	 */
	private $client;

	/**
	 * Service to make requests to SuiteCRM v3 (JSON) API
	 */
	public function __construct (string $appName,
								IUserManager $userManager,
								LoggerInterface $logger,
								IL10N $l10n,
								IConfig $config,
								INotificationManager $notificationManager,
								IClientService $clientService) {
		$this->appName = $appName;
		$this->userManager = $userManager;
		$this->logger = $logger;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->notificationManager = $notificationManager;
		$this->client = $clientService->newClient();
	}

	/**
	 * triggered by a cron job
	 * notifies user of their number of new tickets
	 *
	 * @return void
	 */
	public function checkAlerts(): void {
		$this->userManager->callForAllUsers(function (IUser $user) {
			$this->checkAlertsForUser($user->getUID());
		});
	}

	/**
	 * @param string $userId
	 * @return void
	 */
	private function checkAlertsForUser(string $userId): void {
		$accessToken = $this->config->getUserValue($userId, Application::APP_ID, 'token');
		$notificationEnabled = ($this->config->getUserValue($userId, Application::APP_ID, 'notification_enabled', '0') === '1');
		if ($accessToken && $notificationEnabled) {
			$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
			$lastReminderCheck = (int) $this->config->getUserValue($userId, Application::APP_ID, 'last_reminder_check', '0');
			if ($lastReminderCheck === 0) {
				// back one week
				$d = new DateTime();
				$d->sub(new DateInterval('P1W'));
				$lastReminderCheck = $d->getTimestamp();
			}

			$tsNow = (new DateTime())->getTimestamp();
			//error_log('notif limits: '.$lastReminderCheck.' -> '.$tsNow);
			$reminders = $this->getReminders($suitecrmUrl, $accessToken, $userId, $lastReminderCheck, $tsNow);
			if (!isset($reminders['error']) && count($reminders) > 0) {
				foreach ($reminders as $reminder) {
					// error_log('reminder found: '.$reminder['title']);
					if ($reminder['real_reminder_timestamp'] > $lastReminderCheck) {
						$lastReminderCheck = $reminder['real_reminder_timestamp'];
					}
					$module = $reminder['attributes']['related_event_module'];
					$elemId = $reminder['attributes']['related_event_module_id'];
					$this->sendNCNotification($userId, 'reminder', [
						'type' => $module,
						'link' => $suitecrmUrl . '/index.php?module=' . $module . '&action=DetailView&record=' . $elemId,
						'title' => $reminder['title'],
						'event_timestamp' => $reminder['attributes']['date_willexecute'],
					]);
				}
				// update last check date
				$this->config->setUserValue($userId, Application::APP_ID, 'last_reminder_check', $lastReminderCheck);
			}
		}
	}

	/**
	 * @param string $userId
	 * @param string $subject
	 * @param array $params
	 * @return void
	 */
	private function sendNCNotification(string $userId, string $subject, array $params): void {
		$manager = $this->notificationManager;
		$notification = $manager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new DateTime())
			->setObject('dum', 'dum')
			->setSubject($subject, $params);

		$manager->notify($notification);
	}

	/**
	 * Get reminders about stuff assigned to connected user:
	 * - related to call/meeting assigned to the user
	 * - for an event in the future
	 * - not already read
	 * - reminder set after $since (if defined)
	 *
	 * @param string $url
	 * @param string $accessToken
	 * @param string $userId
	 * @param int|null $reminderSinceTs
	 * @param int|null $reminderUntilTs
	 * @param int|null $eventSinceTs
	 * @param int|null $eventUntilTs
	 * @param ?int $limit
	 * @return array
	 */
	public function getReminders(string $url, string $accessToken, string $userId,
								?int $reminderSinceTs = null, ?int $reminderUntilTs = null,
								?int $eventSinceTs = null, ?int $eventUntilTs = null,
								?int $limit = null): array {
		$scrmUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$filters = [];
		if (!is_null($reminderSinceTs)) {
			$filters[] = 'filter[date_willexecute][gt]=' . $reminderSinceTs;
		}
		if (!is_null($reminderUntilTs)) {
			// date_willexecute is actually the date of the event, not the reminder one...
			// so we make sure we get the max reminder popup_timer
			$filters[] = 'filter[date_willexecute][lt]=' . ($reminderUntilTs + (60 * 60 * 24));
		}
		if (!is_null($eventSinceTs)) {
			$filters[] = 'filter[date_willexecute][gt]=' . $eventSinceTs;
		}
		if (!is_null($eventUntilTs)) {
			$filters[] = 'filter[date_willexecute][lt]=' . $eventUntilTs;
		}
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Reminders?' . implode('&filter[operator]=and&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		// get target date for calls and meetings
//		$tsNow = (new DateTime())->getTimestamp();
		$finalResults = [];
		foreach ($result['data'] as $reminder) {
			// apply time filter on real reminder date
			$realReminderTs = (int) $reminder['attributes']['date_willexecute'] - (int) $reminder['attributes']['timer_popup'];
			if (!is_null($reminderSinceTs) && $realReminderTs <= $reminderSinceTs) {
				continue;
			}
			if (!is_null($reminderUntilTs) && $realReminderTs >= $reminderUntilTs) {
				continue;
			}
			$reminder['real_reminder_timestamp'] = $realReminderTs;
			// is it assigned to user?
			// get related element
			$module = $reminder['attributes']['related_event_module'];
			$elemId = $reminder['attributes']['related_event_module_id'];
			$elem = $this->request(
				$url, $accessToken, $userId, 'module/' . $module . '/' . $elemId
			);
			if (!isset($elem['error'])
				&& isset($elem['data'], $elem['data']['attributes'], $elem['data']['attributes']['assigned_user_id'])
				&& $elem['data']['attributes']['assigned_user_id'] === $scrmUserId
			) {
				$reminder['title'] = $elem['data']['attributes']['name'];
				$finalResults[] = $reminder;
			}
		}

		usort($finalResults, function($a, $b) {
			$ta = $a['real_reminder_timestamp'];
			$tb = $b['real_reminder_timestamp'];
			return ($ta < $tb) ? -1 : 1;
		});
		if ($limit) {
			$finalResults = array_slice($finalResults, 0, $limit);
		}
		return array_values($finalResults);
	}

	/**
	 * !!!! problem with alerts: they appear after the popup has been shown in UI so we don't see them if user didn't see them already...
	 * Get user alerts that are
	 * - assigned to the user
	 * - for an event in the future
	 * - not already read
	 * - reminder set after $since (if defined)
	 * Alerts are created once the reminder execution date is reached
	 *
	 * @param string $url
	 * @param string $accessToken
	 * @param string $userId
	 * @param ?int $sinceTs
	 * @param ?int $limit
	 * @return array
	 */
	public function getAlerts(string $url, string $accessToken, string $userId, ?int $sinceTs = null, ?int $limit = null): array {
		$scrmUserId = $this->config->getUserValue($userId, Application::APP_ID, 'user_id');
		$filters = [
			urlencode('filter[assigned_user_id][eq]') . '=' . urlencode($scrmUserId),
			urlencode('filter[is_read][eq]') . '=0',
		];
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Alerts?' . implode('&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		// get target date for calls and meetings
		$tsNow = (new DateTime())->getTimestamp();
		$finalAlerts = [];
		foreach ($result['data'] as $alert) {
			$urlRedirect = $alert['attributes']['url_redirect'];
			$isCall = preg_match('/module=Calls/', $urlRedirect);
			$isMeeting = preg_match('/module=Meetings/', $urlRedirect);
			$recordMatch = [];
			preg_match('/record=([a-z0-9\-]+)/', $urlRedirect, $recordMatch);
			if (($isCall || $isMeeting) && count($recordMatch) > 1) {
				$recordId = $recordMatch[1];
				$module = $isCall ? 'Calls' : 'Meetings';
				$elem = $this->request(
					$url, $accessToken, $userId, 'module/' . $module . '/' . $recordId
				);
				if (!isset($elem['error']) && isset($elem['data']) && isset($elem['data']['attributes']['date_start'])
				) {
					$tsElem = (new DateTime($elem['data']['attributes']['date_start']))->getTimestamp();
					if ($tsElem > $tsNow) {
						$alert['date_start'] = $elem['data']['attributes']['date_start'];
						$alert['type'] = $isCall ? 'call' : 'meeting';

						// get the related reminder
						$reminder = $this->request(
							$url, $accessToken, $userId, 'module/Reminders/' . $alert['attributes']['reminder_id']
						);
						if (isset($reminder['data'], $reminder['data']['attributes'], $reminder['data']['attributes']['date_willexecute'])) {
							$dateWillExecute = $reminder['data']['attributes']['date_willexecute'];
							$alert['date_willexecute'] = (int) $dateWillExecute;
							// finally add the alert
							$finalAlerts[] = $alert;
						}
					}
				}
			}
		}
		// filter by reminder execution date
		if (!is_null($sinceTs)) {
			$finalAlerts = array_filter($finalAlerts, function($elem) use ($sinceTs) {
				return $elem['date_willexecute'] > $sinceTs;
			});
		}
		// sort by reminder execution date
		usort($finalAlerts, function($a, $b) {
			$ta = $a['date_willexecute'];
			$tb = $b['date_willexecute'];
			return ($ta < $tb) ? -1 : 1;
		});
		if ($limit) {
			$finalAlerts = array_slice($finalAlerts, 0, $limit);
		}
		return array_values($finalAlerts);
	}

	/**
	 * @param string $url
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $query
	 * @param int $offset
	 * @param int $limit
	 * @return array
	 */
	public function search(string $url, string $accessToken, string $userId, string $query, int $offset = 0, int $limit = 5): array {
		$combinedResults = [];
		// contacts
		$filters = [
			'fields[Contacts]=name,first_name,last_name,full_name',
		];
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Contacts?' . implode('&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['data'] as $contact) {
			$fullName = $contact['attributes']['full_name'];
			if (preg_match('/' . $query . '/i', $fullName)) {
				$contact['type'] = 'contact';
				$combinedResults[] = $contact;
			}
		}
		// accounts
		$filters = [
			'fields[Accounts]=name',
		];
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Accounts?' . implode('&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['data'] as $account) {
			$name = $account['attributes']['name'];
			if (preg_match('/' . $query . '/i', $name)) {
				$account['type'] = 'account';
				$combinedResults[] = $account;
			}
		}
		// leads
		$filters = [
			'fields[Leads]=name,full_name',
		];
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Leads?' . implode('&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['data'] as $elem) {
			$name = $elem['attributes']['full_name'];
			if (preg_match('/' . $query . '/i', $name)) {
				$elem['type'] = 'lead';
				$combinedResults[] = $elem;
			}
		}
		// Opportunities
		$filters = [
			'fields[Opportunities]=name,amount,currency_symbol,currency_name',
		];
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Opportunities?' . implode('&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['data'] as $elem) {
			$name = $elem['attributes']['name'];
			if (preg_match('/' . $query . '/i', $name)) {
				$elem['type'] = 'opportunity';
				$combinedResults[] = $elem;
			}
		}
		// Cases
		$filters = [
			'fields[Cases]=name',
		];
		$result = $this->request(
			$url, $accessToken, $userId, 'module/Cases?' . implode('&', $filters)
		);
		if (isset($result['error'])) {
			return $result;
		}
		foreach ($result['data'] as $elem) {
			$name = $elem['attributes']['name'];
			if (preg_match('/' . $query . '/i', $name)) {
				$elem['type'] = 'case';
				$combinedResults[] = $elem;
			}
		}
		return array_slice($combinedResults, $offset, $limit);
	}

	/**
	 * authenticated request to get an image from suitecrm
	 *
	 * @param string $url
	 * @param string $accessToken
	 * @param string $suiteUserId
	 * @return string
	 */
	public function getSuiteCRMAvatar(string $url,
									string $accessToken,
									string $suiteUserId): string {
		$url = $url . '/index.php?entryPoint=download&id=' . urlencode($suiteUserId) . '_photo&type=Users';
		$options = [
			'headers' => [
				'Authorization'  => 'Bearer ' . $accessToken,
				'User-Agent' => 'Nextcloud SuiteCRM integration',
			]
		];
		return $this->client->get($url, $options)->getBody();
	}

	/**
	 * @param string $suitecrmUrl
	 * @param string $accessToken
	 * @param string $userId
	 * @param string $endPoint
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function request(string $suitecrmUrl, string $accessToken, string $userId,
							string $endPoint, array $params = [], string $method = 'GET'): array {
		try {
			$url = $suitecrmUrl . '/Api/index.php/V8/' . $endPoint;
			$options = [
				'headers' => [
					'Authorization'  => 'Bearer ' . $accessToken,
					'User-Agent' => 'Nextcloud SuiteCRM integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					// manage array parameters
					$paramsContent = '';
					foreach ($params as $key => $value) {
						if (is_array($value)) {
							foreach ($value as $oneArrayValue) {
								$paramsContent .= $key . '[]=' . urlencode($oneArrayValue) . '&';
							}
							unset($params[$key]);
						}
					}
					$paramsContent .= http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('Bad credentials')];
			} else {
				return json_decode($body, true);
			}
		} catch (ServerException | ClientException $e) {
			$response = $e->getResponse();
//			$body = (string) $response->getBody();
			// try to refresh token if it's invalid
			if ($response->getStatusCode() === 401) {
				$this->logger->info('Trying to REFRESH the access token', ['app' => $this->appName]);
				$refreshToken = $this->config->getUserValue($userId, Application::APP_ID, 'refresh_token');
				$clientID = $this->config->getAppValue(Application::APP_ID, 'client_id');
				$clientSecret = $this->config->getAppValue(Application::APP_ID, 'client_secret');
				// try to refresh the token
				$result = $this->requestOAuthAccessToken($suitecrmUrl, [
					'client_id' => $clientID,
					'client_secret' => $clientSecret,
					'grant_type' => 'refresh_token',
					'refresh_token' => $refreshToken,
				], 'POST');
				if (isset($result['access_token'], $result['refresh_token'])) {
					$accessToken = $result['access_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'token', $accessToken);
					$refreshToken = $result['refresh_token'];
					$this->config->setUserValue($userId, Application::APP_ID, 'refresh_token', $refreshToken);
					// retry the request with new access token
					return $this->request(
						$suitecrmUrl, $accessToken, $userId, $endPoint, $params, $method
					);
				}
			}
			$this->logger->warning('SuiteCRM API error : '.$e->getMessage(), ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}

	/**
	 * @param string $url
	 * @param array $params
	 * @param string $method
	 * @return array
	 */
	public function requestOAuthAccessToken(string $url, array $params = [], string $method = 'GET'): array {
		try {
			$url = $url . '/Api/access_token';
			$options = [
				'headers' => [
					'User-Agent'  => 'Nextcloud SuiteCRM integration',
				]
			];

			if (count($params) > 0) {
				if ($method === 'GET') {
					$paramsContent = http_build_query($params);
					$url .= '?' . $paramsContent;
				} else {
					$options['body'] = $params;
				}
			}

			if ($method === 'GET') {
				$response = $this->client->get($url, $options);
			} else if ($method === 'POST') {
				$response = $this->client->post($url, $options);
			} else if ($method === 'PUT') {
				$response = $this->client->put($url, $options);
			} else if ($method === 'DELETE') {
				$response = $this->client->delete($url, $options);
			} else {
				return ['error' => $this->l10n->t('Bad HTTP method')];
			}
			$body = $response->getBody();
			$respCode = $response->getStatusCode();

			if ($respCode >= 400) {
				return ['error' => $this->l10n->t('OAuth access token refused')];
			} else {
				return json_decode($body, true);
			}
		} catch (Exception $e) {
			$message = $e->getMessage();
			if (isset($params['password'])) {
				$message = str_replace($params['password'], '********', $message);
			}
			$this->logger->warning('SuiteCRM OAuth error : ' . $message, ['app' => $this->appName]);
			return ['error' => $e->getMessage()];
		}
	}
}
