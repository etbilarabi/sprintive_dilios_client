<?php

namespace Drupal\sprintive_dilios_client\PageCache\RequestPolicy;

use Drupal\Core\PageCache\RequestPolicyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Do not serve a page from cache if Dilios-Authorization header is applicable.
 *
 * @internal
 */
class DisallowDiliosToken implements RequestPolicyInterface {

  const TOKEN = 'Dilios-Authorization';

  /**
   * {@inheritdoc}
   */
  public function check(Request $request) {
    if (!(empty($request->headers->get(self::TOKEN)))) {
      return self::DENY;
    }
  }
}