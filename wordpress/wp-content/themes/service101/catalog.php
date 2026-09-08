<?php
use Service101\Catalog;
use Service101\Routes;
$device=Routes::$device;
if (!$device) { status_header(404); get_template_part('index'); return; }
$copy=s101_copy(); $category=$copy['categories'][$device['category_slug']]??['title'=>$device['category'],'subtitle'=>'','intro'=>''];
$info=$copy['info'][$device['category_slug']]??reset($copy['info']);
$devices=Catalog::devices(Routes::can_preview()); $prices=array_values(Catalog::prices($device['code'],false));
$categories=[]; $brands=[]; $models=[];
foreach ($devices as $row) {
    $categories[$row['category_slug']]=$row['category'];
    if ($row['category_slug']===$device['category_slug']) { $brands[$row['brand_slug']]??=$row; if ($row['brand_slug']===$device['brand_slug']) { $models[]=$row; } }
}
$categories=array_replace(array_intersect_key(array_map(static fn($item)=>$item['title'],$copy['categories']),$categories),$categories);
$title=s101_device_name($device); $image=!empty($device['image1_id'])?wp_get_attachment_image_url($device['image1_id'],'large'):'';
get_header();
?>
<div class="page-shell">
<section class="catalog-top"><div class="container"><div class="selection-shell"><div class="selection-shell__main">
  <div class="category-scroller" data-category-scroller>
    <button class="category-scroller__button category-scroller__button--prev" type="button" aria-label="Предыдущие категории">‹</button>
    <div class="catalog-tabs" data-category-row data-horizontal-scroll>
      <?php foreach ($categories as $slug=>$label): ?>
      <a class="tab tab--<?php echo esc_attr($slug); ?> <?php echo $slug===$device['category_slug']?'active':''; ?>" href="<?php echo esc_url(home_url('/remont/'.$slug.'/')); ?>"><span class="tab-icon tab-icon--<?php echo esc_attr($slug); ?>" aria-hidden="true"><?php echo $copy['icons'][$slug]??$copy['icons']['kompyutery']; ?></span><span><?php echo esc_html($slug==='pristavki'?'Приставки и консоли':$label); ?></span></a>
      <?php endforeach; ?>
    </div>
    <button class="category-scroller__button category-scroller__button--next" type="button" aria-label="Следующие категории">›</button>
  </div>
  <div class="catalog-controls"><div><p class="control-label">Бренд / тип устройства</p><div class="chip-row brand-chip-row" data-horizontal-scroll>
    <?php foreach ($brands as $slug=>$row): ?><a class="chip <?php echo $slug===$device['brand_slug']?'active':''; ?>" href="<?php echo esc_url(home_url($row['path'])); ?>"><?php echo esc_html($row['brand']); ?></a><?php endforeach; ?>
  </div></div><div><p class="control-label">Модель</p><div class="model-scroller" data-model-scroller>
    <button class="model-scroller__button model-scroller__button--prev" type="button" aria-label="Предыдущие модели">‹</button>
    <div class="chip-row model-chip-row" data-model-row data-horizontal-scroll>
    <?php foreach ($models as $row): ?><a class="chip <?php echo $row['code']===$device['code']?'active':''; ?>" href="<?php echo esc_url(home_url($row['path'])); ?>"><?php echo esc_html($row['name']); ?></a><?php endforeach; ?>
    </div><button class="model-scroller__button model-scroller__button--next" type="button" aria-label="Следующие модели">›</button>
  </div></div></div>
