/* TP Informativos: marcar como lido (somente usuário logado). Sem dependências.
   Pode haver mais de um controle por página (topo e fim): todos ficam sincronizados. */
(function () {
  if (!window.TPI) return;
  function pinta(id, lido) {
    document.querySelectorAll('.tpi-lido[data-tpi-id="' + id + '"]').forEach(function (box) {
      box.classList.toggle('is-lido', lido);
      box.querySelector('.tpi-lido__campo').checked = lido;
      box.querySelector('.tpi-lido__estado').textContent = lido ? 'Lido' : 'Não lido';
      box.querySelector('.tpi-lido__acao').textContent = lido ? 'Clique para desmarcar' : 'Marcar este julgado como lido';
      box.querySelector('.tpi-lido__erro').textContent = '';
    });
  }
  document.addEventListener('change', function (e) {
    var campo = e.target.closest('.tpi-lido__campo');
    if (!campo) return;
    var box = campo.closest('.tpi-lido');
    var id = box.getAttribute('data-tpi-id');
    var novo = campo.checked;
    pinta(id, novo); /* resposta imediata; desfaz se o servidor falhar */
    box.setAttribute('aria-busy', 'true');
    fetch(TPI.rest + id, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': TPI.nonce },
      body: JSON.stringify({ lido: novo })
    })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) { pinta(id, !!d.lido); })
      .catch(function () {
        pinta(id, !novo);
        box.querySelector('.tpi-lido__erro').textContent = 'Não foi possível salvar. Tente de novo.';
      })
      .finally(function () { box.removeAttribute('aria-busy'); });
  });
})();
