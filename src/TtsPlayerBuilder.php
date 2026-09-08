<?php

namespace Drupal\local_tts;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Url;

/**
 * Service to build TTS player render arrays.
 *
 * Shared logic between LocalTtsBlock and LocalTtsPlayerFormatter.
 */
class TtsPlayerBuilder implements TrustedCallbackInterface {

  use StringTranslationTrait;

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

  const EXCLUDED_BASE_FIELDS = [
    'nid', 'uuid', 'vid', 'langcode', 'type', 'revision_timestamp',
    'revision_uid', 'revision_log', 'status', 'uid', 'created', 'changed',
    'promote', 'sticky', 'default_langcode', 'revision_default',
    'revision_translation_affected', 'metatag', 'path', 'menu_link',
    'tid', 'weight', 'parent', 'description__format',
  ];

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * TTS service.
   *
   * @var \Drupal\local_tts\TtsService
   */
  protected $ttsService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity repository.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface|null
   */
  protected $entityRepository;

  /**
   * Constructs a TtsPlayerBuilder.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   Config factory.
   * @param \Drupal\local_tts\TtsService $tts_service
   *   TTS service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface|null $entity_repository
   *   The entity repository.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    TtsService $tts_service,
    AccountProxyInterface $current_user,
    EntityTypeManagerInterface $entity_type_manager,
    ?EntityRepositoryInterface $entity_repository = NULL,
  ) {
    $this->configFactory = $config_factory;
    $this->ttsService = $tts_service;
    $this->currentUser = $current_user;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityRepository = $entity_repository;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['buildPlayerLazy'];
  }

  /**
   * Lazy builder callback for the TTS player.
   *
   * @param string $entity_type
   *   The entity type ID.
   * @param string $entity_id
   *   The entity ID.
   * @param string $settings_json
   *   JSON-encoded player settings.
   * @param string|null $langcode
   *   The language of the translation being displayed. When omitted, the
   *   translation matching the current content language is used.
   *
   * @return array
   *   The player render array.
   */
  public function buildPlayerLazy(string $entity_type, string $entity_id, string $settings_json, ?string $langcode = NULL): array {
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if ($entity instanceof TranslatableInterface) {
      if ($langcode !== NULL && $entity->hasTranslation($langcode)) {
        $entity = $entity->getTranslation($langcode);
      }
      elseif ($this->entityRepository) {
        $entity = $this->entityRepository->getTranslationFromContext($entity);
      }
    }
    $settings = json_decode($settings_json, TRUE) ?: [];
    return $this->buildPlayer($entity, $settings);
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
   *   - wrapper_classes: (string) Additional CSS classes for the wrapper.
   *
   * @return array
   *   Render array for the player, or empty array if player cannot be shown.
   */
  public function buildPlayer(?EntityInterface $entity, array $settings = []) {
    $settings += [
      'show_voice_selector' => TRUE,
      'show_speed_control' => TRUE,
      'show_volume_control' => TRUE,
      'fields' => [],
      'wrapper_classes' => '',
    ];

    $id_suffix = substr(md5(microtime() . random_bytes(8)), 0, 8);
    $global_config = $this->configFactory->get('local_tts.settings');

    $langcode = NULL;
    if ($entity) {
      $langcode = $entity->language()->getId();
    }

    if (!$entity) {
      return [];
    }

    $anonymous = new AnonymousUserSession();
    if (!$entity->access('view', $anonymous)) {
      return [];
    }

    if (!$this->entityHasTextContent($entity, $settings['fields'])) {
      return [];
    }

    $available_voices = $this->ttsService->getAvailableVoices($langcode);
    if (empty($available_voices)) {
      return [];
    }

    $container_classes = ['local-tts-container'];
    $custom_classes = trim($settings['wrapper_classes']);
    if (!empty($custom_classes)) {
      $additional_classes = array_filter(explode(' ', $custom_classes));
      foreach ($additional_classes as $class) {
        $container_classes[] = Html::cleanCssIdentifier($class);
      }
    }

    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => $container_classes,
        'data-tts-id' => $id_suffix,
      ],
    ];

    $build['play_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['local-tts-play-wrapper']],
    ];

    $build['play_wrapper']['play_button'] = [
      '#type' => 'button',
      '#value' => '',
      '#attributes' => [
        'id' => 'local-tts-play-button-' . $id_suffix,
        'class' => ['local-tts-button', 'local-tts-play-button'],
        'aria-label' => $this->t('Listen to this article'),
        'aria-pressed' => 'false',
        'aria-controls' => 'local-tts-audio-' . $id_suffix,
      ],
    ];

