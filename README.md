# Page Zero Laravel SDK

`smking/laravel` brings Page Zero CMS content, SEO metadata, and AEO structured data to your Laravel website.

## Features

- **AEO**: Server-render JSON-LD, FAQs, and AI summaries for search engines and AI services.
- **CMS**: Render published content with Blade components, nested paths, and draft previews.
- **SEO and social sharing**: Output titles, descriptions, canonical URLs, Open Graph, and Twitter metadata. Manual CMS fields take priority and work without AEO generation.
- **AI discovery**: Serve Markdown content, sitemaps, and llms.txt.
- **Background delivery**: In `on_demand` mode, public pages read durable local copies while background jobs handle content updates.

Metadata rendering reuses existing content and images without additional AI calls, image downloads, or resizing.

## Installation and upgrades

Give the [Laravel install prompt](https://getpagezero.com/docs/sdk/laravel.md) to your coding assistant to integrate the SDK into your existing project.
It covers installation, upgrades, configuration, prewarming, and deployment verification.

## Releases and requirements

- PHP 8.1+, Laravel 10/11/12.
- [Releases](https://github.com/sillyleo/smking-laravel/releases) · [Changelog](CHANGELOG.md)
- License: MIT.
