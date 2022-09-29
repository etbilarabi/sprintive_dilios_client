<?php

namespace Drupal\sprintive_dilios_client\Logger;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Logger\LogMessageParserInterface;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Exception;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class DiliosLogger implements LoggerInterface {
  use RfcLoggerTrait;
  use StringTranslationTrait;

/**
 * The config object
 *
 * @var \Drupal\Core\Config\Config
 */
  protected $config;

/**
 * The message's placeholders parser.
 *
 * @var \Drupal\Core\Logger\LogMessageParserInterface
 */
  protected $parser;

  /**
   * The request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * If TRUE, it will throw an exception
   *
   * @var bool
   */
  public $throwException = FALSE;

  /**
   * The current count of dilios_log_messages
   *
   * @var int
   */
  protected $count = NULL;

  /**
   * Constructs a "DiliosLogger"
   *
   * @param ClientInterface $client
   * @param ConfigFactoryInterface $config_factory
   */
  public function __construct(ConfigFactoryInterface $config_factory, LogMessageParserInterface $parser, RequestStack $request_stack, AccountInterface $current_user, Connection $connection) {
    $this->config = $config_factory->get('sprintive_dilios_client.settings');
    $this->parser = $parser;
    $this->request = $request_stack->getCurrentRequest();
    $this->currentUser = $current_user;
    $this->database = $connection;
  }

  /**
   * {@inheritDoc}
   */
  public function log($level, $message, array $context = []) {
    if (isset($GLOBALS['stop_dilios_logger']) && $GLOBALS['stop_dilios_logger'] == TRUE) {
      return;
    }

    $error_levels = [RfcLogLevel::ERROR, RfcLogLevel::CRITICAL, RfcLogLevel::EMERGENCY, RfcLogLevel::CRITICAL];
    $warning_levels = [RfcLogLevel::WARNING, RfcLogLevel::ALERT];
    $type = NULL;
    if (in_array($level, $error_levels)) {
      $type = 1;
    } else if (in_array($level, $warning_levels)) {
      $type = 2;
    }

    $url = $this->config->get('dilios.url');
    $api_key = $this->config->get('dilios.key');
    $repo_name = $this->config->get('dilios.repo_name');
    $send_right_now = $this->config->get('dilios.send_right_now');

    if (!isset($this->count)) {
      $count_query = $this->database->select('dilios_log_messages', 'D');
      $this->count = $count_query->countQuery()->execute()->fetchField();
    }

    if ($type && $url && $api_key && $repo_name) {

      // Remove backtrace and exception since they may contain an unserializable variable.
      unset($context['backtrace'], $context['exception']);
      // Convert PSR3-style messages to \Drupal\Component\Render\FormattableMarkup
      // style, so they can be translated too in runtime.
      if(is_string($message)) {
        $message_placeholders = $this->parser->parseMessagePlaceholders($message, $context);
        $message = $this->t($message, $message_placeholders);
      }

      $data = [
        'description' => $message,
        'type' => $type,
        'ip' => isset($context['ip']) ? $context['ip'] : $this->request->getClientIp(),
        'uid' => $this->currentUser->id(),
        'request_uri' => $this->request->getRequestUri(),
        'host' => $this->request->getHost(),
      ];

      if ($type === 1 && $send_right_now) {
        // If it is an error, send the log immediatly.
        self::sendLogs([$data]);
      } else {
        // Create a record on the table, and send it later using a cron job.

        try {
          // Before we insert, we check the limit first.
          if ($this->count <= 300) {
            $this->database->insert('dilios_log_messages')->fields([
              'data' => serialize($data),
            ])->execute();
          }
        } catch (Exception $e) {
          $GLOBALS['stop_dilios_logger'] = TRUE;
        }

      }
    }
  }

  /**
   * Sends a log using POST request
   *
   * @param array $logs
   */
  public static function sendLogs($logs) {
    $config = \Drupal::configFactory()->get('sprintive_dilios_client.settings');
    $repo_name = $config->get('dilios.repo_name');
    $data_to_send = [];

    foreach ($logs as $log) {
      // Try to load the user.
      $user = NULL;
      if (isset($uid)) {
        $user = \Drupal::entityTypeManager()->getStorage('user')->load($uid);
      }

      if (!$user) {
        $user = \Drupal::currentUser();
      }

      $data_to_send[] = [
        'type' => @$log['type'],
        'description' => @$log['description'],
        'user' => $user ? "{$user->getDisplayName()} ({$user->id()})" : "- Unknown -",
        'ip' => @$log['ip'],
        'link' => @$log['request_uri'],
        'project_hostname' => @$log['host'],
      ];
    }

    if ($repo_name) {
      try {
        \Drupal::service('sprintive_dilios_client.requester')->sendLogs($data_to_send, $repo_name);
      } catch (GuzzleException $e) {
        if (isset($GLOBALS['dilios_log_exception']) && $GLOBALS['dilios_log_exception'] == TRUE) {
          throw $e;
        }
      }
    }
  }
}