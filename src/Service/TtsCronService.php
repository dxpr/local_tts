<?php

namespace Drupal\local_tts\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;

/**
 * Handles cron maintenance tasks for the TTS cache.
 */
class TtsCronService {

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly Connection $database,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly FileSystemInterface $fileSystem,
    protected readonly TtsCacheManager $cacheManager,
    protected readonly TimeInterface $time,
    protected readonly StateInterface $state,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('local_tts');
  }

  /**
   * Run all cron maintenance tasks.
   */
  public function run(): void {
    $config = $this->configFactory->get('local_tts.settings');

    $this->cleanupStaleContent();

    if ($config->get('cache_size_limit_enabled')) {
      $this->enforceSizeLimit();
    }

    $this->cleanupOrphanFiles();
  }

  /**
   * Clean up audio references whose source entities have been deleted.
   */
  public function cleanupStaleContent(): void {
    $deleted = 0;
    $records = $this->database->select('local_tts_cache', 'a')
      ->fields('a', ['entity_type', 'entity_id'])
      ->distinct()
      ->isNotNull('entity_type')
      ->isNotNull('entity_id')
      ->execute()
      ->fetchAll();

    $grouped = [];
    foreach ($records as $record) {
      $grouped[$record->entity_type][$record->entity_id][] = $record;
    }

    $staleRecords = [];

    foreach ($grouped as $entityType => $idRecords) {
      try {
        $storage = $this->entityTypeManager->getStorage($entityType);
        $ids = array_keys($idRecords);
        $loaded = $storage->loadMultiple($ids);

        foreach ($ids as $id) {
          if (!isset($loaded[$id])) {
            foreach ($idRecords[$id] as $record) {
              $staleRecords[] = $record;
            }
          }
        }
      }
      catch (\Exception $e) {
        foreach ($idRecords as $entityRecords) {
          foreach ($entityRecords as $record) {
            $staleRecords[] = $record;
          }
        }
      }
    }

    foreach ($staleRecords as $record) {
      $deleted += $this->cacheManager->deleteAudioForEntity($record->entity_type, $record->entity_id);
    }

    if ($deleted > 0) {
      $this->logger->info('Deleted @count stale audio files', [
        '@count' => $deleted,
      ]);
    }
  }

  /**
   * Enforce size limit by deleting least recently accessed files.
   */
  public function enforceSizeLimit(): void {
    $config = $this->configFactory->get('local_tts.settings');
    $maxSize = $config->get('cache_max_size') ?: 1073741824;
    $directory = $this->getAudioDirectory();

    if (!$directory) {
      return;
    }

    $query = $this->database->select('local_tts_cache', 'a');
    $query->fields('a', ['cache_key']);
    $query->addExpression('MAX(accessed)', 'last_accessed');
    $records = $query->groupBy('cache_key')
      ->orderBy('last_accessed', 'ASC')
      ->execute()
      ->fetchAll();
    $currentSize = 0;
    foreach ($records as $record) {
      $record->files = [];
      foreach (['.ogg', '.wav'] as $extension) {
        $path = $directory . '/' . $record->cache_key . $extension;
        if (file_exists($path)) {
          $size = (int) filesize($path);
          $record->files[$path] = $size;
          $currentSize += $size;
        }
      }
    }

    $bytesFreed = 0;
    $deleted = 0;
    foreach ($records as $record) {
      if ($currentSize - $bytesFreed <= $maxSize) {
        break;
      }
      $removed = TRUE;
      foreach ($record->files as $path => $size) {
        if (@unlink($path)) {
          $bytesFreed += $size;
          $deleted++;
        }
        else {
          $removed = FALSE;
        }
      }
      if ($removed) {
        $this->database->delete('local_tts_cache')
          ->condition('cache_key', $record->cache_key)
          ->execute();
      }
    }

    if ($deleted > 0) {
      $this->logger->info('Deleted @count files (@size freed)', [
        '@count' => $deleted,
        '@size' => ByteSizeMarkup::create($bytesFreed),
      ]);
    }
  }

  /**
   * Remove disk files that have no corresponding database record.
   */
  public function cleanupOrphanFiles(): void {
    $lastRun = $this->state->get('local_tts.orphan_cleanup_last_run', 0);
    if ($this->time->getRequestTime() - $lastRun < 21600) {
      return;
    }
    $this->state->set('local_tts.orphan_cleanup_last_run', $this->time->getRequestTime());

    $directory = $this->getAudioDirectory();
    if (!$directory) {
      return;
    }

    $files = array_merge(
      glob($directory . '/*.ogg') ?: [],
      glob($directory . '/*.wav') ?: [],
      glob($directory . '/*.part') ?: []
    );

    if (empty($files)) {
      return;
    }

    $now = $this->time->getRequestTime();

    $knownKeys = $this->database->select('local_tts_cache', 'a')
      ->fields('a', ['cache_key'])
      ->execute()
      ->fetchCol();
    $knownKeys = array_flip($knownKeys);

    $deleted = 0;
    foreach ($files as $filePath) {
      $mtime = @filemtime($filePath);
      if ($mtime !== FALSE && $now - $mtime < 3600) {
        continue;
      }
      $cacheKey = explode('.', basename($filePath))[0];
      if (!isset($knownKeys[$cacheKey])) {
        @unlink($filePath);
        $deleted++;
        if ($deleted >= 500) {
          break;
        }
      }
    }

    if ($deleted > 0) {
      $this->logger->info('Deleted @count orphan audio files', [
        '@count' => $deleted,
      ]);
    }
  }

  /**
   * Check whether an entity bundle is allowed for TTS.
   */
  public function isBundleAllowed(string $entityType, string $bundle): bool {
    $config = $this->configFactory->get('local_tts.settings');
    $allowed = $config->get('allowed_bundles') ?? [];
    if (empty($allowed)) {
      return TRUE;
    }
    $key = $entityType . ':' . $bundle;
    return !empty($allowed[$key]);
  }

  /**
   * Get the real path to the audio cache directory.
   */
  public function getAudioDirectory(): ?string {
    $config = $this->configFactory->get('local_tts.settings');
    $audioDir = $config->get('audio_directory') ?: 'public://local-tts';
    $directory = $this->fileSystem->realpath($audioDir);

    if (!$directory || !is_dir($directory)) {
      return NULL;
    }

    return $directory;
  }

}
