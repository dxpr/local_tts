<?php

namespace Drupal\local_tts;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\local_tts\Exception\TtsServiceUnavailableException;
use Drupal\local_tts\Service\TtsCacheManager;
use Drupal\local_tts\Service\TtsGenerator;
use Drupal\local_tts\Service\TtsTextExtractor;

/**
 * Facade for Kokoro TTS: voice management, health checks, generation.
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
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Path to the local_tts module.
   */
  protected string $modulePath;

  public function __construct(
    protected readonly TtsGenerator $generator,
    protected readonly TtsCacheManager $cacheManager,
    protected readonly TtsTextExtractor $textExtractor,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
    ModuleExtensionList $extensionListModule,
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->logger = $loggerFactory->get('local_tts');
    $this->modulePath = $extensionListModule->getPath('local_tts');
  }

  /**
   * Whether the service is currently extracting text from an entity.
   */
  public function isExtracting(): bool {
    return $this->textExtractor->isExtracting();
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
   */
  public function generateSpeech($text, array $options = []) {
    $config = $this->configFactory->get('local_tts.settings');

    $useCache = $options['use_cache'] ?? $config->get('cache_audio');

    if ($useCache && (empty($options['entity_type']) || empty($options['entity_id']))) {
      throw new \InvalidArgumentException('generateSpeech() with use_cache=TRUE requires entity_type and entity_id in $options.');
    }

    $entityType = $options['entity_type'] ?? NULL;
    $entityId = $options['entity_id'] ?? NULL;

    if ($entityType !== NULL && $entityId !== NULL) {
      $entity = $this->entityTypeManager->getStorage($entityType)->load($entityId);
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

    $maxLength = $config->get('max_text_length') ?? 1000000;
    if ($maxLength > 0 && mb_strlen($text) > $maxLength) {
      $this->logger->warning('Text length (@length chars) exceeds maximum (@max chars)', [
        '@length' => mb_strlen($text),
        '@max' => $maxLength,
      ]);
      throw new \InvalidArgumentException(sprintf('Text length (%d characters) exceeds maximum allowed (%d characters)', mb_strlen($text), $maxLength));
    }

    $defaultLanguage = $options['language'] ?? 'en';
    $voice = $options['voice'] ?? $this->getDefaultVoice($defaultLanguage);
    $speed = $options['speed'] ?? $config->get('default_speed');
    $language = $options['language'] ?? $this->detectLanguageFromVoice($voice);

    $availableVoices = array_keys($this->getAvailableVoices());
    if (!in_array($voice, $availableVoices, TRUE)) {
      $this->logger->error('Invalid voice: @voice', ['@voice' => $voice]);
      throw new \InvalidArgumentException(sprintf('Invalid voice: %s', $voice));
    }

    $numericSpeed = is_numeric($speed);
    $speed = (float) $speed;
    if (!$numericSpeed || !is_finite($speed) || $speed < 0.5 || $speed > 2.0) {
      $this->logger->error('Invalid speed: @speed (must be between 0.5 and 2.0)', ['@speed' => $speed]);
      throw new \InvalidArgumentException(sprintf('Invalid speed: %s (must be between 0.5 and 2.0)', $speed));
    }

    $espeakLang = $this->mapLanguageToEspeak($language);
    $options['language'] = $language;

    $cacheKey = $this->computeCacheKey($text, $voice, $speed, $language);
    $audioDir = $config->get('audio_directory') ?: 'public://local-tts';

    if ($useCache && empty($options['force_refresh'])) {
      $cachedFile = $audioDir . '/' . $cacheKey . '.ogg';
      if (file_exists($cachedFile)) {
        $this->cacheManager->saveMetadata($cacheKey, $text, $voice, $speed, $options);
        return $cachedFile;
      }
    }

    $timeout = $config->get('generation_timeout') ?? 900;

    $this->generator->generate($text, $cacheKey, $voice, $speed, $espeakLang, $timeout);

    $uri = $audioDir . '/' . $cacheKey . '.ogg';

    if ($entityType !== NULL && $entityId !== NULL) {
      $this->cacheManager->saveMetadata($cacheKey, $text, $voice, $speed, $options);
    }

    return $uri;
  }

  /**
   * Detect language code from voice name.
   */
  public function detectLanguageFromVoice($voice) {
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
   */
  public function mapLanguageToEspeak($langcode) {
    $langcode = strtolower($langcode);

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
   */
  public function computeCacheKey(string $text, string $voice, float $speed, string $language): string {
    $espeakLang = $this->mapLanguageToEspeak($language);
    return md5($text . $voice . $speed . $espeakLang);
  }

  /**
   * Get available voices from Kokoro.
   */
  public function getAvailableVoices($langcode = NULL) {
    $allVoices = [
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
      'am_adam' => 'Adam (American English Male)',
      'am_echo' => 'Echo (American English Male)',
      'am_eric' => 'Eric (American English Male)',
      'am_fenrir' => 'Fenrir (American English Male)',
      'am_liam' => 'Liam (American English Male)',
      'am_michael' => 'Michael (American English Male)',
      'am_onyx' => 'Onyx (American English Male)',
      'am_puck' => 'Puck (American English Male)',
      'am_santa' => 'Santa (American English Male)',
      'bf_alice' => 'Alice (British English Female)',
      'bf_emma' => 'Emma (British English Female)',
      'bf_isabella' => 'Isabella (British English Female)',
      'bf_lily' => 'Lily (British English Female)',
      'bm_daniel' => 'Daniel (British English Male)',
      'bm_fable' => 'Fable (British English Male)',
      'bm_george' => 'George (British English Male)',
      'bm_lewis' => 'Lewis (British English Male)',
      'jf_alpha' => 'Alpha (Japanese Female)',
      'jf_gongitsune' => 'Gongitsune (Japanese Female)',
      'jf_nezumi' => 'Nezumi (Japanese Female)',
      'jf_tebukuro' => 'Tebukuro (Japanese Female)',
      'jm_kumo' => 'Kumo (Japanese Male)',
      'zf_xiaobei' => 'Xiaobei (Mandarin Chinese Female)',
      'zf_xiaoni' => 'Xiaoni (Mandarin Chinese Female)',
      'zf_xiaoxiao' => 'Xiaoxiao (Mandarin Chinese Female)',
      'zf_xiaoyi' => 'Xiaoyi (Mandarin Chinese Female)',
      'zm_yunjian' => 'Yunjian (Mandarin Chinese Male)',
      'zm_yunxia' => 'Yunxia (Mandarin Chinese Male)',
      'zm_yunxi' => 'Yunxi (Mandarin Chinese Male)',
      'zm_yunyang' => 'Yunyang (Mandarin Chinese Male)',
      'ff_siwis' => 'Siwis (French Female)',
      'hf_alpha' => 'Alpha (Hindi Female)',
      'hf_beta' => 'Beta (Hindi Female)',
      'hm_omega' => 'Omega (Hindi Male)',
      'hm_psi' => 'Psi (Hindi Male)',
      'ef_dora' => 'Dora (Spanish Female)',
      'em_alex' => 'Alex (Spanish Male)',
      'em_santa' => 'Santa (Spanish Male)',
      'if_sara' => 'Sara (Italian Female)',
      'im_nicola' => 'Nicola (Italian Male)',
      'pf_dora' => 'Dora (Portuguese Female)',
      'pm_alex' => 'Alex (Portuguese Male)',
      'pm_santa' => 'Santa (Portuguese Male)',
    ];

    if ($langcode !== NULL) {
      return $this->filterVoicesByLanguage($allVoices, $langcode);
    }

    return $allVoices;
  }

  /**
   * Get list of supported language codes.
   */
  public function getSupportedLanguages() {
    return array_keys(self::LANGUAGE_MAP);
  }

  /**
   * Normalize speed value to valid select option.
   */
  public function normalizeSpeed($speed) {
    $speed = (string) $speed;
    $availableSpeeds = ['0.8', '1', '1.2', '1.5', '2'];
    if (in_array($speed, $availableSpeeds, TRUE)) {
      return $speed;
    }
    return '1';
  }

  /**
   * Get an available default voice for a content language.
   */
  public function getDefaultVoice(string $language): string {
    $config = $this->configFactory->get('local_tts.settings');
    $defaults = $config->get('default_voices') ?? [];
    $available = $this->getAvailableVoices($language);
    if (isset($defaults[$language], $available[$defaults[$language]])) {
      return $defaults[$language];
    }
    return (string) (array_key_first($available) ?? ($config->get('default_voice') ?: 'af_sky'));
  }

  /**
   * Check whether the TTS binary is available.
   */
  public function isBinaryAvailable(): bool {
    static $available = NULL;
    if ($available !== NULL) {
      return $available;
    }
    $binaryPath = $this->generator->getBinaryPath();
    $available = file_exists($binaryPath) && is_executable($binaryPath);
    return $available;
  }

  /**
   * Expand path with tilde (~) to full path.
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
   * Run system health checks for TTS dependencies.
   */
  public function checkHealth(): array {
    $config = $this->configFactory->get('local_tts.settings');
    $health = [];

    $binaryPath = $this->generator->getBinaryPath();
    if (!file_exists($binaryPath)) {
      $health['binary'] = [
        'label' => 'Kokoro binary',
        'status' => 'error',
        'message' => 'Not found at ' . $binaryPath,
        'path' => $binaryPath,
      ];
    }
    elseif (!is_executable($binaryPath)) {
      $health['binary'] = [
        'label' => 'Kokoro binary',
        'status' => 'error',
        'message' => 'Found but not executable',
        'path' => $binaryPath,
      ];
    }
    else {
      $health['binary'] = [
        'label' => 'Kokoro binary',
        'status' => 'ok',
        'message' => 'Found and executable',
        'path' => $binaryPath,
      ];
    }

    try {
      $ffmpegPath = $this->generator->findFfmpeg();
      $health['ffmpeg'] = [
        'status' => 'ok',
        'message' => 'Found at ' . $ffmpegPath,
        'path' => $ffmpegPath,
      ];
    }
    catch (TtsServiceUnavailableException $e) {
      $health['ffmpeg'] = [
        'status' => 'error',
        'message' => 'Not found; install ffmpeg for audio transcoding',
        'path' => '',
      ];
    }

    $espeakPath = $config->get('espeak_data_path');
    if (!$espeakPath) {
      $health['espeak'] = [
        'label' => 'espeak',
        'status' => 'ok',
        'message' => 'Using bundled espeak (no external path configured)',
        'path' => '',
      ];
    }
    elseif (!is_dir($espeakPath)) {
      $health['espeak'] = [
        'label' => 'espeak',
        'status' => 'warning',
        'message' => 'Configured path not found; falling back to bundled espeak',
        'path' => $espeakPath,
      ];
    }
    else {
      $health['espeak'] = [
        'label' => 'espeak',
        'status' => 'ok',
        'message' => 'Using external path',
        'path' => $espeakPath,
      ];
    }

    $audioDir = $config->get('audio_directory') ?: 'public://local-tts';
    $realPath = $this->fileSystem->realpath($audioDir);
    if (!$realPath) {
      $health['audio_dir'] = [
        'status' => 'warning',
        'message' => 'Does not exist yet (created on first generation)',
        'path' => $audioDir,
        'writable' => FALSE,
      ];
    }
    elseif (!is_writable($realPath)) {
      $health['audio_dir'] = [
        'status' => 'error',
        'message' => 'Not writable at ' . $audioDir,
        'path' => $audioDir,
        'writable' => FALSE,
      ];
    }
    else {
      $health['audio_dir'] = [
        'status' => 'ok',
        'message' => 'Writable at ' . $audioDir,
        'path' => $audioDir,
        'writable' => TRUE,
      ];
    }

    $maxSize = $config->get('cache_max_size') ?: 1073741824;
    $usage = $this->cacheManager->getDiskUsage();
    $totalSize = $usage['total_size'];
    $fileCount = $usage['file_count'];

    $percent = $maxSize > 0 ? (int) round(($totalSize / $maxSize) * 100) : 0;
    $diskStatus = $percent >= 90 ? 'warning' : 'ok';
    $health['disk_usage'] = [
      'label' => 'Disk usage',
      'status' => $diskStatus,
      'message' => $percent . '% of cache limit used',
      'path' => '',
      'total_size' => $totalSize,
      'file_count' => $fileCount,
      'max_size' => (int) $maxSize,
      'percent' => $percent,
    ];

    return $health;
  }

  /**
   * Save metadata for an audio file.
   */
  public function saveMetadata($cacheKey, $text, $voice, $speed, array $options = []) {
    $this->cacheManager->saveMetadata($cacheKey, $text, $voice, $speed, $options);
  }

  /**
   * Load metadata for an audio file.
   */
  public function loadMetadata($cacheKey) {
    return $this->cacheManager->loadMetadata($cacheKey);
  }

  /**
   * Delete all audio files for a specific entity (all languages).
   */
  public function deleteAudioForEntity($entityType, $entityId) {
    return $this->cacheManager->deleteAudioForEntity($entityType, $entityId);
  }

  /**
   * Delete audio for a single entity translation.
   */
  public function deleteAudioForEntityTranslation($entityType, $entityId, $langcode) {
    return $this->cacheManager->deleteAudioForEntityTranslation($entityType, $entityId, $langcode);
  }

  /**
   * Clear the audio cache.
   */
  public function clearCache() {
    return $this->cacheManager->clearCache();
  }

  /**
   * Extract text from an entity using Drupal's render system.
   */
  public function extractTextFromEntity($entity, array $fields = []) {
    return $this->textExtractor->extractTextFromEntity($entity, $fields);
  }

  /**
   * Convert HTML to plain text with sentence breaks.
   */
  public function htmlToPlainText(string $html): string {
    return $this->textExtractor->htmlToPlainText($html);
  }

  /**
   * Estimate word count from an entity's text fields.
   */
  public function estimateWordCount(FieldableEntityInterface $entity, array $fields = []): int {
    return $this->textExtractor->estimateWordCount($entity, $fields);
  }

  /**
   * Split text into chunks at sentence boundaries.
   */
  public function splitIntoChunks($text, $maxLength = TtsGenerator::CHUNK_SIZE) {
    return $this->generator->splitIntoChunks($text, $maxLength);
  }

  /**
   * Filter voices by language code.
   */
  protected function filterVoicesByLanguage(array $voices, $langcode) {
    $langcode = strtolower($langcode);

    if ($langcode === 'und' || $langcode === 'zxx') {
      $langcode = 'en';
    }

    $prefixes = self::LANGUAGE_MAP[$langcode] ?? [];

    if (empty($prefixes) && strpos($langcode, '-') !== FALSE) {
      $baseLang = explode('-', $langcode)[0];
      $prefixes = self::LANGUAGE_MAP[$baseLang] ?? [];
    }

    if (empty($prefixes)) {
      return [];
    }

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

}
