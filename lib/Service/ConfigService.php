<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Service;


use OCA\FullTextSearch_Meilisearch\ConfigLexicon;
use OCA\FullTextSearch_Meilisearch\Exceptions\ConfigurationException;
use OCP\AppFramework\Services\IAppConfig;


class ConfigService {
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}

	public function getConfig(): array {
		return [
			ConfigLexicon::MEILISEARCH_HOST => $this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_HOST),
			ConfigLexicon::MEILISEARCH_INDEX => $this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_INDEX),
			ConfigLexicon::MEILISEARCH_API_KEY => $this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_API_KEY),
		];
	}

	public function setConfig(array $save): void {
		foreach (array_keys($save) as $k) {
			switch ($k) {
				case ConfigLexicon::MEILISEARCH_HOST:
				case ConfigLexicon::MEILISEARCH_INDEX:
				case ConfigLexicon::MEILISEARCH_API_KEY:
					$this->appConfig->setAppValueString($k, $save[$k]);
					break;
			}
		}
	}

	public function getMeilisearchIndex(): string {
		$index = $this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_INDEX);
		if ($index === '') {
			throw new ConfigurationException('Your MeilisearchPlatform is not configured properly');
		}

		return $index;
	}

	public function getMeilisearchHost(): string {
		$host = $this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_HOST);
		if ($host === '') {
			throw new ConfigurationException('Your MeilisearchPlatform is not configured properly');
		}

		return $host;
	}

	public function getMeilisearchApiKey(): string {
		return $this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_API_KEY);
	}

	public function checkConfig(array $data): bool {
		return true;
	}
}
