# smking/laravel

AI-native SEO (AEO) for Laravel. Auto-inject JSON-LD, FAQ, and AI summaries into your pages so ChatGPT, Perplexity, and Google AI can cite them.

## Install

**Don't follow this README to install.** Your smking dashboard generates a per-site install prompt with the real `SMKING_API_KEY`, `SMKING_BASE_URL`, and (if you use CMS) `SMKING_WEBHOOK_SECRET` baked in, plus the exact `composer require`, `vendor:publish`, and `php artisan smking:doctor` commands. The prompt is the source of truth and stays in sync with the SDK version.

Two ways to get it:

```bash
# Option 1 — one-shot wizard (composer require + .env + doctor)
npx @soloworks/smking-wizard

# Option 2 — copy the prompt manually from your smking dashboard's
# install panel into your editor / coding agent.
```

Once `php artisan smking:doctor` is green, the middleware auto-registers and every HTML GET response picks up:

- **AEO** — JSON-LD, FAQ/summary blocks (for ChatGPT, Perplexity, Google AI)
- **SEO** — `<title>`, `og:*`, `twitter:*`, `<link rel="canonical">` (for Google snippet + social shares)
- **Markdown for Agents** (v0.4.0+) — agents requesting `Accept: text/markdown` get a structured markdown rendition. Boosts your Cloudflare Agent Readiness score.
- **Markdown alternate Link header** (v0.5.0+) — every HTML response advertises the markdown rendition via `Link: <{url}>; rel="alternate"; type="text/markdown"`.

smking is the source of truth for SEO/AEO meta. Any existing `<title>`, `<meta name="description">`, `og:*`, or `<link rel="canonical">` in your layout is stripped and replaced with smking's version (v0.3.0+). To keep a tag under your control, disable it via `config('smking.inject.{tag}', false)` or render it yourself with the `<x-smking-meta />` Blade component.

## Manual usage

Disable auto-injection and render where you want:

```php
// config/smking.php
'auto_inject' => false,
```

```blade
{{-- 1. Body content (JSON-LD + FAQ + summary) --}}
<x-smking-aeo path="/products/{{ $product->slug }}" />

{{-- 2. SEO meta inside <head> with fallback to your own page data --}}
<head>
    <x-smking-meta
        :path="request()->path()"
        :fallback-title="$product->name"
        :fallback-og-description="$product->short_description"
    />
</head>

{{-- 3. Facade for full control --}}
@php($aeo = \Smking::forPath('/products/'.$product->slug))
@if ($aeo->isReady())
    <script type="application/ld+json">{!! json_encode($aeo->jsonLd) !!}</script>
    <title>{{ $aeo->seo?->title ?? $product->name }}</title>
@endif
```

The `<x-smking-meta />` component mirrors `getSmkingMetadata()` from `@smking/next` — call it inside `<head>` and it emits exactly the SEO tags the API has values for, falling back to the `fallback-*` props otherwise. Use it when you want SEO meta in your Blade layout but body injection from the middleware.

## Mount the runtime once (v0.17.1+)

Add `<x-smking-runtime />` once in your root Blade layout `<head>` — it emits a `<link>` to the saas-served CSS and a `<script async>` to the bundled Web Component runtime IIFE. Browser caches both per saas-controlled stale-while-revalidate headers, so the cost amortises across every `<x-smking-cms>` instance on the page.

```blade
{{-- resources/views/layouts/app.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }}</title>
    <x-smking-runtime />
</head>
<body>
    @yield('content')
</body>
</html>
```

Optional `:base-url="..."` attribute overrides `config('smking.base_url')`. Trailing slash is trimmed.

Without `<x-smking-runtime />`, `<x-smking-cms>` content still renders but the Tailwind utility classes from the dashboard's cva variants resolve to dead strings — the page reaches the browser unstyled. The wizard installer auto-adds this mount; if you're upgrading manually from < v0.17.1, add the one line above.

## CMS rendering (optional, v0.11.0+)

The base install above only wires AEO. If you author content in the smking dashboard's CMS and want to render it on your Laravel site, use the `<x-smking-cms slug="…" />` Blade component.

The SDK **does not have a "CMS root" config** — you choose any URL prefix (`/blog`, `/knowledge`, `/shop/articles`) and mount the response-aware CMS controller. It returns `404` for an explicit `not_found`, `503` for a pending or unavailable CMS response, and renders ready or preview content with `200`.

```php
// routes/web.php
Route::get('/blog/{path?}', [\Smking\Laravel\Http\Controllers\CmsPageController::class, '__invoke'])
    ->where('path', '.*');
```

```blade
{{-- resources/views/smking-cms-page.blade.php --}}
<x-smking-cms :slug="$slug" :page="$cms" />
```

The optional catch-all handles the mount root, flat slugs, and nested slugs. Passing the controller's preloaded `$cms` object to `<x-smking-cms>` avoids a second public CMS request.

### Cache invalidation

