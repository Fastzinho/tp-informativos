#!/usr/bin/env python3
"""Checagem de sobreposição entre temas dos cadernos (canibalização e conteúdo repetido).

Roda sozinho (sem dependências) e no CI dos PRs que mexem em cadernos/*.json:

    python3 ferramentas/sobreposicao.py              # relatório completo
    python3 ferramentas/sobreposicao.py --ci         # falha só com par NOVO (fora de ferramentas/poda/sobreposicao-conhecida.json)
    python3 ferramentas/sobreposicao.py --aceitar    # grava os pares atuais como conhecidos (depois de olhar cada um)

O que conta como problema:
  ERRO  slug de tema repetido em dois cadernos: quebra a busca de refs "disciplina#tema" vindas de outro
        caderno (tpc_tema_por_ref só acha o tema se o slug for único) e confunde o lido do aluno.
  AVISO dois temas que tratam do mesmo assunto: título quase igual, ou os mesmos nós da taxonomia (tax)
        com muitas palavras-chave em comum. Decisão humana: manter (ângulos diferentes, cada um linka o outro
        em "relacionados"), enxugar um deles para remeter ao outro, ou juntar no mesmo caderno.

Nada aqui apaga ou muda conteúdo. Ver playbook-conteudo.md, seção 11.
"""
import argparse, glob, json, os, re, sys, unicodedata

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASTA = os.path.join(RAIZ, 'cadernos')
ACEITOS = os.path.join(RAIZ, 'ferramentas', 'poda', 'sobreposicao-conhecida.json')
PARAR = set('a o e de da do das dos em no na nos nas um uma para por com sem ao aos as os ou se sua seu ' \
            'que pelo pela pelos pelas sobre entre direito lei teoria geral nocoes regime regras'.split())

def norm(s):
    s = unicodedata.normalize('NFD', re.sub(r'<[^>]+>', ' ', str(s or ''))).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z0-9]+', ' ', s).strip()

def tokens(s):
    return {t for t in norm(s).split() if t not in PARAR and len(t) > 2}

def jac(a, b):
    return len(a & b) / len(a | b) if a and b else 0.0

def carregar():
    temas = []
    for f in sorted(glob.glob(os.path.join(PASTA, '*.json'))):
        if os.path.basename(f).startswith('sobreposicao'):
            continue
        d = json.load(open(f, encoding='utf-8'))
        if 'temas' not in d:
            continue
        for t in d['temas']:
            temas.append({
                'ref': f"{d['slug']}#{t['slug']}", 'caderno': d['slug'], 'slug': t['slug'], 'titulo': t.get('titulo', ''),
                'tit': tokens(t.get('titulo', '')), 'tax': set(t.get('tax') or []),
                'pal': {norm(p) for p in (t.get('palavras') or []) + (t.get('conceitos') or []) if norm(p)},
                'rel': set(t.get('relacionados') or []),
            })
    return temas

def pares(temas):
    achados = []
    for i, a in enumerate(temas):
        for b in temas[i + 1:]:
            jt, jp = jac(a['tit'], b['tit']), jac(a['pal'], b['pal'])
            comuns = len(a['pal'] & b['pal'])
            motivo = None
            if norm(a['titulo']) == norm(b['titulo']):
                motivo = 'título igual'
            elif jt >= 0.6 and a['caderno'] != b['caderno']:
                motivo = f'títulos parecidos ({jt:.0%} das palavras)'
            elif jp >= 0.25 and comuns >= 5:
                motivo = f'{comuns} palavras-chave em comum ({jp:.0%})'
            if motivo:
                ligados = b['slug'] in a['rel'] or a['slug'] in b['rel']
                achados.append({'par': sorted([a['ref'], b['ref']]), 'motivo': motivo, 'mesmo_caderno': a['caderno'] == b['caderno'],
                                'titulos': [a['titulo'], b['titulo']], 'ligados': ligados})
    return achados

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--ci', action='store_true')
    ap.add_argument('--aceitar', action='store_true')
    ap.add_argument('--json', action='store_true')
    o = ap.parse_args()
    temas = carregar()
    erros = []
    por_slug = {}
    for t in temas:
        por_slug.setdefault(t['slug'], []).append(t['ref'])
    for slug, refs in sorted(por_slug.items()):
        if len(refs) > 1:
            erros.append(f'slug "{slug}" repetido em: {", ".join(refs)}')
    achados = pares(temas)
    aceitos = set()
    if os.path.exists(ACEITOS):
        aceitos = {tuple(p['par']) for p in json.load(open(ACEITOS, encoding='utf-8')).get('pares', [])}
    novos = [p for p in achados if tuple(p['par']) not in aceitos]

    if o.aceitar:
        json.dump({'nota': 'Pares de temas já avaliados e mantidos de propósito (ângulos diferentes). '
                           'Gerado por ferramentas/sobreposicao.py --aceitar; tirar um par daqui o faz voltar a avisar.',
                   'pares': [{'par': p['par'], 'motivo': p['motivo']} for p in achados]},
                  open(ACEITOS, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        print(f'{len(achados)} pares gravados em {os.path.relpath(ACEITOS, RAIZ)}')
        return 0
    if o.json:
        print(json.dumps({'erros': erros, 'pares': achados, 'novos': novos}, ensure_ascii=False, indent=1))
        return 0

    print(f'{len(temas)} temas em {len({t["caderno"] for t in temas})} cadernos.')
    for e in erros:
        print(f'ERRO  {e}')
    lista = novos if o.ci else achados
    for p in lista:
        lig = '' if p['ligados'] else '  (não se citam em "relacionados")'
        print(f'AVISO {p["par"][0]}  x  {p["par"][1]}: {p["motivo"]}{lig}\n      "{p["titulos"][0]}" / "{p["titulos"][1]}"')
    print(f'{len(erros)} erro(s), {len(achados)} par(es) parecido(s), {len(novos)} novo(s) fora da lista aceita.')
    if o.ci and (erros or novos):
        print('\nComo resolver: leia os dois temas. Se tratam do mesmo assunto, enxugue um e remeta ao outro (ou junte).\n'
              'Se são ângulos diferentes de propósito, cite um no "relacionados" do outro e rode\n'
              '  python3 ferramentas/sobreposicao.py --aceitar\n'
              'para registrar a decisão. Slug repetido não tem aceite: renomeie o tema NOVO (nunca um já publicado).')
        return 1
    return 0

if __name__ == '__main__':
    sys.exit(main())
