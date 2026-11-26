# Como estudar (guias de método de estudo)

Cada `NN-<slug>.html` vira um post nativo do WordPress em `/como-estudar/<slug>/`. O cabeçalho em comentário define
`slug`, `categoria` (nome exato de uma categoria de `_categorias.json`), `title (Rank Math)` (vira o título do post),
`description` (meta description e resumo), `palavra-chave` e `secundárias` (foco no Rank Math).

- O plugin sincroniza na primeira visita depois do deploy (`includes/como-estudar.php`).
- Post já existente só é atualizado pelo arquivo se não foi editado no painel depois da última sincronização. Editou no WordPress? A partir daí, vale o painel.
- `_hub.html` cria a página `/como-estudar/` uma vez só; depois disso ela é editada no painel.
- Regra editorial: só método de estudo. Conceito jurídico vai para os cadernos, julgado para os informativos, nada de nome de concurso no título e nada mirando "plano de estudo" genérico. Desde 26/11/2026, "plano/planejamento de estudos para concurso" e "planilha de estudos para concurso" são do guia de cronograma (SERP igual à de "cronograma"); a home mira "plataforma de estudos para concursos".

## Gerador de cronograma (0.29.0)
O guia `16-cronograma-de-estudos-para-concurso.html` traz o shortcode `[tp_cronograma]`, desenhado por `includes/cronograma.php` (o post é gravado sem usuário e o kses tiraria `<input>` do corpo). A conta roda no navegador (`assets/tpcrono.js`), sem enviar nada ao servidor. "Carregar um edital" lê os concursos publicados pela rota `/wp-json/tp/v1/cronograma-edital/<slug>`; a lista mostra só os indexáveis. Link direto: `?edital=<slug>#gerador` (a seção "Como estudar para X" das páginas /concursos/ já usa). Regras práticas do gerador (reta final de 1 a 4 semanas, 20% do dia para revisão, metade pelo peso e metade pelo tamanho) estão ditas no texto do guia; mudou no JS, mude no texto.
