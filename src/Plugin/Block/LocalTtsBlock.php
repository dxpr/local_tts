<?php

namespace Drupal\local_tts\Plugin\Block;

use Drupal\local_tts\TtsPlayerBuilder;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Provides an Local TTS block.
 *
 * @Block(
 *   id = "local_tts_block",
 *   admin_label = @Translation("Local Text-to-Speech"),
 *   category = @Translation("Media")
 * )
 */
final class LocalTtsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The TTS player builder.
   */
  protected TtsPlayerBuilder $playerBuilder;

  /**
   * The current route match.
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static($configuration, $plugin_id, $plugin_definition);
    $instance->playerBuilder = $container->get('local_tts.player_builder');
    $instance->routeMatch = $container->get('current_route_match');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'show_voice_selector' => TRUE,
      'show_speed_control' => TRUE,
      'show_volume_control' => TRUE,
      'fields' => [],
      'wrapper_classes' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['show_voice_selector'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Voice Selection Dropdown'),
      '#default_value' => $config['show_voice_selector'],
      '#description' => $this->t('Allow users to select from available voices.'),
    ];

    $form['show_speed_control'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Speed Control'),
      '#default_value' => $config['show_speed_control'],
      '#description' => $this->t('Allow users to adjust speech speed.'),
    ];

    $form['show_volume_control'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show Volume Control'),
      '#default_value' => $config['show_volume_control'],
      '#description' => $this->t('Show volume slider and mute button during playback.'),
    ];

    $form['wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Additional CSS Classes'),
      '#default_value' => $config['wrapper_classes'],
      '#description' => $this->t('Add custom CSS classes to the player wrapper (space-separated).'),
    ];

    $field_options = $this->playerBuilder->getAllTextFieldOptions();

    $form['fields'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Fields to include'),
      '#description' => $this->t('Select the text fields that should be read.'),
      '#options' => $field_options,
      '#default_value' => $config['fields'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state) {
    $this->configuration['show_voice_selector'] = $form_state->getValue('show_voice_selector');
    $this->configuration['show_speed_control'] = $form_state->getValue('show_speed_control');
    $this->configuration['show_volume_control'] = $form_state->getValue('show_volume_control');
    $this->configuration['fields'] = array_filter($form_state->getValue('fields') ?? []);
    $this->configuration['wrapper_classes'] = $form_state->getValue('wrapper_classes');
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $entity = NULL;
    foreach ($this->routeMatch->getParameters() as $parameter) {
      if ($parameter instanceof FieldableEntityInterface) {
        $entity = $parameter;
        break;
      }
    }

    if (!$entity) {
      return [];
    }

    $config = $this->getConfiguration();
    return [
      '#create_placeholder' => TRUE,
      '#lazy_builder' => [
        'local_tts.player_builder:buildPlayerLazy',
        [
          $entity->getEntityTypeId(),
          (string) $entity->id(),
          json_encode($config),
        ],
      ],
    ];
  }

}
