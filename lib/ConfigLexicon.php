<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2025 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch;

use OCP\Config\Lexicon\Entry;
use OCP\Config\Lexicon\ILexicon;
use OCP\Config\Lexicon\Strictness;
use OCP\Config\ValueType;

class ConfigLexicon implements ILexicon {
	public const MEILISEARCH_HOST = 'meilisearch_host';
	public const MEILISEARCH_INDEX = 'meilisearch_index';
	public const MEILISEARCH_API_KEY = 'meilisearch_api_key';

	public function getStrictness(): Strictness {
		return Strictness::NOTICE;
	}

	public function getAppConfigs(): array {
		return [
			new Entry(key: self::MEILISEARCH_HOST, type: ValueType::STRING, defaultRaw: '', definition: 'Address of the Meilisearch server', lazy: true),
			new Entry(key: self::MEILISEARCH_INDEX, type: ValueType::STRING, defaultRaw: '', definition: 'Name of the index on Meilisearch', lazy: true),
			new Entry(key: self::MEILISEARCH_API_KEY, type: ValueType::STRING, defaultRaw: '', definition: 'API key for Meilisearch authentication', lazy: true, sensitive: true),
		];
	}

	public function getUserConfigs(): array {
		return [];
	}
}
