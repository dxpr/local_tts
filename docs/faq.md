# FAQ

## Does my content leave the server?

No. All speech generation happens locally using the bundled Kokoro binary and
ONNX model files. No text or audio is sent to any external service.

## What audio format is used?

OGG Opus. The module generates WAV via Kokoro, then transcodes to OGG Opus
using ffmpeg. File sizes range from tens of KB for short text to several MB
for long-form articles.

## How large are the model files?

The model (`kokoro-v1.0.onnx`) is approximately 310 MB and the voice data
(`voices-v1.0.bin`) is approximately 27 MB. Both are downloaded automatically
during `composer install`.

## Does Local TTS work with page caching?

Yes. Audio is loaded via AJAX when the visitor clicks play, so the page itself
can be fully cached.

## Can I use Local TTS without eSpeak NG?

No. eSpeak NG is required for converting text to phonemes. Without it,
generation will fail.

## What happens if the server is under heavy load?

The module includes a configurable server load threshold. When system load
exceeds it, generation is paused and visitors see "Audio temporarily
unavailable." Cached audio is still served normally.

## Can I add custom voices?

Not at present. Local TTS uses the voices bundled with the Kokoro model.
Custom voices would require training a new ONNX model.
