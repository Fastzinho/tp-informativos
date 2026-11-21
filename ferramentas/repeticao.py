#!/usr/bin/env python3
"""Repetição dentro do tema: o aprofundamento só traz o que o card de cima não diz (playbook, seção 12).

    python3 ferramentas/repeticao.py                    # relatório de todos os temas, piores primeiro
    python3 ferramentas/repeticao.py direito-penal      # só um caderno (vários: separados por espaço)
    python3 ferramentas/repeticao.py --tema peculato    # um tema, bloco a bloco
    python3 ferramentas/repeticao.py --ci               # PR: só os temas novos ou com aprofundamento mudado

O que olha, em cada tema:
  ERRO   bloco "Mais pontos que as questões cobram" (ou outro bloco colado no fim por rodada): a rodada nova
         entra fundida na seção certa, nunca como apêndice.
  ERRO   o mesmo subtítulo duas vezes no aprofundamento.
  AVISO  bloco do aprofundamento com 60% ou mais das palavras já presentes no resumo, esquema, macete, juris
         ou pegadinha. É candidato, não sentença: um caso concreto novo pode usar as mesmas palavras.
  AVISO  a mesma súmula, tema repetitivo ou artigo em dois blocos do aprofundamento ("um ponto, um lugar").
  AVISO  aprofundamento maior que o resto do card e acima de 700 palavras: sinal de tema que pede dois temas.

No --ci, só os ERROS travam, e só nos temas que o PR criou ou cujo aprofundamento mudou: o acervo antigo é
enxugado em lotes, sem travar PR de outra coisa. Sem dependências.
"""
import argparse, glob, html, json, os, re, subprocess, sys, unicodedata

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASTA = os.path.join(RAIZ, 'cadernos')
LIMITE_PALAVRAS = 700
PARAR = set(('a o e de da do das dos em no na nos nas um uma uns umas que se por para com sem nao ao aos as os ou '
             'como mas mais ser sao foi pelo pela pelos pelas seu sua seus suas ele ela isso esse essa este esta lhe '
             'ja so tambem quando ha art lei stf stj sumula caso pode deve ter tem sobre entre ate apos cebraspe fgv '
             'fcc vunesp delegado prova item itens errado certo esta dizer').split())
REF = re.compile(r'(s[uú]mula(?: vinculante)? n?º? ?\d+|tema (?:\d\.)?\d+|art\. ?\d+[º°]?(?:-[a-z])?)', re.I)
APENDICE = re.compile(r'Mais pontos que as quest', re.I)


def texto(h):
    return html.unescape(re.sub(r'<[^>]+>', ' ', str(h or '')))


def norm(s):
    s = unicodedata.normalize('NFD', str(s).lower()).encode('ascii', 'ignore').decode()
    return re.sub(r'\s+', ' ', s)


def palavras(s):
    return {w for w in re.findall(r'[a-z0-9]+', norm(s)) if w not in PARAR and len(w) > 2}


def refs(s):
    return {re.sub(r'[^a-z0-9]', '', norm(r)) for r in REF.findall(s)}


def card(t):
    partes = [t.get('resumo', ''), (t.get('esquema') or {}).get('html', '')]
    partes += [f"{m.get('sigla', '')} {m.get('texto', '')}" for m in t.get('macetes') or []]
    partes += [j.get('texto', '') for j in t.get('juris') or []]
    pg = t.get('pegadinha') or {}
    partes += [pg.get('afirmacao', ''), pg.get('explicacao', '')]
    return texto(' '.join(partes))


def blocos(h):
    out = []
    for p in re.split(r'</p>|</li>', str(h or '')):
        s = texto(p).strip()
        if len(s.split()) >= 8 and not s.lower().startswith('já caiu em'):
            out.append(s)
    return out


def subtitulos(h):
    return [norm(texto(x)).strip(' .:') for x in re.findall(r'<(?:strong|em)>(.*?)</(?:strong|em)>', str(h or ''))
            if len(texto(x).split()) <= 12 and re.search(r'<p><(?:strong|em)>' + re.escape(x) + r'</(?:strong|em)></p>', str(h))]


