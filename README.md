# Page Zero Laravel SDK

`smking/laravel` 將 Page Zero 的 CMS 內容、SEO metadata 與 AEO 結構化資料整合到 Laravel 網站。

## 功能

- **AEO**：在伺服器端輸出 JSON-LD、FAQ 與 AI 摘要，供搜尋引擎與 AI 服務讀取。
- **CMS**：透過 Blade 元件呈現已發布內容，支援巢狀路徑與草稿預覽。
- **SEO 與分享標籤**：輸出標題、描述、canonical、Open Graph 與 Twitter metadata；CMS 人工欄位優先，無須先生成 AEO。
- **AI 讀取入口**：提供 Markdown 內容、sitemap 與 llms.txt。
- **背景交付**：啟用 `on_demand` 後，公開頁面讀取本機持久副本，內容更新由背景工作處理。

SDK 沿用既有內容與圖片；補齊 metadata 不會額外呼叫 AI 或下載、調整圖片。

## 安裝與升級

請將 [Laravel install prompt](https://getpagezero.com/docs/sdk/laravel.md) 交給你的程式助理，依現有專案完成整合。
安裝、升級、設定、預熱與部署驗收均以該指南為準。

## 版本與需求

- PHP 8.1+、Laravel 10／11／12。
- [正式版本](https://github.com/sillyleo/smking-laravel/releases) · [更新紀錄](CHANGELOG.md)
- 授權：MIT。
