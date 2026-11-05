<?php

// Tema usado únicamente por la integración HTTP desechable.
add_action('wp_enqueue_scripts', static function (): void
{
    wp_enqueue_style('dashicons');
});

echo '<!doctype html><html><head><title>Frontend fixture</title>';
wp_head();
echo '</head><body><img src="' . esc_url(home_url('/hero.webp')) . '" alt="">';
wp_footer();
echo '</body></html>';
