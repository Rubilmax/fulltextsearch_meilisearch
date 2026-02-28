<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Platform;


use Exception;
use Meilisearch\Client;
use Meilisearch\Exceptions\CommunicationException;
use OCA\FullTextSearch_Meilisearch\ConfigLexicon;
use OCA\FullTextSearch_Meilisearch\Exceptions\ClientException;
use OCA\FullTextSearch_Meilisearch\Exceptions\ConfigurationException;
use OCA\FullTextSearch_Meilisearch\Service\ConfigService;
use OCA\FullTextSearch_Meilisearch\Service\IndexService;
use OCA\FullTextSearch_Meilisearch\Service\SearchService;
use OCP\AppFramework\Services\IAppConfig;
use OCP\FullTextSearch\Exceptions\PlatformTemporaryException;
use OCP\FullTextSearch\IFullTextSearchPlatform;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndex;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\IRunner;
use OCP\FullTextSearch\Model\ISearchResult;
use Psr\Log\LoggerInterface;


class MeilisearchPlatform implements IFullTextSearchPlatform {

	private ?Client $client = null;
	private ?IRunner $runner = null;

	public function __construct(
		private readonly IAppConfig $appConfig,
		private ConfigService $configService,
		private IndexService $indexService,
		private SearchService $searchService,
		private LoggerInterface $logger,
	) {
	}


	public function getId(): string {
		return 'meilisearch';
	}


	public function getName(): string {
		return 'Meilisearch';
	}


	/**
	 * @return array
	 * @throws ConfigurationException
	 */
	public function getConfiguration(): array {
		$result = $this->configService->getConfig();

		if (!empty($result[ConfigLexicon::MEILISEARCH_API_KEY])) {
			$result[ConfigLexicon::MEILISEARCH_API_KEY] = '********';
		}

		return $result;
	}


	/**
	 * @param IRunner $runner
	 */
	public function setRunner(IRunner $runner) {
		$this->runner = $runner;
	}


	/**
	 * Called when loading the platform.
	 *
	 * @throws ConfigurationException
	 */
	public function loadPlatform() {
		$this->loadClientLibrary();

		$host = $this->configService->getMeilisearchHost();
		$apiKey = $this->configService->getMeilisearchApiKey();
		$this->client = new Client($host, $apiKey);
	}


	/**
	 * @return bool
	 */
	public function testPlatform(): bool {
		try {
			$this->getClient()->health();
			return true;
		} catch (Exception) {
			return false;
		}
	}


	/**
	 * @throws ConfigurationException
	 */
	public function initializeIndex() {
		$this->indexService->initializeIndex($this->getClient());
	}


	/**
	 * @param string $providerId
	 *
	 * @throws ConfigurationException
	 */
	public function resetIndex(string $providerId) {
		if ($providerId === 'all') {
			$this->indexService->resetIndexAll($this->getClient());
		} else {
			$this->indexService->resetIndex($this->getClient(), $providerId);
		}
	}


	/**
	 * @param IIndexDocument $document
	 *
	 * @return IIndex
	 */
	public function indexDocument(IIndexDocument $document): IIndex {
		$document->initHash();
		$indexingException = null;
		try {
			$result = $this->indexService->indexDocument($this->getClient(), $document);
			$index = $this->indexService->parseIndexResult($document->getIndex(), $result);

			$this->updateNewIndexResult(
				$document->getIndex(), $this->encodeJson($result), 'ok',
				IRunner::RESULT_TYPE_SUCCESS
			);

			return $index;
		} catch (CommunicationException) {
			throw new PlatformTemporaryException();
		} catch (Exception $e) {
			$indexingException = $e;
			$this->manageIndexErrorException($document, $e);
		}

		if ($indexingException === null) {
			return $document->getIndex();
		}

		try {
			$result = $this->indexDocumentError($document, $indexingException);
			$index = $this->indexService->parseIndexResult($document->getIndex(), $result);

			$this->updateNewIndexResult(
				$document->getIndex(), $this->encodeJson($result), 'ok',
				IRunner::RESULT_TYPE_WARNING
			);

			return $index;
		} catch (Exception $e) {
			$this->updateNewIndexResult(
				$document->getIndex(), '', 'fail',
				IRunner::RESULT_TYPE_FAIL
			);
			$this->manageIndexErrorException($document, $e);
		}

		return $document->getIndex();
	}


