#!/usr/bin/env python3
"""Confere a lei seca dos cadernos com o acervo oficial do app, palavra por palavra.

Uso:
    python3 ferramentas/conferir_lei.py --acervo ../tchaupobreza/codigo-fonte/public/legal [cadernos/*.json]

O acervo é o mesmo que o app usa na Lei seca (textos da Câmara/Planalto, um JSON por norma).
Fica no repositório do app, que é privado; por isso o script recebe o caminho e não roda no CI daqui.

Regras:
- Cada item de "lei" precisa dizer a norma: campo "norma" (id do acervo, ex.: "n2848-1940")
  ou, na falta dele, o número em "fonte" ("Decreto-Lei 2.848/1940" vira n2848-1940;
  "Constituição" vira nCF-1988).
- O texto é dividido por artigo ("Art. 312."). Cada parágrafo do caderno tem de existir, igual,
  no artigo do acervo (só espaços são normalizados). Pode omitir incisos e parágrafos (trecho),
  e uma linha "(...)" marca a omissão. Notas de redação no fim do parágrafo ("(Incluído pela...)")
  não contam. Não pode trocar palavra, pena ou número.
- A ordem dos parágrafos tem de ser a do acervo.

Também confere se `tax` e `dispositivos` de cada tema existem no app.

Sai com código 1 se houver divergência, para poder entrar num gancho de pré-commit.
"""
import argparse
import glob
import json
import os
import re
import sys
import unicodedata


def norm(s):
    s = unicodedata.normalize('NFC', s)
    s = s.replace(' ', ' ').replace('–', '-').replace('—', '-')
    return re.sub(r'\s+', ' ', s).strip()


NOTA = re.compile(r'\s*\((?:[^()]*\b(?:reda[cç][aã]o|acrescid|inclu[ií]d|revogad|vide|renumerad|vig[eê]ncia)[^()]*)\)\s*$', re.I)


def sem_nota(s):
    """Tira a nota de redação do fim ("(Inciso acrescido pela EC 104/2019)"): o caderno pode citar sem ela."""
    anterior = None
    while anterior != s:
        anterior, s = s, NOTA.sub('', s)
    return s


def norma_de(item):
    if item.get('norma'):
        return item['norma']
    fonte = item.get('fonte', '')
    if re.search(r'Constitui[cç][aã]o', fonte, re.I) and not re.search(r'Estadual|do Estado', fonte, re.I):
        return 'nCF-1988'
    m = re.search(r'(\d[\d.]*)\s*/\s*(\d{4})', fonte)
    if m:
        return 'n%s-%s' % (m.group(1).replace('.', ''), m.group(2))
    return None


def artigos(texto):
    """Quebra o texto do caderno em {rótulo: [parágrafos]} pelo início 'Art. N'."""
    blocos, atual = [], None
    for p in [sem_nota(norm(x)) for x in re.split(r'\n\s*\n', texto) if norm(x)]:
        m = re.match(r'Art\.\s*(\d+[º°o]?(?:-[A-Z])?)\s*\.?', p)
        if m:
            atual = ['Art. %s.' % m.group(1).replace('°', 'º'), []]
            blocos.append(atual)
        if atual is None:
            atual = [None, []]
            blocos.append(atual)
        atual[1].append(p)
    return blocos


def carrega(acervo, nid, cache={}):
    if nid not in cache:
        caminho = os.path.join(acervo, nid + '.json')
        if not os.path.exists(caminho):
            cache[nid] = None
        else:
            d = json.load(open(caminho, encoding='utf-8'))
            cache[nid] = {norm(u['label']).replace('°', 'º').rstrip('.') + '.': u for u in d.get('units', [])}
    return cache[nid]


