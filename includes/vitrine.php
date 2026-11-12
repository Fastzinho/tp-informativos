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
	// Só dentro do bloco core/post-content da página (o Rank Math e outros também passam pelo the_content).
	global $tpv_no_corpo;
	if ( empty( $tpv_no_corpo ) || ! tpv_eh_guia() || get_the_ID() !== get_queried_object_id() || false !== strpos( $html, '<!--tpv-guia-->' ) ) {
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

	return '<!--tpv-guia-->' . $sum . $html . $fim;
}, 30 );

add_filter( 'pre_render_block', function ( $pre, $block ) {
	if ( 'core/post-content' === ( $block['blockName'] ?? '' ) ) {
		$GLOBALS['tpv_no_corpo'] = true;
	}
	return $pre;
}, 10, 2 );
add_filter( 'render_block_core/post-content', function ( $html ) {
	$GLOBALS['tpv_no_corpo'] = false;
	return $html;
} );

/* 0.15.0: capa do guia. O título do tema vira um painel com trilha, título, resumo e autor. */
function tpv_capa_arte( $slug ) {
	$a = array(
		'provas-e-bancas'           => '<rect x="38" y="14" width="124" height="132" rx="10"/><path d="M58 40h40M58 66h40M58 92h40M58 118h40"/><circle cx="118" cy="40" r="8"/><circle cx="144" cy="40" r="8" class="c"/><circle cx="118" cy="66" r="8" class="c"/><circle cx="144" cy="66" r="8"/><circle cx="118" cy="92" r="8" class="c"/><circle cx="144" cy="92" r="8"/><circle cx="118" cy="118" r="8"/><circle cx="144" cy="118" r="8" class="c"/>',
		'lei-seca-e-jurisprudencia' => '<path d="M100 30c-18-10-44-12-66-6v108c22-6 48-4 66 6 18-10 44-12 66-6V24c-22-6-48-4-66 6z"/><path d="M100 30v108"/><path d="M48 50h36M48 66h36M48 82h28M116 50h36M116 66h36M116 82h28" class="f"/><path d="M116 100h36" class="c2"/>',
		'memoria-e-organizacao'     => '<rect x="26" y="52" width="92" height="64" rx="8" transform="rotate(-8 72 84)"/><rect x="46" y="44" width="92" height="64" rx="8" transform="rotate(4 92 76)"/><rect x="66" y="38" width="92" height="64" rx="8" class="b"/><path d="M80 86l14 8 22-26" class="c2"/><path d="M30 146c30-4 60-22 80-46s40-36 64-40" class="f"/>',
	);
	$d = $a[ $slug ] ?? $a['memoria-e-organizacao'];
	return '<svg class="tpv-capa__arte" viewBox="0 0 200 160" aria-hidden="true" focusable="false">' . $d . '</svg>';
}

add_filter( 'render_block_core/post-title', function ( $html, $block ) {
	static $feito = false;
	if ( $feito || ! tpv_eh_guia() || ! empty( $block['attrs']['isLink'] ) || get_the_ID() !== get_queried_object_id() ) {
		return $html;
	}
	$feito = true;
	$p     = get_post( get_queried_object_id() );
	$c     = tpv_cat( $p );
	$lede  = (string) get_post_meta( $p->ID, 'rank_math_description', true );
	$lede  = '' !== $lede ? $lede : $p->post_excerpt;
	$nome  = get_the_author_meta( 'display_name', $p->post_author );
	$ini   = '';
	foreach ( array_slice( preg_split( '/\s+/', trim( $nome ) ), 0, 2 ) as $w ) {
		$ini .= mb_substr( $w, 0, 1 );
	}
	$hub   = home_url( '/como-estudar/' );
	return '<header class="tpv-capa tpv-capa--' . esc_attr( $c ? $c->slug : 'geral' ) . '">'
		. '<div class="tpv-capa__texto">'
		. '<nav class="tpv-capa__trilha" aria-label="Você está em"><a href="' . esc_url( $hub ) . '">Como estudar</a>'
		. ( $c ? '<span aria-hidden="true">/</span><a href="' . esc_url( get_category_link( $c ) ) . '">' . esc_html( $c->name ) . '</a>' : '' ) . '</nav>'
		. '<h1 class="wp-block-post-title tpv-capa__titulo">' . esc_html( get_the_title( $p ) ) . '</h1>'
		. ( '' !== $lede ? '<p class="tpv-capa__lede">' . esc_html( $lede ) . '</p>' : '' )
		. '<div class="tpv-capa__meta"><span class="tpv-capa__avatar" aria-hidden="true">' . esc_html( mb_strtoupper( $ini ) ) . '</span>'
		. '<span><a class="tpv-capa__autor" href="' . esc_url( home_url( '/sobre/' ) ) . '">' . esc_html( $nome ) . '</a>'
		. '<span class="tpv-capa__dados">' . esc_html( tpv_minutos( $p ) . ' min de leitura' ) . ' · Atualizado em <time datetime="' . esc_attr( get_the_modified_date( 'Y-m-d', $p ) ) . '">' . esc_html( get_the_modified_date( 'd/m/Y', $p ) ) . '</time></span></span></div>'
		. '</div>' . tpv_capa_arte( $c ? $c->slug : '' ) . '</header>';
}, 10, 2 );

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

