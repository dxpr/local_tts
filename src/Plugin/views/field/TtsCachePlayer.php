<?php

namespace Drupal\local_tts\Plugin\views\field;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\local_tts\TtsPlayerBuilder;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders a TTS player for a cached audio file.
 */
#[ViewsField("local_tts_cache_player")]
class TtsCachePlayer extends FieldPluginBase {

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The file system service.
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The file URL generator.
   */
  protected FileUrlGeneratorInterface $fileUrlGenerator;

  /**
   * The TTS player builder.
   */
  protected TtsPlayerBuilder $playerBuilder;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->configFactory = $container->get('config.factory');
    $instance->fileSystem = $container->get('file_system');
    $instance->fileUrlGenerator = $container->get('file_url_generator');
    $instance->playerBuilder = $container->get('local_tts.player_builder');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();
    $options['show_voice_selector'] = ['default' => FALSE];
    $options['show_speed_control'] = ['default' => TRUE];
    $options['show_volume_control'] = ['default' => TRUE];
    $options['wrapper_classes'] = ['default' => 'local-tts-compact'];
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
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $this->ensureMyTable();
    $this->addAdditionalFields(['cache_key', 'language']);
  }

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $cache_key = $this->getValue($values, 'cache_key');
    if (empty($cache_key)) {
      return '';
    }

    $audio_dir = $this->configFactory->get('local_tts.settings')->get('audio_directory') ?: 'public://local-tts';
    $uri = $audio_dir . '/' . $cache_key . '.ogg';

    $real_path = $this->fileSystem->realpath($uri);
    if (!$real_path || !file_exists($real_path)) {
      return $this->t('File missing');
    }

    $url = $this->fileUrlGenerator->generateAbsoluteString($uri);
    $langcode = $this->getValue($values, 'language') ?: 'en';

    $classes = trim($this->options['wrapper_classes'] ?? 'local-tts-compact');

    $build = $this->playerBuilder->buildFilePlayer($url, $langcode, [
      'show_voice_selector' => (bool) $this->options['show_voice_selector'],
      'show_speed_control' => (bool) $this->options['show_speed_control'],
      'show_volume_control' => (bool) $this->options['show_volume_control'],
      'wrapper_classes' => $classes,
    ]);

    return $this->getRenderer()->render($build);
  }

}
