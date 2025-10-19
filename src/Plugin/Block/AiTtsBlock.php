<?php

namespace Drupal\ai_tts\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Drupal\ai_tts\TtsService;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Provides an AI TTS block.
 *
 * @Block(
 *   id = "ai_tts_block",
 *   admin_label = @Translation("AI Text-to-Speech"),
 *   category = @Translation("Media")
 * )
 */
class AiTtsBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The TTS service.
   *
   * @var \Drupal\ai_tts\TtsService
   */
  protected $ttsService;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The current node.
   *
   * @var \Drupal\node\NodeInterface|null
   */
  protected $node;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a new AiTtsBlock instance.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\ai_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, TtsService $tts_service, EntityFieldManagerInterface $entity_field_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, RouteMatchInterface $route_match, AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->ttsService = $tts_service;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->node = $route_match->getParameter('node');
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('ai_tts.tts_service'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('current_route_match'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'button_text' => $this->t('Listen to this page'),
      'stop_button_text' => $this->t('Stop'),
      'show_voice_selector' => TRUE,
      'show_speed_control' => TRUE,
      'fields' => [],
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state) {
    $form = parent::blockForm($form, $form_state);
    $config = $this->getConfiguration();

    $form['button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Listen Button Text'),
      '#default_value' => $config['button_text'],
      '#description' => $this->t('Text displayed on the listen button.'),
    ];

    $form['stop_button_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Stop Button Text'),
      '#default_value' => $config['stop_button_text'],
      '#description' => $this->t('Text displayed on the stop button.'),
    ];

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

    $field_options = [];
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo('node');
    foreach (array_keys($bundle_info) as $bundle) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
      foreach ($definitions as $field_name => $definition) {
        $type = $definition->getType();
        if (in_array($type, ['string', 'string_long', 'text', 'text_long', 'text_with_summary', 'text_plain', 'email', 'telephone'])) {
          $field_options[$field_name] = $definition->getLabel() . ' (' . $field_name . ')';
        }
      }
    }
    ksort($field_options);

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
    $this->configuration['button_text'] = $form_state->getValue('button_text');
    $this->configuration['stop_button_text'] = $form_state->getValue('stop_button_text');
    $this->configuration['show_voice_selector'] = $form_state->getValue('show_voice_selector');
    $this->configuration['show_speed_control'] = $form_state->getValue('show_speed_control');
    $this->configuration['fields'] = array_filter($form_state->getValue('fields') ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();
    $global_config = $this->configFactory->get('ai_tts.settings');

    $content = '';
    $selected_fields = array_filter($config['fields'] ?? []);

    if ($this->node) {
      foreach ($this->node->getFieldDefinitions() as $field_name => $field_definition) {
        if (!empty($selected_fields) && !in_array($field_name, $selected_fields, TRUE)) {
          continue;
        }

        if ($this->node->hasField($field_name)) {
          $field = $this->node->get($field_name);

          if (!$field->access('view', $this->currentUser)) {
            continue;
          }

          if (!$field->isEmpty() && in_array($field_definition->getType(), ['string', 'string_long', 'text', 'text_long', 'text_with_summary', 'text_plain', 'email', 'telephone'])) {
            foreach ($field as $item) {
              $text = $item->value;
              $text = html_entity_decode(strip_tags($text ?? ''), ENT_QUOTES | ENT_HTML5);
              $content .= (strlen($content) > 0 ? ' ' : '') . $text;
            }
          }
        }
      }
    }

    if (empty($content)) {
      return [];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-tts-container']],
    ];

    $build['listen_button'] = [
      '#type' => 'button',
      '#value' => $config['button_text'],
      '#attributes' => [
        'id' => 'ai-tts-play-button',
        'class' => ['ai-tts-button', 'ai-tts-play-button'],
        'aria-label' => $this->t('Listen to the content on this page'),
        'aria-pressed' => 'false',
      ],
    ];

    $build['stop_button'] = [
      '#type' => 'button',
      '#value' => $config['stop_button_text'],
      '#attributes' => [
        'id' => 'ai-tts-stop-button',
        'class' => ['ai-tts-button', 'ai-tts-stop-button'],
        'disabled' => 'disabled',
        'aria-label' => $this->t('Stop reading'),
      ],
    ];

    if ($config['show_voice_selector']) {
      $voices = $this->ttsService->getAvailableVoices();
      $voice_options = array_combine($voices, $voices);

      $build['voice_select'] = [
        '#type' => 'select',
        '#options' => $voice_options,
        '#default_value' => $global_config->get('default_voice'),
        '#attributes' => [
          'id' => 'ai-tts-voice-select',
          'class' => ['ai-tts-voice-select'],
          'aria-label' => $this->t('Select voice'),
        ],
      ];
    }

    if ($config['show_speed_control']) {
      $default_speed = $global_config->get('default_speed') ?: 1.0;
      $build['speed_control'] = [
        '#type' => 'number',
        '#title' => $this->t('Speed'),
        '#default_value' => $default_speed,
        '#min' => 0.5,
        '#max' => 2.0,
        '#step' => 0.1,
        '#attributes' => [
          'id' => 'ai-tts-speed-input',
          'class' => ['ai-tts-speed-input'],
          'aria-label' => $this->t('Adjust speech speed'),
          'value' => $default_speed,
        ],
      ];
    }

    $build['status'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ai-tts-status',
        'class' => ['ai-tts-status'],
        'role' => 'status',
        'aria-live' => 'polite',
      ],
    ];

    $build['audio'] = [
      '#type' => 'html_tag',
      '#tag' => 'audio',
      '#attributes' => [
        'id' => 'ai-tts-audio',
        'preload' => 'none',
      ],
    ];

    $build['#attached'] = [
      'library' => ['ai_tts/player'],
      'drupalSettings' => [
        'aiTts' => [
          'defaultVoice' => $global_config->get('default_voice'),
          'defaultSpeed' => $global_config->get('default_speed'),
          'generateUrl' => Url::fromRoute('ai_tts.generate')->toString(),
          'content' => $content,
        ],
      ],
    ];

    return $build;
  }

}
