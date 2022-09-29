<?php

namespace Drupal\sprintive_dilios_client;

/**
 * Interface DiliosRequesterInterface.
 */
interface DiliosRequesterInterface {
  /**
   * Gets the backup retention and and required credintials to upload the backup.
   *
   * @return array
   */
  public function getBackupDetails();

  /**
   * Send logs to the server
   *
   * @param array $logs
   * @param string $repo_name
   * @return void
   */
  public function sendLogs($logs, $repo_name);
}
