<?php

namespace Drupal\sprintive_dilios_client;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\DatabaseExceptionWrapper;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Extension\ProfileExtensionList;
use Drupal\Core\Link;
use Drupal\Core\Render\RendererInterface;
use Drupal\editor\Entity\Editor;
use Drupal\node\Entity\NodeType;
use Drupal\node\NodeInterface;
use Drupal\rabbit_hole\BehaviorSettingsManagerInterface;
use Drupal\system\SystemManager;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\webform\Plugin\WebformHandler\EmailWebformHandler;
use Drupal\yoast_seo\YoastSeoFieldManager;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\TransferStats;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Class DiliosSiteManager.
 */
class DiliosSiteManager {

  private const DEV_MODULES = [
    'devel',
    'blazy_ui',
    'slick_ui',
    'views_ui',
    'webform_ui',
  ];

  private const SECURITY_MODULES = [
    'captcha',
    'recaptcha',
    'password_policy',
    'rabbit_hole',
    'rh_node',
    'rh_taxonomy',
    'username_enumeration_prevention',
  ];

  private const PROHIBITED_USERNAMES = [
    'root',
    'administrator',
    'admin',
    'editor',
    'editorial',
    'user',
  ];

  /**
   * The config manager service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $configFactory;

  /**
   * The Module Handler Interface.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The entity type manager interface.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * System Manager.
   *
   * @var \Drupal\system\SystemManager
   */
  private $systemManager;

  /**
   * Profile Extension List service.
   *
   * @var \Drupal\Core\Extension\ProfileExtensionList
   */
  private $profileExtensionList;

  /**
   * Rabbit Hole Behavior Settings Manager Interface.
   *
   * @var \Drupal\rabbit_hole\BehaviorSettingsManagerInterface
   */
  private $behaviorSettingsManager;

  /**
   * Yoast SEO Field Manager Interface.
   *
   * @var \Drupal\yoast_seo\YoastSeoFieldManager
   */
  private $yoastSeoFieldManager;

  /**
   * The http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $client;

  /**
   * The request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a new DiliosSiteManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   * @param \Drupal\system\SystemManager $system_manager
   * @param \Drupal\Core\Extension\ProfileExtensionList $profile_extension_list
   * @param \Drupal\rabbit_hole\BehaviorSettingsManagerInterface $behavior_settings_manager
   * @param \Drupal\yoast_seo\YoastSeoFieldManager $yoast_seo_field_manager
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ModuleHandlerInterface $module_handler,
    EntityTypeManagerInterface $entity_type_manager,
    SystemManager $system_manager,
    ProfileExtensionList $profile_extension_list,
    BehaviorSettingsManagerInterface $behavior_settings_manager,
    YoastSeoFieldManager $yoast_seo_field_manager,
    ClientInterface $client,
    RequestStack $request_stack,
    RendererInterface $renderer
  ) {
    $this->configFactory = $config_factory;
    $this->moduleHandler = $module_handler;
    $this->entityTypeManager = $entity_type_manager;
    $this->systemManager = $system_manager;
    $this->profileExtensionList = $profile_extension_list;
    $this->behaviorSettingsManager = $behavior_settings_manager;
    $this->yoastSeoFieldManager = $yoast_seo_field_manager;
    $this->client = $client;
    $this->request = $request_stack->getCurrentRequest();
    $this->renderer = $renderer;
  }

  /**
   *
   */
  public function checkAggregation() {
    $css_aggregation = $this->configFactory->get('system.performance')->get('css.preprocess');
    $js_aggregation = $this->configFactory->get('system.performance')->get('js.preprocess');

    return ($js_aggregation && $css_aggregation);
  }

  /**
   *
   */
  public function checkDevModules() {
    $enabled_modules = [];
    foreach (self::DEV_MODULES as $DEV_MODULE) {
      if ($this->checkModuleEnabled($DEV_MODULE)) {
        $enabled_modules[] = $this->moduleHandler->getName($DEV_MODULE);
      }
    }

    return !empty($enabled_modules) ? $enabled_modules : 'Development modules are not enabled';
  }

