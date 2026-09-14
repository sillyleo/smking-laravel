# Laravel 10 隔離相容性測試

這是獨立 Composer 測試專案，不是可部署網站。不讀取客戶儲存庫或 `.env`；SDK 透過 Composer path repository 連到本機工作樹。

在本目錄執行：

```sh
composer install --no-interaction --no-scripts --no-plugins --prefer-dist
php vendor/bin/phpunit --do-not-cache-result
```

固定 Laravel `10.50.2`，搭配 Testbench 8、PHPUnit 10。第一次安裝會產生本機 `composer.lock`；其他依賴未鎖進 Git，換機驗證須記錄 PHP、Composer 與實際解析版本。

`audit.block-insecure=false` 僅用於隔離的歷史版本測試專案，避免 Composer 的安全封鎖取代相容性測試；不代表建議部署過期框架，也不修改正式套件或客戶的安全政策。

乾淨安裝的 Laravel 10 不會自行帶入 SDK 的 HTTP 執行依賴；此環境也用來防止正式套件漏宣告 `guzzlehttp/guzzle`。
