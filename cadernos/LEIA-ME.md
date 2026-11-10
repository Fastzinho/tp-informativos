# Cadernos sistematizados

Cada arquivo `<disciplina>.json` desta pasta vira uma página `/cadernos/<slug>/`, com um card por tema
(âncora `#<slug-do-tema>`). O plugin sincroniza os posts sozinho na primeira visita depois do deploy;
não edite os cadernos no painel (a próxima mudança do JSON sobrescreve).

Vale para qualquer disciplina, jurídica ou não: os blocos vazios simplesmente não aparecem.

## Disciplina

| campo | uso |
|---|---|
| `slug`, `titulo` | obrigatórios. Endereço e H1 (ex.: `direito-penal`, "Caderno de Direito Penal") |
| `disciplina` | nome curto (hub e schema) |
| `descricao` | 1–2 frases: aparece no topo e vira a meta description |
| `ordem` | posição no hub `/cadernos/` |
| `ramo` | opcional: slug do ramo nos informativos, para o link "julgados comentados de…" |
| `revisado_em`, `revisado_rotulo` | data AAAA-MM-DD da conferência e o texto ("Lei e jurisprudência conferidas") |
| `autor` | opcional `{nome, credenciais, url}`. Sem ele, a assinatura é "Equipe Tchau Pobreza, com curadoria de <autor dos informativos>" |
| `fontes` | opcional (HTML curto); sem ele, disciplinas com lei/juris mostram as fontes oficiais |
| `temas` | lista na ordem do sumário |

## Tema

| campo | uso |
|---|---|
| `slug`, `titulo` | obrigatórios. Nunca renomeie o slug de um tema publicado (o lido do aluno usa o post) |
| `grupo` | cabeçalho do sumário (ex.: "Crimes contra a Administração Pública") |
| `base` | ex.: "Art. 312 do CP" |
| `palavras` | termos extras para a busca do sumário |
| `tax` | ids dos nós da taxonomia do app (`codigo-fonte/taxonomy-src/nodes.json` no repositório do app) que este tema explica. É o que liga o tema ao Estudo inteligente, ao "Meu plano" e, no futuro, à compilação do caderno por edital. Use o nó mais específico que existir; vários temas podem apontar para o mesmo nó (peculato e concussão → `penal.crimes-contra-administracao-publica`). Como o slug, depois de publicado não se renomeia: o app guarda a ligação |
| `dispositivos` | ids de dispositivo do acervo de leis do app (`n2848-1940-art-312`, `nCF-1988-art-144`), o mesmo formato do campo `unit` das questões. Liga o tema à questão que cita aquele artigo e ao artigo na Lei seca, com mais precisão do que o nó |
| `conceitos` | termos que ligam o tema aos "Conceitos de base" dos informativos: se o título do conceito contiver o título do tema ou um destes termos (palavra inteira, sem acento), o informativo ganha o link "Aprofundar no Caderno de…" com prévia. Só tema escrito |
| `revisado_em` | AAAA-MM-DD em que o curador leu o tema de fato. Só então aparece "Revisado por" no card e no schema |
| `incidencia` | `{nivel: alta\|media, base: "cobrado em 30 de 48 concursos Cebraspe/FGV com Direito Processual Penal, 2020–2026", bancas: {"Cebraspe": 22, "FGV": 8}}`. Vem da base de incidência (recorte "Todas", sem segmentar por cargo; LEIA-ME da base), só com N ≥ 12 concursos e depois de conferir os exemplos do nó. **Só Alta e Média**: "baixa" é ignorado no código, e "0 de N" nunca entra. Sem `base` não aparece. Contagens do QConcursos não entram |
| `resumo` | "Em 30 segundos" (HTML curto). **Tema sem resumo conta como não escrito** |
| `esquema` | `{rotulo, html}`: tabela em HTML, nunca imagem |
| `lei` | `[{texto, fonte, url, norma?}]`: texto literal do acervo oficial; parágrafos separados por linha em branco. `norma` = id do acervo (ex.: `n2848-1940`); sem ele, sai do número em `fonte`. Conferido por `ferramentas/conferir_lei.py` |
| `macetes` | `[{sigla, texto}]` |
| `juris` | `[{quem, texto, url?, link?}]`: `url` de preferência para o julgado em /informativos/ (rótulo "Ver julgado comentado"); link de tribunal ganha "Ver no site do tribunal" |
| `pegadinha` | `{afirmacao, gabarito: "C"\|"E", explicacao}` |
| `aprofundamento`, `aprofundamento_rotulo` | HTML do bloco recolhível (doutrina, divergências) |
| `praticar` | link do botão; padrão `/estudar/` |
| `relacionados` | slugs de temas da mesma disciplina; sem eles, os vizinhos do sumário |
| `pagina_propria` | `true` dá URL própria ao tema (`/cadernos/<disciplina>/<tema>/`); sem isso, o endereço redireciona para a âncora |

## Indexação

A disciplina fica `noindex,follow` e fora do sitemap até ter `tpc_min_index` temas escritos (padrão 8;
filtro `tpc_min_index`). O hub `/cadernos/` é criado uma vez, com `[tp_cadernos]`, e fica `noindex`
enquanto nenhuma disciplina for indexável.

## Link a partir dos informativos (0.8.1)

O card de conceito de um julgado pode apontar para um tema: no JSON do julgado, o conceito ganha
`"caderno": "direito-penal#peculato"` (ou uma lista) e o `render.py` põe `[tp_caderno tema="direito-penal#peculato"]`
no fim do card. O plugin troca isso por um `<a href>` real ("Aprofundar no Caderno de Direito Penal: Peculato →")
**só quando o tema está publicado e escrito**; antes disso não aparece nada. Por isso, **nunca renomeie o slug de
um tema** que já tenha sido referenciado: os julgados antigos guardam a referência `disciplina#tema`.

## Ligação com o app (0.11.0)

`tax` e `dispositivos` alimentam `GET /wp-json/tp/v1/cadernos-indice` (só temas escritos, cache de 1 h). O app usa esse
índice para mostrar "Ler o resumo" no tópico do cronograma e "Rever teoria" depois de responder uma questão, e abre o
tema num painel com `?tp_embed=1` no endereço do próprio tema (`/cadernos/<disciplina>/<tema>/?tp_embed=1`): só o card,
sem cabeçalho, rodapé nem o botão Praticar, `noindex` e canonical na âncora do caderno. O conteúdo continua só no JSON.
Todo tema novo já deve sair com os dois campos preenchidos (o `ferramentas/conferir_lei.py` confere os ids).


## Conferir a lei seca antes de publicar

```
python3 ferramentas/conferir_lei.py --acervo ../tchaupobreza/codigo-fonte/public/legal
```

Compara cada parágrafo de `lei` com o artigo do acervo que o app usa na Lei seca (o mesmo texto da Câmara).
Pode omitir incisos e parágrafos; não pode trocar palavra, pena ou número. Notas de redação ("(Incluído pela...)")
e epígrafes não contam. Sai com código 1 se houver divergência. O acervo está no repositório do app (privado),
por isso o script não roda no CI daqui: rode antes de abrir o PR.

Atenção às leis de 2025–2026 (ex.: Leis 15.280/2025, 15.353/2026 e 15.397/2026 mudaram penas do CP). O texto do
acervo vence a memória de quem escreve: escreva resumo, esquema e pegadinha a partir dele.

Temas que os informativos já pedem e ainda não estão escritos: `cadernos/FILA.md`. Ao escrever um deles, use o slug de lá e apague a linha.
