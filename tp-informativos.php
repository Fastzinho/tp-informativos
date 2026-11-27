<?php
/**
 * Plugin Name: TP Informativos
 * Description: Informativos STF/STJ comentados: tipo de conteúdo hierárquico (tribunal > periódico > julgado), calendário, "marcar como lido", dados estruturados e campos para automação via REST API.
 * Version:           0.29.74
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Tchau Pobreza
 * Text Domain: tp-informativos
 * Update URI: https://github.com/Fastzinho/tp-informativos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPI_VERSION', '0.29.74' );
define( 'TPI_ARQUIVO', __FILE__ );
require_once __DIR__ . '/includes/dominio.php';
require_once __DIR__ . '/includes/llms.php';
require_once __DIR__ . '/includes/atualizador.php';
require_once __DIR__ . '/includes/medicao.php';
require_once __DIR__ . '/includes/ramos.php';
require_once __DIR__ . '/includes/cadernos.php';
require_once __DIR__ . '/includes/concursos.php';
require_once __DIR__ . '/includes/como-estudar.php';
require_once __DIR__ . '/includes/vitrine.php';
require_once __DIR__ . '/includes/cronograma.php';
require_once __DIR__ . '/includes/app-questoes.php';
define( 'TPI_URL', plugin_dir_url( __FILE__ ) );

/*
 * Estrutura de URLs (CPT hierárquico "informativo"):
 *   /informativos/                       -> Página comum do WP (hub geral) com [tp_ultimos] e [tp_calendario]
 *   /informativos/stj/                   -> tp_tipo = tribunal (hub do tribunal + próximo informativo)
 *   /informativos/stj/informativo-908/               -> tp_tipo = periodico
 *   /informativos/stj/informativo-908/<slug-tese>/   -> tp_tipo = julgado
 *   /informativos/stj/jurisprudencia-em-teses/                  -> tp_tipo = teses_hub (0.7)
 *   /informativos/stj/jurisprudencia-em-teses/edicao-123-tema/  -> tp_tipo = teses (uma página por edição)
 *   /informativos/stf/teses-de-repercussao-geral/<assunto>/     -> teses_hub + teses, mesmo modelo (0.7)
 */

/* ---------------------------------------------------------------------------
 * 1. Tipo de conteúdo, taxonomia e campos
 * ------------------------------------------------------------------------- */
add_action( 'init', 'tpi_register' );
function tpi_register() {
	register_post_type(
		'informativo',
		array(
			'labels'       => array(
				'name'          => 'Informativos',
				'singular_name' => 'Informativo',
				'add_new_item'  => 'Adicionar informativo ou julgado',
				'edit_item'     => 'Editar',
				'all_items'     => 'Todos',
			),
			'public'       => true,
			'hierarchical' => true,
			'has_archive'  => false, // o hub /informativos/ é uma Página comum com [tp_calendario] e [tp_ultimos]
			'rewrite'      => array( 'slug' => 'informativos', 'with_front' => false ),
			'menu_icon'    => 'dashicons-book-alt',
			'show_in_rest' => true,
			'rest_base'    => 'informativos',
			'supports'     => array( 'title', 'editor', 'excerpt', 'thumbnail', 'page-attributes', 'revisions', 'custom-fields', 'author' ),
		)
	);

	register_taxonomy(
		'ramo_direito',
		'informativo',
		array(
			'labels'       => array( 'name' => 'Ramos do Direito', 'singular_name' => 'Ramo do Direito' ),
			'public'       => true,
			'hierarchical' => true,
			'show_in_rest' => true,
			'rest_base'    => 'ramos',
			'rewrite'      => array( 'slug' => 'informativos/ramo', 'with_front' => false ),
		)
	);

	$meta = array(
		'tp_tipo'            => 'string',  // tribunal | periodico | julgado | teses_hub | teses
		'tp_tribunal'        => 'string',  // STJ | STF
		'tp_numero'          => 'integer', // número do informativo
		'tp_status'          => 'string',  // previsto | publicado
		'tp_data_prevista'   => 'string',  // AAAA-MM-DD
		'tp_data_publicacao' => 'string',  // AAAA-MM-DD (divulgação oficial)
		'tp_fonte_data'      => 'string',  // de onde veio a data prevista (texto curto)
		'tp_processo'        => 'string',  // ex.: REsp 2.000.000-RJ
		'tp_relator'         => 'string',
		'tp_orgao'           => 'string',  // ex.: Primeira Turma
		'tp_data_julgamento' => 'string',
		'tp_destaque'        => 'string',  // texto oficial do destaque
		'tp_fonte_url'       => 'string',  // link oficial (PDF/página do informativo)
		'tp_revisado_em'     => 'string',  // AAAA-MM-DD
		'tp_flashcards'      => 'string',  // JSON [{frente, verso}] para exportar ao Anki
		// 0.7: cobertura retroativa
		'tp_situacao'        => 'string',  // vigente | superada | revista | em_discussao (vazio = não conferida)
		'tp_situacao_motivo' => 'string',  // 1 frase; aceita **negrito** e [texto](url)
		'tp_situacao_em'     => 'string',  // AAAA-MM-DD da conferência
		'tp_jt_edicao'       => 'integer', // julgado: edição do Jurisprudência em Teses em que a tese aparece
		'tp_ramo_jt'         => 'string',  // teses: ramo como o STJ classifica (agrupa o hub)
		'tp_atualizada_em'   => 'string',  // teses: data da última atualização oficial da edição
		// 0.16.2: correção de conteúdo depois de publicado (sinaliza "Atualizado" para quem já tinha lido).
		// Só o que muda a resposta do aluno (erro de conteúdo, lei nova); revisão de texto não entra.
		'tp_mudanca_em'      => 'string',  // AAAA-MM-DD
		'tp_mudanca_nota'    => 'string',  // 1 frase: o que mudou
		// 0.21.0: resumo da edição e marcadores (pedido do Jonatan em 20/11/2026).
		'tp_resumo'          => 'string',  // JSON {rotulo, tese, caso, pontos[], prova[]}; marcação inline do pipeline
		'tp_marcadores'      => 'string',  // JSON {importante: "motivo", virada: "o que mudou, com a fonte"}; vazio = sem marcador
	);
	foreach ( $meta as $key => $type ) {
		register_post_meta(
			'informativo',
			$key,
			array(
				'type'          => $type,
				'single'        => true,
				'show_in_rest'  => true,
				'auth_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}

register_activation_hook( __FILE__, function () {
	tpi_register();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* Helpers ------------------------------------------------------------------ */
function tpi_meta( $post_id, $key ) {
	return get_post_meta( $post_id, $key, true );
}
function tpi_data_br( $ymd ) {
	if ( ! $ymd ) {
		return '';
	}
	$ts = strtotime( $ymd );
	return $ts ? wp_date( 'd/m/Y', $ts ) : esc_html( $ymd );
}

/*
 * Página sobre o autor, usada no link da assinatura e no author.url / reviewedBy.url do schema.
 * O arquivo /author/ fica fora do índice (mostra o texto inteiro de todos os posts), então o link
 * vai para a página Sobre, que tem a bio. Sem página Sobre publicada, volta ao arquivo do autor.
 */
function tpi_autor_url( $user_id = 1 ) {
	$sobre = get_page_by_path( 'sobre' );
	$url   = ( $sobre && 'publish' === $sobre->post_status ) ? get_permalink( $sobre ) : get_author_posts_url( (int) $user_id );
	return apply_filters( 'tpi_autor_url', $url, (int) $user_id );
}

/* Autor exibido e usado no schema (independe do nome do usuário do WP). */
function tpi_autor() {
	return apply_filters(
		'tpi_autor',
		array(
			'nome'        => 'Jonatan Ramos',
			'credenciais' => 'especialista em Direito Penal, aprovado no ENAM e na EMERJ',
			'url'         => tpi_autor_url( (int) get_post_field( 'post_author', get_queried_object_id() ) ? (int) get_post_field( 'post_author', get_queried_object_id() ) : 1 ),
		)
	);
}

/*
 * Entidades do schema (marca e autor), com o mesmo @id que o Rank Math usa no resto do site.
 * Assim, Google e buscadores de IA ligam todo julgado à mesma Organização e à mesma Pessoa,
 * em vez de enxergar uma organização e um autor soltos em cada página.
 */
function tpi_schema_org_id() {
	return home_url( '/#organization' );
}

function tpi_rm_titulo( $chave ) {
	$op = get_option( 'rank-math-options-titles', array() );
	return is_array( $op ) && isset( $op[ $chave ] ) ? $op[ $chave ] : '';
}

function tpi_schema_org() {
	$org = array(
		'@type' => 'Organization',
		'@id'   => tpi_schema_org_id(),
		'name'  => tpi_rm_titulo( 'knowledgegraph_name' ) ? tpi_rm_titulo( 'knowledgegraph_name' ) : get_bloginfo( 'name' ),
		'url'   => home_url( '/' ),
	);
	$logo = tpi_rm_titulo( 'knowledgegraph_logo' );
	if ( ! $logo && get_option( 'site_icon' ) ) {
		$logo = wp_get_attachment_image_url( (int) get_option( 'site_icon' ), 'full' );
	}
	if ( $logo ) {
		$org['logo'] = array( '@type' => 'ImageObject', '@id' => home_url( '/#logo' ), 'url' => $logo );
	}
	$perfis = array_values( array_filter( array_map( 'trim', preg_split( '/\s+/', (string) tpi_rm_titulo( 'social_additional_profiles' ) ) ) ) );
	if ( $perfis ) {
		$org['sameAs'] = $perfis;
	}
	return apply_filters( 'tpi_schema_org', $org );
}

function tpi_schema_autor( $user_id = 1 ) {
	$a      = tpi_autor();
	$pessoa = array(
		'@type'       => 'Person',
		'@id'         => get_author_posts_url( $user_id ? (int) $user_id : 1 ),
		'name'        => $a['nome'],
		'url'         => tpi_autor_url( $user_id ? (int) $user_id : 1 ),
		'description' => ucfirst( $a['credenciais'] ),
		'knowsAbout'  => array( 'Direito Penal', 'Direito Processual Penal', 'Execução Penal', 'Tribunal do Júri', 'Jurisprudência do STJ e do STF' ),
		'worksFor'    => array( '@id' => tpi_schema_org_id() ),
	);
	return apply_filters( 'tpi_autor_schema', $pessoa );
}

/* A página de autor também lista os informativos e julgados comentados (o tema só lista posts). */
add_action( 'pre_get_posts', function ( $q ) {
	if ( ! is_admin() && $q->is_main_query() && $q->is_author() ) {
		$q->set( 'post_type', array( 'post', 'informativo' ) );
	}
} );

/*
 * Calendário oficial de divulgação. Guardado na opção "tpi_calendario_oficial" (editável via REST em
 * /wp/v2/settings), com os dados do calendário publicado pelo STJ como valor inicial.
 * Fonte: https://processo.stj.jus.br/docs_internet/jurisprudencia/ajuda/CalendarioIJ.pdf
 */
function tpi_calendario_padrao() {
	$out = array();
	$stj = array( 904 => '2026-10-06', 905 => '2026-10-13', 906 => '2026-10-20', 907 => '2026-10-27', 908 => '2026-11-03', 909 => '2026-11-10', 910 => '2026-11-17', 911 => '2026-11-24', 912 => '2026-12-01', 913 => '2026-12-09', 914 => '2026-12-15' );
	foreach ( $stj as $n => $d ) {
		$out[] = array( 'tribunal' => 'STJ', 'numero' => $n, 'data' => $d, 'fonte' => 'https://processo.stj.jus.br/docs_internet/jurisprudencia/ajuda/CalendarioIJ.pdf', 'estimada' => false );
	}
	// STF: calendário oficial publicado até a edição 1234. As seguintes são estimadas pelo ritmo semanal (segundas-feiras).
	$stf_fonte = 'https://portal.stf.jus.br/textos/verTexto.asp?servico=informativoSTF&pagina=Calendario_publicacoes_Informativo';
	$stf = array( 1231 => '2026-10-05', 1232 => '2026-10-13', 1233 => '2026-10-19', 1234 => '2026-10-26' );
	foreach ( $stf as $n => $d ) {
		$out[] = array( 'tribunal' => 'STF', 'numero' => $n, 'data' => $d, 'fonte' => $stf_fonte, 'estimada' => false );
	}
	$est = array( 1235 => '2026-11-09', 1236 => '2026-11-16', 1237 => '2026-11-23', 1238 => '2026-11-30', 1239 => '2026-12-07', 1240 => '2026-12-14' );
	foreach ( $est as $n => $d ) {
		$out[] = array( 'tribunal' => 'STF', 'numero' => $n, 'data' => $d, 'fonte' => $stf_fonte, 'estimada' => true );
	}
	return $out;
}
function tpi_calendario_oficial() {
	$c = get_option( 'tpi_calendario_oficial' );
	return tpi_reestimar_datas( ( is_array( $c ) && $c ) ? $c : tpi_calendario_padrao() );
}

/* 0.7.3: datas estimadas (STF) se ajustam ao que já existe no site. A âncora é a edição de maior número
   com página (publicada ou prevista) e data; as estimadas anteriores somem, a dela assume a data da página, e as seguintes ficam a cada 7 dias
   a partir dela. Assim um informativo que saiu fora do dia (ex.: 1235 na quarta, 04/11) não aparece mais
   como "próximo", e o calendário não salta semanas. Datas oficiais nunca são alteradas. */
function tpi_reestimar_datas( $itens ) {
	static $ancoras = null;
	if ( null === $ancoras ) {
		$ancoras = array();
		$q = get_posts( array( 'post_type' => 'informativo', 'post_status' => 'publish', 'numberposts' => 50, 'meta_key' => 'tp_numero', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_query' => array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ) ) ) );
		foreach ( $q as $p ) {
			$t    = tpi_meta( $p->ID, 'tp_tribunal' );
			$n    = (int) tpi_meta( $p->ID, 'tp_numero' );
			$prev = 'previsto' === tpi_meta( $p->ID, 'tp_status' );
			$d    = $prev ? tpi_meta( $p->ID, 'tp_data_prevista' ) : tpi_meta( $p->ID, 'tp_data_publicacao' );
			if ( $t && $n && $d && ( empty( $ancoras[ $t ] ) || $n > $ancoras[ $t ]['numero'] ) ) {
				$ancoras[ $t ] = array( 'numero' => $n, 'data' => $d );
			}
		}
	}
	$out = array();
	foreach ( $itens as $c ) {
		$a = $ancoras[ $c['tribunal'] ] ?? null;
		if ( $a && ! empty( $c['estimada'] ) ) {
			if ( (int) $c['numero'] < $a['numero'] ) {
				continue;
			}
			$c['data'] = gmdate( 'Y-m-d', strtotime( $a['data'] . ' 12:00:00 UTC +' . ( 7 * ( (int) $c['numero'] - $a['numero'] ) ) . ' days' ) );
		}
		$out[] = $c;
	}
	return $out;
}
add_action( 'init', function () {
	register_setting(
		'general',
		'tpi_calendario_oficial',
		array(
			'type'         => 'array',
			'default'      => array(),
			'show_in_rest' => array(
				'schema' => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'tribunal' => array( 'type' => 'string' ),
							'numero'   => array( 'type' => 'integer' ),
							'data'     => array( 'type' => 'string' ),
							'fonte'    => array( 'type' => 'string' ),
							'estimada' => array( 'type' => 'boolean' ),
						),
					),
				),
			),
		)
	);
} );

function tpi_data_extenso( $ymd ) {
	$ts = strtotime( (string) $ymd );
	return $ts ? wp_date( 'j \d\e F \d\e Y', $ts ) : '';
}

/* Rótulo curto de uma página (trilha de navegação). */
function tpi_rotulo_curto( $pid ) {
	switch ( tpi_meta( $pid, 'tp_tipo' ) ) {
		case 'tribunal':
			return tpi_meta( $pid, 'tp_tribunal' );
		case 'periodico':
			return 'Informativo ' . tpi_meta( $pid, 'tp_numero' );
		case 'teses':
			return tpi_meta( $pid, 'tp_numero' ) ? 'Edição ' . tpi_meta( $pid, 'tp_numero' ) : get_the_title( $pid );
		default:
			return get_the_title( $pid );
	}
}

/* Página de informativo correspondente a um número, se existir. */
function tpi_periodico_por_numero( $tribunal, $numero ) {
	$q = get_posts( array( 'post_type' => 'informativo', 'numberposts' => 1, 'post_status' => 'publish', 'meta_query' => array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ), array( 'key' => 'tp_tribunal', 'value' => $tribunal ), array( 'key' => 'tp_numero', 'value' => (int) $numero ) ) ) );
	return $q ? $q[0] : null;
}

/* 0.7.2: páginas de informativo no sitemap do Rank Math (pedido do Jonatan em 05/11/2026).
   Força "Incluir no sitemap" para o tipo informativo na leitura da opção, sem regravar as demais
   configurações do Rank Math. Inclui as pré-páginas (previstas), que não recebem link interno. */
/* 0.7.4/0.7.5: o Rank Math lê as opções antes deste plugin carregar, então um filtro de leitura não basta
   (e, na 0.7.4, ele fazia esta checagem achar que a chave já estava gravada; removido na 0.7.5).
   Grava a chave uma vez (mesclando com o que já existe, sem tocar nas demais) e limpa o cache do sitemap. */
add_action( 'init', function () {
	$o = get_option( 'rank-math-options-sitemap' );
	if ( ! is_array( $o ) || ( $o['pt_informativo_sitemap'] ?? '' ) === 'on' ) {
		return;
	}
	$o['pt_informativo_sitemap'] = 'on';
	update_option( 'rank-math-options-sitemap', $o );
	if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) && method_exists( '\\RankMath\\Sitemap\\Cache', 'invalidate_storage' ) ) {
		\RankMath\Sitemap\Cache::invalidate_storage();
	}
}, 99 );
add_filter( 'rank_math/sitemap/exclude_post_type', function ( $excluir, $tipo ) {
	return 'informativo' === $tipo ? false : $excluir;
}, 10, 2 );

/* 0.6.3: links internos para a pré-página de um informativo ainda não divulgado (tp_status "previsto").
   Opção tpi_links_previstos: "nenhum" (padrão, decisão do Jonatan em 05/11/2026: a pré-página é indexável,
   mas ninguém linka para ela até a divulgação), "calendario" (só a tabela do calendário) ou "todos".
   Uma edição já publicada é sempre linkada. */
function tpi_linkavel( $post, $onde ) {
	$id = is_object( $post ) ? $post->ID : (int) $post;
	if ( ! $id || 'previsto' !== tpi_meta( $id, 'tp_status' ) ) {
		return (bool) $id;
	}
	$modo = get_option( 'tpi_links_previstos', 'nenhum' );
	return 'todos' === $modo || ( 'calendario' === $modo && 'calendario' === $onde );
}
add_action( 'init', function () {
	register_setting( 'general', 'tpi_links_previstos', array( 'type' => 'string', 'default' => 'nenhum', 'show_in_rest' => array( 'schema' => array( 'type' => 'string', 'enum' => array( 'nenhum', 'calendario', 'todos' ) ) ), 'sanitize_callback' => function ( $v ) {
		return in_array( $v, array( 'nenhum', 'calendario', 'todos' ), true ) ? $v : 'nenhum';
	} ) );
} );

/* ---------------------------------------------------------------------------
 * 1b. Trilha de navegação acima do título (somente nas páginas de informativo)
 * ------------------------------------------------------------------------- */
add_filter( 'render_block_core/post-title', function ( $html, $block ) {
	static $feito = false;
	if ( $feito || ! is_singular( 'informativo' ) || ! empty( $block['attrs']['isLink'] ) ) {
		return $html;
	}
	$feito = true;
	$id    = get_queried_object_id();
	$itens = array( '<a href="' . esc_url( home_url( '/informativos/' ) ) . '">Informativos</a>' );
	foreach ( array_reverse( get_post_ancestors( $id ) ) as $anc ) {
		$rotulo  = tpi_rotulo_curto( $anc );
		$itens[] = '<a href="' . esc_url( get_permalink( $anc ) ) . '">' . esc_html( $rotulo ) . '</a>';
	}
	$nav = '<nav class="tpi-trilha" aria-label="Trilha de navegação"><ol><li>' . implode( '</li><li>', $itens ) . '</li></ol></nav>';
	return $nav . $html;
}, 10, 2 );

/* 0.4: capas dos informativos são 1200x675 (16:9). O tema força 3:2 com object-fit:cover nas listagens
   (autor, busca, arquivos) e corta o texto da capa. Aqui a proporção vira 16:9 só para este tipo. */
add_filter( 'render_block_core/post-featured-image', function ( $html, $block, $instance ) {
	$pid = isset( $instance->context['postId'] ) ? (int) $instance->context['postId'] : get_the_ID();
	if ( ! $pid || 'informativo' !== get_post_type( $pid ) ) {
		return $html;
	}
	// 0.5.2: na própria página da edição publicada a capa não aparece (repete a lista e empurra o conteúdo).
	// Ela continua como imagem destacada (og:image, schema e listagens).
	if ( is_singular( 'informativo' ) && $pid === get_queried_object_id() && 'periodico' === tpi_meta( $pid, 'tp_tipo' ) && 'previsto' !== tpi_meta( $pid, 'tp_status' ) ) {
		return '';
	}
	return preg_replace( '/aspect-ratio:\s*[^;"]+/', 'aspect-ratio:16/9', $html );
}, 10, 3 );

/* 0.6.2: template próprio para os informativos (WordPress 6.7+). O "single" do tema traz "Escrito por",
   tags, navegação de posts e o bloco "Mais posts" (que busca posts do blog e fica vazio). Aqui fica só o
   essencial; trilha, assinatura, anterior/próximo e relacionados já vêm do plugin. Para personalizar,
   edite "Informativo" em Aparência > Editor > Modelos (a edição do usuário tem prioridade). */
add_action( 'init', function () {
	if ( ! function_exists( 'register_block_template' ) ) {
		return;
	}
	register_block_template(
		'tp-informativos//single-informativo',
		array(
			'title'       => 'Informativo',
			'description' => 'Páginas de tribunal, edição e julgado dos informativos comentados.',
			'post_types'  => array( 'informativo' ),
			'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--50)">
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60)">
<!-- wp:post-title {"level":1} /-->
<!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /-->
</div>
<!-- /wp:group -->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
		)
	);
} );

