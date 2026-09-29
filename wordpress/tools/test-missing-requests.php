<?php
/** WP-CLI eval-file integration check; synthetic rows are removed even on failure. */
use Service101\Requests;
if (wp_get_environment_type()!=='staging' || !current_user_can('manage_options')) {
    throw new RuntimeException('Staging administrator only.');
}
function verify_missing_request(bool $condition,string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo 'PASS '.$message."\n";
}
Requests::maybe_upgrade();
global $wpdb;
$table=Requests::table(); $ids=[];
$category=get_terms(['taxonomy'=>'s101_category','hide_empty'=>false,'number'=>1]);
$input=['Имя'=>'QA synthetic enquiry','Телефон'=>'8 (999) 000-00-01','Бренд'=>'','Модель'=>'',
    'Категория'=>$category && !is_wp_error($category)?$category[0]->slug:'','Комментарий'=>'Synthetic test only',
    'Страница'=>home_url('/remont/'),'_request_id'=>wp_generate_uuid4()];
$filters=['source'=>'test','from'=>'2000-01-02','to'=>'2000-01-02'];
$before=Requests::report($filters);
$brand='QA-'.substr(wp_generate_uuid4(),0,8);
try {
    $normalized=Requests::normalize($input);
    verify_missing_request($normalized['phone']==='+79990000001' && $normalized['brand']==='' && $normalized['model']==='',
        'optional brand and model accepted; phone normalized to +7');
    verify_missing_request(Requests::phone('9990000001')==='+79990000001','ten-digit phone is normalized');
    foreach (['Имя','Телефон','Бренд','Модель','Комментарий','Категория','Страница','_request_id'] as $field) {
        $bad=$input; $bad[$field]=['unexpected']; $blocked=false;
        try { Requests::normalize($bad); } catch (InvalidArgumentException) { $blocked=true; }
        verify_missing_request($blocked,'array input rejected for '.$field);
    }
    foreach (['+7','+1 555 123 4567','799900000010','hello9990000001'] as $phone) {
        $blocked=false; try { Requests::phone($phone); } catch (InvalidArgumentException) { $blocked=true; }
        verify_missing_request($blocked,'incomplete or invalid phone rejected');
    }
    $bad=$input; $bad['Страница']='https://example.org/'; $blocked=false;
    try { Requests::normalize($bad); } catch (InvalidArgumentException) { $blocked=true; }
    verify_missing_request($blocked,'off-site source page rejected');
    $created=Requests::create($input); $ids[]=$created['id'];
    $duplicate=Requests::create($input);
    verify_missing_request(!$created['duplicate'] && $duplicate['duplicate'] && $duplicate['id']===$created['id'],
        'retry with the same request key creates one enquiry');
    $changed=$input; $changed['Модель']='Changed'; $blocked=false;
    try { Requests::create($changed); } catch (InvalidArgumentException) { $blocked=true; }
    verify_missing_request($blocked,'reusing a key for different data is rejected');
    for ($i=1;$i<27;$i++) {
        $row=$input; $row['_request_id']=wp_generate_uuid4(); $row['Бренд']=$brand; $row['Модель']='QA model';
        $created=Requests::create($row); $ids[]=$created['id'];
    }
    foreach ($ids as $id) { $wpdb->update($table,['created_at'=>'2000-01-02 02:00:00'],['id'=>$id],['%s'],['%d']); }
    $report=Requests::report($filters);
    verify_missing_request($report['counts']['total']===$before['counts']['total']+27 && $report['counts']['new']===$before['counts']['new']+27,
        'analytics counts every saved test enquiry');
    $brand_row=array_values(array_filter($report['top']['brand'],static fn($r)=>$r['brand']===$brand));
    verify_missing_request(count($brand_row)===1 && (int)$brand_row[0]['quantity']===26,'brand aggregation counts grouped requests');
    $blank=array_values(array_filter($report['top']['brand'],static fn($r)=>$r['brand']===''));
    verify_missing_request(count($blank)===1 && (int)$blank[0]['quantity']>=1,'unspecified brand remains visible in analytics');
    verify_missing_request(count($report['rows'])===25 && $report['pages']>=2,'request table is paginated');
    $previous_get=$_GET; $_GET=$filters;
    ob_start();
    try { Requests::page(); $html=(string)ob_get_contents(); }
    finally { ob_end_clean(); $_GET=$previous_get; }
    verify_missing_request(str_contains($html,'paged=2') && !str_contains($html,'paged=%25'),
        'admin pagination generates usable links');
    $live=Requests::report(array_merge($filters,['source'=>'live']));
    verify_missing_request(!array_intersect($ids,array_column($live['rows'],'id')),'staging requests are excluded from real requests');
    Requests::update_status($ids[0],'in_progress');
    $report=Requests::report($filters);
    verify_missing_request($report['counts']['in_progress']===$before['counts']['in_progress']+1 && $report['counts']['new']===$before['counts']['new']+26,
        'status update moves an enquiry between summary counts');
    Requests::mail_result($ids[0],false);
    verify_missing_request($wpdb->get_var($wpdb->prepare("SELECT mail_state FROM $table WHERE id=%d",$ids[0]))==='failed',
        'failed email leaves the enquiry saved for staff');
    $deny=static function(array $caps): array { $caps['manage_s101_catalog']=false; return $caps; };
    add_filter('user_has_cap',$deny);
    try {
        $blocked=false; try { Requests::update_status($ids[0],'done'); } catch (RuntimeException) { $blocked=true; }
        verify_missing_request($blocked,'status changes require catalog permission');
        $blocked=false; try { Requests::report($filters); } catch (RuntimeException) { $blocked=true; }
        verify_missing_request($blocked,'contact rows and analytics require catalog permission');
    } finally { remove_filter('user_has_cap',$deny); }
    $blocked=false; try { Requests::update_status($ids[0],'unexpected'); } catch (InvalidArgumentException) { $blocked=true; }
    verify_missing_request($blocked,'unknown status rejected');
    $invalid=Requests::filters(['from'=>['x'],'to'=>'2000-99-99','source'=>['x'],'status'=>['x'],'paged'=>['x']]);
    verify_missing_request($invalid['from']==='' && $invalid['to']==='' && $invalid['status']==='' && $invalid['page']===1,
        'malformed report filters are ignored safely');
} finally {
    foreach ($ids as $id) { $wpdb->delete($table,['id'=>$id],['%d']); }
}
verify_missing_request(Requests::report($filters)['counts']===$before['counts'],'all synthetic requests removed');
