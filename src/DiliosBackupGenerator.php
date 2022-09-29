<?php

namespace Drupal\sprintive_dilios_client;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Site\Settings;
use Drupal\sprintive_dilios_client\Traits\DiliosDatetimeTrait;
use Drush\Sql\SqlBase;
use Exception;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;

/**
 * Class DiliosBackupGenerator.
 */
class DiliosBackupGenerator implements DiliosBackupGeneratorInterface {

  use DiliosDatetimeTrait;

  const METADATA_EXPIRES = 'timestamp-expires';

  /**
   * The cached bucket name.
   *
   * @var string
   */
  protected $bucketName;

  /**
   * The static result cache.
   *
   * @var \Aws\ResultInterface[]
   */
  protected $static = [];

  /**
   * GuzzleHttp\ClientInterface definition.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The dilios requester
   *
   * @var \Drupal\sprintive_dilios_client\DiliosRequesterInterface
   */
  protected $diliosRequester;

  /**
   * The dilios configuration
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $diliosConfig;

  /**
   * Drupal\Core\File\FileSystemInterface definition.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The S3 client.
   *
   * @var \Aws\S3\S3Client
   */
  protected $s3Client;

  /**
   * The space or bucket
   *
   * @var string
   */
  protected $space;

  /**
   * The daily retention
   *
   * @var integer
   */
  protected $dailyRetention = 1;

  /**
   * The weekly retention
   *
   * @var integer
   */
  protected $weeklyRetention = 7;

  /**
   * The monthly retention.
   *
   * @var integer
   */
  protected $monthlyRetention = 30;

  /**
   * Constructs a new DiliosBackupGenerator object.
   */
  public function __construct(ClientInterface $http_client, DiliosRequesterInterface $dilios_requester, ConfigFactoryInterface $config_factory, FileSystemInterface $file_system) {
    $this->httpClient = $http_client;
    $this->diliosRequester = $dilios_requester;
    $this->diliosConfig = $config_factory->get('sprintive_dilios_client.settings');
    $this->fileSystem = $file_system;
    $this->space = $this->diliosConfig->get('dilios.repo_name');

    // Backup.
    $data = $this->diliosRequester->getBackupDetails();
    if (!isset($data['key']) || !isset($data['secret'])) {
      throw new InvalidArgumentException('Invalid key or secret was found');
    }
    // TODO: Change using dilios settings.
    $this->s3Client = new S3Client([
      'version' => 'latest',
      'region' => 'us-east-1',
      'endpoint' => $data['endpoint'],
      'credentials' => [
        'key' => $data['key'],
        'secret' => $data['secret'],
      ],
    ]);
  }

  /**
   * {@inheritDoc}
   */
  public function setRetention($daily = 1, $weekly = 7, $monthly = 30) {
    $this->dailyRetention = $daily;
    $this->weeklyRetention = $weekly;
    $this->monthlyRetention = $monthly;
    return $this;
  }

  /**
   * Creates a bucket if it does not exist.
   *
   * @param string $space
   * @return string
   */
  protected function prepareBucket() {

    if ($this->bucketName) {
      return $this->bucketName;
    }

    $bucket_name = Settings::get('dilios_bucket_name', $this->space);
    try {
      $this->s3Client->headBucket([
        'Bucket' => $bucket_name,
      ]);
    } catch (S3Exception $e) {
      if ($e->getResponse()->getStatusCode() == 404) {
        $this->s3Client->createBucket([
          'Bucket' => $bucket_name,
        ]);
      } else {
        throw $e;
      }

    }

    $this->bucketName = $bucket_name;
    return $bucket_name;
  }

  /**
   * Prepares the expiration timestamp
   *
   * @param int $timestamp
   * @param string $occurence
   *
   * @return int
   */
  protected function prepareExpireTimestamp($timestamp, $occurence, \Datetime $datetime) {
    $cloned_datetime = clone $datetime;
    if (is_numeric($timestamp) && $occurence == DiliosBackup::DILIOS_TIMESTAMP_CUSTOM) {
      return $timestamp;
    }

    switch ($occurence) {
    case DiliosBackup::DILIOS_TIMESTAMP_DAILY:
      return $cloned_datetime->modify($this->getModifier($this->dailyRetention))->getTimestamp();
    case DiliosBackup::DILIOS_TIMESTAMP_WEEKLY:
      return $cloned_datetime->modify($this->getModifier($this->weeklyRetention))->getTimestamp();
    case DiliosBackup::DILIOS_TIMESTAMP_MONTHLY:
      return $cloned_datetime->modify($this->getModifier($this->monthlyRetention))->getTimestamp();
    default:
      throw new InvalidArgumentException('Provided a "custom" occurence, but the timestamp was NULL');
    }
  }

