<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1.0"><?php wp_head(); ?></head>
<body class="<?php echo esc_attr(is_front_page()?'home-page home-page--light':(is_page('b2b')?'inner-page b2b-page':'inner-page')); ?>">
<?php wp_body_open(); s101_reference('header'); ?>
<main id="app">
