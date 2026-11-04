# TP Informativos

Plugin WordPress do Tchau Pobreza: informativos do STJ e do STF comentados (tribunal > edição > julgado), buscador, calendário, "marcar como lido", flashcards para o Anki e dados estruturados.

## Deploy

Igual ao plugin do app: a Hostinger (hPanel → GIT) puxa o branch `estavel` para `public_html/wp-content/plugins/tp-informativos`, com deploy automático por webhook a cada push.

- `main` é o branch de trabalho; para publicar, aumente o `Version:` (cabeçalho e `TPI_VERSION`) e envie o `main` para o `estavel`.
- Nunca edite arquivos direto no servidor: o próximo deploy sobrescreve.
- `includes/atualizador.php` é o plano B (atualização pelo próprio WordPress). Ele se desliga sozinho quando a pasta do plugin tem `.git`, ou seja, quando quem instala é o Git da Hostinger.
