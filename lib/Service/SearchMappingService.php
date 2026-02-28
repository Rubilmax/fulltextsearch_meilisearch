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
	private const DEFAULT_PAGE_SIZE = 20;
	private const FILTERABLE_FIELDS = [
		'owner',
		'users',
		'groups',
		'circles',
		'links',
		'provider',
		'metatags',
		'subtags',
		'tags',
		'source',
		'lastModified',
	];

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
		$page = max(1, (int)$request->getPage());
		$requestedSize = (int)$request->getSize();
		$size = ($requestedSize > 0) ? $requestedSize : self::DEFAULT_PAGE_SIZE;

		$filter = $this->buildFilterExpression($request, $access, $providerId);

		$params = [
			'filter' => $filter,
			'limit' => $size,
			'offset' => ($page - 1) * $size,
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
		$viewerId = trim($access->getViewerId());
		if ($viewerId !== '') {
			$viewerId = $this->escapeFilterValue($viewerId);
			$parts[] = "owner = '$viewerId'";
			$parts[] = "users = '$viewerId'";
		}
		$parts[] = "users = '__all'";

		foreach ($access->getGroups() as $group) {
			if (!is_scalar($group)) {
				continue;
			}
			$parts[] = "groups = '" . $this->escapeFilterValue((string)$group) . "'";
		}

		foreach ($access->getCircles() as $circle) {
			if (!is_scalar($circle)) {
				continue;
			}
			$parts[] = "circles = '" . $this->escapeFilterValue((string)$circle) . "'";
		}

		foreach ($access->getLinks() as $link) {
			if (!is_scalar($link)) {
				continue;
			}
			$parts[] = "links = '" . $this->escapeFilterValue((string)$link) . "'";
		}

		return implode(' OR ', array_values(array_unique($parts)));
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
			if (!is_scalar($tag)) {
				continue;
			}
			$parts[] = "$field = '" . $this->escapeFilterValue((string)$tag) . "'";
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
			if (!is_scalar($tag)) {
				continue;
			}
			$parts[] = "$field = '" . $this->escapeFilterValue((string)$tag) . "'";
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
			if (!$query instanceof ISearchRequestSimpleQuery) {
				continue;
			}

			$field = $this->sanitizeFilterField($query->getField());
			if ($field === null) {
				continue;
			}

			$values = $this->normalizeScalarValues($query->getValues());
			if ($values === []) {
				continue;
			}

			switch ($query->getType()) {
				/* Meilisearch has no dedicated text filter operator, so this is an exact match. */
				case ISearchRequestSimpleQuery::COMPARE_TYPE_TEXT:
				case ISearchRequestSimpleQuery::COMPARE_TYPE_KEYWORD:
				case ISearchRequestSimpleQuery::COMPARE_TYPE_ARRAY:
					$keywordExpr = $this->buildKeywordSimpleQuery($field, $values);
					if ($keywordExpr !== '') {
						$parts[] = $keywordExpr;
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_EQ:
					$numericValues = array_values(array_filter($values, 'is_numeric'));
					if ($numericValues !== []) {
						$parts[] = $this->buildIntEqualitySimpleQuery($field, $numericValues);
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_GTE:
					$value = $this->extractFirstNumericValue($values);
					if ($value !== null) {
						$parts[] = "$field >= $value";
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_LTE:
					$value = $this->extractFirstNumericValue($values);
					if ($value !== null) {
						$parts[] = "$field <= $value";
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_GT:
					$value = $this->extractFirstNumericValue($values);
					if ($value !== null) {
						$parts[] = "$field > $value";
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_INT_LT:
					$value = $this->extractFirstNumericValue($values);
					if ($value !== null) {
						$parts[] = "$field < $value";
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_BOOL:
					$boolValues = [];
					foreach ($values as $value) {
						$bool = $this->normalizeBooleanValue($value);
						if ($bool === null) {
							continue;
						}
						$boolValues[] = $bool;
					}
					if ($boolValues !== []) {
						$boolExpr = $this->buildBooleanSimpleQuery($field, $boolValues);
						if ($boolExpr !== '') {
							$parts[] = $boolExpr;
						}
					}
					break;
				case ISearchRequestSimpleQuery::COMPARE_TYPE_REGEX:
				case ISearchRequestSimpleQuery::COMPARE_TYPE_WILDCARD:
					// Meilisearch does not support regex/wildcard filters - silently ignored
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
			if (!is_scalar($part)) {
				continue;
			}
			$part = trim((string)$part);
			if ($part === '') {
				continue;
			}
			$attrs[] = 'parts.' . $part;
		}

		return array_values(array_unique($attrs));
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

		if (!in_array($field, self::FILTERABLE_FIELDS, true)) {
			return null;
		}

		return $field;
	}

	private function normalizeScalarValues(array $values): array {
		$normalized = [];
		foreach ($values as $value) {
			if (is_scalar($value)) {
				$normalized[] = $value;
				continue;
			}

			if (!is_array($value)) {
				continue;
			}

			foreach ($value as $entry) {
				if (is_scalar($entry)) {
					$normalized[] = $entry;
				}
			}
		}

		return array_values($normalized);
	}

	private function buildKeywordSimpleQuery(string $field, array $values): string {
		$clauses = [];
		foreach ($values as $value) {
			$clauses[] = "$field = '" . $this->escapeFilterValue((string)$value) . "'";
		}

		$clauses = array_values(array_unique($clauses));
		if ($clauses === []) {
			return '';
		}

		if (count($clauses) === 1) {
			return $clauses[0];
		}

		return '(' . implode(' OR ', $clauses) . ')';
	}

	private function buildIntEqualitySimpleQuery(string $field, array $values): string {
		$clauses = [];
		foreach ($values as $value) {
			$clauses[] = "$field = " . (int)$value;
		}

		$clauses = array_values(array_unique($clauses));
		if (count($clauses) === 1) {
			return $clauses[0];
		}

		return '(' . implode(' OR ', $clauses) . ')';
	}

	private function extractFirstNumericValue(array $values): ?int {
		foreach ($values as $value) {
			if (is_numeric($value)) {
				return (int)$value;
			}
		}

		return null;
	}

	private function normalizeBooleanValue(mixed $value): ?bool {
		if (is_bool($value)) {
			return $value;
		}

		if (is_int($value) || is_float($value)) {
			if ((float)$value === 1.0) {
				return true;
			}
			if ((float)$value === 0.0) {
				return false;
			}

			return null;
		}

		if (!is_string($value)) {
			return null;
		}

		$normalized = strtolower(trim($value));
		if ($normalized === 'true' || $normalized === '1' || $normalized === 'yes') {
			return true;
		}

		if ($normalized === 'false' || $normalized === '0' || $normalized === 'no') {
			return false;
		}

		return null;
	}

	private function buildBooleanSimpleQuery(string $field, array $values): string {
		$allowTrue = in_array(true, $values, true);
		$allowFalse = in_array(false, $values, true);
		if ($allowTrue && $allowFalse) {
			return '';
		}

		return $field . ' = ' . ($allowTrue ? 'true' : 'false');
	}
}
