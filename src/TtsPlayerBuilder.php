<?php

namespace Drupal\local_tts;

use Drupal\Component\Utility\Html;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Url;

/**
 * Service to build TTS player render arrays.
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

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly TtsService $ttsService,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ?EntityRepositoryInterface $entityRepository,
    protected readonly ?EntityFieldManagerInterface $entityFieldManager = NULL,
    protected readonly ?EntityTypeBundleInfoInterface $entityTypeBundleInfo = NULL,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['buildPlayerLazy', 'buildFilePlayerLazy'];
  }

  /**
   * Lazy builder callback for the TTS player.
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
   */
  public function buildPlayer(?EntityInterface $entity, array $settings = []) {
    $settings += [
      'show_voice_selector' => TRUE,
      'show_speed_control' => TRUE,
      'show_volume_control' => TRUE,
      'fields' => [],
      'wrapper_classes' => '',
    ];

    if (!$entity) {
      return [];
    }

    if ($this->ttsService->isExtracting()) {
      return [];
    }

    $anonymous = new AnonymousUserSession();
    if (!$entity->access('view', $anonymous)) {
      return [];
    }

    if (!$this->entityHasTextContent($entity, $settings['fields'])) {
      return [];
    }

    if (!$this->ttsService->isBinaryAvailable()) {
      return [];
    }

    $langcode = $entity->language()->getId();
    $available_voices = $this->ttsService->getAvailableVoices($langcode);
    if (empty($available_voices)) {
      return [];
    }

    $global_config = $this->configFactory->get('local_tts.settings');
    $show_download = $global_config->get('show_download') ?? FALSE;

    $instance_settings = [
      'defaultVoice' => $this->ttsService->getDefaultVoice($langcode),
      'defaultSpeed' => $global_config->get('default_speed'),
      'language' => $langcode,
      'entityType' => $entity->getEntityTypeId(),
      'entityId' => $entity->id(),
      'fields' => array_values(array_filter($settings['fields'] ?? [])),
      'showDownload' => (bool) $show_download,
      'wordCount' => ($entity instanceof FieldableEntityInterface) ? $this->ttsService->estimateWordCount($entity, $settings['fields'] ?? []) : 0,
    ];

    $drupal_settings = [
      'generateUrl' => Url::fromRoute('local_tts.generate')->toString(),
    ];

    $build = $this->buildPlayerWidget($settings, $langcode, $available_voices, $instance_settings, $drupal_settings);

    $build['#cache']['contexts'][] = 'route';
    $build['#cache']['contexts'][] = 'languages:language_content';
    $build['#cache']['contexts'][] = 'user.permissions';
    $build['#cache']['tags'][] = $entity->getEntityTypeId() . ':' . $entity->id();
    $build['#cache']['tags'][] = 'config:local_tts.settings';

    return $build;
  }

  /**
   * Lazy builder callback for file-based TTS player.
   */
  public function buildFilePlayerLazy(string $audio_url, string $langcode, string $settings_json): array {
    $settings = json_decode($settings_json, TRUE) ?: [];
    return $this->buildFilePlayer($audio_url, $langcode, $settings);
  }

  /**
   * Build the TTS player for an existing audio file URL.
   */
  public function buildFilePlayer(string $audio_url, string $langcode, array $settings = []): array {
    $settings += [
      'show_voice_selector' => FALSE,
      'show_speed_control' => TRUE,
      'show_volume_control' => TRUE,
      'wrapper_classes' => '',
    ];

    $available_voices = $this->ttsService->getAvailableVoices($langcode);
    $global_config = $this->configFactory->get('local_tts.settings');

    $instance_settings = [
      'audioUrl' => $audio_url,
      'defaultSpeed' => $global_config->get('default_speed'),
      'language' => $langcode,
    ];

    $build = $this->buildPlayerWidget($settings, $langcode, $available_voices, $instance_settings, []);

    $build['#cache']['tags'][] = 'config:local_tts.settings';

    return $build;
  }

  /**
   * Build the shared player widget render array.
   */
  protected function buildPlayerWidget(array $settings, ?string $langcode, array $available_voices, array $instance_settings, array $global_drupal_settings): array {
    $id = substr(md5(microtime() . random_bytes(8)), 0, 8);
    $global_config = $this->configFactory->get('local_tts.settings');
    $show_download = $global_config->get('show_download') ?? FALSE;

    $build = $this->buildContainer($settings, $id);
    $build['play_wrapper'] = $this->buildPlayWrapper($settings, $id, $langcode, $available_voices, $show_download);
    $build['status'] = $this->buildStatusArea($id);
    $build['audio'] = $this->buildAudioElement($id);
    $build['#attached'] = [
      'library' => ['local_tts/player'],
      'drupalSettings' => [
        'localTts' => $global_drupal_settings + [
          'instances' => [
            $id => $instance_settings,
          ],
        ],
      ],
    ];

    return $build;
  }

  /**
   * Build the outer container with CSS classes.
   */
  protected function buildContainer(array $settings, string $id): array {
    $classes = ['local-tts-container', 'local-tts-initializing'];
    $custom = trim($settings['wrapper_classes'] ?? '');
    if (!empty($custom)) {
      foreach (array_filter(explode(' ', $custom)) as $class) {
        $classes[] = Html::cleanCssIdentifier($class);
      }
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => $classes,
        'data-tts-id' => $id,
      ],
    ];
  }

  /**
   * Build the play wrapper containing button, content, and settings.
   */
  protected function buildPlayWrapper(array $settings, string $id, ?string $langcode, array $available_voices, bool $show_download): array {
    $wrapper = [
      '#type' => 'container',
      '#attributes' => ['class' => ['local-tts-play-wrapper']],
    ];

    $wrapper['play_button'] = $this->buildPlayButton($id);
    $wrapper['content'] = $this->buildContentArea($settings, $id);
    $wrapper['settings'] = $this->buildSettingsPanel($settings, $id, $langcode, $available_voices, $show_download);

    return $wrapper;
  }

  /**
   * Build the play/pause button.
   */
  protected function buildPlayButton(string $id): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#attributes' => [
        'type' => 'button',
        'id' => 'local-tts-play-button-' . $id,
        'class' => ['local-tts-play-button'],
        'aria-label' => $this->t('Listen to this article'),
        'aria-pressed' => 'false',
        'aria-controls' => 'local-tts-audio-' . $id,
      ],
      '#value' => '',
    ];
  }

  /**
   * Build the content area: label and playback controls.
   */
  protected function buildContentArea(array $settings, string $id): array {
    $content = [
      '#type' => 'container',
      '#attributes' => ['class' => ['local-tts-content']],
    ];

    $content['label'] = $this->buildLabelArea($id);
    $content['playback_controls'] = $this->buildPlaybackControls($settings, $id);

    return $content;
  }

  /**
   * Build the label with text and duration.
   */
  protected function buildLabelArea(string $id): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'local-tts-label-' . $id,
        'class' => ['local-tts-label'],
      ],
      'text' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'id' => 'local-tts-label-text-' . $id,
          'class' => ['local-tts-label-text'],
        ],
        '#value' => $this->t('Listen to this article'),
      ],
      'duration_text' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => [
          'id' => 'local-tts-label-duration-' . $id,
          'class' => ['local-tts-label-duration'],
        ],
        '#value' => '',
      ],
    ];
  }

  /**
   * Build playback controls: skip, scrubber, time, volume.
   */
  protected function buildPlaybackControls(array $settings, string $id): array {
    $controls = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'local-tts-playback-controls-' . $id,
        'class' => ['local-tts-playback-controls'],
        'hidden' => 'hidden',
      ],
    ];

    $controls['skip_back'] = $this->buildSkipButton('back', $id, -10);
    $controls['current_time'] = $this->buildTimeDisplay('current', $id, 0);
    $controls['scrubber'] = $this->buildScrubber($id, 10);
    $controls['remaining_time'] = $this->buildTimeDisplay('remaining', $id, 20);
    $controls['skip_forward'] = $this->buildSkipButton('forward', $id, 25);

    if (!empty($settings['show_volume_control'])) {
      $controls['volume_wrapper'] = $this->buildVolumeControls($id, 30);
    }

    return $controls;
  }

  /**
   * Build a skip button (back or forward).
   */
  protected function buildSkipButton(string $direction, string $id, int $weight): array {
    $label = $direction === 'back'
      ? $this->t('Skip back 10 seconds')
      : $this->t('Skip forward 10 seconds');

    return [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#weight' => $weight,
      '#attributes' => [
        'type' => 'button',
        'class' => ['local-tts-skip-button', 'local-tts-skip-' . $direction],
        'aria-label' => $label,
      ],
      '#value' => '',
    ];
  }

  /**
   * Build a time display element (current or remaining).
   */
  protected function buildTimeDisplay(string $type, string $id, int $weight): array {
    $isCurrent = $type === 'current';

    return [
      '#type' => 'html_tag',
      '#tag' => 'span',
      '#weight' => $weight,
      '#attributes' => [
        'id' => 'local-tts-' . $type . '-time-' . $id,
        'class' => ['local-tts-' . $type . '-time'],
      ] + ($isCurrent ? ['aria-live' => 'off'] : []),
      '#value' => $isCurrent ? '0:00' : '',
    ];
  }

  /**
   * Build the scrubber range input.
   */
  protected function buildScrubber(string $id, int $weight): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'input',
      '#weight' => $weight,
      '#attributes' => [
        'type' => 'range',
        'id' => 'local-tts-scrubber-' . $id,
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
  }

  /**
   * Build volume controls: mute button and volume slider.
   */
  protected function buildVolumeControls(string $id, int $weight): array {
    return [
      '#type' => 'container',
      '#weight' => $weight,
      '#attributes' => ['class' => ['local-tts-volume-wrapper']],
      'mute_button' => [
        '#type' => 'html_tag',
        '#tag' => 'button',
        '#attributes' => [
          'type' => 'button',
          'class' => ['local-tts-mute-button'],
          'aria-label' => $this->t('Mute'),
          'aria-pressed' => 'false',
        ],
        '#value' => '',
      ],
      'volume_slider' => [
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
      ],
    ];
  }

  /**
   * Build the settings panel: voice, speed, download.
   */
  protected function buildSettingsPanel(array $settings, string $id, ?string $langcode, array $available_voices, bool $show_download): array {
    $panel = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'local-tts-settings-' . $id,
        'class' => ['local-tts-settings'],
      ],
    ];

    if (!empty($settings['show_voice_selector']) && !empty($available_voices)) {
      $default_voice = $this->ttsService->getDefaultVoice($langcode);
      $panel['voice_select'] = [
        '#type' => 'select',
        '#title' => $this->t('Voice'),
        '#options' => $available_voices,
        '#value' => $default_voice,
        '#attributes' => [
          'id' => 'local-tts-voice-select-' . $id,
          'class' => ['local-tts-voice-select'],
          'aria-label' => $this->t('Select voice'),
        ],
      ];
    }

    if (!empty($settings['show_speed_control'])) {
      $global_config = $this->configFactory->get('local_tts.settings');
      $default_speed = $this->ttsService->normalizeSpeed(
        $global_config->get('default_speed') ?: '1'
      );

      $panel['speed_select'] = [
        '#type' => 'select',
        '#title' => $this->t('Speed'),
        '#options' => [
          '0.8' => $this->t('0.8×'),
          '1' => $this->t('1×'),
          '1.2' => $this->t('1.2×'),
          '1.5' => $this->t('1.5×'),
          '2' => $this->t('2×'),
        ],
        '#value' => $default_speed,
        '#attributes' => [
          'id' => 'local-tts-speed-select-' . $id,
          'class' => ['local-tts-speed-select'],
          'aria-label' => $this->t('Playback speed'),
        ],
      ];
    }

    if ($show_download) {
      $panel['download_button'] = [
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

    return $panel;
  }

  /**
   * Build the status area.
   */
  protected function buildStatusArea(string $id): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'local-tts-status-' . $id,
        'class' => ['local-tts-status'],
        'role' => 'status',
        'aria-live' => 'polite',
        'aria-atomic' => 'true',
      ],
    ];
  }

  /**
   * Build the audio element.
   */
  protected function buildAudioElement(string $id): array {
    return [
      '#type' => 'html_tag',
      '#tag' => 'audio',
      '#attributes' => [
        'id' => 'local-tts-audio-' . $id,
        'preload' => 'none',
      ],
    ];
  }

  /**
   * Get text field options across all fieldable entity types.
   *
   * Used by the block and Views player plugins to build field checkboxes.
   */
  public function getAllTextFieldOptions(): array {
    if (!$this->entityFieldManager || !$this->entityTypeBundleInfo) {
      return [];
    }

    $options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entityTypeId => $entityType) {
      if (!$entityType->entityClassImplements(FieldableEntityInterface::class)) {
        continue;
      }
      if (!$entityType->hasViewBuilderClass()) {
        continue;
      }
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityTypeId);
      foreach (array_keys($bundles) as $bundle) {
        $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
        foreach ($definitions as $fieldName => $definition) {
          $type = $definition->getType();
          if (in_array($type, self::ALLOWED_FIELD_TYPES, TRUE)) {
            $options[$fieldName] = $definition->getLabel() . ' (' . $fieldName . ')';
          }
        }
      }
    }
    ksort($options);

    return $options;
  }

  /**
   * Check whether an entity has non-empty text content.
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
