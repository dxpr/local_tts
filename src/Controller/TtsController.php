<?php

namespace Drupal\ai_tts\Controller;

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
class TtsController extends ControllerBase {

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
   */
  public function __construct(
    TtsService $tts_service,
    FileUrlGeneratorInterface $file_url_generator,
    AccountProxyInterface $current_user,
    StateInterface $state,
    TimeInterface $time,
  ) {
    $this->ttsService = $tts_service;
    $this->fileUrlGenerator = $file_url_generator;
    $this->currentUser = $current_user;
    $this->state = $state;
    $this->time = $time;
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
      $container->get('datetime.time')
    );
  }

  /**
   * Generate speech from text.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse|\Symfony\Component\HttpFoundation\BinaryFileResponse
   *   JSON response with file URL or binary audio response.
   *
   *   HTTP Status Codes:
   *   200 - Success
   *   400 - Bad Request (invalid parameters)
   *   408 - Request Timeout (generation timeout)
   *   429 - Too Many Requests (rate limit exceeded)
   *   500 - Internal Server Error (unexpected error)
   *   503 - Service Unavailable (binary not found, file system issues)
   */
  public function generate(Request $request) {
    $text = $request->request->get('text') ?: $request->query->get('text');
    $voice = $request->request->get('voice') ?: $request->query->get('voice');
    $speed = $request->request->get('speed') ?: $request->query->get('speed');
    $language = $request->request->get('language') ?: $request->query->get('language');

    // HTTP 400: Bad Request - Missing required parameters.
    if (empty($text)) {
      return new JsonResponse([
        'error' => 'Bad Request',
        'message' => 'No text provided',
      ], 400);
    }

    // HTTP 429: Too Many Requests - Rate limiting.
    if (!$this->checkRateLimit()) {
      $retry_after = $this->getRetryAfter();
      return new JsonResponse([
        'error' => 'Too Many Requests',
        'message' => 'Rate limit exceeded. Please try again later.',
        'retry_after' => $retry_after,
      ], 429, ['Retry-After' => $retry_after]);
    }

    $options = [];
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
      // HTTP 400: Bad Request - Validation errors.
      return new JsonResponse([
        'error' => 'Bad Request',
        'message' => $e->getMessage(),
      ], 400);
    }
    catch (\RuntimeException $e) {
      // Check for specific error conditions based on message.
      $message = $e->getMessage();

      // HTTP 408: Request Timeout - Generation timeout.
      if (stripos($message, 'timeout') !== FALSE || stripos($message, 'timed out') !== FALSE) {
        $this->getLogger('ai_tts')->warning('TTS generation timeout: @message', [
          '@message' => $message,
        ]);
        return new JsonResponse([
          'error' => 'Request Timeout',
          'message' => 'Audio generation timed out. Try with shorter text.',
        ], 408);
      }

      // HTTP 503: Service Unavailable - Binary or file system issues.
      if (stripos($message, 'binary') !== FALSE ||
          stripos($message, 'not found') !== FALSE ||
          stripos($message, 'not executable') !== FALSE ||
          stripos($message, 'file system') !== FALSE ||
          stripos($message, 'directory') !== FALSE) {
        $this->getLogger('ai_tts')->error('TTS service unavailable: @message', [
          '@message' => $message,
        ]);
        return new JsonResponse([
          'error' => 'Service Unavailable',
          'message' => 'TTS service is temporarily unavailable. Please contact the administrator.',
        ], 503);
      }

      // HTTP 500: Internal Server Error - Other runtime errors.
      $this->getLogger('ai_tts')->error('TTS generation runtime error: @message', [
        '@message' => $message,
      ]);
      return new JsonResponse([
        'error' => 'Internal Server Error',
        'message' => 'An error occurred while generating speech',
      ], 500);
    }
    catch (\Exception $e) {
      // HTTP 500: Internal Server Error - Unexpected errors.
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
    $window = 3600;

    $state_key = 'ai_tts.rate_limit.' . $this->currentUser->id();

    $attempts = $this->state->get($state_key, []);

    // Clean old attempts outside the time window.
    $current_time = $this->time->getRequestTime();
    $attempts = array_filter($attempts, function ($timestamp) use ($current_time, $window) {
      return ($current_time - $timestamp) < $window;
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
    $window = 3600;

    $state_key = 'ai_tts.rate_limit.' . $this->currentUser->id();

    $attempts = $this->state->get($state_key, []);

    if (empty($attempts)) {
      return 0;
    }

    // Get oldest attempt timestamp.
    $oldest_attempt = min($attempts);
    $current_time = $this->time->getRequestTime();

    // Calculate when the oldest attempt will expire.
    $retry_after = ($oldest_attempt + $window) - $current_time;

    return max(0, $retry_after);
  }

}