	/**
	 * @param IIndexDocument $document
	 * @param Exception $e
	 *
	 * @return array
	 * @throws Exception
	 */
	private function indexDocumentError(IIndexDocument $document, Exception $e): array {
		$this->updateRunnerAction('indexDocumentWithoutContent', true);
		$document->setContent('');

		return $this->indexService->indexDocument($this->getClient(), $document);
	}


	/**
	 * @param IIndexDocument $document
	 * @param Exception $e
	 */
	private function manageIndexErrorException(IIndexDocument $document, Exception $e) {
		$message = $e->getMessage();
		$document->getIndex()
				 ->addError($message, get_class($e), IIndex::ERROR_SEV_3);
		$this->updateNewIndexError(
			$document->getIndex(), $message, get_class($e), IIndex::ERROR_SEV_3
		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function deleteIndexes(array $indexes) {
		foreach ($indexes as $index) {
			try {
				$this->indexService->deleteIndex($this->getClient(), $index);
				$this->updateNewIndexResult($index, 'index deleted', 'success', IRunner::RESULT_TYPE_SUCCESS);
			} catch (Exception $e) {
				$this->updateNewIndexResult(
					$index, 'index not deleted', 'issue while deleting index', IRunner::RESULT_TYPE_WARNING
				);
			}
		}
	}


	/**
	 * {@inheritdoc}
	 * @throws Exception
	 */
	public function searchRequest(ISearchResult $result, IDocumentAccess $access) {
		$this->searchService->searchRequest($this->getClient(), $result, $access);
	}


	/**
	 * @param string $providerId
	 * @param string $documentId
	 *
	 * @return IIndexDocument
	 * @throws ConfigurationException
	 */
	public function getDocument(string $providerId, string $documentId): IIndexDocument {
		return $this->searchService->getDocument($this->getClient(), $providerId, $documentId);
	}


	/**
	 * @param string $action
	 * @param bool $force
	 *
	 * @throws Exception
	 */
	private function updateRunnerAction(string $action, bool $force = false) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->updateAction($action, $force);
	}


	/**
	 * @param IIndex $index
	 * @param string $message
	 * @param string $exception
	 * @param int $sev
	 */
	private function updateNewIndexError(IIndex $index, string $message, string $exception, int $sev
	) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->newIndexError($index, $message, $exception, $sev);
	}


	/**
	 * @param IIndex $index
	 * @param string $message
	 * @param string $status
	 * @param int $type
	 */
	private function updateNewIndexResult(IIndex $index, string $message, string $status, int $type) {
		if ($this->runner === null) {
			return;
		}

		$this->runner->newIndexResult($index, $message, $status, $type);
	}

	/**
	 * @param array $data
	 *
	 * @return string
	 */
	private function encodeJson(array $data): string {
		$json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			return '{}';
		}

		return $json;
	}


	/**
	 * @return Client
	 * @throws ClientException
	 */
	private function getClient(): Client {
		if ($this->client === null) {
			throw new ClientException('platform not loaded');
		}

		return $this->client;
	}

	private function loadClientLibrary(): void {
		if (class_exists(Client::class)) {
			return;
		}

		$autoLoad = dirname(__DIR__, 2) . '/vendor/autoload.php';
		if (file_exists($autoLoad)) {
			include_once $autoLoad;
		}

		if (!class_exists(Client::class)) {
			throw new ClientException(
				'Meilisearch client library not found. Please install app dependencies (composer install) or use a packaged release.'
			);
		}
	}
}
