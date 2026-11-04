<?php
/**
 * Atualizações pelo GitHub (padrão do WordPress, sem rota de upload).
 *
 * O WordPress lê o cabeçalho do plugin no branch "estavel" de github.com/<TPI_REPO> e, se a versão for
 * maior que a instalada, mostra "Atualizar" em Painel > Plugins (ou atualiza sozinho, se a atualização
 * automática estiver ligada para este plugin). O pacote é o zip que o GitHub gera para o branch.
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
	// "Verificar novamente" (Painel > Atualizações) ignora o cache.
	if ( is_admin() && ! empty( $_GET['force-check'] ) ) {
		$cache = false;
	}
	if ( false !== $cache ) {
		return $cache;
	}
	// Canal de publicação: o branch TPI_CANAL (padrão "estavel"). O desenvolvimento vai no main;
	// uma versão só chega ao site quando é enviada ao branch estavel com o Version: aumentado.
	$canal = defined( 'TPI_CANAL' ) ? TPI_CANAL : 'estavel';
	$dados = array();
	$resp  = wp_remote_get( 'https://raw.githubusercontent.com/' . TPI_REPO . '/' . rawurlencode( $canal ) . '/tp-informativos.php', array( 'timeout' => 10, 'headers' => array( 'User-Agent' => 'tp-informativos' ) ) );
	if ( ! is_wp_error( $resp ) && 200 === wp_remote_retrieve_response_code( $resp ) ) {
		$cab = substr( wp_remote_retrieve_body( $resp ), 0, 4096 );
		if ( false !== strpos( $cab, 'Plugin Name: TP Informativos' ) && preg_match( '/^\s*\*\s*Version:\s*(\d+\.\d+\.\d+)/m', $cab, $m ) ) {
			$dados = array(
				'versao' => $m[1],
				'pacote' => 'https://github.com/' . TPI_REPO . '/archive/refs/heads/' . rawurlencode( $canal ) . '.zip',
				'url'    => 'https://github.com/' . TPI_REPO . '/commits/' . rawurlencode( $canal ),
				'notas'  => 'Histórico de mudanças: https://github.com/' . TPI_REPO . '/commits/' . $canal,
				'data'   => '',
			);
		}
	}
	// Cache de 1 h (raw.githubusercontent.com não tem limite de API como o api.github.com).
	set_site_transient( 'tpi_release', $dados, HOUR_IN_SECONDS );
	return $dados;
}

/* Instalado pelo Git da Hostinger (pasta com .git)? Então o deploy é feito por lá e este atualizador fica quieto. */
function tpi_atualizador_ligado() {
	return apply_filters( 'tpi_atualizador_ativo', ! file_exists( dirname( TPI_ARQUIVO ) . '/.git' ) );
}

add_filter( 'pre_set_site_transient_update_plugins', function ( $t ) {
	if ( ! tpi_atualizador_ligado() || empty( $t->checked ) ) {
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

/* O zip do branch vem com a pasta tp-informativos-estavel/; renomeia para tp-informativos/. */
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
