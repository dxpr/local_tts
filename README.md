# AI Text-to-Speech Module

A Drupal module that provides high-quality AI-powered text-to-speech
using the Kokoro TTS engine (Rust implementation).

## Features

- High-quality AI voices using Kokoro-82M model
- **64 voices across 9 languages** (English, Japanese, Mandarin Chinese,
  French, Hindi, Spanish, Italian, Portuguese)
- **Language-aware voice filtering** - automatically shows only voices
  matching the content language
- Multiple voice options (male/female, accents)
- Adjustable speech speed (0.5x to 2.0x)
- Audio caching to reduce server load
- Simple block-based integration
- AJAX-powered audio generation
- Drush commands for testing and cache management
- Accessibility-optimized with ARIA labels and keyboard shortcuts

## Requirements

### Drupal

- Drupal 9, 10, or 11
- Node module (core)
- Composer

### External Dependencies

**Kokoro Binary (koko)**

This module requires the Kokoro Rust binary to be installed. The
installation process is automated via Composer scripts (see Installation
section below).

## Installation

### File Placement and Security

**Production installation - system-wide:**

```bash
# Download and install the Kokoro binary
sudo curl -L -o /usr/local/bin/koko \
  https://github.com/lucasjinreal/Kokoros/releases/download/v1.0/koko-linux-x64
sudo chmod 755 /usr/local/bin/koko

# Create directory for model and data files
sudo mkdir -p /usr/local/share/kokoro/checkpoints
sudo mkdir -p /usr/local/share/kokoro/data

# Download model and data files (replace URLs with actual releases)
sudo curl -L -o /usr/local/share/kokoro/checkpoints/kokoro-v1.0.onnx \
  https://github.com/lucasjinreal/Kokoros/releases/download/v1.0/kokoro-v1.0.onnx
sudo curl -L -o /usr/local/share/kokoro/data/voices-v1.0.bin \
  https://github.com/lucasjinreal/Kokoros/releases/download/v1.0/voices-v1.0.bin

# Set proper permissions
sudo chmod 644 /usr/local/share/kokoro/checkpoints/kokoro-v1.0.onnx
sudo chmod 644 /usr/local/share/kokoro/data/voices-v1.0.bin
sudo chown root:root /usr/local/bin/koko
sudo chown -R root:root /usr/local/share/kokoro
```

**Configure in Drupal:**
- Binary path: `/usr/local/bin/koko`
- Model file: `/usr/local/share/kokoro/checkpoints/kokoro-v1.0.onnx`
- Data file: `/usr/local/share/kokoro/data/voices-v1.0.bin`

**Security:**
- Binary: 755 permissions, owned by root or deployment user
- Model/data: 644 permissions, owned by root or deployment user
- Never store files in `sites/default/files/` (user upload directory)

### Quick Start

**Step 1: Install the module**

```bash
cd web/modules/custom/ai_tts
composer install
```

**Step 2: Install Kokoro files**

See "File Placement and Security" section above for production
installation.

**Step 3: Enable the module**

```bash
drush en ai_tts -y
```

**Step 4: Configure the module**

Go to: Administration > Configuration > Media > AI TTS

Set the file paths from the installation above.

**Step 5: Test the installation**

```bash
drush ai-tts:test "Hello World"
```

### Manual Installation (Advanced)

If you prefer manual installation or need a system-wide binary:

1. **Install Kokoro binary to system path:**
   ```bash
   git clone https://github.com/lucasjinreal/Kokoros.git
   cd Kokoros
   bash download_all.sh
   cargo build --release
   sudo cp target/release/koko /usr/local/bin/
   chmod +x /usr/local/bin/koko
   ```

2. **Enable the module:**
   ```bash
   drush en ai_tts -y
   ```

3. **Configure with system binary path:**
   - Go to: Administration > Configuration > Media > AI TTS
   - Set the path to: `/usr/local/bin/koko`
   - Configure other settings as needed

### Docker Alternative (Testing)

For quick testing with Docker:
```bash
docker pull ghcr.io/lucasjinreal/kokoros:latest
```

