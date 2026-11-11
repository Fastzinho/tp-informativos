<?php
/**
 * 0.8.0: Cadernos sistematizados por disciplina (/cadernos/<disciplina>/).
 *
 * Modelo híbrido (decisão do Jonatan em 10/11/2026): uma página por disciplina, um card por tema com âncora
 * (/cadernos/direito-penal/#peculato). O tema também é um post filho, mas o endereço dele redireciona (301) para a
 * âncora até o tema ganhar página própria ("pagina_propria": true), quando crescer ou tiver impressões no Search Console.
 *
 * Fonte única: cadernos/<disciplina>.json neste plugin. O conteúdo é revisado no PR e sincronizado com os posts
 * na primeira requisição depois do deploy (sem senha de aplicação, sem editar no painel; uma edição no painel é
 * sobrescrita na próxima mudança do JSON). Formato documentado em cadernos/LEIA-ME.md.
 *
 * Card do tema: incidência em prova (só com amostra declarada), Em 30 segundos, esquema, lei seca, macete,
 * jurisprudência, pegadinha C/E, aprofundamento recolhível, praticar no app e relacionados.
 * Marcar como lido: o mesmo do informativo (tpi.js), em tp_records kind "informativo", id "informativo:<post_id>",
 * com data.tipo = "caderno". O app não lê esse kind, então não há mistura com os julgados.
 *
 * Indexação: a disciplina só é indexável com pelo menos tpc_min_index() temas escritos (com "Em 30 segundos");
 * antes disso sai com noindex,follow e fica fora do sitemap (evita página rasa, política de scaled content).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPC_DIR', dirname( __DIR__ ) . '/cadernos' );

function tpc_min_index() {
	return (int) apply_filters( 'tpc_min_index', 8 );
}

/* ---------------------------------------------------------------------------
 * 1. Tipo de conteúdo
 * ------------------------------------------------------------------------- */
add_action( 'init', function () {
	register_post_type(
		'caderno',
		array(
			'labels'       => array(
				'name'          => 'Cadernos',
				'singular_name' => 'Caderno',
				'all_items'     => 'Todos',
				'edit_item'     => 'Editar',
			),
			'description'  => 'Cadernos sistematizados por disciplina. O conteúdo vem de cadernos/*.json no plugin.',
			'public'       => true,
			'hierarchical' => true,
			'has_archive'  => false, // o hub /cadernos/ é uma Página comum com [tp_cadernos]
			'rewrite'      => array( 'slug' => 'cadernos', 'with_front' => false ),
			'menu_icon'    => 'dashicons-welcome-learn-more',
			'show_in_rest' => true,
			'rest_base'    => 'cadernos',
			'supports'     => array( 'title', 'editor', 'excerpt', 'page-attributes', 'revisions', 'custom-fields', 'author' ),
		)
	);
	foreach ( array( 'tpc_tipo', 'tpc_slug', 'tpc_hash', 'tpc_dados' ) as $k ) {
		register_post_meta( 'caderno', $k, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => function () {
			return current_user_can( 'edit_posts' );
		} ) );
	}
} );

/* Dados do post (JSON guardado em tpc_dados). */
function tpc_dados( $id ) {
	$d = json_decode( (string) get_post_meta( $id, 'tpc_dados', true ), true );
	return is_array( $d ) ? $d : array();
}
function tpc_eh_disciplina( $id ) {
	return 'disciplina' === get_post_meta( $id, 'tpc_tipo', true );
}
function tpc_temas( $disc_id ) {
	return get_posts( array( 'post_type' => 'caderno', 'post_parent' => $disc_id, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'menu_order', 'order' => 'ASC' ) );
}
function tpc_escrito( $t ) {
	return '' !== trim( wp_strip_all_tags( (string) ( $t['resumo'] ?? '' ) ) );
}
function tpc_n_escritos( $disc_id ) {
	$n = 0;
	foreach ( tpc_temas( $disc_id ) as $p ) {
		$n += tpc_escrito( tpc_dados( $p->ID ) ) ? 1 : 0;
	}
	return $n;
}
function tpc_indexavel( $id ) {
	if ( tpc_eh_disciplina( $id ) ) {
		return tpc_n_escritos( $id ) >= tpc_min_index();
	}
	$d = tpc_dados( $id ); // tema com página própria
	return ! empty( $d['pagina_propria'] ) && tpc_escrito( $d ) && tpc_indexavel( wp_get_post_parent_id( $id ) );
}

/* ---------------------------------------------------------------------------
 * 2. Sincronização: cadernos/*.json → posts
 *    Roda quando a assinatura dos arquivos (nome, tamanho, data) muda. Trava de 2 min contra corrida.
 * ------------------------------------------------------------------------- */
function tpc_arquivos() {
	$a = glob( TPC_DIR . '/*.json' );
	return $a ? $a : array();
}
function tpc_assinatura_arquivos() {
	$s = TPI_VERSION;
	foreach ( tpc_arquivos() as $f ) {
		$s .= '|' . basename( $f ) . ':' . filesize( $f ) . ':' . filemtime( $f );
	}
	return md5( $s );
}
add_action( 'init', function () {
	$sig = tpc_assinatura_arquivos();
	if ( get_option( 'tpc_sync_assinatura' ) === $sig || get_transient( 'tpc_sync_trava' ) ) {
		return;
	}
	set_transient( 'tpc_sync_trava', 1, 2 * MINUTE_IN_SECONDS );
	$ok = true;
	foreach ( tpc_arquivos() as $f ) {
		$ok = tpc_sincroniza( $f ) && $ok;
	}
	if ( $ok ) {
		update_option( 'tpc_sync_assinatura', $sig, true );
	}
	tpc_garante_hub();
	delete_transient( 'tpc_indice_v2' );
	delete_transient( 'tpc_sync_trava' );
}, 30 );

function tpc_post_por( $slug, $parent ) {
	$q = get_posts( array( 'post_type' => 'caderno', 'post_status' => array( 'publish', 'draft', 'private' ), 'name' => $slug, 'post_parent' => $parent, 'numberposts' => 1 ) );
	return $q ? $q[0] : null;
}

