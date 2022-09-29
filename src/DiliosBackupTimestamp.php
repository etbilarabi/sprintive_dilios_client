<?php

namespace Drupal\sprintive_dilios_client;
use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Class BackupTimestamp.
 */
class DiliosBackupTimestamp implements DiliosBackupTimestampInterface {

  /**
   * The keyvalue store.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface
   */
  protected $keyValue;

  /**
   * Constructs a new BackupTimestamp object.
   */
  public function __construct(KeyValueFactoryInterface $keyvalue) {
    $this->keyValue = $keyvalue->get('sprintive_dilios_client.backup');
  }

  /**
   * {@inheritDoc}
   */
  public function getLatestTimestampAsDatetime($key) {
    $datetime = NULL;
    $timestamp = $this->keyValue->get($key . '_last_timestamp');
    if (is_numeric($timestamp)) {
      try {
        $datetime = new \Datetime('@' . $timestamp);
      } catch (\Exception $e) {}
    }

    return $datetime;
  }

  /**
   * {@inheritDoc}
   */
  public function setLatestTimestamp($key) {
    $datetime = $this->time();
    $this->keyValue->set($key . '_last_timestamp', $datetime->getTimestamp());
  }

  /**
   * {@inheritDoc}
   */
  public function time() {
    return new \Datetime();
  }
}
