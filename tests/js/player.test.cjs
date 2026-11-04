const { test } = require("node:test");
const assert = require("node:assert/strict");
const { readFileSync } = require("node:fs");
const { JSDOM } = require("jsdom");

const flush = () => new Promise((resolve) => setImmediate(resolve));
function setup(preview = false) {
  const markup = preview
    ? `<form id="local-tts-voice-settings-form">
    <div><select name="voice_settings[en]"><option>af_sky</option></select></div>
    <div><select name="voice_settings[fr]"><option>ff_siwis</option></select></div>
  </form>`
    : `<div class="local-tts-container" data-tts-id="one">
    <button class="local-tts-play-button"></button>
    <div class="local-tts-label"><span class="local-tts-label-text"></span></div>
    <div class="local-tts-playback-controls" hidden>
      <span class="local-tts-current-time">0:00</span>
      <input type="range" class="local-tts-scrubber" min="0" max="100" value="0">
      <span class="local-tts-remaining-time"></span>
    </div><div class="local-tts-status"></div><audio></audio></div>`;
  const dom = new JSDOM(markup, {
    url: "http://localhost/",
    runScripts: "outside-only",
  });
  const w = dom.window;
  const timers = new Map();
  let nextTimer = 0;
  w.setTimeout = w.setInterval = (fn, delay) => {
    timers.set(++nextTimer, { fn, delay });
    return nextTimer;
  };
  w.clearTimeout = w.clearInterval = (id) => timers.delete(id);
  w.Drupal = {
    behaviors: {},
    t: (s, args = {}) =>
      Object.entries(args).reduce((s, [k, v]) => s.replace(k, v), s),
    Message: class {
      clear() {}
      add() {}
    },
  };
  w.drupalSettings = {
    localTts: {
      generateUrl: "/generate",
      voicePreviewUrl: "/preview",
      entityType: "node",
      entityId: 1,
      defaultSpeed: 1,
      defaultVoice: "af_sky",
    },
  };
  const seen = new WeakSet();
  w.once = (id, selector, ctx) =>
    [...ctx.querySelectorAll(selector)].filter((el) => {
      if (seen.has(el)) return false;
      seen.add(el);
      return true;
    });
  w.once.remove = (id, selector, ctx) =>
    [...ctx.querySelectorAll(selector)].filter((el) => seen.delete(el));
  const requests = [];
  w.fetch = () =>
    new Promise((resolve) =>
      requests.push((data) =>
        resolve({
          ok: true,
          status: 200,
          headers: { get: () => "application/json" },
          json: () => Promise.resolve(data),
        }),
      ),
    );
  let plays = 0;
  w.HTMLMediaElement.prototype.play = function () {
    plays++;
    this._paused = false;
    return Promise.resolve();
  };
  w.HTMLMediaElement.prototype.pause = function () {
    this._paused = true;
  };
  w.HTMLMediaElement.prototype.load = function () {};
  Object.defineProperty(w.HTMLMediaElement.prototype, "paused", {
    get() {
      return this._paused !== false;
    },
  });
  Object.defineProperty(w.HTMLMediaElement.prototype, "duration", {
    get() {
      return 100;
    },
  });
  const audios = [];
  w.Audio = function (url) {
    const audio = w.document.createElement("audio");
    audio.src = url;
    audios.push(audio);
    return audio;
  };
  w.eval(
    readFileSync(
      require("node:path").join(
        __dirname,
        "../../js/",
        preview ? "local-tts-voice-preview.js" : "local-tts-player.js",
      ),
      "utf8",
    ),
  );
  const behavior =
    w.Drupal.behaviors[preview ? "localTtsVoicePreview" : "localTtsPlayer"];
  behavior.attach(w.document);
  return {
    w,
    dom,
    timers,
    requests,
    audios,
    behavior,
    player: w.document.querySelector(".local-tts-container")?._localTtsPlayer,
    get plays() {
      return plays;
    },
  };
}

