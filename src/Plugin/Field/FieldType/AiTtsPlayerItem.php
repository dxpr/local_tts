<?php

namespace Drupal\ai_tts\Plugin\Field\FieldType;

use Drupal\Core\Field\Attribute\FieldType;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemBase;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\DataDefinition;

/**
 * Defines the 'ai_tts_player' field type.
 *
 * A simple boolean field that triggers the TTS player formatter.
 * Stores a single boolean value (1 = enabled, 0 = disabled).
 */
#[FieldType(
  id: "ai_tts_player",
  label: new TranslatableMarkup("AI TTS Player"),
  description: new TranslatableMarkup("Displays a text-to-speech audio player for this content"),
  default_widget: "ai_tts_player_widget",
  default_formatter: "ai_tts_player_formatter",
  cardinality: 1,
)]
class AiTtsPlayerItem extends FieldItemBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultStorageSettings() {
    return [] + parent::defaultStorageSettings();
  }

  /**
   * {@inheritdoc}
   */
  public static function propertyDefinitions(FieldStorageDefinitionInterface $field_definition) {
    $properties['value'] = DataDefinition::create('boolean')
      ->setLabel(t('Enabled'))
      ->setRequired(TRUE);

    return $properties;
  }

  /**
   * {@inheritdoc}
   */
  public static function schema(FieldStorageDefinitionInterface $field_definition) {
    return [
      'columns' => [
        'value' => [
          'type' => 'int',
          'size' => 'tiny',
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function generateSampleValue(FieldDefinitionInterface $field_definition) {
    // Always enabled by default for sample values.
    $values['value'] = 1;
    return $values;
  }

  /**
   * {@inheritdoc}
   */
  public function isEmpty() {
    // Field is never considered empty - always has a value (0 or 1).
    // This ensures it always renders in Layout Builder.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public static function mainPropertyName() {
    return 'value';
  }

}