</div><div class="brand-counter" data-brand-counter><span class="brand-counter__label">Отремонтировано</span><strong data-brand-counter-value>8 545</strong><span class="brand-counter__brand">устройств всего</span></div></div></div></section>
<section class="section catalog-section"><div class="container"><div class="device-workspace">
  <article class="device-card"><div class="device-card__media">
    <?php if ($image): ?><img src="<?php echo esc_url($image); ?>" alt="<?php echo esc_attr($title); ?>" loading="eager"><?php else: ?><div class="device-card__placeholder" aria-hidden="true"></div><?php endif; ?>
  </div><div class="device-card__body"><p class="device-kicker"><?php echo esc_html($category['title']); ?></p><h1 class="device-title"><?php echo esc_html(Routes::$onsite?'Выездной ремонт: '.$title:$title); ?></h1>
  <p class="device-description"><?php echo esc_html(Routes::$onsite?'Мастер приедет домой, в офис или на производство. Возможен ремонт на месте или доставка устройства в сервис после диагностики.':($device['description']?:$category['intro'])); ?></p>
  <div class="device-facts"><span><strong>от 30 мин</strong> типовой ремонт</span><span><strong>до 12 мес</strong> гарантия</span></div></div></article>
  <div class="prices-column"><article class="prices-panel"><div class="prices-panel__head"><div><p class="eyebrow">Цена работы без детали</p><p><?php echo esc_html($category['subtitle']); ?></p></div><span class="warranty-pill">Гарантия до 12 мес</span></div>
    <div class="price-list"><div class="price-list__head" aria-hidden="true"><span>Работа и срок</span><span>Средняя цена с деталью</span><span></span></div>
    <?php foreach ($prices as $index=>$price): if ($index===7): ?><div class="extra-services" hidden><?php endif; ?>
      <div class="price-row" data-service-id="<?php echo esc_attr($price['device_code'].'|'.$price['service_code']); ?>"><div class="price-row__name"><div class="price-row__title"><?php echo esc_html($price['name']); ?> <?php if ($price['badge']): ?><span class="badge"><?php echo esc_html($price['badge']); ?></span><?php endif; ?></div><div class="price-row__time"><?php echo esc_html($price['time']?:'по согласованию'); ?></div><div class="price-row__desc"><?php echo esc_html($price['description']); ?></div></div>
      <div class="price-row__price <?php echo $price['total_type']==='Бесплатно'?'free':''; ?>"><span class="price-row__caption">С деталью</span><?php echo esc_html(Catalog::price_text($price['total_type'],$price['total_amount'])); ?></div><button class="select-service" type="button">Выбрать</button></div>
    <?php endforeach; if (count($prices)>7): ?></div><button class="expand-services" type="button" data-expanded="false">Раскройте весь список услуг</button><?php endif; ?>
    <?php if (!$prices): ?><p class="section-text">Услуги и цены уточняются. Позвоните нам для консультации.</p><?php endif; ?>
    </div></article>
    <aside class="device-info" aria-label="Полезная информация перед ремонтом"><div class="device-info__copy"><p class="eyebrow">Важно знать</p><h3><?php echo esc_html($info['title']); ?></h3><p><?php echo esc_html($info['text']); ?></p></div><ul><?php foreach($info['items'] as $item): ?><li><?php echo esc_html($item); ?></li><?php endforeach; ?></ul><p class="device-info__price">Стоимость работы указана без детали. Полную цену с запчастью мастер подтвердит после диагностики или уточнения модели. Можно выбрать несколько услуг и отправить одну заявку — с вами сразу свяжется нужный мастер.</p></aside>
  </div>
</div></div></section>
<?php s101_reference('contact'); ?>
</div>
<noscript><style>.extra-services[hidden]{display:block}.expand-services,.select-service{display:none}</style></noscript>
<?php
$records=[];
foreach ($prices as $price) {
    $records[]=['id'=>$price['device_code'].'|'.$price['service_code'],'position'=>$price['name'],'cost'=>Catalog::price_text($price['work_type'],$price['work_amount']),'averageCost'=>Catalog::price_text($price['total_type'],$price['total_amount']),'description'=>$price['description'],'image'=>$image,'category_slug'=>$device['category_slug'],'category_title'=>$device['category_title'],'brand_slug'=>$device['brand_slug'],'brand'=>$device['brand'],'model_slug'=>$device['model_slug'],'model'=>$device['name'],'time'=>$price['time'],'badge'=>$price['badge']];
}
s101_state(['page'=>Routes::$onsite?'onsite':'model','serverRendered'=>true,'category'=>$device['category_slug'],'brand'=>$device['brand_slug'],'model'=>$device['model_slug'],'services'=>$records,'device'=>['categorySlug'=>$device['category_slug'],'category'=>$category['title'],'brand'=>$device['brand'],'model'=>$title,'image'=>$image]]);
get_footer();
