/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** global: OCA */
/** global: fts_admin_settings */
/** global: meilisearch_settings */


var meilisearch_elements = {
	meilisearch_div: null,
	meilisearch_host: null,
	meilisearch_index: null,
	meilisearch_api_key: null,


	init: function () {
		meilisearch_elements.meilisearch_div = $('#meilisearch');
		meilisearch_elements.meilisearch_host = $('#meilisearch_host');
		meilisearch_elements.meilisearch_index = $('#meilisearch_index');
		meilisearch_elements.meilisearch_api_key = $('#meilisearch_api_key');

		meilisearch_elements.meilisearch_host.on('input', function () {
			fts_admin_settings.tagSettingsAsNotSaved($(this));
		}).blur(function () {
			meilisearch_settings.saveSettings();
		});

		meilisearch_elements.meilisearch_index.on('input', function () {
			fts_admin_settings.tagSettingsAsNotSaved($(this));
		}).blur(function () {
			meilisearch_settings.saveSettings();
		});

		meilisearch_elements.meilisearch_api_key.on('input', function () {
			fts_admin_settings.tagSettingsAsNotSaved($(this));
		}).blur(function () {
			meilisearch_settings.saveSettings();
		});
	}


};
