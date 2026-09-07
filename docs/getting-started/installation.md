# Installation

## System dependencies

### ffmpeg

ffmpeg is required for transcoding generated WAV audio to OGG Opus format.
Install it before enabling the module.

```bash
# macOS
brew install ffmpeg

# Ubuntu/Debian
sudo apt-get install ffmpeg

# Fedora/RHEL
sudo dnf install ffmpeg

# Arch Linux
sudo pacman -S ffmpeg
```

Verify that the `libopus` codec is available:

```bash
ffmpeg -codecs 2>/dev/null | grep opus
```

### eSpeak NG

eSpeak NG is required for text-to-phoneme conversion. Install it before
enabling the module.

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

- **macOS (Homebrew)**:
  `/opt/homebrew/Cellar/espeak-ng/[version]/share/espeak-ng-data`
- **Linux**: usually `/usr/share/espeak-ng-data`

You will need this path during [configuration](configuration.md).

### Rust (for building the binary)

The Kokoro binary must be built from source. Install Rust/Cargo from
[rustup.rs](https://rustup.rs) if you do not have it already.

## Install the module

```bash
composer require drupal/local_tts
```

This downloads the module code plus model and voice data files (~337MB).

## Build the Kokoro binary

```bash
cd web/modules/contrib/local_tts
composer run build-binary
```

This clones the Kokoro source, downloads required model files, builds the
binary with Cargo, and installs it to `bin/koko`. The build takes roughly
5 to 10 minutes.

## Enable and test

```bash
drush en local_tts -y
drush local-tts:test "Hello World"
```

If the test produces audio, the installation is complete. Visit
`/admin/config/media/local-tts` to configure the eSpeak NG data path and
other settings (see [Configuration](configuration.md)).

**Important:** the eSpeak NG data path must be set in the module settings
before audio generation will work via the web player. The binary requires
the `PIPER_ESPEAKNG_DATA_DIRECTORY` environment variable, which the module
sets automatically from the configured path.

## Bundled files structure

```
local_tts/
├── bin/
│   ├── .htaccess          # Blocks web access
│   ├── LICENSE            # Apache 2.0
│   └── koko               # Kokoro binary (built from source)
├── data/
│   ├── .htaccess          # Blocks web access
│   ├── LICENSE            # Apache 2.0
│   ├── kokoro-v1.0.onnx   # Model file (auto-downloaded, 310MB)
│   └── voices-v1.0.bin    # Voice data (auto-downloaded, 27MB)
```

All binary and data files are protected by `.htaccess` rules that block
web access. For Nginx, add location blocks to deny access to
`local_tts/(bin|data)/`; see [Troubleshooting](#nginx-file-protection)
below.

## Troubleshooting

### Binary not executable

```bash
chmod +x bin/koko
```

### Nginx file protection

Add to your Nginx server block:

```nginx
location ~ ^/modules/(contrib|custom)/local_tts/(bin|data)/ {
    deny all;
    return 403;
}
```

### eSpeak NG not found

Verify it is installed (`espeak-ng --version`) and configure the data
path at `/admin/config/media/local-tts`.
