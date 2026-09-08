<?php

namespace Drupal\local_tts\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Manages TTS audio cache metadata and file operations.
 */
class TtsCacheManager {

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FileSystemInterface $fileSystem,
    protected Connection $database,
    protected readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('local_tts');
  }

  /**
   * Save metadata for an audio file.
   */
  public function saveMetadata(string $cacheKey, string $text, string $voice, float $speed, array $options = []): void {
    $config = $this->configFactory->get('local_tts.settings');
    $audioDir = $config->get('audio_directory') ?: 'public://local-tts';
    $directory = $this->fileSystem->realpath($audioDir);

    if (!$directory) {
      throw new \RuntimeException(sprintf('Audio directory could not be resolved: %s', $audioDir));
    }

    $filePath = $directory . '/' . $cacheKey . '.ogg';
    if (!file_exists($filePath)) {
      $filePath = $directory . '/' . $cacheKey . '.wav';
    }
    $fileSize = file_exists($filePath) ? filesize($filePath) : 0;
    $now = $this->time->getRequestTime();

    $record = [
      'cache_key' => $cacheKey,
      'text_hash' => md5($text),
      'voice' => $voice,
      'speed' => $speed,
      'language' => $options['language'] ?? 'en',
      'file_size' => $fileSize,
      'accessed' => $now,
    ];

    if (!empty($options['entity_type']) && !empty($options['entity_id'])) {
      $record['entity_type'] = $options['entity_type'];
      $record['entity_id'] = $options['entity_id'];
    }

    // Reconnect if the database timed out during long TTS generation.
    try {
      $this->database->query('SELECT 1')->fetchField();
    }
    catch (\Exception $e) {
      Database::closeConnection();
      $this->database = Database::getConnection();
      $this->logger->info('Database connection refreshed before saving metadata.');
    }

    $mergeKeys = [];
    if (isset($record['entity_type'], $record['entity_id'])) {
      $mergeKeys = [
        'entity_type' => $record['entity_type'],
        'entity_id' => $record['entity_id'],
        'language' => $record['language'],
        'cache_key' => $cacheKey,
      ];
    }
    else {
      $mergeKeys = ['cache_key' => $cacheKey];
    }

    $this->database->merge('local_tts_cache')
      ->keys($mergeKeys)
      ->insertFields($record + ['created' => $now])
      ->updateFields($record)
      ->execute();
  }

  /**
   * Load metadata for an audio file.
   */
  public function loadMetadata(string $cacheKey): ?array {
    $result = $this->database->select('local_tts_cache', 'a')
      ->fields('a')
      ->condition('cache_key', $cacheKey)
      ->execute()
      ->fetchAssoc();

    if ($result) {
      $this->database->update('local_tts_cache')
        ->fields(['accessed' => $this->time->getRequestTime()])
        ->condition('cache_key', $cacheKey)
        ->execute();
    }

    return $result ?: NULL;
  }

  /**
   * Delete all audio files for a specific entity (all languages).
   */
  public function deleteAudioForEntity(string $entityType, int|string $entityId): int {
    return $this->deleteAudioMatching([
      'entity_type' => $entityType,
      'entity_id' => $entityId,
    ]);
  }

  /**
   * Delete audio for a single entity translation.
   */
  public function deleteAudioForEntityTranslation(string $entityType, int|string $entityId, string $langcode): int {
    return $this->deleteAudioMatching([
      'entity_type' => $entityType,
      'entity_id' => $entityId,
      'language' => $langcode,
    ]);
  }

  /**
   * Clear the entire audio cache.
   */
  public function clearCache(): bool {
    $config = $this->configFactory->get('local_tts.settings');
    $audioDir = $config->get('audio_directory') ?: 'public://local-tts';

    try {
      if (!$this->fileSystem->deleteRecursive($audioDir)) {
        return FALSE;
      }
      $this->database->truncate('local_tts_cache')->execute();
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear audio cache: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Get disk usage statistics from the cache table.
   */
  public function getDiskUsage(): array {
    try {
      $files = $this->database->select('local_tts_cache', 'c');
      $files->fields('c', ['cache_key']);
      $files->addExpression('MAX(file_size)', 'file_size');
      $files->groupBy('cache_key');
      $query = $this->database->select($files, 'files');
      $query->addExpression('COALESCE(SUM(file_size), 0)', 'total_size');
      $query->addExpression('COUNT(DISTINCT cache_key)', 'file_count');
      $result = $query->execute()->fetchObject();
      return [
        'total_size' => (int) $result->total_size,
        'file_count' => (int) $result->file_count,
      ];
    }
    catch (\Exception $e) {
      return ['total_size' => 0, 'file_count' => 0];
    }
  }

  /**
   * Remove matching metadata, deleting files only after their last reference.
   */
  protected function deleteAudioMatching(array $conditions): int {
    $select = $this->database->select('local_tts_cache', 'c')->fields('c', ['cache_key']);
    $delete = $this->database->delete('local_tts_cache');
    foreach ($conditions as $field => $value) {
      $select->condition($field, $value);
      $delete->condition($field, $value);
    }
    $keys = $select->execute()->fetchCol();
    $delete->execute();

    $audioDir = $this->configFactory->get('local_tts.settings')->get('audio_directory') ?: 'public://local-tts';
    $deleted = 0;
    foreach (array_unique($keys) as $key) {
      $remaining = $this->database->select('local_tts_cache', 'c')
        ->condition('cache_key', $key)->countQuery()->execute()->fetchField();
      if (!$remaining) {
        foreach (['.ogg', '.wav'] as $extension) {
          $uri = $audioDir . '/' . $key . $extension;
          if (file_exists($uri) && $this->fileSystem->delete($uri)) {
            $deleted++;
          }
        }
      }
    }
    return $deleted;
  }

}
