<?php
/**
 * Atualizações pelo GitHub (padrão do WordPress, sem rota de upload).
 *
 * O WordPress consulta a última release pública de github.com/<TPI_REPO> e, se a versão for maior
 * que a instalada, mostra "Atualizar" em Painel > Plugins (ou atualiza sozinho, se a atualização
 * automática estiver ligada para este plugin). O pacote baixado é o asset tp-informativos.zip da release.
 *
 * Trocar o repositório: define( 'TPI_REPO', 'dono/repo' ); no wp-config.php.
 * Desligar: add_filter( 'tpi_atualizador_ativo', '__return_false' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TPI_REPO' ) ) {
	define( 'TPI_REPO', 'Fastzinho/tp-informativos' );
}

function tpi_release_github() {
	$cache = get_site_transient( 'tpi_release' );
	if ( false !== $cache ) {
		return $cache;
	}
	$args  = array( 'timeout' => 10, 'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'tp-informativos' ) );
	$dados = array();
	// 1) Release com o asset tp-informativos.zip (quando houver).
	$resp = wp_remote_get( 'https://api.github.com/repos/' . TPI_REPO . '/releases/latest', $args );
	if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
		$j = json_decode( wp_remote_retrieve_body( $resp ), true );
		foreach ( (array) ( $j['assets'] ?? array() ) as $a ) {
			if ( 'tp-informativos.zip' === ( $a['name'] ?? '' ) ) {
				$dados = array( 'versao' => ltrim( (string) $j['tag_name'], 'v' ), 'pacote' => $a['browser_download_url'], 'url' => $j['html_url'], 'notas' => (string) ( $j['body'] ?? '' ), 'data' => (string) ( $j['published_at'] ?? '' ) );
				break;
			}
		}
	}
	// 2) Sem release: a maior tag vX.Y.Z, baixada como o zip que o GitHub gera para a tag.
	$resp = wp_remote_get( 'https://api.github.com/repos/' . TPI_REPO . '/tags?per_page=100', $args );
	if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
		foreach ( (array) json_decode( wp_remote_retrieve_body( $resp ), true ) as $tag ) {
			$v = ltrim( (string) ( $tag['name'] ?? '' ), 'v' );
			if ( preg_match( '/^\d+\.\d+\.\d+$/', $v ) && ( empty( $dados['versao'] ) || version_compare( $v, $dados['versao'], '>' ) ) ) {
				$dados = array( 'versao' => $v, 'pacote' => 'https://github.com/' . TPI_REPO . '/archive/refs/tags/' . rawurlencode( $tag['name'] ) . '.zip', 'url' => 'https://github.com/' . TPI_REPO . '/tree/' . rawurlencode( $tag['name'] ), 'notas' => '', 'data' => '' );
			}
		}
	}
	// 6 h com resultado; 1 h sem (repo vazio, limite da API ou rede fora).
	set_site_transient( 'tpi_release', $dados, $dados ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $dados;
}

add_filter( 'pre_set_site_transient_update_plugins', function ( $t ) {
	if ( ! apply_filters( 'tpi_atualizador_ativo', true ) || empty( $t->checked ) ) {
		return $t;
	}
	$base = plugin_basename( TPI_ARQUIVO );
	$rel  = tpi_release_github();
	if ( empty( $rel['versao'] ) ) {
		return $t;
	}
	$item = (object) array(
		'id'          => 'github.com/' . TPI_REPO,
		'slug'        => 'tp-informativos',
		'plugin'      => $base,
		'new_version' => $rel['versao'],
		'url'         => 'https://github.com/' . TPI_REPO,
		'package'     => $rel['pacote'],
	);
	if ( version_compare( $rel['versao'], TPI_VERSION, '>' ) ) {
		$t->response[ $base ] = $item;
	} else {
		$t->no_update[ $base ] = $item;
	}
	return $t;
} );

/* Janela "Ver detalhes" da atualização. */
add_filter( 'plugins_api', function ( $res, $acao, $args ) {
	if ( 'plugin_information' !== $acao || 'tp-informativos' !== ( $args->slug ?? '' ) ) {
		return $res;
	}
	$rel = tpi_release_github();
	if ( empty( $rel['versao'] ) ) {
		return $res;
	}
	return (object) array(
		'name'          => 'TP Informativos',
		'slug'          => 'tp-informativos',
		'version'       => $rel['versao'],
		'author'        => 'Tchau Pobreza',
		'homepage'      => 'https://github.com/' . TPI_REPO,
		'download_link' => $rel['pacote'],
		'last_updated'  => $rel['data'],
		'sections'      => array( 'changelog' => wpautop( esc_html( $rel['notas'] ) ) ),
	);
}, 10, 3 );

/* O zip de tag do GitHub vem com a pasta tp-informativos-X.Y.Z/; renomeia para tp-informativos/. */
add_filter( 'upgrader_source_selection', function ( $origem, $remoto, $upgrader, $extra = array() ) {
	if ( empty( $extra['plugin'] ) || plugin_basename( TPI_ARQUIVO ) !== $extra['plugin'] ) {
		return $origem;
	}
	$certo = trailingslashit( $remoto ) . 'tp-informativos/';
	if ( untrailingslashit( $origem ) !== untrailingslashit( $certo ) && @rename( $origem, $certo ) ) {
		return $certo;
	}
	return $origem;
}, 10, 4 );

/* Depois de atualizar: limpa o cache da release e o cache de página (LiteSpeed). */
add_action( 'upgrader_process_complete', function ( $upgrader, $extra ) {
	if ( 'plugin' === ( $extra['type'] ?? '' ) && in_array( plugin_basename( TPI_ARQUIVO ), (array) ( $extra['plugins'] ?? array() ), true ) ) {
		delete_site_transient( 'tpi_release' );
		do_action( 'litespeed_purge_all' );
	}
}, 10, 2 );
