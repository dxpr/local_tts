/**
 * @file
 * Local TTS player functionality.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.aiTtsPlayer = {
    attach: function (context) {
      var players = once('local-tts-player', '.local-tts-container', context);

      if (players.length === 0) {
        return;
      }

      players.forEach(function (player) {
        var playButton = player.querySelector('.local-tts-play-button');
        var labelContainer = player.querySelector('.local-tts-label');
        var labelText = player.querySelector('.local-tts-label-text');
        var labelDuration = player.querySelector('.local-tts-label-duration');
        var playbackControls = player.querySelector('.local-tts-playback-controls');
        var currentTimeDisplay = player.querySelector('.local-tts-current-time');
        var scrubberInput = player.querySelector('.local-tts-scrubber');
        var remainingTimeDisplay = player.querySelector('.local-tts-remaining-time');
        var voiceSelect = player.querySelector('.local-tts-voice-select');
        var speedSelect = player.querySelector('.local-tts-speed-select');
        var skipBackBtn = player.querySelector('.local-tts-skip-back');
        var skipForwardBtn = player.querySelector('.local-tts-skip-forward');
        var statusDiv = player.querySelector('.local-tts-status');
        var audioElement = player.querySelector('audio');
        var muteButton = player.querySelector('.local-tts-mute-button');
        var volumeSlider = player.querySelector('.local-tts-volume-slider');
        var downloadButton = player.querySelector('.local-tts-download-button');

        if (!playButton || !audioElement) {
          return;
        }

        var ttsId = player.getAttribute('data-tts-id');
        var globalConfig = drupalSettings.aiTts || {};
        var instanceConfig = (globalConfig.instances || {})[ttsId] || {};
        var config = Object.assign({}, globalConfig, instanceConfig);
        var isPlaying = false;
        var currentAudioUrl = null;
        var generationId = 0;
        var cancelAudioLoad = null;
        var generatedSpeed = 1;
        var lastAriaUpdate = 0;
        var lastProgressSave = 0;
        var previousVolume = 1;
        var generationTimer = null;
        var lastAnnounce = 0;
        var ARIA_THROTTLE_MS = 200;
        var PROGRESS_SAVE_MS = 5000;
        var ANNOUNCE_THROTTLE_MS = 500;
        var storageKey = 'local_tts_progress_' + config.entityType + '_' + config.entityId;

        audioElement.loop = false;
        audioElement.preload = 'metadata';

        restorePreferences();
        updateDurationEstimate();
        player.classList.remove('local-tts-initializing');

        function formatDuration(seconds) {
          if (!seconds || !isFinite(seconds)) {
            return '';
          }
          var mins = Math.floor(seconds / 60);
          var secs = Math.floor(seconds % 60);
          return mins + ':' + (secs < 10 ? '0' : '') + secs;
        }

        function formatTimeForAria(seconds) {
          if (!seconds || !isFinite(seconds)) {
            return '0 seconds';
          }
          var mins = Math.floor(seconds / 60);
          var secs = Math.floor(seconds % 60);
          var parts = [];
          if (mins > 0) {
            parts.push(mins + ' minute' + (mins !== 1 ? 's' : ''));
          }
          if (secs > 0 || mins === 0) {
            parts.push(secs + ' second' + (secs !== 1 ? 's' : ''));
          }
          return parts.join(' ');
        }

        function getAriaTimeText(currentTime, duration) {
          var elapsed = formatTimeForAria(currentTime);
          var remaining = duration ? formatTimeForAria(duration - currentTime) : 'duration unknown';
          return elapsed + ' elapsed, ' + remaining + ' remaining';
        }

        function estimateDuration(wordCount, speed) {
          if (!wordCount || wordCount <= 0) {
            return '';
          }
          var wpm = 150 * (speed || 1);
          var seconds = Math.round((wordCount / wpm) * 60);
          if (seconds < 60) {
            return Drupal.t('(@sec sec)', {'@sec': seconds});
          }
          return Drupal.t('(@min min)', {'@min': Math.ceil(seconds / 60)});
        }

        function updateDurationEstimate() {
          if (labelDuration && config.wordCount) {
            var est = estimateDuration(config.wordCount, getSelectedSpeed());
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
          var now = Date.now();
          if (now - lastAnnounce < ANNOUNCE_THROTTLE_MS) {
            return;
          }
          lastAnnounce = now;
          while (statusDiv.firstChild) {
            statusDiv.removeChild(statusDiv.firstChild);
          }
          if (message) {
            var span = document.createElement('span');
            span.textContent = message;
            statusDiv.appendChild(span);
          }
        }

        function updateStatus(message, type) {
          type = type || 'info';
          while (statusDiv.firstChild) {
            statusDiv.removeChild(statusDiv.firstChild);
          }
          if (message) {
            var div = document.createElement('div');
            div.className = 'messages messages--' + type;
            div.textContent = message;
            statusDiv.appendChild(div);
          }
        }

        function setLabelWithIcon(iconClass, iconText, text) {
          while (labelText.firstChild) {
            labelText.removeChild(labelText.firstChild);
          }
          var icon = document.createElement('span');
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
          var div = document.createElement('div');
          div.className = 'messages messages--status';
          var link = document.createElement('a');
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
          var wrapper = document.createElement('div');
          wrapper.className = 'messages messages--error';
          var msgSpan = document.createElement('span');
          msgSpan.textContent = errorMsg;
          wrapper.appendChild(msgSpan);

          var retryBtn = document.createElement('button');
          retryBtn.type = 'button';
          retryBtn.className = 'local-tts-retry-btn';
          retryBtn.textContent = Drupal.t('Try again');
          retryBtn.addEventListener('click', function () {
            updateStatus('', 'status');
            if (labelText) {
              labelText.textContent = Drupal.t('Listen to this article');
            }
            generateSpeech();
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
            var saved = localStorage.getItem(storageKey);
            if (saved) {
              var value = parseFloat(saved);
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
            var val = parseFloat(speedSelect.value);
            if (isFinite(val) && val > 0) {
              return val;
            }
          }
          var fallback = parseFloat(config.defaultSpeed);
          return isFinite(fallback) && fallback > 0 ? fallback : 1;
        }

        function applyPlaybackRate() {
          audioElement.playbackRate = getSelectedSpeed() / generatedSpeed;
        }

        function restorePreferences() {
          try {
            var savedVoice = localStorage.getItem('local_tts_voice');
            if (savedVoice && voiceSelect) {
              var opts = voiceSelect.options;
              for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === savedVoice) {
                  voiceSelect.value = savedVoice;
                  break;
                }
              }
            }
            var savedSpeed = localStorage.getItem('local_tts_speed');
            if (savedSpeed && speedSelect) {
              var opts = speedSelect.options;
              for (var i = 0; i < opts.length; i++) {
                if (opts[i].value === savedSpeed) {
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
          var startTime = Date.now();
          generationTimer = setInterval(function () {
            var elapsed = Math.floor((Date.now() - startTime) / 1000);
            var msg = Drupal.t('Generating speech... (@seconds)', {'@seconds': elapsed + 's'});
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
          var newTime = Math.max(0, Math.min(audioElement.duration, audioElement.currentTime + seconds));
          audioElement.currentTime = newTime;
        }

        function pollForAudio(pollUrl, requestId) {
          var maxPollTime = 5 * 60 * 1000;
          var pollInterval = 3000;
          var startTime = Date.now();

          function poll() {
            if (requestId !== generationId) {
              return;
            }
            if (Date.now() - startTime > maxPollTime) {
              stopGenerationTimer();
              var timeoutMsg = Drupal.t('Audio generation timed out. Please try again later.');
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
          var requestId = ++generationId;
          var voice = voiceSelect ? voiceSelect.value : config.defaultVoice;
          var speed = getSelectedSpeed();
          generatedSpeed = speed;

          if (labelText) {
            setLabelWithIcon('local-tts-loading', '', Drupal.t('Generating speech...'));
          }
          playButton.disabled = true;
          playButton.classList.add('loading');
          announceToScreenReader(Drupal.t('Generating speech'));
          startGenerationTimer();

          var formData = new FormData();
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
              var contentType = response.headers.get('content-type') || '';
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
          var msg;
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
            var minutes = result.data.retry_after ? Math.ceil(result.data.retry_after / 60) : 1;
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

          var loadTimeout = setTimeout(function () {
            cleanup();
            currentAudioUrl = null;
            handleError(Drupal.t('Audio could not be loaded. Try refreshing the page.'));
          }, 15000);

          function cleanup() {
            clearTimeout(loadTimeout);
            audioElement.removeEventListener('canplaythrough', onReady);
            audioElement.removeEventListener('canplay', onReady);
            audioElement.removeEventListener('error', onLoadError);
            cancelAudioLoad = null;
          }
          cancelAudioLoad = cleanup;

          function onLoadError() {
            cleanup();
            currentAudioUrl = null;
            handleError(Drupal.t('Audio could not be loaded. The file may be corrupted.'));
          }

          var readyFired = false;
          function onReady() {
            if (readyFired || requestId !== generationId) {
              return;
            }
            readyFired = true;
            cleanup();
            var savedPosition = getSavedProgress();

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

          audioElement.addEventListener('canplaythrough', onReady, {once: true});
          audioElement.addEventListener('canplay', onReady, {once: true});
          audioElement.addEventListener('error', onLoadError, {once: true});
          audioElement.src = audioUrl;
          audioElement.load();
        }

        function togglePlay() {
          if (!isPlaying) {
            if (!currentAudioUrl || audioElement.ended) {
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
          var tag = e.target.tagName.toLowerCase();
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
          var ct = audioElement.currentTime;
          var dur = audioElement.duration;
          var percentage = (ct / dur) * 100;

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
          var now = Date.now();
          if (now - lastProgressSave >= PROGRESS_SAVE_MS) {
            lastProgressSave = now;
            saveProgress(ct);
          }
        });

        if (scrubberInput) {
          scrubberInput.addEventListener('input', function () {
            var newTime = parseFloat(scrubberInput.value);
            audioElement.currentTime = newTime;
            if (audioElement.duration) {
              var pct = (newTime / audioElement.duration) * 100;
              scrubberInput.style.background = 'linear-gradient(to right, var(--ltt-color-thumb) 0%, var(--ltt-color-thumb) ' + pct + '%, var(--ltt-color-track) ' + pct + '%, var(--ltt-color-track) 100%)';
            }
            var now = Date.now();
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

          var wasPlaying = false;
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
            var vol = parseFloat(volumeSlider.value);
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
          var pct = Math.round(vol * 100);
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
          player.dispatchEvent(new CustomEvent('localTtsEvent', {
            bubbles: true,
            detail: {
              action: action,
              entityType: config.entityType,
              entityId: config.entityId,
            },
          }));
        }

        if (voiceSelect) {
          voiceSelect.addEventListener('change', function () {
            savePreference('local_tts_voice', voiceSelect.value);
            stopPlayback();
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
