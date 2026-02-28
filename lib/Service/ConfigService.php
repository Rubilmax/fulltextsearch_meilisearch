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
					$value = $save[$k];
					if (!is_scalar($value)) {
						continue 2;
					}

					if ($k === ConfigLexicon::MEILISEARCH_HOST || $k === ConfigLexicon::MEILISEARCH_INDEX) {
						$value = trim((string)$value);
					}

					$this->appConfig->setAppValueString($k, (string)$value);
					break;
			}
		}
	}

	public function getMeilisearchIndex(): string {
		$index = trim($this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_INDEX));
		if ($index === '') {
			throw new ConfigurationException('Your MeilisearchPlatform is not configured properly');
		}

		return $index;
	}

	public function getMeilisearchHost(): string {
		$host = trim($this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_HOST));
		if ($host === '') {
			throw new ConfigurationException('Your MeilisearchPlatform is not configured properly');
		}

		return $host;
	}

	public function getMeilisearchApiKey(): string {
		return $this->appConfig->getAppValueString(ConfigLexicon::MEILISEARCH_API_KEY);
	}

	public function checkConfig(array $data): bool {
		foreach ($data as $key => $value) {
			if (!in_array(
				$key,
				[
					ConfigLexicon::MEILISEARCH_HOST,
					ConfigLexicon::MEILISEARCH_INDEX,
					ConfigLexicon::MEILISEARCH_API_KEY
				],
				true
			)) {
				return false;
			}

			if (!is_scalar($value)) {
				return false;
			}
		}

		if (array_key_exists(ConfigLexicon::MEILISEARCH_HOST, $data)) {
			$host = trim((string)$data[ConfigLexicon::MEILISEARCH_HOST]);
			if ($host !== '') {
				$parts = parse_url($host);
				if (!is_array($parts)) {
					return false;
				}

				$scheme = strtolower((string)($parts['scheme'] ?? ''));
				$allowedSchemes = ['http', 'https'];
				if (!in_array($scheme, $allowedSchemes, true)) {
					return false;
				}

				if (($parts['host'] ?? '') === '') {
					return false;
				}
			}
		}

		if (array_key_exists(ConfigLexicon::MEILISEARCH_INDEX, $data)) {
			$index = trim((string)$data[ConfigLexicon::MEILISEARCH_INDEX]);
			if ($index !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $index) !== 1) {
				return false;
			}
		}

		return true;
	}
}
