// Test-only IFrame API contract fixture. No YouTube content is downloaded.
window.__youtubePlayers = [];
window.YT = {
  Player: class {
    constructor(element, options) {
      this.options = options;
      this.time = 0;
      this.state = 2;
      this.started = performance.now();
      this.destroyed = false;
      this.iframe = document.createElement("iframe");
      this.iframe.src = `https://www.youtube.com/embed/${options.videoId}?enablejsapi=1&origin=${encodeURIComponent(options.playerVars.origin)}`;
      this.iframe.title = "YouTube player";
      this.iframe.setAttribute("allowfullscreen", "");
      element.replaceWith(this.iframe);
      window.__youtubePlayers.push(this);
      queueMicrotask(() => options.events.onReady({ target: this }));
    }
    getDuration() { return 120; }
    getCurrentTime() { return this.time + (this.state === 1 ? (performance.now() - this.started) / 1000 : 0); }
    getPlayerState() { return this.state; }
    getIframe() { return this.iframe; }
    seekTo(value) { this.time = value; this.started = performance.now(); }
    stateChanged(value) {
      this.time = this.getCurrentTime(); this.started = performance.now(); this.state = value;
      this.options.events.onStateChange({ target: this, data: value });
    }
    playVideo() { this.stateChanged(1); }
    pauseVideo() { this.stateChanged(2); }
    finish() { this.seekTo(120); this.stateChanged(0); }
    fail(code) { this.options.events.onError({ target: this, data: code }); }
    destroy() { this.destroyed = true; this.state = 2; this.iframe.remove(); }
  },
};
window.onYouTubeIframeAPIReady();
