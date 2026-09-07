/**
 * @file
 * Local TTS player functionality.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.aiTtsPlayer = {
    attach: function (context, settings) {
      const players = once('local-tts-player', '.local-tts-container', context);

      if (players.length === 0) {
        return;
      }

      players.forEach(function (player) {
        const playButton = player.querySelector('.local-tts-play-button');
        const labelContainer = player.querySelector('.local-tts-label');
        const labelText = player.querySelector('.local-tts-label-text');
        const labelDuration = player.querySelector('.local-tts-label-duration');
        const playbackControls = player.querySelector('.local-tts-playback-controls');
        const currentTimeDisplay = player.querySelector('.local-tts-current-time');
        const scrubberInput = player.querySelector('.local-tts-scrubber');
        const remainingTimeDisplay = player.querySelector('.local-tts-remaining-time');
        const voiceSelect = player.querySelector('.local-tts-voice-select');
        const speedInput = player.querySelector('.local-tts-speed-input');
        const statusDiv = player.querySelector('.local-tts-status');
        const audioElement = player.querySelector('audio');

        if (!playButton || !audioElement) {
          return;
        }

        const config = drupalSettings.aiTts;
        let isPlaying = false;
        let currentAudioUrl = null;
        let lastAriaUpdate = 0;
        let lastProgressSave = 0;
        const ARIA_THROTTLE_MS = 200;
        const PROGRESS_SAVE_MS = 5000;
        const storageKey = 'local_tts_progress_' + config.entityType + '_' + config.entityId;

        audioElement.loop = false;
        audioElement.preload = 'metadata';

        function formatDuration(seconds) {
          if (!seconds || !isFinite(seconds)) {
            return '';
          }
          const mins = Math.floor(seconds / 60);
          const secs = Math.floor(seconds % 60);
          return mins + ':' + (secs < 10 ? '0' : '') + secs;
        }

        function formatTimeForAria(seconds) {
          if (!seconds || !isFinite(seconds)) {
            return '0 seconds';
          }

          const mins = Math.floor(seconds / 60);
          const secs = Math.floor(seconds % 60);

          let parts = [];
          if (mins > 0) {
            parts.push(mins + ' minute' + (mins !== 1 ? 's' : ''));
          }
          if (secs > 0 || mins === 0) {
            parts.push(secs + ' second' + (secs !== 1 ? 's' : ''));
          }

          return parts.join(' ');
        }

        function getAriaTimeText(currentTime, duration) {
          const elapsed = formatTimeForAria(currentTime);
          const remaining = duration ? formatTimeForAria(duration - currentTime) : 'duration unknown';
          return elapsed + ' elapsed, ' + remaining + ' remaining';
        }

        function updateDurationDisplays(duration) {
          if (!duration || !isFinite(duration)) {
            return;
          }

          const formatted = formatDuration(duration);

          if (labelDuration) {
            labelDuration.textContent = ' · ' + formatted + ' min';
          }

          if (scrubberInput) {
            scrubberInput.max = duration;
            scrubberInput.setAttribute('aria-valuemax', duration.toString());
          }
        }

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
            playButton.setAttribute('aria-label', Drupal.t('Pause audio'));
          } else {
            playButton.setAttribute('aria-pressed', 'false');
            playButton.classList.remove('playing');
            playButton.setAttribute('aria-label', Drupal.t('Play audio'));
          }
        }

        function showPlaybackControls() {
          if (labelContainer) {
            labelContainer.style.display = 'none';
          }
          if (playbackControls) {
            playbackControls.style.display = '';
          }
        }

        function hidePlaybackControls() {
          if (playbackControls) {
            playbackControls.style.display = 'none';
          }
          if (labelContainer) {
            labelContainer.style.display = '';
          }
        }

        function restoreLabelAfterDelay() {
          setTimeout(function() {
            if (labelText && !isPlaying) {
              labelText.textContent = Drupal.t('Listen to this article');
              updateStatus('', 'status');
            }
          }, 8000);
        }

        function saveProgress(time) {
          try {
            localStorage.setItem(storageKey, time.toString());
          } catch (e) {
            // Storage unavailable; silently ignore.
          }
        }

        function getSavedProgress() {
          try {
            var saved = localStorage.getItem(storageKey);
            if (saved) {
              var value = parseFloat(saved);
              if (isFinite(value)) {
                return value;
              }
            }
          } catch (e) {
            // Storage unavailable; silently ignore.
          }
          return 0;
        }

        function clearSavedProgress() {
          try {
            localStorage.removeItem(storageKey);
          } catch (e) {
            // Storage unavailable; silently ignore.
          }
        }

        function pollForAudio(pollUrl) {
          var maxPollTime = 5 * 60 * 1000;
          var pollInterval = 3000;
          var startTime = Date.now();

          function poll() {
            if (Date.now() - startTime > maxPollTime) {
              var timeoutMsg = Drupal.t('Audio generation timed out. Please try again later.');
              if (labelText) {
                labelText.innerHTML = '<span class="local-tts-error">⚠</span> ' + timeoutMsg;
              }
              updateStatus(timeoutMsg, 'error');
              playButton.disabled = false;
              playButton.classList.remove('loading');
              updateButtonStates();
              restoreLabelAfterDelay();
              return;
            }

            fetch(pollUrl)
              .then(function (response) { return response.json(); })
              .then(function (data) {
                if (data.status === 'ready' && data.audio_url) {
                  currentAudioUrl = data.audio_url;
                  playSpeech(data.audio_url);
                } else {
                  setTimeout(poll, pollInterval);
                }
              })
              .catch(function () {
                setTimeout(poll, pollInterval);
              });
          }

          setTimeout(poll, pollInterval);
        }

        function generateSpeech() {
          const voice = voiceSelect ? voiceSelect.value : config.defaultVoice;
          const speed = speedInput ? parseFloat(speedInput.value) : config.defaultSpeed;

          if (labelText) {
            labelText.innerHTML = '<span class="local-tts-loading"></span> ' + Drupal.t('Generating speech...');
          }
          playButton.disabled = true;
          playButton.classList.add('loading');

          // SECURITY: Only entity reference sent, never content text.
          const formData = new FormData();
          formData.append('entity_type', config.entityType);
          formData.append('entity_id', config.entityId);
          formData.append('voice', voice);
          formData.append('speed', speed);

          if (config.language) {
            formData.append('language', config.language);
          }

          if (config.fields && config.fields.length > 0) {
            formData.append('fields', JSON.stringify(config.fields));
          }

          fetch(config.generateUrl, {
            method: 'POST',
            body: formData
          })
            .then(response => {
              if (response.status === 504) {
                return { ok: false, status: 504, data: {} };
              }

              return response.json().then(data => ({
                ok: response.ok,
                status: response.status,
                data: data
              })).catch(() => ({
                ok: response.ok,
                status: response.status,
                data: {}
              }));
            })
            .then(result => {
              if (result.ok && result.data.success && result.data.audio_url) {
                currentAudioUrl = result.data.audio_url;
                playSpeech(result.data.audio_url);
              } else if (result.ok && result.data.status === 'processing' && result.data.poll_url) {
                if (labelText) {
                  labelText.innerHTML = '<span class="local-tts-loading"></span> ' + Drupal.t('Generating speech... This may take a moment.');
                }
                pollForAudio(result.data.poll_url);
              } else {
                var errorMessage = result.data.message || Drupal.t('Unable to generate audio. Please try again later.');

                if (result.status === 429 && result.data.retry_after) {
                  var minutes = Math.ceil(result.data.retry_after / 60);
                  errorMessage = result.data.message || Drupal.t('Too many requests. Please try again in @minutes minutes.', {'@minutes': minutes});
                }

                if (labelText) {
                  labelText.innerHTML = '<span class="local-tts-error">⚠</span> ' + errorMessage;
                }
                updateStatus(errorMessage, 'error');
                playButton.disabled = false;
                playButton.classList.remove('loading');
                updateButtonStates();
                restoreLabelAfterDelay();
              }
            })
            .catch(error => {
              const errorMsg = Drupal.t('Unable to reach the server. Please check your internet connection and try again.');
              if (labelText) {
                labelText.innerHTML = '<span class="local-tts-error">⚠</span> ' + errorMsg;
              }
              updateStatus(errorMsg, 'error');
              playButton.disabled = false;
              playButton.classList.remove('loading');
              updateButtonStates();
              restoreLabelAfterDelay();
            });
        }

        function playSpeech(audioUrl) {
          audioElement.src = audioUrl;
          audioElement.load();

          audioElement.addEventListener('canplaythrough', function onCanPlay() {
            audioElement.removeEventListener('canplaythrough', onCanPlay);

            var savedPosition = getSavedProgress();

            audioElement.play().then(function() {
              isPlaying = true;
              playButton.disabled = false;
              playButton.classList.remove('loading');
              updateButtonStates();
              showPlaybackControls();
              updateStatus('', 'status');

              // Offer to resume from saved position.
              if (savedPosition > 5 && isFinite(savedPosition) && savedPosition < audioElement.duration - 5) {
                var formatted = formatDuration(savedPosition);
                var resumeHtml = '<a href="#" class="local-tts-resume">' +
                  Drupal.t('Resume from @time?', {'@time': formatted}) + '</a>';
                updateStatus(resumeHtml, 'status');

                var resumeLink = statusDiv.querySelector('.local-tts-resume');
                if (resumeLink) {
                  resumeLink.addEventListener('click', function (e) {
                    e.preventDefault();
                    audioElement.currentTime = savedPosition;
                    updateStatus('', 'status');
                  });
                }

                // Auto-dismiss after 10 seconds.
                setTimeout(function () {
                  if (statusDiv.querySelector('.local-tts-resume')) {
                    updateStatus('', 'status');
                    clearSavedProgress();
                  }
                }, 10000);
              }
            }).catch(function(error) {
              const errorMsg = Drupal.t('Error playing audio.');
              if (labelText) {
                labelText.innerHTML = '<span class="local-tts-error">⚠</span> ' + errorMsg;
              }
              updateStatus(errorMsg, 'error');
              playButton.disabled = false;
              playButton.classList.remove('loading');
              isPlaying = false;
              updateButtonStates();
              restoreLabelAfterDelay();
            });
          }, { once: true });
        }

        function togglePlay() {
          if (!isPlaying) {
            if (!currentAudioUrl || audioElement.ended) {
              generateSpeech();
            } else {
              audioElement.play().then(function() {
                isPlaying = true;
                updateButtonStates();
                showPlaybackControls();
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

        function stopPlayback() {
          if (audioElement) {
            audioElement.pause();
            audioElement.currentTime = 0;
          }
          isPlaying = false;
          currentAudioUrl = null;

          if (scrubberInput) {
            scrubberInput.value = 0;
            scrubberInput.disabled = true;
          }

          if (currentTimeDisplay) {
            currentTimeDisplay.textContent = '0:00';
          }
          if (remainingTimeDisplay) {
            remainingTimeDisplay.textContent = '';
          }

          if (labelText) {
            labelText.textContent = Drupal.t('Listen to this article');
          }

          hidePlaybackControls();
          updateButtonStates();
          updateStatus('', 'status');
        }

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

        audioElement.addEventListener('ended', function() {
          isPlaying = false;
          clearSavedProgress();
          updateButtonStates();
          stopPlayback();
        });

        audioElement.addEventListener('pause', function() {
          if (!audioElement.ended && audioElement.currentTime > 0) {
            isPlaying = false;
            updateButtonStates();
          }
        });

        audioElement.addEventListener('play', function() {
          isPlaying = true;
          updateButtonStates();
          showPlaybackControls();
        });

        audioElement.addEventListener('error', function() {
          const errorMsg = Drupal.t('Error playing audio.');
          if (labelText) {
            labelText.innerHTML = '<span class="local-tts-error">⚠</span> ' + errorMsg;
          }
          updateStatus(errorMsg, 'error');
          playButton.disabled = false;
          isPlaying = false;
          updateButtonStates();
          restoreLabelAfterDelay();
        });

        audioElement.addEventListener('loadedmetadata', function() {
          updateDurationDisplays(audioElement.duration);

          if (scrubberInput) {
            scrubberInput.disabled = false;
          }
        });

        // Visual-only updates during playback; ARIA on user interaction only.
        audioElement.addEventListener('timeupdate', function() {
          if (!audioElement.duration) {
            return;
          }

          const currentTime = audioElement.currentTime;
          const duration = audioElement.duration;
          const percentage = (currentTime / duration) * 100;

          if (scrubberInput) {
            scrubberInput.value = currentTime;
            scrubberInput.style.background = 'linear-gradient(to right, #121212 0%, #121212 ' + percentage + '%, #dfdfdf ' + percentage + '%, #dfdfdf 100%)';
          }

          if (currentTimeDisplay) {
            currentTimeDisplay.textContent = formatDuration(currentTime);
          }

          if (remainingTimeDisplay) {
            const remaining = duration - currentTime;
            remainingTimeDisplay.textContent = '-' + formatDuration(remaining);
          }

          // Persist playback progress (throttled).
          var now = Date.now();
          if (now - lastProgressSave >= PROGRESS_SAVE_MS) {
            lastProgressSave = now;
            saveProgress(currentTime);
          }
        });

        if (scrubberInput) {
          scrubberInput.addEventListener('input', function() {
            const newTime = parseFloat(scrubberInput.value);
            audioElement.currentTime = newTime;

            if (audioElement.duration) {
              const percentage = (newTime / audioElement.duration) * 100;
              scrubberInput.style.background = 'linear-gradient(to right, #121212 0%, #121212 ' + percentage + '%, #dfdfdf ' + percentage + '%, #dfdfdf 100%)';
            }

            // A11Y: Throttle ARIA updates to 200ms.
            const now = Date.now();
            if (now - lastAriaUpdate > ARIA_THROTTLE_MS) {
              scrubberInput.setAttribute('aria-valuenow', newTime.toString());
              scrubberInput.setAttribute('aria-valuetext', getAriaTimeText(newTime, audioElement.duration));
              lastAriaUpdate = now;
            }
          });

          scrubberInput.addEventListener('focus', function() {
            if (audioElement.duration) {
              scrubberInput.setAttribute('aria-valuenow', audioElement.currentTime.toString());
              scrubberInput.setAttribute('aria-valuetext', getAriaTimeText(audioElement.currentTime, audioElement.duration));
            }
          });

          let wasPlaying = false;
          scrubberInput.addEventListener('mousedown', function() {
            wasPlaying = !audioElement.paused;
            if (wasPlaying) {
              audioElement.pause();
            }
          });

          scrubberInput.addEventListener('mouseup', function() {
            if (wasPlaying) {
              audioElement.play();
            }
          });
        }

        if (voiceSelect) {
          voiceSelect.addEventListener('change', function() {
            if (isPlaying || currentAudioUrl) {
              stopPlayback();
            }
          });
        }

        if (speedInput) {
          speedInput.addEventListener('change', function() {
            if (isPlaying || currentAudioUrl) {
              stopPlayback();
            }
          });
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
