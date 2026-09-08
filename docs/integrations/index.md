# Adding TTS to Your Site

Local TTS offers four ways to place the audio player on your pages.
Pick the method that fits your site's needs:

| Method | Best for | Scope | Layout Builder |
|--------|----------|-------|:--------------:|
| [Block](block.md) | Site-wide TTS on every content page | All content in a region | Yes |
| [Entity field](field.md) | Per-content-type control | Selected bundles | Yes |
| [Extra field](extra-field.md) | Quick, no-field-storage option | Per display mode | Yes |
| [Views field](views-field.md) | Content listings with per-row players | Views displays | N/A |

## Which should I use?

**Start with the block** if you want every page on your site to have a
player. It auto-detects the current entity and works immediately after
placing it.

**Use the entity field** when you need different voice or speed settings
per content type, or when you want the player to appear only on certain
bundles (e.g. articles but not landing pages). The field formatter
stores voice, speed, and volume settings per display mode.

**Use the extra field** when you want the same result as the entity
field but without adding a real field to the database. The extra field
is toggled on or off in Manage Display and uses the site-wide voice
defaults.

**Use the Views field** when you build content listings (e.g. a blog
index or podcast archive) and want a player on each row. The Views
field renders a player for each entity in the View.

## Combining methods

The module deduplicates players automatically: if both a block and a
field are present on the same page, only one player appears. You can
safely enable the block site-wide and also add fields to specific
content types.

## Prerequisites

All methods require:

1. The module is [installed and enabled](../setting-up/installation.md)
2. The eSpeak NG path is [configured](../setting-up/configuration.md)
3. The "Generate local text-to-speech audio" permission is granted to
   the appropriate role (Anonymous for public use)
