*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{margin:0;font-family:Inter,Hind Siliguri,Noto Sans Bengali,Segoe UI,system-ui,-apple-system,Roboto,sans-serif;font-size:16.5px;line-height:1.7;color:#1d2433;background:#fff;--ps:color-mix(in oklab,var(--p) 9%,#fff);--pd:color-mix(in oklab,var(--p) 70%,#000)}
img{max-inline-size:100%;block-size:auto;display:block}
[hidden]{display:none!important}
a{color:var(--p)}
a:focus-visible,summary:focus-visible{outline:3px solid var(--a);outline-offset:3px;border-radius:6px}
.icon{flex:none;vertical-align:middle}
.wrap{max-inline-size:1180px;margin-inline:auto;padding-inline:20px}
.skip{position:absolute;inset-inline-start:-999px}.skip:focus{inset-inline-start:16px;inset-block-start:8px;background:#fff;padding:8px 12px;z-index:30}
.muted{color:#5f6b7d;font-size:14.5px}
.plain{list-style:none;padding:0;margin:0}
h1,h2,h3{line-height:1.25;margin:0 0 12px;letter-spacing:-.01em}

/* Top strip */
.topbar{background:var(--pd);color:#e8eef7;font-size:13.5px}
.topbar .wrap{display:flex;flex-wrap:wrap;align-items:center;gap:6px 22px;min-block-size:40px}
.topbar a{color:#fff;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.topbar .grow{flex:1}
.langs{display:flex;gap:4px}
.langs a{padding:2px 10px;border:1px solid rgb(255 255 255/.35);border-radius:999px;font-weight:600;font-size:12.5px}
.langs a[aria-current=true]{background:var(--a);border-color:var(--a);color:#1d2433}

/* Header */
header.top{position:sticky;inset-block-start:0;z-index:20;background:rgb(255 255 255/.94);backdrop-filter:saturate(1.4) blur(10px);border-block-end:1px solid #e9edf3}
.bar{display:flex;align-items:center;gap:16px;min-block-size:76px}
.brand{display:flex;align-items:center;gap:12px;text-decoration:none;color:inherit;min-inline-size:0;flex:1}
.brand img,.mono{inline-size:52px;block-size:52px;border-radius:14px;object-fit:contain;flex:none}
.mono{display:grid;place-items:center;font-weight:700;font-size:24px;color:#fff;background:linear-gradient(135deg,var(--p),var(--pd));box-shadow:0 6px 16px -6px var(--p)}
.brand b{display:block;font-size:20px;line-height:1.2}
.brand small{display:block;color:#5f6b7d;font-size:13.5px}
nav.menu ul{list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:4px}
nav.menu a{display:block;padding:9px 15px;border-radius:999px;text-decoration:none;color:#1d2433;font-weight:600;font-size:15.5px}
nav.menu a:hover{background:var(--ps);color:var(--p)}
nav.menu a[aria-current=page]{background:var(--p);color:#fff}
details.nav{display:none;position:relative}
@media (max-width:860px){nav.menu.wide{display:none}details.nav{display:block}details.nav summary{list-style:none;cursor:pointer;padding:9px 16px;border-radius:999px;background:var(--p);color:#fff;font-weight:600}details.nav summary::-webkit-details-marker{display:none}details.nav[open] ul{position:absolute;inset-inline-end:0;inset-block-start:calc(100% + 10px);min-inline-size:220px;background:#fff;border-radius:14px;box-shadow:0 18px 40px -12px rgb(16 24 40/.3);padding:8px;display:block}details.nav li a{display:block;padding:10px 14px;border-radius:10px;text-decoration:none;color:#1d2433;font-weight:600}details.nav li a:hover{background:var(--ps)}}

/* Sections: alternate bands, centred headings */
main{display:block}
.block{padding-block:64px}
.block:nth-of-type(even){background:var(--ps)}
.head{text-align:center;margin-block-end:34px}
.eyebrow{display:inline-block;font-size:13px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:var(--p);background:var(--ps);border-radius:999px;padding:4px 14px;margin-block-end:10px}
.block:nth-of-type(even) .eyebrow{background:#fff}
h2.title{font-size:clamp(26px,3.4vw,36px)}
.head .line{display:block;inline-size:56px;block-size:4px;border-radius:4px;background:var(--a);margin:12px auto 0}

/* Hero */
.block-hero{padding:0}
.hero{position:relative;overflow:hidden;color:#fff;background:linear-gradient(120deg,var(--pd),var(--p))}
.hero .bg{position:absolute;inset:0;inline-size:100%;block-size:100%;object-fit:cover;opacity:.32;mix-blend-mode:luminosity}
.hero .glow{position:absolute;inset:0;pointer-events:none;background:radial-gradient(80% 90% at 15% 20%,color-mix(in oklab,var(--a) 28%,transparent),transparent 60%)}
.hero .wrap{position:relative;z-index:1;display:grid;gap:36px;align-items:center;padding-block:84px 120px}
@media (min-width:900px){.hero.has-img .wrap{grid-template-columns:1.15fr 1fr}}
.hero h1{font-size:clamp(32px,5.6vw,58px);line-height:1.12;margin-block-end:16px;text-shadow:0 2px 18px rgb(0 0 0/.18)}
.hero p{font-size:clamp(17px,2vw,20px);max-inline-size:620px;opacity:.95;margin:0}
.hero .pic{border-radius:22px;border:6px solid rgb(255 255 255/.18);box-shadow:0 30px 60px -24px rgb(0 0 0/.55);transform:rotate(1.5deg);inline-size:100%;object-fit:cover;max-block-size:380px}
.actions{display:flex;flex-wrap:wrap;gap:12px;margin-block-start:26px}
.btn{display:inline-flex;align-items:center;gap:8px;padding:13px 24px;border-radius:999px;background:var(--a);color:#1d2433;font-weight:700;text-decoration:none;box-shadow:0 10px 24px -10px var(--a);transition:transform .15s}
.btn:hover{transform:translateY(-2px)}
.btn.ghost{background:rgb(255 255 255/.12);color:#fff;border:1.5px solid rgb(255 255 255/.5);box-shadow:none}
.wave{position:absolute;inset-inline:0;inset-block-end:-1px;z-index:1;display:block;inline-size:100%;block-size:70px;color:#fff}
.block-hero + .block:nth-of-type(even){padding-block-start:40px}

/* Cards */
.cards{display:grid;gap:18px;grid-template-columns:repeat(auto-fill,minmax(250px,1fr))}
.card{background:#fff;border:1px solid #e6eaf1;border-radius:20px;padding:22px;box-shadow:0 1px 2px rgb(16 24 40/.04);transition:transform .15s,box-shadow .15s}
.card:hover{transform:translateY(-3px);box-shadow:0 18px 36px -18px rgb(16 24 40/.25)}
.card h3{font-size:19px}
.tile{display:grid;place-items:center;inline-size:46px;block-size:46px;border-radius:14px;background:var(--ps);color:var(--p);margin-block-end:14px}
.prose{white-space:pre-line;max-inline-size:860px;margin-inline:auto;font-size:17.5px}
.block-text .prose{text-align:center}

/* Notices */
.board{max-inline-size:900px;margin-inline:auto;background:#fff;border-radius:22px;border:1px solid #e6eaf1;box-shadow:0 20px 44px -26px rgb(16 24 40/.35);overflow:hidden}
.board-head{display:flex;align-items:center;gap:10px;padding:16px 22px;background:linear-gradient(90deg,var(--p),var(--pd));color:#fff;font-weight:700}
.notice{display:flex;gap:16px;align-items:center;padding:16px 22px;border-block-end:1px solid #eef1f6}
.notice:last-child{border-block-end:0}
.notice.pinned{border-inline-start:5px solid #d92d20;background:#fff8f7}
.date{flex:none;display:grid;place-items:center;min-inline-size:62px;padding:6px 8px;border-radius:14px;background:var(--ps);color:var(--p);font-weight:700;line-height:1.15;text-align:center;white-space:nowrap}
.date b{display:block;font-size:22px}.date span{font-size:12.5px}
.notice a{color:#1d2433;text-decoration:none;font-weight:600;font-size:17px}.notice a:hover{color:var(--p)}
.pin{display:inline-block;font-size:12px;font-weight:700;color:#b42318;background:#fee4e2;border-radius:999px;padding:1px 10px;margin-inline-start:8px;vertical-align:middle}
.more{text-align:center;margin-block-start:22px}
.more a{display:inline-flex;align-items:center;gap:6px;font-weight:700;text-decoration:none}

/* Programs */
.programs .card{position:relative;overflow:hidden;border:0;color:#fff;background:linear-gradient(140deg,var(--p),var(--pd))}
.programs .card:nth-child(3n+2){background:linear-gradient(140deg,color-mix(in oklab,var(--p) 55%,#0ea5e9),var(--pd))}
.programs .card:nth-child(3n){background:linear-gradient(140deg,color-mix(in oklab,var(--a) 70%,#c2410c),color-mix(in oklab,var(--a) 40%,#7c2d12))}
.programs .bubble{position:absolute;inset-inline-end:-30px;inset-block-start:-30px;inline-size:120px;block-size:120px;border-radius:50%;background:rgb(255 255 255/.12)}
.programs .tile{background:rgb(255 255 255/.18);color:#fff}
.programs .muted{color:rgb(255 255 255/.85)}
.chips{display:flex;flex-wrap:wrap;gap:6px;margin-block-start:8px}
.chips span{font-size:13px;padding:2px 10px;border-radius:999px;background:rgb(255 255 255/.18)}

/* People, gallery, files */
.people .card{text-align:center}
.people img{inline-size:112px;block-size:112px;border-radius:50%;object-fit:cover;margin:0 auto 14px;border:4px solid var(--ps)}
.people .mono{margin:0 auto 14px;inline-size:112px;block-size:112px;border-radius:50%;font-size:40px}
.files{max-inline-size:760px;margin-inline:auto}
.files li a{display:flex;align-items:center;gap:12px;padding:14px 18px;margin-block-end:10px;border-radius:14px;background:#fff;border:1px solid #e6eaf1;text-decoration:none;color:#1d2433;font-weight:600}
.files li a:hover{border-color:var(--p);color:var(--p)}

/* Attendance */
.counts{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));margin-block-end:22px}
.count{display:flex;align-items:center;gap:14px;border-radius:20px;padding:18px;background:#fff;border:1px solid #e6eaf1}
.count .tile{margin:0}
.count b{display:block;font-size:32px;line-height:1}
.count.present .tile{background:#dcfae6;color:#067647}.count.late .tile{background:#fef0c7;color:#b54708}
.count.absent .tile{background:#fee4e2;color:#b42318}.count.leave .tile{background:#e0eaff;color:#3538cd}
.who{font-size:18px;margin-block:6px 12px}
.note{text-align:center}

/* Admission, contact */
.admit{max-inline-size:860px;margin-inline:auto;text-align:center;border-radius:24px;padding:36px 26px;background:linear-gradient(135deg,var(--ps),#fff);border:1px dashed color-mix(in oklab,var(--p) 40%,#fff)}
.deadline{display:inline-flex;align-items:center;gap:8px;margin-block-start:12px;padding:6px 16px;border-radius:999px;background:#fff;font-weight:700;color:var(--p)}
.contact .card{display:flex;gap:14px;align-items:flex-start}
.contact .tile{margin:0}

/* Footer */
footer.foot{background:#0f1729;color:#c7cfdd;padding-block:56px 26px;font-size:15px}
footer.foot a{color:#fff;text-decoration:none}
footer.foot .cols{display:grid;gap:30px;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}
footer.foot h3{color:#fff;font-size:17px}
footer.foot li{margin-block-end:8px}
footer.foot .line{display:flex;gap:10px;align-items:flex-start;margin-block-end:10px}
.powered{margin-block-start:34px;padding-block-start:18px;border-block-start:1px solid rgb(255 255 255/.12);color:#8b97ab;font-size:13px;text-align:center}

/* Template minimal: quiet and white, colour only for links and buttons. */
.tpl-minimal .topbar{background:#f4f6f9;color:#3b4555}.tpl-minimal .topbar a{color:#1d2433}.tpl-minimal .langs a{border-color:#cfd6e0}
.tpl-minimal .hero{background:#f4f6f9;color:#1d2433}.tpl-minimal .hero .glow,.tpl-minimal .hero .bg,.tpl-minimal .bubble{display:none}
.tpl-minimal .hero h1{text-shadow:none}.tpl-minimal .hero .pic{border-color:#fff;transform:none}
.tpl-minimal .btn.ghost{color:var(--p);border-color:var(--p)}
.tpl-minimal .wave{display:none}
.tpl-minimal .block:nth-of-type(even){background:#fafbfc}
.tpl-minimal .programs .card{background:#fff;color:#1d2433;border:1px solid #e6eaf1}
.tpl-minimal .programs .tile{background:var(--ps);color:var(--p)}.tpl-minimal .programs .muted{color:#5f6b7d}
.tpl-minimal .chips span{background:var(--ps)}
.tpl-minimal .board-head{background:#fff;color:#1d2433;border-block-end:1px solid #eef1f6}
.tpl-minimal footer.foot{background:#f4f6f9;color:#3b4555}.tpl-minimal footer.foot a,.tpl-minimal footer.foot h3{color:#1d2433}
.notice-body{padding:26px}
.notice-body h1{font-size:clamp(24px,3vw,32px)}
.wave{color:var(--ps)}
.tpl-minimal .wave{color:#fafbfc}
.cards{grid-template-columns:repeat(auto-fit,minmax(240px,300px));justify-content:center}
.block-attendance .who,.block-attendance .muted{text-align:center}
.block-attendance .counts{max-inline-size:900px;margin-inline:auto}
.lines{white-space:pre-line}
.cards.contact{grid-template-columns:repeat(auto-fit,minmax(220px,1fr))}

/* Header: glass that deepens once the page scrolls (the script marks it) */
header.top{transition:box-shadow .3s,background-color .3s}
header.top.scrolled{background:rgb(255 255 255/.8);box-shadow:0 12px 30px -20px rgb(16 24 40/.45)}

/* Sections rise into view (only with the script, and only for people who like motion) */
@media (prefers-reduced-motion:no-preference){.js .reveal{opacity:0;transform:translateY(28px);transition:opacity .8s ease,transform .8s ease}.js .reveal.in{opacity:1;transform:none}}

/* Slider: swipe without the script; fades or slides by itself with it */
.block-slider{padding:0}
.slider{position:relative;overflow:hidden;background:var(--pd);color:#fff;outline:0}
.slider .track{position:relative;display:flex;overflow-x:auto;scroll-snap-type:x mandatory;scrollbar-width:none}
.slider .track::-webkit-scrollbar{display:none}
.slide{position:relative;flex:0 0 100%;margin:0;scroll-snap-align:start;block-size:clamp(400px,64vh,660px);overflow:hidden}
.h-full .slide{block-size:calc(100svh - 116px);min-block-size:440px}
.slide img{position:absolute;inset:0;inline-size:100%;block-size:100%;object-fit:cover}
.slide .shade{position:absolute;inset:0;background:linear-gradient(90deg,rgb(8 15 30/.74),rgb(8 15 30/.28) 62%,rgb(8 15 30/.08)),linear-gradient(0deg,rgb(8 15 30/.55),transparent 45%)}
.slide figcaption{position:relative;z-index:1;display:flex;align-items:center;block-size:100%}
.cap{max-inline-size:660px;padding-block:48px 70px}
.cap h1,.cap h2{font-size:clamp(30px,5.2vw,58px);line-height:1.1;margin-block-end:14px;text-shadow:0 2px 24px rgb(0 0 0/.35)}
.cap p{font-size:clamp(16px,2vw,20px);opacity:.92;margin:0 0 24px}
.live.fx-fade .track{display:grid;overflow:hidden}
.live.fx-fade .slide{grid-area:1/1;opacity:0;visibility:hidden;transition:opacity 1s ease,visibility 0s linear 1s}
.live.fx-fade .slide.on{opacity:1;visibility:visible;transition:opacity 1s ease}
@media (prefers-reduced-motion:no-preference){.live .slide img{transition:transform 8s ease-out}.live .slide.on img{transform:scale(1.09)}.live .cap{opacity:0;transform:translateY(22px);transition:opacity .8s ease .3s,transform .8s ease .3s}.live .slide.on .cap{opacity:1;transform:none}}
.slider .prev,.slider .next{position:absolute;inset-block-start:50%;z-index:2;display:grid;place-items:center;inline-size:50px;block-size:50px;margin-block-start:-25px;border-radius:50%;border:1px solid rgb(255 255 255/.4);background:rgb(255 255 255/.14);backdrop-filter:blur(8px);color:#fff;cursor:pointer;transition:background-color .2s,transform .2s}
.slider .prev:hover,.slider .next:hover{background:rgb(255 255 255/.3);transform:scale(1.06)}
.slider .prev{inset-inline-start:20px}.slider .next{inset-inline-end:20px}
.dots{position:absolute;inset-block-end:24px;inset-inline:0;z-index:2;display:flex;justify-content:center;gap:8px}
.dots button{inline-size:10px;block-size:10px;padding:0;border:0;border-radius:999px;background:rgb(255 255 255/.55);cursor:pointer;transition:inline-size .35s,background-color .35s}
.dots button[aria-current=true]{inline-size:32px;background:var(--a)}
.slider:focus-visible{box-shadow:inset 0 0 0 3px var(--a)}
@media (max-width:640px){.slider .prev,.slider .next{display:none}.cap{padding-block:36px 64px}}

/* Gallery: a grid with a large first picture, masonry, or a strip; captions; opens large */
.gallery{position:relative}
.gallery .strip{display:grid;gap:14px;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}
.gallery a{position:relative;display:block;block-size:100%;border-radius:18px;overflow:hidden;background:#e9edf3;color:#fff;isolation:isolate}
.gallery img{inline-size:100%;block-size:100%;aspect-ratio:4/3;object-fit:cover;transition:transform .6s ease,filter .6s ease}
.gallery a:hover img,.gallery a:focus-visible img{transform:scale(1.07);filter:saturate(1.15)}
.gallery .zoom{position:absolute;inset-block-start:12px;inset-inline-end:12px;display:grid;place-items:center;inline-size:40px;block-size:40px;border-radius:50%;background:rgb(255 255 255/.92);color:var(--p);opacity:0;transform:scale(.8);transition:opacity .25s,transform .25s}
.gallery a:hover .zoom,.gallery a:focus-visible .zoom{opacity:1;transform:none}
.gallery .caption{position:absolute;inset-inline:0;inset-block-end:0;padding:34px 16px 12px;font-size:14.5px;font-weight:600;line-height:1.4;background:linear-gradient(0deg,rgb(8 15 30/.8),transparent)}
@media (min-width:720px){.gallery.grid li:first-child{grid-column:span 2;grid-row:span 2}}
@media (max-width:719px){.gallery.grid .strip{grid-template-columns:1fr 1fr;gap:10px}.gallery.grid li:first-child{grid-column:span 2}.gallery.masonry .strip{columns:2;column-gap:10px}.gallery .caption{padding:24px 10px 8px;font-size:13px}}
.gallery.masonry .strip{display:block;columns:3 240px;column-gap:14px}
.gallery.masonry li{break-inside:avoid;margin-block-end:14px}
.gallery.masonry img{aspect-ratio:auto}
.gallery.carousel .strip{display:flex;gap:14px;overflow-x:auto;scroll-snap-type:x mandatory;padding-block-end:8px;scrollbar-width:thin}
.gallery.carousel li{flex:0 0 min(80%,340px);scroll-snap-align:start}
.gallery.carousel .prev,.gallery.carousel .next{position:absolute;inset-block-start:50%;z-index:2;display:grid;place-items:center;inline-size:46px;block-size:46px;margin-block-start:-27px;border-radius:50%;border:0;background:#fff;color:var(--p);box-shadow:0 10px 26px -10px rgb(16 24 40/.45);cursor:pointer}
.gallery.carousel .prev{inset-inline-start:-10px}.gallery.carousel .next{inset-inline-end:-10px}

/* The picture viewer */
.lightbox{inline-size:100vw;max-inline-size:100vw;block-size:100vh;max-block-size:100vh;margin:0;padding:0;border:0;background:rgb(6 10 20/.95);color:#fff}
.lightbox[open]{display:grid;place-items:center}
.lightbox::backdrop{background:rgb(6 10 20/.7)}
.lightbox figure{display:grid;justify-items:center;gap:14px;margin:0;padding:64px 80px}
.lightbox img{max-inline-size:min(1400px,100%);max-block-size:calc(100vh - 170px);border-radius:12px;box-shadow:0 30px 90px rgb(0 0 0/.55);touch-action:pan-y}
.lightbox figcaption{max-inline-size:760px;font-size:16px;text-align:center;opacity:.9}
.lightbox button{position:absolute;display:grid;place-items:center;inline-size:50px;block-size:50px;border-radius:50%;border:1px solid rgb(255 255 255/.25);background:rgb(255 255 255/.08);color:#fff;cursor:pointer}
.lightbox button:hover{background:rgb(255 255 255/.2)}
.lightbox .close{inset-block-start:16px;inset-inline-end:16px}
.lightbox .prev{inset-inline-start:16px;inset-block-start:50%;margin-block-start:-25px}
.lightbox .next{inset-inline-end:16px;inset-block-start:50%;margin-block-start:-25px}
.lightbox .counter{position:absolute;inset-block-start:28px;inset-inline-start:24px;font-size:14px;opacity:.8}
@media (max-width:640px){.lightbox figure{padding:64px 10px 90px}.lightbox .prev,.lightbox .next{inset-block-start:auto;inset-block-end:20px;margin:0}}

/* Numbers: cards that count up */
.stats{display:grid;gap:18px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));max-inline-size:1040px;margin-inline:auto}
.stat{text-align:center;padding:30px 18px;border-radius:22px;background:#fff;border:1px solid #e6eaf1;border-block-start:4px solid var(--a);box-shadow:0 20px 44px -30px rgb(16 24 40/.4)}
.stat .figure{display:block;font-size:clamp(36px,4.8vw,52px);font-weight:800;line-height:1;letter-spacing:-.02em;color:var(--p)}
.stat .suffix{color:var(--a);margin-inline-start:2px}
.stat .label{display:block;margin-block-start:12px;color:#5f6b7d;font-weight:600}
.tpl-school .block-stats,.tpl-school .block-stats:nth-of-type(even){background:linear-gradient(120deg,var(--pd),var(--p));color:#fff}
.tpl-school .block-stats .title{color:#fff}.tpl-school .block-stats .eyebrow{background:rgb(255 255 255/.14);color:#fff}
.tpl-school .block-stats .stat{background:rgb(255 255 255/.08);border-color:rgb(255 255 255/.16);border-block-start-color:var(--a);box-shadow:none;backdrop-filter:blur(4px)}
.tpl-school .block-stats .figure{color:#fff}.tpl-school .block-stats .label{color:rgb(255 255 255/.8)}

/* A notice of the last days */
.new{display:inline-block;font-size:12px;font-weight:700;color:#065f46;background:#d1fae5;border-radius:999px;padding:1px 10px;margin-inline-start:8px;vertical-align:middle}

/* Template premium: deep ink, the accent as gold, serif headings, quiet lines */
.tpl-premium{background:#fbf9f4;color:#1b2333;--ps:color-mix(in oklab,var(--a) 9%,#fbf9f4);--ink:color-mix(in oklab,var(--p) 42%,#070d1a);--gold:color-mix(in oklab,var(--a) 82%,#6b4e00)}
.tpl-premium h1,.tpl-premium h2,.tpl-premium h3,.tpl-premium .brand b,.tpl-premium .stat .figure,.tpl-premium footer.foot h3{font-family:Georgia,Cambria,Times New Roman,Hind Siliguri,serif;letter-spacing:0}
.tpl-premium .topbar{background:var(--ink);color:#d9d3c3}
.tpl-premium .langs a{border-color:rgb(255 255 255/.25)}.tpl-premium .langs a[aria-current=true]{background:var(--a);border-color:var(--a);color:var(--ink)}
.tpl-premium header.top{background:rgb(251 249 244/.92);border-block-end:1px solid color-mix(in oklab,var(--a) 28%,#fff)}
.tpl-premium header.top.scrolled{background:rgb(251 249 244/.8)}
.tpl-premium .mono{background:var(--ink);color:var(--a);border:1px solid var(--a);box-shadow:none;font-family:Georgia,serif}
.tpl-premium nav.menu a{border-radius:0;color:#1b2333}
.tpl-premium nav.menu a:hover{background:transparent;color:var(--gold)}
.tpl-premium nav.menu a[aria-current=page]{background:transparent;color:var(--ink);box-shadow:inset 0 -2px 0 var(--a)}
.tpl-premium details.nav summary{background:var(--ink);border-radius:6px}
.tpl-premium .hero{background:linear-gradient(120deg,var(--ink),color-mix(in oklab,var(--p) 65%,var(--ink)))}
.tpl-premium .hero .glow{background:radial-gradient(70% 80% at 88% 8%,color-mix(in oklab,var(--a) 24%,transparent),transparent 60%)}
.tpl-premium .hero .pic{border-radius:8px;border:1px solid color-mix(in oklab,var(--a) 60%,transparent);transform:none}
.tpl-premium .btn{border-radius:6px;background:var(--a);color:var(--ink);letter-spacing:.02em}
.tpl-premium .btn.ghost{background:transparent;color:#fff;border-color:var(--a)}
.tpl-premium .eyebrow{background:none;padding:0;color:var(--gold);letter-spacing:.24em;font-size:12.5px}
.tpl-premium .block:nth-of-type(even){background:#f3eee2}
.tpl-premium .block:nth-of-type(even) .eyebrow{background:none}
.tpl-premium .head .line{inline-size:96px;block-size:1px;background:linear-gradient(90deg,transparent,var(--a),transparent)}
.tpl-premium .wave{color:#f3eee2}
.tpl-premium .card,.tpl-premium .stat,.tpl-premium .board,.tpl-premium .count,.tpl-premium .files li a{border-radius:10px;border-color:#ebe3d0;box-shadow:0 26px 50px -38px rgb(30 24 10/.5)}
.tpl-premium .card{border-block-start:3px solid var(--a)}
.tpl-premium .tile{background:color-mix(in oklab,var(--a) 14%,#fff);color:var(--gold);border-radius:8px}
.tpl-premium .programs .card,.tpl-premium .programs .card:nth-child(3n+2),.tpl-premium .programs .card:nth-child(3n){background:linear-gradient(160deg,var(--ink),color-mix(in oklab,var(--p) 55%,var(--ink)))}
.tpl-premium .programs .tile{background:rgb(255 255 255/.08);color:var(--a)}
.tpl-premium .board-head{background:var(--ink);color:var(--a);letter-spacing:.04em}
.tpl-premium .date{background:var(--ink);color:var(--a);border-radius:6px}
.tpl-premium .stat .figure{color:var(--ink)}
.tpl-premium .block-stats,.tpl-premium .block-stats:nth-of-type(even){background:var(--ink);color:#fff}
.tpl-premium .block-stats .title{color:#fff}
.tpl-premium .block-stats .stat{background:transparent;border:1px solid rgb(255 255 255/.14);border-block-start:2px solid var(--a);box-shadow:none}
.tpl-premium .block-stats .figure{color:var(--a)}.tpl-premium .block-stats .label{color:#d9d3c3}
.tpl-premium .gallery a{border-radius:8px}
.tpl-premium .admit{border-radius:10px;background:#fff;border:1px solid #ebe3d0}
.tpl-premium .slide .shade{background:linear-gradient(90deg,rgb(7 13 26/.82),rgb(7 13 26/.3) 62%,rgb(7 13 26/.05)),linear-gradient(0deg,rgb(7 13 26/.5),transparent 45%)}
.tpl-premium footer.foot{background:var(--ink);color:#c9c2b0}
.tpl-premium footer.foot h3{color:var(--a)}
.tpl-minimal .block-stats .stat{border-block-start-width:1px}
/* Bangla letters join: no spacing between them */
:lang(bn) .eyebrow,:lang(bn) .tpl-premium .eyebrow,:lang(bn) .tpl-premium .board-head{letter-spacing:0;text-transform:none}
@media (max-width:640px){.stats{grid-template-columns:1fr 1fr;gap:12px}.stat{padding:22px 12px}.stat .figure{font-size:34px}}

/* Admission enquiry form */
.enquiry{max-inline-size:860px;margin-inline:auto}
.enquiry-text{text-align:center;margin-block-end:22px}
.enquiry-form{background:#fff;border:1px solid #e6eaf1;border-radius:22px;padding:26px;box-shadow:0 22px 46px -30px rgb(16 24 40/.4)}
.enquiry .fields{display:grid;gap:16px;grid-template-columns:repeat(auto-fit,minmax(240px,1fr))}
.enquiry .field{display:grid;gap:6px;align-content:start;font-weight:600;font-size:14.5px}
.enquiry .field.wide{grid-column:1/-1}
.enquiry .field em{font-style:normal;font-weight:400;color:#5f6b7d}
.enquiry input,.enquiry select,.enquiry textarea{font:inherit;font-weight:400;inline-size:100%;padding:11px 14px;border:1.5px solid #d5dbe5;border-radius:12px;background:#fbfcfe;color:#1d2433}
.enquiry input:focus,.enquiry select:focus,.enquiry textarea:focus{outline:0;border-color:var(--p);box-shadow:0 0 0 4px color-mix(in oklab,var(--p) 18%,transparent);background:#fff}
.enquiry [aria-invalid=true]{border-color:#d92d20}
.enquiry .err{color:#b42318;font-weight:500;font-size:13px}
.enquiry-alert{margin:0 0 16px;padding:12px 16px;border-radius:12px;background:#fee4e2;color:#912018;font-weight:600}
.enquiry-actions{display:flex;flex-wrap:wrap;align-items:center;gap:14px;margin-block-start:20px}
.enquiry-actions .btn{border:0;cursor:pointer;font:inherit;font-weight:700}
.enquiry-actions .btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
.enquiry-done{text-align:center;padding:36px 24px;border-radius:22px;background:#fff;border:1px solid #e6eaf1}
.enquiry-done .tile{margin-inline:auto;background:#dcfae6;color:#067647}
.hp{position:absolute;inset-inline-start:-10000px;inline-size:1px;block-size:1px;overflow:hidden}
.tpl-premium .enquiry-form,.tpl-premium .enquiry-done{border-radius:10px;border-color:#ebe3d0;border-block-start:3px solid var(--a)}
.tpl-premium .enquiry input,.tpl-premium .enquiry select,.tpl-premium .enquiry textarea{border-radius:6px}

/* The own line of the school above every page */
.motto{padding:6px 16px;text-align:center;font-size:15px;line-height:1.6;background:var(--pd);color:#fff;border-block-end:1px solid rgb(255 255 255/.12)}

/* Message from the head */
.message{display:grid;gap:28px;align-items:center;max-inline-size:960px;margin:0 auto;padding:30px;border-radius:24px;background:#fff;border:1px solid #e6eaf1;box-shadow:0 22px 46px -32px rgb(16 24 40/.4)}
@media (min-width:720px){.message{grid-template-columns:220px 1fr}}
.message .portrait{inline-size:220px;block-size:260px;object-fit:cover;border-radius:18px;margin-inline:auto;box-shadow:0 18px 36px -20px rgb(16 24 40/.5)}
.message blockquote{margin:0;white-space:pre-line;font-size:17.5px;line-height:1.85;position:relative;padding-inline-start:22px;border-inline-start:4px solid var(--a)}
.message figcaption{margin-block-start:18px;display:grid;gap:2px}
.message figcaption b{font-size:18px}.message figcaption span{color:#5f6b7d}

/* Academic calendar */
.calendar{display:grid;gap:22px;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));max-inline-size:1100px;margin-inline:auto}
.month{background:#fff;border:1px solid #e6eaf1;border-radius:20px;padding:18px 20px;box-shadow:0 14px 30px -26px rgb(16 24 40/.4)}
.month h3{font-size:17px;color:var(--p);padding-block-end:10px;border-block-end:2px solid var(--ps)}
.event{display:flex;gap:14px;align-items:flex-start;padding-block:10px;border-block-end:1px dashed #e6eaf1}
.event:last-child{border-block-end:0}
.event time{flex:none;display:grid;place-items:center;min-inline-size:52px;padding:4px 6px;border-radius:12px;background:var(--ps);color:var(--p);font-weight:700;line-height:1.15;text-align:center}
.event time b{font-size:19px}.event time span{font-size:12px}
.event p{margin:2px 0 0;font-weight:600}
.event.past{opacity:.55}

/* Template campus: a college or university; deep blue, straight lines, uppercase labels */
.tpl-campus{--ink:color-mix(in oklab,var(--p) 55%,#06122b)}
.tpl-campus .topbar{background:var(--ink)}
.tpl-campus header.top{border-block-end:3px solid var(--p)}
.tpl-campus .mono{border-radius:6px;background:var(--ink);box-shadow:none}
.tpl-campus nav.menu a{border-radius:0;font-size:14.5px;letter-spacing:.04em;text-transform:uppercase}
.tpl-campus nav.menu a:hover{background:transparent;color:var(--p);box-shadow:inset 0 -2px 0 var(--p)}
.tpl-campus nav.menu a[aria-current=page]{background:var(--ink);color:#fff}
.tpl-campus .hero{background:repeating-linear-gradient(135deg,rgb(255 255 255/.035) 0 2px,transparent 2px 14px),linear-gradient(120deg,var(--ink),var(--p))}
.tpl-campus .hero .pic{border-radius:6px;transform:none;border:4px solid #fff}
.tpl-campus .btn{border-radius:4px;text-transform:uppercase;letter-spacing:.05em;font-size:14.5px}
.tpl-campus .eyebrow{border-radius:2px;background:var(--ink);color:#fff;letter-spacing:.14em}
.tpl-campus .block:nth-of-type(even) .eyebrow{background:var(--ink)}
.tpl-campus .head .line{inline-size:100%;max-inline-size:120px;block-size:3px;background:var(--p)}
.tpl-campus .block:nth-of-type(even){background:#f2f5fa}
.tpl-campus .card,.tpl-campus .month,.tpl-campus .stat,.tpl-campus .message,.tpl-campus .enquiry-form{border-radius:4px;box-shadow:none;border-color:#dfe5ee}
.tpl-campus .card{border-inline-start:4px solid var(--p)}
.tpl-campus .programs .card,.tpl-campus .programs .card:nth-child(3n+2),.tpl-campus .programs .card:nth-child(3n){background:var(--ink);border-inline-start-color:var(--a)}
.tpl-campus .tile{border-radius:4px}
.tpl-campus .board{border-radius:0;box-shadow:none;border:0;border-block-start:4px solid var(--ink);background:transparent}
.tpl-campus .board-head{background:transparent;color:var(--ink);border-block-end:1px solid #dfe5ee;letter-spacing:.08em;text-transform:uppercase}
.tpl-campus .notice{background:transparent;border-block-end:1px solid #dfe5ee}
.tpl-campus .date{border-radius:2px;background:var(--ink);color:#fff}
.tpl-campus .gallery a,.tpl-campus .message .portrait{border-radius:4px}
.tpl-campus .block-stats,.tpl-campus .block-stats:nth-of-type(even){background:var(--ink);color:#fff}
.tpl-campus .block-stats .title{color:#fff}
.tpl-campus .block-stats .stat{background:transparent;border:0;border-inline-start:3px solid var(--a);text-align:start}
.tpl-campus .block-stats .figure{color:#fff}.tpl-campus .block-stats .label{color:rgb(255 255 255/.75)}
.tpl-campus footer.foot{background:var(--ink)}
.tpl-campus .wave{display:none}
.tpl-campus .motto{background:var(--ink)}

/* Template madrasa: deep green and gold, a geometric band, arched cards */
.tpl-madrasa{--ink:color-mix(in oklab,var(--p) 50%,#022b1c);--gold:color-mix(in oklab,var(--a) 85%,#5c4300);background:#fbfaf5}
.tpl-madrasa .topbar,.tpl-madrasa .motto{background:repeating-conic-gradient(from 45deg,rgb(255 255 255/.06) 0 25%,transparent 0 50%) 0 0/16px 16px,var(--ink)}
.tpl-madrasa .motto{font-size:18px;color:var(--a);border-block-end:1px solid color-mix(in oklab,var(--a) 50%,transparent)}
.tpl-madrasa header.top{background:rgb(251 250 245/.94);border-block-end:2px solid var(--a)}
.tpl-madrasa .mono{border-radius:50% 50% 12px 12px;background:var(--ink);color:var(--a);border:1px solid var(--a);box-shadow:none}
.tpl-madrasa nav.menu a[aria-current=page]{background:var(--ink);color:var(--a)}
.tpl-madrasa nav.menu a:hover{background:color-mix(in oklab,var(--a) 14%,#fff);color:var(--ink)}
.tpl-madrasa .hero{background:repeating-conic-gradient(from 45deg,rgb(255 255 255/.045) 0 25%,transparent 0 50%) 0 0/28px 28px,radial-gradient(70% 90% at 50% 0,color-mix(in oklab,var(--p) 80%,#fff),transparent 70%),var(--ink)}
.tpl-madrasa .hero .glow{background:radial-gradient(60% 70% at 85% 15%,color-mix(in oklab,var(--a) 25%,transparent),transparent 60%)}
.tpl-madrasa .hero .pic{border-radius:120px 120px 16px 16px;transform:none;border:3px solid var(--a)}
.tpl-madrasa .btn{background:var(--a);color:var(--ink)}
.tpl-madrasa .btn.ghost{background:transparent;color:#fff;border-color:var(--a)}
.tpl-madrasa .eyebrow{background:none;color:var(--gold);padding:0}
.tpl-madrasa .block:nth-of-type(even){background:#f1f5ee}
.tpl-madrasa .block:nth-of-type(even) .eyebrow{background:none}
.tpl-madrasa .head .line{inline-size:170px;block-size:12px;background:radial-gradient(circle,var(--a) 0 2.5px,transparent 3px) center/14px 12px repeat-x,linear-gradient(var(--a),var(--a)) center/100% 1px no-repeat}
.tpl-madrasa .card,.tpl-madrasa .month,.tpl-madrasa .message,.tpl-madrasa .enquiry-form,.tpl-madrasa .stat{border-radius:40px 40px 14px 14px;border-color:color-mix(in oklab,var(--a) 35%,#fff)}
.tpl-madrasa .card{border-block-start:3px solid var(--a)}
.tpl-madrasa .tile{border-radius:50% 50% 8px 8px;background:color-mix(in oklab,var(--a) 16%,#fff);color:var(--ink)}
.tpl-madrasa .programs .card,.tpl-madrasa .programs .card:nth-child(3n+2),.tpl-madrasa .programs .card:nth-child(3n){background:repeating-conic-gradient(from 45deg,rgb(255 255 255/.05) 0 25%,transparent 0 50%) 0 0/20px 20px,var(--ink)}
.tpl-madrasa .programs .tile{background:rgb(255 255 255/.1);color:var(--a)}
.tpl-madrasa .board{border-radius:30px 30px 14px 14px;border-color:color-mix(in oklab,var(--a) 35%,#fff)}
.tpl-madrasa .board-head{background:var(--ink);color:var(--a)}
.tpl-madrasa .date,.tpl-madrasa .event time{background:var(--ink);color:var(--a);border-radius:50% 50% 8px 8px}
.tpl-madrasa .message .portrait{border-radius:110px 110px 16px 16px;border:3px solid var(--a)}
.tpl-madrasa .message blockquote{border-inline-start-color:var(--a)}
.tpl-madrasa .gallery a{border-radius:60px 60px 12px 12px}
.tpl-madrasa .block-stats,.tpl-madrasa .block-stats:nth-of-type(even){background:repeating-conic-gradient(from 45deg,rgb(255 255 255/.05) 0 25%,transparent 0 50%) 0 0/20px 20px,var(--ink);color:#fff}
.tpl-madrasa .block-stats .title{color:#fff}
.tpl-madrasa .block-stats .stat{background:transparent;border:1px solid color-mix(in oklab,var(--a) 45%,transparent)}
.tpl-madrasa .block-stats .figure{color:var(--a)}.tpl-madrasa .block-stats .label{color:rgb(255 255 255/.8)}
.tpl-madrasa .wave{color:#f1f5ee}
.tpl-madrasa footer.foot{background:repeating-conic-gradient(from 45deg,rgb(255 255 255/.04) 0 25%,transparent 0 50%) 0 0/16px 16px,var(--ink)}
.tpl-madrasa footer.foot h3{color:var(--a)}
:lang(bn) .tpl-campus nav.menu a,:lang(bn) .tpl-campus .btn,:lang(bn) .tpl-campus .eyebrow,:lang(bn) .tpl-campus .board-head{letter-spacing:0}
