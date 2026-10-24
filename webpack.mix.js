const mix = require('laravel-mix');

mix.options({ processCssUrls: false });

mix
    .js('resources/js/app.js', 'public/js')
    .postCss('resources/css/app.css', 'public/css', [
        require('@tailwindcss/postcss'),
    ])
    .version();
