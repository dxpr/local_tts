/**
 * @file
 * Voice preview functionality for the Local TTS settings page.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.localTtsVoicePreview = {
    attach: function (context) {
      var previewUrl = drupalSettings.localTts && drupalSettings.localTts.voicePreviewUrl;
      if (!previewUrl) {
        return;
      }

      var forms = once('local-tts-voice-preview', '#local-tts-settings-form', context);
      if (!forms.length) {
        return;
      }

      var form = forms[0];
      var currentAudio = null;
      var currentButton = null;

      // Find all voice select elements (skip default_speed).
      var selects = form.querySelectorAll('select[name^="voice_settings["]');

      selects.forEach(function (select) {
        if (select.name === 'voice_settings[default_speed]') {
          return;
        }

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'button button--small local-tts-preview-btn';
        button.textContent = Drupal.t('Preview');
        button.style.marginLeft = '0.5em';

        select.parentNode.insertBefore(button, select.nextSibling);

        button.addEventListener('click', function () {
          // Toggle off if already playing from this button.
          if (currentAudio && currentButton === button) {
            stopPreview();
            return;
          }

          // Stop any other playing preview.
          if (currentAudio) {
            stopPreview();
          }

          var voice = select.value;
          var speedSelect = form.querySelector(
            'select[name="voice_settings[default_speed]"]'
          );
          var speed = speedSelect ? speedSelect.value : '1';

          button.textContent = Drupal.t('Loading...');
          button.disabled = true;

          var url = previewUrl
            + '?voice=' + encodeURIComponent(voice)
            + '&speed=' + encodeURIComponent(speed);

          fetch(url, {credentials: 'same-origin'})
            .then(function (response) {
              var contentType = response.headers.get('content-type') || '';
              if (contentType.indexOf('application/json') === -1) {
                throw new Error(Drupal.t('The server returned an unexpected response. Check that the TTS binary and ffmpeg are installed.'));
              }
              return response.json().then(function (data) {
                return {ok: response.ok, status: response.status, data: data};
              });
            })
            .then(function (result) {
              if (result.ok && result.data.success && result.data.audio_url) {
                playPreview(result.data.audio_url, button);
              }
              else if (result.status === 503) {
                showError(button, result.data.message || Drupal.t('The TTS service is unavailable. The TTS binary or ffmpeg may not be installed.'));
              }
              else {
                showError(button, result.data.message || Drupal.t('Voice preview failed.'));
              }
            })
            .catch(function (err) {
              showError(button, err.message || Drupal.t('Could not connect to the server.'));
            });
        });
      });

      /**
       * Play a preview audio file.
       */
      function playPreview(audioUrl, button) {
        var audio = new Audio(audioUrl);
        currentAudio = audio;
        currentButton = button;

        button.textContent = Drupal.t('Stop');
        button.disabled = false;
        button.classList.add('is-active');

        audio.addEventListener('ended', function () {
          resetButton(button);
          currentAudio = null;
          currentButton = null;
        });

        audio.addEventListener('error', function () {
          showError(button, Drupal.t('Audio playback failed. The file may be corrupted.'));
          currentAudio = null;
          currentButton = null;
        });

        audio.play().catch(function () {
          showError(button, Drupal.t('Audio playback was blocked by the browser.'));
          currentAudio = null;
          currentButton = null;
        });
      }

      /**
       * Stop the currently playing preview.
       */
      function stopPreview() {
        if (currentAudio) {
          currentAudio.pause();
          currentAudio.currentTime = 0;
        }
        if (currentButton) {
          resetButton(currentButton);
        }
        currentAudio = null;
        currentButton = null;
      }

      /**
       * Reset a button to its default state.
       */
      function resetButton(button) {
        button.textContent = Drupal.t('Preview');
        button.disabled = false;
        button.classList.remove('is-active');
      }

      /**
       * Show an error via Drupal's messages API and reset the button.
       */
      function showError(button, message) {
        resetButton(button);
        var messenger = new Drupal.Message();
        messenger.clear();
        messenger.add(message, {type: 'error'});
      }
    }
  };

})(Drupal, drupalSettings, once);