/* O tema mostra "Escrito por <usuário>"; nas páginas de informativo o plugin mostra a assinatura editorial. */
add_filter( 'body_class', function ( $c ) {
	if ( is_page() && has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'tp_buscador' ) ) {
		$c[] = 'tpi-hub-pagina';
	}
	if ( is_singular( 'informativo' ) ) {
		$c[] = 'tpi-pagina';
		$c[] = 'tpi-tipo-' . sanitize_html_class( tpi_meta( get_queried_object_id(), 'tp_tipo' ) );
	}
	return $c;
} );

/*
 * 0.17.0 (Jonatan 17/11/2026): assinatura padrão em todo o site, numa linha só: iniciais em círculo,
 * "Por <nome>" e as datas separadas por " · ". A mesma de informativos, cadernos e páginas de concurso.
 * As credenciais saem da linha (continuam no schema e na página /sobre/, que o nome abre).
 * $datas: lista de array( rótulo, data ISO ), ex.: array( array( 'publicado em', '2026-11-17' ) ).
 */
function tpi_iniciais( $nome ) {
	$p = preg_split( '/\s+/', trim( (string) $nome ) );
	$i = mb_substr( $p[0] ?? '', 0, 1 ) . ( count( $p ) > 1 ? mb_substr( end( $p ), 0, 1 ) : '' );
	return mb_strtoupper( $i );
}
function tpi_byline( $datas, $a = null ) {
	$a    = $a ? $a : tpi_autor();
	$nome = $a['url'] ? '<a href="' . esc_url( $a['url'] ) . '">' . esc_html( $a['nome'] ) . '</a>' : esc_html( $a['nome'] );
	$h    = '<div class="tpi-assinatura tpi-byline"><span class="tpi-byline__av" aria-hidden="true">' . esc_html( tpi_iniciais( $a['nome'] ) ) . '</span><p>Por <strong>' . $nome . '</strong>';
	foreach ( $datas as $d ) {
		if ( empty( $d[1] ) ) {
			continue;
		}
		$h .= ' · ' . esc_html( $d[0] ) . ' <time datetime="' . esc_attr( $d[1] ) . '">' . esc_html( tpi_data_br( substr( $d[1], 0, 10 ) ) ) . '</time>';
	}
	return $h . '</p></div>';
}

function tpi_assinatura( $id ) {
	return tpi_byline( array(
		array( 'publicado em', get_the_date( 'Y-m-d', $id ) ),
		array( 'revisado em', (string) tpi_meta( $id, 'tp_revisado_em' ) ),
	) );
}

/* ---------------------------------------------------------------------------
 * 2b. Navegação e confiança (0.4): anterior/próximo, como citar, reportar erro
 * ------------------------------------------------------------------------- */
function tpi_irmaos( $id ) {
	$pai = wp_get_post_parent_id( $id );
	if ( ! $pai ) {
		return array();
	}
	return get_posts( array( 'post_type' => 'informativo', 'post_parent' => $pai, 'numberposts' => 100, 'orderby' => 'menu_order date', 'order' => 'ASC', 'fields' => 'ids' ) );
}

function tpi_edicoes_vizinhas( $id ) {
	if ( 'teses' === tpi_meta( $id, 'tp_tipo' ) ) {
		// Edições de Teses: vizinhas pela ordem de número dentro do mesmo hub.
		$irmaos = array_reverse( wp_list_pluck( tpi_edicoes_teses( wp_get_post_parent_id( $id ) ), 'ID' ) );
		$i      = array_search( $id, $irmaos, true );
		return false === $i ? array( 0, 0 ) : array( $irmaos[ $i - 1 ] ?? 0, $irmaos[ $i + 1 ] ?? 0 );
	}
	$trib = tpi_meta( $id, 'tp_tribunal' );
	$num  = (int) tpi_meta( $id, 'tp_numero' );
	$base = array( 'post_type' => 'informativo', 'numberposts' => 1, 'post_status' => 'publish', 'meta_key' => 'tp_numero', 'orderby' => 'meta_value_num' );
	$mq   = function ( $cmp ) use ( $trib, $num ) {
		return array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ), array( 'key' => 'tp_tribunal', 'value' => $trib ), array( 'key' => 'tp_numero', 'value' => $num, 'compare' => $cmp, 'type' => 'NUMERIC' ) );
	};
	$ant = get_posts( $base + array( 'order' => 'DESC', 'meta_query' => $mq( '<' ) ) );
	$pro = get_posts( $base + array( 'order' => 'ASC', 'meta_query' => $mq( '>' ) ) );
	$pro = ( $pro && tpi_linkavel( $pro[0], 'vizinhos' ) ) ? $pro[0]->ID : 0;
	return array( $ant ? $ant[0]->ID : 0, $pro );
}

function tpi_nav_vizinhos( $ant, $pro, $rotulo, $tipo ) {
	if ( ! $ant && ! $pro ) {
		return '';
	}
	$item = function ( $pid, $classe, $dir ) use ( $tipo ) {
		if ( ! $pid ) {
			return '<span class="tpi-vizinhos__vazio" aria-hidden="true"></span>';
		}
		$nome = ( 'edicao' === $tipo && 'periodico' === tpi_meta( $pid, 'tp_tipo' ) ) ? 'Informativo ' . tpi_meta( $pid, 'tp_numero' ) . ' do ' . tpi_meta( $pid, 'tp_tribunal' ) : get_the_title( $pid );
		return '<a class="tpi-vizinhos__' . $classe . '" href="' . esc_url( get_permalink( $pid ) ) . '"' . ( 'ant' === $classe ? ' rel="prev"' : ' rel="next"' ) . '><span class="tpi-vizinhos__dir">' . $dir . '</span><span class="tpi-vizinhos__nome">' . esc_html( $nome ) . '</span></a>';
	};
	$d = 'edicao' === $tipo ? array( '← Edição anterior', 'Próxima edição →' ) : array( '← Julgado anterior', 'Próximo julgado →' );
	return '<nav class="tpi-vizinhos" aria-label="' . esc_attr( $rotulo ) . '">' . $item( $ant, 'ant', $d[0] ) . $item( $pro, 'pro', $d[1] ) . '</nav>';
}

function tpi_resumo_edicao( $id ) {
	$data  = tpi_data_br( tpi_meta( $id, 'tp_data_publicacao' ) );
	$fonte = tpi_meta( $id, 'tp_fonte_url' );
	$itens = array();
	if ( $data ) {
		$itens[] = 'Divulgado em <time datetime="' . esc_attr( tpi_meta( $id, 'tp_data_publicacao' ) ) . '">' . esc_html( $data ) . '</time>';
	}
	if ( $fonte ) {
		$itens[] = '<a href="' . esc_url( $fonte ) . '" rel="noopener" target="_blank">Informativo oficial do ' . esc_html( tpi_meta( $id, 'tp_tribunal' ) ) . '<span class="tpi-sr"> (abre em nova aba)</span></a>';
	}
	return $itens ? '<p class="tpi-resumo-edicao">' . implode( '<span aria-hidden="true"> · </span>', $itens ) . '</p>' : '';
}

