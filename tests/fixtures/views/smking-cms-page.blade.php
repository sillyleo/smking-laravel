<!doctype html>
<html lang="zh-Hant">
<head>
    <meta charset="utf-8">
    <title>{{ $cms->title }}</title>
    <x-smking-runtime />
</head>
<body>
    <nav aria-label="Blog">
        <a href="/blog">Blog</a>
        <a href="/blog/category/news">最新消息</a>
    </nav>
    <main>
        <x-smking-cms :slug="$slug" :page="$cms" />
    </main>
</body>
</html>