Ready AEO and CMS responses cache server-side for one hour by default. When generated AEO changes, or when you publish, rename, or archive a CMS page, smking SaaS POSTs a signed webhook to `https://<your-site>/api/smking/webhook` (auto-mounted by the SDK in v0.11+). The handler verifies HMAC against `SMKING_WEBHOOK_SECRET` and evicts the matching AEO, Markdown, or CMS cache entries so visitors see the new content on the next request.

If `SMKING_WEBHOOK_SECRET` is unset, the webhook route returns 503 and cache invalidation falls back to TTL-based expiry.

## Config (config/smking.php)

| Key | Default | Notes |
|-----|---------|-------|
| `api_key` | `env('SMKING_API_KEY')` | Publishable key from the dashboard |
| `base_url` | _(required, no default)_ | Set `SMKING_BASE_URL` to your smking deployment origin |
| `auto_inject` | `true` | Register middleware globally |
| `inject_in_tests` | `false` | When false (v0.7.3+ default), middleware short-circuits under `php artisan test` / Pest so feature tests don't time out against an unreachable backend. Set `SMKING_INJECT_IN_TESTS=true` in `.env.testing` for genuine integration tests |
| `only` / `except` | see file | Path filters (Laravel wildcard) |
| `inject.*` | all `true` | Toggle json_ld / meta_description / faq / summary / seo_title / og_title / og_description / og_image / canonical / markdown |
| `inject.visibility` | `sr_only` | Body-fragment visibility: `sr_only` (default, visually hidden), `visible` (raw, v0.5.x behavior), `noscript` |
| `cache.ttl` | `3600` | Seconds; `0` disables |
| `timeout` | `3` | HTTP timeout in seconds |

## How it works

1. Middleware runs after your response is built.
2. For each HTML `GET` 200, it calls `POST /api/v1/public/aeo` with the request path.
3. If smking has ready content, structured data + SEO meta go into `<head>`; FAQ + summary go before `</body>`.
4. **Always override** (v0.3.0+): every enabled SEO tag (`<title>`, `og:*`, `canonical`, `meta description`) gets written by smking. Any matching host markup is stripped first (attribute-order-insensitive) so the document only ever has one of each. To keep a tag under your control, set `config('smking.inject.{tag}', false)` or render it yourself.
5. Unknown paths are registered for background crawling — next request will serve content.
6. Responses are cached per path in Laravel's cache. Pending/error states fail open.
7. **Agent content negotiation** (v0.4.0+): when `Accept: text/markdown` is preferred over `text/html` (q-value-aware), the middleware fetches `/api/v1/public/md` and replaces the body with markdown. `Vary: Accept` is added so caches stay consistent. First-time misses fall through to HTML and trigger the same background crawl.
8. **Agent discovery** (v0.5.0+): every HTML response advertises the markdown alternate via `Link: <{url}>; rel="alternate"; type="text/markdown"` (RFC 8288). Appended to any existing Link headers; idempotent if you already wired your own.
9. **Visually-hidden body fragments by default** (v0.6.0+): auto-injected `summaryHtml` / `faqHtml` are wrapped in an inline-style sr-only `<div>` so they don't pollute SPA layouts where `</body>` injection lands outside `#app`. Microdata stays in the DOM (Googlebot reads it); JSON-LD in `<head>` is the primary AEO signal. Switch with `SMKING_INJECT_VISIBILITY=visible` if you want the v0.5.x behavior. The `<x-smking-aeo />` Blade component is unaffected — explicit placement is always rendered as you wrote it.

## Gradual rollout / A/B comparison

`config('smking.only')` is a strict whitelist — when non-empty, the middleware only runs on paths that match. Use it to roll out smking gradually, or to A/B-compare smking-enabled paths against untouched ones.

### Soft launch one URL

```php
// config/smking.php
'only' => ['products/widget'],
```

Now only `https://your-site.com/products/widget` gets smking-injected meta + JSON-LD. Every other page is untouched. Measure impact for a week before expanding.

### Expand to one section

```php
'only' => ['products/*'],
```

All product pages enabled, rest of site untouched. Continue measuring against control pages (homepage, blog, etc.).

### A/B comparison

```php
'only' => [
    'products/widget',   // Variant A — smking enabled
    'products/gizmo',    // Variant B — smking enabled
    // 'products/sprocket' — Control: NOT in `only`, no smking
],
```

Compare AEO score / search ranking / AI-citation share across the three pages over your measurement window.

### Full rollout

```php
'only' => [],   // empty == every HTML page (default behavior)
```

`only` patterns use Laravel's `Request::is()` syntax, identical to `except`. Combine both — `only` is checked first (must match), then `except` (must not match) — so you can whitelist `products/*` and blacklist `products/draft-*` simultaneously.

## Outage Runbook

When the smking SaaS is down or unreachable, the SDK fails open — your pages still render normally, just without smking-injected content. Three knobs you may want to know about:

### 0. Layered protection — single-flight + circuit breaker (v0.7.0+)

Two complementary defenses run on every cache miss:

**Single-flight cache lock** — When a path is uncached and traffic spikes, only ONE PHP-FPM worker calls smking upstream; others fail open immediately (return un-injected page). Per-path protection. Uses `Cache::lock()` (redis / memcached / database / file / dynamodb drivers; graceful fallback for stores without lock support). Laravel's `array` store is process-local, so it cannot coordinate separate PHP-FPM workers and should not be used for production outage protection.

