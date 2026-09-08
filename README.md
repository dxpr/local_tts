# Local Text-to-Speech

AI-powered text-to-speech for Drupal using the Kokoro TTS engine with 54 voices
across 9 languages.

## Features

- **54 voices across 9 languages** (English, Japanese, Mandarin Chinese, French,
  Hindi, Spanish, Italian, Portuguese)
- **OGG Opus output** for small file sizes and broad browser support
- **Queue-based generation** via Drupal's Queue API; visitors see a progress
  indicator while audio is generated in the background
- **Chunked processing** for long content: text is split at sentence boundaries
  and generated in parallel, then concatenated into a single file
- **Content change detection** via text hash; audio is only regenerated when
  content actually changes
- **Render-based text extraction** captures computed fields, Views output, and
  block field content
- **Playback progress persistence** via localStorage; visitors can resume where
  they left off
- **Voice preview** on the settings page; hear any voice before selecting it
- **Language-aware voice filtering** automatically shows only matching voices
- **Auto-generate on save**: optionally queue TTS generation whenever content is
  created or updated, so audio is ready before the first visitor arrives
- **Content type filtering**: limit TTS to specific content types
- **Download button**: optional download icon in the player controls
- **Batch generation**: generate audio for many entities at once via admin UI or
  Drush, with date filtering and dry-run support
- Adjustable speech speed (0.5x to 2.0x)
- Audio caching with automatic orphan cleanup during cron
- Block and field-based integration options
- Drush commands for testing, batch generation, and cache management
- Accessibility-optimized with ARIA labels and keyboard shortcuts
- **Privacy-first**: only publicly viewable content converted to audio

## Requirements

### Drupal

- Drupal 9, 10, or 11
- Node module (core)

### System Dependencies

**ffmpeg** (required for WAV to OGG Opus transcoding):

```bash
# macOS
brew install ffmpeg

# Ubuntu/Debian
sudo apt-get install ffmpeg
```

**eSpeak NG** (required for text-to-phoneme conversion):

```bash
# macOS
brew install espeak-ng

# Ubuntu/Debian
sudo apt-get install espeak-ng

# Fedora/RHEL
sudo dnf install espeak-ng

# Arch Linux
sudo pacman -S espeak-ng
```

After installation, note the `espeak-ng-data` directory location:
- macOS (Homebrew): `/opt/homebrew/Cellar/espeak-ng/[version]/share/espeak-ng-data`
- Linux: Usually `/usr/share/espeak-ng-data`

## Installation

```bash
# Step 1: Install module dependencies
cd web/modules/custom/local_tts
composer install
```

This automatically downloads:
- Model and voice data files (~337MB) ✅
- **Note:** Binary requires manual build (see below)

### Step 2: Build Kokoro Binary

The Kokoro binary must be built from source as no
pre-built binaries are available:

