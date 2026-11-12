// Gera como-estudar/capas/<slug>.png (1200x630) para imagem destacada / og:image dos guias.
// Uso: node ferramentas/capas-como-estudar.js  (precisa do Playwright com Chromium)
const fs = require('fs'), path = require('path');
const { chromium } = require('playwright');
const dir = path.join(__dirname, '..', 'como-estudar');
const cats = JSON.parse(fs.readFileSync(path.join(dir, '_categorias.json'), 'utf8'));
const arte = {
  'provas-e-bancas': '<rect x="38" y="14" width="124" height="132" rx="10"/><path d="M58 40h40M58 66h40M58 92h40M58 118h40"/><circle cx="118" cy="40" r="8"/><circle cx="144" cy="40" r="8" class="c"/><circle cx="118" cy="66" r="8" class="c"/><circle cx="144" cy="66" r="8"/><circle cx="118" cy="92" r="8" class="c"/><circle cx="144" cy="92" r="8"/><circle cx="118" cy="118" r="8"/><circle cx="144" cy="118" r="8" class="c"/>',
  'lei-seca-e-jurisprudencia': '<path d="M100 30c-18-10-44-12-66-6v108c22-6 48-4 66 6 18-10 44-12 66-6V24c-22-6-48-4-66 6z"/><path d="M100 30v108"/><path d="M48 50h36M48 66h36M48 82h28M116 50h36M116 66h36M116 82h28" class="f"/><path d="M116 100h36" class="c2"/>',
  'memoria-e-organizacao': '<rect x="26" y="52" width="92" height="64" rx="8" transform="rotate(-8 72 84)"/><rect x="46" y="44" width="92" height="64" rx="8" transform="rotate(4 92 76)"/><rect x="66" y="38" width="92" height="64" rx="8" class="b"/><path d="M80 86l14 8 22-26" class="c2"/><path d="M30 146c30-4 60-22 80-46s40-36 64-40" class="f"/>',
};
const fundo = { 'provas-e-bancas': '#2c5748', 'lei-seca-e-jurisprudencia': '#2b4d58', 'memoria-e-organizacao': '#1f4135' };
const fonte = fs.readFileSync(path.join(__dirname, 'fontes', 'manrope.woff2')).toString('base64');
const esc = s => s.replace(/&/g, '&amp;').replace(/</g, '&lt;');
(async () => {
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 1200, height: 630 } });
  for (const f of fs.readdirSync(dir).filter(f => /^\d+-.*\.html$/.test(f))) {
    const h = fs.readFileSync(path.join(dir, f), 'utf8').match(/<!--([\s\S]*?)-->/)[1];
    const m = Object.fromEntries(h.trim().split('\n').map(l => [l.slice(0, l.indexOf(':')).trim(), l.slice(l.indexOf(':') + 1).trim()]));
    const cat = cats.find(c => c.nome === m.categoria) || cats[0];
    const html = `<!doctype html><meta charset="utf-8"><style>
@font-face{font-family:M;src:url(data:font/woff2;base64,${fonte}) format('woff2');font-weight:200 800}
*{box-sizing:border-box;margin:0}body{width:1200px;height:630px;font-family:M,system-ui,sans-serif;background:${fundo[cat.slug]};color:#fff;position:relative;overflow:hidden}
body:before{content:"";position:absolute;inset:0;background:radial-gradient(circle at 90% 6%,rgba(242,227,191,.22),rgba(242,227,191,0) 40%),radial-gradient(circle at 0 100%,rgba(0,0,0,.2),rgba(0,0,0,0) 50%)}
.t{position:absolute;left:80px;top:72px;width:760px;bottom:72px;display:flex;flex-direction:column}
.o{font-size:22px;font-weight:800;letter-spacing:.1em;text-transform:uppercase;color:#f2e3bf}
h1{margin-top:28px;font-size:${m['title (Rank Math)'].length > 60 ? 54 : 62}px;line-height:1.1;font-weight:700;letter-spacing:-.02em;text-wrap:balance}
.r{margin-top:auto;display:flex;align-items:center;gap:16px}
.k{display:flex;align-items:center;gap:6px;height:50px;transform:rotate(-14deg)}.k i{display:block;width:9px;height:38px;border-radius:6px;background:#7fa58f}.k i:nth-child(2){height:50px;background:#b3bf9c}.k i:nth-child(3){height:28px;background:#e3cfa0}
.n{display:flex;flex-direction:column;line-height:1.05}.n span{font-size:20px;font-weight:500}.n strong{font-size:31px;font-weight:800;letter-spacing:-.6px}.n b{color:#c6b388}
svg{position:absolute;right:70px;top:50%;transform:translateY(-50%);width:270px;fill:none;stroke:rgba(242,227,191,.75);stroke-width:4;stroke-linecap:round;stroke-linejoin:round}
.c{fill:rgba(242,227,191,.9)}.b{fill:rgba(242,227,191,.14)}.f{stroke:rgba(242,227,191,.45);stroke-width:3}.c2{stroke:#b9955b;stroke-width:6}
</style><div class="t"><div class="o">Como estudar · ${esc(cat.nome)}</div><h1>${esc(m['title (Rank Math)'])}</h1><div class="r"><div class="k"><i></i><i></i><i></i></div><div class="n"><span>Tchau</span><strong>Pobreza<b>.</b></strong></div></div></div><svg viewBox="0 0 200 160">${arte[cat.slug]}</svg>`;
    await p.setContent(html, { waitUntil: 'networkidle' });
    await p.evaluate(() => document.fonts.ready);
    await p.screenshot({ path: path.join(dir, 'capas', m.slug + '.png') });
    console.log(m.slug);
  }
  await b.close();
})();
