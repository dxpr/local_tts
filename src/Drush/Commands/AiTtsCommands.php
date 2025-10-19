<?php

namespace Drupal\ai_tts\Drush\Commands;

use Drupal\ai_tts\TtsService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for AI TTS module.
 */
final class AiTtsCommands extends DrushCommands {

  /**
   * Constructs an AiTtsCommands object.
   *
   * @param \Drupal\ai_tts\TtsService $ttsService
   *   The TTS service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(
    private readonly TtsService $ttsService,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
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
   * @command ai-tts:test
   * @aliases tts-test
   * @option voice The voice to use (e.g., af_sky, am_adam).
   * @option speed The speech speed (0.5 to 2.0).
   * @option language The language code for proper pronunciation (e.g., en, es, ja).
   * @option no-play Skip automatic audio playback.
   * @usage ai-tts:test
   *   Generate and play "Hello World" using default settings.
   * @usage ai-tts:test "Welcome to Drupal" --voice=am_adam --speed=1.2
   *   Generate and play speech with custom text, voice, and speed.
   * @usage ai-tts:test "Hola mundo" --voice=ef_dora --language=es
   *   Generate Spanish speech with proper Spanish pronunciation.
   * @usage ai-tts:test "Hello World" --no-play
   *   Generate speech without automatic playback.
   */
  #[CLI\Command(name: 'ai-tts:test', aliases: ['tts-test'])]
  #[CLI\Argument(name: 'text', description: 'The text to speak')]
  #[CLI\Option(name: 'voice', description: 'The voice to use')]
  #[CLI\Option(name: 'speed', description: 'The speech speed (0.5 to 2.0)')]
  #[CLI\Option(name: 'language', description: 'The language code for proper pronunciation (e.g., en, es, ja)')]
  #[CLI\Option(name: 'no-play', description: 'Skip automatic audio playback')]
  #[CLI\Usage(name: 'ai-tts:test', description: 'Generate and play "Hello World" using default settings')]
  #[CLI\Usage(name: 'ai-tts:test "Welcome to Drupal" --voice=am_adam --speed=1.2', description: 'Generate and play speech with custom text, voice, and speed')]
  #[CLI\Usage(name: 'ai-tts:test "Hola mundo" --voice=ef_dora --language=es', description: 'Generate Spanish speech with proper Spanish pronunciation')]
  #[CLI\Usage(name: 'ai-tts:test "Hello World" --no-play', description: 'Generate speech without automatic playback')]
  public function test(
    string $text = 'Hello World',
    array $options = [
      'voice' => NULL,
      'speed' => NULL,
      'language' => NULL,
      'no-play' => FALSE,
    ],
  ): void {
    $this->output()->writeln('🔊 Testing AI TTS (streaming mode)...');

    $config = $this->configFactory->get('ai_tts.settings');

    // Get configuration.
    $voice = $options['voice'] ?? $config->get('default_voice') ?? 'af_sky';
    $speed = $options['speed'] ?? $config->get('default_speed') ?? 1.0;
    $language = $options['language'] ?? $this->ttsService->detectLanguageFromVoice($voice);

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
    $config = $this->configFactory->get('ai_tts.settings');
    $binary_path = $config->get('koko_binary_path');

    if (!file_exists($binary_path) || !is_executable($binary_path)) {
      $this->logger()->error('Koko binary not found or not executable at: @path', ['@path' => $binary_path]);
      return;
    }

    // Get model paths.
    $home_dir = getenv('HOME');
    $model_path = $home_dir . '/.cache/kokoros/checkpoints/kokoro-v1.0.onnx';
    $data_path = $home_dir . '/.cache/kokoros/data/voices-v1.0.bin';

    // Map language to espeak format.
    $espeak_lang = $this->mapLanguageToEspeak($language);

    // Detect platform and get audio player.
    $os = PHP_OS_FAMILY;
    $player_command = NULL;
    $player_name = NULL;

    if ($os === 'Darwin') {
      $player_command = 'afplay -';
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

    // Build streaming command: echo "text" | koko ... stream | player.
    $command = sprintf(
      'echo %s | %s --lan %s --model %s --data %s --style %s --speed %s stream 2>/dev/null | %s',
      escapeshellarg($text),
      escapeshellarg($binary_path),
      escapeshellarg($espeak_lang),
      escapeshellarg($model_path),
      escapeshellarg($data_path),
      escapeshellarg($voice),
      escapeshellarg((string) $speed),
      $player_command
    );

    // Execute streaming playback.
    $output = [];
    $return_code = 0;
    exec($command, $output, $return_code);

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
    $langcode = strtolower($langcode);

    $map = [
      'en' => 'en-us',
      'en-us' => 'en-us',
      'en-gb' => 'en-gb',
      'es' => 'es',
      'fr' => 'fr-fr',
      'hi' => 'hi',
      'it' => 'it',
      'ja' => 'ja',
      'pt' => 'pt-pt',
      'pt-br' => 'pt-br',
      'pt-pt' => 'pt-pt',
      'zh' => 'cmn',
      'zh-hans' => 'cmn',
      'zh-hant' => 'cmn',
      'ko' => 'ko',
    ];

    return $map[$langcode] ?? 'en-us';
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
   * @command ai-tts:voices
   * @aliases tts-voices
   * @option language Filter voices by language code (e.g., en, es, ja).
   * @usage ai-tts:voices
   *   Display all available voices.
   * @usage ai-tts:voices --language=es
   *   Display only Spanish voices.
   * @usage ai-tts:voices --language=ja
   *   Display only Japanese voices.
   */
  #[CLI\Command(name: 'ai-tts:voices', aliases: ['tts-voices'])]
  #[CLI\Option(name: 'language', description: 'Filter voices by language code (e.g., en, es, ja)')]
  #[CLI\Usage(name: 'ai-tts:voices', description: 'Display all available voices')]
  #[CLI\Usage(name: 'ai-tts:voices --language=es', description: 'Display only Spanish voices')]
  #[CLI\Usage(name: 'ai-tts:voices --language=ja', description: 'Display only Japanese voices')]
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
    $this->output()->writeln('Use these voices with: drush ai-tts:test "Your text" --voice=VOICE_CODE');
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
   * @command ai-tts:read
   * @aliases tts-read
   * @option voice The voice to use (e.g., af_sky, am_adam).
   * @option speed The speech speed (0.5 to 2.0).
   * @option language The language code for proper pronunciation (e.g., en, es, ja).
   * @option field The field to read (default: auto-detect body field).
   * @option stream Use streaming mode instead of cached files.
   * @usage ai-tts:read node 123
   *   Generate and play TTS for node 123 (caches the audio file).
   * @usage ai-tts:read node 123 --voice=am_adam
   *   Use a specific voice for the content.
   * @usage ai-tts:read node 123 --field=field_summary
   *   Read a specific field instead of the body.
   * @usage ai-tts:read node 123 --stream
   *   Use streaming mode for instant playback (no caching).
   */
  #[CLI\Command(name: 'ai-tts:read', aliases: ['tts-read'])]
  #[CLI\Argument(name: 'entity_type', description: 'The entity type (e.g., node)')]
  #[CLI\Argument(name: 'entity_id', description: 'The entity ID')]
  #[CLI\Option(name: 'voice', description: 'The voice to use')]
  #[CLI\Option(name: 'speed', description: 'The speech speed (0.5 to 2.0)')]
  #[CLI\Option(name: 'language', description: 'The language code')]
  #[CLI\Option(name: 'field', description: 'The field to read (default: auto-detect)')]
  #[CLI\Option(name: 'stream', description: 'Use streaming mode instead of cached files')]
  #[CLI\Usage(name: 'ai-tts:read node 123', description: 'Generate and play TTS for node 123')]
  #[CLI\Usage(name: 'ai-tts:read node 123 --voice=am_adam', description: 'Use a specific voice')]
  #[CLI\Usage(name: 'ai-tts:read node 123 --field=field_summary', description: 'Read a specific field')]
  #[CLI\Usage(name: 'ai-tts:read node 123 --stream', description: 'Use streaming mode')]
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

    if (!$entity->access('view')) {
      $this->logger()->error('Access denied to entity: @type:@id', [
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

    $config = $this->configFactory->get('ai_tts.settings');
    $voice = $options['voice'] ?? $config->get('default_voice') ?? 'af_sky';
    $speed = $options['speed'] ?? $config->get('default_speed') ?? 1.0;
    $language = $options['language'] ?? $entity_language ?? $this->ttsService->detectLanguageFromVoice($voice);

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

    // Auto-detect common text fields.
    $common_fields = [
      'body',
      'field_body',
      'field_description',
      'field_text',
      'field_content',
      'description',
    ];

    foreach ($common_fields as $field) {
      if ($entity->hasField($field) && !$entity->get($field)->isEmpty()) {
        return $this->extractFieldText($entity, $field);
      }
    }

    // Fallback to entity label.
    return $entity->label() ?? '';
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

    if (!$field->access('view')) {
      return '';
    }

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
   * @command ai-tts:cache-clear
   * @aliases tts-cc
   * @usage ai-tts:cache-clear
   *   Clear all cached audio files.
   */
  #[CLI\Command(name: 'ai-tts:cache-clear', aliases: ['tts-cc'])]
  #[CLI\Usage(name: 'ai-tts:cache-clear', description: 'Clear all cached audio files')]
  public function cacheClear(): void {
    $this->output()->writeln('Clearing TTS audio cache...');

    if ($this->ttsService->clearCache()) {
      $this->output()->writeln('✅ Cache cleared successfully.');
    }
    else {
      $this->logger()->error('Failed to clear cache.');
    }
  }

}
