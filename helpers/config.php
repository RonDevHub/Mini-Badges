<?php
return [
    // Default colors (can be overridden by GET)
    'defaultLabelColor'   => '#555',
    'defaultMessageColor' => '#0B7DBE',
    'defaultTextColor'    => '#fff',

    // Default language of dynamic badges (en,de,es,it,fr,uk)
    'defaultLang' => 'en',

    // Typography
    'fontFamily' => 'Courier New, Consolas, monospace, Noto Color Emoji, Segoe UI Emoji, Apple Color Emoji, DejaVu Sans, Verdana, sans-serif',

    // Caching for API results (seconds)
    'cacheTime'           => 600,
    
    // Allowed owners for GitHub badges, Example: ['owner1', 'owner2'] or [] for all
    'allowedOwners' => ['owner1'], // only these allowed
    // Optional: GitHub Token (Personal Access Token) for higher rate limits
    // Leave empty if not needed; for public data 60 req/h unauthenticated.
    'githubToken'         => '',

    // Allowed owners for Codeberg badges, Example: ['owner1', 'owner2'] or [] for all
    'allowedCodebergOwners' => ['owner1'], // only these allowed
    // Codeberg Token (Personal Access Token)
    'codebergToken' => '',

    // Allowed owners for Forgejo badges, Example: ['owner1', 'owner2'] or [] for all
    'allowedForgejoOwners' => ['owner1'], // only these allowed
    'forgejoURL' => 'https://example.net', // Forgejo instance URL
    // Forgejo Token (Personal Access Token)
    'forgejoToken' => '',

    // HTTP cache headers for SVG output
    'svgMaxAge'           => 300,

    // Defined color names for badges
    'colors' => [
        'black' => '#000000',
        'white' => '#ffffff',
        'gray' => '#808080',
        'grey' => '#808080',
        'red' => '#ff0000',
        'orange' => '#ffa500',
        'yellow' => '#ffff00',
        'lime' => '#00ff00',
        'green' => '#4c1',
        'teal' => '#008080',
        'cyan' => '#00ffff',
        'aqua' => '#00ffff',
        'blue' => '#007ec6',
        'navy' => '#000080',
        'purple' => '#800080',
        'magenta' => '#ff00ff',
        'pink' => '#ffc0cb',
        'brown' => '#a52a2a',
        'brightgreen' => '#4c1',
        'yellowgreen' => '#a4a61d',
        'orange' => '#fe7d37',
        'red' => '#e05d44',
        'blue' => '#007ec6',
        'lightgrey' => '#9f9f9f',
        'success' => '#198754',
        'primary' => '#0d6efd',
        'secondary' => '#6c757d',
        'info' => '#0dcaf0',
        'warning' => '#ffc107',
        'danger' => '#dc3545',
        'light' => '#f8f9fa',
        'dark' => '#212529',
        'muted' => '#6c757d'
    ],
];
