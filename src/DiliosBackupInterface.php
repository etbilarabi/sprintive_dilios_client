<?php

namespace Drupal\sprintive_dilios_client;

/**
 * Interface DiliosBackupInterface.
 */
interface DiliosBackupInterface {

  /**
   * This function is only usefull for testing purposes. re-call init() function.
   *
   * @return void
   */
  public function reInit();

  /**
   * Starts the backup process
   *
   * @return void
   */
  public function startBackup();

  /**
   * Generates a custom backup
   *
   * @param int $days
   * @return void
   */
  public function customBackup($days);

  /**
   * List all folders and check them to see if they are expired, and delete them.
   *
   * @return void
   */
  public function checkForExpiredBackups();
}
