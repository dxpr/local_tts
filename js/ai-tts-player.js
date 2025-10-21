/**
 * @file
 * AI TTS player functionality - NYTimes-style interface.
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
        const playButton = player.querySelector('.ai-tts-play-button');
        const labelContainer = player.querySelector('.ai-tts-label');
        const labelText = player.querySelector('.ai-tts-label-text');
        const labelDuration = player.querySelector('.ai-tts-label-duration');
        const playbackControls = player.querySelector('.ai-tts-playback-controls');
        const currentTimeDisplay = player.querySelector('.ai-tts-current-time');
        const scrubberInput = player.querySelector('.ai-tts-scrubber');
        const remainingTimeDisplay = player.querySelector('.ai-tts-remaining-time');
        const voiceSelect = player.querySelector('.ai-tts-voice-select');
        const speedInput = player.querySelector('.ai-tts-speed-input');
        const statusDiv = player.querySelector('.ai-tts-status');
        const audioElement = player.querySelector('audio');

        if (!playButton || !audioElement) {
          return;
        }

        // ARCHITECTURE: Fail hard. If config missing, block wouldn't render.
        const config = drupalSettings.aiTts;

        let isPlaying = false;
        let currentAudioUrl = null;
        let lastAriaUpdate = 0;
        const ARIA_THROTTLE_MS = 200;

        audioElement.loop = false;
        audioElement.preload = 'metadata';

        // Play/pause icons are handled via CSS background-images

        /**
         * Format seconds as M:SS or MM:SS.
         */
        function formatDuration(seconds) {
          if (!seconds || !isFinite(seconds)) {
            return '';
          }
          const mins = Math.floor(seconds / 60);
          const secs = Math.floor(seconds % 60);
          return mins + ':' + (secs < 10 ? '0' : '') + secs;
        }

        /**
         * Format time for ARIA announcements (human-readable).
         */
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

        /**
         * Get ARIA valuetext for scrubber.
         */
        function getAriaTimeText(currentTime, duration) {
          const elapsed = formatTimeForAria(currentTime);
          const remaining = duration ? formatTimeForAria(duration - currentTime) : 'duration unknown';

          return elapsed + ' elapsed, ' + remaining + ' remaining';
        }

        /**
         * Update duration displays when metadata loads.
         */
        function updateDurationDisplays(duration) {
          if (!duration || !isFinite(duration)) {
            return;
          }

          const formatted = formatDuration(duration);

          // Update label: "Listen to this article · 7:35 min"
          if (labelDuration) {
            labelDuration.textContent = ' · ' + formatted + ' min';
          }

          // Update scrubber max.
          if (scrubberInput) {
            scrubberInput.max = duration;
            scrubberInput.setAttribute('aria-valuemax', duration.toString());
          }
        }

        /**
         * Update status message.
         */
        function updateStatus(message, type) {
          type = type || 'info';
          if (message) {
            statusDiv.innerHTML = '<div class="messages messages--' + type + '">' + message + '</div>';
          } else {
            statusDiv.innerHTML = '';
          }
        }

        /**
         * Update button and UI state.
         */
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

        /**
         * Show playback controls (scrubber, time displays) and hide label.
         */
        function showPlaybackControls() {
          if (labelContainer) {
            labelContainer.style.display = 'none';
          }
          if (playbackControls) {
            playbackControls.style.display = '';
          }
        }

        /**
         * Hide playback controls and show label.
         */
        function hidePlaybackControls() {
          if (playbackControls) {
            playbackControls.style.display = 'none';
          }
          if (labelContainer) {
            labelContainer.style.display = '';
          }
        }

        /**
         * Generate speech via AJAX.
         */
        function generateSpeech() {
          const voice = voiceSelect ? voiceSelect.value : config.defaultVoice;
          const speed = speedInput ? parseFloat(speedInput.value) : config.defaultSpeed;

          // Show loading in label area (replaces "Listen to this article").
          if (labelText) {
            labelText.innerHTML = '<span class="ai-tts-loading"></span> ' + Drupal.t('Generating speech...');
          }
          playButton.disabled = true;
          playButton.classList.add('loading');

          // SECURITY: Only entity reference sent, never content text.
          const formData = new FormData();
          formData.append('entity_type', config.entityType);
          formData.append('entity_id', config.entityId);
          formData.append('voice', voice);
          formData.append('speed', speed);

          // Send language to load correct translation.
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
              // Handle timeout and server errors that may not return JSON.
              if (response.status === 504) {
                return {
                  ok: false,
                  status: 504,
                  data: {}
                };
              }

              // Parse JSON response for other status codes.
              return response.json().then(data => ({
                ok: response.ok,
                status: response.status,
                data: data
              })).catch(() => ({
                // If JSON parsing fails, return response status anyway.
                ok: response.ok,
                status: response.status,
                data: {}
              }));
            })
            .then(result => {
              if (result.ok && result.data.success && result.data.audio_url) {
                currentAudioUrl = result.data.audio_url;
                playSpeech(result.data.audio_url);
              } else {
                // Handle error responses with user-friendly messages.
                let errorMessage = result.data.message || Drupal.t('Unable to generate audio');

                // Provide context-appropriate messages based on HTTP status code.
                if (result.status === 400) {
                  errorMessage = result.data.message || Drupal.t('Please check your text and try again');
                } else if (result.status === 408) {
                  errorMessage = Drupal.t('Audio generation is taking longer than expected. Please try with shorter text.');
                } else if (result.status === 429) {
                  let retryMsg = '';
                  if (result.data.retry_after) {
                    const minutes = Math.ceil(result.data.retry_after / 60);
                    retryMsg = Drupal.t(' Please try again in @minutes minutes.', {'@minutes': minutes});
                  }
                  errorMessage = Drupal.t('Too many requests at once.') + retryMsg;
                } else if (result.status === 500) {
                  errorMessage = Drupal.t('Something went wrong on our end. Please try again in a few moments.');
                } else if (result.status === 503) {
                  errorMessage = Drupal.t('The service is temporarily unavailable. Please try again later or contact support if this continues.');
                } else if (result.status === 504) {
                  errorMessage = Drupal.t('The server is taking too long to process your request. Please try with shorter text or contact support.');
                }

                // Show error in label area.
                if (labelText) {
                  labelText.innerHTML = '<span class="ai-tts-error">⚠</span> ' + errorMessage;
                }
                updateStatus(errorMessage, 'error');
                playButton.disabled = false;
                playButton.classList.remove('loading');
                updateButtonStates();

                // Restore original label after 5 seconds.
                setTimeout(function() {
                  if (labelText && !isPlaying) {
                    labelText.textContent = Drupal.t('Listen to this article');
                    updateStatus('', 'status');
                  }
                }, 5000);
              }
            })
            .catch(error => {
              // Actual network errors (user's connection or browser issues).
              const errorMsg = Drupal.t('Unable to reach the server. Please check your internet connection and try again.');
              if (labelText) {
                labelText.innerHTML = '<span class="ai-tts-error">⚠</span> ' + errorMsg;
              }
              updateStatus(errorMsg, 'error');
              playButton.disabled = false;
              playButton.classList.remove('loading');
              updateButtonStates();

              // Restore original label after 5 seconds.
              setTimeout(function() {
                if (labelText && !isPlaying) {
                  labelText.textContent = Drupal.t('Listen to this article');
                  updateStatus('', 'status');
                }
              }, 5000);
            });
        }

        /**
         * Load and play audio.
         */
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
              showPlaybackControls();
              updateStatus('', 'status');
            }).catch(function(error) {
              const errorMsg = Drupal.t('Error playing audio.');
              if (labelText) {
                labelText.innerHTML = '<span class="ai-tts-error">⚠</span> ' + errorMsg;
              }
              updateStatus(errorMsg, 'error');
              playButton.disabled = false;
              playButton.classList.remove('loading');
              isPlaying = false;
              updateButtonStates();

              // Restore original label after 5 seconds.
              setTimeout(function() {
                if (labelText && !isPlaying) {
                  labelText.textContent = Drupal.t('Listen to this article');
                  updateStatus('', 'status');
                }
              }, 5000);
            });
          }, { once: true });
        }

        /**
         * Toggle play/pause.
         */
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

        /**
         * Stop and reset playback.
         */
        function stopPlayback() {
          if (audioElement) {
            audioElement.pause();
            audioElement.currentTime = 0;
          }
          isPlaying = false;
          currentAudioUrl = null;

          // Reset scrubber.
          if (scrubberInput) {
            scrubberInput.value = 0;
            scrubberInput.disabled = true;
          }

          // Reset time displays.
          if (currentTimeDisplay) {
            currentTimeDisplay.textContent = '0:00';
          }
          if (remainingTimeDisplay) {
            remainingTimeDisplay.textContent = '';
          }

          // Restore original label text.
          if (labelText) {
            labelText.textContent = Drupal.t('Listen to this article');
          }

          hidePlaybackControls();
          updateButtonStates();
          updateStatus('', 'status');
        }

        // =====================================================================
        // EVENT LISTENERS
        // =====================================================================

        // Play button click.
        playButton.addEventListener('click', function(e) {
          e.preventDefault();
          togglePlay();
        });

        // Play button keyboard (space bar).
        playButton.addEventListener('keydown', function(e) {
          if (e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            togglePlay();
          }
        });

        // Audio ended.
        audioElement.addEventListener('ended', function() {
          isPlaying = false;
          updateButtonStates();
          stopPlayback();
        });

        // Audio pause.
        audioElement.addEventListener('pause', function() {
          if (!audioElement.ended && audioElement.currentTime > 0) {
            isPlaying = false;
            updateButtonStates();
          }
        });

        // Audio play.
        audioElement.addEventListener('play', function() {
          isPlaying = true;
          updateButtonStates();
          showPlaybackControls();
        });

        // Audio error.
        audioElement.addEventListener('error', function() {
          const errorMsg = Drupal.t('Error playing audio.');
          if (labelText) {
            labelText.innerHTML = '<span class="ai-tts-error">⚠</span> ' + errorMsg;
          }
          updateStatus(errorMsg, 'error');
          playButton.disabled = false;
          isPlaying = false;
          updateButtonStates();

          // Restore original label after 5 seconds.
          setTimeout(function() {
            if (labelText && !isPlaying) {
              labelText.textContent = Drupal.t('Listen to this article');
              updateStatus('', 'status');
            }
          }, 5000);
        });

        // Audio metadata loaded (duration available).
        audioElement.addEventListener('loadedmetadata', function() {
          updateDurationDisplays(audioElement.duration);

          if (scrubberInput) {
            scrubberInput.disabled = false;
          }
        });

        // Audio time update (during playback).
        // A11Y: CRITICAL - Do NOT update aria-valuenow or aria-valuetext here!
        // Only update visual displays. ARIA updates happen on user interaction only.
        audioElement.addEventListener('timeupdate', function() {
          if (!audioElement.duration) {
            return;
          }

          const currentTime = audioElement.currentTime;
          const duration = audioElement.duration;
          const percentage = (currentTime / duration) * 100;

          // Update scrubber position (visual only).
          if (scrubberInput) {
            scrubberInput.value = currentTime;

            // Update progress bar fill (gradient background).
            scrubberInput.style.background = 'linear-gradient(to right, #121212 0%, #121212 ' + percentage + '%, #dfdfdf ' + percentage + '%, #dfdfdf 100%)';
          }

          // Update current time display.
          if (currentTimeDisplay) {
            currentTimeDisplay.textContent = formatDuration(currentTime);
          }

          // Update remaining time display.
          if (remainingTimeDisplay) {
            const remaining = duration - currentTime;
            remainingTimeDisplay.textContent = '-' + formatDuration(remaining);
          }
        });

        // Scrubber input (user seeking).
        if (scrubberInput) {
          scrubberInput.addEventListener('input', function() {
            const newTime = parseFloat(scrubberInput.value);
            audioElement.currentTime = newTime;

            // Update visual progress bar immediately.
            if (audioElement.duration) {
              const percentage = (newTime / audioElement.duration) * 100;
              scrubberInput.style.background = 'linear-gradient(to right, #121212 0%, #121212 ' + percentage + '%, #dfdfdf ' + percentage + '%, #dfdfdf 100%)';
            }

            // A11Y: Throttle ARIA updates to prevent overwhelming screen readers.
            const now = Date.now();
            if (now - lastAriaUpdate > ARIA_THROTTLE_MS) {
              scrubberInput.setAttribute('aria-valuenow', newTime.toString());
              scrubberInput.setAttribute('aria-valuetext', getAriaTimeText(newTime, audioElement.duration));
              lastAriaUpdate = now;
            }
          });

          // A11Y: Update ARIA when user focuses on scrubber.
          scrubberInput.addEventListener('focus', function() {
            if (audioElement.duration) {
              scrubberInput.setAttribute('aria-valuenow', audioElement.currentTime.toString());
              scrubberInput.setAttribute('aria-valuetext', getAriaTimeText(audioElement.currentTime, audioElement.duration));
            }
          });

          // Optional: Pause while dragging (NYTimes does this).
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

        // Voice/speed change listeners.
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
