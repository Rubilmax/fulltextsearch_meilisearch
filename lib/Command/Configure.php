<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\FullTextSearch_Meilisearch\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\FullTextSearch_Meilisearch\ConfigLexicon;
use OCA\FullTextSearch_Meilisearch\Service\ConfigService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
			 ->addOption('host', null, InputOption::VALUE_REQUIRED, 'Address of the Meilisearch server')
			 ->addOption('index', null, InputOption::VALUE_REQUIRED, 'Name of the index on Meilisearch')
			 ->addOption('api-key', null, InputOption::VALUE_REQUIRED, 'API key for Meilisearch authentication')
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
		$config = [];
		if ($input->getOption('host') !== null) {
			$config[ConfigLexicon::MEILISEARCH_HOST] = $input->getOption('host');
		}
		if ($input->getOption('index') !== null) {
			$config[ConfigLexicon::MEILISEARCH_INDEX] = $input->getOption('index');
		}
		if ($input->getOption('api-key') !== null) {
			$config[ConfigLexicon::MEILISEARCH_API_KEY] = $input->getOption('api-key');
		}

		if (!empty($config)) {
			if (!$this->configService->checkConfig($config)) {
				$output->writeln('<error>Invalid Meilisearch configuration values provided.</error>');
				return self::FAILURE;
			}

			$this->configService->setConfig($config);
		}

		$json = json_encode($this->configService->getConfig(), JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE);
		$output->writeln(($json === false) ? '{}' : $json);
		return self::SUCCESS;
	}
}
