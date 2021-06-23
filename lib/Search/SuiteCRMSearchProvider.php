<?php

declare(strict_types=1);

/**
 * @copyright Copyright (c) 2020, Julien Veyssier
 *
 * @author Julien Veyssier <eneiluj@posteo.net>
 *
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\SuiteCRM\Search;

use OCA\SuiteCRM\Service\SuiteCRMAPIService;
use OCA\SuiteCRM\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Search\IProvider;
use OCP\Search\ISearchQuery;
use OCP\Search\SearchResult;

class SuiteCRMSearchProvider implements IProvider {

	/** @var IAppManager */
	private $appManager;

	/** @var IL10N */
	private $l10n;

	/** @var IURLGenerator */
	private $urlGenerator;
	/**
	 * @var IConfig
	 */
	private $config;
	/**
	 * @var SuiteCRMAPIService
	 */
	private $service;

	/**
	 * CospendSearchProvider constructor.
	 *
	 * @param IAppManager $appManager
	 * @param IL10N $l10n
	 * @param IConfig $config
	 * @param IURLGenerator $urlGenerator
	 * @param SuiteCRMAPIService $service
	 */
	public function __construct(IAppManager $appManager,
								IL10N $l10n,
								IConfig $config,
								IURLGenerator $urlGenerator,
								SuiteCRMAPIService $service) {
		$this->appManager = $appManager;
		$this->l10n = $l10n;
		$this->config = $config;
		$this->urlGenerator = $urlGenerator;
		$this->service = $service;
	}

	/**
	 * @inheritDoc
	 */
	public function getId(): string {
		return 'suitecrm-search';
	}

	/**
	 * @inheritDoc
	 */
	public function getName(): string {
		return $this->l10n->t('SuiteCRM');
	}

	/**
	 * @inheritDoc
	 */
	public function getOrder(string $route, array $routeParameters): int {
		if (strpos($route, Application::APP_ID . '.') === 0) {
			// Active app, prefer SuiteCRM results
			return -1;
		}

		return 20;
	}

	/**
	 * @inheritDoc
	 */
	public function search(IUser $user, ISearchQuery $query): SearchResult {
		if (!$this->appManager->isEnabledForUser(Application::APP_ID, $user)) {
			return SearchResult::complete($this->getName(), []);
		}

		$limit = $query->getLimit();
		$term = $query->getTerm();
		$offset = $query->getCursor();
		$offset = $offset ? intval($offset) : 0;

//		$theme = $this->config->getUserValue($user->getUID(), 'accessibility', 'theme', '');
		$thumbnailUrl = $this->urlGenerator->imagePath(Application::APP_ID, 'app-color.svg');

		$suitecrmUrl = $this->config->getAppValue(Application::APP_ID, 'oauth_instance_url');
		$accessToken = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'token');

		$searchEnabled = $this->config->getUserValue($user->getUID(), Application::APP_ID, 'search_enabled', '0') === '1';
		if ($accessToken === '' || !$searchEnabled) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$searchResults = $this->service->search($suitecrmUrl, $accessToken, $user->getUID(), $term, $offset, $limit);

		if (isset($searchResults['error'])) {
			return SearchResult::paginated($this->getName(), [], 0);
		}

		$formattedResults = array_map(function (array $entry) use ($thumbnailUrl, $suitecrmUrl): SuiteCRMSearchResultEntry {
			return new SuiteCRMSearchResultEntry(
				$this->getThumbnailUrl($entry, $thumbnailUrl),
				$this->getMainText($entry),
				$this->getSubline($entry),
				$this->getLinkToSuiteCRM($entry, $suitecrmUrl),
				'',
				false
			);
		}, $searchResults);

		return SearchResult::paginated(
			$this->getName(),
			$formattedResults,
			$offset + $limit
		);
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getMainText(array $entry): string {
		if ($entry['type'] === 'contact') {
			return $entry['attributes']['full_name'];
		} elseif ($entry['type'] === 'account') {
			return $entry['attributes']['name'];
		} elseif ($entry['type'] === 'lead') {
			return $entry['attributes']['full_name'];
		} elseif ($entry['type'] === 'opportunity') {
			return $entry['attributes']['name'];
		} elseif ($entry['type'] === 'case') {
			return $entry['attributes']['name'];
		}
		return '';
	}

	/**
	 * @param array $entry
	 * @return string
	 */
	protected function getSubline(array $entry): string {
		if ($entry['type'] === 'contact') {
			return '👤 ' . $this->l10n->t('Contact');
		} elseif ($entry['type'] === 'account') {
			return '🛡 ' . $this->l10n->t('Account');
		} elseif ($entry['type'] === 'lead') {
			return '💥 ' . $this->l10n->t('Lead');
		} elseif ($entry['type'] === 'opportunity') {
			return '💡 ' . $this->l10n->t('Opportunity')
				. ' (' . $entry['attributes']['amount'] . ' ' . ($entry['attributes']['currency_symbol'] ?? $entry['attributes']['currency_name']) . ')';
		} elseif ($entry['type'] === 'case') {
			return '📁 ' . $this->l10n->t('Case');
		}
		return '';
	}

	/**
	 * @param array $entry
	 * @param string $url
	 * @return string
	 */
	protected function getLinkToSuiteCRM(array $entry, string $url): string {
		if ($entry['type'] === 'contact') {
			return $url . '/index.php?module=Contacts&action=DetailView&record=' . $entry['id'];
		} elseif ($entry['type'] === 'account') {
			return $url . '/index.php?module=Accounts&action=DetailView&record=' . $entry['id'];
		} elseif ($entry['type'] === 'lead') {
			return $url . '/index.php?module=Leads&action=DetailView&record=' . $entry['id'];
		} elseif ($entry['type'] === 'opportunity') {
			return $url . '/index.php?module=Opportunities&action=DetailView&record=' . $entry['id'];
		} elseif ($entry['type'] === 'case') {
			return $url . '/index.php?module=Cases&action=DetailView&record=' . $entry['id'];
		}
		return '';
	}

	/**
	 * @param array $entry
	 * @param string $thumbnailUrl
	 * @return string
	 */
	protected function getThumbnailUrl(array $entry, string $thumbnailUrl): string {
		return $thumbnailUrl;
	}
}
