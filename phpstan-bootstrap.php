<?php

/**
 * @file
 * Drupal constants not available during static analysis.
 */

if (!defined('REQUIREMENT_OK')) {
  define('REQUIREMENT_OK', 0);
}
if (!defined('REQUIREMENT_INFO')) {
  define('REQUIREMENT_INFO', -1);
}
if (!defined('REQUIREMENT_WARNING')) {
  define('REQUIREMENT_WARNING', 1);
}
if (!defined('REQUIREMENT_ERROR')) {
  define('REQUIREMENT_ERROR', 2);
}

if (!function_exists('batch_set')) {

  /**
   * Stub for batch_set() during static analysis.
   */
  function batch_set($batch_definition) {}

}