  /**
   *
   */
  public function checkGoogleAnalytics() {
    $analytics_id = '';
    if ($this->moduleHandler->moduleExists('google_analytics')) {
      $analytics_id = $this->configFactory->get('google_analytics.settings')->get('account');
    }
    else {
      return [FALSE, 'Google analytics is not enabled'];
    }

    if ($analytics_id) {
      // Check caching.
      $cache = (bool) $this->configFactory->get('google_analytics.settings')->get('cache');
      if (!$cache) {
        return [FALSE, "Caching is disabled"];
      }

      return [TRUE, sprintf('Google analytics is configured with the ID: <strong>%s</strong>', $analytics_id)];
    }
    else {
      return [FALSE, 'Google analytics is not configured'];
    }
  }

  /**
   *
   */
  public function checkRealTimeSEO() {
    $realtime_seo = [];
    $bundles = $this->nodeTypesWithLanding();
    $status = TRUE;
    foreach ($bundles as $bundle) {
      if (!$this->yoastSeoFieldManager->isAttached('node', $bundle, 'field_yoast_seo')) {
        $realtime_seo[] = sprintf("The bundle \"%s\" does not have Yoast SEO setup", $bundle);
        $status = 'warning';
      }
    }

    if (empty($realtime_seo)) {
      $realtime_seo = 'All bundles have Yoast SEO';
    }

    return [$status, $realtime_seo];
  }

  /**
   *
   */
  public function checkLengthIndicator() {
    $length_indicator = [];
    $status = TRUE;
    $bundles = $this->nodeTypesWithLanding();

    if (!$this->checkModuleEnabled('length_indicator')) {
      return [FALSE, 'Length indicator module is not enabled'];
    }

    foreach ($bundles as $bundle) {
      $title_config = $this->configFactory->get("core.entity_form_display.node.$bundle.default")->get('content.title');
      $length_indicator_config = $title_config['third_party_settings']['length_indicator'] ?? NULL;
      if (isset($length_indicator_config) && $length_indicator_config['indicator'] === TRUE) {
        $min = $length_indicator_config['indicator_opt']['optimin'];
        $max = $length_indicator_config['indicator_opt']['optimax'];
        $tolerance = $length_indicator_config['indicator_opt']['tolerance'];

        if ($min !== 30 || $max !== 60 || $tolerance !== 15) {
          $length_indicator[] = sprintf('The bundle "%s" has a length indicator, but the values are wrong, make sure that min==30 and max == 60 and tolerance == 15', $bundle);
          $status = 'warning';
        }
      }
      else {
        $length_indicator[] = sprintf('The bundle "%s" has no length indicator', $bundle);
        $status = 'warning';
      }

    }

    if (empty($length_indicator)) {
      $length_indicator = "All nodes have length indicator setup correctly";
    }

    return [$status, $length_indicator];
  }

  /**
   * Gets the robot txt.
   *
   * @return void
   */
  public function robotTxt() {
    $result = file_get_contents(DRUPAL_ROOT . '/robots.txt');
    if ($result) {
      return [TRUE, 'Found robots.txt, apache can read it'];
    }
    else {
      return [FALSE, 'Could not read robots.txt file, make sure it exists, or the permissions are set correctly'];
    }
  }

  /**
   *
   */
  public function checkRedirect() {
    $host = $this->request->getHost();
    // Add www on the beginning.
    if (substr($host, 0, 4) !== 'www.') {
      $host = 'www.' . $host;
    }

    try {
      // Call using guzzle.
      $this->client->request('GET', $this->request->getScheme() . '://' . $host, [
        'allow_redirects' => TRUE,
        'on_stats' => function (TransferStats $stats) use (&$host) {
          $host = $stats->getEffectiveUri()->getHost();
        },
      ]);

      return substr($host, 0, 4) !== 'www.';
    }
    catch (GuzzleException $e) {
      return FALSE;
    }
  }

