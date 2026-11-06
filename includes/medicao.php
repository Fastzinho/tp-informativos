<?php
/**
 * Medição de conversões dos anúncios do ChatGPT (pixel da OpenAI, "oaiq") com consentimento.  (0.7.13)
 *
 * - O pixel só mede depois que a pessoa aceita no aviso de cookies (LGPD; guia de cookies da ANPD).
 *   Antes disso roda oaiq("consent", false), que não envia nada e não grava cookie.
 * - Script assíncrono no <head>, um só por página, em todas as páginas públicas. Fica fora para quem
 *   pode editar o site (não suja as conversões com os testes da equipe).
 * - Conversão: "lead_created" quando a inscrição na newsletter dá certo (envio pelo formulário ou
 *   chegada em /newsletter/?inscricao=ok). Também envia "page_viewed" em cada página, com consentimento.
 * - Google Analytics (Site Kit): Consent Mode v2. analytics_storage segue a mesma escolha; os sinais de anúncio do Google
 *   ficam sempre negados (não anunciamos no Google). Sem aceite, o GA manda só pings sem cookie.
 * - O HTML sai igual para todos (cache do LiteSpeed ok); a escolha fica no localStorage (tpi_cookies).
 * - Reabrir o aviso: qualquer link para #preferencias-cookies (ex.: na política de privacidade ou no rodapé).
 *
 * Opção tpi_oaiq_pixel: ID do pixel (vazio desliga tudo, inclusive o aviso). Editável pela REST API (settings).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TPI_OAIQ_PADRAO', '3T8SiyXgd7M6vyx1rvnhHg' );

add_action( 'init', function () {
	register_setting( 'general', 'tpi_oaiq_pixel', array(
		'type'              => 'string',
		'default'           => TPI_OAIQ_PADRAO,
		'show_in_rest'      => true,
		'sanitize_callback' => function ( $v ) {
			return preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $v );
		},
	) );
} );

function tpi_oaiq_pixel() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || current_user_can( 'edit_posts' ) ) {
		return '';
	}
	return (string) apply_filters( 'tpi_oaiq_pixel', get_option( 'tpi_oaiq_pixel', TPI_OAIQ_PADRAO ) );
}

/* Cabeçalho: fila do oaiq + consentimento + init, antes de qualquer outro script (prioridade 1). */
add_action( 'wp_head', function () {
	$id = tpi_oaiq_pixel();
	if ( '' === $id ) {
		return;
	}
	?>
<script id="tpi-oaiq">
!function(w,d,s,u){if(w.oaiq)return;var q=function(){q.q.push(arguments)};q.q=[];w.oaiq=q;var j=d.createElement(s);j.async=1;j.src=u;var f=d.getElementsByTagName(s)[0];f.parentNode.insertBefore(j,f)}(window,document,"script","https://bzrcdn.openai.com/sdk/oaiq.min.js");
(function(){var c=null;try{c=localStorage.getItem("tpi_cookies")}catch(e){}
window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments)};
gtag("consent","default",{analytics_storage:c==="sim"?"granted":"denied",ad_storage:"denied",ad_user_data:"denied",ad_personalization:"denied",wait_for_update:500});
oaiq("consent",c==="sim");oaiq("init",{pixelId:<?php echo wp_json_encode( $id ); ?>});if(c==="sim")oaiq("measure","page_viewed",{type:"contents"});})();
</script>
	<?php
}, 1 );

/* Rodapé: aviso de cookies (só aparece sem escolha salva ou ao clicar em #cookies) e disparo da conversão. */
add_action( 'wp_footer', function () {
	if ( '' === tpi_oaiq_pixel() ) {
		return;
	}
	$politica = esc_url( home_url( '/privacidade/#cookies' ) );
	?>
<div id="tpi-cookies" class="tpi-cookies" role="region" aria-label="Aviso de cookies" hidden>
	<p class="tpi-cookies__texto">Usamos cookies de medição para contar as visitas e saber se os nossos anúncios funcionam. Eles só são ativados se você aceitar. <a href="<?php echo $politica; ?>">Saiba mais</a>.</p>
	<div class="tpi-cookies__acoes">
		<button type="button" class="tpi-cookies__btn tpi-cookies__btn--sim" data-tpi-cookies="sim">Aceitar</button>
		<button type="button" class="tpi-cookies__btn" data-tpi-cookies="nao">Recusar</button>
	</div>
</div>
<style id="tpi-cookies-css">
.tpi-cookies{position:fixed;left:16px;right:16px;bottom:16px;z-index:99999;max-width:560px;margin:0 auto;background:#fff;color:#1f2a26;border:1px solid #d6d2c4;border-radius:12px;box-shadow:0 6px 24px rgba(0,0,0,.15);padding:16px;font:15px/1.5 Manrope,system-ui,sans-serif}
.tpi-cookies[hidden]{display:none}
.tpi-cookies__texto{margin:0 0 12px}
.tpi-cookies__texto a{color:#2c5748;text-decoration:underline}
.tpi-cookies__acoes{display:flex;gap:8px;flex-wrap:wrap}
.tpi-cookies__btn{min-height:44px;padding:8px 20px;border-radius:8px;border:2px solid #2c5748;background:#fff;color:#2c5748;font:inherit;font-weight:700;cursor:pointer}
.tpi-cookies__btn--sim{background:#2c5748;color:#fff}
.tpi-cookies__btn:focus-visible{outline:3px solid #b9955b;outline-offset:2px}
</style>
<script id="tpi-cookies-js">
(function(){
	var aviso=document.getElementById("tpi-cookies");if(!aviso||!window.oaiq)return;
	function ler(){try{return localStorage.getItem("tpi_cookies")}catch(e){return null}}
	function abrir(){aviso.hidden=false;var b=aviso.querySelector("button");if(b)b.focus()}
	if(!ler())aviso.hidden=false;
	aviso.addEventListener("click",function(e){
		var b=e.target.closest("[data-tpi-cookies]");if(!b)return;
		var v=b.getAttribute("data-tpi-cookies"),antes=ler();
		try{localStorage.setItem("tpi_cookies",v)}catch(err){}
		oaiq("consent",v==="sim");
		if(window.gtag)gtag("consent","update",{analytics_storage:v==="sim"?"granted":"denied"});
		if(v==="sim"&&antes!=="sim")oaiq("measure","page_viewed",{type:"contents"});
		aviso.hidden=true;
	});
	document.addEventListener("click",function(e){var a=e.target.closest('a[href$="#preferencias-cookies"]');if(a){e.preventDefault();abrir()}});
	if(location.hash==="#preferencias-cookies")abrir();
	function lead(){if(ler()==="sim")oaiq("measure","lead_created",{type:"customer_action"})}
	document.addEventListener("tpi:newsletter-inscrito",lead);
	if(/[?&]inscricao=ok(&|$)/.test(location.search))lead();
})();
</script>
	<?php
}, 20 );
