<?php

declare(strict_types=1);

/**
 * @copyright 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2020 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\UnattendedUpgrades\Service;

use OC\Installer;
use OC_App;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;
use function array_reduce;
use function in_array;

class Upgrader {

	public function __construct(private Installer $installer,
								private Config $config,
								private ITimeFactory $timeFactory,
								private LoggerInterface $logger) {
	}

	public function upgrade(bool $dryRun = false): int {
		if (!$dryRun) {
			$this->logger->info("Unattended upgrade started");
		}

		list($maintenanceWindowStart, $maintenanceWindowEnd) = $this->config->getMaintenanceWindow();
		$now = $this->timeFactory->getTime();
		if ($now < $maintenanceWindowStart->getTimestamp()
			|| $now > $maintenanceWindowEnd->getTimestamp()) {
			$this->logger->debug("Unattended upgrade aborted because the maintenance window is not open");
			return 0;
		}

		$allowed = $this->config->getAllowedAppIds();
		$blocked = $this->config->getBlockedAppIds();
		$upgraded = array_reduce(OC_App::getAllApps(), function (int $carry, string $appId) use ($dryRun, $allowed, $blocked) {
			if (!$this->installer->isUpdateAvailable($appId)) {
				return $carry;
			}

			if (!empty($allowed) && !in_array($appId, $allowed, true)) {
				$this->logger->debug("Ignoring unattended upgrade for $appId because the app is not allowed");
				return $carry;
			}
			if (!empty($blocked) && in_array($appId, $blocked, true)) {
				$this->logger->debug("Ignoring unattended upgrade for $appId because the app is blocked");
				return $carry;
			}

			if ($dryRun) {
				// Enough info
				return $carry + 1;
			}

			try {
				if ($this->installer->updateAppstoreApp($appId)) {
					$this->logger->info("Unattended upgrade of $appId was successful");
				} else {
					$this->logger->error("Unattended upgrade of $appId failed");
				}
			} catch (Throwable $e) {
				$this->logger->error("Unattended upgrade of $appId failed: " . $e->getMessage(), ['exception' => $e]);
				return $carry;
			}

			return $carry + 1;
		}, 0);

		if (!$dryRun) {
			$this->logger->info("Unattended upgrade finished");
		}

		return $upgraded;
	}

}
