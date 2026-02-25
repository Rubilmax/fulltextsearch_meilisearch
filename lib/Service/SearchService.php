<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Service;


use Exception;
use Meilisearch\Client;
use OC\FullTextSearch\Model\DocumentAccess;
use OC\FullTextSearch\Model\IndexDocument;
use OCA\FullTextSearch_Meilisearch\Exceptions\ConfigurationException;
use OCA\FullTextSearch_Meilisearch\Exceptions\SearchQueryGenerationException;
use OCA\FullTextSearch_Meilisearch\Tools\Traits\TArrayTools;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\IIndexDocument;
use OCP\FullTextSearch\Model\ISearchResult;
use Psr\Log\LoggerInterface;


class SearchService {
	use TArrayTools;

	public function __construct(
		private SearchMappingService $searchMappingService,
		private ConfigService $configService,
		private LoggerInterface $logger
	) {
	}

	/**
	 * @param Client $client
	 * @param ISearchResult $searchResult
	 * @param IDocumentAccess $access
	 *
	 * @throws Exception
	 */
	public function searchRequest(
		Client $client,
		ISearchResult $searchResult,
		IDocumentAccess $access
	): void {
		try {
			$this->logger->debug('New search request', ['searchResult' => $searchResult]);
			$query = $this->searchMappingService->generateSearchQuery(
				$searchResult->getRequest(), $access, $searchResult->getProvider()
																   ->getId()
			);
		} catch (SearchQueryGenerationException) {
			return;
		}

		try {
			$this->logger->debug('Searching Meilisearch', ['params' => $query['params'] ?? []]);
			$index = $client->index($this->configService->getMeilisearchIndex());
			$result = $index->search($query['query'], $query['params']);
		} catch (Exception $e) {
			$this->logger->debug(
				'exception while searching',
				[
					'exception' => $e,
					'searchResult.Request' => $searchResult->getRequest(),
					'query' => $query
				]
			);
			throw $e;
		}

		$raw = $result->getRaw();
		$this->logger->debug('result from Meilisearch', ['result' => $raw]);
		$this->updateSearchResult($searchResult, $raw);

		foreach ($raw['hits'] as $entry) {
			$searchResult->addDocument($this->parseSearchEntry($entry, $access->getViewerId()));
		}

		$this->logger->debug('Search Result', ['searchResult' => $searchResult]);
	}


	/**
	 * @param Client $client
	 * @param string $providerId
	 * @param string $documentId
	 *
	 * @return IIndexDocument
	 * @throws ConfigurationException
	 */
	public function getDocument(
		Client $client,
		string $providerId,
		string $documentId
	): IIndexDocument {
		$docId = $this->searchMappingService->getDocumentQuery($providerId, $documentId);
		$index = $client->index($this->configService->getMeilisearchIndex());
		$result = $index->getDocument($docId);

		$access = new DocumentAccess($result['owner']);
		$access->setUsers($result['users']);
		$access->setGroups($result['groups']);
		$access->setCircles($result['circles']);
		$access->setLinks($result['links']);

		$doc = new IndexDocument($providerId, $documentId);
		$doc->setAccess($access);
		$doc->setMetaTags($result['metatags']);
		$doc->setSubTags($result['subtags']);
		$doc->setTags($result['tags']);
		$doc->setHash($result['hash']);
		$doc->setModifiedTime($result['lastModified'] ?? 0);
		$doc->setSource($result['source']);
		$doc->setTitle($result['title']);
		$doc->setParts($result['parts']);

		$this->getDocumentInfos($doc, $result);

		$content = $this->get('content', $result, '');
		$doc->setContent($content);

		return $doc;
	}


	/**
	 * @param IndexDocument $index
	 * @param array $source
	 */
	private function getDocumentInfos(IndexDocument $index, array $source): void {
		$ak = array_keys($source);
		foreach ($ak as $k) {
			if (str_starts_with($k, 'info_')) {
				continue;
			}
			$value = $source[$k];
			if (is_array($value)) {
				$index->setInfoArray($k, $value);
				continue;
			}

			if (is_bool($value)) {
				$index->setInfoBool($k, $value);
				continue;
			}

			if (is_numeric($value)) {
				$index->setInfoInt($k, (int)$value);
				continue;
			}

			$index->setInfo($k, (string)$value);
		}
	}


	/**
	 * @param ISearchResult $searchResult
	 * @param array $result
	 */
	private function updateSearchResult(ISearchResult $searchResult, array $result): void {
		$searchResult->setRawResult(json_encode($result));
		$searchResult->setTotal($result['estimatedTotalHits'] ?? $result['totalHits'] ?? 0);
		$searchResult->setMaxScore(0);
		$searchResult->setTime($result['processingTimeMs'] ?? 0);
		$searchResult->setTimedOut(false);
	}


	/**
	 * @param array $entry
	 * @param string $viewerId
	 *
	 * @return IIndexDocument
	 */
	private function parseSearchEntry(array $entry, string $viewerId): IIndexDocument {
		$access = new DocumentAccess();
		$access->setViewerId($viewerId);

		[$providerId, $documentId] = IndexMappingService::decodeDocumentId($entry['id']);
		$document = new IndexDocument($providerId, $documentId);
		$document->setAccess($access);
		$document->setHash($this->get('hash', $entry));
		$document->setModifiedTime($this->getInt('lastModified', $entry));
		$document->setScore('0');
		$document->setSource($this->get('source', $entry));
		$document->setTitle($this->get('title', $entry));

		$formatted = $entry['_formatted'] ?? [];
		$document->setExcerpts($this->parseSearchEntryExcerpts($formatted));

		return $document;
	}


	/**
	 * Parse excerpts from Meilisearch _formatted response.
	 *
	 * @param array $formatted
	 * @return array
	 */
	private function parseSearchEntryExcerpts(array $formatted): array {
		$result = [];
		$fields = ['content', 'title'];
		foreach ($fields as $field) {
			if (isset($formatted[$field]) && $formatted[$field] !== '') {
				$result[] = [
					'source' => $field,
					'excerpt' => $formatted[$field]
				];
			}
		}

		return $result;
	}
}
