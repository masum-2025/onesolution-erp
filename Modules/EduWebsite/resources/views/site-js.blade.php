(function () {
    'use strict';
    var root = document.documentElement;
    var calm = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var lang = root.lang || 'en';
    var number = function (value) {
        try { return new Intl.NumberFormat(lang).format(value); } catch (e) { return String(value); }
    };
    root.classList.add('js');

    var header = document.querySelector('header.top');
    if (header) {
        var mark = function () { header.classList.toggle('scrolled', window.scrollY > 8); };
        window.addEventListener('scroll', mark, { passive: true });
        mark();
    }

    var seen = function (elements, show) {
        if (!('IntersectionObserver' in window)) { elements.forEach(show); return; }
        var watcher = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { show(entry.target); watcher.unobserve(entry.target); }
            });
        }, { rootMargin: '0px 0px -10% 0px' });
        elements.forEach(function (element) { watcher.observe(element); });
    };
    seen([].slice.call(document.querySelectorAll('.reveal')), function (element) { element.classList.add('in'); });

    var swipe = function (element, back, forward) {
        var start = null;
        element.addEventListener('pointerdown', function (event) { start = event.clientX; });
        element.addEventListener('pointerup', function (event) {
            if (start === null) return;
            var moved = event.clientX - start;
            start = null;
            if (Math.abs(moved) < 40) return;
            (moved > 0) === (root.dir === 'rtl') ? forward() : back();
        });
    };

    [].slice.call(document.querySelectorAll('.slider')).forEach(function (slider) {
        var track = slider.querySelector('.track');
        var slides = [].slice.call(track.children);
        var dots = [].slice.call(slider.querySelectorAll('.dots button'));
        var fade = slider.classList.contains('fx-fade');
        var index = 0;
        var timer = null;
        var wait = Math.max(3, Math.min(10, parseInt(slider.getAttribute('data-interval'), 10) || 5)) * 1000;
        if (slides.length < 2) { slides.forEach(function (slide) { slide.classList.add('on'); }); return; }
        slider.classList.add('live');
        [].slice.call(slider.querySelectorAll('[hidden]')).forEach(function (element) { element.hidden = false; });

        var show = function (next, user) {
            index = (next + slides.length) % slides.length;
            slides.forEach(function (slide, at) {
                slide.classList.toggle('on', at === index);
                slide.setAttribute('aria-hidden', at === index ? 'false' : 'true');
            });
            dots.forEach(function (dot, at) { dot.setAttribute('aria-current', at === index ? 'true' : 'false'); });
            if (!fade) track.scrollTo({ left: slides[index].offsetLeft, behavior: calm || !user ? 'auto' : 'smooth' });
            if (user) restart();
        };
        var stop = function () { clearInterval(timer); timer = null; };
        var play = function () {
            if (calm || slider.getAttribute('data-autoplay') !== '1' || timer || document.hidden) return;
            timer = setInterval(function () { show(index + 1, false); }, wait);
        };
        var restart = function () { stop(); play(); };

        slider.querySelector('.prev').addEventListener('click', function () { show(index - 1, true); });
        slider.querySelector('.next').addEventListener('click', function () { show(index + 1, true); });
        dots.forEach(function (dot, at) { dot.addEventListener('click', function () { show(at, true); }); });
        slider.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') show(index - 1, true);
            if (event.key === 'ArrowRight') show(index + 1, true);
        });
        slider.addEventListener('mouseenter', stop);
        slider.addEventListener('mouseleave', play);
        slider.addEventListener('focusin', stop);
        slider.addEventListener('focusout', play);
        document.addEventListener('visibilitychange', function () { document.hidden ? stop() : play(); });
        if (fade) {
            swipe(track, function () { show(index - 1, true); }, function () { show(index + 1, true); });
        } else {
            var settle = null;
            track.addEventListener('scroll', function () {
                clearTimeout(settle);
                settle = setTimeout(function () {
                    var near = Math.round(Math.abs(track.scrollLeft) / track.clientWidth);
                    if (near !== index) show(near, true);
                }, 120);
            }, { passive: true });
        }
        show(0, false);
        play();
    });

    [].slice.call(document.querySelectorAll('.gallery.carousel')).forEach(function (row) {
        var strip = row.querySelector('.strip');
        [].slice.call(row.querySelectorAll('[hidden]')).forEach(function (element) { element.hidden = false; });
        var step = function (way) {
            var flip = root.dir === 'rtl' ? -1 : 1;
            strip.scrollBy({ left: way * flip * strip.clientWidth * 0.8, behavior: calm ? 'auto' : 'smooth' });
        };
        row.querySelector('.prev').addEventListener('click', function () { step(-1); });
        row.querySelector('.next').addEventListener('click', function () { step(1); });
    });

    var box = document.getElementById('lightbox');
    if (box && typeof box.showModal === 'function') {
        var picture = box.querySelector('img');
        var caption = box.querySelector('figcaption');
        var counter = box.querySelector('.counter');
        var list = [];
        var at = 0;
        var open = function (next) {
            at = (next + list.length) % list.length;
            var link = list[at];
            picture.src = link.getAttribute('href');
            picture.alt = link.getAttribute('data-caption') || '';
            caption.textContent = link.getAttribute('data-caption') || '';
            counter.textContent = number(at + 1) + ' / ' + number(list.length);
        };
        document.addEventListener('click', function (event) {
            var link = event.target.closest && event.target.closest('.gallery a[data-full]');
            if (!link) return;
            event.preventDefault();
            list = [].slice.call(link.closest('.gallery').querySelectorAll('a[data-full]'));
            open(list.indexOf(link));
            box.showModal();
        });
        box.querySelector('.close').addEventListener('click', function () { box.close(); });
        box.querySelector('.prev').addEventListener('click', function () { open(at - 1); });
        box.querySelector('.next').addEventListener('click', function () { open(at + 1); });
        box.addEventListener('click', function (event) { if (event.target === box) box.close(); });
        box.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') open(at - 1);
            if (event.key === 'ArrowRight') open(at + 1);
        });
        box.addEventListener('close', function () { picture.removeAttribute('src'); });
        swipe(picture, function () { open(at - 1); }, function () { open(at + 1); });
    }

    seen([].slice.call(document.querySelectorAll('[data-count]')), function (element) {
        var target = parseInt(element.getAttribute('data-count'), 10) || 0;
        if (calm || target === 0) return;
        var began = null;
        var tick = function (now) {
            if (began === null) began = now;
            var done = Math.min(1, (now - began) / 1400);
            element.textContent = number(Math.round(target * (1 - Math.pow(1 - done, 3))));
            if (done < 1) window.requestAnimationFrame(tick);
        };
        window.requestAnimationFrame(tick);
    });
})();