/* 0.14.3: item "Como estudar" no menu do cabeçalho (parte de modelo "header" salva no editor do site).
   Roda uma vez; no celular fica oculto (tp-nav-hide) para não estourar a largura, mas o link segue no HTML. */
add_action( 'init', function () {
	if ( get_option( 'tpv_menu_v1' ) ) { return; }
	$partes = get_posts( array( 'post_type' => 'wp_template_part', 'post_status' => 'any', 'numberposts' => 20, 'suppress_filters' => true ) );
	$ancora = '<a href="/cadernos/" class="tp-nav-link">Cadernos</a>';
	$novo   = '<a href="/como-estudar/" class="tp-nav-link tp-nav-hide" style="white-space:nowrap">Como estudar</a>';
	$feito  = false;
	foreach ( $partes as $p ) {
		$c = $p->post_content;
		if ( false === strpos( $c, $ancora ) || false !== strpos( $c, 'href="/como-estudar/"' ) ) { continue; }
		// Direto no banco: wp_update_post sem usuário logado passaria pelo kses e tiraria o <style> do cabeçalho.
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_content' => str_replace( $ancora, $ancora . $novo, $c ) ), array( 'ID' => $p->ID ) );
		clean_post_cache( $p->ID );
		$feito = true;
	}
	update_option( 'tpv_menu_v1', $feito ? 'inserido' : 'nao-achou', false );
}, 60 );

/* 0.15.0: imagem destacada dos guias (como-estudar/capas/<slug>.png, gerada por ferramentas/capas-como-estudar.js).
   Vira og:image no Rank Math e imagem do Article. Troca a imagem quando o arquivo muda (hash em tpv_capa_hash). */
add_action( 'init', function () {
	$dir = plugin_dir_path( TPI_ARQUIVO ) . 'como-estudar/capas/';
	$arq = glob( $dir . '*.png' );
	if ( ! $arq ) {
		return;
	}
	$assin = md5( implode( '|', array_map( 'md5_file', $arq ) ) );
	if ( get_option( 'tpv_capas_sig' ) === $assin || get_transient( 'tpv_capas_lock' ) ) {
		return;
	}
	set_transient( 'tpv_capas_lock', 1, 120 );
	require_once ABSPATH . 'wp-admin/includes/image.php';
	foreach ( $arq as $f ) {
		$slug = basename( $f, '.png' );
		$p    = get_page_by_path( $slug, OBJECT, 'post' );
		$hash = md5_file( $f );
		if ( ! $p || get_post_meta( $p->ID, 'tpv_capa_hash', true ) === $hash ) {
			continue;
		}
		$up = wp_upload_bits( 'capa-' . $slug . '.png', null, file_get_contents( $f ) );
		if ( ! empty( $up['error'] ) ) {
			continue;
		}
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => get_the_title( $p ), 'post_status' => 'inherit' ), $up['file'], $p->ID );
		if ( is_wp_error( $id ) || ! $id ) {
			continue;
		}
		wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $up['file'] ) );
		update_post_meta( $id, '_wp_attachment_image_alt', 'Capa do guia: ' . get_the_title( $p ) );
		$velho = (int) get_post_thumbnail_id( $p->ID );
		set_post_thumbnail( $p->ID, $id );
		update_post_meta( $p->ID, 'tpv_capa_hash', $hash );
		if ( $velho && $velho !== $id && get_post_meta( $velho, '_wp_attachment_image_alt', true ) && 0 === strpos( (string) get_post_meta( $velho, '_wp_attachment_image_alt', true ), 'Capa do guia:' ) ) {
			wp_delete_attachment( $velho, true );
		}
	}
	update_option( 'tpv_capas_sig', $assin, false );
	delete_transient( 'tpv_capas_lock' );
}, 70 );