**Requirements:** Rust/Cargo ([install from https://rustup.rs](https://rustup.rs))

```bash
# Build the binary (~5-10 minutes)
composer run build-binary
```

This will:
1. Clone the Kokoro source
2. Download required model files
3. Build the binary with Cargo
4. Install to `bin/koko`

### Step 3: Enable and Configure

```bash
# Enable the module
drush en local_tts -y

# Configure eSpeak path
# Go to: /admin/config/media/local-tts
# Set the eSpeak NG data path from the Requirements section above

# Test installation
drush local-tts:test "Hello World"
```

### Bundled Files Structure

```
local_tts/
├── bin/
│   ├── .htaccess          # Blocks web access
│   ├── LICENSE            # Apache 2.0
│   └── koko               # Kokoro binary (build from source)
├── data/
│   ├── .htaccess          # Blocks web access
│   ├── LICENSE            # Apache 2.0
│   ├── kokoro-v1.0.onnx   # Model file (auto-downloaded)
│   └── voices-v1.0.bin    # Voice data (auto-downloaded)
```

**Security:** All files protected by `.htaccess`. For Nginx,
add location blocks to deny access to
`local_tts/(bin|data)/`.

### Manual Commands

```bash
# Download model files only
composer run download-models

# Build binary from source
composer run build-binary

# Check if all files are present
composer run check-files
```

## Configuration

Go to: Configuration > Media > Local TTS Settings
(`/admin/config/media/local-tts`)

**Key Settings:**
- **eSpeak NG data path** - Path to espeak-ng-data directory (required)
- **Default voice per language** - Set preferred voices
- **Default speech speed** - Speed multiplier (0.5x to 2.0x)
- **Cache settings** - Enable/disable audio caching
- **Security settings** - Rate limiting, text length limits, timeout

## Usage

### Block Integration

Quick setup for site-wide TTS:

1. Go to: Structure > Block layout
2. Place "Local Text-to-Speech" block
3. Configure voice/speed controls and fields to include

### Field Integration

Per-content-type control with Layout Builder support:

1. Go to: Structure > Content types > [Type] > Manage fields
2. Add field: "Local TTS Player"
3. Configure display: Manage display > position field
4. Set voice/speed controls and field selection

**Permissions:** Grant "Generate local text-to-speech audio"
permission.

## Available Voices

**American English (20 voices):** af_alloy, af_aoede, af_bella, af_heart,
af_jessica, af_kore, af_nicole, af_nova, af_river, af_sarah, af_sky, am_adam,
am_echo, am_eric, am_fenrir, am_liam, am_michael, am_onyx, am_puck, am_santa

**British English (8 voices):** bf_alice, bf_emma, bf_isabella, bf_lily,
bm_daniel, bm_fable, bm_george, bm_lewis

**Other Languages:** Japanese (5), Mandarin Chinese (8), French (1), Hindi (4),
Spanish (3), Italian (2), Portuguese (3)

View all voices:
```bash
drush local-tts:voices
drush local-tts:voices --language=es
```

## Drush Commands

### Test TTS Generation

```bash
# Basic test with instant playback (streaming mode)
drush local-tts:test "Hello World"

# Custom voice and speed
drush local-tts:test "Your text" --voice=am_adam --speed=1.2

# Multi-language support
drush local-tts:test "Hola mundo" --voice=ef_dora --language=es
drush local-tts:test "こんにちは" --voice=jf_alpha --language=ja
```

### Read Entity Content

```bash
# Read node content (generates cached files)
drush local-tts:read node 123

# With specific voice
drush local-tts:read node 123 --voice=bf_emma

# Streaming mode (instant playback, no cache)
drush local-tts:read node 123 --stream

# Read specific field
drush local-tts:read node 123 --field=field_summary
```

### Batch Generation

```bash
# Generate audio for multiple entities
drush local-tts:batch --entity-type=node \
  --bundle=article --language=en --limit=10

# Multiple languages
drush local-tts:batch --entity-type=node --bundle=page --language=en,es,fr

# Force regeneration
drush local-tts:batch --entity-type=node --bundle=article --force

# Only entities updated since a date
drush local-tts:batch --entity-type=node \
  --bundle=article --updated-after=2026-01-01

# Preview count without generating
drush local-tts:batch --entity-type=node --bundle=article --dry-run
```

### Cache Management

```bash
# Clear all cached audio files
drush local-tts:cache-clear
```

## Architecture

**Services:**
- `TtsService` - Facade: voice management, health checks,
  generation coordination
- `TtsGenerator` - Binary execution, chunked processing,
  WAV to OGG transcoding via ffmpeg
- `TtsCacheManager` - Cache metadata, file reference counting
- `TtsTextExtractor` - Render-based text extraction
- `TtsCronService` - Stale metadata cleanup, LRU eviction,
  orphan file removal
- `TtsBatchService` - Batch generation with retry logic
- `TtsPlayerBuilder` - Player render array with voice controls

**Controllers:**
- `TtsController` - AJAX endpoint for audio generation with queue-based
  processing and status polling
- `VoicePreviewController` - Voice preview endpoint for settings page
- `TtsCacheController` - Cache entry deletion

**Queue Workers:**
- `TtsGenerationWorker` - Background audio generation
  with stale-content detection

**Plugins:**
- `LocalTtsBlock` - Block plugin for site-wide TTS
- `LocalTtsPlayerItem` - Field type for per-content TTS
- `LocalTtsPlayerFormatter` - Field formatter with settings

**Frontend:**
- `local-tts-player.js` - Audio playback, queue polling, progress persistence
- `local-tts-voice-preview.js` - Voice preview on settings page

### How It Works

1. User clicks "Listen to this page"
2. JavaScript sends AJAX with entity reference (not content)
3. Server renders the entity to extract text and checks for cached audio
4. If the content hash matches, the cached OGG is returned immediately
5. Otherwise the generation job is queued; the player polls every 3 seconds
6. The queue worker runs koko to generate WAV, then transcodes to OGG Opus
7. For content over 2000 characters, text is chunked at sentence boundaries
8. The player starts playback and saves progress to localStorage

**Caching:** Cache key based on text content (MD5),
voice, speed, and language code. Content change detection
uses a stored text hash to avoid unnecessary regeneration.

## Troubleshooting

### Binary not executable

```bash
cd web/modules/custom/local_tts
composer run download-files
chmod +x bin/koko
```

### eSpeak NG errors

Install eSpeak NG and configure the data path in module settings. Common paths:
- macOS: `/opt/homebrew/Cellar/espeak-ng/[version]/share/espeak-ng-data`
- Ubuntu/Debian: `/usr/share/espeak-ng-data`

### Files exposed via HTTP (Apache)

Ensure `.htaccess` files are respected:
```bash
sudo a2enmod rewrite
# Set AllowOverride All in Apache config
```

### Files exposed via HTTP (Nginx)

Add to Nginx config:
```nginx
location ~ ^/modules/custom/local_tts/(bin|data)/ {
    deny all;
    return 403;
}
```

### Player not showing

- Check content is publicly viewable (anonymous access)
- Verify voices available for content language:
  `drush local-tts:voices --language=en`
- Grant "Generate AI text-to-speech audio" permission

## Development

### Linting and Code Standards

Run coding standards checks:
```bash
docker compose --profile lint run --rm drupal-lint
```

Auto-fix coding standard violations:
```bash
docker compose --profile lint run --rm drupal-lint-auto-fix
```

Run Drupal compatibility checks:
```bash
docker compose --profile lint run --rm drupal-check
```

## License

This module is licensed under GPL-2.0+

**Note:** The Kokoro TTS engine is licensed under Apache License 2.0.

## Credits

- Kokoro TTS by [lucasjinreal](https://github.com/lucasjinreal)
- Based on the [Kokoro-82M model](https://huggingface.co/hexgrad/Kokoro-82M) by hexgrad