**Per-surface circuit breaker** — Once any path hits a 5xx / transport error, a flag is set for `circuit_breaker_ttl` seconds (default 60). A transport failure atomically opens it before the optional cold-start retry; only the request that wins that cache claim may retry, so overlapping workers cannot all spend the long retry budget. While the flag is present, every other path on THAT surface short-circuits without touching the upstream. Protects against high-cardinality outage events (catalog spray, full-site crawler) where per-path cache wouldn't help — the second URL in the burst doesn't know the first one just failed. Auto half-open: when the flag expires the next request hits upstream; success closes the breaker, another failure trips it again. Disable with `SMKING_CIRCUIT_BREAKER=false` if your customer cache layer can't store namespace flags reliably.

Two independent breakers exist (since v0.7.0 round-4):

- HTML AEO injection (`/api/v1/public/aeo`, every page render) — `smking:circuit:aeo:{ns}`
- Markdown for agents (`/api/v1/public/md`, agent-only `Accept: text/markdown` clients) — `smking:circuit:md:{ns}`

A markdown outage no longer suppresses HTML injection: the agent surface is optional, and an issue isolated there should never affect the customer-facing render path. Both surfaces still rotate together when `(api_key, base_url)` changes.

### 1. Cache absorbs most outages automatically (v0.7.0+, refined in v0.10.0)

Four-tier cache TTL with adaptive backoff on errors:

| Status | TTL | Behavior |
|---|---|---|
| `ready` | 1 hour | Customer's cached AEO content keeps serving |
| `not_found` (4xx) | 60 sec | Backend audit catching up; first-launch products visible within ~1 min after crawl/generate completes |
| `pending` (202) | 15 sec | SaaS explicit "in-progress" signal — short cushion against hot-launch polling |
| `server_error` (5xx, DNS, TCP, timeout) | **30s → 5min → 30min → 24hr** | Adaptive backoff per consecutive failure (v0.10.0+) |

The `server_error` ladder is keyed by consecutive-failure count for each cache key independently. A first failure (typical of an install-time typo or transient network blip) caches for 30 seconds — auto-recovers without operator intervention once the underlying issue is fixed. Steady-state outage protection kicks in by failure #4 at the full 24hr fallback, preserving the FPM-pool-saturation defense that made the flat 24hr TTL necessary in v0.7.0.

A successful `ready` response resets the counter, so the next outage starts at 30s again. Customize the ladder via `cache.server_error_backoff` in `config/smking.php`, or set to `[]` to disable backoff and restore the pre-v0.10.0 flat 24hr behavior.

### 2. Tighten timeouts further if you're at scale

Default since v0.7.0: `connect_timeout=1s`, `timeout=1.5s`. For million-PV sites where every millisecond counts:

```dotenv
SMKING_CONNECT_TIMEOUT=0.5
SMKING_HTTP_TIMEOUT=1
```

**Conversely, low-traffic / stage / newly-launched sites have the opposite problem** (v0.19.1+): they don't keep the upstream serverless function warm, so the first AEO hit eats a 3-5s cold start that blows the 1.5s read timeout → `server_error` stuck in the backoff cache (and the page never gets registered for crawling). AEO discovery now retries **once** on a first-attempt timeout using a generous cold-start timeout — the first attempt wakes the function, the retry lands warm. Warm sites never retry, so there's zero steady-state impact.

```dotenv
SMKING_COLD_START_RETRY=true          # default; set false for single-shot discovery
SMKING_COLD_START_TIMEOUT=8           # read timeout (s) for the retry attempt
SMKING_COLD_START_CONNECT_TIMEOUT=3   # connect timeout (s) for the retry attempt
```

The retry only fires on a thrown request (DNS / TCP / timeout) — a transport success carrying a 5xx isn't retried (the SaaS is reporting its own breakage). Applies to the AEO HTML surface; the markdown-for-agents surface keeps single-shot semantics.

### 3. Kill switch when SaaS is in trouble

Set in `.env` and clear config cache:

```dotenv
SMKING_AUTO_INJECT=false
```

Rebuild Laravel's production config cache after editing `.env`:

```bash
php artisan config:cache
```

Middleware still emits `X-Smking-Status` headers (so `curl -I` install verification works) but doesn't try to fetch any content. Reverts to original page entirely.

### 4. Recovering after SaaS comes back

Per-path:

```bash
php artisan smking:cache:purge /products/widget
```

This forgets both `smking:aeo:*` and `smking:md:*` cache for that path AND clears the per-surface circuit breakers so the next request actually re-fetches (no waiting for breaker TTL). Use this whenever you've fixed something upstream and want immediate recovery on a specific path.

Per WC product:

```bash
php artisan smking:cache:purge --product-id=42
```

Clears the AEO-surface entry for that product plus the AEO-surface circuit breaker.

For wholesale recovery (clears the whole app cache):

```bash
php artisan cache:clear
```

### 5. Inspecting circuit-breaker state (v0.7.1+)

