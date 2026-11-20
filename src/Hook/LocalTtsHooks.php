<?php

namespace Drupal\local_tts\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\Core\Entity\Display\EntityViewDisplayInterface;
use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\local_tts\TtsPlayerBuilder;

/**
 * Hook implementations for local_tts.
 */
final class LocalTtsHooks {

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      case 'help.page.local_tts':
        $output = '';
        $output .= '<h3>' . t('About') . '</h3>';
        $output .= '<p>' . t('The Local Text-to-Speech module adds audio players to your content that read text aloud. It uses the Kokoro text-to-speech engine to create high-quality, natural-sounding speech in multiple languages, entirely on your own server.') . '</p>';
        $output .= '<h3>' . t('Requirements') . '</h3>';
        $output .= '<p>' . t('You need to install the Kokoro binary (koko) on your server. <a href="@url">Download and installation instructions</a>', [
          '@url' => 'https://github.com/lucasjinreal/Kokoros',
        ]) . '</p>';
        $output .= '<h3>' . t('Configuration') . '</h3>';
        $output .= '<p>' . t('Configure the module at Administration &gt; Configuration &gt; Media &gt; Local Text-to-Speech.') . '</p>';
        return $output;
    }
  }

  /**
   * Implements hook_entity_extra_field_info().
   */
  #[Hook('entity_extra_field_info')]
  public function entityExtraFieldInfo() {
    $extra = [];

    $entity_types = \Drupal::entityTypeManager()->getDefinitions();
    $bundle_info = \Drupal::service('entity_type.bundle.info');

    foreach ($entity_types as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements(FieldableEntityInterface::class)) {
        continue;
      }
      if (!$entity_type->hasViewBuilderClass()) {
        continue;
      }

      foreach (array_keys($bundle_info->getBundleInfo($entity_type_id)) as $bundle) {
        $extra[$entity_type_id][$bundle]['display']['local_tts_player'] = [
          'label' => t('TTS Player'),
          'description' => t('Audio player that reads this content aloud.'),
          'weight' => 100,
          'visible' => FALSE,
        ];
      }
    }

    return $extra;
  }

  /**
   * Implements hook_entity_view().
   */
  #[Hook('entity_view')]
  public function entityView(array &$build, EntityInterface $entity, EntityViewDisplayInterface $display, $view_mode) {
    if (!$display->getComponent('local_tts_player')) {
      return;
    }
    if (!($entity instanceof FieldableEntityInterface)) {
      return;
    }

    $build['local_tts_player'] = [
      '#create_placeholder' => TRUE,
      '#lazy_builder' => [
        'local_tts.player_builder:buildPlayerLazy',
        [
          $entity->getEntityTypeId(),
          (string) $entity->id(),
          json_encode([
            'show_voice_selector' => TRUE,
            'show_speed_control' => TRUE,
            'show_volume_control' => TRUE,
            'fields' => [],
            'wrapper_classes' => '',
          ]),
          $entity->language()->getId(),
        ],
      ],
    ];
  }

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron() {
    \Drupal::service('local_tts.cron_service')->run();
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity) {
    $original = method_exists($entity, 'getOriginal') ? $entity->getOriginal() : ($entity->original ?? NULL);
    $tts_service = \Drupal::service('local_tts.tts_service');
    $translations = $entity instanceof
    TranslatableInterface ? $entity->getTranslationLanguages() : [$entity->language()->getId() => $entity->language()];
    foreach ($translations as $langcode => $language) {
      $translation = $entity instanceof
      TranslatableInterface ? $entity->getTranslation($langcode) : $entity;
      $previous = $original;
      if ($original instanceof TranslatableInterface) {
        $previous = $original->hasTranslation($langcode) ? $original->getTranslation($langcode) : NULL;
      }
      if ($translation->access('view', new AnonymousUserSession()) && $previous
        && _local_tts_text_content_changed($translation, $previous) === FALSE
        && $previous->access('view', new AnonymousUserSession())) {
        continue;
      }
      $tts_service->deleteAudioForEntityTranslation($entity->getEntityTypeId(), $entity->id(), $langcode);
      _local_tts_auto_generate($translation);
    }
  }

  /**
   * Implements hook_entity_translation_delete().
   */
  #[Hook('entity_translation_delete')]
  public function entityTranslationDelete(EntityInterface $translation) {
    \Drupal::service('local_tts.tts_service')->deleteAudioForEntityTranslation(
      $translation->getEntityTypeId(), $translation->id(), $translation->language()->getId()
    );
  }

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity) {
    _local_tts_auto_generate($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity) {
    \Drupal::service('local_tts.tts_service')->deleteAudioForEntity(
      $entity->getEntityTypeId(),
      $entity->id()
    );
  }

  /**
   * Implements hook_form_node_form_alter().
   */
  #[Hook('form_node_form_alter')]
  public function formNodeFormAlter(&$form, FormStateInterface $form_state, $form_id) {
    $has_tts_field = FALSE;
    $tts_field_key = NULL;

    foreach ($form as $key => $element) {
      if (!is_array($element)) {
        continue;
      }

      if (isset($element['#type']) && $element['#type'] === 'container') {
        if (isset($element['widget']['#field_name'])) {
          $field_name = $element['widget']['#field_name'];
          $form_object = $form_state->getFormObject();
          if (!($form_object instanceof EntityFormInterface)) {
            continue;
          }
          $field_definitions = \Drupal::service('entity_field.manager')
            ->getFieldDefinitions('node', $form_object->getEntity()->bundle());

          if (isset($field_definitions[$field_name]) && $field_definitions[$field_name]->getType() === 'local_tts_player') {
            $has_tts_field = TRUE;
            $tts_field_key = $key;
            break;
          }
        }
      }
    }

    if (!$has_tts_field) {
      return;
    }

    $form['local_tts_options'] = [
      '#type' => 'details',
      '#title' => t('Local Text-to-Speech'),
      '#group' => 'advanced',
      '#attributes' => [
        'class' => ['local-tts-form-options'],
      ],
      '#weight' => 96,
      '#optional' => TRUE,
    ];

    if (isset($tts_field_key)) {
      $form[$tts_field_key]['#group'] = 'local_tts_options';
    }
  }

  /**
   * Implements hook_page_attachments_alter().
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments) {
    $theme = \Drupal::theme()->getActiveTheme()->getName();
    $base_themes = array_keys(\Drupal::theme()->getActiveTheme()->getBaseThemeExtensions());

    if ($theme === 'gin' || in_array('gin', $base_themes, TRUE)
        || $theme === 'claro' || in_array('claro', $base_themes, TRUE)) {
      $attachments['#attached']['library'][] = 'local_tts/player-gin';
    }
    elseif ($theme === 'dxpr_theme' || in_array('dxpr_theme', $base_themes, TRUE)) {
      $attachments['#attached']['library'][] = 'local_tts/player-dxpr-theme';
    }
  }

}
