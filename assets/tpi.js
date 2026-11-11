/* TP Informativos 0.10.0: "marcar como lido" com a conta do app (Supabase), sem dependências.
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

  /* 0.11.4: filtro do caderno pela leitura (Todos / Não lidos / Lidos), no mesmo desenho do app (Jurisprudência
     e Lei seca). Só para quem tem conta e já leu algum tema; é só visual: o HTML segue com todos os temas. */
  var CH_FILTRO = 'tpc-filtro-leitura';
  var filtro = 'all';
  try { filtro = localStorage.getItem(CH_FILTRO) || 'all'; } catch (e) {}
  function pintaFiltro() {
    var prog = document.querySelector('.tpc-cabeca [data-tpi-progresso], .tpc [data-tpi-progresso]');
    var cards = document.querySelectorAll('.tpc-temas > .tpc-tema');
    if (!prog || !cards.length) return;
    var ids = prog.getAttribute('data-tpi-progresso').split(',').filter(Boolean);
    var n = ids.filter(function (i) { return lidos[i]; }).length;
    var ativo = !semSessao && n > 0;
    var caixa = document.querySelector('.tpc-filtro');
    if (!caixa && ativo) {
      caixa = document.createElement('div'); caixa.className = 'tpc-filtro'; caixa.setAttribute('role', 'radiogroup'); caixa.setAttribute('aria-label', 'Filtrar pela leitura');
      [['all', 'Todos', ''], ['unread', 'Não lidos', ICONE_UM], ['read', 'Lidos', ICONE_DOIS]].forEach(function (o) {
        var b = document.createElement('button'); b.type = 'button'; b.setAttribute('role', 'radio'); b.setAttribute('data-f', o[0]);
        b.className = 'tpc-filtro__botao' + (o[2] ? '' : ' tpc-filtro__todos');
        b.innerHTML = o[2] || '<span>' + o[1] + '</span>';
        b.setAttribute('data-rotulo', o[1]);
        b.addEventListener('click', function () {
          filtro = filtro === o[0] ? 'all' : o[0]; /* tocar de novo no ativo volta para Todos, como no app */
          try { localStorage.setItem(CH_FILTRO, filtro); } catch (e) {}
          pintaFiltro();
        });
        caixa.appendChild(b);
      });
      prog.insertAdjacentElement('afterend', caixa);
    }
    if (caixa) caixa.hidden = !ativo;
    var f = ativo ? filtro : 'all';
    var conta = { all: ids.length, unread: ids.length - n, read: n };
    if (caixa) caixa.querySelectorAll('button').forEach(function (b) {
      var k = b.getAttribute('data-f'), on = f === k, q = conta[k];
      b.classList.toggle('on', on); b.classList.toggle('zero', !q && k !== 'all');
      b.setAttribute('aria-checked', String(on));
      b.setAttribute('aria-label', b.getAttribute('data-rotulo') + ' (' + q + ')');
      b.title = b.getAttribute('data-rotulo') + ' · ' + q + (q === 1 ? ' tema' : ' temas') + (on && k !== 'all' ? '. Toque de novo para ver todos' : '');
    });
    cards.forEach(function (art) {
      var box = art.querySelector('.tpc-lido[data-tpi-id]');
      var lido = !!(box && lidos[box.getAttribute('data-tpi-id')]);
      var some = (f === 'read' && !lido) || (f === 'unread' && lido);
      art.hidden = some;
      var a = document.querySelector('.tpc-sumario a[href="#' + art.id + '"]');
      if (a && a.parentNode) a.parentNode.classList.toggle('tpc-filtrado', some);
    });
  }
  /* Link para um tema escondido pelo filtro (sumário, informativo, Google): volta para Todos. */
  function mostraAlvo() {
    var alvo = location.hash && document.getElementById(decodeURIComponent(location.hash.slice(1)));
    if (alvo && alvo.hidden && alvo.classList.contains('tpc-tema')) {
      filtro = 'all'; try { localStorage.setItem(CH_FILTRO, filtro); } catch (e) {}
      pintaFiltro(); alvo.scrollIntoView();
    }
  }
  window.addEventListener('hashchange', mostraAlvo);

  function pintaTudo() {
    document.querySelectorAll('.tpi-lido[data-tpi-id]').forEach(function (box) {
      box.hidden = false;
      pintaControle(box, !!lidos[box.getAttribute('data-tpi-id')]);
    });
    pintaCards();
    pintaFiltro(); mostraAlvo();
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
    mostraApp();
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
      return checaApp(s);
    }).catch(function () { pintaTudo(); mostraApp(); });
  }
  sessao().then(function (s) { pintaConta(s); return s ? carregar(s) : semConta(); });
  /* 0.9.1: conteúdo inserido depois (prévia do caderno) pede para repintar os marcadores de lido. */
  document.addEventListener('tpi:repintar', function () {
    sessao().then(function (s) { if (s && !semSessao) pintaTudo(); else semConta(); });
  });

  /* 0.10.0: a pessoa vê se entrou. Três estados, decididos aqui (o HTML é o mesmo para todos, fica em cache
     e é o que o Google vê): sem conta, visitante (sessão anônima do app: salva só neste aparelho) e com conta.
     - Topo: o cabeçalho é do tema (editor do site), achado pelo botão "Estudar" (a.tp-nav-cta). Se ele sumir,
       só o aviso perto das ações continua.
     - Aviso perto das ações (lido / flashcards / sumário do caderno), um por página. */
  var estadoConta = 'fora';
  function nomeDe(u) {
    var m = (u && u.user_metadata) || {};
    var n = String(m.full_name || m.name || m.given_name || '').trim().split(/\s+/)[0];
    return n || String((u && u.email) || '').split('@')[0] || 'você';
  }
  function mascara(email) {
    var p = String(email || '').split('@');
    return p.length === 2 ? p[0].slice(0, 2) + '•••@' + p[1] : '';
  }
  function linkAppDeVolta() { var a = document.createElement('a'); a.href = C.appUrl; linkDeVolta(a); return a.href; }
  function pintaConta(s) {
    estadoConta = !s ? 'fora' : (s.user && s.user.is_anonymous ? 'visitante' : 'conta');
    document.documentElement.setAttribute('data-tpi-conta', estadoConta);
    try { pintaTopo(s); } catch (e) {}
    try { pintaStatus(s); } catch (e) {}
  }
  function pintaTopo(s) {
    document.querySelectorAll('a.tp-nav-cta').forEach(function (cta) {
      var pai = cta.parentNode;
      pai.querySelectorAll('.tpi-nav-entrar, .tpi-nav-conta').forEach(function (x) { x.remove(); });
      if (!cta.getAttribute('data-tpi-txt')) cta.setAttribute('data-tpi-txt', cta.innerHTML);
      cta.innerHTML = cta.getAttribute('data-tpi-txt');
      if (estadoConta === 'fora') {
        var e = document.createElement('a');
        e.className = 'tpi-nav-entrar'; e.href = linkAppDeVolta(); e.textContent = 'Entrar';
        e.addEventListener('click', function (ev) { if (abreLogin(e, { geral: true })) ev.preventDefault(); });
        /* mesmo tamanho de letra e altura do "Estudar" do tema, seja qual for o estilo do cabeçalho */
        var cs = getComputedStyle(cta);
        e.style.fontSize = cs.fontSize; e.style.fontFamily = cs.fontFamily; e.style.minHeight = cta.offsetHeight + 'px';
        pai.insertBefore(e, cta);
        return;
      }
      /* Com sessão: "Continuar estudando" (no celular fica "Estudar") e o botão da conta ao lado. */
      var t = Array.prototype.filter.call(cta.childNodes, function (n) { return n.nodeType === 3 && n.textContent.trim(); })[0];
      if (t) {
        var longo = document.createElement('span'); longo.className = 'tpi-cta-longo'; longo.textContent = 'Continuar estudando';
        var curto = document.createElement('span'); curto.className = 'tpi-cta-curto'; curto.textContent = t.textContent.trim();
        cta.insertBefore(longo, t); cta.insertBefore(curto, t); cta.removeChild(t);
      }
      var mc = menuConta(s), bt = mc.querySelector('.tpi-nav-conta__botao');
      bt.style.fontFamily = getComputedStyle(cta).fontFamily;
      pai.insertBefore(mc, cta);
    });
  }
  function menuConta(s) {
    var u = s.user, visitante = estadoConta === 'visitante';
    var box = document.createElement('div'); box.className = 'tpi-nav-conta';
    var b = document.createElement('button'); b.type = 'button'; b.className = 'tpi-nav-conta__botao' + (visitante ? ' is-visitante' : '');
    b.setAttribute('aria-expanded', 'false'); b.setAttribute('aria-haspopup', 'true');
    if (visitante) {
      b.innerHTML = '<span class="tpi-nav-conta__ponto" aria-hidden="true"></span><span class="tpi-nav-conta__rotulo">Visitante</span>';
      b.setAttribute('aria-label', 'Modo visitante: ver opções');
    } else {
      b.textContent = nomeDe(u).charAt(0).toUpperCase();
      b.setAttribute('aria-label', 'Sua conta: ' + nomeDe(u));
    }
    var m = document.createElement('div'); m.className = 'tpi-nav-conta__menu'; m.hidden = true;
    var topo = document.createElement('p'); topo.className = 'tpi-nav-conta__quem';
    var forte = document.createElement('strong');
    forte.textContent = visitante ? 'Modo visitante' : nomeDe(u);
    topo.appendChild(forte);
    topo.appendChild(document.createTextNode(visitante ? 'Seu progresso fica só neste aparelho.' : mascara(u.email)));
    m.appendChild(topo);
    var item = function (txt, href) { var a = document.createElement('a'); a.textContent = txt; a.href = href; m.appendChild(a); return a; };
    if (visitante) item('Criar conta grátis', linkAppDeVolta()).className = 'is-destaque';
    item('Abrir meu estudo', C.appUrl);
    item('Meus flashcards', linkApp());
    if (!visitante) {
      var sair = document.createElement('button'); sair.type = 'button'; sair.textContent = 'Sair';
      sair.addEventListener('click', function () { sairDaConta(s); });
      m.appendChild(sair);
    }
    var fecha = function () { m.hidden = true; b.setAttribute('aria-expanded', 'false'); };
    b.addEventListener('click', function (ev) {
      var abrir = m.hidden; m.hidden = !abrir; b.setAttribute('aria-expanded', String(abrir));
      /* Foco no 1º item só pelo teclado (detail 0); com mouse ou dedo, nada fica com contorno. */
      if (abrir && !ev.detail) { var p = m.querySelector('a, button'); if (p) p.focus(); }
    });
    document.addEventListener('click', function (e) { if (!box.contains(e.target)) fecha(); });
    box.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !m.hidden) { fecha(); b.focus(); } });
    box.appendChild(b); box.appendChild(m);
    return box;
  }
  /* Sair aqui sai também do app: os dois guardam a sessão na mesma chave deste domínio. */
  function sairDaConta(s) {
    var fim = function () { try { localStorage.removeItem(CHAVE); } catch (e) {} location.reload(); };
    fetch(C.url + '/auth/v1/logout?scope=local', { method: 'POST', headers: { apikey: C.key, Authorization: 'Bearer ' + s.access_token } })
      .then(fim, fim);
  }
  function pintaStatus(s) {
    var velho = document.querySelector('.tpi-conta-status'); if (velho) velho.remove();
    var alvo = document.querySelector('.tpi-app[data-tpi-ids]') || document.querySelector('[data-tpi-progresso]')
      || document.querySelector('.tpc-sumario') || document.querySelector('.tpi-lido[data-tpi-id]:not(.tpi-lido--topo)');
    if (!alvo) return;
    var p = document.createElement('p'); p.className = 'tpi-conta-status is-' + estadoConta;
    var txt = document.createElement('span');
    var ponto = '<span class="tpi-conta-status__ponto" aria-hidden="true"></span>';
    var comCards = !!document.querySelector('.tpi-app[data-tpi-ids]');
    if (estadoConta === 'conta') {
      txt.appendChild(document.createTextNode('Conectado como '));
      var n = document.createElement('strong'); n.textContent = nomeDe(s.user); txt.appendChild(n);
      txt.appendChild(document.createTextNode(comCards ? '. O que você ler e os flashcards vão para a sua conta.' : '. O que você ler fica salvo na sua conta.'));
      p.innerHTML = ponto; p.appendChild(txt);
    } else {
      txt.textContent = estadoConta === 'visitante' ? 'Modo visitante: seu progresso fica só neste aparelho.'
        : (comCards ? 'Você não entrou. O que você ler e os flashcards não ficam salvos.' : 'Você não entrou. O que você ler não fica salvo.');
      p.innerHTML = ponto; p.appendChild(txt);
      var a = document.createElement('a'); a.href = linkAppDeVolta();
      a.textContent = estadoConta === 'visitante' ? 'Criar conta grátis' : 'Entrar';
      if (estadoConta === 'fora') a.addEventListener('click', function (ev) { if (abreLogin(a, { geral: true })) ev.preventDefault(); });
      p.appendChild(a);
    }
    alvo.parentNode.insertBefore(p, alvo);
  }

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
    pintaConta(s);
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
      + '<h2 id="tpi-login-titulo"></h2>'
      + '<p class="tpi-login__sub"></p>'
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
      if (semSessao && !emailEnviado) { try { localStorage.removeItem(PENDENTE); localStorage.removeItem(APP_PENDENTE); } catch (x) {} }
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
  function abreLogin(origem, motivo) {
    if (typeof HTMLDialogElement !== 'function' || !window.fetch) return false;
    if (!dlg) montaLogin();
    voltaFoco = origem;
    /* 0.9.0: o texto do convite segue o que a pessoa clicou (marcar lido ou levar os flashcards). */
    var n = motivo && motivo.cards;
    dlg.querySelector('#tpi-login-titulo').textContent = n ? 'Guarde ' + (n === 1 ? 'este flashcard' : 'estes ' + n + ' flashcards') + '. É grátis.' : 'Salve seu progresso. É grátis.';
    dlg.querySelector('.tpi-login__sub').textContent = motivo && motivo.geral
      ? 'Entre para guardar o que você ler e levar os flashcards ao app, com revisão no dia certo. Você continua nesta página.'
      : n
      ? 'Entre e eles vão direto para os seus Flashcards no app, com revisão espaçada: cada um volta no dia certo para você não esquecer. Você continua nesta página.'
      : 'Entre para marcar ' + (document.querySelector('.tpc') ? 'este tema' : 'este julgado') + ' como lido. Você continua nesta página, e ele fica guardado com o resto dos seus estudos, em qualquer aparelho.';
    if (!dlg.open) { dlg.showModal(); passo(dlg.querySelector('.tpi-login__codigo').hidden ? 'email' : 'codigo'); }
    return true;
  }
  function fechaLogin() { if (dlg && dlg.open) dlg.close(); }

  /* 0.9.0: "Adicionar ao app". Grava os flashcards do julgado (ou da edição) direto em tp_records, kind
     "flashcard", no formato que o app lê (src/platform/api.ts, cardData). O id de cada card vem pronto do PHP
     (fixo por julgado + frente): ON CONFLICT DO NOTHING faz a segunda importação só completar o que falta,
     sem duplicar nem zerar o progresso de quem já revisou. Só os ids ficam no HTML; o texto vem do REST no clique. */
  var APP_PENDENTE = 'tpi-importar-pendente';
  var linkApp = function () { try { var u = new URL(C.appUrl, location.href); u.searchParams.set('aba', 'flashcards'); return u.href; } catch (e) { return C.appUrl; } };
  function mostraApp() { document.querySelectorAll('.tpi-app[data-tpi-ids]').forEach(function (b) { b.hidden = false; }); }
  function idsDe(box) { return (box.getAttribute('data-tpi-ids') || '').split(',').filter(Boolean); }
  function pintaApp(box, tem, novos) {
    var n = idsDe(box).length, botao = box.querySelector('.tpi-app__botao'), estado = box.querySelector('.tpi-app__estado');
    box.hidden = false;
    var todos = tem >= n;
    box.classList.toggle('is-feito', todos);
    botao.textContent = todos ? (n === 1 ? 'Este flashcard já está no app' : 'Estes ' + n + ' flashcards já estão no app')
      : (tem ? 'Adicionar os ' + (n - tem) + ' que faltam ao app' : (n === 1 ? 'Adicionar este flashcard ao app' : 'Adicionar estes ' + n + ' flashcards ao app'));
    botao.disabled = todos;
    if (novos === undefined && !tem) { estado.textContent = ''; return; }
    var txt = novos === undefined ? (todos ? '' : tem + ' de ' + n + ' já estão nos seus Flashcards. ')
      : novos ? (novos === 1 ? '1 flashcard adicionado' : novos + ' flashcards adicionados') + ' aos seus Flashcards. '
      : 'Você já tinha estes flashcards. ';
    estado.innerHTML = '';
    estado.appendChild(document.createTextNode(txt));
    var a = document.createElement('a'); a.href = linkApp(); a.textContent = 'Revisar no app'; estado.appendChild(a);
  }
  function checaApp(s) {
    var caixas = document.querySelectorAll('.tpi-app[data-tpi-ids]');
    if (!caixas.length) return;
    var todos = [];
    caixas.forEach(function (b) { todos = todos.concat(idsDe(b)); });
    var ler = function (s) { return api(s, 'tp_records?select=id&kind=eq.flashcard&id=in.(' + todos.map(encodeURIComponent).join(',') + ')'); };
    return ler(s).catch(function (err) { /* token vencido numa aba antiga: renova uma vez */
      if (String(err && err.message) !== 'jwt') throw err;
      sessaoP = renovar(s).catch(function () { return null; });
      return sessaoP.then(function (n) { if (!n) throw err; return ler(n); });
    }).then(function (linhas) {
      var tem = {}; (linhas || []).forEach(function (l) { tem[l.id] = true; });
      caixas.forEach(function (b) { pintaApp(b, idsDe(b).filter(function (i) { return tem[i]; }).length); });
      concluiApp();
    }).catch(function () { mostraApp(); });
  }
  function concluiApp() {
    var p = null;
    try { p = JSON.parse(localStorage.getItem(APP_PENDENTE) || 'null'); localStorage.removeItem(APP_PENDENTE); } catch (e) { return; }
    if (!p || !p.url || Date.now() - p.at > 30 * 60 * 1000) return;
    var box = null;
    document.querySelectorAll('.tpi-app[data-tpi-cards]').forEach(function (b) { if (b.getAttribute('data-tpi-cards') === p.url) box = b; });
    var botao = box && box.querySelector('.tpi-app__botao');
    if (botao && !botao.disabled) botao.click();
  }
  function srsNovo() { /* createEmptyCard do ts-fsrs, como o newSrs() do app */
    return { due: new Date().toISOString(), stability: 0, difficulty: 0, elapsed_days: 0, scheduled_days: 0, learning_steps: 0, reps: 0, lapses: 0, state: 0 };
  }
  function importa(box) {
    var botao = box.querySelector('.tpi-app__botao'), estado = box.querySelector('.tpi-app__estado');
    if (box.getAttribute('aria-busy') === 'true') return;
    if (semSessao) {
      try { localStorage.setItem(APP_PENDENTE, JSON.stringify({ url: box.getAttribute('data-tpi-cards'), at: Date.now() })); } catch (e) {}
      if (abreLogin(botao, { cards: idsDe(box).length })) return;
      estado.innerHTML = 'Para guardar, <a href="' + C.appUrl + '">entre na sua conta do Tchau Pobreza</a>. É grátis.';
      linkDeVolta(estado.querySelector('a'));
      return;
    }
    box.setAttribute('aria-busy', 'true'); botao.disabled = true;
    var antes = botao.textContent; botao.textContent = 'Adicionando…'; estado.textContent = '';
    var cards = fetch(box.getAttribute('data-tpi-cards'), { credentials: 'omit' }).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); });
    function gravar(s, lista) {
      if (!s) throw new Error('sessao');
      var linhas = lista.map(function (c) {
        return { user_id: s.user.id, id: c.id, kind: 'flashcard', data: {
          type: 'basic', front: c.front, back: c.back, deck: c.deck, topic: '', tags: c.tags || [], source: c.source || { kind: 'free' },
          suspended: false, srs: srsNovo(), log: []
        } };
      });
      return api(s, 'tp_records?on_conflict=user_id,id&select=id', {
        method: 'POST', headers: { Prefer: 'resolution=ignore-duplicates,return=representation' }, body: JSON.stringify(linhas)
      });
    }
    Promise.all([sessao(), cards]).then(function (r) {
      var s = r[0], lista = (r[1] && r[1].cards) || [];
      if (!lista.length) throw new Error('vazio');
      return Promise.resolve().then(function () { return gravar(s, lista); }).catch(function (err) {
        if (String(err && err.message) !== 'jwt' || !s) throw err;
        sessaoP = renovar(s).catch(function () { return null; });
        return sessaoP.then(function (n) { s = n; return gravar(n, lista); });
      }).then(function (novas) {
        try { localStorage.setItem('tchau-records-changed:' + s.user.id, String(Date.now())); } catch (e) {} /* aba do app aberta recarrega */
        box.removeAttribute('aria-busy');
        pintaApp(box, idsDe(box).length, (novas || []).length);
        document.dispatchEvent(new CustomEvent('tpi:flashcards-importados', { detail: { n: (novas || []).length } }));
      });
    }).catch(function (err) {
      if (window.console) console.error('[tp-informativos] falha ao importar os flashcards:', err && err.message);
      box.removeAttribute('aria-busy'); botao.disabled = false; botao.textContent = antes;
      estado.textContent = /^(jwt|sessao)$/.test(String(err && err.message)) ? 'Sua sessão expirou. Entre de novo no app e volte aqui.'
        : 'Não foi possível adicionar agora. Tente de novo.' + detalhe(err);
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.tpi-app__botao');
    if (b) importa(b.closest('.tpi-app'));
  });
  /* 0.11.1: a aba do julgado aberta antes de importar a edição em outra aba (ou restaurada pelo Voltar) mostrava
     "Adicionar" com cards que já estavam no app. Clicar não duplicava (mesmo id, ON CONFLICT DO NOTHING), mas confundia.
     Reconfere quando outra aba avisa (tchau-records-changed, gravado aqui e no app), na volta pelo histórico e ao
     voltar para a aba (no máximo 1 vez por minuto, para não gastar egress à toa). */
  var ultimaConferida = Date.now();
  function reconfere(forca) {
    if (semSessao || !document.querySelector('.tpi-app[data-tpi-ids]')) return;
    if (!forca && Date.now() - ultimaConferida < 60000) return;
    ultimaConferida = Date.now();
    sessao().then(function (s) { if (s) checaApp(s); });
  }
  window.addEventListener('storage', function (e) { if (e.key && e.key.indexOf('tchau-records-changed:') === 0) reconfere(true); });
  window.addEventListener('pageshow', function (e) { if (e.persisted) reconfere(true); });
  document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'visible') reconfere(false); });

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

