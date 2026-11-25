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
| `indexar` | opcional. `false` tira a página do Google (`noindex,follow`, fora do sitemap) **sem tirar nada do ar**: a página, o hub, o app e os links seguem iguais. Só por decisão da poda trimestral (seção abaixo), nunca na criação |
| `temas` | lista na ordem do sumário |

## Tema

| campo | uso |
|---|---|
| `slug`, `titulo` | obrigatórios. Nunca renomeie o slug de um tema publicado (o lido do aluno usa o post) |
| `grupo` | cabeçalho do sumário (ex.: "Crimes contra a Administração Pública") |
| `base` | ex.: "Art. 312 do CP" |
| `palavras` | termos extras para a busca do sumário |
| `tax` | ids dos nós da taxonomia do app (`codigo-fonte/taxonomy-src/nodes.json` no repositório do app) que este tema explica. É o que liga o tema ao Estudo inteligente, ao "Meu plano" e, no futuro, à compilação do caderno por edital. Use o nó mais específico que existir; vários temas podem apontar para o mesmo nó (peculato e concussão → `penal.crimes-contra-administracao-publica`). Tema por bloco de artigos de uma lei que já tem tema geral (ex.: os três blocos do Abuso de autoridade) fica com `tax: []` e se liga pelos `dispositivos`: o app só usa o nó quando ele leva a um tema só, então o nó continua abrindo o tema geral, que tem o mapa da lei, e a questão que cita o artigo abre o tema do bloco. Como o slug, depois de publicado não se renomeia: o app guarda a ligação |
| `dispositivos` | ids de dispositivo do acervo de leis do app (`n2848-1940-art-312`, `nCF-1988-art-144`), o mesmo formato do campo `unit` das questões. Liga o tema à questão que cita aquele artigo e ao artigo na Lei seca, com mais precisão do que o nó. Disciplina sem lei seca (Língua Portuguesa, Raciocínio Lógico, Informática) deixa `[]`; só tema com bloco `lei` precisa dele |
| `conceitos` | termos que ligam o tema aos "Conceitos de base" dos informativos: se o título do conceito contiver o título do tema ou um destes termos (palavra inteira, sem acento), o informativo ganha o link "Aprofundar no Caderno de…" com prévia. Só tema escrito |
| `revisado_em` | AAAA-MM-DD em que o curador leu o tema de fato. Só então aparece "Revisado por" no card e no schema |
| `mudancas` | `[{em: "AAAA-MM-DD", tipo: "lei"\|"correcao"\|"juris", nota: "1 frase, até 180 caracteres"}]`. Acende o selo **Atualizado** (com a nota e o botão "Entendi") para quem marcou o tema como lido **antes** dessa data, no site e no passo "Resumo do caderno" do app. Só o que muda a resposta de quem já leu: **lei** nova ou alterada, **correcao** de afirmação, número ou gabarito errado, **juris** que muda a conclusão (tese superada, súmula nova). **Não** registre: reescrita, vírgula, macete, exemplo ou pegadinha nova, reordenação, tema novo. Vale a entrada com a data mais recente; as antigas ficam como histórico. `conferir_lei.py --desde origin/main` avisa quando a lei, o gabarito ou a jurisprudência mudou sem entrada nova |
| `incidencia` | `{nivel: alta\|media, base: "cobrado em 30 de 48 concursos Cebraspe/FGV com Direito Processual Penal, 2020–2026", bancas: {"Cebraspe": 22, "FGV": 8}}`. Vem da base de incidência (recorte "Todas", sem segmentar por cargo; LEIA-ME da base), só com N ≥ 12 concursos e depois de conferir os exemplos do nó. **Só Alta e Média**: "baixa" é ignorado no código, e "0 de N" nunca entra. Sem `base` não aparece. Contagens do QConcursos não entram |
| `resumo` | "Em 30 segundos" (HTML curto). **Tema sem resumo conta como não escrito** |
| `esquema` | `{rotulo, html}`: tabela em HTML, nunca imagem |
| `figura` | opcional `{rotulo, src, largura, altura, src_celular, largura_celular, altura_celular, alt, legenda}`. Ilustração depois do esquema, com arquivos em `assets/figuras/` (`src: "figuras/<nome>.svg"`). **Só onde o desenho ensina** (estrutura, posição, fluxo, linha do tempo), nunca enfeite. O `esquema` em HTML continua obrigatório: é o texto equivalente que o Google e o leitor de tela leem. `alt` descreve o que a figura ensina; sem ele a figura não aparece. Arte larga precisa de `src_celular` (vertical, até 560 de largura), senão a letra some no celular. SVG sem fonte externa e com `<title>`/`<desc>` |
| `figuras` | opcional (0.26.0, playbook 13.2) `{titulo?, rotulo?, colunas?: [3 rótulos], linhas: [{grupo?, nome, o_que_e, atencao?}]}`. Tabela "Cada figura, em uma linha" depois do esquema: toda figura, espécie ou classificação que a prova cobra, com definição de uma linha e o ponto de atenção. `titulo` troca o cabeçalho (ex.: "Cada classificação, em uma linha"); `grupo` abre uma faixa dentro da tabela. É a camada de completude do tema: nada que caia em questão fica só como rótulo |
| `mapa` | opcional (0.23.0), só no tema geral de uma lei penal: `[{norma, rotulo, de, ate, fora: [], linhas: [{art, conduta, pena, troca?}]}]`. Tabela "Mapa da lei" com **todos** os crimes da norma entre os artigos `de` e `ate`, depois do esquema (recolhida quando passa de 12 linhas). `python3 ferramentas/mapa_lei.py --acervo <legal> --gerar` acrescenta a linha que falta para cada pena do acervo ("Pena -", "a pena é de", "incorre na pena de", "punível com"), com a conduta tirada do artigo; linha existente não é tocada, então a conduta pode ser reescrita à mão. `troca` (o que a banca troca: hediondez, súmula, requisito que cai) é sempre à mão e só onde há cobrança. Tipo sem pena escrita no artigo (art. 28 da Lei de Drogas) entra à mão, com a pena copiada do texto; várias medidas separadas por "; ". Artigo de lei que altera outra (ex.: art. 33 da 15.358 mexendo no CP) fica fora pelo `ate` ou em `fora` (rótulo da linha, ex.: `"33, § 3º-A"`). O `conferir_lei.py` trava se faltar crime, se a pena não estiver no texto do acervo ou se o artigo não existir. Tema por bloco de artigos não repete o mapa: o mapa mora no tema geral |
| `lei` | Dois formatos. **Contextualizado (padrão desde 0.24.0, piloto no Penal):** `[{norma?, artigos: ["Art. 13."], ref: "art. 13 do CP", regras: ["<strong>§ 1º.</strong> regra explicada, com pena"], trechos?: ["frase literal"], literalidades?: [{trecho, marcas: ["palavra"], afirmacao, gabarito: "C"\|"E", porque}], conferido: {}, fonte, url}]`. `literalidades` (0.26.0, playbook 13.3): só onde a banca troca uma palavra do texto; o card mostra o trecho com as `marcas` destacadas e, logo abaixo, a afirmação de prova, "Gabarito da afirmação acima" e o porquê. O `conferir_lei.py` exige o trecho literal e cada marca dentro dele. O card mostra "Na lei seca": a regra de cada dispositivo com redação própria (todo dispositivo que antes era copiado entra, inclusive penas, para nada sumir), só as frases cuja redação exata é a pegadinha em `trechos` (literais do acervo) e a linha "Leia na íntegra: art. X" com a janela da lei e o link para a Lei seca do app. Não substitui a leitura do artigo: aponta para ela. `conferido` guarda o hash de cada artigo do acervo; rode `--carimbar` depois de reler as regras. Se a lei mudar, o hash não bate e o `conferir_lei.py` trava até alguém reescrever a regra. **Literal (antigo):** `[{texto, fonte, url, norma?}]`: artigo inteiro do acervo (gere com `--completar`); parágrafos separados por linha em branco. `norma` = id do acervo (ex.: `n2848-1940`); sem ele, sai do número em `fonte`. Conferido por `ferramentas/conferir_lei.py` |
| `macetes` | `[{sigla, texto}]`. A `sigla` é um título curto em frase (ex.: "DEBILIDADE × PERDA"). Sigla de letras só se for consagrada em aula e em questão (MARI, LUTA, LEEE, IPE, CADI, MODERECOPA, AIEV); nunca invente uma |
| `juris` | `[{quem, texto, url?, link?}]`: `url` de preferência para o julgado em /informativos/ (rótulo "Ver julgado comentado"); link de tribunal ganha "Ver no site do tribunal" |
| `pegadinha` | `{afirmacao, gabarito: "C"\|"E", explicacao}` |
| `ja_caiu` | opcional (0.26.0, playbook 13.7) lista de provas que confirmam o card, ex.: `"Cebraspe, Delegado PC-PE 2024 (o titular é o povo)"`. Uma linha discreta no topo do card, fora do texto de estudo |
| `para_entender` | opcional (0.26.0, playbook 13.8) `[{texto, tema?}]`, até 3. "Para entender este tema", antes do "Em 30 segundos": o conceito de outro tema numa frase simples e, com `tema` (slug da mesma disciplina), o link para ele. Só em tema que depende de conceito de fora |
| `detalhes` | opcional (0.26.0, playbook 13.7) lista de HTML curto. "Detalhes que caem", visível: regra, exceção ou distinção que a prova cobra e que não cabe em figuras, lei, juris ou pegadinhas. Um ponto por item, sem subtítulos |
| `pegadinhas` | opcional (0.26.0) `[{errado, certo, fonte}]`. "Pegadinhas que já caíram", visível, depois da pegadinha do card; `fonte` = banca e prova |
| `aprofundamento`, `aprofundamento_rotulo` | HTML do bloco recolhível. **Tema reformado (playbook 13.7) não usa:** o conteúdo vai para `figuras`, `juris`, literalidades, `pegadinhas`, `detalhes` e `ja_caiu`. Nos demais, **só o que o card de cima não diz** (playbook, seção 12): começa, se houver, por `<p>Já caiu em: …</p>` (as provas que confirmam o card, numa linha) e segue em até quatro seções fixas, nesta ordem e só as que tiverem conteúdo: `<p><strong>Exceções e detalhes cobrados</strong></p>`, `<p><strong>Distinções</strong></p>`, `<p><strong>Pegadinhas que já caíram</strong></p>` ("Errado: … Certo: … (banca, prova)", sem repetir a `pegadinha` do card), `<p><strong>Jurisprudência além da básica</strong></p>`. Cada súmula, artigo ou conceito aparece uma vez no tema; rodada nova (régua, revisão, questões do app) entra fundida na seção certa, nunca como bloco colado no fim. Tudo amarrado a lei, julgado ou prova real; nada de "parte da doutrina" sem fonte. `ferramentas/repeticao.py` aponta o que repete. Rótulo padrão: "exceções que caem em prova" |
| `praticar` | link do botão; padrão `/estudar/` |
| `relacionados` | slugs de temas da mesma disciplina; um slug de outro caderno também vale, se for único entre os temas publicados (0.23.1). Sem eles, os vizinhos do sumário |
| `pagina_propria` | `true` dá URL própria ao tema (`/cadernos/<disciplina>/<tema>/`); sem isso, o endereço redireciona para a âncora |

