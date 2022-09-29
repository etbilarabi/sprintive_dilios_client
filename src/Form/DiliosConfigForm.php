<?php

namespace Drupal\sprintive_dilios_client\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\sprintive_dilios_client\Logger\DiliosLogger;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class DiliosConfigForm.
 */
class DiliosConfigForm extends ConfigFormBase {

  /**
   * The current user
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->currentUser = $container->get('current_user');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'sprintive_dilios_client.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'dilios_config_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('sprintive_dilios_client.settings');

    $form['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#description' => $this->t('Username for the simple auth'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('username'),
    ];
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('Password'),
      '#description' => $this->t('Password for the simple auth'),
      '#maxlength' => 64,
      '#size' => 64,
      '#default_value' => $config->get('password'),
    ];

    $form['dilios'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Dilios API'),
      '#tree' => TRUE,
      'url' => [
        '#type' => 'textfield',
        '#title' => $this->t('API Url'),
        '#default_value' => $config->get('dilios.url'),
      ],
      'key' => [
        '#type' => 'textfield',
        '#title' => $this->t('API Key'),
        '#default_value' => $config->get('dilios.key'),
      ],
      'repo_name' => [
        '#type' => 'textfield',
        '#title' => $this->t('Repo Name'),
        '#default_value' => $config->get('dilios.repo_name'),
      ],
      'username' => [
        '#type' => 'textfield',
        '#title' => $this->t('Basic Auth: Username'),
        '#default_value' => $config->get('dilios.username'),
      ],
      'password' => [
        '#type' => 'textfield',
        '#title' => $this->t('Basic Auth: Password'),
        '#default_value' => $config->get('dilios.password'),
      ],
      'test' => [
        '#type' => 'submit',
        '#value' => $this->t('Test'),
        '#submit' => ['::submitForm', '::testApi'],
      ],
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * Test the logger API
   *
   * @return void
   */
  public function testApi(array &$form, FormStateInterface $form_state) {
    try {
      /** @var \Drupal\sprintive_dilios_client\DiliosSiteManager $dilios_site_manager */
      $GLOBALS['dilios_log_exception'] = TRUE;
      DiliosLogger::sendLogs([
        [
          'description' => 'This is a test',
          'type' => 1,
          'ip' => \Drupal::request()->getClientIp(),
          'request_uri' => \Drupal::request()->getRequestUri(),
          'host' => \Drupal::request()->getHost(),
        ],
      ]);
      $this->messenger()->addStatus('The test was successful');
    } catch (GuzzleException $e) {
      $this->messenger()->addError($e->getMessage());
    }

    // Try connecting to digital ocean.
    try {
      \Drupal::service('sprintive_dilios_client.backup_generator')->testBackup();
      $this->messenger()->addMessage('Backups look fine. Make sure to run "drush sgb" to generate a custom backup for more testing.');
    } catch (Exception $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $config = $this->config('sprintive_dilios_client.settings');
    if ($form_state->getValue('password')) {
      $config->set('password', $form_state->getValue('password'));
    }

    $config->set('username', $form_state->getValue('username'))
      ->set('dilios', $form_state->getValue('dilios'))
      ->save();
  }
}
