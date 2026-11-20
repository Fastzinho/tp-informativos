#!/usr/bin/env python3
"""Poda trimestral dos cadernos: lista as páginas candidatas a melhorar ou a sair do Google.

A poda mexe SÓ na indexação. Nenhum tema é apagado: o conteúdo continua no site, no app ("Ler o resumo",
"Rever teoria"), nas questões e no Estudo inteligente. Regra completa no playbook-conteudo.md, seção 11.

Entrada: a exportação do Search Console, aba Páginas, últimos 3 meses (Desempenho > Resultados da pesquisa >
3 meses > Páginas > Exportar > CSV). Pode ser o .zip inteiro ou o Páginas.csv / Pages.csv de dentro.

    python3 ferramentas/poda_cadernos.py --gsc ~/Downloads/export.zip            # relatório em Markdown
    python3 ferramentas/poda_cadernos.py --gsc Paginas.csv --registrar           # e grava a rodada no histórico

Unidade da poda: a página da disciplina (/cadernos/<slug>/) e o tema com "pagina_propria". Tema que é só âncora
(#slug) não tem dado próprio no Search Console (o Google ignora o #), então não é candidato a sair do índice:
quando a disciplina vai mal, o remédio é melhorar os temas dela.

Escada (para não tirar do Google por um trimestre ruim):
  1ª rodada com 0 impressões  -> MELHORAR (título, descrição, temas rasos, links internos); se a checagem de
                                 sobreposição apontar tema repetido em outro caderno, enxugar e remeter
  2ª rodada seguida com 0     -> NOINDEX sugerido ("indexar": false no JSON da disciplina). Decisão humana.
  Página com menos de 90 dias no ar não entra (o Google ainda está descobrindo).
"""
import argparse, csv, datetime, glob, io, json, os, re, subprocess, sys, zipfile

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PASTA = os.path.join(RAIZ, 'cadernos')
HIST = os.path.join(RAIZ, 'ferramentas', 'poda', 'historico.json')
MIN_INDEX = 8  # tpc_min_index() do plugin
IDADE_MIN = 90

def numero(v):
    v = str(v or '').strip().replace('%', '').replace('.', '').replace(',', '.') if re.search(r'\d,\d', str(v or '')) else str(v or '').strip().replace('%', '')
    try:
        return float(v)
    except ValueError:
        return 0.0

def ler_gsc(caminho):
    if caminho.lower().endswith('.zip'):
        z = zipfile.ZipFile(caminho)
        nome = next((n for n in z.namelist() if re.search(r'(p[aá]ginas|pages)\.csv$', n, re.I)), None)
        if not nome:
            sys.exit('Não achei Páginas.csv dentro do zip. Exporte a aba Páginas do relatório de Desempenho.')
        texto = z.read(nome).decode('utf-8-sig')
    else:
        texto = open(caminho, encoding='utf-8-sig').read()
    linhas = list(csv.reader(io.StringIO(texto)))
    cab = [c.strip().lower() for c in linhas[0]]
    def col(*nomes):
        for i, c in enumerate(cab):
            if any(n in c for n in nomes):
                return i
        sys.exit(f'Coluna {nomes[0]} não encontrada no CSV: {linhas[0]}')
    iu, ic, ii, ip = col('página', 'pagina', 'page'), col('clique', 'click'), col('impress'), col('posi')
    dados = {}
    for l in linhas[1:]:
        if len(l) <= max(iu, ic, ii, ip):
            continue
        m = re.search(r'/cadernos/([^/?#]+)/(?:([^/?#]+)/)?', l[iu])
        if not m:
            continue
        chave = m.group(1) + ('/' + m.group(2) if m.group(2) else '')
        d = dados.setdefault(chave, {'cliques': 0, 'impressoes': 0, 'posicao': 0})
        d['cliques'] += numero(l[ic]); d['impressoes'] += numero(l[ii]); d['posicao'] = numero(l[ip]) or d['posicao']
    return dados

def publicado_em(arquivo):
    try:
        r = subprocess.run(['git', '-C', RAIZ, 'log', '--diff-filter=A', '--format=%cs', '--', arquivo],
                           capture_output=True, text=True, timeout=30)
        datas = r.stdout.split()
        if datas and subprocess.run(['git', '-C', RAIZ, 'rev-parse', '--is-shallow-repository'],
                                    capture_output=True, text=True).stdout.strip() != 'true':
            return datas[-1]
    except Exception:
        pass
    return None

