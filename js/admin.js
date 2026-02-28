/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** global: OCA */
/** global: meilisearch_elements */
/** global: meilisearch_settings */


$(document).ready(function () {
	if (typeof OCA === 'undefined'
		|| typeof OCA.FullTextSearchAdmin === 'undefined'
		|| typeof meilisearch_elements === 'undefined'
		|| typeof meilisearch_settings === 'undefined') {
		return;
	}


	/**
	 * @constructs MeilisearchAdmin
	 */
	var MeilisearchAdmin = function () {
		$.extend(MeilisearchAdmin.prototype, meilisearch_elements);
		$.extend(MeilisearchAdmin.prototype, meilisearch_settings);

		meilisearch_elements.init();
		meilisearch_settings.refreshSettingPage();
	};

	OCA.FullTextSearchAdmin.meilisearch = MeilisearchAdmin;
	OCA.FullTextSearchAdmin.meilisearch.settings = new MeilisearchAdmin();

});
