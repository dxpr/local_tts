<?php

/**
 * @file
 * Views integration for the Local TTS module.
 */

/**
 * Implements hook_views_data_alter().
 */
function local_tts_views_views_data_alter(array &$data) {
  if (isset($data['node_field_data'])) {
    $data['node_field_data']['local_tts_player'] = [
      'title' => t('TTS Player'),
      'help' => t('Audio player that reads the entity content aloud.'),
      'field' => [
        'id' => 'local_tts_player',
      ],
    ];
  }
}
