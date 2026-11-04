<?php
/**
 * Plugin Name: TP Informativos
 * Description: Informativos STF/STJ comentados: tipo de conteúdo hierárquico (tribunal > periódico > julgado), calendário, "marcar como lido", dados estruturados e campos para automação via REST API.
 * Version: 0.6.0
 * Requires at least: 6.3
 * Requires PHP: 7.4
 * Author: Tchau Pobreza
 * Text Domain: tp-informativos
 * Update URI: https://github.com/Fastzinho/tp-informativos
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPI_VERSION', '0.6.0' );
define( 'TPI_ARQUIVO', __FILE__ );
require_once __DIR__ . '/includes/atualizador.php';
define( 'TPI_URL', plugin_dir_url( __FILE__ ) );

/*
 * Estrutura de URLs (CPT hierárquico "informativo"):
 *   /informativos/                       -> Página comum do WP (hub geral) com [tp_ultimos] e [tp_calendario]
 *   /informativos/stj/                   -> tp_tipo = tribunal (hub do tribunal + próximo informativo)
 *   /informativos/stj/informativo-908/               -> tp_tipo = periodico
 *   /informativos/stj/informativo-908/<slug-tese>/   -> tp_tipo = julgado
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
		'tp_tipo'            => 'string',  // tribunal | periodico | julgado
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

/* Autor exibido e usado no schema (independe do nome do usuário do WP). */
function tpi_autor() {
	return apply_filters(
		'tpi_autor',
		array(
			'nome'        => 'Jonatan Ramos',
			'credenciais' => 'especialista em Direito Penal, aprovado no ENAM e na EMERJ',
			'url'         => get_author_posts_url( (int) get_post_field( 'post_author', get_queried_object_id() ) ? (int) get_post_field( 'post_author', get_queried_object_id() ) : 1 ),
		)
	);
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
	return ( is_array( $c ) && $c ) ? $c : tpi_calendario_padrao();
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

/* Página de informativo correspondente a um número, se existir. */
function tpi_periodico_por_numero( $tribunal, $numero ) {
	$q = get_posts( array( 'post_type' => 'informativo', 'numberposts' => 1, 'post_status' => 'publish', 'meta_query' => array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ), array( 'key' => 'tp_tribunal', 'value' => $tribunal ), array( 'key' => 'tp_numero', 'value' => (int) $numero ) ) ) );
	return $q ? $q[0] : null;
}

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
		$rotulo  = 'tribunal' === tpi_meta( $anc, 'tp_tipo' ) ? tpi_meta( $anc, 'tp_tribunal' ) : ( 'periodico' === tpi_meta( $anc, 'tp_tipo' ) ? 'Informativo ' . tpi_meta( $anc, 'tp_numero' ) : get_the_title( $anc ) );
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

function tpi_assinatura( $id ) {
	$a     = tpi_autor();
	$nome  = $a['url'] ? '<a href="' . esc_url( $a['url'] ) . '">' . esc_html( $a['nome'] ) . '</a>' : esc_html( $a['nome'] );
	$data  = get_the_date( 'd/m/Y', $id );
	$rev   = tpi_meta( $id, 'tp_revisado_em' );
	$html  = '<div class="tpi-assinatura"><p class="tpi-assinatura__autor">Comentado por <strong>' . $nome . '</strong>, ' . esc_html( $a['credenciais'] ) . '.</p>';
	$html .= '<p class="tpi-assinatura__datas">Publicado em <time datetime="' . esc_attr( get_the_date( 'c', $id ) ) . '">' . esc_html( $data ) . '</time>';
	if ( $rev ) {
		$html .= '. Revisado em <time datetime="' . esc_attr( $rev ) . '">' . esc_html( tpi_data_br( $rev ) ) . '</time>';
	}
	return $html . '.</p></div>';
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
	$trib = tpi_meta( $id, 'tp_tribunal' );
	$num  = (int) tpi_meta( $id, 'tp_numero' );
	$base = array( 'post_type' => 'informativo', 'numberposts' => 1, 'post_status' => 'publish', 'meta_key' => 'tp_numero', 'orderby' => 'meta_value_num' );
	$mq   = function ( $cmp ) use ( $trib, $num ) {
		return array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ), array( 'key' => 'tp_tribunal', 'value' => $trib ), array( 'key' => 'tp_numero', 'value' => $num, 'compare' => $cmp, 'type' => 'NUMERIC' ) );
	};
	$ant = get_posts( $base + array( 'order' => 'DESC', 'meta_query' => $mq( '<' ) ) );
	$pro = get_posts( $base + array( 'order' => 'ASC', 'meta_query' => $mq( '>' ) ) );
	return array( $ant ? $ant[0]->ID : 0, $pro ? $pro[0]->ID : 0 );
}

