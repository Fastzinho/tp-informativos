<?php
/**
 * 0.18.0: Páginas de concurso (/concursos/<slug>/): guia de estudos + edital verticalizado.
 *
 * Piloto aprovado pelo Jonatan em 17/11/2026 (thread "Criar páginas de estudo por concurso"), com 5 concursos.
 * Página de ESTUDO, não de notícia: ficha curta e datada do edital (banca, vagas, inscrições, prova, edital),
 * "Como estudar para X" em 4 passos gerados dos dados do edital + 1 parágrafo escrito à mão sobre a banca,
 * e o edital verticalizado com as colunas T/Q/R (teoria, questões, revisão), salvas no aparelho (localStorage).
 *
 * Fontes:
 * - concursos/<slug>.json: parte editorial (revisada no PR). Formato em concursos/LEIA-ME.md.
 * - Questões por tópico e texto do edital: concursos.json gravado pelo deploy do app (plugin tchau-pobreza,
 *   app/concursos.json, gerado por scripts/coverage/concursos.ts). Quando entram questões no app, a página muda
 *   sozinha no próximo deploy dele. Se o arquivo do app não existir, vale a cópia concursos/dados-app.json.
 * - concursos/links.json: tópico do edital → tema do caderno.
 *
 * Indexação: só com pelo menos tpcon_min_index() dos tópicos com questões no app (antes, noindex,follow e fora do
 * sitemap), contra página rasa (política de scaled content).
 * Vocabulário (Jonatan 17/11): "questões", nunca "Certo/Errado"; sem "grátis" nos textos.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPCON_DIR', dirname( __DIR__ ) . '/concursos' );

function tpcon_min_index() {
	return (float) apply_filters( 'tpcon_min_index', 0.6 );
}

/* ---------------------------------------------------------------------------
 * 1. Tipo de conteúdo
 * ------------------------------------------------------------------------- */
add_action( 'init', function () {
	register_post_type(
		'concurso',
		array(
			'labels'       => array(
				'name'          => 'Concursos',
				'singular_name' => 'Concurso',
				'all_items'     => 'Todos',
				'edit_item'     => 'Editar',
			),
			'description'  => 'Guias de estudo por concurso. O conteúdo vem de concursos/*.json no plugin e do deploy do app.',
			'public'       => true,
			'hierarchical' => false,
			'has_archive'  => false, // o hub /concursos/ é uma Página comum com [tp_concursos]
			'rewrite'      => array( 'slug' => 'concursos', 'with_front' => false ),
			'menu_icon'    => 'dashicons-clipboard',
			'show_in_rest' => true,
			'rest_base'    => 'concursos',
			'supports'     => array( 'title', 'editor', 'excerpt', 'revisions', 'custom-fields', 'author' ),
		)
	);
	foreach ( array( 'tpcon_hash', 'tpcon_dados' ) as $k ) {
		register_post_meta( 'concurso', $k, array( 'type' => 'string', 'single' => true, 'show_in_rest' => true, 'auth_callback' => function () {
			return current_user_can( 'edit_posts' );
		} ) );
	}
} );

function tpcon_dados( $id ) {
	$d = json_decode( (string) get_post_meta( $id, 'tpcon_dados', true ), true );
	return is_array( $d ) ? $d : array();
}

/* ---------------------------------------------------------------------------
 * 2. Dados do app (questões por tópico e texto do edital)
 * ------------------------------------------------------------------------- */
function tpcon_arquivo_app() {
	$app = WP_PLUGIN_DIR . '/tchau-pobreza/app/concursos.json';
	$app = apply_filters( 'tpcon_arquivo_app', $app );
	return is_readable( $app ) ? $app : TPCON_DIR . '/dados-app.json';
}

/* Plano do app ($id = id do plano no catálogo, ex.: pmpe). Guardado em transient pela data do arquivo. */
function tpcon_plano( $id ) {
	static $mem = array();
	if ( isset( $mem[ $id ] ) ) {
		return $mem[ $id ];
	}
	$f     = tpcon_arquivo_app();
	$chave = 'tpcon_' . md5( $f . '|' . (string) @filemtime( $f ) . '|' . $id );
	$p     = get_transient( $chave );
	if ( ! is_array( $p ) ) {
		$todos = json_decode( (string) @file_get_contents( $f ), true );
		$p     = array();
		if ( isset( $todos['planos'][ $id ] ) ) {
			$p           = $todos['planos'][ $id ];
			$p['gerado'] = $todos['gerado'] ?? '';
			$p['pronto'] = (int) ( $todos['pronto'] ?? 3 );
		}
		if ( ! $p && TPCON_DIR . '/dados-app.json' !== $f ) { // plano novo ainda não está no app instalado
			$reserva = json_decode( (string) @file_get_contents( TPCON_DIR . '/dados-app.json' ), true );
			if ( isset( $reserva['planos'][ $id ] ) ) {
				$p           = $reserva['planos'][ $id ];
				$p['gerado'] = $reserva['gerado'] ?? '';
				$p['pronto'] = (int) ( $reserva['pronto'] ?? 3 );
			}
		}
		set_transient( $chave, $p, DAY_IN_SECONDS );
	}
	return $mem[ $id ] = $p;
}

function tpcon_cobertura( $plano ) {
	$total = 0;
	$com   = 0;
	$pr    = (int) ( $plano['pronto'] ?? 3 );
	foreach ( $plano['disciplinas'] ?? array() as $d ) {
		foreach ( $d['topicos'] as $t ) {
			$total++;
			$com += ( (int) $t['questoes'] >= $pr ) ? 1 : 0;
		}
	}
	return array( $com, $total );
}

