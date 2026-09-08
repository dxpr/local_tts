# Local Text-to-Speech for Drupal

Add a "Listen to this page" button to any Drupal content. Fully
self-hosted: no cloud APIs, no subscriptions, no data leaving your
server.

## Hear it

Listen to a few of the 54 built-in voices:

**English (US)** · af_alloy
<audio controls preload="none" src="audio/af_alloy-sample.ogg"></audio>

**English (UK)** · bf_emma
<audio controls preload="none" src="audio/bf_emma-sample.ogg"></audio>

**Spanish** · ef_dora
<audio controls preload="none" src="audio/ef_dora-sample.ogg"></audio>

**Japanese** · jf_alpha
<audio controls preload="none" src="audio/jf_alpha-sample.ogg"></audio>

[All 54 voices across 9 languages](using/choosing-a-voice.md)

## Add it to your site

Local TTS integrates with Drupal through four methods. Pick the one
that fits your setup:

| Method | Best for | Setup |
|--------|----------|-------|
| **[Block](integrations/block.md)** | Site-wide player on every content page | Place block in a region |
| **[Entity field](integrations/field.md)** | Per-content-type voice/speed control | Add field to selected bundles |
| **[Extra field](integrations/extra-field.md)** | Quick toggle, no field storage | Enable in Manage Display |
| **[Views field](integrations/views-field.md)** | Player per row in content listings | Add field to a View |

All methods share the same player UI, accessibility features, and
caching system. [Which should I use?](integrations/index.md)

## How it works

1. A visitor clicks "Listen to this page"
2. The module extracts text server-side by rendering the entity
3. If a cached audio file exists for the current content, it is served
   immediately
4. Otherwise a background job generates the audio; the player polls
   until it is ready
5. The visitor hears OGG Opus audio and can pause, seek, and resume
   on return visits

## Key capabilities

| Capability | Detail |
|------------|--------|
| Voices | 54 voices across 9 languages ([browse](using/choosing-a-voice.md)) |
| Audio format | OGG Opus, small files, broad browser support |
| Speed control | 0.5x to 2.0x, adjustable per voice |
| Caching | Content-hash based; auto-invalidates on edit |
| Long content | Chunked at sentence boundaries, concatenated into one file |
| Batch generation | [Admin UI or Drush](managing-audio/batch-generation.md); date filtering, dry-run |
| Auto-generate | Queue audio on content save; ready before the first visit |
| Accessibility | ARIA labels, keyboard shortcuts, screen reader support |
| Privacy | All processing local; no external API calls |
