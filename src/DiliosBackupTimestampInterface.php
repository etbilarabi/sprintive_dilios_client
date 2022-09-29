<?php

namespace Drupal\sprintive_dilios_client;

/**
 * Interface DiliosBackupTimestampInterface.
 */
interface DiliosBackupTimestampInterface {

  /**
   * Gets the last time the backup was generated for a given key, keys can be 'daily', 'weekly' or 'monthly.
   *
   * @param string $key
   * @return \Datetime|FALSE
   */
  public function getLatestTimestampAsDatetime($key);

  /**
   * Sets the last time the backup was generated for a given key.
   *
   * @param string $key
   * @return void
   */
  public function setLatestTimestamp($key);

  /**
   * Gets the current as Datetime.
   *
   * @return \Datetime
   */
  public function time();
}
