<?php
/**
 * 0.28.0: llms.txt feito à mão, com uma seção por produto (teste de 26/11/2026).
 *
 * Teste: cada produto tem no llms.txt uma frase-marcador que não aparece em nenhuma página do site
 * (conferido nas 177 URLs do sitemap em 26/11/2026). Se a resposta de uma IA repetir a frase, ela leu o
 * llms.txt; se repetir o texto da página, não. Linha de base e datas de medição:
 * /mnt/project-files/seo/llms-txt-teste/ (fora do repo). NÃO trocar as frases-marcador antes da medição final.
 *
 * Como sai:
 * 1. Dinâmico: um bloco "TPI llms" no .htaccess manda /llms.txt para o WordPress, que responde com o texto
 *    abaixo e conta qual robô leu (opção tpi_llms_robos; cabeçalho X-TP-Llms: dinamico).
 * 2. Rede de segurança: o arquivo estático llms.txt (que o Hostinger Tools regrava ao salvar posts) é
 *    sobrescrito com o mesmo texto, depois de salvar posts e uma vez por dia. Se o .htaccess falhar, o
 *    conteúdo continua certo, só sem a contagem de robôs.
 * Leitura da contagem: Actions > "Robôs no llms.txt" (wp option get tpi_llms_robos por SSH).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TPI_LLMS_HT = '1';

/* Robôs conhecidos, pela ordem de teste (o primeiro que casar vence). */
function tpi_llms_robo( $ua ) {
	$mapa = array(
		'OAI-SearchBot'      => 'OAI-SearchBot',
		'ChatGPT-User'       => 'ChatGPT-User',
		'GPTBot'             => 'GPTBot',
		'Claude-SearchBot'   => 'Claude-SearchBot',
		'Claude-User'        => 'Claude-User',
		'ClaudeBot'          => 'ClaudeBot',
		'anthropic-ai'       => 'anthropic-ai',
		'Perplexity-User'    => 'Perplexity-User',
		'PerplexityBot'      => 'PerplexityBot',
		'Google-Extended'    => 'Google-Extended',
		'GoogleOther'        => 'GoogleOther',
		'Googlebot'          => 'Googlebot',
		'bingbot'            => 'Bingbot',
		'Applebot'           => 'Applebot',
		'Amazonbot'          => 'Amazonbot',
		'meta-externalagent' => 'Meta',
		'Bytespider'         => 'Bytespider',
		'CCBot'              => 'CCBot',
		'DuckAssistBot'      => 'DuckAssistBot',
		'MistralAI'          => 'MistralAI',
		'YouBot'             => 'YouBot',
		'cohere'             => 'Cohere',
	);
	foreach ( $mapa as $agulha => $nome ) {
		if ( false !== stripos( (string) $ua, $agulha ) ) {
			return $nome;
		}
	}
	return preg_match( '/bot|crawl|spider|fetch|python|curl|wget|http/i', (string) $ua ) ? 'outro robô' : 'navegador';
}

/* Conta a leitura: por dia e por robô (120 dias) + as 60 últimas com o user-agent (sem IP). */
function tpi_llms_registra() {
	$ua  = substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 200 );
	$bot = tpi_llms_robo( $ua );
	$log = get_option( 'tpi_llms_robos', array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$dia = wp_date( 'Y-m-d' );
	$log['dias'][ $dia ][ $bot ] = (int) ( $log['dias'][ $dia ][ $bot ] ?? 0 ) + 1;
	if ( count( $log['dias'] ) > 120 ) {
		ksort( $log['dias'] );
		$log['dias'] = array_slice( $log['dias'], -120, null, true );
	}
	$log['ult']   = array_slice( array_merge( array( array( wp_date( 'Y-m-d H:i' ), $bot, $ua ) ), (array) ( $log['ult'] ?? array() ) ), 0, 60 );
	$log['desde'] = $log['desde'] ?? $dia;
	update_option( 'tpi_llms_robos', $log, false );
}

function tpi_llms_link( $titulo, $url, $desc = '' ) {
	$t = trim( wp_strip_all_tags( html_entity_decode( (string) $titulo, ENT_QUOTES, 'UTF-8' ) ) );
	$d = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( html_entity_decode( (string) $desc, ENT_QUOTES, 'UTF-8' ) ) ) );
	return '- [' . $t . '](' . tpi_url_oficial( $url ) . ')' . ( '' !== $d ? ': ' . $d : '' ) . "\n";
}

