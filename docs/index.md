# Local Text-to-Speech for Drupal

Local text-to-speech for Drupal using the Kokoro TTS engine. 54 voices across
9 languages, fully self-hosted with no cloud APIs, no subscriptions, and no
data leaving your server.

## Features

- **54 voices across 9 languages**: American English (20), British English (8),
  Japanese (5), Mandarin Chinese (8), French (1), Hindi (4),
  Spanish (3), Italian (2), Portuguese (3)
- **Language-aware voice filtering**: automatically shows only voices matching
  the content language
- **Adjustable speech speed**: 0.5x to 2.0x
- **Audio caching**: generated files cached on disk to reduce server load
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
3. The server validates anonymous access to the entity
4. Kokoro TTS generates speech via eSpeak NG phoneme processing
5. The audio file is cached in `public://local-tts/`
6. An HTML5 `<audio>` element plays the result

Cache keys are based on the text content hash, voice, speed, and language code,
so regeneration only happens when the content changes.

## Architecture

- **TtsService**: core service interfacing with the `koko` binary
- **TtsPlayerBuilder**: shared service for building the player UI
- **TtsController**: AJAX endpoint for audio generation
- **LocalTtsBlock**: block plugin for site-wide TTS
- **LocalTtsPlayerItem**: field type for per-content TTS
- **LocalTtsPlayerFormatter**: field formatter with voice/speed settings

## Related modules

- [DXPR CMS](https://www.drupal.org/project/dxpr_cms): Drupal pre-configured
  with DXPR Builder, DXPR Theme, and security best practices
- [AI module](https://www.drupal.org/project/ai): Drupal's AI integration
  framework (Local TTS is a standalone alternative for speech)
