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
	private const LEGACY_ID_SEPARATOR = '_-_';
	private const ESCAPED_ID_PREFIX = 'h_';

	public function __construct(
		private ConfigService $configService,
	) {
	}


	/**
	 * Encode a providerId:documentId pair into a Meilisearch-safe document ID.
	 * Meilisearch requires alphanumeric IDs (plus - and _).
	 */
	public static function encodeDocumentId(string $providerId, string $documentId): string {
		if (self::requiresEscapedId($providerId, $documentId)) {
			return self::encodeEscapedDocumentId($providerId, $documentId);
		}

		return self::encodeLegacyDocumentId($providerId, $documentId);
	}


	/**
	 * Decode a Meilisearch document ID back to [providerId, documentId].
	 */
	public static function decodeDocumentId(string $encodedId): array {
		$decodedEscaped = self::decodeEscapedDocumentId($encodedId);
		if ($decodedEscaped !== null) {
			return $decodedEscaped;
		}

		$decoded = str_replace(self::LEGACY_ID_SEPARATOR, ':', $encodedId);
		$parts = explode(':', $decoded, 2);
		if (count($parts) < 2) {
			return [$parts[0] ?? '', ''];
		}

		return [$parts[0], $parts[1]];
	}

	/**
	 * Legacy encoding used before collision-safe IDs were introduced.
	 */
	private static function encodeLegacyDocumentId(string $providerId, string $documentId): string {
		return str_replace(':', self::LEGACY_ID_SEPARATOR, $providerId . ':' . $documentId);
	}

	/**
	 * Collision-safe encoding for IDs that cannot be represented safely with legacy encoding.
	 */
	private static function encodeEscapedDocumentId(string $providerId, string $documentId): string {
		return self::ESCAPED_ID_PREFIX . bin2hex($providerId) . '_' . bin2hex($documentId);
	}

	private static function requiresEscapedId(string $providerId, string $documentId): bool {
		if (str_contains($providerId, self::LEGACY_ID_SEPARATOR) || str_contains($documentId, self::LEGACY_ID_SEPARATOR)) {
			return true;
		}

		return (
			preg_match('/^[A-Za-z0-9_:-]+$/', $providerId) !== 1
			|| preg_match('/^[A-Za-z0-9_:-]+$/', $documentId) !== 1
		);
	}

	/**
	 * Decode collision-safe IDs. Returns null when the format is not matched.
	 */
	private static function decodeEscapedDocumentId(string $encodedId): ?array {
		if (!str_starts_with($encodedId, self::ESCAPED_ID_PREFIX)) {
			return null;
		}

		$payload = substr($encodedId, strlen(self::ESCAPED_ID_PREFIX));
		$separatorPos = strpos($payload, '_');
		if ($separatorPos === false) {
			return null;
		}

		$providerHex = substr($payload, 0, $separatorPos);
		$documentHex = substr($payload, $separatorPos + 1);
		if (!self::isHexData($providerHex) || !self::isHexData($documentHex)) {
			return null;
		}

		$providerId = ($providerHex === '') ? '' : hex2bin($providerHex);
		$documentId = ($documentHex === '') ? '' : hex2bin($documentHex);
		if ($providerId === false || $documentId === false) {
			return null;
		}

		return [$providerId, $documentId];
	}

	private static function isHexData(string $value): bool {
		if ($value === '') {
			return true;
		}

		if (strlen($value) % 2 !== 0) {
			return false;
		}

		return preg_match('/^[a-f0-9]+$/', $value) === 1;
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

		return (array) $result;
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

		return (array) $result;
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
		$docIds = array_unique([
			self::encodeDocumentId($providerId, $documentId),
			self::encodeLegacyDocumentId($providerId, $documentId),
		]);

		foreach ($docIds as $docId) {
			try {
				$index->deleteDocument($docId);
			} catch (ApiException) {
			}
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