  /**
   *
   */
  public function checkSecurityModules() {
    $disabled_modules = [];
    $status = TRUE;
    foreach (self::SECURITY_MODULES as $SECURITY_MODULE) {
      if (!$this->checkModuleEnabled($SECURITY_MODULE)) {
        $disabled_modules[$SECURITY_MODULE] = $this->moduleHandler->getName($SECURITY_MODULE);
      }
    }

    if (count($disabled_modules) == 2 && in_array('password_policy', array_keys($disabled_modules)) && in_array('username_enumeration_prevention', array_keys($disabled_modules))) {
      $status = 'warning';
    }
    elseif (!empty($disabled_modules)) {
      $status = FALSE;
    }
    else {
      $status = TRUE;
    }

    return [$status, empty($disabled_modules) ? "All security modules are enabled" : $disabled_modules];
  }

  /**
   *
   */
  public function checkProhibitedUsernames() {
    $prohibited_usernames = [];

    foreach ($this::PROHIBITED_USERNAMES as $PROHIBITED_USERNAME) {
      $user = $this->loadUsersByProperties(['name' => $PROHIBITED_USERNAME]);
      if (!empty($user)) {
        $prohibited_usernames[] = $PROHIBITED_USERNAME;
      }
    }

    return empty($prohibited_usernames) ? "No prohibited username is used" : $prohibited_usernames;
  }

  /**
   *
   */
  public function checkStatusReport() {
    $requirements = $this->systemManager->listRequirements();
    $issues = [];

    foreach ($requirements as $requirement) {
      if (isset($requirement['severity']) && $requirement['severity'] > 0) {
        $issues[] = $requirement;
      }
    }

    return $issues;
  }

  /**
   *
   */
  public function checkSeedsProfileInfo() {
    $installed_profiles = $this->profileExtensionList->getAllInstalledInfo();
    if (array_key_exists('seeds', $installed_profiles)) {
      $seeds_info = $this->profileExtensionList->getExtensionInfo('seeds');
    }

    return isset($seeds_info) ? $seeds_info['version'] : "Seeds in NOT installed";
  }

  /**
   * Gets the php.
   *
   * @return string
   */
  public function getPhpVersion() {
    return phpversion();
  }

  /**
   * Gets Drupal version.
   *
   * @return string
   */
  public function getDrupalVersion() {
    return \Drupal::VERSION;
  }

  /**
   * Gets the date of public of the default varient.
   *
   * @return string|null
   *   Return the published date, if available. Otherwise return null.
   */
  public function getSitemapPublishDate() {
    try {
      $publish_timestamp = \Drupal::database()->select('simple_sitemap', 'S')
        ->fields('S', ['sitemap_created'])
        ->condition('type', 'default')->execute()->fetchField();
      if ($publish_timestamp) {
        return \Drupal::service('date.formatter')->format($publish_timestamp);
      }
    }
    catch (DatabaseExceptionWrapper $e) {
      // No table found, simply assume that sitemap is not available.
    }

    return NULL;
  }

  /**
   *
   */
  public function checkFrontTitle() {
    $page_front = $this->configFactory->get('system.site')->get('page.front');
    $front_nid = str_replace('/node/', '', $page_front);
    $node = $this->entityTypeManager->getStorage('node')->load($front_nid);
    if ($node instanceof NodeInterface) {
      $results = [];
      // Get every language.
      $langauges = \Drupal::languageManager()->getLanguages();
      foreach ($langauges as $language) {
        $results[] = "In {$language->getName()}: " . ($node->hasTranslation($language->getId()) ? $node->getTranslation($language->getId())->getTitle() : $node->getTitle());
      }
      return $results;
    }

    if ($page_front) {
      return "Unknown Title from route \"$page_front\"";
    }

    return 'Could NOT load homepage!';
  }

