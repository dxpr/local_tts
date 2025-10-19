<?php

namespace Drupal\ai_tts\Controller;

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
class TtsController extends ControllerBase {

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

      // Provide user-friendly message from exception if available.
      $user_message = $e->getMessage();

      // If it's a generic message, provide more helpful default.
      if (strpos($user_message, 'TTS binary not found') !== FALSE ||
          strpos($user_message, 'not executable') !== FALSE ||
          strpos($user_message, 'Model file not found') !== FALSE ||
          strpos($user_message, 'Data file not found') !== FALSE ||
          strpos($user_message, 'Failed to create audio directory') !== FALSE) {
        $user_message = 'TTS service is not properly configured. Please contact the administrator.';
      }

      return new JsonResponse([
        'error' => 'Service Unavailable',
        'message' => $user_message,
      ], 503);
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

}
