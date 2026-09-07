# Local Text-to-Speech

AI-powered text-to-speech for Drupal using the Kokoro TTS engine with 64 voices
across 9 languages.

## Features

- **64 voices across 9 languages** (English, Japanese, Mandarin Chinese, French,
  Hindi, Spanish, Italian, Portuguese)
- **Language-aware voice filtering** - automatically shows only voices matching
  content language
- Adjustable speech speed (0.5x to 2.0x)
- Audio caching to reduce server load
- Block and field-based integration options
- Drush commands for testing, batch generation, and cache management
- Accessibility-optimized with ARIA labels and keyboard shortcuts
- **Privacy-first**: Only publicly viewable content converted to audio

## Requirements

### Drupal

- Drupal 9, 10, or 11
- Node module (core)

### System Dependencies

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
- **Default speech speed** - Speed multiplier (0.8x to 2x)
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

**Permissions:** Grant "Generate AI text-to-speech audio" permission.

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
```

### Cache Management

```bash
# Clear all cached audio files
drush local-tts:cache-clear
```

## Architecture

**Services:**
- `TtsService` - Core service for interfacing with koko binary
- `TtsPlayerBuilder` - Shared service for building player UI

**Controllers:**
- `TtsController` - AJAX endpoint for audio generation

**Plugins:**
- `LocalTtsBlock` - Block plugin for site-wide TTS
- `LocalTtsPlayerItem` - Field type for per-content TTS
- `LocalTtsPlayerFormatter` - Field formatter with settings

**Frontend:**
- `local-tts-player.js` - Audio playback controls

### How It Works

1. User clicks "Listen to this page"
2. JavaScript sends AJAX with entity reference (not content)
3. Server validates anonymous access
4. TTS generates audio with language-specific phoneme processing
5. Audio cached in `public://local-tts/` (if enabled)
6. HTML5 `<audio>` element plays speech

**Caching:** Cache key based on text content (MD5),
voice, speed, and language code.

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
