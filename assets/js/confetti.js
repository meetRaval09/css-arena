/* ────────────────────────────────────────────────────────────────
   Confetti, written from scratch on a canvas.

   No library and no CDN: the hall has no internet, so anything fetched at
   runtime would simply not arrive. This is a few hundred paper rectangles
   under gravity, spinning about their own axis — the spin is what sells it,
   because a rectangle seen edge-on goes thin and catches the eye.

   It stops on its own, removes its canvas, and does nothing at all if the
   machine asks for reduced motion.
   ──────────────────────────────────────────────────────────────── */
(function (w) {
  'use strict';

  var COLORS = ['#7c5cff', '#22e3d6', '#c9f24a', '#ffb62e', '#ff4fa3', '#f4f1ff'];

  function confetti(opts) {
    opts = opts || {};
    if (w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var count    = opts.count    || 190;
    var duration = opts.duration || 4200;   // ms before we stop drawing

    var cv = document.createElement('canvas');
    cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;' +
                       'pointer-events:none;z-index:9999';
    document.body.appendChild(cv);

    var ctx = cv.getContext('2d');
    var dpr = Math.min(w.devicePixelRatio || 1, 2);
    var W, H;

    function size() {
      W = cv.clientWidth; H = cv.clientHeight;
      cv.width = W * dpr; cv.height = H * dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    size();
    w.addEventListener('resize', size);

    /* Two cannons, one in from each bottom corner, firing up and inward.
       Corners beat a single burst from the middle: the pieces cross, so the
       screen fills instead of leaving a hole where the message is. */
    var bits = [];
    for (var i = 0; i < count; i++) {
      var left  = i % 2 === 0;
      var angle = (left ? -60 : -120) + (Math.random() * 34 - 17);   // degrees
      var speed = 11 + Math.random() * 11;
      var rad   = angle * Math.PI / 180;
      bits.push({
        x:  left ? W * 0.08 : W * 0.92,
        y:  H * 0.98,
        vx: Math.cos(rad) * speed,
        vy: Math.sin(rad) * speed,
        w:  5 + Math.random() * 6,
        h:  8 + Math.random() * 7,
        col: COLORS[(Math.random() * COLORS.length) | 0],
        rot: Math.random() * Math.PI * 2,
        spin: (Math.random() - 0.5) * 0.4,
        tilt: Math.random() * Math.PI * 2,
        tiltSpeed: 0.08 + Math.random() * 0.12,
        life: 1
      });
    }

    var t0 = 0;

    function frame(t) {
      if (!t0) t0 = t;
      var age = t - t0;

      ctx.clearRect(0, 0, W, H);
      var alive = 0;

      for (var i = 0; i < bits.length; i++) {
        var b = bits[i];

        b.vy += 0.32;          // gravity
        b.vx *= 0.988;         // air
        b.vy *= 0.992;
        b.x  += b.vx;
        b.y  += b.vy;
        b.rot  += b.spin;
        b.tilt += b.tiltSpeed;

        if (age > duration - 1100) b.life = Math.max(0, (duration - age) / 1100);
        if (b.y - 40 > H || b.life <= 0) continue;
        alive++;

        ctx.save();
        ctx.translate(b.x, b.y);
        ctx.rotate(b.rot);
        /* scaleY by cos(tilt) is the whole trick: the piece turns edge-on and
           back, so a flat rectangle reads as a tumbling scrap of paper. */
        ctx.scale(1, Math.cos(b.tilt));
        ctx.globalAlpha = b.life;
        ctx.fillStyle = b.col;
        ctx.fillRect(-b.w / 2, -b.h / 2, b.w, b.h);
        ctx.restore();
      }

      if (alive > 0 && age < duration) {
        w.requestAnimationFrame(frame);
      } else {
        w.removeEventListener('resize', size);
        if (cv.parentNode) cv.parentNode.removeChild(cv);
      }
    }
    w.requestAnimationFrame(frame);
  }

  w.confetti = confetti;
})(window);
