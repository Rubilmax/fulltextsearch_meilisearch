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
		if (typeof OC === 'undefined' || typeof OC.generateUrl !== 'function') {
			return;
		}

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
		if (!result || typeof result !== 'object') {
			return;
		}
		if (!meilisearch_elements.meilisearch_host
			|| !meilisearch_elements.meilisearch_index
			|| !meilisearch_elements.meilisearch_api_key) {
			return;
		}

		meilisearch_elements.meilisearch_host.val(result.meilisearch_host);
		meilisearch_elements.meilisearch_index.val(result.meilisearch_index);
		meilisearch_elements.meilisearch_api_key.val(result.meilisearch_api_key);

		if (typeof fts_admin_settings !== 'undefined'
			&& typeof fts_admin_settings.tagSettingsAsSaved === 'function') {
			fts_admin_settings.tagSettingsAsSaved(meilisearch_elements.meilisearch_div);
		}
	},


	saveSettings: function () {
		if (typeof OC === 'undefined' || typeof OC.generateUrl !== 'function') {
			return;
		}
		if (!meilisearch_elements.meilisearch_host
			|| !meilisearch_elements.meilisearch_index
			|| !meilisearch_elements.meilisearch_api_key) {
			return;
		}

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