## Indexação

A disciplina fica `noindex,follow` e fora do sitemap até ter `tpc_min_index` temas escritos (padrão 8;
filtro `tpc_min_index`). O hub `/cadernos/` é criado uma vez, com `[tp_cadernos]`, e fica `noindex`
enquanto nenhuma disciplina for indexável.

## Poda trimestral e sobreposição (0.21.3)

Os cadernos alimentam as questões e o Estudo inteligente, então **nenhum tema é apagado** por causa do Google. A poda
decide só a indexação. Regra completa no playbook de conteúdo, seção 11.

- **Ao criar ou mudar caderno:** o CI do PR roda `python3 ferramentas/sobreposicao.py --ci`. Erro com slug de tema
  repetido em dois cadernos (quebra a ref `disciplina#tema` de outro caderno); aviso com tema que parece tratar do
  mesmo assunto de outro (título parecido ou muitas `palavras`/`conceitos` em comum). Resolver: enxugar o novo e
  remeter ao existente, ou, se são ângulos diferentes de propósito, registrar com `--aceitar`.
- **A cada trimestre (20 de fev/mai/ago/nov):** exportar Desempenho > Páginas (3 meses) do Search Console e rodar
  `python3 ferramentas/poda_cadernos.py --gsc export.zip --registrar`. Página com 0 impressões: 1ª vez, melhorar;
  2ª vez seguida, sugerir `"indexar": false` para o Jonatan decidir. Página com menos de 90 dias no ar fica de fora.
  Os dados da poda ficam em `ferramentas/poda/` (fora do deploy).

