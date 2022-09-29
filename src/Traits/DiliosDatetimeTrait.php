<?php
namespace Drupal\sprintive_dilios_client\Traits;

trait DiliosDatetimeTrait {
     /**
   * Gets the modifier for datetime object
   *
   * @param int $days
   * @return string
   */
  private function getModifier($days) {
    if ($days === 1) {
      return '+1 day';
    } else {
      return "+$days days";
    }
  }
}