function tpcon_indexavel( $id ) {
	$d     = tpcon_dados( $id );
	$plano = tpcon_plano( $d['plano'] ?? '' );
	list( $com, $total ) = tpcon_cobertura( $plano );
	return $total > 0 && $com / $total >= tpcon_min_index();
}

/* ---------------------------------------------------------------------------
 * 3. Sincronização: concursos/<slug>.json → posts (mesmo esquema dos cadernos)
 * ------------------------------------------------------------------------- */
function tpcon_arquivos() {
	$a = glob( TPCON_DIR . '/*.json' );
	return array_values( array_filter( $a ? $a : array(), function ( $f ) {
		return ! in_array( basename( $f ), array( 'dados-app.json', 'links.json' ), true );
	} ) );
}
add_action( 'init', function () {
	$s = TPI_VERSION;
	foreach ( tpcon_arquivos() as $f ) {
		$s .= '|' . basename( $f ) . ':' . filesize( $f ) . ':' . filemtime( $f );
	}
	$sig = md5( $s );
	if ( get_option( 'tpcon_sync_assinatura' ) === $sig || get_transient( 'tpcon_sync_trava' ) ) {
		return;
	}
	set_transient( 'tpcon_sync_trava', 1, 2 * MINUTE_IN_SECONDS );
	$ok     = true;
	$vistos = array();
	foreach ( tpcon_arquivos() as $f ) {
		$slug = tpcon_sincroniza( $f );
		$ok   = $slug && $ok;
		if ( $slug ) {
			$vistos[] = $slug;
		}
	}
	if ( $ok ) {
		foreach ( get_posts( array( 'post_type' => 'concurso', 'post_status' => 'publish', 'numberposts' => -1 ) ) as $p ) {
			if ( ! in_array( $p->post_name, $vistos, true ) ) {
				wp_update_post( array( 'ID' => $p->ID, 'post_status' => 'draft' ) );
			}
		}
		update_option( 'tpcon_sync_assinatura', $sig, true );
	}
	tpcon_garante_hub();
	delete_transient( 'tpcon_sync_trava' );
}, 31 );

function tpcon_titulo( $c ) {
	return 'Guia de estudos ' . $c['sigla'] . ( ! empty( $c['ano'] ) ? ' ' . $c['ano'] : '' ) . ': ' . $c['cargo'];
}

function tpcon_sincroniza( $arquivo ) {
	$c = json_decode( (string) file_get_contents( $arquivo ), true );
	if ( ! is_array( $c ) || empty( $c['slug'] ) || empty( $c['plano'] ) || empty( $c['sigla'] ) || empty( $c['cargo'] ) ) {
		return '';
	}
	$slug   = sanitize_title( $c['slug'] );
	$titulo = tpcon_titulo( $c );
	$json   = wp_json_encode( $c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	$hash   = md5( $titulo . '|' . $json );
	$q      = get_posts( array( 'post_type' => 'concurso', 'post_status' => array( 'publish', 'draft', 'private' ), 'name' => $slug, 'numberposts' => 1 ) );
	$p      = $q ? $q[0] : null;
	if ( $p && get_post_meta( $p->ID, 'tpcon_hash', true ) === $hash && 'publish' === $p->post_status ) {
		return $slug;
	}
	$post = array(
		'post_type'    => 'concurso',
		'post_status'  => 'publish',
		'post_title'   => $titulo,
		'post_name'    => $slug,
		'menu_order'   => (int) ( $c['ordem'] ?? 0 ),
		'post_excerpt' => $c['descricao'] ?? '',
		// Só para a busca do WP e a análise do Rank Math; a página é montada de tpcon_dados.
		'post_content' => ( $c['descricao'] ?? '' ) . "\n\n" . wp_strip_all_tags( (string) ( $c['banca_texto'] ?? '' ) ),
		'post_author'  => 1,
	);
	if ( $p ) {
		$post['ID'] = $p->ID;
	}
	$id = wp_insert_post( wp_slash( $post ), true );
	if ( is_wp_error( $id ) ) {
		return '';
	}
	update_post_meta( $id, 'tpcon_dados', wp_slash( $json ) );
	update_post_meta( $id, 'tpcon_hash', $hash );
	// Rank Math: o JSON é a fonte (como nos guias de Como estudar).
	update_post_meta( $id, 'rank_math_title', $titulo . ' · como estudar e edital verticalizado' );
	if ( ! empty( $c['descricao'] ) ) {
		update_post_meta( $id, 'rank_math_description', $c['descricao'] );
	}
	$kw = array_filter( array_merge( array( $c['palavra_chave'] ?? '' ), (array) ( $c['secundarias'] ?? array() ) ) );
	if ( $kw ) {
		update_post_meta( $id, 'rank_math_focus_keyword', implode( ',', array_map( 'trim', $kw ) ) );
	}
	return $slug;
}

/* Página /concursos/ com [tp_concursos], criada uma vez se não existir. */
function tpcon_garante_hub() {
	if ( get_option( 'tpcon_hub_criado' ) || ! tpcon_arquivos() ) {
		return;
	}
	if ( ! get_page_by_path( 'concursos' ) ) {
		$id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => 'Guias de estudo por concurso',
			'post_name'    => 'concursos',
			'post_content' => "<!-- wp:paragraph -->\n<p>Como estudar para cada concurso, com o edital verticalizado em checklist e as questões do app em cada tópico.</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:shortcode -->\n[tp_concursos]\n<!-- /wp:shortcode -->",
			'post_author'  => 1,
		), true );
		if ( ! is_wp_error( $id ) ) {
			update_post_meta( $id, 'rank_math_description', 'Guias de estudo por concurso: como estudar, por onde começar e o edital verticalizado em checklist, com as questões do app em cada tópico.' );
			update_post_meta( $id, 'rank_math_focus_keyword', 'guia de estudos concurso,edital verticalizado' );
		}
	}
	update_option( 'tpcon_hub_criado', 1, false );
}