/* 0.9.1: prévia do caderno a partir do conceito do informativo. O link é real (href para o tema do caderno);
   aqui o clique simples abre a prévia num <dialog>. Ctrl/Cmd/botão do meio seguem o link normalmente. */
(function () {
  var C = window.TPI || {};
  var base = (C.rest || '/wp-json/') + 'tp/v1/caderno-previa/';
  var dlg = null, cache = {};
  function monta() {
    dlg = document.createElement('dialog');
    dlg.className = 'tpc-gaveta';
    dlg.setAttribute('aria-labelledby', 'tpc-gaveta-titulo');
    dlg.innerHTML = '<div class="tpc-gaveta__alca" aria-hidden="true"></div>'
      + '<header class="tpc-gaveta__topo"><p><span>Prévia do caderno</span><strong id="tpc-gaveta-titulo"></strong></p>'
      + '<button type="button" class="tpc-gaveta__fechar" aria-label="Fechar prévia">×</button></header>'
      + '<div class="tpc-gaveta__corpo" tabindex="-1"></div>'
      + '<footer class="tpc-gaveta__pe"><a class="tpc-gaveta__ver" href="#">Ver o tema completo no caderno</a><a class="tpc-gaveta__praticar" href="#">Praticar este tema no app</a></footer>';
    document.body.appendChild(dlg);
    dlg.querySelector('.tpc-gaveta__fechar').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    dlg.addEventListener('close', function () { document.documentElement.classList.remove('tpc-sem-rolagem'); });
  }
  function mostra(d, a) {
    dlg.querySelector('#tpc-gaveta-titulo').textContent = d.caderno || '';
    var corpo = dlg.querySelector('.tpc-gaveta__corpo');
    corpo.innerHTML = d.html; corpo.scrollTop = 0;
    dlg.querySelector('.tpc-gaveta__ver').href = d.url || a.href;
    dlg.querySelector('.tpc-gaveta__praticar').href = d.praticar || '/estudar/';
    document.dispatchEvent(new CustomEvent('tpi:repintar'));
  }
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[data-tpc-previa]');
    if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
    if (typeof HTMLDialogElement !== 'function' || !window.fetch) return; /* navegador antigo: segue o link */
    e.preventDefault();
    if (!dlg) monta();
    var id = a.getAttribute('data-tpc-previa');
    dlg.querySelector('.tpc-gaveta__corpo').innerHTML = '<p class="tpc-gaveta__carregando">Carregando…</p>';
    dlg.querySelector('.tpc-gaveta__ver').href = a.href;
    if (!dlg.open) { dlg.showModal(); document.documentElement.classList.add('tpc-sem-rolagem'); }
    if (typeof window.gtag === 'function') window.gtag('event', 'caderno_previa', { tema_id: id, origem: location.pathname });
    (cache[id] ? Promise.resolve(cache[id]) : fetch(base + id).then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); }))
      .then(function (d) { cache[id] = d; mostra(d, a); })
      .catch(function () { dlg.close(); location.href = a.href; }); /* falhou: vai para o caderno */
  });
})();