def analisar(caderno, t):
    ap = t.get('aprofundamento') or ''
    base = card(t)
    pb, rb = palavras(base), refs(base)
    erros, avisos = [], []
    if APENDICE.search(ap):
        erros.append('bloco "Mais pontos que as questões cobram": fundir cada ponto na seção certa')
    vistos = set()
    for s in subtitulos(ap):
        if s in vistos:
            erros.append(f'subtítulo repetido: "{s}"')
        vistos.add(s)
    repetidos, onde = [], {}
    for i, b in enumerate(blocos(ap)):
        pw = palavras(b)
        if pw and len(pw & pb) / len(pw) >= 0.6:
            repetidos.append(b)
        for r in refs(b):
            onde.setdefault(r, set()).add(i)
    for b in repetidos:
        avisos.append(f'repete o card: "{b[:110]}…"')
    for r, ii in sorted(onde.items()):
        if len(ii) > 1:
            avisos.append(f'{r} aparece em {len(ii)} blocos do aprofundamento')
    na, nb = len(texto(ap).split()), len(base.split())
    if na > LIMITE_PALAVRAS and na > nb:
        avisos.append(f'aprofundamento com {na} palavras, maior que o card ({nb}): pensar em dois temas')
    return {'ref': f'{caderno}#{t["slug"]}', 'erros': erros, 'avisos': avisos, 'palavras_ap': na, 'palavras_card': nb,
            'repetidas': sum(len(b.split()) for b in repetidos)}


def carregar(filtro=None):
    for f in sorted(glob.glob(os.path.join(PASTA, '*.json'))):
        d = json.load(open(f, encoding='utf-8'))
        if 'temas' not in d or (filtro and d['slug'] not in filtro):
            continue
        for t in d['temas']:
            if t.get('resumo'):
                yield d['slug'], t


def na_base(base):
    """Aprofundamento de cada tema na base do PR (origin/main), para checar só o que o PR mexeu."""
    out = {}
    try:
        nomes = subprocess.run(['git', 'ls-tree', '--name-only', base, 'cadernos/'], cwd=RAIZ, capture_output=True,
                               text=True, check=True).stdout.split()
    except Exception:
        return None
    for n in nomes:
        if not n.endswith('.json'):
            continue
        try:
            d = json.loads(subprocess.run(['git', 'show', f'{base}:{n}'], cwd=RAIZ, capture_output=True, text=True,
                                          check=True).stdout)
        except Exception:
            continue
        for t in d.get('temas') or []:
            out[f'{d.get("slug")}#{t.get("slug")}'] = t.get('aprofundamento') or ''
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('cadernos', nargs='*')
    ap.add_argument('--tema')
    ap.add_argument('--ci', action='store_true')
    ap.add_argument('--base', default='origin/main')
    ap.add_argument('--json', action='store_true')
    o = ap.parse_args()
    res = []
    antigos = na_base(o.base) if o.ci else None
    if o.ci and antigos is None:
        print(f'Não achei {o.base} para comparar (git fetch da base?). Sem ela, o --ci não sabe o que o PR mexeu.')
        return 1
    for c, t in carregar(set(o.cadernos) or None):
        if o.tema and t['slug'] != o.tema:
            continue
        r = analisar(c, t)
        if o.ci and antigos is not None and antigos.get(r['ref']) == (t.get('aprofundamento') or ''):
            continue
        res.append(r)
    if o.json:
        print(json.dumps(res, ensure_ascii=False, indent=1))
        return 0
    res.sort(key=lambda r: (-len(r['erros']), -r['repetidas'], -r['palavras_ap']))
    for r in res:
        if not (r['erros'] or r['avisos']):
            continue
        print(f"{r['ref']}  (aprofundamento {r['palavras_ap']} palavras, card {r['palavras_card']})")
        for e in r['erros']:
            print(f'  ERRO  {e}')
        for a in r['avisos']:
            print(f'  AVISO {a}')
    ne = sum(len(r['erros']) for r in res)
    na_ = sum(len(r['avisos']) for r in res)
    print(f'{len(res)} tema(s) olhado(s){" (só os que o PR mexeu)" if o.ci else ""}: {ne} erro(s), {na_} aviso(s).')
    if o.ci and ne:
        print('\nO aprofundamento só traz o que o card não diz, em seções fixas (playbook, seção 12). Funda o bloco\n'
              'colado no fim nas seções certas e tire o subtítulo repetido. Os avisos não travam: leia cada um.')
        return 1
    return 0


if __name__ == '__main__':
    sys.exit(main())
