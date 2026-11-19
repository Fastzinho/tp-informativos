// Gera os gráficos do guia "Raio-X das provas do Cebraspe" em como-estudar/graficos/*.png.
// Dados: /mnt/project-files/raio-x-provas/ (contagem de 19/11/2026). Uso: node ferramentas/graficos-raio-x.js
const fs = require('fs'), path = require('path');
const { chromium } = require('playwright');
const out = path.join(__dirname, '..', 'como-estudar', 'graficos');
const fonte = fs.readFileSync(path.join(__dirname, 'fontes', 'manrope.woff2')).toString('base64');
const C = { fg: '#1d2b26', muted: '#5b6b64', line: '#dcd8cb', bar: '#2c5748', hi: '#a8822f', bg: '#ffffff' };
const graficos = {
  'raio-x-certo-por-prova': () => {
    const d = [[38,1],[40,0],[42,7],[44,18],[46,29],[48,24],[50,32],[52,19],[54,5],[56,6],[58,1]];
    const W=900,H=420,L=60,R=880,T=60,B=340,max=35,w=(R-L)/d.length;let s='';
    s+=`<text x="${L}" y="30" class="tt">Provas por faixa de gabarito Certo</text>`;
    [0,10,20,30].forEach(v=>{const y=B-(v/max)*(B-T);s+=`<line x1="${L}" x2="${R}" y1="${y}" y2="${y}" class="ax"/><text x="${L-10}" y="${y+5}" text-anchor="end">${v}</text>`});
    d.forEach(([x,n],i)=>{const h=(n/max)*(B-T),bx=L+i*w+3;s+=`<rect x="${bx}" y="${B-h}" width="${w-6}" height="${h}" rx="4" fill="${C.bar}"/>`;if(n)s+=`<text x="${bx+(w-6)/2}" y="${B-h-8}" text-anchor="middle" class="v">${n}</text>`;s+=`<text x="${L+i*w}" y="${B+24}" text-anchor="middle">${x}%</text>`});
    s+=`<text x="${R}" y="${B+24}" text-anchor="middle">60%</text>`;
    const x50=L+6*w;s+=`<line x1="${x50}" x2="${x50}" y1="${T-10}" y2="${B}" class="ref"/><text x="${x50+6}" y="${T-14}">50%</text>`;
    s+=`<text x="${(L+R)/2}" y="${B+56}" text-anchor="middle">Porcentagem de gabarito Certo na prova (142 provas do Cebraspe, 2020–2026)</text>`;
    return [W,H,s];
  },
  'raio-x-palavras': () => {
    const d=[["pois / porque",68.9,161],["exclusivamente",64.8,54],["vedado / vedada",64.8,125],["somente",60.7,135],["necessariamente",60.5,38],["apenas",60.1,153],["independentemente",58.8,97],["obrigatoriamente",55.6,36],["todos / todas",54.0,250],["salvo / exceto",53.1,64],["não",52.2,1395],["sempre",50.0,58],["qualquer",49.4,164],["deve / devem",48.6,611],["possível",48.0,198],["pode / podem",43.4,650]];
    const W=900,L=210,R=730,T=56,rh=32,H=T+d.length*rh+50,x=v=>L+(v/80)*(R-L);let s='';
    s+=`<text x="20" y="30" class="tt">Gabarito Errado quando a questão tem a palavra</text>`;
    [0,20,40,60,80].forEach(v=>{s+=`<line x1="${x(v)}" x2="${x(v)}" y1="${T}" y2="${T+d.length*rh}" class="ax"/><text x="${x(v)}" y="${T+d.length*rh+22}" text-anchor="middle">${v}%</text>`});
    d.forEach(([w,p,n],i)=>{const y=T+i*rh;s+=`<text x="${L-12}" y="${y+21}" text-anchor="end" class="lab">${w}</text><rect x="${L}" y="${y+6}" width="${x(p)-L}" height="${rh-12}" rx="4" fill="${p>=60?C.hi:C.bar}"/><text x="${x(p)+8}" y="${y+21}" class="v">${p.toFixed(0)}%</text><text x="${x(p)+52}" y="${y+21}">${n} questões</text>`});
    s+=`<line x1="${x(50)}" x2="${x(50)}" y1="${T-8}" y2="${T+d.length*rh}" class="ref"/>`;
    return [W,H,s];
  },
  'raio-x-temas': () => {
    const d=[["Direitos e deveres fundamentais",97,60,62],["Crimes (parte especial)",97,38,39],["Prisão",88,37,42],["Provas no processo penal",81,34,42],["Teoria geral do crime",79,31,39],["Inquérito policial",79,33,42],["Atos administrativos",78,47,60],["Crimes contra o patrimônio",77,30,39],["Lei de Drogas",74,25,34],["Pontuação",72,31,43],["Licitações",70,42,60],["Contratos (Direito Civil)",70,26,37],["Organização do Estado",69,43,62],["Improbidade administrativa",65,39,60],["Responsabilidade civil do Estado",63,38,60]];
    const W=900,L=300,R=740,T=56,rh=32,H=T+d.length*rh+50,x=v=>L+(v/100)*(R-L);let s='';
    s+=`<text x="20" y="30" class="tt">Concursos em que o tema caiu (Cebraspe e FGV, 71 concursos)</text>`;
    [0,25,50,75,100].forEach(v=>{s+=`<line x1="${x(v)}" x2="${x(v)}" y1="${T}" y2="${T+d.length*rh}" class="ax"/><text x="${x(v)}" y="${T+d.length*rh+22}" text-anchor="middle">${v}%</text>`});
    d.forEach(([t,p,a,b],i)=>{const y=T+i*rh;s+=`<text x="${L-12}" y="${y+21}" text-anchor="end" class="lab">${t}</text><rect x="${L}" y="${y+6}" width="${x(p)-L}" height="${rh-12}" rx="4" fill="${C.bar}"/><text x="${x(p)+8}" y="${y+21}" class="v">${p}%</text><text x="${x(p)+54}" y="${y+21}">${a} de ${b}</text>`});
    return [W,H,s];
  },
};
(async () => {
  const b = await chromium.launch();
  for (const [nome, f] of Object.entries(graficos)) {
    const [W, H, svg] = f();
    const p = await b.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: 2 });
    await p.setContent(`<!doctype html><meta charset="utf-8"><style>@font-face{font-family:M;src:url(data:font/woff2;base64,${fonte}) format('woff2');font-weight:200 800}
body{margin:0;background:${C.bg}}svg text{font-family:M,sans-serif;font-size:16px;fill:${C.muted}}svg .v{fill:${C.fg};font-weight:700}svg .lab{fill:${C.fg}}svg .tt{fill:${C.fg};font-size:20px;font-weight:700}
.ax{stroke:${C.line}}.ref{stroke:${C.fg};stroke-dasharray:4 4;stroke-width:1.5}</style><svg width="${W}" height="${H}" viewBox="0 0 ${W} ${H}">${svg}<text x="${W-14}" y="${H-12}" text-anchor="end" style="font-size:13px">tchaupobreza.com.br/como-estudar/raio-x-provas-cebraspe/</text></svg>`);
    await p.waitForTimeout(200);
    await p.screenshot({ path: path.join(out, nome + '.png') });
    await p.close();
  }
  await b.close();
})();
