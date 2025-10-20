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

## Security & Privacy

**Rule:** Only publicly viewable content can be converted to audio.

**Why:** Audio files cache to `public://` filesystem. Private content must not be exposed.

**Requirements for TTS:**
- Content published (status = 1)
- Anonymous users can view content
- "Generate AI text-to-speech audio" permission granted

**Enforcement:**
- **UI** - Player hidden on private content
- **API** - Returns HTTP 403 for private content
- **Drush** - Shows error with solution

**Automatic cache cleanup:**
- Audio deleted when source content updated
- Audio deleted when source content deleted
- Keeps cache synchronized with content

**Development workaround:**

Test TTS on private content without caching:

```bash
# Streaming mode: no cache, bypasses access check
drush ai-tts:read node 123 --stream
```

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

**eSpeak NG**

The Kokoro binary requires eSpeak NG for text-to-phoneme conversion across
multiple languages. This must be installed separately:

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

After installation, note the location of the `espeak-ng-data` directory:
- macOS (Homebrew):
  `/opt/homebrew/Cellar/espeak-ng/[version]/share/espeak-ng-data`
- Linux: Usually `/usr/share/espeak-ng-data` or
  `/usr/local/share/espeak-ng-data`

You'll need to configure this path in the module settings.

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
- Model file:
  `/usr/local/share/kokoro/checkpoints/kokoro-v1.0.onnx`
- Data file: `/usr/local/share/kokoro/data/voices-v1.0.bin`
- eSpeak NG data path: `/usr/share/espeak-ng-data`
  (or appropriate path for your system)

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

Go to: Configuration > Media > AI TTS Settings (`/admin/config/media/ai-tts`)

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
   - Go to: Configuration > Media > AI TTS Settings
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

### Option 1: Block (Simple)

**When to use:** Quick setup, testing, or site-wide TTS on all pages.

**Steps:**

1. Go to: Structure > Block layout
2. Click "Place block" in your desired region
3. Find "AI Text-to-Speech" and click "Place block"
4. Configure:
   - **Show Voice Selection Dropdown** - Let users choose voices
   - **Show Speed Control** - Let users adjust playback speed
   - **Fields to include** - Select which fields to read
5. Save

The block appears on all pages in that region and reads content from the selected fields.

### Option 2: Field (Advanced)

**When to use:** Per-content-type control, Layout Builder, or custom field selection.

**Benefits:**
- Position player anywhere in entity display
- Different settings per content type
- Works with Layout Builder
- Granular field selection

**Add the field:**

1. Go to: Structure > Content types > [Your type] > Manage fields
2. Click "Add field"
3. Select "AI TTS Player"
4. Label: "Text-to-Speech Player" (or your preference)
5. Save field settings

**Configure display:**

1. Go to: Structure > Content types > [Your type] > Manage display
2. Drag the field to your desired position
3. Click the gear icon:
   - **Show Voice Selection Dropdown** - Let users choose voices
   - **Show Speed Control** - Let users adjust playback speed
   - **Fields to include** - Select fields to read (empty = all)
4. Save

**Layout Builder:**

1. Edit your layout
2. Add block > select your TTS field
3. Position in layout
4. Configure settings

**Permissions:** Users need "Generate AI text-to-speech audio" permission to use the player.

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

**Automatic filtering:** The player shows only voices matching your content's language.

**Behavior:**
- Content language detected automatically
- Voice selector shows only matching voices
- Player hidden if no voices available for language
- Default voice auto-adjusted to first available

**Examples:**
- English (en) → American + British voices
- Japanese (ja) → Japanese voices only
- Spanish (es) → Spanish voices only
- Unsupported language → Player hidden

**Supported languages:**
- `en`, `en-us` → American English
- `en-gb` → British English
- `ja` → Japanese
- `zh`, `zh-hans`, `zh-hant` → Mandarin Chinese
- `fr` → French
- `hi` → Hindi
- `es` → Spanish
- `it` → Italian
- `pt`, `pt-br`, `pt-pt` → Portuguese

**Check supported languages:**
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

**Services:**
- `TtsService` - Core service for interfacing with koko binary
- `TtsPlayerBuilder` - Shared service for building player UI

**Controllers:**
- `TtsController` - AJAX endpoint for audio generation

**Forms:**
- `AiTtsSettingsForm` - Configuration form at `/admin/config/media/ai-tts`

**Plugins:**
- `AiTtsBlock` - Block plugin for site-wide TTS
- `AiTtsPlayerItem` - Field type for per-content TTS
- `AiTtsPlayerWidget` - Hidden widget (always enabled)
- `AiTtsPlayerFormatter` - Field formatter with settings

**Commands:**
- `AiTtsCommands` - Drush commands (test, voices, read, cache-clear)

