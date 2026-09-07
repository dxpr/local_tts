<?php

namespace Drupal\local_tts\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for single-file cache deletion actions.
 */
final class TtsCacheController extends ControllerBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('file_system')
    );
  }

  public function __construct(Connection $database, FileSystemInterface $file_system) {
    $this->database = $database;
    $this->fileSystem = $file_system;
  }

  /**
   * Delete a single cached audio file.
   */
  public function deleteFile(string $cache_key) {
    if (!preg_match('/^[a-f0-9]{32}$/', $cache_key)) {
      $this->messenger()->addError($this->t('Invalid cache key.'));
      return $this->redirect('local_tts.cache_overview');
    }

    $config = $this->config('local_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';
    $directory = $this->fileSystem->realpath($audio_dir);

    if ($directory) {
      $file_path = $directory . '/' . $cache_key . '.wav';
      if (file_exists($file_path)) {
        @unlink($file_path);
      }
    }

    $this->database->delete('local_tts_cache')
      ->condition('cache_key', $cache_key)
      ->execute();

    $this->messenger()->addStatus($this->t('Audio file deleted.'));
    return $this->redirect('local_tts.cache_overview');
  }

}