def sobreposicoes():
    try:
        sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
        import sobreposicao
        return sobreposicao.pares(sobreposicao.carregar())
    except Exception:
        return []

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--gsc', required=True, help='exportação do Search Console (zip ou Páginas.csv), últimos 3 meses')
    ap.add_argument('--hoje', default=datetime.date.today().isoformat())
    ap.add_argument('--registrar', action='store_true', help='grava a rodada em ferramentas/poda/historico.json')
    o = ap.parse_args()
    gsc = ler_gsc(os.path.expanduser(o.gsc))
    hoje = datetime.date.fromisoformat(o.hoje)
    hist = json.load(open(HIST, encoding='utf-8')) if os.path.exists(HIST) else {'rodadas': []}
    anteriores = set(hist['rodadas'][-1]['zeradas']) if hist['rodadas'] else set()
    pares = sobreposicoes()

    paginas = []
    for f in sorted(glob.glob(os.path.join(PASTA, '*.json'))):
        d = json.load(open(f, encoding='utf-8'))
        if 'temas' not in d:
            continue
        escritos = sum(1 for t in d['temas'] if re.sub(r'<[^>]+>', '', t.get('resumo') or '').strip())
        if escritos < MIN_INDEX or d.get('indexar') is False:
            continue  # já fora do Google
        pub = publicado_em(os.path.relpath(f, RAIZ))
        idade = (hoje - datetime.date.fromisoformat(pub)).days if pub else None
        g = gsc.get(d['slug'], {'cliques': 0, 'impressoes': 0, 'posicao': 0})
        vizinhos = sorted({p['par'][1].split('#')[0] if p['par'][0].startswith(d['slug'] + '#') else p['par'][0].split('#')[0]
                           for p in pares if not p['mesmo_caderno'] and any(r.startswith(d['slug'] + '#') for r in p['par'])})
        paginas.append({'slug': d['slug'], 'titulo': d.get('titulo', d['slug']), 'temas': len(d['temas']), 'escritos': escritos,
                        'idade': idade, 'vizinhos': vizinhos, **g})
        for t in d['temas']:
            if t.get('pagina_propria') and t.get('indexar') is not False:
                chave = d['slug'] + '/' + t['slug']
                g = gsc.get(chave, {'cliques': 0, 'impressoes': 0, 'posicao': 0})
                paginas.append({'slug': chave, 'titulo': t['titulo'], 'temas': 1, 'escritos': 1, 'idade': idade, 'vizinhos': [], **g})

    def acao(p):
        if p['idade'] is not None and p['idade'] < IDADE_MIN:
            return 'aguardar', f'no ar há {p["idade"]} dias (mínimo {IDADE_MIN})'
        if p['impressoes'] > 0:
            return 'ok', ''
        if p['slug'] in anteriores:
            return 'noindex', '2ª rodada seguida sem impressões: sugerir "indexar": false (decisão do Jonatan)'
        extra = (' Temas repetidos em ' + ', '.join(p['vizinhos']) + ': enxugar o daqui e remeter ao outro (ferramentas/sobreposicao.py).') if p['vizinhos'] else ''
        if p['escritos'] < 12:
            return 'melhorar', f'só {p["escritos"]} temas: completar o caderno antes de pensar em tirar do índice.' + extra
        return 'melhorar', 'rever title/description no Rank Math, links internos (informativos, concursos, como estudar) e temas rasos.' + extra

    for p in paginas:
        p['acao'], p['motivo'] = acao(p)
    zeradas = [p['slug'] for p in paginas if p['acao'] in ('melhorar', 'noindex')]

    print(f'# Poda trimestral dos cadernos ({o.hoje})\n')
    print(f'Fonte: Search Console, últimos 3 meses. {len(paginas)} páginas no índice; {len(zeradas)} sem nenhuma impressão.\n')
    print('Nada aqui sai do ar nem do app. "noindex" só tira a página do Google, e só com o OK do Jonatan.\n')
    for rot, chave in (('Sair do Google (2ª rodada zerada)', 'noindex'), ('Melhorar', 'melhorar'), ('Aguardar', 'aguardar')):
        grupo = [p for p in paginas if p['acao'] == chave]
        if grupo:
            print(f'## {rot} ({len(grupo)})\n')
            for p in grupo:
                print(f'- **/cadernos/{p["slug"]}/** ({p["escritos"]} temas): {p["motivo"]}')
            print()
    ok = sorted((p for p in paginas if p['acao'] == 'ok'), key=lambda p: -p['impressoes'])
    if ok:
        print(f'## Com impressões ({len(ok)})\n\n| página | impressões | cliques | posição |\n|---|---:|---:|---:|')
        for p in ok:
            print(f'| /cadernos/{p["slug"]}/ | {p["impressoes"]:.0f} | {p["cliques"]:.0f} | {p["posicao"]:.1f} |')
    if o.registrar:
        hist['rodadas'].append({'data': o.hoje, 'zeradas': zeradas})
        json.dump(hist, open(HIST, 'w', encoding='utf-8'), ensure_ascii=False, indent=1)
        print(f'\nRodada gravada em {os.path.relpath(HIST, RAIZ)} (a próxima compara com esta).')

if __name__ == '__main__':
    main()
