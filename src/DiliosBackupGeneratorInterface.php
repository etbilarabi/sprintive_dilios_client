<?php

namespace Drupal\sprintive_dilios_client;

/**
 * Interface DiliosBackupGeneratorInterface.
 */
interface DiliosBackupGeneratorInterface {

  /**
   * Sets the retention of the backups
   *
   * @param integer $daily
   * @param integer $weekly
   * @param integer $monthly
   *
   * @return $this
   */
  public function setRetention($daily = 1, $weekly = 7, $monthly = 30);

  /**
   * Generates a backup for db and files.
   *
   * @param string $occurence
   * @param \Datetime $datetime
   * @param int $expire_timestamp
   *
   * @return void
   */
  public function generate($occurence, \Datetime $datetime, $expire_timestamp = NULL);

  /**
   * Clean up any old backup data.
   *
   * @return void
   */
  public function cleanUp();

  /**
   * List all folders.
   *
   * @param string $prefix
   * @param bool $only_folders
   * @return array
   */
  public function listObjects($prefix = NULL, $only_folders = FALSE);

  /**
   * Deletes an object.
   *
   * @param string $key
   * u
   * @return boolean
   */
  public function deleteObject($key);

  /**
   * Gets the expiration timestamp of an object using a key
   *
   * @param string $key
   * @return \Datetime|FALSE
   */
  public function getExpireDatetimeOfObject($key);

  /**
   * Tests if we can backup successfully.
   *
   * @return void
   */
  public function testBackup();
}
