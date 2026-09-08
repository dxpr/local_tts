<?php

namespace Drupal\local_tts\Controller;

use Drupal\local_tts\Exception\TtsServiceUnavailableException;
use Drupal\local_tts\Exception\TtsTimeoutException;
use Drupal\local_tts\TtsService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for voice preview on the settings page.
 */
final class VoicePreviewController extends ControllerBase {

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
   * Constructs a VoicePreviewController object.
   *
   * @param \Drupal\local_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   */
  public function __construct(
    TtsService $tts_service,
    FileUrlGeneratorInterface $file_url_generator,
  ) {
    $this->ttsService = $tts_service;
    $this->fileUrlGenerator = $file_url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('local_tts.tts_service'),
      $container->get('file_url_generator')
    );
  }

  /**
   * Generate a voice preview audio sample.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   JSON response with audio URL or error.
   */
  public function preview(Request $request) {
    $voice = $request->query->get('voice');
    $speed = $request->query->get('speed', '1');

    if (empty($voice)) {
      return new JsonResponse([
        'error' => 'Bad Request',
        'message' => 'Voice parameter is required.',
      ], 400);
    }

    $language = $this->ttsService->detectLanguageFromVoice($voice);
    $text = $this->getSampleText($language);

    try {
      $audio_uri = $this->ttsService->generateSpeech($text, [
        'voice' => $voice,
        'speed' => (float) $speed,
        'language' => $language,
        'use_cache' => FALSE,
      ]);
    }
    catch (\InvalidArgumentException $e) {
      return new JsonResponse([
        'error' => 'Bad Request',
        'message' => $e->getMessage(),
      ], 400);
    }
    catch (TtsTimeoutException $e) {
      return new JsonResponse([
        'error' => 'Request Timeout',
        'message' => 'Preview generation timed out.',
      ], 408);
    }
    catch (TtsServiceUnavailableException $e) {
      return new JsonResponse([
        'error' => 'Service Unavailable',
        'message' => $e->getMessage(),
      ], 503);
    }
    catch (\Exception $e) {
      $this->getLogger('local_tts')->error('Voice preview error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return new JsonResponse([
        'error' => 'Internal Server Error',
        'message' => 'Failed to generate preview.',
      ], 500);
    }

    if (!$audio_uri) {
      return new JsonResponse([
        'error' => 'Service Unavailable',
        'message' => 'Failed to generate preview audio.',
      ], 503);
    }

    $audio_url = $this->fileUrlGenerator->generateAbsoluteString(
      $audio_uri
    );

    return new JsonResponse([
      'success' => TRUE,
      'audio_url' => $audio_url,
    ]);
  }

  /**
   * Get a sample sentence for the given language.
   *
   * @param string $language
   *   The language code.
   *
   * @return string
   *   A sample sentence in the given language.
   */
  protected function getSampleText(string $language): string {
    $samples = [
      'en' => 'This is a preview of the selected voice. You can adjust the speed and choose the voice that sounds best for your content.',
      'en-gb' => 'This is a preview of the selected voice. You can adjust the speed and choose the voice that sounds best for your content.',
      'ja' => "\xe3\x81\x93\xe3\x82\x8c\xe3\x81\xaf\xe9\x81\xb8\xe6\x8a\x9e\xe3\x81\x97\xe3\x81\x9f\xe9\x9f\xb3\xe5\xa3\xb0\xe3\x81\xae\xe3\x83\x97\xe3\x83\xac\xe3\x83\x93\xe3\x83\xa5\xe3\x83\xbc\xe3\x81\xa7\xe3\x81\x99\xe3\x80\x82",
      'zh' => "\xe8\xbf\x99\xe6\x98\xaf\xe6\x89\x80\xe9\x80\x89\xe8\xaf\xad\xe9\x9f\xb3\xe7\x9a\x84\xe9\xa2\x84\xe8\xa7\x88\xe3\x80\x82",
      'fr' => "Ceci est un aper\xc3\xa7u de la voix s\xc3\xa9lectionn\xc3\xa9e.",
      'hi' => "\xe0\xa4\xaf\xe0\xa4\xb9 \xe0\xa4\x9a\xe0\xa4\xaf\xe0\xa4\xa8\xe0\xa4\xbf\xe0\xa4\xa4 \xe0\xa4\x86\xe0\xa4\xb5\xe0\xa4\xbe\xe0\xa4\x9c\xe0\xa4\xbc \xe0\xa4\x95\xe0\xa4\xbe \xe0\xa4\xaa\xe0\xa5\x82\xe0\xa4\xb0\xe0\xa5\x8d\xe0\xa4\xb5\xe0\xa4\xbe\xe0\xa4\xb5\xe0\xa4\xb2\xe0\xa5\x8b\xe0\xa4\x95\xe0\xa4\xa8 \xe0\xa4\xb9\xe0\xa5\x88\xe0\xa5\xa4",
      'es' => 'Esta es una vista previa de la voz seleccionada.',
      'it' => "Questa \xc3\xa8 un'anteprima della voce selezionata.",
      'pt' => "Esta \xc3\xa9 uma pr\xc3\xa9-visualiza\xc3\xa7\xc3\xa3o da voz selecionada.",
    ];

    return $samples[$language] ?? $samples['en'];
  }

}
