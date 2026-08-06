<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Romain Milon
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use Meilisearch\Client;
use OCA\FullTextSearch_Meilisearch\Exceptions\ClientException;
use OCA\FullTextSearch_Meilisearch\Service\IndexMappingService;
use OCA\FullTextSearch_Meilisearch\Service\SearchMappingService;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function assertSameValue(mixed $expected, mixed $actual, string $description): void {
	if ($expected === $actual) {
		return;
	}

	throw new RuntimeException(
		$description . PHP_EOL
		. 'Expected: ' . var_export($expected, true) . PHP_EOL
		. 'Actual:   ' . var_export($actual, true)
	);
}

final class FakeTaskClient extends Client {
	/** @var array<string, mixed> */
	private array $completed;
	/** @var array<int, int> */
	public array $waitedFor = [];

	/** @param array<string, mixed> $completed */
	public function __construct(array $completed) {
		$this->completed = $completed;
	}

	public function waitForTask($uid, int $timeoutInMs = 5000, int $intervalInMs = 50): array {
		$this->waitedFor[] = (int)$uid;
		assertSameValue(300000, $timeoutInMs, 'Task timeout should allow long indexing operations');
		assertSameValue(100, $intervalInMs, 'Task polling interval should remain responsive');

		return $this->completed;
	}
}

$translate = new ReflectionMethod(SearchMappingService::class, 'translateRequiredTerms');
$requiredTermCases = [
	'test' => 'test',
	'document is a simple +test' => 'document is a simple "test"',
	'document is a simple +test +testing' => 'document is a simple "test" "testing"',
	'+document is a simple -test -testing' => '"document" is a simple -test -testing',
	'+"exact phrase" optional' => '"exact phrase" optional',
	'C++ reference' => 'C++ reference',
];
foreach ($requiredTermCases as $input => $expected) {
	assertSameValue($expected, $translate->invoke(null, $input), 'Required-term translation failed');
}

$changedSettings = new ReflectionMethod(IndexMappingService::class, 'getChangedSettings');
$requiredSettings = [
	'filterableAttributes' => [
		'owner', 'users', 'groups', 'circles', 'links',
		'provider', 'metatags', 'subtags', 'tags', 'source',
		'lastModified',
	],
	'searchableAttributes' => ['title', 'content', 'parts'],
	'sortableAttributes' => ['lastModified'],
	'displayedAttributes' => ['*'],
];
assertSameValue([], $changedSettings->invoke(null, $requiredSettings), 'Unchanged settings must not enqueue a task');
assertSameValue(
	['searchableAttributes' => ['title', 'content', 'parts']],
	$changedSettings->invoke(null, array_replace($requiredSettings, ['searchableAttributes' => ['*']])),
	'Only changed settings should be submitted'
);

$mappingService = (new ReflectionClass(IndexMappingService::class))->newInstanceWithoutConstructor();
$waitForTask = new ReflectionMethod(IndexMappingService::class, 'waitForTaskCompletion');
$successfulClient = new FakeTaskClient(['uid' => 42, 'status' => 'succeeded']);
assertSameValue(
	['uid' => 42, 'status' => 'succeeded'],
	$waitForTask->invoke($mappingService, $successfulClient, ['taskUid' => 42]),
	'Successful task result should be returned'
);
assertSameValue([42], $successfulClient->waitedFor, 'Document writes must wait for their task');

try {
	$failedClient = new FakeTaskClient([
		'uid' => 43,
		'status' => 'failed',
		'error' => ['message' => 'invalid document'],
	]);
	$waitForTask->invoke($mappingService, $failedClient, ['taskUid' => 43]);
	throw new RuntimeException('Failed tasks must raise an exception');
} catch (ClientException $exception) {
	assertSameValue(
		'Meilisearch task 43 did not succeed: invalid document',
		$exception->getMessage(),
		'Task failure should preserve the Meilisearch error'
	);
}

fwrite(STDOUT, "fulltextsearch_meilisearch unit tests passed\n");
