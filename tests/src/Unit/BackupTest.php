<?php

namespace Drupal\Tests\sprintive_dilios_client\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\KeyValueStore\DatabaseStorage;
use Drupal\Core\KeyValueStore\KeyValueFactory;
use Drupal\sprintive_dilios_client\DiliosBackup;
use Drupal\sprintive_dilios_client\DiliosBackupGenerator;
use Drupal\sprintive_dilios_client\DiliosBackupTimestamp;
use Drupal\sprintive_dilios_client\DiliosRequester;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Client;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * @coversDefaultClass \Drupal\sprintive_dilios_client\DiliosBackup.php
 * @group backup
 */
class BackupTest extends UnitTestCase {

  /**
   * The dilios backup
   *
   * @var \Drupal\sprintive_dilios_client\DiliosBackupInterface
   */
  protected $diliosBackup;

  protected $diliosConfig = [
    'dilios.repo_name' => 'test-space',
    'dilios.url' => 'http://test.local',
    'dilios.key' => 'SomeKey',
  ];

  protected $keyValues;

  protected $currentDate = '2021-01-01';

  protected $dailyGenerated;
  protected $weeklyGenerated;
  protected $monthlyGenerated;
  protected $customGenerated;
  protected $customExpires;

  protected $backupRequestData = [
    'daily' => 3,
    'weekly' => 7,
    'monthly' => 30,
    'active' => TRUE,
    'secret' => 'Test',
    'key' => 'Test',
    'endpoint' => 'Test',
  ];

  /**
   * {@inheritDoc}
   */
  protected function setUp(): void {
    $this->keyValues = [
      DiliosBackup::DILIOS_TIMESTAMP_DAILY . '_last_timestamp' => FALSE,
      DiliosBackup::DILIOS_TIMESTAMP_WEEKLY . '_last_timestamp' => $this->timeMock()->getTimestamp(),
      DiliosBackup::DILIOS_TIMESTAMP_MONTHLY . '_last_timestamp' => $this->timeMock()->getTimestamp(),
    ];

    // Mock DiliosRequester.
    /** @var \Drupal\sprintive_dilios_client\DiliosRequesterInterface&MockObject $requester */
    $requester = $this->getMockBuilder(DiliosRequester::class)->disableOriginalConstructor()->onlyMethods(['getBackupDetails'])->getMock();
    $requester->method('getBackupDetails')->will($this->returnCallback(function () {
      return $this->backupRequestData;
    }));

    // Mock Config and Configfactory.
    /** @var \Drupal\Core\Config\Config&MockObject $config */
    $config = $this->getMockBuilder(Config::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
    $config->method('get')->will($this->returnCallback([$this, 'configGetMock']));

    /** @var \Drupal\Core\Config\ConfigFactoryInterface&MockObject $config_factory */
    $config_factory = $this->getMockBuilder(ConfigFactory::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
    $config_factory->method('get')->willReturn($config);

    // Mock keyvalue.
    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface&MockObject $key_value */
    $key_value = $this->getMockBuilder(DatabaseStorage::class)->disableOriginalConstructor()->onlyMethods(['set', 'get'])->getMock();
    $key_value->method('get')->will($this->returnCallback([$this, 'keyValueGetMock']));
    $key_value->method('set')->will($this->returnCallback([$this, 'keyValueSetMock']));

    // Mock keyvalue factory.
    $key_value_factory = $this->getMockBuilder(KeyValueFactory::class)->disableOriginalConstructor()->onlyMethods(['get'])->getMock();
    $key_value_factory->method('get')->willReturn($key_value);

    // Mock DiliosBackupTimestamp
    /** @var \Drupal\sprintive_dilios_client\DiliosBackupTimestampInterface&MockObject $dilios_backup_timestamp */
    $dilios_backup_timestamp = $this->getMockBuilder(DiliosBackupTimestamp::class)->onlyMethods(['time'])->setConstructorArgs([$key_value_factory])->getMock();
    $dilios_backup_timestamp->method('time')->willReturn($this->returnCallback([$this, 'timeMock']));

    // Mock DiliosBackupGenerator.
    /** @var \Drupal\sprintive_dilios_client\DiliosBackupGeneratorInterface&MockObject $dilios_backup_generator */
    $dilios_backup_generator = $this->getMockBuilder(DiliosBackupGenerator::class)->disableOriginalConstructor()->onlyMethods(['generate', 'cleanUp'])->getMock();
    $dilios_backup_generator->method('generate')->will($this->returnCallback([$this, 'generateMock']));
    $dilios_backup_generator->method('cleanUp')->willReturnSelf();

    // Constructs a DiliosBackup object.
    $this->diliosBackup = new DiliosBackup($dilios_backup_generator, $dilios_backup_timestamp, $requester, $config_factory);
  }

  public function configGetMock($key) {
    return $this->diliosConfig[$key] ?? NULL;
  }

  public function keyValueGetMock($key) {
    return $this->keyValues[$key] ?? FALSE;
  }

  public function keyValueSetMock($key, $value) {
    $this->keyValues[$key] = $value;
  }

  /**
   * Mock 'time' method.
   *
   * @return \Datetime
   */
  public function timeMock() {
    return \Datetime::createFromFormat('Y-m-d', $this->currentDate);
  }

  public function generateMock($occurence, \Datetime $datetime, $expire_timestamp = NULL) {
    $date = $datetime->format('Y-m-d');
    $space = 'test-space';

    if ($occurence == DiliosBackup::DILIOS_TIMESTAMP_DAILY) {
      $this->dailyGenerated = "{$space}-$occurence-$date.gz";
    }

    if ($occurence == DiliosBackup::DILIOS_TIMESTAMP_WEEKLY) {
      $this->weeklyGenerated = "{$space}-$occurence-$date.gz";
    }

    if ($occurence == DiliosBackup::DILIOS_TIMESTAMP_MONTHLY) {
      $this->monthlyGenerated = "{$space}-$occurence-$date.gz";
    }

    if ($occurence == DiliosBackup::DILIOS_TIMESTAMP_CUSTOM) {
      $this->customGenerated = "{$space}-$occurence-$date.gz";
      $this->customExpires = $expire_timestamp;
    }
  }

  /**
   * Tests cronjob backup.
   *
   */
  public function testOccurenceBackup() {
    // First backup, because 'daily_last_timestamp' is null, and the others are not. We expect only a daily backup genreated for the first function.
    $this->diliosBackup->startBackup();
    $this->assertEquals('test-space-daily-2021-01-01.gz', $this->dailyGenerated);
    $this->assertNull($this->weeklyGenerated);
    $this->assertNull($this->monthlyGenerated);
    // We expect changes on the keyvalues.
    $first_timestamp = $this->timeMock()->getTimestamp();
    $this->assertEquals($first_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_DAILY . '_last_timestamp'));
    $this->assertEquals($first_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_WEEKLY . '_last_timestamp'));
    $this->assertEquals($first_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_MONTHLY . '_last_timestamp'));

