<?php
/**
 * 0.7.19: página própria por ramo do Direito, com tópicos.
 *
 * - Endereço limpo: /informativos/ramo/<ramo>/ (antes dava 404 porque a regra do tipo "informativo", hierárquico,
 *   capturava o caminho). O hub com só ?ramo=<ramo> redireciona (301) para esse endereço.
 * - Tópico = termo-filho do ramo na taxonomia ramo_direito (ex.: Direito Tributário > ICMS). O julgado recebe o ramo
 *   e o tópico. Tópico não tem página própria: vira chip de filtro dentro da página do ramo, e só aparece quando
 *   tiver pelo menos TPI_MIN_TOPICO julgados (filtro "tpi_min_topico"). Abaixo disso, o julgado fica em "Outros julgados".
 * - Indexação: a página do ramo só é indexável com pelo menos TPI_MIN_INDEX_RAMO julgados (filtro "tpi_min_index_ramo").
 *   Antes disso funciona para quem navega, mas sai com noindex,follow (evita página rasa no Google).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function tpi_min_topico() {
	return (int) apply_filters( 'tpi_min_topico', 3 );
}
function tpi_min_index_ramo() {
	return (int) apply_filters( 'tpi_min_index_ramo', 8 );
}

/* Ramo de primeiro nível de um julgado (sobe a árvore se o julgado só tiver o tópico). */
function tpi_ramo_principal( $post ) {
	$termos = get_the_terms( $post, 'ramo_direito' );
	if ( ! $termos || is_wp_error( $termos ) ) {
		return null;
	}
	foreach ( $termos as $t ) {
		if ( 0 === (int) $t->parent ) {
			return $t;
		}
	}
	$anc = get_ancestors( $termos[0]->term_id, 'ramo_direito', 'taxonomy' );
	return $anc ? get_term( end( $anc ), 'ramo_direito' ) : $termos[0];
}

/* Tópicos (termos-filho) de um julgado dentro de um ramo. */
function tpi_topicos_de( $post, $ramo_id ) {
	$termos = get_the_terms( $post, 'ramo_direito' );
	if ( ! $termos || is_wp_error( $termos ) ) {
		return array();
	}
	return array_values( array_filter( $termos, function ( $t ) use ( $ramo_id ) {
		return (int) $t->parent && in_array( (int) $ramo_id, array_map( 'intval', get_ancestors( $t->term_id, 'ramo_direito', 'taxonomy' ) ), true );
	} ) );
}

/* Julgados publicados de um ramo (inclui os marcados só com um tópico dele). */
function tpi_julgados_do_ramo( $ramo ) {
	return get_posts(
		array(
			'post_type'      => 'informativo',
			'post_status'    => 'publish',
			'posts_per_page' => 500,
			'no_found_rows'  => true,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array( array( 'key' => 'tp_tipo', 'value' => 'julgado' ) ),
			'tax_query'      => array( array( 'taxonomy' => 'ramo_direito', 'field' => 'term_id', 'terms' => $ramo->term_id, 'include_children' => true ) ),
		)
	);
}

function tpi_total_do_ramo( $ramo ) {
	static $cache = array();
	if ( ! isset( $cache[ $ramo->term_id ] ) ) {
		$cache[ $ramo->term_id ] = count( tpi_julgados_do_ramo( $ramo ) );
	}
	return $cache[ $ramo->term_id ];
}

/* Ramos de primeiro nível com julgados, para o hub e para "outros ramos". */
function tpi_ramos_principais() {
	$ramos = get_terms( array( 'taxonomy' => 'ramo_direito', 'hide_empty' => false, 'parent' => 0 ) );
	if ( is_wp_error( $ramos ) ) {
		return array();
	}
	$saida = array();
	foreach ( $ramos as $r ) {
		$r->tpi_total = tpi_total_do_ramo( $r );
		if ( $r->tpi_total ) {
			$saida[] = $r;
		}
	}
	return $saida;
}

/* --- Endereço limpo: a regra entra no topo, antes da regra do tipo "informativo". --- */
add_action( 'init', function () {
	add_rewrite_rule( '^informativos/ramo/([^/]+)/page/([0-9]+)/?$', 'index.php?ramo_direito=$matches[1]&paged=$matches[2]', 'top' );
	add_rewrite_rule( '^informativos/ramo/([^/]+)/?$', 'index.php?ramo_direito=$matches[1]', 'top' );
}, 11 );
// Regrava as regras uma vez a cada versão (a atualização pelo GitHub não roda o gancho de ativação).
add_action( 'init', function () {
	if ( get_option( 'tpi_regras_versao' ) !== TPI_VERSION ) {
		flush_rewrite_rules( false );
		update_option( 'tpi_regras_versao', TPI_VERSION, true );
	}
}, 99 );

