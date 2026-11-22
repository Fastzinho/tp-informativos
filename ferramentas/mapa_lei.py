#!/usr/bin/env python3
"""Mapa da lei: a tabela com TODOS os crimes de uma lei, no tema geral dela (campo "mapa" do tema).

    python3 ferramentas/mapa_lei.py --acervo ../tchaupobreza/codigo-fonte/public/legal --gerar   # preenche as linhas que faltam
    python3 ferramentas/mapa_lei.py --acervo ../tchaupobreza/codigo-fonte/public/legal            # só confere

Formato no JSON do tema (LEIA-ME, campo "mapa"):

    "mapa": [{"norma": "n13869-2019", "rotulo": "todos os crimes da Lei 13.869/2019", "de": "9", "ate": "38",
              "fora": [], "linhas": [{"art": "9º", "conduta": "...", "pena": "detenção, de 1 a 4 anos, e multa",
                                      "troca": "o que a banca troca (opcional)"}]}]

--gerar acrescenta uma linha para cada "Pena -" do acervo entre os artigos "de" e "ate" que ainda não está no mapa,
com a conduta tirada do próprio artigo (o começo do caput ou do parágrafo) e a pena sem os números por extenso.
Tipos sem "Pena -" (tortura omissiva do art. 1º, § 2º; art. 28 da Lei de Drogas) entram à mão, com a pena copiada do
artigo, e a conferência confere essa pena do mesmo jeito. Linhas já existentes não são tocadas: a conduta pode ser reescrita à mão, e a coluna "troca" é sempre escrita à mão.

A conferência (roda também dentro de conferir_lei.py) trava quando:
  - um "Pena -" do acervo no intervalo não tem linha no mapa (nem está em "fora"): o mapa tem de ser completo;
  - a pena de uma linha não aparece no texto do artigo do acervo (comparação sem números por extenso; pena com
    várias partes separadas por "; " confere cada parte, para as medidas sem prisão do art. 28 da Lei de Drogas);
  - o artigo da linha não existe na norma.
"""
import argparse, glob, json, os, re, sys, unicodedata

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
EXTENSO = re.compile(r'\s*\([a-zçãâéêíóôõúü \-]+\)')
NOTA = re.compile(r'\s*\((?:[^()]*\b(?:reda[cç][aã]o|acrescid|inclu[ií]d|revogad|vide|renumerad|vig[eê]ncia|express[aã]o|vetad|retificad|promulgad|publicad|transformad|alterad)[^()]*)\)', re.I)


def norm(s):
    s = unicodedata.normalize('NFC', str(s)).replace(' ', ' ').replace('–', '-').replace('—', '-')
    return re.sub(r'\s+', ' ', s).strip()


def limpa(s):
    """Pena para mostrar: sem números por extenso, sem nota de redação, sem 'Pena -', sem pontuação final."""
    s = NOTA.sub('', norm(s))
    s = re.sub(r'\s*\(([^()]*)\)', lambda m: '' if EXTENSO.fullmatch(' (' + m.group(1).lower() + ')') else m.group(0), s)
    s = re.sub(r'^penas?\s*[-:]\s*', '', s, flags=re.I).replace(' ,', ',')
    s = re.sub(r'[\s.;]+$', '', s)
    return re.sub(r'\s+', ' ', s)


def canon(s):
    """Pena comparável: a pena limpa, em minúsculas."""
    return limpa(s).lower()


def num_art(s):
    """'9º' / 'Art. 241-A' / '33, § 3º' -> (33, 'A', '§ 3º') para ordenar e achar a unidade."""
    m = re.match(r'(?:Art\.\s*)?(\d+(?:\.\d{3})*)[º°o]?(?:-([A-Z]))?\.?\s*(?:,\s*(.*))?$', norm(s))
    if not m:
        return None
    return int(m.group(1).replace('.', '')), m.group(2) or '', (m.group(3) or '').strip()


def unidades(acervo, norma, cache={}):
    if norma not in cache:
        caminho = os.path.join(acervo, norma + '.json')
        cache[norma] = None
        if os.path.exists(caminho):
            out = {}
            for u in json.load(open(caminho, encoding='utf-8')).get('units', []):
                k = num_art(u.get('label', ''))
                if k:
                    out[k[:2]] = u
            cache[norma] = out
    return cache[norma]


MESMA = re.compile(r'\b(?:nas? mesmas? penas?|incorre(?:m)? na mesma pena)', re.I)
MESMAS = 'as mesmas do caput'


PENA_NO_TEXTO = re.compile(r'(?:a pena(?: privativa de liberdade)?(?: para [^,]*?)? (?:é|será) de|incorre na pena de|punível com)\s+'
                           r'(.+?)(?=[.;:](?:\s|$)|,\s*(?:quem|aquele|se)\b|\s*\((?:inclu|acresc|reda|par[áa]grafo)|$)', re.I)
INCISO = re.compile(r'^(?:[IVXLC]+\s*[-–]|[a-z]\))\s*')


