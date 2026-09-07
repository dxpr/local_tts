<?php

namespace Drupal\local_tts\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Query\TableSortExtender;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Overview form for managing cached TTS audio files.
 */
final class TtsCacheOverviewForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The date formatter.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('file_system')
    );
  }

  public function __construct(
    Connection $database,
    EntityTypeManagerInterface $entity_type_manager,
    DateFormatterInterface $date_formatter,
    FileSystemInterface $file_system,
  ) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'local_tts_cache_overview_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('local_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';
    $max_size = $config->get('cache_max_size') ?: 1073741824;

    // Read filters from the request.
    $request = $this->getRequest();
    $filter_language = $request->query->get('language', '');
    $filter_voice = $request->query->get('voice', '');
    $filter_speed = $request->query->get('speed', '');

    // Sortable header definition.
    $header = [
      'entity' => ['data' => $this->t('Entity'), 'field' => 'c.entity_id'],
      'language' => ['data' => $this->t('Language'), 'field' => 'c.language'],
      'voice' => ['data' => $this->t('Voice'), 'field' => 'c.voice'],
      'speed' => ['data' => $this->t('Speed'), 'field' => 'c.speed'],
      'file_size' => ['data' => $this->t('File size'), 'field' => 'c.file_size', 'sort' => 'desc'],
      'created' => ['data' => $this->t('Created'), 'field' => 'c.created'],
      'operations' => $this->t('Operations'),
    ];

    // Total count and size (unfiltered) for the summary.
    $totals = $this->database->select('local_tts_cache', 'c')
      ->fields('c', [])
      ->countQuery()
      ->execute()
      ->fetchField();
    $total_count = (int) $totals;

    $total_size_result = $this->database->query('SELECT COALESCE(SUM(file_size), 0) FROM {local_tts_cache}')
      ->fetchField();
    $total_size = (int) $total_size_result;
    $percent = $max_size > 0 ? round(($total_size / $max_size) * 100) : 0;

    // Summary bar.
    $form['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['local-tts-cache-summary']],
    ];

    if ($total_count === 0) {
      $form['summary']['text'] = [
        '#markup' => '<p>' . $this->t('No cached audio files. Audio is generated on demand when visitors click "Listen to this article."') . '</p>',
      ];
      return $form;
    }

    $form['summary']['text'] = [
      '#markup' => '<p><strong>' . $this->t('@count files, @size total (@percent% of @limit limit)', [
        '@count' => $total_count,
        '@size' => ByteSizeMarkup::create($total_size),
        '@percent' => $percent,
        '@limit' => ByteSizeMarkup::create($max_size),
      ]) . '</strong></p>',
    ];

    $form['summary']['directory'] = [
      '#markup' => '<p>' . $this->t('Cache directory: <code>@dir</code>', [
        '@dir' => $audio_dir,
      ]) . '</p>',
    ];

    // Collect distinct values for filter dropdowns.
    $languages = $this->database->query('SELECT DISTINCT language FROM {local_tts_cache} ORDER BY language')
      ->fetchCol();
    $voices = $this->database->query('SELECT DISTINCT voice FROM {local_tts_cache} ORDER BY voice')
      ->fetchCol();
    $speeds = $this->database->query('SELECT DISTINCT speed FROM {local_tts_cache} ORDER BY speed')
      ->fetchCol();

    // Filter dropdowns.
    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['local-tts-cache-filters', 'container-inline']],
    ];

    $language_options = ['' => $this->t('All languages')];
    foreach ($languages as $lang) {
      $language_options[$lang] = $lang;
    }
    $form['filters']['language'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#options' => $language_options,
      '#default_value' => $filter_language,
    ];

    $voice_options = ['' => $this->t('All voices')];
    foreach ($voices as $v) {
      $voice_options[$v] = $v;
    }
    $form['filters']['voice'] = [
      '#type' => 'select',
      '#title' => $this->t('Voice'),
      '#options' => $voice_options,
      '#default_value' => $filter_voice,
    ];

    $speed_options = ['' => $this->t('All speeds')];
    foreach ($speeds as $s) {
      $speed_options[$s] = $s . 'x';
    }
    $form['filters']['speed'] = [
      '#type' => 'select',
      '#title' => $this->t('Speed'),
      '#options' => $speed_options,
      '#default_value' => $filter_speed,
    ];

    $form['filters']['apply'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
      '#submit' => ['::applyFilters'],
    ];

    if ($filter_language || $filter_voice || $filter_speed) {
      $form['filters']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Reset'),
        '#url' => Url::fromRoute('local_tts.cache_overview'),
        '#attributes' => ['class' => ['button']],
      ];
    }

    // Build the query with sorting, filtering, and paging.
    /** @var \Drupal\Core\Database\Query\PagerSelectExtender $query */
    $query = $this->database->select('local_tts_cache', 'c')
      ->fields('c')
      ->extend(TableSortExtender::class)
      ->extend(PagerSelectExtender::class);
    $query->limit(50)
      ->orderByHeader($header);

    if ($filter_language) {
      $query->condition('c.language', $filter_language);
    }
    if ($filter_voice) {
      $query->condition('c.voice', $filter_voice);
    }
    if ($filter_speed) {
      $query->condition('c.speed', $filter_speed);
    }

    $rows = $query->execute()->fetchAll();

    // Preload entity titles in bulk.
    $entity_refs = [];
    foreach ($rows as $row) {
      if (!empty($row->entity_type) && !empty($row->entity_id)) {
        $entity_refs[$row->entity_type][$row->entity_id] = $row->entity_id;
      }
    }

    $entity_labels = [];
    foreach ($entity_refs as $entity_type => $ids) {
      try {
        $storage = $this->entityTypeManager->getStorage($entity_type);
        $entities = $storage->loadMultiple($ids);
        foreach ($entities as $entity) {
          $entity_labels[$entity_type . ':' . $entity->id()] = $entity;
        }
      }
      catch (\Exception $e) {
      }
    }

    $options = [];
    foreach ($rows as $row) {
      $key = $row->entity_type . ':' . $row->entity_id;
      $entity_cell = $row->entity_type . '/' . $row->entity_id;

      if (isset($entity_labels[$key])) {
        $entity = $entity_labels[$key];
        try {
          $entity_cell = [
            'data' => [
              '#type' => 'link',
              '#title' => $entity->label(),
              '#url' => $entity->toUrl(),
            ],
          ];
        }
        catch (\Exception $e) {
          $entity_cell = $entity->label();
        }
      }

      $delete_url = Url::fromRoute('local_tts.cache_delete', [
        'cache_key' => $row->cache_key,
      ]);

      $options[$row->cache_key] = [
        'entity' => $entity_cell,
        'language' => $row->language,
        'voice' => $row->voice,
        'speed' => $row->speed . 'x',
        'file_size' => ByteSizeMarkup::create((int) $row->file_size),
        'created' => $this->dateFormatter->formatTimeDiffSince($row->created),
        'operations' => [
          'data' => [
            '#type' => 'operations',
            '#links' => [
              'delete' => [
                'title' => $this->t('Delete'),
                'url' => $delete_url,
              ],
            ],
          ],
        ],
      ];
    }

    $form['files'] = [
      '#type' => 'tableselect',
      '#header' => $header,
      '#options' => $options,
      '#empty' => $this->t('No cached audio files match the selected filters.'),
    ];

    $form['pager'] = [
      '#type' => 'pager',
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['delete_selected'] = [
      '#type' => 'submit',
      '#value' => $this->t('Delete selected'),
      '#submit' => ['::deleteSelected'],
      '#button_type' => 'danger',
    ];

    $form['actions']['clear_all'] = [
      '#type' => 'submit',
      '#value' => $this->t('Clear all cache'),
      '#submit' => ['::clearAll'],
      '#button_type' => 'danger',
      '#attributes' => [
        'onclick' => "return confirm('" . $this->t('Delete all @count cached audio files? This cannot be undone.', ['@count' => $total_count]) . "')",
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
  }

  /**
   * Submit handler: apply filter dropdowns via query parameters.
   */
  public function applyFilters(array &$form, FormStateInterface $form_state) {
    $params = [];
    $language = $form_state->getValue('language');
    $voice = $form_state->getValue('voice');
    $speed = $form_state->getValue('speed');

    if ($language) {
      $params['language'] = $language;
    }
    if ($voice) {
      $params['voice'] = $voice;
    }
    if ($speed) {
      $params['speed'] = $speed;
    }

    $form_state->setRedirect('local_tts.cache_overview', [], ['query' => $params]);
  }

  /**
   * Submit handler: delete selected files.
   */
  public function deleteSelected(array &$form, FormStateInterface $form_state) {
    $selected = array_filter($form_state->getValue('files'));

    if (empty($selected)) {
      $this->messenger()->addWarning($this->t('No files selected.'));
      return;
    }

    $config = $this->config('local_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';
    $directory = $this->fileSystem->realpath($audio_dir);
    $deleted = 0;

    foreach ($selected as $cache_key) {
      if ($directory) {
        $file_path = $directory . '/' . $cache_key . '.ogg';
        if (file_exists($file_path)) {
          @unlink($file_path);
        }
      }

      $this->database->delete('local_tts_cache')
        ->condition('cache_key', $cache_key)
        ->execute();
      $deleted++;
    }

    $this->messenger()->addStatus($this->t('Deleted @count audio files.', ['@count' => $deleted]));
    $form_state->setRedirect('local_tts.cache_overview');
  }

  /**
   * Submit handler: clear all cached files.
   */
  public function clearAll(array &$form, FormStateInterface $form_state) {
    $config = $this->config('local_tts.settings');
    $audio_dir = $config->get('audio_directory') ?: 'public://local-tts';

    try {
      $this->fileSystem->deleteRecursive($audio_dir);
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to delete cache directory: @message', ['@message' => $e->getMessage()]));
      return;
    }

    $this->database->truncate('local_tts_cache')->execute();
    $this->messenger()->addStatus($this->t('All cached audio files have been deleted.'));
    $form_state->setRedirect('local_tts.cache_overview');
  }

}
