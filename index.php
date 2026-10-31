<?php
/**
 * 页面外壳（前端为 hash 路由，所有页面都由本文件输出）
 *
 * 下面这段算的是静态资源版本戳：有了它，改完前端代码上传后浏览器 / CDN 才会去拉新文件。
 * 优先级：config.php 里的 asset_version（发布时手工改，最可控）
 *         → 没配就自动取 assets/ 目录下所有文件的最新修改时间（上传即生效）。
 * 注意：ES Module 的子模块（views/*.js）由 app.js 里的 import 加载，不带版本参数，
 *      所以服务端还要把 /assets/ 下的 js、css 配成协商缓存（见 deploy/nginx-site.conf）。
 */
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/maintenance.php';
header('Cache-Control: no-store');
$__maintenance = maintenance_state();
if ($__maintenance['enabled'] && !maintenance_admin() && !isset($_GET['admin'])) {
    http_response_code(503);
    header('Retry-After: 3600');
    require __DIR__ . '/maintenance-page.php';
    exit;
}
$__assetVersion = '';
$__cfgFile = __DIR__ . '/config.php';
if (is_file($__cfgFile)) {
    $__cfg = require $__cfgFile;
    if (is_array($__cfg) && !empty($__cfg['asset_version'])) {
        $__assetVersion = (string) $__cfg['asset_version'];
    }
}
if ($__assetVersion === '') {
    $__newest = 0;
    $__base = __DIR__ . '/assets';
    if (is_dir($__base)) {
        $__it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($__base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($__it as $__f) {
            if ($__f->isFile() && $__f->getMTime() > $__newest) {
                $__newest = $__f->getMTime();
            }
        }
    }
    $__assetVersion = $__newest ? date('YmdHis', $__newest) : '1';
}
$__assetVersion .= '-maintenance-1';
?><!DOCTYPE html>
<html lang="zh-CN">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>华政选课 · 课程评分与选课讨论</title>
  <meta name="description" content="华东政法大学选课经验分享社区：课程评分、短评、课程讨论与友邻闲聊。" />
  <link rel="stylesheet" href="assets/style.css?v=<?= $__assetVersion ?>" />
</head>
<body>
  <header class="site-header">
    <div class="wrap header-inner">
      <a class="logo" href="#/">华政选课</a>
      <nav class="main-nav">
        <a href="#/courses">找课</a>
        <a href="#/chat">友邻</a>
        <a href="#/new-course">添加课程</a>
        <a href="#/import">批量导入</a>
      </nav>
      <form class="header-search" id="header-search">
        <input type="search" name="q" placeholder="搜课程 / 老师 / 课号" autocomplete="off" />
        <button type="submit">搜索</button>
      </form>
      <div class="user-area" id="user-area"></div>
    </div>
  </header>

  <main id="app" class="wrap"></main>

  <footer class="site-footer">
    <div class="wrap">
      <p>华政选课 · 由学生共建的选课经验社区</p>
      <p class="muted">评分与言论仅代表发布者个人体验，请理性参考。</p>
    </div>
  </footer>

  <div id="toast-box" class="toast-box"></div>

  <script type="module" src="assets/js/app.js?v=<?= $__assetVersion ?>"></script>
</body>
</html>
