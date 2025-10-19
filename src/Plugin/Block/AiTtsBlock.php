<?php

namespace Drupal\ai_tts\Plugin\Block;

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Entity\FieldableEntityInterface;
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
   * The current entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface|null
   */
  protected $entity;

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
    $this->currentUser = $current_user;

    // Try to get any fieldable entity from route parameters.
    $this->entity = NULL;
    foreach ($route_match->getParameters() as $parameter) {
      if ($parameter instanceof FieldableEntityInterface) {
        $this->entity = $parameter;
        break;
      }
    }
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
  }

  /**
   * Get a valid default voice for the given language.
   *
   * @param array $available_voices
   *   Available voices for the language.
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   A valid voice code.
   */
  protected function getValidDefaultVoice(array $available_voices, $langcode) {
    $config = $this->configFactory->get('ai_tts.settings');
    $default_voices = $config->get('default_voices') ?? [];

    // Check language-specific default first.
    if (isset($default_voices[$langcode]) && isset($available_voices[$default_voices[$langcode]])) {
      return $default_voices[$langcode];
    }

    // Fall back to first available voice for this language.
    return array_key_first($available_voices);
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $config = $this->getConfiguration();
    $global_config = $this->configFactory->get('ai_tts.settings');

    // Get entity language for voice filtering.
    $langcode = NULL;
    if ($this->entity && method_exists($this->entity, 'language')) {
      $langcode = $this->entity->language()->getId();
    }

    // ARCHITECTURE: Fail hard, no defensive coding.
    // If entity doesn't exist, Drupal routing wouldn't have loaded it.
    // If no voices available, backend will return 500.
    // If no text content, backend will return 400.
    // Block only shows on entity pages, always has entity context.
    if (!$this->entity) {
      return [];
    }

    // Check access (valid check - prevents showing UI for private content).
    $anonymous = new AnonymousUserSession();
    if (!$this->entity->access('view', $anonymous)) {
      return [];
    }

    // Voices check still valid (prevents UI for unsupported languages).
    $available_voices = $this->ttsService->getAvailableVoices($langcode);
    if (empty($available_voices)) {
      return [];
    }

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-tts-container', 'container-inline']],
    ];

    $build['controls'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-tts-controls', 'container-inline']],
    ];

    $build['controls']['listen_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Listen to this page'),
      '#attributes' => [
        'id' => 'ai-tts-play-button',
        'class' => ['ai-tts-button', 'ai-tts-play-button'],
        'aria-label' => $this->t('Listen to the content on this page'),
        'aria-pressed' => 'false',
        'aria-controls' => 'ai-tts-audio',
      ],
    ];

    $build['controls']['stop_button'] = [
      '#type' => 'button',
      '#value' => $this->t('Stop'),
      '#attributes' => [
        'id' => 'ai-tts-stop-button',
        'class' => ['ai-tts-button', 'ai-tts-stop-button'],
        'disabled' => 'disabled',
        'aria-label' => $this->t('Stop reading'),
        'aria-controls' => 'ai-tts-audio',
      ],
    ];

    $build['settings'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-tts-settings', 'container-inline']],
    ];

    if ($config['show_voice_selector']) {
      $default_voice = $this->getValidDefaultVoice(
        $available_voices,
        $langcode
      );

      $build['settings']['voice_select'] = [
        '#type' => 'select',
        '#title' => $this->t('Voice'),
        '#options' => $available_voices,
        '#value' => $default_voice,
        '#attributes' => [
          'id' => 'ai-tts-voice-select',
          'class' => ['ai-tts-voice-select'],
          'aria-label' => $this->t('Select voice'),
        ],
      ];
    }

    if ($config['show_speed_control']) {
      $default_speed = $this->ttsService->normalizeSpeed(
        $global_config->get('default_speed') ?: '1'
      );

      $build['settings']['speed_control'] = [
        '#type' => 'select',
        '#title' => $this->t('Speed'),
        '#options' => [
          '0.8' => $this->t('0.8x'),
          '1' => $this->t('1x (Normal)'),
          '1.2' => $this->t('1.2x'),
          '1.5' => $this->t('1.5x'),
          '2' => $this->t('2x'),
        ],
        '#value' => $default_speed,
        '#attributes' => [
          'id' => 'ai-tts-speed-input',
          'class' => ['ai-tts-speed-input'],
          'aria-label' => $this->t('Adjust speech speed'),
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

    $build['duration'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ai-tts-duration',
        'class' => ['ai-tts-duration'],
        'style' => 'display: none;',
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

    // Use language-aware default voice for JavaScript.
    $js_default_voice = $this->getValidDefaultVoice(
      $available_voices,
      $langcode
    );

    // SECURITY: Pass only entity reference to JavaScript, NOT the content.
    // Server loads entity, validates access, extracts text.
    $build['#attached'] = [
      'library' => ['ai_tts/player'],
      'drupalSettings' => [
        'aiTts' => [
          'defaultVoice' => $js_default_voice,
          'defaultSpeed' => $global_config->get('default_speed'),
          'generateUrl' => Url::fromRoute('ai_tts.generate')->toString(),
          'language' => $langcode,
          'entityType' => $this->entity->getEntityTypeId(),
          'entityId' => $this->entity->id(),
          'fields' => array_values(array_filter($config['fields'] ?? [])),
        ],
      ],
    ];

    // Add cache contexts and tags to ensure block content is unique per page.
    $build['#cache']['contexts'][] = 'route';
    $build['#cache']['contexts'][] = 'languages:language_content';

    if ($this->entity) {
      $entity_type = $this->entity->getEntityTypeId();
      $entity_id = $this->entity->id();
      $build['#cache']['tags'][] = "{$entity_type}:{$entity_id}";
    }

    $build['#cache']['tags'][] = 'config:ai_tts.settings';

    return $build;
  }

}