function tpi_como_citar( $id ) {
	$a     = tpi_autor();
	$meses = array( 'jan.', 'fev.', 'mar.', 'abr.', 'maio', 'jun.', 'jul.', 'ago.', 'set.', 'out.', 'nov.', 'dez.' );
	$fmt   = function ( $ts ) use ( $meses ) {
		return wp_date( 'j', $ts ) . ' ' . $meses[ (int) wp_date( 'n', $ts ) - 1 ] . ' ' . wp_date( 'Y', $ts );
	};
	$partes = explode( ' ', $a['nome'] );
	$sobre  = mb_strtoupper( array_pop( $partes ) );
	$ref    = $sobre . ', ' . implode( ' ', $partes ) . '. ' . get_the_title( $id ) . '. <em>Tchau Pobreza</em>, ' . $fmt( get_post_time( 'U', true, $id ) ) . '. Disponível em: ' . esc_html( get_permalink( $id ) ) . '. Acesso em: ' . $fmt( time() ) . '.';
	$email  = apply_filters( 'tpi_email_erros', 'contato@tchaupobreza.com.br' );
	// Título cru: get_the_title() aplica wptexturize e o assunto do e-mail saía com &#8220; no lugar das aspas.
	$assunto = rawurlencode( 'Erro no comentário: ' . html_entity_decode( wp_strip_all_tags( get_post_field( 'post_title', $id ) ), ENT_QUOTES, 'UTF-8' ) );
	$corpo   = rawurlencode( "Página: " . get_permalink( $id ) . "\n\nO que está errado:\n" );
	return '<section class="tpi-citar" aria-labelledby="tpi-citar-titulo"><h2 id="tpi-citar-titulo">Como citar este comentário</h2><p class="tpi-citar__ref">' . $ref . '</p>'
		. '<p class="tpi-citar__erro">Encontrou um erro? <a href="mailto:' . esc_attr( $email ) . '?subject=' . $assunto . '&amp;body=' . $corpo . '">Avise por e-mail</a> e a correção entra com a data de revisão atualizada.</p></section>';
}

/* ---------------------------------------------------------------------------
 * 2. Conteúdo automático em volta do texto (antes/depois)
 * ------------------------------------------------------------------------- */
add_filter( 'the_content', 'tpi_wrap_content', 20 );
function tpi_wrap_content( $content ) {
	if ( ! is_singular( 'informativo' ) || get_the_ID() !== get_queried_object_id() ) {
		return $content;
	}
	$id   = get_the_ID();
	$tipo = tpi_meta( $id, 'tp_tipo' );
	$pre  = '';
	$pos  = '';

	if ( 'periodico' === $tipo && 'previsto' === tpi_meta( $id, 'tp_status' ) ) {
		$pre .= sprintf(
			'<div class="tpi-aviso" role="note"><strong>Informativo ainda não divulgado.</strong> Divulgação prevista para %s. Esta página será atualizada com todos os julgados comentados assim que o tribunal publicar.</div>',
			esc_html( tpi_data_br( tpi_meta( $id, 'tp_data_prevista' ) ) )
		);
	}

	if ( in_array( $tipo, array( 'periodico', 'julgado', 'teses' ), true ) ) {
		$pre .= tpi_assinatura( $id );
	}
	if ( in_array( $tipo, array( 'julgado', 'teses' ), true ) ) {
		$pre .= tpi_aviso_situacao( $id );
	}

	$abas = 'julgado' === $tipo && tpi_embed_app() && tpi_resumo_de( $id );
	if ( 'julgado' === $tipo ) {
		$pre     .= tpi_botao_lido( $id, true );
		$topo     = $pre; // assinatura, situação da tese e "Marcar como lido" ficam acima das abas
		$pre      = '';
		$pre     .= tpi_chips( $id, true );
		$pre     .= tpi_ficha_julgado( $id );
		$destaque = tpi_meta( $id, 'tp_destaque' );
		if ( $destaque ) {
			$pre .= '<figure class="tpi-destaque"><figcaption>Destaque oficial do ' . esc_html( tpi_meta( $id, 'tp_tribunal' ) ) . '</figcaption><blockquote><p>' . wp_kses_post( $destaque ) . '</p></blockquote></figure>';
		}
		$pos .= tpi_selo_teses( $id );
		$pos .= tpi_fonte_julgado( $id );
		$pos .= tpi_botao_lido( $id );
		$irmaos = tpi_irmaos( $id );
		$i      = array_search( $id, $irmaos, true );
		if ( false !== $i ) {
			$pos .= tpi_nav_vizinhos( $irmaos[ $i - 1 ] ?? 0, $irmaos[ $i + 1 ] ?? 0, 'Outros julgados desta edição', 'julgado' );
		}
		$pos .= tpi_como_citar( $id );
	}

	if ( 'periodico' === $tipo ) {
		$lista = tpi_lista_julgados( $id );
		if ( $lista && 'previsto' !== tpi_meta( $id, 'tp_status' ) ) {
			// 0.5.2: edição publicada → os julgados vêm primeiro; o texto editorial (único da página,
			// evita página "só lista") desce para "Panorama da edição".
			$pre    .= tpi_resumo_edicao( $id ) . $lista;
			$content = trim( $content ) ? '<section class="tpi-panorama" aria-labelledby="tpi-panorama-titulo"><h2 id="tpi-panorama-titulo">Panorama da edição</h2>' . $content . '</section>' : '';
		} else {
			$pos .= $lista;
		}
		$pos .= tpi_botao_anki( $id, true );
		list( $ant, $pro ) = tpi_edicoes_vizinhas( $id );
		$pos .= tpi_nav_vizinhos( $ant, $pro, 'Outras edições do ' . tpi_meta( $id, 'tp_tribunal' ), 'edicao' );
	}

	if ( 'teses' === $tipo ) {
		$pre .= tpi_resumo_teses( $id );
		$pos .= tpi_botao_anki( $id );
		list( $ant, $pro ) = tpi_edicoes_vizinhas( $id );
		$pai  = wp_get_post_parent_id( $id );
		$pos .= tpi_nav_vizinhos( $ant, $pro, $pai ? 'Outras edições de ' . get_the_title( $pai ) : 'Outras edições', 'edicao' );
		$pos .= tpi_como_citar( $id );
	}

	if ( 'teses_hub' === $tipo ) {
		$pos .= tpi_lista_teses( $id );
	}

	if ( in_array( $tipo, array( 'periodico', 'julgado', 'teses', 'teses_hub' ), true ) ) {
		$pos .= tpi_ponte_como_estudar();
		$pos .= tpi_newsletter_cta();
	}

	if ( 'julgado' === $tipo ) {
		$corpo = $pre . $content . $pos;
		return $topo . ( $abas ? tpi_julgado_em_abas( $id, $corpo ) : $corpo );
	}
	return $pre . $content . $pos;
}

/**
 * 0.19.2: os informativos são as páginas que o Google mais relê; daqui sai o caminho até os guias de método
 * (/como-estudar/), que estavam "detectados, mas não indexados" no Search Console.
 */
function tpi_ponte_como_estudar() {
	$g = array(
		'/como-estudar/como-estudar-jurisprudencia/' => 'como estudar jurisprudência e informativos',
		'/como-estudar/como-estudar-lei-seca/'       => 'como estudar lei seca',
		'/como-estudar/'                             => 'o método completo, do zero à prova',
	);
	$l = array();
	foreach ( $g as $url => $txt ) {
		$l[] = '<a href="' . esc_url( home_url( $url ) ) . '">' . esc_html( $txt ) . '</a>';
	}
	return '<aside class="tpi-ponte-guias" aria-label="Guias de como estudar"><p><strong>Para a prova:</strong> veja ' . $l[0] . ', ' . $l[1] . ' e ' . $l[2] . '.</p></aside>';
}

/* ---------------------------------------------------------------------------
 * 2c. Cobertura retroativa (0.7): situação da tese, Jurisprudência em Teses
 * ------------------------------------------------------------------------- */
function tpi_situacoes() {
	return array(
		'superada'     => array( 'Tese superada', 'Esta tese não reflete mais o entendimento atual.' ),
		'revista'      => array( 'Tese revista em parte', 'Parte desta tese mudou depois do julgamento.' ),
		'em_discussao' => array( 'Tema em discussão', 'O assunto está pendente de definição no tribunal.' ),
	);
}

/* 0.16.2: última mudança relevante do julgado, para o selo "Atualizado" de quem já leu (tpi.js compara com o readAt).
   Conta a situação que mudou o entendimento (superada, revista, em discussão) e a correção registrada em tp_mudanca_*.
   A data de modificação do post NÃO serve: qualquer ajuste de texto a muda. */
function tpi_mudanca( $id ) {
	$m    = array( 'em' => '', 'nota' => '' );
	$sit  = tpi_meta( $id, 'tp_situacao' );
	$mapa = tpi_situacoes();
	$em   = (string) tpi_meta( $id, 'tp_situacao_em' );
	if ( isset( $mapa[ $sit ] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $em ) ) {
		$motivo = tpi_texto_simples( tpi_meta( $id, 'tp_situacao_motivo' ) );
		$m      = array( 'em' => $em, 'nota' => $mapa[ $sit ][0] . ': ' . ( $motivo ? $motivo : $mapa[ $sit ][1] ) );
	}
	$em = (string) tpi_meta( $id, 'tp_mudanca_em' );
	$nt = tpi_texto_simples( tpi_meta( $id, 'tp_mudanca_nota' ) );
	if ( $nt && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $em ) && $em > $m['em'] ) {
		$m = array( 'em' => $em, 'nota' => 'Correção: ' . $nt );
	}
	return $m;
}

/* Atributos data-* da mudança (vazio quando não há). */
function tpi_attr_mudanca( $m ) {
	if ( empty( $m['em'] ) ) {
		return '';
	}
	return ' data-tpi-mudou="' . esc_attr( $m['em'] ) . '" data-tpi-mudou-nota="' . esc_attr( $m['nota'] ) . '"';
}

/* Tira a marcação inline (**negrito**, [texto](url)) para usar em atributo e no selo. */
function tpi_texto_simples( $txt ) {
	$txt = preg_replace( '/\*\*(.+?)\*\*/', '$1', (string) $txt );
	$txt = preg_replace( '/\[([^\]]+)\]\((https?:[^)\s]+)\)/', '$1', $txt );
	return trim( wp_strip_all_tags( $txt ) );
}

/* Marcação inline mínima do pipeline: **negrito** e [texto](url). */
function tpi_inline( $txt ) {
	$txt = esc_html( (string) $txt );
	$txt = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $txt );
	return preg_replace_callback(
		'/\[([^\]]+)\]\((https?:[^)\s]+)\)/',
		function ( $m ) {
			return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>';
		},
		$txt
	);
}

function tpi_aviso_situacao( $id ) {
	$sit  = tpi_meta( $id, 'tp_situacao' );
	$mapa = tpi_situacoes();
	if ( ! isset( $mapa[ $sit ] ) ) {
		return '';
	}
	$motivo = tpi_meta( $id, 'tp_situacao_motivo' );
	$em     = tpi_meta( $id, 'tp_situacao_em' );
	$html   = '<div class="tpi-aviso tpi-situacao tpi-situacao--' . esc_attr( $sit ) . '" role="note"><strong>' . esc_html( $mapa[ $sit ][0] ) . '.</strong> ' . ( $motivo ? tpi_inline( $motivo ) : esc_html( $mapa[ $sit ][1] ) );
	if ( $em ) {
		$html .= ' <span class="tpi-situacao__data">(conferido em <time datetime="' . esc_attr( $em ) . '">' . esc_html( tpi_data_br( $em ) ) . '</time>)</span>';
	}
	return $html . '</div>';
}

/* Edição de Teses (publicada) com um número, dentro do hub do tribunal. */
function tpi_teses_por_numero( $tribunal, $numero ) {
	$q = get_posts( array( 'post_type' => 'informativo', 'numberposts' => 1, 'post_status' => 'publish', 'meta_query' => array( array( 'key' => 'tp_tipo', 'value' => 'teses' ), array( 'key' => 'tp_tribunal', 'value' => $tribunal ), array( 'key' => 'tp_numero', 'value' => (int) $numero ) ) ) );
	return $q ? $q[0] : null;
}

/* Selo no julgado: "Esta tese está na Edição N do Jurisprudência em Teses" (link só se a edição já tem página). */
function tpi_selo_teses( $id ) {
	$n = (int) tpi_meta( $id, 'tp_jt_edicao' );
	if ( ! $n ) {
		return '';
	}
	$ed    = tpi_teses_por_numero( 'STJ', $n );
	$rot   = 'Edição ' . $n . ' do Jurisprudência em Teses do STJ';
	$alvo  = $ed ? '<a href="' . esc_url( get_permalink( $ed ) ) . '">' . esc_html( $rot ) . '</a>' : esc_html( $rot );
	return '<p class="tpi-selo-teses">Esta tese também está na ' . $alvo . '.</p>';
}

function tpi_resumo_teses( $id ) {
	$itens = array();
	$at    = tpi_meta( $id, 'tp_atualizada_em' );
	$fonte = tpi_meta( $id, 'tp_fonte_url' );
	if ( $at ) {
		$itens[] = 'Edição oficial atualizada em <time datetime="' . esc_attr( $at ) . '">' . esc_html( tpi_data_br( $at ) ) . '</time>';
	}
	if ( tpi_meta( $id, 'tp_situacao_em' ) ) {
		$itens[] = 'Situação das teses conferida em ' . esc_html( tpi_data_br( tpi_meta( $id, 'tp_situacao_em' ) ) );
	}
	if ( $fonte ) {
		$itens[] = '<a href="' . esc_url( $fonte ) . '" rel="noopener" target="_blank">Edição oficial no ' . esc_html( tpi_meta( $id, 'tp_tribunal' ) ) . '<span class="tpi-sr"> (abre em nova aba)</span></a>';
	}
	return $itens ? '<p class="tpi-resumo-edicao">' . implode( '<span aria-hidden="true"> · </span>', $itens ) . '</p>' : '';
}

/* Edições de um hub de Teses, da maior para a menor (sem número, como os grupos de repercussão geral, vão por título). */
function tpi_edicoes_teses( $hub_id ) {
	if ( ! $hub_id ) {
		return array();
	}
	$eds = get_posts( array( 'post_type' => 'informativo', 'post_status' => 'publish', 'post_parent' => $hub_id, 'numberposts' => 500, 'orderby' => 'title', 'order' => 'ASC' ) );
	usort(
		$eds,
		function ( $x, $y ) {
			return (int) tpi_meta( $y->ID, 'tp_numero' ) <=> (int) tpi_meta( $x->ID, 'tp_numero' );
		}
	);
	return $eds;
}

/* Hub de Teses: edições agrupadas por ramo, da mais nova para a mais antiga. */
function tpi_lista_teses( $hub_id ) {
	$eds = tpi_edicoes_teses( $hub_id );
	if ( ! $eds ) {
		return '<p class="tpi-vazio">As primeiras edições comentadas entram aqui em breve.</p>';
	}
	$grupos = array();
	foreach ( $eds as $e ) {
		$ramo = tpi_meta( $e->ID, 'tp_ramo_jt' );
		if ( ! $ramo ) {
			$t    = get_the_terms( $e, 'ramo_direito' );
			$ramo = ( $t && ! is_wp_error( $t ) ) ? $t[0]->name : 'Outros temas';
		}
		$grupos[ $ramo ][] = $e;
	}
	ksort( $grupos );
	$html = '<section class="tpi-julgados tpi-teses-lista" aria-labelledby="tpi-teses-titulo"><h2 id="tpi-teses-titulo">Edições comentadas</h2>';
	foreach ( $grupos as $ramo => $lista ) {
		$html .= '<h3>' . esc_html( $ramo ) . '</h3><ul>';
		foreach ( $lista as $e ) {
			$sit   = tpi_meta( $e->ID, 'tp_situacao' );
			$mapa  = tpi_situacoes();
			$extra = isset( $mapa[ $sit ] ) ? ' <span class="tpi-situacao-chip">' . esc_html( $mapa[ $sit ][0] ) . '</span>' : '';
			$html .= '<li><a href="' . esc_url( get_permalink( $e ) ) . '">' . esc_html( get_the_title( $e ) ) . '</a>' . $extra . '</li>';
		}
		$html .= '</ul>';
	}
	return $html . '</section>';
}

