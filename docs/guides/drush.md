# Drush Commands

## Test TTS generation

```bash
# Basic test with instant playback (streaming mode)
drush local-tts:test "Hello World"

# Custom voice and speed
drush local-tts:test "Your text" --voice=am_adam --speed=1.2

# Multi-language support
drush local-tts:test "Hola mundo" --voice=ef_dora --language=es
drush local-tts:test "こんにちは" --voice=jf_alpha --language=ja
```

## Read entity content

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

## Batch generation

```bash
# Generate audio for multiple entities
drush local-tts:batch --entity-type=node \
  --bundle=article --language=en --limit=10

# Multiple languages
drush local-tts:batch --entity-type=node \
  --bundle=page --language=en,es,fr

# Force regeneration of existing cached files
drush local-tts:batch --entity-type=node \
  --bundle=article --force
```

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
# Clear all cached audio files
drush local-tts:cache-clear
```
