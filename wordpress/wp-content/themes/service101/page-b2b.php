<?php get_header();
while (have_posts()) { the_post(); echo s101_markup(get_the_content()); }
s101_state(); get_footer();
