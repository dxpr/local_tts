<?php

namespace Drupal\local_tts\Controller;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Session\AccountProxyInterface;
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
   * The flood service.
   *
   * @var \Drupal\Core\Flood\FloodInterface
   */
  protected $flood;

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
   * Constructs a TtsController object.
   *
   * @param \Drupal\local_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Flood\FloodInterface $flood
   *   The flood service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   */
  public function __construct(
    TtsService $tts_service,
    FileUrlGeneratorInterface $file_url_generator,
    AccountProxyInterface $current_user,
    FloodInterface $flood,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
    Connection $database,
    QueueFactory $queue_factory,
  ) {
    $this->ttsService = $tts_service;
    $this->fileUrlGenerator = $file_url_generator;
    $this->currentUser = $current_user;
    $this->flood = $flood;
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
    $this->database = $database;
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('local_tts.tts_service'),
      $container->get('file_url_generator'),
      $container->get('current_user'),
      $container->get('flood'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('database'),
      $container->get('queue')
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
      $text = $this->ttsService->extractTextFromEntity($entity);

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
    $cache_key = $this->ttsService->computeCacheKey($text, $resolved_voice, $resolved_speed, $language);

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

    // Record rate limit event after successful queuing.
    $this->flood->register('local_tts.generate', self::RATE_LIMIT_WINDOW);

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

    return $this->flood->isAllowed(
      'local_tts.generate',
      $threshold,
      self::RATE_LIMIT_WINDOW
    );
  }

  /**
   * Get the number of seconds until rate limit resets.
   *
   * @return int
   *   Seconds until the user can make another request.
   */
  protected function getRetryAfter() {
    return self::RATE_LIMIT_WINDOW;
  }

}