When AEO content stops appearing in production, the breaker may be silently short-circuiting upstream calls. Check it without touching the cache:

```bash
php artisan smking:circuit:status
```

Sample output (any surface open → exit code 1 for scripted health checks):

```
smking circuit breaker status:

  aeo (HTML AEO injection): closed
       key: smking:circuit:aeo:abc123
  md  (markdown for agents): OPEN — re-check after configured TTL (60s)
       key: smking:circuit:md:abc123

Recovery: wait for TTL, or `php artisan smking:cache:purge <path>` / `--product-id=N` to force-clear.
```

The breaker also logs trip + close events through the configured `LoggerInterface`:

```
[warning] smking: circuit breaker tripped for aeo surface
  context: {"surface":"aeo","ttl_seconds":60,"key":"smking:circuit:aeo:..."}

[info] smking: circuit closed for aeo surface
  context: {"surface":"aeo"}
```

The trip log is rate-limited to one line per outage window (a million-request burst produces one log, not a million). The close log fires once on the first successful upstream call after recovery — implemented via an atomic tombstone pull, so concurrent recovery requests log at most once.

Wire your usual log → metric path (Datadog Logs / Sentry / etc.) to alert on either string when you want a paging trigger instead of a polling status check.

### Correlated AEO request logs (v0.21.6+)

Each uncached AEO discovery emits one structured `smking: AEO discovery`
record through Laravel's configured `LoggerInterface`. Its context contains:

- `request_id`, reused by the first attempt and optional cold-start retry
- `attempt_count`, `attempt_outcomes`, and exact `http_statuses`
- `circuit_action` (`none`, `opened_retry_owner`, `retry_denied`, or
  `short_circuited`)
- `final_status` and `duration_ms`

The same request ID is sent as `X-Smking-Request-Id`; each wire attempt carries
`X-Smking-Attempt: 1|2`. SaaS echoes the request ID and writes its own
`public_aeo_request` JSON event, so an operator can correlate the customer log
with Vercel Runtime Logs.

Calls that never receive an HTTP response, plus circuit-short-circuited calls,
cannot report themselves immediately. The SDK therefore keeps a best-effort
outbox in the configured Laravel cache (newest 20 events, 24-hour TTL) and
piggybacks it on the next ordinary AEO POST. It only removes event IDs after an
observability-aware SaaS response echoes the request ID. This creates no extra
request and never delays the host response for telemetry delivery.

Use a shared production cache such as Redis, Memcached, or database if these
events and the circuit breaker must coordinate across PHP-FPM workers. The
`array` store is process-local. No new `.env` key is required.

Telemetry excludes API keys, raw paths/URLs, query strings, exception text,
request/response bodies, and customer content.

## On-demand delivery (v0.22.0, opt-in)

Installing v0.22.0 alone does not change the active delivery mode. The package
defaults to `SMKING_DELIVERY_MODE=legacy`, continues using the existing AEO POST
and CMS GET clients, and leaves versioned CMS notifications disabled. It does
not register a scheduler. The new v2 content GET, versioned cache, bounded
background work, independent reporting, prewarm, and rollback checks are for
an explicitly prepared isolated rollout; they are not a production enablement
instruction.

### 尚未發布：本地持久副本候選

