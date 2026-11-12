<?php
/**
 * Seção "Como estudar" (0.13.0): guias de método de estudo como posts nativos do WordPress.
 *
 * Fonte: como-estudar/*.html. Cada arquivo começa com um comentário de cabeçalho
 * (slug, categoria, title, description, palavra-chave, secundárias) e o resto é o corpo do post.
 * Categorias: como-estudar/_categorias.json. Página hub /como-estudar/: como-estudar/_hub.html.
 *
 * Regras:
 * - Post que não existe é criado e publicado.
 * - Post que já existe só é atualizado pelo arquivo se ninguém o editou no painel depois da última
 *   sincronização. Edição feita no WordPress vence; o arquivo deixa de mandar naquele post.
 * - Uma vez só, o permalink dos posts passa a /como-estudar/%postname%/ (se não estiver no modo simples, ?p=). Os tipos
 *   informativo e caderno (e a taxonomia ramo) têm with_front => false, então não mudam de endereço.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPCE_DIR', dirname( __DIR__ ) . '/como-estudar' );
define( 'TPCE_BASE', '/como-estudar/%postname%/' );

function tpce_arquivos() {
	$a = glob( TPCE_DIR . '/[0-9]*.html' );
	return $a ? $a : array();
}

/** Lê o cabeçalho "chave: valor" do comentário inicial e devolve [meta, corpo]. */
function tpce_le( $arquivo ) {
	$txt  = (string) file_get_contents( $arquivo );
	$meta = array();
	if ( preg_match( '/^\s*<!--(.*?)-->/s', $txt, $m ) ) {
		foreach ( preg_split( '/\R/', $m[1] ) as $linha ) {
			if ( preg_match( '/^\s*([^:]+?)\s*:\s*(.+)$/u', $linha, $kv ) ) {
				$meta[ strtolower( trim( $kv[1] ) ) ] = trim( $kv[2] );
			}
		}
		$txt = substr( $txt, strlen( $m[0] ) );
	}
	return array( $meta, trim( $txt ) );
}

function tpce_assinatura() {
	$s = TPI_VERSION;
	foreach ( array_merge( tpce_arquivos(), array( TPCE_DIR . '/_categorias.json', TPCE_DIR . '/_hub.html' ) ) as $f ) {
		if ( file_exists( $f ) ) {
			$s .= '|' . basename( $f ) . ':' . filesize( $f ) . ':' . md5_file( $f );
		}
	}
	return md5( $s );
}

add_action( 'init', function () {
	if ( ! is_dir( TPCE_DIR ) ) {
		return;
	}
	$sig = tpce_assinatura();
	if ( get_option( 'tpce_sync_assinatura' ) === $sig || get_transient( 'tpce_sync_trava' ) ) {
		return;
	}
	set_transient( 'tpce_sync_trava', 1, 2 * MINUTE_IN_SECONDS );
	tpce_permalink();
	$cats = tpce_categorias();
	$ok   = true;
	foreach ( tpce_arquivos() as $f ) {
		$ok = tpce_post( $f, $cats ) && $ok;
	}
	tpce_hub();
	if ( $ok ) {
		update_option( 'tpce_sync_assinatura', $sig, true );
	}
	delete_transient( 'tpce_sync_trava' );
}, 40 );

/** Troca o permalink dos posts uma vez só, e só a partir do padrão /%postname%/. */
function tpce_permalink() {
	if ( get_option( 'tpce_permalink_feito' ) ) {
		return;
	}
	global $wp_rewrite;
	$atual = (string) get_option( 'permalink_structure' );
	// Sem posts de blog antes desta seção, trocar a estrutura só mexe nos posts (e nos arquivos de categoria/autor).
	if ( '' !== $atual && TPCE_BASE !== $atual ) {
		$wp_rewrite->set_permalink_structure( TPCE_BASE );
		flush_rewrite_rules( false );
	}
	update_option( 'tpce_permalink_feito', $atual ? $atual : 'vazio', false );
}

/** Cria ou atualiza as categorias. Devolve slug => term_id. */
function tpce_categorias() {
	$out = array();
	$arq = TPCE_DIR . '/_categorias.json';
	$c   = file_exists( $arq ) ? json_decode( (string) file_get_contents( $arq ), true ) : array();
	foreach ( is_array( $c ) ? $c : array() as $cat ) {
		if ( empty( $cat['slug'] ) || empty( $cat['nome'] ) ) {
			continue;
		}
		$t = get_term_by( 'slug', $cat['slug'], 'category' );
		if ( $t ) {
			$id = (int) $t->term_id;
			if ( get_term_meta( $id, 'tpce_hash', true ) !== md5( wp_json_encode( $cat ) ) ) {
				wp_update_term( $id, 'category', array( 'name' => $cat['nome'], 'description' => $cat['descricao'] ?? '' ) );
			}
		} else {
			$r = wp_insert_term( $cat['nome'], 'category', array( 'slug' => $cat['slug'], 'description' => $cat['descricao'] ?? '' ) );
			if ( is_wp_error( $r ) ) {
				continue;
			}
			$id = (int) $r['term_id'];
		}
		if ( ! empty( $cat['title'] ) ) {
			update_term_meta( $id, 'rank_math_title', $cat['title'] . ' %sep% %sitename%' );
		}
		if ( ! empty( $cat['description'] ) ) {
			update_term_meta( $id, 'rank_math_description', $cat['description'] );
		}
		update_term_meta( $id, 'tpce_hash', md5( wp_json_encode( $cat ) ) );
		$out[ $cat['slug'] ] = $id;
	}
	return $out;
}

