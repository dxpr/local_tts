<?php

namespace Drupal\local_tts\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountSwitcherInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\local_tts\TtsPlayerBuilder;

/**
 * Extracts text from entities for TTS generation.
 */
class TtsTextExtractor {

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Whether text extraction is in progress.
   */
  protected bool $extracting = FALSE;

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly RendererInterface $renderer,
    protected readonly ModuleHandlerInterface $moduleHandler,
    protected readonly ?AccountSwitcherInterface $accountSwitcher,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('local_tts');
  }

  /**
   * Whether text extraction is currently rendering an entity.
   */
  public function isExtracting(): bool {
    return $this->extracting;
  }

  /**
   * Extract text from an entity using Drupal's render system.
   */
  public function extractTextFromEntity(EntityInterface $entity, array $fields = []): string {
    $switched = FALSE;
    if ($this->accountSwitcher) {
      $this->accountSwitcher->switchTo(new AnonymousUserSession());
      $switched = TRUE;
    }
    $wasExtracting = $this->extracting;
    $this->extracting = TRUE;

    try {
      $langcode = $entity->language()->getId();
      $entityTypeId = $entity->getEntityTypeId();
      $viewBuilder = $this->entityTypeManager->getViewBuilder($entityTypeId);
      if ($fields) {
        $view = [];
        if ($entity instanceof FieldableEntityInterface) {
          foreach ($fields as $fieldName) {
            if (!is_string($fieldName) || !$entity->hasField($fieldName)
              || in_array($fieldName, TtsPlayerBuilder::EXCLUDED_BASE_FIELDS, TRUE)) {
              continue;
            }
            $items = $entity->get($fieldName);
            if (in_array($items->getFieldDefinition()->getType(), TtsPlayerBuilder::ALLOWED_FIELD_TYPES, TRUE)) {
              $view[$fieldName] = $viewBuilder->viewField($items, ['label' => 'hidden']);
            }
          }
        }
      }
      else {
        $view = $viewBuilder->view($entity, 'default', $langcode);
      }
      $rendered = $this->renderer->renderInIsolation($view);
      $text = $this->htmlToPlainText((string) $rendered);

      $context = [
        'entity' => $entity,
        'langcode' => $langcode,
        'fields' => $fields,
      ];
      $this->moduleHandler->alter('local_tts_text', $text, $context);

      return $text;
    }
    catch (\Exception $e) {
      $this->logger->warning('Render-based text extraction failed: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return '';
    }
    finally {
      $this->extracting = $wasExtracting;
      if ($switched) {
        $this->accountSwitcher->switchBack();
      }
    }
  }

  /**
   * Convert HTML to plain text with sentence breaks.
   */
  public function htmlToPlainText(string $html): string {
    $html = preg_replace('#<(script|style|template)\b[^>]*>.*?</\1\s*>#is', '', $html);
    $blockTags = 'h[1-6]|p|div|section|article|header|footer|nav|aside|main|'
      . 'blockquote|pre|figure|figcaption|details|summary|'
      . 'li|dt|dd|tr|th|td|caption';

    $html = preg_replace('#</(' . $blockTags . ')>#i', '. ', $html);
    $html = preg_replace('#<br\s*/?\s*>#i', '. ', $html);
    $html = preg_replace('#<hr\s*/?\s*>#i', '. ', $html);

    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
    $text = preg_replace('/([.!?])\s*\.(\s)/', '$1$2', $text);
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
  }

  /**
   * Estimate word count from an entity's text fields.
   */
  public function estimateWordCount(FieldableEntityInterface $entity, array $fields = []): int {
    $fields = array_filter($fields);
    $count = 0;

    foreach ($entity->getFieldDefinitions() as $fieldName => $definition) {
      if (in_array($fieldName, TtsPlayerBuilder::EXCLUDED_BASE_FIELDS, TRUE)) {
        continue;
      }
      if (!empty($fields) && !in_array($fieldName, $fields, TRUE)) {
        continue;
      }
      if (!in_array($definition->getType(), TtsPlayerBuilder::ALLOWED_FIELD_TYPES, TRUE)) {
        continue;
      }
      $value = $entity->get($fieldName)->getString();
      if ($value !== '') {
        $count += str_word_count(strip_tags($value));
      }
    }

    return $count;
  }

}