目前分支正在依[現行計劃與驗收](../../docs/research/sdk-on-demand-delivery-recovery-2026-09-14.md#local-copy-policy)分段改造，不是新版已完成或啟用指示。L1 將 v2 本文、下載代次、權限及 CMS 撤回紀錄保存為私有版本化 JSON，預設位於 `storage/app/smking-delivery`，可用 `SMKING_DELIVERY_LOCAL_STORE_PATH` 指定單機部署間共用的持久目錄。一般 `cache:clear` 不再清除這些資料；此目錄不得放在 public 或一般 cache 目錄，也不得隨部署移除。來源／金鑰／格式隔離仍保留，檔案損毀會明確失敗，不自動改讀舊 cache。

舊 v2 cache 不由訪客自動遷入。保持 `legacy` 模式、停止舊版本寫入者並保留完整現行 cache 後，可對明確已知識別執行有界、本地匯入：

```bash
php artisan smking:delivery:import --resource=cms-page --identifier=slug:article
php artisan smking:delivery:import --resource=aeo --identifier=path:/products/article
```

同一命令最多 20 筆、10 秒批次預算；不掃全庫、不呼叫來源、不改模式或舊 cache。只接受既有 v2 格式，不匯入舊 PHP SDK 物件；缺頁、失效或無法證明就緒時應重新預熱並核對，不能直接切換。移入權限及撤回後才移入本文，既有持久狀態不被舊 cache 覆蓋。完整持久目錄若從舊備份還原，必須重新核對來源／撤回及就緒狀態後才公開，不能假設舊備份仍獲授權。

L2A 接收端已加入四類共同的 `content_delivery_v2` 更新／撤回處理，原 `cms_delivery_v2` 仍只接受 CMS。一般更新保留本文及每日索引，背景下載須符合通知版本，或具備下述較新發布證據才替換；撤回與亂序保護涵蓋目前 SDK 的 `legacy` 模式。切回模式不等於任意降級舊套件：不認識新撤回紀錄的版本不得直接作安全回退。

舊 `cms_page`／`aeo` 通知仍清理 v1 cache，但對既有 v2 副本（或已開啟的 `on_demand` 模式）只登記背景檢查，不先清本文；即使本文還在 freshness 時間內，worker 也會處理更新提示。舊通知沒有版本，不能冒充有序撤回或已驗證通知能力。工作未能登記會回 503 並保留副本；`registered` 只表示已受理，不代表客戶已取得新版。執行中再次收到登記不會被前一次完成動作清掉，既有工作期限及重試上限不延長。

L2B 本機候選已接上 SaaS 發送與逐資源接收紀錄。SaaS 須先套用 `0087_notification_site_files`（僅放寬 SaaS 通知表的公共檔案限制），並明確設定站台 `config.contentDeliveryNotificationResources`，例如 `["cms-page", "aeo", "markdown", "site-file"]`；本次沒有替任何站台設定或套用。未設定該清單時，既有 `cmsDeliveryNotificationsEnabled` 仍只使用原 CMS 通知格式。原有全域旗標、來源／scope／金鑰、webhook 設定及 CDN 就緒條件均保留，安裝套件不會自動開啟。

新格式每批先向相同 webhook 發送無內容的 `content_delivery_probe_v2` 簽章探測，明確確認該批資源及來源／scope／金鑰指紋後才發內容通知；舊 SDK 的一般 200 回應不算支援。兩次 HTTP 都在 SaaS 交易外，各有 2 秒上限，共用既有 3 次嘗試額度。探測只證明接收能力，不預熱、不登記內容，也不授予每日後備豁免。

SDK 只有在版本通知實際處理成功後，才記錄該資源的近期接收證據；來源、金鑰、scope、webhook secret 改變或關閉通知時不沿用。紀錄效期 24 小時，未確認、過期、接收／背景更新失敗或快取紀錄遺失便恢復每日後備資格。四類使用相同規則；低更新頻率下，即使 webhook 仍配置著，也可能因沒有近期證據而每日檢查。這是保守的失效保護，不是永久宣稱通知可用。`smking:doctor --json` 可讀各資源的近期證據／後備原因，不靠訪客探測。

L3A 本機候選讓既有 v2 GET 在來源發布旗標開啟時附帶可選的 `delivery.publication`（同一資源／識別的 revision、generation、withdrawalRevision、action、contentVersion）。來源先核對已準備 target 與本文雜湊一致，讀取競態不一致回不可快取的 503，不能把舊本文標成新 revision；未追蹤內容、旗標關閉或仍在準備的安全舊快照不附證據。本文雜湊、舊 API、文字回應格式及預設旗標不變，也沒有新增 Sync API、全站清單或客戶資料庫。

SDK 背景 GET 可依此證據安全超過上次通知的版本，漏通知不再永久卡住；沒有通知設定也保存相同持久版本／撤回紀錄。較舊版本、同版不同內容、降低撤回紀錄、識別／雜湊不符均拒絕；下載期間通知變更則本次下載不提交。一般更新失敗保留舊本文；若明確證據揭露中途曾撤回，該次撤回前的本文不得因新版提交失敗而復活。舊來源未提供證據時仍可使用原 GET，但不能安全越過已有的精確版本限制；SaaS 與 SDK 必須一起驗證，不能只升 SDK 就宣稱補檢已完整。

L3B 已在本機補上完整每日巡檢候選，沿用上述持久儲存及 GET，不新增 Sync API：

- 每站依來源／金鑰固定選在客戶時區 03:00～03:09 開始；Laravel scheduler 在 03 點每分鐘接續同一輪，04:00 不再啟動新檢查。每批預設最多 10 筆、共用 5 秒等待預算，上限 20 筆／10 秒；不代表檔案系統阻塞或主機負載也有硬性期限。
- 已發起的檢查同日不重複；未嘗試尾端先於上一輪已檢查項目。容量被占用、熔斷等待或尚未發出 HTTP 就耗盡預算，保留給下一批。程序中斷後結果不明的項目記為失敗，當天不冒險重複；失敗或 04:00 仍有待處理內容，都不能算整輪完成，舊本文不因此刪除。
- 識別索引與進度保存在 `SMKING_DELIVERY_LOCAL_STORE_PATH`，不受 `cache:clear` 影響。獨立的非阻塞本機檔案鎖序列化巡檢，不鎖住訪客讀取；只支援同一主機的本機檔案系統，不宣稱跨主機／NFS 協調。索引預設 500 筆，上限 1,000 筆；這是保護上限，不是實測吞吐保證。暫停 AEO 時略過 AEO／Markdown／公共檔案，CMS／Blog 仍按原則檢查。
- 新版下載先登記索引才提交本文；登記失敗不替換成功舊版。訪客讀取不登記或修補索引。`php artisan smking:delivery:reconcile --status` 是唯讀觀測，列出 `known`、`eligible`、`checked`、`refreshed`、`pending`、`failed`、`in_flight`、`complete` 與最近完成日期；`smking:doctor --json` 只在當日全輪成功時將此項標為通過，不以「曾啟動」代替完成。doctor 的整體「安裝正常」仍不是啟用授權。
- 升級時停止舊寫入者、保持 `legacy`，先依可信已知識別用 `smking:delivery:import` 匯入內容，再用 `smking:delivery:reconcile --import-index` 明確匯入原 format 2 索引。此步無 HTTP、不刪舊 cache、不掃 Redis；只接受本機已有合法本文的識別，保留原驗證日期，不把搬資料當成新檢查。部分匯入持續顯示未完成，補齊後重跑才解除。索引已遺失／損毀時不以空清單或舊 cache 冒充恢復；需核對持久備份／可信清單，L4 切換前仍須完整就緒檢查。

L4A 本機候選已將一般公開讀取改成純本地。CMS／AEO／Markdown／公共檔案無論暖頁、冷頁、已知待更新或本機副本損毀，都不從訪客路徑下載、排下載或修補索引。原商品 HTML 在 AEO／Markdown 未準備時仍可顯示；未準備 Blog、sitemap／llms.txt 明確回 503，已知撤回 Blog 回 404。獨立網址觀測／健康／爬蟲回報保留，不能據此視為已準備內容。`page_budget_ms` 不再控制一般公開讀取，明確背景取得仍有原等待與容量限制。

**草稿預覽例外：** 2026-09-19 老闆明確選擇保留帶 `smking_preview` 的即時草稿預覽。這條入口仍經既有 v1 API 驗證 token、即時取草稿、不快取，也不寫入公開持久副本；不能將含預覽參數的流量納入「全部零回源」宣稱。一般不帶預覽參數的公開讀取維持零內容 HTTP／下載登記，預設 `legacy` 舊模式不因本次修改變更。

首次取得不能靠訪客；可由管理者依 SaaS 已發布內容的可信識別，明確執行既有預熱命令的新選項。以下是假識別範例，不會自動發現全站內容：

```bash
php artisan smking:delivery:prewarm --slug=article
php artisan smking:delivery:prewarm --resource=aeo --identifier=path:/products/article
php artisan smking:delivery:prewarm --resource=markdown --identifier=path:/products/article
php artisan smking:delivery:prewarm --resource=site-file --identifier=kind:sitemap
php artisan smking:delivery:prewarm --resource=cms-page --identifier=slug:article --check
```

每次只處理同一類明確識別，預設最多 10 筆／5 秒，上限 20 筆／10 秒。`--slug` 保持相容，但不可與 `--resource`／`--identifier` 混用。`legacy` 與 `on_demand` 都可明確預熱新內容，不要求已啟用通知、不修改模式；背景工作／回報狀態仍須就緒。新版本通知沿用已有背景 worker；無通知的新內容需管理者或受信任發布程序提供識別並執行預熱，不能以每日已知索引代替此步。

`--check` 不發 HTTP、不寫持久狀態：核對指定本文、目前已知目標、持久索引與背景狀態，回報每筆 `content_version`；舊本文已過 freshness 時間仍可用，不因 TTL 判定失效。但新目標仍待取得、索引丟失／損毀／滿載等情況不能回報就緒。**此結果只證明指定內容在本機可用，不證明已列出整次發布的全部內容、沒有遺漏來源更新，或舊備份已重新獲授權。** 以下 L4B1 補上明確版本與整份清單核對，完整來源及操作放行門檻仍未完成；不能拿 `--check` 成功直接部署或把 `registered` 改稱客戶已生效。

### L4B1 發布清單核對（未發布、本機候選）

明確執行預熱現在使用 `OnDemandDelivery::prepare()`：不再因成功本文仍 fresh 而省略來源檢查，但保留容量、熔斷、撤回及權限保護。失敗會使本次命令失敗，不因仍有舊本文便宣稱準備完成；一般公開讀取與每日背景 `refresh()` 不受此變更影響。

**未發布候選：耗盡工作的受控恢復。** 來源連續失敗三次後，背景工作仍停止自動重試。管理者確認來源恢復、work／report heartbeat 及其他狀態正常後，可對指定識別執行上述明確預熱。只有有效的 200 快照完成版本、權限與撤回核對並寫入持久副本後，才移除同一識別已耗盡且未執行中的工作；其他工作與失敗紀錄不會清除。503、無效快照或下載途中撤回均不解除耗盡狀態。`--check` 仍唯讀，`--plan` 仍要求整體背景就緒；訪客不觸發重試，也不延長既有三次上限。若另有過期／丟失工作、回報錯誤或心跳失效，須先處理原因，不能藉此預熱掩蓋。

`--plan` 接受管理者核對後的本機 JSON 檔，包含整次作業的有限 target 清單，不是全站發現或 Sync API。輸入來源必須是**當前 SaaS 已提交發布紀錄**，不可從客戶舊備份／本地索引推導完整性，也不能只用可能仍舊的 CDN 回應作為「來源最新」證據。L4B2 已補下述只讀來源匯出／再次比對工具；真實環境、必要引用及切換仍須驗收。

格式如下，範例中的來源、指紋、版本及雜湊皆須替換成經核對的真值：

```json
{
  "format": 1,
  "source": "https://api.example.test",
  "keyFingerprint": "<目前 API key 的 64 位 SHA-256 十六進位指紋，不放原始 key>",
  "targets": [{
    "resource": "cms-page", "identifier": "slug:article", "action": "update",
    "revision": 42, "generation": 42, "withdrawalRevision": 0,
    "contentVersion": "sha256:<64 位十六進位本文版本>"
  }]
}
```

四類 target 沿用既有 `delivery.publication` 欄位；撤回使用 `action=withdraw`、`contentVersion=null` 且 `withdrawalRevision=revision`。清單須涵蓋本次相關的已發布內容及撤回，不能只列希望看見的成功頁。來源與金鑰指紋須符合目前 SDK 設定；只讀絕對路徑的一般本機檔，拒絕 URL、重複 target、錯誤識別、空清單、超過 1 MiB 或 1,000 筆的輸入。這是輸入保護上限，不是實測容量承諾。

```bash
php artisan smking:delivery:prewarm --plan=/private/path/release.json --offset=0
# 依 next_offset 明確執行後續批次；例如下一批從 10 開始。
php artisan smking:delivery:prewarm --plan=/private/path/release.json --offset=10
php artisan smking:delivery:prewarm --plan=/private/path/release.json --check
```

每次最多準備設定的批次筆數，**每次結果都檢查整份清單**；`--check` 不可指定 offset，也不可混用 `--slug`／`--resource`／`--identifier`。核對本文配對的發布證據、完整 revision／generation／撤回紀錄及持久索引，不只比本文雜湊。來源回舊版、少證據、失敗或已知更高版本都不能符合舊清單；取得較新撤回仍先保留安全屏障。無通知的重新發布可由清單指定高於撤回且保留撤回歷史的版本，再取得相符來源證據才恢復；一般讀取、舊清單或舊回應無法解除撤回。

輸出包含 `plan_hash`、全清單 `requested`／`ready`、本批 `processed`、逐筆結果、`next_offset`、`complete` 與 `error`。尚有缺項會以 `release_not_ready`／非零退出，不代表應無限重試前批；應保存同一 `plan_hash` 的每批結果並處理錯誤後續接。`next_offset` 只是本次輸入的位置，不是伺服器 cursor，也不會自動排程。失敗的本批不回 `complete=true`；後續成功批次不能代替先前失敗批次的來源核對。

`verification=local_only` 表示唯讀本地比對；`source_batch_and_local_list` 表示本批明確準備加整份本地比對。**兩者的 `complete` 都不代表權限已重新確認、全站清單完整或允許對外。** 已保存撤回／權限屏障可能直接拒絕取得，因此 `processed` 也不是 HTTP 計數；此命令不是即時來源探針。

備份還原必須先停止對外與寫入者，在隔離路徑處理備份；清單要取自當前來源，不能跟著舊備份一起還原。保留每批來源核對、最後完整檢查及必要圖片／樣式／外部引用的證據後才考慮切換。若無法核對所有舊副本及撤回，維持隔離，使用新的空白持久目錄依當前可信清單重新準備，不直接把舊目錄接回公開讀取。這些操作未自動執行；實機隔離／切換、必要引用及還原仍待 L5 驗證。

### L4B2 來源匯出與切換檢查（未發布、本機候選）

SaaS repo 的 `apps/web/scripts/export-delivery-preparation.mts` 是管理者使用的只讀工具，不是新 API 或排程。只接受明確的 `SMKING_PREPARATION_DATABASE_URL`，不讀 `.env`、不使用一般 `DATABASE_URL`。使用單一只讀 `repeatable read` 交易，限定 workspace／site／資源範圍；輸出不含原始 key。操作者仍須核對資料庫確實屬於 `--source` 的部署、其 GET／來源發布旗標已獲准啟用，以及資源範圍符合客戶實際掛載；命令無法從任意網址推知部署設定，也不會開旗標。

```bash
# 在 apps/web 執行；連線由已授權的秘密管理方式注入，不寫入命令、Git 或紀錄。
pnpm exec tsx scripts/export-delivery-preparation.mts \
  --site=SITE_UUID --workspace=WORKSPACE_UUID --source=https://DELIVERY_SOURCE \
  --resources=cms-page,aeo,markdown,site-file --files=sitemap,llms_txt \
  --output=/private/path/new-release
```

`--output` 必須是不存在的新目錄。產生 `plan.json`（給 SDK 預熱）及 `checkpoint.json`（供來源再次核對），不覆蓋舊證據。只準備 Blog 時明確使用 `--resources=cms-page`、不傳 `--files`；不能為了繞過錯誤而縮小實際需要的範圍。工具保留所選資源的撤回；對照已發布 CMS、AEO path／product_id、Markdown、已發布 tag 模板的分類頁與所選公共檔案。草稿不當公開來源；未追蹤舊內容、待準備 target、掛載搬移未完成、缺能力、錯租戶、版本／數量超限均拒絕。每類來源／target 最多讀 1,001 筆以偵測上限，不會靜默截斷。

準備與必要引用核對後，使用**相同參數**把 `--output` 換成 `--check=/private/path/new-release`。工具重新從來源讀取並核對整份 plan 雜湊與來源 checkpoint；來源 revision、目錄／版本、金鑰、站台狀態、網域或相關來源識別資料改變即拒絕。改版後需重新匯出新目錄並重走核對，不能編輯 checkpoint 強行通過。它只證明兩次觀測相符，不鎖住後續發布；`activationAuthorized` 永遠為 false。

放行順序（此輪未在任何主機執行）：

1. 明確核對來源部署、資料庫、站台、金鑰指紋、SDK 版本及啟用資源。首次切換／還原前隔離訪客與所有舊寫入者，協調短暫停止來源發布／重算直到最後核對與切換，不能把只讀交易當跨系統鎖。
2. 從當前來源匯出清單，依同一 `plan_hash` 完整覆蓋全部批次。中途 `release_not_ready` 可表示尾段尚未準備，其他錯誤須處理並重跑該批；保留每批結果，最後 SDK `--plan ... --check` 必須全數符合。
3. 在隔離主機逐類核對可見內容、圖片、字型、樣式、腳本及嵌入資源；記錄可本地供應與仍依賴外部的項目、失敗後呈現及接受理由。來源工具不下載或鏡像資產，也不以「有 HTML」證明整頁離線可用。缺證據不得放行。
4. 再執行 SaaS `--check`，確認來源與匯出一致；若不同則重新準備。連同實機零內容回源、排程／worker、故障與回退證據一起人工審查，另行取得切換授權。
5. 還原優先使用新的空白私有持久目錄重新準備，保留舊備份但不接回服務，也不匯入舊工作佇列／索引。舊備份可能含追蹤開啟前的資料或已消失的撤回歷史，來源工具不能重建未知歷史；不能證明安全就保持隔離。若必須直接復原原目錄，先另訂並驗證完整還原程序，不以這組命令自動放行。

套件不啟動主機 scheduler，客戶仍須自行執行 Laravel scheduler 與背景 worker。L4B2 的來源工具與操作門檻已有本機候選，正式使用前仍須審查／CI 及真實主機驗收；本機候選測試不是實機凌晨輪次、正式 CDN 或 PHP-FPM 容量證據。

### 回報損失確認（下一版候選）

獨立回報若因容量、過期或重試耗盡留下永久損失，先讀取狀態並調查原因：

```bash
php artisan smking:delivery:report --status
```

確認資料已無法補送且損失可接受後，才使用該次狀態中的精確 token：

```bash
php artisan smking:delivery:report --ack-losses='sha256:從 status 複製的完整值'
```

此命令不發 HTTP，只清除 token 精確對應的 `losses`；待送事件、路徑觀測、故障計數與 `last_error` 均保留。檢查後若又有新損失，或清空後重播舊 token，命令會回 `losses_changed`，必須重新執行 `--status` 並調查。不得以刪除整筆 Redis／cache key 代替。完整步驟與尚待實機驗證的界線見[SDK 回報損失確認操作](../../docs/research/sdk-on-demand-delivery-report-loss-operations-2026-09-30.md)。

Keep the customer site's `composer.lock` unchanged until that site is ready to
test this version. SaaS migrations, delivery flags, CDN behavior, and the
customer's worker and rollback procedure need separate validation before
setting `SMKING_DELIVERY_MODE=on_demand`. Releasing this package does not enable
any of those dependencies.

## Upgrading

This package is in `v0.x`. Per Composer's caret convention for pre-1.0 packages, **every minor bump (0.5 → 0.6, 0.6 → 0.7) is treated as breaking** — the constraint `"smking/laravel": "^0.6"` resolves to `>=0.6.0 <0.7.0` and `composer update` won't cross into 0.7.

### Cross-minor upgrade (e.g. 0.6 → 0.7)

Edit `composer.json` to bump the constraint, then update:

```bash
# 1. Bump constraint
composer require smking/laravel:^0.7

# 2. (optional) refresh published config — see docs/upgrading note below
php artisan vendor:publish --tag=smking-config --force
php artisan config:clear

# 3. Verify install
php artisan smking:doctor
```

`smking:doctor` (v0.6.3+) shows a "config schema drift" row that lists any new keys present in the package default but missing from your published `config/smking.php` — handy for deciding whether to re-publish.

### In-minor upgrade (patch, e.g. 0.6.1 → 0.6.2)

Patches stay in your existing `^0.X` range — `composer update` is enough:

```bash
composer update smking/laravel
```

### Deploying to production

Always commit `composer.lock` to your repo and use `composer install` (NOT `update`) on production deploys:

```bash
# CI / deploy script
composer install --no-dev --optimize-autoloader
```

`composer install` reads the lockfile and installs the exact versions you tested in staging. `composer update` re-resolves and may pull a release into prod that bypassed QA — especially risky while this package is `v0.x` with breaking minors. Always bump in dev, test in staging, then ship the lockfile.

See [CHANGELOG.md](CHANGELOG.md) for what each release changes.

## Requirements

- PHP 8.1+
- Laravel 10 / 11 / 12

## License

MIT