def confere(acervo, arquivo):
    erros, ok = [], 0
    disc = json.load(open(arquivo, encoding='utf-8'))
    for tema in disc.get('temas', []):
        for i, item in enumerate(tema.get('lei') or []):
            onde = '%s#%s lei[%d]' % (os.path.basename(arquivo), tema.get('slug'), i)
            nid = norma_de(item)
            unidades = carrega(acervo, nid) if nid else None
            if not unidades:
                erros.append('%s: norma não achada no acervo (%s). Ponha "norma" com o id do acervo.' % (onde, nid))
                continue
            for rotulo, pars in artigos(item.get('texto', '')):
                if not rotulo:
                    erros.append('%s: texto antes do primeiro "Art."; não dá para conferir.' % onde)
                    continue
                u = unidades.get(rotulo)
                if not u:
                    erros.append('%s: %s não existe em %s.' % (onde, rotulo, nid))
                    continue
                oficiais = [sem_nota(norm(x)) for x in re.split(r'\n\s*\n', u['text']) if norm(x)]
                pos = -1
                for p in pars:
                    if p in ('(...)', '[...]', '...'):
                        continue
                    try:
                        achou = oficiais.index(p, pos + 1)
                    except ValueError:
                        if p in oficiais:
                            erros.append('%s: %s fora de ordem: "%s"' % (onde, rotulo, p[:90]))
                        else:
                            parecido = max(oficiais, key=lambda o: _semelhanca(o, p))
                            erros.append('%s: %s diverge do acervo.\n    caderno: %s\n    acervo:  %s' % (onde, rotulo, p[:300], parecido[:300]))
                        continue
                    pos = achou
                ok += 1
    return ok, erros


def _semelhanca(a, b):
    wa, wb = set(a.lower().split()), set(b.lower().split())
    return len(wa & wb) / (len(wa | wb) or 1)


def confere_ligacoes(acervo, arquivos):
    """tax e dispositivos (0.10.2) têm de existir no app: nós da taxonomia e unidades do acervo."""
    nos_arq = os.path.join(acervo, '..', '..', 'taxonomy-src', 'nodes.json')
    if not os.path.exists(nos_arq):
        return ['taxonomy-src/nodes.json não achado ao lado do acervo; tax não conferido.']
    nos = json.load(open(nos_arq, encoding='utf-8'))
    nos = nos if isinstance(nos, list) else nos.get('nodes', nos)
    ids = {x['id'] if isinstance(x, dict) else x for x in (nos if isinstance(nos, list) else nos.keys())}
    unidades = set()
    for f in glob.glob(os.path.join(acervo, '*.json')):
        unidades |= {u.get('id') for u in json.load(open(f, encoding='utf-8')).get('units', [])}
    erros = []
    for arq in arquivos:
        for t in json.load(open(arq, encoding='utf-8')).get('temas', []):
            onde = '%s#%s' % (os.path.basename(arq), t.get('slug'))
            if t.get('resumo') and not (t.get('tax') and t.get('dispositivos')):
                erros.append('%s: tema escrito sem tax ou dispositivos.' % onde)
            erros += ['%s: nó "%s" não existe na taxonomia do app.' % (onde, x) for x in t.get('tax') or [] if x not in ids]
            erros += ['%s: dispositivo "%s" não existe no acervo.' % (onde, x) for x in t.get('dispositivos') or [] if x not in unidades]
    return erros


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--acervo', required=True, help='pasta codigo-fonte/public/legal do app')
    ap.add_argument('arquivos', nargs='*')
    a = ap.parse_args()
    raiz = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    arquivos = a.arquivos or sorted(glob.glob(os.path.join(raiz, 'cadernos', '*.json')))
    total, todos = 0, []
    for arq in arquivos:
        ok, erros = confere(a.acervo, arq)
        total += ok
        todos += erros
    todos += confere_ligacoes(a.acervo, arquivos)
    for e in todos:
        print('ERRO ' + e)
    print('%d artigo(s) conferido(s), %d divergência(s).' % (total, len(todos)))
    sys.exit(1 if todos else 0)


if __name__ == '__main__':
    main()