## Mudar um tema de caderno (0.22.0)

Para dividir um caderno grande (critérios no playbook, seção 12), **mova o tema inteiro** para o JSON do caderno novo,
sem mudar o `slug`. O sincronizador reaproveita o post que já existe e troca só o caderno-pai: o id fica o mesmo, e com
ele o lido do aluno, o selo Atualizado e o índice do app. Os endereços velhos seguem funcionando: `/cadernos/<antigo>/<tema>/`
responde 301 para o lugar novo (com `?tp_embed=1` do app junto), e `/cadernos/<antigo>/#<tema>` leva ao lugar novo por um
script curto na página antiga. As refs `antigo#tema` dos informativos já acham o tema em qualquer caderno. Nunca apague e
recrie o tema com outro slug: isso cria outro post e o aluno perde o lido.

## Repetição dentro do tema (0.22.0)

`python3 ferramentas/repeticao.py [caderno…] [--tema slug]` lista, tema a tema, o que o aprofundamento repete do card.
No CI do PR, só os temas que o PR criou ou cujo aprofundamento mudou: **trava** com bloco "Mais pontos que as questões
cobram" colado no fim ou subtítulo repetido; **avisa** (sem travar) bloco com 60% ou mais das palavras já no card, a mesma
súmula/artigo em dois blocos e aprofundamento acima de 700 palavras e maior que o card.

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

