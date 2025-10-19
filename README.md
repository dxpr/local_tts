# AI Text-to-Speech Module

A Drupal module that provides high-quality AI-powered text-to-speech using the Kokoro TTS engine (Rust implementation).

## Features

- High-quality AI voices using Kokoro-82M model
- Multiple voice options (male/female, accents)
- Adjustable speech speed
- Audio caching to reduce server load
- Simple block-based integration
- AJAX-powered audio generation
- Drush commands for testing and cache management

## Requirements

### Drupal

- Drupal 9, 10, or 11
- Node module (core)

### External Dependencies

**Kokoro Binary (koko)**

This module requires the Kokoro Rust binary to be installed on the server.

**Installation Options:**

1. **From Source** (requires Rust toolchain):
   ```bash
   git clone https://github.com/lucasjinreal/Kokoros.git
   cd Kokoros
   bash download_all.sh
   cargo build --release
   sudo cp target/release/koko /usr/local/bin/
   ```

2. **Docker** (for testing):
   ```bash
   docker pull ghcr.io/lucasjinreal/kokoros:latest
   ```

3. **Pre-built Binaries** (if available):
   Check the [Kokoros releases page](https://github.com/lucasjinreal/Kokoros/releases)

## Installation

1. Place this module in `modules/custom/ai_tts`

2. Install the Kokoro binary (see requirements above)

3. Enable the module:
   ```bash
   drush en ai_tts
   ```

4. Configure the module:
   - Go to: Administration > Configuration > Media > AI TTS
   - Set the path to your koko binary (e.g., `/usr/local/bin/koko`)
   - Choose default voice and speed
   - Configure caching options

## Usage

### Add TTS to Your Pages

1. Go to: Structure > Block layout
2. Click "Place block" in your desired region
3. Search for "AI Text-to-Speech"
4. Configure the block:
   - Set button text
   - Choose whether to show voice selector
   - Choose whether to show speed control
   - Set CSS selector for content (default: `article .field--name-body`)
5. Save the block configuration

### Available Voices

The module supports multiple Kokoro voices:

**American English:**
- `af_sky` - Female (Sky)
- `af_nicole` - Female (Nicole)
- `af_heart` - Female (Heart)
- `af_bella` - Female (Bella)
- `af_sarah` - Female (Sarah)
- `am_adam` - Male (Adam)
- `am_michael` - Male (Michael)

**British English:**
- `bf_emma` - Female (Emma)
- `bf_isabella` - Female (Isabella)
- `bm_george` - Male (George)
- `bm_lewis` - Male (Lewis)

### Drush Commands

The module provides convenient Drush commands for testing and managing TTS functionality.

#### Test TTS Generation

Generate speech and automatically play it back:

```bash
# Basic test with "Hello World" using default settings
# Audio will play automatically
drush ai-tts:test
drush tts-test  # Short alias

# Custom text with automatic playback
drush ai-tts:test "Welcome to Drupal 11"

# With custom voice and speed
drush ai-tts:test "Your text here" --voice=am_adam --speed=1.2

# Female British voice at slower speed
drush ai-tts:test "Good morning" --voice=bf_emma --speed=0.8

# Generate without automatic playback
drush ai-tts:test "Hello World" --no-play
```

**Available Options:**
- `--voice` - Voice to use (see available voices below)
- `--speed` - Speech speed from 0.5 to 2.0 (default: 1.0)
- `--no-play` - Skip automatic playback (just generate the audio file)

**Platform Support:**
The command automatically detects your operating system and uses the appropriate audio player:
- **macOS**: `afplay`
- **Linux**: `paplay` (PulseAudio), `aplay` (ALSA), or `ffplay` (FFmpeg)
- **Windows**: PowerShell SoundPlayer

#### List Available Voices

Display all available TTS voices:

```bash
drush ai-tts:voices
drush tts-voices  # Short alias
```

This shows all 11 voices with their identifiers.

#### Clear Audio Cache

Remove all cached audio files:

```bash
drush ai-tts:cache-clear
drush tts-cc  # Short alias
```

Useful when you want to regenerate audio or free up disk space.

#### Manual Audio Playback

If you used `--no-play` or want to replay generated audio:

```bash
# macOS
afplay /path/to/generated/audio.wav

# Linux (ALSA)
aplay /path/to/generated/audio.wav

# Linux (PulseAudio)
paplay /path/to/generated/audio.wav
```

The test command outputs the full path when using `--no-play`.

## Architecture

### Components

**PHP Components:**
- `TtsService` - Core service for interfacing with koko binary
- `TtsController` - AJAX endpoint for generating audio
- `AiTtsSettingsForm` - Admin configuration form
- `AiTtsBlock` - Block plugin for rendering the player
- `AiTtsCommands` - Drush commands for testing and cache management

**Frontend:**
- `ai-tts-player.js` - JavaScript for audio playback control
- `ai-tts-player.html.twig` - Template for player interface

### How It Works

1. User clicks "Listen" button on a page
2. JavaScript extracts text content from the configured CSS selector
3. AJAX request sent to `/ai-tts/generate` with text, voice, and speed
4. `TtsService` calls koko binary: `koko text "..." --voice af_sky --output /path/to/file.wav`
5. Generated audio file is cached (if caching enabled)
6. Audio URL returned to frontend
7. HTML5 `<audio>` element plays the generated speech

### Caching

Audio files are cached by default in `public://ai-tts/` directory. Cache key is based on:
- Text content (MD5 hash)
- Voice selection
- Speed setting

This prevents regenerating the same audio multiple times.

## Configuration Options

### Global Settings (Admin Form)

- **Koko Binary Path** - Absolute path to koko executable
- **Default Voice** - Voice to use when none specified
- **Default Speed** - Speech speed (0.5 - 2.0)
- **Cache Audio** - Enable/disable audio file caching
- **Audio Directory** - Where to store cached audio files

### Block Settings

- **Button Text** - Text for the play button
- **Show Voice Selector** - Allow users to choose voice
- **Show Speed Control** - Allow users to adjust speed
- **Content Selector** - CSS selector for content to read

## Troubleshooting

### Binary not found or not executable

**Error:** "Binary not found or not executable at: /usr/local/bin/koko"

**Solutions:**
1. Verify the binary exists: `ls -la /usr/local/bin/koko`
2. Make it executable: `chmod +x /usr/local/bin/koko`
3. Check the path in module configuration

### Permission denied when generating audio

**Error:** Audio files cannot be created

**Solutions:**
1. Ensure the web server has write permissions to the audio directory
2. Check: `ls -la sites/default/files/ai-tts/`
3. Fix permissions: `chmod 755 sites/default/files/ai-tts/`

### Audio generation is slow

**Solutions:**
1. Enable caching in module settings
2. Consider using a faster server/CPU
3. Limit text length in block configuration
4. Pre-generate common audio files

### Drush commands not found

**Error:** "Command ai-tts:test was not found"

**Solutions:**
1. Clear Drupal cache: `drush cr`
2. Verify the module is enabled: `drush pm:list | grep ai_tts`
3. Check that Drush can find the commands: `drush list | grep tts`
4. Ensure `drush.services.yml` exists in the module directory

## Known Limitations

- Text content is truncated to 5000 characters
- No progress indicator during generation
- No batch generation for multiple elements
- Limited error handling
- No voice style mixing (advanced Kokoro feature)
- No streaming playback (audio generates fully before playing)

## Future Enhancements

- [ ] Progress indicator during audio generation
- [ ] Batch generation for multiple content blocks
- [ ] Voice style mixing support
- [ ] Streaming playback
- [ ] User preferences storage
- [ ] Text highlighting during playback
- [ ] Support for SSML markup
- [ ] Admin UI for testing voices
- [x] Drush commands for cache management
- [ ] Better error messages for end users

## License

This module is licensed under GPL-2.0+

**Note:** The Kokoro TTS engine is licensed under Apache License 2.0 (check the [Kokoros repository](https://github.com/lucasjinreal/Kokoros) for current license status).

## Credits

- Kokoro TTS by [lucasjinreal](https://github.com/lucasjinreal)
- Based on the [Kokoro-82M model](https://huggingface.co/hexgrad/Kokoro-82M) by hexgrad

## Support

For issues related to:
- **This Drupal module** - File an issue in your project repository
- **Kokoro TTS binary** - See [Kokoros GitHub](https://github.com/lucasjinreal/Kokoros)