  /**
   * {@inheritDoc}
   */
  public function generate($occurence, \Datetime $datetime, $expire_timestamp = NULL) {
    $temp = $this->fileSystem->getTempDirectory();
    $files = $this->fileSystem->realpath('public://');
    $date = $datetime->format('Y-m-d (H:i:s)');

    $database_path = $temp . '/database.sql.gz';
    if (!file_exists($database_path)) {
      // Generate sql dump.
      $sql = SqlBase::create(['gzip' => TRUE, 'result-file' => $temp . '/database.sql']);
      $sql->dump();

      if (!$database_path) {
        throw new Exception('Failed to write to: ' . $database_path);
      }
    }

    $files_path = $temp . '/files.tar.gz';
    if (!file_exists($files_path)) {
      // Prepare files.
      $error = NULL;
      $output = NULL;
      exec("tar --exclude='tmp' --exclude='style' -zcvf $files_path -C $files .", $output, $error);

      if ($error > 0) {
        throw new Exception('Could not compress ' . $files);
      }
    }

    // Prepare bucket.
    $bucket_name = $this->prepareBucket();

    // Prepare expire timestamp.
    $expire_timestamp = $this->prepareExpireTimestamp($expire_timestamp, $occurence, $datetime);
    $space_with_add = $this->space;
    if ($dilios_env = Settings::get('dilios_env')) {
      $space_with_add = $space_with_add . '-' . $dilios_env;
    }

    $folder_name = "{$space_with_add}-{$occurence}-{$date}";
    // Create a folder.
    $this->s3Client->putObject([
      'Bucket' => $bucket_name,
      'Key' => "$folder_name/",
      'ACL' => 'private',
      'Metadata' => [
        self::METADATA_EXPIRES => $expire_timestamp,
      ],
    ]);

    // Upload database.
    $this->s3Client->putObject([
      'Bucket' => $bucket_name,
      'Key' => "$folder_name/database.sql.gz",
      'Body' => file_get_contents($database_path),
      'ACL' => 'private',
      'Metadata' => [
        self::METADATA_EXPIRES => $expire_timestamp,
      ],
    ]);

    // Upload Files.
    $this->s3Client->putObject([
      'Bucket' => $bucket_name,
      'Key' => "$folder_name/files.tar.gz",
      'Body' => file_get_contents($files_path),
      'ACL' => 'private',
      'Metadata' => [
        self::METADATA_EXPIRES => $expire_timestamp,
      ],
    ]);

    \Drupal::messenger()->addStatus(t('Created a new backup: @backup', ['@backup' => $folder_name]));
  }

  /**
   * {@inheritDoc}
   */
  public function listObjects($prefix = NULL, $only_folders = FALSE) {
    $bucket_name = $this->prepareBucket();
    $objects = $this->s3Client->listObjectsV2([
      'Bucket' => $bucket_name,
      'prefix' => $prefix,
    ]);

    $objects_array = $objects->toArray()['Contents'] ?? [];
    if ($only_folders) {
      return array_filter($objects_array, function ($object) {
        // Only return folders.
        return substr($object['Key'], -1) === '/';
      });
    } else {
      return $objects_array;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function deleteObject($key) {
    $bucket_name = $this->prepareBucket();
    $this->s3Client->deleteMatchingObjects($bucket_name, $key);
  }

  public function getExpireDatetimeOfObject($key) {
    $bucket_name = $this->prepareBucket();
    $object = $this->s3Client->headObject([
      'Bucket' => $bucket_name,
      'Key' => $key,
    ]);

    $metadata = $object->get('@metadata');
    $timestamp = $metadata['headers']['x-amz-meta-' . self::METADATA_EXPIRES] ?? FALSE;
    try {
      return new \Datetime('@' . $timestamp);
    } catch (Exception $e) {
      return FALSE;
    }
  }

  /**
   * {@inheritDoc}
   */
  public function cleanUp() {
    $temp = $this->fileSystem->getTempDirectory();
    if (file_exists($temp . '/files.tar.gz')) {
      $this->fileSystem->delete($temp . '/files.tar.gz');
    }

    if (file_exists($temp . '/database.sql.gz')) {
      $this->fileSystem->delete($temp . '/database.sql.gz');
    }
  }

  /**
   * {@inheritDoc}
   *
   * // TODO: Test the backup.0
   */
  public function testBackup() {
    // Try to prepare the space.
    $this->prepareBucket();

    // Make sure we can write into temp directory.
    $temp = $this->fileSystem->getTempDirectory();
    $this->fileSystem->prepareDirectory($temp);

    // Check if we can read files folder.
    is_readable($this->fileSystem->realpath('public://'));
  }
}
