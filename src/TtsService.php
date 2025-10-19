<?php

namespace Drupal\ai_tts;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Service for interfacing with Kokoro TTS binary.
 */
class TtsService {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $logger;

  /**
   * Constructs a TtsService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory, FileSystemInterface $file_system, LoggerChannelFactoryInterface $logger_factory) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('ai_tts');
  }

  /**
   * Generate speech from text using Kokoro TTS.
   *
   * @param string $text
   *   The text to convert to speech.
   * @param array $options
   *   Optional parameters:
   *   - voice: Voice to use (default: from config)
   *   - speed: Speech speed (default: from config)
   *   - use_cache: Whether to use cached audio (default: from config)
   *
   * @return string|null
   *   The file URI of the generated audio, or NULL on failure.
   */
  public function generateSpeech($text, array $options = []) {
    $config = $this->configFactory->get('ai_tts.settings');

    $voice = $options['voice'] ?? $config->get('default_voice');
    $speed = $options['speed'] ?? $config->get('default_speed');
    $use_cache = $options['use_cache'] ?? $config->get('cache_audio');

    $cache_key = md5($text . $voice . $speed);
    $audio_dir = $config->get('audio_directory') ?: 'public://ai-tts';

    if ($use_cache) {
      $cached_file = $audio_dir . '/' . $cache_key . '.wav';
      if (file_exists($cached_file)) {
        return $cached_file;
      }
    }

    $directory = $this->fileSystem->realpath($audio_dir);
    if (!$directory) {
      $this->fileSystem->prepareDirectory($audio_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
      $directory = $this->fileSystem->realpath($audio_dir);
    }

    $output_file = $directory . '/' . $cache_key . '.wav';
    $binary_path = $config->get('koko_binary_path');

    if (!file_exists($binary_path) || !is_executable($binary_path)) {
      $this->logger->error('Koko binary not found or not executable at: @path', ['@path' => $binary_path]);
      return NULL;
    }

    $home_dir = getenv('HOME');
    $model_path = $home_dir . '/.cache/kokoros/checkpoints/kokoro-v1.0.onnx';
    $data_path = $home_dir . '/.cache/kokoros/data/voices-v1.0.bin';

    $command = sprintf(
      '%s --model %s --data %s --style %s --speed %s text %s --output %s 2>&1',
      escapeshellarg($binary_path),
      escapeshellarg($model_path),
      escapeshellarg($data_path),
      escapeshellarg($voice),
      escapeshellarg((string) $speed),
      escapeshellarg($text),
      escapeshellarg($output_file)
    );

    $this->logger->info('Executing command: @command', ['@command' => $command]);

    exec($command, $output, $return_code);

    if ($return_code !== 0) {
      $this->logger->error('Koko TTS failed with return code @code. Output: @output', [
        '@code' => $return_code,
        '@output' => implode("\n", $output),
      ]);
      return NULL;
    }

    if (!file_exists($output_file)) {
      $this->logger->error('Audio file was not created at: @path', ['@path' => $output_file]);
      return NULL;
    }

    $uri = $audio_dir . '/' . $cache_key . '.wav';

    return $uri;
  }

  /**
   * Get available voices from Kokoro.
   *
   * @return array
   *   Array of available voice names.
   */
  public function getAvailableVoices() {
    return [
      'af_sky',
      'af_nicole',
      'af_heart',
      'af_bella',
      'af_sarah',
      'am_adam',
      'am_michael',
      'bf_emma',
      'bf_isabella',
      'bm_george',
      'bm_lewis',
    ];
  }

  /**
   * Clear the audio cache.
   *
   * @return bool
   *   TRUE on success, FALSE on failure.
   */
  public function clearCache() {
    $config = $this->configFactory->get('ai_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://ai-tts';

    try {
      $this->fileSystem->deleteRecursive($audio_dir);
      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to clear audio cache: @message', ['@message' => $e->getMessage()]);
      return FALSE;
    }
  }

}
