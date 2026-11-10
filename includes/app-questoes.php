<?php
/**
 * Convite para resolver no app as questões do julgado  (0.7.22)
 *
 * - As questões continuam na página (conteúdo indexável). O convite entra logo depois delas, antes dos flashcards,
 *   que é quando a pessoa acabou de responder e quer treinar mais.
 * - Link: /?tp_app=1&julgado=<id do post>&visitante=1. O app abre a Jurisprudência no julgado, com as questões dele
 *   (as do site e as do app). Sem sessão, "visitante=1" entra sem cadastro (o progresso fica no navegador; a conta
 *   criada depois dispara registration_completed no app). Com sessão, o app ignora o "visitante".
 * - Só aparece quando o app sabe abrir o julgado: o plugin do app liga com
 *   add_filter( 'tpi_app_questoes', '__return_true' ). A opção tpi_app_questoes força ("1") ou desliga ("0").
 *   Sem isso, o botão prometeria "as questões deste julgado" e cairia na tela inicial do app.
 * - Medição: clique vira evento "app_questoes_clique" no Google Analytics (Site Kit), sem dado pessoal.
 *   A conversão continua sendo o cadastro no app (registration_completed).
 * - Primeiro toque (tp_origem): a home e o app já gravam; os informativos são a principal entrada do Google,
 *   então gravam também, com a mesma regra, para o cadastro que nasce de um julgado não aparecer como "direto".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', function () {
	register_setting( 'general', 'tpi_app_questoes', array(
		'type'              => 'string',
		'default'           => '',
		'show_in_rest'      => true,
		'sanitize_callback' => function ( $v ) {
			return in_array( (string) $v, array( '1', '0' ), true ) ? (string) $v : '';
		},
	) );
} );

function tpi_app_questoes_ligado() {
	$o = (string) get_option( 'tpi_app_questoes', '' );
	if ( '0' === $o ) {
		return false;
	}
	return '1' === $o || (bool) apply_filters( 'tpi_app_questoes', false );
}

function tpi_app_questoes_url( $id ) {
	return add_query_arg( array( 'julgado' => (int) $id, 'visitante' => 1 ), tpi_supabase()['appUrl'] );
}

function tpi_app_questoes_cta( $id ) {
	$t = 'tpi-app-cta-' . (int) $id;
	return '<aside class="tpi-app-cta" aria-labelledby="' . $t . '">'
		. '<p class="tpi-app-cta__titulo" id="' . $t . '">Quer treinar mais este julgado?</p>'
		. '<p>No app do Tchau Pobreza você resolve as questões deste tema, estas e as que preparamos lá dentro, com a correção na hora e o seu desempenho guardado.</p>'
		. '<p><a class="tpi-btn" href="' . esc_url( tpi_app_questoes_url( $id ) ) . '" rel="nofollow" data-tpi-app-cta="' . (int) $id . '" data-tpi-trib="' . esc_attr( tpi_meta( $id, 'tp_tribunal' ) ) . '">Resolver as questões no app</a></p>'
		. '<p class="tpi-app-cta__micro">Grátis. Dá para começar sem cadastro e criar a conta depois, sem perder o que fez.</p>'
		. '</aside>';
}

/* Entra antes do título "Flashcards para fixar" (âncora fixa do render.py). Sem ele, não entra: a página não tem questões. */
add_filter( 'the_content', function ( $content ) {
	if ( ! is_singular( 'informativo' ) || get_the_ID() !== get_queried_object_id() || 'julgado' !== tpi_meta( get_the_ID(), 'tp_tipo' ) ) {
		return $content;
	}
	if ( ! tpi_app_questoes_ligado() ) {
		return $content;
	}
	$m = array();
	if ( ! preg_match( '/<h2[^>]*\bid="flashcards-para-fixar"/', $content, $m, PREG_OFFSET_CAPTURE ) ) {
		return $content;
	}
	$pos = $m[0][1];
	return substr( $content, 0, $pos ) . tpi_app_questoes_cta( get_the_ID() ) . substr( $content, $pos );
}, 19 );

add_action( 'wp_footer', function () {
	if ( is_admin() || is_feed() || ! ( is_singular( 'informativo' ) || is_post_type_archive( 'informativo' ) || is_tax( 'ramo_direito' ) ) ) {
		return;
	}
	?>
<script id="tpi-app-cta-js" data-no-optimize="1">
(function(){
/* Origem do aluno (primeiro toque, sem dado pessoal): mesma regra de src/platform/origem.ts do app e da home. */
try{if(!localStorage.getItem("tp_origem")){var q=new URLSearchParams(location.search),h="";try{h=document.referrer?new URL(document.referrer).hostname.toLowerCase():""}catch(e){}if(/(^|\.)tchaupobreza\.com\.br$/.test(h))h="";var c=function(v,m){return String(v||"").trim().toLowerCase().replace(/[^\w.:+\/-]+/g,"_").slice(0,m||80)},o=null,at=new Date().toISOString(),l=c(location.pathname,100)||"/";if(c(q.get("utm_source"))){o={source:c(q.get("utm_source")),medium:c(q.get("utm_medium"))||"(sem)",at:at,landing:l};["campaign","content","term"].forEach(function(k){var v=c(q.get("utm_"+k));if(v)o[k]=v});if(h)o.ref=c(h,100)}else if(q.get("gclid")){o={source:"google",medium:"cpc",at:at,landing:l}}else if(h){var b=/(^|\.)(google|bing|duckduckgo|yahoo|ecosia|yandex)\./.exec(h);o=b?{source:b[2],medium:"organic",ref:c(h,100),at:at,landing:l}:{source:c(h.replace(/^www\./,""),100),medium:"referral",ref:c(h,100),at:at,landing:l}}if(o)localStorage.setItem("tp_origem",JSON.stringify(o))}}catch(e){}
document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest("a[data-tpi-app-cta]");if(!a)return;
if(typeof window.gtag==="function")window.gtag("event","app_questoes_clique",{julgado_id:a.getAttribute("data-tpi-app-cta"),tribunal:a.getAttribute("data-tpi-trib")||""});});
})();
</script>
	<?php
}, 30 );
