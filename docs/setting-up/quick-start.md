# Quick Start

Get TTS working on your site in five steps. This guide covers the
shortest path; see [Installation](installation.md) and
[Configuration](configuration.md) for the full details.

## 1. Install dependencies

```bash
# macOS
brew install ffmpeg espeak-ng

# Ubuntu/Debian
sudo apt-get install ffmpeg espeak-ng
```

You also need Rust/Cargo: [rustup.rs](https://rustup.rs).

## 2. Install and build

```bash
composer require drupal/local_tts
cd web/modules/contrib/local_tts
composer run build-binary
```

## 3. Enable the module

```bash
drush en local_tts -y
```

## 4. Configure eSpeak

Visit `/admin/config/media/local-tts` and set the **eSpeak NG data
path**:

- **macOS**: `/opt/homebrew/Cellar/espeak-ng/[version]/share/espeak-ng-data`
- **Linux**: `/usr/share/espeak-ng-data`

## 5. Place the player

The fastest option is the block:

1. Go to **Structure > Block layout**
2. Click **Place block** in the Content region
3. Find **Local Text-to-Speech** and click **Place block**
4. Save

Visit any published node. You should see a TTS player. Click play to
generate and hear the audio.

## Verify it works

```bash
drush local-tts:test "Hello World"
```

If you hear audio, the installation is working. If not, expand the
**System health** panel at `/admin/config/media/local-tts` to check
which component is missing.

## Next steps

- [Choose a voice](../using/choosing-a-voice.md) for your site
- [Configure settings](configuration.md) (speed, caching, security)
- Compare [integration methods](../integrations/index.md) to pick the
  best fit for your site
