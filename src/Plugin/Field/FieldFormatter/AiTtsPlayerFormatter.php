<?php

namespace Drupal\ai_tts\Plugin\Field\FieldFormatter;

use Drupal\ai_tts\TtsPlayerBuilder;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'ai_tts_player_formatter' formatter.
 */
#[FieldFormatter(
  id: 'ai_tts_player_formatter',
  label: new TranslatableMarkup('TTS Player'),
  description: new TranslatableMarkup('Displays an audio player for text-to-speech.'),
  field_types: [
    'ai_tts_player',
  ],
)]
class AiTtsPlayerFormatter extends FormatterBase implements ContainerFactoryPluginInterface {

  /**
   * Allowed field types for TTS processing.
   */
  const ALLOWED_FIELD_TYPES = [
    'string',
    'string_long',
    'text',
    'text_long',
    'text_with_summary',
    'text_plain',
    'email',
    'telephone',
  ];

  /**
   * Base fields to exclude from TTS.
   */
  const EXCLUDED_BASE_FIELDS = [
    'nid', 'uuid', 'vid', 'langcode', 'type', 'revision_timestamp',
    'revision_uid', 'revision_log', 'status', 'uid', 'created', 'changed',
    'promote', 'sticky', 'default_langcode', 'revision_default',
    'revision_translation_affected', 'metatag', 'path', 'menu_link',
    'tid', 'weight', 'parent', 'description__format',
  ];

  /**
   * The TTS player builder service.
   *
   * @var \Drupal\ai_tts\TtsPlayerBuilder
   */
  protected $playerBuilder;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs an AiTtsPlayerFormatter object.
   *
   * @param string $plugin_id
   *   The plugin_id for the formatter.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The definition of the field to which the formatter is associated.
   * @param array $settings
   *   The formatter settings.
   * @param string $label
   *   The formatter label display setting.
   * @param string $view_mode
   *   The view mode.
   * @param array $third_party_settings
   *   Any third party settings.
   * @param \Drupal\ai_tts\TtsPlayerBuilder $player_builder
   *   The TTS player builder service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, TtsPlayerBuilder $player_builder, EntityFieldManagerInterface $entity_field_manager) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
    $this->playerBuilder = $player_builder;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'] ?? [],
      $configuration['label'] ?? 'hidden',
      $configuration['view_mode'] ?? 'default',
      $configuration['third_party_settings'] ?? [],
      $container->get('ai_tts.player_builder'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'show_voice_selector' => TRUE,
      'show_speed_control' => TRUE,
      'fields' => [],
      'wrapper_classes' => '',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $elements = parent::settingsForm($form, $form_state);

    $elements['show_voice_selector'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Voice Selection Dropdown'),
      '#default_value' => $this->getSetting('show_voice_selector'),
      '#description' => $this->t('Allow users to select from available voices.'),
    ];

    $elements['show_speed_control'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Speed Control'),
      '#default_value' => $this->getSetting('show_speed_control'),
      '#description' => $this->t('Allow users to adjust speech speed.'),
    ];

    $elements['wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Additional CSS Classes'),
      '#default_value' => $this->getSetting('wrapper_classes'),
      '#description' => $this->t('Add custom CSS classes to the player wrapper (space-separated).'),
    ];

    // Get text fields for the current entity type/bundle.
    $field_options = $this->getTextFieldOptions();

    if (!empty($field_options)) {
      $elements['fields'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Fields to include'),
        '#description' => $this->t('Select the text fields that should be read. Leave empty to include all text fields.'),
        '#options' => $field_options,
        '#default_value' => $this->getSetting('fields'),
      ];
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = [];

    $summary[] = $this->getSetting('show_voice_selector')
      ? $this->t('Voice selector: Shown')
      : $this->t('Voice selector: Hidden');

    $summary[] = $this->getSetting('show_speed_control')
      ? $this->t('Speed control: Shown')
      : $this->t('Speed control: Hidden');

    $wrapper_classes = trim($this->getSetting('wrapper_classes'));
    if (!empty($wrapper_classes)) {
      $summary[] = $this->t('Custom classes: @classes', ['@classes' => $wrapper_classes]);
    }

    $selected_fields = array_filter($this->getSetting('fields'));
    if (empty($selected_fields)) {
      $summary[] = $this->t('Fields: All text fields');
    }
    else {
      $summary[] = $this->t('Fields: @count selected', ['@count' => count($selected_fields)]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];

    // Only render if field value is TRUE (enabled).
    if (!empty($items[0]->value)) {
      $entity = $items->getEntity();

      // Delegate to the player builder service.
      $elements[0] = $this->playerBuilder->buildPlayer($entity, $this->getSettings());
    }

    return $elements;
  }

  /**
   * Get text field options for the current entity type and bundle.
   *
   * @return array
   *   Array of field names to labels.
   */
  protected function getTextFieldOptions() {
    $field_options = [];

    $entity_type = $this->fieldDefinition->getTargetEntityTypeId();
    $bundle = $this->fieldDefinition->getTargetBundle();

    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle);
    foreach ($definitions as $field_name => $definition) {
      // Skip excluded base fields.
      if (in_array($field_name, self::EXCLUDED_BASE_FIELDS, TRUE)) {
        continue;
      }

      $type = $definition->getType();
      if (in_array($type, self::ALLOWED_FIELD_TYPES, TRUE)) {
        $field_options[$field_name] = $definition->getLabel() . ' (' . $field_name . ')';
      }
    }

    ksort($field_options);

    return $field_options;
  }

}
