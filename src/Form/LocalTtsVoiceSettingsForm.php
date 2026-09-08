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
 * Voice and content settings for Local TTS.
 */
final class LocalTtsVoiceSettingsForm extends ConfigFormBase {

  /**
   * The TTS service.
   */
  protected TtsService $ttsService;

  /**
   * The language manager.
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The entity type bundle info service.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

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
    return 'local_tts_voice_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('local_tts.settings');

    $form['voice_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Voice Settings'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $enabled_languages = $this->languageManager->getLanguages();
    $default_voices = $config->get('default_voices') ?? [];

    $form['voice_settings']['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Configure default voice for each language. Only languages with available voices are shown.') . '</p>',
    ];

    foreach ($enabled_languages as $langcode => $language) {
      $language_voices = $this->ttsService->getAvailableVoices($langcode);
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

    $form['#attached']['library'][] = 'local_tts/voice-preview';
    $form['#attached']['drupalSettings']['localTts']['voicePreviewUrl'] = Url::fromRoute('local_tts.voice_preview')->toString();

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $voice_settings = $form_state->getValue('voice_settings');
    $default_speed = $voice_settings['default_speed'] ?? '1';
    unset($voice_settings['default_speed'], $voice_settings['description']);

    $allowed_bundles = array_filter($form_state->getValue('allowed_bundles') ?? []);
    $bundles_map = [];
    foreach ($allowed_bundles as $key) {
      $bundles_map[$key] = TRUE;
    }

    $this->config('local_tts.settings')
      ->set('default_voices', $voice_settings)
      ->set('default_speed', $default_speed)
      ->set('auto_generate', (bool) $form_state->getValue('auto_generate'))
      ->set('allowed_bundles', $bundles_map)
      ->set('show_download', (bool) $form_state->getValue('show_download'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
