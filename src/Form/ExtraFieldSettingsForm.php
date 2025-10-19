<?php

namespace Drupal\ai_tts\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure AI TTS Extra Field settings.
 *
 * Allows administrators to enable the TTS player extra field on specific
 * entity type/bundle combinations and select which fields to aggregate.
 */
class ExtraFieldSettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs an ExtraFieldSettingsForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityFieldManagerInterface $entity_field_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_tts.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_tts_extra_field_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_tts.settings');
    $enabled_bundles = $config->get('extra_field_enabled_bundles') ?? [];

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Configure which entity types and bundles should display the AI TTS Player as an extra field. Once enabled, you can position the player in the "Manage Display" settings for each bundle.') . '</p>',
    ];

    // Get content entity types.
    $entity_types = $this->getContentEntityTypes();

    $form['bundles'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Entity Types'),
    ];

    foreach ($entity_types as $entity_type_id => $entity_type_label) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);

      if (empty($bundles)) {
        continue;
      }

      $form['entity_type_' . $entity_type_id] = [
        '#type' => 'details',
        '#title' => $entity_type_label,
        '#group' => 'bundles',
      ];

      foreach ($bundles as $bundle_id => $bundle_info) {
        $bundle_key = $entity_type_id . '__' . $bundle_id;

        // Check if this bundle is currently enabled.
        $is_enabled = FALSE;
        $selected_fields = [];
        foreach ($enabled_bundles as $bundle_config) {
          if ($bundle_config['entity_type'] === $entity_type_id &&
              $bundle_config['bundle'] === $bundle_id) {
            $is_enabled = TRUE;
            $selected_fields = $bundle_config['fields'] ?? [];
            break;
          }
        }

        $form['entity_type_' . $entity_type_id][$bundle_key] = [
          '#type' => 'details',
          '#title' => $bundle_info['label'],
          '#open' => $is_enabled,
        ];

        $form['entity_type_' . $entity_type_id][$bundle_key]['enabled'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Enable TTS Player extra field'),
          '#default_value' => $is_enabled,
        ];

        // Get text fields for this bundle.
        $text_fields = $this->getTextFields($entity_type_id, $bundle_id);

        if (!empty($text_fields)) {
          $form['entity_type_' . $entity_type_id][$bundle_key]['fields'] = [
            '#type' => 'checkboxes',
            '#title' => $this->t('Text fields to include in audio'),
            '#options' => $text_fields,
            '#default_value' => $selected_fields,
            '#description' => $this->t('Select which text fields should be aggregated into the audio player. If none are selected, all text fields will be included.'),
            '#states' => [
              'visible' => [
                ':input[name="' . $bundle_key . '[enabled]"]' => ['checked' => TRUE],
              ],
            ],
          ];
        }
        else {
          $form['entity_type_' . $entity_type_id][$bundle_key]['no_fields'] = [
            '#markup' => '<p><em>' . $this->t('No text fields available on this bundle.') . '</em></p>',
            '#states' => [
              'visible' => [
                ':input[name="' . $bundle_key . '[enabled]"]' => ['checked' => TRUE],
              ],
            ],
          ];
        }
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $enabled_bundles = [];

    // Get all entity types.
    $entity_types = $this->getContentEntityTypes();

    foreach ($entity_types as $entity_type_id => $entity_type_label) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entity_type_id);

      foreach ($bundles as $bundle_id => $bundle_info) {
        $bundle_key = $entity_type_id . '__' . $bundle_id;
        $values = $form_state->getValue($bundle_key);

        if (!empty($values['enabled'])) {
          $fields = [];
          if (!empty($values['fields'])) {
            // Filter out unchecked fields.
            $fields = array_values(array_filter($values['fields']));
          }

          $enabled_bundles[] = [
            'entity_type' => $entity_type_id,
            'bundle' => $bundle_id,
            'fields' => $fields,
          ];
        }
      }
    }

    $this->config('ai_tts.settings')
      ->set('extra_field_enabled_bundles', $enabled_bundles)
      ->save();

    // Clear entity extra field cache.
    $this->entityFieldManager->clearCachedFieldDefinitions();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get content entity types suitable for TTS.
   *
   * @return array
   *   Associative array of entity type IDs to labels.
   */
  protected function getContentEntityTypes() {
    $entity_types = [];

    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      // Only include fieldable content entity types.
      if ($entity_type->getBundleEntityType() &&
          $entity_type->hasViewBuilderClass() &&
          $entity_type->getGroup() === 'content') {
        $entity_types[$entity_type_id] = $entity_type->getLabel();
      }
    }

    return $entity_types;
  }

  /**
   * Get text fields for a given entity type and bundle.
   *
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The bundle ID.
   *
   * @return array
   *   Associative array of field names to labels.
   */
  protected function getTextFields($entity_type_id, $bundle) {
    $text_fields = [];

    $allowed_field_types = [
      'string',
      'string_long',
      'text',
      'text_long',
      'text_with_summary',
    ];

    $excluded_base_fields = [
      'nid', 'uuid', 'vid', 'langcode', 'type', 'revision_timestamp',
      'revision_uid', 'revision_log', 'status', 'uid', 'created', 'changed',
      'promote', 'sticky', 'default_langcode', 'revision_default',
      'revision_translation_affected', 'metatag', 'path', 'menu_link',
      'tid', 'weight', 'parent', 'description__format',
    ];

    $field_definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);

    foreach ($field_definitions as $field_name => $field_definition) {
      // Skip excluded base fields.
      if (in_array($field_name, $excluded_base_fields, TRUE)) {
        continue;
      }

      if (in_array($field_definition->getType(), $allowed_field_types, TRUE)) {
        $text_fields[$field_name] = $field_definition->getLabel();
      }
    }

    return $text_fields;
  }

}