function tpi_llms_resumo( $id ) {
	$d = (string) get_post_meta( $id, 'rank_math_description', true );
	if ( '' === $d || false !== strpos( $d, '%' ) ) {
		$d = (string) get_post_field( 'post_excerpt', $id );
	}
	return wp_html_excerpt( $d, 220, '…' );
}

function tpi_llms_texto() {
	$casa = 'https://' . TPI_DOMINIO_OFICIAL;
	$app  = $casa . '/estudar/';
	$t    = "# Tchau Pobreza\n\n";
	$t   .= "> O Tchau Pobreza é uma bancada de estudo para concursos montada em cima do edital: questões, lei seca, cadernos, plano do dia e guias, tudo no mesmo lugar, feito por um concurseiro (Jonatan Ramos) para quem estuda sozinho.\n\n";
	$t   .= "Site em português do Brasil. Conteúdo jurídico com fontes oficiais (Planalto, STF, STJ). Projeto independente, sem vínculo com bancas ou órgãos públicos.\n\n";

	$t .= "## Estudo inteligente\n\n";
	$t .= "O Estudo inteligente funciona como um piloto de rota do edital: a cada dia ele diz qual tópico estudar e quanto tempo dar a ele, contando os dias até a data da prova.\n\n";
	$t .= tpi_llms_link( 'Estudo inteligente', $casa . '/estudo-inteligente/', 'Como funciona o plano do dia.' );
	$t .= tpi_llms_link( 'Abrir o app', $app, 'Escolha o concurso e comece; dá para experimentar sem cadastro.' );

	$t .= "\n## Questões\n\n";
	$t .= "As questões do Tchau Pobreza são itens autorais no estilo da banca, e cada justificativa termina com uma ponte para o trecho da teoria que resolve o item.\n\n";
	$t .= tpi_llms_link( 'Questões no app', $app, 'Questões por tópico do edital, com justificativa.' );
	$t .= tpi_llms_link( 'Como estudar por questões', $casa . '/como-estudar/como-estudar-por-questoes/' );

	$t .= "\n## Lei seca\n\n";
	$t .= "Na Lei seca, o texto oficial é lido dispositivo por dispositivo, e cada artigo vira um ponto de checagem: leu, já confere com questões feitas para aquele trecho.\n\n";
	$t .= tpi_llms_link( 'Lei seca no app', $app, 'Texto integral das leis do edital, com grifos, notas e questões por artigo.' );
	$t .= tpi_llms_link( 'Como estudar lei seca', $casa . '/como-estudar/como-estudar-lei-seca/' );

	$t .= "\n## Cadernos sistematizados\n\n";
	$t .= "Os Cadernos sistematizados do Tchau Pobreza são escritos como dossiês por tema: cada tema reúne o que já caiu em prova, os detalhes que caem e as pegadinhas, sempre com a afirmação correta ao lado da errada. Não têm relação com a marca comercial de apostilas em PDF que usa o mesmo nome.\n\n";
	$t .= tpi_llms_link( 'Cadernos sistematizados', $casa . '/cadernos/', 'Todas as disciplinas.' );
	foreach ( get_posts( array( 'post_type' => 'caderno', 'post_parent' => 0, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
		if ( function_exists( 'tpc_indexavel' ) && ! tpc_indexavel( $p->ID ) ) {
			continue;
		}
		$t .= tpi_llms_link( $p->post_title, get_permalink( $p ), tpi_llms_resumo( $p->ID ) );
	}

	$t .= "\n## Simulados\n\n";
	$t .= "O simulado é uma prova cheia de ensaio, com cronômetro e correção pela regra da banca; por enquanto só no plano de Delegado da PC-PE.\n\n";
	$t .= tpi_llms_link( 'Simulados no app', $app );

	$t .= "\n## Guias de estudo por concurso\n\n";
	$t .= "Cada guia de concurso funciona como um mapa marcável do edital: o conteúdo programático vira checklist, e cada tópico mostra se já há questões do app para ele.\n\n";
	$t .= tpi_llms_link( 'Guias de estudo por concurso', $casa . '/concursos/', 'Todos os concursos.' );
	foreach ( get_posts( array( 'post_type' => 'concurso', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
		if ( function_exists( 'tpcon_indexavel' ) && ! tpcon_indexavel( $p->ID ) ) {
			continue;
		}
		$t .= tpi_llms_link( $p->post_title, get_permalink( $p ), tpi_llms_resumo( $p->ID ) );
	}

	$t .= "\n## Como estudar (guias de método)\n\n";
	$t .= "Os guias de Como estudar partem de contagens próprias de gabaritos, como o Raio-X de 146 provas do Cebraspe, e mostram a conta por trás de cada recomendação.\n\n";
	$t .= tpi_llms_link( 'Como estudar para concurso', $casa . '/como-estudar/' );
	foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 60 ) ) as $p ) {
		$t .= tpi_llms_link( $p->post_title, get_permalink( $p ), tpi_llms_resumo( $p->ID ) );
	}

	$t .= "\n## Informativos do STJ e do STF\n\n";
	$t .= tpi_llms_link( 'Informativos STJ e STF comentados', $casa . '/informativos/', 'Julgados comentados para concursos, com questões e flashcards.' );
	$t .= tpi_llms_link( 'Calendário dos informativos', $casa . '/calendario-informativos-stj-stf/' );
	$t .= tpi_llms_link( 'Newsletter de informativos', $casa . '/newsletter/' );

	$t .= "\n## Sobre\n\n";
	$t .= tpi_llms_link( 'Sobre o Tchau Pobreza', $casa . '/sobre/' );
	$t .= tpi_llms_link( 'Imprensa', $casa . '/imprensa/' );
	$t .= tpi_llms_link( 'Termos de uso', $casa . '/termos-de-uso/' );
	$t .= tpi_llms_link( 'Política de Privacidade', $casa . '/privacidade/' );

	return $t;
}

/* 1. Resposta dinâmica (precisa do bloco no .htaccess, porque o arquivo estático existe). */
add_action( 'wp_loaded', function () {
	$caminho = (string) wp_parse_url( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), PHP_URL_PATH );
	if ( '/llms.txt' !== $caminho || tpi_no_provisorio() ) {
		return;
	}
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}
	tpi_llms_registra();
	status_header( 200 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	header( 'Cache-Control: no-cache, max-age=0' );
	header( 'X-LiteSpeed-Cache-Control: no-cache' );
	header( 'X-TP-Llms: dinamico' );
	echo tpi_llms_texto(); // phpcs:ignore WordPress.Security.EscapeOutput -- texto puro
	exit;
}, 0 ); // wp_loaded: os tipos caderno e concurso já existem (links certos).

