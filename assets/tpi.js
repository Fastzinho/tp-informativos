/* TP Informativos 0.7.18: "marcar como lido" com a conta do app (Supabase), sem dependências.
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
  var semSessao = false;
  /* Ícones check e check-check do Lucide, os mesmos do app (Lei seca e Jurisprudência). */
  var SVG = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" ';
  var ICONE_UM = SVG + 'class="i-um" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>';
  var ICONE_DOIS = SVG + 'class="i-dois" stroke-width="2.4"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>';

  function pintaControle(box, lido) {
    box.classList.toggle('is-lido', lido);
    var estado = box.querySelector('.tpi-lido__estado');
    var novoEstado = lido ? (box.getAttribute('data-tpi-tipo') === 'caderno' ? 'Tema marcado como lido.' : 'Julgado marcado como lido.') : '';
    if (estado.textContent !== novoEstado) estado.textContent = novoEstado;
    var botao = box.querySelector('.tpi-lido__botao');
    botao.setAttribute('aria-pressed', String(lido));
    botao.title = semSessao ? 'Marcar como lido: entre na sua conta para salvar o que já leu'
      : (lido ? 'Lido. Clique para desmarcar' : 'Marcar como lido');
    box.querySelector('.tpi-lido__acao').textContent = lido ? 'Lido' : 'Marcar como lido';
  }

  /* Cards da edição: botão ✓ / ✓✓ igual ao do app. Para o visitante sem conta aparece o ✓ cinza
     com a dica de que dá para salvar; o clique abre o convite para entrar, sem gravar nada. */
  function pintaCards() {
    document.querySelectorAll('.tpi-cards--julgados li.tpi-card[data-tpi-id]').forEach(function (li) {
      var lido = !semSessao && !!lidos[li.getAttribute('data-tpi-id')];
      li.classList.toggle('is-lido', lido);
      var b = li.querySelector('.tpi-card__lido');
      if (!b) { /* fica em posição absoluta, num espaço reservado pelo CSS: não empurra o layout (sem CLS) */
        var titulo = li.getAttribute('data-tpi-titulo') || (li.querySelector('.tpi-card__titulo') || {}).textContent || 'este julgado';
        b = document.createElement('button'); b.type = 'button'; b.className = 'tpi-card__lido';
        /* Nome acessível fixo; o estado vai em aria-pressed (botão de alternância, igual ao app). */
        b.setAttribute('aria-label', 'Lido: ' + String(titulo).trim());
        b.innerHTML = ICONE_UM + ICONE_DOIS;
        li.appendChild(b);
      }
      b.setAttribute('aria-pressed', String(lido));
      b.title = semSessao ? 'Marcar como lido: entre na sua conta para salvar o que já leu'
        : (lido ? 'Lido. Clique para desmarcar' : 'Marcar como lido');
    });
  }

  function pintaTudo() {
    document.querySelectorAll('.tpi-lido[data-tpi-id]').forEach(function (box) {
      box.hidden = false;
      pintaControle(box, !!lidos[box.getAttribute('data-tpi-id')]);
    });
    pintaCards();
    document.querySelectorAll('[data-tpi-progresso]').forEach(function (p) {
      var ids = p.getAttribute('data-tpi-progresso').split(',').filter(Boolean);
      var n = ids.filter(function (i) { return lidos[i]; }).length;
      p.querySelector('.tpi-progresso__barra span').style.width = (ids.length ? Math.round(100 * n / ids.length) : 0) + '%';
      p.querySelector('.tpi-progresso__texto').innerHTML = 'Você leu <strong>' + n + ' de ' + ids.length + '</strong> ' + (p.getAttribute('data-tpi-nome') || 'julgados desta edição') + '.';
      p.hidden = false;
    });
    /* 0.8.0: sumário dos cadernos. ✓✓ verde ao lado do tema lido (o item é link para a âncora, não botão). */
    document.querySelectorAll('a[data-tpc-lido]').forEach(function (a) {
      var lido = !!lidos[a.getAttribute('data-tpc-lido')];
      a.classList.toggle('is-lido', lido);
      var el = a.querySelector('.tpc-sumario__lido');
      if (el) { el.innerHTML = lido ? ICONE_DOIS + '<span class="tpi-sr"> Lido</span>' : ''; el.hidden = !lido; }
    });
    document.querySelectorAll('.tpi-edicao[data-tpi-ids]').forEach(function (li) {
      var ids = li.getAttribute('data-tpi-ids').split(',').filter(Boolean);
      var n = ids.filter(function (i) { return lidos[i]; }).length;
      var todos = ids.length && n === ids.length;
      li.classList.toggle('is-lido', !!todos);
      var el = li.querySelector('.tpi-edicao__lidos');
      /* Indicador (não é botão: a linha toda é link). Todos lidos = ✓✓ verde; parcial = "3/10" + ✓ cinza; nenhum = ✓ cinza. */
      if (el) {
        el.innerHTML = (todos ? ICONE_DOIS : ICONE_UM)
          + (todos || !n ? '' : '<span class="tpi-edicao__conta" aria-hidden="true">' + n + '/' + ids.length + '</span>')
          + '<span class="tpi-sr">' + (todos ? ' Lido' : ' ' + n + ' de ' + ids.length + ' julgados lidos') + '</span>';
        el.hidden = false;
      }
    });
  }

  /* Sem conta: o botão aparece igual para todos (o visitante descobre que dá para salvar);
     ao clicar, em vez de gravar, abre o convite para entrar no app. */
  function semConta() {
    semSessao = true;
    document.querySelectorAll('.tpi-lido[data-tpi-id]').forEach(function (box) { box.hidden = false; pintaControle(box, false); });
    pintaCards();
  }

  function carregar(s) {
    var ler = function (s) { return api(s, 'tp_records?select=id&kind=eq.' + KIND + '&limit=5000'); };
    return ler(s).catch(function (err) {
      if (String(err && err.message) !== 'jwt') throw err;
      sessaoP = renovar(s).catch(function () { return null; });
      return sessaoP.then(function (n) { if (!n) throw err; return ler(n); });
    }).then(function (linhas) {
      (linhas || []).forEach(function (l) { lidos[l.id.replace(KIND + ':', '')] = true; });
      pintaTudo();
      concluiPendente();
    }).catch(function () { pintaTudo(); });
  }
  sessao().then(function (s) { return s ? carregar(s) : semConta(); });

  /* 0.7.16: o visitante clicou em "Marcar como lido", entrou no app e voltou (o app lê ?voltar=):
     completa a marcação que ele pediu, uma vez só, se ainda estiver valendo (30 min). */
  var PENDENTE = 'tpi-marcar-pendente';
  function guardaPendente(id) {
    try { localStorage.setItem(PENDENTE, JSON.stringify({ id: id, at: Date.now() })); } catch (e) {}
  }
  function concluiPendente() {
    var p = null;
    try { p = JSON.parse(localStorage.getItem(PENDENTE) || 'null'); localStorage.removeItem(PENDENTE); } catch (e) { return; }
    if (!p || !p.id || Date.now() - p.at > 30 * 60 * 1000 || lidos[p.id]) return;
    var box = document.querySelector('[data-tpi-id="' + String(p.id).replace(/[^0-9]/g, '') + '"]');
    var botao = box && box.querySelector('.tpi-lido__botao, .tpi-card__lido');
    if (botao) botao.click();
  }
  /* Link do convite com o endereço desta página, para o app trazer a pessoa de volta depois do login. */
  function linkDeVolta(a) {
    try {
      var u = new URL(a.getAttribute('href'), location.href);
      u.searchParams.set('voltar', location.pathname + location.search);
      a.href = u.href;
    } catch (e) {}
  }

  /* 0.7.17: login no próprio informativo (modal), sem mandar a pessoa para o app.
     Mesma conta e mesma sessão do app: a resposta do Supabase Auth é gravada na mesma chave do
     localStorage que o supabase-js do app usa. Nada disto (nem o script do Google) carrega antes
     de a pessoa abrir o modal. */
  var dlg = null, voltaFoco = null, emailEnviado = '';
  function auth(caminho, corpo) {
    return fetch(C.url + '/auth/v1/' + caminho, {
      method: 'POST', headers: { apikey: C.key, 'Content-Type': 'application/json' }, body: JSON.stringify(corpo)
    }).then(function (r) {
      return r.text().then(function (t) {
        var d = null; try { d = t ? JSON.parse(t) : null; } catch (e) {}
        if (!r.ok) { var err = new Error(msgErro(r.status, d)); err.status = r.status; throw err; }
        return d;
      });
    });
  }
  function msgErro(status, d) {
    var m = String((d && (d.msg || d.error_description || d.message || d.error_code)) || '').toLowerCase();
    if (status === 429 || /rate|security purposes/.test(m)) return 'Muitas tentativas seguidas. Espere um minuto e tente de novo.';
    if (/expired|invalid|otp/.test(m) && /token|otp|code/.test(m)) return 'Código inválido ou vencido. Confira o e-mail ou peça outro.';
    if (/signup|signups/.test(m)) return 'Não encontramos uma conta com este e-mail.';
    if (/email/.test(m) && /invalid|valid/.test(m)) return 'Confira o e-mail digitado.';
    return 'Não foi possível entrar agora. Tente de novo.';
  }
  function entrou(s) {
    if (!s || !s.access_token || !s.user) throw new Error('Não foi possível entrar agora. Tente de novo.');
    if (!s.expires_at && s.expires_in) s.expires_at = Math.floor(Date.now() / 1000) + s.expires_in;
    try { localStorage.setItem(CHAVE, JSON.stringify(s)); } catch (e) {}
    try { localStorage.removeItem('tp-voltar'); } catch (e) {}
    medeCadastro(s.user);
    sessaoP = Promise.resolve(s); semSessao = false;
    fechaLogin();
    var t = document.createElement('p');
    t.className = 'tpi-login-ok'; t.setAttribute('role', 'status');
    t.textContent = 'Pronto, você entrou. Seu progresso fica salvo na sua conta.';
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 5000);
    carregar(s);
  }
  /* Mesma conversão do app (registration_completed, uma vez por conta, só com consentimento):
     a marca no localStorage é a mesma, então o app não conta de novo. */
  function medeCadastro(u) {
    try {
      if (!u || !u.id || u.is_anonymous) return;
      var marca = 'tp_oaiq_cadastro:' + u.id;
      if (localStorage.getItem(marca)) return;
      var ids = (u.identities || []).filter(function (i) { return i.provider && i.provider !== 'anonymous'; })
        .map(function (i) { return Date.parse(i.created_at || ''); }).filter(function (n) { return !isNaN(n); });
      var criada = ids.length ? Math.min.apply(null, ids) : Date.parse(u.created_at || '');
      var d = Date.now() - criada;
      if (isNaN(criada) || d < -300000 || d > 3600000) return;
      if ((window.tpiCookies ? window.tpiCookies.ok : localStorage.getItem('tpi_cookies') === 'sim') && typeof window.oaiq === 'function') { // 0.7.22: segue a regra do site (sem aviso = mede até a pessoa recusar)
        window.oaiq('measure', 'registration_completed', { type: 'customer_action' });
        localStorage.setItem(marca, 'enviado');
      } else localStorage.setItem(marca, 'pendente');
    } catch (e) {}
  }
  function aviso(txt, info) { var a = dlg.querySelector('.tpi-login__aviso'); a.textContent = txt || ''; a.classList.toggle('is-info', !!info); }
  function ocupado(form, sim) {
    var b = form.querySelector('button[type=submit]');
    b.disabled = sim; if (sim) { b.setAttribute('data-txt', b.textContent); b.textContent = 'Aguarde…'; } else if (b.getAttribute('data-txt')) b.textContent = b.getAttribute('data-txt');
  }
  function passo(qual) {
    dlg.querySelector('.tpi-login__email').hidden = qual !== 'email';
    dlg.querySelector('.tpi-login__codigo').hidden = qual !== 'codigo';
    var g = dlg.querySelector('.tpi-login__google'); if (g) g.hidden = qual !== 'email';
    aviso('');
    var campo = dlg.querySelector(qual === 'email' ? 'input[type=email]' : 'input[name=codigo]');
    if (campo) setTimeout(function () { campo.focus(); }, 0);
  }
  var gsi = null;
  function botaoGoogle(alvo) {
    if (!C.google || !window.crypto || !crypto.subtle) { var ou = alvo.nextElementSibling; if (ou) ou.remove(); alvo.remove(); return; }
    gsi = gsi || new Promise(function (ok, falha) {
      if (window.google && google.accounts && google.accounts.id) return ok();
      var sc = document.createElement('script'); sc.src = 'https://accounts.google.com/gsi/client'; sc.async = true;
      sc.onload = ok; sc.onerror = function () { gsi = null; falha(); }; document.head.appendChild(sc);
    });
    var bruto = btoa(String.fromCharCode.apply(null, crypto.getRandomValues(new Uint8Array(32))));
    Promise.all([gsi, crypto.subtle.digest('SHA-256', new TextEncoder().encode(bruto))]).then(function (r) {
      var hash = Array.prototype.map.call(new Uint8Array(r[1]), function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
      var g = window.google.accounts.id;
      g.initialize({ client_id: C.google, nonce: hash, ux_mode: 'popup', use_fedcm_for_button: true, callback: function (resp) {
        aviso('Entrando…', true);
        auth('token?grant_type=id_token', { provider: 'google', id_token: resp.credential, nonce: bruto }).then(entrou).catch(function (e) { aviso(e.message); });
      } });
      g.renderButton(alvo, { type: 'standard', theme: 'outline', size: 'large', text: 'continue_with', shape: 'rectangular', logo_alignment: 'center', locale: 'pt-BR', width: Math.min(360, alvo.clientWidth || 300) });
    }).catch(function () { alvo.innerHTML = '<p class="tpi-login__nota">O botão do Google não carregou. Use o e-mail abaixo.</p>'; });
  }
  function montaLogin() {
    dlg = document.createElement('dialog');
    dlg.className = 'tpi-login';
    dlg.setAttribute('aria-labelledby', 'tpi-login-titulo');
    dlg.innerHTML = '<button type="button" class="tpi-login__fechar" aria-label="Fechar">×</button>'
      + '<h2 id="tpi-login-titulo">Salve seu progresso. É grátis.</h2>'
      + '<p class="tpi-login__sub">Entre para marcar ' + (document.querySelector('.tpc') ? 'este tema' : 'este julgado') + ' como lido. Você continua nesta página, e ele fica guardado com o resto dos seus estudos, em qualquer aparelho.</p>'
      + (C.google ? '<div class="tpi-login__google"></div><p class="tpi-login__ou"><span>ou</span></p>' : '')
      + '<form class="tpi-login__email"><label>Seu e-mail<input type="email" name="email" autocomplete="email" required></label>'
      + '<button type="submit" class="tpi-login__principal">Receber código por e-mail</button>'
      + '<p class="tpi-login__nota">Sem senha.' + (C.signup ? ' Se for sua primeira vez, sua conta é criada na hora.' : '') + '</p></form>'
      + '<form class="tpi-login__codigo" hidden><p class="tpi-login__nota tpi-login__enviado"></p>'
      + '<label>Código<input name="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="10" required></label>'
      + '<button type="submit" class="tpi-login__principal">Entrar</button>'
      + '<button type="button" class="tpi-login__link tpi-login__outro">Usar outro e-mail</button></form>'
      + '<p class="tpi-login__aviso" role="status"></p>'
      + '<p class="tpi-login__nota tpi-login__rodape">Mesma conta do app Tchau Pobreza. <a href="' + (C.privacidade || '/privacidade/') + '" target="_blank" rel="noopener">Privacidade</a></p>';
    document.body.appendChild(dlg);
    dlg.querySelector('.tpi-login__fechar').addEventListener('click', fechaLogin);
    dlg.addEventListener('click', function (e) { if (e.target === dlg) fechaLogin(); }); /* clique fora */
    dlg.addEventListener('close', function () {
      /* Desistiu antes de pedir o código: não deixa o julgado pendente para uma volta futura. */
      if (semSessao && !emailEnviado) { try { localStorage.removeItem(PENDENTE); } catch (x) {} }
      if (voltaFoco) voltaFoco.focus();
    });
    dlg.querySelector('.tpi-login__outro').addEventListener('click', function () { passo('email'); });
    dlg.querySelector('.tpi-login__email').addEventListener('submit', function (e) {
      e.preventDefault(); var f = e.currentTarget, email = f.email.value.trim();
      ocupado(f, true); aviso('');
      /* Se a pessoa usar o link do e-mail em vez do código, o app abre e devolve para cá. */
      try { localStorage.setItem('tp-voltar', JSON.stringify({ url: location.origin + location.pathname + location.search, at: Date.now() })); } catch (x) {}
      var dest = C.appUrl ? '?redirect_to=' + encodeURIComponent(C.appUrl) : '';
      auth('otp' + dest, { email: email, create_user: !!C.signup }).then(function () {
        emailEnviado = email;
        dlg.querySelector('.tpi-login__enviado').innerHTML = 'Enviamos um código para <strong></strong>. Digite aqui, ou clique no link do e-mail: você volta para este julgado.';
        dlg.querySelector('.tpi-login__enviado strong').textContent = email;
        passo('codigo');
      }).catch(function (x) { aviso(x.message); }).finally(function () { ocupado(f, false); });
    });
    dlg.querySelector('.tpi-login__codigo').addEventListener('submit', function (e) {
      e.preventDefault(); var f = e.currentTarget, token = f.codigo.value.replace(/\D/g, '');
      ocupado(f, true); aviso('');
      auth('verify', { type: 'email', email: emailEnviado, token: token }).then(entrou)
        .catch(function (x) { aviso(x.message); }).finally(function () { ocupado(f, false); });
    });
    var g = dlg.querySelector('.tpi-login__google'); if (g) botaoGoogle(g);
  }
  function abreLogin(origem) {
    if (typeof HTMLDialogElement !== 'function' || !window.fetch) return false;
    if (!dlg) montaLogin();
    voltaFoco = origem;
    if (!dlg.open) { dlg.showModal(); passo(dlg.querySelector('.tpi-login__codigo').hidden ? 'email' : 'codigo'); }
    return true;
  }
  function fechaLogin() { if (dlg && dlg.open) dlg.close(); }

  document.addEventListener('click', function (e) {
    var botao = e.target.closest('.tpi-lido__botao, .tpi-card__lido');
    if (!botao) return;
    var box = botao.closest('[data-tpi-id]');
    if (box.getAttribute('aria-busy') === 'true') return;
    if (semSessao) {
      guardaPendente(box.getAttribute('data-tpi-id'));
      if (abreLogin(botao)) return;
      /* Sem <dialog> (navegador antigo): convite com link para o app, que traz de volta. */
      var convite = box.querySelector('.tpi-lido__convite');
      if (!convite && box.classList.contains('tpi-card')) { /* card: cria o convite na hora, no fim do card */
        convite = document.createElement('p'); convite.className = 'tpi-lido__convite tpi-card__convite';
        convite.innerHTML = 'Para salvar o que já leu, <a href="' + C.appUrl + '">entre na sua conta do Tchau Pobreza</a>. Fica guardado junto com o resto dos seus estudos, em qualquer dispositivo.';
        box.appendChild(convite);
      }
      if (convite) { convite.hidden = false; var a = convite.querySelector('a'); if (a) { linkDeVolta(a); a.focus(); } }
      return;
    }
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
            court: box.getAttribute('data-tpi-trib') || '', issue: Number(box.getAttribute('data-tpi-num')) || null,
            tipo: box.getAttribute('data-tpi-tipo') || 'julgado'
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
            document.dispatchEvent(new CustomEvent('tpi:newsletter-inscrito', { detail: { origem: f.getAttribute('data-origem') || '' } })); // 0.7.13: conversão (includes/medicao.php)
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

/* 0.7.19: filtro por tópico na página do ramo. Sem JavaScript, os chips são âncoras para cada grupo. */
(function () {
  var nav = document.querySelector('.tpi-topicos');
  if (!nav) return;
  var status = document.getElementById('tpi-topicos-status');
  nav.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-tpi-topico]');
    if (!a) return;
    e.preventDefault();
    var alvo = a.getAttribute('data-tpi-topico');
    nav.querySelectorAll('a[data-tpi-topico]').forEach(function (x) {
      if (x === a) x.setAttribute('aria-current', 'true'); else x.removeAttribute('aria-current');
    });
    var n = 0;
    document.querySelectorAll('.tpi-topico[data-tpi-topico]').forEach(function (s) {
      var ver = !alvo || s.getAttribute('data-tpi-topico') === alvo;
      s.hidden = !ver;
      if (ver) n += s.querySelectorAll('li.tpi-card').length;
    });
    if (status) status.textContent = alvo ? 'Mostrando ' + n + (n === 1 ? ' julgado' : ' julgados') + ' de ' + a.firstChild.textContent.trim() + '.' : 'Mostrando todos os julgados.';
  });
})();

