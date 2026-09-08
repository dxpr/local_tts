/**
 * @file
 * Local TTS player functionality.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  const ARIA_THROTTLE_MS = 200;
  const PROGRESS_SAVE_MS = 5000;
  const ANNOUNCE_THROTTLE_MS = 500;
  const POLL_INTERVAL_MS = 3000;
  const MAX_POLL_MS = 5 * 60 * 1000;
  const RESUME_TIMEOUT_MS = 10000;

  function clearChildren(el) {
    while (el.firstChild) {
      el.removeChild(el.firstChild);
    }
  }

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

  function scrubberGradient(percentage) {
    return 'linear-gradient(to right, var(--ltt-color-thumb) 0%, var(--ltt-color-thumb) ' +
      percentage + '%, var(--ltt-color-track) ' + percentage + '%, var(--ltt-color-track) 100%)';
  }

  /**
   * Constructor for a single TTS player instance.
   */
  function LocalTtsPlayer(container) {
    this.container = container;
    this.playButton = container.querySelector('.local-tts-play-button');
    this.labelContainer = container.querySelector('.local-tts-label');
    this.labelText = container.querySelector('.local-tts-label-text');
    this.labelDuration = container.querySelector('.local-tts-label-duration');
    this.playbackControls = container.querySelector('.local-tts-playback-controls');
    this.currentTimeDisplay = container.querySelector('.local-tts-current-time');
    this.scrubberInput = container.querySelector('.local-tts-scrubber');
    this.remainingTimeDisplay = container.querySelector('.local-tts-remaining-time');
    this.voiceSelect = container.querySelector('.local-tts-voice-select');
    this.speedSelect = container.querySelector('.local-tts-speed-select');
    this.skipBackBtn = container.querySelector('.local-tts-skip-back');
    this.skipForwardBtn = container.querySelector('.local-tts-skip-forward');
    this.statusDiv = container.querySelector('.local-tts-status');
    this.audioElement = container.querySelector('audio');
    this.muteButton = container.querySelector('.local-tts-mute-button');
    this.volumeSlider = container.querySelector('.local-tts-volume-slider');
    this.downloadButton = container.querySelector('.local-tts-download-button');

    if (!this.playButton || !this.audioElement) {
      return;
    }

    const ttsId = container.getAttribute('data-tts-id');
    const globalConfig = drupalSettings.localTts || {};
    const instanceConfig = (globalConfig.instances || {})[ttsId] || {};
    this.config = Object.assign({}, globalConfig, instanceConfig);

    this.isPlaying = false;
    this.currentAudioUrl = null;
    this.generationId = 0;
    this.cancelAudioLoad = null;
    this.generatedSpeed = 1;
    this.lastAriaUpdate = 0;
    this.lastProgressSave = 0;
    this.previousVolume = 1;
    this.generationTimer = null;
    this.lastAnnounce = 0;
    this.currentBlobUrl = null;
    this.isFileMode = !!this.config.audioUrl;

    this.storageKey = this.isFileMode
      ? 'local_tts_progress_file_' + this.config.audioUrl.replace(/[^a-z0-9]/gi, '_').slice(-60)
      : 'local_tts_progress_' + this.config.entityType + '_' + this.config.entityId;

    this.audioElement.loop = false;
    this.audioElement.preload = 'metadata';

    this._boundHandlers = {};
    this._bindEvents();
    this._restorePreferences();
    this._updateDurationEstimate();
    container.classList.remove('local-tts-initializing');
  }

  // -- State management --

  LocalTtsPlayer.prototype.setButtonState = function (state) {
    switch (state) {
      case 'loading':
        this.playButton.disabled = true;
        this.playButton.classList.add('loading');
        this.playButton.classList.remove('playing');
        this.playButton.setAttribute('aria-pressed', 'false');
        this.playButton.setAttribute('aria-label', Drupal.t('Loading audio'));
        break;
      case 'ready':
        this.playButton.disabled = false;
        this.playButton.classList.remove('loading', 'playing');
        this.playButton.setAttribute('aria-pressed', 'false');
        this.playButton.setAttribute('aria-label', Drupal.t('Play audio'));
        this.container.classList.remove('local-tts-playing');
        break;
      case 'playing':
        this.playButton.disabled = false;
        this.playButton.classList.remove('loading');
        this.playButton.classList.add('playing');
        this.playButton.setAttribute('aria-pressed', 'true');
        this.playButton.setAttribute('aria-label', Drupal.t('Pause audio'));
        this.container.classList.add('local-tts-playing');
        break;
      case 'paused':
        this.playButton.disabled = false;
        this.playButton.classList.remove('loading');
        this.playButton.classList.remove('playing');
        this.playButton.setAttribute('aria-pressed', 'false');
        this.playButton.setAttribute('aria-label', Drupal.t('Play audio'));
        this.container.classList.remove('local-tts-playing');
        break;
      case 'error':
        this.playButton.disabled = false;
        this.playButton.classList.remove('loading', 'playing');
        this.playButton.setAttribute('aria-pressed', 'false');
        this.playButton.setAttribute('aria-label', Drupal.t('Play audio'));
        this.container.classList.remove('local-tts-playing');
        break;
    }
  };

  LocalTtsPlayer.prototype.getSelectedSpeed = function () {
    if (this.speedSelect) {
      const val = parseFloat(this.speedSelect.value);
      if (isFinite(val) && val > 0) {
        return val;
      }
    }
    const fallback = parseFloat(this.config.defaultSpeed);
    return isFinite(fallback) && fallback > 0 ? fallback : 1;
  };

  LocalTtsPlayer.prototype._applyPlaybackRate = function () {
    this.audioElement.playbackRate = this.getSelectedSpeed() / this.generatedSpeed;
  };

  // -- DOM/UI --

  LocalTtsPlayer.prototype._showPlaybackControls = function () {
    if (this.labelContainer) {
      this.labelContainer.hidden = true;
    }
    if (this.playbackControls) {
      this.playbackControls.removeAttribute('style');
      this.playbackControls.hidden = false;
    }
    const btn = this.playButton;
    setTimeout(function () { btn.focus(); }, 100);
  };

  LocalTtsPlayer.prototype._hidePlaybackControls = function () {
    if (this.playbackControls) {
      this.playbackControls.hidden = true;
    }
    if (this.labelContainer) {
      this.labelContainer.hidden = false;
    }
  };

  LocalTtsPlayer.prototype._updateStatus = function (message, type) {
    const statusType = type || 'info';
    clearChildren(this.statusDiv);
    if (message) {
      const div = document.createElement('div');
      div.className = 'messages messages--' + statusType;
      div.textContent = message;
      this.statusDiv.appendChild(div);
    }
  };

  LocalTtsPlayer.prototype._setLabelWithIcon = function (iconClass, iconText, text) {
    clearChildren(this.labelText);
    const icon = document.createElement('span');
    icon.className = iconClass;
    if (iconText) {
      icon.textContent = iconText;
    }
    this.labelText.appendChild(icon);
    this.labelText.appendChild(document.createTextNode(' ' + text));
    if (this.labelDuration) {
      this.labelDuration.hidden = true;
    }
  };

  LocalTtsPlayer.prototype._showResumePrompt = function (savedPosition) {
    const self = this;
    clearChildren(this.statusDiv);
    const div = document.createElement('div');
    div.className = 'messages messages--status';
    const link = document.createElement('a');
    link.href = '#';
    link.className = 'local-tts-resume';
    link.textContent = Drupal.t('Resume from @time?', {'@time': formatDuration(savedPosition)});
    link.addEventListener('click', function (e) {
      e.preventDefault();
      self.audioElement.currentTime = savedPosition;
      self._updateStatus('', 'status');
    });
    div.appendChild(link);
    this.statusDiv.appendChild(div);
  };

  LocalTtsPlayer.prototype._showRetryButton = function (errorMsg) {
    const self = this;
    clearChildren(this.statusDiv);
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
      self._updateStatus('', 'status');
      if (self.labelText) {
        self.labelText.textContent = Drupal.t('Listen to this article');
      }
      if (self.isFileMode) {
        const requestId = ++self.generationId;
        self.currentAudioUrl = self.config.audioUrl;
        self._playSpeech(self.config.audioUrl, requestId);
      }
      else {
        self._generateSpeech();
      }
    });

    this.statusDiv.appendChild(wrapper);
    this.statusDiv.appendChild(retryBtn);
    setTimeout(function () { retryBtn.focus(); }, 100);
  };

  LocalTtsPlayer.prototype._updateDurationEstimate = function () {
    if (this.labelDuration && this.config.wordCount) {
      const est = estimateDuration(this.config.wordCount, this.getSelectedSpeed());
      this.labelDuration.textContent = est || '';
      this.labelDuration.hidden = false;
    }
  };

  LocalTtsPlayer.prototype._updateDurationDisplays = function (duration) {
    if (!duration || !isFinite(duration)) {
      return;
    }
    if (this.scrubberInput) {
      this.scrubberInput.max = duration;
      this.scrubberInput.setAttribute('aria-valuemax', duration.toString());
    }
  };

  LocalTtsPlayer.prototype._updateScrubberFill = function (percentage) {
    if (this.scrubberInput) {
      this.scrubberInput.style.background = scrubberGradient(percentage);
    }
  };

  LocalTtsPlayer.prototype._updateDownloadButton = function (url) {
    if (this.downloadButton && url) {
      this.downloadButton.href = url;
      this.downloadButton.hidden = false;
    }
  };

  LocalTtsPlayer.prototype._updateVolumeAria = function (slider, vol) {
    const pct = Math.round(vol * 100);
    slider.setAttribute('aria-valuenow', pct.toString());
    slider.setAttribute('aria-valuetext', Drupal.t('Volume @pct%', {'@pct': pct}));
  };

  // -- Accessibility --

  LocalTtsPlayer.prototype._announceToScreenReader = function (message) {
    const now = Date.now();
    if (now - this.lastAnnounce < ANNOUNCE_THROTTLE_MS) {
      return;
    }
    this.lastAnnounce = now;
    clearChildren(this.statusDiv);
    if (message) {
      const span = document.createElement('span');
      span.textContent = message;
      this.statusDiv.appendChild(span);
    }
  };

  // -- Persistence --

  LocalTtsPlayer.prototype._saveProgress = function (time) {
    try { localStorage.setItem(this.storageKey, time.toString()); }
    catch (e) { /* storage unavailable */ }
  };

  LocalTtsPlayer.prototype._getSavedProgress = function () {
    try {
      const saved = localStorage.getItem(this.storageKey);
      if (saved) {
        const value = parseFloat(saved);
        if (isFinite(value)) {
          return value;
        }
      }
    }
    catch (e) { /* storage unavailable */ }
    return 0;
  };

  LocalTtsPlayer.prototype._clearSavedProgress = function () {
    try { localStorage.removeItem(this.storageKey); }
    catch (e) { /* storage unavailable */ }
  };

  LocalTtsPlayer.prototype._restorePreferences = function () {
    try {
      const savedVoice = localStorage.getItem('local_tts_voice');
      if (savedVoice && this.voiceSelect) {
        const voiceOpts = this.voiceSelect.options;
        for (let i = 0; i < voiceOpts.length; i++) {
          if (voiceOpts[i].value === savedVoice) {
            this.voiceSelect.value = savedVoice;
            break;
          }
        }
      }
      const savedSpeed = localStorage.getItem('local_tts_speed');
      if (savedSpeed && this.speedSelect) {
        const speedOpts = this.speedSelect.options;
        for (let i = 0; i < speedOpts.length; i++) {
          if (speedOpts[i].value === savedSpeed) {
            this.speedSelect.value = savedSpeed;
            break;
          }
        }
      }
    }
    catch (e) { /* storage unavailable */ }
  };

  LocalTtsPlayer.prototype._savePreference = function (key, value) {
    try { localStorage.setItem(key, value); }
    catch (e) { /* storage unavailable */ }
  };

  // -- Audio lifecycle --

  LocalTtsPlayer.prototype._startGenerationTimer = function () {
    const self = this;
    const startTime = Date.now();
    this.generationTimer = setInterval(function () {
      const elapsed = Math.floor((Date.now() - startTime) / 1000);
      const msg = Drupal.t('Generating speech... (@seconds)', {'@seconds': elapsed + 's'});
      if (self.labelText) {
        self._setLabelWithIcon('local-tts-loading', '', msg);
      }
    }, 1000);
  };

  LocalTtsPlayer.prototype._stopGenerationTimer = function () {
    if (this.generationTimer) {
      clearInterval(this.generationTimer);
      this.generationTimer = null;
    }
  };

  LocalTtsPlayer.prototype._revokeBlobUrl = function () {
    if (this.currentBlobUrl) {
      URL.revokeObjectURL(this.currentBlobUrl);
      this.currentBlobUrl = null;
    }
  };

  LocalTtsPlayer.prototype._seekRelative = function (seconds) {
    if (!this.audioElement.duration) {
      return;
    }
    this.audioElement.currentTime = Math.max(0,
      Math.min(this.audioElement.duration, this.audioElement.currentTime + seconds));
  };

  LocalTtsPlayer.prototype._pollForAudio = function (pollUrl, requestId) {
    const self = this;
    const startTime = Date.now();

    function poll() {
      if (requestId !== self.generationId) {
        return;
      }
      if (Date.now() - startTime > MAX_POLL_MS) {
        self._stopGenerationTimer();
        const timeoutMsg = Drupal.t('Audio generation timed out. Please try again later.');
        if (self.labelText) {
          self._setLabelWithIcon('local-tts-error', '⚠', timeoutMsg);
        }
        self.setButtonState('error');
        self._showRetryButton(timeoutMsg);
        return;
      }

      fetch(pollUrl)
        .then(function (response) { return response.json(); })
        .then(function (data) {
          if (requestId !== self.generationId) {
            return;
          }
          if (data.status === 'ready' && data.audio_url) {
            self._stopGenerationTimer();
            self.currentAudioUrl = data.audio_url;
            self._announceToScreenReader(Drupal.t('Audio ready'));
            self._playSpeech(data.audio_url, requestId);
          }
          else {
            setTimeout(poll, POLL_INTERVAL_MS);
          }
        })
        .catch(function () {
          setTimeout(poll, POLL_INTERVAL_MS);
        });
    }

    setTimeout(poll, POLL_INTERVAL_MS);
  };

  LocalTtsPlayer.prototype._generateSpeech = function () {
    const self = this;
    const requestId = ++this.generationId;
    const voice = this.voiceSelect ? this.voiceSelect.value : this.config.defaultVoice;
    const speed = this.getSelectedSpeed();
    this.generatedSpeed = speed;

    if (this.labelText) {
      this._setLabelWithIcon('local-tts-loading', '', Drupal.t('Generating speech...'));
    }
    this.setButtonState('loading');
    this._announceToScreenReader(Drupal.t('Generating speech'));
    this._startGenerationTimer();

    const formData = new FormData();
    formData.append('entity_type', this.config.entityType);
    formData.append('entity_id', this.config.entityId);
    formData.append('voice', voice);
    formData.append('speed', speed);

    if (this.config.language) {
      formData.append('language', this.config.language);
    }
    if (this.config.fields && this.config.fields.length > 0) {
      formData.append('fields', JSON.stringify(this.config.fields));
    }

    fetch(this.config.generateUrl, {
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
        if (requestId !== self.generationId) {
          return;
        }
        if (result.ok && result.data.success && result.data.audio_url) {
          self._stopGenerationTimer();
          self.currentAudioUrl = result.data.audio_url;
          self._announceToScreenReader(Drupal.t('Audio ready'));
          self._playSpeech(result.data.audio_url, requestId);
        }
        else if (result.ok && result.data.status === 'processing' && result.data.poll_url) {
          self._pollForAudio(result.data.poll_url, requestId);
        }
        else {
          self._stopGenerationTimer();
          self._showGenerateError(result);
        }
      })
      .catch(function () {
        if (requestId !== self.generationId) {
          return;
        }
        self._stopGenerationTimer();
        self._handleError(Drupal.t('Could not connect to the server. Check your internet connection.'));
      });
  };

  LocalTtsPlayer.prototype._showGenerateError = function (result) {
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
    this._handleError(msg);
  };

  LocalTtsPlayer.prototype._handleError = function (msg) {
    if (this.labelText) {
      this.labelText.textContent = msg;
    }
    this.setButtonState('error');
    this._announceToScreenReader(msg);
    this._showRetryButton(msg);
  };

  LocalTtsPlayer.prototype._playSpeech = function (audioUrl, requestId) {
    const self = this;

    if (this.cancelAudioLoad) {
      this.cancelAudioLoad();
    }

    let cancelled = false;
    this.cancelAudioLoad = function () {
      cancelled = true;
      self.cancelAudioLoad = null;
    };

    fetch(audioUrl, {credentials: 'same-origin'})
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.blob();
      })
      .then(function (blob) {
        if (cancelled || requestId !== self.generationId) {
          return;
        }
        self._revokeBlobUrl();
        const blobUrl = URL.createObjectURL(blob);
        self.currentBlobUrl = blobUrl;
        self.audioElement.src = blobUrl;
        self.audioElement.load();

        function onReady() {
          self.audioElement.removeEventListener('canplay', onReady);
          self.audioElement.removeEventListener('error', onLoadError);
          if (cancelled || requestId !== self.generationId) {
            return;
          }
          self.cancelAudioLoad = null;
          const savedPosition = self._getSavedProgress();

          self.audioElement.play().then(function () {
            if (requestId !== self.generationId) {
              return;
            }
            self.isPlaying = true;
            self._applyPlaybackRate();
            self.setButtonState('playing');
            self._showPlaybackControls();
            self._updateStatus('', 'status');
            self._updateDownloadButton(audioUrl);
            self._announceToScreenReader(Drupal.t('Playback started'));
            self._dispatchAnalytics('play');

            if (savedPosition > 5 && isFinite(savedPosition) && savedPosition < self.audioElement.duration - 5) {
              self._showResumePrompt(savedPosition);
              setTimeout(function () {
                if (self.statusDiv.querySelector('.local-tts-resume')) {
                  self._updateStatus('', 'status');
                  self._clearSavedProgress();
                }
              }, RESUME_TIMEOUT_MS);
            }
          }).catch(function () {
            if (requestId !== self.generationId) {
              return;
            }
            self.isPlaying = false;
            self._handleError(Drupal.t('Error playing audio.'));
          });
        }

        function onLoadError() {
          self.audioElement.removeEventListener('canplay', onReady);
          self.audioElement.removeEventListener('error', onLoadError);
          self.cancelAudioLoad = null;
          self.currentAudioUrl = null;
          self._handleError(Drupal.t('Audio could not be loaded. The file may be corrupted.'));
        }

        self.audioElement.addEventListener('canplay', onReady, {once: true});
        self.audioElement.addEventListener('error', onLoadError, {once: true});
      })
      .catch(function () {
        if (cancelled || requestId !== self.generationId) {
          return;
        }
        self.cancelAudioLoad = null;
        self.currentAudioUrl = null;
        self._handleError(Drupal.t('Audio could not be loaded. Try refreshing the page.'));
      });
  };

  LocalTtsPlayer.prototype.togglePlay = function () {
    const self = this;
    if (!this.isPlaying) {
      if (!this.currentAudioUrl || this.audioElement.ended) {
        if (this.isFileMode) {
          const requestId = ++this.generationId;
          this.currentAudioUrl = this.config.audioUrl;
          this._playSpeech(this.config.audioUrl, requestId);
          return;
        }
        this._generateSpeech();
      }
      else {
        this.audioElement.play().then(function () {
          self.isPlaying = true;
          self.setButtonState('playing');
          self._showPlaybackControls();
          self._updateStatus('', 'status');
          self._announceToScreenReader(Drupal.t('Playback started'));
        }).catch(function () {
          self.isPlaying = false;
          self._handleError(Drupal.t('Error playing audio.'));
        });
      }
    }
    else {
      this.audioElement.pause();
      this.isPlaying = false;
      this.setButtonState('paused');
      this._updateStatus('', 'status');
      this._announceToScreenReader(Drupal.t('Playback paused'));
      this._dispatchAnalytics('pause');
    }
  };

  LocalTtsPlayer.prototype.stopPlayback = function () {
    this.generationId++;
    this._stopGenerationTimer();
    if (this.cancelAudioLoad) {
      this.cancelAudioLoad();
    }
    if (this.audioElement) {
      this.audioElement.pause();
      this.audioElement.currentTime = 0;
    }
    this.isPlaying = false;
    this.currentAudioUrl = null;

    if (this.scrubberInput) {
      this.scrubberInput.value = 0;
      this.scrubberInput.disabled = true;
    }
    if (this.currentTimeDisplay) {
      this.currentTimeDisplay.textContent = '0:00';
    }
    if (this.remainingTimeDisplay) {
      this.remainingTimeDisplay.textContent = '';
    }
    if (this.labelText) {
      this.labelText.textContent = Drupal.t('Listen to this article');
    }

    this._hidePlaybackControls();
    this.setButtonState('ready');
    this._updateStatus('', 'status');
    this._updateDurationEstimate();
    if (this.downloadButton) {
      this.downloadButton.hidden = true;
    }
  };

  // -- Analytics --

  LocalTtsPlayer.prototype._dispatchAnalytics = function (action) {
    const detail = {action: action};
    if (this.isFileMode) {
      detail.audioUrl = this.config.audioUrl;
    }
    else {
      detail.entityType = this.config.entityType;
      detail.entityId = this.config.entityId;
    }
    this.container.dispatchEvent(new CustomEvent('localTtsEvent', {
      bubbles: true,
      detail: detail,
    }));
  };

  // -- Event binding --

  LocalTtsPlayer.prototype._bindEvents = function () {
    const self = this;
    const h = this._boundHandlers;

    h.playClick = function (e) { e.preventDefault(); self.togglePlay(); };
    h.playKeydown = function (e) {
      if (e.key === ' ' || e.key === 'Spacebar') {
        e.preventDefault();
        self.togglePlay();
      }
    };
    this.playButton.addEventListener('click', h.playClick);
    this.playButton.addEventListener('keydown', h.playKeydown);

    if (this.skipBackBtn) {
      h.skipBack = function () { self._seekRelative(-10); };
      this.skipBackBtn.addEventListener('click', h.skipBack);
    }
    if (this.skipForwardBtn) {
      h.skipForward = function () { self._seekRelative(10); };
      this.skipForwardBtn.addEventListener('click', h.skipForward);
    }

    h.containerKeydown = function (e) {
      const tag = e.target.tagName.toLowerCase();
      if (tag === 'select' || tag === 'input' || tag === 'textarea') {
        return;
      }
      if (e.key === 'ArrowLeft') {
        e.preventDefault();
        self._seekRelative(-10);
      }
      else if (e.key === 'ArrowRight') {
        e.preventDefault();
        self._seekRelative(10);
      }
    };
    this.container.addEventListener('keydown', h.containerKeydown);

    h.audioEnded = function () {
      self.isPlaying = false;
      self._clearSavedProgress();
      self.setButtonState('ready');
      self._announceToScreenReader(Drupal.t('Playback ended'));
      self.stopPlayback();
      self._dispatchAnalytics('ended');
    };

    h.audioPause = function () {
      if (!self.audioElement.ended && self.audioElement.currentTime > 0) {
        self.isPlaying = false;
        self.setButtonState('paused');
      }
    };

    h.audioPlay = function () {
      self.isPlaying = true;
      self.setButtonState('playing');
      self._showPlaybackControls();
    };

    h.audioError = function () {
      self.isPlaying = false;
      self.currentAudioUrl = null;
      self._handleError(Drupal.t('Error playing audio.'));
    };

    h.audioLoadedMeta = function () {
      self._updateDurationDisplays(self.audioElement.duration);
      if (self.scrubberInput) {
        self.scrubberInput.disabled = false;
      }
    };

    h.audioTimeUpdate = function () {
      if (!self.audioElement.duration) {
        return;
      }
      const ct = self.audioElement.currentTime;
      const dur = self.audioElement.duration;
      const percentage = (ct / dur) * 100;

      self._updateScrubberFill(percentage);
      if (self.scrubberInput) {
        self.scrubberInput.value = ct;
      }
      if (self.currentTimeDisplay) {
        self.currentTimeDisplay.textContent = formatDuration(ct);
      }
      if (self.remainingTimeDisplay) {
        self.remainingTimeDisplay.textContent = '-' + formatDuration(dur - ct);
      }
      const now = Date.now();
      if (now - self.lastProgressSave >= PROGRESS_SAVE_MS) {
        self.lastProgressSave = now;
        self._saveProgress(ct);
      }
    };

    this.audioElement.addEventListener('ended', h.audioEnded);
    this.audioElement.addEventListener('pause', h.audioPause);
    this.audioElement.addEventListener('play', h.audioPlay);
    this.audioElement.addEventListener('error', h.audioError);
    this.audioElement.addEventListener('loadedmetadata', h.audioLoadedMeta);
    this.audioElement.addEventListener('timeupdate', h.audioTimeUpdate);

    if (this.scrubberInput) {
      let wasPlayingBeforeScrub = false;

      h.scrubberInput = function () {
        const newTime = parseFloat(self.scrubberInput.value);
        self.audioElement.currentTime = newTime;
        if (self.audioElement.duration) {
          const pct = (newTime / self.audioElement.duration) * 100;
          self._updateScrubberFill(pct);
        }
        const now = Date.now();
        if (now - self.lastAriaUpdate > ARIA_THROTTLE_MS) {
          self.scrubberInput.setAttribute('aria-valuenow', newTime.toString());
          self.scrubberInput.setAttribute('aria-valuetext', getAriaTimeText(newTime, self.audioElement.duration));
          self.lastAriaUpdate = now;
        }
      };

      h.scrubberFocus = function () {
        if (self.audioElement.duration) {
          self.scrubberInput.setAttribute('aria-valuenow', self.audioElement.currentTime.toString());
          self.scrubberInput.setAttribute('aria-valuetext', getAriaTimeText(self.audioElement.currentTime, self.audioElement.duration));
        }
      };

      h.scrubberMousedown = function () {
        wasPlayingBeforeScrub = !self.audioElement.paused;
        if (wasPlayingBeforeScrub) {
          self.audioElement.pause();
        }
      };

      h.scrubberMouseup = function () {
        if (wasPlayingBeforeScrub) {
          self.audioElement.play();
        }
      };

      this.scrubberInput.addEventListener('input', h.scrubberInput);
      this.scrubberInput.addEventListener('focus', h.scrubberFocus);
      this.scrubberInput.addEventListener('mousedown', h.scrubberMousedown);
      this.scrubberInput.addEventListener('mouseup', h.scrubberMouseup);
    }

    if (this.muteButton) {
      h.muteClick = function () {
        if (self.audioElement.muted) {
          self.audioElement.muted = false;
          self.audioElement.volume = self.previousVolume || 1;
          self.muteButton.setAttribute('aria-pressed', 'false');
          self.muteButton.classList.remove('muted');
          if (self.volumeSlider) {
            self.volumeSlider.value = self.audioElement.volume;
            self._updateVolumeAria(self.volumeSlider, self.audioElement.volume);
          }
        }
        else {
          self.previousVolume = self.audioElement.volume;
          self.audioElement.muted = true;
          self.muteButton.setAttribute('aria-pressed', 'true');
          self.muteButton.classList.add('muted');
          if (self.volumeSlider) {
            self.volumeSlider.value = 0;
            self._updateVolumeAria(self.volumeSlider, 0);
          }
        }
      };
      this.muteButton.addEventListener('click', h.muteClick);
    }

    if (this.volumeSlider) {
      h.volumeInput = function () {
        const vol = parseFloat(self.volumeSlider.value);
        self.audioElement.volume = vol;
        self.audioElement.muted = vol === 0;
        if (self.muteButton) {
          self.muteButton.setAttribute('aria-pressed', vol === 0 ? 'true' : 'false');
          self.muteButton.classList.toggle('muted', vol === 0);
        }
        self._updateVolumeAria(self.volumeSlider, vol);
      };
      this.volumeSlider.addEventListener('input', h.volumeInput);
    }

    if (this.voiceSelect) {
      h.voiceChange = function () {
        self._savePreference('local_tts_voice', self.voiceSelect.value);
        if (!self.isFileMode) {
          self.stopPlayback();
        }
      };
      this.voiceSelect.addEventListener('change', h.voiceChange);
    }

    if (this.speedSelect) {
      h.speedChange = function () {
        self._savePreference('local_tts_speed', self.speedSelect.value);
        self._applyPlaybackRate();
        self._updateDurationEstimate();
      };
      this.speedSelect.addEventListener('change', h.speedChange);
    }
  };

  LocalTtsPlayer.prototype._unbindEvents = function () {
    const h = this._boundHandlers;

    this.playButton.removeEventListener('click', h.playClick);
    this.playButton.removeEventListener('keydown', h.playKeydown);

    if (this.skipBackBtn && h.skipBack) {
      this.skipBackBtn.removeEventListener('click', h.skipBack);
    }
    if (this.skipForwardBtn && h.skipForward) {
      this.skipForwardBtn.removeEventListener('click', h.skipForward);
    }

    this.container.removeEventListener('keydown', h.containerKeydown);

    this.audioElement.removeEventListener('ended', h.audioEnded);
    this.audioElement.removeEventListener('pause', h.audioPause);
    this.audioElement.removeEventListener('play', h.audioPlay);
    this.audioElement.removeEventListener('error', h.audioError);
    this.audioElement.removeEventListener('loadedmetadata', h.audioLoadedMeta);
    this.audioElement.removeEventListener('timeupdate', h.audioTimeUpdate);

    if (this.scrubberInput) {
      this.scrubberInput.removeEventListener('input', h.scrubberInput);
      this.scrubberInput.removeEventListener('focus', h.scrubberFocus);
      this.scrubberInput.removeEventListener('mousedown', h.scrubberMousedown);
      this.scrubberInput.removeEventListener('mouseup', h.scrubberMouseup);
    }

    if (this.muteButton && h.muteClick) {
      this.muteButton.removeEventListener('click', h.muteClick);
    }
    if (this.volumeSlider && h.volumeInput) {
      this.volumeSlider.removeEventListener('input', h.volumeInput);
    }
    if (this.voiceSelect && h.voiceChange) {
      this.voiceSelect.removeEventListener('change', h.voiceChange);
    }
    if (this.speedSelect && h.speedChange) {
      this.speedSelect.removeEventListener('change', h.speedChange);
    }
  };

  // -- Cleanup --

  LocalTtsPlayer.prototype.destroy = function () {
    this._stopGenerationTimer();
    if (this.cancelAudioLoad) {
      this.cancelAudioLoad();
    }
    if (this.audioElement) {
      this.audioElement.pause();
      this.audioElement.removeAttribute('src');
      this.audioElement.load();
    }
    this._revokeBlobUrl();
    this._unbindEvents();
    this.isPlaying = false;
    this.currentAudioUrl = null;
  };

  // -- Drupal behavior --

  Drupal.behaviors.localTtsPlayer = {
    attach: function (context) {
      const players = once('local-tts-player', '.local-tts-container', context);
      players.forEach(function (container) {
        const instance = new LocalTtsPlayer(container);
        container._localTtsPlayer = instance;
      });
    },

    detach: function (context, settings, trigger) {
      if (trigger !== 'unload') {
        return;
      }
      const containers = once.remove('local-tts-player', '.local-tts-container', context);
      containers.forEach(function (container) {
        const instance = container._localTtsPlayer;
        if (instance) {
          instance.destroy();
          delete container._localTtsPlayer;
        }
      });
    },
  };

})(Drupal, drupalSettings, once);