/* ---------------------------------------------------------------------------
 * 4. Robots e sitemap
 * ------------------------------------------------------------------------- */
function tpcon_atual_nao_indexavel() {
	return is_singular( 'concurso' ) && ! tpcon_indexavel( get_queried_object_id() );
}
add_filter( 'rank_math/frontend/robots', function ( $r ) {
	if ( tpcon_atual_nao_indexavel() ) {
		$r['index']  = 'noindex';
		$r['follow'] = 'follow';
	}
	return $r;
} );
add_filter( 'wp_robots', function ( $r ) {
	if ( tpcon_atual_nao_indexavel() ) {
		unset( $r['index'] );
		$r['noindex'] = true;
		$r['follow']  = true;
	}
	return $r;
} );
add_action( 'init', function () {
	$o = get_option( 'rank-math-options-sitemap' );
	if ( ! is_array( $o ) || ( $o['pt_concurso_sitemap'] ?? '' ) === 'on' ) {
		return;
	}
	$o['pt_concurso_sitemap'] = 'on';
	update_option( 'rank-math-options-sitemap', $o );
	if ( class_exists( '\\RankMath\\Sitemap\\Cache' ) && method_exists( '\\RankMath\\Sitemap\\Cache', 'invalidate_storage' ) ) {
		\RankMath\Sitemap\Cache::invalidate_storage();
	}
}, 99 );
add_filter( 'rank_math/sitemap/exclude_post_type', function ( $excluir, $tipo ) {
	return 'concurso' === $tipo ? false : $excluir;
}, 10, 2 );
add_filter( 'rank_math/sitemap/entry', function ( $url, $tipo, $obj ) {
	if ( 'post' === $tipo && is_object( $obj ) && isset( $obj->post_type ) && 'concurso' === $obj->post_type && ! tpcon_indexavel( $obj->ID ) ) {
		return false;
	}
	return $url;
}, 10, 3 );

/* ---------------------------------------------------------------------------
 * 5. Montagem da página
 * ------------------------------------------------------------------------- */
function tpcon_link_app( $c, $onde ) {
	return add_query_arg(
		array(
			'plano'        => $c['plano'],
			'visitante'    => 1,
			'utm_source'   => 'concurso',
			'utm_medium'   => $onde,
			'utm_campaign' => $c['slug'],
		),
		home_url( '/estudar/' )
	);
}

/* Tema do caderno para o título do tópico (concursos/links.json), só se publicado e escrito. */
function tpcon_tema_caderno( $titulo ) {
	static $regras = null;
	if ( null === $regras ) {
		$j      = json_decode( (string) @file_get_contents( TPCON_DIR . '/links.json' ), true );
		$regras = is_array( $j['regras'] ?? null ) ? $j['regras'] : array();
	}
	if ( ! function_exists( 'tpc_tema_por_ref' ) ) {
		return null;
	}
	foreach ( $regras as $r ) {
		if ( @preg_match( '/' . str_replace( '/', '\/', $r['padrao'] ) . '/u', $titulo ) ) {
			return tpc_tema_por_ref( $r['tema'] );
		}
	}
	return null;
}

function tpcon_lista_e( $itens ) {
	if ( count( $itens ) < 2 ) {
		return implode( '', $itens );
	}
	$ult = array_pop( $itens );
	return implode( ', ', $itens ) . ' e ' . $ult;
}

function tpcon_dias_ate( $ymd ) {
	$tz = wp_timezone();
	$a  = new DateTime( 'today', $tz );
	$b  = new DateTime( $ymd, $tz );
	return (int) $a->diff( $b )->format( '%r%a' );
}