function tpi_nav_vizinhos( $ant, $pro, $rotulo, $tipo ) {
	if ( ! $ant && ! $pro ) {
		return '';
	}
	$item = function ( $pid, $classe, $dir ) use ( $tipo ) {
		if ( ! $pid ) {
			return '<span class="tpi-vizinhos__vazio" aria-hidden="true"></span>';
		}
		$nome = 'edicao' === $tipo ? 'Informativo ' . tpi_meta( $pid, 'tp_numero' ) . ' do ' . tpi_meta( $pid, 'tp_tribunal' ) : get_the_title( $pid );
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
	$assunto = rawurlencode( 'Erro no comentário: ' . get_the_title( $id ) );
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

	if ( in_array( $tipo, array( 'periodico', 'julgado' ), true ) ) {
		$pre .= tpi_assinatura( $id );
	}

	if ( 'julgado' === $tipo ) {
		$pre     .= tpi_botao_lido( $id, true );
		$pre     .= tpi_ficha_julgado( $id );
		$destaque = tpi_meta( $id, 'tp_destaque' );
		if ( $destaque ) {
			$pre .= '<figure class="tpi-destaque"><figcaption>Destaque oficial do ' . esc_html( tpi_meta( $id, 'tp_tribunal' ) ) . '</figcaption><blockquote><p>' . wp_kses_post( $destaque ) . '</p></blockquote></figure>';
		}
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

	if ( in_array( $tipo, array( 'periodico', 'julgado' ), true ) ) {
		$pos .= tpi_newsletter_cta();
	}

	return $pre . $content . $pos;
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
		$ramos = get_the_terms( $f, 'ramo_direito' );
		$ramo  = ( $ramos && ! is_wp_error( $ramos ) ) ? $ramos[0]->name : 'Outros temas';
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
			$html .= '<li class="tpi-card" data-tpi-id="' . esc_attr( $f->ID ) . '">';
			$proc  = tpi_meta( $f->ID, 'tp_processo' );
			if ( $proc ) {
				$html .= '<p class="tpi-card__ramo">' . esc_html( $proc . ( tpi_meta( $f->ID, 'tp_orgao' ) ? ' · ' . tpi_meta( $f->ID, 'tp_orgao' ) : '' ) ) . '</p>';
			}
			$html .= '<h4 class="tpi-card__titulo"><a href="' . esc_url( get_permalink( $f ) ) . '">' . esc_html( get_the_title( $f ) ) . '</a></h4>';
			$resumo = $f->post_excerpt ? $f->post_excerpt : tpi_meta( $f->ID, 'tp_destaque' );
			if ( $resumo ) {
				$html .= '<p class="tpi-card__resumo">' . esc_html( wp_trim_words( $resumo, 40 ) ) . '</p>';
			}
			$html .= '</li>';
		}
		$html .= '</ol>';
	}
	return $html . '</section>';
}

function tpi_newsletter_cta() {
	$url = apply_filters( 'tpi_newsletter_url', home_url( '/newsletter/' ) );
	return '<section class="tpi-newsletter" aria-labelledby="tpi-nl-titulo"><h2 id="tpi-nl-titulo">Receba o próximo informativo comentado</h2><p>Um e-mail por semana, às quartas, com os informativos do STJ e do STF comentados: a tese, como ela cai em prova e flashcards prontos para revisar.</p><p><a class="tpi-btn" href="' . esc_url( $url ) . '">Quero receber</a></p></section>';
}

