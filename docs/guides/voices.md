# Available Voices

Local TTS ships 54 voices across 9 languages via the Kokoro TTS engine.
The player automatically filters voices to match the content language.

## American English (20 voices)

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

## British English (8 voices)

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

## Japanese (5 voices)

| Voice ID      | Description        |
|---------------|--------------------|
| jf_alpha      | Female, Alpha      |
| jf_gongitsune | Female, Gongitsune |
| jf_nezumi     | Female, Nezumi     |
| jf_tebukuro   | Female, Tebukuro   |
| jm_kumo       | Male, Kumo         |

## Mandarin Chinese (8 voices)

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

## French (1 voice)

| Voice ID  | Description    |
|-----------|----------------|
| ff_siwis  | Female, Siwis  |

## Hindi (4 voices)

| Voice ID   | Description     |
|------------|-----------------|
| hf_alpha   | Female, Alpha   |
| hf_beta    | Female, Beta    |
| hm_omega   | Male, Omega     |
| hm_psi     | Male, Psi       |

## Spanish (3 voices)

| Voice ID  | Description   |
|-----------|---------------|
| ef_dora   | Female, Dora  |
| em_alex   | Male, Alex    |
| em_santa  | Male, Santa   |

## Italian (2 voices)

| Voice ID     | Description       |
|--------------|-------------------|
| if_sara      | Female, Sara      |
| im_nicola    | Male, Nicola      |

## Portuguese (3 voices)

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
