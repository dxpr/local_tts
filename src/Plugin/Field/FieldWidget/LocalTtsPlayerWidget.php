<?php

namespace Drupal\local_tts\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Plugin implementation of the 'local_tts_player_widget' widget.
 *
 * Provides a checkbox to enable/disable the text-to-speech player per entity.
 */
#[FieldWidget(
  id: 'local_tts_player_widget',
  label: new TranslatableMarkup('Text-to-speech player toggle'),
  field_types: ['local_tts_player'],
  multiple_values: TRUE,
)]
class LocalTtsPlayerWidget extends WidgetBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'display_label' => FALSE,
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element['display_label'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use field label instead of default label'),
      '#description' => $this->t('By default, shows "Show AI text-to-speech player". Check this to use the field label instead.'),
      '#default_value' => $this->getSetting('display_label'),
      '#weight' => -1,
    ];
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $display_label = $this->getSetting('display_label');
    $summary[] = $this->t('Use field label: @display_label', [
      '@display_label' => ($display_label ? $this->t('Yes') : $this->t('No')),
    ]);

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state) {
    $element['value'] = $element + [
      '#type' => 'checkbox',
      '#default_value' => !empty($items[0]->value),
    ];

    // Override the title from the incoming $element.
    if ($this->getSetting('display_label')) {
      $element['value']['#title'] = $this->fieldDefinition->getLabel();
    }
    else {
      $element['value']['#title'] = $this->t('Show AI text-to-speech player');
    }

    return $element;
  }

}
