<?php

namespace Drupal\local_tts\Plugin\QueueWorker;

use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\local_tts\TtsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queued TTS audio generation jobs in the background.
 */
#[QueueWorker(
  id: 'local_tts_generate',
  title: new TranslatableMarkup('Local TTS Audio Generation'),
  cron: ['time' => 300],
)]
final class TtsGenerationWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The TTS service.
   *
   * @var \Drupal\local_tts\TtsService
   */
  protected TtsService $ttsService;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Constructs a TtsGenerationWorker object.
   *
   * @param array $configuration
   *   Plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\local_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\Logger\LoggerChannelInterface $logger
   *   The logger channel.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TtsService $tts_service,
    LoggerChannelInterface $logger,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->ttsService = $tts_service;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('local_tts.tts_service'),
      $container->get('logger.factory')->get('local_tts')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    if (empty($data['entity_type']) || empty($data['entity_id'])) {
      $this->logger->warning('Queue item missing required fields; skipping.');
      return;
    }

    // Reload the source even when the job contains text: it may have been
    // edited, unpublished, translated or deleted while waiting for cron.
    $text = $this->extractTextForQueueItem($data);
    if (empty($text)) {
      return;
    }
    if (isset($data['text']) && $data['text'] !== '' && $data['text'] !== $text) {
      $this->logger->info('Dropping stale TTS job for @type:@id.', [
        '@type' => $data['entity_type'],
        '@id' => $data['entity_id'],
      ]);
      return;
    }

    $options = [
      'entity_type' => $data['entity_type'],
      'entity_id' => $data['entity_id'],
      'voice' => $data['voice'] ?? NULL,
      'speed' => $data['speed'] ?? NULL,
      'language' => $data['language'] ?? 'en',
    ];

    try {
      $this->ttsService->generateSpeech($text, $options);
    }
    catch (\InvalidArgumentException $e) {
      // Invalid voice, speed or text length will never succeed on retry, so
      // log it and let the queue drop the item instead of retrying forever.
      $this->logger->warning('Dropping queued TTS job for @type:@id: @msg', [
        '@type' => $data['entity_type'],
        '@id' => $data['entity_id'],
        '@msg' => $e->getMessage(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Queue TTS generation failed for @type:@id: @msg', [
        '@type' => $data['entity_type'],
        '@id' => $data['entity_id'],
        '@msg' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Load and extract text from an entity for a queue item.
   *
   * @param array $data
   *   Queue item data with entity_type and entity_id.
   *
   * @return string
   *   Extracted text, or empty string on failure.
   */
  protected function extractTextForQueueItem(array $data): string {
    try {
      $entity = \Drupal::entityTypeManager()
        ->getStorage($data['entity_type'])
        ->load($data['entity_id']);

      if (!$entity) {
        return '';
      }

      $langcode = $data['language'] ?? NULL;
      if ($langcode && $entity instanceof TranslatableInterface) {
        if (!$entity->hasTranslation($langcode)) {
          return '';
        }
        $entity = $entity->getTranslation($langcode);
      }
      if (!$entity->access('view', new AnonymousUserSession())) {
        return '';
      }

      return $this->ttsService->extractTextFromEntity($entity, $data['fields'] ?? []);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to extract text for @type:@id: @msg', [
        '@type' => $data['entity_type'],
        '@id' => $data['entity_id'],
        '@msg' => $e->getMessage(),
      ]);
      return '';
    }
  }

}