/* ---------------------------------------------------------------------------
 * 3. Marcar como lido: conta do app (Supabase), a mesma de ?tp_app=1  (0.6)
 *    Grava em public.tp_records (kind 'informativo', id 'informativo:<post_id>'), protegido pela RLS
 *    tp_records_own. O HTML sai igual para todos (cache do LiteSpeed ok); o estado é aplicado em tpi.js.
 * ------------------------------------------------------------------------- */
function tpi_lidos_usuario() {
	return array(); // compatibilidade: o estado não é mais conhecido no servidor
}

function tpi_supabase() {
	return apply_filters(
		'tpi_supabase',
		array(
			'url'    => 'https://jzzfbusaarewckpjifpf.supabase.co',
			'key'    => 'sb_publishable_Dxoblx3dXzg9WGxmLHF7iQ_GuBTq-ej', // chave pública (a mesma que o app publica na página)
			'appUrl' => home_url( '/?tp_app=1' ),
		)
	);
}

function tpi_botao_lido( $id, $compacto = false ) {
	$classe = 'tpi-lido' . ( $compacto ? ' tpi-lido--topo' : '' );
	$campo  = 'tpi-lido-' . $id . ( $compacto ? '-topo' : '' );
	$dados  = ' data-tpi-id="' . esc_attr( $id ) . '" data-tpi-titulo="' . esc_attr( get_the_title( $id ) ) . '" data-tpi-url="' . esc_url( get_permalink( $id ) ) . '" data-tpi-trib="' . esc_attr( tpi_meta( $id, 'tp_tribunal' ) ) . '" data-tpi-num="' . esc_attr( tpi_meta( $id, 'tp_numero' ) ) . '"';
	// Checkbox nativo: o estado (marcado/desmarcado) é anunciado pelo leitor de tela sem depender do texto.
	$html = '<div class="' . esc_attr( $classe ) . '"' . $dados . ' hidden>'
		. '<input type="checkbox" class="tpi-lido__campo" id="' . esc_attr( $campo ) . '">'
		. '<label for="' . esc_attr( $campo ) . '"><span class="tpi-lido__icone" aria-hidden="true"></span>'
		. '<span class="tpi-lido__texto"><strong class="tpi-lido__estado">Não lido</strong>'
		. '<span class="tpi-lido__acao">Marcar este julgado como lido</span></span></label>'
		. '<span class="tpi-lido__erro" role="status"></span></div>';
	if ( ! $compacto ) {
		$cfg   = tpi_supabase();
		$html .= '<p class="tpi-lido-cta" hidden><a href="' . esc_url( $cfg['appUrl'] ) . '">Entre na sua conta do Tchau Pobreza</a> para marcar este julgado como lido e acompanhar seu progresso junto com o resto dos seus estudos.</p>';
	}
	return $html;
}

/* ---------------------------------------------------------------------------
 * 4. Calendário: [tp_calendario tribunal="STJ" limite="12"]
 *    Tabela HTML (indexável e acessível), não widget em JavaScript.
 * ------------------------------------------------------------------------- */