/* Hub com só ?ramo=x → página do ramo (301). Com outros filtros (busca, tribunal, número) continua sendo busca. */
add_action( 'template_redirect', function () {
	if ( ! is_page( 'informativos' ) || ! function_exists( 'tpi_busca_params' ) ) {
		return;
	}
	$b = tpi_busca_params();
	if ( ! $b['ramo'] || '' !== $b['q'] || $b['trib'] || $b['inf'] ) {
		return;
	}
	$t = get_term_by( 'slug', $b['ramo'], 'ramo_direito' );
	if ( $t && ! is_wp_error( $t ) ) {
		$link = get_term_link( $t );
		if ( ! is_wp_error( $link ) ) {
			wp_safe_redirect( $link, 301 );
			exit;
		}
	}
} );

/* --- Indexação condicionada ao volume --- */
function tpi_ramo_atual() {
	if ( ! is_tax( 'ramo_direito' ) ) {
		return null;
	}
	$t = get_queried_object();
	return ( $t instanceof WP_Term ) ? $t : null;
}
function tpi_ramo_indexavel( $t ) {
	return 0 === (int) $t->parent && tpi_total_do_ramo( $t ) >= tpi_min_index_ramo();
}
add_filter( 'rank_math/frontend/robots', function ( $r ) {
	$t = tpi_ramo_atual();
	if ( $t ) {
		$r['index']  = tpi_ramo_indexavel( $t ) ? 'index' : 'noindex';
		$r['follow'] = 'follow';
	}
	return $r;
} );
add_filter( 'wp_robots', function ( $r ) {
	$t = tpi_ramo_atual();
	if ( $t && ! tpi_ramo_indexavel( $t ) ) {
		unset( $r['index'] );
		$r['noindex'] = true;
		$r['follow']  = true;
	}
	return $r;
} );
// Sitemap do Rank Math: ramo sem volume (ou tópico) fica de fora.
add_filter( 'rank_math/sitemap/entry', function ( $url, $tipo, $obj ) {
	if ( 'term' === $tipo && $obj instanceof WP_Term && 'ramo_direito' === $obj->taxonomy && ! tpi_ramo_indexavel( $obj ) ) {
		return false;
	}
	return $url;
}, 10, 3 );

/* --- Modelo da página do ramo (o tema mostraria a listagem de posts genérica) --- */
add_action( 'init', function () {
	register_block_type(
		'tp-informativos/ramo',
		array(
			'api_version'     => 3,
			'title'           => 'Informativos: página do ramo',
			'category'        => 'widgets',
			'render_callback' => 'tpi_render_ramo',
			'supports'        => array( 'inserter' => false ),
		)
	);
	if ( ! function_exists( 'register_block_template' ) ) {
		return;
	}
	register_block_template(
		'tp-informativos//taxonomy-ramo_direito',
		array(
			'title'       => 'Informativos por ramo do Direito',
			'description' => 'Página de cada ramo do Direito, com os julgados comentados agrupados por tópico.',
			'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--50)">
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60)">
<!-- wp:tp-informativos/ramo /-->
</div>
<!-- /wp:group -->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
		)
	);
} );

add_filter( 'body_class', function ( $c ) {
	if ( is_tax( 'ramo_direito' ) ) {
		$c[] = 'tpi-pagina-ramo';
	}
	return $c;
} );