  /**
   * Gets info related to the recaptcha module.
   *
   * @return array|false
   */
  public function getRecaptchaInfo() {
    if (!$this->checkModuleEnabled('recaptcha')) {
      return [FALSE, 'The module is not enabled'];
    }
    $captcha_settings = $this->configFactory->get('captcha.settings');
    $recaptcha_settings = $this->configFactory->get('recaptcha.settings');
    $results = [];
    $recaptcha_is_the_default = $captcha_settings->get('default_challenge') == 'recaptcha/reCAPTCHA';
    $wrong_settings = FALSE;
    $no_points = FALSE;
    if (!$recaptcha_is_the_default) {
      $results[] = 'The reCaptcha challenge is not the default challenge';
    }

    // Get captcha points.
    /** @var \Drupal\captcha\CaptchaPointInterface[] $points */
    $points = $this->entityTypeManager->getStorage('captcha_point')->loadByProperties([
      'status' => TRUE,
    ]);

    foreach ($points as $point) {
      $results[] = "The captcha point \"{$point->getFormId()}\" is enabled";
    }

    if (empty($points)) {
      $no_points = TRUE;
      $results[] = 'There is no captcha point enabled';
    }

    // Check the key.
    if (!$recaptcha_settings->get('site_key') || !$recaptcha_settings->get('secret_key')) {
      $wrong_settings = TRUE;
      $results[] = 'The recaptcha settings are not set correctly';
    }

    return [$recaptcha_is_the_default && !$wrong_settings && !$no_points, $results];

  }

  /**
   * Gets the securty updates for modules.
   *
   * @return void
   */
  public function getModulesUpdates() {
    /** @var \Drupal\update\UpdateManagerInterface $update_manager */
    $update_manager = \Drupal::service('update.manager');
    $available_updates = [];
    $projects = $update_manager->projectStorage('update_project_data');
    $has_security = FALSE;
    foreach ($projects as $id => $project) {
      if (version_compare($project['recommended'], $project['existing_version'], '>')) {
        $suffix = "";
        if (isset($project['security updates'])) {
          $has_security = TRUE;
          $suffix = " <strong class='text-danger'>(Security Update) <i class=\"fa fa-exclamation-circle\" aria-hidden=\"true\"></i></strong>";
        }

        if ($id == 'seeds' || $id == 'drupal') {
          $available_updates[] = "<strong>{$project['title']} ({$project['existing_version']})</strong> can be updated to \"{$project['recommended']}\"$suffix";
        }
        else {
          $available_updates[] = "The module \"<strong>{$project['title']} ({$project['existing_version']})</strong>\" can be updated to \"{$project['recommended']}\"$suffix";
        }
      }
    }
    return [$has_security, $available_updates];
  }

  /**
   * Gets the status of view modes.
   *
   * @return array
   */
  public function getViewModes() {
    $status = TRUE;
    $result = [];
    if (!$this->checkModuleEnabled('ds')) {
      $status = 'warning';
      $result[] = 'Display suite is not enabled';
    }

    if (!$this->checkModuleEnabled('layout_builder')) {
      $status = 'warning';
      $result[] = 'Layout builder is not enabled';
    }

    if (!$this->checkModuleEnabled('layout_builder') && !$this->checkModuleEnabled('ds')) {
      return [$status, $result];
    }

    $query = $this->entityTypeManager->getStorage('entity_view_display')->getQuery();
    $query->condition('status', TRUE);
    $group = $query->orConditionGroup();
    $group->condition('targetEntityType', 'node');
    $group->condition('targetEntityType', 'taxonomy_term');
    $query->condition($group);
    $view_displays = $query->execute();
    /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface[] $view_displays */
    $view_displays = $this->entityTypeManager->getStorage('entity_view_display')->loadMultiple($view_displays);

    foreach ($view_displays as $view_display) {
      // Layout builder.
      if ($view_display->getThirdPartySetting('layout_builder', 'enabled')) {
        $allow_custom = $view_display->getThirdPartySetting('layout_builder', 'allow_custom');
        $result[] = sprintf('The view display "<strong>%s</strong>" is using "<strong>Layout Builder</strong>" with "<strong>Customization</strong>" %s',
          $view_display->id(),
          $allow_custom ? '<span class="text-success">Enabled</span>' : '<span class="text-danger"></span>'
        );
      }
      elseif ($layout_settings = $view_display->getThirdPartySetting('ds', 'layout')) {
        // DS.
        $id = $layout_settings['id'];
        $wrapper = $layout_settings['settings']['outer_wrapper'];
        $warn = FALSE;
        if ($wrapper !== 'article') {
          $status = "warning";
          $warn = TRUE;
        }
        $result[] = sprintf('The view display "<strong>%s</strong>" is using "<strong>Display Suite</strong>" with the wrapper "%s"',
          $view_display->id(),
          sprintf("<span class='%s'>{$wrapper}</span>", $warn ? 'text-warning' : 'text-success')
              );
      }
      else {
        $status = "warning";
        $result[] = sprintf('<span class="text-warning">The view display "<strong>%s</strong>" does not have any known enabled plugins or modules</span>', $view_display->id());
      }
    }

    return [$status, $result];
  }

