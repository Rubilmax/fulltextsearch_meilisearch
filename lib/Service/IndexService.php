<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Service;

use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
use OCA\FullTextSearch_Meilisearch\Exceptions\AccessIsEmptyException;
use OCA\FullTextSearch_Meilisearch\Exceptions\ConfigurationException;
use OCA\FullTextSearch_Meilisearch\Tools\Traits\TArrayTools;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use Psr\Log\LoggerInterface;

class IndexService {

	use TArrayTools;

	public function __construct(
		private IndexMappingService $indexMappingService,
		private LoggerInterface $logger
	) {
	}


	/**
	 * @param Client $client
	 *
	 * @return bool
	 * @throws ConfigurationException
	 */
	public function testIndex(Client $client): bool {
		try {
			$client->getIndex($this->indexMappingService->getIndexName());
			return true;
		} catch (ApiException) {
			return false;
		}
	}


	/**
	 * @param Client $client
	 *
	 * @throws ConfigurationException
	 */
	public function initializeIndex(Client $client): void {
		$indexName = $this->indexMappingService->getIndexName();
		$creationTask = [];

		try {
			$client->getIndex($indexName);
		} catch (ApiException) {
			$creationTask = $client->createIndex($indexName, ['primaryKey' => 'id']);
		}
		$this->waitForTaskCompletion($client, $creationTask);

		$this->indexMappingService->configureIndexSettings($client);
	}


	/**
	 * @param Client $client
	 * @param string $providerId
	 *
	 * @throws ConfigurationException
	 */
	public function resetIndex(Client $client, string $providerId): void {
		try {
			$index = $client->index($this->indexMappingService->getIndexName());
			$index->deleteDocuments(['filter' => "provider = '" . $this->escapeFilterValue($providerId) . "'"]);
		} catch (ApiException $e) {
			$this->logger->error('reset index', ['exception' => $e]);
		}
	}


	/**
	 * @param Client $client
	 *
	 * @throws ConfigurationException
	 */
	public function resetIndexAll(Client $client): void {
		try {
			$client->deleteIndex($this->indexMappingService->getIndexName());
		} catch (ApiException $e) {
			$this->logger->warning($e->getMessage(), ['exception' => $e]);
		}
	}


	/**
	 * @param Client $client
	 * @param IIndex $index
	 *
	 * @throws ConfigurationException
	 */
	public function deleteIndex(Client $client, IIndex $index): void {
		$this->indexMappingService->indexDocumentRemove(
			$client,
			$index->getProviderId(),
			$index->getDocumentId()
		);
	}


	/**
	 * @param Client $client
	 * @param IIndexDocument $document
	 *
	 * @return array
	 * @throws ConfigurationException
	 * @throws AccessIsEmptyException
	 */
	public function indexDocument(Client $client, IIndexDocument $document): array {
		$result = [];
		$index = $document->getIndex();
		if ($index->isStatus(IIndex::INDEX_REMOVE)) {
			$this->indexMappingService->indexDocumentRemove(
				$client, $document->getProviderId(), $document->getId()
			);
		} else if ($index->isStatus(IIndex::INDEX_OK) && !$index->isStatus(IIndex::INDEX_CONTENT)
				   && !$index->isStatus(IIndex::INDEX_META)) {
			$result = $this->indexMappingService->indexDocumentUpdate($client, $document);
		} else {
			$result = $this->indexMappingService->indexDocumentNew($client, $document);
		}

		return $result;
	}


	/**
	 * @param IIndex $index
	 * @param array $result
	 *
	 * @return IIndex
	 */
	public function parseIndexResult(IIndex $index, array $result): IIndex {
		$index->setLastIndex();

		if (array_key_exists('exception', $result)) {
			$exceptionMessage = (string)($result['exception'] ?? 'indexing exception');
			$index->setStatus(IIndex::INDEX_FAILED);
			$index->addError(
				$this->get('message', $result, $exceptionMessage),
				'',
				IIndex::ERROR_SEV_3
			);

			return $index;
		}

		if ($index->getErrorCount() === 0) {
			$index->setStatus(IIndex::INDEX_DONE);
		}

		return $index;
	}

	private function escapeFilterValue(string $value): string {
		return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
	}

	private function waitForTaskCompletion(Client $client, array $task): void {
		if ($task === []) {
			return;
		}

		$taskUid = $task['taskUid'] ?? $task['uid'] ?? null;
		if (!is_scalar($taskUid) || !is_numeric((string)$taskUid)) {
			return;
		}

		$client->waitForTask((int)$taskUid, 30000, 100);
	}
}
