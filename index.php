<?php
// BBJ 管理界面入口
// 仅输出本地 index.html，不做远程拉取、不做文件同步
$local = __DIR__ . '/index.html';

if (!file_exists($local)) {
    header('Content-Type: text/html; charset=utf-8');
    die('<!DOCTYPE html><html><head><meta charset="utf-8"><title>BBJ 初始化</title></head><body>'
      . '<h1>本地 index.html 缺失</h1>'
      . '<p>请参考 SPEC.md 第 8 节部署步骤，将管理界面文件落地到本目录后重试。</p>'
      . '</body></html>');
}

header('Content-Type: text/html; charset=utf-8');
readfile($local);