  /**
   * Check the status of body fields and if they are using the 'basic_editor'
   *
   * @return void
   */
  public function basicEditorBodyFields() {
    $result = [];
    /** @var \Drupal\Core\Field\FieldConfigStorageBase $storage */
    $storage = $this->entityTypeManager->getStorage('field_config');
    $fields = $storage->getQuery('OR')->condition('field_type', 'text_with_summary')->condition('field_type', 'text_long')->execute();
    /** @var \Drupal\Core\Field\FieldConfigInterface[] $fields */
    $fields = $storage->loadMultiple($fields);
    $editors = Editor::loadMultiple();
    foreach ($fields as $field) {
      $ul = [
        '#type' => 'html_tag',
        '#tag' => 'ul',
      ];
      foreach ($editors as $editor) {
        $allowed = (bool) $field->getThirdPartySetting('allowed_formats', $editor->id(), FALSE);
        if ($allowed) {
          $ul[] = [
            '#type' => 'html_tag',
            '#tag' => 'li',
            '#value' => sprintf('The editor "%s" is allowed', $editor->label()),
          ];
        }
      }

      if (count($ul) === 2) {
        $ul = ['#plain_text' => 'No allowed formats present'];
      }

      $render = [
        'field_name' => ['#markup' => sprintf('<p><strong>%s (%s.%s)</strong><p>', $field->label(), $field->getTargetEntityTypeId(), $field->getTargetBundle())],
        'ul' => $ul,
      ];

      $result[] = $this->renderer->renderPlain($render);
    }

    return $result;
  }

