<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\FullTextSearch_Meilisearch\Service\ConfigService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class Configure extends Base {

	public function __construct(
		private ConfigService $configService
	) {
		parent::__construct();
	}

	protected function configure() {
		parent::configure();
		$this->setName('fulltextsearch_meilisearch:configure')
			 ->addArgument('json', InputArgument::OPTIONAL, 'set config')
			 ->setDescription('Configure the installation');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @return Integer
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		if ($input->getArgument('json')) {
			$decoded = json_decode((string)$input->getArgument('json'), true);
			if (is_array($decoded)) {
				$this->configService->setConfig($decoded);
			}
		}

		$json = json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
		$output->writeln(($json === false) ? '{}' : $json);
		return self::SUCCESS;
	}
}
