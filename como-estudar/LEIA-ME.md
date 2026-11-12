# Como estudar (guias de método de estudo)

Cada `NN-<slug>.html` vira um post nativo do WordPress em `/como-estudar/<slug>/`. O cabeçalho em comentário define
`slug`, `categoria` (nome exato de uma categoria de `_categorias.json`), `title (Rank Math)` (vira o título do post),
`description` (meta description e resumo), `palavra-chave` e `secundárias` (foco no Rank Math).

- O plugin sincroniza na primeira visita depois do deploy (`includes/como-estudar.php`).
- Post já existente só é atualizado pelo arquivo se não foi editado no painel depois da última sincronização. Editou no WordPress? A partir daí, vale o painel.
- `_hub.html` cria a página `/como-estudar/` uma vez só; depois disso ela é editada no painel.
- Regra editorial: só método de estudo. Conceito jurídico vai para os cadernos, julgado para os informativos, nada de nome de concurso no título e nada mirando "plano de estudo" (é da home).
