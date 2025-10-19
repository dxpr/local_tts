/**
 * @file
 * AI TTS player functionality.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.aiTtsPlayer = {
    attach: function (context, settings) {
      const players = once('ai-tts-player', '.ai-tts-container', context);

      if (players.length === 0) {
        return;
      }

      players.forEach(function (player) {
        const playButton = player.querySelector('#ai-tts-play-button');
        const stopButton = player.querySelector('#ai-tts-stop-button');
        const voiceSelect = player.querySelector('#ai-tts-voice-select');
        const speedInput = player.querySelector('#ai-tts-speed-input');
        const statusDiv = player.querySelector('#ai-tts-status');
        const audioElement = player.querySelector('#ai-tts-audio');

        if (!playButton || !stopButton || !audioElement) {
          return;
        }

        const config = drupalSettings.aiTts || {};
        const content = config.content || '';

        if (!content) {
          player.style.display = 'none';
          return;
        }

        let isPlaying = false;
        let currentAudioUrl = null;

        audioElement.loop = false;

        function updateStatus(message, type) {
          type = type || 'info';
          if (message) {
            statusDiv.innerHTML = '<div class="messages messages--' + type + '">' + message + '</div>';
          } else {
            statusDiv.innerHTML = '';
          }
        }

        function updateButtonStates() {
          if (isPlaying) {
            playButton.value = Drupal.t('Pause');
            playButton.setAttribute('aria-pressed', 'true');
            stopButton.disabled = false;
          } else {
            if (currentAudioUrl && audioElement.currentTime > 0 && !audioElement.ended) {
              playButton.value = Drupal.t('Resume');
            } else {
              playButton.value = playButton.getAttribute('data-original-text');
            }
            playButton.setAttribute('aria-pressed', 'false');
          }
        }

        function generateSpeech(text) {
          const voice = voiceSelect ? voiceSelect.value : config.defaultVoice;
          const speed = speedInput ? parseFloat(speedInput.value) : config.defaultSpeed;

          updateStatus(Drupal.t('Generating speech...'), 'status');
          playButton.disabled = true;

          const formData = new FormData();
          formData.append('text', text);
          formData.append('voice', voice);
          formData.append('speed', speed);

          fetch(config.generateUrl, {
            method: 'POST',
            body: formData
          })
            .then(response => response.json())
            .then(data => {
              if (data.success && data.audio_url) {
                currentAudioUrl = data.audio_url;
                playSpeech(data.audio_url);
              } else {
                updateStatus(Drupal.t('Failed to generate speech.'), 'error');
                playButton.disabled = false;
                updateButtonStates();
              }
            })
            .catch(error => {
              updateStatus(Drupal.t('Error generating speech: @error', {'@error': error.message}), 'error');
              playButton.disabled = false;
              updateButtonStates();
            });
        }

        function playSpeech(audioUrl) {
          audioElement.src = audioUrl;
          audioElement.load();

          audioElement.addEventListener('canplaythrough', function onCanPlay() {
            audioElement.removeEventListener('canplaythrough', onCanPlay);
            audioElement.play().then(function() {
              isPlaying = true;
              playButton.disabled = false;
              updateButtonStates();
              updateStatus(Drupal.t('Playing...'), 'status');
            }).catch(function(error) {
              updateStatus(Drupal.t('Error playing audio.'), 'error');
              playButton.disabled = false;
              isPlaying = false;
              updateButtonStates();
            });
          }, { once: true });
        }

        function stopSpeech() {
          if (audioElement) {
            audioElement.pause();
            audioElement.currentTime = 0;
          }
          isPlaying = false;
          currentAudioUrl = null;
          stopButton.disabled = true;
          updateButtonStates();
          updateStatus('', 'status');
        }

        function togglePlay() {
          if (!isPlaying) {
            if (!currentAudioUrl || audioElement.ended) {
              generateSpeech(content);
            } else {
              audioElement.play().then(function() {
                isPlaying = true;
                updateButtonStates();
                updateStatus(Drupal.t('Playing...'), 'status');
              });
            }
          } else {
            audioElement.pause();
            isPlaying = false;
            updateButtonStates();
            updateStatus(Drupal.t('Paused'), 'status');
          }
        }

        audioElement.addEventListener('ended', function() {
          isPlaying = false;
          stopButton.disabled = true;
          updateButtonStates();
          updateStatus('', 'status');
        });

        audioElement.addEventListener('pause', function() {
          if (!audioElement.ended && audioElement.currentTime > 0) {
            isPlaying = false;
            updateButtonStates();
          }
        });

        audioElement.addEventListener('play', function() {
          isPlaying = true;
          stopButton.disabled = false;
          updateButtonStates();
        });

        audioElement.addEventListener('error', function() {
          updateStatus(Drupal.t('Error playing audio.'), 'error');
          playButton.disabled = false;
          isPlaying = false;
          stopButton.disabled = true;
          updateButtonStates();
        });

        playButton.setAttribute('data-original-text', playButton.value);

        playButton.addEventListener('click', function(e) {
          e.preventDefault();
          togglePlay();
        });

        stopButton.addEventListener('click', function(e) {
          e.preventDefault();
          stopSpeech();
        });

        if (voiceSelect) {
          voiceSelect.addEventListener('change', function() {
            if (isPlaying || currentAudioUrl) {
              stopSpeech();
            }
          });
        }

        if (speedInput) {
          speedInput.addEventListener('change', function() {
            if (isPlaying || currentAudioUrl) {
              stopSpeech();
            }
          });
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
