# FAQ

## What languages does Local TTS support?

Local TTS supports 9 languages: American English, British English, Japanese,
Mandarin Chinese, French, Hindi, Spanish, Italian, and Portuguese. Each
language requires matching voices; the player automatically filters the
voice list to the content's language. See the
[Available Voices](voices.md) page for the complete list.

## Does my content leave the server?

No. All speech generation happens locally using the bundled Kokoro binary and
ONNX model files. No text or audio is sent to any external service. The only
system dependency is eSpeak NG, which also runs locally.

## How large are the model files?

The model (`kokoro-v1.0.onnx`) is approximately 310MB and the voice data
(`voices-v1.0.bin`) is approximately 27MB. These files are downloaded
automatically during `composer install`.

## What audio format is used?

Audio is generated as OGG Opus, which provides excellent compression at high
quality. A typical article produces files in the 5 to 50KB range. The module
first generates WAV via the Kokoro engine, then transcodes to OGG Opus using
ffmpeg. ffmpeg must be installed on the server (see
[Installation](../getting-started/installation.md)).

## How does caching work?

Each generated audio file is cached on disk with a key derived from the text
content hash, voice ID, speed, and language code. When a visitor requests
audio for a page, the module checks for a cached file first and only generates
new audio if the content has changed. A text hash stored in the database
enables fast change detection without regenerating the audio. The cache
directory defaults to `public://local-tts/` and its size is configurable.

Orphan audio files (files on disk with no matching database record) are
automatically cleaned up during cron, rate-limited to once every 6 hours.

## How does queue-based generation work?

When a visitor clicks play on uncached content, the module queues a background
generation job and returns a polling URL. The player's JavaScript checks the
status endpoint every 3 seconds, showing "Generating speech..." until the
audio is ready. Queue items are processed during cron; for faster delivery,
run cron frequently or use `drush queue:run local_tts_generate`.

## How does the module handle long content?

Content over 2000 characters is automatically split at sentence boundaries
(full stops, exclamation marks, question marks). Each chunk is generated
separately, then all chunks are concatenated and transcoded into a single
OGG Opus file in one ffmpeg pass.

## Can I preview voices before selecting one?

Yes. The settings page at `/admin/config/media/local-tts` includes a
"Preview" button next to each voice dropdown. Clicking it generates a short
sample sentence in the selected voice and speed.

## Does the player remember where I stopped?

Yes. Playback position is saved to the browser's localStorage every 5 seconds.
When a visitor returns to the same page, the player offers a "Resume from
X:XX?" link. Progress is cleared when playback reaches the end.

## Can I use Local TTS without eSpeak NG?

No. eSpeak NG is required for converting text to phonemes, which the Kokoro
engine then converts to speech. Without eSpeak NG, generation will fail.

## Does Local TTS work with page caching?

Yes. The audio is loaded via AJAX when the visitor clicks the play button, so
the page itself can be fully cached. The AJAX endpoint checks for a cached
audio file and serves it directly if one exists.

## What happens if the server is under heavy load?

Local TTS includes a configurable server load threshold. When the system load
average exceeds this threshold, audio generation is paused and visitors see
an "Audio temporarily unavailable" message. Cached audio that was generated
before the load spike is still served normally.

## Why does the player not appear on some pages?

The player only appears on content that is publicly viewable (accessible to
anonymous users). Check that:

- The content is published
- Anonymous users have the "Generate local text-to-speech audio" permission
- Voices are available for the content's language
  (`drush local-tts:voices --language=en`)
- The block or field is placed and configured correctly

## Can I add custom voices?

Not at present. Local TTS uses the voices bundled with the Kokoro model.
New voices would require training a custom ONNX model, which is outside the
scope of this module.

## How do I protect the binary and model files on Nginx?

Add location blocks to your Nginx server config:

```nginx
location ~ ^/modules/(contrib|custom)/local_tts/(bin|data)/ {
    deny all;
    return 403;
}
```

Apache protection is handled automatically by the `.htaccess` files bundled
with the module.
