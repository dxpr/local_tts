<?php

namespace Drupal\local_tts\Form;

use Drupal\local_tts\TtsService;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * System and infrastructure settings for Local TTS.
 */
final class LocalTtsSettingsForm extends ConfigFormBase {

  /**
   * The TTS service.
   */
  protected TtsService $ttsService;

  public function __construct(TtsService $tts_service) {
    $this->ttsService = $tts_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('local_tts.tts_service')
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

    $health = $this->ttsService->checkHealth();
    $has_errors = FALSE;
    foreach ($health as $check) {
      if ($check['status'] === 'error') {
        $has_errors = TRUE;
        break;
      }
    }

    $form['health'] = [
      '#type' => 'details',
      '#title' => $has_errors ? $this->t('System health: issues detected') : $this->t('System health: all checks passed'),
      '#open' => $has_errors,
    ];

    $header = [
      'component' => $this->t('Component'),
      'status' => $this->t('Status'),
      'details' => $this->t('Details'),
    ];

    $rows = [];
    $status_labels = [
      'ok' => $this->t('OK'),
      'warning' => $this->t('Warning'),
      'error' => $this->t('Error'),
    ];
    foreach ($health as $key => $check) {
      $status_class = 'color--' . ($check['status'] === 'ok' ? 'success' : ($check['status'] === 'warning' ? 'warning' : 'error'));
      $detail = $check['message'];
      if (!empty($check['path'])) {
        $detail .= ' (' . $check['path'] . ')';
      }
      if ($key === 'disk_usage' && isset($check['total_size'])) {
        $detail .= ' ' . $this->t('@size in @count files (@percent% of @limit)', [
          '@size' => ByteSizeMarkup::create($check['total_size']),
          '@count' => $check['file_count'],
          '@percent' => $check['percent'],
          '@limit' => ByteSizeMarkup::create($check['max_size']),
        ]);
      }
      $rows[] = [
        'component' => $check['label'] ?? $key,
        'status' => [
          'data' => $status_labels[$check['status']] ?? $check['status'],
          'class' => [$status_class],
        ],
        'details' => $detail,
      ];
    }

    $form['health']['table'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $rows,
      '#empty' => $this->t('No health checks available.'),
    ];

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
        '#markup' => '<p>' . $this->t('eSpeak NG data directory detected automatically at <code>@path</code>. Leave blank to use the version bundled with the Kokoro binary.', ['@path' => $detected_path]) . '</p>',
      ];
    }
    elseif (!$detected_path && !$current_path) {
      $form['espeak']['description'] = [
        '#markup' => '<p>' . $this->t('The Kokoro binary includes a bundled eSpeak NG, so no external installation is needed. Optionally, install espeak-ng system-wide and enter the data directory path below to use it instead.') . '</p>',
      ];
    }
    else {
      $form['espeak']['description'] = [
        '#markup' => '<p>' . $this->t('Using a system-wide eSpeak NG installation. Leave blank to use the version bundled with the Kokoro binary.') . '</p>',
      ];
    }

    $form['espeak']['espeak_data_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('eSpeak NG data path'),
      '#description' => $this->t('Optional. Path to espeak-ng-data directory. Leave blank to use the version bundled with the Kokoro binary.'),
      '#default_value' => $effective_path,
      '#required' => FALSE,
      '#attributes' => [
        'placeholder' => '/usr/share/espeak-ng-data',
      ],
    ];

    try {
      $cache_url = Url::fromRoute('view.local_tts_cache.page_1')->toString();
      $caching_description = $this->t('Audio files are cached to avoid regenerating the same content. See <a href=":cache_url">cache overview</a> for current usage.', [
        ':cache_url' => $cache_url,
      ]);
    }
    catch (\Exception $e) {
      $caching_description = $this->t('Audio files are cached to avoid regenerating the same content.');
    }

    $form['caching'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Caching Settings'),
      '#description' => $caching_description,
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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $espeak_path = $form_state->getValue('espeak_data_path');
    if ($espeak_path) {
      $real_path = TtsService::expandPath($espeak_path);
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
    $max_size_mb = $form_state->getValue('cache_max_size');
    $max_size_bytes = $max_size_mb * 1048576;

    $espeak_path = $form_state->getValue('espeak_data_path');

    $this->config('local_tts.settings')
      ->set('espeak_data_path', $espeak_path ? TtsService::expandPath($espeak_path) : '')
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
      ->clear('koko_binary_path')
      ->clear('model_path')
      ->clear('data_path')
      ->save();

    parent::submitForm($form, $form_state);
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
