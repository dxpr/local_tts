<?php

namespace Drupal\local_tts\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Url;
use Drupal\local_tts\TtsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for TTS generation and status endpoints.
 */
final class TtsController extends ControllerBase {

  /**
   * Rate limit time window in seconds (1 hour).
   */
  const RATE_LIMIT_WINDOW = 3600;

  /**
   * The TTS service.
   *
   * @var \Drupal\local_tts\TtsService
   */
  protected $ttsService;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The state service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * The time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected $time;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The renderer service.
   *
   * @var \Drupal\Core\Render\RendererInterface
   */
  protected $renderer;

  /**
   * Constructs a TtsController object.
   *
   * @param \Drupal\local_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\State\StateInterface $state
   *   The state service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   */
  public function __construct(
    TtsService $tts_service,
    FileUrlGeneratorInterface $file_url_generator,
    AccountProxyInterface $current_user,
    StateInterface $state,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    QueueFactory $queue_factory,
    RendererInterface $renderer,
  ) {
    $this->ttsService = $tts_service;
    $this->fileUrlGenerator = $file_url_generator;
    $this->currentUser = $current_user;
    $this->state = $state;
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->queueFactory = $queue_factory;
    $this->renderer = $renderer;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('local_tts.tts_service'),
      $container->get('file_url_generator'),
      $container->get('current_user'),
      $container->get('state'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('queue'),
      $container->get('renderer')
    );
  }

  /**
   * Generate speech from an entity's rendered text content.
   *
   * Returns cached audio immediately when the content hash or file cache
   * matches. Otherwise queues the generation job and returns a poll URL
   * so the client can check back for completion.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with file URL, processing status, or error details.
   */
  public function generate(Request $request) {
    $voice = $request->request->get('voice') ?: $request->query->get('voice');
    $speed = $request->request->get('speed') ?: $request->query->get('speed');
    $entity_type = $request->request->get('entity_type') ?: $request->query->get('entity_type');
    $entity_id = $request->request->get('entity_id') ?: $request->query->get('entity_id');
    $language_from_request = $request->request->get('language') ?: $request->query->get('language');

    // SECURITY: Never accept text from client.
    if (empty($entity_type) || empty($entity_id)) {
      return new JsonResponse([
        'error' => 'Bad Request',
        'message' => 'Entity reference required',
      ], 400);
    }

    // Load and validate entity server-side (SECURITY).
    try {
      $storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $storage->load($entity_id);

      // Try exact translation match, then fall back to base language.
      if ($entity && $language_from_request && $entity instanceof TranslatableInterface) {
        if ($entity->hasTranslation($language_from_request)) {
          $entity = $entity->getTranslation($language_from_request);
        }
        elseif (strpos($language_from_request, '-') !== FALSE) {
          $base_lang = explode('-', $language_from_request)[0];
          if ($entity->hasTranslation($base_lang)) {
            $entity = $entity->getTranslation($base_lang);
          }
        }
      }

      if (!$entity) {
        return new JsonResponse([
          'error' => 'Not Found',
          'message' => 'Entity not found',
        ], 404);
      }

      if (!$entity->access('view', $this->currentUser())) {
        return new JsonResponse([
          'error' => 'Forbidden',
          'message' => 'Access denied',
        ], 403);
      }

      // Render-based text extraction captures computed fields.
      $text = $this->extractTextFromEntity($entity);

      if (empty(trim($text))) {
        return new JsonResponse([
          'error' => 'Bad Request',
          'message' => 'No text content found in entity',
        ], 400);
      }

      $language = $entity->language()->getId();
    }
    catch (\Exception $e) {
      $this->getLogger('local_tts')->error('Error loading entity: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Internal Server Error',
        'message' => 'Failed to load entity',
      ], 500);
    }