function tpcon_guia( $c, $plano, $total ) {
	$pr    = (int) ( $plano['pronto'] ?? 3 );
	$discs = $plano['disciplinas'] ?? array();
	// Disciplinas alternativas ("Língua Estrangeira · Inglês" e "· Espanhol": o candidato escolhe uma) contam uma vez.
	$ws = array();
	foreach ( $discs as $d ) {
		if ( (float) $d['peso'] <= 0 ) {
			continue;
		}
		$base = trim( explode( ' · ', $d['nome'] )[0] );
		if ( ! isset( $ws[ $base ] ) ) {
			$ws[ $base ] = array_merge( $d, array( 'nome' => $base ) );
		}
	}
	$ws = array_values( $ws );
	$nome  = $c['sigla'] . ' ' . $c['cargo'];
	// Tempo
	if ( ! empty( $c['prova_data'] ) && tpcon_dias_ate( $c['prova_data'] ) > 0 ) {
		$sem   = (int) floor( tpcon_dias_ate( $c['prova_data'] ) / 7 );
		$est   = max( $sem - 4, 1 );
		$tempo = 'Faltam <b>' . $sem . ' semanas</b> até a prova (' . esc_html( tpi_data_br( $c['prova_data'] ) ) . '). Para ver os ' . $total . ' tópicos uma vez e guardar as 4 últimas semanas só para revisão, a conta dá <b>' . (int) ceil( $total / $est ) . ' tópicos por semana</b>.';
	} elseif ( ! empty( $c['edital_anterior'] ) ) {
		$tempo = 'Ainda não há data de prova. Estudar pelo edital de ' . esc_html( $c['edital_anterior'] ) . ' adianta o trabalho: quando o novo sair, você compara com esta lista e ajusta só o que mudou.';
	} else {
		$tempo = 'Divida os ' . $total . ' tópicos pelas semanas que você tem até a prova e guarde o último mês só para revisão.';
	}
	// Ordem
	$ordem = '';
	if ( count( $ws ) >= 2 ) {
		$pesos = array_map( function ( $d ) {
			return (float) $d['peso'];
		}, $ws );
		$mx    = max( $pesos );
		$exato = max( $pesos ) - min( $pesos ) < 0.01;
		$igual = ( $mx - min( $pesos ) ) / $mx < 0.1;
		if ( $igual ) {
			usort( $ws, function ( $a, $b ) {
				return count( $b['topicos'] ) - count( $a['topicos'] );
			} );
			$ordem = 'Na ' . esc_html( $c['sigla'] ) . ', ' . ( $exato ? 'todas as disciplinas da objetiva valem o mesmo (' . round( $ws[0]['peso'] ) . ' questões cada)' : 'as disciplinas da objetiva valem quase o mesmo' )
				. '. Então o que decide é o tamanho: <b>' . esc_html( $ws[0]['nome'] ) . '</b> (' . count( $ws[0]['topicos'] ) . ' tópicos) e <b>' . esc_html( $ws[1]['nome'] ) . '</b> (' . count( $ws[1]['topicos'] ) . ') pedem mais semanas. Comece por elas e encaixe as curtas no meio.';
		} else {
			$soma = array_sum( $pesos );
			usort( $ws, function ( $a, $b ) {
				return $b['peso'] <=> $a['peso'];
			} );
			$corte = isset( $ws[3] ) ? (float) $ws[3]['peso'] : -1;
			$top   = array_values( array_filter( array_slice( $ws, 0, 3 ), function ( $d ) use ( $corte ) {
				return (float) $d['peso'] > $corte + 0.01;
			} ) );
			if ( 1 === count( $top ) ) {
				$ordem = '<b>' . esc_html( $top[0]['nome'] ) . '</b> sozinha vale ' . round( $top[0]['peso'] / $soma * 100 ) . '% da prova. Comece por ela e distribua o resto, que pesa parecido, pelo tamanho de cada disciplina.';
			}
		}
		if ( ! $igual && count( $top ) > 1 ) {
			$ordem = 'As ' . ( 2 === count( $top ) ? 'duas' : 'três' ) . ' que mais pesam são ' . tpcon_lista_e( array_map( function ( $d ) use ( $soma ) {
				return '<b>' . esc_html( $d['nome'] ) . '</b> (' . round( $d['peso'] / $soma * 100 ) . '%)';
			}, $top ) ) . '. Juntas, valem ' . round( array_sum( array_column( $top, 'peso' ) ) / $soma * 100 ) . '% da prova. Comece por elas e deixe as leves para intercalar.';
		}
	}
	// Lacunas
	$fracas = array();
	$vistas = array();
	foreach ( $discs as $d ) {
		$base = trim( explode( ' · ', $d['nome'] )[0] );
		if ( isset( $vistas[ $base ] ) ) {
			continue;
		}
		$vistas[ $base ] = 1;
		$d['nome']       = $base;
		$n = count( $d['topicos'] );
		$k = count( array_filter( $d['topicos'], function ( $t ) use ( $pr ) {
			return (int) $t['questoes'] >= $pr;
		} ) );
		if ( $n && (float) $d['peso'] > 0 && $k / $n < 0.5 ) {
			$fracas[] = '<b>' . esc_html( $d['nome'] ) . '</b>';
		}
	}
	$lacuna = $fracas
		? 'No app, ainda há poucas questões de ' . tpcon_lista_e( $fracas ) . '. Para essas, estude pela teoria e use os tópicos abaixo como roteiro. No app, o painel do plano tem o botão “Pedir este tópico”.'
		: 'Todas as disciplinas da objetiva já têm questões no app, então dá para estudar cada tópico com teoria e questões no mesmo dia.';
	$passos = array(
		array( 'Tempo', 'Quanto estudar por semana', $tempo ),
		array( 'Ordem', 'Por onde começar', $ordem ),
		array( 'Método', 'Teoria, questões e revisão', 'Leia a teoria do tópico, resolva questões no mesmo dia e revise antes de esquecer. É o que as colunas T, Q e R do edital abaixo acompanham.' ),
		array( 'Lacunas', 'Onde o app ainda não ajuda', $lacuna ),
	);
	$h = '<section class="tpcon-guia" id="como-estudar"><h2>Como estudar para ' . esc_html( $nome ) . '</h2>'
		. '<p class="tpcon-sub">Um roteiro em quatro passos, montado com o peso das disciplinas e o tamanho deste edital.</p><ol class="tpcon-passos">';
	foreach ( array_values( array_filter( $passos, function ( $p ) {
		return '' !== $p[2];
	} ) ) as $i => $p ) {
		$h .= '<li><span class="tpcon-k">' . ( $i + 1 ) . ' · ' . esc_html( $p[0] ) . '</span><h3>' . esc_html( $p[1] ) . '</h3><p>' . $p[2] . '</p></li>';
	}
	$h .= '</ol>';
	if ( ! empty( $c['banca_texto'] ) ) {
		$h .= '<div class="tpcon-banca"><h3>Como a banca costuma cobrar (' . esc_html( $c['banca'] ) . ')</h3>' . wp_kses_post( $c['banca_texto'] ) . '</div>';
	}
	return $h . '</section>';
}

