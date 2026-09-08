# Drush Commands

## Test TTS generation

Generate speech from arbitrary text. By default plays the audio
immediately; use `--no-play` to generate without playback.

```bash
# Basic test with instant playback (streaming mode)
drush local-tts:test "Hello World"

# Custom voice and speed
drush local-tts:test "Your text" --voice=am_adam --speed=1.2

# Multi-language support
drush local-tts:test "Hola mundo" --voice=ef_dora --language=es
drush local-tts:test "こんにちは" --voice=jf_alpha --language=ja

# Generate without playback (useful on servers without audio output)
drush local-tts:test "Hello World" --no-play
```

| Option | Description |
|--------|-------------|
| `--voice` | Voice ID to use (e.g. `af_sky`, `am_adam`) |
| `--speed` | Speech speed, 0.5 to 2.0 (default: 1.0) |
| `--language` | Language code for pronunciation (e.g. `en`, `es`, `ja`) |
| `--no-play` | Generate audio without automatic playback |

## Read entity content

Generate and play TTS for a specific entity. Creates a cached file by
default; use `--stream` for instant playback without caching.

```bash
# Read node content (generates cached files)
drush local-tts:read node 123

# With specific voice
drush local-tts:read node 123 --voice=bf_emma

# Streaming mode (instant playback, no cache)
drush local-tts:read node 123 --stream

# Read a specific field
drush local-tts:read node 123 --field=field_summary
```

| Option | Description |
|--------|-------------|
| `--voice` | Voice ID to use |
| `--speed` | Speech speed, 0.5 to 2.0 |
| `--language` | Language code (auto-detected from entity if omitted) |
| `--field` | Read a specific field instead of the full rendered entity |
| `--stream` | Use streaming mode (instant playback, no caching) |

## Batch generation

Generate audio for multiple entities at once. Requires both
`--entity-type` and `--bundle`.

```bash
# Generate audio for 10 English articles
drush local-tts:batch --entity-type=node \
  --bundle=article --language=en --limit=10

# Multiple languages
drush local-tts:batch --entity-type=node \
  --bundle=page --language=en,es,fr

# Force regeneration of existing cached files
drush local-tts:batch --entity-type=node \
  --bundle=article --force

# Only process entities updated since a specific date
drush local-tts:batch --entity-type=node \
  --bundle=article --updated-after=2026-01-01

# Preview how many entities would be processed
drush local-tts:batch --entity-type=node \
  --bundle=article --dry-run
```

| Option | Description |
|--------|-------------|
| `--entity-type` | Entity type to process (required; e.g. `node`, `taxonomy_term`) |
| `--bundle` | Bundle to process (required; e.g. `article`, `page`) |
| `--language` | Language code(s), comma-separated (default: `en`) |
| `--limit` | Maximum entities to process; 0 for no limit (default: 0) |
| `--force` | Regenerate audio even when a cached file already exists |
| `--updated-after` | Only process entities updated after this date (`Y-m-d` format) |
| `--dry-run` | Show the count of entities that would be processed, then exit |

## List available voices

```bash
# All voices
drush local-tts:voices

# Filter by language code
drush local-tts:voices --language=en
drush local-tts:voices --language=ja
```

## Cache management

```bash
# Clear all cached audio files and database records
drush local-tts:cache-clear
```
