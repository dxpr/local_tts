<?php

namespace Drupal\ai_tts\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'ai_tts_player_widget' widget.
 *
 * This is a hidden widget that always enables the TTS player.
 * The field value is always 1 (enabled) and cannot be changed by editors.
 */
#[FieldWidget(
  id: 'ai_tts_player_widget',
  label: new TranslatableMarkup('TTS Player (Hidden)'),
  field_types: ['ai_tts_player'],
  multiple_values: TRUE,
)]
class AiTtsPlayerWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    // Hidden widget - always returns value of 1 (enabled).
    // The field is always active, no UI needed.
    $element['value'] = [
      '#type' => 'value',
      '#value' => 1,
    ];

    return $element;
  }

}