  /**
   * Gets a mapping between.
   *
   * @return void
   */
  public function getUrlPatterns() {
    $node_type_list = $taxonomy_vocabulary_list = [];
    // Get Node types and vocabularies.
    /** @var \Drupal\node\NodeTypeInterface[] $node_types */
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    /** @var \Drupal\taxonomy\VocabularyInterface[] $vocabularies */
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    /** @var \Drupal\pathauto\PathautoPatternInterface[] $patterns */
    $patterns = $this->entityTypeManager->getStorage('pathauto_pattern')->loadMultiple();

    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface[] $all_entity_bundles */
    $all_entity_bundles = array_merge($node_types, $vocabularies);

    foreach ($all_entity_bundles as $entity_bundle) {
      $found_patterns = [];
      // Check if the bundle has a pattern.
      foreach ($patterns as $pattern) {
        $selections = $pattern->getSelectionConditions();
        foreach ($selections as $selection) {
          $config = $selection->getConfiguration();
          $bundles = $config["bundles"] ?? [];
          if ($config['id'] == $entity_bundle->getEntityTypeId() && in_array($entity_bundle->id(), $bundles)) {
            $found_patterns[] = $pattern->getPattern();
            break;
          }
        }
      }

      if (empty($found_patterns)) {
        ${$entity_bundle->getEntityTypeId() . '_list'}[] = [
          '#type' => 'html_tag',
          '#tag' => 'li',
          '#attributes' => [
            'class' => ['list-group-item', 'list-group-item-warning'],
          ],
          '#value' => sprintf('The bundle "%s" of "%s" has no patterns', $entity_bundle->id(), $entity_bundle->getEntityTypeId()),
        ];
      }
      else {
        $ul = [
          '#type' => 'html_tag',
          '#tag' => 'ul',
        ];

        foreach ($found_patterns as $pattern) {
          $ul[] = [
            '#type' => 'html_tag',
            '#tag' => 'li',
            '#value' => $pattern,
          ];
        }
        ${$entity_bundle->getEntityTypeId() . '_list'}[] = [
          '#type' => 'html_tag',
          '#tag' => 'li',
          '#attributes' => [
            'class' => ['list-group-item', 'list-group-item-success'],
          ],
          '#value' => sprintf('The bundle "%s" of "%s" has the patterns:', $entity_bundle->id(), $entity_bundle->getEntityTypeId()),
          'list' => $ul,
        ];
      }
    }

    $render = [
      't1' => ['#markup' => '<h2 class="text-center">Node Types</h2>'],
      'node_list' => [
        '#type' => 'html_tag',
        '#tag' => 'ul',
        '#attributes' => [
          'class' => ['list-group mb-3'],
        ],
      ] + $node_type_list,
      't2' => ['#markup' => '<h2 class="text-center">Vocabularies</h2>'],
      'vocabulary_list' => [
        '#type' => 'html_tag',
        '#tag' => 'ul',
        '#attributes' => [
          'class' => ['list-group'],
        ],
      ] + $taxonomy_vocabulary_list,
    ];

    return $this->renderer->renderPlain($render);
  }

  /**
   * Gets the status of the browser caching.
   *
   * @return array
   */
  public function getBrowserCachingStatus() {
    $performance = $this->configFactory->get('system.performance');
    $max_age_browser = (int) $performance->get('cache.page.max_age');

    if ($max_age_browser > 0) {
      $dtF = new \DateTime('@0');
      $dtT = new \DateTime("@$max_age_browser");
      return ['info', $dtF->diff($dtT)->format('%i minutes')];
    }
    else {
      return [FALSE, 'No caching'];
    }
  }

  /**
   * Gets the delete permissions.
   *
   * @return array
   */
  public function getDeletePermissions() {
    // Load all roles.
    /** @var \Drupal\user\RoleInterface[] $roles */
    $roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $results = [];
    $status = TRUE;
    foreach ($roles as $role) {
      $permissions = $role->getPermissions();
      $delete_permissions = array_filter($permissions, function ($permission) {
        return strpos($permission, 'delete') !== FALSE || strpos($permission, 'remove') !== FALSE;
      });

      if (!empty($delete_permissions)) {
        $status = 'warning';
        $results[] = sprintf('The role "%s" has the following potential delete permissions: <em>(%s)</em>', "{$role->label()} ({$role->id()})", implode(', ', $delete_permissions));
      }

    }

    if (empty($results)) {
      $results = 'No role has any delete permission (You should probably check manually too)';
    }

    return [$status, $results];
  }

  /**
   *
   */
  public function checkWebformHandlers() {
    if (!$this->checkModuleEnabled('webform')) {
      return 'Webform modules is not enabled';
    }

    $webforms = $this->entityTypeManager->getStorage('webform')->loadMultiple();
    $result = [];
    foreach ($webforms as $webform) {
      $webform_handlers = $webform->getHandlers();
      $has_email_handler = FALSE;
      foreach ($webform_handlers as $webform_handler) {
        if ($webform_handler instanceof EmailWebformHandler) {
          $has_email_handler = TRUE;
          $handler_config = $webform_handler->getEmailConfiguration();
          if (isset($handler_config['to_mail'])) {
            $result[] = "The webform \"{$webform->label()}\" has an email handler with the recipient \"{$handler_config['to_mail']}\"";
          }
          else {
            $result[] = "The webform \"{$webform->label()}\" has an email handler but with no recipient";
          }
        }
      }
      if (!$has_email_handler) {
        $result[] = "The webform \"{$webform->label()}\" DOES NOT have an email handler";
      }

    }

    return !empty($result) ? $result : 'The website DOES NOT have any webforms';
  }

