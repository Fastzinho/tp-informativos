/* TP Informativos 0.7.10: "marcar como lido" com a conta do app (Supabase), sem dependências.
 *
 * - Lê a sessão que o app (?tp_app=1) guarda no localStorage deste domínio.
 * - Grava em public.tp_records: kind "informativo", id "informativo:<post_id>". A RLS do banco
 *   (user_id = auth.uid()) garante que cada pessoa só lê e grava os próprios registros.
 * - O HTML vem igual para todos (pode ficar em cache); o estado é aplicado aqui.
 */
(function () {
  var C = window.TPI;
  if (!C || !C.url || !C.key) return;
  var CHAVE = 'tchau-supabase-' + new URL(C.url).hostname; // mesma storageKey do app
  var KIND = 'informativo';

  function lerSessao() {
    try {
      var s = JSON.parse(localStorage.getItem(CHAVE) || 'null');
      if (s && s.currentSession) s = s.currentSession; // formato antigo do supabase-js
      return s && s.access_token && s.user && s.user.id ? s : null;
    } catch (e) { return null; }
  }

  function renovar(s) {
    return fetch(C.url + '/auth/v1/token?grant_type=refresh_token', {
      method: 'POST',
      headers: { apikey: C.key, 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: s.refresh_token })
    }).then(function (r) { if (!r.ok) throw new Error('sessao'); return r.json(); })
      .then(function (n) {
        if (!n.expires_at && n.expires_in) n.expires_at = Math.floor(Date.now() / 1000) + n.expires_in;
        try { localStorage.setItem(CHAVE, JSON.stringify(n)); } catch (e) {}
        return n;
      });
  }

  var sessaoP = null;
  function sessao() {
    if (sessaoP) return sessaoP;
    var s = lerSessao();
    if (!s) return (sessaoP = Promise.resolve(null));
    var vence = (s.expires_at || 0) * 1000;
    sessaoP = vence - Date.now() > 60000 ? Promise.resolve(s) : renovar(s).catch(function () { return null; });
    return sessaoP;
  }

  function api(s, caminho, opcoes) {
    opcoes = opcoes || {};
    opcoes.headers = Object.assign({ apikey: C.key, Authorization: 'Bearer ' + s.access_token, 'Content-Type': 'application/json' }, opcoes.headers || {});
    return fetch(C.url + '/rest/v1/' + caminho, opcoes).then(function (r) {
      if (r.status === 401) throw new Error('jwt');
      if (!r.ok) return r.text().then(function (t) { throw new Error(t || r.status); });
      return r.status === 204 ? null : r.text().then(function (t) { return t ? JSON.parse(t) : null; });
    });
  }

  /* Mostra o código do erro do banco (ex.: 23514), para o suporte saber a causa sem abrir o console. */
  function detalhe(err) {
    try { var o = JSON.parse(err.message); return o && (o.code || o.message) ? ' (erro ' + (o.code || '') + (o.message ? ': ' + o.message : '') + ')' : ''; }
    catch (e) { return /^\d{3}$/.test(String(err && err.message)) ? ' (erro ' + err.message + ')' : ''; }
  }

  var lidos = {};

  function pintaControle(box, lido) {
    box.classList.toggle('is-lido', lido);
    var topo = box.classList.contains('tpi-lido--topo');
    var estado = box.querySelector('.tpi-lido__estado');
    var novoEstado = lido ? (topo ? 'Lido' : 'Você já leu este julgado.') : '';
    if (estado.textContent !== novoEstado) estado.textContent = novoEstado;
    box.querySelector('.tpi-lido__acao').textContent = lido ? (topo ? 'Desmarcar' : 'Desmarcar como lido') : 'Marcar como lido';
    box.querySelector('.tpi-lido__botao').setAttribute('aria-label', lido ? 'Desmarcar este julgado como lido' : 'Marcar este julgado como lido');
  }

  function pintaTudo() {
    document.querySelectorAll('.tpi-lido[data-tpi-id]').forEach(function (box) {
      box.hidden = false;
      pintaControle(box, !!lidos[box.getAttribute('data-tpi-id')]);
    });
    document.querySelectorAll('.tpi-cards--julgados li.tpi-card[data-tpi-id]').forEach(function (li) {
      var lido = !!lidos[li.getAttribute('data-tpi-id')];
      li.classList.toggle('is-lido', lido);
      var b = li.querySelector('.tpi-card__marcar');
      if (!b) { /* só para quem tem conta; fica em posição absoluta, então não empurra o layout (sem CLS) */
        b = document.createElement('button'); b.type = 'button'; b.className = 'tpi-lido__botao tpi-card__marcar';
        b.innerHTML = '<span class="tpi-lido__icone" aria-hidden="true"></span><span class="tpi-lido__acao"></span>';
        li.appendChild(b);
      }
      var titulo = li.getAttribute('data-tpi-titulo') || 'este julgado';
      b.querySelector('.tpi-lido__acao').textContent = lido ? 'Lido' : 'Marcar como lido';
      b.setAttribute('aria-label', (lido ? 'Lido. Desmarcar: ' : 'Marcar como lido: ') + titulo);
    });
    document.querySelectorAll('[data-tpi-progresso]').forEach(function (p) {
      var ids = p.getAttribute('data-tpi-progresso').split(',').filter(Boolean);
      var n = ids.filter(function (i) { return lidos[i]; }).length;
      p.querySelector('.tpi-progresso__barra span').style.width = (ids.length ? Math.round(100 * n / ids.length) : 0) + '%';
      p.querySelector('.tpi-progresso__texto').innerHTML = 'Você leu <strong>' + n + ' de ' + ids.length + '</strong> julgados desta edição.';
      p.hidden = false;
    });
    document.querySelectorAll('.tpi-edicao[data-tpi-ids]').forEach(function (li) {
      var ids = li.getAttribute('data-tpi-ids').split(',').filter(Boolean);
      var n = ids.filter(function (i) { return lidos[i]; }).length;
      var todos = ids.length && n === ids.length;
      li.classList.toggle('is-lido', !!todos);
      var el = li.querySelector('.tpi-edicao__lidos');
      if (el) { el.textContent = todos ? 'Lido' : n + ' de ' + ids.length + ' lidos'; el.hidden = false; }
    });
  }

  function semConta() {
    document.querySelectorAll('.tpi-lido-cta').forEach(function (p) { p.hidden = false; });
  }

  sessao().then(function (s) {
    if (!s) return semConta();
    var ler = function (s) { return api(s, 'tp_records?select=id&kind=eq.' + KIND + '&limit=5000'); };
    return ler(s).catch(function (err) {
      if (String(err && err.message) !== 'jwt') throw err;
      sessaoP = renovar(s).catch(function () { return null; });
      return sessaoP.then(function (n) { if (!n) throw err; return ler(n); });
    }).then(function (linhas) {
      (linhas || []).forEach(function (l) { lidos[l.id.replace(KIND + ':', '')] = true; });
      pintaTudo();
    }).catch(function () { pintaTudo(); });
  });

  document.addEventListener('click', function (e) {
    var botao = e.target.closest('.tpi-lido__botao');
    if (!botao) return;
    var box = botao.closest('[data-tpi-id]');
    if (box.getAttribute('aria-busy') === 'true') return;
    var id = box.getAttribute('data-tpi-id');
    var novo = !lidos[id];
    var erro = box.querySelector('.tpi-lido__erro') || { textContent: '' };
    erro.textContent = '';
    lidos[id] = novo; pintaTudo(); /* resposta imediata; desfaz se falhar */
    box.setAttribute('aria-busy', 'true');
    function gravar(s) {
      if (!s) throw new Error('sessao');
      var rid = encodeURIComponent(KIND + ':' + id);
      if (!novo) return api(s, 'tp_records?kind=eq.' + KIND + '&id=eq.' + rid, { method: 'DELETE' });
      return api(s, 'tp_records?on_conflict=user_id,id', {
        method: 'POST',
        headers: { Prefer: 'resolution=merge-duplicates,return=minimal' },
        body: JSON.stringify({
          user_id: s.user.id, id: KIND + ':' + id, kind: KIND,
          data: {
            postId: Number(id), read: true, readAt: new Date().toISOString(),
            title: box.getAttribute('data-tpi-titulo') || '', url: box.getAttribute('data-tpi-url') || '',
            court: box.getAttribute('data-tpi-trib') || '', issue: Number(box.getAttribute('data-tpi-num')) || null
          }
        })
      });
    }
    sessao().then(function (s) {
      /* Token vencido (401): renova uma vez com o refresh_token e tenta de novo. */
      return Promise.resolve(gravar(s)).catch(function (err) {
        if (String(err && err.message) !== 'jwt' || !s) throw err;
        sessaoP = renovar(s).catch(function () { return null; });
        return sessaoP.then(gravar);
      });
    }).catch(function (err) {
      if (window.console) console.error('[tp-informativos] falha ao salvar o lido:', err && err.message);
      if (String(err && err.message) === 'jwt') err = new Error('sessao');
      lidos[id] = !novo; pintaTudo();
      if (!box.querySelector('.tpi-lido__erro')) { alert(String(err && err.message) === 'sessao' ? 'Sua sessão expirou. Entre de novo no app e volte aqui.' : 'Não foi possível salvar. Tente de novo.'); return; }
      erro.textContent = String(err && err.message) === 'sessao'
        ? 'Sua sessão expirou. Entre de novo no app e volte aqui.'
        : 'Não foi possível salvar. Tente de novo.' + detalhe(err);
    }).finally(function () { box.removeAttribute('aria-busy'); });
  });
})();