/* Soma dos pesos contando uma vez as disciplinas alternativas ("Língua Estrangeira · Inglês" / "· Espanhol"). */
function tpcon_soma_pesos( $discs ) {
	$por = array();
	foreach ( $discs as $d ) {
		$base         = trim( explode( ' · ', $d['nome'] )[0] );
		$por[ $base ] = max( $por[ $base ] ?? 0, (float) $d['peso'] );
	}
	$s = array_sum( $por );
	return $s > 0 ? $s : 1;
}

function tpcon_cores() {
	return array( '#2c5748', '#b9955b', '#4f7fa0', '#a8432f', '#6b8f3c', '#8a5f0c', '#6b4fa0', '#3f8f86', '#c06c84', '#5f6f7a', '#9c7a3c', '#2f5f8a', '#7a4f3c', '#4c8a5a', '#a05f8a' );
}

function tpcon_pagina( $id ) {
	$c     = tpcon_dados( $id );
	$plano = tpcon_plano( $c['plano'] ?? '' );
	if ( ! $c || ! $plano ) {
		return '<p>O edital deste concurso ainda está sendo preparado.</p>';
	}
	$pr    = (int) ( $plano['pronto'] ?? 3 );
	$discs = $plano['disciplinas'];
	list( $com, $total ) = tpcon_cobertura( $plano );
	$app   = tpcon_link_app( $c, 'topo' );
	$cores = tpcon_cores();
	$conf  = $c['conferido_em'] ?? '';

	$h  = '<div class="tpcon" data-plano="' . esc_attr( $c['plano'] ) . '" data-total="' . (int) $total . '">';
	// Topo
	$h .= '<section class="tpcon-topo"><div class="tpcon-intro">';
	$h .= '<p class="tpcon-eyebrow">Guia de estudos · ' . esc_html( $c['banca'] ) . '</p>';
	$h .= '<h1 class="tpcon-h1">' . str_replace( esc_html( $c['sigla'] ), '<span class="tpcon-nw">' . esc_html( $c['sigla'] ) . '</span>', esc_html( tpcon_titulo( $c ) ) ) . '<small>' . esc_html( $c['orgao'] ) . '</small></h1>';
	$h .= '<p class="tpcon-lede">Por onde começar, quanto estudar por semana até a prova e os <b>' . (int) $total . '</b> tópicos do edital em checklist, para marcar o que você já estudou, praticou e revisou. Cada tópico mostra quantas questões o app tem e qual caderno explica o assunto.</p>';
	$h .= tpi_byline( array( array( 'conferido no edital em', $conf ) ) );
	$h .= '<p class="tpcon-ctas"><a class="tpcon-btn tpcon-btn--pri" data-tpcon-cta="topo" href="' . esc_url( $app ) . '">Estudar por este edital</a><a class="tpcon-btn" href="#como-estudar">Como estudar</a><a class="tpcon-btn" href="#edital">Edital verticalizado</a></p>';
	$h .= '</div>';
	// Ficha
	$h .= '<aside class="tpcon-ficha" aria-label="Dados do edital"><header><h2>Ficha do edital</h2><span>conferida em ' . esc_html( tpi_data_br( $conf ) ) . '</span></header>';
	if ( ! empty( $c['edital_anterior'] ) ) {
		$h .= '<p class="tpcon-anterior">Ainda não há edital novo. Esta página segue o último edital (' . esc_html( $c['edital_anterior'] ) . '), que costuma servir de base para o próximo.</p>';
	}
	$h .= '<dl>';
	$h .= '<div><dt>Banca</dt><dd>' . esc_html( $c['banca'] ) . '</dd></div>';
	$h .= '<div><dt>Vagas</dt><dd>' . ( ! empty( $c['vagas'] ) ? esc_html( $c['vagas'] ) : '<span class="tpcon-fraco">Ver no edital</span>' ) . '</dd></div>';
	if ( ! empty( $c['inscricoes_ate'] ) ) {
		$h .= '<div><dt>Inscrições</dt><dd data-tpcon-insc="' . esc_attr( $c['inscricoes_ate'] ) . '">até ' . esc_html( tpi_data_br( $c['inscricoes_ate'] ) ) . '</dd></div>';
	} else {
		$h .= '<div><dt>Inscrições</dt><dd><span class="tpcon-fraco">Sem edital aberto</span></dd></div>';
	}
	$h .= '<div><dt>Prova</dt><dd>' . ( ! empty( $c['prova_data'] ) ? '<span data-tpcon-prova="' . esc_attr( $c['prova_data'] ) . '">' . esc_html( tpi_data_br( $c['prova_data'] ) ) . '</span>' : '<span class="tpcon-fraco">Sem data</span>' ) . '<span class="tpcon-sub2">' . esc_html( $c['prova'] ?? '' ) . '</span></dd></div>';
	$h .= '<div><dt>Edital</dt><dd>' . esc_html( $c['edital'] ) . ( ! empty( $c['retificacao'] ) ? '<span class="tpcon-sub2">' . esc_html( $c['retificacao'] ) . '</span>' : '' ) . '</dd></div>';
	$h .= '</dl><footer>Dados copiados do edital oficial. Retificações posteriores podem mudar datas. Confira sempre na <a href="' . esc_url( $c['url_banca'] ) . '" rel="nofollow noopener" target="_blank">página da banca</a>.</footer></aside></section>';

	// Resumo: progresso + peso
	$soma = tpcon_soma_pesos( $discs );
	$h   .= '<section class="tpcon-resumo"><div class="tpcon-painel"><h2>Seu progresso neste edital</h2><div class="tpcon-prog" aria-live="polite"></div>'
		. '<p class="tpcon-cob"><b>' . $com . ' de ' . $total . '</b> tópicos já têm questões no app (' . number_format_i18n( (int) ( $plano['questoes'] ?? 0 ) ) . ' questões).</p>'
		. '<div class="tpcon-bar"><i style="width:' . esc_attr( round( $com / max( $total, 1 ) * 100, 1 ) ) . '%"></i></div></div>';
	$h   .= '<div class="tpcon-painel"><h2>Peso de cada disciplina na prova</h2><div class="tpcon-pesos" role="img" aria-label="Peso de cada disciplina na prova">';
	foreach ( $discs as $i => $d ) {
		if ( (float) $d['peso'] > 0 ) {
			$h .= '<span style="flex:' . esc_attr( (float) $d['peso'] ) . ';background:' . esc_attr( $cores[ $i % count( $cores ) ] ) . '"></span>';
		}
	}
	$h .= '</div><ul class="tpcon-leg">';
	foreach ( $discs as $i => $d ) {
		$h .= '<li><i style="background:' . ( (float) $d['peso'] > 0 ? esc_attr( $cores[ $i % count( $cores ) ] ) : 'var(--tpi-linha)' ) . '"></i><span>' . esc_html( $d['nome'] ) . '</span><em>' . ( (float) $d['peso'] > 0 ? round( $d['peso'] / $soma * 100 ) . '%' : 'só discursiva' ) . '</em></li>';
	}
	$h .= '</ul></div></section>';

	// Guia
	$h .= tpcon_guia( $c, $plano, $total );

	// Edital verticalizado
	$h .= '<section class="tpcon-corpo" id="edital"><nav class="tpcon-sum" aria-label="Disciplinas"><h2>Disciplinas</h2><ol>';
	foreach ( $discs as $i => $d ) {
		$h .= '<li><a href="#d-' . esc_attr( $d['id'] ) . '"><span>' . esc_html( $d['nome'] ) . '</span><em data-tpcon-cont="' . esc_attr( $d['id'] ) . '">0/' . count( $d['topicos'] ) . '</em></a></li>';
	}
	$h .= '</ol></nav><div class="tpcon-lista">';
	$h .= '<h2>Edital verticalizado ' . esc_html( $c['sigla'] . ' ' . $c['cargo'] ) . '</h2>';
	$h .= '<p class="tpcon-sub">Marque <b>T</b> quando estudar a teoria, <b>Q</b> quando resolver questões e <b>R</b> quando revisar. O texto de cada tópico é o do edital. Quer montar o seu do zero? Veja <a href="' . esc_url( home_url( '/como-estudar/edital-verticalizado/' ) ) . '">Edital verticalizado: o que é e como fazer o seu</a>.</p>';
	$h .= '<div class="tpcon-filtros" hidden><input type="search" id="tpcon-busca" placeholder="Buscar tópico (ex.: tortura, crase)" aria-label="Buscar tópico"><div class="tpcon-seg" role="group" aria-label="Filtro"><button type="button" data-f="todos" aria-pressed="true">Todos</button><button type="button" data-f="pendentes" aria-pressed="false">Falta estudar</button><button type="button" data-f="questoes" aria-pressed="false">Com questões</button></div></div>';
	$abrir = $total <= 100 ? count( $discs ) : 2;
	foreach ( $discs as $i => $d ) {
		$n      = count( $d['topicos'] );
		$k      = 0;
		$linhas = '';
		$grupo  = '';
		$cad    = null;
		foreach ( $d['topicos'] as $j => $t ) {
			$q    = (int) $t['questoes'];
			$k   += $q >= $pr ? 1 : 0;
			$cod  = (string) $t['cod'];
			$pai  = false !== strpos( $cod, '.' ) ? explode( '.', $cod )[0] : '';
			if ( $pai && ! empty( $d['grupos'][ $pai ] ) && $pai !== $grupo ) {
				$grupo   = $pai;
				$linhas .= '<li class="tpcon-grupo"><span class="tpcon-cod">' . esc_html( $pai ) . '</span><p>' . esc_html( $d['grupos'][ $pai ] ) . '</p></li>';
			}
			$tema  = tpcon_tema_caderno( $t['titulo'] );
			$cad   = $cad ? $cad : $tema;
			$chip  = $q >= $pr ? '<span class="tpcon-chip tpcon-chip--ok">' . $q . ' questões no app</span>'
				: ( $q > 0 ? '<span class="tpcon-chip">' . $q . ' ' . ( 1 === $q ? 'questão' : 'questões' ) . ', ainda pouco</span>' : '<span class="tpcon-chip">Ainda sem questões</span>' );
			$links = $tema ? '<a href="' . esc_url( tpc_link_tema( $tema ) ) . '" title="' . esc_attr( $tema->post_title ) . '">Ler no caderno →</a>' : '';
			$links .= $q >= $pr ? '<a href="' . esc_url( tpcon_link_app( $c, 'topico' ) ) . '" data-tpcon-cta="topico">Praticar</a>' : '';
			$tid    = $d['id'] . ':' . ( '' !== $cod ? $cod : $j );
			$linhas .= '<li class="tpcon-t" data-id="' . esc_attr( $tid ) . '" data-q="' . ( $q >= $pr ? 1 : 0 ) . '"><span class="tpcon-cod">' . esc_html( $cod ) . '</span><div class="tpcon-tx"><p>' . esc_html( $t['titulo'] ) . '</p><div class="tpcon-tags">' . $chip . $links . '</div></div><div class="tpcon-trq">';
			foreach ( array( 'T' => 'Teoria', 'Q' => 'Questões', 'R' => 'Revisão' ) as $col => $rot ) {
				$linhas .= '<input type="checkbox" data-c="' . $col . '" aria-label="' . esc_attr( $rot . ': ' . mb_substr( $t['titulo'], 0, 60 ) ) . '">';
			}
			$linhas .= '</div></li>';
		}
		$cad_link = $cad ? '<a href="' . esc_url( get_permalink( $cad->post_parent ) ) . '">' . esc_html( get_the_title( $cad->post_parent ) ) . ' →</a>' : '';
		$h       .= '<details class="tpcon-disc" id="d-' . esc_attr( $d['id'] ) . '"' . ( $i < $abrir ? ' open' : '' ) . '><summary><h3>' . esc_html( $d['nome'] ) . '</h3><span class="tpcon-mini"><i data-tpcon-barra="' . esc_attr( $d['id'] ) . '"></i></span>'
			. '<span class="tpcon-meta"><span>' . $n . ' tópicos</span><span>' . ( (float) $d['peso'] > 0 ? 'peso ' . esc_html( number_format_i18n( (float) $d['peso'], fmod( (float) $d['peso'], 1 ) ? 1 : 0 ) ) . ' questões' : 'sem questão objetiva' ) . '</span><span>' . $k . ' com questões</span>' . $cad_link . '</span></summary>'
			. '<div class="tpcon-cols" aria-hidden="true"><span>Item</span><span>Tópico do edital</span><span class="tpcon-trq"><span>T</span><span>Q</span><span>R</span></span></div><ol class="tpcon-ts">' . $linhas . '</ol></details>';
	}
	$h .= '<p class="tpcon-nada" hidden>Nenhum tópico com esse filtro.</p></div></section>';

	// Chamada final + fontes
	$h .= '<section class="tpcon-final"><div><h2>Estude por este edital no app</h2><p>O plano de ' . esc_html( $c['sigla'] . ' ' . $c['cargo'] ) . ' já monta o cronograma pelo peso de cada disciplina, traz a lei seca de cada tópico e marca as revisões do dia.</p></div><a class="tpcon-btn" data-tpcon-cta="final" href="' . esc_url( tpcon_link_app( $c, 'final' ) ) . '">Começar o plano</a></section>';
	$gerado = ! empty( $plano['gerado'] ) ? tpi_data_br( substr( $plano['gerado'], 0, 10 ) ) : '';
	$h .= '<footer class="tpcon-fontes"><p><b>Fonte:</b> ' . esc_html( $c['edital'] ) . ( ! empty( $c['retificacao'] ) ? '. ' . esc_html( $c['retificacao'] ) : '' ) . ' Conteúdo programático transcrito do edital oficial; a divisão em tópicos é do Tchau Pobreza.</p>'
		. ( $gerado ? '<p>Questões e pesos contados pelo app em ' . esc_html( $gerado ) . '. Esta página não é afiliada à banca nem ao órgão.</p>' : '' ) . '</footer>';
	// Chamada fixa no celular
	$h .= '<div class="tpcon-fixo"><p><b>' . esc_html( $c['sigla'] . ' · ' . $c['cargo'] ) . '</b>' . ( ! empty( $c['prova_data'] ) ? '<span data-tpcon-prova-curta="' . esc_attr( $c['prova_data'] ) . '">Prova em ' . esc_html( tpi_data_br( $c['prova_data'] ) ) . '</span>' : 'Plano pelo último edital' ) . '</p><a class="tpcon-btn tpcon-btn--pri" data-tpcon-cta="fixo" href="' . esc_url( tpcon_link_app( $c, 'fixo' ) ) . '">Estudar no app</a></div>';
	return $h . '</div>';
}

