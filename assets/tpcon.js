/*
 * TP Concursos 0.18.0: marcações T/Q/R do edital verticalizado (salvas no aparelho), busca, filtros,
 * contagem de dias até as inscrições e a prova (calculada no navegador, porque o HTML fica em cache) e eventos GA.
 */
(function () {
  var raiz = document.querySelector('.tpcon');
  if (!raiz) return;
  var plano = raiz.getAttribute('data-plano');
  var chave = 'tp-ev-' + plano;
  var ler = function () { try { return JSON.parse(localStorage.getItem(chave) || '{}') || {}; } catch (e) { return {}; } };
  var gravar = function (v) { try { localStorage.setItem(chave, JSON.stringify(v)); } catch (e) {} };
  var evento = function (nome, extra) {
    if (typeof window.gtag !== 'function') return;
    var d = { plano: plano };
    for (var k in extra) d[k] = extra[k];
    window.gtag('event', nome, d);
  };
  var linhas = Array.prototype.slice.call(raiz.querySelectorAll('.tpcon-t'));
  var marc = ler();

  // Estado salvo
  linhas.forEach(function (li) {
    var v = marc[li.getAttribute('data-id')] || '';
    li.querySelectorAll('input[data-c]').forEach(function (i) { i.checked = v.indexOf(i.getAttribute('data-c')) >= 0; });
    li.classList.toggle('feito', v.length === 3);
  });

  function progresso() {
    var total = linhas.length, est = 0, tudo = 0, porDisc = {};
    linhas.forEach(function (li) {
      var v = marc[li.getAttribute('data-id')] || '', disc = li.getAttribute('data-id').split(':')[0];
      porDisc[disc] = porDisc[disc] || [0, 0];
      porDisc[disc][1]++;
      if (v.indexOf('T') >= 0) { est++; porDisc[disc][0]++; }
      if (v.length === 3) tudo++;
    });
    var pct = total ? est / total : 0, C = 2 * Math.PI * 26;
    var alvo = raiz.querySelector('.tpcon-prog');
    if (alvo) {
      alvo.innerHTML = '<svg width="64" height="64" viewBox="0 0 64 64" aria-hidden="true"><circle cx="32" cy="32" r="26" fill="none" stroke="#f1f5f2" stroke-width="7"/>'
        + '<circle cx="32" cy="32" r="26" fill="none" stroke="#2c5748" stroke-width="7" stroke-linecap="round" stroke-dasharray="' + C + '" stroke-dashoffset="' + (C * (1 - pct)) + '" transform="rotate(-90 32 32)"/>'
        + '<text x="32" y="37" text-anchor="middle" font-size="14" font-weight="800" fill="#1e2b26">' + Math.round(pct * 100) + '%</text></svg>'
        + '<p><strong>' + est + ' de ' + total + '</strong>tópicos com teoria marcada · ' + tudo + ' com T, Q e R. '
        + (est ? 'Suas marcações ficam salvas neste aparelho.' : 'Comece marcando o que você já estudou.') + '</p>';
    }
    Object.keys(porDisc).forEach(function (d) {
      var c = raiz.querySelector('[data-tpcon-cont="' + d + '"]'), b = raiz.querySelector('[data-tpcon-barra="' + d + '"]');
      if (c) c.textContent = porDisc[d][0] + '/' + porDisc[d][1];
      if (b) b.style.width = (porDisc[d][0] / porDisc[d][1] * 100) + '%';
    });
  }
  progresso();

  raiz.addEventListener('change', function (e) {
    var i = e.target;
    if (!i.getAttribute || !i.getAttribute('data-c')) return;
    var li = i.closest('.tpcon-t'), id = li.getAttribute('data-id');
    marc = ler();
    var v = '';
    li.querySelectorAll('input[data-c]').forEach(function (x) { if (x.checked) v += x.getAttribute('data-c'); });
    if (v) marc[id] = v; else delete marc[id];
    gravar(marc);
    li.classList.toggle('feito', v.length === 3);
    progresso();
    if (i.checked && Object.keys(marc).length === 1) evento('concurso_marcar', { coluna: i.getAttribute('data-c') });
  });

  // Busca e filtros (sem JS, a lista fica inteira e os filtros escondidos)
  var filtros = raiz.querySelector('.tpcon-filtros'), busca = raiz.querySelector('#tpcon-busca'), modo = 'todos';
  if (filtros) filtros.hidden = false;
  var norm = function (s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
  function filtrar() {
    var q = norm(busca ? busca.value.trim() : ''), algum = false;
    raiz.querySelectorAll('.tpcon-disc').forEach(function (d) {
      var vis = 0;
      d.querySelectorAll('.tpcon-t').forEach(function (li) {
        var v = marc[li.getAttribute('data-id')] || '';
        var ok = (!q || norm(li.querySelector('.tpcon-tx > p').textContent).indexOf(q) >= 0)
          && (modo !== 'pendentes' || v.indexOf('T') < 0)
          && (modo !== 'questoes' || li.getAttribute('data-q') === '1');
        li.hidden = !ok;
        if (ok) vis++;
      });
      d.querySelectorAll('.tpcon-grupo').forEach(function (g) { g.hidden = !!(q || modo !== 'todos'); });
      d.hidden = !vis;
      if (vis && (q || modo !== 'todos')) d.open = true;
      algum = algum || vis > 0;
    });
    var nada = raiz.querySelector('.tpcon-nada');
    if (nada) nada.hidden = algum;
  }
  if (busca) busca.addEventListener('input', filtrar);
  raiz.querySelectorAll('.tpcon-seg button').forEach(function (b) {
    b.addEventListener('click', function () {
      modo = b.getAttribute('data-f');
      raiz.querySelectorAll('.tpcon-seg button').forEach(function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
      marc = ler();
      filtrar();
    });
  });

  // Dias até as inscrições e a prova
  var hoje = new Date(); hoje.setHours(0, 0, 0, 0);
  var dias = function (ymd) { var p = ymd.split('-'); return Math.round((new Date(+p[0], +p[1] - 1, +p[2]) - hoje) / 864e5); };
  var insc = raiz.querySelector('[data-tpcon-insc]');
  if (insc) {
    var di = dias(insc.getAttribute('data-tpcon-insc'));
    if (di < 0) insc.innerHTML = 'encerradas em ' + insc.textContent.replace(/^até /, '');
    else insc.insertAdjacentHTML('beforeend', '<span class="tpcon-pill ' + (di <= 7 ? 'tpcon-pill--alerta' : 'tpcon-pill--falta') + '">' + (di === 0 ? 'termina hoje' : di === 1 ? 'falta 1 dia' : 'faltam ' + di + ' dias') + '</span>');
  }
  var prova = raiz.querySelector('[data-tpcon-prova]');
  if (prova) { var dp = dias(prova.getAttribute('data-tpcon-prova')); if (dp > 0) prova.insertAdjacentHTML('beforeend', '<span class="tpcon-pill">em ' + dp + ' dias</span>'); }
  var curta = raiz.querySelector('[data-tpcon-prova-curta]');
  if (curta) { var dc = dias(curta.getAttribute('data-tpcon-prova-curta')); if (dc > 0) curta.textContent = 'Prova em ' + dc + ' dias'; }

  // Cliques nas chamadas para o app
  raiz.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('[data-tpcon-cta]');
    if (a) evento('concurso_cta', { posicao: a.getAttribute('data-tpcon-cta') });
  });
})();
