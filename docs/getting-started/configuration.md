# Configuration

## Module settings

After enabling the module, visit **Administration > Configuration >
Media > Local TTS Settings** (`/admin/config/media/local-tts`) to
configure the module.

### eSpeak NG data path

Set the path to the `espeak-ng-data` directory on your server. Common
locations:

- **macOS (Homebrew)**:
  `/opt/homebrew/Cellar/espeak-ng/[version]/share/espeak-ng-data`
- **Linux**: `/usr/share/espeak-ng-data`

### Voice settings

- **Default voice per language**: set the preferred voice for each
  language your site supports
- **Default speech speed**: speed multiplier from 0.8x to 2.0x

### Cache settings

- **Audio caching**: enable or disable file-based caching of generated
  audio
- **Cache directory**: defaults to `public://local-tts/`
- **Maximum cache size**: limit on disk space used by cached files

### Security settings

- **Rate limiting**: limits the number of generation requests per time
  window
- **Maximum text length**: caps the amount of text that can be converted
  in a single request
- **Generation timeout**: maximum time allowed for a single generation
  request
- **Server load threshold**: pauses generation when server load exceeds
  this value

## Placing the player

### Block integration

For site-wide TTS on all content pages:

1. Go to **Structure > Block layout**
2. Place the **Local Text-to-Speech** block in a region (e.g. Content)
3. Configure which fields to include and voice/speed controls

### Field integration

For per-content-type control with Layout Builder support:

1. Go to **Structure > Content types > [Type] > Manage fields**
2. Add a field of type **Local TTS Player**
3. Position the field in **Manage display**
4. Configure voice/speed controls and field selection in the formatter
   settings

## Permissions

Two permissions control access:

- **Administer Local TTS settings**: access the configuration form
- **Generate local text-to-speech audio**: required for visitors to use
  the TTS player; grant this to the Anonymous role for public use