add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'concurso' ) || get_the_ID() !== get_queried_object_id() ) {
		return $content;
	}
	return tpcon_pagina( get_the_ID() );
}, 20 );

/* Hub: [tp_concursos] */
add_shortcode( 'tp_concursos', function () {
	$ps = get_posts( array( 'post_type' => 'concurso', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'menu_order title', 'order' => 'ASC' ) );
	if ( ! $ps ) {
		return '';
	}
	wp_enqueue_style( 'tp-concursos', TPI_URL . 'assets/tpcon.css', array(), TPI_VERSION );
	$h = '<ul class="tpcon-hub">';
	foreach ( $ps as $p ) {
		$c     = tpcon_dados( $p->ID );
		$plano = tpcon_plano( $c['plano'] ?? '' );
		list( $com, $total ) = tpcon_cobertura( $plano );
		$h .= '<li><a href="' . esc_url( get_permalink( $p ) ) . '"><span class="tpcon-eyebrow">' . esc_html( $c['banca'] ?? '' ) . '</span><strong>' . esc_html( ( $c['sigla'] ?? '' ) . ' · ' . ( $c['cargo'] ?? '' ) ) . '</strong>'
			. '<span>' . ( ! empty( $c['prova_data'] ) ? 'Prova em ' . esc_html( tpi_data_br( $c['prova_data'] ) ) : 'Pelo último edital (' . esc_html( $c['edital_anterior'] ?? '' ) . ')' ) . '</span>'
			. '<span>' . (int) $total . ' tópicos · ' . (int) $com . ' com questões no app</span></a></li>';
	}
	return $h . '</ul>';
} );

