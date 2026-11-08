# TP Informativos

Plugin WordPress do Tchau Pobreza: informativos do STJ e do STF comentados (tribunal > edição > julgado), buscador, calendário, "marcar como lido", flashcards para o Anki e dados estruturados.

## Deploy

**Deploy (desde a 0.7.21):** todo merge no `main` dispara `.github/workflows/deploy.yml`, que envia o plugin ao servidor da Hostinger por SSH (rsync), limpa o cache do LiteSpeed e mostra a versão instalada. Segredos em Settings → Secrets and variables → Actions (`HOSTINGER_HOST`, `HOSTINGER_PORT`, `HOSTINGER_USER`, `HOSTINGER_PASSWORD` ou `HOSTINGER_SSH_KEY`, e opcional `HOSTINGER_WP_PATH`). Sem os segredos, o deploy é pulado com aviso. O arquivo `.deploy-actions` gravado no servidor desliga o atualizador antigo.

Antes da 0.7.21, o site se atualizava pelo próprio WordPress (`includes/atualizador.php`), lendo o `Version:` do branch `estavel`. Esse caminho continua no código como reserva.

- `main` é o branch de trabalho; para publicar, aumente o `Version:` (cabeçalho e `TPI_VERSION`) e envie o `main` para o `estavel`.
- O Git da Hostinger não é usado aqui: ele aceita um repositório por site, e essa vaga é do plugin do app (`Fastzinho/tchaupobreza`, branch `deploy`).
- Nunca edite arquivos direto no servidor: a próxima atualização sobrescreve.

## Cobertura retroativa (0.7)

- `tp_tipo = teses_hub` e `teses`: hub e edições do Jurisprudência em Teses do STJ (`/informativos/stj/jurisprudencia-em-teses/edicao-NNN-tema/`) e grupos de teses de repercussão geral do STF (`/informativos/stf/teses-de-repercussao-geral/<assunto>/`). Uma página por edição.
- `tp_situacao` (`vigente`, `superada`, `revista`, `em_discussao`), `tp_situacao_motivo` e `tp_situacao_em`: faixa no topo do julgado e da edição de Teses quando a tese não está vigente.
- `tp_jt_edicao` no julgado: selo "Esta tese também está na Edição N do Jurisprudência em Teses", com link quando a edição tiver página.
- A faixa reaproveita `.tpi-aviso`; classes novas sem estilo próprio ainda: `.tpi-situacao--*`, `.tpi-situacao-chip`, `.tpi-selo-teses`, `.tpi-teses-lista`.
