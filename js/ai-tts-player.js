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
            playButton.setAttribute('aria-pressed', 'true');
            playButton.classList.add('playing');
            stopButton.disabled = false;
          } else {
            playButton.setAttribute('aria-pressed', 'false');
            playButton.classList.remove('playing');
          }
        }

        function generateSpeech(text) {
          const voice = voiceSelect ? voiceSelect.value : config.defaultVoice;
          const speed = speedInput ? parseFloat(speedInput.value) : (config.defaultSpeed || 1);
          const language = config.language || 'en';

          updateStatus('<span class="ai-tts-loading"></span> ' + Drupal.t('Generating speech...'), 'status');
          playButton.disabled = true;
          playButton.classList.add('loading');

          const formData = new FormData();
          formData.append('text', text);
          formData.append('voice', voice);
          formData.append('speed', speed);
          formData.append('language', language);

          fetch(config.generateUrl, {
            method: 'POST',
            body: formData
          })
            .then(response => {
              // Parse JSON response regardless of status.
              return response.json().then(data => ({
                ok: response.ok,
                status: response.status,
                data: data
              }));
            })
            .then(result => {
              if (result.ok && result.data.success && result.data.audio_url) {
                currentAudioUrl = result.data.audio_url;
                playSpeech(result.data.audio_url);
              } else {
                // Handle error responses with proper messages.
                let errorMessage = result.data.message || 'Failed to generate speech';

                // Provide user-friendly messages based on HTTP status code.
                if (result.status === 400) {
                  // Bad Request - validation errors.
                  errorMessage = result.data.message || Drupal.t('Invalid request parameters');
                } else if (result.status === 408) {
                  // Request Timeout.
                  errorMessage = Drupal.t('Generation took too long. Try with shorter text.');
                } else if (result.status === 429) {
                  // Too Many Requests - rate limiting.
                  let retryMsg = '';
                  if (result.data.retry_after) {
                    const minutes = Math.ceil(result.data.retry_after / 60);
                    retryMsg = Drupal.t(' Try again in @minutes minutes.', {'@minutes': minutes});
                  }
                  errorMessage = Drupal.t('Rate limit exceeded.') + retryMsg;
                } else if (result.status === 500) {
                  // Internal Server Error.
                  errorMessage = Drupal.t('Server error. Please try again later.');
                } else if (result.status === 503) {
                  // Service Unavailable.
                  errorMessage = Drupal.t('Service temporarily unavailable. Please contact support.');
                }

                updateStatus(errorMessage, 'error');
                playButton.disabled = false;
                playButton.classList.remove('loading');
                updateButtonStates();
              }
            })
            .catch(error => {
              // Network errors or unexpected failures.
              updateStatus(Drupal.t('Network error. Please check your connection.'), 'error');
              playButton.disabled = false;
              playButton.classList.remove('loading');
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
              playButton.classList.remove('loading');
              updateButtonStates();
              updateStatus('', 'status');
            }).catch(function(error) {
              updateStatus(Drupal.t('Error playing audio.'), 'error');
              playButton.disabled = false;
              playButton.classList.remove('loading');
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
                updateStatus('', 'status');
              });
            }
          } else {
            audioElement.pause();
            isPlaying = false;
            updateButtonStates();
            updateStatus('', 'status');
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

        playButton.addEventListener('click', function(e) {
          e.preventDefault();
          togglePlay();
        });

        playButton.addEventListener('keydown', function(e) {
          if (e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            togglePlay();
          }
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
