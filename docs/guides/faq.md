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

## How does caching work?

Each generated audio file is cached on disk with a key derived from the text
content hash, voice ID, speed, and language code. When a visitor requests
audio for a page, the module checks for a cached file first and only generates
new audio if the content has changed. The cache directory defaults to
`public://local-tts/` and its size is configurable.

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