/* 0.8.0: cadernos. Busca no sumário e pegadinha Certo/Errado. Sem JavaScript, o sumário são âncoras e o
   gabarito abre em <details>. */
(function () {
  var busca = document.getElementById('tpc-busca');
  if (busca) {
    var vazio = document.querySelector('.tpc-sumario__vazio');
    var norm = function (s) { return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); };
    busca.addEventListener('input', function () {
      var q = norm(busca.value.trim()), n = 0;
      document.querySelectorAll('.tpc-sumario__lista li').forEach(function (li) {
        var a = li.querySelector('a[data-tpc-busca]');
        if (!a) { li.hidden = !!q; return; } /* rótulo de grupo some durante a busca */
        var ok = !q || norm(a.getAttribute('data-tpc-busca')).indexOf(q) > -1;
        li.hidden = !ok; if (ok) n++;
      });
      if (vazio) vazio.hidden = n > 0;
    });
  }
  document.querySelectorAll('.tpc-ce[data-gab]').forEach(function (box) {
    var det = box.querySelector('details');
    if (!det) return;
    var gab = box.getAttribute('data-gab');
    var opcoes = document.createElement('div');
    opcoes.className = 'tpc-ce__opcoes';
    opcoes.setAttribute('role', 'group');
    opcoes.setAttribute('aria-label', 'Sua resposta');
    var veredito = document.createElement('p');
    veredito.className = 'tpc-ce__veredito'; veredito.setAttribute('role', 'status');
    [['C', 'Certo'], ['E', 'Errado']].forEach(function (o) {
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'tpc-ce__opcao'; b.textContent = o[1];
      b.addEventListener('click', function () {
        var ok = o[0] === gab;
        opcoes.querySelectorAll('button').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
        veredito.textContent = ok ? 'Você acertou.' : 'Não foi dessa vez.';
        veredito.className = 'tpc-ce__veredito ' + (ok ? 'is-ok' : 'is-erro');
        det.open = true;
      });
      opcoes.appendChild(b);
    });
    box.insertBefore(opcoes, det);
    box.insertBefore(veredito, det);
  });
})();