function tpc_upsert( $slug, $parent, $titulo, $resumo, $ordem, $tipo, $dados ) {
	$json = wp_json_encode( $dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$hash = md5( $titulo . '|' . $ordem . '|' . $json );
	$p    = tpc_post_por( $slug, $parent );
	if ( $p && get_post_meta( $p->ID, 'tpc_hash', true ) === $hash && 'publish' === $p->post_status ) {
		return $p->ID;
	}
	$texto = wp_strip_all_tags( (string) $resumo );
	$post  = array(
		'post_type'    => 'caderno',
		'post_status'  => 'publish',
		'post_title'   => $titulo,
		'post_name'    => $slug,
		'post_parent'  => $parent,
		'menu_order'   => $ordem,
		'post_excerpt' => $texto,
		'post_content' => $texto, // só para a busca do WP e a análise do Rank Math; a página é montada de tpc_dados
		'post_author'  => 1,
	);
	if ( $p ) {
		$post['ID'] = $p->ID;
	}
	$id = wp_insert_post( wp_slash( $post ), true );
	if ( is_wp_error( $id ) ) {
		return 0;
	}
	update_post_meta( $id, 'tpc_tipo', $tipo );
	update_post_meta( $id, 'tpc_slug', $slug );
	update_post_meta( $id, 'tpc_dados', wp_slash( $json ) );
	update_post_meta( $id, 'tpc_hash', $hash );
	return $id;
}

function tpc_sincroniza( $arquivo ) {
	$c = json_decode( (string) file_get_contents( $arquivo ), true );
	if ( ! is_array( $c ) || empty( $c['slug'] ) || empty( $c['titulo'] ) ) {
		return false;
	}
	$slug  = sanitize_title( $c['slug'] );
	$temas = isset( $c['temas'] ) && is_array( $c['temas'] ) ? $c['temas'] : array();
	$disc  = $c;
	unset( $disc['temas'] );
	$disc['_versao'] = md5( (string) file_get_contents( $arquivo ) ); // qualquer mudança nos temas atualiza a data da disciplina
	$disc_id = tpc_upsert( $slug, 0, $c['titulo'], $c['descricao'] ?? '', (int) ( $c['ordem'] ?? 0 ), 'disciplina', $disc );
	if ( ! $disc_id ) {
		return false;
	}
	$vistos = array();
	foreach ( array_values( $temas ) as $i => $t ) {
		if ( empty( $t['slug'] ) || empty( $t['titulo'] ) ) {
			continue;
		}
		$ts       = sanitize_title( $t['slug'] );
		$vistos[] = $ts;
		tpc_upsert( $ts, $disc_id, $t['titulo'], $t['resumo'] ?? '', $i + 1, 'tema', $t );
	}
	// Tema que saiu do JSON vira rascunho (não apaga: o histórico de lidos guarda o id do post).
	foreach ( get_posts( array( 'post_type' => 'caderno', 'post_parent' => $disc_id, 'post_status' => 'publish', 'numberposts' => -1 ) ) as $p ) {
		if ( ! in_array( $p->post_name, $vistos, true ) ) {
			wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );
		}
	}
	return true;
}

/* Página /cadernos/ com [tp_cadernos], criada uma vez se não existir. */
function tpc_garante_hub() {
	if ( get_option( 'tpc_hub_criado' ) || ! tpc_arquivos() ) {
		return;
	}
	if ( ! get_page_by_path( 'cadernos' ) ) {
		wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Cadernos sistematizados',
			'post_name'    => 'cadernos',
			'post_content' => "<!-- wp:paragraph -->\n<p>Resumos organizados por disciplina e por tema, com esquemas, lei seca, jurisprudência, macetes e pegadinhas de banca. Gratuitos e atualizados.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[tp_cadernos]\n<!-- /wp:shortcode -->",
			'post_author'  => 1,
		) );
	}
	update_option( 'tpc_hub_criado', 1, false );
}

/* ---------------------------------------------------------------------------
 * 3. Endereços: tema sem página própria → âncora na página da disciplina (301)
 * ------------------------------------------------------------------------- */
add_action( 'template_redirect', function () {
	if ( ! is_singular( 'caderno' ) ) {
		return;
	}
	$id = get_queried_object_id();
	if ( tpc_eh_disciplina( $id ) ) {
		return;
	}
	if ( tpc_eh_embed() ) { // leitura no app: o tema sozinho, sem redirecionar para a âncora
		return;
	}
	$d   = tpc_dados( $id );
	$pai = wp_get_post_parent_id( $id );
	if ( empty( $d['pagina_propria'] ) && $pai ) {
		wp_safe_redirect( get_permalink( $pai ) . '#' . get_post_field( 'post_name', $id ), 301 );
		exit;
	}
} );
function tpc_link_tema( $p ) {
	$d = tpc_dados( $p->ID );
	return ! empty( $d['pagina_propria'] ) ? get_permalink( $p ) : get_permalink( $p->post_parent ) . '#' . $p->post_name;
}

/*
 * 0.8.1: [tp_caderno tema="direito-penal#peculato"] no card de conceito do julgado (o render.py põe o shortcode
 * quando o conceito tem "caderno" no JSON). Vira um <a href> real para o tema, renderizado no servidor, e só
 * quando o tema está publicado e escrito (tem "Em 30 segundos"); antes disso não imprime nada, para não mandar o
 * leitor a uma âncora inexistente. Aceita vários temas separados por vírgula. Assim o link aparece sozinho nos
 * julgados antigos quando o tema entra no caderno (o deploy limpa o cache do LiteSpeed).
 */
function tpc_tema_por_ref( $ref ) {
	$partes = array_map( 'sanitize_title', explode( '#', trim( $ref ), 2 ) );
	if ( 2 !== count( $partes ) || '' === $partes[0] || '' === $partes[1] ) {
		return null;
	}
	$disc = tpc_post_por( $partes[0], 0 );
	if ( ! $disc || 'publish' !== $disc->post_status ) {
		return null;
	}
	$tema = tpc_post_por( $partes[1], $disc->ID );
	return ( $tema && 'publish' === $tema->post_status && tpc_escrito( tpc_dados( $tema->ID ) ) ) ? $tema : null;
}
add_shortcode( 'tp_caderno', function ( $a ) {
	$a     = shortcode_atts( array( 'tema' => '' ), $a, 'tp_caderno' );
	$links = array();
	foreach ( array_filter( array_map( 'trim', explode( ',', (string) $a['tema'] ) ) ) as $ref ) {
		$t = tpc_tema_por_ref( $ref );
		if ( ! $t ) {
			continue;
		}
		// 0.9.1: mesmo link do casamento automático (seção 9), com prévia na página; o href continua real.
		foreach ( tpc_indice() as $i ) {
			if ( (int) $i['id'] === (int) $t->ID ) {
				$links[] = tpc_link_conceito( $i, true );
			}
		}
	}
	if ( $links ) {
		wp_enqueue_style( 'tp-cadernos', TPI_URL . 'assets/tpc.css', array(), TPI_VERSION );
	}
	return implode( '', $links );
} );

/* ---------------------------------------------------------------------------
 * 4. Robots e sitemap
 * ------------------------------------------------------------------------- */
function tpc_atual_nao_indexavel() {
	if ( tpc_eh_embed() ) {
		return true;
	}
	if ( is_singular( 'caderno' ) ) {
		return ! tpc_indexavel( get_queried_object_id() );
	}
	if ( is_page( 'cadernos' ) ) { // hub sem nenhuma disciplina indexável
		foreach ( get_posts( array( 'post_type' => 'caderno', 'post_parent' => 0, 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
			if ( tpc_indexavel( $id ) ) {
				return false;
			}
		}
		return true;
	}
	return false;
}
add_filter( 'rank_math/frontend/robots', function ( $r ) {
	if ( tpc_atual_nao_indexavel() ) {
		$r['index']  = 'noindex';
		$r['follow'] = 'follow';
	}
	return $r;
} );
add_filter( 'wp_robots', function ( $r ) {
	if ( tpc_atual_nao_indexavel() ) {
		unset( $r['index'] );
		$r['noindex'] = true;
		$r['follow']  = true;
	}
	return $r;
} );
add_action( 'init', function () {
	$o = get_option( 'rank-math-options-sitemap' );
	if ( ! is_array( $o ) || ( $o['pt_caderno_sitemap'] ?? '' ) === 'on' ) {
		return;
	}
	$o['pt_caderno_sitemap'] = 'on';
	update_option( 'rank-math-options-sitemap', $o );
	if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) && method_exists( '\\RankMath\\Sitemap\\Cache', 'invalidate_storage' ) ) {
		\RankMath\Sitemap\Cache::invalidate_storage();
	}
}, 99 );
add_filter( 'rank_math/sitemap/exclude_post_type', function ( $excluir, $tipo ) {
	return 'caderno' === $tipo ? false : $excluir;
}, 10, 2 );
add_filter( 'rank_math/sitemap/entry', function ( $url, $tipo, $obj ) {
	if ( 'post' === $tipo && is_object( $obj ) && isset( $obj->post_type ) && 'caderno' === $obj->post_type && ! tpc_indexavel( $obj->ID ) ) {
		return false;
	}
	return $url;
}, 10, 3 );

