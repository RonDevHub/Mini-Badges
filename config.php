<?php
return [
    // Default colors (can be overridden by GET)
    'defaultLabelColor'   => '#555',
    'defaultMessageColor' => '#7db701',
    'defaultTextColor'    => '#fff',

    // Allowed owners for GitHub badges, Example: ['owner1', 'owner2'] or [] for all
    'allowedOwners' => ['RonDevHub'], // nur diese erlaubt

    // Typography
    'fontFamily' => 'Courier New, Consolas, monospace, Noto Color Emoji, Segoe UI Emoji, Apple Color Emoji, DejaVu Sans, Verdana, sans-serif',

    // Caching for API results (seconds)
    'cacheTime'           => 600,

    // Optional: GitHub Token (Personal Access Token) for higher rate limits
    // Leave empty if not needed; for public data 60 req/h unauthenticated.
    'githubToken'         => '',

    // HTTP cache headers for SVG output
    'svgMaxAge'           => 300
];

