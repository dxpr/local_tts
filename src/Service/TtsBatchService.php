<?php

namespace Drupal\ai_tts\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\ai_tts\TtsService;
use Drupal\ai_tts\Exception\TtsServiceUnavailableException;
use Drupal\ai_tts\Exception\TtsTimeoutException;

/**
 * Service for batch TTS generation operations.
 */
class TtsBatchService {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The TTS service.
   *
   * @var \Drupal\ai_tts\TtsService
   */
  protected $ttsService;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $bundleInfo;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a TtsBatchService object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\ai_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundle_info
   *   The bundle info service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    TtsService $tts_service,
    ConfigFactoryInterface $config_factory,
    EntityTypeBundleInfoInterface $bundle_info,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->ttsService = $tts_service;
    $this->configFactory = $config_factory;
    $this->bundleInfo = $bundle_info;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * Get all entity bundles with ai_tts_player field attached.
   *
   * @return array
   *   Array of entity bundles keyed by entity type ID and bundle.
   */
  public function getAvailableEntityBundles(): array {
    $bundles = [];

    $field_map = \Drupal::service('entity_field.manager')->getFieldMapByFieldType('ai_tts_player');

    foreach ($field_map as $entity_type_id => $fields) {
      $bundle_info = $this->bundleInfo->getBundleInfo($entity_type_id);

      foreach ($fields as $field_info) {
        foreach ($field_info['bundles'] as $bundle) {
          $label = $bundle_info[$bundle]['label'] ?? $bundle;
          $bundles[$entity_type_id][$bundle] = "{$label} ({$entity_type_id})";
        }
      }
    }

    return $bundles;
  }

  /**
   * Get entities for TTS generation.
   *
   * @param array $entity_bundles
   *   Array of entity type:bundle strings.
   * @param array $langcodes
   *   Array of language codes to filter by.
   * @param bool $force_refresh
   *   Whether to regenerate even if cached.
   * @param int $limit
   *   Maximum number of entities (0 for no limit).
   * @param string|null $updated_after
   *   Optional date filter (Y-m-d format) to only get entities updated
   *   after this date.
   *
   * @return array
   *   Array of entity data arrays with keys:
   *   entity_type, entity_id, bundle, langcode.
   */
  public function getEntitiesForGeneration(
    array $entity_bundles,
    array $langcodes,
    bool $force_refresh = FALSE,
    int $limit = 0,
    ?string $updated_after = NULL,
  ): array {
    $entities = [];

    foreach ($entity_bundles as $entity_bundle) {
      [$entity_type_id, $bundle] = explode(':', $entity_bundle);

      foreach ($langcodes as $langcode) {
        $storage = $this->entityTypeManager->getStorage($entity_type_id);
        $query = $storage->getQuery();
        $query->accessCheck(FALSE);

        // Filter by bundle.
        $bundle_key = $storage->getEntityType()->getKey('bundle');
        if ($bundle_key) {
          $query->condition($bundle_key, $bundle);
        }

        // Filter by language.
        if ($storage->getEntityType()->isTranslatable()) {
          $query->condition('langcode', $langcode);
        }

        // Only published content (for nodes).
        if ($entity_type_id === 'node') {
          $query->condition('status', 1);
        }

        // Filter by updated date if specified.
        if ($updated_after !== NULL) {
          $changed_key = $storage->getEntityType()->getKey('changed');
          if ($changed_key) {
            // Convert date string to timestamp.
            $timestamp = strtotime($updated_after . ' 00:00:00');
            if ($timestamp !== FALSE) {
              $query->condition($changed_key, $timestamp, '>=');
            }
          }
        }

        // Skip entities with cached audio (unless force refresh).
        if (!$force_refresh) {
          $cached_ids = $this->getCachedEntityIds($entity_type_id, $bundle, $langcode);
          if (!empty($cached_ids)) {
            $id_key = $storage->getEntityType()->getKey('id');
            $query->condition($id_key, $cached_ids, 'NOT IN');
          }
        }

        // Apply limit.
        if ($limit > 0) {
          $remaining = $limit - count($entities);
          if ($remaining <= 0) {
            break 2;
          }
          $query->range(0, $remaining);
        }

        $ids = $query->execute();

        foreach ($ids as $id) {
          $entities[] = [
            'entity_type' => $entity_type_id,
            'entity_id' => $id,
            'bundle' => $bundle,
            'langcode' => $langcode,
          ];
        }
      }
    }

    return $entities;
  }

