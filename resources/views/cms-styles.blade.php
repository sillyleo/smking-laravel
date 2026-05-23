{{--
  AUTO-GENERATED - do not edit by hand.
  Source:  packages/shared/src/styles/cms-styles.css
  Sync:    pnpm sync:cms-styles
  Hash:    f555c5fed446

  Included by smking::cms (cms.blade.php) when
  config('smking.cms.theme_mode') === 'css' (the default, Mode A).
  Mode B (tailwind-prose) skips this view entirely.
--}}
<style data-smking="cms-styles">
/*
 * smking CMS — canonical block styles (Mode A "css")
 *
 * Single source of truth for all customer-facing CMS render styling.
 * Consumed at build time by `scripts/sync-cms-styles.mjs` which emits:
 *   - packages/smking-next/src/lib/smking-cms-styles.ts  (TS const)
 *   - packages/smking-laravel/resources/views/cms-styles.blade.php  (Blade)
 *
 * The dashboard imports the same file directly via Next.js workspace
 * alias so what the editor preview shows = what the customer site renders
 * (WYSIWYG strict per boss original spec).
 *
 * Mode B (`tailwind-prose`) customers do NOT load this file — their
 * Tailwind + @tailwindcss/typography plugin replaces all styles below.
 *
 * Namespacing rules (cross-platform parity):
 *   - All selectors are scoped to `.smk-cms` or `.smk-block--*` — no
 *     global element rules (`h1 {}` `p {}`) so SDK styles never leak
 *     into customer's other page sections.
 *   - Wrapped in `@layer smking-cms` so customer CSS authoring with
 *     Tailwind `@layer base|components|utilities` has predictable order.
 *   - Tokens on `.smk-cms` (CSS variables) — customer overrides via
 *     `:root { --smk-accent: …; }` or per-instance.
 *
 * Edit invariants:
 *   1. Never write a global element selector.
 *   2. Always declare variants via `[data-*]` attribute selectors — the
 *      SDK markup emits the attributes; CSS reads them. No hard-coded
 *      ratios / layouts / colors.
 *   3. Test against Mode A's class hooks: smk-cms / smk-block--{type} /
 *      smk-hero / smk-nav / smk-nav-search / smk-carousel.
 */

