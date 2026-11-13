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
  no artigo do acervo (só espaços são normalizados). Notas de redação no fim do parágrafo ("(Incluído pela...)")
  não contam. Não pode trocar palavra, pena ou número.
- O artigo vai INTEIRO, na ordem do acervo, com epígrafes e parágrafos acrescidos depois ("§ 4º-A", "§ 12").
  Omitir só com uma linha "(...)" no lugar exato do corte, que aparece para o aluno. Corte calado é erro
  (2026-11-11: o art. 155 do caderno parava no § 4º, IV, e faltavam o inciso V e os §§ 4º-A a 12).
  Para gerar o texto completo: --completar reescreve cada bloco "lei" com o artigo inteiro do acervo.

Também confere se `tax` e `dispositivos` de cada tema existem no app, e o formato de `mudancas` (0.16.0).
Com --desde <ref do git> (ex.: origin/main), avisa quando a lei, o gabarito da pegadinha ou a
jurisprudência de um tema publicado mudou sem uma entrada nova em `mudancas` (só aviso: pode ser
reformatação; decida se o aluno que já leu precisa saber).

Sai com código 1 se houver divergência, para poder entrar num gancho de pré-commit.
"""
import argparse
import datetime
import glob
import subprocess
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
        m = re.match(r'(?:Art\.|ARTIGO|Artigo)\s*(\d+(?:\.\d{3})*[º°o]?(?:-[A-Z])?)\s*\.?', p)
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
                pos, corte = -1, False
                for p in pars:
                    if p in ('(...)', '[...]', '...'):
                        corte = True
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
                    if achou > pos + 1 and not corte:
                        erros.append('%s: %s omite sem "(...)": %s' % (onde, rotulo, _rotulos(oficiais[pos + 1:achou])))
                    pos = achou
                    corte = False
                if pos < len(oficiais) - 1 and not corte:
                    erros.append('%s: %s omite sem "(...)" o fim do artigo: %s' % (onde, rotulo, _rotulos(oficiais[pos + 1:])))
                ok += 1
    return ok, erros


def _rotulos(pars):
    """Resumo do que ficou de fora: os rótulos ("§ 4º-A", "V") ou o começo do parágrafo."""
    out = []
    for p in pars:
        m = re.match(r'(§\s*\d+[º°o]?(?:-[A-Z])?|Parágrafo único|[IVXLC]+\s*-|[a-z]\)|Pena)', p)
        out.append(m.group(1) if m else '"%s"' % p[:40])
    return ', '.join(out)


def completar(acervo, arquivo):
    """Reescreve cada bloco "lei" com os artigos inteiros do acervo, sem notas de redação."""
    s = open(arquivo, encoding='utf-8').read()
    disc = json.loads(s)
    so_rotulo = re.compile(r'^(§\s*\d+[º°o]?(?:-[A-Z])?\.?|Parágrafo único\.?|[IVXLC]+\s*-|[a-z]\))$')
    mudou = 0
    for tema in disc.get('temas', []):
        for item in tema.get('lei') or []:
            unidades = carrega(acervo, norma_de(item)) or {}
            out = []
            for rotulo, _ in artigos(item.get('texto', '')):
                u = unidades.get(rotulo)
                if not u:
                    continue
                for x in re.split(r'\n\s*\n', u['text']):
                    if not norm(x):
                        continue
                    p = sem_nota(norm(x))
                    if so_rotulo.match(p):  # revogado: sem a nota sobraria só o rótulo
                        p = p + ' (Revogado)' if re.search(r'revogad', x, re.I) else norm(x)
                    out.append(p)
            novo = '\n\n'.join(out)
            if out and novo != item.get('texto'):
                antigo = json.dumps(item['texto'], ensure_ascii=False)
                if s.count(antigo) == 1:  # troca só a string: preserva a formatação do arquivo
                    s = s.replace(antigo, json.dumps(novo, ensure_ascii=False))
                    item['texto'] = novo
                    mudou += 1
                    print('completado: %s#%s' % (os.path.basename(arquivo), tema.get('slug')))
    if mudou:
        open(arquivo, 'w', encoding='utf-8').write(s)
    return mudou


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


TIPOS_MUDANCA = ('lei', 'correcao', 'juris')


def confere_mudancas(arquivos):
    """0.16.0: `mudancas` acende o selo "Atualizado" para quem leu antes. Formato estrito para não errar em silêncio."""
    hoje = datetime.date.today().isoformat()
    erros = []
    for arq in arquivos:
        for t in json.load(open(arq, encoding='utf-8')).get('temas', []):
            onde = '%s#%s' % (os.path.basename(arq), t.get('slug'))
            m = t.get('mudancas')
            if m is None:
                continue
            if not isinstance(m, list):
                erros.append('%s: mudancas tem de ser uma lista.' % onde)
                continue
            for x in m:
                em, tipo, nota = str(x.get('em', '')), x.get('tipo'), str(x.get('nota', '')).strip()
                if not re.fullmatch(r'\d{4}-\d{2}-\d{2}', em) or em > hoje:
                    erros.append('%s: mudança com data inválida ou futura (%s).' % (onde, em))
                if tipo not in TIPOS_MUDANCA:
                    erros.append('%s: tipo de mudança "%s" (use %s).' % (onde, tipo, ', '.join(TIPOS_MUDANCA)))
                if not nota or len(nota) > 180:
                    erros.append('%s: nota da mudança vazia ou com mais de 180 caracteres.' % onde)
    return erros


def _essencial(t):
    """O que, se mudar, pode mudar a resposta do aluno: texto da lei, gabarito da pegadinha, jurisprudência."""
    return (
        [norm(sem_nota(i.get('texto', ''))) for i in t.get('lei') or []],
        (t.get('pegadinha') or {}).get('gabarito'),
        [norm(j.get('texto', '')) for j in t.get('juris') or []],
    )


def avisa_sem_registro(arquivos, ref):
    avisos = []
    for arq in arquivos:
        rel = os.path.relpath(arq, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
        try:
            antes = json.loads(subprocess.run(['git', 'show', '%s:%s' % (ref, rel)], capture_output=True, check=True, text=True).stdout)
        except (subprocess.CalledProcessError, ValueError):
            continue  # caderno novo
        velhos = {t.get('slug'): t for t in antes.get('temas', [])}
        for t in json.load(open(arq, encoding='utf-8')).get('temas', []):
            v = velhos.get(t.get('slug'))
            if not v or not v.get('resumo') or _essencial(v) == _essencial(t):
                continue
            if len(t.get('mudancas') or []) > len(v.get('mudancas') or []):
                continue
            avisos.append('%s#%s: lei, gabarito ou jurisprudência mudou sem entrada nova em "mudancas". '
                          'Se muda a resposta de quem já leu, registre (LEIA-ME, campo mudancas).' % (os.path.basename(arq), t.get('slug')))
    return avisos


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--acervo', required=True, help='pasta codigo-fonte/public/legal do app')
    ap.add_argument('--completar', action='store_true', help='reescreve os blocos "lei" com os artigos inteiros do acervo')
    ap.add_argument('--desde', help='ref do git (ex.: origin/main) para avisar mudança essencial sem registro em mudancas')
    ap.add_argument('arquivos', nargs='*')
    a = ap.parse_args()
    raiz = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    arquivos = a.arquivos or sorted(glob.glob(os.path.join(raiz, 'cadernos', '*.json')))
    if a.completar:
        for arq in arquivos:
            completar(a.acervo, arq)
    total, todos = 0, []
    for arq in arquivos:
        ok, erros = confere(a.acervo, arq)
        total += ok
        todos += erros
    todos += confere_ligacoes(a.acervo, arquivos)
    todos += confere_mudancas(arquivos)
    for e in todos:
        print('ERRO ' + e)
    if a.desde:
        for w in avisa_sem_registro(arquivos, a.desde):
            print('AVISO ' + w)
    print('%d artigo(s) conferido(s), %d divergência(s).' % (total, len(todos)))
    sys.exit(1 if todos else 0)


if __name__ == '__main__':
    main()
