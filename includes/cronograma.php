<?php
/**
 * 0.29.0: gerador de cronograma de estudos (guia /como-estudar/cronograma-de-estudos-para-concurso/).
 *
 * O guia traz o shortcode [tp_cronograma]. O formulário é desenhado aqui no servidor (o post é gravado sem usuário,
 * e o kses tiraria <input>/<select> do corpo); a conta roda no navegador (assets/tpcrono.js). Nada é enviado ao
 * servidor: os campos ficam só no localStorage de quem usa.
 *
 * "Carregar um edital" lê as disciplinas, o peso e os tópicos dos planos das páginas /concursos/ pela rota
 * /wp-json/tp/v1/cronograma-edital/<slug> (mesma fonte do edital verticalizado: tchau-pobreza/app/concursos.json).
 * Link direto: ?edital=<slug> abre o gerador já preenchido.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Concursos publicados. A lista "Carregar um edital" mostra só os indexáveis; o link ?edital= aceita todos. */
function tpcrono_editais( $so_indexaveis = true ) {
	if ( ! function_exists( 'tpcon_dados' ) ) {
		return array();
	}
	$out = array();
	foreach ( get_posts( array( 'post_type' => 'concurso', 'post_status' => 'publish', 'numberposts' => 40, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
		$c = tpcon_dados( $p->ID );
		if ( empty( $c['plano'] ) || ( $so_indexaveis && ! tpcon_indexavel( $p->ID ) ) ) {
			continue;
		}
		$out[ $p->post_name ] = array(
			'id'    => $p->ID,
			'nome'  => trim( ( $c['sigla'] ?? '' ) . ' · ' . ( $c['cargo'] ?? '' ) ),
			'ordem' => (int) ( $c['ordem'] ?? 99 ),
		);
	}
	uasort( $out, function ( $a, $b ) {
		return array( $a['ordem'], $a['nome'] ) <=> array( $b['ordem'], $b['nome'] );
	} );
	return $out;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'tp/v1', '/cronograma-edital/(?P<slug>[a-z0-9-]+)', array(
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'callback'            => function ( $req ) {
			$ed = tpcrono_editais( false );
			$s  = (string) $req['slug'];
			if ( ! isset( $ed[ $s ] ) ) {
				return new WP_Error( 'tpcrono_404', 'Edital não encontrado.', array( 'status' => 404 ) );
			}
			$c     = tpcon_dados( $ed[ $s ]['id'] );
			$plano = tpcon_plano( $c['plano'] );
			// Disciplinas alternativas ("Língua Estrangeira · Inglês" / "· Espanhol") entram uma vez; sem peso na objetiva, fora.
			$discs = array();
			foreach ( $plano['disciplinas'] ?? array() as $d ) {
				if ( (float) $d['peso'] <= 0 ) {
					continue;
				}
				$base = trim( explode( ' · ', $d['nome'] )[0] );
				if ( isset( $discs[ $base ] ) ) {
					continue;
				}
				$discs[ $base ] = array(
					'nome'    => $base,
					'peso'    => (float) $d['peso'],
					'topicos' => array_values( array_map( function ( $t ) {
						return wp_strip_all_tags( (string) $t['titulo'] );
					}, $d['topicos'] ?? array() ) ),
				);
			}
			$prova = ( ! empty( $c['prova_data'] ) && tpcon_dias_ate( $c['prova_data'] ) > 7 ) ? $c['prova_data'] : '';
			$r     = rest_ensure_response( array(
				'slug'        => $s,
				'nome'        => $ed[ $s ]['nome'],
				'prova'       => $prova,
				'url'         => get_permalink( $ed[ $s ]['id'] ),
				'plano'       => (string) $c['plano'],
				'disciplinas' => array_values( $discs ),
			) );
			$r->header( 'Cache-Control', 'public, max-age=3600' );
			return $r;
		},
	) );
} );

