<?php
// BBJ 海报自动更新入口
// TMDB 热门影片 → 本地 vod_name 精确匹配 → UPDATE vod_pic_slide + vod_level
// 数据源：TMDB v3 API（直连，无需代理）
define('BBJ_INCLUDED', true);

// 文件缓存：1 小时 TTL，序列化到 ./cache/md5(key)
function bbj_cache_set($key, $data, $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    file_put_contents($dir . md5($key), serialize($data));
}

function bbj_cache_get($key, $ttl, $dir) {
    $f = $dir . md5($key);
    if (file_exists($f) && (time() - filemtime($f) < $ttl)) {
        $v = unserialize(file_get_contents($f));
        return $v === false ? false : $v;
    }
    return false;
}

$config_file = __DIR__ . '/haibao_config.php';
if (!file_exists($config_file)) {
    die('❌ 配置文件缺失，请检查 haibao_config.php');
}
$cfg = include $config_file;

$db_conf_file = __DIR__ . '/../application/database.php';
if (!file_exists($db_conf_file)) {
    die('❌ 无法找到数据库配置文件');
}
$db = include $db_conf_file;

$conn = new mysqli($db['hostname'], $db['username'], $db['password'], $db['database'], (int)$db['hostport']);
if ($conn->connect_error) {
    die('❌ 连接数据库失败: ' . $conn->connect_error);
}
$conn->set_charset($db['charset'] ?? 'utf8');

// URL 参数解析与钳制
$bbjtype = isset($_GET['bbjtype']) ? (string)$_GET['bbjtype'] : 'hot';
if (!in_array($bbjtype, array('hot', 'new', 'even'), true)) {
    $bbjtype = 'hot';
}

$num_max = isset($cfg['num_max']) ? (int)$cfg['num_max'] : 15;
$num = isset($_GET['num']) ? (int)$_GET['num'] : 10;
if ($num < 1) $num = 1;
if ($num > $num_max) $num = $num_max;

$level = isset($_GET['level']) ? (int)$_GET['level'] : 9;
if ($level < 1) $level = 1;
if ($level > 9) $level = 9;

$filtercondi = isset($_GET['filtercondi']) ? (string)$_GET['filtercondi'] : 'doubanid';
if (!in_array($filtercondi, array('doubanid', 'name'), true)) {
    $filtercondi = 'doubanid';
}

$orderby = isset($_GET['orderby']) && strtoupper($_GET['orderby']) === 'DESC' ? 'DESC' : 'ASC';
// orderby 含义：ASC=取最近一次 → ORDER BY vod_time DESC，DESC=取第一次 → ORDER BY vod_time ASC
$order_sql = ($orderby === 'ASC') ? 'DESC' : 'ASC';

// codetype 为兼容原 URL 保留，内部不使用

// 缓存查询
$cache_key  = 'haibao_' . $bbjtype . '_' . $num . '_' . $level;
$cache_ttl  = isset($cfg['cache_ttl']) ? (int)$cfg['cache_ttl'] : 3600;
$cache_dir  = __DIR__ . '/cache/';
$movies     = bbj_cache_get($cache_key, $cache_ttl, $cache_dir);
$from_cache = ($movies !== false && is_array($movies));

if (!$from_cache) {
    $api_base  = 'https://api.themoviedb.org/3';
    $endpoint  = $bbjtype === 'hot' ? 'movie/popular'
               : ($bbjtype === 'new' ? 'movie/now_playing'
               : 'movie/top_rated');
    $lang      = isset($cfg['tmdb_lang']) ? $cfg['tmdb_lang'] : 'zh-CN';
    $field     = isset($cfg['image_field']) ? (string)$cfg['image_field'] : 'poster';
    if (!in_array($field, array('poster', 'backdrop'), true)) {
        $field = 'poster';
    }
    $img_size  = $field === 'backdrop'
        ? (isset($cfg['backdrop_size']) ? $cfg['backdrop_size'] : 'w1280')
        : (isset($cfg['image_size']) ? $cfg['image_size'] : 'w500');
    $img_base  = 'https://image.tmdb.org/t/p/' . $img_size . '/';
    $field_key = $field . '_path';

    $movies = array();
    $pages  = (int)ceil($num / 20);
    if ($pages < 1) $pages = 1;

    for ($page = 1; $page <= $pages && count($movies) < $num; $page++) {
        $api_url = $api_base . '/' . $endpoint
                 . '?api_key=' . urlencode($cfg['tmdb_v3_key'])
                 . '&language=' . urlencode($lang)
                 . '&page=' . $page;

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($code !== 200 || $resp === false) {
            die('❌ TMDB API 错误 ' . $code . ($err ? '（' . htmlspecialchars($err) . '）' : '') . '：' . htmlspecialchars(substr((string)$resp, 0, 200)));
        }

        $data = json_decode((string)$resp, true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            die('❌ TMDB 响应格式异常');
        }

        foreach ($data['results'] as $r) {
            if (count($movies) >= $num) break;
            if (isset($r[$field_key]) && is_string($r[$field_key]) && $r[$field_key] !== '') {
                $movies[] = array(
                    'title' => $r['title'] ?? '',
                    'url'   => $img_base . ltrim($r[$field_key], '/'),
                );
            }
        }
    }

    if (empty($movies)) {
        die('❌ TMDB 未返回结果，请稍后重试');
    }

    bbj_cache_set($cache_key, $movies, $cache_dir);
}

