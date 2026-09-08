<?php

namespace Drupal\local_tts_views\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;

/**
 * Renders a TTS player for the entity in a Views row.
 *
 * @ingroup views_field_handlers
 */
#[ViewsField("local_tts_player")]
class LocalTtsPlayer extends FieldPluginBase {

  /**
   * {@inheritdoc}
   */
  public function usesGroupBy() {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['show_voice_selector'] = ['default' => FALSE];
    $options['show_speed_control'] = ['default' => FALSE];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $form['show_voice_selector'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show voice selector'),
      '#default_value' => $this->options['show_voice_selector'],
    ];

    $form['show_speed_control'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show speed control'),
      '#default_value' => $this->options['show_speed_control'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $entity = $this->getEntity($values);
    if (!$entity) {
      return [];
    }

    return [
      '#create_placeholder' => TRUE,
      '#lazy_builder' => [
        'local_tts.player_builder:buildPlayerLazy',
        [
          $entity->getEntityTypeId(),
          (string) $entity->id(),
          json_encode([
            'show_voice_selector' => (bool) $this->options['show_voice_selector'],
            'show_speed_control' => (bool) $this->options['show_speed_control'],
            'wrapper_classes' => 'local-tts-compact',
            'fields' => [],
          ]),
        ],
      ],
    ];
  }

}
