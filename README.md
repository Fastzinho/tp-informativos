# TP Informativos

Plugin WordPress do Tchau Pobreza: informativos do STJ e do STF comentados (tribunal > edição > julgado), buscador, calendário, "marcar como lido", flashcards para o Anki e dados estruturados.

## Deploy

O site se atualiza pelo próprio WordPress (`includes/atualizador.php`): ele lê o `Version:` do branch `estavel` e baixa o zip desse branch. Leva até 1 hora; "Verificar novamente" em Painel → Atualizações busca na hora.

- `main` é o branch de trabalho; para publicar, aumente o `Version:` (cabeçalho e `TPI_VERSION`) e envie o `main` para o `estavel`.
- O Git da Hostinger não é usado aqui: ele aceita um repositório por site, e essa vaga é do plugin do app (`Fastzinho/tchaupobreza`, branch `deploy`).
- Nunca edite arquivos direto no servidor: a próxima atualização sobrescreve.
