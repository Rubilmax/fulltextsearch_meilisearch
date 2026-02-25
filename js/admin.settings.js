/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/** global: OC */
/** global: meilisearch_elements */
/** global: fts_admin_settings */




var meilisearch_settings = {

	config: null,

	refreshSettingPage: function () {

		$.ajax({
			method: 'GET',
			url: OC.generateUrl('/apps/fulltextsearch_meilisearch/admin/settings')
		}).done(function (res) {
			meilisearch_settings.updateSettingPage(res);
		});

	},

	/** @namespace result.meilisearch_host */
	/** @namespace result.meilisearch_index */
	/** @namespace result.meilisearch_api_key */
	updateSettingPage: function (result) {

		meilisearch_elements.meilisearch_host.val(result.meilisearch_host);
		meilisearch_elements.meilisearch_index.val(result.meilisearch_index);
		meilisearch_elements.meilisearch_api_key.val(result.meilisearch_api_key);

		fts_admin_settings.tagSettingsAsSaved(meilisearch_elements.meilisearch_div);
	},


	saveSettings: function () {

		var data = {
			meilisearch_host: meilisearch_elements.meilisearch_host.val(),
			meilisearch_index: meilisearch_elements.meilisearch_index.val(),
			meilisearch_api_key: meilisearch_elements.meilisearch_api_key.val()
		};

		$.ajax({
			method: 'POST',
			url: OC.generateUrl('/apps/fulltextsearch_meilisearch/admin/settings'),
			data: {
				data: data
			}
		}).done(function (res) {
			meilisearch_settings.updateSettingPage(res);
		});

	}


};