def tipos_com_pena(texto):
    """[(rotulo_do_paragrafo, texto_do_tipo, pena)] para cada 'Pena -' do artigo e para cada pena escrita no
    próprio parágrafo ("a pena é de", "incorre na pena de", "punível com").
    Incisos e alíneas entram no texto do tipo ("Pescar mediante a utilização de: explosivos...; substâncias tóxicas")."""
    pars = [norm(x) for x in re.split(r'\n\s*\n', texto) if norm(x)]
    out, tipo = [], None
    for p in pars:
        if re.match(r'Penas?\s*[-:]', p):
            if tipo and tipo[2] is None:
                out.append((tipo[0], tipo[1], p))
            tipo = None
            continue
        if INCISO.match(p):
            if tipo:
                item = NOTA.sub('', INCISO.sub('', p)).strip().rstrip(':;.')
                sep = ' ' if tipo[1].rstrip().endswith(':') else '; '
                tipo = (tipo[0], tipo[1].rstrip() + sep + item, tipo[2])
                if tipo[2] is not None:
                    out[-1] = tipo
            continue
        m = re.match(r'(Art\.\s*\S+|§\s*\d+[º°o]?(?:-[A-Z])?\.?|Parágrafo único\.?)\s*', p)
        if not m:
            continue
        rot = m.group(1).rstrip('.').replace('°', 'º')
        corpo = NOTA.sub('', re.sub(r'^[-–.\s]+', '', p[m.end():])).strip()
        if not rot.startswith('Art') and MESMA.search(p):
            tipo = (rot, corpo, MESMAS)
            out.append(tipo)
            continue
        rot = '' if rot.startswith('Art') else rot
        dentro = PENA_NO_TEXTO.search(corpo)
        if dentro:
            # "§ 2º Se ... a pena é de reclusão, de 4 a 12 anos." / "incorre na pena de detenção ..." / "Constitui crime punível com ..."
            antes = corpo[:dentro.start()].rstrip(' ,;')
            antes = re.sub(r',?\s*(?:a pena(?: privativa de liberdade)?(?: para [^,]*?)?|incorre)$', '', antes, flags=re.I).rstrip(' ,;')
            depois = corpo[dentro.end():].lstrip(' ,;.:')
            conduta = antes if len(antes) > 20 else (depois or corpo)
            tipo = (rot, conduta, dentro.group(1))
            out.append(tipo)
            continue
        tipo = (rot, corpo, None)
    return out


def conduta_de(texto, limite=170):
    t = NOTA.sub('', texto).strip()
    t = re.sub(r'^[-–\s]+', '', t)
    t = t.rstrip(':').strip()
    if len(t) <= limite:
        return t
    corte = t[:limite].rsplit(' ', 1)[0].rstrip(',;')
    return corte + '…'


def no_intervalo(k, de, ate):
    return num_art(de)[:2] <= k <= num_art(ate)[:2]


def rotulo_linha(k, par):
    base = '%dº' % k[0] if k[0] < 10 else str(k[0])
    if k[1]:
        base += '-' + k[1]
    return base + (', ' + par if par else '')


def processa(acervo, arquivo, gerar):
    erros, mudou = [], False
    d = json.load(open(arquivo, encoding='utf-8'))
    for tema in d.get('temas', []):
        for mi, mapa in enumerate(tema.get('mapa') or []):
            onde = '%s#%s mapa[%d]' % (os.path.basename(arquivo), tema.get('slug'), mi)
            us = unidades(acervo, mapa.get('norma', ''))
            if not us:
                erros.append('%s: norma %s não achada no acervo.' % (onde, mapa.get('norma')))
                continue
            linhas = mapa.setdefault('linhas', [])
            tem = {}
            for li in linhas:
                k = num_art(li.get('art', ''))
                if not k or k[:2] not in us:
                    erros.append('%s: artigo "%s" não existe em %s.' % (onde, li.get('art'), mapa['norma']))
                    continue
                tem[(k[:2], k[2])] = li
                txt = canon(us[k[:2]]['text'])
                if li.get('pena') == MESMAS and MESMA.search(us[k[:2]]['text']):
                    continue
                if not li.get('pena') or any(parte not in txt for parte in canon(li['pena']).split('; ')):
                    erros.append('%s: art. %s, pena "%s" não está no texto do acervo.' % (onde, li.get('art'), li.get('pena')))
            fora = {norm(x) for x in mapa.get('fora') or []}
            for k in sorted(us):
                if not no_intervalo(k, mapa.get('de', '0'), mapa.get('ate', '99999')):
                    continue
                for par, tipo, pena in tipos_com_pena(us[k]['text']):
                    chave = (k, par)
                    rot = rotulo_linha(k, par)
                    if chave in tem or norm(rot) in fora:
                        continue
                    if gerar:
                        linhas.append({'art': rot, 'conduta': conduta_de(tipo), 'pena': limpa(pena)})
                        tem[chave] = linhas[-1]
                        mudou = True
                    else:
                        erros.append('%s: falta o art. %s ("%s"). Rode --gerar ou ponha em "fora".' % (onde, rot, conduta_de(tipo, 60)))
            if gerar:
                linhas.sort(key=lambda li: (num_art(li['art'])[:2], (num_art(li['art'])[2] != '', num_art(li['art'])[2])))
    if mudou:
        ind = 1 if open(arquivo, encoding='utf-8').read().startswith('{\n "') else 2
        open(arquivo, 'w', encoding='utf-8').write(json.dumps(d, ensure_ascii=False, indent=ind) + '\n')
    return erros


def confere_todos(acervo, arquivos):
    out = []
    for a in arquivos:
        out += processa(acervo, a, False)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--acervo', required=True)
    ap.add_argument('--gerar', action='store_true')
    ap.add_argument('arquivos', nargs='*')
    o = ap.parse_args()
    arquivos = o.arquivos or sorted(glob.glob(os.path.join(RAIZ, 'cadernos', '*.json')))
    erros = []
    for a in arquivos:
        erros += processa(o.acervo, a, o.gerar)
    for e in erros:
        print('ERRO ' + e)
    n = sum(len(m.get('linhas', [])) for a in arquivos for t in json.load(open(a, encoding='utf-8')).get('temas', []) for m in t.get('mapa') or [])
    print('%d linha(s) de mapa, %d erro(s).' % (n, len(erros)))
    return 1 if erros else 0


if __name__ == '__main__':
    sys.exit(main())
