<?php

/**
 * AI crawler UA patterns — mirror of:
 *   - packages/smking-next/src/lib/crawlers.ts
 *   - plugin/ai-commerce-backend/includes/crawler-patterns.php
 *
 * 2026-04 active list. Quarterly refresh from Cloudflare Radar / Dark
 * Visitors / each vendor's docs. When updating, update all three files
 * in lockstep.
 *
 * Categories:
 *   - training       corpus crawler for model training (delayed impact)
 *   - search         live answer-engine crawler (immediate impressions)
 *   - user_triggered bot acting on behalf of a real user (live citation)
 *
 * @return array<int, array{name: string, regex: string, category: string}>
 */

declare(strict_types=1);

return [
    // OpenAI
    ['name' => 'GPTBot',             'regex' => '/GPTBot\/[\d.]+/',             'category' => 'training'],
    ['name' => 'OAI-SearchBot',      'regex' => '/OAI-SearchBot\/[\d.]+/',      'category' => 'search'],
    ['name' => 'ChatGPT-User',       'regex' => '/ChatGPT-User\/[\d.]+/',       'category' => 'user_triggered'],

    // Anthropic
    ['name' => 'ClaudeBot',          'regex' => '/ClaudeBot\/[\d.]+/',          'category' => 'training'],
    ['name' => 'Claude-Web',         'regex' => '/Claude-Web\/[\d.]+/',         'category' => 'user_triggered'],
    ['name' => 'anthropic-ai',       'regex' => '/anthropic-ai/',               'category' => 'training'],

    // Perplexity
    ['name' => 'PerplexityBot',      'regex' => '/PerplexityBot\/[\d.]+/',      'category' => 'training'],
    ['name' => 'Perplexity-User',    'regex' => '/Perplexity-User\/[\d.]+/',    'category' => 'user_triggered'],

    // Google
    ['name' => 'Google-Extended',    'regex' => '/Google-Extended/',            'category' => 'training'],
    ['name' => 'GoogleOther',        'regex' => '/GoogleOther/',                'category' => 'training'],

    // Apple
    ['name' => 'Applebot-Extended',  'regex' => '/Applebot-Extended\/[\d.]+/',  'category' => 'training'],

    // Common Crawl
    ['name' => 'CCBot',              'regex' => '/CCBot\/[\d.]+/',              'category' => 'training'],

    // ByteDance / Doubao
    ['name' => 'Bytespider',         'regex' => '/Bytespider/',                 'category' => 'training'],

    // Meta AI
    ['name' => 'meta-externalagent', 'regex' => '/meta-externalagent\/[\d.]+/', 'category' => 'training'],

    // Amazon Alexa+
    ['name' => 'Amazonbot',          'regex' => '/Amazonbot\/[\d.]+/',          'category' => 'training'],

    // Cohere
    ['name' => 'cohere-ai',          'regex' => '/cohere-ai/',                  'category' => 'training'],

    // Diffbot
    ['name' => 'Diffbot',            'regex' => '/Diffbot\/[\d.]+/',            'category' => 'training'],
];
