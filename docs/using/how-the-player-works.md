# How the Player Works

This page explains what visitors see and how to use the TTS player.

## What the visitor sees

When a page has TTS enabled, a player bar appears with:

- A **play/pause** button
- A **progress scrubber** showing elapsed and total time
- A **speed selector** (0.5x to 2.0x)
- An optional **download** button (if enabled in
  [settings](../setting-up/configuration.md#player-settings))

The player also announces state changes to screen readers via ARIA live
regions.

## First play

Clicking play for the first time on a page without cached audio
triggers background generation. The player shows "Generating
speech..." and polls the server every 3 seconds. Once the audio is
ready, playback starts automatically.

Pages with cached audio begin playing immediately.

## Resuming playback

The player saves your position to the browser's localStorage every
5 seconds. When you return to the same page, the player shows a
"Resume from X:XX?" link. Clicking it jumps to where you left off.
Progress is cleared when playback reaches the end.

## Keyboard shortcuts

The player supports standard keyboard interaction:

| Key | Action |
|-----|--------|
| Space / Enter | Play or pause |
| Arrow left / right | Seek backward / forward |
| Tab | Move between player controls |

## How text is extracted

The module never receives text from the browser. Instead, the server
loads the entity and renders it, extracting plain text from the
configured fields. This means computed fields, Views output, and
block field content are all included in the audio. It also prevents
content injection: the client sends only an entity reference, not
the content itself.

## Long content

Content over 2,000 characters is split at sentence boundaries. Each
chunk is generated separately, then all chunks are concatenated into
a single OGG file in one ffmpeg pass. The result is seamless playback
regardless of content length.

## Caching

Each audio file is cached with a key derived from the text hash,
voice, speed, and language. When content is edited, the hash changes
and the old audio is invalidated automatically. See
[Keeping audio current](../managing-audio/cache.md) for details.
