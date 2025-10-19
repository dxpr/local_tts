<?php

namespace Drupal\ai_tts\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure AI TTS settings.
 */
class AiTtsSettingsForm extends ConfigFormBase {

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

    $form['koko_binary'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Kokoro Binary Configuration'),
    ];

    $form['koko_binary']['koko_binary_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to koko binary'),
      '#description' => $this->t('Absolute path to the koko executable. Example: /usr/local/bin/koko'),
      '#default_value' => $config->get('koko_binary_path'),
      '#required' => TRUE,
    ];

    // Test the binary.
    $binary_path = $config->get('koko_binary_path');
    if ($binary_path && file_exists($binary_path) && is_executable($binary_path)) {
      $form['koko_binary']['binary_status'] = [
        '#markup' => '<div class="messages messages--status">' . $this->t('Binary found and executable at: @path', ['@path' => $binary_path]) . '</div>',
      ];
    }
    elseif ($binary_path) {
      $form['koko_binary']['binary_status'] = [
        '#markup' => '<div class="messages messages--warning">' . $this->t('Binary not found or not executable at: @path', ['@path' => $binary_path]) . '</div>',
      ];
    }

    $form['voice_settings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Voice Settings'),
    ];

    $form['voice_settings']['default_voice'] = [
      '#type' => 'select',
      '#title' => $this->t('Default voice'),
      '#description' => $this->t('Select the default voice for speech synthesis.'),
      '#options' => $this->getAvailableVoices(),
      '#default_value' => $config->get('default_voice'),
    ];

    $form['voice_settings']['default_speed'] = [
      '#type' => 'select',
      '#title' => $this->t('Default speech speed'),
      '#description' => $this->t('Speech speed multiplier (0.8 = slower, 2 = faster).'),
      '#options' => [
        '0.8' => '0.8x',
        '1' => '1x (Normal)',
        '1.2' => '1.2x',
        '1.5' => '1.5x',
        '2' => '2x',
      ],
      '#default_value' => $config->get('default_speed') ?: '1',
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
      '#description' => $this->t('Configure automatic cache cleanup to manage disk space and content freshness.'),
    ];

    $form['cache_management']['cache_size_limit_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable size-based cache cleanup'),
      '#description' => $this->t('Automatically delete least recently used audio files when cache exceeds size limit.'),
      '#default_value' => $config->get('cache_size_limit_enabled') ?? TRUE,
    ];

    $form['cache_management']['cache_max_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum cache size (MB)'),
      '#description' => $this->t('Maximum disk space for cached audio files. Default: 1024 MB (1 GB). Files will be deleted by least recently used when this limit is exceeded.'),
      '#default_value' => round(($config->get('cache_max_size') ?? 1073741824) / 1048576),
      '#min' => 10,
      '#max' => 102400,
      '#step' => 1,
      '#field_suffix' => 'MB',
      '#states' => [
        'visible' => [
          ':input[name="cache_size_limit_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['cache_management']['cache_content_tracking_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable content-based cache invalidation'),
      '#description' => $this->t('Automatically delete audio files when the source content is updated. Tracks entity changes (nodes, taxonomy terms, etc.) and removes associated audio.'),
      '#default_value' => $config->get('cache_content_tracking_enabled') ?? TRUE,
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
      '#step' => 30,
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

    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Convert MB to bytes for storage.
    $max_size_mb = $form_state->getValue('cache_max_size');
    $max_size_bytes = $max_size_mb * 1048576;

    $this->config('ai_tts.settings')
      ->set('koko_binary_path', $form_state->getValue('koko_binary_path'))
      ->set('default_voice', $form_state->getValue('default_voice'))
      ->set('default_speed', $form_state->getValue('default_speed'))
      ->set('cache_audio', $form_state->getValue('cache_audio'))
      ->set('audio_directory', $form_state->getValue('audio_directory'))
      ->set('cache_size_limit_enabled', $form_state->getValue('cache_size_limit_enabled'))
      ->set('cache_max_size', $max_size_bytes)
      ->set('cache_content_tracking_enabled', $form_state->getValue('cache_content_tracking_enabled'))
      ->set('max_text_length', $form_state->getValue('max_text_length'))
      ->set('generation_timeout', $form_state->getValue('generation_timeout'))
      ->set('rate_limit_enabled', $form_state->getValue('rate_limit_enabled'))
      ->set('rate_limit_threshold', $form_state->getValue('rate_limit_threshold'))
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
    return \Drupal::service('ai_tts.tts_service')->getAvailableVoices();
  }

}