    // Content change detection: serve cached audio when text is unchanged.
    $config = $this->config('local_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';
    $text_hash = md5($text);

    $existing = $this->database->select('local_tts_cache', 'c')
      ->fields('c', ['cache_key', 'text_hash'])
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->condition('language', $language)
      ->execute()
      ->fetchAssoc();

    if ($existing && $existing['text_hash'] === $text_hash) {
      $cached_uri = $this->findAudioFile($audio_dir, $existing['cache_key']);
      if ($cached_uri) {
        $audio_url = $this->fileUrlGenerator->generateAbsoluteString($cached_uri);
        return new JsonResponse([
          'success' => TRUE,
          'audio_url' => $audio_url,
          'text' => mb_substr($text, 0, 100) . (mb_strlen($text) > 100 ? '...' : ''),
        ]);
      }
    }

    // HTTP 429: Rate limiting.
    if (!$this->checkRateLimit()) {
      $retry_after = $this->getRetryAfter();
      $minutes = max(1, (int) ceil($retry_after / 60));
      return new JsonResponse([
        'error' => 'Too Many Requests',
        'error_code' => 'rate_limit',
        'message' => 'You have made too many requests. Please try again in ' . $minutes . ' minutes.',
        'retry_after' => $retry_after,
      ], 429, ['Retry-After' => $retry_after]);
    }

    // Compute cache key using the same formula as TtsService.
    $resolved_voice = $voice ?: $config->get('default_voice');
    $resolved_speed = $speed ? (float) $speed : (float) ($config->get('default_speed') ?: 1);
    $cache_key = $this->computeCacheKey($text, $resolved_voice, $resolved_speed, $language);

    // Return immediately when the file already exists on disk.
    $cached_uri = $this->findAudioFile($audio_dir, $cache_key);
    if ($cached_uri) {
      $audio_url = $this->fileUrlGenerator->generateAbsoluteString($cached_uri);
      return new JsonResponse([
        'success' => TRUE,
        'audio_url' => $audio_url,
        'text' => mb_substr($text, 0, 100) . (mb_strlen($text) > 100 ? '...' : ''),
      ]);
    }

    // Queue for background generation and return a poll URL.
    $queue = $this->queueFactory->get('local_tts_generate');
    $queue->createItem([
      'text' => $text,
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
      'voice' => $resolved_voice,
      'speed' => $resolved_speed,
      'language' => $language,
      'cache_key' => $cache_key,
    ]);

    $status_url = Url::fromRoute('local_tts.status', [
      'cache_key' => $cache_key,
    ])->toString();

    return new JsonResponse([
      'status' => 'processing',
      'poll_url' => $status_url,
      'cache_key' => $cache_key,
    ]);
  }