function tpcrono_tem_gerador() {
	if ( ! is_singular( 'post' ) ) {
		return false;
	}
	$p = get_post( get_queried_object_id() );
	return $p && has_shortcode( $p->post_content, 'tp_cronograma' );
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! tpcrono_tem_gerador() ) {
		return;
	}
	wp_enqueue_style( 'tp-cronograma', TPI_URL . 'assets/tpcrono.css', array(), TPI_VERSION );
	wp_enqueue_script( 'tp-cronograma', TPI_URL . 'assets/tpcrono.js', array(), TPI_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
} );

add_shortcode( 'tp_cronograma', function () {
	$dias = array( 'seg' => 'Seg', 'ter' => 'Ter', 'qua' => 'Qua', 'qui' => 'Qui', 'sex' => 'Sex', 'sab' => 'Sáb', 'dom' => 'Dom' );
	$pad  = array( 'seg' => 2, 'ter' => 2, 'qua' => 2, 'qui' => 2, 'sex' => 2, 'sab' => 4, 'dom' => 0 );
	$ops  = '';
	foreach ( tpcrono_editais() as $slug => $e ) {
		$ops .= '<option value="' . esc_attr( $slug ) . '">' . esc_html( $e['nome'] ) . '</option>';
	}
	$h  = '<div class="tpcrono" id="gerador" data-api="' . esc_url( rest_url( 'tp/v1/cronograma-edital/' ) ) . '" data-app="' . esc_url( home_url( '/estudar/' ) ) . '">';
	$h .= '<form class="tpcrono-form" novalidate>';
	$h .= '<p class="tpcrono-olho">Gerador de cronograma</p>';

	// 1. Edital
	$h .= '<fieldset class="tpcrono-bloco"><legend><span class="tpcrono-n">1</span> Matérias e peso</legend>';
	if ( $ops ) {
		$h .= '<div class="tpcrono-linha tpcrono-edital"><label for="tpcrono-edital">Carregar um edital</label>'
			. '<select id="tpcrono-edital"><option value="">Montar com as minhas matérias</option>' . $ops . '</select></div>';
	}
	$h .= '<p class="tpcrono-ajuda">Peso = quantas questões a matéria vale na prova. Tópicos = quantos assuntos ela tem no edital (se não souber, deixe 10).</p>';
	$h .= '<div class="tpcrono-tabela" role="group" aria-label="Matérias"><div class="tpcrono-cab" aria-hidden="true"><span>Matéria</span><span>Peso</span><span>Tópicos</span><span>Você nessa matéria</span><span></span></div><div class="tpcrono-materias"></div></div>';
	$h .= '<button type="button" class="tpcrono-mais">+ Adicionar matéria</button>';
	$h .= '</fieldset>';

	// 2. Tempo
	$h .= '<fieldset class="tpcrono-bloco"><legend><span class="tpcrono-n">2</span> Seu tempo</legend>';
	$h .= '<div class="tpcrono-linha"><label for="tpcrono-prova">Data da prova</label><input type="date" id="tpcrono-prova"></div>';
	$h .= '<div class="tpcrono-linha"><label for="tpcrono-semanas">Sem data? Semanas de estudo</label><input type="number" id="tpcrono-semanas" min="2" max="78" value="16" inputmode="numeric"></div>';
	$h .= '<p class="tpcrono-ajuda">Horas de estudo em cada dia da semana (0 = folga).</p><div class="tpcrono-dias">';
	foreach ( $dias as $k => $rot ) {
		$h .= '<label class="tpcrono-dia"><span>' . esc_html( $rot ) . '</span><input type="number" data-dia="' . esc_attr( $k ) . '" min="0" max="14" step="0.5" value="' . esc_attr( $pad[ $k ] ) . '" inputmode="decimal" aria-label="Horas na ' . esc_attr( $rot ) . '"></label>';
	}
	$h .= '</div></fieldset>';
	$h .= '<div class="tpcrono-acoes"><button type="submit" class="tpcrono-gerar">Gerar meu cronograma</button><button type="button" class="tpcrono-limpar">Recomeçar</button></div>';
	$h .= '</form>';
	$h .= '<div class="tpcrono-resultado" aria-live="polite" hidden></div>';
	$h .= '<noscript><p class="tpcrono-ajuda">O gerador precisa de JavaScript. O modelo pronto logo abaixo funciona sem ele.</p></noscript>';
	return $h . '</div>';
} );
