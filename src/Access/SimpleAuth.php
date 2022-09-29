<?php

namespace Drupal\sprintive_dilios_client\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Routing\Access\AccessInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Checks if HTTP basic authentication credentials are given and correct.
 */
class SimpleAuth implements AccessInterface {

  /**
   * The current request.
   *
   * @var Request $request
   */
  private $request;

  /**
   * The config manager service.
   *
   * @var ConfigFactoryInterface $configFactoryInterface
   */
  private $configFactoryInterface;

  /**
   * SimpleAuth constructor.
   *
   * @param RequestStack $requestStack
   *   The current request stack.
   * @param ConfigFactoryInterface $config_factory_interface
   *   The config factory.
   */
  public function __construct(RequestStack $requestStack, ConfigFactoryInterface $config_factory_interface) {
    $request = $requestStack->getCurrentRequest();
    $this->configFactoryInterface = $config_factory_interface->get('sprintive_dilios_client.settings');
  }

  /**
   * Checks if the HTTP basic authentication credentials are valid for request.
   *
   * @param Request $request
   *   The current request.
   *
   * @return AccessResult
   *   Allow access if the HTTP basic authentication credentials are valid.
   */
  public function access(Request $request) {
    $username = NULL;
    $password = NULL;

    // Try to get the Dilios-Authorization header
    $dilios_auth = $request->headers->get('Dilios-Authorization');
    if ($dilios_auth) {
      $dilios_auth = str_replace('Basic ', '', $dilios_auth);
      [$username, $password] = explode(':', base64_decode($dilios_auth));
    }

    if ($username === $this->configFactoryInterface->get('username') && $password === $this->configFactoryInterface->get('password')) {
      return AccessResult::allowed()->setCacheMaxAge(0);
    }
    return AccessResult::forbidden()->setCacheMaxAge(0);
  }
}
