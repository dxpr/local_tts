<?php

namespace Drupal\local_tts\Drush\Commands;

use Drupal\local_tts\Service\TtsBatchService;
use Drupal\local_tts\TtsService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for Local TTS module.
 */
final class LocalTtsCommands extends DrushCommands {

  /**
   * Constructs an LocalTtsCommands object.
   *
   * @param \Drupal\local_tts\TtsService $ttsService
   *   The TTS service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   * @param \Drupal\local_tts\Service\TtsBatchService $batchService
   *   The TTS batch service.
   * @param \Drupal\Core\Extension\ModuleExtensionList $moduleExtensionList
   *   The module extension list service.
   */
  public function __construct(
    private readonly TtsService $ttsService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly TtsBatchService $batchService,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {
    parent::__construct();
  }

  /**
   * Test TTS generation by speaking "Hello World" (streaming mode).
   *
   * @param string $text
   *   The text to speak (default: "Hello World").
   * @param array $options
   *   The command options.
   *
   * @command local-tts:test
   * @aliases tts-test
   * @option voice The voice to use (e.g., af_sky, am_adam).
   * @option speed The speech speed (0.5 to 2.0).
   * @option language The language code for proper pronunciation (e.g., en, es, ja).
   * @option no-play Skip automatic audio playback.
   * @usage local-tts:test
   *   Generate and play "Hello World" using default settings.
   * @usage local-tts:test "Welcome to Drupal" --voice=am_adam --speed=1.2
   *   Generate and play speech with custom text, voice, and speed.
   * @usage local-tts:test "Hola mundo" --voice=ef_dora --language=es
   *   Generate Spanish speech with proper Spanish pronunciation.
   * @usage local-tts:test "Hello World" --no-play
   *   Generate speech without automatic playback.
   */
  #[CLI\Command(name: 'local-tts:test', aliases: ['tts-test'])]
  #[CLI\Argument(name: 'text', description: 'The text to speak')]
  #[CLI\Option(name: 'voice', description: 'The voice to use')]
  #[CLI\Option(name: 'speed', description: 'The speech speed (0.5 to 2.0)')]
  #[CLI\Option(name: 'language', description: 'The language code for proper pronunciation (e.g., en, es, ja)')]
  #[CLI\Option(name: 'no-play', description: 'Skip automatic audio playback')]
  #[CLI\Usage(name: 'local-tts:test', description: 'Generate and play "Hello World" using default settings')]
  #[CLI\Usage(name: 'local-tts:test "Welcome to Drupal" --voice=am_adam --speed=1.2', description: 'Generate and play speech with custom text, voice, and speed')]
  #[CLI\Usage(name: 'local-tts:test "Hola mundo" --voice=ef_dora --language=es', description: 'Generate Spanish speech with proper Spanish pronunciation')]
  #[CLI\Usage(name: 'local-tts:test "Hello World" --no-play', description: 'Generate speech without automatic playback')]
  public function test(
    string $text = 'Hello World',
    array $options = [
      'voice' => NULL,
      'speed' => NULL,
      'language' => NULL,
      'no-play' => FALSE,
    ],
  ): void {
    $this->output()->writeln('🔊 Testing Local TTS (streaming mode)...');

    $config = $this->configFactory->get('local_tts.settings');

    // Get configuration.
    $language = $options['language'] ?? 'en';
    $voice = $options['voice'] ?? $this->getDefaultVoiceForLanguage($language);
    $speed = $options['speed'] ?? $config->get('default_speed') ?? 1.0;

    // Display settings.
    $this->output()->writeln("Text: $text");
    $this->output()->writeln("Voice: $voice");
    $this->output()->writeln("Speed: $speed");
    $this->output()->writeln("Language: $language");
    $this->output()->writeln('');

    if ($options['no-play']) {
      $this->output()->writeln('⚠️  Streaming mode requires audio playback.');
      $this->output()->writeln('Use the web interface to generate cached audio files.');
      return;
    }

    // Use streaming mode for instant playback.
    $this->playStreamingAudio($text, $voice, $speed, $language);
  }

  /**
   * Play audio using streaming mode for instant playback.
   *
   * @param string $text
   *   The text to speak.
   * @param string $voice
   *   The voice to use.
   * @param float $speed
   *   The speech speed.
   * @param string $language
   *   The language code.
   */
  protected function playStreamingAudio(string $text, string $voice, float $speed, string $language): void {
    $config = $this->configFactory->get('local_tts.settings');

    // Use bundled files from module directory.
    $module_path = $this->moduleExtensionList->getPath('local_tts');
    $binary_path = DRUPAL_ROOT . '/' . $module_path . '/bin/koko';
    $model_path = DRUPAL_ROOT . '/' . $module_path . '/data/kokoro-v1.0.onnx';
    $data_path = DRUPAL_ROOT . '/' . $module_path . '/data/voices-v1.0.bin';

    if (!file_exists($binary_path) || !is_executable($binary_path)) {
      $this->logger()->error('Koko binary not found or not executable at: @path', ['@path' => $binary_path]);
      return;
    }

    // Map language to espeak format.
    $espeak_lang = $this->mapLanguageToEspeak($language);

    // Detect platform and get audio player.
    $os = PHP_OS_FAMILY;
    $player_command = NULL;
    $player_name = NULL;

    $use_temp_file = FALSE;

    if ($os === 'Darwin') {
      // Afplay doesn't support stdin - need temp file.
      $use_temp_file = TRUE;
      $player_command = 'afplay';
      $player_name = 'afplay (macOS)';
    }
    elseif ($os === 'Linux') {
      // Try common Linux audio players.
      if ($this->commandExists('paplay')) {
        $player_command = 'paplay -';
        $player_name = 'paplay (PulseAudio)';
      }
      elseif ($this->commandExists('aplay')) {
        $player_command = 'aplay -';
        $player_name = 'aplay (ALSA)';
      }
      elseif ($this->commandExists('ffplay')) {
        $player_command = 'ffplay -nodisp -autoexit -';
        $player_name = 'ffplay (FFmpeg)';
      }
    }

    if (!$player_command) {
      $this->output()->writeln('⚠️  No compatible audio player found for streaming mode.');
      $this->output()->writeln('Please install: afplay (macOS), paplay/aplay (Linux), or ffplay');
      return;
    }

    $this->output()->writeln("🎵 Streaming audio using $player_name...");
    $this->output()->writeln('⚡ Time-to-first-audio: ~1-2 seconds');
    $this->output()->writeln('');

    // Set up environment variable for espeak-ng data.
    $espeak_data_path = $config->get('espeak_data_path');
    $env_prefix = '';
    if ($espeak_data_path) {
      $espeak_parent = dirname($espeak_data_path);
      $env_prefix = 'PIPER_ESPEAKNG_DATA_DIRECTORY=' . escapeshellarg($espeak_parent) . ' ';
    }

    if ($use_temp_file) {
      // macOS: Use text command instead of stream to avoid log
      // pollution in audio output.
      $temp_file = tempnam(sys_get_temp_dir(), 'tts_') . '.wav';

      $command = sprintf(
        '%s%s --lan %s --model %s --data %s --style %s --speed %s text %s --output %s 2>/dev/null',
        $env_prefix,
        escapeshellarg($binary_path),
        escapeshellarg($espeak_lang),
        escapeshellarg($model_path),
        escapeshellarg($data_path),
        escapeshellarg($voice),
        escapeshellarg((string) $speed),
        escapeshellarg($text),
        escapeshellarg($temp_file)
      );

      exec($command, $output, $return_code);

      if ($return_code === 0 && file_exists($temp_file) && filesize($temp_file) > 0) {
        exec(escapeshellarg($player_command) . ' ' . escapeshellarg($temp_file), $play_output, $play_return);
        @unlink($temp_file);
        $return_code = $play_return;
      }
      elseif ($return_code !== 0) {
        $this->logger()->error('TTS generation failed: ' . implode("\n", $output));
      }
      elseif (!file_exists($temp_file) || filesize($temp_file) === 0) {
        $this->logger()->error('TTS temp file not created or empty at: ' . $temp_file);
      }
    }
    else {
      // Linux: Stream to player.
      $command = sprintf(
        'echo %s | %s%s --lan %s --model %s --data %s --style %s --speed %s stream 2>/dev/null | %s',
        escapeshellarg($text),
        $env_prefix,
        escapeshellarg($binary_path),
        escapeshellarg($espeak_lang),
        escapeshellarg($model_path),
        escapeshellarg($data_path),
        escapeshellarg($voice),
        escapeshellarg((string) $speed),
        $player_command
      );

      exec($command, $output, $return_code);
    }

    if ($return_code !== 0) {
      $this->logger()->warning("Streaming playback failed with code $return_code");
      if (!empty($output)) {
        $this->logger()->debug('Error: ' . implode("\n", $output));
      }
    }
    else {
      $this->output()->writeln('✅ Playback complete!');
    }
  }

  /**
   * Map Drupal language code to espeak-ng language identifier.
   *
   * @param string $langcode
   *   Drupal language code (e.g., 'en', 'es', 'ja').
   *
   * @return string
   *   espeak-ng language identifier.
   */
  protected function mapLanguageToEspeak(string $langcode): string {
    return $this->ttsService->mapLanguageToEspeak($langcode);
  }

  /**
   * Attempt to play an audio file using platform-specific players.
   *
   * @param string $file_path
   *   The absolute path to the audio file.
   */
  protected function playAudio(string $file_path): void {
    $os = PHP_OS_FAMILY;
    $player_command = NULL;
    $player_name = NULL;

    // Detect platform and choose appropriate audio player.
    if ($os === 'Darwin') {
      // macOS.
      $player_command = 'afplay';
      $player_name = 'afplay (macOS)';
    }
    elseif ($os === 'Linux') {
      // Try common Linux audio players in order of preference.
      $linux_players = [
        'paplay' => 'paplay (PulseAudio)',
        'aplay' => 'aplay (ALSA)',
        'ffplay' => 'ffplay (FFmpeg)',
      ];

      foreach ($linux_players as $cmd => $name) {
        if ($this->commandExists($cmd)) {
          $player_command = $cmd;
          $player_name = $name;
          break;
        }
      }
    }
    elseif ($os === 'Windows') {
      // Windows - use PowerShell.
      $player_command = 'powershell -c (New-Object Media.SoundPlayer';
      $player_name = 'PowerShell SoundPlayer';
    }

    if ($player_command) {
      $this->output()->writeln("🎵 Playing audio using $player_name...");

      // Build the command based on OS.
      if ($os === 'Windows') {
        $command = "powershell -c \"(New-Object Media.SoundPlayer '$file_path').PlaySync();\"";
      }
      else {
        $command = escapeshellcmd($player_command) . ' ' . escapeshellarg($file_path);
      }

      // Execute the playback command.
      $output = [];
      $return_code = 0;
      exec($command . ' 2>&1', $output, $return_code);

      if ($return_code !== 0) {
        $this->logger()->warning("Audio playback failed. You can play manually with: $player_command $file_path");
        if (!empty($output)) {
          $this->logger()->debug('Playback error: ' . implode("\n", $output));
        }
      }
      else {
        $this->output()->writeln('✅ Playback complete!');
      }
    }
    else {
      $this->output()->writeln('⚠️  No audio player found on this system.');
      $this->output()->writeln('To play manually:');
      $this->output()->writeln("  afplay $file_path  (macOS)");
      $this->output()->writeln("  aplay $file_path   (Linux)");
      $this->output()->writeln("  or open the file in your browser");
    }
  }

  /**
   * Check if a command exists on the system.
   *
   * @param string $command
   *   The command to check.
   *
   * @return bool
   *   TRUE if the command exists, FALSE otherwise.
   */
  protected function commandExists(string $command): bool {
    $os = PHP_OS_FAMILY;

    if ($os === 'Windows') {
      $check = "where $command";
    }
    else {
      $check = "command -v $command";
    }

    exec($check . ' 2>&1', $output, $return_code);
    return $return_code === 0;
  }

  /**
   * List available TTS voices.
   *
   * @param array $options
   *   The command options.
   *
   * @command local-tts:voices
   * @aliases tts-voices
   * @option language Filter voices by language code (e.g., en, es, ja).
   * @usage local-tts:voices
   *   Display all available voices.
   * @usage local-tts:voices --language=es
   *   Display only Spanish voices.
   * @usage local-tts:voices --language=ja
   *   Display only Japanese voices.
   */
  #[CLI\Command(name: 'local-tts:voices', aliases: ['tts-voices'])]
  #[CLI\Option(name: 'language', description: 'Filter voices by language code (e.g., en, es, ja)')]
  #[CLI\Usage(name: 'local-tts:voices', description: 'Display all available voices')]
  #[CLI\Usage(name: 'local-tts:voices --language=es', description: 'Display only Spanish voices')]
  #[CLI\Usage(name: 'local-tts:voices --language=ja', description: 'Display only Japanese voices')]
  public function listVoices(array $options = ['language' => NULL]): void {
    $langcode = $options['language'] ?? NULL;

    if ($langcode) {
      $this->output()->writeln("Available TTS Voices for language '$langcode':");
    }
    else {
      $this->output()->writeln('Available TTS Voices:');
    }
    $this->output()->writeln('');

    $voices = $this->ttsService->getAvailableVoices($langcode);

    if (empty($voices)) {
      $this->output()->writeln("  No voices available for language '$langcode'.");
      $this->output()->writeln('');
      $supported_languages = $this->ttsService->getSupportedLanguages();
      $this->output()->writeln('Supported languages: ' . implode(', ', $supported_languages));
      return;
    }

    foreach ($voices as $voice_code => $voice_name) {
      $this->output()->writeln("  • $voice_code - $voice_name");
    }

    $this->output()->writeln('');
    $this->output()->writeln(sprintf('Total: %d voices', count($voices)));
    $this->output()->writeln('Use these voices with: drush local-tts:test "Your text" --voice=VOICE_CODE');
  }

  /**
   * Generate and play TTS audio for an entity (generates cached files).
   *
   * @param string $entity_type
   *   The entity type (e.g., node, taxonomy_term).
   * @param string $entity_id
   *   The entity ID.
   * @param array $options
   *   The command options.
   *
   * @command local-tts:read
   * @aliases tts-read
   * @option voice The voice to use (e.g., af_sky, am_adam).
   * @option speed The speech speed (0.5 to 2.0).
   * @option language The language code for proper pronunciation (e.g., en, es, ja).
   * @option field The field to read (default: rendered entity text).
   * @option stream Use streaming mode instead of cached files.
   * @usage local-tts:read node 123
   *   Generate and play TTS for node 123 (caches the audio file).
   * @usage local-tts:read node 123 --voice=am_adam
   *   Use a specific voice for the content.
   * @usage local-tts:read node 123 --field=field_summary
   *   Read a specific field instead of the body.
   * @usage local-tts:read node 123 --stream
   *   Use streaming mode for instant playback (no caching).
   */
  #[CLI\Command(name: 'local-tts:read', aliases: ['tts-read'])]
  #[CLI\Argument(name: 'entity_type', description: 'The entity type (e.g., node)')]
  #[CLI\Argument(name: 'entity_id', description: 'The entity ID')]
  #[CLI\Option(name: 'voice', description: 'The voice to use')]
  #[CLI\Option(name: 'speed', description: 'The speech speed (0.5 to 2.0)')]
  #[CLI\Option(name: 'language', description: 'The language code')]
  #[CLI\Option(name: 'field', description: 'The field to read (default: rendered entity text)')]
  #[CLI\Option(name: 'stream', description: 'Use streaming mode instead of cached files')]
  #[CLI\Usage(name: 'local-tts:read node 123', description: 'Generate and play TTS for node 123')]
  #[CLI\Usage(name: 'local-tts:read node 123 --voice=am_adam', description: 'Use a specific voice')]
  #[CLI\Usage(name: 'local-tts:read node 123 --field=field_summary', description: 'Read a specific field')]
  #[CLI\Usage(name: 'local-tts:read node 123 --stream', description: 'Use streaming mode')]
  public function readEntity(
    string $entity_type,
    string $entity_id,
    array $options = [
      'voice' => NULL,
      'speed' => NULL,
      'language' => NULL,
      'field' => NULL,
      'stream' => FALSE,
    ],
  ): void {
    // Load the entity.
    try {
      $entity_storage = $this->entityTypeManager->getStorage($entity_type);
      $entity = $entity_storage->load($entity_id);
    }
    catch (\Exception $e) {
      $this->logger()->error('Invalid entity type: @type', ['@type' => $entity_type]);
      return;
    }

    if (!$entity) {
      $this->logger()->error('Entity not found: @type:@id', [
        '@type' => $entity_type,
        '@id' => $entity_id,
      ]);
      return;
    }

    // Get entity label for display.
    $entity_label = $entity->label() ?? "ID $entity_id";
    $this->output()->writeln("📄 Reading: $entity_label ($entity_type:$entity_id)");
    $this->output()->writeln('');

    // Extract text from entity.
    $text = $this->extractTextFromEntity($entity, $options['field']);

    if (empty($text)) {
      $this->logger()->error('No text content found in entity.');
      $this->output()->writeln('💡 Try specifying a field with --field=field_name');
      return;
    }

    // Truncate text for display.
    $text_preview = mb_substr($text, 0, 200) . (mb_strlen($text) > 200 ? '...' : '');
    $this->output()->writeln("Text: $text_preview");
    $this->output()->writeln("Length: " . mb_strlen($text) . " characters");
    $this->output()->writeln('');

    // Get language from entity if available.
    $entity_language = NULL;
    if (method_exists($entity, 'language')) {
      $entity_language = $entity->language()->getId();
    }

    $config = $this->configFactory->get('local_tts.settings');
    $language = $options['language'] ?? $entity_language ?? 'en';
    $voice = $options['voice'] ?? $this->getDefaultVoiceForLanguage($language);
    $speed = $options['speed'] ?? $config->get('default_speed') ?? 1.0;

    $this->output()->writeln("Voice: $voice");
    $this->output()->writeln("Speed: $speed");
    $this->output()->writeln("Language: $language");
    $this->output()->writeln('');

    // Use streaming mode or cached file mode.
    if ($options['stream']) {
      $this->output()->writeln('🎵 Using streaming mode (no caching)...');
      $this->playStreamingAudio($text, $voice, $speed, $language);
    }
    else {
      $this->output()->writeln('💾 Generating cached audio file...');

      // Generate speech using TTS service (creates cached file).
      $tts_options = [
        'voice' => $voice,
        'speed' => (float) $speed,
        'language' => $language,
        'entity_type' => $entity_type,
        'entity_id' => $entity_id,
      ];

      try {
        $audio_uri = $this->ttsService->generateSpeech($text, $tts_options);

        if ($audio_uri) {
          $real_path = $this->fileSystem->realpath($audio_uri);
          $this->output()->writeln("✅ Audio cached at: $audio_uri");
          $this->output()->writeln('');
          $this->output()->writeln('🎵 Playing cached audio...');
          $this->playAudio($real_path);
        }
        else {
          $this->logger()->error('Failed to generate speech.');
        }
      }
      catch (\RuntimeException $e) {
        if (strpos($e->getMessage(), 'private content') !== FALSE) {
          $this->output()->writeln('');
          $this->output()->writeln('[error] ' . $e->getMessage());
          $this->output()->writeln('Solution: Use --stream flag to play without caching, or publish the content.');
          return;
        }
        $this->logger()->error('Error generating speech: @message', ['@message' => $e->getMessage()]);
      }
      catch (\Exception $e) {
        $this->logger()->error('Error generating speech: @message', ['@message' => $e->getMessage()]);
      }
    }
  }

  /**
   * Extract text content from an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to extract text from.
   * @param string|null $field_name
   *   Optional specific field name to extract.
   *
   * @return string
   *   The extracted text content.
   */
  protected function extractTextFromEntity($entity, $field_name = NULL): string {
    // If specific field requested, try to get it.
    if ($field_name && $entity->hasField($field_name)) {
      return $this->extractFieldText($entity, $field_name);
    }

    // Use the same render-based extraction as the player, so the cached
    // file and text hash written here are the ones the player serves.
    return $this->ttsService->extractTextFromEntity($entity);
  }

  /**
   * Extract text from a specific field.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   * @param string $field_name
   *   The field name.
   *
   * @return string
   *   The extracted text.
   */
  protected function extractFieldText($entity, $field_name): string {
    if (!$entity->hasField($field_name)) {
      return '';
    }

    $field = $entity->get($field_name);

    if ($field->isEmpty()) {
      return '';
    }

    $text_parts = [];

    // Handle different field types.
    foreach ($field as $item) {
      // Text fields with format (like body).
      if (isset($item->value)) {
        $text = $item->value;

        // Strip HTML tags for formatted text.
        if (isset($item->format)) {
          $text = strip_tags($text);
        }

        $text_parts[] = $text;
      }
      // Plain string fields.
      elseif (is_string($item->value)) {
        $text_parts[] = $item->value;
      }
      // Entity reference fields - get labels.
      elseif (method_exists($item, 'entity') && $item->entity) {
        $text_parts[] = $item->entity->label();
      }
    }

    return implode(' ', $text_parts);
  }

  /**
   * Clear the TTS audio cache.
   *
   * @command local-tts:cache-clear
   * @aliases tts-cc
   * @usage local-tts:cache-clear
   *   Clear all cached audio files.
   */
  #[CLI\Command(name: 'local-tts:cache-clear', aliases: ['tts-cc'])]
  #[CLI\Usage(name: 'local-tts:cache-clear', description: 'Clear all cached audio files')]
  public function cacheClear(): void {
    $this->output()->writeln('Clearing TTS audio cache...');

    if ($this->ttsService->clearCache()) {
      $this->output()->writeln('✅ Cache cleared successfully.');
    }
    else {
      $this->logger()->error('Failed to clear cache.');
    }
  }

  /**
   * Batch generate TTS audio for multiple entities.
   *
   * @param array $options
   *   The command options.
   *
   * @command local-tts:batch
   * @aliases tts-batch
   * @option entity-type Entity type to process (e.g., node, taxonomy_term).
   * @option bundle Bundle to process (e.g., article, page).
   * @option language Language code(s), comma-separated (e.g., en,es).
   * @option limit Maximum number of entities to process (0 for no limit).
   * @option force Force regeneration even if cached.
   * @option updated-after Only process entities updated after this date (Y-m-d format).
   * @usage local-tts:batch --entity-type=node --bundle=article --language=en --limit=10
   *   Generate TTS for 10 English articles.
   * @usage local-tts:batch --entity-type=node --bundle=page --language=en,es --force
   *   Regenerate TTS for all pages in English and Spanish.
   */
  #[CLI\Command(name: 'local-tts:batch', aliases: ['tts-batch'])]
  #[CLI\Option(name: 'entity-type', description: 'Entity type to process')]
  #[CLI\Option(name: 'bundle', description: 'Bundle to process')]
  #[CLI\Option(name: 'language', description: 'Language code(s), comma-separated')]
  #[CLI\Option(name: 'limit', description: 'Maximum number of entities (0 for no limit)')]
  #[CLI\Option(name: 'force', description: 'Force regeneration even if cached')]
  #[CLI\Option(name: 'updated-after', description: 'Only process entities updated after this date (format: 2025-01-01)')]
  #[CLI\Option(name: 'dry-run', description: 'Show count of entities to process without generating audio')]
  #[CLI\Usage(name: 'local-tts:batch --entity-type=node --bundle=article --language=en --limit=10', description: 'Generate TTS for 10 English articles')]
  #[CLI\Usage(name: 'local-tts:batch --entity-type=node --bundle=page --language=en,es --force', description: 'Regenerate TTS for all pages in English and Spanish')]
  public function batchGenerate(
    array $options = [
      'entity-type' => NULL,
      'bundle' => NULL,
      'language' => 'en',
      'limit' => 0,
      'force' => FALSE,
      'updated-after' => NULL,
      'dry-run' => FALSE,
    ],
  ): void {
    // Validate required options.
    if (empty($options['entity-type']) || empty($options['bundle'])) {
      $this->logger()->error('Both --entity-type and --bundle are required.');
      return;
    }

    // Parse languages.
    $languages = array_map('trim', explode(',', $options['language']));

    // Build entity bundle string.
    $entity_bundle = $options['entity-type'] . ':' . $options['bundle'];

    // Get entities to process.
    $entities = $this->batchService->getEntitiesForGeneration(
      [$entity_bundle],
      $languages,
      (bool) $options['force'],
      (int) $options['limit'],
      $options['updated-after']
    );

    // If dry-run, just show count and exit.
    if ($options['dry-run']) {
      $count = count($entities);
      $this->output()->writeln("Entities to process: $count");
      return;
    }

    $this->output()->writeln('');
    $this->output()->writeln('=== TTS Batch Generation ===');
    $this->output()->writeln("Entity type: {$options['entity-type']}");
    $this->output()->writeln("Bundle: {$options['bundle']}");
    $this->output()->writeln('Languages: ' . implode(', ', $languages));
    $this->output()->writeln('Limit: ' . ($options['limit'] ? $options['limit'] : 'No limit'));
    $this->output()->writeln('Force refresh: ' . ($options['force'] ? 'Yes' : 'No'));
    if ($options['updated-after']) {
      $this->output()->writeln("Updated after: {$options['updated-after']}");
    }
    $this->output()->writeln('');

    if (empty($entities)) {
      $this->output()->writeln('No entities found to process.');
      return;
    }

    $total = count($entities);
    $this->output()->writeln("Found $total entities to process.");
    $this->output()->writeln('');

    // Process each entity.
    $processed = 0;
    $generated = 0;
    $cached = 0;
    $errors = [];

    foreach ($entities as $entity_data) {
      $processed++;

      try {
        // Load entity.
        $entity = $this->entityTypeManager
          ->getStorage($entity_data['entity_type'])
          ->load($entity_data['entity_id']);

        if (!$entity) {
          $errors[] = "Entity {$entity_data['entity_type']}:{$entity_data['entity_id']} not found.";
          continue;
        }

        // Get translation.
        $langcode = $entity_data['langcode'] ?? $entity->language()->getId();
        if ($entity->hasTranslation($langcode)) {
          $entity = $entity->getTranslation($langcode);
        }

        // Extract text.
        $text = $this->extractTextFromEntity($entity, NULL);

        if (empty($text)) {
          $errors[] = "No text content for {$entity_data['entity_type']}:{$entity_data['entity_id']}.";
          continue;
        }

        // Generate TTS.
        $this->output()->write("[$processed/$total] Processing {$entity_data['entity_type']}:{$entity_data['entity_id']} ($langcode)... ");

        $config = $this->configFactory->get('local_tts.settings');
        $default_voice = $this->getDefaultVoiceForLanguage($langcode);

        $tts_options = [
          'language' => $langcode,
          'voice' => $default_voice,
          'speed' => $config->get('default_speed') ?? 1.0,
          'entity_type' => $entity_data['entity_type'],
          'entity_id' => $entity_data['entity_id'],
          'use_cache' => TRUE,
          'force_refresh' => (bool) $options['force'],
          'skip_access_check' => FALSE,
        ];

        $audio_uri = $this->ttsService->generateSpeech($text, $tts_options);

        if ($audio_uri) {
          $generated++;
          $this->output()->writeln('Generated');
        }
        else {
          $cached++;
          $this->output()->writeln('Cached');
        }
      }
      catch (\Exception $e) {
        $this->output()->writeln('ERROR');
        $errors[] = "{$entity_data['entity_type']}:{$entity_data['entity_id']} - " . $e->getMessage();
      }
    }

    // Summary.
    $this->output()->writeln('');
    $this->output()->writeln('=== Summary ===');
    $this->output()->writeln("Processed: $processed");
    $this->output()->writeln("Generated: $generated");
    $this->output()->writeln("Cached: $cached");
    $this->output()->writeln('Errors: ' . count($errors));

    if (!empty($errors)) {
      $this->output()->writeln('');
      $this->output()->writeln('Errors:');
      foreach ($errors as $error) {
        $this->output()->writeln("  - $error");
      }
    }
  }

  /**
   * Get the default voice for a given language.
   *
   * @param string $langcode
   *   The language code.
   *
   * @return string
   *   The default voice code for the language.
   */
  protected function getDefaultVoiceForLanguage(string $langcode): string {
    $config = $this->configFactory->get('local_tts.settings');
    $default_voices = $config->get('default_voices') ?? [];

    // Check language-specific default first.
    if (isset($default_voices[$langcode])) {
      return $default_voices[$langcode];
    }

    // Fall back to first available voice for this language.
    $available_voices = $this->ttsService->getAvailableVoices($langcode);
    if (!empty($available_voices)) {
      return array_key_first($available_voices);
    }

    // Final fallback to af_sky.
    return 'af_sky';
  }

}
