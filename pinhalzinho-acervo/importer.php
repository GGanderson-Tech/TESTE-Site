<?php
if (!defined('ABSPATH')) { exit; }
function ph_manifest() {
    static $cached=null;if ($cached!==null) { return $cached; }
    $base=defined('PH_ACERVO_PATH') ? PH_ACERVO_PATH : __DIR__.'/acervo';
    $file=$base.'/manifest.json';
    if (!is_readable($file)) { throw new RuntimeException('Pacote acervo/manifest.json ausente. Extraia o acervo junto do plugin.'); }
    $data=json_decode(file_get_contents($file),true);
    if (isset($data['collection_complete']) && !$data['collection_complete'] && wp_parse_url(home_url(),PHP_URL_HOST)!=='127.0.0.1') { throw new RuntimeException('A coleta ainda está em andamento; este manifesto parcial serve apenas à homologação local.'); }
    if (!$data || empty($data['pages'])) { throw new RuntimeException('Manifesto inválido.'); }
    $cached=array($base,$data);return $cached;
}
/** Reconstitui PDFs grandes em uploads; nunca exige escrita na pasta do plugin. */
function ph_materialize_asset($base,$item,$target) {
    $root=realpath($base);$source=realpath($base.'/'.$item['local']);
    if ($source) {
        if (strpos($source,$root.DIRECTORY_SEPARATOR)!==0 || hash_file('sha256',$source)!==$item['sha256']) { throw new RuntimeException('Checksum da origem: '.$item['path']); }
        if (!copy($source,$target)) { throw new RuntimeException('Falha ao copiar '.$item['path']); }
        return;
    }
    if (empty($item['chunks'])) { throw new RuntimeException('Arquivo ausente: '.$item['path']); }
    $temp=$target.'.ph-importing';$writer=fopen($temp,'wb');
    if (!$writer) { throw new RuntimeException('Sem permissão de escrita em uploads.'); }
    try {
        foreach ($item['chunks'] as $part) {
            $file=realpath($base.'/'.$part['local']);
            if (!$file || strpos($file,$root.DIRECTORY_SEPARATOR)!==0 || hash_file('sha256',$file)!==$part['sha256']) { throw new RuntimeException('Parte ausente ou checksum inválido: '.$part['local']); }
            $reader=fopen($file,'rb');if (!$reader) { throw new RuntimeException('Falha ao ler parte.'); }
            try { $copied=stream_copy_to_stream($reader,$writer); } finally { fclose($reader); }
            if ($copied!==$part['bytes']) { throw new RuntimeException('Parte incompleta: '.$part['local']); }
        }
        fclose($writer);$writer=null;
        if (hash_file('sha256',$temp)!==$item['sha256']) { throw new RuntimeException('Checksum do documento reconstituído inválido.'); }
        if (!rename($temp,$target)) { throw new RuntimeException('Falha ao concluir documento.'); }
    } finally { if (is_resource($writer)) { fclose($writer); } if (file_exists($temp)) { unlink($temp); } }
}
function ph_import_batch() {
    $lock=get_option('ph_import_lock');
    if ($lock && microtime(true)-(float)$lock>300) { delete_option('ph_import_lock'); }
    if (!add_option('ph_import_lock',microtime(true),'',false)) { throw new RuntimeException('Outro lote está em andamento. Aguarde e tente novamente.'); }
    try { return ph_import_batch_unlocked(); } finally { delete_option('ph_import_lock'); }
}
function ph_import_batch_unlocked() {
    list($base,$data)=ph_manifest();
    $state=get_option('ph_import_state',array('asset'=>0,'page'=>0,'errors'=>array(),'done'=>false));
    if (!empty($state['errors'])) { $state=array('asset'=>0,'page'=>0,'document'=>0,'errors'=>array(),'done'=>false); }
    if ($state['done']) { return $state; }
    if (!isset($state['document'])) { $state['document']=0; }
    require_once ABSPATH.'wp-admin/includes/image.php';
    $assets=get_option('ph_asset_map',array());$map=get_option('ph_source_map',array());
    for ($n=0;$n<12 && $state['asset']<count($data['assets']);$n++,$state['asset']++) {
        $item=$data['assets'][$state['asset']];$source=realpath($base.'/'.$item['local']);
        if (isset($assets[$item['path']]) && get_post($assets[$item['path']])) { continue; }
        
        $upload=wp_upload_dir();if (!empty($upload['error'])) { throw new RuntimeException($upload['error']); }
        $name=wp_unique_filename($upload['path'],basename($item['local']));$target=$upload['path'].'/'.$name;
        try { ph_materialize_asset($base,$item,$target); } catch (Throwable $e) { $state['errors'][]=$e->getMessage();continue; }
        $mime=wp_check_filetype($name);if (!$mime['type']) { unlink($target);$state['errors'][]='Tipo não suportado: '.$item['path'];continue; }
        $id=wp_insert_attachment(array('post_mime_type'=>$mime['type'],'post_title'=>sanitize_text_field(rawurldecode(basename($item['path']))),'post_status'=>'inherit'),$target,0,true);
        if (is_wp_error($id)) { $state['errors'][]=$id->get_error_message();continue; }
        update_post_meta($id,'_ph_source_url',$item['url']);update_post_meta($id,'_ph_sha256',$item['sha256']);
        $assets[$item['path']]=$id;update_option('ph_asset_map',$assets,false);
        if (wp_attachment_is_image($id)) { wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$target)); }
    }
    update_option('ph_asset_map',$assets,false);
    if ($state['asset']>=count($data['assets'])) {
        for ($n=0;$n<10 && $state['page']<count($data['pages']);$n++,$state['page']++) {
            $item=$data['pages'][$state['page']];if (isset($map[$item['path']]) && get_post($map[$item['path']])) { continue; }
            $type=$item['type'] ?? 'ph_documento';$html=wp_kses_post($item['clean_html'] ?? '');
            $html.='<p class="source-note">Conteúdo preservado do portal institucional. <a href="'.esc_url($item['url']).'">Consultar a fonte original</a>. A data de importação não representa a data do ato.</p>';
            $id=wp_insert_post(array('post_type'=>$type,'post_title'=>sanitize_text_field($item['title']),'post_content'=>$html,'post_status'=>'publish'),true);
            if (is_wp_error($id)) { $state['errors'][]=$id->get_error_message();continue; }
            update_post_meta($id,'_ph_portrait_angle',isset($item['portrait_angle']) ? $item['portrait_angle'] : 0);update_post_meta($id,'_ph_source_url',$item['url']);update_post_meta($id,'_ph_legacy_path',$item['path']);update_post_meta($id,'_ph_collected_at',$data['collected_at']);
            if (!empty($item['session_date'])) { update_post_meta($id,'_ph_session_date',$item['session_date']); }
            if (!empty($item['year'])) { wp_set_object_terms($id,(string)$item['year'],'ph_ano'); }
            if (!empty($item['author'])) { wp_set_object_terms($id,$item['author'],'ph_autor'); }
            if (!empty($item['subject'])) { wp_set_object_terms($id,$item['subject'],'ph_assunto'); }
            foreach ($type==='ph_vereador' ? $item['images'] : array() as $image) { if (isset($assets[$image['src']])) { set_post_thumbnail($id,$assets[$image['src']]);break; } }
            $map[$item['path']]=$id;update_option('ph_source_map',$map,false);
        }
        update_option('ph_source_map',$map,false);
    }
    if ($state['page']>=count($data['pages'])) {
        $records=get_option('ph_file_records',array());
        for ($n=0;$n<24 && $state['document']<count($data['assets']);$n++,$state['document']++) {
            $item=$data['assets'][$state['document']];
            if (strtolower(pathinfo($item['local'],PATHINFO_EXTENSION))!=='pdf' || !isset($assets[$item['path']]) || isset($records[$item['path']])) { continue; }
            $title=rawurldecode(preg_replace('/_([0-9A-Fa-f]{2})/','%$1',basename($item['path'])));
            $title=sanitize_text_field(preg_replace('/\.(pdf|aspx)$/i','',$title));
            $file=wp_get_attachment_url($assets[$item['path']]);
            $content='<p>Documento público preservado do portal da Câmara. O título reproduz o nome do arquivo original; confira o conteúdo do PDF para sua identificação jurídica.</p><p><a class="button button-outline" href="'.esc_url($file).'">Abrir / baixar PDF</a></p><p class="source-note">Fonte: <a href="'.esc_url($item['url']).'">arquivo no portal original</a>. Consulte o PDF para identificação e datas do ato.</p>';
            $id=wp_insert_post(array('post_type'=>'ph_documento','post_title'=>$title,'post_content'=>$content,'post_status'=>'publish'),true);
            if (is_wp_error($id)) { $state['errors'][]=$id->get_error_message();continue; }
            update_post_meta($id,'_ph_source_url',$item['url']);update_post_meta($id,'_ph_attachment_id',$assets[$item['path']]);update_post_meta($id,'_ph_metadata_inferred','filename');
            if (preg_match('/\b(19[0-9]{2}|20[0-9]{2})\b/',$title,$year)) { wp_set_object_terms($id,$year[1],'ph_ano'); }
            $subject='Documentos PDF';foreach (array('lei'=>'Leis','resolu'=>'Resoluções','decreto'=>'Decretos','ato'=>'Atos','contrato'=>'Contratos','edital'=>'Editais') as $term=>$label) { if (stripos($title,$term)!==false) { $subject=$label;break; } }
            wp_set_object_terms($id,$subject,'ph_assunto');$records[$item['path']]=$id;update_option('ph_file_records',$records,false);
        }
        update_option('ph_file_records',$records,false);
    }
    if ($state['page']>=count($data['pages']) && $state['document']>=count($data['assets'])) { $urls=array();foreach ($assets as $path=>$id) { $urls[$path]=wp_get_attachment_url($id); } foreach ($map as $path=>$id) { $urls[$path]=get_permalink($id); } update_option('ph_url_map',$urls,false);ph_configure($map,$assets);$state['done']=empty($state['errors']); }
    update_option('ph_import_state',$state,false);return $state;
}
function ph_configure($map,$assets) {
    if (get_stylesheet()!=='pinhalzinho') { return; }
    if (!get_option('timezone_string')) { update_option('timezone_string','America/Sao_Paulo'); }
    foreach (array('green'=>'#022264','gold'=>'#fef431','transparencia_url'=>'https://pinhalzinho.amendolaeamendola.com.br/transparenciacamara/','ouvidoria_url'=>'https://cmpinhalzinho.flowdocs.com.br:2053/public/home','legislacao_url'=>'https://leismunicipais.com.br/camara/sp/pinhalzinho','sessoes_url'=>get_post_type_archive_link('ph_sessao'),'vereadores_url'=>get_post_type_archive_link('ph_vereador'),'address'=>'Rua Cruzeiro do Sul, 225, Centro — CEP 12995-027','phone'=>'(11) 4018-4286','email'=>'secretaria@camarapinhalzinho.sp.gov.br','hours'=>'Segunda a sexta, das 8h às 11h e das 13h às 16h') as $key=>$value) { if (!get_theme_mod('pinhalzinho_'.$key)) { set_theme_mod('pinhalzinho_'.$key,$value); } }
    if (isset($assets['FAVICOM0.gif']) && !get_theme_mod('custom_logo')) { set_theme_mod('custom_logo',$assets['FAVICOM0.gif']); }
    if (get_option('ph_menu_created')) { return; }
    $locations=get_theme_mod('nav_menu_locations',array());
    if (!empty($locations['primary'])) { return; } // Preserva o menu já escolhido pelo administrador.
    $menu=wp_create_nav_menu('Portal institucional Pinhalzinho');if (is_wp_error($menu)) { return; }
    $groups=array('A Câmara'=>array('Departamentos'=>'departamentos.html','Mesa Diretora'=>'mesadiretora.html','História'=>'historia.html','Símbolos'=>'simbolos.html','Fotos da cidade'=>'fotos.html'),'Vereadores'=>get_post_type_archive_link('ph_vereador'),'Atividade Legislativa'=>array('Sessões e pautas'=>get_post_type_archive_link('ph_sessao'),'Proposituras'=>get_post_type_archive_link('ph_propositura'),'Legislação e acervo'=>get_post_type_archive_link('ph_documento')),'Transparência'=>array('Portal da Transparência'=>'https://pinhalzinho.amendolaeamendola.com.br/transparenciacamara/','Licitações e contratos'=>get_post_type_archive_link('ph_licitacao'),'Diário Oficial'=>'https://www.pinhalzinho.sp.gov.br/diario-oficial-eletronico','Gestão fiscal'=>'gestaofiscal.html','Balancetes'=>'24.html'),'Serviços ao Cidadão'=>array('Ouvidoria e e-SIC'=>'https://cmpinhalzinho.flowdocs.com.br:2053/public/home','Carta de Serviços'=>'https://www.camarapinhalzinho.sp.gov.br/wa_files/Carta_20de_20Servi_C3_A7os_20ao_20Usus_C3_A1rio_202025_20b.pdf'),'Contato'=>'contato.html');
    $resolve=function($path) use ($map,$assets) {
        $base='https://www.camarapinhalzinho.sp.gov.br/';$key=strpos($path,$base)===0 ? substr($path,strlen($base)) : $path;
        if (isset($map[$key])) { return get_permalink($map[$key]); }
        if (isset($assets[$key])) { return wp_get_attachment_url($assets[$key]); }
        return strpos($path,'http')===0 ? $path : $base.$path;
    };
    foreach ($groups as $label=>$items) {
        $parent=wp_update_nav_menu_item($menu,0,array('menu-item-title'=>$label,'menu-item-type'=>'custom','menu-item-url'=>is_array($items) ? $resolve(reset($items)) : $resolve($items),'menu-item-status'=>'publish'));
        if (is_array($items)) { foreach ($items as $title=>$path) { wp_update_nav_menu_item($menu,0,array('menu-item-title'=>$title,'menu-item-type'=>'custom','menu-item-url'=>$resolve($path),'menu-item-parent-id'=>$parent,'menu-item-status'=>'publish')); } }
    }
    $locations['primary']=$menu;set_theme_mod('nav_menu_locations',$locations);update_option('ph_menu_created',true,false);
}
