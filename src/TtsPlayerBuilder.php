<?php

namespace Drupal\ai_tts;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Service to build TTS player render arrays.
 *
 * Shared logic between AiTtsBlock and AiTtsPlayerFormatter.
 */
class TtsPlayerBuilder {

  use StringTranslationTrait;

  /**
   * Allowed field types for TTS processing.
   */
  const ALLOWED_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
    'text_plain',
    'email',
    'telephone',
  ];

  /**
   * Base fields to exclude from TTS (administrative/metadata).
   */
  const EXCLUDED_BASE_FIELDS = [
    'nid', 'uuid', 'vid', 'langcode', 'type', 'revision_timestamp',
    'revision_uid', 'revision_log', 'status', 'uid', 'created', 'changed',
    'promote', 'sticky', 'default_langcode', 'revision_default',
    'revision_translation_affected', 'metatag', 'path', 'menu_link',
    'tid', 'weight', 'parent', 'description__format',
  ];

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The TTS service.
   *
   * @var \Drupal\ai_tts\TtsService
   */
  protected $ttsService;

  /**
   * Constructs a TtsPlayerBuilder object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\ai_tts\TtsService $tts_service
   *   The TTS service.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TtsService $tts_service,
  ) {
    $this->configFactory = $config_factory;
    $this->ttsService = $tts_service;
  }

  /**
   * Build the TTS player render array for an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface|null $entity
   *   The entity to build player for.
   * @param array $settings
   *   Player settings:
   *   - show_voice_selector: (bool) Show voice dropdown.
   *   - show_speed_control: (bool) Show speed control.
   *   - fields: (array) Field names to include (empty = all).
   *
   * @return array
   *   Render array for the player, or empty array if player cannot be shown.
   */
  public function buildPlayer(?EntityInterface $entity, array $settings = []) {
    // Default settings.
    $settings += [
      'show_voice_selector' => TRUE,
      'show_speed_control' => TRUE,
      'fields' => [],
    ];

    $global_config = $this->configFactory->get('ai_tts.settings');

    // Get entity language for voice filtering.
    $langcode = NULL;
    if ($entity && method_exists($entity, 'language')) {
      $langcode = $entity->language()->getId();
    }

    // ARCHITECTURE: Fail hard, no defensive coding.
    // If entity doesn't exist, return empty.
    if (!$entity) {
      return [];
    }

    // Check access (valid check - prevents showing UI for private content).
    $anonymous = new AnonymousUserSession();
    if (!$entity->access('view', $anonymous)) {
      return [];
    }

    // Voices check still valid (prevents UI for unsupported languages).
    $available_voices = $this->ttsService->getAvailableVoices($langcode);
    if (empty($available_voices)) {
      return [];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-tts-container', 'container-inline']],
    ];

    $build['controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-tts-controls', 'container-inline']],
    ];

    $build['controls']['listen_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Listen to this page'),
      '#attributes' => [
        'id' => 'ai-tts-play-button',
        'class' => ['ai-tts-button', 'ai-tts-play-button'],
        'aria-label' => $this->t('Listen to the content on this page'),
        'aria-pressed' => 'false',
        'aria-controls' => 'ai-tts-audio',
      ],
    ];

    $build['controls']['stop_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Stop'),
      '#attributes' => [
        'id' => 'ai-tts-stop-button',
        'class' => ['ai-tts-button', 'ai-tts-stop-button'],
        'disabled' => 'disabled',
        'aria-label' => $this->t('Stop reading'),
        'aria-controls' => 'ai-tts-audio',
      ],
    ];

    $build['settings'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-tts-settings', 'container-inline']],
    ];

    if ($settings['show_voice_selector']) {
      $default_voice = $this->getValidDefaultVoice(
        $available_voices,
        $langcode
      );

      $build['settings']['voice_select'] = [
        '#type' => 'select',
        '#title' => $this->t('Voice'),
        '#options' => $available_voices,
        '#value' => $default_voice,
        '#attributes' => [
          'id' => 'ai-tts-voice-select',
          'class' => ['ai-tts-voice-select'],
          'aria-label' => $this->t('Select voice'),
        ],
      ];
    }

    if ($settings['show_speed_control']) {
      $default_speed = $this->ttsService->normalizeSpeed(
        $global_config->get('default_speed') ?: '1'
      );

      $build['settings']['speed_control'] = [
        '#type' => 'select',
        '#title' => $this->t('Speed'),
        '#options' => [
          '0.8' => $this->t('0.8x'),
          '1' => $this->t('1x (Normal)'),
          '1.2' => $this->t('1.2x'),
          '1.5' => $this->t('1.5x'),
          '2' => $this->t('2x'),
        ],
        '#value' => $default_speed,
        '#attributes' => [
          'id' => 'ai-tts-speed-input',
          'class' => ['ai-tts-speed-input'],
          'aria-label' => $this->t('Adjust speech speed'),
        ],
      ];
    }

    $build['status'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ai-tts-status',
        'class' => ['ai-tts-status'],
        'role' => 'status',
        'aria-live' => 'polite',
      ],
    ];

    $build['duration'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ai-tts-duration',
        'class' => ['ai-tts-duration'],
        'style' => 'display: none;',
      ],
    ];

    $build['audio'] = [
      '#type' => 'html_tag',
      '#tag' => 'audio',
      '#attributes' => [
        'id' => 'ai-tts-audio',
        'preload' => 'none',
      ],
    ];

    // Use language-aware default voice for JavaScript.
    $js_default_voice = $this->getValidDefaultVoice(
      $available_voices,
      $langcode
    );

    // SECURITY: Pass only entity reference to JavaScript, NOT the content.
    // Server loads entity, validates access, extracts text.
    $build['#attached'] = [
      'library' => ['ai_tts/player'],
      'drupalSettings' => [
        'aiTts' => [
          'defaultVoice' => $js_default_voice,
          'defaultSpeed' => $global_config->get('default_speed'),
          'generateUrl' => Url::fromRoute('ai_tts.generate')->toString(),
          'language' => $langcode,
          'entityType' => $entity->getEntityTypeId(),
          'entityId' => $entity->id(),
          'fields' => array_values(array_filter($settings['fields'] ?? [])),
        ],
      ],
    ];

    // Add cache contexts and tags.
    $build['#cache']['contexts'][] = 'route';
    $build['#cache']['contexts'][] = 'languages:language_content';

    $entity_type = $entity->getEntityTypeId();
    $entity_id = $entity->id();
    $build['#cache']['tags'][] = "{$entity_type}:{$entity_id}";
    $build['#cache']['tags'][] = 'config:ai_tts.settings';

    return $build;
  }

  /**
   * Get a valid default voice for the given language.
   *
   * @param array $available_voices
   *   Available voices for the language.
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   A valid voice code.
   */
  protected function getValidDefaultVoice(array $available_voices, $langcode) {
    $config = $this->configFactory->get('ai_tts.settings');
    $default_voices = $config->get('default_voices') ?? [];

    // Check language-specific default first.
    if (isset($default_voices[$langcode]) && isset($available_voices[$default_voices[$langcode]])) {
      return $default_voices[$langcode];
    }

    // Fall back to first available voice for this language.
    return array_key_first($available_voices);
  }

}
