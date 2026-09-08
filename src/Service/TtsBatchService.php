<?php

namespace Drupal\local_tts\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\local_tts\TtsService;
use Drupal\local_tts\Exception\TtsServiceUnavailableException;
use Drupal\local_tts\Exception\TtsTimeoutException;

/**
 * Service for batch TTS generation operations.
 */
class TtsBatchService {

  use StringTranslationTrait;

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly TtsService $ttsService,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly EntityTypeBundleInfoInterface $bundleInfo,
    LoggerChannelFactoryInterface $loggerFactory,
    protected readonly EntityFieldManagerInterface $entityFieldManager,
    protected readonly Connection $database,
  ) {
    $this->logger = $loggerFactory->get('local_tts');
  }

  /**
   * Get all entity bundles with local_tts_player field attached.
   */
  public function getAvailableEntityBundles(): array {
    $bundles = [];

    $fieldMap = $this->entityFieldManager->getFieldMapByFieldType('local_tts_player');

    foreach ($fieldMap as $entityTypeId => $fields) {
      $bundleInfo = $this->bundleInfo->getBundleInfo($entityTypeId);

      foreach ($fields as $fieldInfo) {
        foreach ($fieldInfo['bundles'] as $bundle) {
          $label = $bundleInfo[$bundle]['label'] ?? $bundle;
          $bundles[$entityTypeId][$bundle] = "{$label} ({$entityTypeId})";
        }
      }
    }

    return $bundles;
  }

  /**
   * Get entities for TTS generation.
   */
  public function getEntitiesForGeneration(
    array $entityBundles,
    array $langcodes,
    bool $forceRefresh = FALSE,
    int $limit = 0,
    ?string $updatedAfter = NULL,
  ): array {
    $entities = [];

    foreach ($entityBundles as $entityBundle) {
      [$entityTypeId, $bundle] = explode(':', $entityBundle);

      foreach ($langcodes as $langcode) {
        $storage = $this->entityTypeManager->getStorage($entityTypeId);
        $query = $storage->getQuery();
        $query->accessCheck(FALSE);

        $bundleKey = $storage->getEntityType()->getKey('bundle');
        if ($bundleKey) {
          $query->condition($bundleKey, $bundle);
        }

        if ($storage->getEntityType()->isTranslatable()) {
          $query->condition('langcode', $langcode);
        }

        if ($entityTypeId === 'node') {
          $query->condition('status', 1, '=', $langcode);
        }

        if ($updatedAfter !== NULL) {
          $changedKey = $storage->getEntityType()->getKey('changed');
          if (!$changedKey) {
            $definitions = $this->entityFieldManager->getFieldDefinitions($entityTypeId, $bundle);
            foreach ($definitions as $fieldName => $definition) {
              if ($definition->getType() === 'changed') {
                $changedKey = $fieldName;
                break;
              }
            }
          }
          if ($changedKey) {
            $timestamp = strtotime($updatedAfter . ' 00:00:00');
            if ($timestamp !== FALSE) {
              $query->condition($changedKey, $timestamp, '>=', $langcode);
            }
          }
        }

        if (!$forceRefresh) {
          $cachedIds = $this->getCachedEntityIds($entityTypeId, $langcode);
          if (!empty($cachedIds)) {
            $idKey = $storage->getEntityType()->getKey('id');
            $query->condition($idKey, $cachedIds, 'NOT IN');
          }
        }

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
            'entity_type' => $entityTypeId,
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
   */
  protected function getCachedEntityIds(string $entityTypeId, string $langcode): array {
    $records = $this->database->select('local_tts_cache', 'c')
      ->fields('c', ['entity_id', 'cache_key'])
      ->condition('entity_type', $entityTypeId)
      ->condition('language', $langcode)
      ->isNotNull('entity_id')
      ->execute()
      ->fetchAll();
    $audioDir = $this->configFactory->get('local_tts.settings')->get('audio_directory') ?: 'public://local-tts';
    $ids = [];
    foreach ($records as $record) {
      if (file_exists($audioDir . '/' . $record->cache_key . '.ogg') || file_exists($audioDir . '/' . $record->cache_key . '.wav')) {
        $ids[] = $record->entity_id;
      }
    }
    return array_values(array_unique($ids));
  }

  /**
   * Process a batch of entities.
   */
  public function processBatch(
    array $entities,
    array $options,
    int $totalEntities,
    array &$context,
  ): void {
    if (!isset($context['sandbox']['progress'])) {
      try {
        $this->database->query('SET SESSION wait_timeout = 600')->execute();
        $this->database->query('SET SESSION interactive_timeout = 600')->execute();
      }
      catch (\Exception $e) {
        // Continue if SET fails (e.g., insufficient privileges).
      }

      $context['sandbox']['progress'] = 0;
      $context['sandbox']['total'] = count($entities);
      $context['sandbox']['current_batch_start'] = time();
      $context['results'] += [
        'processed' => 0,
        'generated' => 0,
        'cached' => 0,
        'errors' => [],
        'high_load_pauses' => 0,
        'retry_counts' => [],
      ];
    }

    if ($options['max_load_threshold'] !== NULL) {
      if (!$this->canProcessBatch($options['max_load_threshold'])) {
        if ($options['pause_on_high_load']) {
          $context['results']['high_load_pauses']++;
          $load = function_exists('sys_getloadavg') ? round(sys_getloadavg()[0], 2) : 'N/A';
          $context['message'] = $this->t('Server load too high (@load > @threshold). Pausing batch... (Pause #@count)', [
            '@load' => $load,
            '@threshold' => $options['max_load_threshold'],
            '@count' => $context['results']['high_load_pauses'],
          ]);

          sleep(10);
          $context['finished'] = 0;
          return;
        }
        else {
          $context['results']['errors'][] = $this->t('Server load too high to continue processing.');
          $context['finished'] = 1;
          return;
        }
      }
    }

    if ($options['inter_batch_delay'] > 0 && $context['results']['processed'] > 0) {
      sleep($options['inter_batch_delay']);
    }

    foreach (array_slice($entities, $context['sandbox']['progress']) as $entityData) {
      $retryCount = 0;
      $maxRetries = $options['max_retries'];
      $generated = FALSE;

      while ($retryCount <= $maxRetries && !$generated) {
        try {
          try {
            $this->database->query('SELECT 1')->fetchField();
          }
          catch (\Exception $e) {
            Database::closeConnection();
            $this->logger->info('Database connection refreshed during batch processing.');
          }

          $entity = $this->entityTypeManager
            ->getStorage($entityData['entity_type'])
            ->load($entityData['entity_id']);

          if (!$entity) {
            $context['results']['errors'][] = $this->t('Entity @type:@id not found.', [
              '@type' => $entityData['entity_type'],
              '@id' => $entityData['entity_id'],
            ]);
            break;
          }

          if (!($entity instanceof FieldableEntityInterface)) {
            $context['results']['errors'][] = $this->t('Entity @type:@id has no fields.', [
              '@type' => $entityData['entity_type'],
              '@id' => $entityData['entity_id'],
            ]);
            break;
          }

          $langcode = $entityData['langcode'] ?? $entity->language()->getId();
          if ($entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
            $entity = $entity->getTranslation($langcode);
          }

          if (!$entity->access('view', new AnonymousUserSession())) {
            throw new \RuntimeException('Cannot generate audio for private content.');
          }

          $text = $this->ttsService->extractTextFromEntity($entity);

          if (empty($text)) {
            $context['results']['errors'][] = $this->t('No text content found for @type:@id.', [
              '@type' => $entityData['entity_type'],
              '@id' => $entityData['entity_id'],
            ]);
            break;
          }

          $voice = $options['voice'] ?? $this->ttsService->getDefaultVoice($langcode);

          $generationOptions = [
            'language' => $langcode,
            'voice' => $voice,
            'speed' => $options['speed'],
            'entity_type' => $entityData['entity_type'],
            'entity_id' => $entityData['entity_id'],
            'use_cache' => TRUE,
            'force_refresh' => $options['force_refresh'],
          ];

          $cacheKey = $this->ttsService->computeCacheKey($text, $voice, (float) $options['speed'], $langcode);
          $audioDir = $this->configFactory->get('local_tts.settings')->get('audio_directory') ?: 'public://local-tts';
          $wasCached = empty($options['force_refresh']) && file_exists($audioDir . '/' . $cacheKey . '.ogg');

          ob_start();

          try {
            $this->ttsService->generateSpeech($text, $generationOptions);
          }
          finally {
            ob_end_clean();
          }

          $context['results'][$wasCached ? 'cached' : 'generated']++;
          $generated = TRUE;

          $this->logger->info('Audio ready for @type:@id (@lang)', [
            '@type' => $entityData['entity_type'],
            '@id' => $entityData['entity_id'],
            '@lang' => $langcode,
          ]);

        }
        catch (TtsServiceUnavailableException $e) {
          if ($retryCount < $maxRetries) {
            $retryCount++;
            $context['results']['high_load_pauses']++;

            $this->logger->warning('TTS service unavailable, retrying (@retry/@max): @message', [
              '@retry' => $retryCount,
              '@max' => $maxRetries,
              '@message' => $e->getMessage(),
            ]);

            sleep(min(30, 5 * $retryCount));
          }
          else {
            $context['results']['errors'][] = $this->t('Failed after @retries retries: @type:@id - @message', [
              '@retries' => $maxRetries,
              '@type' => $entityData['entity_type'],
              '@id' => $entityData['entity_id'],
              '@message' => $e->getMessage(),
            ]);

            $this->logger->error('TTS generation failed after retries: @type:@id', [
              '@type' => $entityData['entity_type'],
              '@id' => $entityData['entity_id'],
            ]);

            break;
          }
        }
        catch (TtsTimeoutException $e) {
          $context['results']['errors'][] = $this->t('Timeout: @type:@id - @message', [
            '@type' => $entityData['entity_type'],
            '@id' => $entityData['entity_id'],
            '@message' => $e->getMessage(),
          ]);

          $this->logger->error('TTS generation timeout: @type:@id', [
            '@type' => $entityData['entity_type'],
            '@id' => $entityData['entity_id'],
          ]);

          break;
        }
        catch (\Exception $e) {
          $context['results']['errors'][] = $this->t('Error: @type:@id - @message', [
            '@type' => $entityData['entity_type'],
            '@id' => $entityData['entity_id'],
            '@message' => $e->getMessage(),
          ]);

          $this->logger->error('TTS generation error: @type:@id - @error', [
            '@type' => $entityData['entity_type'],
            '@id' => $entityData['entity_id'],
            '@error' => $e->getMessage(),
          ]);

          break;
        }
      }

      $context['results']['processed']++;
      $context['sandbox']['progress']++;

      if ($retryCount > 0 && $generated) {
        $context['results']['retry_counts'][$entityData['entity_id']] = $retryCount;
      }
    }

    $elapsed = time() - $context['sandbox']['current_batch_start'];
    $rate = $context['sandbox']['progress'] > 0
      ? $elapsed / $context['sandbox']['progress']
      : 0;
    $remaining = max(0, $totalEntities - $context['results']['processed']);
    $estimate = $rate > 0 ? round(($remaining * $rate) / 60, 1) : '?';

    $context['message'] = $this->t('Processed @current of @total entities. Generated: @generated, Cached: @cached, Errors: @errors. Est. @estimate min remaining.', [
      '@current' => $context['results']['processed'],
      '@total' => $totalEntities,
      '@generated' => $context['results']['generated'],
      '@cached' => $context['results']['cached'],
      '@errors' => count($context['results']['errors']),
      '@estimate' => $estimate,
    ]);

    if ($context['sandbox']['total'] > 0) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['total'];
    }
    else {
      $context['finished'] = 1;
    }
  }

  /**
   * Check if we can process batch based on server load.
   */
  protected function canProcessBatch(float $maxLoad): bool {
    if (!function_exists('sys_getloadavg')) {
      return TRUE;
    }

    $load = sys_getloadavg();
    $currentLoad = $load[0];

    return $currentLoad <= $maxLoad;
  }

}
