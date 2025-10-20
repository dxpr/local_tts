<?php

namespace Drupal\ai_tts\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\ai_tts\Service\TtsBatchService;
use Drupal\ai_tts\TtsService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for batch TTS generation.
 */
class AiTtsBatchForm extends FormBase {

  /**
   * The TTS batch service.
   *
   * @var \Drupal\ai_tts\Service\TtsBatchService
   */
  protected $batchService;

  /**
   * The TTS service.
   *
   * @var \Drupal\ai_tts\TtsService
   */
  protected $ttsService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_tts.batch_service'),
      $container->get('ai_tts.tts_service')
    );
  }

  /**
   * Constructs an AiTtsBatchForm object.
   *
   * @param \Drupal\ai_tts\Service\TtsBatchService $batch_service
   *   The batch service.
   * @param \Drupal\ai_tts\TtsService $tts_service
   *   The TTS service.
   */
  public function __construct(TtsBatchService $batch_service, TtsService $tts_service) {
    $this->batchService = $batch_service;
    $this->ttsService = $tts_service;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_tts_batch_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_tts.settings');

    $form['#attributes']['class'][] = 'ai-tts-batch-form';

    // Entity selection.
    $form['entity_selection'] = [
      '#type' => 'details',
      '#title' => $this->t('Content Selection'),
      '#open' => TRUE,
    ];

    // Get all entity bundles with ai_tts_player field attached.
    $available_bundles = $this->batchService->getAvailableEntityBundles();

    $bundle_options = [];
    foreach ($available_bundles as $entity_type_id => $bundles) {
      foreach ($bundles as $bundle => $label) {
        $bundle_options["$entity_type_id:$bundle"] = $label;
      }
    }

    $form['entity_selection']['entity_bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content types'),
      '#description' => $this->t('Select content types to generate audio for. Only types with TTS field enabled are shown.'),
      '#options' => $bundle_options,
      '#required' => TRUE,
    ];

    $form['entity_selection']['date_filter'] = [
      '#type' => 'details',
      '#title' => $this->t('Date filter'),
      '#description' => $this->t('Optionally filter entities by update date.'),
      '#open' => FALSE,
    ];

    $form['entity_selection']['date_filter']['enable_date_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable date filtering'),
      '#description' => $this->t('Only process entities updated after a specific date.'),
      '#default_value' => FALSE,
    ];

    $form['entity_selection']['date_filter']['updated_after'] = [
      '#type' => 'date',
      '#title' => $this->t('Updated after'),
      '#description' => $this->t('Only process entities updated after this date (e.g., 2025-01-01).'),
      '#states' => [
        'visible' => [
          ':input[name="enable_date_filter"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="enable_date_filter"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Voice & generation settings.
    $form['generation_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Voice & Generation Settings'),
      '#open' => TRUE,
    ];

    $form['generation_settings']['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#description' => $this->t('Generate audio for this language. Only entities with this language will be processed.'),
      '#options' => [
        'en' => $this->t('English'),
        'es' => $this->t('Spanish'),
        'fr' => $this->t('French'),
        'ja' => $this->t('Japanese'),
        'zh' => $this->t('Chinese (Mandarin)'),
        'hi' => $this->t('Hindi'),
        'it' => $this->t('Italian'),
        'pt' => $this->t('Portuguese'),
      ],
      '#default_value' => 'en',
      '#required' => TRUE,
    ];

    // Get available voices.
    $voice_options = ['_default' => $this->t('- Use default per language -')];
    $voice_options += $this->ttsService->getAvailableVoices();

    $form['generation_settings']['voice'] = [
      '#type' => 'select',
      '#title' => $this->t('Voice'),
      '#description' => $this->t('Voice to use. Leave as "Default" to use configured defaults per language.'),
      '#options' => $voice_options,
      '#default_value' => '_default',
    ];

    $form['generation_settings']['speed'] = [
      '#type' => 'select',
      '#title' => $this->t('Speed'),
      '#options' => [
        '0.8' => $this->t('0.8x (Slower)'),
        '1' => $this->t('1.0x (Normal)'),
        '1.2' => $this->t('1.2x (Faster)'),
        '1.5' => $this->t('1.5x (Much faster)'),
        '2' => $this->t('2.0x (Very fast)'),
      ],
      '#default_value' => $config->get('default_speed') ?? '1',
    ];

    $form['generation_settings']['force_refresh'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Force regeneration'),
      '#description' => $this->t('Regenerate audio even if cached files already exist. <strong>Warning:</strong> This will significantly increase processing time and server load.'),
      '#default_value' => FALSE,
    ];

    $form['generation_settings']['limit'] = [
      '#type' => 'number',
      '#title' => $this->t('Limit'),
      '#description' => $this->t('Maximum number of entities to process. Leave at 0 for no limit.'),
      '#default_value' => 0,
      '#min' => 0,
    ];

    // Server load management.
    $form['load_management'] = [
      '#type' => 'details',
      '#title' => $this->t('Server Load Management'),
      '#description' => $this->t('TTS generation is CPU-intensive. These settings help prevent server overload.'),
      '#open' => TRUE,
    ];

    $form['load_management']['batch_size'] = [
      '#type' => 'select',
      '#title' => $this->t('Batch size'),
      '#description' => $this->t('Number of entities to process per batch operation. Smaller batches reduce memory usage but take longer overall.'),
      '#options' => [
        '1' => $this->t('1 (Safest, slowest)'),
        '5' => $this->t('5 (Conservative)'),
        '10' => $this->t('10 (Balanced)'),
        '25' => $this->t('25 (Aggressive)'),
        '50' => $this->t('50 (Maximum)'),
      ],
      '#default_value' => '10',
      '#required' => TRUE,
    ];

    $form['load_management']['inter_batch_delay'] = [
      '#type' => 'select',
      '#title' => $this->t('Delay between batches'),
      '#description' => $this->t('Pause between batch operations to allow server to recover. Recommended for large operations.'),
      '#options' => [
        '0' => $this->t('None (fastest)'),
        '1' => $this->t('1 second'),
        '2' => $this->t('2 seconds'),
        '5' => $this->t('5 seconds (recommended)'),
        '10' => $this->t('10 seconds (conservative)'),
        '30' => $this->t('30 seconds (very conservative)'),
      ],
      '#default_value' => '5',
    ];

    // Server load threshold with disable option.
    $current_load = function_exists('sys_getloadavg') ? round(sys_getloadavg()[0], 2) : 'N/A';
    $configured_threshold = $config->get('max_server_load') ?? 2.0;

    $form['load_management']['enable_load_check'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable server load checking'),
      '#description' => $this->t('Check server load before processing each batch. Disable to skip load checks entirely (not recommended for shared hosting).'),
      '#default_value' => TRUE,
    ];

    $form['load_management']['max_load_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Max server load threshold'),
      '#description' => $this->t('Skip batch operations when 1-minute load average exceeds this value. Current system load: <strong>@load</strong>. Configured default: <strong>@default</strong>.', [
        '@load' => $current_load,
        '@default' => $configured_threshold,
      ]),
      '#default_value' => $configured_threshold,
      '#min' => 0.1,
      '#max' => 20.0,
      '#step' => 0.1,
      '#states' => [
        'visible' => [
          ':input[name="enable_load_check"]' => ['checked' => TRUE],
        ],
        'required' => [
          ':input[name="enable_load_check"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['load_management']['pause_on_high_load'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Pause (not fail) when server load is high'),
      '#description' => $this->t('If enabled, batch will wait and retry when load is high instead of failing. This makes batch processing more resilient but may take much longer.'),
      '#default_value' => TRUE,
      '#states' => [
        'visible' => [
          ':input[name="enable_load_check"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['load_management']['max_retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Max retries per entity'),
      '#description' => $this->t('Maximum number of retry attempts for failed generations before skipping.'),
      '#default_value' => 3,
      '#min' => 0,
      '#max' => 10,
      '#states' => [
        'visible' => [
          ':input[name="pause_on_high_load"]' => ['checked' => TRUE],
          ':input[name="enable_load_check"]' => ['checked' => TRUE],
        ],
      ],
    ];

    // Preview section.
    $form['preview'] = [
      '#type' => 'details',
      '#title' => $this->t('Preview'),
      '#open' => FALSE,
    ];

    $form['preview']['info'] = [
      '#type' => 'markup',
      '#markup' => '<div id="batch-preview">' . $this->t('Select options above to see estimate.') . '</div>',
    ];

    // Actions.
    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Start batch generation'),
      '#button_type' => 'primary',
    ];

    // Attach library for preview functionality.
    $form['#attached']['library'][] = 'ai_tts/batch_form';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Ensure at least one bundle selected.
    $selected = array_filter($values['entity_bundles']);
    if (empty($selected)) {
      $form_state->setErrorByName('entity_bundles', $this->t('You must select at least one content type.'));
    }

    // Warn if force refresh + large limit.
    if ($values['force_refresh'] && ($values['limit'] == 0 || $values['limit'] > 100)) {
      $this->messenger()->addWarning($this->t('Force regeneration with many entities will take significant time and server resources.'));
    }

    // Warn if load checking is disabled.
    if (!$values['enable_load_check']) {
      $this->messenger()->addWarning($this->t('Server load checking is disabled. This may cause server performance issues during batch processing.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    // Get selected bundles.
    $selected_bundles = array_filter($values['entity_bundles']);

    // Prepare date filter.
    $updated_after = NULL;
    if (!empty($values['enable_date_filter']) && !empty($values['updated_after'])) {
      $updated_after = $values['updated_after'];
    }

    // Get entities to process.
    $entities = $this->batchService->getEntitiesForGeneration(
      array_values($selected_bundles),
      $values['language'],
      (bool) $values['force_refresh'],
      (int) $values['limit'],
      $updated_after
    );

    if (empty($entities)) {
      $this->messenger()->addWarning($this->t('No entities found to process. All may already have cached audio.'));
      return;
    }

    $total_entities = count($entities);

    // Build batch definition.
    $batch = [
      'title' => $this->t('Generating TTS audio for @count entities', ['@count' => $total_entities]),
      'init_message' => $this->t('Initializing batch TTS generation...'),
      'progress_message' => $this->t('Processed @current of @total entities (@percentage%). Estimated time remaining: @estimate.'),
      'error_message' => $this->t('TTS batch generation encountered errors.'),
      'operations' => [],
      'finished' => [static::class, 'batchFinished'],
      'progressive' => TRUE,
    ];

    // Determine max load threshold (NULL if disabled).
    $max_load_threshold = $values['enable_load_check']
      ? (float) $values['max_load_threshold']
      : NULL;

    // Split into chunks based on batch_size.
    $batch_size = (int) $values['batch_size'];
    $chunks = array_chunk($entities, $batch_size);

    foreach ($chunks as $chunk) {
      $batch['operations'][] = [
        [$this->batchService, 'processBatch'],
        [
          $chunk,
          [
            'language' => $values['language'],
            'voice' => $values['voice'] !== '_default' ? $values['voice'] : NULL,
            'speed' => (float) $values['speed'],
            'force_refresh' => (bool) $values['force_refresh'],
            'max_load_threshold' => $max_load_threshold,
            'pause_on_high_load' => (bool) $values['pause_on_high_load'],
            'max_retries' => (int) $values['max_retries'],
            'inter_batch_delay' => (int) $values['inter_batch_delay'],
          ],
          $total_entities,
        ],
      ];
    }

    batch_set($batch);
  }

  /**
   * Batch finished callback.
   *
   * @param bool $success
   *   Whether the batch completed successfully.
   * @param array $results
   *   Results array from batch context.
   * @param array $operations
   *   Remaining operations (if any).
   */
  public static function batchFinished($success, $results, $operations) {
    $messenger = \Drupal::messenger();

    if ($success) {
      $messenger->addStatus(t('Successfully processed @count entities.', [
        '@count' => $results['processed'] ?? 0,
      ]));

      if (!empty($results['generated'])) {
        $messenger->addStatus(t('Generated @count new audio files.', [
          '@count' => $results['generated'],
        ]));
      }

      if (!empty($results['cached'])) {
        $messenger->addMessage(t('Skipped @count entities (already cached).', [
          '@count' => $results['cached'],
        ]));
      }

      if (!empty($results['high_load_pauses'])) {
        $messenger->addWarning(t('Paused @count times due to high server load.', [
          '@count' => $results['high_load_pauses'],
        ]));
      }

      if (!empty($results['retry_counts'])) {
        $total_retries = array_sum($results['retry_counts']);
        $messenger->addMessage(t('Retried @count entities (@total total retry attempts).', [
          '@count' => count($results['retry_counts']),
          '@total' => $total_retries,
        ]));
      }

      if (!empty($results['errors'])) {
        $messenger->addError(t('Failed to process @count entities.', [
          '@count' => count($results['errors']),
        ]));

        foreach (array_slice($results['errors'], 0, 10) as $error) {
          $messenger->addError($error);
        }

        if (count($results['errors']) > 10) {
          $messenger->addError(t('...and @more more errors', [
            '@more' => count($results['errors']) - 10,
          ]));
        }
      }
    }
    else {
      $messenger->addError(t('Batch processing failed with errors.'));
    }
  }

}
