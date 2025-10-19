<?php

namespace Drupal\ai_tts\Form;

use Drupal\ai_tts\TtsService;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Test form for AI TTS.
 */
class AiTtsTestForm extends FormBase {

  /**
   * The TTS service.
   *
   * @var \Drupal\ai_tts\TtsService
   */
  protected $ttsService;

  /**
   * The file URL generator.
   *
   * @var \Drupal\Core\File\FileUrlGeneratorInterface
   */
  protected $fileUrlGenerator;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new AiTtsTestForm.
   *
   * @param \Drupal\ai_tts\TtsService $tts_service
   *   The TTS service.
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $file_url_generator
   *   The file URL generator.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   */
  public function __construct(
    TtsService $tts_service,
    FileUrlGeneratorInterface $file_url_generator,
    FileSystemInterface $file_system,
  ) {
    $this->ttsService = $tts_service;
    $this->fileUrlGenerator = $file_url_generator;
    $this->fileSystem = $file_system;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_tts.tts_service'),
      $container->get('file_url_generator'),
      $container->get('file_system')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_tts_test_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#prefix'] = '<div id="ai-tts-test-wrapper">';
    $form['#suffix'] = '</div>';

    $form['description'] = [
      '#markup' => '<p>' . $this->t('Test the AI TTS service by entering text below. The audio will be generated and played in your browser.') . '</p>',
    ];

    $form['test_input'] = [
      '#type' => 'details',
      '#title' => $this->t('Test Input'),
      '#open' => TRUE,
    ];

    $form['test_input']['text'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Text to convert'),
      '#description' => $this->t('Enter the text you want to convert to speech. Maximum @max characters.', [
        '@max' => number_format($this->config('ai_tts.settings')->get('max_text_length') ?: 1000000),
      ]),
      '#default_value' => 'Hello! This is a test of the AI Text-to-Speech system. How does it sound?',
      '#required' => TRUE,
      '#rows' => 5,
    ];

    $voices = $this->ttsService->getAvailableVoices();
    $form['test_input']['voice'] = [
      '#type' => 'select',
      '#title' => $this->t('Voice'),
      '#options' => $voices,
      '#default_value' => $this->config('ai_tts.settings')->get('default_voice') ?: 'af_sky',
    ];

    $form['test_input']['speed'] = [
      '#type' => 'number',
      '#title' => $this->t('Speed'),
      '#min' => 0.5,
      '#max' => 2.0,
      '#step' => 0.1,
      '#default_value' => $this->config('ai_tts.settings')->get('default_speed') ?: 1.0,
      '#description' => $this->t('Speech speed (0.5 = slow, 1.0 = normal, 2.0 = fast)'),
    ];

    $form['test_input']['actions'] = [
      '#type' => 'actions',
    ];

    $form['test_input']['actions']['generate'] = [
      '#type' => 'button',
      '#value' => $this->t('Generate Speech'),
      '#ajax' => [
        'callback' => '::generateCallback',
        'wrapper' => 'ai-tts-test-wrapper',
        'progress' => [
          'type' => 'throbber',
          'message' => $this->t('Generating audio...'),
        ],
      ],
    ];

    // Results section.
    $storage = $form_state->getStorage();
    if (!empty($storage['audio_url'])) {
      $form['results'] = [
        '#type' => 'details',
        '#title' => $this->t('Results'),
        '#open' => TRUE,
      ];

      $form['results']['status'] = [
        '#markup' => '<div class="messages messages--status">' .
        $this->t('✓ Audio generated successfully! File size: @size', [
          '@size' => $storage['file_size'],
        ]) . '</div>',
      ];

      $form['results']['player'] = [
        '#type' => 'html_tag',
        '#tag' => 'audio',
        '#attributes' => [
          'controls' => TRUE,
          'autoplay' => TRUE,
          'src' => $storage['audio_url'],
          'style' => 'width: 100%; max-width: 600px;',
        ],
        '#value' => $this->t('Your browser does not support the audio element.'),
      ];

      $form['results']['timing'] = [
        '#markup' => '<p><small>' .
        $this->t('Generation time: @time seconds', [
          '@time' => number_format($storage['generation_time'], 2),
        ]) . '</small></p>',
      ];

      $form['results']['download'] = [
        '#type' => 'link',
        '#title' => $this->t('Download Audio File'),
        '#url' => Url::fromUri($storage['audio_url']),
        '#attributes' => [
          'class' => ['button'],
          'download' => TRUE,
        ],
      ];
    }

    // Show errors if any.
    if (!empty($storage['error_message'])) {
      $form['error'] = [
        '#markup' => '<div class="messages messages--error">' .
        $storage['error_message'] . '</div>',
      ];
    }

    return $form;
  }

  /**
   * AJAX callback for generate button.
   */
  public function generateCallback(array &$form, FormStateInterface $form_state) {
    $text = $form_state->getValue('text');
    $voice = $form_state->getValue('voice');
    $speed = (float) $form_state->getValue('speed');

    try {
      $start_time = microtime(TRUE);

      $audio_uri = $this->ttsService->generateSpeech($text, [
        'voice' => $voice,
        'speed' => $speed,
        'use_cache' => FALSE,
      ]);

      $generation_time = microtime(TRUE) - $start_time;

      if ($audio_uri) {
        $real_path = $this->fileSystem->realpath($audio_uri);
        $file_size = file_exists($real_path) ? format_size(filesize($real_path)) : 'unknown';

        $audio_url = $this->fileUrlGenerator->generateAbsoluteString($audio_uri);

        // Store in form storage to persist across AJAX rebuilds.
        $storage = $form_state->getStorage();
        $storage['audio_url'] = $audio_url;
        $storage['file_size'] = $file_size;
        $storage['generation_time'] = $generation_time;
        $storage['error_message'] = NULL;
        $form_state->setStorage($storage);

        $this->messenger()->addStatus($this->t('Audio generated successfully in @time seconds!', [
          '@time' => number_format($generation_time, 2),
        ]));
      }
      else {
        $storage = $form_state->getStorage();
        $storage['error_message'] = $this->t('Failed to generate audio. Check the error logs for details.');
        $storage['audio_url'] = NULL;
        $form_state->setStorage($storage);
      }
    }
    catch (\InvalidArgumentException $e) {
      $storage = $form_state->getStorage();
      $storage['error_message'] = $this->t('Validation error: @message', [
        '@message' => $e->getMessage(),
      ]);
      $storage['audio_url'] = NULL;
      $form_state->setStorage($storage);
    }
    catch (\RuntimeException $e) {
      $storage = $form_state->getStorage();
      $storage['error_message'] = $this->t('Service error: @message', [
        '@message' => $e->getMessage(),
      ]);
      $storage['audio_url'] = NULL;
      $form_state->setStorage($storage);
    }
    catch (\Exception $e) {
      $storage = $form_state->getStorage();
      $storage['error_message'] = $this->t('Unexpected error: @message', [
        '@message' => $e->getMessage(),
      ]);
      $storage['audio_url'] = NULL;
      $form_state->setStorage($storage);
    }

    $form_state->setRebuild(TRUE);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Form submission is handled by AJAX callback.
  }

}