function tpce_post( $arquivo, $cats ) {
	list( $m, $corpo ) = tpce_le( $arquivo );
	if ( empty( $m['slug'] ) || empty( $m['title (rank math)'] ) || '' === $corpo ) {
		return false;
	}
	$slug = sanitize_title( $m['slug'] );
	$hash = md5( wp_json_encode( $m ) . $corpo );
	$q    = get_posts( array( 'post_type' => 'post', 'name' => $slug, 'post_status' => array( 'publish', 'draft', 'pending', 'private', 'future' ), 'numberposts' => 1 ) );
	$p    = $q ? $q[0] : null;
	if ( $p ) {
		if ( get_post_meta( $p->ID, 'tpce_hash', true ) === $hash ) {
			return true;
		}
		// Editado no painel depois da última sincronização: o WordPress vence.
		if ( get_post_meta( $p->ID, 'tpce_sync', true ) !== $p->post_modified_gmt ) {
			return true;
		}
	}
	$cat_id = 0;
	foreach ( $cats as $s => $id ) {
		$t = get_term( $id, 'category' );
		if ( $t && ! is_wp_error( $t ) && isset( $m['categoria'] ) && $t->name === $m['categoria'] ) {
			$cat_id = $id;
		}
	}
	$post = array(
		'post_type'     => 'post',
		'post_status'   => $p ? $p->post_status : 'publish',
		'post_title'    => $m['title (rank math)'],
		'post_name'     => $slug,
		'post_content'  => $corpo,
		'post_excerpt'  => $m['description'] ?? '',
		'post_author'   => $p ? (int) $p->post_author : 1,
		'post_category' => $cat_id ? array( $cat_id ) : array(),
		'comment_status' => 'closed',
		'ping_status'   => 'closed',
	);
	if ( $p ) {
		$post['ID'] = $p->ID;
	}
	$id = wp_insert_post( wp_slash( $post ), true );
	if ( is_wp_error( $id ) ) {
		return false;
	}
	if ( ! empty( $m['description'] ) ) {
		update_post_meta( $id, 'rank_math_description', $m['description'] );
	}
	$kw = trim( ( $m['palavra-chave'] ?? '' ) . ( empty( $m['secundárias'] ) ? '' : ',' . str_replace( ';', ',', $m['secundárias'] ) ), ', ' );
	if ( $kw ) {
		update_post_meta( $id, 'rank_math_focus_keyword', implode( ',', array_map( 'trim', explode( ',', $kw ) ) ) );
	}
	update_post_meta( $id, 'tpce_hash', $hash );
	update_post_meta( $id, 'tpce_sync', get_post_field( 'post_modified_gmt', $id ) );
	return true;
}

/** Página /como-estudar/, criada uma vez. Depois disso, edite no painel. */
function tpce_hub() {
	if ( get_option( 'tpce_hub_criado' ) || ! file_exists( TPCE_DIR . '/_hub.html' ) ) {
		return;
	}
	if ( ! get_page_by_path( 'como-estudar' ) ) {
		list( $m, $corpo ) = tpce_le( TPCE_DIR . '/_hub.html' );
		$id = wp_insert_post( wp_slash( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Como estudar para concursos',
			'post_name'    => 'como-estudar',
			'post_content' => $corpo,
			'post_excerpt' => $m['description'] ?? '',
			'post_author'  => 1,
		) ), true );
		if ( ! is_wp_error( $id ) ) {
			if ( ! empty( $m['description'] ) ) {
				update_post_meta( $id, 'rank_math_description', $m['description'] );
			}
			if ( ! empty( $m['palavra-chave'] ) ) {
				update_post_meta( $id, 'rank_math_focus_keyword', $m['palavra-chave'] );
			}
		}
	}
	update_option( 'tpce_hub_criado', 1, false );
}

/* Botão de chamada (.tp-cta) e tabelas dos guias. Só nos posts e na página hub. */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! ( is_singular( 'post' ) || is_page( 'como-estudar' ) || is_category() ) ) {
		return;
	}
	wp_register_style( 'tp-como-estudar', false, array(), TPI_VERSION );
	wp_enqueue_style( 'tp-como-estudar' );
	wp_add_inline_style( 'tp-como-estudar', '.tp-cta{display:inline-block;padding:.8em 1.4em;border-radius:8px;background:#2c5748;color:#fff!important;font-weight:700;text-decoration:none}.tp-cta:hover,.tp-cta:focus-visible{background:#1f3f34}.wp-block-post-content table,.entry-content table{border-collapse:collapse;width:100%;display:block;overflow-x:auto}.wp-block-post-content th,.wp-block-post-content td,.entry-content th,.entry-content td{border:1px solid #c5d8cb;padding:.45em .6em;text-align:left;vertical-align:top}.wp-block-post-content thead th,.entry-content thead th{background:#eff5f0}.wp-block-post-content blockquote,.entry-content blockquote{border-left:4px solid #2c5748;margin-left:0;padding-left:1em}' );
} );

/*
 * 0.13.1: a troca do permalink acontece no meio do init, quando as categorias já tinham registrado as regras
 * com o prefixo antigo. Por isso /como-estudar/category/<slug>/ dava 404. Uma nova gravação das regras, numa
 * requisição em que tudo já nasce com o prefixo novo, resolve. Roda uma vez.
 */
add_action( 'init', function () {
	if ( '/como-estudar/%postname%/' !== get_option( 'permalink_structure' ) || '1' === get_option( 'tpce_regras_v' ) ) {
		return;
	}
	flush_rewrite_rules( false );
	update_option( 'tpce_regras_v', '1', false );
}, 99 );
