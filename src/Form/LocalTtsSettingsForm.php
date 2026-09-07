<?php

namespace Drupal\local_tts\Form;

use Drupal\local_tts\TtsService;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Local TTS settings.
 */
final class LocalTtsSettingsForm extends ConfigFormBase {

  /**
   * The TTS service.
   *
   * @var \Drupal\local_tts\TtsService
   */
  protected $ttsService;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * Constructs an LocalTtsSettingsForm object.
   *
   * @param \Drupal\local_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info
   *   The entity type bundle info.
   */
  public function __construct(
    TtsService $tts_service,
    LanguageManagerInterface $language_manager,
    EntityTypeBundleInfoInterface $bundle_info,
  ) {
    $this->ttsService = $tts_service;
    $this->languageManager = $language_manager;
    $this->bundleInfo = $bundle_info;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('local_tts.tts_service'),
      $container->get('language_manager'),
      $container->get('entity_type.bundle.info')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['local_tts.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'local_tts_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('local_tts.settings');

    // eSpeak NG Configuration.
    $form['espeak'] = [
      '#type' => 'details',
      '#title' => $this->t('eSpeak NG Configuration'),
      '#open' => TRUE,
    ];

    $detected_path = $this->detectEspeakDataPath();
    $current_path = $config->get('espeak_data_path');
    $effective_path = $current_path ?: $detected_path;

    if ($detected_path && !$current_path) {
      $form['espeak']['description'] = [
        '#markup' => '<p>' . $this->t('eSpeak NG data directory detected automatically at <code>@path</code>.', ['@path' => $detected_path]) . '</p>',
      ];
    }
    elseif (!$detected_path && !$current_path) {
      $form['espeak']['description'] = [
        '#markup' => '<p>' . $this->t('eSpeak NG is required for phoneme processing. Install it with <code>apt-get install espeak-ng</code> (Linux) or <code>brew install espeak-ng</code> (macOS), then enter the data directory path below.') . '</p>',
      ];
    }
    else {
      $form['espeak']['description'] = [
        '#markup' => '<p>' . $this->t('eSpeak NG is required for phoneme processing and must be installed system-wide.') . '</p>',
      ];
    }

    $form['espeak']['espeak_data_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('eSpeak NG data path'),
      '#description' => $this->t('Path to espeak-ng-data directory (contains phoneme data for text processing).'),
      '#default_value' => $effective_path,
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => '/usr/share/espeak-ng-data',
      ],
    ];

    $form['voice_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Voice Settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    // Get enabled languages on the site.
    $enabled_languages = $this->languageManager->getLanguages();
    $default_voices = $config->get('default_voices') ?? [];

    $form['voice_settings']['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure default voice for each language. Only languages with available voices are shown.') . '</p>',
    ];

    foreach ($enabled_languages as $langcode => $language) {
      // Get voices available for this language.
      $language_voices = $this->ttsService->getAvailableVoices($langcode);

      // Skip if no voices available for this language.
      if (empty($language_voices)) {
        continue;
      }

      $form['voice_settings'][$langcode] = [
        '#type' => 'select',
        '#title' => $this->t('Default voice for @language', ['@language' => $language->getName()]),
        '#description' => $this->t('@count voices available', ['@count' => count($language_voices)]),
        '#options' => $language_voices,
        '#default_value' => $default_voices[$langcode] ?? array_key_first($language_voices),
      ];
    }

    $form['voice_settings']['default_speed'] = [
      '#type' => 'select',
      '#title' => $this->t('Default speech speed'),
      '#description' => $this->t('Speech speed multiplier (0.8 = slower, 2 = faster).'),
      '#options' => [
        '0.8' => $this->t('0.8x'),
        '1' => $this->t('1x (Normal)'),
        '1.2' => $this->t('1.2x'),
        '1.5' => $this->t('1.5x'),
        '2' => $this->t('2x'),
      ],
      '#default_value' => $this->ttsService->normalizeSpeed($config->get('default_speed') ?: '1'),
    ];

    $form['caching'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Caching Settings'),
    ];

    $form['caching']['cache_audio'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache generated audio files'),
      '#description' => $this->t('Store generated audio files to avoid regenerating the same content.'),
      '#default_value' => $config->get('cache_audio'),
    ];

    $form['caching']['audio_directory'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Audio cache directory'),
      '#description' => $this->t('Directory for storing cached audio files (e.g., public://local-tts).'),
      '#default_value' => $config->get('audio_directory'),
      '#states' => [
        'visible' => [
          ':input[name="cache_audio"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['cache_management'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cache Management'),
      '#description' => $this->t('Automatic cache cleanup is always enabled: size-based cleanup removes least recently used files when limit is exceeded, and content-based invalidation removes audio when source content is updated.'),
    ];

    $form['cache_management']['cache_max_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum cache size (MB)'),
      '#description' => $this->t('Maximum disk space for cached audio files. Default: 1024 MB (1 GB). Least recently used files will be automatically deleted when this limit is exceeded.'),
      '#default_value' => round(($config->get('cache_max_size') ?? 1073741824) / 1048576),
      '#min' => 10,
      '#max' => 102400,
      '#step' => 1,
      '#field_suffix' => 'MB',
    ];

    $form['security'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Security Settings'),
      '#description' => $this->t('Configure security constraints to prevent abuse and protect server resources.'),
    ];

    $form['security']['max_text_length'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum text length'),
      '#description' => $this->t('Maximum number of characters allowed per request. Set to 0 for unlimited. Default: 1,000,000 characters (~200,000 words) - suitable for long-form content, books, and documentation.'),
      '#default_value' => $config->get('max_text_length') ?? 1000000,
      '#min' => 0,
      '#max' => 10000000,
      '#step' => 10000,
      '#field_suffix' => $this->t('characters'),
    ];

    $form['security']['generation_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('Generation timeout'),
      '#description' => $this->t('Maximum time (in seconds) to wait for audio generation. Prevents hanging processes. Default: 900 seconds (15 minutes).'),
      '#default_value' => $config->get('generation_timeout') ?? 900,
      '#min' => 10,
      '#max' => 3600,
      '#step' => 1,
      '#field_suffix' => $this->t('seconds'),
    ];

    $form['security']['rate_limit_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable rate limiting'),
      '#description' => $this->t('Limit the number of TTS generation requests per user to prevent abuse. Recommended: enabled.'),
      '#default_value' => $config->get('rate_limit_enabled') ?? TRUE,
    ];

