<?php

namespace Drupal\sprintive_dilios_client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\Site\Settings;
use Drupal\sprintive_dilios_client\Traits\DiliosDatetimeTrait;
use Exception;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;

/**
 * Class DiliosBackup.
 */
class DiliosBackup implements DiliosBackupInterface {

  use MessengerTrait;
  use DiliosDatetimeTrait;
  /**
   * The timestamp keys to store in the keyvalue storage.
   */
  const DILIOS_TIMESTAMP_DAILY = 'daily';
  const DILIOS_TIMESTAMP_WEEKLY = 'weekly';
  const DILIOS_TIMESTAMP_MONTHLY = 'monthly';
  const DILIOS_TIMESTAMP_CUSTOM = 'custom';

  /**
   * GuzzleHttp\ClientInterface definition.
   *
   * @var \Drupal\sprintive_dilios_client\DiliosBackupGeneratorInterface
   */
  protected $diliosBackupGenerator;

  /**
   * The dilios backup timestamp service.
   *
   * @var \Drupal\sprintive_dilios_client\DiliosBackupTimestampInterface
   */
  protected $diliosBackupTimestamp;

  /**
   * The dilios requester
   *
   * @var \Drupal\sprintive_dilios_client\DiliosRequesterInterface
   */
  protected $diliosRequester;

  /**
   * The dilios client configurations
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $config;

  /**
   * The daily retention in days.
   *
   * @var int|FALSE
   */
  protected $daily;

  /**
   * The weekly retention in days.
   *
   * @var int|FALSE
   */
  protected $weekly;

  /**
   * The monthly retention in days.
   *
   * @var int|FALSE
   */
  protected $monthly;

  /**
   * The spaces key
   *
   * @var string
   */
  protected $key;

  /**
   * The spaces secret
   *
   * @var string
   */
  protected $secret;

  /**
   * The space name
   *
   * @var string
   */
  protected $space;

  /**
   * The base API url.
   *
   * @var string
   */
  protected $baseApi;

  /**
   * The dilios API key.
   *
   * @var string
   */
  protected $apiKey;

  /**
   * The auth array for basic auth on dilios.
   *
   * @var array
   */
  protected $auth;

  /**
   * Constructs a new DiliosBackup object.
   */
  public function __construct(DiliosBackupGeneratorInterface $dilios_backup_generator, DiliosBackupTimestampInterface $dilios_backup_timestamp, DiliosRequesterInterface $dilios_requester, ConfigFactoryInterface $config_factory) {
    $this->diliosBackupGenerator = $dilios_backup_generator;
    $this->diliosBackupTimestamp = $dilios_backup_timestamp;
    $this->diliosRequester = $dilios_requester;
    $this->config = $config_factory->get('sprintive_dilios_client.settings');
    $this->init();
  }

  /**
   * Check if the values of the keys of this object are set.
   *
   * @return void
   */
  protected function checkIfVarsAresSetUp() {
    $check = ['daily', 'weekly', 'monthly', 'key', 'secret', 'space', 'baseApi', 'apiKey'];
    $vars = get_object_vars($this);
    foreach ($vars as $key => $var) {
      if (!in_array($key, $check)) {
        continue;
      }

      if ($key == 'active') {
        if (!$var) {
          throw new Exception('The project is not active');
        }
        continue;
      }

      if (!isset($var) || $var === FALSE) {
        throw new InvalidArgumentException(sprintf('The property "%s" is invalid', $key));
      }
    }
  }

  /**
   * Initialize the service, get the backup information from Dilios server before operating.
   *
   * @return void
   */
  protected function init() {
    $this->baseApi = $this->config->get('dilios.url') ?? NULL;
    $this->apiKey = $this->config->get('dilios.key') ?? NULL;
    $username = $this->config->get('dilios.username');
    $password = $this->config->get('dilios.password');
    $this->auth = $username && $password ? [$username, $password] : [];
    $this->auth = [$this->config->get('dilios.username'), $this->config->get('dilios.password')];
    $this->space = $this->config->get('dilios.repo_name') ?? NULL;
    $data = $this->diliosRequester->getBackupDetails();
    $this->daily = $data['daily'];
    $this->weekly = $data['weekly'];
    $this->monthly = $data['monthly'];
    $this->key = $data['key'] ?? NULL;
    $this->secret = $data['secret'] ?? NULL;
    $this->checkIfVarsAresSetUp();
    $this->diliosBackupGenerator->setRetention($this->daily, $this->weekly, $this->monthly);
  }

