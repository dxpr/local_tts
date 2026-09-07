<?php

namespace Drupal\ai_tts\Plugin\Block;

use Drupal\ai_tts\TtsPlayerBuilder;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
final class AiTtsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * Base fields to exclude from TTS (administrative/metadata).
   */
  const EXCLUDED_BASE_FIELDS = [
    'nid', 'uuid', 'vid', 'langcode', 'type', 'revision_timestamp',
    'revision_uid', 'revision_log', 'status', 'uid', 'created', 'changed',
    'promote', 'sticky', 'default_langcode', 'revision_default',
    'revision_translation_affected', 'metatag', 'path', 'menu_link',
    'tid', 'weight', 'parent', 'description__format',
  ];

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

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
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The route match service.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

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
   * @param \Drupal\ai_tts\TtsPlayerBuilder $player_builder
   *   The TTS player builder service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The route match service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, TtsPlayerBuilder $player_builder, EntityFieldManagerInterface $entity_field_manager, EntityTypeBundleInfoInterface $entity_type_bundle_info, RouteMatchInterface $route_match, AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->playerBuilder = $player_builder;
    $this->entityFieldManager = $entity_field_manager;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->routeMatch = $route_match;
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
      $container->get('ai_tts.player_builder'),
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
      'show_voice_selector' => TRUE,
      'show_speed_control' => TRUE,
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

    $form['wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Additional CSS Classes'),
      '#default_value' => $config['wrapper_classes'],
      '#description' => $this->t('Add custom CSS classes to the player wrapper (space-separated).'),
    ];

    $field_options = [];
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo('node');
    foreach (array_keys($bundle_info) as $bundle) {
      $definitions = $this->entityFieldManager->getFieldDefinitions('node', $bundle);
      foreach ($definitions as $field_name => $definition) {
        $type = $definition->getType();
        if (in_array($type, self::ALLOWED_FIELD_TYPES)) {
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
    $this->configuration['show_voice_selector'] = $form_state->getValue('show_voice_selector');
    $this->configuration['show_speed_control'] = $form_state->getValue('show_speed_control');
    $this->configuration['fields'] = array_filter($form_state->getValue('fields') ?? []);
    $this->configuration['wrapper_classes'] = $form_state->getValue('wrapper_classes');
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    // Get entity from route parameters (must be in build(), not constructor,
    // because blocks are cached and constructor doesn't run on every request).
    $entity = NULL;
    foreach ($this->routeMatch->getParameters() as $parameter) {
      if ($parameter instanceof FieldableEntityInterface) {
        $entity = $parameter;
        break;
      }
    }

    // Delegate to the player builder service.
    return $this->playerBuilder->buildPlayer($entity, $this->getConfiguration());
  }

}