function tpi_ficha_julgado( $id ) {
	$linhas = array(
		'Informativo'        => trim( tpi_meta( $id, 'tp_tribunal' ) . ' ' . tpi_meta( $id, 'tp_numero' ) ),
		'Órgão julgador'     => tpi_meta( $id, 'tp_orgao' ),
		'Relator(a)'         => tpi_meta( $id, 'tp_relator' ),
		'Processo'           => tpi_meta( $id, 'tp_processo' ),
		'Julgado em'         => tpi_data_br( tpi_meta( $id, 'tp_data_julgamento' ) ),
	);
	$pai  = wp_get_post_parent_id( $id );
	$html = '<dl class="tpi-ficha" aria-label="Dados do julgamento">';
	foreach ( $linhas as $rotulo => $valor ) {
		if ( $valor ) {
			$dd    = ( 'Informativo' === $rotulo && $pai ) ? '<a href="' . esc_url( get_permalink( $pai ) ) . '">' . esc_html( $valor ) . '</a>' : esc_html( $valor );
			$html .= '<div><dt>' . esc_html( $rotulo ) . '</dt><dd>' . $dd . '</dd></div>';
		}
	}
	$rp = tpi_ramo_principal( get_post( $id ) );
	if ( $rp ) { // 0.7.19: link interno para a página do ramo (hub temático).
		$tps   = tpi_topicos_de( get_post( $id ), $rp->term_id );
		$html .= '<div><dt>Ramo do Direito</dt><dd><a href="' . esc_url( get_term_link( $rp ) ) . '">' . esc_html( $rp->name ) . '</a>' . ( $tps ? ' · ' . esc_html( implode( ', ', wp_list_pluck( $tps, 'name' ) ) ) : '' ) . '</dd></div>';
	}
	return $html . '</dl>';
}

function tpi_fonte_julgado( $id ) {
	$fonte = tpi_meta( $id, 'tp_fonte_url' );
	if ( ! $fonte ) {
		return '';
	}
	return '<section class="tpi-fonte" aria-labelledby="tpi-fonte-titulo"><h2 id="tpi-fonte-titulo">Fonte oficial</h2><p>Texto integral da nota no ' . esc_html( tpi_meta( $id, 'tp_tribunal' ) ) . ': <a href="' . esc_url( $fonte ) . '" rel="noopener" target="_blank">' . esc_html( trim( 'Informativo ' . tpi_meta( $id, 'tp_numero' ) . ', ' . tpi_meta( $id, 'tp_processo' ), ', ' ) ) . '</a> (abre em nova aba).</p></section>';
}

function tpi_lista_julgados( $periodico_id ) {
	$filhos = get_posts(
		array(
			'post_type'   => 'informativo',
			'post_parent' => $periodico_id,
			'numberposts' => 100,
			'orderby'     => 'menu_order date',
			'order'       => 'ASC',
		)
	);
	if ( ! $filhos ) {
		return '';
	}
	$html  = '<section class="tpi-julgados" aria-labelledby="tpi-julgados-titulo"><h2 id="tpi-julgados-titulo">Os ' . count( $filhos ) . ' julgados desta edição</h2>';
	// 0.6: o progresso vem da conta do app (Supabase) e é preenchido no navegador; a página pode ficar em cache.
	$html .= '<p class="tpi-progresso" data-tpi-progresso="' . esc_attr( implode( ',', wp_list_pluck( $filhos, 'ID' ) ) ) . '" hidden><span class="tpi-progresso__barra" aria-hidden="true"><span></span></span><span class="tpi-progresso__texto"></span></p>';
	// 0.4: agrupado por ramo (índice escaneável, como nos buscadores de jurisprudência), na ordem do informativo.
	$grupos = array();
	foreach ( $filhos as $f ) {
		$rp    = tpi_ramo_principal( $f );
		$ramo  = $rp ? $rp->name : 'Outros temas';
		$grupos[ $ramo ][] = $f;
	}
	if ( count( $grupos ) > 1 ) {
		$html .= '<nav class="tpi-ramos-edicao" aria-label="Ramos desta edição"><ul>';
		foreach ( $grupos as $ramo => $lista ) {
			$html .= '<li><a href="#ramo-' . esc_attr( sanitize_title( $ramo ) ) . '">' . esc_html( $ramo ) . ' <span class="tpi-ramos__n">' . count( $lista ) . '</span></a></li>';
		}
		$html .= '</ul></nav>';
	}
	foreach ( $grupos as $ramo => $lista ) {
		$html .= '<h3 class="tpi-grupo" id="ramo-' . esc_attr( sanitize_title( $ramo ) ) . '">' . esc_html( $ramo ) . '</h3><ol class="tpi-cards tpi-cards--julgados">';
		foreach ( $lista as $f ) {
			$html .= '<li class="tpi-card" data-tpi-id="' . esc_attr( $f->ID ) . '" data-tpi-titulo="' . esc_attr( get_the_title( $f ) ) . '" data-tpi-url="' . esc_url( get_permalink( $f ) ) . '" data-tpi-trib="' . esc_attr( tpi_meta( $f->ID, 'tp_tribunal' ) ) . '" data-tpi-num="' . esc_attr( tpi_meta( $f->ID, 'tp_numero' ) ) . '"' . tpi_attr_mudanca( tpi_mudanca( $f->ID ) ) . '>';
			$proc  = tpi_meta( $f->ID, 'tp_processo' );
			if ( $proc ) {
				$html .= '<p class="tpi-card__ramo">' . esc_html( $proc . ( tpi_meta( $f->ID, 'tp_orgao' ) ? ' · ' . tpi_meta( $f->ID, 'tp_orgao' ) : '' ) ) . '</p>';
			}
			$html .= tpi_chips( $f->ID );
			$html .= '<h4 class="tpi-card__titulo"><a href="' . esc_url( get_permalink( $f ) ) . '">' . esc_html( get_the_title( $f ) ) . '</a></h4>';
			$resumo = $f->post_excerpt ? $f->post_excerpt : tpi_meta( $f->ID, 'tp_destaque' );
			if ( $resumo ) {
				$html .= '<p class="tpi-card__resumo">' . esc_html( wp_trim_words( $resumo, 40 ) ) . '</p>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';
	}
	$html .= '</section>';
	$resumo = tpi_resumo_blocos( $periodico_id, $filhos );
	if ( ! $resumo ) {
		return $html;
	}
	// 0.21.0: a mesma URL serve as duas leituras (sem página nova concorrendo no Google). #resumo abre direto no resumo.
	$alterna = '<div class="tpr-alterna" role="tablist" aria-label="Formato da edição">'
		. '<button type="button" role="tab" id="tpr-tab-cards" aria-controls="tpr-cards" aria-selected="true">Comentado</button>'
		. '<button type="button" role="tab" id="tpr-tab-resumo" aria-controls="tpr-resumo" aria-selected="false" tabindex="-1">Resumo</button></div>';
	$html    = str_replace( '<section class="tpi-julgados" aria-labelledby', '<section class="tpi-julgados" id="tpr-cards" role="tabpanel" aria-labelledby', $html );
	return $alterna . $html . $resumo;
}

/* ---------------------------------------------------------------------------
 * 0.21.0: marcadores e resumo da edição
 * ------------------------------------------------------------------------- */
function tpi_icone( $k ) {
	$d = array(
		'importante' => 'M12 2l3 6.6 7 .7-5.3 4.8 1.6 7L12 17.4 5.7 21l1.6-7L2 9.3l7-.7z',
		'vinculante' => 'M12 2 2 7v2h20V7zM4 11v7h3v-7zm6.5 0v7h3v-7zM17 11v7h3v-7zM2 20v2h20v-2z',
		'virada'     => 'M17.6 6.4A8 8 0 1 0 20 12h-2.5a5.5 5.5 0 1 1-1.6-3.9L13 11h7V4z',
	);
	return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="' . $d[ $k ] . '"/></svg>';
}

/* Precedente qualificado, lido do campo processo (objetivo, sem trabalho manual).
   STF + "Tema" = repercussão geral; STJ + "Tema de Relevância" = relevância; STJ + "Tema" = repetitivo. */
function tpi_vinculante( $id ) {
	$proc = (string) tpi_meta( $id, 'tp_processo' );
	if ( ! preg_match( '/\bTemas?\b|\bRG\b|repercuss|repetitiv/iu', $proc ) ) {
		return '';
	}
	if ( 'STF' === tpi_meta( $id, 'tp_tribunal' ) ) {
		return 'Repercussão geral';
	}
	return preg_match( '/relev[âa]ncia/iu', $proc ) ? 'Tema de relevância' : 'Recurso repetitivo';
}

function tpi_marcadores( $id ) {
	$m = json_decode( (string) tpi_meta( $id, 'tp_marcadores' ), true );
	$m = is_array( $m ) ? $m : array();
	$out = array();
	if ( ! empty( $m['importante'] ) ) {
		$out['importante'] = array( 'Julgado importante', tpi_texto_simples( $m['importante'] ) );
	}
	$v = tpi_vinculante( $id );
	if ( $v ) {
		$out['vinculante'] = array( $v, 'Tema de relevância' === $v ? 'Questão julgada pelo STJ no rito da relevância da questão federal.' : 'Tese fixada em precedente qualificado, que juízes e tribunais devem observar.' );
	}
	if ( ! empty( $m['virada'] ) ) {
		$out['virada'] = array( 'Mudança de entendimento', tpi_texto_simples( $m['virada'] ) );
	}
	return $out;
}

/* Selos do julgado. Na página do julgado ($detalhe) o motivo aparece por extenso; na lista, só no title. */
function tpi_chips( $id, $detalhe = false ) {
	$m = tpi_marcadores( $id );
	if ( ! $m ) {
		return '';
	}
	$html = '<ul class="tpr-chips" aria-label="Marcadores do julgado">';
	foreach ( $m as $k => $v ) {
		$html .= '<li class="tpr-chip tpr-chip--' . $k . '" title="' . esc_attr( $v[1] ) . '">' . tpi_icone( $k ) . esc_html( $v[0] ) . '</li>';
	}
	$html .= '</ul>';
	if ( $detalhe ) {
		$notas = '';
		foreach ( array( 'importante' => 'Por que é importante', 'virada' => 'O que mudou' ) as $k => $rot ) {
			if ( isset( $m[ $k ] ) && $m[ $k ][1] ) {
				$cru    = json_decode( (string) tpi_meta( $id, 'tp_marcadores' ), true );
				$notas .= '<p><strong>' . esc_html( $rot ) . ':</strong> ' . tpi_inline( $cru[ $k ] ) . '</p>';
			}
		}
		$html = '<div class="tpr-chips-julgado">' . $html . $notas . '</div>';
	}
	return $html;
}

function tpi_legenda_marcadores() {
	return '<p class="tpr-legenda"><span class="tpr-chip tpr-chip--importante">' . tpi_icone( 'importante' ) . 'Julgado importante</span> '
		. '<span class="tpr-chip tpr-chip--vinculante">' . tpi_icone( 'vinculante' ) . 'Repetitivo, repercussão geral ou relevância</span> '
		. '<span class="tpr-chip tpr-chip--virada">' . tpi_icone( 'virada' ) . 'Mudança de entendimento</span></p>';
}

/* Um bloco do resumo (tese, caso, porquê, como cai em prova). Serve à edição e ao painel do app (0.21.1). $mais = rodapé do bloco. */
function tpi_resumo_bloco( $f, $r, $quem, $rid, $mais ) {
	$tit  = get_the_title( $f );
	$rp   = tpi_ramo_principal( $f );
	$meta = trim( tpi_meta( $f->ID, 'tp_processo' ) . ( tpi_meta( $f->ID, 'tp_orgao' ) ? ' · ' . tpi_meta( $f->ID, 'tp_orgao' ) : '' ) );
	$b    = '<article class="tpr-bloco" id="' . esc_attr( $rid ) . '"><p class="tpr-meta">' . ( $rp ? '<span class="tpr-ramo">' . esc_html( $rp->name ) . '</span> ' : '' ) . esc_html( $meta ) . '</p>';
	$b   .= tpi_chips( $f->ID ) . '<h3 class="tpr-titulo">' . esc_html( $tit ) . '</h3>';
	if ( $r ) {
		$b .= '<div class="tpr-tese"><p class="tpr-rot">Tese</p><p>' . tpi_inline( $r['tese'] ) . '</p></div>';
		if ( ! empty( $r['caso'] ) ) {
			$b .= '<p class="tpr-rot">O caso</p><p>' . tpi_inline( $r['caso'] ) . '</p>';
		}
		foreach ( array( 'pontos' => 'Por que ' . $quem . ' decidiu assim', 'prova' => 'Como cai em prova' ) as $k => $rot ) {
			if ( ! empty( $r[ $k ] ) && is_array( $r[ $k ] ) ) {
				$b .= '<p class="tpr-rot">' . esc_html( $rot ) . '</p><ul>';
				foreach ( $r[ $k ] as $item ) {
					$b .= '<li>' . tpi_inline( $item ) . '</li>';
				}
				$b .= '</ul>';
			}
		}
	} elseif ( $f->post_excerpt ) {
		$b .= '<p>' . esc_html( $f->post_excerpt ) . '</p>';
	}
	return $b . '<p class="tpr-mais">' . $mais . '</p></article>';
}

/* 0.21.1: painel "Ver julgado comentado" do app (?tp_embed=1, fora do Google). Julgado com resumo abre em
   Resumo | Comentado, começando no Resumo; a página pública do julgado não muda. */
function tpi_embed_app() {
	return isset( $_GET['tp_embed'] ) && '1' === $_GET['tp_embed'];
}

function tpi_julgado_em_abas( $id, $comentado ) {
	$post = get_post( $id );
	$r    = tpi_resumo_de( $id );
	if ( ! $post || ! $r ) {
		return '';
	}
	$quem    = 'STF' === tpi_meta( $id, 'tp_tribunal' ) ? 'o STF' : 'o STJ';
	$alterna = '<div class="tpr-alterna tpr-alterna--julgado" role="tablist" aria-label="Formato do julgado" data-tpr-evento="julgado_formato">'
		. '<button type="button" role="tab" id="tpr-tab-resumo" aria-controls="tpr-resumo" aria-selected="true">Resumo</button>'
		. '<button type="button" role="tab" id="tpr-tab-cards" aria-controls="tpr-cards" aria-selected="false" tabindex="-1">Comentado</button></div>';
	$resumo  = '<section class="tpr-resumo tpr-resumo--julgado" id="tpr-resumo" role="tabpanel" aria-labelledby="tpr-tab-resumo">'
		. tpi_resumo_bloco( $post, $r, $quem, 'resumo-1', '<button type="button" class="tpr-ir" data-tpr-ir="cards">Ler o comentário completo, com questões e flashcards</button>' )
		. '</section>';
	return $alterna . $resumo . '<div class="tpr-comentado" id="tpr-cards" role="tabpanel" aria-labelledby="tpr-tab-cards" hidden>' . $comentado . '</div>';
}

function tpi_resumo_de( $id ) {
	$r = json_decode( (string) tpi_meta( $id, 'tp_resumo' ), true );
	return is_array( $r ) && ! empty( $r['tese'] ) ? $r : null;
}

/* Resumo da edição: um bloco por julgado, todo em HTML do servidor (o Google lê o texto das abas).
   Só aparece quando pelo menos um julgado tem tp_resumo; julgado sem resumo vira bloco curto com o excerto. */
function tpi_resumo_blocos( $periodico_id, $filhos ) {
	$tem = false;
	foreach ( $filhos as $f ) {
		if ( tpi_resumo_de( $f->ID ) ) {
			$tem = true;
			break;
		}
	}
	if ( ! $tem ) {
		return '';
	}
	$trib  = tpi_meta( $periodico_id, 'tp_tribunal' );
	$num   = tpi_meta( $periodico_id, 'tp_numero' );
	$quem  = 'STF' === $trib ? 'o STF' : 'o STJ';
	$data  = tpi_data_br( tpi_meta( $periodico_id, 'tp_data_publicacao' ) );
	$autor = tpi_autor();
	$html  = '<section class="tpr-resumo" id="tpr-resumo" role="tabpanel" aria-labelledby="tpr-tab-resumo" hidden>';
	$html .= '<h2 class="tpr-cab">Resumo do Informativo ' . esc_html( $num . ' do ' . $trib ) . '</h2>';
	$html .= '<p class="tpr-sub">' . esc_html( trim( ( $data ? 'Divulgado em ' . $data . ' · ' : '' ) . count( $filhos ) . ' julgados · Tchau Pobreza, por ' . ( $autor['nome'] ?? 'Jonatan Ramos' ) ) ) . '</p>';
	$html .= '<div class="tpr-topo"><p class="tpr-intro">A edição inteira em uma tela: a tese, o caso, o porquê e o que cai em prova. O comentário completo, com questões e flashcards, está no link do fim de cada bloco.</p>'
		. '<button type="button" class="tpr-imprimir" data-tpr-imprimir>Imprimir ou salvar em PDF</button></div>';
	$html .= tpi_legenda_marcadores();
	$nav   = '';
	$blocos = '';
	foreach ( $filhos as $i => $f ) {
		$r    = tpi_resumo_de( $f->ID );
		$rid  = 'resumo-' . ( $i + 1 );
		$tit  = get_the_title( $f );
		$nav .= '<li><a href="#' . $rid . '">' . esc_html( ! empty( $r['rotulo'] ) ? $r['rotulo'] : $tit ) . '</a></li>';
		$blocos .= tpi_resumo_bloco( $f, $r, $quem, $rid, '<a href="' . esc_url( get_permalink( $f ) ) . '">Ler o comentário completo, com questões e flashcards</a>' );
	}
	$html .= '<nav class="tpr-indice" aria-label="Julgados do resumo"><ol>' . $nav . '</ol></nav>' . $blocos;
	$html .= '<p class="tpr-rodape">Resumo do Informativo ' . esc_html( $num . ' do ' . $trib ) . ' · ' . esc_html( preg_replace( '#^https?://#', '', get_permalink( $periodico_id ) ) ) . '</p>';
	return $html . '</section>';
}

/* Newsletter. 0.7.6 trouxe o Kit; 0.7.8 volta ao Hostinger Reach (decisão do Jonatan em 05/11/2026: plano Grow,
   sem a marca do provedor nos e-mails). Opção tpi_newsletter_provedor: "kit" (padrão, para a troca não derrubar o formulário) ou "reach";
   o Reach só entra quando também tpi_reach_pronto = 1 (site conectado e lista conferida).
   - reach: o formulário posta na rota própria tp-informativos/v1/newsletter, que repassa ao plugin Hostinger Reach
     pelo gancho oficial hostinger_reach_submit. Não usa o nonce do WP (a página fica no cache do LiteSpeed e o nonce
     venceria); a proteção é um campo isca e um limite de 5 tentativas por IP por hora.
   - kit: posta direto no Kit (opção tpi_kit_form_id).
   O formulário só aparece com o provedor pronto; sem ele, o botão leva à /newsletter/ se ela existir, ou some. */
function tpi_newsletter_provedor() {
	$p = get_option( 'tpi_newsletter_provedor', 'kit' );
	if ( 'kit' === $p ) {
		return preg_replace( '/\D/', '', (string) get_option( 'tpi_kit_form_id', '' ) ) ? 'kit' : '';
	}
	return has_action( 'hostinger_reach_submit' ) && get_option( 'tpi_reach_pronto', '' ) ? 'reach' : '';
}

function tpi_kit_form_html( $origem ) {
	$prov = tpi_newsletter_provedor();
	if ( '' === $prov ) {
		return '';
	}
	if ( 'kit' === $prov ) {
		$form = preg_replace( '/\D/', '', (string) get_option( 'tpi_kit_form_id', '' ) );
		$acao = apply_filters( 'tpi_kit_form_action', 'https://app.kit.com/forms/' . $form . '/subscriptions', $form );
	} else {
		$acao = rest_url( 'tp-informativos/v1/newsletter' );
	}
	$uid = 'tpi-nl-email-' . wp_unique_id();
	return '<form class="tpi-nl-form" action="' . esc_url( $acao ) . '" method="post" data-provedor="' . esc_attr( $prov ) . '" data-origem="' . esc_attr( $origem ) . '">'
		. '<label for="' . $uid . '" class="tpi-nl-rotulo">Seu e-mail</label>'
		. '<div class="tpi-nl-campos"><input id="' . $uid . '" type="email" name="email_address" required autocomplete="email" inputmode="email" placeholder="voce@exemplo.com">'
		. '<button type="submit" class="tpi-btn">Quero receber</button></div>'
		. ( 'reach' === $prov ? '<div class="tpi-nl-isca" aria-hidden="true"><label>Não preencha<input type="text" name="site" tabindex="-1" autocomplete="off"></label></div><input type="hidden" name="origem" value="' . esc_attr( $origem ) . '">' : '' )
		. '<p class="tpi-nl-aviso">Você vai receber um e-mail para confirmar a inscrição. Dá para sair a qualquer momento. Veja a <a href="' . esc_url( home_url( '/privacidade/' ) ) . '">política de privacidade</a>.</p>'
		. '</form>';
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'tp-informativos/v1', '/newsletter', array(
		'methods'             => 'POST',
		'permission_callback' => '__return_true',
		'callback'            => 'tpi_newsletter_inscrever',
	) );
} );