  /**
   * Check whether a queued audio file is ready.
   *
   * @param string $cache_key
   *   The MD5 cache key identifying the audio file.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON with status "ready" and audio_url, or status "processing".
   */
  public function status(string $cache_key): JsonResponse {
    $config = $this->config('local_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';

    $audio_uri = $this->findAudioFile($audio_dir, $cache_key);
    if ($audio_uri) {
      $audio_url = $this->fileUrlGenerator->generateAbsoluteString($audio_uri);
      return new JsonResponse([
        'status' => 'ready',
        'audio_url' => $audio_url,
      ]);
    }

    return new JsonResponse([
      'status' => 'processing',
    ]);
  }

  /**
   * Find an audio file on disk, checking both .ogg and .wav extensions.
   *
   * @param string $audio_dir
   *   The audio directory URI (e.g. public://local-tts).
   * @param string $cache_key
   *   The MD5 cache key (filename without extension).
   *
   * @return string|null
   *   The file URI if found, or NULL.
   */
  protected function findAudioFile(string $audio_dir, string $cache_key): ?string {
    foreach (['.ogg', '.wav'] as $ext) {
      $uri = $audio_dir . '/' . $cache_key . $ext;
      if (file_exists($uri)) {
        return $uri;
      }
    }
    return NULL;
  }

  /**
   * Compute a TTS cache key using the same formula as TtsService.
   *
   * @param string $text
   *   The source text.
   * @param string $voice
   *   The resolved voice code.
   * @param float $speed
   *   The resolved speech speed.
   * @param string $language
   *   The Drupal language code.
   *
   * @return string
   *   The MD5 cache key.
   */
  protected function computeCacheKey(string $text, string $voice, float $speed, string $language): string {
    $espeak_lang = $this->mapLanguageToEspeak($language);
    return md5($text . $voice . $speed . $espeak_lang);
  }

  /**
   * Map a Drupal language code to an espeak-ng identifier.
   *
   * Replicates the protected TtsService::mapLanguageToEspeak() so the
   * controller can compute matching cache keys.
   *
   * @param string $langcode
   *   Drupal language code (e.g. 'en', 'es', 'ja').
   *
   * @return string
   *   The espeak-ng language identifier.
   */
  protected function mapLanguageToEspeak(string $langcode): string {
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
    return $map[$langcode] ?? 'en-us';
  }

  /**
   * Get the state key for rate limiting the current user.
   *
   * @return string
   *   The state key for tracking rate limits.
   */
  protected function getRateLimitStateKey() {
    return 'local_tts.rate_limit.' . $this->currentUser->id();
  }

  /**
   * Check if current user is within rate limits.
   *
   * @return bool
   *   TRUE if within limits, FALSE if exceeded.
   */
  protected function checkRateLimit() {
    $config = $this->config('local_tts.settings');

    if (!$config->get('rate_limit_enabled')) {
      return TRUE;
    }

    $threshold = (int) ($config->get('rate_limit_threshold') ?? 20);

    $state_key = $this->getRateLimitStateKey();

    $attempts = $this->state->get($state_key, []);

    // Clean old attempts outside the time window.
    $current_time = $this->time->getRequestTime();
    $attempts = array_filter($attempts, function ($timestamp) use ($current_time) {
      return ($current_time - $timestamp) < self::RATE_LIMIT_WINDOW;
    });

    if (count($attempts) >= $threshold) {
      return FALSE;
    }

    // Record this attempt.
    $attempts[] = $current_time;
    $this->state->set($state_key, $attempts);

    return TRUE;
  }

  /**
   * Get the number of seconds until rate limit resets.
   *
   * @return int
   *   Seconds until the user can make another request.
   */
  protected function getRetryAfter() {
    $state_key = $this->getRateLimitStateKey();

    $attempts = $this->state->get($state_key, []);

    if (empty($attempts)) {
      return 0;
    }

    $oldest_attempt = min($attempts);
    $current_time = $this->time->getRequestTime();

    $retry_after = ($oldest_attempt + self::RATE_LIMIT_WINDOW) - $current_time;

    return max(0, $retry_after);
  }

  /**
   * Extract text from an entity using Drupal's render system.
   *
   * Renders the entity in default view mode and strips HTML, capturing
   * computed fields, Views fields, and block field content that the
   * previous field-by-field approach missed.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to extract text from.
   *
   * @return string
   *   The extracted and sanitized plain text content.
   */
  protected function extractTextFromEntity($entity) {
    try {
      $langcode = $entity->language()->getId();
      $entity_type_id = $entity->getEntityTypeId();
      $view_builder = $this->entityTypeManager->getViewBuilder($entity_type_id);
      $view = $view_builder->view($entity, 'default', $langcode);
      $rendered = $this->renderer->renderPlain($view);
      return $this->htmlToPlainText((string) $rendered);
    }
    catch (\Exception $e) {
      $this->getLogger('local_tts')->warning('Render-based text extraction failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return '';
    }
  }

  /**
   * Convert HTML to plain text with sentence breaks at block boundaries.
   *
   * @param string $html
   *   The HTML string to convert.
   *
   * @return string
   *   Clean plain text with natural sentence breaks.
   */
  protected function htmlToPlainText(string $html): string {
    $block_tags = 'h[1-6]|p|div|section|article|header|footer|nav|aside|main|'
      . 'blockquote|pre|figure|figcaption|details|summary|'
      . 'li|dt|dd|tr|th|td|caption';

    // Insert sentence breaks after closing block-level tags.
    $html = preg_replace('#</(' . $block_tags . ')>#i', '. ', $html);
    $html = preg_replace('#<br\s*/?\s*>#i', '. ', $html);
    $html = preg_replace('#<hr\s*/?\s*>#i', '. ', $html);

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    // Collapse doubled punctuation from injected full stops.
    $text = preg_replace('/([.!?])\s*\.(\s)/', '$1$2', $text);
    // Normalise whitespace.
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
  }

}
