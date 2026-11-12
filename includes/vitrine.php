<?php
/**
 * Vitrine (0.14.0): visual compartilhado das páginas de entrada orgânica.
 * - /cadernos/ ([tp_cadernos], em cadernos.php) e /como-estudar/ ([tp_como_estudar]): busca, cards, pontes e chamada para o app.
 * - Guias da seção Como estudar (posts): barra de dados, sumário, resposta curta em destaque, chamada em painel,
 *   fontes discretas, autor e "Continue lendo".
 * CSS e JS em assets/tpv.css e assets/tpv.js.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function tpv_assets() {
	wp_enqueue_style( 'tp-vitrine', TPI_URL . 'assets/tpv.css', array(), TPI_VERSION );
	wp_enqueue_script( 'tp-vitrine', TPI_URL . 'assets/tpv.js', array(), TPI_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
}

function tpv_app_url() {
	return home_url( '/estudar/' );
}

/** Painel verde de chamada para o app. */
function tpv_cta( $titulo, $texto, $botao, $url = '' ) {
	return '<section class="tpv-cta"><div><h2 class="tpv-cta__titulo">' . esc_html( $titulo ) . '</h2><p class="tpv-cta__texto">' . esc_html( $texto ) . '</p></div>'
		. '<a class="tpv-cta__botao" href="' . esc_url( $url ? $url : tpv_app_url() ) . '">' . esc_html( $botao ) . ' <span aria-hidden="true">→</span></a></section>';
}

