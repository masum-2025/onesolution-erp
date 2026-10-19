*,*::before,*::after{box-sizing:border-box}
html{-webkit-text-size-adjust:100%;scroll-behavior:smooth}
body{margin:0;font-family:Hind Siliguri,Noto Sans Bengali,Segoe UI,system-ui,-apple-system,Roboto,sans-serif;font-size:16.5px;line-height:1.7;color:#1d2433;background:#fff;--ps:color-mix(in oklab,var(--p) 9%,#fff);--pd:color-mix(in oklab,var(--p) 70%,#000)}
img{max-inline-size:100%;block-size:auto;display:block}
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
.gallery{display:grid;gap:12px;grid-template-columns:repeat(auto-fill,minmax(220px,1fr))}
.gallery a{display:block;border-radius:16px;overflow:hidden}
.gallery img{inline-size:100%;aspect-ratio:4/3;object-fit:cover;transition:transform .3s}
.gallery a:hover img{transform:scale(1.05)}
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