/* ---------------------------------------------------------------------------
 * 6. Modelo do tema (sem o título do bloco: o H1 sai da página), classes e trilha
 * ------------------------------------------------------------------------- */
add_action( 'init', function () {
	if ( ! function_exists( 'register_block_template' ) ) {
		return;
	}
	register_block_template(
		'tp-informativos//single-concurso',
		array(
			'title'       => 'Concurso',
			'description' => 'Guias de estudo por concurso (/concursos/<slug>/).',
			'post_types'  => array( 'concurso' ),
			'content'     => '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->
<!-- wp:group {"tagName":"main","style":{"spacing":{"margin":{"top":"var:preset|spacing|40"}}},"layout":{"type":"constrained"}} -->
<main class="wp-block-group" style="margin-top:var(--wp--preset--spacing--40)">
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|30","bottom":"var:preset|spacing|60"}}},"layout":{"type":"constrained"}} -->
<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--30);padding-bottom:var(--wp--preset--spacing--60)">
<!-- wp:post-content {"align":"wide","layout":{"type":"constrained","contentSize":"1180px"}} /-->
</div>
<!-- /wp:group -->
</main>
<!-- /wp:group -->
<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->',
		)
	);
} );

add_filter( 'body_class', function ( $c ) {
	if ( is_singular( 'concurso' ) ) {
		$c[] = 'tpi-pagina';
		$c[] = 'tpcon-pagina';
	}
	return $c;
} );

