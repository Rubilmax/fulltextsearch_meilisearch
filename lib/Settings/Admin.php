<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Settings;

use InvalidArgumentException;
use OCA\FullTextSearch_Meilisearch\ConfigLexicon;
use OCA\FullTextSearch_Meilisearch\Service\ConfigService;
use OCP\IL10N;
use OCP\IUser;
use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsFormWithHandlers;

class Admin implements IDeclarativeSettingsFormWithHandlers {

	public function __construct(
		private readonly ConfigService $configService,
		private readonly IL10N $l,
	) {
	}

	#[\Override]
	public function getSchema(): array {
		return [
			'id' => 'meilisearch',
			'priority' => 31,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'fulltextsearch',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_EXTERNAL,
			'title' => $this->l->t('Meilisearch'),
			'fields' => [
				[
					'id' => ConfigLexicon::MEILISEARCH_HOST,
					'title' => $this->l->t('Address of the Meilisearch server'),
					'type' => DeclarativeSettingsTypes::URL,
					'placeholder' => 'http://localhost:7700',
					'default' => '',
				],
				[
					'id' => ConfigLexicon::MEILISEARCH_INDEX,
					'title' => $this->l->t('Index'),
					'description' => $this->l->t('Name of your index.'),
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => ConfigLexicon::DEFAULT_MEILISEARCH_INDEX,
					'default' => ConfigLexicon::DEFAULT_MEILISEARCH_INDEX,
				],
				[
					'id' => ConfigLexicon::MEILISEARCH_API_KEY,
					'title' => $this->l->t('API Key'),
					'description' => $this->l->t('API key for authentication with Meilisearch.'),
					'type' => DeclarativeSettingsTypes::PASSWORD,
					'default' => '',
					'sensitive' => true,
				],
			],
		];
	}

	#[\Override]
	public function getValue(string $fieldId, IUser $user): mixed {
		$config = $this->configService->getConfig();
		if (!array_key_exists($fieldId, $config)) {
			throw new InvalidArgumentException($this->l->t('Unknown Meilisearch setting'));
		}

		return $config[$fieldId];
	}

	#[\Override]
	public function setValue(string $fieldId, mixed $value, IUser $user): void {
		if ($fieldId === ConfigLexicon::MEILISEARCH_API_KEY && $value === 'dummySecret') {
			return;
		}

		$data = [$fieldId => $value];
		if (!$this->configService->checkConfig($data)) {
			throw new InvalidArgumentException($this->l->t('Invalid Meilisearch setting'));
		}

		$this->configService->setConfig($data);
	}
}