@layer smking-cms {
  /* ============================================================
     Design tokens — customer overrides on :root or .smk-cms
     ============================================================ */
  .smk-cms {
    --smk-fg: #0a0a0a;
    --smk-fg-muted: #525252;
    --smk-fg-subtle: #a3a3a3;
    --smk-bg: #fff;
    --smk-bg-soft: #fafafa;
    --smk-border: #e5e5e5;
    --smk-border-strong: #d4d4d4;
    --smk-accent: #0a0a0a;
    --smk-accent-fg: #fff;
    --smk-radius-sm: 6px;
    --smk-radius-md: 10px;
    --smk-radius-lg: 16px;
    --smk-section-gap: 4rem;
    --smk-font-serif: ui-serif, Georgia, "Iowan Old Style", "Apple Garamond",
      "Times New Roman", serif;
    --smk-font-sans: -apple-system, BlinkMacSystemFont, "Segoe UI",
      "PingFang TC", "Noto Sans CJK TC", "Microsoft JhengHei", sans-serif;
  }
  @media (prefers-color-scheme: dark) {
    .smk-cms {
      --smk-fg: #f5f5f5;
      --smk-fg-muted: #a3a3a3;
      --smk-fg-subtle: #525252;
      --smk-bg: #0a0a0a;
      --smk-bg-soft: #171717;
      --smk-border: #262626;
      --smk-border-strong: #404040;
      --smk-accent: #f5f5f5;
      --smk-accent-fg: #0a0a0a;
    }
  }

  /* ============================================================
     Article wrapper
     ============================================================ */
  .smk-cms {
    color: var(--smk-fg);
    font-family: var(--smk-font-sans);
    line-height: 1.7;
    font-size: 17px;
    max-width: 42rem;
    margin: 0 auto;
    padding: 3rem 1.5rem;
  }
  .smk-cms__title {
    font-family: var(--smk-font-serif);
    font-size: clamp(2rem, 5vw, 3rem);
    font-weight: 700;
    line-height: 1.1;
    letter-spacing: -0.02em;
    margin: 0 0 3rem;
  }
  .smk-cms > .smk-block + .smk-block {
    margin-top: 2.5rem;
  }
  /* Hero edges out to wrapper bounds; other blocks stay readable column. */
  .smk-cms > .smk-block--hero {
    margin-left: -1.5rem;
    margin-right: -1.5rem;
  }

  /* ============================================================
     Article — Plate-rendered HTML
     SDK emits: `<div class="smk-block smk-block--article">{html}</div>`
     The inner HTML comes from Plate's serializeHtml on the SaaS side,
     so customer SDK ships zero editor deps. Below selectors style the
     emitted h1/h2/p/ul/blockquote etc inside the article wrapper.
     ============================================================ */
  .smk-block--article p {
    margin: 0 0 1.25em;
  }
  .smk-block--article p:last-child {
    margin-bottom: 0;
  }
  .smk-block--article h1,
  .smk-block--article h2,
  .smk-block--article h3 {
    font-family: var(--smk-font-serif);
    font-weight: 700;
    letter-spacing: -0.015em;
    line-height: 1.2;
  }
  .smk-block--article h1 {
    font-size: 2rem;
    margin: 2em 0 0.5em;
  }
  .smk-block--article h2 {
    font-size: 1.6rem;
    margin: 1.8em 0 0.5em;
  }
  .smk-block--article h3 {
    font-size: 1.25rem;
    margin: 1.5em 0 0.5em;
  }
  .smk-block--article h1:first-child,
  .smk-block--article h2:first-child,
  .smk-block--article h3:first-child {
    margin-top: 0;
  }
  .smk-block--article ul,
  .smk-block--article ol {
    margin: 0 0 1.25em;
    padding-left: 1.75em;
  }
  .smk-block--article li {
    margin: 0.4em 0;
  }
  .smk-block--article li::marker {
    color: var(--smk-fg-subtle);
  }
  .smk-block--article a {
    color: inherit;
    text-decoration: underline;
    text-decoration-color: var(--smk-border-strong);
    text-underline-offset: 3px;
    transition: text-decoration-color 0.15s;
  }
  .smk-block--article a:hover {
    text-decoration-color: currentColor;
  }
  .smk-block--article blockquote {
    margin: 1.5em 0;
    padding: 0.25em 0 0.25em 1.25rem;
    border-left: 3px solid var(--smk-border-strong);
    color: var(--smk-fg-muted);
    font-style: italic;
  }
  .smk-block--article code {
    background: var(--smk-bg-soft);
    padding: 0.15em 0.4em;
    border-radius: 4px;
    font-size: 0.9em;
    font-family: ui-monospace, "SF Mono", Menlo, monospace;
  }
  .smk-block--article pre {
    background: var(--smk-bg-soft);
    padding: 1rem;
    border-radius: var(--smk-radius-sm);
    overflow-x: auto;
    margin: 1.5em 0;
  }
  .smk-block--article pre code {
    background: transparent;
    padding: 0;
  }
  .smk-block--article img {
    max-width: 100%;
    height: auto;
    border-radius: var(--smk-radius-sm);
    margin: 1.5em 0;
  }

  /* ============================================================
     Hero — magazine cover banner
     SDK emits: `<section class="smk-block smk-block--hero smk-hero">`
     ============================================================ */
  .smk-hero {
    text-align: center;
    padding: 5rem 1.5rem 4rem;
    background: var(--smk-bg-soft);
    border-radius: 0;
    margin-bottom: 3rem;
    position: relative;
  }
  .smk-hero__image {
    max-width: 100%;
    height: auto;
    margin: 0 auto 2rem;
    border-radius: var(--smk-radius-md);
    display: block;
  }
  .smk-hero__title {
    font-family: var(--smk-font-serif);
    font-size: clamp(2.25rem, 6vw, 4rem);
    font-weight: 800;
    line-height: 1.05;
    letter-spacing: -0.025em;
    margin: 0 0 1rem;
    color: var(--smk-fg);
    max-width: 32rem;
    margin-inline: auto;
  }
  .smk-hero__subtitle {
    color: var(--smk-fg-muted);
    font-size: 0.95rem;
    margin: 1.5rem auto 0;
    max-width: 28rem;
    letter-spacing: 0.02em;
  }
  .smk-hero__cta {
    display: inline-block;
    padding: 0.75rem 1.5rem;
    margin-top: 2rem;
    background: var(--smk-accent);
    color: var(--smk-accent-fg);
    border-radius: var(--smk-radius-sm);
    text-decoration: none;
    font-weight: 500;
    font-size: 0.95rem;
    transition: opacity 0.15s;
  }
  .smk-hero__cta:hover {
    opacity: 0.85;
  }

  /* ============================================================
     Nav — recent-posts / taxonomy-list
     SDK emits: `<section class="smk-block smk-block--nav-{type} smk-nav {sub-class}" data-layout="list|grid|carousel">`
     ============================================================ */
  .smk-nav {
    padding: 2.5rem 0;
    border-top: 1px solid var(--smk-border);
  }
  .smk-nav__heading {
    font-size: 0.75rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.12em;
    color: var(--smk-fg-muted);
    margin: 0 0 1.5rem;
  }
  .smk-nav__list {
    list-style: none;
    padding: 0;
    margin: 0;
  }

  /* List layout (data-layout="list") */
  .smk-nav[data-layout="list"] .smk-nav__list {
    display: flex;
    flex-direction: column;
  }
  .smk-nav[data-layout="list"] .smk-nav__item + .smk-nav__item {
    border-top: 1px solid var(--smk-border);
  }
  .smk-nav[data-layout="list"] .smk-nav__link {
    display: flex;
    gap: 1.25rem;
    align-items: center;
    padding: 1.25rem 0;
    color: inherit;
    text-decoration: none;
    transition: opacity 0.15s;
  }
  .smk-nav[data-layout="list"] .smk-nav__thumb {
    width: 6rem;
    height: 6rem;
    object-fit: cover;
    border-radius: var(--smk-radius-sm);
    flex-shrink: 0;
    background: var(--smk-bg-soft);
  }

  /* Grid layout (data-layout="grid") */
  .smk-nav[data-layout="grid"] .smk-nav__list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
    gap: 1.25rem;
  }
  .smk-nav[data-layout="grid"] .smk-nav__link {
    display: flex;
    flex-direction: column;
    color: inherit;
    text-decoration: none;
    background: var(--smk-bg);
    border: 1px solid var(--smk-border);
    border-radius: var(--smk-radius-md);
    overflow: hidden;
    transition: transform 0.15s, box-shadow 0.15s, border-color 0.15s;
  }
  .smk-nav[data-layout="grid"] .smk-nav__link:hover {
    transform: translateY(-2px);
    border-color: var(--smk-border-strong);
    box-shadow: 0 8px 24px -8px rgb(0 0 0 / 0.12);
  }
  .smk-nav[data-layout="grid"] .smk-nav__thumb {
    width: 100%;
    aspect-ratio: 16 / 10;
    object-fit: cover;
    background: var(--smk-bg-soft);
  }
  .smk-nav[data-layout="grid"] .smk-nav__meta {
    padding: 1rem;
  }

  /* Carousel layout (data-layout="carousel") — horizontal scroll-snap */
  .smk-nav[data-layout="carousel"] .smk-nav__list {
    display: grid;
    grid-auto-flow: column;
    grid-auto-columns: 70%;
    gap: 1rem;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    padding-bottom: 0.5rem;
  }
  @media (min-width: 640px) {
    .smk-nav[data-layout="carousel"] .smk-nav__list {
      grid-auto-columns: 40%;
    }
  }
  .smk-nav[data-layout="carousel"] .smk-nav__item {
    scroll-snap-align: start;
    background: var(--smk-bg);
    border: 1px solid var(--smk-border);
    border-radius: var(--smk-radius-md);
    overflow: hidden;
  }
  .smk-nav[data-layout="carousel"] .smk-nav__link {
    display: flex;
    flex-direction: column;
    color: inherit;
    text-decoration: none;
  }
  .smk-nav[data-layout="carousel"] .smk-nav__thumb {
    width: 100%;
    aspect-ratio: 16 / 10;
    object-fit: cover;
    background: var(--smk-bg-soft);
  }
  .smk-nav[data-layout="carousel"] .smk-nav__meta {
    padding: 1rem;
  }

  /* Shared internals */
  .smk-nav__link:hover {
    opacity: 0.85;
  }
  .smk-nav[data-layout="grid"] .smk-nav__link:hover,
  .smk-nav[data-layout="carousel"] .smk-nav__link:hover {
    opacity: 1;
  }
  .smk-nav__meta {
    flex: 1;
    min-width: 0;
  }
  .smk-nav__title {
    font-family: var(--smk-font-serif);
    font-size: 1.15rem;
    font-weight: 700;
    line-height: 1.3;
    margin: 0 0 0.5rem;
    letter-spacing: -0.01em;
  }
  .smk-nav__excerpt {
    font-size: 0.9rem;
    color: var(--smk-fg-muted);
    margin: 0;
    line-height: 1.55;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
  .smk-nav__date {
    display: block;
    font-size: 0.75rem;
    color: var(--smk-fg-subtle);
    margin-top: 0.6rem;
    letter-spacing: 0.04em;
    text-transform: uppercase;
  }
  .smk-nav__empty {
    color: var(--smk-fg-subtle);
    font-style: italic;
    padding: 1rem 0;
  }
  .smk-nav__view-all {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    margin-top: 1.5rem;
    color: var(--smk-accent);
    font-size: 0.875rem;
    font-weight: 600;
    text-decoration: none;
  }
  .smk-nav__view-all::after {
    content: "→";
    transition: transform 0.15s;
  }
  .smk-nav__view-all:hover::after {
    transform: translateX(3px);
  }

  /* ============================================================
     Nav-search — inline filter input
     SDK emits: `<section class="smk-block smk-block--nav-search smk-nav-search">`
                with `data-smk-search-input` / `data-smk-search-results` /
                `data-smk-search-empty` attributes the IIFE wires up.
     ============================================================ */
  .smk-nav-search {
    padding: 2rem 0;
    border-top: 1px solid var(--smk-border);
  }
  .smk-nav-search__shell {
    position: relative;
  }
  .smk-nav-search__input {
    width: 100%;
    padding: 0.875rem 1.125rem;
    border: 1px solid var(--smk-border);
    border-radius: var(--smk-radius-md);
    background: var(--smk-bg);
    color: var(--smk-fg);
    font-size: 1rem;
    font-family: inherit;
    transition: border-color 0.15s, box-shadow 0.15s;
  }
  .smk-nav-search__input::placeholder {
    color: var(--smk-fg-subtle);
  }
  .smk-nav-search__input:focus {
    outline: none;
    border-color: var(--smk-fg);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--smk-fg) 8%, transparent);
  }
  .smk-nav-search__list {
    list-style: none;
    padding: 0;
    margin: 0.75rem 0 0;
    border: 1px solid var(--smk-border);
    border-radius: var(--smk-radius-md);
    background: var(--smk-bg);
    overflow: hidden;
    box-shadow: 0 4px 12px -4px rgb(0 0 0 / 0.1);
  }
  .smk-nav-search__result + .smk-nav-search__result {
    border-top: 1px solid var(--smk-border);
  }
  .smk-nav-search__result a {
    display: block;
    padding: 0.875rem 1.125rem;
    color: var(--smk-fg);
    text-decoration: none;
    transition: background 0.1s;
  }
  .smk-nav-search__result a:hover {
    background: var(--smk-bg-soft);
  }
  .smk-nav-search__empty {
    color: var(--smk-fg-subtle);
    padding: 1rem 0 0;
    font-size: 0.9rem;
  }

  /* ============================================================
     Carousel — Apple-newsroom style horizontal carousel with
     `data-aspect` token-driven aspect-ratio, container-query-anchored
     nav buttons, and dots indicator.
     SDK emits:
       <smking-carousel class="smk-block smk-block--carousel smk-carousel"
                        data-aspect="16:9|4:3|1:1|3:4|9:16">
         <div class="smk-carousel__viewport">
           <smking-slide>
             <div class="smk-carousel__image-frame">
               <img class="smk-carousel__image" /> or .__image--placeholder
             </div>
             <div class="smk-carousel__caption-row">
               <div class="smk-carousel__caption">…</div>
               <a class="smk-carousel__download" href />
             </div>
           </smking-slide>
         </div>
         <button class="smk-carousel__nav-prev|next" />
         <div class="smk-carousel__dots">
           <button class="smk-carousel__dot" aria-current="true|false" />
         </div>
       </smking-carousel>

     Web Component IIFE (SMKING_ELEMENTS_IIFE) upgrades the markup with
     swipe / scroll-snap / dot-binding behaviour client-side. CSS below
     drives all visual state — image cover-fill, nav-button vertical
     centering anchored to image height (cqw container-queries), dot
     active state.
     ============================================================ */
  smking-carousel,
  .smk-carousel {
    display: block;
    position: relative;
    margin: 2.5rem 0;
    padding-top: 2.5rem;              /* room for editor settings overlay; harmless on customer side */
    padding-bottom: 1.5rem;
    container-type: inline-size;      /* enables `cqw` for nav-button vertical anchor */
    --smk-carousel-aspect: 16 / 9;
    --smk-carousel-nav-top: 28.125cqw;   /* 50cqw × 9/16 = half of image height */
  }
  .smk-carousel[data-aspect="4:3"],
  smking-carousel[data-aspect="4:3"] {
    --smk-carousel-aspect: 4 / 3;
    --smk-carousel-nav-top: 37.5cqw;
  }
  .smk-carousel[data-aspect="1:1"],
  smking-carousel[data-aspect="1:1"] {
    --smk-carousel-aspect: 1 / 1;
    --smk-carousel-nav-top: 50cqw;
  }
  .smk-carousel[data-aspect="3:4"],
  smking-carousel[data-aspect="3:4"] {
    --smk-carousel-aspect: 3 / 4;
    --smk-carousel-nav-top: 66.667cqw;
  }
  .smk-carousel[data-aspect="9:16"],
  smking-carousel[data-aspect="9:16"] {
    --smk-carousel-aspect: 9 / 16;
    --smk-carousel-nav-top: 88.889cqw;
  }

  .smk-carousel__viewport {
    display: flex;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    scroll-behavior: smooth;
    scrollbar-width: none;
    -webkit-overflow-scrolling: touch;
  }
  .smk-carousel__viewport::-webkit-scrollbar {
    display: none;
  }

  smking-slide,
  .smk-carousel__slide {
    display: flex;
    flex-direction: column;
    flex: 0 0 100%;
    scroll-snap-align: start;
    min-width: 0;
    padding-inline: 0.5rem;
    gap: 1rem;
  }

  /* Image frame — fixed-aspect box. Image cover-fills. */
  .smk-carousel__image-frame {
    position: relative;
    aspect-ratio: var(--smk-carousel-aspect);
    overflow: hidden;
    border-radius: var(--smk-radius-md);
  }
  .smk-carousel__image {
    display: block;
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  .smk-carousel__image--placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--smk-bg-soft);
    border: 1px dashed var(--smk-border);
  }

  /* Caption row — text on the left, download icon on the right. Matches
     Apple newsroom's per-slide attribution + asset-download pattern. */
  .smk-carousel__caption-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding-inline: 0.25rem;
  }
  .smk-carousel__caption {
    flex: 1;
    text-align: center;
    font-size: 0.875rem;
    color: var(--smk-fg-muted);
    line-height: 1.5;
  }
  .smk-carousel__download {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 9999px;
    color: var(--smk-fg-muted);
    text-decoration: none;
    transition: background-color 0.15s, color 0.15s;
  }
  .smk-carousel__download:hover {
    background: var(--smk-bg-soft);
    color: var(--smk-fg);
  }

  /* Nav buttons — circular cards overlapping the image edges. Top is
     centered on the IMAGE (not the whole carousel) via container query:
     image height = 100cqw × (ratio_h / ratio_w); half = nav-top var.
     2.5rem offset = container padding-top. */
  .smk-carousel__nav-prev,
  .smk-carousel__nav-next {
    position: absolute;
    top: calc(2.5rem + var(--smk-carousel-nav-top));
    transform: translateY(-50%);
    z-index: 1;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
    padding: 0;
    border-radius: 9999px;
    border: 1px solid var(--smk-border);
    background: var(--smk-bg);
    color: var(--smk-fg);
    cursor: pointer;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.06);
    transition: background-color 0.15s, transform 0.15s;
  }
  .smk-carousel__nav-prev { left: 0.75rem; }
  .smk-carousel__nav-next { right: 0.75rem; }
  .smk-carousel__nav-prev:hover,
  .smk-carousel__nav-next:hover {
    background: var(--smk-bg-soft);
  }
  .smk-carousel__nav-prev:active,
  .smk-carousel__nav-next:active {
    transform: translateY(-50%) scale(0.95);
  }

  /* Dots — small circles centered below the caption row. Inactive is
     hairline grey, active fills with foreground colour. */
  .smk-carousel__dots {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.75rem;
  }
  .smk-carousel__dot {
    width: 0.5rem;
    height: 0.5rem;
    padding: 0;
    border-radius: 9999px;
    border: none;
    background: color-mix(in srgb, var(--smk-fg) 20%, transparent);
    cursor: pointer;
    transition: background-color 0.15s, transform 0.15s;
  }
  .smk-carousel__dot:hover {
    background: color-mix(in srgb, var(--smk-fg) 45%, transparent);
  }
  .smk-carousel__dot[aria-current="true"] {
    background: var(--smk-fg);
    transform: scale(1.1);
  }

  /* ============================================================
     Unknown block — forward-compat fallback
     ============================================================ */
  .smk-block--unknown {
    display: none;
  }
}
</style>