function tpi_newsletter_inscrever( WP_REST_Request $req ) {
	$json  = false !== strpos( (string) $req->get_header( 'accept' ), 'application/json' );
	$volta = function ( $ok, $msg, $status = 200 ) use ( $json ) {
		if ( $json ) {
			return new WP_REST_Response( array( 'status' => $ok ? 'success' : 'failed', 'mensagem' => $msg ), $status );
		}
		$r = new WP_REST_Response( null, 303 );
		$r->header( 'Location', add_query_arg( 'inscricao', $ok ? 'ok' : 'erro', home_url( '/newsletter/' ) ) );
		return $r;
	};
	if ( '' !== (string) $req->get_param( 'site' ) ) {
		return $volta( true, 'ok' ); // robô: finge sucesso
	}
	$email = sanitize_email( (string) $req->get_param( 'email_address' ) );
	if ( ! is_email( $email ) ) {
		return $volta( false, 'E-mail inválido.', 400 );
	}
	if ( ! has_action( 'hostinger_reach_submit' ) ) {
		return $volta( false, 'Inscrições indisponíveis no momento.', 503 );
	}
	$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	$chave = 'tpi_nl_' . md5( $ip );
	$n     = (int) get_transient( $chave );
	if ( $n >= 5 ) {
		return $volta( false, 'Muitas tentativas. Tente de novo mais tarde.', 429 );
	}
	set_transient( $chave, $n + 1, HOUR_IN_SECONDS );

	$falhou = false;
	$pega   = function () use ( &$falhou ) { $falhou = true; };
	add_action( 'hostinger_reach_contact_failed', $pega );
	do_action( 'hostinger_reach_submit', array(
		'group'    => apply_filters( 'tpi_reach_grupo', 'Newsletter informativos' ),
		'tags'     => '',
		'email'    => $email,
		'name'     => '',
		'surname'  => '',
		'phone'    => '',
		'metadata' => array( 'plugin' => 'tp-informativos', 'origem' => sanitize_key( (string) $req->get_param( 'origem' ) ) ),
	) );
	remove_action( 'hostinger_reach_contact_failed', $pega );
	return $falhou ? $volta( false, 'Não deu certo. Tente de novo em instantes.', 502 ) : $volta( true, 'ok' );
}

function tpi_newsletter_cta() {
	$form = tpi_kit_form_html( 'julgado' );
	if ( '' === $form ) {
		$pag = get_page_by_path( 'newsletter' );
		if ( ! $pag || 'publish' !== $pag->post_status ) {
			return '';
		}
		$url  = apply_filters( 'tpi_newsletter_url', get_permalink( $pag ) );
		$form = '<p><a class="tpi-btn" href="' . esc_url( $url ) . '">Quero receber</a></p>';
	}
	return '<section class="tpi-newsletter" aria-labelledby="tpi-nl-titulo"><h2 id="tpi-nl-titulo">Receba o próximo informativo comentado</h2><p>Um e-mail por semana, às quartas, com os informativos do STJ e do STF comentados: a tese, como ela cai em prova e flashcards prontos para revisar.</p>' . $form . '</section>';
}
add_shortcode( 'tp_newsletter', function () {
	$form = tpi_kit_form_html( 'pagina' );
	$q    = isset( $_GET['inscricao'] ) ? sanitize_key( wp_unslash( $_GET['inscricao'] ) ) : '';
	if ( 'ok' === $q ) {
		$form = '<p class="tpi-nl-status" role="status">Quase lá! Enviamos um e-mail de confirmação de newsletter@tchaupobreza.com.br. Clique no botão dele para ativar a inscrição. Ele chega em poucos segundos; se não aparecer, olhe as abas Promoções e Spam.</p>';
	} elseif ( 'erro' === $q ) {
		$form = '<p class="tpi-nl-status" role="status">Não deu certo. Confira o e-mail e tente de novo.</p>' . $form;
	}
	return $form ? '<section class="tpi-newsletter tpi-newsletter-pagina">' . $form . '</section>' : '';
} );
add_action( 'init', function () {
	register_setting( 'general', 'tpi_newsletter_provedor', array( 'type' => 'string', 'default' => 'kit', 'show_in_rest' => true, 'sanitize_callback' => function ( $v ) {
		return 'kit' === $v ? 'kit' : 'reach';
	} ) );
	register_setting( 'general', 'tpi_reach_pronto', array( 'type' => 'string', 'default' => '', 'show_in_rest' => true, 'sanitize_callback' => function ( $v ) {
		return $v ? '1' : '';
	} ) );
	register_setting( 'general', 'tpi_kit_form_id', array( 'type' => 'string', 'default' => '', 'show_in_rest' => true, 'sanitize_callback' => function ( $v ) {
		return preg_replace( '/\D/', '', (string) $v );
	} ) );
} );

/* ---------------------------------------------------------------------------
 * 3. Marcar como lido: conta do app (Supabase), a mesma de ?tp_app=1  (0.6)
 *    Grava em public.tp_records (kind 'informativo', id 'informativo:<post_id>'), protegido pela RLS
 *    tp_records_own. O HTML sai igual para todos (cache do LiteSpeed ok); o estado é aplicado em tpi.js.
 * ------------------------------------------------------------------------- */
function tpi_lidos_usuario() {
	return array(); // compatibilidade: o estado não é mais conhecido no servidor
}

function tpi_app_opcao( $chave ) {
	$o = get_option( 'tp_config', array() );
	return is_array( $o ) && isset( $o[ $chave ] ) ? $o[ $chave ] : '';
}

function tpi_supabase() {
	return apply_filters(
		'tpi_supabase',
		array(
			'url'    => 'https://jzzfbusaarewckpjifpf.supabase.co',
			'key'    => 'sb_publishable_Dxoblx3dXzg9WGxmLHF7iQ_GuBTq-ej', // chave pública (a mesma que o app publica na página)
			'appUrl' => home_url( '/?tp_app=1' ),
			// Login no modal do informativo (0.7.17): mesmas opções do plugin do app (Configurações → Tchau Pobreza).
			'google' => tpi_app_opcao( 'google' ) ? (string) tpi_app_opcao( 'google_client_id' ) : '',
			'signup' => (bool) tpi_app_opcao( 'signup' ),
		)
	);
}

function tpi_botao_lido( $id, $compacto = false ) {
	$classe = 'tpi-lido' . ( $compacto ? ' tpi-lido--topo' : '' );
	$campo  = 'tpi-lido-' . $id . ( $compacto ? '-topo' : '' );
	$dados  = ' data-tpi-id="' . esc_attr( $id ) . '" data-tpi-titulo="' . esc_attr( get_the_title( $id ) ) . '" data-tpi-url="' . esc_url( get_permalink( $id ) ) . '" data-tpi-trib="' . esc_attr( tpi_meta( $id, 'tp_tribunal' ) ) . '" data-tpi-num="' . esc_attr( tpi_meta( $id, 'tp_numero' ) ) . '"' . tpi_attr_mudanca( tpi_mudanca( $id ) );
	// 0.7.16: mesmo marcador do app (✓ cinza / ✓✓ verde, ícones check e check-check do Lucide), aqui com texto curto ao
	// lado ("Marcar como lido" / "Lido"), porque é a página onde chega o visitante do Google. Um botão só, de alternância:
	// nome acessível fixo + aria-pressed; a linha role=status (visualmente oculta) anuncia a mudança no leitor de tela.
	$svg  = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" ';
	$html = '<div class="' . esc_attr( $classe ) . '"' . $dados . ' hidden>'
		. ( $compacto ? '' : '<p class="tpi-lido__pergunta">Terminou a leitura?</p>' )
		. '<p class="tpi-lido__estado tpi-sr" role="status"></p>'
		. '<button type="button" class="tpi-lido__botao" aria-pressed="false" aria-label="Marcar como lido" title="Marcar como lido">'
		. $svg . 'class="i-um" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>'
		. $svg . 'class="i-dois" stroke-width="2.4"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>'
		. '<span class="tpi-lido__acao" aria-hidden="true">Marcar como lido</span></button>'
		. '<p class="tpi-lido__erro" role="status"></p>'
		. '<p class="tpi-lido__convite" hidden>Para salvar, <a href="' . esc_url( tpi_supabase()['appUrl'] ) . '">entre na sua conta do Tchau Pobreza</a>. Os julgados que você marcar ficam guardados junto com o resto dos seus estudos, em qualquer dispositivo.</p></div>';
	return $html;
}

/* ---------------------------------------------------------------------------
 * 4. Calendário: [tp_calendario tribunal="STJ" limite="12"]
 *    Tabela HTML (indexável e acessível), não widget em JavaScript.
 * ------------------------------------------------------------------------- */
