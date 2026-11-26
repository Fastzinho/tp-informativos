<?php
/**
 * 0.27.0: domínio provisório da Hostinger (25/11/2026).
 *
 * O site também respondia em <algo>.hostingersite.com com status 200, canonical apontando para ele mesmo e
 * index, follow (o robots.txt da Hostinger nesse domínio só bloqueia o Googlebot; Bing e robôs de IA liam a cópia).
 * O llms.txt gerado pelo Hostinger Tools saiu com todos os links nesse domínio.
 *
 * 1. No domínio provisório: noindex (cabeçalho e meta) e canonical para tchaupobreza.com.br (0.27.1; o 301 da 0.27.0 virou loop).
 * 2. llms.txt: desde a 0.28.0 escrito por inteiro em includes/llms.php (o estático é sobrescrito)
 *    (depois que o Hostinger Tools regrava o arquivo ao salvar posts, e uma vez por dia).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TPI_DOMINIO_OFICIAL = 'tchaupobreza.com.br';

function tpi_host_provisorio( $host ) {
	return (bool) preg_match( '/\.hostingersite\.com$/i', (string) $host );
}

/* 0.27.1: o 301 virou loop. A CDN da Hostinger (hcdn) reescreve o Location para o host pedido, então o
 * redirect apontava para o próprio domínio provisório. Em vez de redirecionar, a cópia passa a dizer
 * noindex e aponta o canonical para o domínio oficial. O wp-admin e o login seguem funcionando nela
 * (o botão do hPanel pode usar esse endereço). */
function tpi_no_provisorio() {
	$host = strtolower( preg_replace( '/:\d+$/', '', (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
	return tpi_host_provisorio( $host );
}
function tpi_url_oficial( $url ) {
	return preg_replace( '#^https?://[a-z0-9-]+\.hostingersite\.com#i', 'https://' . TPI_DOMINIO_OFICIAL, (string) $url );
}
if ( tpi_no_provisorio() && ! headers_sent() ) {
	header( 'X-Robots-Tag: noindex, follow', true );
}
add_filter( 'rank_math/frontend/canonical', function ( $c ) {
	return tpi_no_provisorio() ? tpi_url_oficial( $c ) : $c;
}, 99 );
add_filter( 'rank_math/frontend/robots', function ( $r ) {
	if ( tpi_no_provisorio() ) {
		$r['index'] = 'noindex';
		$r['follow'] = 'follow';
	}
	return $r;
}, 99 );
add_filter( 'wp_robots', function ( $r ) {
	if ( tpi_no_provisorio() ) {
		unset( $r['index'] );
		$r['noindex'] = true;
	}
	return $r;
}, 99 );

/* 0.28.0: o llms.txt passou a ser escrito por inteiro em includes/llms.php (seções por produto). */
function tpi_conserta_llms() {
	if ( function_exists( 'tpi_llms_grava_estatico' ) ) {
		tpi_llms_grava_estatico();
	}
}
add_action( 'save_post', function () {
	add_action( 'shutdown', 'tpi_conserta_llms', 999 );
}, 999 );
add_action( 'init', function () {
	if ( ! get_transient( 'tpi_llms_conferido_028' ) ) {
		set_transient( 'tpi_llms_conferido_028', 1, DAY_IN_SECONDS );
		add_action( 'shutdown', 'tpi_conserta_llms', 999 );
	}
} );
