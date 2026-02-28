<!--
  - SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# Full text search - Meilisearch

_Full text search - Meilisearch_ is an extension to the [Full text search](https://github.com/nextcloud/fulltextsearch) framework for Nextcloud.

It allows you to index your content into a Meilisearch instance.

## Getting Started

### Prerequisites

- Nextcloud 32 or later
- A running [Meilisearch](https://www.meilisearch.com/) instance (v1.0+)
- The [Full text search](https://apps.nextcloud.com/apps/fulltextsearch) app installed and enabled on your Nextcloud instance

### 1. Install Meilisearch

Follow the [official Meilisearch installation guide](https://www.meilisearch.com/docs/learn/getting_started/installation) for your platform.

Quick start with Docker:

```bash
docker run -d --name meilisearch \
  -p 7700:7700 \
  -e MEILI_MASTER_KEY='YOUR_MASTER_KEY' \
  -v $(pwd)/meili_data:/meili_data \
  getmeili/meilisearch:latest
```

Or install via curl:

```bash
curl -L https://install.meilisearch.com | sh
./meilisearch --master-key="YOUR_MASTER_KEY"
```

### 2. Install the App

Install `fulltextsearch_meilisearch` from the Nextcloud App Store, or manually:

```bash
cd /path/to/nextcloud/apps
git clone https://github.com/nextcloud/fulltextsearch_meilisearch.git
```

Then enable it:

```bash
occ app:enable fulltextsearch_meilisearch
```

### 3. Configure the App

#### Via the Admin UI

Go to **Settings > Administration > Full text search** and fill in:

- **Meilisearch Host**: The URL of your Meilisearch instance (e.g., `http://localhost:7700`)
- **Meilisearch Index**: The name of the index to use (e.g., `nextcloud`)
- **API Key**: Your Meilisearch API key (the master key or a key with appropriate permissions)

#### Via the Command Line

```bash
occ fulltextsearch_meilisearch:configure --json '{"meilisearch_host":"http://localhost:7700","meilisearch_index":"nextcloud","meilisearch_api_key":"YOUR_MASTER_KEY"}'
```

### 4. Run the Initial Index

```bash
occ fulltextsearch:index
```

This will index all content from enabled full text search providers (e.g., files, bookmarks) into your Meilisearch instance.

### 5. Verify

Run a test search to confirm everything is working:

```bash
occ fulltextsearch:search "test query"
```

## Limitations

### No Binary Content Extraction (Ingest Pipeline)

**What it means**: Meilisearch does not have an equivalent to Elasticsearch's ingest pipeline with the attachment processor. This means binary file content (PDFs, DOCX, XLSX, PPTX, etc.) encoded as base64 cannot be processed and extracted by Meilisearch itself.

**Impact**: Documents whose content is provided as base64-encoded binary data will be indexed with empty content. The document metadata (title, tags, access permissions) will still be indexed and searchable, but the body text of binary files will not be searchable unless it is extracted upstream.

**Workarounds**:
- Ensure the full text search content provider (e.g., `fulltextsearch_files`) extracts plain text from files before sending them to the platform. Many providers already do this.
- Use external tools like [Apache Tika](https://tika.apache.org/) or [pdftotext](https://poppler.freedesktop.org/) to pre-process files and provide plain-text content to the indexing pipeline.
- For Nextcloud Files, the `fulltextsearch_files` app typically handles text extraction for common file types.

### No Wildcard or Regex Filters

**What it means**: Meilisearch does not support wildcard patterns (e.g., `source:*.pdf`) or regex-based filters in its filter expressions. Elasticsearch allowed wildcard and regex queries on any field.

**Impact**: Search providers that rely on `getWildcardFilters()` or `getRegexFilters()` from the `ISearchRequest` interface will have those filters silently ignored. Search results may be broader than expected when these filters were previously narrowing results.

**Workarounds**:
- Use keyword-based filters instead of wildcard patterns where possible. For example, filter by exact `source` values rather than wildcard patterns.
- Perform client-side filtering on results when exact matching is needed but not supported by Meilisearch filters.
- For file extension filtering, consider adding a dedicated `extension` field to indexed documents that can be filtered exactly.

### Asynchronous Indexing Operations

**What it means**: Meilisearch processes document additions, updates, and deletions asynchronously via a task queue. When a document is submitted for indexing, Meilisearch returns a task ID immediately, and the actual indexing happens in the background.

**Impact**: There may be a brief delay between indexing a document and being able to find it via search. In practice, Meilisearch processes tasks very quickly (typically under a second), so this is rarely noticeable.

**Workarounds**:
- This is generally transparent and requires no action. The app treats a successfully enqueued task as a successful operation.
- If you need to verify indexing completion, you can check task status via the Meilisearch dashboard or API (`GET /tasks/{taskUid}`).

### No Custom Analyzers or Tokenizers

**What it means**: Meilisearch uses its own built-in tokenization and language detection. Unlike Elasticsearch, you cannot configure custom analyzers, tokenizers, char filters, or shingle filters.

**Impact**: The `analyzer_tokenizer` setting from the Elasticsearch version is not available. For most languages, Meilisearch's built-in tokenization works well. However, specific languages that previously required a custom tokenizer (e.g., CJK languages with the `icu_tokenizer`) may see different search behavior.

**Workarounds**:
- Meilisearch has built-in support for CJK (Chinese, Japanese, Korean) languages and many other scripts. In most cases, the built-in tokenization is sufficient.
- Test search quality with your specific content and language to verify the results are acceptable.

### Search Scoring Differences

**What it means**: Meilisearch uses its own ranking rules (typo tolerance, word proximity, attribute ranking, exactness, etc.) instead of Elasticsearch's BM25 scoring model. Search results are ranked differently.

**Impact**: The order of search results may differ from what users experienced with the Elasticsearch backend. Meilisearch does not expose a numeric relevance score, so `IIndexDocument::setScore()` receives a default value.

**Workarounds**:
- Meilisearch's ranking is generally very good out of the box. You can customize ranking rules via the Meilisearch settings API if needed.
- The relevance model is different but not worse -- Meilisearch is designed for typo-tolerant, human-friendly search.

### Document ID Encoding

**What it means**: Meilisearch requires document IDs to be alphanumeric (plus `-` and `_`). The original Elasticsearch format used colons (`providerId:documentId`), which are not allowed. Document IDs are automatically encoded by replacing `:` with `_-_`.

**Impact**: This is handled transparently by the app. If you inspect documents directly in the Meilisearch dashboard, IDs will appear as `providerId_-_documentId` instead of `providerId:documentId`.

**Workarounds**:
- No action needed. The encoding/decoding is automatic and invisible to Nextcloud and its search providers.
