<?php

namespace Drupal\ai_tts\Controller;

use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_tts\Exception\TtsServiceUnavailableException;
use Drupal\ai_tts\Exception\TtsTimeoutException;
use Drupal\ai_tts\TtsService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Component\Datetime\TimeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for TTS generation endpoints.
 */
final class TtsController extends ControllerBase {

  /**
   * Rate limit time window in seconds (1 hour).
   */
  const RATE_LIMIT_WINDOW = 3600;

  /**
   * The TTS service.
   *
   * @var \Drupal\ai_tts\TtsService
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
   * Constructs a TtsController object.
   *
   * @param \Drupal\ai_tts\TtsService $tts_service
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
   */
  public function __construct(
    TtsService $tts_service,
    FileUrlGeneratorInterface $file_url_generator,
    AccountProxyInterface $current_user,
    StateInterface $state,
    TimeInterface $time,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->ttsService = $tts_service;
    $this->fileUrlGenerator = $file_url_generator;
    $this->currentUser = $current_user;
    $this->state = $state;
    $this->time = $time;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_tts.tts_service'),
      $container->get('file_url_generator'),
      $container->get('current_user'),
      $container->get('state'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Generate speech from text.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with file URL or error details.
   */
  public function generate(Request $request) {
    $voice = $request->request->get('voice') ?: $request->query->get('voice');
    $speed = $request->request->get('speed') ?: $request->query->get('speed');
    $entity_type = $request->request->get('entity_type') ?: $request->query->get('entity_type');
    $entity_id = $request->request->get('entity_id') ?: $request->query->get('entity_id');
    $language_from_request = $request->request->get('language') ?: $request->query->get('language');
    $fields_json = $request->request->get('fields') ?: $request->query->get('fields');

    // HTTP 400: Bad Request - Entity reference required.
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

      // Load specific translation if language provided.
      // Follow Drupal core pattern: try exact match, then base language.
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

      // Check access control.
      if (!$entity->access('view', $this->currentUser())) {
        return new JsonResponse([
          'error' => 'Forbidden',
          'message' => 'Access denied',
        ], 403);
      }

      // Extract text from entity fields.
      $text = $this->extractTextFromEntity($entity, $fields_json);

      if (empty(trim($text))) {
        return new JsonResponse([
          'error' => 'Bad Request',
          'message' => 'No text content found in entity',
        ], 400);
      }

      // Detect language from entity.
      $language = $entity->language()->getId();
    }
    catch (\Exception $e) {
      $this->getLogger('ai_tts')->error('Error loading entity: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse([
        'error' => 'Internal Server Error',
        'message' => 'Failed to load entity',
      ], 500);
    }

    // HTTP 429: Too Many Requests - Rate limiting.
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

    $options = [
      'entity_type' => $entity_type,
      'entity_id' => $entity_id,
    ];
    if ($voice) {
      $options['voice'] = $voice;
    }
    if ($speed) {
      $options['speed'] = (float) $speed;
    }
    if ($language) {
      $options['language'] = $language;
    }

    // Generate speech with proper error handling.
    try {
      $audio_uri = $this->ttsService->generateSpeech($text, $options);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse([
        'error' => 'Bad Request',
        'message' => $e->getMessage(),
      ], 400);
    }
    catch (TtsTimeoutException $e) {
      $this->getLogger('ai_tts')->warning('TTS generation timeout: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Request Timeout',
        'message' => 'Audio generation timed out. Try with shorter text.',
      ], 408);
    }
    catch (TtsServiceUnavailableException $e) {
      $this->getLogger('ai_tts')->error('TTS service unavailable: @message', [
        '@message' => $e->getMessage(),
      ]);

      $raw = $e->getMessage();
      $response = $this->buildServiceErrorResponse($raw);

      return new JsonResponse($response, 503);
    }
    catch (\RuntimeException $e) {
      // Check if this is an access denied error.
      if (strpos($e->getMessage(), 'private content') !== FALSE) {
        return new JsonResponse([
          'error' => 'Access denied',
          'message' => $e->getMessage(),
        ], 403);
      }

      $this->getLogger('ai_tts')->error('TTS generation runtime error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Internal Server Error',
        'message' => 'An error occurred while generating speech',
      ], 500);
    }
    catch (\Exception $e) {
      $this->getLogger('ai_tts')->error('TTS generation unexpected error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Internal Server Error',
        'message' => 'An unexpected error occurred',
      ], 500);
    }

    // HTTP 503: Service Unavailable - Failed to generate (NULL returned).
    if (!$audio_uri) {
      $this->getLogger('ai_tts')->error('TTS generation returned NULL without exception');
      return new JsonResponse([
        'error' => 'Service Unavailable',
        'message' => 'Failed to generate speech. Please try again later.',
      ], 503);
    }

    // HTTP 200: Success.
    $audio_url = $this->fileUrlGenerator->generateAbsoluteString($audio_uri);

    return new JsonResponse([
      'success' => TRUE,
      'audio_url' => $audio_url,
      'text' => mb_substr($text, 0, 100) . (mb_strlen($text) > 100 ? '...' : ''),
    ], 200);
  }

  /**
   * Get the state key for rate limiting the current user.
   *
   * @return string
   *   The state key for tracking rate limits.
   */
  protected function getRateLimitStateKey() {
    return 'ai_tts.rate_limit.' . $this->currentUser->id();
  }

  /**
   * Check if current user is within rate limits.
   *
   * @return bool
   *   TRUE if within limits, FALSE if exceeded.
   */
  protected function checkRateLimit() {
    $config = $this->config('ai_tts.settings');

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

    // Check if threshold exceeded.
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

    // Get oldest attempt timestamp.
    $oldest_attempt = min($attempts);
    $current_time = $this->time->getRequestTime();

    // Calculate when the oldest attempt will expire.
    $retry_after = ($oldest_attempt + self::RATE_LIMIT_WINDOW) - $current_time;

    return max(0, $retry_after);
  }

  /**
   * Build a structured 503 error response with role-appropriate messages.
   *
   * @param string $raw_message
   *   The raw exception message from TtsService.
   *
   * @return array
   *   Response array with error, error_code, message, and admin_message.
   */
  protected function buildServiceErrorResponse($raw_message) {
    $is_admin = $this->currentUser->hasPermission('administer ai tts settings');

    if (strpos($raw_message, 'Server is experiencing high load') !== FALSE) {
      $config = $this->config('ai_tts.settings');
      $threshold = $config->get('max_server_load') ?? 2;

      return [
        'error' => 'Service Unavailable',
        'error_code' => 'server_load',
        'message' => $is_admin
          ? $raw_message . ' The current threshold is ' . $threshold . '. You can adjust this in the TTS settings. Set it to 0 to disable the load check.'
          : 'Audio is temporarily unavailable due to high server demand. Please try again shortly.',
      ];
    }

    if (strpos($raw_message, 'TTS binary not found') !== FALSE ||
        strpos($raw_message, 'not executable') !== FALSE ||
        strpos($raw_message, 'Model file not found') !== FALSE ||
        strpos($raw_message, 'Data file not found') !== FALSE ||
        strpos($raw_message, 'Failed to create audio directory') !== FALSE) {
      return [
        'error' => 'Service Unavailable',
        'error_code' => 'not_configured',
        'message' => $is_admin
          ? 'TTS configuration error: ' . $raw_message . ' Check the TTS settings page.'
          : 'The audio service is currently unavailable. The site administrator has been notified.',
      ];
    }

    return [
      'error' => 'Service Unavailable',
      'error_code' => 'unknown',
      'message' => $is_admin
        ? 'TTS error: ' . $raw_message
        : 'Audio generation failed. Please try again later.',
    ];
  }

  /**
   * Extract text content from entity fields (server-side only).
   *
   * SECURITY: This method validates and sanitizes all text extraction.
   * Never trust client-provided text.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to extract text from.
   * @param string|null $fields_json
   *   JSON-encoded array of field names to extract (optional).
   *
   * @return string
   *   The extracted and sanitized text content.
   */
  protected function extractTextFromEntity($entity, $fields_json = NULL) {
    $allowed_field_types = [
      'string',
      'string_long',
      'text',
      'text_long',
      'text_with_summary',
      'text_plain',
      'email',
      'telephone',
    ];

    $excluded_base_fields = [
      'nid', 'uuid', 'vid', 'langcode', 'type', 'revision_timestamp',
      'revision_uid', 'revision_log', 'status', 'uid', 'created', 'changed',
      'promote', 'sticky', 'default_langcode', 'revision_default',
      'revision_translation_affected', 'metatag', 'path', 'menu_link',
      'tid', 'weight', 'parent', 'description__format',
    ];

    $selected_fields = [];
    if ($fields_json) {
      $decoded = json_decode($fields_json, TRUE);
      if (is_array($decoded)) {
        $selected_fields = $decoded;
      }
    }

    $content = '';

    if (!($entity instanceof FieldableEntityInterface)) {
      return $content;
    }

    foreach ($entity->getFieldDefinitions() as $field_name => $field_definition) {
      // Skip excluded base fields.
      if (in_array($field_name, $excluded_base_fields, TRUE)) {
        continue;
      }

      // If specific fields were requested, only process those.
      if (!empty($selected_fields) && !in_array($field_name, $selected_fields, TRUE)) {
        continue;
      }

      if ($entity->hasField($field_name)) {
        $field = $entity->get($field_name);

        // Check field-level access.
        if (!$field->access('view', $this->currentUser())) {
          continue;
        }

        if (!$field->isEmpty() && in_array($field_definition->getType(), $allowed_field_types)) {
          foreach ($field as $item) {
            // Get the actual value - handle different item types.
            if (isset($item->value)) {
              $text = $item->value;
            }
            elseif (is_string($item)) {
              $text = $item;
            }
            else {
              continue;
            }

            // SECURITY: Strip all HTML tags and decode entities.
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
            if (!empty(trim($text))) {
              $content .= (strlen($content) > 0 ? ' ' : '') . $text;
            }
          }
        }
      }
    }

    return $content;
  }

}
