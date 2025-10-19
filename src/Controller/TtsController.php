<?php

namespace Drupal\ai_tts\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\ai_tts\TtsService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
   * Constructs a TtsController object.
   *
   * @param \Drupal\ai_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(TtsService $tts_service, FileUrlGeneratorInterface $file_url_generator) {
    $this->ttsService = $tts_service;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_tts.tts_service'),
      $container->get('file_url_generator')
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

    if (empty($text)) {
      return new JsonResponse(['error' => 'No text provided'], 400);
    }

    $options = [];
    if ($voice) {
      $options['voice'] = $voice;
    }
    if ($speed) {
      $options['speed'] = (float) $speed;
    }

    $audio_uri = $this->ttsService->generateSpeech($text, $options);

    if (!$audio_uri) {
      return new JsonResponse(['error' => 'Failed to generate speech'], 500);
    }

    $audio_url = $this->fileUrlGenerator->generateAbsoluteString($audio_uri);

    return new JsonResponse([
      'success' => TRUE,
      'audio_url' => $audio_url,
      'text' => $text,
    ]);
  }

}