    // Run it again, it should not generate a new backup.
    $this->dailyGenerated = NULL;
    $this->diliosBackup->startBackup();
    $this->assertNull($this->dailyGenerated);
    $this->assertNull($this->weeklyGenerated);
    $this->assertNull($this->monthlyGenerated);

    // Advance a day.
    $this->currentDate = '2021-01-02';
    $this->diliosBackup->startBackup();
    $this->assertEquals('test-space-daily-2021-01-02.gz', $this->dailyGenerated);
    $this->assertNull($this->weeklyGenerated);
    $this->assertNull($this->monthlyGenerated);

    // We expect changes on the keyvalues.
    $second_timestamp = $this->timeMock()->getTimestamp();
    $this->assertEquals($second_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_DAILY . '_last_timestamp'));
    $this->assertEquals($first_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_WEEKLY . '_last_timestamp'));
    $this->assertEquals($first_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_MONTHLY . '_last_timestamp'));

    // Advance 6 days.
    $this->currentDate = '2021-01-08';
    $this->diliosBackup->startBackup();
    $this->assertEquals('test-space-daily-2021-01-08.gz', $this->dailyGenerated);
    $this->assertEquals('test-space-weekly-2021-01-08.gz', $this->weeklyGenerated);
    $this->assertNull($this->monthlyGenerated);

    // We expect changes on the keyvalues.
    $third_timestamp = $this->timeMock()->getTimestamp();
    $this->assertEquals($third_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_DAILY . '_last_timestamp'));
    $this->assertEquals($third_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_WEEKLY . '_last_timestamp'));
    $this->assertEquals($first_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_MONTHLY . '_last_timestamp'));

    // Advance to the next month.
    $this->currentDate = '2021-02-01';
    $this->diliosBackup->startBackup();
    $this->assertEquals('test-space-daily-2021-02-01.gz', $this->dailyGenerated);
    $this->assertEquals('test-space-weekly-2021-02-01.gz', $this->weeklyGenerated);
    $this->assertEquals('test-space-monthly-2021-02-01.gz', $this->monthlyGenerated);

    // We expect changes on the keyvalues.
    $fourth_timestamp = $this->timeMock()->getTimestamp();
    $this->assertEquals($fourth_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_DAILY . '_last_timestamp'));
    $this->assertEquals($fourth_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_WEEKLY . '_last_timestamp'));
    $this->assertEquals($fourth_timestamp, $this->keyValueGetMock(DiliosBackup::DILIOS_TIMESTAMP_MONTHLY . '_last_timestamp'));
  }

  /**
   * Tests the 'customBackup' method.
   */
  public function testCustomBackup() {
    $this->diliosBackup->customBackup(10);
    $this->assertEquals($this->customGenerated, 'test-space-custom-2021-01-01.gz');
    $this->assertEquals($this->customExpires, $this->timeMock()->modify('+10 days')->getTimestamp());
  }

  /**
   * Tests disabling daily, weekly and monthly backups.
   *
   */
  public function testDisablingBackups() {
    $this->backupRequestData['daily'] = 0;
    $this->backupRequestData['weekly'] = 0;
    $this->backupRequestData['monthly'] = 0;
    $this->diliosBackup->reInit();
    $this->diliosBackup->startBackup();
    $this->assertNull($this->dailyGenerated);
    $this->assertNull($this->weeklyGenerated);
    $this->assertNull($this->monthlyGenerated);
    $this->backupRequestData['daily'] = 1;
    $this->diliosBackup->reInit();
    $this->diliosBackup->startBackup();
    $this->assertNotNull($this->dailyGenerated);
    $this->assertNull($this->weeklyGenerated);
    $this->assertNull($this->monthlyGenerated);
  }
}