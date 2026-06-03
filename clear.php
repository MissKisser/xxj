<?php
// BBJ 缓存清理入口
// 删除 ./cache/ 目录下的所有文件（不删除子目录）
// 不接收参数、不做远程调用
$cache_dir = __DIR__ . '/cache/';

if (!is_dir($cache_dir)) {
    header('Content-Type: text/plain; charset=utf-8');
    die('缓存目录不存在，无需清理');
}

$deleted = 0;
$errors  = array();
$entries = scandir($cache_dir);

foreach ($entries as $name) {
    if ($name === '.' || $name === '..') continue;
    $path = $cache_dir . $name;
    if (is_file($path)) {
        if (@unlink($path)) {
            $deleted++;
        } else {
            $errors[] = $name;
        }
    }
}

header('Content-Type: text/plain; charset=utf-8');
if (empty($errors)) {
    echo '缓存已清理，共删除 ' . $deleted . ' 个文件';
} else {
    echo '缓存已清理 ' . $deleted . ' 个文件，失败 ' . count($errors) . ' 个：' . implode(', ', $errors);
}