/** Guias publicados da seção (posts sincronizados). */
function tpv_guias() {
	static $g = null;
	if ( null === $g ) {
		$g = get_posts( array( 'post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 60, 'meta_key' => 'tpce_hash', 'orderby' => 'date', 'order' => 'ASC' ) );
	}
	return $g;
}
function tpv_guia( $slug ) {
	foreach ( tpv_guias() as $p ) {
		if ( $p->post_name === $slug ) {
			return $p;
		}
	}
	return null;
}
function tpv_minutos( $p ) {
	return max( 2, (int) round( str_word_count( wp_strip_all_tags( $p->post_content ) ) / 200 ) );
}
function tpv_cat( $p ) {
	$c = get_the_category( $p->ID );
	return $c ? $c[0] : null;
}

function tpv_card_guia( $p, $num = 0 ) {
	$c = tpv_cat( $p );
	return '<li class="tpv-card"><a class="tpv-card__link" href="' . esc_url( get_permalink( $p ) ) . '">'
		. '<span class="tpv-card__topo">' . ( $num ? '<span class="tpv-card__num">' . (int) $num . '</span>' : '' )
		. ( $c ? '<span class="tpv-card__selo">' . esc_html( $c->name ) . '</span>' : '' )
		. '<span class="tpv-card__tempo">' . esc_html( tpv_minutos( $p ) . ' min' ) . '</span></span>'
		. '<span class="tpv-card__titulo">' . esc_html( get_the_title( $p ) ) . '</span>'
		. ( $p->post_excerpt ? '<span class="tpv-card__texto">' . esc_html( $p->post_excerpt ) . '</span>' : '' )
		. '<span class="tpv-card__acao">Ler o guia <span aria-hidden="true">→</span></span>'
		. '</a></li>';
}

/** Faixa com até 3 guias (por slug), para ligar cadernos e informativos aos guias. */
function tpv_ponte_guias( $slugs, $titulo ) {
	$itens = '';
	foreach ( $slugs as $s ) {
		$p = tpv_guia( $s );
		if ( $p ) {
			$itens .= tpv_card_guia( $p );
		}
	}
	if ( ! $itens ) {
		return '';
	}
	return '<h2 class="tpv-secao">' . esc_html( $titulo ) . '</h2><ul class="tpv-grade tpv-grade--3">' . $itens . '</ul>'
		. '<p class="tpv-mais"><a href="' . esc_url( home_url( '/como-estudar/' ) ) . '">Ver todos os guias de como estudar <span aria-hidden="true">→</span></a></p>';
}

/* ---------------------------------------------------------------------------
 * Hub /como-estudar/
 * ------------------------------------------------------------------------- */
add_shortcode( 'tp_como_estudar', function () {
	$guias = tpv_guias();
	if ( ! $guias ) {
		return '';
	}
	$h  = '<div class="tpv alignwide">';
	$h .= '<section class="tpv-hero"><p class="tpv-hero__olho">' . esc_html( count( $guias ) . ' guias · com dados de provas oficiais' ) . '</p>'
		. '<p class="tpv-hero__titulo">O jeito de estudar, explicado com números</p>'
		. '<p class="tpv-hero__nota">Cada guia responde uma pergunta e mostra de onde vêm os dados: editais, gabaritos definitivos e provas que nós mesmos contamos. Quando algo é só regra prática, está escrito.</p>'
		. '<nav class="tpv-pilulas" aria-label="Categorias">';
	$cats = array();
	foreach ( $guias as $p ) {
		$c = tpv_cat( $p );
		if ( $c && ! isset( $cats[ $c->term_id ] ) ) {
			$cats[ $c->term_id ] = $c;
		}
	}
	foreach ( $cats as $c ) {
		$h .= '<a class="tpv-pilula" href="#' . esc_attr( $c->slug ) . '">' . esc_html( $c->name ) . '</a>';
	}
	$h .= '</nav></section>';

	$comece = '';
	$n      = 0;
	foreach ( array( 'edital-verticalizado', 'ciclo-de-estudos', 'como-estudar-por-questoes', 'revisao-espacada' ) as $s ) {
		$p = tpv_guia( $s );
		if ( $p ) {
			$comece .= tpv_card_guia( $p, ++$n );
		}
	}
	if ( $comece ) {
		$h .= '<h2 class="tpv-secao">Começando do zero? Siga esta ordem</h2><ul class="tpv-grade tpv-grade--4">' . $comece . '</ul>';
	}
	foreach ( $cats as $c ) {
		$itens = '';
		foreach ( $guias as $p ) {
			$pc = tpv_cat( $p );
			if ( $pc && $pc->term_id === $c->term_id ) {
				$itens .= tpv_card_guia( $p );
			}
		}
		$h .= '<h2 class="tpv-secao" id="' . esc_attr( $c->slug ) . '">' . esc_html( $c->name ) . '</h2>';
		if ( $c->description ) {
			$h .= '<p class="tpv-secao__texto">' . esc_html( wp_trim_words( wp_strip_all_tags( $c->description ), 34 ) ) . '</p>';
		}
		$h .= '<ul class="tpv-grade">' . $itens . '</ul>';
	}
	$h .= '<h2 class="tpv-secao">Quer estudar a matéria?</h2><ul class="tpv-grade tpv-grade--2">'
		. '<li class="tpv-card tpv-card--destaque"><a class="tpv-card__link" href="' . esc_url( home_url( '/cadernos/' ) ) . '"><span class="tpv-card__titulo">Cadernos sistematizados</span><span class="tpv-card__texto">Resumo, esquema, lei seca, jurisprudência e pegadinha de cada tema, por disciplina.</span><span class="tpv-card__acao">Ver cadernos <span aria-hidden="true">→</span></span></a></li>'
		. '<li class="tpv-card tpv-card--destaque"><a class="tpv-card__link" href="' . esc_url( home_url( '/informativos/' ) ) . '"><span class="tpv-card__titulo">Informativos comentados</span><span class="tpv-card__texto">Os julgados do STJ e do STF que importam para concurso, explicados e com questões.</span><span class="tpv-card__acao">Ver informativos <span aria-hidden="true">→</span></span></a></li>'
		. '</ul>';
	$h .= tpv_cta( 'Prefere que o plano saia pronto?', 'O Tchau Pobreza é uma plataforma de estudos para concursos grátis: escolha o seu edital e receba ciclo, revisões e prática em Certo ou Errado.', 'Começar grátis' );
	return $h . '</div>';
} );

/* A página /como-estudar/ nasceu com a lista em HTML (0.13.0). Se ninguém a editou, troca pela vitrine. */
add_action( 'init', function () {
	if ( get_option( 'tpv_hub_v2' ) ) {
		return;
	}
	$p = get_page_by_path( 'como-estudar' );
	if ( $p && false !== strpos( $p->post_content, 'Guias diretos sobre o jeito de estudar' ) && false === strpos( $p->post_content, 'Ver todos' ) ) {
		wp_update_post( array(
			'ID'           => $p->ID,
			'post_content' => "<!-- wp:paragraph -->\n<p>Guias diretos sobre o jeito de estudar para concurso: como a prova funciona, como estudar lei seca e jurisprudência, como revisar e como organizar o edital. Escritos por quem está estudando para concurso e constrói o Tchau Pobreza.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[tp_como_estudar]\n<!-- /wp:shortcode -->",
		) );
	}
	update_option( 'tpv_hub_v2', 1, false );
}, 50 );

/* ---------------------------------------------------------------------------
 * Guia (post da seção)
 * ------------------------------------------------------------------------- */
function tpv_eh_guia() {
	return is_singular( 'post' ) && '' !== (string) get_post_meta( get_queried_object_id(), 'tpce_hash', true );
}

add_filter( 'the_content', function ( $html ) {
	// Só no corpo da página (depois do <head>, onde o Rank Math também passa pelo the_content).
	if ( ! tpv_eh_guia() || get_the_ID() !== get_queried_object_id() || ! did_action( 'wp_head' ) || doing_action( 'wp_head' ) || false !== strpos( $html, 'tpv-guia-meta' ) ) {
		return $html;
	}
	$p     = get_post( get_queried_object_id() );

	// Âncoras e sumário a partir dos H2.
	$toc   = array();
	$usado = array();
	$html  = preg_replace_callback( '#<h2>(.*?)</h2>#s', function ( $m ) use ( &$toc, &$usado ) {
		$txt = wp_strip_all_tags( $m[1] );
		$id  = sanitize_title( $txt );
		$id  = isset( $usado[ $id ] ) ? $id . '-' . ( ++$usado[ $id ] ) : $id;
		$usado[ $id ] = $usado[ $id ] ?? 1;
		if ( 'fontes' !== $id ) {
			$toc[] = '<li><a href="#' . esc_attr( $id ) . '">' . esc_html( $txt ) . '</a></li>';
		}
		return '<h2 id="' . esc_attr( $id ) . '">' . $m[1] . '</h2>';
	}, $html );

	// Resposta curta em destaque.
	$html = preg_replace( '#<p><strong>Resposta curta:</strong>#', '<p class="tpv-resposta"><strong>Resposta curta:</strong>', $html, 1 );

	// Bloco final "Fazendo isso no Tchau Pobreza" + botão vira painel.
	$html = preg_replace_callback( '#<h2 id="[^"]*">([^<]+)</h2>\s*<p>((?:(?!</p>).)*)</p>\s*<p><a class="tp-cta" href="([^"]+)">([^<]*)</a></p>#s', function ( $m ) {
		return '<section class="tpv-cta tpv-cta--guia"><div><h2 class="tpv-cta__titulo">' . $m[1] . '</h2><p class="tpv-cta__texto">' . $m[2] . '</p></div>'
			. '<a class="tpv-cta__botao" href="' . esc_url( $m[3] ) . '">' . $m[4] . ' <span aria-hidden="true">→</span></a></section>';
	}, $html );

	// Fontes discretas.
	$html = preg_replace( '#<h2 id="fontes">Fontes</h2>\s*<ul>#', '<h2 id="fontes" class="tpv-fontes__titulo">Fontes</h2><ul class="tpv-fontes">', $html );

	$c     = tpv_cat( $p );
	$meta  = '<div class="tpv-guia-meta">'
		. ( $c ? '<a class="tpv-card__selo" href="' . esc_url( get_category_link( $c ) ) . '">' . esc_html( $c->name ) . '</a>' : '' )
		. '<span>' . esc_html( tpv_minutos( $p ) . ' min de leitura' ) . '</span>'
		. '<span>Atualizado em ' . esc_html( get_the_modified_date( 'd/m/Y', $p ) ) . '</span></div>';
	$sum   = count( $toc ) >= 3 ? '<details class="tpv-sumario" open><summary>Neste guia</summary><ol>' . implode( '', $toc ) . '</ol></details>' : '';

	// Autor e continue lendo.
	$autor = '<aside class="tpv-autor"><p class="tpv-autor__nome">' . esc_html( get_the_author_meta( 'display_name', $p->post_author ) ) . '</p>'
		. '<p class="tpv-autor__texto">Estuda para concurso e constrói o Tchau Pobreza. Os números deste guia vêm de editais, gabaritos definitivos e provas oficiais, contados por nós; as fontes estão acima.</p>'
		. '<p><a href="' . esc_url( home_url( '/sobre/' ) ) . '">Sobre o projeto <span aria-hidden="true">→</span></a></p></aside>';
	$rel   = array();
	foreach ( tpv_guias() as $g ) {
		$gc = tpv_cat( $g );
		if ( $g->ID !== $p->ID && $c && $gc && $gc->term_id === $c->term_id ) {
			$rel[] = $g;
		}
	}
	foreach ( tpv_guias() as $g ) {
		if ( count( $rel ) >= 3 ) {
			break;
		}
		if ( $g->ID !== $p->ID && ! in_array( $g, $rel, true ) ) {
			$rel[] = $g;
		}
	}
	$cont = '';
	foreach ( array_slice( $rel, 0, 3 ) as $g ) {
		$cont .= tpv_card_guia( $g );
	}
	$fim = $autor . ( $cont ? '<h2 class="tpv-secao">Continue lendo</h2><ul class="tpv-grade tpv-grade--3 alignwide">' . $cont . '</ul>' : '' );

	return $meta . $sum . $html . $fim;
}, 30 );

add_filter( 'body_class', function ( $c ) {
	if ( tpv_eh_guia() ) {
		$c[] = 'tpv-guia';
	}
	return $c;
} );

add_action( 'wp_enqueue_scripts', function () {
	$conteudo = is_singular() ? (string) get_post_field( 'post_content', get_queried_object_id() ) : '';
	if ( tpv_eh_guia() || is_category() || has_shortcode( $conteudo, 'tp_como_estudar' ) ) {
		tpv_assets();
	}
}, 12 );
