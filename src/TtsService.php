<?php

namespace Drupal\local_tts;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\local_tts\Exception\TtsServiceUnavailableException;
use Drupal\local_tts\Exception\TtsTimeoutException;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\TypedData\TranslatableInterface;

/**
 * Service for interfacing with Kokoro TTS binary.
 */
class TtsService {

  /**
   * Maximum characters per chunk for long text generation.
   */
  const CHUNK_SIZE = 2000;

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
   * The module path.
   *
   * @var string
   */
  protected $modulePath;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

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
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The account switcher.
   *
   * @var \Drupal\Core\Session\AccountSwitcherInterface|null
   */
  protected $accountSwitcher;

  /**
   * Whether text extraction is currently rendering an entity.
   *
   * Player builders check this flag so the player UI (button labels, voice
   * and speed option lists) is never included in the text that is spoken.
   *
   * @var bool
   */
  protected $extracting = FALSE;

  /**
   * Constructs a TtsService object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Extension\ModuleExtensionList $extension_list_module
   *   The module extension list.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\Session\AccountSwitcherInterface|null $account_switcher
   *   The account switcher.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    ModuleExtensionList $extension_list_module,
    TimeInterface $time,
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    RendererInterface $renderer,
    ModuleHandlerInterface $module_handler,
    ?AccountSwitcherInterface $account_switcher = NULL,
  ) {
    $this->configFactory = $config_factory;
    $this->fileSystem = $file_system;
    $this->logger = $logger_factory->get('local_tts');
    $this->modulePath = $extension_list_module->getPath('local_tts');
    $this->time = $time;
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->renderer = $renderer;
    $this->moduleHandler = $module_handler;
    $this->accountSwitcher = $account_switcher;
  }

  /**
   * Whether the service is currently extracting text from an entity.
   *
   * @return bool
   *   TRUE while extractTextFromEntity() is rendering an entity.
   */
  public function isExtracting(): bool {
    return $this->extracting;
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
   *   - force_refresh: Regenerate while retaining cache metadata
   *   - entity_type: Entity type for tracking (e.g., 'node')
   *   - entity_id: Entity ID for tracking.
   *
   * @return string|null
   *   The file URI of the generated audio, or NULL on failure.
   *
   * @throws \InvalidArgumentException
   *   When validation fails for text length, voice, or speed.
   * @throws \Drupal\local_tts\Exception\TtsTimeoutException
   * @throws \Drupal\local_tts\Exception\TtsServiceUnavailableException
   * @throws \RuntimeException
   * @throws \Exception
   */
  public function generateSpeech($text, array $options = []) {
    $config = $this->configFactory->get('local_tts.settings');

    // Entity context is REQUIRED for persistent cached files.
    $use_cache = $options['use_cache'] ?? $config->get('cache_audio');

    if ($use_cache && (empty($options['entity_type']) || empty($options['entity_id']))) {
      throw new \InvalidArgumentException('generateSpeech() with use_cache=TRUE requires entity_type and entity_id in $options.');
    }

    $entity_type = $options['entity_type'] ?? NULL;
    $entity_id = $options['entity_id'] ?? NULL;
    // Public audio must always be tied to an existing, publicly viewable
    // translation, including when a caller requests a cache hit.
    if ($entity_type !== NULL && $entity_id !== NULL) {
      $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
      if (!$entity) {
        throw new \InvalidArgumentException('Cannot generate audio for a missing entity.');
      }
      if (!empty($options['language']) && $entity instanceof TranslatableInterface) {
        if (!$entity->hasTranslation($options['language'])) {
          throw new \InvalidArgumentException('Cannot generate audio for a missing translation.');
        }
        $entity = $entity->getTranslation($options['language']);
      }
      if (!$entity->access('view', new AnonymousUserSession())) {
        throw new \RuntimeException('Cannot generate audio for private content. Only content viewable by anonymous users can be cached.');
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

    $default_language = $options['language'] ?? 'en';
    $defaults = $config->get('default_voices') ?? [];
    $voice = $options['voice'] ?? $defaults[$default_language] ?? $config->get('default_voice') ?? array_key_first($this->getAvailableVoices($default_language)) ?? 'af_sky';
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
    if (!is_finite($speed) || $speed < 0.5 || $speed > 2.0) {
      $this->logger->error('Invalid speed: @speed (must be between 0.5 and 2.0)', ['@speed' => $speed]);
      throw new \InvalidArgumentException(sprintf('Invalid speed: %s (must be between 0.5 and 2.0)', $speed));
    }

    $espeak_lang = $this->mapLanguageToEspeak($language);
    $options['language'] = $language;

    // Language is part of the cache KEY (separate files per language).
    $cache_key = $this->computeCacheKey($text, $voice, $speed, $language);
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';

    if ($use_cache && empty($options['force_refresh'])) {
      $cached_file = $audio_dir . '/' . $cache_key . '.ogg';
      if (file_exists($cached_file)) {
        $this->saveMetadata($cache_key, $text, $voice, $speed, $options);
        return $cached_file;
      }
    }

    if (!$this->fileSystem->prepareDirectory($audio_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      $this->logger->error('Failed to create audio directory: @dir', ['@dir' => $audio_dir]);
      throw new TtsServiceUnavailableException(sprintf('Failed to create audio directory: %s', $audio_dir));
    }
    $directory = $this->fileSystem->realpath($audio_dir);
    if (!$directory) {
      throw new TtsServiceUnavailableException(sprintf('Audio directory path could not be resolved: %s', $audio_dir));
    }

    $ogg_output = $directory . '/' . $cache_key . '.ogg';

    // Use bundled binary and data files from module directory.
    $binary_path = DRUPAL_ROOT . '/' . $this->modulePath . '/bin/koko';
    $data_dir = DRUPAL_ROOT . '/' . $this->modulePath . '/data';

    $model_path = $data_dir . '/kokoro-v1.0.onnx';
    $data_path = $data_dir . '/voices-v1.0.bin';

    if (!file_exists($binary_path)) {
      $this->logger->error('Koko binary not found at: @path', ['@path' => $binary_path]);
      throw new TtsServiceUnavailableException(sprintf('TTS binary not found at: %s. Please run: composer install', $binary_path));
    }

    if (!is_executable($binary_path)) {
      $this->logger->error('Koko binary not executable at: @path', ['@path' => $binary_path]);
      throw new TtsServiceUnavailableException(sprintf('TTS binary not executable at: %s. Please run: chmod +x %s', $binary_path, $binary_path));
    }

    // Validate model files exist.
    if (!file_exists($model_path)) {
      $this->logger->error('Model file not found at: @path', ['@path' => $model_path]);
      throw new TtsServiceUnavailableException(sprintf('Model file not found at: %s. Please run: composer install', $model_path));
    }
    if (!file_exists($data_path)) {
      $this->logger->error('Data file not found at: @path', ['@path' => $data_path]);
      throw new TtsServiceUnavailableException(sprintf('Data file not found at: %s. Please run: composer install', $data_path));
    }

    // Security: Check server load before executing binary.
    $max_load = $config->get('max_server_load') ?? 2;
    if ($max_load > 0 && function_exists('sys_getloadavg')) {
      $load = sys_getloadavg();
      if ($load !== FALSE) {
        $current_load = (float) $load[0];
        if ($current_load > $max_load) {
          $this->logger->warning('TTS generation blocked due to high server load: current @current exceeds threshold @max', [
            '@current' => number_format($current_load, 2),
            '@max' => number_format($max_load, 2),
          ]);
          throw new TtsServiceUnavailableException('Server is experiencing high load. Audio generation is temporarily disabled to maintain performance.');
        }
      }
    }

    $timeout = $config->get('generation_timeout') ?? 900;

    // Split long text into chunks for reliable generation.
    if (mb_strlen($text) > self::CHUNK_SIZE) {
      $this->generateChunked($text, $ogg_output, $binary_path, $model_path, $data_path, $voice, $speed, $espeak_lang, $cache_key, $directory, $timeout);
    }
    else {
      $this->generateSingle($text, $ogg_output, $binary_path, $model_path, $data_path, $voice, $speed, $espeak_lang, $directory, $timeout);
    }

    $uri = $audio_dir . '/' . $cache_key . '.ogg';

    // Track entity audio even when reuse is disabled, so deletion and access
    // changes can still remove its public file.
    if ($entity_type !== NULL && $entity_id !== NULL) {
      $this->saveMetadata($cache_key, $text, $voice, $speed, $options);
    }

    return $uri;
  }

  /**
   * Generate audio for a single (short) text and transcode to OGG Opus.
   *
   * @param string $text
   *   The text to synthesise.
   * @param string $ogg_output
   *   Absolute path for the final .ogg file.
   * @param string $binary_path
   *   Path to the koko binary.
   * @param string $model_path
   *   Path to the ONNX model.
   * @param string $data_path
   *   Path to the voice data file.
   * @param string $voice
   *   Voice identifier.
   * @param float $speed
   *   Speech speed.
   * @param string $espeak_lang
   *   Espeak-ng language identifier.
   * @param string $directory
   *   Real path to the audio output directory.
   * @param int $timeout
   *   Generation timeout in seconds.
   */
  protected function generateSingle($text, $ogg_output, $binary_path, $model_path, $data_path, $voice, $speed, $espeak_lang, $directory, $timeout) {
    $wav_file = $directory . '/' . md5($ogg_output) . '_' . bin2hex(random_bytes(8)) . '_single.wav';

    $command = sprintf(
      '%s --lan %s --model %s --data %s --style %s --speed %s text %s --output %s 2>&1',
      escapeshellarg($binary_path),
      escapeshellarg($espeak_lang),
      escapeshellarg($model_path),
      escapeshellarg($data_path),
      escapeshellarg($voice),
      escapeshellarg((string) $speed),
      escapeshellarg($text),
      escapeshellarg($wav_file)
    );

    $this->logger->info('Executing TTS command for single text');

    try {
      $result = $this->execWithTimeout($command, $timeout);
      $this->handleExecResult($result, $wav_file, $timeout);
      $this->transcodeToOpus($wav_file, $ogg_output);
    }
    finally {
      if (file_exists($wav_file)) {
        @unlink($wav_file);
      }
    }
  }

  /**
   * Generate audio for long text by splitting into chunks.
   *
   * Each chunk is generated as a separate WAV, then all chunks are
   * concatenated and transcoded to a single OGG Opus file.
   *
   * @param string $text
   *   The full text to synthesise.
   * @param string $ogg_output
   *   Absolute path for the final .ogg file.
   * @param string $binary_path
   *   Path to the koko binary.
   * @param string $model_path
   *   Path to the ONNX model.
   * @param string $data_path
   *   Path to the voice data file.
   * @param string $voice
   *   Voice identifier.
   * @param float $speed
   *   Speech speed.
   * @param string $espeak_lang
   *   Espeak-ng language identifier.
   * @param string $cache_key
   *   Cache key used for naming temporary chunk files.
   * @param string $directory
   *   Real path to the audio output directory.
   * @param int $timeout
   *   Generation timeout in seconds per chunk.
   */
  protected function generateChunked($text, $ogg_output, $binary_path, $model_path, $data_path, $voice, $speed, $espeak_lang, $cache_key, $directory, $timeout) {
    $cache_key .= '_' . bin2hex(random_bytes(8));
    $chunks = $this->splitIntoChunks($text);
    $chunk_files = [];

    try {
      foreach ($chunks as $index => $chunk_text) {
        $chunk_wav = $directory . '/' . $cache_key . '_chunk' . $index . '.wav';
        $chunk_files[] = $chunk_wav;

        $command = sprintf(
          '%s --lan %s --model %s --data %s --style %s --speed %s text %s --output %s 2>&1',
          escapeshellarg($binary_path),
          escapeshellarg($espeak_lang),
          escapeshellarg($model_path),
          escapeshellarg($data_path),
          escapeshellarg($voice),
          escapeshellarg((string) $speed),
          escapeshellarg($chunk_text),
          escapeshellarg($chunk_wav)
        );

        $this->logger->info('Generating chunk @i of @total', [
          '@i' => $index + 1,
          '@total' => count($chunks),
        ]);

        $result = $this->execWithTimeout($command, $timeout);
        $this->handleExecResult($result, $chunk_wav, $timeout);
      }

      // Concatenate and transcode all chunks in one ffmpeg call.
      $this->concatAndTranscode($chunk_files, $ogg_output, $directory, $cache_key);
    }
    finally {
      // Clean up temporary chunk WAV files.
      foreach ($chunk_files as $file) {
        if (file_exists($file)) {
          @unlink($file);
        }
      }
      // Clean up the concat list file if it exists.
      $list_file = $directory . '/' . $cache_key . '_concat.txt';
      if (file_exists($list_file)) {
        @unlink($list_file);
      }
    }
  }

  /**
   * Handle the result of a koko TTS execution.
   *
   * @param array $result
   *   Result array from execWithTimeout.
   * @param string $output_file
   *   Expected WAV output file path.
   * @param int $timeout
   *   Timeout value used, for error messages.
   */
  protected function handleExecResult(array $result, $output_file, $timeout) {
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
  }

  /**
   * Concatenate multiple WAV files and transcode to OGG Opus.
   *
   * Uses ffmpeg's concat demuxer to join WAV chunks, then encodes
   * the result as OGG Opus in a single pass.
   *
   * @param array $wav_files
   *   Array of WAV file paths to concatenate.
   * @param string $ogg_output
   *   Destination path for the OGG Opus file.
   * @param string $directory
   *   Working directory for the concat list file.
   * @param string $cache_key
   *   Cache key for naming the temporary list file.
   */
  protected function concatAndTranscode(array $wav_files, $ogg_output, $directory, $cache_key) {
    $list_file = $directory . '/' . $cache_key . '_concat.txt';
    $lines = [];
    foreach ($wav_files as $path) {
      $escaped = str_replace("'", "'\\''", $path);
      $lines[] = "file '" . $escaped . "'";
    }
    file_put_contents($list_file, implode("\n", $lines));

    $partial_output = $ogg_output . '.' . bin2hex(random_bytes(8)) . '.part';
    $command = sprintf(
      '%s -y -f concat -safe 0 -i %s -c:a libopus -b:a 48k -f ogg %s 2>&1',
      escapeshellarg($this->findFfmpeg()),
      escapeshellarg($list_file),
      escapeshellarg($partial_output)
    );

    $result = $this->execWithTimeout($command, 60);

    if ($result['return_code'] !== 0) {
      @unlink($partial_output);
      $this->logger->error('ffmpeg concat+transcode failed: @output', [
        '@output' => implode("\n", $result['output']),
      ]);
      throw new \RuntimeException(sprintf('ffmpeg concat and transcode failed: %s', implode(' ', array_slice($result['output'], -3))));
    }

    $this->publishOutput($partial_output, $ogg_output);
  }

  /**
   * Atomically move a finished .part file to its final .ogg path.
   *
   * The ffmpeg process writes output progressively. Using a temporary file and
   * renaming it prevents the status endpoint (and any other file_exists()
   * check) from serving a half-written file.
   *
   * @param string $partial_output
   *   Path of the completed temporary file.
   * @param string $ogg_output
   *   Final destination path.
   *
   * @throws \RuntimeException
   *   When ffmpeg produced no file or the rename fails.
   */
  protected function publishOutput($partial_output, $ogg_output) {
    if (!file_exists($partial_output)) {
      throw new \RuntimeException(sprintf('ffmpeg did not produce output file: %s', $partial_output));
    }
    if (!@rename($partial_output, $ogg_output)) {
      @unlink($partial_output);
      throw new \RuntimeException(sprintf('Could not move audio file into place: %s', $ogg_output));
    }
  }

  /**
   * Transcode a WAV file to OGG Opus and remove the source WAV.
   *
   * @param string $wav_path
   *   Path to the source WAV file.
   * @param string $ogg_path
   *   Path for the output OGG Opus file.
   *
   * @throws \RuntimeException
   *   When ffmpeg fails or the output file is not created.
   */
  protected function transcodeToOpus($wav_path, $ogg_path) {
    $partial_output = $ogg_path . '.' . bin2hex(random_bytes(8)) . '.part';
    $command = sprintf(
      '%s -y -i %s -c:a libopus -b:a 48k -f ogg %s 2>&1',
      escapeshellarg($this->findFfmpeg()),
      escapeshellarg($wav_path),
      escapeshellarg($partial_output)
    );

    $result = $this->execWithTimeout($command, 60);

    // Remove the intermediate WAV file regardless of the outcome.
    @unlink($wav_path);

    if ($result['return_code'] !== 0) {
      @unlink($partial_output);
      $this->logger->error('ffmpeg transcode failed: @output', [
        '@output' => implode("\n", $result['output']),
      ]);
      throw new \RuntimeException(sprintf('ffmpeg transcode to OGG Opus failed: %s', implode(' ', array_slice($result['output'], -3))));
    }

    $this->publishOutput($partial_output, $ogg_path);
  }

  /**
   * Resolve the absolute path to the ffmpeg binary.
   */
  protected function findFfmpeg(): string {
    $candidates = [
      '/usr/bin/ffmpeg',
      '/usr/local/bin/ffmpeg',
      '/opt/homebrew/bin/ffmpeg',
    ];
    foreach ($candidates as $path) {
      if (is_executable($path)) {
        return $path;
      }
    }
    $output = [];
    $code = 0;
    @exec('which ffmpeg 2>/dev/null', $output, $code);
    if ($code === 0 && !empty($output[0]) && is_executable($output[0])) {
      return $output[0];
    }
    throw new TtsServiceUnavailableException('ffmpeg not found. Install ffmpeg for audio transcoding.');
  }

  /**
   * Split text into chunks at sentence boundaries.
   *
   * @param string $text
   *   The text to split.
   * @param int $max_length
   *   Maximum characters per chunk.
   *
   * @return array
   *   Array of text chunks, each at most $max_length characters
   *   (unless a single sentence exceeds the limit).
   */
  public function splitIntoChunks($text, $max_length = self::CHUNK_SIZE) {
    // Split at sentence boundaries: . ! ? followed by whitespace.
    $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $chunks = [];
    $current_chunk = '';

    foreach ($sentences as $sentence) {
      $candidate = $current_chunk === '' ? $sentence : $current_chunk . ' ' . $sentence;
      if (mb_strlen($candidate) <= $max_length) {
        $current_chunk = $candidate;
      }
      else {
        if ($current_chunk !== '') {
          $chunks[] = $current_chunk;
        }
        // Sentence itself exceeds limit; include it as its own chunk.
        $current_chunk = $sentence;
      }
    }

    if ($current_chunk !== '') {
      $chunks[] = $current_chunk;
    }

    return $chunks;
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
   *   Espeak-ng language identifier.
   *
   * @see https://github.com/espeak-ng/espeak-ng/blob/master/docs/languages.md
   */
  public function mapLanguageToEspeak($langcode) {
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

    return $map[$langcode] ?? $map[explode('-', $langcode)[0]] ?? 'en-us';
  }

  /**
   * Compute a TTS cache key.
   *
   * @param string $text
   *   The source text.
   * @param string $voice
   *   The voice code.
   * @param float $speed
   *   The speech speed.
   * @param string $language
   *   The Drupal language code.
   *
   * @return string
   *   The MD5 cache key.
   */
  public function computeCacheKey(string $text, string $voice, float $speed, string $language): string {
    $espeak_lang = $this->mapLanguageToEspeak($language);
    return md5($text . $voice . $speed . $espeak_lang);
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

    // Treat "und" (undefined) as English (most common case).
    if ($langcode === 'und' || $langcode === 'zxx') {
      $langcode = 'en';
    }

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
    $config = $this->configFactory->get('local_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';

    try {
      if (!$this->fileSystem->deleteRecursive($audio_dir)) {
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
  public function saveMetadata($cache_key, $text, $voice, $speed, array $options = []) {
    $config = $this->configFactory->get('local_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';
    $directory = $this->fileSystem->realpath($audio_dir);

    if (!$directory) {
      throw new \RuntimeException(sprintf('Audio directory could not be resolved: %s', $audio_dir));
    }

    $file_path = $directory . '/' . $cache_key . '.ogg';
    $file_size = file_exists($file_path) ? filesize($file_path) : 0;
    $now = $this->time->getRequestTime();

    $record = [
      'cache_key' => $cache_key,
      'text_hash' => md5($text),
      'voice' => $voice,
      'speed' => $speed,
      'language' => $options['language'] ?? 'en',
      'file_size' => $file_size,
      'created' => $now,
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

    // Keep every variant tracked so invalidation removes all old audio.
    $merge_keys = [];
    if (isset($record['entity_type'], $record['entity_id'])) {
      $merge_keys = [
        'entity_type' => $record['entity_type'],
        'entity_id' => $record['entity_id'],
        'language' => $record['language'],
        'cache_key' => $cache_key,
      ];
    }
    else {
      $merge_keys = ['cache_key' => $cache_key];
    }

    $this->database->merge('local_tts_cache')
      ->keys($merge_keys)
      ->fields($record)
      ->execute();
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
    $result = $this->database->select('local_tts_cache', 'a')
      ->fields('a')
      ->condition('cache_key', $cache_key)
      ->execute()
      ->fetchAssoc();

    if ($result) {
      $this->database->update('local_tts_cache')
        ->fields(['accessed' => $this->time->getRequestTime()])
        ->condition('cache_key', $cache_key)
        ->execute();
    }

    return $result ?: NULL;
  }

  /**
   * Delete all audio files for a specific entity (all languages).
   *
   * When an entity is updated or deleted, we delete ALL cached audio files
   * for that entity across all languages, since the language is part of the
   * cache key and creates separate files.
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
    return $this->deleteAudioMatching([
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
    ]);
  }

  /**
   * Delete audio for a single entity translation.
   */
  public function deleteAudioForEntityTranslation($entity_type, $entity_id, $langcode) {
    return $this->deleteAudioMatching([
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'language' => $langcode,
    ]);
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

    $audio_dir = $this->configFactory->get('local_tts.settings')->get('audio_directory') ?: 'public://local-tts';
    $deleted = 0;
    foreach (array_unique($keys) as $key) {
      $remaining = $this->database->select('local_tts_cache', 'c')
        ->condition('cache_key', $key)->countQuery()->execute()->fetchField();
      if (!$remaining) {
        foreach (['.ogg', '.wav'] as $extension) {
          $uri = $audio_dir . '/' . $key . $extension;
          if (file_exists($uri) && $this->fileSystem->delete($uri)) {
            $deleted++;
          }
        }
      }
    }
    return $deleted;
  }

  /**
   * Extract text from an entity using Drupal's render system.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to extract text from.
   * @param array $fields
   *   Optional text field names to render. Empty means the full entity display.
   *
   * @return string
   *   The extracted and sanitised plain text content.
   */
  public function extractTextFromEntity($entity, array $fields = []) {
    // Generated audio is public, so render exactly what an anonymous visitor
    // would see: never bake editor-only output into the spoken text.
    $switched = FALSE;
    if ($this->accountSwitcher) {
      $this->accountSwitcher->switchTo(new AnonymousUserSession());
      $switched = TRUE;
    }
    $was_extracting = $this->extracting;
    $this->extracting = TRUE;

    try {
      $langcode = $entity->language()->getId();
      $entity_type_id = $entity->getEntityTypeId();
      $view_builder = $this->entityTypeManager->getViewBuilder($entity_type_id);
      if ($fields) {
        $view = [];
        if ($entity instanceof FieldableEntityInterface) {
          foreach ($fields as $field_name) {
            if (!is_string($field_name) || !$entity->hasField($field_name)
              || in_array($field_name, TtsPlayerBuilder::EXCLUDED_BASE_FIELDS, TRUE)) {
              continue;
            }
            $items = $entity->get($field_name);
            if (in_array($items->getFieldDefinition()->getType(), TtsPlayerBuilder::ALLOWED_FIELD_TYPES, TRUE)) {
              // viewField applies field access and text format filtering.
              $view[$field_name] = $view_builder->viewField($items, ['label' => 'hidden']);
            }
          }
        }
      }
      else {
        $view = $view_builder->view($entity, 'default', $langcode);
      }
      $rendered = $this->renderer->renderInIsolation($view);
      $text = $this->htmlToPlainText((string) $rendered);

      $context = [
        'entity' => $entity,
        'langcode' => $langcode,
        'fields' => $fields,
      ];
      $this->moduleHandler->alter('local_tts_text', $text, $context);

      return $text;
    }
    catch (\Exception $e) {
      $this->logger->warning('Render-based text extraction failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return '';
    }
    finally {
      $this->extracting = $was_extracting;
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
    }
  }

  /**
   * Convert HTML to plain text with sentence breaks.
   *
   * @param string $html
   *   The HTML string to convert.
   *
   * @return string
   *   Clean plain text with natural sentence breaks.
   */
  public function htmlToPlainText(string $html): string {
    // These elements contain source code or inert markup, not spoken content.
    $html = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $block_tags = 'h[1-6]|p|div|section|article|header|footer|nav|aside|main|'
      . 'blockquote|pre|figure|figcaption|details|summary|'
      . 'li|dt|dd|tr|th|td|caption';

    $html = preg_replace('#</(' . $block_tags . ')>#i', '. ', $html);
    $html = preg_replace('#<br\s*/?\s*>#i', '. ', $html);
    $html = preg_replace('#<hr\s*/?\s*>#i', '. ', $html);

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    // Collapse doubled punctuation from injected full stops.
    $text = preg_replace('/([.!?])\s*\.(\s)/', '$1$2', $text);
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
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
  public static function expandPath($path) {
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

    // Set up environment variables.
    $env = NULL;
    $config = $this->configFactory->get('local_tts.settings');
    $espeak_data_path = $config->get('espeak_data_path');

    if ($espeak_data_path) {
      // Get parent directory that contains espeak-ng-data.
      $espeak_parent = dirname($espeak_data_path);
      // proc_open requires string env vars.
      $server_env = array_filter($_SERVER, 'is_string');
      $env = array_merge($server_env, [
        'ESPEAK_DATA_PATH' => $espeak_parent,
        'PIPER_ESPEAKNG_DATA_DIRECTORY' => $espeak_parent,
      ]);
    }

    $process = proc_open($command, $descriptors, $pipes, NULL, $env);

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

    // Timeout: terminate the process.
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
