<?php

namespace Drupal\ai_tts;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Lazy builders for AI TTS extra field rendering.
 *
 * Follows Drupal core pattern from CommentLazyBuilders.
 *
 * @see \Drupal\comment\CommentLazyBuilders
 */
class TtsLazyBuilders implements TrustedCallbackInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * The TTS service.
   *
   * @var \Drupal\ai_tts\TtsService
   */
  protected $ttsService;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new TtsLazyBuilders object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\ai_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
    TtsService $tts_service,
    AccountInterface $current_user,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->ttsService = $tts_service;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['renderPlayer'];
  }

  /**
   * Lazy builder callback: Renders the TTS player for an entity.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param int|string $entity_id
   *   The entity ID.
   * @param string $view_mode
   *   The view mode.
   * @param string $langcode
   *   The language code.
   * @param bool $is_in_preview
   *   Whether the entity is in preview mode.
   *
   * @return array
   *   A renderable array for the TTS player.
   */
  public function renderPlayer($entity_type_id, $entity_id, $view_mode, $langcode, $is_in_preview) {
    $build = [
      '#theme' => 'ai_tts_player',
      '#cache' => [
        'contexts' => ['user.permissions', 'languages:language_interface'],
        'tags' => ["$entity_type_id:$entity_id"],
        'max-age' => 0,
      ],
    ];

    // Don't render in preview mode.
    if ($is_in_preview) {
      return $build;
    }

    try {
      // Load the entity.
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      $entity = $storage->load($entity_id);

      if (!$entity) {
        return $build;
      }

      // Load the correct translation.
      if ($entity->hasTranslation($langcode)) {
        $entity = $entity->getTranslation($langcode);
      }

      // Check access - entity must be viewable.
      if (!$entity->access('view', $this->currentUser)) {
        return $build;
      }

      // Get available voices for this language.
      $available_voices = $this->ttsService->getAvailableVoices($langcode);
      if (empty($available_voices)) {
        // No voices available for this language.
        return $build;
      }

      // Get field configuration for this bundle.
      $config = \Drupal::config('ai_tts.settings');
      $enabled_bundles = $config->get('extra_field_enabled_bundles') ?? [];
      $selected_fields = [];

      foreach ($enabled_bundles as $bundle_config) {
        if ($bundle_config['entity_type'] === $entity_type_id &&
            $bundle_config['bundle'] === $entity->bundle()) {
          $selected_fields = $bundle_config['fields'] ?? [];
          break;
        }
      }

      // Get global settings.
      $global_config = \Drupal::config('ai_tts.settings');
      $default_voice = $global_config->get('default_voice');
      $default_speed = $global_config->get('default_speed');

      // Convert voice options to JavaScript-friendly format.
      $js_voices = [];
      foreach ($available_voices as $voice_id => $voice_label) {
        $js_voices[] = [
          'value' => $voice_id,
          'label' => $voice_label,
        ];
      }

      // Determine default voice for this language.
      $js_default_voice = $this->ttsService->getDefaultVoiceForLanguage(
        $langcode,
        $default_voice,
        $available_voices
      );

      // Build the player render array.
      $build = [
        '#theme' => 'ai_tts_player',
        '#attached' => [
          'library' => ['ai_tts/player'],
          'drupalSettings' => [
            'aiTts' => [
              'defaultVoice' => $js_default_voice,
              'defaultSpeed' => $default_speed,
              'generateUrl' => Url::fromRoute('ai_tts.generate')->toString(),
              'language' => $langcode,
              'entityType' => $entity_type_id,
              'entityId' => $entity_id,
              'fields' => array_values($selected_fields),
            ],
          ],
        ],
        '#cache' => [
          'contexts' => ['user.permissions', 'languages:language_interface'],
          'tags' => [
            "$entity_type_id:$entity_id",
            'config:ai_tts.settings',
          ],
          // Cache for 1 hour since voices and settings don't change often.
          'max-age' => 3600,
        ],
      ];

    }
    catch (\Exception $e) {
      \Drupal::logger('ai_tts')->error('Error rendering TTS player: @message', [
        '@message' => $e->getMessage(),
      ]);
      return $build;
    }

    return $build;
  }

}
