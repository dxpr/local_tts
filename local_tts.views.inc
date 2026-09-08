<?php

/**
 * @file
 * Views data definitions for the Local TTS module.
 */

/**
 * Implements hook_views_data().
 */
function local_tts_views_data() {
  $data = [];

  $data['local_tts_cache'] = [
    'table' => [
      'group' => t('TTS Cache'),
      'base' => [
        'field' => 'id',
        'title' => t('TTS Audio Cache'),
        'help' => t('Cached text-to-speech audio files.'),
      ],
    ],
    'id' => [
      'title' => t('ID'),
      'help' => t('The cache record ID.'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'cache_key' => [
      'title' => t('Cache key'),
      'help' => t('MD5 hash identifying the audio file.'),
      'field' => ['id' => 'standard'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
    ],
    'entity_type' => [
      'title' => t('Entity type'),
      'help' => t('The entity type (e.g. node).'),
      'field' => ['id' => 'standard'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
    ],
    'entity_id' => [
      'title' => t('Entity ID'),
      'help' => t('The entity ID.'),
      'field' => ['id' => 'standard'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
    ],
    'language' => [
      'title' => t('Language'),
      'help' => t('The language code of the audio.'),
      'field' => ['id' => 'standard'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
    ],
    'text_hash' => [
      'title' => t('Text hash'),
      'help' => t('Hash of the source text content.'),
      'field' => ['id' => 'standard'],
    ],
    'voice' => [
      'title' => t('Voice'),
      'help' => t('The TTS voice used.'),
      'field' => ['id' => 'standard'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
    ],
    'speed' => [
      'title' => t('Speed'),
      'help' => t('The playback speed multiplier.'),
      'field' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'file_size' => [
      'title' => t('File size'),
      'help' => t('Size of the audio file in bytes.'),
      'field' => ['id' => 'local_tts_file_size'],
      'sort' => ['id' => 'standard'],
      'filter' => ['id' => 'numeric'],
    ],
    'created' => [
      'title' => t('Created'),
      'help' => t('When the audio was generated.'),
      'field' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
      'filter' => ['id' => 'date'],
    ],
    'accessed' => [
      'title' => t('Last accessed'),
      'help' => t('When the audio was last played.'),
      'field' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
      'filter' => ['id' => 'date'],
    ],
    'player' => [
      'title' => t('Audio player'),
      'help' => t('An inline audio player for the cached file.'),
      'field' => ['id' => 'local_tts_cache_player'],
      'real field' => 'cache_key',
    ],
  ];

  return $data;
}

/**
 * Implements hook_views_data_alter().
 */
function local_tts_views_data_alter(array &$data) {
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