/* 0.25.1: edições do calendário que ainda não foram comentadas (previstas, com data estimada ou já divulgadas
   aguardando o comentário), da mais próxima para a mais distante. As comentadas ficam fora: já aparecem nas
   listas por edição ([tp_edicoes]) e nos hubs de tribunal, e antes empurravam as próximas datas para fora do limite. */
function tpi_calendario_pendentes( $trib = '' ) {
	$trib  = strtoupper( (string) $trib );
	$hoje  = wp_date( 'Y-m-d' );
	$itens = array();
	foreach ( tpi_calendario_oficial() as $c ) {
		if ( $trib && $c['tribunal'] !== $trib ) {
			continue;
		}
		$itens[ $c['tribunal'] . '-' . (int) $c['numero'] ] = array( 'tribunal' => $c['tribunal'], 'numero' => (int) $c['numero'], 'data' => $c['data'], 'post' => null, 'estimada' => ! empty( $c['estimada'] ) );
	}
	$meta = array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ) );
	if ( $trib ) {
		$meta[] = array( 'key' => 'tp_tribunal', 'value' => $trib );
	}
	foreach ( get_posts( array( 'post_type' => 'informativo', 'numberposts' => -1, 'meta_query' => $meta ) ) as $p ) {
		$k = tpi_meta( $p->ID, 'tp_tribunal' ) . '-' . (int) tpi_meta( $p->ID, 'tp_numero' );
		if ( 'previsto' !== tpi_meta( $p->ID, 'tp_status' ) ) {
			unset( $itens[ $k ] ); // já comentado
			continue;
		}
		$est         = isset( $itens[ $k ] ) && ! empty( $itens[ $k ]['estimada'] );
		$itens[ $k ] = array( 'tribunal' => tpi_meta( $p->ID, 'tp_tribunal' ), 'numero' => (int) tpi_meta( $p->ID, 'tp_numero' ), 'data' => tpi_meta( $p->ID, 'tp_data_prevista' ), 'post' => $p, 'estimada' => $est );
	}
	// Data passada só fica se houver pré-página (edição divulgada e ainda sem comentário); o resto é acervo antigo.
	$itens = array_filter( $itens, function ( $i ) use ( $hoje ) { return $i['post'] || $i['data'] >= $hoje; } );
	usort( $itens, function ( $x, $y ) { return strcmp( $x['data'] . $x['tribunal'], $y['data'] . $y['tribunal'] ); } );
	$vistos = array();
	foreach ( $itens as &$i ) {
		$i['proximo'] = ! isset( $vistos[ $i['tribunal'] ] ); // primeira pendente de cada tribunal
		$vistos[ $i['tribunal'] ] = true;
	}
	unset( $i );
	return $itens;
}

function tpi_calendario_situacao( $i, $curto = false ) {
	if ( $i['data'] < wp_date( 'Y-m-d' ) ) {
		return '<span class="tpi-sit">Divulgado</span>';
	}
	if ( ! empty( $i['estimada'] ) ) {
		return '<span class="tpi-sit tpi-sit--est">' . ( $curto ? 'Estimada' : 'Data estimada' ) . '</span>';
	}
	return '<span class="tpi-sit tpi-sit--prev">' . ( ! empty( $i['proximo'] ) ? 'Próximo' : 'Previsto' ) . '</span>';
}

function tpi_calendario_fontes() {
	return '<p class="tpi-calendario__fonte">Fontes: <a href="https://processo.stj.jus.br/docs_internet/jurisprudencia/ajuda/CalendarioIJ.pdf" rel="noopener" target="_blank">calendário oficial do STJ</a> e <a href="https://portal.stf.jus.br/textos/verTexto.asp?servico=informativoSTF&amp;pagina=Calendario_publicacoes_Informativo" rel="noopener" target="_blank">calendário do STF</a>. Quando o STF ainda não publicou a data, ela é estimada pelo ritmo semanal das edições e marcada como tal.</p>';
}

add_shortcode( 'tp_calendario', function ( $atts ) {
	$a     = shortcode_atts( array( 'tribunal' => '', 'limite' => 8 ), $atts );
	$trib  = strtoupper( $a['tribunal'] );
	$itens = array_slice( tpi_calendario_pendentes( $trib ), 0, (int) $a['limite'] );
	if ( ! $itens ) {
		return '<div class="tpi-calendario"><p class="tpi-vazio">Nenhuma edição prevista no momento' . ( $trib ? ' para o ' . esc_html( $trib ) : '' ) . '. Assim que o tribunal publicar a próxima data, ela aparece aqui.</p>' . tpi_calendario_fontes() . '</div>';
	}
	$html = '<div class="tpi-calendario"><table><caption>Próximas datas de divulgação' . ( $trib ? ' do ' . esc_html( $trib ) : ' do STJ e do STF' ) . '</caption><thead><tr><th scope="col">Data</th><th scope="col">Edição</th><th scope="col">Situação</th></tr></thead><tbody>';
	foreach ( $itens as $i ) {
		$ts     = strtotime( $i['data'] );
		$data   = '<time datetime="' . esc_attr( $i['data'] ) . '"><span class="tpi-cal__dia">' . esc_html( wp_date( 'd', $ts ) ) . '</span> <span class="tpi-cal__mes">' . esc_html( wp_date( 'M', $ts ) ) . '</span></time>';
		$nome   = esc_html( (string) $i['numero'] );
		$edicao = ( $i['post'] && tpi_linkavel( $i['post'], 'calendario' ) ) ? '<a href="' . esc_url( get_permalink( $i['post'] ) ) . '">Informativo ' . $nome . '</a>' : 'Informativo ' . $nome;
		$html  .= '<tr><td>' . $data . '</td><td><span class="tpi-trib tpi-trib--' . esc_attr( strtolower( $i['tribunal'] ) ) . '">' . esc_html( $i['tribunal'] ) . '</span> ' . $edicao . '</td><td>' . tpi_calendario_situacao( $i ) . '</td></tr>';
	}
	return $html . '</tbody></table>' . tpi_calendario_fontes() . '</div>';
} );

/* Próximo informativo: [tp_proximo tribunal="STJ"] */
add_shortcode( 'tp_proximo', function ( $atts ) {
	$a    = shortcode_atts( array( 'tribunal' => 'STJ', 'compacto' => '' ), $atts );
	$comp = ! empty( $a['compacto'] ); // 0.18.2: versão enxuta do hub (h3, texto curto), abaixo das edições
	$hoje = wp_date( 'Y-m-d' );
	$prox = null;
	foreach ( tpi_calendario_oficial() as $c ) {
		if ( strtoupper( $a['tribunal'] ) === $c['tribunal'] && $c['data'] > $hoje && ( ! $prox || $c['data'] < $prox['data'] ) ) {
			$ja = tpi_periodico_por_numero( $c['tribunal'], $c['numero'] );
			if ( $ja && 'previsto' !== tpi_meta( $ja->ID, 'tp_status' ) ) {
				continue; // já divulgado (saiu antes da data do calendário)
			}
			$prox = $c;
		}
	}
	if ( ! $prox ) {
		return '';
	}
	$dias = (int) round( ( strtotime( $prox['data'] ) - strtotime( $hoje ) ) / DAY_IN_SECONDS );
	$post = tpi_periodico_por_numero( $prox['tribunal'], $prox['numero'] );
	$nome = 'Informativo ' . $prox['numero'] . ' do ' . $prox['tribunal'];
	$hid  = 'tpi-proximo-' . strtolower( $prox['tribunal'] );
	$quando = 1 === $dias ? 'Amanhã' : 'Em ' . $dias . ' dias';
	$titulo = ( $post && tpi_linkavel( $post, 'proximo' ) ) ? '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $nome ) . '</a>' : esc_html( $nome );
	if ( $comp ) {
		return '<li class="tpi-proximo tpi-proximo--compacto"><h3 class="tpi-proximo__titulo">' . $titulo . '</h3><p class="tpi-proximo__data">' . esc_html( $quando ) . ' · ' . ( empty( $prox['estimada'] ) ? 'prevista para ' : 'data estimada: ' ) . esc_html( tpi_data_extenso( $prox['data'] ) ) . '</p></li>';
	}
	$html = '<section class="tpi-proximo" aria-labelledby="' . $hid . '"><p class="tpi-proximo__quando">' . $quando . '</p>';
	$html .= '<h2 id="' . $hid . '" class="tpi-proximo__titulo">' . $titulo . '</h2>';
	$html .= '<p class="tpi-proximo__data">' . ( empty( $prox['estimada'] )
		? 'Divulgação prevista para ' . esc_html( tpi_data_extenso( $prox['data'] ) ) . ', segundo o calendário oficial do tribunal.'
		: 'Data estimada: ' . esc_html( tpi_data_extenso( $prox['data'] ) ) . '. O tribunal ainda não publicou o calendário desta edição.' ) . ' Os julgados entram comentados aqui no dia da divulgação.</p></section>';
	return $html;
} );

/* Edições por tribunal e ano: [tp_edicoes anos="2"]
   0.4: colunas STJ | STF, cada edição com data, nº de julgados e progresso de leitura (logado). */
add_shortcode( 'tp_edicoes', function ( $atts ) {
	$a     = shortcode_atts( array( 'anos' => 2, 'proximos' => '' ), $atts );
	$prox  = ! empty( $a['proximos'] ) ? tpi_edicoes_coluna_proximos() : '';
	$html  = '<p class="tpi-edicoes__dica">Cada edição tem duas leituras: <strong>Comentado</strong>, com o estudo completo de cada julgado, e <strong>Resumo</strong>, para revisar rápido ou imprimir.</p><div class="tpi-edicoes' . ( $prox ? ' tpi-edicoes--3' : '' ) . '">';
	foreach ( array( 'STJ', 'STF' ) as $trib ) {
		$q = get_posts( array( 'post_type' => 'informativo', 'post_status' => 'publish', 'numberposts' => 200, 'meta_key' => 'tp_numero', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_query' => array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ), array( 'key' => 'tp_tribunal', 'value' => $trib ) ) ) );
		$q     = array_values( array_filter( $q, function ( $p ) { return tpi_linkavel( $p, 'edicoes' ); } ) );
		$hid   = 'tpi-ed-' . strtolower( $trib );
		$html .= '<section class="tpi-edicoes__col" aria-labelledby="' . $hid . '"><h3 id="' . $hid . '" class="tpi-edicoes__trib"><span class="tpi-trib tpi-trib--' . strtolower( $trib ) . '">' . $trib . '</span> ' . ( 'STJ' === $trib ? 'Superior Tribunal de Justiça' : 'Supremo Tribunal Federal' ) . '</h3>';
		if ( ! $q ) {
			$html .= '<p class="tpi-vazio">As primeiras edições comentadas do ' . $trib . ' entram aqui. Veja as próximas datas no calendário.</p></section>';
			continue;
		}
		$por_ano = array();
		foreach ( $q as $p ) {
			$prev = 'previsto' === tpi_meta( $p->ID, 'tp_status' );
			$data = $prev ? tpi_meta( $p->ID, 'tp_data_prevista' ) : tpi_meta( $p->ID, 'tp_data_publicacao' );
			$por_ano[ $data ? substr( $data, 0, 4 ) : get_the_date( 'Y', $p ) ][] = array( $p, $prev, $data );
		}
		foreach ( array_slice( $por_ano, 0, (int) $a['anos'], true ) as $ano => $lista ) {
			$html .= '<h4 class="tpi-edicoes__ano">' . esc_html( $ano ) . '</h4><ul class="tpi-edicoes__lista">';
			foreach ( $lista as list( $p, $prev, $data ) ) {
				$filhos = get_posts( array( 'post_type' => 'informativo', 'post_parent' => $p->ID, 'numberposts' => 100, 'fields' => 'ids' ) );
				$n      = count( $filhos );
				$info   = $prev ? 'Previsto · ' . tpi_data_br( $data ) : tpi_data_br( $data ) . ' · ' . sprintf( _n( '%d julgado', '%d julgados', $n ), $n );
				$html  .= '<li class="tpi-edicao' . ( $prev ? ' is-previsto' : '' ) . '"' . ( $n ? ' data-tpi-ids="' . esc_attr( implode( ',', $filhos ) ) . '"' : '' ) . '><a href="' . esc_url( get_permalink( $p ) ) . '"><span class="tpi-edicao__num">' . esc_html( tpi_meta( $p->ID, 'tp_numero' ) ) . '</span><span class="tpi-edicao__info">' . esc_html( $info ) . '</span>';
				if ( $n ) {
					$html .= '<span class="tpi-edicao__lidos" hidden></span>';
				}
				$html .= '<span class="tpi-sr"> (Informativo ' . esc_html( tpi_meta( $p->ID, 'tp_numero' ) ) . ' do ' . $trib . ')</span></a></li>';
			}
			$html .= '</ul>';
		}
		$html .= '</section>';
	}
	return $html . $prox . '</div>';
} );

/* 0.25.1: terceira coluna do hub, "Próximas edições": só o que ainda vai sair (o calendário sem as edições já
   comentadas, que estão nas duas colunas ao lado). A primeira de cada tribunal é a "Próxima" e linka a pré-página
   quando tpi_links_previstos permite ("calendario" ou "todos"). */
function tpi_edicoes_coluna_proximos( $limite = 6 ) {
	$itens = array_slice( tpi_calendario_pendentes(), 0, $limite );
	$html  = '<section class="tpi-edicoes__col tpi-edicoes__col--prox" aria-labelledby="tpi-ed-prox"><h3 id="tpi-ed-prox" class="tpi-edicoes__trib">Próximas edições</h3>';
	if ( ! $itens ) {
		$html .= '<p class="tpi-vazio">Nenhuma edição prevista no momento. Assim que o STJ ou o STF publicar a próxima data, ela aparece aqui.</p>';
	} else {
		$por_ano = array();
		foreach ( $itens as $i ) {
			$por_ano[ substr( $i['data'], 0, 4 ) ][] = $i;
		}
		foreach ( $por_ano as $ano => $lista ) {
			$html .= '<h4 class="tpi-edicoes__ano">' . esc_html( $ano ) . '</h4><ul class="tpi-edicoes__lista">';
			foreach ( $lista as $i ) {
				$t      = strtolower( $i['tribunal'] );
				$quando = wp_date( 'd/m', strtotime( $i['data'] ) ); // o ano está no h4 acima
				$dentro = '<span class="tpi-edicao__num">' . esc_html( (string) $i['numero'] ) . '</span><span class="tpi-edicao__info"><span class="tpi-trib tpi-trib--' . esc_attr( $t ) . '">' . esc_html( $i['tribunal'] ) . '</span> <time datetime="' . esc_attr( $i['data'] ) . '">' . esc_html( $quando ) . '</time><span class="tpi-sr"> (Informativo ' . esc_html( (string) $i['numero'] ) . ' do ' . esc_html( $i['tribunal'] ) . ')</span></span>' . tpi_calendario_situacao( $i, true );
				$link   = $i['post'] && tpi_linkavel( $i['post'], 'calendario' );
				$html  .= '<li class="tpi-edicao is-previsto">' . ( $link ? '<a href="' . esc_url( get_permalink( $i['post'] ) ) . '">' . $dentro . '</a>' : '<div class="tpi-edicao__linha">' . $dentro . '</div>' ) . '</li>';
			}
			$html .= '</ul>';
		}
	}
	$cal = get_page_by_path( 'calendario-informativos-stj-stf' );
	if ( $cal && 'publish' === $cal->post_status ) {
		$html .= '<p class="tpi-edicoes__mais"><a href="' . esc_url( get_permalink( $cal ) ) . '">Calendário completo e fontes oficiais</a></p>';
	}
	return $html . '</section>';
}

/* Últimos periódicos: [tp_ultimos tribunal="" limite="6"] */
add_shortcode( 'tp_ultimos', function ( $atts ) {
	$a    = shortcode_atts( array( 'tribunal' => '', 'limite' => 6 ), $atts );
	$meta = array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ) );
	if ( $a['tribunal'] ) {
		$meta[] = array( 'key' => 'tp_tribunal', 'value' => strtoupper( $a['tribunal'] ) );
	}
	$q = get_posts( array( 'post_type' => 'informativo', 'numberposts' => (int) $a['limite'] + 2, 'meta_query' => $meta, 'meta_key' => 'tp_numero', 'orderby' => 'meta_value_num', 'order' => 'DESC' ) );
	$q = array_slice( array_values( array_filter( $q, function ( $p ) { return tpi_linkavel( $p, 'ultimos' ); } ) ), 0, (int) $a['limite'] );
	if ( ! $q ) {
		return '';
	}
	$html = '<ol class="tpi-cards">';
	foreach ( $q as $p ) {
		$prev  = 'previsto' === tpi_meta( $p->ID, 'tp_status' );
		$html .= '<li class="tpi-card tpi-card--edicao"><p class="tpi-card__ramo">' . ( $prev ? 'Previsto para ' . esc_html( tpi_data_br( tpi_meta( $p->ID, 'tp_data_prevista' ) ) ) : 'Divulgado em ' . esc_html( tpi_data_br( tpi_meta( $p->ID, 'tp_data_publicacao' ) ) ) ) . '</p><h3 class="tpi-card__titulo"><a href="' . esc_url( get_permalink( $p ) ) . '">' . esc_html( get_the_title( $p ) ) . '</a></h3>';
		if ( $p->post_excerpt ) {
			$html .= '<p class="tpi-card__resumo">' . esc_html( $p->post_excerpt ) . '</p>';
		}
		$filhos = get_posts( array( 'post_type' => 'informativo', 'post_parent' => $p->ID, 'numberposts' => 100, 'orderby' => 'menu_order date', 'order' => 'ASC' ) );
		if ( $filhos ) {
			$html .= '<ul class="tpi-card__julgados">';
			foreach ( array_slice( $filhos, 0, 4 ) as $f ) {
				$html .= '<li><a href="' . esc_url( get_permalink( $f ) ) . '">' . esc_html( get_the_title( $f ) ) . '</a></li>';
			}
			$html .= '</ul>';
			if ( count( $filhos ) > 4 ) {
				$html .= '<p class="tpi-card__mais"><a href="' . esc_url( get_permalink( $p ) ) . '">Ver os ' . count( $filhos ) . ' julgados da edição</a></p>';
			}
		}
		$html .= '</li>';
	}
	return $html . '</ol>';
} );