function tpi_render_ramo() {
	$t = tpi_ramo_atual();
	if ( ! $t ) {
		return '';
	}
	if ( $t->parent ) { // tópico não tem página própria: manda para o ramo, já no tópico.
		$pai = get_term( $t->parent, 'ramo_direito' );
		return $pai && ! is_wp_error( $pai ) ? '<p><a href="' . esc_url( get_term_link( $pai ) . '#topico-' . $t->slug ) . '">Ver ' . esc_html( $t->name ) . ' em ' . esc_html( $pai->name ) . '</a></p>' : '';
	}
	$julgados = tpi_julgados_do_ramo( $t );
	$total    = count( $julgados );
	$hub      = home_url( '/informativos/' );

	// Agrupa por tópico; tópicos com menos de N julgados vão para "Outros julgados".
	$por_topico = array();
	$nomes      = array();
	foreach ( $julgados as $j ) {
		foreach ( tpi_topicos_de( $j, $t->term_id ) as $tp ) {
			$por_topico[ $tp->slug ][] = $j;
			$nomes[ $tp->slug ]        = $tp->name;
		}
	}
	$min    = tpi_min_topico();
	$grupos = array_filter( $por_topico, function ( $l ) use ( $min ) {
		return count( $l ) >= $min;
	} );
	uksort( $grupos, function ( $a, $b ) use ( $nomes ) {
		return strcoll( $nomes[ $a ], $nomes[ $b ] );
	} );
	$nos_grupos = array();
	foreach ( $grupos as $l ) {
		foreach ( $l as $j ) {
			$nos_grupos[ $j->ID ] = true;
		}
	}
	$outros = array_values( array_filter( $julgados, function ( $j ) use ( $nos_grupos ) {
		return empty( $nos_grupos[ $j->ID ] );
	} ) );

	$html  = '<div class="tpi-ramo alignwide">';
	$html .= '<nav class="tpi-trilha" aria-label="Trilha de navegação"><ol><li><a href="' . esc_url( $hub ) . '">Informativos</a></li><li><a href="' . esc_url( $hub . '#tpi-ramos-titulo' ) . '">Ramos do Direito</a></li></ol></nav>';
	$html .= '<h1 class="tpi-ramo__titulo">' . esc_html( $t->name ) . ' nos informativos do STJ e do STF</h1>';
	$intro = trim( (string) $t->description );
	$html .= '<div class="tpi-ramo__intro">' . ( $intro ? wpautop( wp_kses_post( $intro ) ) : '<p>Julgados de ' . esc_html( $t->name ) . ' publicados nos informativos do STJ e do STF, com comentário, ficha do processo e o que pode cair em prova. Os mais recentes aparecem primeiro.</p>' ) . '</div>';
	$html .= '<p class="tpi-ramo__total">' . sprintf( _n( '%d julgado comentado', '%d julgados comentados', $total ), $total ) . '</p>';
	$cad = function_exists( 'tpc_caderno_do_ramo' ) ? tpc_caderno_do_ramo( $t->slug ) : null;
	if ( $cad ) { // 0.11.4: a teoria do ramo, no caderno sistematizado
		$n     = count( tpc_temas( $cad->ID ) );
		$html .= '<p class="tpi-ramo__caderno">Quer a teoria organizada por tema? Veja o <a href="' . esc_url( get_permalink( $cad ) ) . '">' . esc_html( $cad->post_title ) . '</a>: ' . esc_html( sprintf( _n( '%d tema', '%d temas', $n ), $n ) ) . ' com resumo, esquema, lei seca e pegadinhas de banca.</p>';
	}

	if ( $grupos ) {
		$html .= '<nav class="tpi-topicos" aria-label="Filtrar por tópico"><p class="tpi-topicos__rotulo" id="tpi-topicos-rotulo">Filtrar por tópico</p><ul aria-labelledby="tpi-topicos-rotulo">';
		$html .= '<li><a href="#tpi-ramo-lista" data-tpi-topico="" aria-current="true">Todos <span class="tpi-ramos__n">' . $total . '</span></a></li>';
		foreach ( $grupos as $slug => $l ) {
			$html .= '<li><a href="#topico-' . esc_attr( $slug ) . '" data-tpi-topico="' . esc_attr( $slug ) . '">' . esc_html( $nomes[ $slug ] ) . ' <span class="tpi-ramos__n">' . count( $l ) . '</span></a></li>';
		}
		if ( $outros ) {
			$html .= '<li><a href="#topico-outros" data-tpi-topico="outros">Outros <span class="tpi-ramos__n">' . count( $outros ) . '</span></a></li>';
		}
		$html .= '</ul></nav><p class="tpi-sr" role="status" id="tpi-topicos-status"></p>';
	}

	$html .= '<div id="tpi-ramo-lista" class="tpi-ramo__lista">';
	if ( ! $total ) {
		$html .= '<p class="tpi-vazio">Ainda não há julgados comentados neste ramo.</p>';
	} elseif ( ! $grupos ) {
		$html .= '<h2 class="tpi-grupo">Julgados mais recentes</h2><ol class="tpi-cards tpi-cards--julgados">';
		foreach ( $julgados as $j ) {
			$html .= tpi_card_julgado( $j );
		}
		$html .= '</ol>';
	} else {
		foreach ( $grupos as $slug => $l ) {
			$html .= '<section class="tpi-topico" id="topico-' . esc_attr( $slug ) . '" data-tpi-topico="' . esc_attr( $slug ) . '"><h2 class="tpi-grupo">' . esc_html( $nomes[ $slug ] ) . '</h2><ol class="tpi-cards tpi-cards--julgados">';
			foreach ( $l as $j ) {
				$html .= tpi_card_julgado( $j );
			}
			$html .= '</ol></section>';
		}
		if ( $outros ) {
			$html .= '<section class="tpi-topico" id="topico-outros" data-tpi-topico="outros"><h2 class="tpi-grupo">Outros julgados</h2><ol class="tpi-cards tpi-cards--julgados">';
			foreach ( $outros as $j ) {
				$html .= tpi_card_julgado( $j );
			}
			$html .= '</ol></section>';
		}
	}
	$html .= '</div>';

	// Outros ramos (links internos entre as páginas de ramo).
	$outros_ramos = array_filter( tpi_ramos_principais(), function ( $r ) use ( $t ) {
		return $r->term_id !== $t->term_id;
	} );
	if ( $outros_ramos ) {
		$html .= '<section class="tpi-ramos" aria-labelledby="tpi-outros-ramos"><h2 id="tpi-outros-ramos">Outros ramos do Direito</h2><ul>';
		foreach ( $outros_ramos as $r ) {
			$html .= '<li><a href="' . esc_url( get_term_link( $r ) ) . '">' . esc_html( $r->name ) . ' <span class="tpi-ramos__n">' . (int) $r->tpi_total . '</span></a></li>';
		}
		$html .= '</ul></section>';
	}
	$html .= '<p class="tpi-limpar"><a href="' . esc_url( $hub ) . '">Buscar em todos os informativos</a></p>';
	$html .= function_exists( 'tpi_newsletter_cta' ) ? tpi_newsletter_cta() : '';
	return $html . '</div>';
}