/* Trilha no topo do conteúdo (o modelo não tem o bloco de título, onde os cadernos põem a trilha). */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'concurso' ) || get_the_ID() !== get_queried_object_id() ) {
		return $content;
	}
	$c = tpcon_dados( get_the_ID() );
	return '<nav class="tpi-trilha" aria-label="Trilha de navegação"><ol><li><a href="' . esc_url( home_url( '/concursos/' ) ) . '">Concursos</a></li><li>' . esc_html( ( $c['sigla'] ?? '' ) . ' ' . ( $c['cargo'] ?? '' ) ) . '</li></ol></nav>' . $content;
}, 21 );

/* ---------------------------------------------------------------------------
 * 7. Dados estruturados: WebPage + Article + BreadcrumbList (sem JobPosting/Event/FAQ)
 * ------------------------------------------------------------------------- */
add_action( 'wp_head', function () {
	if ( ! is_singular( 'concurso' ) || ! apply_filters( 'tpi_schema_ativo', true ) ) {
		return;
	}
	$id     = get_queried_object_id();
	$c      = tpcon_dados( $id );
	$a      = tpi_autor();
	$pessoa = array( '@type' => 'Person', 'name' => $a['nome'], 'url' => $a['url'] );
	$url    = get_permalink( $id );
	$titulo = wp_strip_all_tags( get_the_title( $id ) );
	$page   = array( '@type' => 'WebPage', '@id' => $url, 'url' => $url, 'name' => $titulo, 'inLanguage' => 'pt-BR' );
	if ( ! empty( $c['conferido_em'] ) ) {
		$page['lastReviewed'] = $c['conferido_em'];
		$page['reviewedBy']   = $pessoa;
	}
	$art = array(
		'@type'               => 'Article',
		'@id'                 => $url . '#article',
		'headline'            => $titulo,
		'description'         => $c['descricao'] ?? '',
		'datePublished'       => get_post_time( 'c', true, $id ),
		'dateModified'        => get_post_modified_time( 'c', true, $id ),
		'author'              => $pessoa,
		'publisher'           => array( '@type' => 'Organization', 'name' => get_bloginfo( 'name' ), 'url' => home_url( '/' ) ),
		'mainEntityOfPage'    => $url,
		'isPartOf'            => array( '@id' => $url ),
		'inLanguage'          => 'pt-BR',
		'about'               => array( '@type' => 'Thing', 'name' => trim( ( $c['sigla'] ?? '' ) . ' ' . ( $c['cargo'] ?? '' ) ) ),
	);
	$bc  = array(
		'@type'           => 'BreadcrumbList',
		'itemListElement' => array(
			array( '@type' => 'ListItem', 'position' => 1, 'name' => 'Concursos', 'item' => home_url( '/concursos/' ) ),
			array( '@type' => 'ListItem', 'position' => 2, 'name' => $titulo, 'item' => $url ),
		),
	);
	echo '<script type="application/ld+json">' . wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => array( $page, $art, $bc ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
}, 30 );

add_filter( 'rank_math/frontend/description', function ( $desc ) {
	if ( '' !== (string) $desc || ! is_singular( 'concurso' ) ) {
		return $desc;
	}
	$c = tpcon_dados( get_queried_object_id() );
	return (string) ( $c['descricao'] ?? '' );
} );

/* ---------------------------------------------------------------------------
 * 8. Assets
 * ------------------------------------------------------------------------- */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular( 'concurso' ) ) {
		return;
	}
	wp_enqueue_style( 'tp-informativos', TPI_URL . 'assets/tpi.css', array(), TPI_VERSION );
	wp_enqueue_style( 'tp-concursos', TPI_URL . 'assets/tpcon.css', array( 'tp-informativos' ), TPI_VERSION );
	wp_enqueue_script( 'tp-concursos', TPI_URL . 'assets/tpcon.js', array(), TPI_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
} );
