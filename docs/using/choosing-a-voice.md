# Choosing a Voice

Local TTS ships 54 voices across 9 languages via the Kokoro TTS engine.
The player automatically filters voices to match the content language.

## Picking the right voice

Consider these factors when choosing a default voice:

- **Audience**: a university site might prefer a clear, neutral voice
  (af_alloy or bf_emma); a creative agency might pick something warmer
  (af_heart or bm_fable).
- **Language match**: the module only shows voices that match the
  content language. If your site is multilingual, set a default voice
  per language on the
  [Voices tab](../setting-up/configuration.md#default-voice-per-language).
- **Speed**: each voice sounds slightly different at different speeds.
  Use the Preview button on the Voices settings tab to compare before
  committing.
- **Consistency**: pick one male and one female voice per language for
  your site and use them everywhere, rather than mixing many voices
  across pages.

## Voice samples

Listen to a sample from each language below, or use the Preview buttons
on the [Voices settings tab](../setting-up/configuration.md#voice-settings)
to hear any voice with custom speed settings.

### American English (20 voices)

<audio controls preload="none" src="../../audio/af_alloy-sample.ogg"></audio>
*Sample: af_alloy (Alloy, Female)*

<audio controls preload="none" src="../../audio/am_adam-sample.ogg"></audio>
*Sample: am_adam (Adam, Male)*

| Voice ID    | Description          |
|-------------|----------------------|
| af_alloy    | Female, Alloy        |
| af_aoede    | Female, Aoede        |
| af_bella    | Female, Bella        |
| af_heart    | Female, Heart        |
| af_jessica  | Female, Jessica      |
| af_kore     | Female, Kore         |
| af_nicole   | Female, Nicole       |
| af_nova     | Female, Nova         |
| af_river    | Female, River        |
| af_sarah    | Female, Sarah        |
| af_sky      | Female, Sky          |
| am_adam     | Male, Adam           |
| am_echo    | Male, Echo           |
| am_eric    | Male, Eric           |
| am_fenrir  | Male, Fenrir         |
| am_liam    | Male, Liam           |
| am_michael | Male, Michael        |
| am_onyx    | Male, Onyx           |
| am_puck    | Male, Puck           |
| am_santa   | Male, Santa          |

### British English (8 voices)

<audio controls preload="none" src="../../audio/bf_emma-sample.ogg"></audio>
*Sample: bf_emma (Emma, Female)*

<audio controls preload="none" src="../../audio/bm_fable-sample.ogg"></audio>
*Sample: bm_fable (Fable, Male)*

| Voice ID     | Description          |
|--------------|----------------------|
| bf_alice     | Female, Alice        |
| bf_emma      | Female, Emma         |
| bf_isabella  | Female, Isabella     |
| bf_lily      | Female, Lily         |
| bm_daniel    | Male, Daniel         |
| bm_fable     | Male, Fable          |
| bm_george    | Male, George         |
| bm_lewis     | Male, Lewis          |

### Japanese (5 voices)

<audio controls preload="none" src="../../audio/jf_alpha-sample.ogg"></audio>
*Sample: jf_alpha (Alpha, Female)*

| Voice ID      | Description        |
|---------------|--------------------|
| jf_alpha      | Female, Alpha      |
| jf_gongitsune | Female, Gongitsune |
| jf_nezumi     | Female, Nezumi     |
| jf_tebukuro   | Female, Tebukuro   |
| jm_kumo       | Male, Kumo         |

### Mandarin Chinese (8 voices)

<audio controls preload="none" src="../../audio/zf_xiaobei-sample.ogg"></audio>
*Sample: zf_xiaobei (Xiaobei, Female)*

| Voice ID    | Description      |
|-------------|------------------|
| zf_xiaobei  | Female, Xiaobei  |
| zf_xiaoni   | Female, Xiaoni   |
| zf_xiaoxiao | Female, Xiaoxiao |
| zf_xiaoyi   | Female, Xiaoyi   |
| zm_yunjian  | Male, Yunjian    |
| zm_yunxi    | Male, Yunxi      |
| zm_yunxia   | Male, Yunxia     |
| zm_yunyang  | Male, Yunyang    |

### French (1 voice)

*French voice generation was unavailable at sample recording time.*

| Voice ID  | Description    |
|-----------|----------------|
| ff_siwis  | Female, Siwis  |

### Hindi (4 voices)

<audio controls preload="none" src="../../audio/hf_alpha-sample.ogg"></audio>
*Sample: hf_alpha (Alpha, Female)*

| Voice ID   | Description     |
|------------|-----------------|
| hf_alpha   | Female, Alpha   |
| hf_beta    | Female, Beta    |
| hm_omega   | Male, Omega     |
| hm_psi     | Male, Psi       |

### Spanish (3 voices)

<audio controls preload="none" src="../../audio/ef_dora-sample.ogg"></audio>
*Sample: ef_dora (Dora, Female)*

| Voice ID  | Description   |
|-----------|---------------|
| ef_dora   | Female, Dora  |
| em_alex   | Male, Alex    |
| em_santa  | Male, Santa   |

### Italian (2 voices)

<audio controls preload="none" src="../../audio/if_sara-sample.ogg"></audio>
*Sample: if_sara (Sara, Female)*

| Voice ID     | Description       |
|--------------|-------------------|
| if_sara      | Female, Sara      |
| im_nicola    | Male, Nicola      |

### Portuguese (3 voices)

*Portuguese voice generation was unavailable at sample recording time.*

| Voice ID   | Description     |
|------------|-----------------|
| pf_dora    | Female, Dora    |
| pm_alex    | Male, Alex      |
| pm_santa   | Male, Santa     |

## Drush voice commands

```bash
# List all available voices
drush local-tts:voices

# Filter by language
drush local-tts:voices --language=es

# Test a specific voice
drush local-tts:test "Hello World" --voice=bf_emma
```
