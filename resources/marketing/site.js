/* Hero slider (autoplay, dots, arrows, swipe, pause on hover/focus), reveal on scroll, counters. Under 2 KB. */
(function () {
  var d = document, still = matchMedia('(prefers-reduced-motion: reduce)').matches;
  d.documentElement.classList.add('js');
  d.querySelectorAll('[data-slider]').forEach(function (box) {
    var slides = box.querySelectorAll('.slide'), dots = box.querySelectorAll('.dotset button'), i = 0, timer, dur;
    function speed() { dur = (+box.getAttribute('data-seconds') || 7) * 1000; box.style.setProperty('--dur', dur / 1000 + 's'); }
    speed(); 
    function show(n) {
      if (n === i && slides[i].classList.contains('on')) { dots[i].classList.remove('on'); void dots[i].offsetWidth; dots[i].classList.add('on'); play(); return; }
      slides[i].classList.remove('on'); slides[i].classList.add('out'); dots[i].classList.remove('on');
      var old = slides[i]; setTimeout(function () { old.classList.remove('out'); }, 900);
      i = (n + slides.length) % slides.length;
      slides[i].classList.add('on'); dots[i].classList.add('on'); play();
    }
    function play() { clearTimeout(timer); if (!still && box.getAttribute('data-autoplay') !== 'false' && !box.classList.contains('paused')) timer = setTimeout(function () { show(i + 1); }, dur); }
    dots.forEach(function (b, n) { b.onclick = function () { show(n); }; });
    box.querySelector('[data-prev]').onclick = function () { show(i - 1); };
    box.querySelector('[data-next]').onclick = function () { show(i + 1); };
    box.onmouseenter = box.onfocusin = function () { box.classList.add('paused'); clearTimeout(timer); };
    box.onmouseleave = box.onfocusout = function () { box.classList.remove('paused'); play(); };
    var x0 = null;
    box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) { if (x0 === null) return; var dx = e.changedTouches[0].clientX - x0; if (Math.abs(dx) > 40) show(i + (dx < 0 ? 1 : -1)); x0 = null; });
    play();
  });
  var items = d.querySelectorAll('.sec-head,.card,.plan,.gal figure,.offer,.partner,.final,.panel,.list li');
  items.forEach(function (el) { el.classList.add('rv'); });
  var io = new IntersectionObserver(function (es) {
    es.forEach(function (e) {
      if (!e.isIntersecting) return;
      var el = e.target; el.classList.add('in'); io.unobserve(el);
      var to = +el.getAttribute('data-count');
      if (to && !still) { var t0 = performance.now(); (function step(t) { var p = Math.min(1, (t - t0) / 1200); el.textContent = Math.round(to * (1 - Math.pow(1 - p, 3))); if (p < 1) requestAnimationFrame(step); })(t0); }
    });
  }, { threshold: .15 });
  items.forEach(function (el) { io.observe(el); });
  d.querySelectorAll('[data-count]').forEach(function (el) { io.observe(el); });
})();
/* An offer's countdown, every minute. */
(function () {
  document.querySelectorAll('[data-ends]').forEach(function (box) {
    var end = Date.parse(box.getAttribute('data-ends')), parts = box.querySelectorAll('b');
    function tick() {
      var left = Math.max(0, Math.floor((end - Date.now()) / 60000));
      if (!left) { box.closest('.offer-wrap').hidden = true; return; }
      [Math.floor(left / 1440), Math.floor(left % 1440 / 60), left % 60].forEach(function (n, k) { if (parts[k]) parts[k].textContent = new Intl.NumberFormat(document.documentElement.lang).format(n); });
    }
    tick(); setInterval(tick, 60000);
  });
})();
/* A look link's Customize panel: show each choice at once. */
document.querySelectorAll('.look-form').forEach(function (f) { f.addEventListener('change', function () { f.submit(); }); });
