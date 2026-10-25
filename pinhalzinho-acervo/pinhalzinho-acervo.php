<?php
/**
 * Plugin Name: Pinhalzinho — Acervo Legislativo
 * Description: Tipos de conteúdo, filtros e importação verificável do acervo público do portal legado.
 * Version: 2.0.0
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 */
if (!defined('ABSPATH')) { exit; }
function ph_types() {
    foreach (array('ph_documento'=>'Acervo e legislação','ph_vereador'=>'Vereadores','ph_sessao'=>'Sessões','ph_licitacao'=>'Licitações e contratos','ph_propositura'=>'Proposituras') as $type=>$label) {
        register_post_type($type,array('labels'=>array('name'=>$label,'singular_name'=>$label),'public'=>true,'has_archive'=>true,'show_in_rest'=>true,'supports'=>array('title','editor','thumbnail','excerpt','custom-fields'),'rewrite'=>array('slug'=>str_replace('ph_','',$type))));
    }
    register_taxonomy('ph_assunto',array('ph_documento','ph_propositura','ph_licitacao','ph_sessao'),array('label'=>'Tipo de documento','public'=>true,'show_in_rest'=>true,'hierarchical'=>true));
    register_taxonomy('ph_autor',array('ph_propositura'),array('label'=>'Vereador / autor','public'=>true,'show_in_rest'=>true));
    register_taxonomy('ph_ano',array('ph_documento','ph_propositura','ph_licitacao','ph_sessao'),array('label'=>'Ano','public'=>true,'show_in_rest'=>true));
}
add_action('init','ph_types');
register_activation_hook(__FILE__,function(){ph_types();flush_rewrite_rules();});
function ph_local_links($content) {
    if (is_admin()) { return $content; }
    static $replace=null;
    if ($replace===null) {
        $replace=array();
        foreach (get_option('ph_url_map',array()) as $path=>$url) {
            foreach (array($path,rawurldecode($path)) as $key) {
                $replace['href="https://www.camarapinhalzinho.sp.gov.br/'.$key.'"']='href="'.esc_url($url).'"';
                $replace['src="https://www.camarapinhalzinho.sp.gov.br/'.$key.'"']='src="'.esc_url($url).'"';
            }
        }
    }
    $notes=array();
    $content=preg_replace_callback('/<p class="source-note">.*?<\/p>/s',function($match) use (&$notes){$key='PH_SOURCE_NOTE_'.count($notes);$notes[$key]=$match[0];return $key;},$content);
    return strtr(strtr($content,$replace),$notes);
}
add_filter('the_content','ph_local_links',15);
function ph_old_redirect() {
    $path=ltrim((string)wp_parse_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '',PHP_URL_PATH),'/');
    $prefix=ltrim((string)wp_parse_url(home_url('/'),PHP_URL_PATH),'/');if ($prefix && strpos($path,$prefix)===0) { $path=substr($path,strlen($prefix)); }
    if (!$path || $path==='index.html') { if ($path==='index.html') { wp_safe_redirect(home_url('/'),301);exit; } return; }
    $map=get_option('ph_source_map',array());$assets=get_option('ph_asset_map',array());
    if (isset($map[$path])) { wp_safe_redirect(get_permalink($map[$path]),301);exit; }
    if (isset($assets[$path])) { wp_safe_redirect(wp_get_attachment_url($assets[$path]),301);exit; }
}
add_action('template_redirect','ph_old_redirect',1);
function ph_filters($query) {
    if (is_admin() || !$query->is_main_query() || ! $query->is_post_type_archive(array('ph_documento','ph_propositura','ph_licitacao','ph_sessao'))) { return; }
    $tax=array();
    foreach (array('ph_ano','ph_assunto','ph_autor') as $name) { if (!empty($_GET[$name])) { $tax[]=array('taxonomy'=>$name,'field'=>'slug','terms'=>sanitize_text_field(wp_unslash($_GET[$name]))); } }
    if ($tax) { $query->set('tax_query',$tax); }
    if (!empty($_GET['q'])) { $query->set('s',sanitize_text_field(wp_unslash($_GET['q']))); }
    $query->set('posts_per_page',12);$query->set('orderby','title');$query->set('order','ASC');
}
add_action('pre_get_posts','ph_filters');
require_once __DIR__.'/importer.php';
add_action('admin_menu',function(){add_management_page('Importar acervo Pinhalzinho','Acervo Pinhalzinho','manage_options','ph-acervo','ph_import_screen');});
function ph_import_screen() {
    if (!current_user_can('manage_options')) { return; }
    echo '<div class="wrap"><h1>Acervo público de Pinhalzinho</h1><p>Importa a cópia coletada do portal, sem sobrescrever conteúdos existentes. Use primeiro em homologação. Os itens preservam a fonte; datas de coleta não são datas de publicação dos atos. O pacote acervo deve estar dentro da pasta deste plugin.</p><button class="button button-primary" id="ph-start">Importar acervo e configurar navegação</button><pre id="ph-progress" role="status" style="white-space:pre-wrap"></pre></div>';
    $nonce=wp_create_nonce('ph_import');
    ?><script>document.getElementById('ph-start').addEventListener('click',async function(){this.disabled=true;const log=document.getElementById('ph-progress');try{let done=false;while(!done){const data=new URLSearchParams({action:'ph_import',_ajax_nonce:<?php echo wp_json_encode($nonce); ?>});const response=await fetch(ajaxurl,{method:'POST',body:data});const result=await response.json();if(!result.success)throw new Error(result.data);log.textContent=JSON.stringify(result.data,null,2);done=result.data.done;} }catch(e){log.textContent+='\n'+e.message;}finally{this.disabled=false;}});</script><?php
}
add_action('wp_ajax_ph_import',function(){check_ajax_referer('ph_import');if (!current_user_can('manage_options')) { wp_send_json_error('Acesso negado',403); } try { wp_send_json_success(ph_import_batch()); } catch (Throwable $e) { wp_send_json_error($e->getMessage()); }});
add_action('add_meta_boxes',function(){
    add_meta_box('ph-session-date','Data e horário da sessão',function($post){
        wp_nonce_field('ph_session_date','ph_session_nonce');
        $value=get_post_meta($post->ID,'_ph_session_date',true);
        echo '<p><label for="ph-session-date-field">Data e horário local do site</label></p><input id="ph-session-date-field" type="datetime-local" name="ph_session_date" value="'.esc_attr($value ? substr(str_replace(' ','T',$value),0,16) : '').'"><p>Usado para destacar a próxima sessão na página inicial. Confira também a pauta e o fuso horário nas configurações do WordPress.</p>';
    },'ph_sessao','side');
});
add_action('save_post_ph_sessao',function($id){
    if (empty($_POST['ph_session_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['ph_session_nonce'])),'ph_session_date') || !current_user_can('edit_post',$id) || wp_is_post_autosave($id) || wp_is_post_revision($id)) { return; }
    $value=isset($_POST['ph_session_date']) ? sanitize_text_field(wp_unslash($_POST['ph_session_date'])) : '';
    if ($value==='') { delete_post_meta($id,'_ph_session_date');return; }
    $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value,wp_timezone());
    if ($date && $date->format('Y-m-d\TH:i')===$value) { update_post_meta($id,'_ph_session_date',$date->format('Y-m-d H:i:s')); }
});
