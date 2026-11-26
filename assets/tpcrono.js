/* Gerador de cronograma de estudos (0.29.0). Tudo roda aqui no navegador; os campos ficam só no localStorage. */
(function () {
  'use strict';
  var raiz = document.querySelector('.tpcrono');
  if (!raiz) return;
  var form = raiz.querySelector('.tpcrono-form');
  var lista = raiz.querySelector('.tpcrono-materias');
  var saida = raiz.querySelector('.tpcrono-resultado');
  var selEdital = raiz.querySelector('#tpcrono-edital');
  var inProva = raiz.querySelector('#tpcrono-prova');
  var inSemanas = raiz.querySelector('#tpcrono-semanas');
  var inDias = [].slice.call(raiz.querySelectorAll('[data-dia]'));
  var API = raiz.getAttribute('data-api');
  var APP = raiz.getAttribute('data-app');
  var CHAVE = 'tp-cronograma-v1';
  var DIAS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];
  var DIF = [['1', 'Já domino'], ['1.5', 'Mais ou menos'], ['2', 'Fraco ou novo']];
  var EXEMPLO = [
    { nome: 'Língua Portuguesa', peso: 10, topicos: 12, dif: 1.5 },
    { nome: 'Direito Constitucional', peso: 10, topicos: 12, dif: 1.5 },
    { nome: 'Direito Administrativo', peso: 10, topicos: 10, dif: 2 },
    { nome: 'Raciocínio Lógico', peso: 5, topicos: 8, dif: 2 },
    { nome: 'Informática', peso: 5, topicos: 8, dif: 1 }
  ];
  var edital = null; // { slug, nome, prova, url, plano, disciplinas:[{nome,peso,topicos:[titulos]}] }

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function num(v, pad) { var n = parseFloat(String(v).replace(',', '.')); return isFinite(n) ? n : pad; }
  function horas(h) {
    var t = Math.round(h * 2) / 2;
    var hh = Math.floor(t), mm = t - hh ? '30' : '';
    if (!hh) return '30 min';
    return hh + 'h' + mm;
  }
  function pct(x) { return Math.round(x * 100) + '%'; }
  function dm(d) { return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2); }
  function hoje() { var d = new Date(); d.setHours(0, 0, 0, 0); return d; }
  function somaDias(d, n) { var x = new Date(d); x.setDate(x.getDate() + n); return x; }
  function lerData(s) { if (!/^\d{4}-\d{2}-\d{2}$/.test(s || '')) return null; var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2]); }

  /* ---------- Linhas de matéria ---------- */
  function linha(m) {
    var div = document.createElement('div');
    div.className = 'tpcrono-materia';
    var ops = DIF.map(function (d) { return '<option value="' + d[0] + '"' + (String(m.dif || 1.5) === d[0] ? ' selected' : '') + '>' + d[1] + '</option>'; }).join('');
    div.innerHTML = '<label class="m-campo m-c-nome"><span>Matéria</span><input type="text" class="m-nome" placeholder="Ex.: Direito Penal" value="' + esc(m.nome || '') + '"></label>'
      + '<label class="m-campo"><span>Peso</span><input type="number" class="m-peso" min="0" step="0.5" inputmode="decimal" value="' + esc(m.peso == null ? '' : m.peso) + '"></label>'
      + '<label class="m-campo"><span>Tópicos</span><input type="number" class="m-top" min="1" step="1" inputmode="numeric" value="' + esc(m.topicos || 10) + '"' + (m.titulos ? ' readonly title="Tópicos do edital carregado"' : '') + '></label>'
      + '<label class="m-campo m-c-dif"><span>Você nessa matéria</span><select class="m-dif">' + ops + '</select></label>'
      + '<button type="button" class="m-tirar" aria-label="Tirar ' + esc(m.nome || 'matéria') + '">×</button>';
    if (m.titulos) div._titulos = m.titulos;
    lista.appendChild(div);
  }
  function materias() {
    return [].slice.call(lista.querySelectorAll('.tpcrono-materia')).map(function (el) {
      return {
        nome: el.querySelector('.m-nome').value.trim(),
        peso: num(el.querySelector('.m-peso').value, 0),
        topicos: Math.max(1, Math.round(num(el.querySelector('.m-top').value, 10))),
        dif: num(el.querySelector('.m-dif').value, 1.5),
        titulos: el._titulos || null
      };
    });
  }
  function preencher(ms) { lista.innerHTML = ''; ms.forEach(linha); }

  raiz.querySelector('.tpcrono-mais').addEventListener('click', function () {
    linha({ nome: '', peso: 5, topicos: 10, dif: 1.5 });
    var ult = lista.lastElementChild; if (ult) ult.querySelector('.m-nome').focus();
  });
  lista.addEventListener('click', function (e) {
    var b = e.target.closest('.m-tirar'); if (!b) return;
    b.parentNode.remove();
  });

  /* ---------- Guardar e recuperar ---------- */
  function estado() {
    var h = {}; inDias.forEach(function (i) { h[i.getAttribute('data-dia')] = num(i.value, 0); });
    return { edital: edital ? edital.slug : '', prova: inProva.value, semanas: inSemanas.value, horas: h, materias: materias().map(function (m) { return { nome: m.nome, peso: m.peso, topicos: m.topicos, dif: m.dif }; }) };
  }
  function guardar() { try { localStorage.setItem(CHAVE, JSON.stringify(estado())); } catch (e) { /* sem armazenamento */ } }
  function recuperar() { try { return JSON.parse(localStorage.getItem(CHAVE) || 'null'); } catch (e) { return null; } }

  /* ---------- Edital pronto ---------- */
  function carregarEdital(slug, depois) {
    if (!slug) { edital = null; preencher(EXEMPLO); if (depois) depois(); return; }
    raiz.classList.add('is-carregando');
    fetch(API + encodeURIComponent(slug), { credentials: 'omit' }).then(function (r) { if (!r.ok) throw r; return r.json(); }).then(function (d) {
      edital = d;
      preencher(d.disciplinas.map(function (x) { return { nome: x.nome, peso: x.peso, topicos: x.topicos.length || 1, dif: 1.5, titulos: x.topicos.length ? x.topicos : null }; }));
      if (d.prova) inProva.value = d.prova;
      if (selEdital) selEdital.value = slug;
      if (depois) depois();
    }).catch(function () {
      edital = null; if (selEdital) selEdital.value = '';
      aviso('Não conseguimos carregar esse edital agora. Preencha as matérias à mão.');
    }).then(function () { raiz.classList.remove('is-carregando'); });
  }
  if (selEdital) selEdital.addEventListener('change', function () { carregarEdital(selEdital.value); });

  function aviso(t) { saida.hidden = false; saida.innerHTML = '<p class="tpcrono-alerta">' + esc(t) + '</p>'; }

  /* ---------- A conta ---------- */
  // Distribui n unidades inteiras pelos pesos (maior resto), com ao menos 1 para cada peso > 0 quando dá.
  function repartir(pesos, n) {
    var soma = pesos.reduce(function (a, b) { return a + b; }, 0) || 1;
    var base = pesos.map(function (p) { return Math.floor(p / soma * n); });
    var resto = pesos.map(function (p, i) { return { i: i, r: p / soma * n - base[i] }; }).sort(function (a, b) { return b.r - a.r; });
    var falta = n - base.reduce(function (a, b) { return a + b; }, 0);
    for (var k = 0; k < falta; k++) base[resto[k % resto.length].i]++;
    pesos.forEach(function (p, i) {
      if (p > 0 && base[i] === 0 && n >= pesos.length) {
        var maior = base.indexOf(Math.max.apply(null, base)); base[maior]--; base[i]++;
      }
    });
    return base;
  }

  function calcular() {
    var ms = materias().filter(function (m) { return m.nome && m.peso > 0; });
    if (!ms.length) return { erro: 'Coloque pelo menos uma matéria com nome e peso maior que zero.' };
    var dias = inDias.map(function (i) { return Math.max(0, Math.min(14, num(i.value, 0))); });
    var semanaH = dias.reduce(function (a, b) { return a + b; }, 0);
    if (semanaH < 1) return { erro: 'Coloque pelo menos 1 hora de estudo na semana.' };
    var t0 = hoje(), prova = lerData(inProva.value), W;
    if (prova) {
      W = Math.floor((prova - t0) / 864e5 / 7);
      if (W < 1) return { erro: 'A data da prova já passou ou está a menos de uma semana. Nesse caso, não monte cronograma: revise o que já estudou e faça questões.' };
    } else {
      W = Math.max(2, Math.min(78, Math.round(num(inSemanas.value, 16))));
    }
    var R = W >= 20 ? 4 : W >= 10 ? 3 : W >= 5 ? 2 : 1; // reta final
    var B = Math.max(1, W - R);

    // Revisão: ~20% de cada dia de estudo (mínimo 30 min em dia com 1 h ou mais).
    var rev = dias.map(function (h) { return h >= 1 ? Math.max(0.5, Math.round(h * 0.2 * 2) / 2) : 0; });
    var estudoDia = dias.map(function (h, i) { return h - rev[i]; });
    var S = estudoDia.reduce(function (a, b) { return a + b; }, 0);

    // Regra prática: metade do tempo segue o peso na prova, metade segue o tamanho (tópicos); as duas pela dificuldade.
    var sP = ms.reduce(function (a, m) { return a + m.peso * m.dif; }, 0);
    var sT = ms.reduce(function (a, m) { return a + m.topicos * m.dif; }, 0);
    var pontos = ms.map(function (m) { return 0.5 * m.peso * m.dif / sP + 0.5 * m.topicos * m.dif / sT; });
    var somaPeso = ms.reduce(function (a, m) { return a + m.peso; }, 0);
    var unid = repartir(pontos, Math.round(S * 2)); // meias horas por semana
    ms.forEach(function (m, i) {
      m.h = unid[i] / 2;
      m.parte = m.peso / somaPeso;
      m.porSemana = m.topicos / B;
      m.hTopico = m.porSemana ? m.h / m.porSemana : 0;
    });

    // Semana-modelo: sessões de 1 h (ou 30 min), intercaladas pelo método de D'Hondt.
    var sess = [], usados = ms.map(function () { return 0; });
    var totalU = unid.reduce(function (a, b) { return a + b; }, 0);
    while (sess.length < totalU * 2) {
      var melhor = -1, q = -1;
      for (var i = 0; i < ms.length; i++) {
        if (usados[i] >= unid[i]) continue;
        var v = unid[i] / (usados[i] + 1);
        if (v > q) { q = v; melhor = i; }
      }
      if (melhor < 0) break;
      var tam = unid[melhor] - usados[melhor] >= 2 ? 2 : 1;
      usados[melhor] += tam;
      sess.push({ m: melhor, u: tam });
    }
    var grade = dias.map(function () { return []; }), fila = sess.slice();
    estudoDia.forEach(function (h, d) {
      var cap = Math.round(h * 2);
      if (rev[d]) grade[d].push({ nome: 'Revisão e questões', u: rev[d] * 2, rev: true });
      var hojeJa = {};
      while (cap > 0 && fila.length) {
        // 1º: matéria que ainda não entrou hoje e cabe inteira; 2º: qualquer uma que caiba; por último, parte a primeira.
        var k = -1, j;
        for (j = 0; j < fila.length && k < 0; j++) if (!hojeJa[fila[j].m] && fila[j].u <= cap) k = j;
        for (j = 0; j < fila.length && k < 0; j++) if (fila[j].u <= cap) k = j;
        if (k < 0) k = 0;
        var s = fila[k];
        hojeJa[s.m] = 1;
        if (s.u <= cap) { fila.splice(k, 1); grade[d].push({ m: s.m, nome: ms[s.m].nome, u: s.u }); cap -= s.u; }
        else { s.u -= cap; grade[d].push({ m: s.m, nome: ms[s.m].nome, u: cap }); cap = 0; }
      }
      // junta sessões vizinhas da mesma matéria
      grade[d] = grade[d].reduce(function (acc, x) { var a = x.rev ? null : acc.filter(function (y) { return !y.rev && y.m === x.m; })[0]; if (a) a.u += x.u; else acc.push(x); return acc; }, []);
    });

    // Semana a semana: cada matéria anda um pouco toda semana até a reta final.
    var semanas = [];
    for (var w = 1; w <= W; w++) {
      var ini = somaDias(t0, (w - 1) * 7), fim = somaDias(t0, w * 7 - 1);
      if (prova && fim >= prova) fim = somaDias(prova, -1);
      var it = { n: w, ini: ini, fim: fim, fase: w <= B ? 'base' : (w === W ? 'prova' : 'reta'), itens: [] };
      if (w <= B) {
        ms.forEach(function (m) {
          var a = Math.ceil(m.topicos * (w - 1) / B - 1e-9) + 1, b = Math.ceil(m.topicos * w / B - 1e-9);
          it.itens.push({ nome: m.nome, h: m.h, de: a, ate: b, titulos: m.titulos ? m.titulos.slice(a - 1, b) : null });
        });
      }
      semanas.push(it);
    }
    var alertas = [];
    ms.forEach(function (m) {
      if (m.hTopico < 1) alertas.push(m.nome + ': sobram só ' + horas(m.hTopico) + ' por tópico. Aumente as horas ou comece pelos tópicos que mais caem.');
    });
    if (W > 40) alertas.push('Mais de 40 semanas: refaça o cronograma a cada 2 ou 3 meses, porque o seu ritmo e o edital vão mudar.');
    return { alertas: alertas, ms: ms, dias: dias, rev: rev, grade: grade, semanas: semanas, W: W, B: B, R: R, semanaH: semanaH, prova: prova };
  }

  /* ---------- Desenho do resultado ---------- */
  function desenhar(r) {
    var h = '';
    h += '<div class="tpcrono-resumo">'
      + '<div><b>' + r.W + '</b><span>semanas até a prova</span></div>'
      + '<div><b>' + r.B + '</b><span>de matéria nova</span></div>'
      + '<div><b>' + r.R + '</b><span>de reta final</span></div>'
      + '<div><b>' + horas(r.semanaH) + '</b><span>por semana</span></div></div>';
    if (edital) h += '<p class="tpcrono-fonte">Matérias, peso e tópicos do edital ' + esc(edital.nome) + ' (<a href="' + esc(edital.url) + '">ver o edital verticalizado</a>).</p>';
    h += '<p class="tpcrono-sub">Horas por matéria</p><div class="tpcrono-rolar"><table class="tpcrono-t"><thead><tr><th>Matéria</th><th>Peso</th><th>Horas/semana</th><th>Tópicos/semana</th><th>Tempo/tópico</th></tr></thead><tbody>';
    r.ms.forEach(function (m) {
      h += '<tr><td>' + esc(m.nome) + '</td><td>' + pct(m.parte) + '</td><td>' + horas(m.h) + '</td><td>' + (Math.round(m.porSemana * 10) / 10).toString().replace('.', ',') + '</td><td' + (m.hTopico < 1 ? ' class="is-pouco"' : '') + '>' + horas(m.hTopico) + '</td></tr>';
    });
    h += '</tbody></table></div>';
    if (r.alertas && r.alertas.length) h += '<ul class="tpcrono-alertas">' + r.alertas.map(function (a) { return '<li>' + esc(a) + '</li>'; }).join('') + '</ul>';

    h += '<p class="tpcrono-sub">Sua semana-modelo</p><p class="tpcrono-nota">Repita esta semana até a reta final. A revisão abre o dia porque é o que mais rende na memória.</p><div class="tpcrono-semana">';
    r.grade.forEach(function (g, d) {
      h += '<div class="tpcrono-dia-col' + (r.dias[d] ? '' : ' is-folga') + '"><p class="tpcrono-dia-nome">' + DIAS[d] + ' <small>' + (r.dias[d] ? horas(r.dias[d]) : 'folga') + '</small></p>';
      g.forEach(function (s) { h += '<p class="tpcrono-sessao' + (s.rev ? ' is-rev' : '') + '"><span>' + esc(s.nome) + '</span><small>' + horas(s.u / 2) + '</small></p>'; });
      h += '</div>';
    });
    h += '</div>';

    h += '<p class="tpcrono-sub">Semana a semana</p><ol class="tpcrono-semanas">';
    r.semanas.forEach(function (s) {
      var rot = s.fase === 'base' ? 'Matéria nova' : s.fase === 'reta' ? 'Reta final' : 'Semana da prova';
      h += '<li class="tpcrono-s is-' + s.fase + '"><p class="tpcrono-s-cab"><b>Semana ' + s.n + '</b> <span>' + dm(s.ini) + ' a ' + dm(s.fim) + '</span> <em>' + rot + '</em></p>';
      if (s.fase === 'base') {
        h += '<ul>';
        s.itens.forEach(function (it) {
          var faixa = it.ate < it.de ? 'só revisão e questões' : (it.de === it.ate ? 'tópico ' + it.de : 'tópicos ' + it.de + ' a ' + it.ate);
          h += '<li><b>' + esc(it.nome) + '</b>: ' + faixa;
          if (it.titulos && it.titulos.length) h += '<span class="tpcrono-titulos">' + it.titulos.map(esc).join(' · ') + '</span>';
          h += '</li>';
        });
        h += '</ul>';
      } else if (s.fase === 'reta') {
        h += '<p>Sem tópico novo. Um simulado completo no fim de semana, revisão dos erros dele e das matérias de maior peso, e questões dos tópicos em que você mais erra.</p>';
      } else {
        h += '<p>Revisão leve dos seus resumos e dos erros anotados. Nada de conteúdo novo. Na véspera, confira local, horário e documentos, e durma cedo.</p>';
      }
      h += '</li>';
    });
    h += '</ol>';

    h += '<div class="tpcrono-baixar"><button type="button" class="tpcrono-csv">Baixar planilha (Excel)</button><button type="button" class="tpcrono-pdf">Imprimir ou salvar em PDF</button></div>';
    var app = APP + (edital && edital.plano ? '?plano=' + encodeURIComponent(edital.plano) : '');
    h += '<div class="tpcrono-app"><p><b>Perdeu uma semana?</b> No cronograma em papel, você refaz a conta. No Estudo inteligente do Tchau Pobreza, a fila do dia se ajusta sozinha: revisões vencidas primeiro, depois os tópicos novos pelo peso do edital, com questões de cada um.</p><a class="tpcrono-app-btn" href="' + esc(app) + '">Estudar pelo meu edital no app</a></div>';
    saida.innerHTML = h;
    saida.hidden = false;
    saida._r = r;
  }

  function csv(r) {
    var q = function (s) { s = String(s == null ? '' : s); return /[";\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s; };
    var linhas = [['Semana', 'Início', 'Fim', 'Fase', 'Matéria', 'Horas na semana', 'Tópicos', 'Assuntos', 'Feito?']];
    r.semanas.forEach(function (s) {
      var rot = s.fase === 'base' ? 'Matéria nova' : s.fase === 'reta' ? 'Reta final' : 'Semana da prova';
      if (s.fase !== 'base') { linhas.push([s.n, dm(s.ini), dm(s.fim), rot, 'Simulado, revisão e questões', '', '', '', '']); return; }
      s.itens.forEach(function (it) {
        linhas.push([s.n, dm(s.ini), dm(s.fim), rot, it.nome, String(Math.round(it.h * 2) / 2).replace('.', ','), it.ate < it.de ? 'revisão' : (it.de === it.ate ? String(it.de) : it.de + ' a ' + it.ate), it.titulos ? it.titulos.join(' | ') : '', '']);
      });
    });
    var txt = '﻿' + linhas.map(function (l) { return l.map(q).join(';'); }).join('\r\n');
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([txt], { type: 'text/csv;charset=utf-8' }));
    a.download = 'cronograma-de-estudos' + (edital ? '-' + edital.slug : '') + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 500);
  }

  saida.addEventListener('click', function (e) {
    if (e.target.closest('.tpcrono-csv') && saida._r) csv(saida._r);
    if (e.target.closest('.tpcrono-pdf')) { document.body.classList.add('tpcrono-imprimindo'); window.print(); }
  });
  window.addEventListener('afterprint', function () { document.body.classList.remove('tpcrono-imprimindo'); });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var r = calcular();
    if (r.erro) { aviso(r.erro); return; }
    desenhar(r);
    guardar();
    saida.scrollIntoView({ behavior: 'smooth', block: 'start' });
  });
  raiz.querySelector('.tpcrono-limpar').addEventListener('click', function () {
    try { localStorage.removeItem(CHAVE); } catch (e) { /* nada */ }
    edital = null; if (selEdital) selEdital.value = '';
    inProva.value = ''; inSemanas.value = 16;
    inDias.forEach(function (i, k) { i.value = k < 5 ? 2 : k === 5 ? 4 : 0; });
    preencher(EXEMPLO); saida.hidden = true; saida.innerHTML = '';
  });

  /* ---------- Início ---------- */
  var pedido = (location.search.match(/[?&]edital=([a-z0-9-]+)/) || [])[1];
  var salvo = recuperar();
  if (pedido) {
    carregarEdital(pedido);
  } else if (salvo) {
    inProva.value = salvo.prova || '';
    inSemanas.value = salvo.semanas || 16;
    inDias.forEach(function (i) { var v = salvo.horas && salvo.horas[i.getAttribute('data-dia')]; if (v != null) i.value = v; });
    if (salvo.edital) {
      var guard = salvo;
      carregarEdital(salvo.edital, function () {
        // mantém o nível que a pessoa marcou em cada matéria
        [].slice.call(lista.querySelectorAll('.tpcrono-materia')).forEach(function (el, i) { var m = guard.materias[i]; if (m && m.nome === el.querySelector('.m-nome').value) el.querySelector('.m-dif').value = String(m.dif); });
        if (guard.prova) inProva.value = guard.prova;
      });
    } else {
      preencher(salvo.materias && salvo.materias.length ? salvo.materias : EXEMPLO);
    }
  } else {
    preencher(EXEMPLO);
  }
})();
