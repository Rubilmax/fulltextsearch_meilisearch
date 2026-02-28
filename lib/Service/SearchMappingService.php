<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Service;


use OCA\FullTextSearch_Meilisearch\Exceptions\ConfigurationException;
use OCA\FullTextSearch_Meilisearch\Exceptions\SearchQueryGenerationException;
use OCP\FullTextSearch\Model\IDocumentAccess;
use OCP\FullTextSearch\Model\ISearchRequest;
use OCP\FullTextSearch\Model\ISearchRequestSimpleQuery;


class SearchMappingService {

	public function __construct(
		private ConfigService $configService
	) {
	}


	/**
	 * @param ISearchRequest $request
	 * @param IDocumentAccess $access
	 * @param string $providerId
	 *
	 * @return array
	 * @throws ConfigurationException
	 * @throws SearchQueryGenerationException
	 */
	public function generateSearchQuery(
		ISearchRequest $request,
		IDocumentAccess $access,
		string $providerId
	): array {
		$searchString = $request->getSearch();

		$filter = $this->buildFilterExpression($request, $access, $providerId);

		$params = [
			'filter' => $filter,
			'limit' => $request->getSize(),
			'offset' => ($request->getPage() - 1) * $request->getSize(),
			'attributesToHighlight' => $this->getHighlightAttributes($request),
			'highlightPreTag' => '',
			'highlightPostTag' => '',
			'showMatchesPosition' => true,
		];

		return ['query' => $searchString, 'params' => $params];
	}


	/**
	 * @param string $providerId
	 * @param string $documentId
	 *
	 * @return string
	 */
	public function getDocumentQuery(string $providerId, string $documentId): string {
		return IndexMappingService::encodeDocumentId($providerId, $documentId);
	}


	/**
	 * Build a Meilisearch filter expression from the search request, access control, and provider.
	 *
	 * @param ISearchRequest $request
	 * @param IDocumentAccess $access
	 * @param string $providerId
	 *
	 * @return string
	 */
	private function buildFilterExpression(
		ISearchRequest $request,
		IDocumentAccess $access,
		string $providerId
	): string {
		$filters = [];

		// Provider filter
		$filters[] = "provider = '" . $this->escapeFilterValue($providerId) . "'";

		// Access control (OR logic: user must match at least one)
		$accessFilter = $this->buildAccessFilter($access);
		if ($accessFilter !== '') {
			$filters[] = '(' . $accessFilter . ')';
		}

		// Metatags (OR logic)
		$metaFilter = $this->buildTagFilter('metatags', $request->getMetaTags());
		if ($metaFilter !== '') {
			$filters[] = '(' . $metaFilter . ')';
		}

		// Subtags (AND logic)
		$subFilter = $this->buildSubtagFilter('subtags', $request->getSubTags(true));
		if ($subFilter !== '') {
			$filters[] = $subFilter;
		}

		// Simple queries
		$simpleFilter = $this->buildSimpleQueryFilter($request->getSimpleQueries());
		if ($simpleFilter !== '') {
			$filters[] = $simpleFilter;
		}

		// Since filter (time range)
		$since = (int)$request->getOption('since');
		if ($since > 0) {
			$filters[] = "lastModified >= $since";
		}

		return implode(' AND ', $filters);
	}


	/**
	 * @param IDocumentAccess $access
	 *
	 * @return string
	 */
	private function buildAccessFilter(IDocumentAccess $access): string {
		$parts = [];
		$viewerId = $this->escapeFilterValue($access->getViewerId());
		$parts[] = "owner = '$viewerId'";
		$parts[] = "users = '$viewerId'";
		$parts[] = "users = '__all'";

		foreach ($access->getGroups() as $group) {
			$parts[] = "groups = '" . $this->escapeFilterValue($group) . "'";
		}

		foreach ($access->getCircles() as $circle) {
			$parts[] = "circles = '" . $this->escapeFilterValue($circle) . "'";
		}

		return implode(' OR ', $parts);
	}


	/**
	 * Build an OR filter for tags (metatags).
	 *
	 * @param string $field
	 * @param array $tags
	 *
	 * @return string
	 */
	private function buildTagFilter(string $field, array $tags): string {
		if (empty($tags)) {
			return '';
		}

		$parts = [];
		foreach ($tags as $tag) {
			$parts[] = "$field = '" . $this->escapeFilterValue($tag) . "'";
		}

		return implode(' OR ', $parts);
	}


	/**
	 * Build an AND filter for subtags.
	 *
	 * @param string $field
	 * @param array $tags
	 *
	 * @return string
	 */
	private function buildSubtagFilter(string $field, array $tags): string {
		if (empty($tags)) {
			return '';
		}

		$parts = [];
		foreach ($tags as $tag) {
			$parts[] = "$field = '" . $this->escapeFilterValue($tag) . "'";
		}

		return implode(' AND ', $parts);
	}


	/**
	 * @param ISearchRequestSimpleQuery[] $queries
	 *
	 * @return string
	 */
	private function buildSimpleQueryFilter(array $queries): string {
		$parts = [];
		foreach ($queries as $query) {
			$field = $this->sanitizeFilterField($query->getField());
			if ($field === null) {
				continue;
			}

			$values = $query->getValues();
			if ($values === []) {
				continue;
			}

			$value = $values[0];

			switch ($query->getType()) {
				case ISearchRequestSimpleQuery::COMPARE_TYPE_KEYWORD:
					$parts[] = "$field = '" . $this->escapeFilterValue((string)$value) . "'";
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_EQ:
					if (is_numeric($value)) {
						$parts[] = "$field = " . (int)$value;
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_GTE:
					if (is_numeric($value)) {
						$parts[] = "$field >= " . (int)$value;
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_LTE:
					if (is_numeric($value)) {
						$parts[] = "$field <= " . (int)$value;
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_GT:
					if (is_numeric($value)) {
						$parts[] = "$field > " . (int)$value;
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_LT:
					if (is_numeric($value)) {
						$parts[] = "$field < " . (int)$value;
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_WILDCARD:
					// Meilisearch does not support wildcard filters - silently ignored
					break;
			}
		}

		return implode(' AND ', $parts);
	}


	/**
	 * @param ISearchRequest $request
	 *
	 * @return array
	 */
	private function getHighlightAttributes(ISearchRequest $request): array {
		$attrs = ['content', 'title'];
		foreach ($request->getParts() as $part) {
			$attrs[] = 'parts.' . $part;
		}

		return $attrs;
	}


	/**
	 * @param string $value
	 *
	 * @return string
	 */
	private function escapeFilterValue(string $value): string {
		return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
	}

	private function sanitizeFilterField(string $field): ?string {
		$field = trim($field);
		if ($field === '') {
			return null;
		}

		if (preg_match('/^[A-Za-z0-9_]+(?:\.[A-Za-z0-9_]+)*$/', $field) !== 1) {
			return null;
		}

		return $field;
	}
}
