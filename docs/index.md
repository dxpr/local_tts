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
- **Voice preview**: listen to any voice directly on the settings page before
  selecting it as the default
- **Language-aware voice filtering**: automatically shows only voices matching
  the content language
- **Adjustable speech speed**: 0.5x to 2.0x
- **Audio caching**: generated files cached on disk to reduce server load
- **Orphan cleanup**: stale audio files with no matching database record are
  removed automatically during cron
- **Block and field integration**: place a player site-wide via Block layout or
  per content type via a dedicated field
- **Drush commands**: test generation, batch processing, cache management, and
  voice listing
- **Accessibility**: ARIA labels, keyboard shortcuts, semantic HTML
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

- **TtsService**: core service interfacing with the `koko` binary; handles
  single and chunked generation, WAV to OGG Opus transcoding via ffmpeg
- **TtsPlayerBuilder**: shared service for building the player UI
- **TtsController**: AJAX endpoint that checks cache, queues generation, and
  exposes a status polling endpoint
- **TtsGenerationWorker**: queue worker plugin that processes background
  generation jobs
- **VoicePreviewController**: endpoint for previewing voices on the settings
  page
- **LocalTtsBlock**: block plugin for site-wide TTS
- **LocalTtsPlayerItem**: field type for per-content TTS
- **LocalTtsPlayerFormatter**: field formatter with voice/speed settings

## Related modules

- [DXPR CMS](https://www.drupal.org/project/dxpr_cms): Drupal pre-configured
  with DXPR Builder, DXPR Theme, and security best practices
- [AI module](https://www.drupal.org/project/ai): Drupal's AI integration
  framework (Local TTS is a standalone alternative for speech)