/* ---------------------------------------------------------------------------
 * 5. Montagem da página
 * ------------------------------------------------------------------------- */
function tpc_niveis() {
	return array( 'alta' => 'Alta incidência', 'media' => 'Média incidência', 'baixa' => 'Baixa incidência' );
}
/*
 * 0.12.1: parágrafo da lei seca. O artigo vai inteiro (ferramentas/conferir_lei.py reprova corte calado), então a
 * epígrafe ("Furto qualificado") ganha estilo de rubrica e o corte explícito "(...)" fica visível como corte.
 */
function tpc_lei_p( $para ) {
	if ( in_array( $para, array( '(...)', '[...]', '...' ), true ) ) {
		return '<p class="tpc-lei__corte" title="Trecho omitido; o artigo inteiro está na Lei seca do app">(...)</p>';
	}
	$disp = preg_match( '/^(Art\.|§|Pena\b|Par[áa]grafo|[IVXLC]+\s*[-–]|[a-z]\)|\()/u', $para );
	if ( ! $disp && mb_strlen( $para ) <= 160 && ! preg_match( '/[.;:,]$/u', $para ) ) {
		return '<p class="tpc-lei__epigrafe">' . esc_html( $para ) . '</p>';
	}
	return '<p>' . esc_html( $para ) . '</p>';
}

function tpc_html( $s ) {
	return wp_kses_post( (string) $s );
}

/* Mesmo marcador do informativo (tpi.js cuida do estado e do login). */
function tpc_botao_lido( $p, $disc_titulo, $fim = false ) {
	$svg = '<svg viewBox="0 0 24 24" width="17" height="17" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" ';
	return '<div class="tpi-lido tpi-lido--topo tpc-lido' . ( $fim ? ' tpc-lido--fim' : '' ) . '" data-tpi-id="' . esc_attr( $p->ID ) . '" data-tpi-tipo="caderno" data-tpi-titulo="' . esc_attr( $disc_titulo . ': ' . $p->post_title ) . '" data-tpi-url="' . esc_url( tpc_link_tema( $p ) ) . '" hidden>'
		. '<p class="tpi-lido__estado tpi-sr"' . ( $fim ? ' aria-hidden="true"' : ' role="status"' ) . '></p>' // 0.11.4: o do fim espelha o do topo; só um anuncia
		. '<button type="button" class="tpi-lido__botao" aria-pressed="false" aria-label="Marcar ' . esc_attr( $p->post_title ) . ' como lido" title="Marcar como lido">'
		. $svg . 'class="i-um" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>'
		. $svg . 'class="i-dois" stroke-width="2.4"><path d="M18 6 7 17l-5-5"/><path d="m22 10-7.5 7.5L13 16"/></svg>'
		. '<span class="tpi-lido__acao" aria-hidden="true">Marcar como lido</span></button>'
		. '<p class="tpi-lido__erro" role="status"></p>'
		. '<p class="tpi-lido__convite" hidden>Para salvar, <a href="' . esc_url( tpi_supabase()['appUrl'] ) . '">entre na sua conta do Tchau Pobreza</a>. Os temas que você marcar ficam guardados junto com o resto dos seus estudos.</p></div>';
}

function tpc_incidencia( $inc ) {
	if ( ! is_array( $inc ) || empty( $inc['nivel'] ) || empty( $inc['base'] ) ) {
		return ''; // sem amostra declarada não há selo
	}
	// Só Alta e Média: a base de incidência classifica parte dos itens, então pouca ocorrência não prova
	// que o tema cai pouco (regra da base em /incidencia, 10/11/2026). "Baixa" e "0 de N" nunca aparecem.
	$niveis = tpc_niveis();
	$nivel  = in_array( $inc['nivel'], array( 'alta', 'media' ), true ) ? $inc['nivel'] : '';
	if ( ! $nivel ) {
		return '';
	}
	$h = '<p class="tpc-freq"><span class="tpc-pill tpc-pill--' . $nivel . '">' . esc_html( $niveis[ $nivel ] ) . '</span> <span class="tpc-freq__base">' . esc_html( $inc['base'] ) . '</span>';
	if ( ! empty( $inc['bancas'] ) && is_array( $inc['bancas'] ) ) {
		$b = array();
		foreach ( $inc['bancas'] as $banca => $n ) {
			$b[] = esc_html( $banca ) . ' <b>' . (int) $n . '</b>';
		}
		$h .= ' <span class="tpc-freq__bancas">' . implode( ' · ', $b ) . '</span>';
	}
	return $h . '</p>';
}

