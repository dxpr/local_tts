# Contributing

## Issue queue

Report bugs and feature requests on the
[drupal.org issue queue](https://www.drupal.org/project/issues/local_tts).

## Source code

- [git.drupalcode.org/project/local_tts](https://git.drupalcode.org/project/local_tts)
  (primary)
- [github.com/dxpr/local_tts](https://github.com/dxpr/local_tts) (mirror)

Pull requests are accepted on GitHub and mirrored to drupal.org.

## Branch conventions

- `1.x`: main development branch
- Feature branches: `feat/<description>` or `feature/<description>`
- Bug fixes: `fix/<description>`

## Coding standards

The project enforces Drupal coding standards and ESLint for JavaScript.
All checks run automatically on pull requests.

### PHP (Drupal coding standards)

```bash
docker compose --profile lint run --rm drupal-lint
```

Auto-fix coding standard violations:

```bash
docker compose --profile lint run --rm drupal-lint-auto-fix
```

### Drupal compatibility

```bash
docker compose --profile lint run --rm drupal-check
```

### JavaScript (ESLint)

```bash
npm ci
npx eslint .
```

## Architecture overview

The module is built around these core services:

- `local_tts.tts_service`: interfaces with the `koko` binary for speech
  generation, handles eSpeak NG phoneme processing, model selection, and
  audio file caching
- `local_tts.player_builder`: builds the player render array with voice
  controls, speed slider, and accessibility markup

The block plugin (`LocalTtsBlock`) and field formatter
(`LocalTtsPlayerFormatter`) both delegate to `TtsPlayerBuilder` for the
player UI, so the rendering logic is shared.

Audio generation flows through `TtsController`, which validates the
request, calls `TtsService::generateSpeech()`, and returns a JSON
response with the audio file URL.
