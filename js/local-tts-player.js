/**
 * @file
 * Local TTS player functionality.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.localTtsPlayer = {
    attach: function (context) {
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
        const speedSelect = player.querySelector('.local-tts-speed-select');
        const skipBackBtn = player.querySelector('.local-tts-skip-back');
        const skipForwardBtn = player.querySelector('.local-tts-skip-forward');
        const statusDiv = player.querySelector('.local-tts-status');
        const audioElement = player.querySelector('audio');
        const muteButton = player.querySelector('.local-tts-mute-button');
        const volumeSlider = player.querySelector('.local-tts-volume-slider');
        const downloadButton = player.querySelector('.local-tts-download-button');

        if (!playButton || !audioElement) {
          return;
        }

        const ttsId = player.getAttribute('data-tts-id');
        const globalConfig = drupalSettings.localTts || {};
        const instanceConfig = (globalConfig.instances || {})[ttsId] || {};
        const config = Object.assign({}, globalConfig, instanceConfig);
        let isPlaying = false;
        let currentAudioUrl = null;
        let generationId = 0;
        let cancelAudioLoad = null;
        let generatedSpeed = 1;
        let lastAriaUpdate = 0;
        let lastProgressSave = 0;
        let previousVolume = 1;
        let generationTimer = null;
        let lastAnnounce = 0;
        const ARIA_THROTTLE_MS = 200;
        const PROGRESS_SAVE_MS = 5000;
        const ANNOUNCE_THROTTLE_MS = 500;
        const isFileMode = !!config.audioUrl;
        const storageKey = isFileMode
          ? 'local_tts_progress_file_' + config.audioUrl.replace(/[^a-z0-9]/gi, '_').slice(-60)
          : 'local_tts_progress_' + config.entityType + '_' + config.entityId;

        audioElement.loop = false;
        audioElement.preload = 'metadata';

        restorePreferences();
        updateDurationEstimate();
        player.classList.remove('local-tts-initializing');

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
          const parts = [];
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

        function estimateDuration(wordCount, speed) {
          if (!wordCount || wordCount <= 0) {
            return '';
          }
          const wpm = 150 * (speed || 1);
          const seconds = Math.round((wordCount / wpm) * 60);
          if (seconds < 60) {
            return Drupal.t('(@sec sec)', {'@sec': seconds});
          }
          return Drupal.t('(@min min)', {'@min': Math.ceil(seconds / 60)});
        }

        function updateDurationEstimate() {
          if (labelDuration && config.wordCount) {
            const est = estimateDuration(config.wordCount, getSelectedSpeed());
            labelDuration.textContent = est || '';
            labelDuration.hidden = false;
          }
        }

        function updateDurationDisplays(duration) {
          if (!duration || !isFinite(duration)) {
            return;
          }
          if (scrubberInput) {
            scrubberInput.max = duration;
            scrubberInput.setAttribute('aria-valuemax', duration.toString());
          }
        }

        function announceToScreenReader(message) {
          const now = Date.now();
          if (now - lastAnnounce < ANNOUNCE_THROTTLE_MS) {
            return;
          }
          lastAnnounce = now;
          while (statusDiv.firstChild) {
            statusDiv.removeChild(statusDiv.firstChild);
          }
          if (message) {
            const span = document.createElement('span');
            span.textContent = message;
            statusDiv.appendChild(span);
          }
        }

        function updateStatus(message, type) {
          const statusType = type || 'info';
          while (statusDiv.firstChild) {
            statusDiv.removeChild(statusDiv.firstChild);
          }
          if (message) {
            const div = document.createElement('div');
            div.className = 'messages messages--' + statusType;
            div.textContent = message;
            statusDiv.appendChild(div);
          }
        }

        function setLabelWithIcon(iconClass, iconText, text) {
          while (labelText.firstChild) {
            labelText.removeChild(labelText.firstChild);
          }
          const icon = document.createElement('span');
          icon.className = iconClass;
          if (iconText) {
            icon.textContent = iconText;
          }
          labelText.appendChild(icon);
          labelText.appendChild(document.createTextNode(' ' + text));
          if (labelDuration) {
            labelDuration.hidden = true;
          }
        }

        function showResumePrompt(savedPosition) {
          while (statusDiv.firstChild) {
            statusDiv.removeChild(statusDiv.firstChild);
          }
          const div = document.createElement('div');
          div.className = 'messages messages--status';
          const link = document.createElement('a');
          link.href = '#';
          link.className = 'local-tts-resume';
          link.textContent = Drupal.t('Resume from @time?', {'@time': formatDuration(savedPosition)});
          link.addEventListener('click', function (e) {
            e.preventDefault();
            audioElement.currentTime = savedPosition;
            updateStatus('', 'status');
          });
          div.appendChild(link);
          statusDiv.appendChild(div);
        }

        function showRetryButton(errorMsg) {
          while (statusDiv.firstChild) {
            statusDiv.removeChild(statusDiv.firstChild);
          }
          const wrapper = document.createElement('div');
          wrapper.className = 'messages messages--error';
          const msgSpan = document.createElement('span');
          msgSpan.textContent = errorMsg;
          wrapper.appendChild(msgSpan);

          const retryBtn = document.createElement('button');
          retryBtn.type = 'button';
          retryBtn.className = 'local-tts-retry-btn';
          retryBtn.textContent = Drupal.t('Try again');
          retryBtn.addEventListener('click', function () {
            updateStatus('', 'status');
            if (labelText) {
              labelText.textContent = Drupal.t('Listen to this article');
            }
            if (isFileMode) {
              const requestId = ++generationId;
              currentAudioUrl = config.audioUrl;
              playSpeech(config.audioUrl, requestId);
            }
            else {
              generateSpeech();
            }
          });

          statusDiv.appendChild(wrapper);
          statusDiv.appendChild(retryBtn);
          setTimeout(function () { retryBtn.focus(); }, 100);
        }

        function updateButtonStates() {
          if (isPlaying) {
            playButton.setAttribute('aria-pressed', 'true');
            playButton.classList.add('playing');
            playButton.setAttribute('aria-label', Drupal.t('Pause audio'));
            player.classList.add('local-tts-playing');
          }
          else {
            playButton.setAttribute('aria-pressed', 'false');
            playButton.classList.remove('playing');
            playButton.setAttribute('aria-label', Drupal.t('Play audio'));
            player.classList.remove('local-tts-playing');
          }
        }

        function showPlaybackControls() {
          if (labelContainer) {
            labelContainer.style.display = 'none';
          }
          if (playbackControls) {
            playbackControls.style.display = '';
          }
          setTimeout(function () { playButton.focus(); }, 100);
        }

        function hidePlaybackControls() {
          if (playbackControls) {
            playbackControls.style.display = 'none';
          }
          if (labelContainer) {
            labelContainer.style.display = '';
          }
        }

        function saveProgress(time) {
          try {
            localStorage.setItem(storageKey, time.toString());
          }
          catch (e) {
          }
        }

        function getSavedProgress() {
          try {
            const saved = localStorage.getItem(storageKey);
            if (saved) {
              const value = parseFloat(saved);
              if (isFinite(value)) {
                return value;
              }
            }
          }
          catch (e) {
          }
          return 0;
        }

        function clearSavedProgress() {
          try {
            localStorage.removeItem(storageKey);
          }
          catch (e) {
          }
        }

        function getSelectedSpeed() {
          if (speedSelect) {
            const val = parseFloat(speedSelect.value);
            if (isFinite(val) && val > 0) {
              return val;
            }
          }
          const fallback = parseFloat(config.defaultSpeed);
          return isFinite(fallback) && fallback > 0 ? fallback : 1;
        }

        function applyPlaybackRate() {
          audioElement.playbackRate = getSelectedSpeed() / generatedSpeed;
        }

        function restorePreferences() {
          try {
            const savedVoice = localStorage.getItem('local_tts_voice');
            if (savedVoice && voiceSelect) {
              const voiceOpts = voiceSelect.options;
              for (let i = 0; i < voiceOpts.length; i++) {
                if (voiceOpts[i].value === savedVoice) {
                  voiceSelect.value = savedVoice;
                  break;
                }
              }
            }
            const savedSpeed = localStorage.getItem('local_tts_speed');
            if (savedSpeed && speedSelect) {
              const speedOpts = speedSelect.options;
              for (let i = 0; i < speedOpts.length; i++) {
                if (speedOpts[i].value === savedSpeed) {
                  speedSelect.value = savedSpeed;
                  break;
                }
              }
            }
          }
          catch (e) {
          }
        }

        function savePreference(key, value) {
          try {
            localStorage.setItem(key, value);
          }
          catch (e) {
          }
        }

        function startGenerationTimer() {
          const startTime = Date.now();
          generationTimer = setInterval(function () {
            const elapsed = Math.floor((Date.now() - startTime) / 1000);
            const msg = Drupal.t('Generating speech... (@seconds)', {'@seconds': elapsed + 's'});
            if (labelText) {
              setLabelWithIcon('local-tts-loading', '', msg);
            }
          }, 1000);
        }

        function stopGenerationTimer() {
          if (generationTimer) {
            clearInterval(generationTimer);
            generationTimer = null;
          }
        }

        function seekRelative(seconds) {
          if (!audioElement.duration) {
            return;
          }
          const newTime = Math.max(0, Math.min(audioElement.duration, audioElement.currentTime + seconds));
          audioElement.currentTime = newTime;
        }

        function pollForAudio(pollUrl, requestId) {
          const maxPollTime = 5 * 60 * 1000;
          const pollInterval = 3000;
          const startTime = Date.now();

          function poll() {
            if (requestId !== generationId) {
              return;
            }
            if (Date.now() - startTime > maxPollTime) {
              stopGenerationTimer();
              const timeoutMsg = Drupal.t('Audio generation timed out. Please try again later.');
              if (labelText) {
                setLabelWithIcon('local-tts-error', '⚠', timeoutMsg);
              }
              playButton.disabled = false;
              playButton.classList.remove('loading');
              updateButtonStates();
              showRetryButton(timeoutMsg);
              return;
            }

            fetch(pollUrl)
              .then(function (response) { return response.json(); })
              .then(function (data) {
                if (requestId !== generationId) {
                  return;
                }
                if (data.status === 'ready' && data.audio_url) {
                  stopGenerationTimer();
                  currentAudioUrl = data.audio_url;
                  announceToScreenReader(Drupal.t('Audio ready'));
                  playSpeech(data.audio_url, requestId);
                }
                else {
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
          const requestId = ++generationId;
          const voice = voiceSelect ? voiceSelect.value : config.defaultVoice;
          const speed = getSelectedSpeed();
          generatedSpeed = speed;

          if (labelText) {
            setLabelWithIcon('local-tts-loading', '', Drupal.t('Generating speech...'));
          }
          playButton.disabled = true;
          playButton.classList.add('loading');
          announceToScreenReader(Drupal.t('Generating speech'));
          startGenerationTimer();

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
            body: formData,
            credentials: 'same-origin',
          })
            .then(function (response) {
              const contentType = response.headers.get('content-type') || '';
              if (contentType.indexOf('application/json') === -1) {
                return {ok: false, status: response.status, data: {}, htmlResponse: true};
              }
              return response.json().then(function (data) {
                return {ok: response.ok, status: response.status, data: data};
              }).catch(function () {
                return {ok: false, status: response.status, data: {}};
              });
            })
            .then(function (result) {
              if (requestId !== generationId) {
                return;
              }
              if (result.ok && result.data.success && result.data.audio_url) {
                stopGenerationTimer();
                currentAudioUrl = result.data.audio_url;
                announceToScreenReader(Drupal.t('Audio ready'));
                playSpeech(result.data.audio_url, requestId);
              }
              else if (result.ok && result.data.status === 'processing' && result.data.poll_url) {
                pollForAudio(result.data.poll_url, requestId);
              }
              else {
                stopGenerationTimer();
                showGenerateError(result);
              }
            })
            .catch(function () {
              if (requestId !== generationId) {
                return;
              }
              stopGenerationTimer();
              handleError(Drupal.t('Could not connect to the server. Check your internet connection.'));
            });
        }

        function showGenerateError(result) {
          let msg;
          if (result.htmlResponse) {
            msg = Drupal.t('The server did not return a valid response. This usually means the "Generate local text-to-speech audio" permission is not granted for your role.');
          }
          else if (result.status === 403) {
            msg = result.data.message || Drupal.t('Access denied. Check that the "Generate local text-to-speech audio" permission is granted.');
          }
          else if (result.status === 404) {
            msg = result.data.message || Drupal.t('Content not found.');
          }
          else if (result.status === 429) {
            const minutes = result.data.retry_after ? Math.ceil(result.data.retry_after / 60) : 1;
            msg = result.data.message || Drupal.t('Too many requests. Please try again in @minutes minutes.', {'@minutes': minutes});
          }
          else if (result.status === 503) {
            msg = result.data.message || Drupal.t('The text-to-speech service is temporarily unavailable. The TTS binary or ffmpeg may not be installed.');
          }
          else if (result.status === 504) {
            msg = Drupal.t('Audio generation timed out. The content may be too long.');
          }
          else if (result.status >= 500) {
            msg = result.data.message || Drupal.t('Server error during audio generation. Check the Drupal logs for details.');
          }
          else {
            msg = result.data.message || Drupal.t('Audio generation failed (HTTP @status).', {'@status': result.status});
          }
          handleError(msg);
        }

        function handleError(msg) {
          if (labelText) {
            labelText.textContent = msg;
          }
          playButton.disabled = false;
          playButton.classList.remove('loading');
          updateButtonStates();
          announceToScreenReader(msg);
          showRetryButton(msg);
        }

        function playSpeech(audioUrl, requestId) {
          if (cancelAudioLoad) {
            cancelAudioLoad();
          }

          let cancelled = false;
          cancelAudioLoad = function () {
            cancelled = true;
            cancelAudioLoad = null;
          };

          fetch(audioUrl, {credentials: 'same-origin'})
            .then(function (response) {
              if (!response.ok) {
                throw new Error('HTTP ' + response.status);
              }
              return response.blob();
            })
            .then(function (blob) {
              if (cancelled || requestId !== generationId) {
                return;
              }
              const blobUrl = URL.createObjectURL(blob);
              audioElement.src = blobUrl;
              audioElement.load();

              function onReady() {
                audioElement.removeEventListener('canplay', onReady);
                audioElement.removeEventListener('error', onLoadError);
                if (cancelled || requestId !== generationId) {
                  return;
                }
                cancelAudioLoad = null;
                const savedPosition = getSavedProgress();

                audioElement.play().then(function () {
                  if (requestId !== generationId) {
                    return;
                  }
                  isPlaying = true;
                  applyPlaybackRate();
                  playButton.disabled = false;
                  playButton.classList.remove('loading');
                  updateButtonStates();
                  showPlaybackControls();
                  updateStatus('', 'status');
                  updateDownloadButton(audioUrl);
                  announceToScreenReader(Drupal.t('Playback started'));
                  dispatchAnalytics('play');

                  if (savedPosition > 5 && isFinite(savedPosition) && savedPosition < audioElement.duration - 5) {
                    showResumePrompt(savedPosition);
                    setTimeout(function () {
                      if (statusDiv.querySelector('.local-tts-resume')) {
                        updateStatus('', 'status');
                        clearSavedProgress();
                      }
                    }, 10000);
                  }
                }).catch(function () {
                  if (requestId !== generationId) {
                    return;
                  }
                  isPlaying = false;
                  handleError(Drupal.t('Error playing audio.'));
                });
              }

              function onLoadError() {
                audioElement.removeEventListener('canplay', onReady);
                audioElement.removeEventListener('error', onLoadError);
                cancelAudioLoad = null;
                currentAudioUrl = null;
                handleError(Drupal.t('Audio could not be loaded. The file may be corrupted.'));
              }

              audioElement.addEventListener('canplay', onReady, {once: true});
              audioElement.addEventListener('error', onLoadError, {once: true});
            })
            .catch(function () {
              if (cancelled || requestId !== generationId) {
                return;
              }
              cancelAudioLoad = null;
              currentAudioUrl = null;
              handleError(Drupal.t('Audio could not be loaded. Try refreshing the page.'));
            });
        }

        function togglePlay() {
          if (!isPlaying) {
            if (!currentAudioUrl || audioElement.ended) {
              if (isFileMode) {
                const requestId = ++generationId;
                currentAudioUrl = config.audioUrl;
                playSpeech(config.audioUrl, requestId);
                return;
              }
              generateSpeech();
            }
            else {
              audioElement.play().then(function () {
                isPlaying = true;
                updateButtonStates();
                showPlaybackControls();
                updateStatus('', 'status');
                announceToScreenReader(Drupal.t('Playback started'));
              }).catch(function () {
                isPlaying = false;
                handleError(Drupal.t('Error playing audio.'));
              });
            }
          }
          else {
            audioElement.pause();
            isPlaying = false;
            updateButtonStates();
            updateStatus('', 'status');
            announceToScreenReader(Drupal.t('Playback paused'));
            dispatchAnalytics('pause');
          }
        }

        function stopPlayback() {
          generationId++;
          stopGenerationTimer();
          if (cancelAudioLoad) {
            cancelAudioLoad();
          }
          playButton.disabled = false;
          playButton.classList.remove('loading');
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
          updateDurationEstimate();
          if (downloadButton) {
            downloadButton.hidden = true;
          }
        }

        playButton.addEventListener('click', function (e) {
          e.preventDefault();
          togglePlay();
        });

        playButton.addEventListener('keydown', function (e) {
          if (e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            togglePlay();
          }
        });

        if (skipBackBtn) {
          skipBackBtn.addEventListener('click', function () {
            seekRelative(-10);
          });
        }
        if (skipForwardBtn) {
          skipForwardBtn.addEventListener('click', function () {
            seekRelative(10);
          });
        }

        player.addEventListener('keydown', function (e) {
          const tag = e.target.tagName.toLowerCase();
          if (tag === 'select' || tag === 'input' || tag === 'textarea') {
            return;
          }
          if (e.key === 'ArrowLeft') {
            e.preventDefault();
            seekRelative(-10);
          }
          else if (e.key === 'ArrowRight') {
            e.preventDefault();
            seekRelative(10);
          }
        });

        audioElement.addEventListener('ended', function () {
          isPlaying = false;
          clearSavedProgress();
          updateButtonStates();
          announceToScreenReader(Drupal.t('Playback ended'));
          stopPlayback();
          dispatchAnalytics('ended');
        });

        audioElement.addEventListener('pause', function () {
          if (!audioElement.ended && audioElement.currentTime > 0) {
            isPlaying = false;
            updateButtonStates();
          }
        });

        audioElement.addEventListener('play', function () {
          isPlaying = true;
          updateButtonStates();
          showPlaybackControls();
        });

        audioElement.addEventListener('error', function () {
          isPlaying = false;
          currentAudioUrl = null;
          handleError(Drupal.t('Error playing audio.'));
        });

        audioElement.addEventListener('loadedmetadata', function () {
          updateDurationDisplays(audioElement.duration);
          if (scrubberInput) {
            scrubberInput.disabled = false;
          }
        });

        audioElement.addEventListener('timeupdate', function () {
          if (!audioElement.duration) {
            return;
          }
          const ct = audioElement.currentTime;
          const dur = audioElement.duration;
          const percentage = (ct / dur) * 100;

          if (scrubberInput) {
            scrubberInput.value = ct;
            scrubberInput.style.background = 'linear-gradient(to right, var(--ltt-color-thumb) 0%, var(--ltt-color-thumb) ' + percentage + '%, var(--ltt-color-track) ' + percentage + '%, var(--ltt-color-track) 100%)';
          }
          if (currentTimeDisplay) {
            currentTimeDisplay.textContent = formatDuration(ct);
          }
          if (remainingTimeDisplay) {
            remainingTimeDisplay.textContent = '-' + formatDuration(dur - ct);
          }
          const now = Date.now();
          if (now - lastProgressSave >= PROGRESS_SAVE_MS) {
            lastProgressSave = now;
            saveProgress(ct);
          }
        });

        if (scrubberInput) {
          scrubberInput.addEventListener('input', function () {
            const newTime = parseFloat(scrubberInput.value);
            audioElement.currentTime = newTime;
            if (audioElement.duration) {
              const pct = (newTime / audioElement.duration) * 100;
              scrubberInput.style.background = 'linear-gradient(to right, var(--ltt-color-thumb) 0%, var(--ltt-color-thumb) ' + pct + '%, var(--ltt-color-track) ' + pct + '%, var(--ltt-color-track) 100%)';
            }
            const now = Date.now();
            if (now - lastAriaUpdate > ARIA_THROTTLE_MS) {
              scrubberInput.setAttribute('aria-valuenow', newTime.toString());
              scrubberInput.setAttribute('aria-valuetext', getAriaTimeText(newTime, audioElement.duration));
              lastAriaUpdate = now;
            }
          });

          scrubberInput.addEventListener('focus', function () {
            if (audioElement.duration) {
              scrubberInput.setAttribute('aria-valuenow', audioElement.currentTime.toString());
              scrubberInput.setAttribute('aria-valuetext', getAriaTimeText(audioElement.currentTime, audioElement.duration));
            }
          });

          let wasPlaying = false;
          scrubberInput.addEventListener('mousedown', function () {
            wasPlaying = !audioElement.paused;
            if (wasPlaying) {
              audioElement.pause();
            }
          });
          scrubberInput.addEventListener('mouseup', function () {
            if (wasPlaying) {
              audioElement.play();
            }
          });
        }

        if (muteButton) {
          muteButton.addEventListener('click', function () {
            if (audioElement.muted) {
              audioElement.muted = false;
              audioElement.volume = previousVolume || 1;
              muteButton.setAttribute('aria-pressed', 'false');
              muteButton.classList.remove('muted');
              if (volumeSlider) {
                volumeSlider.value = audioElement.volume;
                updateVolumeAria(volumeSlider, audioElement.volume);
              }
            }
            else {
              previousVolume = audioElement.volume;
              audioElement.muted = true;
              muteButton.setAttribute('aria-pressed', 'true');
              muteButton.classList.add('muted');
              if (volumeSlider) {
                volumeSlider.value = 0;
                updateVolumeAria(volumeSlider, 0);
              }
            }
          });
        }

        if (volumeSlider) {
          volumeSlider.addEventListener('input', function () {
            const vol = parseFloat(volumeSlider.value);
            audioElement.volume = vol;
            audioElement.muted = vol === 0;
            if (muteButton) {
              muteButton.setAttribute('aria-pressed', vol === 0 ? 'true' : 'false');
              muteButton.classList.toggle('muted', vol === 0);
            }
            updateVolumeAria(volumeSlider, vol);
          });
        }

        function updateVolumeAria(slider, vol) {
          const pct = Math.round(vol * 100);
          slider.setAttribute('aria-valuenow', pct.toString());
          slider.setAttribute('aria-valuetext', Drupal.t('Volume @pct%', {'@pct': pct}));
        }

        function updateDownloadButton(url) {
          if (downloadButton && url) {
            downloadButton.href = url;
            downloadButton.hidden = false;
          }
        }

        function dispatchAnalytics(action) {
          const detail = { action: action };
          if (isFileMode) {
            detail.audioUrl = config.audioUrl;
          }
          else {
            detail.entityType = config.entityType;
            detail.entityId = config.entityId;
          }
          player.dispatchEvent(new CustomEvent('localTtsEvent', {
            bubbles: true,
            detail: detail,
          }));
        }

        if (voiceSelect) {
          voiceSelect.addEventListener('change', function () {
            savePreference('local_tts_voice', voiceSelect.value);
            if (!isFileMode) {
              stopPlayback();
            }
          });
        }

        if (speedSelect) {
          speedSelect.addEventListener('change', function () {
            savePreference('local_tts_speed', speedSelect.value);
            applyPlaybackRate();
            updateDurationEstimate();
          });
        }
      });
    },
  };

})(Drupal, drupalSettings, once);