Note: Using Docker requires additional configuration to make the binary
accessible to Drupal.

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

Kokoro-82M supports **64 voices across 9 languages**. The module includes
all voices:

**American English (af_ / am_)** - 20 voices
- Female: af_alloy, af_aoede, af_bella, af_heart, af_jessica, af_kore,
  af_nicole, af_nova, af_river, af_sarah, af_sky
- Male: am_adam, am_echo, am_eric, am_fenrir, am_liam, am_michael,
  am_onyx, am_puck, am_santa

**British English (bf_ / bm_)** - 8 voices
- Female: bf_alice, bf_emma, bf_isabella, bf_lily
- Male: bm_daniel, bm_fable, bm_george, bm_lewis

**Japanese (jf_ / jm_)** - 5 voices
- Female: jf_alpha, jf_gongitsune, jf_nezumi, jf_tebukuro
- Male: jm_kumo

**Mandarin Chinese (zf_ / zm_)** - 8 voices
- Female: zf_xiaobei, zf_xiaoni, zf_xiaoxiao, zf_xiaoyi
- Male: zm_yunjian, zm_yunxia, zm_yunxi, zm_yunyang

**French (ff_)** - 1 voice
- Female: ff_siwis

**Hindi (hf_ / hm_)** - 4 voices
- Female: hf_alpha, hf_beta
- Male: hm_omega, hm_psi

**Spanish (ef_ / em_)** - 3 voices
- Female: ef_dora
- Male: em_alex, em_santa

**Italian (if_ / im_)** - 2 voices
- Female: if_sara
- Male: im_nicola

**Portuguese (pf_ / pm_)** - 3 voices
- Female: pf_dora
- Male: pm_alex, pm_santa

**Note:** Non-English language support may vary in quality due to training
data limitations. Some languages have limited voice options (e.g., French
has only one voice).

### Language-Aware Voice Filtering

The module automatically filters available voices based on the language
of the content being read:

**How It Works:**
1. The module detects the language of the node/content (e.g., English,
   Japanese, French)
2. Only voices matching that language are shown in the voice selector
3. If no voices are available for the content language, the entire TTS
   block is automatically hidden
4. The default voice is automatically adjusted to the first available
   voice for the content language

**Examples:**
- **English content** (en) → Shows all American and British English
  voices
- **Japanese content** (ja) → Shows only Japanese voices (jf_*, jm_*)
- **Spanish content** (es) → Shows only Spanish voices (ef_*, em_*)
- **Content in unsupported language** → Block is hidden (no TTS
  available)

**Supported Language Mappings:**
- `en`, `en-us` → American English voices
- `en-gb` → British English voices
- `ja` → Japanese voices
- `zh`, `zh-hans`, `zh-hant` → Mandarin Chinese voices
- `fr` → French voices
- `hi` → Hindi voices
- `es` → Spanish voices
- `it` → Italian voices
- `pt`, `pt-br`, `pt-pt` → Portuguese voices

This ensures users only see relevant, natural-sounding voices for their
content's language.

**Dynamic Language Support:**
The list of supported languages is maintained automatically based on the
available voices in the Kokoro binary. When voices are updated, the
supported language list updates accordingly. You can see the current list
by running:
```bash
drush ai-tts:voices --language=invalid
```

### Composer Commands

The module includes helpful Composer scripts for setup and maintenance:

```bash
# Download pre-built Kokoro binary for your platform
composer run download-binary

# Build Kokoro binary from source (requires Rust)
composer run build-binary

# Test the TTS functionality
composer run test

# View all available commands
composer run-script --list
```

### Drush Commands

The module provides convenient Drush commands for testing and managing
TTS functionality.

#### Test TTS Generation

Generate speech and automatically play it back using **streaming mode**
for instant playback:

```bash
# Basic test with "Hello World" using default settings
# Uses streaming mode - audio starts playing in ~1-2 seconds!
drush ai-tts:test
drush tts-test  # Short alias

# Custom text with automatic playback
drush ai-tts:test "Welcome to Drupal 11"

# With custom voice and speed
drush ai-tts:test "Your text here" --voice=am_adam --speed=1.2

# Female British voice at slower speed
drush ai-tts:test "Good morning" --voice=bf_emma --speed=0.8

# Spanish text with proper Spanish pronunciation
drush ai-tts:test "Hola mundo" --voice=ef_dora --language=es

# Japanese text with proper Japanese pronunciation
drush ai-tts:test "こんにちは" --voice=jf_alpha --language=ja
```

**Streaming Mode Benefits:**
- ⚡ **1-2 second time-to-first-audio** instead of 5-10s for
  traditional generation
- Audio starts playing almost immediately while still being generated
- No file caching required - perfect for quick testing
- Uses the `koko stream` command under the hood

**Available Options:**
- `--voice` - Voice to use (see available voices below)
- `--speed` - Speech speed from 0.5 to 2.0 (default: 1.0)
- `--language` - Language code for proper pronunciation (e.g., en, es,
  ja, fr, hi, it, pt, zh). If not specified, the language is
  auto-detected from the voice prefix.

**Platform Support:**
The command automatically detects your operating system and uses the
appropriate audio player:
- **macOS**: `afplay`
- **Linux**: `paplay` (PulseAudio), `aplay` (ALSA), or `ffplay` (FFmpeg)
- **Windows**: PowerShell SoundPlayer

#### List Available Voices

Display all available TTS voices:

```bash
# List all available voices (64 voices across 9 languages)
drush ai-tts:voices
drush tts-voices  # Short alias

# List only Spanish voices
drush ai-tts:voices --language=es

# List only Japanese voices
drush ai-tts:voices --language=ja

# List only English voices
drush ai-tts:voices --language=en
```

**Available Options:**
- `--language` - Filter voices by language code (e.g., en, es, ja, fr,
  hi, it, pt, zh)

This shows all 64 voices with their identifiers, or filtered by language
if specified.

#### Read Entity Content

Generate and play TTS audio for Drupal entities (nodes, taxonomy terms,
etc.). This command generates **cached files** that are interoperable with
frontend-generated audio:

```bash
# Read a node's content (auto-detects body field)
drush ai-tts:read node 123
drush tts-read node 123  # Short alias

# Use with a specific voice
drush ai-tts:read node 123 --voice=bf_emma

# Read a taxonomy term
drush ai-tts:read taxonomy_term 45

# Read a specific field instead of body
drush ai-tts:read node 123 --field=field_summary

# Use streaming mode for instant playback (no caching)
drush ai-tts:read node 123 --stream

# Spanish content with proper voice
drush ai-tts:read node 456 --voice=ef_dora --language=es
```

**Available Options:**
- `--voice` - Voice to use (default: site configuration)
- `--speed` - Speech speed from 0.5 to 2.0 (default: 1.0)
- `--language` - Language code (default: auto-detected from entity or
  voice)
- `--field` - Specific field to read (default: auto-detects body,
  field_description, etc.)
- `--stream` - Use streaming mode for instant playback instead of
  caching

**How It Works:**
1. **Default mode (cached)**: Generates audio file using `TtsService`,
   stores in cache, plays the cached file
2. **Streaming mode (`--stream`)**: Pipes text directly to koko for
   instant playback (1-2s time-to-first-audio)

**Cache Interoperability:**
- Cached files are stored in the same location as frontend-generated
  audio (`public://ai-tts/`)
- Uses the same cache key algorithm (MD5 hash of text + voice + speed +
  language)
- If you generate audio for a node via Drush, the frontend will use that
  cached file
- Perfect for pre-generating audio for frequently accessed content

**Supported Entity Types:**
- `node` - Content nodes
- `taxonomy_term` - Taxonomy terms
- `media` - Media entities (if they have text fields)
- Any entity with text fields

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
2. JavaScript detects content language and available voices
3. AJAX request sent to `/ai-tts/generate` with text, voice, speed, and
   language
4. `TtsService` calls koko binary with proper language for G2P
   (grapheme-to-phoneme):
   - Example: `koko --lan es --style ef_dora --speed 1.0 text "Hola"
     --output output.wav`