// 轮播图写入策略（模拟原 BBJ 插件行为）：
// 1. 清理旧占位行（兼容旧版 INSERT 残留）
// 2. 重置上次由 BBJ 设为当前 level 的视频回 level=0
// 3. filtercondi=doubanid → 按 vod_name 匹配 + vod_douban_id > 0 校验
//    filtercondi=name → 按 vod_name 匹配（不校验豆瓣 ID）
// 4. orderby=ASC（取最近一次）→ ORDER BY vod_time DESC，反之亦然
// 5. 匹配成功 → UPDATE vod_pic_slide + vod_level
$table = isset($cfg['db_table']) ? $cfg['db_table'] : 'mac_vod';

// 1. 清理旧占位行
$conn->query("DELETE FROM {$table} WHERE vod_name LIKE 'BBJ轮播图-%'");

// 2. 重置上次 BBJ 设置的同 level 视频
$conn->query("UPDATE {$table} SET vod_level = 0 WHERE vod_level = {$level}");

// 3+4. 按 filtercondi 构建 SELECT，按 orderby 处理同名重复
if ($filtercondi === 'name') {
    $find_sql = "SELECT vod_id FROM {$table} WHERE vod_name = ? ORDER BY vod_time {$order_sql} LIMIT 1";
} else {
    $find_sql = "SELECT vod_id FROM {$table} WHERE vod_name = ? AND vod_douban_id > 0 ORDER BY vod_time {$order_sql} LIMIT 1";
}

$find_stmt = $conn->prepare($find_sql);
$upd_stmt  = $conn->prepare(
    "UPDATE {$table} SET vod_pic_slide = ?, vod_level = {$level} WHERE vod_id = ?"
);
if (!$find_stmt || !$upd_stmt) {
    die('❌ 预编译失败: ' . $conn->error);
}

$updated = 0;
$skipped = 0;
$matched_list = array();
$errors  = array();

foreach ($movies as $i => $movie) {
    $title = $movie['title'];
    $url   = $movie['url'];

    $find_stmt->bind_param('s', $title);
    $find_stmt->execute();
    $res = $find_stmt->get_result();

    if ($row = $res->fetch_assoc()) {
        $vid = (int)$row['vod_id'];
        $upd_stmt->bind_param('si', $url, $vid);
        if ($upd_stmt->execute() && $upd_stmt->affected_rows > 0) {
            $updated++;
            $matched_list[] = $title;
        } else {
            $errors[] = $title . ' UPDATE失败';
        }
    } else {
        $skipped++;
    }
}
$find_stmt->close();
$upd_stmt->close();

if ($updated === 0) {
    die('❌ 未匹配到任何本地影片，共尝试 ' . count($movies) . ' 部，全部跳过');
}

$cache_tag = $from_cache ? '，来自缓存' : '';
$condi_tag = $filtercondi === 'name' ? '影片名称' : '豆瓣ID';
echo '✅ 海报已成功更新（' . $bbjtype . '，' . $level . '星，' . $updated . '/' . count($movies) . '张匹配' . $cache_tag . '）'
   . '<br>匹配条件：' . $condi_tag . '，已更新影片：' . implode('、', $matched_list);
if ($skipped > 0) {
    echo '<br>⚠️ 跳过 ' . $skipped . ' 部（本地无匹配）';
}
if (!empty($errors)) {
    echo '<br>⚠️ 部分失败：' . htmlspecialchars(implode('；', $errors));
}

$conn->close();
