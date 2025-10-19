<?php

namespace Drupal\ai_tts\Exception;

/**
 * Exception thrown when TTS service is unavailable.
 *
 * This includes binary not found, not executable, or file system issues.
 */
class TtsServiceUnavailableException extends \RuntimeException {}
