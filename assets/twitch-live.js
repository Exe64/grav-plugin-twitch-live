// twitch-live: a click on a live channel (its image, its name or "Watch") opens its player in the stage at
// the top of the section, one at a time; a second click or "Close the player" closes it. Ctrl, Cmd, Shift or
// middle click keep the plain link to Twitch. The strip presentation gets previous/next buttons.
// Texts come translated from the template (data-player-title).
(function () {
  var calm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.querySelectorAll('.twitch').forEach(function (sec) {
    var stage = sec.querySelector('.tw-stage'), frame = stage.querySelector('.tw-frame'), now = stage.querySelector('.tw-now');
    var triggers = sec.querySelectorAll('[data-channel]'), current = null, opener = null;
    function mark() {
      triggers.forEach(function (t) { if (!t.hasAttribute('aria-hidden')) t.setAttribute('aria-expanded', t.dataset.channel === current ? 'true' : 'false'); });
    }
    function close(refocus) {
      frame.textContent = ''; stage.hidden = true; current = null; mark();
      if (refocus && opener) opener.focus();
    }
    triggers.forEach(function (t) {
      if (!t.hasAttribute('aria-hidden')) t.setAttribute('aria-controls', stage.id);
      t.addEventListener('click', function (e) {
        if (e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        var ch = t.dataset.channel;
        if (current === ch) { close(false); return; }
        var name = sec.querySelector('.twitch-link[data-channel="' + ch + '"] .twitch-name');
        opener = t.hasAttribute('aria-hidden') ? name.parentNode : t;
        var f = document.createElement('iframe');
        // Twitch only plays on the host names given in parent: the current one
        f.src = 'https://player.twitch.tv/?channel=' + encodeURIComponent(ch) + '&parent=' + encodeURIComponent(location.hostname) + '&autoplay=true';
        f.title = sec.dataset.playerTitle.replace('%s', name.textContent);
        f.allow = 'autoplay; fullscreen';
        f.allowFullscreen = true;
        frame.textContent = ''; frame.appendChild(f);
        now.textContent = name.textContent;
        stage.hidden = false; current = ch; mark();
        stage.scrollIntoView({ block: 'nearest', behavior: calm ? 'auto' : 'smooth' });
      });
    });
    stage.querySelector('.tw-close').addEventListener('click', function () { close(true); });
    mark();

    var ctl = sec.querySelector('.tw-strip-ctl'), track = sec.querySelector('.tw-items');
    if (!ctl || !track) return;
    var prev = ctl.querySelector('.tw-prev'), next = ctl.querySelector('.tw-next');
    function ends() {
      prev.disabled = track.scrollLeft <= 1;
      next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 1;
      ctl.hidden = prev.disabled && next.disabled;
    }
    function by(dir) { track.scrollBy({ left: dir * track.clientWidth * 0.85, behavior: calm ? 'auto' : 'smooth' }); }
    prev.addEventListener('click', function () { by(-1); });
    next.addEventListener('click', function () { by(1); });
    track.addEventListener('scroll', ends, { passive: true });
    window.addEventListener('resize', ends);
    ends();
  });
})();