    $form['security']['rate_limit_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Rate limit threshold'),
      '#description' => $this->t('Maximum number of requests allowed per hour per user.'),
      '#default_value' => $config->get('rate_limit_threshold') ?? 20,
      '#min' => 1,
      '#max' => 1000,
      '#step' => 1,
      '#field_suffix' => $this->t('requests/hour'),
      '#states' => [
        'visible' => [
          ':input[name="rate_limit_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['security']['max_server_load'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum server load threshold'),
      '#description' => $this->t('The TTS binary will NOT run if server load (1-minute average) exceeds this value. This prevents TTS generation from impacting server performance during high-load periods. Set to 0 to disable load checking. Default: 2.0'),
      '#default_value' => $config->get('max_server_load') ?? 2,
      '#min' => 0,
      '#max' => 100,
      '#step' => 0.1,
      '#field_suffix' => $this->t('load average'),
    ];

    $form['generation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Generation'),
    ];

    $form['generation']['auto_generate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Auto-generate audio on content save'),
      '#description' => $this->t('Queue a TTS generation job whenever content is created or updated, so audio is ready before the first visitor arrives.'),
      '#default_value' => $config->get('auto_generate') ?? FALSE,
    ];

    $bundle_options = [];
    $node_bundles = $this->bundleInfo->getBundleInfo('node');
    foreach ($node_bundles as $bundle_id => $bundle_data) {
      $bundle_options['node:' . $bundle_id] = $bundle_data['label'];
    }
    $allowed_bundles = $config->get('allowed_bundles') ?? [];

    $form['generation']['allowed_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#description' => $this->t('Limit TTS to these content types. Leave all unchecked to allow all types.'),
      '#options' => $bundle_options,
      '#default_value' => array_keys(array_filter($allowed_bundles)),
    ];

    $form['player_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Player'),
    ];

    $form['player_settings']['show_download'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show download button'),
      '#description' => $this->t('Allow visitors to download the generated audio file.'),
      '#default_value' => $config->get('show_download') ?? FALSE,
    ];

    // Attach voice preview JS and pass the preview endpoint URL.
    $form['#attached']['library'][] = 'local_tts/voice-preview';
    $form['#attached']['drupalSettings']['localTts']['voicePreviewUrl'] = Url::fromRoute('local_tts.voice_preview')->toString();

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $espeak_path = $form_state->getValue('espeak_data_path');
    if ($espeak_path) {
      $real_path = $this->expandPath($espeak_path);
      if (!is_dir($real_path)) {
        $form_state->setErrorByName('espeak_data_path', $this->t('The eSpeak NG data directory does not exist at: @path', ['@path' => $real_path]));
      }
    }

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Convert MB to bytes for storage.
    $max_size_mb = $form_state->getValue('cache_max_size');
    $max_size_bytes = $max_size_mb * 1048576;

    // Extract voice settings array.
    $voice_settings = $form_state->getValue('voice_settings');

    // Separate default_speed from language-specific voices.
    $default_speed = $voice_settings['default_speed'] ?? '1';
    unset($voice_settings['default_speed']);
    unset($voice_settings['description']);

    $allowed_bundles = array_filter($form_state->getValue('allowed_bundles') ?? []);
    $bundles_map = [];
    foreach ($allowed_bundles as $key) {
      $bundles_map[$key] = TRUE;
    }

    $this->config('local_tts.settings')
      ->set('espeak_data_path', $form_state->getValue('espeak_data_path'))
      ->set('default_voices', $voice_settings)
      ->set('default_speed', $default_speed)
      ->set('cache_audio', $form_state->getValue('cache_audio'))
      ->set('audio_directory', $form_state->getValue('audio_directory'))
      ->set('cache_size_limit_enabled', TRUE)
      ->set('cache_max_size', $max_size_bytes)
      ->set('max_text_length', $form_state->getValue('max_text_length'))
      ->set('generation_timeout', $form_state->getValue('generation_timeout'))
      ->set('rate_limit_enabled', $form_state->getValue('rate_limit_enabled'))
      ->set('rate_limit_threshold', $form_state->getValue('rate_limit_threshold'))
      ->set('max_server_load', $form_state->getValue('max_server_load'))
      ->set('auto_generate', (bool) $form_state->getValue('auto_generate'))
      ->set('allowed_bundles', $bundles_map)
      ->set('show_download', (bool) $form_state->getValue('show_download'))
      ->clear('default_voice')
      ->clear('koko_binary_path')
      ->clear('model_path')
      ->clear('data_path')
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get available Kokoro voices.
   *
   * @return array
   *   Array of voice options.
   */
  protected function getAvailableVoices() {
    // Get voices from the TTS service.
    return $this->ttsService->getAvailableVoices();
  }

  /**
   * Expand path with tilde (~) to full path.
   *
   * @param string $path
   *   Path potentially containing ~.
   *
   * @return string
   *   Expanded path.
   */
  protected function expandPath($path) {
    if (strpos($path, '~') === 0) {
      $home = getenv('HOME');
      if ($home) {
        return str_replace('~', $home, $path);
      }
    }
    return $path;
  }

  /**
   * Auto-detect the eSpeak NG data directory.
   *
   * @return string|null
   *   Detected path, or NULL if not found.
   */
  protected function detectEspeakDataPath() {
    $candidates = [
      '/usr/share/espeak-ng-data',
      '/usr/lib/x86_64-linux-gnu/espeak-ng-data',
      '/usr/lib/aarch64-linux-gnu/espeak-ng-data',
      '/usr/local/share/espeak-ng-data',
      '/opt/homebrew/share/espeak-ng-data',
    ];

    // Check Homebrew Cellar paths on macOS.
    $cellar = '/opt/homebrew/Cellar/espeak-ng';
    if (is_dir($cellar)) {
      $versions = @scandir($cellar, SCANDIR_SORT_DESCENDING);
      if ($versions) {
        foreach ($versions as $v) {
          if ($v === '.' || $v === '..') {
            continue;
          }
          $path = $cellar . '/' . $v . '/share/espeak-ng-data';
          if (is_dir($path)) {
            return $path;
          }
        }
      }
    }

    foreach ($candidates as $path) {
      if (is_dir($path)) {
        return $path;
      }
    }

    return NULL;
  }

}
