<?php get_header(); ?>
<?php if (!is_404() && have_posts()) : while (have_posts()) : the_post(); ?>
<section class="section"><div class="container"><h1><?php the_title(); ?></h1><?php the_content(); ?></div></section>
<?php endwhile; else: ?>
<section class="section"><div class="container"><h1>Страница не найдена</h1><p>Устройство ещё не опубликовано или адрес изменён.</p><a class="btn btn-primary" href="<?php echo esc_url(home_url('/')); ?>">На главную</a></div></section>
<?php endif; s101_state(); get_footer(); ?>