test("removed player ignores pending generation response", async () => {
  const s = setup();
  s.player.togglePlay();
  s.behavior.detach(s.w.document, {}, "unload");
  s.requests[0]({ success: true, audio_url: "/audio.ogg" });
  await flush();
  assert.equal(s.plays, 0);
  s.dom.window.close();
});
test("pending resume cannot restore playing state after stop", async () => {
  const s = setup();
  s.player.currentAudioUrl = "/audio.ogg";
  s.player.togglePlay();
  s.player.stopPlayback();
  await flush();
  assert.equal(s.player.isPlaying, false);
  s.dom.window.close();
});
test("scrubbing resumes when mouse is released outside the slider", async () => {
  const s = setup();
  await s.player.audioElement.play();
  s.player.scrubberInput.dispatchEvent(
    new s.w.MouseEvent("mousedown", { bubbles: true }),
  );
  s.w.document.dispatchEvent(new s.w.MouseEvent("mouseup", { bubbles: true }));
  await flush();
  assert.equal(s.player.audioElement.paused, false);
  s.dom.window.close();
});
test("timeline shows zero and keeps ARIA position in sync", () => {
  const s = setup();
  s.player.audioElement.dispatchEvent(new s.w.Event("timeupdate"));
  assert.equal(s.player.currentTimeDisplay.textContent, "0:00");
  s.player.audioElement.currentTime = 12;
  s.player.audioElement.dispatchEvent(new s.w.Event("timeupdate"));
  assert.equal(s.player.scrubberInput.getAttribute("aria-valuenow"), "12");
  s.dom.window.close();
});
test("reattaching behavior does not duplicate players", () => {
  const s = setup();
  s.behavior.attach(s.w.document);
  s.player.playButton.click();
  assert.equal(s.requests.length, 1);
  s.dom.window.close();
});
test("latest voice preview wins when responses arrive out of order", async () => {
  const s = setup(true);
  const buttons = s.w.document.querySelectorAll("button");
  buttons[0].click();
  buttons[1].click();
  s.requests[1]({ success: true, audio_url: "/new.ogg" });
  await flush();
  s.requests[0]({ success: true, audio_url: "/old.ogg" });
  await flush();
  assert.equal(s.audios.filter((a) => !a.paused).length, 1);
  assert.equal(s.audios.find((a) => !a.paused).getAttribute("src"), "/new.ogg");
  s.dom.window.close();
});
test("late error from stopped preview leaves current preview stoppable", async () => {
  const s = setup(true);
  const buttons = s.w.document.querySelectorAll("button");
  buttons[0].click();
  s.requests[0]({ success: true, audio_url: "/old.ogg" });
  await flush();
  buttons[1].click();
  s.requests[1]({ success: true, audio_url: "/new.ogg" });
  await flush();
  s.audios[0].dispatchEvent(new s.w.Event("error"));
  buttons[1].click();
  assert.equal(s.audios[1].paused, true);
  s.dom.window.close();
});
test("preview detach stops audio and invalidates pending requests", async () => {
  const s = setup(true);
  s.w.document.querySelector("button").click();
  s.behavior.detach?.(s.w.document, {}, "unload");
  s.requests[0]({ success: true, audio_url: "/old.ogg" });
  await flush();
  assert.equal(s.plays, 0);
  s.dom.window.close();
});
test("detach clears poll, focus, and resume timers", async () => {
  const s = setup();
  s.player.togglePlay();
  s.requests[0]({ status: "processing", poll_url: "/poll" });
  await flush();
  s.behavior.detach(s.w.document, {}, "unload");
  assert.equal(s.timers.size, 0);
  s.dom.window.close();
});
test("late polling response after detach never starts audio", async () => {
  const s = setup();
  s.player.togglePlay();
  s.requests[0]({ status: "processing", poll_url: "/poll" });
  await flush();
  const [id, timer] = [...s.timers].find(([, timer]) => timer.delay === 3000);
  s.timers.delete(id);
  timer.fn();
  s.behavior.detach(s.w.document, {}, "unload");
  s.requests[1]({ status: "ready", audio_url: "/audio.ogg" });
  await flush();
  assert.equal(s.plays, 0);
  s.dom.window.close();
});
test("speed changes use ratio to synthesis speed", () => {
  const s = setup();
  s.player.config.defaultSpeed = 2;
  s.player.generatedSpeed = 0.8;
  s.player._applyPlaybackRate();
  assert.equal(s.player.audioElement.playbackRate, 2.5);
  s.dom.window.close();
});
test("seeking clamps to the start and end", () => {
  const s = setup();
  s.player._seekRelative(-10);
  assert.equal(s.player.audioElement.currentTime, 0);
  s.player._seekRelative(200);
  assert.equal(s.player.audioElement.currentTime, 100);
  s.dom.window.close();
});
test("100 simultaneous players detach without timers or late playback", async () => {
  const s = setup();
  const first = s.w.document.querySelector(".local-tts-container");
  for (let i = 1; i < 100; i++) s.w.document.body.append(first.cloneNode(true));
  s.behavior.attach(s.w.document);
  for (const el of s.w.document.querySelectorAll(".local-tts-container"))
    el._localTtsPlayer.togglePlay();
  assert.equal(s.requests.length, 100);
  for (const resolve of s.requests)
    resolve({ status: "processing", poll_url: "/poll" });
  await flush();
  s.behavior.detach(s.w.document, {}, "unload");
  assert.equal(s.timers.size, 0);
  assert.equal(s.plays, 0);
  s.dom.window.close();
});
test("stop clears pending playback focus and resume prompt callbacks", async () => {
  const s = setup();
  s.w.localStorage.setItem(s.player.storageKey, "30");
  s.player._playSpeech("/audio.ogg", s.player.generationId);
  await flush();
  assert.ok(s.timers.size > 0);
  s.player.stopPlayback();
  assert.equal(s.timers.size, 0);
  s.dom.window.close();
});
test("storage denial does not prevent player initialization or playback", async () => {
  const s = setup();
  Object.defineProperty(s.w, "localStorage", {
    get() {
      throw new Error("denied");
    },
  });
  s.player._restorePreferences();
  s.player._saveProgress(20);
  s.player._savePreference("voice", "af_sky");
  assert.equal(s.player._getSavedProgress(), 0);
  s.player._clearSavedProgress();
  s.player._playSpeech("/audio.ogg", s.player.generationId);
  await flush();
  assert.equal(s.player.isPlaying, true);
  s.dom.window.close();
});

test("queued media events cannot resurrect stopped playback", () => {
  const s = setup();
  s.player.stopPlayback();
  s.player.audioElement.dispatchEvent(new s.w.Event("play"));
  assert.equal(s.player.isPlaying, false);
  assert.equal(s.player.playbackControls.hidden, true);
  s.dom.window.close();
});

test("pause at the beginning synchronizes the player state", () => {
  const s = setup();
  s.player.isPlaying = true;
  s.player.audioElement.dispatchEvent(new s.w.Event("pause"));
  assert.equal(s.player.isPlaying, false);
  s.dom.window.close();
});