/* ---------------------------------------------------------------------------
 * 5. Dados estruturados (Article + BreadcrumbList + ItemList)
 *    Desative com add_filter('tpi_schema_ativo','__return_false') se o plugin de SEO
 *    já gerar Article para este tipo de conteúdo.
 * ------------------------------------------------------------------------- */
add_action( 'wp_head', 'tpi_schema', 30 );
function tpi_schema() {
	if ( ! is_singular( 'informativo' ) || ! apply_filters( 'tpi_schema_ativo', true ) ) {
		return;
	}
	$id     = get_queried_object_id();
	$post   = get_post( $id );
	$autor  = tpi_schema_autor( (int) $post->post_author );
	$graph  = array();
	$tipo   = tpi_meta( $id, 'tp_tipo' );

	$article = array(
		'@type'            => 'Article',
		'@id'              => get_permalink( $id ) . '#article',
		'headline'         => wp_strip_all_tags( get_the_title( $id ) ),
		'datePublished'    => get_post_time( 'c', true, $id ),
		'dateModified'     => get_post_modified_time( 'c', true, $id ),
		'author'           => array( '@id' => $autor['@id'] ),
		'publisher'        => array( '@id' => tpi_schema_org_id() ),
		'mainEntityOfPage' => get_permalink( $id ),
		'inLanguage'       => 'pt-BR',
		'isAccessibleForFree' => true,
	);
	if ( has_post_thumbnail( $id ) ) {
		$article['image'] = get_the_post_thumbnail_url( $id, 'full' );
	}
	if ( tpi_meta( $id, 'tp_fonte_url' ) ) {
		$article['isBasedOn'] = tpi_meta( $id, 'tp_fonte_url' );
	}
	// reviewedBy e lastReviewed pertencem a WebPage no schema.org.
	$webpage = array(
		'@type' => 'WebPage',
		'@id'   => get_permalink( $id ),
		'url'   => get_permalink( $id ),
		'name'  => wp_strip_all_tags( get_the_title( $id ) ),
	);
	if ( tpi_meta( $id, 'tp_revisado_em' ) ) {
		$webpage['reviewedBy']   = array( '@id' => $autor['@id'] );
		$webpage['lastReviewed'] = tpi_meta( $id, 'tp_revisado_em' );
	}
	$article['isPartOf'] = array( '@id' => get_permalink( $id ) );
	$graph[]             = $webpage;
	$graph[]             = $article;
	$graph[]             = tpi_schema_org();
	$graph[]             = $autor;

	// Breadcrumb: Informativos > ancestrais > atual
	$itens   = array( array( 'name' => 'Informativos', 'url' => home_url( '/informativos/' ) ) );
	foreach ( array_reverse( get_post_ancestors( $id ) ) as $anc ) {
		$itens[] = array( 'name' => get_the_title( $anc ), 'url' => get_permalink( $anc ) );
	}
	$itens[] = array( 'name' => get_the_title( $id ), 'url' => get_permalink( $id ) );
	$bc      = array( '@type' => 'BreadcrumbList', 'itemListElement' => array() );
	foreach ( $itens as $i => $it ) {
		$bc['itemListElement'][] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => wp_strip_all_tags( $it['name'] ), 'item' => $it['url'] );
	}
	$graph[] = $bc;

	if ( 'periodico' === $tipo ) {
		$filhos = get_posts( array( 'post_type' => 'informativo', 'post_parent' => $id, 'numberposts' => 100, 'orderby' => 'menu_order date', 'order' => 'ASC' ) );
		if ( $filhos ) {
			$lista = array( '@type' => 'ItemList', 'itemListElement' => array() );
			foreach ( $filhos as $i => $f ) {
				$lista['itemListElement'][] = array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => get_permalink( $f ) );
			}
			$graph[] = $lista;
		}
	}

	echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => $graph ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}

/* ---------------------------------------------------------------------------
 * 6. Assets (CSS leve + JS só para usuário logado em julgado/periódico)
 * ------------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {
	$conteudo = is_singular() ? (string) get_post_field( 'post_content', get_queried_object_id() ) : '';
	if ( ! is_singular( 'informativo' ) && ! is_tax( 'ramo_direito' ) && ! has_shortcode( $conteudo, 'tp_calendario' ) && ! has_shortcode( $conteudo, 'tp_ultimos' ) && ! has_shortcode( $conteudo, 'tp_proximo' ) && ! has_shortcode( $conteudo, 'tp_buscador' ) && ! has_shortcode( $conteudo, 'tp_edicoes' ) && ! has_shortcode( $conteudo, 'tp_newsletter' ) ) {
		return;
	}
	wp_enqueue_style( 'tp-informativos', TPI_URL . 'assets/tpi.css', array(), TPI_VERSION );
	$cfg = tpi_supabase();
	wp_enqueue_script( 'tp-informativos', TPI_URL . 'assets/tpi.js', array(), TPI_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script( 'tp-informativos', 'TPI', array( 'url' => untrailingslashit( $cfg['url'] ), 'key' => $cfg['key'], 'appUrl' => $cfg['appUrl'], 'google' => $cfg['google'] ?? '', 'signup' => ! empty( $cfg['signup'] ), 'rest' => esc_url_raw( rest_url() ) ) );
} );


/* 0.12.0: lei em janela. Cada "art. 298, III, do CTB" do julgado, da edição ou do caderno vira um botão que abre o
   artigo do acervo da Lei seca, só para leitura. O script e as leis são do plugin do app (tchau-pobreza/app/embed/leis.js
   e app/legal/*.json, estáticos, sem Supabase), com o mesmo parser de citações do app. O HTML não muda (cache e SEO
   intactos); o script é adiado e só baixa a lei no clique. Sem o plugin do app, nada acontece. */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( array( 'informativo', 'caderno' ) ) || ! defined( 'TP_VERSION' ) || ! file_exists( WP_PLUGIN_DIR . '/tchau-pobreza/app/embed/leis.js' ) ) {
		return;
	}
	wp_enqueue_script( 'tp-leis', plugins_url( 'tchau-pobreza/app/embed/leis.js' ), array(), TP_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_add_inline_script( 'tp-leis', 'window.TP_LEIS=' . wp_json_encode( array( 'app' => home_url( '/?tp_app=1' ), 'scope' => '.entry-content' ) ) . ';', 'before' );
}, 20 );

/* ---------------------------------------------------------------------------
 * 7. Flashcards para o Anki
 *    GET /wp-json/tp/v1/anki/<id> devolve um .txt (separado por tabulação) que o Anki importa
 *    direto (Arquivo > Importar). No periódico, junta os cartões de todos os julgados.
 * ------------------------------------------------------------------------- */
function tpi_cards_de( $id ) {
	$c = json_decode( (string) tpi_meta( $id, 'tp_flashcards' ), true );
	return is_array( $c ) ? $c : array();
}
/* 0.9.0: cartões prontos para gravar direto nos Flashcards do app (mesmo texto do .txt do Anki).
   - id fixo por julgado + frente (formato card:<uuid> que o app exige): importar de novo não duplica
     e nunca zera o progresso (o JS grava com ON CONFLICT DO NOTHING).
   - Baralho "<Ramo>::Informativos": o Estudo inteligente puxa card sem tema pela disciplina do 1º nível do
     baralho. A edição fica na etiqueta (stf_inf1236), igual ao .txt. Sem ramo, vai para "Informativos". */
function tpi_posts_de_cards( $id ) {
	return 'periodico' === tpi_meta( $id, 'tp_tipo' )
		? get_posts( array( 'post_type' => 'informativo', 'post_parent' => $id, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order date', 'order' => 'ASC' ) )
		: array( get_post( $id ) );
}
function tpi_limpa_card( $s ) {
	$s = preg_replace( '/\*\*?(.+?)\*\*?/u', '$1', (string) $s );
	return trim( preg_replace( '/[\t\r\n]+/', ' ', $s ) );
}
/* 0.16.11: o verso ganha "(STJ, Inf. 909)" para o card dizer de onde veio no Anki/app, mas só se o texto ainda
   não citar o informativo (vários já terminam com "(STJ, AgRg no HC ..., Inf. 909)" e a fonte saía em dobro). */
function tpi_verso_com_fonte( $verso, $trib, $num ) {
	if ( '' !== (string) $num && preg_match( '/\bInf(?:ormativo|\.)?\s*' . preg_quote( (string) $num, '/' ) . '\b/iu', $verso ) ) {
		return $verso;
	}
	return $verso . ' (' . $trib . ', Inf. ' . $num . ')';
}
function tpi_uuid_card( $post_id, $frente ) {
	$h = sha1( 'tpi-card:' . $post_id . ':' . $frente );
	$h = substr( $h, 0, 12 ) . '5' . substr( $h, 13, 3 ) . dechex( 8 | ( hexdec( $h[16] ) & 3 ) ) . substr( $h, 17, 15 );
	return substr( $h, 0, 8 ) . '-' . substr( $h, 8, 4 ) . '-' . substr( $h, 12, 4 ) . '-' . substr( $h, 16, 4 ) . '-' . substr( $h, 20, 12 );
}
function tpi_cards_app( $id ) {
	$trib = tpi_meta( $id, 'tp_tribunal' );
	$num  = tpi_meta( $id, 'tp_numero' );
	$tag  = strtolower( $trib ) . '_inf' . $num;
	$out  = array();
	foreach ( tpi_posts_de_cards( $id ) as $p ) {
		if ( ! $p ) {
			continue;
		}
		$ramo = function_exists( 'tpi_ramo_principal' ) ? tpi_ramo_principal( $p ) : null;
		$deck = ( $ramo && ! is_wp_error( $ramo ) ? $ramo->name . '::' : '' ) . 'Informativos';
		foreach ( tpi_cards_de( $p->ID ) as $c ) {
			$frente = tpi_limpa_card( $c['frente'] ?? '' );
			$verso  = tpi_limpa_card( $c['verso'] ?? '' );
			if ( '' === $frente || '' === $verso ) {
				continue;
			}
			$out[] = array(
				'id'     => 'card:' . tpi_uuid_card( $p->ID, $frente ),
				'front'  => $frente,
				'back'   => tpi_verso_com_fonte( $verso, $trib, $num ),
				'deck'   => $deck,
				'tags'   => array( $tag ),
				'source' => array( 'kind' => 'informativo', 'guid' => 'tpi:' . $p->ID, 'title' => get_the_title( $p ), 'label' => $trib . ', Inf. ' . $num, 'url' => get_permalink( $p ) ),
			);
		}
	}
	return $out;
}
function tpi_botao_app( $id, $edicao = false ) {
	$cards = tpi_cards_app( $id );
	$n     = count( $cards );
	if ( ! $n ) {
		return '';
	}
	$ids = implode( ',', wp_list_pluck( $cards, 'id' ) );
	$txt = 'Adicionar ' . ( 1 === $n ? 'este flashcard' : 'estes ' . $n . ' flashcards' ) . ' ao app';
	return '<div class="tpi-app" data-tpi-cards="' . esc_url( rest_url( 'tp/v1/flashcards/' . $id ) ) . '" data-tpi-ids="' . esc_attr( $ids ) . '" data-tpi-n="' . $n . '" hidden>'
		. '<button type="button" class="tpi-btn tpi-app__botao">' . esc_html( $txt ) . '</button>'
		. '<p class="tpi-app__dica">Eles entram nos seus Flashcards do Tchau Pobreza e voltam na hora certa, sozinhos' . ( $edicao ? ', separados por disciplina' : '' ) . '.</p>'
		. '<p class="tpi-app__estado" role="status"></p></div>';
}
/* 0.16.10: no julgado, data-tpi-sel liga a escolha card a card (o JS põe uma caixinha em cada .tpi-flashcard,
   todas marcadas). O link continua baixando tudo sem JavaScript; com escolha, ganha ?c=<índices> e a mesma
   seleção vale para o "Adicionar ao app". data-tpi-sel = nº de cards; se não bater com os da página, o JS desiste. */
function tpi_botao_anki( $id, $edicao = false ) {
	// 0.16.12: sem flashcards (ex.: pré-página de edição prevista), sem botão; antes baixava um .txt vazio.
	if ( ! tpi_cards_app( $id ) ) {
		return '';
	}
	$url = rest_url( 'tp/v1/anki/' . $id );
	$txt = $edicao ? 'Baixar os flashcards da edição para o Anki' : 'Baixar estes flashcards para o Anki';
	$n   = $edicao ? 0 : count( tpi_cards_de( $id ) );
	$sel = $n > 1 ? ' data-tpi-sel="' . $n . '"' : '';
	return tpi_botao_app( $id, $edicao ) . '<p class="tpi-anki"><a class="tpi-btn tpi-btn--anki" href="' . esc_url( $url ) . '" download rel="nofollow"' . $sel . '>' . esc_html( $txt ) . '</a><span class="tpi-anki__dica">Arquivo .txt. No Anki, use Arquivo e depois Importar.</span></p>';
}
add_shortcode( 'tp_anki', function () {
	return is_singular( 'informativo' ) ? tpi_botao_anki( get_queried_object_id() ) : '';
} );
add_action( 'rest_api_init', function () {
	register_rest_route(
		'tp/v1',
		'/flashcards/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $r ) {
				$id = (int) $r['id'];
				if ( 'informativo' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
					return new WP_Error( 'tpi_invalido', 'Conteúdo não encontrado.', array( 'status' => 404 ) );
				}
				$res = new WP_REST_Response( array( 'cards' => tpi_cards_app( $id ) ) );
				$res->header( 'Cache-Control', 'public, max-age=600' );
				$res->header( 'X-Robots-Tag', 'noindex' );
				return $res;
			},
		)
	);
} );
add_action( 'rest_api_init', function () {
	register_rest_route(
		'tp/v1',
		'/anki/(?P<id>\d+)',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $r ) {
				$id = (int) $r['id'];
				if ( 'informativo' !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) ) {
					return new WP_Error( 'tpi_invalido', 'Conteúdo não encontrado.', array( 'status' => 404 ) );
				}
				$trib  = tpi_meta( $id, 'tp_tribunal' );
				$num   = tpi_meta( $id, 'tp_numero' );
				$posts = 'periodico' === tpi_meta( $id, 'tp_tipo' )
					? get_posts( array( 'post_type' => 'informativo', 'post_parent' => $id, 'numberposts' => 100, 'orderby' => 'menu_order date', 'order' => 'ASC' ) )
					: array( get_post( $id ) );
				$limpa = function ( $s ) {
					$s = preg_replace( '/\*\*?(.+?)\*\*?/u', '$1', (string) $s );
					return trim( preg_replace( '/[\t\r\n]+/', ' ', $s ) );
				};
				$linhas = array( '#separator:tab', '#html:false', '#deck:Tchau Pobreza::Informativos ' . $trib . '::' . $trib . ' ' . $num, '#tags column:3' );
				// 0.16.10: ?c=0,2,3 leva só os cards escolhidos (índices em tp_flashcards; vale para o julgado).
				$so = null;
				if ( 'periodico' !== tpi_meta( $id, 'tp_tipo' ) && preg_match( '/^\d+(,\d+)*$/', (string) $r['c'] ) ) {
					$so = array_flip( array_map( 'intval', explode( ',', $r['c'] ) ) );
				}
				foreach ( $posts as $p ) {
					$tag = strtolower( $trib ) . '_inf' . $num;
					foreach ( tpi_cards_de( $p->ID ) as $i => $c ) {
						if ( null !== $so && ! isset( $so[ $i ] ) ) {
							continue;
						}
						$verso    = tpi_verso_com_fonte( $limpa( $c['verso'] ?? '' ), $trib, $num );
						$linhas[] = $limpa( $c['frente'] ?? '' ) . "\t" . $verso . "\t" . $tag;
					}
				}
				$nome = sanitize_file_name( 'flashcards-' . strtolower( $trib ) . '-' . $num . ( 'periodico' === tpi_meta( $id, 'tp_tipo' ) ? '' : '-' . get_post_field( 'post_name', $id ) ) . '.txt' );
				header( 'Content-Type: text/plain; charset=utf-8' );
				header( 'Content-Disposition: attachment; filename="' . $nome . '"' );
				header( 'X-Robots-Tag: noindex' );
				echo implode( "\n", $linhas ) . "\n";
				exit;
			},
		)
	);
} );

