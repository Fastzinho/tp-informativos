<?php
/**
 * 0.27.0: domínio provisório da Hostinger (25/11/2026).
 *
 * O site também respondia em <algo>.hostingersite.com com status 200, canonical apontando para ele mesmo e
 * index, follow (o robots.txt da Hostinger nesse domínio só bloqueia o Googlebot; Bing e robôs de IA liam a cópia).
 * O llms.txt gerado pelo Hostinger Tools saiu com todos os links nesse domínio.
 *
 * 1. Qualquer GET/HEAD no domínio provisório vai com 301 para o mesmo caminho em tchaupobreza.com.br.
 *    POST fica de fora (não quebrar chamada da própria Hostinger).
 * 2. Rede de segurança do llms.txt: se o arquivo estático tiver o domínio provisório, troca pelo oficial
 *    (depois que o Hostinger Tools regrava o arquivo ao salvar posts, e uma vez por dia).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TPI_DOMINIO_OFICIAL = 'tchaupobreza.com.br';

function tpi_host_provisorio( $host ) {
	return (bool) preg_match( '/\.hostingersite\.com$/i', (string) $host );
}

( function () {
	$host   = strtolower( preg_replace( '/:\d+$/', '', (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ) );
	$metodo = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
	if ( ! tpi_host_provisorio( $host ) || ! in_array( $metodo, array( 'GET', 'HEAD' ), true ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
		return;
	}
	$uri = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
	if ( '' === $uri || '/' !== $uri[0] ) {
		$uri = '/';
	}
	header( 'X-Robots-Tag: noindex', true );
	header( 'Location: https://' . TPI_DOMINIO_OFICIAL . $uri, true, 301 );
	exit;
} )();

function tpi_conserta_llms() {
	$arq = ABSPATH . 'llms.txt';
	if ( ! is_readable( $arq ) || ! is_writable( $arq ) ) {
		return;
	}
	$txt = (string) file_get_contents( $arq );
	$novo = preg_replace( '#(https?://)?[a-z0-9-]+\.hostingersite\.com#i', 'https://' . TPI_DOMINIO_OFICIAL, $txt );
	// Linha do "Agent (MCP protocol)" da Hostinger aponta para o domínio provisório: sai.
	$novo = preg_replace( '#^- \[Agent \(MCP protocol\)\]\([^)]*\)\s*$#mi', '', (string) $novo );
	$novo = preg_replace( "/## Optional\s*\n\s*\n(\[comment\])/", '$1', (string) $novo );
	if ( is_string( $novo ) && $novo !== $txt ) {
		file_put_contents( $arq, $novo );
	}
}
add_action( 'save_post', function () {
	add_action( 'shutdown', 'tpi_conserta_llms', 999 );
}, 999 );
add_action( 'init', function () {
	if ( ! get_transient( 'tpi_llms_conferido' ) ) {
		set_transient( 'tpi_llms_conferido', 1, DAY_IN_SECONDS );
		add_action( 'shutdown', 'tpi_conserta_llms', 999 );
	}
} );