add_shortcode( 'tp_calendario', function ( $atts ) {
	$a     = shortcode_atts( array( 'tribunal' => '', 'limite' => 8 ), $atts );
	$trib  = strtoupper( $a['tribunal'] );
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
	foreach ( get_posts( array( 'post_type' => 'informativo', 'numberposts' => 50, 'meta_query' => $meta ) ) as $p ) {
		$k   = tpi_meta( $p->ID, 'tp_tribunal' ) . '-' . (int) tpi_meta( $p->ID, 'tp_numero' );
		$prev = 'previsto' === tpi_meta( $p->ID, 'tp_status' );
		$est = isset( $itens[ $k ] ) && ! empty( $itens[ $k ]['estimada'] ) && $prev;
		$itens[ $k ] = array( 'tribunal' => tpi_meta( $p->ID, 'tp_tribunal' ), 'numero' => (int) tpi_meta( $p->ID, 'tp_numero' ), 'data' => $prev ? tpi_meta( $p->ID, 'tp_data_prevista' ) : tpi_meta( $p->ID, 'tp_data_publicacao' ), 'post' => $p, 'previsto' => $prev, 'estimada' => $est );
	}
	// Mostra a última edição comentada e as próximas.
	$itens = array_filter( $itens, function ( $i ) use ( $hoje ) { return $i['post'] || $i['data'] >= $hoje; } );
	usort( $itens, function ( $x, $y ) { return strcmp( $x['data'] . $x['tribunal'], $y['data'] . $y['tribunal'] ); } );
	$itens = array_slice( $itens, 0, (int) $a['limite'] );
	if ( ! $itens ) {
		return '';
	}
	$html = '<div class="tpi-calendario"><table><caption>Próximas datas de divulgação' . ( $trib ? ' do ' . esc_html( $trib ) : ' do STJ e do STF' ) . '</caption><thead><tr><th scope="col">Data</th><th scope="col">Edição</th><th scope="col">Situação</th></tr></thead><tbody>';
	foreach ( $itens as $i ) {
		$ts     = strtotime( $i['data'] );
		$data   = '<time datetime="' . esc_attr( $i['data'] ) . '"><span class="tpi-cal__dia">' . esc_html( wp_date( 'd', $ts ) ) . '</span> <span class="tpi-cal__mes">' . esc_html( wp_date( 'M', $ts ) ) . '</span></time>';
		$com    = $i['post'] && empty( $i['previsto'] );
		$nome   = esc_html( (string) $i['numero'] );
		$edicao = $i['post'] ? '<a href="' . esc_url( get_permalink( $i['post'] ) ) . '">Informativo ' . $nome . '</a>' : 'Informativo ' . $nome;
		if ( $com ) {
			$sit = '<span class="tpi-sit tpi-sit--ok">Comentado</span>';
		} elseif ( $i['data'] < $hoje ) {
			$sit = '<span class="tpi-sit">Divulgado</span>';
		} elseif ( ! empty( $i['estimada'] ) ) {
			$sit = '<span class="tpi-sit tpi-sit--est">Data estimada</span>';
		} else {
			$sit = '<span class="tpi-sit tpi-sit--prev">Previsto</span>';
		}
		$html .= '<tr' . ( $com ? ' class="is-comentado"' : '' ) . '><td>' . $data . '</td><td><span class="tpi-trib tpi-trib--' . esc_attr( strtolower( $i['tribunal'] ) ) . '">' . esc_html( $i['tribunal'] ) . '</span> ' . $edicao . '</td><td>' . $sit . '</td></tr>';
	}
	return $html . '</tbody></table><p class="tpi-calendario__fonte">Fontes: <a href="https://processo.stj.jus.br/docs_internet/jurisprudencia/ajuda/CalendarioIJ.pdf" rel="noopener" target="_blank">calendário oficial do STJ</a> e <a href="https://portal.stf.jus.br/textos/verTexto.asp?servico=informativoSTF&amp;pagina=Calendario_publicacoes_Informativo" rel="noopener" target="_blank">calendário do STF</a>. Quando o STF ainda não publicou a data, ela é estimada pelo ritmo semanal das edições e marcada como tal.</p></div>';
} );