function tpc_card( $p, $disc, $irmaos, $nivel_h = 2 ) {
	$t    = tpc_dados( $p->ID );
	$slug = $p->post_name;
	$hn   = 'h' . $nivel_h;
	$hb   = 'h' . ( $nivel_h + 1 );
	$escr = tpc_escrito( $t );
	$h    = '<article class="tpc-tema' . ( $escr ? '' : ' is-rascunho' ) . '" id="' . esc_attr( $slug ) . '" aria-labelledby="tpc-t-' . esc_attr( $slug ) . '">';
	$h   .= '<header class="tpc-tema__topo">';
	if ( ! empty( $t['base'] ) || ! empty( $t['grupo'] ) ) {
		$h .= '<p class="tpc-kicker">' . esc_html( implode( ' · ', array_filter( array( $t['grupo'] ?? '', $t['base'] ?? '' ) ) ) ) . '</p>';
	}
	$h .= '<' . $hn . ' id="tpc-t-' . esc_attr( $slug ) . '" class="tpc-tema__titulo">' . esc_html( $p->post_title ) . ' <a class="tpc-ancora" href="#' . esc_attr( $slug ) . '" aria-label="Link para este tema">#</a></' . $hn . '>';
	$h .= tpc_incidencia( $t['incidencia'] ?? null );
	if ( ! empty( $t['revisado_em'] ) ) {
		$cur = tpc_autor( tpc_dados( $disc->ID ) );
		$h  .= '<p class="tpc-revisado">Revisado por ' . esc_html( $cur['nome'] ) . ' em <time datetime="' . esc_attr( $t['revisado_em'] ) . '">' . esc_html( tpi_data_br( $t['revisado_em'] ) ) . '</time></p>';
	}
	$h .= tpc_botao_lido( $p, $disc->post_title );
	$h .= '</header>';

	if ( $escr ) {
		$h .= '<div class="tpc-30s"><p class="tpc-rotulo">Em 30 segundos</p>' . tpc_html( wpautop( $t['resumo'] ) ) . '</div>';
	} else {
		$h .= '<p class="tpc-aviso">Este tema está sendo escrito. Enquanto isso, a lei e a prática já estão disponíveis.</p>';
	}

	if ( ! empty( $t['esquema']['html'] ) ) {
		$h .= '<section class="tpc-bloco"><' . $hb . '>Esquema' . ( ! empty( $t['esquema']['rotulo'] ) ? ' <span class="tpc-tag">' . esc_html( $t['esquema']['rotulo'] ) . '</span>' : '' ) . '</' . $hb . '><div class="tpc-rolagem">' . tpc_html( $t['esquema']['html'] ) . '</div></section>';
	}

	if ( ! empty( $t['lei'] ) && is_array( $t['lei'] ) ) {
		$h .= '<section class="tpc-bloco"><' . $hb . '>Lei seca <span class="tpc-tag">texto oficial</span></' . $hb . '>';
		foreach ( $t['lei'] as $l ) {
			$paras = array_filter( array_map( 'trim', preg_split( "/\n\s*\n/", (string) ( $l['texto'] ?? '' ) ) ) );
			$h    .= '<blockquote class="tpc-lei">';
			foreach ( $paras as $para ) {
				$h .= tpc_lei_p( $para );
			}
			$h .= '</blockquote><p class="tpc-fonte">' . esc_html( $l['fonte'] ?? '' );
			if ( ! empty( $l['url'] ) ) {
				$h .= ' · <a href="' . esc_url( $l['url'] ) . '" rel="noopener" target="_blank">texto oficial</a>';
			}
			$h .= ' · <a href="' . esc_url( home_url( '/estudar/' ) ) . '">Ler na Lei seca do app</a></p>';
		}
		$h .= '</section>';
	}

	if ( ! empty( $t['macetes'] ) && is_array( $t['macetes'] ) ) {
		$h .= '<section class="tpc-bloco"><' . $hb . '>Macete</' . $hb . '><div class="tpc-macetes">';
		foreach ( $t['macetes'] as $m ) {
			$h .= '<div class="tpc-macete"><strong class="tpc-macete__sigla">' . esc_html( $m['sigla'] ?? '' ) . '</strong><span class="tpc-macete__texto">' . tpc_html( $m['texto'] ?? '' ) . '</span></div>';
		}
		$h .= '</div></section>';
	}

	if ( ! empty( $t['juris'] ) && is_array( $t['juris'] ) ) {
		$h .= '<section class="tpc-bloco"><' . $hb . '>Jurisprudência</' . $hb . '><ul class="tpc-juris">';
		foreach ( $t['juris'] as $j ) {
			$h .= '<li><span class="tpc-juris__quem">' . esc_html( $j['quem'] ?? '' ) . '</span><span>' . tpc_html( $j['texto'] ?? '' ) . '</span>';
			if ( ! empty( $j['url'] ) ) {
				/* 0.10.2: só o post do site é "julgado comentado"; link de tribunal diz que é a fonte oficial. */
				$interno = 0 === strpos( $j['url'], '/' ) || false !== strpos( $j['url'], wp_parse_url( home_url(), PHP_URL_HOST ) );
				$rotulo  = $j['link'] ?? ( $interno ? 'Ver julgado comentado' : 'Ver no site do tribunal' );
				$h      .= '<a href="' . esc_url( $j['url'] ) . '"' . ( $interno ? '' : ' rel="noopener"' ) . '>' . esc_html( $rotulo ) . '</a>';
			}
			$h .= '</li>';
		}
		$h .= '</ul></section>';
	}

	$pg = $t['pegadinha'] ?? null;
	if ( is_array( $pg ) && ! empty( $pg['afirmacao'] ) && in_array( $pg['gabarito'] ?? '', array( 'C', 'E' ), true ) ) {
		$gab = 'C' === $pg['gabarito'] ? 'Certo' : 'Errado';
		$h  .= '<section class="tpc-bloco"><' . $hb . '>Pegadinha de banca <span class="tpc-tag">Certo ou Errado</span></' . $hb . '>'
			. '<div class="tpc-ce" data-gab="' . esc_attr( $pg['gabarito'] ) . '"><p class="tpc-ce__afirmacao">' . tpc_html( $pg['afirmacao'] ) . '</p>'
			. '<details class="tpc-ce__gabarito"><summary>Ver gabarito</summary><p><strong>Gabarito: ' . $gab . '.</strong> ' . tpc_html( $pg['explicacao'] ?? '' ) . '</p></details></div></section>';
	}

	if ( ! empty( $t['aprofundamento'] ) ) {
		$h .= '<details class="tpc-fundo"><summary><span>Aprofundamento</span> <span class="tpc-fundo__sub">' . esc_html( $t['aprofundamento_rotulo'] ?? 'doutrina e divergências' ) . '</span></summary><div class="tpc-fundo__corpo">' . tpc_html( $t['aprofundamento'] ) . '</div></details>';
	}

	if ( $escr ) { // 0.11.4: o mesmo botão no fim do tema, onde a leitura termina; os dois andam juntos (mesmo data-tpi-id)
		$h .= tpc_botao_lido( $p, $disc->post_title, true );
	}

	$praticar = ! empty( $t['praticar'] ) ? $t['praticar'] : home_url( '/estudar/' );
	$h       .= '<p class="tpc-cta"><span><strong>Pratique ' . esc_html( mb_strtolower( $p->post_title ) ) . ' agora.</strong> Itens Certo/Errado no estilo da banca, com revisão espaçada. Grátis.</span><a class="tpc-cta__botao" href="' . esc_url( $praticar ) . '">Praticar este tema</a></p>';

	// Relacionados: os do JSON (slugs da mesma disciplina) ou, sem eles, os vizinhos de sumário.
	$rel  = array();
	$mapa = array();
	foreach ( $irmaos as $ir ) {
		$mapa[ $ir->post_name ] = $ir;
	}
	if ( ! empty( $t['relacionados'] ) && is_array( $t['relacionados'] ) ) {
		foreach ( $t['relacionados'] as $r ) {
			if ( isset( $mapa[ $r ] ) ) {
				$rel[] = $mapa[ $r ];
			}
		}
	} else {
		$i = array_search( $slug, array_keys( $mapa ), true );
		foreach ( array( $i - 1, $i + 1 ) as $k ) {
			$v = array_values( $mapa );
			if ( false !== $i && isset( $v[ $k ] ) ) {
				$rel[] = $v[ $k ];
			}
		}
	}
	if ( $rel ) {
		$h .= '<p class="tpc-rel"><span>Relacionados:</span>';
		foreach ( $rel as $r ) {
			$h .= ' <a href="' . esc_url( tpc_link_tema( $r ) ) . '">' . esc_html( $r->post_title ) . '</a>';
		}
		$h .= '</p>';
	}
	return $h . '</article>';
}

/* Autor: o do site (tpi_autor) ou o do JSON ("autor": {"nome","credenciais","url"}), para disciplinas fora do Direito. */
function tpc_autor( $d ) {
	$a = tpi_autor();
	if ( ! empty( $d['autor']['nome'] ) ) {
		$a = array( 'nome' => $d['autor']['nome'], 'credenciais' => $d['autor']['credenciais'] ?? '', 'url' => $d['autor']['url'] ?? '' );
	}
	return $a;
}

