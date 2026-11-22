# Páginas de concurso (/concursos/<slug>/)

Guia de estudos + edital verticalizado por concurso. Código em `includes/concursos.php`, estilos em `assets/tpcon.css`, marcações T/Q/R em `assets/tpcon.js` (salvas no aparelho).

## Arquivos
- `<slug>.json`: parte editorial, uma por concurso. Sincroniza com o post (tipo `concurso`) no deploy, como os cadernos.
- `dados-app.json`: cópia de reserva das questões por tópico. Vale só se o app instalado ainda não tiver `wp-content/plugins/tchau-pobreza/app/concursos.json` (gerado no build do app por `scripts/coverage/concursos.ts`). Quando o app tem o arquivo, a página usa o dele e se atualiza a cada deploy do app.
- `links.json`: ligação do edital com os cadernos (o link só aparece se o tema estiver publicado e escrito).
  - `disciplinas`: disciplina do edital → cadernos, só para nomes que não batem com o campo `disciplina` do caderno (ex.: "Direito Civil e Empresarial"). O prefixo "Noções de" e os acentos já são ignorados, então "Noções de Direito Administrativo" acha o caderno de Direito Administrativo sozinho.
  - `regras`: tópico → tema (regex no título, vale a primeira que casar; `"tema": ""` tira o link). Sem regra, o tópico casa sozinho com o título ou os `conceitos` dos temas dos cadernos da própria disciplina (termo mais longo vence). Use regra só para corrigir casamento errado ou ligar o que o automático não acha.

## Campos do `<slug>.json`
| campo | uso |
|---|---|
| `slug`, `ordem` | URL e ordem no hub |
| `plano` | id do plano no catálogo do app (ex.: `pmpe`); liga os tópicos e o botão do app (`/estudar/?plano=`) |
| `sigla`, `cargo`, `orgao`, `ano` | título "Guia de estudos SIGLA ANO: cargo" (sem `ano` quando não há edital novo) |
| `edital_anterior` | ano do último edital, quando ainda não há edital novo (mostra o aviso na ficha) |
| `banca`, `vagas`, `inscricoes_ate`, `prova_data`, `prova`, `edital`, `retificacao`, `url_banca` | ficha do edital (datas AAAA-MM-DD; os dias que faltam são calculados no navegador) |
| `conferido_em` | data em que conferimos o edital (ficha, assinatura e `lastReviewed`) |
| `palavra_chave`, `secundarias`, `descricao` | Rank Math (foco, secundárias e descrição) |
| `banca_texto` | parágrafo escrito à mão sobre como a banca cobra (HTML simples) |

## Regras
- Página de estudo, não de notícia: nada de salário, requisitos ou cronograma completo.
- Vocabulário: "questões" (nunca Certo/Errado ou C/E) e sem "grátis".
- Indexa só com 60% ou mais dos tópicos com questões no app (`tpcon_min_index`); abaixo disso, noindex e fora do sitemap.
- Concurso novo: crie o JSON com um `plano` que exista no app. Confira os fatos do `banca_texto` no edital.
