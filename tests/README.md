# Local TTS regression checks

Run the JavaScript lifecycle tests and linters from the module directory:

```sh
npm ci --ignore-scripts
npm test
npm run lint:js
npm run lint:css
```

The JavaScript tests execute the actual player and preview scripts in jsdom.
Audio and fetch are controlled test doubles so delayed responses, cancellation,
and overlapping requests can be reproduced deterministically. These tests do
not replace browser audio playback checks.

Run the PHP integration checks on an installed **sandbox** Drupal site, after
applying module database updates:

```sh
drush php:script /absolute/path/to/local_tts/tests/integration/cache-and-chunks.php
```

Export `LOCAL_TTS_INTEGRATION_SANDBOX=1` first; the script exits without it.

The PHP checks cover chunk boundaries, Unicode preservation, shared cache files,
metadata timestamps, orphan cleanup, the database uniqueness constraint, invalid
speeds, batch defaults, and the real flood backend. They temporarily replace
cache rows inside a transaction and roll it back, and use a separate temporary
file directory and in-memory configuration/state. Do not run these checks
against production or concurrently with requests that use the audio cache.