/* 0.9.1 (EEAT honesto, Jonatan 10/11): o caderno é da equipe, com curadoria do autor do site. "Revisado por" só
   aparece no tema que o curador leu de fato ("revisado_em" no tema). Com "autor" no JSON, vale o autor dado. */
function tpc_assinatura( $disc_id, $d ) {
	$a    = tpc_autor( $d );
	$nome = $a['url'] ? '<a href="' . esc_url( $a['url'] ) . '">' . esc_html( $a['nome'] ) . '</a>' : esc_html( $a['nome'] );
	$rev  = $d['revisado_em'] ?? '';
	$quem = ! empty( $d['autor']['nome'] )
		? 'Por <strong>' . $nome . '</strong>' . ( $a['credenciais'] ? ', ' . esc_html( $a['credenciais'] ) : '' )
		: 'Por <strong>Equipe Tchau Pobreza</strong>, com curadoria de <strong>' . $nome . '</strong>' . ( $a['credenciais'] ? ', ' . esc_html( $a['credenciais'] ) : '' );
	$h    = '<div class="tpi-assinatura"><p class="tpi-assinatura__autor">' . $quem . '.</p><p class="tpi-assinatura__datas">Atualizado em <time datetime="' . esc_attr( get_the_modified_date( 'c', $disc_id ) ) . '">' . esc_html( get_the_modified_date( 'd/m/Y', $disc_id ) ) . '</time>';
	if ( $rev ) {
		$h .= '. ' . esc_html( $d['revisado_rotulo'] ?? 'Conteúdo conferido' ) . ' em <time datetime="' . esc_attr( $rev ) . '">' . esc_html( tpi_data_br( $rev ) ) . '</time>';
	}
	return $h . '.</p></div>';
}

function tpc_pagina_disciplina( $id ) {
	$disc  = get_post( $id );
	$d     = tpc_dados( $id );
	$temas = tpc_temas( $id );
	$h     = '<div class="tpc alignwide">';
	$h    .= tpc_assinatura( $id, $d );
	if ( ! empty( $d['descricao'] ) ) {
		$h .= '<p class="tpc-lede">' . esc_html( $d['descricao'] ) . '</p>';
	}
	if ( ! $temas ) {
		return $h . '<p class="tpc-aviso">Os primeiros temas deste caderno entram em breve.</p></div>';
	}
	$ids = wp_list_pluck( $temas, 'ID' );
	$h  .= '<p class="tpi-progresso" data-tpi-progresso="' . esc_attr( implode( ',', $ids ) ) . '" data-tpi-nome="temas deste caderno" hidden><span class="tpi-progresso__barra" aria-hidden="true"><span></span></span><span class="tpi-progresso__texto"></span></p>';
	$h  .= '<div class="tpc-grade">';

	// Sumário (agrupado; cada tema com a cor da incidência e o marcador de lido).
	$h    .= '<nav class="tpc-sumario" aria-label="Sumário do caderno"><label class="tpc-rotulo" for="tpc-busca">Buscar no caderno</label><input id="tpc-busca" class="tpc-busca" type="search" placeholder="Ex.: ' . esc_attr( mb_strtolower( $temas[0]->post_title ) ) . '" autocomplete="off"><ol class="tpc-sumario__lista">';
	$grupo = null;
	foreach ( $temas as $p ) {
		$t = tpc_dados( $p->ID );
		$g = (string) ( $t['grupo'] ?? '' );
		if ( $g !== $grupo && '' !== $g ) {
			$h .= '<li class="tpc-sumario__grupo">' . esc_html( $g ) . '</li>';
		}
		$grupo = $g;
		$nv    = $t['incidencia']['nivel'] ?? '';
		$busca = remove_accents( mb_strtolower( $p->post_title . ' ' . ( $t['base'] ?? '' ) . ' ' . implode( ' ', (array) ( $t['palavras'] ?? array() ) ) ) );
		$h    .= '<li><a href="#' . esc_attr( $p->post_name ) . '" data-tpc-busca="' . esc_attr( $busca ) . '" data-tpc-lido="' . esc_attr( $p->ID ) . '"' . ( tpc_escrito( $t ) ? '' : ' class="is-rascunho"' ) . '>'
			. '<span class="tpc-sumario__nome">' . esc_html( $p->post_title ) . '</span>'
			. ( isset( tpc_niveis()[ $nv ] ) ? '<i class="tpc-ponto tpc-ponto--' . esc_attr( $nv ) . '" title="' . esc_attr( tpc_niveis()[ $nv ] ) . '"></i><span class="tpi-sr"> (' . esc_html( tpc_niveis()[ $nv ] ) . ')</span>' : '' )
			. '<span class="tpc-sumario__lido" hidden></span></a></li>';
	}
	$h .= '</ol><p class="tpc-sumario__vazio" hidden>Nenhum tema com esse termo.</p></nav>';

	$h .= '<div class="tpc-temas">';
	foreach ( $temas as $p ) {
		$h .= tpc_card( $p, $disc, $temas );
	}
	$h .= '</div></div>';

	// Rodapé: fontes, aviso de mudança da lei, links para os informativos do ramo.
	$juridico = false;
	foreach ( $temas as $p ) {
		$t        = tpc_dados( $p->ID );
		$juridico = $juridico || ! empty( $t['lei'] ) || ! empty( $t['juris'] );
	}
	$h .= '<footer class="tpc-rodape">';
	if ( ! empty( $d['fontes'] ) ) {
		$h .= '<p><strong>Fontes:</strong> ' . tpc_html( $d['fontes'] ) . '</p>';
	} elseif ( $juridico ) {
		$h .= '<p><strong>Fontes:</strong> textos legais das bases oficiais (Planalto e Câmara dos Deputados); súmulas, teses e julgados dos sites do STF e do STJ.</p><p><strong>A lei muda.</strong> Cada caderno mostra quando o conteúdo foi conferido.</p>';
	}
	$h .= '<p>Achou algo errado ou desatualizado? Avise em contato@tchaupobreza.com.br.</p>';
	if ( ! empty( $d['ramo'] ) ) {
		$t = get_term_by( 'slug', $d['ramo'], 'ramo_direito' );
		if ( $t && ! is_wp_error( $t ) ) {
			$h .= '<p>Veja também os <a href="' . esc_url( get_term_link( $t ) ) . '">julgados comentados de ' . esc_html( $t->name ) . '</a>.</p>';
		}
	}
	return $h . '</footer></div>';
}

function tpc_pagina_tema( $id ) {
	$p   = get_post( $id );
	$pai = get_post( $p->post_parent );
	$h   = '<div class="tpc tpc--solo">' . tpc_card( $p, $pai, tpc_temas( $pai->ID ), 2 );
	return $h . '<p class="tpc-voltar"><a href="' . esc_url( get_permalink( $pai ) ) . '">Ver o ' . esc_html( $pai->post_title ) . ' completo</a></p></div>';
}

add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'caderno' ) || get_the_ID() !== get_queried_object_id() ) {
		return $content;
	}
	$id = get_the_ID();
	return tpc_eh_disciplina( $id ) ? tpc_pagina_disciplina( $id ) : tpc_pagina_tema( $id );
}, 20 );