No formato contextualizado, confere que cada artigo de `artigos` existe, que o hash em `conferido` é o do acervo atual e que cada `trecho` é literal; sem travessão nas regras. Depois de escrever ou revisar as regras, carimbe:

```
python3 ferramentas/conferir_lei.py --carimbar --acervo ../tchaupobreza/codigo-fonte/public/legal cadernos/<caderno>.json
```

No formato literal, compara cada parágrafo de `lei` com o artigo do acervo que o app usa na Lei seca (o mesmo texto da Câmara).
Não pode trocar palavra, pena ou número, e o artigo vai **inteiro**, na ordem do acervo, com epígrafes e os
parágrafos acrescidos depois (`§ 4º-A`, `§ 12`). Omitir só com uma linha `(...)` no ponto do corte, que aparece para
o aluno; corte calado reprova. Para não copiar à mão, gere o texto completo do acervo:

```
python3 ferramentas/conferir_lei.py --completar --acervo ../tchaupobreza/codigo-fonte/public/legal
``` Notas de redação ("(Incluído pela...)")
e epígrafes não contam. Sai com código 1 se houver divergência. O acervo está no repositório do app (privado),
por isso o script não roda no CI daqui: rode antes de abrir o PR.

Atenção às leis de 2025–2026 (ex.: Leis 15.280/2025, 15.353/2026 e 15.397/2026 mudaram penas do CP). O texto do
acervo vence a memória de quem escreve: escreva resumo, esquema e pegadinha a partir dele.

Temas que os informativos já pedem e ainda não estão escritos: `cadernos/FILA.md`. Ao escrever um deles, use o slug de lá e apague a linha.
Se o tema couber melhor em outro caderno (ex.: `direito-penal#trafico-de-drogas` escrito na Legislação Penal Especial), basta manter o slug: o link do julgado acha o tema publicado com esse slug em qualquer caderno, desde que o slug seja único entre os cadernos.

## Questões do app que citam o material de apoio (2026-11-19)

O "Rever teoria" das questões com "Do material de apoio" só abre um tema que **traz o conceito citado** (caso Q-9117:
a questão citava pharming e o tema de segurança não falava disso). O app mede isso com uma cópia do texto dos temas,
`codigo-fonte/questions-src/cadernos/indice.json`. Depois de mudar um caderno, no repositório do app:

```
node scripts/questions/cadernos-sync.mjs ../../tp-informativos/cadernos
node scripts/questions/build.mjs
```

Tirar de um tema um ponto que questões citam faz o link delas sumir no próximo build (não quebra nada). Os pontos que
as questões cobravam e o caderno não trazia entraram no aprofundamento, em "Mais pontos que as questões cobram".
