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
use OCP\FullTextSearch\Model\IIndexDocument;


class IndexMappingService {

	public function __construct(
		private ConfigService $configService,
	) {
	}


	/**
	 * Encode a providerId:documentId pair into a Meilisearch-safe document ID.
	 * Meilisearch requires alphanumeric IDs (plus - and _).
	 */
	public static function encodeDocumentId(string $providerId, string $documentId): string {
		return str_replace(':', '_-_', $providerId . ':' . $documentId);
	}


	/**
	 * Decode a Meilisearch document ID back to [providerId, documentId].
	 */
	public static function decodeDocumentId(string $encodedId): array {
		$decoded = str_replace('_-_', ':', $encodedId);
		$parts = explode(':', $decoded, 2);
		if (count($parts) < 2) {
			return [$parts[0] ?? '', ''];
		}

		return [$parts[0], $parts[1]];
	}


	/**
	 * @return string
	 * @throws ConfigurationException
	 */
	public function getIndexName(): string {
		return $this->configService->getMeilisearchIndex();
	}


	/**
	 * Configure Meilisearch index settings (filterable, searchable, sortable attributes).
	 *
	 * @param Client $client
	 * @throws ConfigurationException
	 */
	public function configureIndexSettings(Client $client): void {
		$index = $client->index($this->configService->getMeilisearchIndex());

		$index->updateFilterableAttributes([
			'owner', 'users', 'groups', 'circles', 'links',
			'provider', 'metatags', 'subtags', 'tags', 'source',
			'lastModified',
		]);

		$index->updateSearchableAttributes([
			'title', 'content',
		]);

		$index->updateSortableAttributes([
			'lastModified',
		]);

		$index->updateDisplayedAttributes(['*']);
	}


	/**
	 * @param Client $client
	 * @param IIndexDocument $document
	 *
	 * @return array
	 * @throws AccessIsEmptyException
	 * @throws ConfigurationException
	 */
	public function indexDocumentNew(Client $client, IIndexDocument $document): array {
		$index = $client->index($this->configService->getMeilisearchIndex());

		$body = $this->generateIndexBody($document);
		$body['id'] = self::encodeDocumentId($document->getProviderId(), $document->getId());

		$result = $index->addDocuments([$body]);

		return $result->toArray();
	}


	/**
	 * @param Client $client
	 * @param IIndexDocument $document
	 *
	 * @return array
	 * @throws AccessIsEmptyException
	 * @throws ConfigurationException
	 */
	public function indexDocumentUpdate(Client $client, IIndexDocument $document): array {
		$index = $client->index($this->configService->getMeilisearchIndex());

		$body = $this->generateIndexBody($document);
		$body['id'] = self::encodeDocumentId($document->getProviderId(), $document->getId());

		$result = $index->updateDocuments([$body]);

		return $result->toArray();
	}


	/**
	 * @param Client $client
	 * @param string $providerId
	 * @param string $documentId
	 *
	 * @throws ConfigurationException
	 */
	public function indexDocumentRemove(Client $client, string $providerId, string $documentId): void {
		$index = $client->index($this->configService->getMeilisearchIndex());
		$docId = self::encodeDocumentId($providerId, $documentId);

		try {
			$index->deleteDocument($docId);
		} catch (ApiException) {
		}
	}


	/**
	 * @param IIndexDocument $document
	 *
	 * @return array
	 * @throws AccessIsEmptyException
	 */
	public function generateIndexBody(IIndexDocument $document): array {
		$access = $document->getAccess();

		$body = [
			'owner' => $access->getOwnerId(),
			'users' => $access->getUsers(),
			'groups' => $access->getGroups(),
			'circles' => $access->getCircles(),
			'links' => $access->getLinks(),
			'metatags' => $document->getMetaTags(),
			'subtags' => $document->getSubTags(true),
			'tags' => $document->getTags(),
			'hash' => $document->getHash(),
			'provider' => $document->getProviderId(),
			'lastModified' => $document->getModifiedTime(),
			'source' => $document->getSource(),
			'title' => $document->getTitle(),
			'parts' => $document->getParts(),
		];

		$content = $document->getContent();
		if ($content !== '' && $document->isContentEncoded() === IIndexDocument::ENCODED_BASE64) {
			$decoded = base64_decode($content);
			$content = ($decoded !== false && mb_check_encoding($decoded, 'UTF-8'))
				? $decoded
				: '';
		}
		$body['content'] = $content;

		return array_merge($document->getInfoAll(), $body);
	}
}