/* 0.11.4: caderno indexável ligado a um ramo dos informativos (campo "ramo" do JSON), para o link de volta. */
function tpc_caderno_do_ramo( $ramo ) {
	foreach ( get_posts( array( 'post_type' => 'caderno', 'post_parent' => 0, 'post_status' => 'publish', 'numberposts' => -1 ) ) as $p ) {
		$d = tpc_dados( $p->ID );
		if ( ( $d['ramo'] ?? '' ) === $ramo && tpc_indexavel( $p->ID ) ) {
			return $p;
		}
	}
	return null;
}

/* Hub: [tp_cadernos] lista as disciplinas com o número de temas e o progresso de leitura.
   0.11.4: só as indexáveis (com tpc_min_index() temas escritos). As outras seguem no ar para quem chega
   pelo link do informativo, mas não entram na vitrine enquanto estão rasas. */
add_shortcode( 'tp_cadernos', function () {
	$discs = array_values( array_filter( get_posts( array( 'post_type' => 'caderno', 'post_parent' => 0, 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'menu_order title', 'order' => 'ASC' ) ), function ( $p ) {
		return tpc_indexavel( $p->ID );
	} ) );
	if ( ! $discs ) {
		return '<p class="tpi-vazio">Os primeiros cadernos entram aqui em breve.</p>';
	}
	$h = '<ul class="tpc-hub">';
	foreach ( $discs as $p ) {
		$d   = tpc_dados( $p->ID );
		$ids = wp_list_pluck( tpc_temas( $p->ID ), 'ID' );
		$h  .= '<li class="tpc-hub__item tpi-edicao"' . ( $ids ? ' data-tpi-ids="' . esc_attr( implode( ',', $ids ) ) . '"' : '' ) . '><a href="' . esc_url( get_permalink( $p ) ) . '"><span class="tpc-hub__nome">' . esc_html( $d['disciplina'] ?? $p->post_title ) . '</span><span class="tpc-hub__info">' . esc_html( sprintf( _n( '%d tema', '%d temas', count( $ids ) ), count( $ids ) ) ) . '</span>'
			. ( $ids ? '<span class="tpi-edicao__lidos" hidden></span>' : '' ) . '</a></li>';
	}
	return $h . '</ul>';
} );

/* ---------------------------------------------------------------------------
 * 6. Tema do site: modelo próprio, trilha e classes
 * ------------------------------------------------------------------------- */
add_action( 'init', function () {
	if ( ! function_exists( 'register_block_template' ) ) {
		return;
	}
	register_block_template(
		'tp-informativos//single-caderno',
		array(
			'title'       => 'Caderno',
			'description' => 'Páginas dos cadernos sistematizados (disciplina e tema).',
			'post_types'  => array( 'caderno' ),
			'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--50)">
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|40","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--60)">
<!-- wp:post-title {"level":1,"align":"wide"} /-->
<!-- wp:post-content {"align":"full","layout":{"type":"constrained"}} /-->
</div>
<!-- /wp:group -->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
		)
	);
} );

add_filter( 'render_block_core/post-title', function ( $html, $block ) {
	static $feito = false;
	if ( $feito || ! is_singular( 'caderno' ) || ! empty( $block['attrs']['isLink'] ) ) {
		return $html;
	}
	$feito = true;
	$itens = array( '<a href="' . esc_url( home_url( '/cadernos/' ) ) . '">Cadernos</a>' );
	foreach ( array_reverse( get_post_ancestors( get_queried_object_id() ) ) as $anc ) {
		$itens[] = '<a href="' . esc_url( get_permalink( $anc ) ) . '">' . esc_html( get_the_title( $anc ) ) . '</a>';
	}
	return '<nav class="tpi-trilha alignwide" aria-label="Trilha de navegação"><ol><li>' . implode( '</li><li>', $itens ) . '</li></ol></nav>' . $html;
}, 10, 2 );

add_filter( 'body_class', function ( $c ) {
	if ( is_singular( 'caderno' ) ) {
		$c[] = 'tpi-pagina';
		$c[] = 'tpc-pagina';
	}
	return $c;
} );

/* ---------------------------------------------------------------------------
 * 7. Dados estruturados: WebPage + Article + BreadcrumbList (sem FAQPage: o Google restringiu esse
 *    resultado a sites de governo e saúde em 2023)
 * ------------------------------------------------------------------------- */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'caderno' ) || ! apply_filters( 'tpi_schema_ativo', true ) ) {
		return;
	}
	$id    = get_queried_object_id();
	$d     = tpc_dados( $id );
	$a     = tpc_autor( tpc_eh_disciplina( $id ) ? $d : tpc_dados( wp_get_post_parent_id( $id ) ) );
	$pessoa = array( '@type' => 'Person', 'name' => $a['nome'], 'url' => $a['url'] );
	$org    = array( '@type' => 'Organization', 'name' => 'Equipe Tchau Pobreza', 'url' => home_url( '/' ) );
	$dd     = tpc_eh_disciplina( $id ) ? $d : tpc_dados( wp_get_post_parent_id( $id ) );
	$autor  = ! empty( $dd['autor']['nome'] ) ? $pessoa : $org;
	$url   = get_permalink( $id );
	$page  = array( '@type' => 'WebPage', '@id' => $url, 'url' => $url, 'name' => wp_strip_all_tags( get_the_title( $id ) ), 'inLanguage' => 'pt-BR' );
	if ( ! tpc_eh_disciplina( $id ) && ! empty( $d['revisado_em'] ) ) { // revisão humana só no tema lido de fato
		$page['reviewedBy']   = $pessoa;
		$page['lastReviewed'] = $d['revisado_em'];
	}
	$art = array(
		'@type'               => 'Article',
		'@id'                 => $url . '#article',
		'headline'            => wp_strip_all_tags( get_the_title( $id ) ),
		'description'         => $d['descricao'] ?? wp_strip_all_tags( (string) ( $d['resumo'] ?? '' ) ),
		'datePublished'       => get_post_time( 'c', true, $id ),
		'dateModified'        => get_post_modified_time( 'c', true, $id ),
		'author'              => $autor,
		'editor'              => $pessoa,
		'publisher'           => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
		'mainEntityOfPage'    => $url,
		'isPartOf'            => array( '@id' => $url ),
		'inLanguage'          => 'pt-BR',
		'isAccessibleForFree' => true,
	);
	if ( tpc_eh_disciplina( $id ) ) {
		$art['about'] = array( '@type' => 'Thing', 'name' => $d['disciplina'] ?? get_the_title( $id ) );
		$partes       = array();
		foreach ( tpc_temas( $id ) as $p ) {
			if ( tpc_escrito( tpc_dados( $p->ID ) ) ) {
				$partes[] = array( '@type' => 'WebPageElement', 'name' => $p->post_title, 'url' => tpc_link_tema( $p ) );
			}
		}
		if ( $partes ) {
			$art['hasPart'] = $partes;
		}
	}
	$itens = array( array( 'name' => 'Cadernos', 'url' => home_url( '/cadernos/' ) ) );
	foreach ( array_reverse( get_post_ancestors( $id ) ) as $anc ) {
		$itens[] = array( 'name' => get_the_title( $anc ), 'url' => get_permalink( $anc ) );
	}
	$itens[] = array( 'name' => get_the_title( $id ), 'url' => $url );
	$bc      = array( '@type' => 'BreadcrumbList', 'itemListElement' => array() );
	foreach ( $itens as $i => $it ) {
		$bc['itemListElement'][] = array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => wp_strip_all_tags( $it['name'] ), 'item' => $it['url'] );
	}
	echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => array( $page, $art, $bc ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 30 );

