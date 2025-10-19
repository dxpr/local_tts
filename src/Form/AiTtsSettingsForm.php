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
      '#type' => 'number',
      '#title' => $this->t('Default speech speed'),
      '#description' => $this->t('Speech speed multiplier (0.5 = half speed, 2.0 = double speed).'),
      '#default_value' => $config->get('default_speed'),
      '#min' => 0.5,
      '#max' => 2.0,
      '#step' => 0.1,
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
    $this->config('ai_tts.settings')
      ->set('koko_binary_path', $form_state->getValue('koko_binary_path'))
      ->set('default_voice', $form_state->getValue('default_voice'))
      ->set('default_speed', $form_state->getValue('default_speed'))
      ->set('cache_audio', $form_state->getValue('cache_audio'))
      ->set('audio_directory', $form_state->getValue('audio_directory'))
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
    // Common Kokoro voices based on the documentation.
    return [
      'af_sky' => 'af_sky (Female, Sky)',
      'af_nicole' => 'af_nicole (Female, Nicole)',
      'af_heart' => 'af_heart (Female, Heart)',
      'af_bella' => 'af_bella (Female, Bella)',
      'af_sarah' => 'af_sarah (Female, Sarah)',
      'am_adam' => 'am_adam (Male, Adam)',
      'am_michael' => 'am_michael (Male, Michael)',
      'bf_emma' => 'bf_emma (British Female, Emma)',
      'bf_isabella' => 'bf_isabella (British Female, Isabella)',
      'bm_george' => 'bm_george (British Male, George)',
      'bm_lewis' => 'bm_lewis (British Male, Lewis)',
    ];
  }

}
