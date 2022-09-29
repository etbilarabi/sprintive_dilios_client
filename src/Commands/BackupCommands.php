<?php

namespace Drupal\sprintive_dilios_client\Commands;

use Drupal\Core\Site\Settings;
use Drush\Commands\DrushCommands;

/**
 * A drush command file for generating backups.
 */
class BackupCommands extends DrushCommands {

  /**
   * Run a cron job for backups.
   *
   * @command sprintive_dilios_client:backup-cron
   * @group sprintive_dilios_client
   * @aliases sbc
   * @usage sprintive:backup-cron
   */
  public function backupCron() {
    // Check if backups are enabled.
    if (!Settings::get('dilios_enable_backups', FALSE)) {
      \Drupal::messenger()->addWarning('Dilios backups are disabled using the sbc command');
      return;
    }

    \Drupal::service('sprintive_dilios_client.backup')->startBackup();
    \Drupal::service('sprintive_dilios_client.backup')->checkForExpiredBackups();
    $this->output()->writeln('Backup cron');
  }

  /**
   * Generates a custom backup.
   *
   * @command sprintive_dilios_client:generate-backup
   * @group sprintive_dilios_client
   * @aliases sgb
   * @usage sprintive:generate-backup
   *
   * @param int $days
   *   The number of days to keep the backup for.
   */
  public function generateBackup($days) {
    \Drupal::service('sprintive_dilios_client.backup')->customBackup((int) $days);
    $this->output()->writeln('Backup generated successfully');
  }

}
