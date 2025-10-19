<?php

namespace Drupal\ai_tts\Drush\Commands;

use Drupal\ai_tts\TtsService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for AI TTS module.
 */
final class AiTtsCommands extends DrushCommands {

  /**
   * Constructs an AiTtsCommands object.
   *
   * @param \Drupal\ai_tts\TtsService $ttsService
   *   The TTS service.
   */
  public function __construct(
    private readonly TtsService $ttsService,
  ) {
    parent::__construct();
  }

  /**
   * Test TTS generation by speaking "Hello World".
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
   * @option no-play Skip automatic audio playback.
   * @usage ai-tts:test
   *   Generate and play "Hello World" using default settings.
   * @usage ai-tts:test "Welcome to Drupal" --voice=am_adam --speed=1.2
   *   Generate and play speech with custom text, voice, and speed.
   * @usage ai-tts:test "Hello World" --no-play
   *   Generate speech without automatic playback.
   */
  #[CLI\Command(name: 'ai-tts:test', aliases: ['tts-test'])]
  #[CLI\Argument(name: 'text', description: 'The text to speak')]
  #[CLI\Option(name: 'voice', description: 'The voice to use')]
  #[CLI\Option(name: 'speed', description: 'The speech speed (0.5 to 2.0)')]
  #[CLI\Option(name: 'no-play', description: 'Skip automatic audio playback')]
  #[CLI\Usage(name: 'ai-tts:test', description: 'Generate and play "Hello World" using default settings')]
  #[CLI\Usage(name: 'ai-tts:test "Welcome to Drupal" --voice=am_adam --speed=1.2', description: 'Generate and play speech with custom text, voice, and speed')]
  #[CLI\Usage(name: 'ai-tts:test "Hello World" --no-play', description: 'Generate speech without automatic playback')]
  public function test(string $text = 'Hello World', array $options = ['voice' => NULL, 'speed' => NULL, 'no-play' => FALSE]): void {
    $this->output()->writeln('🔊 Testing AI TTS...');

    // Prepare options for TTS service.
    $tts_options = [];
    if ($options['voice']) {
      $tts_options['voice'] = $options['voice'];
    }
    if ($options['speed']) {
      $tts_options['speed'] = (float) $options['speed'];
    }

    // Generate speech.
    $this->output()->writeln("Text: $text");
    if (!empty($tts_options['voice'])) {
      $this->output()->writeln("Voice: {$tts_options['voice']}");
    }
    if (!empty($tts_options['speed'])) {
      $this->output()->writeln("Speed: {$tts_options['speed']}");
    }

    $audio_uri = $this->ttsService->generateSpeech($text, $tts_options);

    if ($audio_uri) {
      // Convert URI to real path.
      $real_path = \Drupal::service('file_system')->realpath($audio_uri);
      $this->output()->writeln("✅ Success! Audio generated at: $audio_uri");

      // Try to play the audio automatically unless --no-play is set.
      if (!$options['no-play']) {
        $this->output()->writeln('');
        $this->playAudio($real_path);
      }
      else {
        $this->output()->writeln('');
        $this->output()->writeln('To play manually:');
        $this->output()->writeln("  afplay $real_path  (macOS)");
        $this->output()->writeln("  aplay $real_path   (Linux)");
        $this->output()->writeln("  or open the file in your browser");
      }
    }
    else {
      $this->logger()->error('Failed to generate speech. Check the AI TTS configuration and Kokoro binary setup.');
    }
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
      // macOS
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
      // Windows - use PowerShell
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
   * @command ai-tts:voices
   * @aliases tts-voices
   * @usage ai-tts:voices
   *   Display all available voices.
   */
  #[CLI\Command(name: 'ai-tts:voices', aliases: ['tts-voices'])]
  #[CLI\Usage(name: 'ai-tts:voices', description: 'Display all available voices')]
  public function listVoices(): void {
    $this->output()->writeln('Available TTS Voices:');
    $this->output()->writeln('');

    $voices = $this->ttsService->getAvailableVoices();
    foreach ($voices as $voice) {
      $this->output()->writeln("  • $voice");
    }

    $this->output()->writeln('');
    $this->output()->writeln('Use these voices with: drush ai-tts:test "Your text" --voice=VOICE_NAME');
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