/* ---------------------------------------------------------------------------
 * 8. Buscador de jurisprudência comentada: [tp_buscador]
 *    Formulário GET (funciona sem JavaScript e é acessível). Busca nos julgados comentados por texto
 *    (título, resumo, conteúdo, destaque) e por número de processo, com filtros de tribunal, ramo e
 *    informativo. Sem busca, mostra a vitrine do hub: próximas edições, última edição, ramos e calendário.
 * ------------------------------------------------------------------------- */
function tpi_busca_params() {
	$g = function ( $k ) {
		return isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	};
	return array(
		'q'    => mb_substr( $g( 'q' ), 0, 120 ),
		'trib' => in_array( strtoupper( $g( 'tribunal' ) ), array( 'STJ', 'STF' ), true ) ? strtoupper( $g( 'tribunal' ) ) : '',
		'ramo' => sanitize_title( $g( 'ramo' ) ),
		'inf'  => absint( $g( 'informativo' ) ),
		'pag'  => max( 1, absint( $g( 'pagina' ) ) ),
	);
}
function tpi_busca_ativa() {
	$b = tpi_busca_params();
	return '' !== $b['q'] || $b['trib'] || $b['ramo'] || $b['inf'];
}

/* Resultados de busca interna não devem ser indexados (orientação do Google); o hub sem parâmetros continua indexável. */
add_filter( 'rank_math/frontend/robots', function ( $r ) {
	if ( is_page( 'informativos' ) && tpi_busca_ativa() ) {
		$r['index']  = 'noindex';
		$r['follow'] = 'follow';
	}
	return $r;
} );
add_filter( 'wp_robots', function ( $r ) {
	if ( is_page( 'informativos' ) && tpi_busca_ativa() ) {
		unset( $r['index'] );
		$r['noindex'] = true;
		$r['follow']  = true;
	}
	return $r;
} );

function tpi_busca_ids( $b ) {
	global $wpdb;
	$meta = array( array( 'key' => 'tp_tipo', 'value' => 'julgado' ) );
	if ( $b['trib'] ) {
		$meta[] = array( 'key' => 'tp_tribunal', 'value' => $b['trib'] );
	}
	if ( $b['inf'] ) {
		$meta[] = array( 'key' => 'tp_numero', 'value' => $b['inf'] );
	}
	$base = array( 'post_type' => 'informativo', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 500, 'meta_query' => $meta, 'no_found_rows' => true );
	if ( $b['ramo'] ) {
		$base['tax_query'] = array( array( 'taxonomy' => 'ramo_direito', 'field' => 'slug', 'terms' => $b['ramo'] ) );
	}
	if ( '' === $b['q'] ) {
		return get_posts( $base + array( 'orderby' => 'date', 'order' => 'DESC' ) );
	}
	$por_texto = get_posts( $base + array( 's' => $b['q'], 'orderby' => 'relevance' ) );
	// Número de processo, súmula ou trecho do destaque: busca nos campos do julgado.
	$like     = '%' . $wpdb->esc_like( $b['q'] ) . '%';
	$digitos  = preg_replace( '/\D/', '', $b['q'] );
	$sql      = "SELECT post_id FROM {$wpdb->postmeta} WHERE (meta_key IN ('tp_processo','tp_destaque') AND meta_value LIKE %s)";
	$args     = array( $like );
	if ( strlen( $digitos ) >= 4 ) {
		$sql   .= " OR (meta_key = 'tp_processo' AND REPLACE(REPLACE(REPLACE(meta_value,'.',''),'-',''),' ','') LIKE %s)";
		$args[] = '%' . $wpdb->esc_like( $digitos ) . '%';
	}
	$por_meta = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $sql, $args ) ) ); // phpcs:ignore WordPress.DB
	$filtrados = $por_meta ? get_posts( $base + array( 'post__in' => $por_meta, 'orderby' => 'date' ) ) : array();
	return array_values( array_unique( array_merge( $filtrados, $por_texto ) ) );
}

function tpi_card_julgado( $f ) {
	$rp    = tpi_ramo_principal( $f );
	$ramo  = $rp ? $rp->name : '';
	if ( $rp && is_tax( 'ramo_direito' ) ) { // na página do ramo, o ramo é óbvio: mostra o tópico.
		$ramo = implode( ', ', wp_list_pluck( tpi_topicos_de( $f, $rp->term_id ), 'name' ) );
	}
	$trib  = tpi_meta( $f->ID, 'tp_tribunal' );
	$html  = '<li class="tpi-card tpi-resultado" data-tpi-id="' . esc_attr( $f->ID ) . '"><p class="tpi-card__ramo"><span class="tpi-trib tpi-trib--' . esc_attr( strtolower( $trib ) ) . '">' . esc_html( $trib ) . '</span> Informativo ' . esc_html( tpi_meta( $f->ID, 'tp_numero' ) ) . ( $ramo ? '<span class="tpi-card__sep" aria-hidden="true"></span>' . esc_html( $ramo ) : '' ) . '</p>';
	$html .= '<h3 class="tpi-card__titulo"><a href="' . esc_url( get_permalink( $f ) ) . '">' . esc_html( get_the_title( $f ) ) . '</a></h3>';
	if ( $f->post_excerpt ) {
		$html .= '<p class="tpi-card__resumo">' . esc_html( wp_trim_words( $f->post_excerpt, 38 ) ) . '</p>';
	}
	$proc = tpi_meta( $f->ID, 'tp_processo' );
	if ( $proc ) {
		$html .= '<p class="tpi-card__proc">' . esc_html( $proc . ( tpi_meta( $f->ID, 'tp_orgao' ) ? ', ' . tpi_meta( $f->ID, 'tp_orgao' ) : '' ) ) . '</p>';
	}
	return $html . '</li>';
}

add_shortcode( 'tp_buscador', function () {
	$b     = tpi_busca_params();
	$acao  = get_permalink();
	$ramos = tpi_ramos_principais();

	$opt_ramo = '<option value="">Todos os ramos</option>';
	foreach ( $ramos as $r ) {
		$opt_ramo .= '<option value="' . esc_attr( $r->slug ) . '"' . selected( $b['ramo'], $r->slug, false ) . '>' . esc_html( $r->name ) . '</option>';
	}
	$opt_trib = '<option value="">STJ e STF</option>';
	foreach ( array( 'STJ', 'STF' ) as $tr ) {
		$opt_trib .= '<option value="' . $tr . '"' . selected( $b['trib'], $tr, false ) . '>' . $tr . '</option>';
	}

	$html  = '<section class="tpi-busca" role="search" aria-labelledby="tpi-busca-titulo"><h2 id="tpi-busca-titulo" class="tpi-busca__titulo">Buscar julgados comentados</h2>';
	$html .= '<form class="tpi-busca__form" method="get" action="' . esc_url( $acao ) . '">';
	$html .= '<div class="tpi-busca__linha"><label class="tpi-sr" for="tpi-q">Tema, palavra-chave ou número do processo</label><input id="tpi-q" type="search" name="q" value="' . esc_attr( $b['q'] ) . '" placeholder="Ex.: dano moral coletivo, IPTU, REsp 2.294.977" autocomplete="off"><button type="submit" class="tpi-btn">Buscar</button></div>';
	$html .= '<div class="tpi-busca__filtros"><label>Tribunal<select name="tribunal">' . $opt_trib . '</select></label><label>Ramo do Direito<select name="ramo">' . $opt_ramo . '</select></label><label>Informativo nº<input type="number" name="informativo" inputmode="numeric" min="1" value="' . ( $b['inf'] ? esc_attr( $b['inf'] ) : '' ) . '" placeholder="Ex.: 908"></label></div>';
	$html .= '</form></section>';

	if ( tpi_busca_ativa() ) {
		$ids   = tpi_busca_ids( $b );
		$total = count( $ids );
		$porpg = 12;
		$pags  = max( 1, (int) ceil( $total / $porpg ) );
		$pag   = min( $b['pag'], $pags );
		$fatia = array_slice( $ids, ( $pag - 1 ) * $porpg, $porpg );
		$html .= '<section class="tpi-resultados" aria-live="polite" aria-labelledby="tpi-res-titulo"><h2 id="tpi-res-titulo" class="tpi-resultados__titulo">' . ( $total ? sprintf( _n( '%d julgado encontrado', '%d julgados encontrados', $total ), $total ) : 'Nenhum julgado encontrado' ) . '</h2>';
		if ( $total ) {
			$html .= '<ol class="tpi-cards tpi-cards--julgados">';
			foreach ( $fatia as $id ) {
				$html .= tpi_card_julgado( get_post( $id ) );
			}
			$html .= '</ol>';
			if ( $pags > 1 ) {
				$html .= '<nav class="tpi-paginas" aria-label="Páginas de resultado"><ol>';
				for ( $i = 1; $i <= $pags; $i++ ) {
					$url   = add_query_arg( array_filter( array( 'q' => $b['q'], 'tribunal' => $b['trib'], 'ramo' => $b['ramo'], 'informativo' => $b['inf'], 'pagina' => $i > 1 ? $i : null ) ), $acao );
					$html .= '<li>' . ( $i === $pag ? '<span aria-current="page">' . $i . '</span>' : '<a href="' . esc_url( $url ) . '">' . $i . '</a>' ) . '</li>';
				}
				$html .= '</ol></nav>';
			}
		} else {
			$html .= '<p class="tpi-vazio">Tente um termo mais curto, tire um filtro ou busque pelo número do processo sem pontos. Também dá para navegar pelos ramos abaixo.</p>';
		}
		$html .= '<p class="tpi-limpar"><a href="' . esc_url( $acao ) . '">Limpar busca e voltar ao início</a></p></section>';
	}

	// Vitrine (também aparece abaixo dos resultados, mais enxuta)
	if ( ! tpi_busca_ativa() ) {
		// 0.25.1: as próximas edições viraram a 3ª coluna ao lado de STJ e STF (antes: bloco "Próximas edições" e
		// tabela do calendário no fim da página, que repetia as edições já comentadas).
		$html .= '<section class="tpi-vitrine" aria-labelledby="tpi-ed-titulo"><h2 id="tpi-ed-titulo">Informativos comentados por edição</h2>' . do_shortcode( '[tp_edicoes proximos="1"]' ) . '</section>';
	}
	if ( $ramos ) {
		$html .= '<section class="tpi-ramos" aria-labelledby="tpi-ramos-titulo"><h2 id="tpi-ramos-titulo">Navegue por ramo do Direito</h2><ul>';
		foreach ( $ramos as $r ) {
			$ativo = $b['ramo'] === $r->slug;
			$html .= '<li><a href="' . esc_url( get_term_link( $r ) ) . '"' . ( $ativo ? ' aria-current="true"' : '' ) . '>' . esc_html( $r->name ) . ' <span class="tpi-ramos__n">' . (int) $r->tpi_total . '</span></a></li>';
		}
		$html .= '</ul></section>';
	}
	return '<div class="tpi-hub alignwide">' . $html . '</div>';
} );
