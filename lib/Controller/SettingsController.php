<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Controller;

use Exception;
use OCA\FullTextSearch_Meilisearch\AppInfo\Application;
use OCA\FullTextSearch_Meilisearch\Service\ConfigService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;


/**
 * Class SettingsController
 *
 * @package OCA\FullTextSearch_Meilisearch\Controller
 */
class SettingsController extends Controller {

	public function __construct(
		IRequest $request,
		private ConfigService $configService
	) {
		parent::__construct(Application::APP_NAME, $request);
	}

	/**
	 * @return DataResponse
	 * @throws Exception
	 */
	#[AdminRequired]
	public function getSettingsAdmin(): DataResponse {
		$data = $this->configService->getConfig();

		return new DataResponse($data, Http::STATUS_OK);
	}

	/**
	 * @param mixed $data
	 *
	 * @return DataResponse
	 * @throws Exception
	 */
	#[AdminRequired]
	public function setSettingsAdmin($data = []): DataResponse {
		if (!is_array($data)) {
			$data = [];
		}

		if ($this->configService->checkConfig($data)) {
			$this->configService->setConfig($data);
		}

		return $this->getSettingsAdmin();
	}
}
