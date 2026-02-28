<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Service;


use Exception;
use Meilisearch\Client;
use Meilisearch\Exceptions\ApiException;
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

		$hits = $raw['hits'] ?? [];
		if (!is_array($hits)) {
			$hits = [];
		}

		foreach ($hits as $entry) {
			if (!is_array($entry) || !array_key_exists('id', $entry)) {
				continue;
			}

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
		$index = $client->index($this->configService->getMeilisearchIndex());
		$result = null;
		$docIds = IndexMappingService::getDocumentIdCandidates($providerId, $documentId);
		foreach ($docIds as $docId) {
			try {
				$result = $index->getDocument($docId);
				break;
			} catch (ApiException $e) {
				$isLastCandidate = ($docId === $docIds[array_key_last($docIds)]);
				if ($isLastCandidate || $e->getCode() !== 404) {
					throw $e;
				}
			}
		}

		if (!is_array($result)) {
			$result = [];
		}

		$access = new DocumentAccess((string)($result['owner'] ?? ''));
		$access->setUsers((array)($result['users'] ?? []));
		$access->setGroups((array)($result['groups'] ?? []));
		$access->setCircles((array)($result['circles'] ?? []));
		$access->setLinks((array)($result['links'] ?? []));

		$doc = new IndexDocument($providerId, $documentId);
		$doc->setAccess($access);
		$doc->setMetaTags((array)($result['metatags'] ?? []));
		$doc->setSubTags((array)($result['subtags'] ?? []));
		$doc->setTags((array)($result['tags'] ?? []));
		$doc->setHash((string)($result['hash'] ?? ''));
		$doc->setModifiedTime((int)($result['lastModified'] ?? 0));
		$doc->setSource((string)($result['source'] ?? ''));
		$doc->setTitle((string)($result['title'] ?? ''));
		$doc->setParts((array)($result['parts'] ?? []));

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
		$searchResult->setRawResult($this->encodeJson($result));
		$searchResult->setTotal((int)($result['estimatedTotalHits'] ?? $result['totalHits'] ?? 0));
		$searchResult->setMaxScore(0);
		$searchResult->setTime((int)($result['processingTimeMs'] ?? 0));
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

		[$providerId, $documentId] = IndexMappingService::decodeDocumentId((string)$entry['id']);
		$document = new IndexDocument($providerId, $documentId);
		$document->setAccess($access);
		$document->setHash($this->get('hash', $entry));
		$document->setModifiedTime($this->getInt('lastModified', $entry));
		$document->setScore('0');
		$document->setSource($this->get('source', $entry));
		$document->setTitle($this->get('title', $entry));

		$formatted = $entry['_formatted'] ?? [];
		if (!is_array($formatted)) {
			$formatted = [];
		}
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
		$seen = [];
		$fields = ['content', 'title'];
		foreach ($fields as $field) {
			if (isset($formatted[$field]) && is_string($formatted[$field]) && $formatted[$field] !== '') {
				$result[] = [
					'source' => $field,
					'excerpt' => $formatted[$field]
				];
				$seen[$field] = true;
			}
		}

		$parts = $formatted['parts'] ?? [];
		if (is_array($parts)) {
			foreach ($parts as $part => $excerpt) {
				if (!is_string($part) || !is_string($excerpt) || $excerpt === '') {
					continue;
				}

				$source = 'parts.' . $part;
				$result[] = [
					'source' => $source,
					'excerpt' => $excerpt
				];
				$seen[$source] = true;
			}
		}

		foreach ($formatted as $field => $excerpt) {
			if (!is_string($field) || !str_starts_with($field, 'parts.')) {
				continue;
			}
			if (!is_string($excerpt) || $excerpt === '' || isset($seen[$field])) {
				continue;
			}

			$result[] = [
				'source' => $field,
				'excerpt' => $excerpt
			];
		}

		return $result;
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
}