  /**
   * {@inheritDoc}
   */
  public function reInit() {
    $this->init();
  }

  /**
   * Check if the time is for daily backup.
   *
   * @return bool
   */
  protected function timeForDailyBackup() {
    // Check if daily backups are disabled.
    if (!$this->daily || $this->daily == 0) {
      return FALSE;
    }

    $datetime = $this->diliosBackupTimestamp->getLatestTimestampAsDatetime(self::DILIOS_TIMESTAMP_DAILY);
    if (!$datetime) {
      return TRUE;
    }

    // Increment a day, and compare with the current time.
    $datetime->modify("+1 day");
    return $this->diliosBackupTimestamp->time()->getTimestamp() >= $datetime->getTimestamp();
  }

  /**
   * Check if the time is for a weekly backup.
   *
   * @return bool
   */
  protected function timeForWeeklyBackup() {
    // Check if daily backups are disabled.
    if (!$this->weekly || $this->weekly == 0) {
      return FALSE;
    }

    $datetime = $this->diliosBackupTimestamp->getLatestTimestampAsDatetime(self::DILIOS_TIMESTAMP_WEEKLY);
    if (!$datetime) {
      return TRUE;
    }

    // Increment a week, and compare with the current time.
    $datetime->modify('+1 week');
    return $this->diliosBackupTimestamp->time()->getTimestamp() >= $datetime->getTimestamp();
  }

  /**
   * Check if the time is for a monthly backup.
   *
   * @return bool
   */
  protected function timeForMonthlyBackup() {
    // Check if daily backups are disabled.
    if (!$this->monthly || $this->monthly == 0) {
      return FALSE;
    }

    $datetime = $this->diliosBackupTimestamp->getLatestTimestampAsDatetime(self::DILIOS_TIMESTAMP_MONTHLY);
    if (!$datetime) {
      return TRUE;
    }

    // Increment a month, and compare with the current time.
    $datetime->modify('+1 month');
    return $this->diliosBackupTimestamp->time()->getTimestamp() >= $datetime->getTimestamp();
  }

  /**
   * {@inheritDoc}
   */
  public function startBackup() {
    $this->diliosBackupGenerator->cleanUp();

    $datetime = $this->diliosBackupTimestamp->time();
    if ($this->timeForDailyBackup()) {
      $this->diliosBackupGenerator->generate(self::DILIOS_TIMESTAMP_DAILY, $datetime);
      $this->diliosBackupTimestamp->setLatestTimestamp(self::DILIOS_TIMESTAMP_DAILY);
    }

    if ($this->timeForWeeklyBackup()) {
      $this->diliosBackupGenerator->generate(self::DILIOS_TIMESTAMP_WEEKLY, $datetime);
      $this->diliosBackupTimestamp->setLatestTimestamp(self::DILIOS_TIMESTAMP_WEEKLY);

    }

    if ($this->timeForMonthlyBackup()) {
      $this->diliosBackupGenerator->generate(self::DILIOS_TIMESTAMP_MONTHLY, $datetime);
      $this->diliosBackupTimestamp->setLatestTimestamp(self::DILIOS_TIMESTAMP_MONTHLY);
    }

    $this->diliosBackupGenerator->cleanUp();
  }

  /**
   * {@inheritDoc}
   *
   */
  public function customBackup($days) {
    $this->diliosBackupGenerator->cleanUp();
    $datetime = $this->diliosBackupTimestamp->time();
    $datetime->modify($this->getModifier($days));
    $this->diliosBackupGenerator->generate(self::DILIOS_TIMESTAMP_CUSTOM, $this->diliosBackupTimestamp->time(), $datetime->getTimestamp());
  }

  /**
   * {@inheritDoc}
   */
  public function checkForExpiredBackups() {
    $folders = $this->diliosBackupGenerator->listObjects(NULL, TRUE);
    foreach ($folders as $folder) {
      // Check the metadata of the folder.
      $expiration_date = $this->diliosBackupGenerator->getExpireDatetimeOfObject($folder['Key']);
      if ($expiration_date && $expiration_date->getTimestamp() < $this->diliosBackupTimestamp->time()->getTimestamp()) {
        $this->diliosBackupGenerator->deleteObject($folder['Key']);
        $this->messenger()->addStatus(t('Removed the backup "@backup"', ['@backup' => $folder['Key']]));
      }
    }
  }
}
