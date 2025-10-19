<?php

namespace Drupal\ai_tts;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\ai_tts\Exception\TtsServiceUnavailableException;
use Drupal\ai_tts\Exception\TtsTimeoutException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Service for interfacing with Kokoro TTS binary.
 */
class TtsService {

  /**
   * Language to voice prefix mapping.
   */
  const LANGUAGE_MAP = [
    'en' => ['af_', 'am_', 'bf_', 'bm_'],
    'en-us' => ['af_', 'am_'],
    'en-gb' => ['bf_', 'bm_'],
    'ja' => ['jf_', 'jm_'],
    'zh' => ['zf_', 'zm_'],
    'zh-hans' => ['zf_', 'zm_'],
    'zh-hant' => ['zf_', 'zm_'],
    'fr' => ['ff_'],
    'hi' => ['hf_', 'hm_'],
    'es' => ['ef_', 'em_'],
    'it' => ['if_', 'im_'],
    'pt' => ['pf_', 'pm_'],
    'pt-br' => ['pf_', 'pm_'],
    'pt-pt' => ['pf_', 'pm_'],
  ];

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
   *   - language: Language code for G2P (default: detected from voice)
   *   - use_cache: Whether to use cached audio (default: from config)
   *   - entity_type: Entity type for tracking (e.g., 'node')
   *   - entity_id: Entity ID for tracking.
   *
   * @return string|null
   *   The file URI of the generated audio, or NULL on failure.
   *
   * @throws \InvalidArgumentException
   *   When validation fails for text length, voice, or speed.
   */
  public function generateSpeech($text, array $options = []) {
    $config = $this->configFactory->get('ai_tts.settings');

    // Security: Only generate audio for publicly accessible content.
    if (!empty($options['entity_type']) && !empty($options['entity_id'])) {
      $entity_type = $options['entity_type'];
      $entity_id = $options['entity_id'];

      try {
        $entity = \Drupal::entityTypeManager()
          ->getStorage($entity_type)
          ->load($entity_id);

        if ($entity) {
          $anonymous = new AnonymousUserSession();
          if (!$entity->access('view', $anonymous)) {
            $this->logger->warning('Refusing to cache audio for non-public content: @type:@id', [
              '@type' => $entity_type,
              '@id' => $entity_id,
            ]);
            throw new \RuntimeException('Cannot generate audio for private content. Only content viewable by anonymous users can be cached.');
          }
        }
      }
      catch (\RuntimeException $e) {
        throw $e;
      }
      catch (\Exception $e) {
        $this->logger->error('Error checking entity access: @message', ['@message' => $e->getMessage()]);
      }
    }

    // Security: Validate text length.
    $max_length = $config->get('max_text_length') ?? 1000000;
    if ($max_length > 0 && mb_strlen($text) > $max_length) {
      $this->logger->warning('Text length (@length chars) exceeds maximum (@max chars)', [
        '@length' => mb_strlen($text),
        '@max' => $max_length,
      ]);
      throw new \InvalidArgumentException(sprintf('Text length (%d characters) exceeds maximum allowed (%d characters)', mb_strlen($text), $max_length));
    }

    $voice = $options['voice'] ?? $config->get('default_voice');
    $speed = $options['speed'] ?? $config->get('default_speed');
    $language = $options['language'] ?? $this->detectLanguageFromVoice($voice);
    $use_cache = $options['use_cache'] ?? $config->get('cache_audio');

    // Security: Validate voice against allowed list.
    $available_voices = array_keys($this->getAvailableVoices());
    if (!in_array($voice, $available_voices, TRUE)) {
      $this->logger->error('Invalid voice: @voice', ['@voice' => $voice]);
      throw new \InvalidArgumentException(sprintf('Invalid voice: %s', $voice));
    }

    // Security: Validate speed is within safe range.
    $speed = (float) $speed;
    if ($speed < 0.5 || $speed > 2.0) {
      $this->logger->error('Invalid speed: @speed (must be between 0.5 and 2.0)', ['@speed' => $speed]);
      throw new \InvalidArgumentException(sprintf('Invalid speed: %s (must be between 0.5 and 2.0)', $speed));
    }

    // Convert to espeak-ng language identifier.
    $espeak_lang = $this->mapLanguageToEspeak($language);

    // Include language in cache key to prevent wrong pronunciation.
    $cache_key = md5($text . $voice . $speed . $espeak_lang);
    $audio_dir = $config->get('audio_directory') ?: 'public://ai-tts';

    if ($use_cache) {
      $cached_file = $audio_dir . '/' . $cache_key . '.wav';
      if (file_exists($cached_file)) {
        return $cached_file;
      }
    }

    $directory = $this->fileSystem->realpath($audio_dir);
    if (!$directory) {
      if (!$this->fileSystem->prepareDirectory($audio_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
        $this->logger->error('Failed to create audio directory: @dir', ['@dir' => $audio_dir]);
        throw new TtsServiceUnavailableException(sprintf('Failed to create audio directory: %s', $audio_dir));
      }
      $directory = $this->fileSystem->realpath($audio_dir);
      if (!$directory) {
        throw new TtsServiceUnavailableException(sprintf('Audio directory path could not be resolved: %s', $audio_dir));
      }
    }

    $output_file = $directory . '/' . $cache_key . '.wav';
    $binary_path = $config->get('koko_binary_path');

    if (!file_exists($binary_path)) {
      $this->logger->error('Koko binary not found at: @path', ['@path' => $binary_path]);
      throw new TtsServiceUnavailableException(sprintf('TTS binary not found at: %s', $binary_path));
    }

    if (!is_executable($binary_path)) {
      $this->logger->error('Koko binary not executable at: @path', ['@path' => $binary_path]);
      throw new TtsServiceUnavailableException(sprintf('TTS binary not executable at: %s', $binary_path));
    }

    // Get model paths from configuration.
    $model_path = $config->get('model_path');
    $data_path = $config->get('data_path');

    // Expand tilde in paths.
    $model_path = $this->expandPath($model_path);
    $data_path = $this->expandPath($data_path);

    // Validate model files exist.
    if (!file_exists($model_path)) {
      $this->logger->error('Model file not found at: @path', ['@path' => $model_path]);
      throw new TtsServiceUnavailableException(sprintf('Model file not found at: %s', $model_path));
    }
    if (!file_exists($data_path)) {
      $this->logger->error('Data file not found at: @path', ['@path' => $data_path]);
      throw new TtsServiceUnavailableException(sprintf('Data file not found at: %s', $data_path));
    }

    $command = sprintf(
      '%s --lan %s --model %s --data %s --style %s --speed %s text %s --output %s 2>&1',
      escapeshellarg($binary_path),
      escapeshellarg($espeak_lang),
      escapeshellarg($model_path),
      escapeshellarg($data_path),
      escapeshellarg($voice),
      escapeshellarg((string) $speed),
      escapeshellarg($text),
      escapeshellarg($output_file)
    );

    $this->logger->info('Executing command: @command', ['@command' => $command]);

    // Security: Execute with timeout to prevent hanging.
    $timeout = $config->get('generation_timeout') ?? 900;
    $result = $this->execWithTimeout($command, $timeout);

    if ($result['return_code'] !== 0) {
      if ($result['timeout']) {
        $this->logger->error('Koko TTS timed out after @timeout seconds', ['@timeout' => $timeout]);
        throw new TtsTimeoutException(sprintf('TTS generation timed out after %d seconds', $timeout));
      }
      $this->logger->error('Koko TTS failed with return code @code. Output: @output', [
        '@code' => $result['return_code'],
        '@output' => implode("\n", $result['output']),
      ]);
      throw new \RuntimeException(sprintf('TTS generation failed with exit code %d: %s', $result['return_code'], implode(' ', array_slice($result['output'], -3))));
    }

    if (!file_exists($output_file)) {
      $this->logger->error('Audio file was not created at: @path', ['@path' => $output_file]);
      throw new TtsServiceUnavailableException(sprintf('TTS audio file was not created at: %s', $output_file));
    }

    $uri = $audio_dir . '/' . $cache_key . '.wav';

    // Save metadata for content tracking if enabled.
    if ($config->get('cache_content_tracking_enabled')) {
      $this->saveMetadata($cache_key, $text, $voice, $speed, $options);
    }

    return $uri;
  }

  /**
   * Detect language code from voice name.
   *
   * @param string $voice
   *   The voice code (e.g., 'af_sky', 'ef_dora').
   *
   * @return string
   *   The language code (e.g., 'en', 'es', 'ja').
   */
  public function detectLanguageFromVoice($voice) {
    // Extract first letter from voice prefix.
    $prefix = substr($voice, 0, 1);

    $map = [
      'a' => 'en',
      'b' => 'en-gb',
      'e' => 'es',
      'f' => 'fr',
      'h' => 'hi',
      'i' => 'it',
      'j' => 'ja',
      'p' => 'pt',
      'z' => 'zh',
    ];

    return $map[$prefix] ?? 'en';
  }

  /**
   * Map Drupal language code to espeak-ng language identifier.
   *
   * @param string $langcode
   *   Drupal language code (e.g., 'en', 'es', 'ja').
   *
   * @return string
   *   espeak-ng language identifier.
   *
   * @see https://github.com/espeak-ng/espeak-ng/blob/master/docs/languages.md
   */
  protected function mapLanguageToEspeak($langcode) {
    // Normalize to lowercase.
    $langcode = strtolower($langcode);

    // Map Drupal language codes to espeak-ng identifiers.
    $map = [
      'en' => 'en-us',
      'en-us' => 'en-us',
      'en-gb' => 'en-gb',
      'es' => 'es',
      'fr' => 'fr-fr',
      'hi' => 'hi',
      'it' => 'it',
      'ja' => 'ja',
      'pt' => 'pt-pt',
      'pt-br' => 'pt-br',
      'pt-pt' => 'pt-pt',
      'zh' => 'cmn',
      'zh-hans' => 'cmn',
      'zh-hant' => 'cmn',
      'ko' => 'ko',
    ];

    return $map[$langcode] ?? 'en-us';
  }

  /**
   * Get available voices from Kokoro.
   *
   * @param string|null $langcode
   *   Optional language code to filter voices. If provided, only voices
   *   for that language will be returned.
   *
   * @return array
   *   Array of available voice names keyed by voice code.
   */
  public function getAvailableVoices($langcode = NULL) {
    $all_voices = [
      // American English - Female.
      'af_alloy' => 'Alloy (American English Female)',
      'af_aoede' => 'Aoede (American English Female)',
      'af_bella' => 'Bella (American English Female)',
      'af_heart' => 'Heart (American English Female)',
      'af_jessica' => 'Jessica (American English Female)',
      'af_kore' => 'Kore (American English Female)',
      'af_nicole' => 'Nicole (American English Female)',
      'af_nova' => 'Nova (American English Female)',
      'af_river' => 'River (American English Female)',
      'af_sarah' => 'Sarah (American English Female)',
      'af_sky' => 'Sky (American English Female)',
      // American English - Male.
      'am_adam' => 'Adam (American English Male)',
      'am_echo' => 'Echo (American English Male)',
      'am_eric' => 'Eric (American English Male)',
      'am_fenrir' => 'Fenrir (American English Male)',
      'am_liam' => 'Liam (American English Male)',
      'am_michael' => 'Michael (American English Male)',
      'am_onyx' => 'Onyx (American English Male)',
      'am_puck' => 'Puck (American English Male)',
      'am_santa' => 'Santa (American English Male)',
      // British English - Female.
      'bf_alice' => 'Alice (British English Female)',
      'bf_emma' => 'Emma (British English Female)',
      'bf_isabella' => 'Isabella (British English Female)',
      'bf_lily' => 'Lily (British English Female)',
      // British English - Male.
      'bm_daniel' => 'Daniel (British English Male)',
      'bm_fable' => 'Fable (British English Male)',
      'bm_george' => 'George (British English Male)',
      'bm_lewis' => 'Lewis (British English Male)',
      // Japanese - Female.
      'jf_alpha' => 'Alpha (Japanese Female)',
      'jf_gongitsune' => 'Gongitsune (Japanese Female)',
      'jf_nezumi' => 'Nezumi (Japanese Female)',
      'jf_tebukuro' => 'Tebukuro (Japanese Female)',
      // Japanese - Male.
      'jm_kumo' => 'Kumo (Japanese Male)',
      // Mandarin Chinese - Female.
      'zf_xiaobei' => 'Xiaobei (Mandarin Chinese Female)',
      'zf_xiaoni' => 'Xiaoni (Mandarin Chinese Female)',
      'zf_xiaoxiao' => 'Xiaoxiao (Mandarin Chinese Female)',
      'zf_xiaoyi' => 'Xiaoyi (Mandarin Chinese Female)',
      // Mandarin Chinese - Male.
      'zm_yunjian' => 'Yunjian (Mandarin Chinese Male)',
      'zm_yunxia' => 'Yunxia (Mandarin Chinese Male)',
      'zm_yunxi' => 'Yunxi (Mandarin Chinese Male)',
      'zm_yunyang' => 'Yunyang (Mandarin Chinese Male)',
      // French - Female.
      'ff_siwis' => 'Siwis (French Female)',
      // Hindi - Female.
      'hf_alpha' => 'Alpha (Hindi Female)',
      'hf_beta' => 'Beta (Hindi Female)',
      // Hindi - Male.
      'hm_omega' => 'Omega (Hindi Male)',
      'hm_psi' => 'Psi (Hindi Male)',
      // Spanish - Female.
      'ef_dora' => 'Dora (Spanish Female)',
      // Spanish - Male.
      'em_alex' => 'Alex (Spanish Male)',
      'em_santa' => 'Santa (Spanish Male)',
      // Italian - Female.
      'if_sara' => 'Sara (Italian Female)',
      // Italian - Male.
      'im_nicola' => 'Nicola (Italian Male)',
      // Portuguese - Female.
      'pf_dora' => 'Dora (Portuguese Female)',
      // Portuguese - Male.
      'pm_alex' => 'Alex (Portuguese Male)',
      'pm_santa' => 'Santa (Portuguese Male)',
    ];

    if ($langcode !== NULL) {
      return $this->filterVoicesByLanguage($all_voices, $langcode);
    }

    return $all_voices;
  }

  /**
   * Filter voices by language code.
   *
   * @param array $voices
   *   All available voices.
   * @param string $langcode
   *   The language code to filter by.
   *
   * @return array
   *   Filtered voices for the specified language.
   */
  protected function filterVoicesByLanguage(array $voices, $langcode) {
    // Normalize language code to lowercase.
    $langcode = strtolower($langcode);

    // Get prefixes for this language.
    $prefixes = self::LANGUAGE_MAP[$langcode] ?? [];

    // If no exact match, try base language (e.g., 'en' from 'en-au').
    if (empty($prefixes) && strpos($langcode, '-') !== FALSE) {
      $base_lang = explode('-', $langcode)[0];
      $prefixes = self::LANGUAGE_MAP[$base_lang] ?? [];
    }

    // If still no match, return empty array.
    if (empty($prefixes)) {
      return [];
    }

    // Filter voices by prefix.
    $filtered = [];
    foreach ($voices as $code => $label) {
      foreach ($prefixes as $prefix) {
        if (strpos($code, $prefix) === 0) {
          $filtered[$code] = $label;
          break;
        }
      }
    }

    return $filtered;
  }

  /**
   * Get list of supported language codes.
   *
   * @return array
   *   Array of supported language codes (e.g., ['en', 'es', 'ja', ...]).
   */
  public function getSupportedLanguages() {
    return array_keys(self::LANGUAGE_MAP);
  }

  /**
   * Normalize speed value to valid select option.
   *
   * @param mixed $speed
   *   The speed value to normalize.
   *
   * @return string
   *   A valid speed value ('0.8', '1', '1.2', '1.5', or '2').
   */
  public function normalizeSpeed($speed) {
    $speed = (string) $speed;
    $available_speeds = ['0.8', '1', '1.2', '1.5', '2'];
    if (in_array($speed, $available_speeds, TRUE)) {
      return $speed;
    }
    return '1';
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

  /**
   * Save metadata for an audio file.
   *
   * @param string $cache_key
   *   The cache key (filename without extension).
   * @param string $text
   *   The source text.
   * @param string $voice
   *   The voice used.
   * @param float $speed
   *   The speed used.
   * @param array $options
   *   Additional options including entity tracking info.
   */
  protected function saveMetadata($cache_key, $text, $voice, $speed, array $options = []) {
    $config = $this->configFactory->get('ai_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://ai-tts';
    $directory = $this->fileSystem->realpath($audio_dir);

    if (!$directory) {
      return;
    }

    $metadata = [
      'text_hash' => md5($text),
      'voice' => $voice,
      'speed' => $speed,
      'generated' => \Drupal::time()->getRequestTime(),
    ];

    // Add entity tracking if provided.
    if (!empty($options['entity_type']) && !empty($options['entity_id'])) {
      $metadata['entity_type'] = $options['entity_type'];
      $metadata['entity_id'] = $options['entity_id'];

      // Get entity changed time if available.
      try {
        $entity = \Drupal::entityTypeManager()
          ->getStorage($options['entity_type'])
          ->load($options['entity_id']);

        if ($entity && method_exists($entity, 'getChangedTime')) {
          $metadata['entity_changed'] = $entity->getChangedTime();
        }
      }
      catch (\Exception $e) {
        // Entity doesn't exist or can't be loaded, skip.
      }
    }

    $metadata_file = $directory . '/' . $cache_key . '.json';
    file_put_contents($metadata_file, json_encode($metadata, JSON_PRETTY_PRINT));
  }

  /**
   * Load metadata for an audio file.
   *
   * @param string $cache_key
   *   The cache key (filename without extension).
   *
   * @return array|null
   *   The metadata array, or NULL if not found.
   */
  public function loadMetadata($cache_key) {
    $config = $this->configFactory->get('ai_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://ai-tts';
    $directory = $this->fileSystem->realpath($audio_dir);

    if (!$directory) {
      return NULL;
    }

    $metadata_file = $directory . '/' . $cache_key . '.json';

    if (!file_exists($metadata_file)) {
      return NULL;
    }

    $contents = file_get_contents($metadata_file);
    return json_decode($contents, TRUE);
  }

  /**
   * Delete audio files for a specific entity.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int|string $entity_id
   *   The entity ID.
   *
   * @return int
   *   The number of files deleted.
   */
  public function deleteAudioForEntity($entity_type, $entity_id) {
    $config = $this->configFactory->get('ai_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://ai-tts';
    $directory = $this->fileSystem->realpath($audio_dir);

    if (!$directory || !is_dir($directory)) {
      return 0;
    }

    $deleted = 0;
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
      if ($file->isFile() && $file->getExtension() === 'json') {
        $metadata = json_decode(file_get_contents($file->getPathname()), TRUE);

        if (isset($metadata['entity_type']) && isset($metadata['entity_id']) &&
            $metadata['entity_type'] === $entity_type &&
            $metadata['entity_id'] == $entity_id) {

          // Delete both the metadata file and the corresponding WAV file.
          $base_path = substr($file->getPathname(), 0, -5);
          @unlink($file->getPathname());
          @unlink($base_path . '.wav');
          $deleted++;
        }
      }
    }

    if ($deleted > 0) {
      $this->logger->info('Deleted @count audio files for @type:@id', [
        '@count' => $deleted,
        '@type' => $entity_type,
        '@id' => $entity_id,
      ]);
    }

    return $deleted;
  }

  /**
   * Expand path with tilde (~) to full path.
   *
   * @param string $path
   *   Path potentially containing ~.
   *
   * @return string
   *   Expanded path.
   */
  protected function expandPath($path) {
    if (strpos($path, '~') === 0) {
      $home = getenv('HOME');
      if ($home) {
        return str_replace('~', $home, $path);
      }
    }
    return $path;
  }

  /**
   * Execute a command with timeout support.
   *
   * @param string $command
   *   The command to execute.
   * @param int $timeout
   *   Maximum execution time in seconds.
   *
   * @return array
   *   Array containing:
   *   - output: Command output lines
   *   - return_code: Exit code
   *   - timeout: Boolean indicating if command timed out
   */
  protected function execWithTimeout($command, $timeout = 60) {
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
      return [
        'output' => ['Failed to start process'],
        'return_code' => -1,
        'timeout' => FALSE,
      ];
    }

    // Close stdin.
    fclose($pipes[0]);

    // Set streams to non-blocking.
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);

    $start_time = time();
    $output = '';
    $error_output = '';

    // Poll for output until timeout or process exits.
    while (time() - $start_time < $timeout) {
      $status = proc_get_status($process);

      // Read any available output.
      $output .= stream_get_contents($pipes[1]);
      $error_output .= stream_get_contents($pipes[2]);

      // Check if process has exited.
      if (!$status['running']) {
        // Read remaining output.
        $output .= stream_get_contents($pipes[1]);
        $error_output .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
          'output' => array_filter(explode("\n", $output . $error_output)),
          'return_code' => $status['exitcode'],
          'timeout' => FALSE,
        ];
      }

      // Small sleep to avoid busy-waiting.
      usleep(100000);
    }

    // Timeout occurred - terminate the process.
    proc_terminate($process, 9);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [
      'output' => array_filter(explode("\n", $output . $error_output)),
      'return_code' => -1,
      'timeout' => TRUE,
    ];
  }

}
