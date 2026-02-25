<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

use OCA\FullTextSearch_Meilisearch\AppInfo\Application;
use OCP\Util;


Util::addScript(Application::APP_NAME, 'admin.elements');
Util::addScript(Application::APP_NAME, 'admin.settings');
Util::addScript(Application::APP_NAME, 'admin');

Util::addStyle(Application::APP_NAME, 'admin');

?>

<div id="meilisearch" class="section" style="display: none;">
	<h2><?php p($l->t('Meilisearch')) ?></h2>

	<div class="div-table">

		<div class="div-table-row">
			<div class="div-table-col div-table-col-left">
				<span class="leftcol"><?php p($l->t('Address of the Meilisearch server')); ?>:</span>
			</div>
			<div class="div-table-col">
				<input type="text" id="meilisearch_host"
					   placeholder="http://localhost:7700"/>
			</div>
		</div>

		<div class="div-table-row">
			<div class="div-table-col div-table-col-left">
				<span class="leftcol"><?php p($l->t('Index')); ?>:</span>
				<br/>
				<em><?php p($l->t('Name of your index.')); ?></em>
			</div>
			<div class="div-table-col">
				<input type="text" id="meilisearch_index" placeholder="my_index"/>
			</div>
		</div>

		<div class="div-table-row">
			<div class="div-table-col div-table-col-left">
				<span class="leftcol"><?php p($l->t('API Key')); ?>:</span>
				<br/>
				<em><?php p($l->t('API key for authentication with Meilisearch.')); ?></em>
			</div>
			<div class="div-table-col">
				<input type="password" id="meilisearch_api_key" placeholder=""/>
			</div>
		</div>


	</div>


</div>