/* Descrição para o Rank Math quando ninguém escreveu uma no painel. */
add_filter( 'rank_math/frontend/description', function ( $desc ) {
	if ( '' !== (string) $desc || ! is_singular( 'caderno' ) ) {
		return $desc;
	}
	$d = tpc_dados( get_queried_object_id() );
	return wp_strip_all_tags( (string) ( $d['descricao'] ?? $d['resumo'] ?? '' ) );
} );

/* ---------------------------------------------------------------------------
 * 8. Assets
 * ------------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {
	$conteudo = is_singular() ? (string) get_post_field( 'post_content', get_queried_object_id() ) : '';
	if ( ! is_singular( 'caderno' ) && ! has_shortcode( $conteudo, 'tp_cadernos' ) ) {
		return;
	}
	wp_enqueue_style( 'tp-informativos', TPI_URL . 'assets/tpi.css', array(), TPI_VERSION );
	wp_enqueue_style( 'tp-cadernos', TPI_URL . 'assets/tpc.css', array( 'tp-informativos' ), TPI_VERSION );
	$cfg = tpi_supabase();
	wp_enqueue_script( 'tp-informativos', TPI_URL . 'assets/tpi.js', array(), TPI_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
	wp_localize_script( 'tp-informativos', 'TPI', array( 'url' => untrailingslashit( $cfg['url'] ), 'key' => $cfg['key'], 'appUrl' => $cfg['appUrl'], 'google' => $cfg['google'] ?? '', 'signup' => ! empty( $cfg['signup'] ), 'rest' => esc_url_raw( rest_url() ) ) );
}, 11 );

/* ---------------------------------------------------------------------------
 * 9. Link dos informativos para os cadernos (0.9.1, decisão do Jonatan em 10/11/2026)
 *    No fim de cada "Conceito de base" do julgado (core/details com a classe tpi-conceito), quando o título do
 *    conceito cita um tema escrito do caderno, entra um link real <a href> para a âncora do tema. Com JavaScript,
 *    o clique abre uma prévia na própria página (gaveta no computador, folha de baixo no celular); o href continua
 *    sendo o do caderno. O conceito fica no julgado: nada sai da página nem é copiado do caderno.
 *    Marcação explícita ([tp_caderno tema="disc#tema"], seção 3) vence. Sem ela, casamento automático: título do tema
 *    ou um dos "conceitos" do JSON no título do conceito, palavra inteira, sem acento; vence o termo mais longo.
 *    Assim os julgados antigos, sem marcação, também ganham o link quando o tema entra no caderno.
 * ------------------------------------------------------------------------- */
function tpc_norm( $s ) {
	$s = remove_accents( mb_strtolower( wp_strip_all_tags( html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' ) ) ) );
	return ' ' . trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) ) . ' ';
}
function tpc_indice() {
	$idx = get_transient( 'tpc_indice_v2' );
	if ( is_array( $idx ) ) {
		return $idx;
	}
	$idx = array();
	foreach ( get_posts( array( 'post_type' => 'caderno', 'post_status' => 'publish', 'numberposts' => -1, 'post_parent__not_in' => array( 0 ), 'orderby' => 'menu_order', 'order' => 'ASC' ) ) as $p ) {
		$t = tpc_dados( $p->ID );
		if ( ! tpc_escrito( $t ) || 'publish' !== get_post_status( $p->post_parent ) ) {
			continue;
		}
		$chaves = array();
		foreach ( array_merge( array( $p->post_title ), (array) ( $t['conceitos'] ?? array() ) ) as $c ) {
			$n = trim( tpc_norm( $c ) );
			if ( strlen( $n ) >= 4 ) {
				$chaves[] = $n;
			}
		}
		$tem = array();
		if ( ! empty( $t['esquema']['html'] ) ) {
			$tem[] = 'esquema';
		}
		if ( ! empty( $t['lei'] ) ) {
			$tem[] = 'lei seca';
		}
		if ( ! empty( $t['juris'] ) ) {
			$tem[] = 'jurisprudência';
		}
		if ( ! empty( $t['pegadinha'] ) ) {
			$tem[] = 'pegadinha';
		}
		$ult = array_pop( $tem );
		$idx[] = array(
			'id'     => $p->ID,
			'titulo' => $p->post_title,
			'chaves' => $chaves,
			'url'    => tpc_link_tema( $p ),
			'disc'   => get_the_title( $p->post_parent ),
			'grupo'  => (string) ( $t['grupo'] ?? '' ),
			'tem'    => $tem ? implode( ', ', $tem ) . ' e ' . $ult : (string) $ult,
			// 0.11.0: ligação com o app (tópico da taxonomia e dispositivo do acervo) e endereço do próprio tema, que abre embutido.
			'tax'    => array_values( array_filter( array_map( 'strval', (array) ( $t['tax'] ?? array() ) ) ) ),
			'dis'    => array_values( array_filter( array_map( 'strval', (array) ( $t['dispositivos'] ?? array() ) ) ) ),
			'post'   => get_permalink( $p ),
		);
	}
	set_transient( 'tpc_indice_v2', $idx, DAY_IN_SECONDS );
	return $idx;
}
function tpc_tema_para( $texto ) {
	$alvo   = tpc_norm( $texto );
	$melhor = null;
	$tam    = 0;
	foreach ( tpc_indice() as $t ) {
		foreach ( $t['chaves'] as $c ) {
			if ( strlen( $c ) > $tam && false !== strpos( $alvo, ' ' . $c . ' ' ) ) {
				$melhor = $t;
				$tam    = strlen( $c );
			}
		}
	}
	return $melhor;
}
function tpc_link_conceito( $t, $com_tema = false ) {
	$meta = implode( ' · ', array_filter( array( $t['grupo'], $t['tem'] ) ) );
	return '<p class="tpi-conceito__caderno tpc-ir"><a class="tpc-ir__link" href="' . esc_url( $t['url'] ) . '" data-tpc-previa="' . esc_attr( $t['id'] ) . '">'
		. '<span class="tpc-ir__icone" aria-hidden="true"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 7v14"/><path d="M3 18a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h5a4 4 0 0 1 4 4 4 4 0 0 1 4-4h5a1 1 0 0 1 1 1v13a1 1 0 0 1-1 1h-6a3 3 0 0 0-3 3 3 3 0 0 0-3-3z"/></svg></span>'
		. '<span>Aprofundar no ' . esc_html( $t['disc'] . ( $com_tema ? ': ' . $t['titulo'] : '' ) ) . ' <span aria-hidden="true">→</span></span></a>'
		. ( $meta ? '<span class="tpc-ir__meta">' . esc_html( $meta ) . '</span>' : '' ) . '</p>';
}
add_filter( 'render_block_core/details', function ( $html, $block ) {
	if ( ! is_singular( 'informativo' ) || false === strpos( (string) ( $block['attrs']['className'] ?? '' ), 'tpi-conceito' ) ) {
		return $html;
	}
	// Conceito já marcado com [tp_caderno] pelo projeto dos informativos: a marcação explícita vence.
	if ( false !== strpos( $html, 'tpi-conceito__caderno' ) || false !== strpos( $html, '[tp_caderno' ) ) {
		return $html;
	}
	if ( ! preg_match( '#<summary[^>]*>(.*?)</summary>#s', $html, $m ) ) {
		return $html;
	}
	$t = tpc_tema_para( $m[1] );
	if ( ! $t ) {
		return $html;
	}
	wp_enqueue_style( 'tp-cadernos', TPI_URL . 'assets/tpc.css', array(), TPI_VERSION );
	$pos = strrpos( $html, '</details>' );
	return false === $pos ? $html : substr( $html, 0, $pos ) . tpc_link_conceito( $t ) . substr( $html, $pos );
}, 10, 2 );

/* Prévia: só os blocos de leitura rápida; o resto fica no caderno. */
function tpc_previa_html( $p ) {
	$t   = tpc_dados( $p->ID );
	$pai = get_post( $p->post_parent );
	$h   = '<div class="tpc tpc-previa">';
	if ( ! empty( $t['base'] ) || ! empty( $t['grupo'] ) ) {
		$h .= '<p class="tpc-kicker">' . esc_html( implode( ' · ', array_filter( array( $t['grupo'] ?? '', $t['base'] ?? '' ) ) ) ) . '</p>';
	}
	$h .= '<h2 class="tpc-tema__titulo" id="tpc-previa-titulo">' . esc_html( $p->post_title ) . '</h2>';
	$h .= tpc_incidencia( $t['incidencia'] ?? null );
	$h .= tpc_botao_lido( $p, $pai->post_title );
	$h .= '<div class="tpc-30s"><p class="tpc-rotulo">Em 30 segundos</p>' . tpc_html( wpautop( $t['resumo'] ?? '' ) ) . '</div>';
	if ( ! empty( $t['esquema']['html'] ) ) {
		$h .= '<div class="tpc-rolagem">' . tpc_html( $t['esquema']['html'] ) . '</div>';
	}
	if ( ! empty( $t['lei'][0]['texto'] ) ) {
		$h .= '<blockquote class="tpc-lei">';
		foreach ( array_filter( array_map( 'trim', preg_split( "/\n\s*\n/", (string) $t['lei'][0]['texto'] ) ) ) as $para ) {
			$h .= tpc_lei_p( $para );
		}
		$h .= '</blockquote>';
	}
	return $h . '</div>';
}
add_action( 'rest_api_init', function () {
	register_rest_route( 'tp/v1', '/caderno-previa/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true', // só conteúdo publicado e público
		'callback'            => function ( WP_REST_Request $req ) {
			$p = get_post( (int) $req['id'] );
			if ( ! $p || 'caderno' !== $p->post_type || 'publish' !== $p->post_status || ! $p->post_parent || ! tpc_escrito( tpc_dados( $p->ID ) ) ) {
				return new WP_Error( 'tpc_invalido', 'Tema não encontrado.', array( 'status' => 404 ) );
			}
			$t   = tpc_dados( $p->ID );
			$res = rest_ensure_response( array(
				'html'     => tpc_previa_html( $p ),
				'url'      => tpc_link_tema( $p ),
				'caderno'  => get_the_title( $p->post_parent ),
				'praticar' => ! empty( $t['praticar'] ) ? $t['praticar'] : home_url( '/estudar/' ),
			) );
			$res->header( 'Cache-Control', 'public, max-age=600' );
			return $res;
		},
	) );
} );

