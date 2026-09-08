# Architecture

The module uses a service-oriented architecture. Core services are
registered in `local_tts.services.yml`; Drush commands in
`drush.services.yml`.

## PHP services

| Service | Class | Responsibility |
|---------|-------|----------------|
| `local_tts.tts_service` | `TtsService` | Facade: voice management, health checks, generation coordination |
| `local_tts.tts_generator` | `TtsGenerator` | Binary execution, chunked processing, WAV to OGG transcoding via ffmpeg |
| `local_tts.cache_manager` | `TtsCacheManager` | Cache metadata table, file reference counting, metadata saves |
| `local_tts.text_extractor` | `TtsTextExtractor` | Render-based text extraction with anonymous account switching |
| `local_tts.cron_service` | `TtsCronService` | Stale metadata cleanup, LRU eviction, orphan file removal |
| `local_tts.batch_service` | `TtsBatchService` | Batch generation with retry logic and translation-aware queries |
| `local_tts.player_builder` | `TtsPlayerBuilder` | Player render array with voice controls and accessibility markup |

## Controllers

| Controller | Route | Purpose |
|------------|-------|---------|
| `TtsController` | `/local-tts/generate` (POST) | Validates entity, extracts text, checks cache, queues generation |
| `TtsController` | `/local-tts/status/{cache_key}` | Polling endpoint: reports "ready" or "processing" |
| `VoicePreviewController` | `/admin/config/media/local-tts/preview` | Generates voice preview audio samples |
| `TtsCacheController` | `/admin/config/media/local-tts/cache/delete/{cache_key}` | Deletes individual cache entries |

## Plugins

| Plugin | Type | Purpose |
|--------|------|---------|
| `LocalTtsBlock` | Block | Site-wide TTS player via Block layout |
| `LocalTtsPlayerItem` | Field type | Per-content TTS player field |
| `LocalTtsPlayerFormatter` | Field formatter | Configurable voice/speed/volume settings |
| `TtsGenerationWorker` | Queue worker | Background generation with stale-content detection |

## Frontend

| File | Purpose |
|------|---------|
| `local-tts-player.js` | Player constructor: playback, queue polling, scrubber, progress persistence |
| `local-tts-voice-preview.js` | Voice preview buttons on the Voices settings tab |
| `local-tts-player.css` | Player layout, CSS custom properties for theming |
| `local-tts-gin.css` | Gin admin theme integration |
| `local-tts-dxpr-theme.css` | DXPR Theme integration |

The block plugin (`LocalTtsBlock`) and field formatter
(`LocalTtsPlayerFormatter`) both delegate to `TtsPlayerBuilder` for the
player UI, so the rendering logic is shared.

## Key design decisions

- **Server-side text extraction**: the generate endpoint never accepts
  text from the client. It loads the entity server-side and renders it
  to extract text, preventing content injection.
- **Atomic file writes**: generated audio is written to a `.part` temp
  file with a random suffix, then atomically renamed to the final path.
  This prevents concurrent workers from serving half-written files.
- **Reference-counted deletion**: multiple metadata rows can reference
  the same audio file (e.g. same text with different entity sources).
  Files are only deleted when no metadata rows reference them.
- **Stale-content detection**: the queue worker re-extracts text from
  the entity at processing time and compares it to the queued text,
  dropping jobs where the content has changed since queuing.
