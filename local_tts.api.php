<?php

/**
 * @file
 * Hooks provided by the Local TTS module.
 */

/**
 * Alter text before it is sent to the TTS engine.
 *
 * @param string $text
 *   The extracted plain text (passed by reference).
 * @param array $context
 *   Associative array with keys:
 *   - entity: The source entity.
 *   - langcode: The language code.
 *   - fields: Selected field names, or an empty array for the entity display.
 */
function hook_local_tts_text_alter(&$text, array $context) {
  $text = str_replace('Read more', '', $text);
}