/* ---------------------------------------------------------------------------
 * 10. Leitura no app (0.11.0, decisão do Jonatan em 10/11/2026: "contextual em fases")
 *     O Estudo inteligente mostra "Ler o resumo" num tópico novo e "Rever teoria" depois de um erro, abrindo o tema
 *     num painel do app (o mesmo do julgado comentado). Duas peças, nenhuma no Supabase:
 *     - GET tp/v1/cadernos-indice: temas escritos com os campos tax e dispositivos do JSON, leve e com cache;
 *     - ?tp_embed=1 no endereço do tema: só o card, sem cabeçalho e rodapé do site, fora do índice (noindex + X-Robots-Tag),
 *       com o canonical na âncora do caderno. O conteúdo continua num lugar só (o JSON), sem cópia no app.
 * ------------------------------------------------------------------------- */
function tpc_eh_embed() {
	if ( ! isset( $_GET['tp_embed'] ) || '1' !== $_GET['tp_embed'] || ! is_singular( 'caderno' ) ) {
		return false;
	}
	return ! tpc_eh_disciplina( get_queried_object_id() );
}
add_action( 'template_redirect', function () {
	if ( tpc_eh_embed() && ! headers_sent() ) {
		header( 'X-Robots-Tag: noindex, follow', true );
	}
}, 5 );
add_filter( 'rank_math/frontend/canonical', function ( $c ) {
	return tpc_eh_embed() ? tpc_link_tema( get_post( get_queried_object_id() ) ) : $c;
} );
add_filter( 'body_class', function ( $c ) {
	if ( tpc_eh_embed() ) {
		$c[] = 'tp-embed';
	}
	return $c;
} );
add_action( 'wp', function () {
	if ( ! tpc_eh_embed() ) {
		return;
	}
	add_filter( 'show_admin_bar', '__return_false' );
	remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
	remove_action( 'wp_print_styles', 'print_emoji_styles' );
	remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
} );
add_action( 'wp_enqueue_scripts', function () {
	if ( tpc_eh_embed() ) {
		wp_dequeue_script( 'hostinger-reach-embed' );
	}
}, 999 );
add_action( 'wp_head', function () {
	if ( ! tpc_eh_embed() ) {
		return;
	}
	// Links do tema (julgados, lei, caderno completo) abrem fora do painel. O botão "Praticar" some: o aluno já está no app.
	echo '<base target="_blank">' . "\n";
	echo '<style id="tpc-embed">body.tp-embed header.wp-block-template-part,body.tp-embed footer.wp-block-template-part,body.tp-embed .tpi-trilha,body.tp-embed .tpc-cta,body.tp-embed .tpc-ancora,body.tp-embed .wp-block-post-title,#wpadminbar{display:none!important}html{margin-top:0!important}body.tp-embed main{margin-top:0!important;padding-top:16px!important}</style>' . "\n";
}, 1 );

add_action( 'rest_api_init', function () {
	register_rest_route( 'tp/v1', '/cadernos-indice', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true', // só temas publicados e escritos
		'callback'            => function () {
			$items = array();
			foreach ( tpc_indice() as $i ) {
				if ( empty( $i['tax'] ) && empty( $i['dis'] ) ) {
					continue;
				}
				$items[] = array(
					'i' => (int) $i['id'], // 0.12.6: o lido do aluno é o registro "informativo:<i>" (o mesmo do botão Marcar como lido)
					'u' => $i['post'],  // abre embutido (?tp_embed=1)
					'a' => $i['url'],   // "Abrir no site": âncora no caderno
					't' => $i['titulo'],
					'c' => $i['disc'],
					'g' => $i['grupo'],
					'x' => $i['tax'],
					'd' => $i['dis'],
				);
			}
			$res = rest_ensure_response( array( 'v' => 1, 'items' => $items ) );
			// O transient (limpo a cada sincronização) deixa a resposta barata; navegador e CDN seguram por 1 h.
			$res->header( 'Cache-Control', 'public, max-age=3600' );
			$res->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			return $res;
		},
	) );
} );
