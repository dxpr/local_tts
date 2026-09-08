<?php

namespace Drupal\local_tts\Plugin\views\field;

use Drupal\Core\Form\FormStateInterface;
use Drupal\local_tts\TtsPlayerBuilder;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a TTS player for the entity in a Views row.
 */
#[ViewsField("local_tts_player")]
class LocalTtsPlayer extends FieldPluginBase {

  /**
   * The TTS player builder.
   */
  protected TtsPlayerBuilder $playerBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->playerBuilder = $container->get('local_tts.player_builder');
    return $instance;
  }

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
    $options['show_voice_selector'] = ['default' => TRUE];
    $options['show_speed_control'] = ['default' => TRUE];
    $options['show_volume_control'] = ['default' => TRUE];
    $options['wrapper_classes'] = ['default' => ''];
    $options['fields'] = ['default' => []];
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
      '#description' => $this->t('Allow users to select from available voices.'),
      '#default_value' => $this->options['show_voice_selector'],
    ];

    $form['show_speed_control'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show speed control'),
      '#description' => $this->t('Allow users to adjust speech speed.'),
      '#default_value' => $this->options['show_speed_control'],
    ];

    $form['show_volume_control'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show volume control'),
      '#description' => $this->t('Show volume slider and mute button during playback.'),
      '#default_value' => $this->options['show_volume_control'],
    ];

    $form['wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Additional CSS classes'),
      '#description' => $this->t('Add custom CSS classes to the player wrapper (space-separated).'),
      '#default_value' => $this->options['wrapper_classes'],
    ];

    $field_options = $this->playerBuilder->getAllTextFieldOptions();

    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Fields to include'),
      '#description' => $this->t('Select the text fields that should be read.'),
      '#options' => $field_options,
      '#default_value' => $this->options['fields'],
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
      return '';
    }

    $classes = trim($this->options['wrapper_classes'] ?? '');

    $build = [
      '#create_placeholder' => TRUE,
      '#lazy_builder' => [
        'local_tts.player_builder:buildPlayerLazy',
        [
          $entity->getEntityTypeId(),
          (string) $entity->id(),
          json_encode([
            'show_voice_selector' => (bool) $this->options['show_voice_selector'],
            'show_speed_control' => (bool) $this->options['show_speed_control'],
            'show_volume_control' => (bool) $this->options['show_volume_control'],
            'wrapper_classes' => $classes,
            'fields' => array_values(array_filter($this->options['fields'] ?? [])),
          ]),
        ],
      ],
    ];

    return $this->getRenderer()->render($build);
  }

}