/* Próximo informativo: [tp_proximo tribunal="STJ"] */
add_shortcode( 'tp_proximo', function ( $atts ) {
	$a    = shortcode_atts( array( 'tribunal' => 'STJ' ), $atts );
	$hoje = wp_date( 'Y-m-d' );
	$prox = null;
	foreach ( tpi_calendario_oficial() as $c ) {
		if ( strtoupper( $a['tribunal'] ) === $c['tribunal'] && $c['data'] > $hoje && ( ! $prox || $c['data'] < $prox['data'] ) ) {
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
	$html = '<section class="tpi-proximo" aria-labelledby="' . $hid . '"><p class="tpi-proximo__quando">' . ( 1 === $dias ? 'Amanhã' : 'Em ' . $dias . ' dias' ) . '</p>';
	$html .= '<h2 id="' . $hid . '" class="tpi-proximo__titulo">' . ( $post ? '<a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( $nome ) . '</a>' : esc_html( $nome ) ) . '</h2>';
	$html .= '<p class="tpi-proximo__data">' . ( empty( $prox['estimada'] )
		? 'Divulgação prevista para ' . esc_html( tpi_data_extenso( $prox['data'] ) ) . ', segundo o calendário oficial do tribunal.'
		: 'Data estimada: ' . esc_html( tpi_data_extenso( $prox['data'] ) ) . '. O tribunal ainda não publicou o calendário desta edição.' ) . ' Os julgados entram comentados aqui no dia da divulgação.</p></section>';
	return $html;
} );

/* Edições por tribunal e ano: [tp_edicoes anos="2"]
   0.4: colunas STJ | STF, cada edição com data, nº de julgados e progresso de leitura (logado). */
add_shortcode( 'tp_edicoes', function ( $atts ) {
	$a     = shortcode_atts( array( 'anos' => 2 ), $atts );
	$html  = '<div class="tpi-edicoes">';
	foreach ( array( 'STJ', 'STF' ) as $trib ) {
		$q = get_posts( array( 'post_type' => 'informativo', 'post_status' => 'publish', 'numberposts' => 200, 'meta_key' => 'tp_numero', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_query' => array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ), array( 'key' => 'tp_tribunal', 'value' => $trib ) ) ) );
		$hid   = 'tpi-ed-' . strtolower( $trib );
		$html .= '<section class="tpi-edicoes__col" aria-labelledby="' . $hid . '"><h3 id="' . $hid . '" class="tpi-edicoes__trib"><span class="tpi-trib tpi-trib--' . strtolower( $trib ) . '">' . $trib . '</span> ' . ( 'STJ' === $trib ? 'Superior Tribunal de Justiça' : 'Supremo Tribunal Federal' ) . '</h3>';
		if ( ! $q ) {
			$html .= '<p class="tpi-vazio">As primeiras edições comentadas do ' . $trib . ' entram aqui. Veja as datas no calendário abaixo.</p></section>';
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
	return $html . '</div>';
} );

/* Últimos periódicos: [tp_ultimos tribunal="" limite="6"] */
add_shortcode( 'tp_ultimos', function ( $atts ) {
	$a    = shortcode_atts( array( 'tribunal' => '', 'limite' => 6 ), $atts );
	$meta = array( array( 'key' => 'tp_tipo', 'value' => 'periodico' ) );
	if ( $a['tribunal'] ) {
		$meta[] = array( 'key' => 'tp_tribunal', 'value' => strtoupper( $a['tribunal'] ) );
	}
	$q = get_posts( array( 'post_type' => 'informativo', 'numberposts' => (int) $a['limite'], 'meta_query' => $meta, 'meta_key' => 'tp_numero', 'orderby' => 'meta_value_num', 'order' => 'DESC' ) );
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
	$autor  = apply_filters(
		'tpi_autor_schema',
		array(
			'@type'    => 'Person',
			'name'     => get_the_author_meta( 'display_name', $post->post_author ),
			'url'      => get_author_posts_url( $post->post_author ),
		)
	);
	$graph  = array();
	$tipo   = tpi_meta( $id, 'tp_tipo' );

	$article = array(
		'@type'            => 'Article',
		'@id'              => get_permalink( $id ) . '#article',
		'headline'         => wp_strip_all_tags( get_the_title( $id ) ),
		'datePublished'    => get_post_time( 'c', true, $id ),
		'dateModified'     => get_post_modified_time( 'c', true, $id ),
		'author'           => $autor,
		'publisher'        => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
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
		$webpage['reviewedBy']   = $autor;
		$webpage['lastReviewed'] = tpi_meta( $id, 'tp_revisado_em' );
	}
	$article['isPartOf'] = array( '@id' => get_permalink( $id ) );
	$graph[]             = $webpage;
	$graph[]             = $article;

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
	if ( ! is_singular( 'informativo' ) && ! has_shortcode( $conteudo, 'tp_calendario' ) && ! has_shortcode( $conteudo, 'tp_ultimos' ) && ! has_shortcode( $conteudo, 'tp_proximo' ) && ! has_shortcode( $conteudo, 'tp_buscador' ) && ! has_shortcode( $conteudo, 'tp_edicoes' ) ) {
		return;
	}
	wp_enqueue_style( 'tp-informativos', TPI_URL . 'assets/tpi.css', array(), TPI_VERSION );
	$cfg = tpi_supabase();
	wp_enqueue_script( 'tp-informativos', TPI_URL . 'assets/tpi.js', array(), TPI_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script( 'tp-informativos', 'TPI', array( 'url' => untrailingslashit( $cfg['url'] ), 'key' => $cfg['key'], 'appUrl' => $cfg['appUrl'] ) );
} );


/* ---------------------------------------------------------------------------
 * 7. Flashcards para o Anki
 *    GET /wp-json/tp/v1/anki/<id> devolve um .txt (separado por tabulação) que o Anki importa
 *    direto (Arquivo > Importar). No periódico, junta os cartões de todos os julgados.
 * ------------------------------------------------------------------------- */
function tpi_cards_de( $id ) {
	$c = json_decode( (string) tpi_meta( $id, 'tp_flashcards' ), true );
	return is_array( $c ) ? $c : array();
}
function tpi_botao_anki( $id, $edicao = false ) {
	$url = rest_url( 'tp/v1/anki/' . $id );
	$txt = $edicao ? 'Baixar os flashcards da edição para o Anki' : 'Baixar estes flashcards para o Anki';
	return '<p class="tpi-anki"><a class="tpi-btn tpi-btn--anki" href="' . esc_url( $url ) . '" download rel="nofollow">' . esc_html( $txt ) . '</a><span class="tpi-anki__dica">Arquivo .txt. No Anki, use Arquivo e depois Importar.</span></p>';
}
add_shortcode( 'tp_anki', function () {
	return is_singular( 'informativo' ) ? tpi_botao_anki( get_queried_object_id() ) : '';
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
				foreach ( $posts as $p ) {
					$tag = strtolower( $trib ) . '_inf' . $num;
					foreach ( tpi_cards_de( $p->ID ) as $c ) {
						$verso    = $limpa( $c['verso'] ?? '' ) . ' (' . $trib . ', Inf. ' . $num . ')';
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
	$ramos = get_the_terms( $f, 'ramo_direito' );
	$ramo  = ( $ramos && ! is_wp_error( $ramos ) ) ? $ramos[0]->name : '';
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
	$ramos = get_terms( array( 'taxonomy' => 'ramo_direito', 'hide_empty' => true ) );
	$ramos = is_wp_error( $ramos ) ? array() : $ramos;

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
		$html .= '<div class="tpi-proximos">' . do_shortcode( '[tp_proximo tribunal="STJ"]' ) . do_shortcode( '[tp_proximo tribunal="STF"]' ) . '</div>';
		$html .= '<section class="tpi-vitrine" aria-labelledby="tpi-ed-titulo"><h2 id="tpi-ed-titulo">Informativos comentados por edição</h2>' . do_shortcode( '[tp_edicoes]' ) . '</section>';
	}
	if ( $ramos ) {
		$html .= '<section class="tpi-ramos" aria-labelledby="tpi-ramos-titulo"><h2 id="tpi-ramos-titulo">Navegue por ramo do Direito</h2><ul>';
		foreach ( $ramos as $r ) {
			$ativo = $b['ramo'] === $r->slug;
			$html .= '<li><a href="' . esc_url( add_query_arg( 'ramo', $r->slug, $acao ) ) . '"' . ( $ativo ? ' aria-current="true"' : '' ) . '>' . esc_html( $r->name ) . ' <span class="tpi-ramos__n">' . (int) $r->count . '</span></a></li>';
		}
		$html .= '</ul></section>';
	}
	if ( ! tpi_busca_ativa() ) {
		$html .= '<section class="tpi-vitrine" aria-labelledby="tpi-cal-titulo"><h2 id="tpi-cal-titulo">Calendário de informativos</h2>' . do_shortcode( '[tp_calendario limite="10"]' ) . '</section>';
	}
	return '<div class="tpi-hub alignwide">' . $html . '</div>';
} );
