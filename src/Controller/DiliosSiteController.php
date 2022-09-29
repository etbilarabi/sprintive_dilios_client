<?php

namespace Drupal\sprintive_dilios_client\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Class DiliosSiteController.
 *
 * @package Drupal\sprintive_dilios_client\Controller
 */
class DiliosSiteController extends ControllerBase {

  /**
   * Dilios Manager.
   *
   * @var \Drupal\sprintive_dilios_client\DiliosSiteManager
   */
  protected $diliosSiteManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->diliosSiteManager = $container->get('sprintive_dilios_client.site_manager');
    return $instance;
  }

  /**
   * Get performance report about the website.
   */
  public function getPerformance() {
    $result = [];

    // Check CSS and JS aggregation settings.
    $aggregation = $this->diliosSiteManager->checkAggregation();
    $result['aggregation'] = [
      'name' => 'CSS and JS Aggregation',
      'result' => $aggregation ? 'Aggregation is working' : 'Aggregation is NOT working',
      'status' => $aggregation,
    ];

    // Check enabled development modules.
    $dev_modules = $this->diliosSiteManager->checkDevModules();
    $result['dev_modules'] = [
      'name' => 'Enabled Development Modules',
      'result' => $dev_modules,
      'status' => !is_array($dev_modules),
    ];

    // Check if ultimate cron is enabled.
    $ultimate_cron = $this->diliosSiteManager->checkModuleEnabled('ultimate_cron');
    $result['ultimate_cron'] = [
      'name' => 'Ultimate Cron',
      'result' => $ultimate_cron ? "Ultimate Cron is enabled" : "Ultimate Cron is NOT enabled",
      'status' => 'info',
    ];

    [$status, $browser_cache] = $this->diliosSiteManager->getBrowserCachingStatus();
    $result['browser_cache'] = [
      'name' => "Browser Cache",
      'result' => $browser_cache,
      'status' => $status,
    ];

    $redirect_is_enabled = $this->diliosSiteManager->checkModuleEnabled('redirect');
    $result['redirect'] = [
      'name' => 'Redirect Module',
      'result' => $redirect_is_enabled ? 'Redirect module is enabled' : 'Redirect module is not enabled',
      'status' => $redirect_is_enabled,
    ];

    return new JsonResponse($result);
  }

  /**
   * Get SEO report about the website.
   */
  public function getSEO() {
    $result = [];

    // Check Google Analytics.
    [$status, $google_analytics] = $this->diliosSiteManager->checkGoogleAnalytics();
    $result['google_analytics'] = [
      'name' => 'Google Analytics UA',
      'result' => $google_analytics,
      'status' => $status,
    ];

    // Check if simple sitemap is enabled.
    [$status, $simple_sitemap] = $this->diliosSiteManager->getSimpleSitemap();
    $result['simple_sitemap'] = [
      'name' => 'Simple Sitemap',
      'result' => $simple_sitemap,
      'status' => $status,
    ];

    // Check if Yoast SEO is added to content types that have landing page.
    [$status, $realtime_seo] = $this->diliosSiteManager->checkRealTimeSEO();
    $result['realtime_seo'] = [
      'name' => 'Real-Time SEO',
      'result' => $realtime_seo,
      'status' => $status,
    ];

    // Check if Length Indicator is configured for content types that have landing page.
    [$status, $length_indicator] = $this->diliosSiteManager->checkLengthIndicator();
    $result['length_indicator'] = [
      'name' => 'Title Length Indicator',
      'result' => $length_indicator,
      'status' => $status,
    ];

    // Check if website redirects to non-www.
    $redirect = $this->diliosSiteManager->checkRedirect();
    $result['www_redirect'] = [
      'name' => 'Redirect From WWW to Non-WWW',
      'result' => $redirect ? "Redirect to Non-WWW is enabled" : "Redirect to Non-WWW is NOT enabled",
      'status' => 'warning',
    ];

    // Check for dummy content.
    $dummy_content = $this->diliosSiteManager->getDummyContent();
    $result['dummy_content'] = [
      'name' => 'Dummy Content',
      'result' => empty($dummy_content) ? "There are no dummy content" : $dummy_content,
      'status' => empty($dummy_content),
    ];

    // Check masquerade module.
    $result['masquerade'] = [
      'name' => 'Masquerade',
      'result' => $this->diliosSiteManager->checkModuleEnabled('masquerade') ? 'Masquerade is enabled' : 'Masquerade is not enabled',
      'status' => $this->diliosSiteManager->checkModuleEnabled('masquerade'),
    ];

    $result['homepage_title'] = [
      'name' => 'Homepage Title',
      'result' => $this->diliosSiteManager->checkFrontTitle(),
      'status' => 'info',
    ];

    $handlers = $this->diliosSiteManager->checkWebformHandlers();
    $result['webform_handlers'] = [
      'name' => 'Webform Email Handlers',
      'result' => $handlers,
      'status' => 'info',
    ];

    // SMTP module.
    $result['smtp'] = [
      'name' => 'SMTP Module',
      'result' => $this->diliosSiteManager->checkModuleEnabled('smtp') ? 'The SMTP module is enabled' : 'The SMTP module is not enabled',
      'status' => 'info',
    ];

    $result['editors'] = [
      'name' => 'Allowed Formats',
      'result' => $this->diliosSiteManager->basicEditorBodyFields(),
      'status' => 'info',
    ];

    $result['patterns'] = [
      'name' => 'URL Patterns',
      'result' => $this->diliosSiteManager->getUrlPatterns(),
      'status' => 'info',
    ];

    [$status, $robot] = $this->diliosSiteManager->robotTxt();
    $result['robot'] = [
      'name' => 'Robot File',
      'result' => $robot,
      'status' => $status,
    ];

    [$status, $view_modes] = $this->diliosSiteManager->getViewModes();
    $result['view_modes'] = [
      'name' => 'View Modes',
      'result' => $view_modes,
      'status' => $status,
    ];

    [$status, $rabbit_hole] = $this->diliosSiteManager->getRabbitHole();
    $result['rabbit_hole'] = [
      'name' => 'Rabbit Hole',
      'result' => $rabbit_hole,
      'status' => $status,
    ];

    return new JsonResponse($result);
  }

  /**
   * Get security report about the website.
   */
  public function getSecurity() {
    $result = [];

    // Check disabled security modules.
    [$status, $security_modules] = $this->diliosSiteManager->checkSecurityModules();
    $result['security_modules'] = [
      'name' => 'Disabled Security Modules',
      'result' => $security_modules,
      'status' => $status,
    ];

    // Check for prohibited usernames.
    $prohibited_usernames = $this->diliosSiteManager->checkProhibitedUsernames();
    $result['prohibited_usernames'] = [
      'name' => 'Prohibited usernames',
      'result' => $prohibited_usernames,
      'status' => !is_array($prohibited_usernames),
    ];

    $result['user_registration'] = [
      'name' => 'Who Can Register?',
      'result' => $this->diliosSiteManager->checkWhoCanRegister(),
      'status' => 'info',
    ];

    [$status, $delete_permissions] = $this->diliosSiteManager->getDeletePermissions();
    $result['delete_permissions'] = [
      'name' => 'Delete Permissions',
      'result' => $delete_permissions,
      'status' => $status,
    ];

    $updates = $this->diliosSiteManager->getModulesUpdates();
    [$has_security, $available_updates] = $updates;
    $result['update_manager'] = [
      'name' => 'Update Manager',
      'result' => empty($available_updates) ? 'All modules are up to date' : $available_updates,
      'status' => $has_security ? FALSE : (empty($available_updates) ? TRUE : 'warning'),
    ];

    [$status, $recaptcha] = $this->diliosSiteManager->getRecaptchaInfo();
    $result['recaptcha'] = [
      'name' => 'Recaptcha',
      'result' => $recaptcha,
      'status' => $status,
    ];

    [$status, $emails] = $this->diliosSiteManager->getEmailSettings();
    $result['website_emails'] = [
      'name' => 'Website & Root Emails',
      'result' => $emails,
      'status' => $status,
    ];

    [$status, $seeds_toolbar] = $this->diliosSiteManager->getSeedsToolbarSupport();
    $result['seeds_toolbar'] = [
      'name' => 'Seeds Toolbar',
      'result' => $seeds_toolbar,
      'status' => $status,
    ];

    return new JsonResponse($result);
  }

  /**
   * Get status report about the website.
   */
  public function getStatusReport() {
    return new JsonResponse($this->diliosSiteManager->checkStatusReport());
  }

  /**
   * Gets the current commit's hash.
   *
   * @return string
   */
  private function getCurrentCommitHash($branch) {
    // Slice the 'public_html' part.
    $git_root = explode('/', DRUPAL_ROOT);
    $git_root = array_slice($git_root, 0, count($git_root) - 1);
    $git_root = implode('/', $git_root);
    if ($hash = file_get_contents($git_root . '/.git/refs/heads/' . $branch)) {
      return trim($hash);
    }

    return FALSE;
  }

  /**
   *
   */
  public function getInfo(Request $request) {
    /** @var \Drupal\simple_sitemap\Simplesitemap $sitemap_manager */
    $sitemap_manager = \Drupal::service('simple_sitemap.generator');
    $varient = $sitemap_manager->getSitemap();
    return new JsonResponse([
      'php' => $this->diliosSiteManager->getPhpVersion(),
      'commit_hash' => $this->getCurrentCommitHash($request->query->get('branch')),
      'seeds_version' => $this->diliosSiteManager->checkSeedsProfileInfo(),
      'drupal_core_version' => $this->diliosSiteManager->getDrupalVersion(),
      'sitemap_published' => $this->diliosSiteManager->getSitemapPublishDate(),
      'status_report' => json_decode($this->getStatusReport()->getContent(), TRUE),
      'performance' => json_decode($this->getPerformance()->getContent(), TRUE),
      'seo' => json_decode($this->getSEO()->getContent(), TRUE),
      'security' => json_decode($this->getSecurity()->getContent(), TRUE),
    ]);
  }

  /**
   * @todo Remove this
   */
  public function checkList() {
    $result = [];

    $result['usernames_check'] = [
      'test_name' => 'Matching usernames and role names',
      'test_result' => $this->diliosSiteManager->checkUsernames(),
    ];

    return new JsonResponse($result);
  }

}
