# Local Text-to-Speech for Drupal

Local text-to-speech for Drupal using the Kokoro TTS engine. 54 voices across
9 languages, fully self-hosted with no cloud APIs, no subscriptions, and no
data leaving your server.

## Features

- **54 voices across 9 languages**: American English (20), British English (8),
  Japanese (5), Mandarin Chinese (8), French (1), Hindi (4),
  Spanish (3), Italian (2), Portuguese (3)
- **OGG Opus output**: audio encoded as OGG Opus for small file sizes and
  broad browser support
- **Queue-based generation**: audio is generated in the background via
  Drupal's Queue API; visitors see a progress indicator while waiting
- **Chunked processing**: long content is split at sentence boundaries and
  generated in chunks, then concatenated into a single file
- **Content change detection**: a text hash is stored alongside each cached
  file; audio is only regenerated when the content actually changes
- **Render-based text extraction**: content is extracted by rendering the
  entity, capturing computed fields, Views output, and block field content
- **Playback progress persistence**: playback position is saved to
  localStorage every 5 seconds and restored on return visits
- **Voice preview**: listen to any voice directly on the
  [Voices settings tab](getting-started/configuration.md#voice-settings)
  before selecting it as the default
- **Language-aware voice filtering**: automatically shows only voices matching
  the content language
- **Adjustable speech speed**: 0.5x to 2.0x
- **Auto-generate on save**: optionally queue TTS generation whenever content
  is created or updated, so audio is ready before the first visitor arrives
- **Content type filtering**: limit TTS to specific content types
- **Download button**: optionally allow visitors to download generated audio
- **Audio caching**: generated files cached on disk with configurable size
  limits and automatic LRU eviction
- **Orphan cleanup**: stale audio files with no matching database record are
  removed automatically during cron
- **Block and field integration**: place a player site-wide via Block layout or
  per content type via a dedicated field
- **Drush commands**: test generation, batch processing, cache management, and
  voice listing
- **Batch generation**: generate audio for multiple entities at once via the
  admin UI or Drush
- **Accessibility**: ARIA labels, keyboard shortcuts, screen reader
  announcements, semantic HTML
- **Privacy-first**: only publicly viewable content is converted to audio; no
  data sent to external services

## How it works

1. A visitor clicks "Listen to this page"
2. JavaScript sends an AJAX request with the entity reference (not the content)
3. The server renders the entity to extract text and checks for a cached file
4. If the content hash matches an existing cache entry, the cached OGG file is
   returned immediately
5. Otherwise the generation job is queued and the visitor sees a progress
   indicator; the JavaScript polls the status endpoint every 3 seconds
6. The queue worker runs koko (Kokoro TTS) to generate WAV audio, then
   transcodes it to OGG Opus via ffmpeg
7. For long content (over 2000 characters), the text is split at sentence
   boundaries, each chunk is generated separately, and the results are
   concatenated into a single OGG file
8. The status endpoint reports "ready" once the file exists; the player
   starts playback and saves progress to localStorage

## Architecture

The module is built around a service-oriented architecture with clear
separation of concerns:

- **TtsService**: facade coordinating voice management, health checks, and
  generation; delegates to specialised services below
- **TtsGenerator**: interfaces with the `koko` binary for speech generation,
  handles chunked processing and WAV to OGG Opus transcoding via ffmpeg
- **TtsCacheManager**: manages the cache metadata database table, file
  reference counting, and metadata save/update operations
- **TtsTextExtractor**: render-based text extraction with account switching
  to anonymous, HTML-to-plain-text conversion, and re-entrancy safety
- **TtsCronService**: cron-driven cleanup of stale metadata, LRU cache
  eviction, orphan file removal, and bundle-filtering logic
- **TtsBatchService**: batch generation with retry logic, database keepalive,
  and translation-aware entity queries
- **TtsPlayerBuilder**: shared service for building the player render array
  with voice controls, speed selector, and accessibility markup
- **TtsController**: AJAX endpoint that checks cache, queues generation, and
  exposes a status polling endpoint
- **TtsGenerationWorker**: queue worker plugin that processes background
  generation jobs with stale-content detection
- **VoicePreviewController**: endpoint for previewing voices on the
  settings page
- **LocalTtsBlock**: block plugin for site-wide TTS
- **LocalTtsPlayerItem**: field type for per-content TTS
- **LocalTtsPlayerFormatter**: field formatter with voice/speed/volume
  settings

## Admin interface

The module provides five admin tabs at **Configuration > Media > Local TTS**:

| Tab | Path | Purpose |
|-----|------|---------|
| System | `/admin/config/media/local-tts` | eSpeak path, caching, cache size, security |
| Voices | `/admin/config/media/local-tts/voices` | Per-language default voice, speed, auto-generate, content types, download button |
| Test | `/admin/config/media/local-tts/test` | Generate and play speech from arbitrary text |
| Cache | `/admin/config/media/local-tts/cache` | Browse cached audio files with inline players |
| Batch | `/admin/config/media/local-tts/batch` | Generate audio for multiple entities at once |

![System settings tab](images/settings-system.jpg)

## Related modules

- [DXPR CMS](https://www.drupal.org/project/dxpr_cms): Drupal pre-configured
  with DXPR Builder, DXPR Theme, and security best practices
- [AI module](https://www.drupal.org/project/ai): Drupal's AI integration
  framework (Local TTS is a standalone alternative for speech)
