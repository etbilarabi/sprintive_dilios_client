<?php

namespace Drupal\sprintive_dilios_client;

use Consolidation\Config\Util\ArrayUtil;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;

/**
 * Class DiliosRequester.
 */
class DiliosRequester implements DiliosRequesterInterface {

  const CACHE_BACKUP_DETAILS = 'dilios_backup_details';

  /**
   * GuzzleHttp\ClientInterface definition.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Drupal\Core\Cache\CacheBackendInterface definition.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheDefault;

  /**
   * The dilios configurations.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $diliosConfig;

  /**
   * Constructs a new DiliosRequester object.
   */
  public function __construct(ClientInterface $http_client, CacheBackendInterface $cache_default, ConfigFactoryInterface $config_factory) {
    $this->httpClient = $http_client;
    $this->cacheDefault = $cache_default;
    $this->diliosConfig = $config_factory->get('sprintive_dilios_client.settings');
  }

  /**
   * Requests dilios api.
   */
  protected function requestJson($method, $uri, $options = []) {
    $url = trim($this->diliosConfig->get('dilios.url'), '/') . '/' . trim($uri, '/');
    $username = $this->diliosConfig->get('dilios.username');
    $password = $this->diliosConfig->get('dilios.password');
    $auth = $username && $password ? [$username, $password] : [];
    $api_key = $this->diliosConfig->get('dilios.key');
    if (!$url || !$api_key) {
      throw new InvalidArgumentException('Dilios client settings are not setup');
    }

    $required_options = [
      'headers' => [
        'DILIOS-API-KEY' => $this->diliosConfig->get('dilios.key'),
      ],
      'auth' => $auth,
    ];
    $response = $this->httpClient->request($method, $url, ArrayUtil::mergeRecursiveDistinct($required_options, $options));

    $json = (string) $response->getBody();
    return json_decode($json, TRUE);
  }

  /**
   * {@inheritDoc}
   */
  public function getBackupDetails() {
    $backup_details = &drupal_static(self::CACHE_BACKUP_DETAILS);
    if (!$backup_details) {
      $backup_details = $this->requestJson('GET', '/backup/' . $this->diliosConfig->get('dilios.repo_name'));
    }

    return $backup_details;
  }

  /**
   * {@inheritDoc}
   */
  public function sendLogs($logs, $repo_name) {
    return $this->requestJson('POST', '/log', [
      'connect_timeout' => 4,
      'json' => [
        'logs' => $logs,
        'project_repo' => $repo_name,
      ],
    ]);
  }
}