    $build['play_wrapper']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['local-tts-content']],
    ];

    $build['play_wrapper']['content']['label'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'local-tts-label-' . $id_suffix,
        'class' => ['local-tts-label'],
      ],
    ];

    $build['play_wrapper']['content']['label']['text'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => [
        'id' => 'local-tts-label-text-' . $id_suffix,
        'class' => ['local-tts-label-text'],
      ],
      '#value' => $this->t('Listen to this article'),
    ];

    $build['play_wrapper']['content']['label']['duration_text'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => [
        'id' => 'local-tts-label-duration-' . $id_suffix,
        'class' => ['local-tts-label-duration'],
      ],
      '#value' => '',
    ];

    $build['play_wrapper']['content']['playback_controls'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'local-tts-playback-controls-' . $id_suffix,
        'class' => ['local-tts-playback-controls'],
        'style' => 'display: none;',
      ],
    ];

    $build['play_wrapper']['content']['playback_controls']['current_time'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => [
        'id' => 'local-tts-current-time-' . $id_suffix,
        'class' => ['local-tts-current-time'],
        'aria-live' => 'off',
      ],
      '#value' => '0:00',
    ];

    $build['play_wrapper']['content']['playback_controls']['scrubber'] = [
      '#type' => 'html_tag',
      '#tag' => 'input',
      '#attributes' => [
        'type' => 'range',
        'id' => 'local-tts-scrubber-' . $id_suffix,
        'class' => ['local-tts-scrubber'],
        'min' => '0',
        'max' => '100',
        'value' => '0',
        'step' => '0.1',
        'role' => 'slider',
        'aria-label' => $this->t('Audio timeline'),
        'aria-valuemin' => '0',
        'aria-valuemax' => '100',
        'aria-valuenow' => '0',
        'aria-valuetext' => $this->t('0 seconds elapsed, duration unknown'),
        'disabled' => 'disabled',
      ],
    ];

    $build['play_wrapper']['content']['playback_controls']['remaining_time'] = [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#attributes' => [
        'id' => 'local-tts-remaining-time-' . $id_suffix,
        'class' => ['local-tts-remaining-time'],
      ],
      '#value' => '',
    ];

    if ($settings['show_volume_control']) {
      $build['play_wrapper']['content']['playback_controls']['volume_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['local-tts-volume-wrapper'],
        ],
      ];

      $build['play_wrapper']['content']['playback_controls']['volume_wrapper']['mute_button'] = [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['local-tts-mute-button'],
          'aria-label' => $this->t('Mute'),
          'aria-pressed' => 'false',
        ],
        '#value' => '',
      ];

      $build['play_wrapper']['content']['playback_controls']['volume_wrapper']['volume_slider'] = [
        '#type' => 'html_tag',
        '#tag' => 'input',
        '#attributes' => [
          'type' => 'range',
          'class' => ['local-tts-volume-slider'],
          'min' => '0',
          'max' => '1',
          'value' => '1',
          'step' => '0.05',
          'role' => 'slider',
          'aria-label' => $this->t('Volume'),
          'aria-valuemin' => '0',
          'aria-valuemax' => '100',
          'aria-valuenow' => '100',
          'aria-valuetext' => $this->t('Volume 100%'),
        ],
      ];
    }

    $build['play_wrapper']['settings'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'local-tts-settings-' . $id_suffix,
        'class' => ['local-tts-settings'],
      ],
    ];

    if ($settings['show_voice_selector']) {
      $default_voice = $this->getValidDefaultVoice(
        $available_voices,
        $langcode
      );

      $build['play_wrapper']['settings']['voice_select'] = [
        '#type' => 'select',
        '#title' => $this->t('Voice'),
        '#options' => $available_voices,
        '#value' => $default_voice,
        '#attributes' => [
          'id' => 'local-tts-voice-select-' . $id_suffix,
          'class' => ['local-tts-voice-select'],
          'aria-label' => $this->t('Select voice'),
        ],
      ];
    }

    if ($settings['show_speed_control']) {
      $default_speed = $this->ttsService->normalizeSpeed(
        $global_config->get('default_speed') ?: '1'
      );

      $build['play_wrapper']['settings']['speed_control'] = [
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
          'id' => 'local-tts-speed-input-' . $id_suffix,
          'class' => ['local-tts-speed-input'],
          'aria-label' => $this->t('Adjust speech speed'),
        ],
      ];
    }

    $show_download = $global_config->get('show_download') ?? FALSE;
    if ($show_download) {
      $build['play_wrapper']['settings']['download_button'] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#attributes' => [
          'class' => ['local-tts-download-button', 'button', 'button--small'],
          'role' => 'button',
          'aria-label' => $this->t('Download audio'),
          'download' => '',
          'hidden' => 'hidden',
        ],
        '#value' => $this->t('Download'),
      ];
    }

    $build['status'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'local-tts-status-' . $id_suffix,
        'class' => ['local-tts-status'],
        'role' => 'status',
        'aria-live' => 'polite',
        'aria-atomic' => 'true',
      ],
    ];

    $build['audio'] = [
      '#type' => 'html_tag',
      '#tag' => 'audio',
      '#attributes' => [
        'id' => 'local-tts-audio-' . $id_suffix,
        'preload' => 'none',
      ],
    ];

    $js_default_voice = $this->getValidDefaultVoice($available_voices, $langcode);

    // Build status polling base URL by stripping the placeholder key.
    $dummy_key = str_repeat('0', 32);
    $status_url = Url::fromRoute('local_tts.status', ['cache_key' => $dummy_key])->toString();
    $status_base_url = str_replace($dummy_key, '', $status_url);

    // SECURITY: Pass only entity reference to JavaScript, NOT the content.
    $build['#attached'] = [
      'library' => ['local_tts/player'],
      'drupalSettings' => [
        'aiTts' => [
          'generateUrl' => Url::fromRoute('local_tts.generate')->toString(),
          'statusBaseUrl' => $status_base_url,
          'isAdmin' => (bool) $this->currentUser->hasPermission('administer local tts settings'),
          'settingsUrl' => Url::fromRoute('local_tts.settings')->toString(),
          'instances' => [
            $id_suffix => [
              'defaultVoice' => $js_default_voice,
              'defaultSpeed' => $global_config->get('default_speed'),
              'language' => $langcode,
              'entityType' => $entity->getEntityTypeId(),
              'entityId' => $entity->id(),
              'fields' => array_values(array_filter($settings['fields'] ?? [])),
              'showDownload' => (bool) $show_download,
            ],
          ],
        ],
      ],
    ];

    $build['#cache']['contexts'][] = 'route';
    $build['#cache']['contexts'][] = 'languages:language_content';
    $build['#cache']['contexts'][] = 'user.permissions';

    $entity_type = $entity->getEntityTypeId();
    $entity_id = $entity->id();
    $build['#cache']['tags'][] = "{$entity_type}:{$entity_id}";
    $build['#cache']['tags'][] = 'config:local_tts.settings';

    return $build;
  }

  /**
   * Get valid default voice for given language.
   *
   * @param array $available_voices
   *   Available voices.
   * @param string $langcode
   *   Language code.
   *
   * @return string
   *   Voice code.
   */
  protected function getValidDefaultVoice(array $available_voices, $langcode) {
    $config = $this->configFactory->get('local_tts.settings');
    $default_voices = $config->get('default_voices') ?? [];

    if (isset($default_voices[$langcode]) && isset($available_voices[$default_voices[$langcode]])) {
      return $default_voices[$langcode];
    }

    return array_key_first($available_voices);
  }

  /**
   * Check whether an entity has non-empty text content.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check.
   * @param array $field_filter
   *   Field names to check (empty = all text fields).
   *
   * @return bool
   *   TRUE if the entity has text content.
   */
  protected function entityHasTextContent(EntityInterface $entity, array $field_filter = []): bool {
    if (!($entity instanceof FieldableEntityInterface)) {
      return FALSE;
    }

    $field_filter = array_filter($field_filter);

    foreach ($entity->getFieldDefinitions() as $field_name => $definition) {
      if (in_array($field_name, self::EXCLUDED_BASE_FIELDS, TRUE)) {
        continue;
      }
      if (!empty($field_filter) && !in_array($field_name, $field_filter, TRUE)) {
        continue;
      }
      if (!in_array($definition->getType(), self::ALLOWED_FIELD_TYPES, TRUE)) {
        continue;
      }
      if (!$entity->get($field_name)->isEmpty()) {
        return TRUE;
      }
    }

    return FALSE;
  }

}