  /**
   * Gets all dummy content.
   *
   * @return array
   */
  public function getDummyContent() {
    $result = [];
    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $group = $query->orConditionGroup();
    $group->condition('title', '%lorem%', 'LIKE')
      ->condition('title', '%ipsum%', 'LIKE')
      ->condition('title', 'test %', 'LIKE')
      ->condition('title', '% test %', 'LIKE')
      ->condition('title', '%test', 'LIKE')
      ->condition('title', 'test');
    $query->condition($group);
    $node_ids = $query->execute();
    /** @var \Drupal\node\NodeInterface[] $nodes */
    $nodes = $this->entityTypeManager->getStorage('node')->loadMultiple($node_ids);

    foreach ($nodes as $node) {
      $link = Link::fromTextAndUrl($node->getTitle(), $node->toUrl()->setOption('absolute', TRUE))->toRenderable();
      $result[] = $this->renderer->render($link);
    }

    return $result;
  }

  /**
   *
   */
  public function checkUsernames() {
    $user_role_matches = [];
    $user_roles = $this->entityTypeManager->getStorage('user_role')->loadMultiple();
    $roles_names = [];
    foreach ($user_roles as $role) {
      $roles_names[] = $role->label();
    }

    $users = $this->entityTypeManager->getStorage('user')->loadMultiple();
    foreach ($users as $user) {
      $username = $user->getUsername();
      foreach ($roles_names as $role_name) {
        if ($username && strpos($role_name, $username) !== FALSE) {
          $user_role_matches[] = [
            'uid' => $user->id(),
            'username' => $username,
            'role_name' => $role_name,
          ];
        }
      }
    }

    return !empty($user_role_matches) ? $user_role_matches : 'No matches found between any username and any role name';
  }

  /**
   *
   */
  public function checkWhoCanRegister() {
    return $this->configFactory->get('user.settings')->get('register');
  }

  /**
   *
   */
  public function checkModuleEnabled($module_name) {
    return $this->moduleHandler->moduleExists($module_name);
  }

  /**
   * Gets the website email and the root email.
   *
   * @return void
   */
  public function getEmailSettings() {
    $status = 'info';
    $result = [];
    // Get the website email.
    $website_email = $this->configFactory->get('system.site')->get('mail');
    // Get the root email.
    $root_email = NULL;
    /** @var \Drupal\user\UserInterface */
    $user = $this->entityTypeManager->getStorage('user')->load(1);
    if ($user) {
      $root_email = $user->getEmail();
    }

    $result[] = sprintf('The website email: <strong>%s</strong>', $website_email);
    $result[] = sprintf('The root email: <strong>%s</strong>', $root_email);

    if (strpos($website_email, 'sprintive') === FALSE || strpos($root_email, 'sprintive') === FALSE) {
      $status = 'warning';
    }

    return [$status, $result];
  }

  /**
   * Gets seeds toolbar support link.
   *
   * @return void
   */
  public function getSeedsToolbarSupport() {
    if (!$this->checkModuleEnabled('seeds_toolbar')) {
      return ['warning', 'Seeds toolbar is not enabled'];
    }

    $support_link = $this->configFactory->get('seeds_toolbar.settings')->get('support');
    if (empty($support_link)) {
      return ['warning', 'The support link is empty'];
    }
    else {
      return ['info', 'The support link is: ' . $support_link];
    }
  }