/* 0.7.6: formulário da newsletter (Kit) envia sem sair da página. Sem JS, o POST normal leva à página de sucesso do Kit. */
(function () {
  document.querySelectorAll('form.tpi-nl-form').forEach(function (f) {
    f.addEventListener('submit', function (ev) {
      if (!window.fetch || !window.FormData) return;
      ev.preventDefault();
      var botao = f.querySelector('button');
      var aviso = f.querySelector('.tpi-nl-status');
      if (!aviso) {
        aviso = document.createElement('p');
        aviso.className = 'tpi-nl-status';
        aviso.setAttribute('role', 'status');
        f.appendChild(aviso);
      }
      botao.disabled = true;
      aviso.textContent = 'Enviando...';
      fetch(f.action, { method: 'POST', body: new FormData(f), headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (d && d.status === 'quarantined' && d.url) {
            window.location.href = d.url; // checagem anti-robô do Kit; depois dela a inscrição segue
            return;
          }
          if (d && d.status === 'success') {
            f.querySelector('.tpi-nl-campos').hidden = true;
            aviso.textContent = 'Quase lá! Enviamos um e-mail de confirmação de newsletter@tchaupobreza.com.br. Clique no botão dele para ativar a inscrição. Ele chega em poucos segundos; se não aparecer, olhe as abas Promoções e Spam.';
          } else {
            botao.disabled = false;
            aviso.textContent = (d && d.mensagem) || 'Não deu certo. Confira o e-mail digitado e tente de novo.';
          }
        })
        .catch(function () { f.submit(); });
    });
  });
})();
