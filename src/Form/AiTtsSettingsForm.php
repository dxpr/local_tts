<?php

namespace Drupal\ai_tts\Form;

use Drupal\ai_tts\TtsService;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure AI TTS settings.
 */
class AiTtsSettingsForm extends ConfigFormBase {

  /**
   * The TTS service.
   *
   * @var \Drupal\ai_tts\TtsService
   */
  protected $ttsService;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs an AiTtsSettingsForm object.
   *
   * @param \Drupal\ai_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(
    TtsService $tts_service,
    LanguageManagerInterface $language_manager,
  ) {
    $this->ttsService = $tts_service;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_tts.tts_service'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_tts.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_tts_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_tts.settings');

    // File Paths Configuration.
    $form['paths'] = [
      '#type' => 'details',
      '#title' => $this->t('File Paths'),
      '#open' => TRUE,
    ];

    $form['paths']['koko_binary_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Koko executable path'),
      '#description' => $this->t('Absolute path to koko binary. Must be executable (755 permissions), owned by root or deployment user.'),
      '#default_value' => $config->get('koko_binary_path'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => '/usr/local/bin/koko',
      ],
    ];

    $form['paths']['model_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model file path'),
      '#description' => $this->t('Path to kokoro-v1.0.onnx file (~100-200MB). Must be readable (644 permissions), owned by root or deployment user.'),
      '#default_value' => $config->get('model_path'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => '/usr/local/share/kokoro/checkpoints/kokoro-v1.0.onnx',
      ],
    ];

    $form['paths']['data_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Voices data path'),
      '#description' => $this->t('Path to voices-v1.0.bin file (~10-50MB). Must be readable (644 permissions), owned by root or deployment user.'),
      '#default_value' => $config->get('data_path'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => '/usr/local/share/kokoro/data/voices-v1.0.bin',
      ],
    ];

    $form['paths']['espeak_data_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('eSpeak NG data path'),
      '#description' => $this->t('Path to espeak-ng-data directory (contains phoneme data for text processing). Must be readable (755 permissions).'),
      '#default_value' => $config->get('espeak_data_path'),
      '#required' => TRUE,
      '#attributes' => [
        'placeholder' => '/opt/homebrew/share/espeak-ng-data',
      ],
    ];

    // Status checks.
    $binary_path = $config->get('koko_binary_path');
    $model_path = $config->get('model_path');
    $data_path = $config->get('data_path');

    $status_messages = [];

    // Binary status.
    if ($binary_path) {
      $binary_exists = file_exists($binary_path);
      $binary_executable = $binary_exists && is_executable($binary_path);

      if ($binary_executable) {
        $status_messages[] = '✓ ' . $this->t('Binary found and executable at: <code>@path</code>', ['@path' => $binary_path]);
      }
      elseif ($binary_exists) {
        $status_messages[] = '✗ ' . $this->t('Binary found but not executable. Run: <code>chmod +x @path</code>', ['@path' => $binary_path]);
      }
      else {
        $status_messages[] = '✗ ' . $this->t('Binary not found at: <code>@path</code>', ['@path' => $binary_path]);
      }
    }

    // Model files status.
    if ($model_path && $data_path) {
      $model_real = $this->expandPath($model_path);
      $data_real = $this->expandPath($data_path);

      $model_exists = file_exists($model_real);
      $data_exists = file_exists($data_real);

      if ($model_exists && $data_exists) {
        $model_size = filesize($model_real);
        $data_size = filesize($data_real);
        $status_messages[] = '✓ ' . $this->t('Model files found (@model_size, @data_size)', [
          '@model_size' => format_size($model_size),
          '@data_size' => format_size($data_size),
        ]);
      }
      else {
        if (!$model_exists) {
          $status_messages[] = '✗ ' . $this->t('Model not found: <code>@path</code>', ['@path' => $model_real]);
        }
        if (!$data_exists) {
          $status_messages[] = '✗ ' . $this->t('Data not found: <code>@path</code>', ['@path' => $data_real]);
        }
      }
    }

    // Display status if any messages.
    if (!empty($status_messages)) {
      $has_errors = strpos(implode(' ', $status_messages), '✗') !== FALSE;
      $message_class = $has_errors ? 'messages--error' : 'messages--status';

      if ($has_errors) {
        $status_messages[] = $this->t('Install using: <code>cd @module && composer run download-binary</code>', [
          '@module' => 'modules/custom/ai_tts',
        ]);
      }

      $form['paths']['status'] = [
        '#markup' => '<div class="messages ' . $message_class . '">' .
        implode('<br>', $status_messages) . '</div>',
      ];
    }

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
      '#description' => $this->t('Directory for storing cached audio files (e.g., public://ai-tts).'),
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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $binary_path = $form_state->getValue('koko_binary_path');

    if (!file_exists($binary_path)) {
      $form_state->setErrorByName('koko_binary_path', $this->t('The binary file does not exist at the specified path.'));
    }
    elseif (!is_executable($binary_path)) {
      $form_state->setErrorByName('koko_binary_path', $this->t('The binary file is not executable.'));
    }

    // Validate model path.
    $model_path = $form_state->getValue('model_path');
    if ($model_path) {
      $model_real = $this->expandPath($model_path);
      if (!file_exists($model_real)) {
        $form_state->setErrorByName('model_path', $this->t('Model file does not exist at: @path', ['@path' => $model_real]));
      }
    }

    // Validate data path.
    $data_path = $form_state->getValue('data_path');
    if ($data_path) {
      $data_real = $this->expandPath($data_path);
      if (!file_exists($data_real)) {
        $form_state->setErrorByName('data_path', $this->t('Data file does not exist at: @path', ['@path' => $data_real]));
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

    $this->config('ai_tts.settings')
      ->set('koko_binary_path', $form_state->getValue('koko_binary_path'))
      ->set('model_path', $form_state->getValue('model_path'))
      ->set('data_path', $form_state->getValue('data_path'))
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
      ->clear('default_voice')
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

}