  /**
   * Gets the rabbit hole status of each node_type and vocabulary.
   *
   * @return void
   */
  public function getRabbitHole() {
    $result = [];

    // Check if the module is enabled.
    if (!$this->checkModuleEnabled('rabbit_hole')) {
      return [FALSE, 'Rabbit hole is not enabled'];
    }

    // Get all bundles of taxonomy and nodes.
    $node_types = NodeType::loadMultiple();
    $vocabularies = Vocabulary::loadMultiple();
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface[] $all_types */
    $all_types = array_merge($node_types, $vocabularies);
    /** @var \Drupal\rabbit_hole\BehaviorSettingsManagerInterface $rabbit_hole */
    $rabbit_hole = \Drupal::service('rabbit_hole.behavior_settings_manager');

    foreach ($all_types as $type) {
      $rabbit_hole_settings = $rabbit_hole->loadBehaviorSettingsAsConfig($type->getEntityTypeId(), $type->id());
      if ($rabbit_hole_settings) {
        $string = sprintf("<strong>%s</strong> of <strong>%s</strong>:<br> Action: %s",
          $type->getEntityType()->getLabel(),
          $type->label(),
          $rabbit_hole_settings->get('action')
        );
        if ($rabbit_hole_settings->get('action') == 'page_redirect') {
          $string .= sprintf(', Redirect To: %s, Redirect Code: %s, Redirect Fallback: %s',
            $rabbit_hole_settings->get('redirect'),
            $rabbit_hole_settings->get('redirect_code'),
            $rabbit_hole_settings->get('redirect_fallback_action'),

          );
        }
        $result[] = $string;
      }
    }

    return ['info', $result];
  }

  /**
   * Gets the status of simple sitemap.
   *
   * @return void
   */
  public function getSimpleSitemap() {
    $status = TRUE;
    $result = [];

    // Check if the module is enabled.
    if (!$this->checkModuleEnabled('simple_sitemap')) {
      return [FALSE, 'Simple sitemap is not enabled'];
    }

    /** @var \Drupal\simple_sitemap\Simplesitemap $simple_sitemap */
    $simple_sitemap = \Drupal::service('simple_sitemap.generator');
    // Get all bundles of taxonomy and nodes.
    $node_types = NodeType::loadMultiple();
    $vocabularies = Vocabulary::loadMultiple();
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface[] $all_types */
    $all_types = array_merge($node_types, $vocabularies);
    foreach ($all_types as $type) {
      $bundle_settings = $simple_sitemap->getBundleSettings($type->getEntityType()->getBundleOf(), $type->id());
      if ($bundle_settings && $bundle_settings['index']) {
        $result[] = sprintf('"<strong>%s</strong>" of "<strong>%s</strong>": Priority: %s, Change Freq: %s, Include Image: %s',
          $type->getEntityType()->getLabel(),
          $type->label(),
          $bundle_settings['priority'],
          $bundle_settings['changefreq'],
          $bundle_settings['include_images'] ? 'Includes' : "Not Included"
        );
      }
      else {
        $status = 'warning';
        $result[] = sprintf('"<strong>%s</strong>" of "<strong>%s</strong>" <span class="text-danger text-bold">does not</span> have a simple sitemap set up', $type->getEntityType()->getLabel(),
          $type->label(),);
      }
    }

    return [$status, $result];
  }

  /**
   * Get all content types that have landing page (Not redirected from rabbit hole)
   */
  private function nodeTypesWithLanding() {
    $bundles_with_landings = [];
    $bundles = $this->entityTypeManager->getStorage('node_type')->loadMultiple();

    foreach ($bundles as $bundle) {
      $rabbit_hole = $this->behaviorSettingsManager->loadBehaviorSettingsAsConfig('node_type', $bundle->id());
      if ($rabbit_hole->get('action') === 'display_page') {
        $bundles_with_landings[] = $bundle->id();
      }
    }

    return $bundles_with_landings;
  }

  /**
   *
   */
  private function loadUsersByProperties(array $props) {
    return $this->entityTypeManager->getStorage('user')->loadByProperties($props);
  }

}