5. Generated audio file is cached (if caching enabled)
6. Audio URL returned to frontend
7. HTML5 `<audio>` element plays the generated speech

**Language-Aware Processing:**
- The language code is passed to espeak-ng for proper pronunciation
- Spanish text uses Spanish phonemes (not English with Spanish accent)
- Japanese text uses Japanese phonemes
- Each language gets native pronunciation rules

### Caching

Audio files are cached by default in `public://ai-tts/` directory. Cache
key is based on:
- Text content (MD5 hash)
- Voice selection
- Speed setting
- **Language code** (ensures proper pronunciation per language)

This prevents regenerating the same audio multiple times. **Importantly,
the cache is language-aware**, so the same text in different languages
will have separate cache files with correct pronunciation for each
language.

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

## Development

### Git Hooks for Code Quality

The module includes git hooks that automatically enforce Drupal coding
standards on every commit.

**What the hooks do:**

- **pre-commit**: Automatically fixes code style issues and blocks
  commits with remaining errors
- **commit-msg**: Validates commit message format (conventional commits
  recommended)

**Installation:**

```bash
cd web/modules/custom/ai_tts
./scripts/install-git-hooks.sh
```

**How it works:**

1. You stage files: `git add src/Form/MyForm.php`
2. You commit: `git commit -m "feat: add new feature"`
3. Pre-commit hook runs automatically:
   - Auto-fixes code style issues (spacing, indentation, etc.)
   - Stages the fixed files
   - Checks for remaining violations
   - Blocks commit if errors remain
4. Commit-msg hook validates your commit message format
5. If all checks pass, commit proceeds

**Example workflow:**

```bash
# Make changes to a file
vim src/TtsService.php

# Stage and commit
git add src/TtsService.php
git commit -m "fix: resolve caching issue"

# Hook output:
# ✓ Auto-fixed 5 issues
# ✓ All checks passed! Proceeding with commit...
```

**Skipping hooks (not recommended):**

```bash
git commit --no-verify -m "message"
```

**Uninstalling hooks:**

```bash
rm .git/hooks/pre-commit .git/hooks/commit-msg
```

### Running Linters Manually

The module includes Docker-based linting for CI/CD:

```bash
# Run all linters (no auto-fix)
docker compose --profile lint run --rm drupal-lint

# Run with auto-fix
docker compose --profile lint run --rm drupal-lint-auto-fix

# Run PHPStan static analysis
docker compose --profile lint run --rm drupal-check
```

Or use Composer scripts:

```bash
# Check code style
composer run lint

# Auto-fix code style
composer run lint:fix
```

### Contributing

When contributing to this module:

1. Install git hooks: `./scripts/install-git-hooks.sh`
2. Follow Drupal coding standards (enforced by hooks)
3. Use conventional commit messages:
   - `feat:` - New features
   - `fix:` - Bug fixes
   - `docs:` - Documentation changes
   - `refactor:` - Code refactoring
   - `test:` - Adding tests
   - `chore:` - Maintenance tasks
4. Ensure all linters pass before pushing
5. Test your changes with `drush ai-tts:test`

## Troubleshooting

### Binary installation fails

**Error:** Composer scripts fail to download or build the binary

**Solutions:**
1. Check your internet connection for downloads
2. For build errors, ensure Rust is installed:
   `curl --proto '=https' --tlsv1.2 -sSf https://sh.rustup.rs | sh`
3. Check available releases:
   https://github.com/lucasjinreal/Kokoros/releases
4. Try manual installation (see Manual Installation section)
5. Check disk space and permissions in the module's `bin/` directory

### Binary not found or not executable

**Error:** "Binary not found or not executable at:
modules/custom/ai_tts/bin/koko"

**Solutions:**
1. Run the Composer setup:
   `cd web/modules/custom/ai_tts && composer run download-binary`
2. Verify the binary exists: `ls -la web/modules/custom/ai_tts/bin/koko`
3. Make it executable: `chmod +x web/modules/custom/ai_tts/bin/koko`
4. Update the path in module configuration to match your installation
5. Check the status report: Administration > Reports > Status report

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
