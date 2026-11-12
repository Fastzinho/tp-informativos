/* Vitrine 0.14.0: busca de temas no hub /cadernos/. */
(function () {
  var input = document.querySelector('[data-tpv-busca]');
  var lista = document.querySelector('[data-tpv-resultados]');
  var dados = document.querySelector('[data-tpv-indice]');
  if (!input || !lista || !dados) return;
  var temas = [];
  try { temas = JSON.parse(dados.textContent) || []; } catch (e) { return; }
  function norm(s) { return (s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); }
  temas.forEach(function (t) { t.n = norm(t.t + ' ' + t.g + ' ' + t.p + ' ' + t.d); t.nt = norm(t.t); });
  var ativo = -1;
  function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
  function render() {
    var q = norm(input.value.trim());
    ativo = -1;
    if (q.length < 2) { lista.hidden = true; lista.innerHTML = ''; return; }
    var partes = q.split(/\s+/);
    var achados = temas.filter(function (t) { return partes.every(function (p) { return t.n.indexOf(p) !== -1; }); });
    achados.sort(function (a, b) { return (b.nt.indexOf(q) === 0) - (a.nt.indexOf(q) === 0) || (b.nt.indexOf(q) !== -1) - (a.nt.indexOf(q) !== -1); });
    lista.innerHTML = achados.length
      ? achados.slice(0, 10).map(function (t) { return '<li><a href="' + esc(t.u) + '">' + esc(t.t) + '<small>' + esc(t.d) + (t.g ? ' · ' + esc(t.g) : '') + '</small></a></li>'; }).join('')
      : '<li class="tpv-busca__vazio">Nenhum tema encontrado. Tente outra palavra.</li>';
    lista.hidden = false;
  }
  input.addEventListener('input', render);
  input.addEventListener('keydown', function (e) {
    var links = lista.querySelectorAll('a');
    if (!links.length) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      ativo = (ativo + (e.key === 'ArrowDown' ? 1 : -1) + links.length) % links.length;
      links.forEach(function (a, i) { a.classList.toggle('is-ativo', i === ativo); });
      links[ativo].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      e.preventDefault();
      window.location.href = links[Math.max(ativo, 0)].href;
    } else if (e.key === 'Escape') {
      lista.hidden = true;
    }
  });
  document.addEventListener('click', function (e) { if (!e.target.closest('.tpv-busca')) lista.hidden = true; });
  input.addEventListener('focus', render);
})();