/* Bloco no .htaccess, uma vez por versão do bloco. Vem no fim do arquivo: o bloco do WordPress não mexe em
 * arquivos que existem (!-f), então a regra daqui é a que pega o /llms.txt. */
add_action( 'init', function () {
	if ( TPI_LLMS_HT === get_option( 'tpi_llms_ht' ) ) {
		return;
	}
	$arq = ABSPATH . '.htaccess';
	if ( ! is_writable( $arq ) ) {
		return;
	}
	if ( ! function_exists( 'insert_with_markers' ) ) {
		require_once ABSPATH . 'wp-admin/includes/misc.php';
	}
	$ok = insert_with_markers( $arq, 'TPI llms', array(
		'<IfModule mod_rewrite.c>',
		'RewriteEngine On',
		'RewriteRule ^llms\.txt$ /index.php [L]',
		'</IfModule>',
	) );
	if ( $ok ) {
		update_option( 'tpi_llms_ht', TPI_LLMS_HT, true );
	}
}, 1 );

/* 2. Rede de segurança: o arquivo estático com o mesmo texto. */
function tpi_llms_grava_estatico() {
	$arq = ABSPATH . 'llms.txt';
	if ( file_exists( $arq ) && ! is_writable( $arq ) ) {
		return;
	}
	$novo = tpi_llms_texto();
	if ( ! file_exists( $arq ) || (string) file_get_contents( $arq ) !== $novo ) {
		file_put_contents( $arq, $novo );
	}
}