  /**
   * Get entity IDs that already have cached audio.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Array of entity IDs.
   */
  protected function getCachedEntityIds(string $entity_type_id, string $bundle, string $langcode): array {
    $connection = \Drupal::database();

    return $connection->select('ai_tts_cache', 'c')
      ->fields('c', ['entity_id'])
      ->condition('entity_type', $entity_type_id)
      ->condition('language', $langcode)
      ->isNotNull('entity_id')
      ->execute()
      ->fetchCol();
  }

  /**
   * Process a batch of entities.
   *
   * @param array $entities
   *   Array of entity data to process.
   * @param array $options
   *   Batch options including:
   *   - language: Language code
   *   - voice: Voice to use (or NULL for default)
   *   - speed: Speech speed
   *   - force_refresh: Whether to regenerate cached files
   *   - max_load_threshold: Server load threshold (or NULL to disable)
   *   - pause_on_high_load: Whether to pause vs fail on high load
   *   - max_retries: Maximum retry attempts
   *   - inter_batch_delay: Seconds to wait between batches.
   * @param int $total_entities
   *   Total number of entities in entire batch.
   * @param array $context
   *   Batch API context array.
   */
  public function processBatch(
    array $entities,
    array $options,
    int $total_entities,
    array &$context,
  ): void {
    // Initialize context.
    if (!isset($context['sandbox']['progress'])) {
      // CRITICAL FIX: Set MySQL wait_timeout for long-running AI
      // operations. This prevents "MySQL server has gone away" during
      // 30+ second TTS generation.
      try {
        \Drupal::database()->query('SET SESSION wait_timeout = 600')->execute();
        \Drupal::database()->query('SET SESSION interactive_timeout = 600')->execute();
      }
      catch (\Exception $e) {
        // Silently continue if SET fails (e.g., insufficient privileges)
      }

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['total'] = $total_entities;
      $context['sandbox']['current_batch_start'] = time();
      $context['results']['processed'] = 0;
      $context['results']['generated'] = 0;
      $context['results']['cached'] = 0;
      $context['results']['errors'] = [];
      $context['results']['high_load_pauses'] = 0;
      $context['results']['retry_counts'] = [];
    }

    $logger = $this->loggerFactory->get('ai_tts');

    // Server load check (disabled by setting max_load_threshold to NULL).
    if ($options['max_load_threshold'] !== NULL) {
      if (!$this->canProcessBatch($options['max_load_threshold'])) {
        if ($options['pause_on_high_load']) {
          // Pause and retry later.
          $context['results']['high_load_pauses']++;
          $load = function_exists('sys_getloadavg') ? round(sys_getloadavg()[0], 2) : 'N/A';
          $context['message'] = $this->t('Server load too high (@load > @threshold). Pausing batch... (Pause #@count)', [
            '@load' => $load,
            '@threshold' => $options['max_load_threshold'],
            '@count' => $context['results']['high_load_pauses'],
          ]);

          // Wait 10 seconds before retry.
          sleep(10);

          // Don't increment progress - we'll retry this batch.
          $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
          return;
        }
        else {
          // Fail the batch.
          $context['results']['errors'][] = $this->t('Server load too high to continue processing.');
          $context['finished'] = 1;
          return;
        }
      }
    }

    // Inter-batch delay.
    if ($options['inter_batch_delay'] > 0 && $context['sandbox']['progress'] > 0) {
      sleep($options['inter_batch_delay']);
    }

    // Process entities.
    foreach ($entities as $entity_data) {
      $retry_count = 0;
      $max_retries = $options['max_retries'];
      $generated = FALSE;

      while ($retry_count <= $max_retries && !$generated) {
        try {
          // Ensure database connection is alive before loading entity.
          // TTS generation can take 30+ seconds, causing MySQL timeout.
          // Use mysql_ping() approach: test connection and reconnect if needed.
          $database = \Drupal::database();
          try {
            // Simple ping query to keep connection alive.
            $database->query('SELECT 1')->fetchField();
          }
          catch (\Exception $e) {
            // Connection lost. Force reconnection by destroying old connection.
            $database->destroy();
            // Get fresh database connection.
            $database = \Drupal::database();
            $logger->info('Database connection refreshed during batch processing.');
          }

          // Load entity.
          $entity = $this->entityTypeManager
            ->getStorage($entity_data['entity_type'])
            ->load($entity_data['entity_id']);

          if (!$entity) {
            $context['results']['errors'][] = $this->t('Entity @type:@id not found.', [
              '@type' => $entity_data['entity_type'],
              '@id' => $entity_data['entity_id'],
            ]);
            break;
          }

          // Get translation if needed.
          $langcode = $entity_data['langcode'] ?? $entity->language()->getId();
          if ($entity->hasTranslation($langcode)) {
            $entity = $entity->getTranslation($langcode);
          }

          // Extract text from entity.
          $text = $this->extractTextFromEntity($entity);

          if (empty($text)) {
            $context['results']['errors'][] = $this->t('No text content found for @type:@id.', [
              '@type' => $entity_data['entity_type'],
              '@id' => $entity_data['entity_id'],
            ]);
            break;
          }

          // Build generation options.
          // Note: We skip access check since we're in a batch operation and
          // already verified the entity exists. This avoids database timeout
          // issues during long TTS generation.
          // Get default voice for this language (or use override from form).
          $voice = $options['voice'] ?? $this->getDefaultVoiceForLanguage($langcode);

          $generation_options = [
            'language' => $langcode,
            'voice' => $voice,
            'speed' => $options['speed'],
            'entity_type' => $entity_data['entity_type'],
            'entity_id' => $entity_data['entity_id'],
            'use_cache' => !$options['force_refresh'],
            'skip_access_check' => TRUE,
          ];

          // Generate TTS (CPU-intensive operation).
          // Suppress output to prevent batch corruption.
          ob_start();

          $file_uri = $this->ttsService->generateSpeech(
            $text,
            $generation_options
          );

          ob_end_clean();

          if ($file_uri) {
            $context['results']['generated']++;
            $generated = TRUE;

            $logger->info('Generated TTS for @type:@id (@lang)', [
              '@type' => $entity_data['entity_type'],
              '@id' => $entity_data['entity_id'],
              '@lang' => $langcode,
            ]);
          }
          else {
            // File was cached, not newly generated.
            $context['results']['cached']++;
            $generated = TRUE;
          }

          $context['results']['processed']++;
          $context['sandbox']['progress']++;

        }
        catch (TtsServiceUnavailableException $e) {
          // Server load too high or service unavailable.
          if ($retry_count < $max_retries) {
            $retry_count++;
            $context['results']['high_load_pauses']++;

            $logger->warning('TTS service unavailable, retrying (@retry/@max): @message', [
              '@retry' => $retry_count,
              '@max' => $max_retries,
              '@message' => $e->getMessage(),
            ]);

            // Wait before retry (exponential backoff).
            sleep(min(30, 5 * $retry_count));
          }
          else {
            // Max retries reached.
            $context['results']['errors'][] = $this->t('Failed after @retries retries: @type:@id - @message', [
              '@retries' => $max_retries,
              '@type' => $entity_data['entity_type'],
              '@id' => $entity_data['entity_id'],
              '@message' => $e->getMessage(),
            ]);

            $logger->error('TTS generation failed after retries: @type:@id', [
              '@type' => $entity_data['entity_type'],
              '@id' => $entity_data['entity_id'],
            ]);

            break;
          }
        }
        catch (TtsTimeoutException $e) {
          // Timeout - don't retry, likely too much text.
          $context['results']['errors'][] = $this->t('Timeout: @type:@id - @message', [
            '@type' => $entity_data['entity_type'],
            '@id' => $entity_data['entity_id'],
            '@message' => $e->getMessage(),
          ]);

          $logger->error('TTS generation timeout: @type:@id', [
            '@type' => $entity_data['entity_type'],
            '@id' => $entity_data['entity_id'],
          ]);

          break;
        }
        catch (\Exception $e) {
          // Generic error.
          $context['results']['errors'][] = $this->t('Error: @type:@id - @message', [
            '@type' => $entity_data['entity_type'],
            '@id' => $entity_data['entity_id'],
            '@message' => $e->getMessage(),
          ]);

          $logger->error('TTS generation error: @type:@id - @error', [
            '@type' => $entity_data['entity_type'],
            '@id' => $entity_data['entity_id'],
            '@error' => $e->getMessage(),
          ]);

          break;
        }
      }

      // Track retry statistics.
      if ($retry_count > 0 && $generated) {
        $context['results']['retry_counts'][$entity_data['entity_id']] = $retry_count;
      }
    }

    // Update progress.
    $elapsed = time() - $context['sandbox']['current_batch_start'];
    $rate = $context['sandbox']['progress'] > 0
      ? $elapsed / $context['sandbox']['progress']
      : 0;
    $remaining = $context['sandbox']['total'] - $context['sandbox']['progress'];
    $estimate = $rate > 0 ? round(($remaining * $rate) / 60, 1) : '?';

    $context['message'] = $this->t('Processed @current of @total entities. Generated: @generated, Cached: @cached, Errors: @errors. Est. @estimate min remaining.', [
      '@current' => $context['sandbox']['progress'],
      '@total' => $context['sandbox']['total'],
      '@generated' => $context['results']['generated'],
      '@cached' => $context['results']['cached'],
      '@errors' => count($context['results']['errors']),
      '@estimate' => $estimate,
    ]);

    // Calculate finished percentage.
    if ($context['sandbox']['total'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Check if we can process batch based on server load.
   *
   * @param float $max_load
   *   The maximum allowed load average.
   *
   * @return bool
   *   TRUE if load is acceptable, FALSE if too high.
   */
  protected function canProcessBatch(float $max_load): bool {
    if (!function_exists('sys_getloadavg')) {
      // Can't check load, assume OK.
      return TRUE;
    }

    $load = sys_getloadavg();
    $current_load = $load[0];

    return $current_load <= $max_load;
  }

  /**
   * Extract text content from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to extract text from.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractTextFromEntity($entity): string {
    // Auto-detect common text fields.
    $common_fields = [
      'body',
      'field_body',
      'field_description',
      'field_text',
      'field_content',
      'description',
    ];

    foreach ($common_fields as $field) {
      if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
        return $this->extractFieldText($entity, $field);
      }
    }

    // Fallback to entity label.
    return $entity->label() ?? '';
  }

  /**
   * Extract text from a specific field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   The extracted text.
   */
  protected function extractFieldText($entity, $field_name): string {
    if (!$entity->hasField($field_name)) {
      return '';
    }

    $field = $entity->get($field_name);

    if ($field->isEmpty()) {
      return '';
    }

    $text_parts = [];

    // Handle different field types.
    foreach ($field as $item) {
      // Text fields with format (like body).
      if (isset($item->value)) {
        $text = $item->value;

        // Strip HTML tags for formatted text.
        if (isset($item->format)) {
          $text = strip_tags($text);
        }

        $text_parts[] = $text;
      }
      // Plain string fields.
      elseif (is_string($item->value)) {
        $text_parts[] = $item->value;
      }
      // Entity reference fields - get labels.
      elseif (method_exists($item, 'entity') && $item->entity) {
        $text_parts[] = $item->entity->label();
      }
    }

    return implode(' ', $text_parts);
  }

  /**
   * Get the default voice for a given language.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The default voice ID.
   */
  protected function getDefaultVoiceForLanguage(string $langcode): string {
    $config = $this->configFactory->get('ai_tts.settings');
    $default_voices = $config->get('default_voices') ?? [];

    // Check language-specific default first.
    if (isset($default_voices[$langcode])) {
      return $default_voices[$langcode];
    }

    // Fall back to first available voice for this language.
    $available_voices = $this->ttsService->getAvailableVoices($langcode);
    if (!empty($available_voices)) {
      return array_key_first($available_voices);
    }

    // Final fallback to af_sky.
    return 'af_sky';
  }

}