**Frontend:**
- `ai-tts-player.js` - Audio playback controls
- `ai-tts.libraries.yml` - Library definition

### How It Works

**Player rendering:**
1. `TtsPlayerBuilder` service builds player UI
2. Player shown only if content viewable by anonymous users
3. Voice selector filtered by content language
4. JavaScript attached with entity reference (not content)

**Audio generation:**
1. User clicks "Listen to this page"
2. JavaScript sends AJAX to `/ai-tts/generate` with entity reference
3. `TtsController` validates access and extracts text from entity
4. `TtsService` calls koko with language-specific phoneme processing
5. Audio cached in `public://ai-tts/` (if enabled)
6. Audio URL returned to browser
7. HTML5 `<audio>` element plays speech

**Security model:**
- Only entity reference passed to JavaScript (not content)
- Server validates anonymous access before generation
- Rate limiting prevents abuse
- Cache invalidated on entity updates

**Language processing:**
- Language code passed to eSpeak NG for phoneme conversion
- Spanish text → Spanish phonemes (native pronunciation)
- Japanese text → Japanese phonemes (native pronunciation)
- Each language uses native pronunciation rules

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

### Global Settings

Go to: Configuration > Media > AI TTS Settings

**File Paths**
- **Koko executable path** - Path to koko binary
- **Model file path** - Path to kokoro-v1.0.onnx
- **Voices data path** - Path to voices-v1.0.bin
- **eSpeak NG data path** - Path to espeak-ng-data directory

**Voice Settings**
- **Default voice for [language]** - Default voice per enabled language
- **Default speech speed** - Speed multiplier (0.8x to 2x)

**Caching Settings**
- **Cache generated audio files** - Enable/disable caching
- **Audio cache directory** - Storage location (default: public://ai-tts)

**Cache Management**
- **Maximum cache size (MB)** - Size limit with automatic cleanup

**Security Settings**
- **Maximum text length** - Character limit per request
- **Generation timeout** - Maximum processing time (seconds)
- **Enable rate limiting** - Prevent abuse
- **Rate limit threshold** - Requests per hour per user
- **Maximum server load threshold** - Skip generation during high load

### Block Settings

- **Show Voice Selection Dropdown** - Let users choose voices
- **Show Speed Control** - Let users adjust playback speed
- **Fields to include** - Select fields to read

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

### Binary not found or not executable

**Symptom:** Error message about binary not found or not executable.

**Fix:**
```bash
# Download binary
cd web/modules/custom/ai_tts
composer run download-binary

# Make executable
chmod +x bin/koko

# Verify
ls -la bin/koko
```

Update path in Configuration > Media > AI TTS Settings if needed.

### eSpeak NG errors

**Symptom:** "Failed to initialize eSpeak-ng" or "Error processing file 'espeak-ng-data/phontab'"

**Fix:**
```bash
# Install eSpeak NG
# macOS:
brew install espeak-ng

# Ubuntu/Debian:
sudo apt-get install espeak-ng

# Find data directory
# macOS:
find /opt/homebrew -name "espeak-ng-data" -type d

# Linux:
find /usr -name "espeak-ng-data" -type d 2>/dev/null
```

Configure path in Configuration > Media > AI TTS Settings.

**Common paths:**
- macOS: `/opt/homebrew/Cellar/espeak-ng/[version]/share/espeak-ng-data`
- Ubuntu/Debian: `/usr/share/espeak-ng-data`
- Fedora/RHEL: `/usr/share/espeak-ng-data`

### Permission denied

**Symptom:** Cannot create audio files.

**Fix:**
```bash
# Check permissions
ls -la sites/default/files/ai-tts/

# Fix if needed
chmod 755 sites/default/files/ai-tts/
```

### Slow audio generation

**Symptoms:** Long wait times for audio.

**Solutions:**
1. Enable caching: Configuration > Media > AI TTS Settings
2. Reduce text length in block/field settings
3. Use faster CPU/server
4. Pre-generate audio with Drush: `drush ai-tts:read node 123`

### Drush commands not found

**Symptom:** "Command ai-tts:test was not found"

**Fix:**
```bash
# Clear cache
drush cr

# Verify module enabled
drush pm:list | grep ai_tts

# List available commands
drush list | grep tts
```

### Player not showing

**Possible causes:**

1. **Private content** - Player hidden for content anonymous users can't view
2. **No voices for language** - Player hidden if no voices available for content language
3. **Missing permission** - Grant "Generate AI text-to-speech audio" permission
4. **Field disabled** - Check field display settings (Manage display)

**Debug:**
```bash
# Check voices available for language
drush ai-tts:voices --language=en

# Test with simple text
drush ai-tts:test "Hello World"
```

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
