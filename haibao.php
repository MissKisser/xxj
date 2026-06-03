<?php
// BBJ 海报自动更新入口 v3.0
// 三种独立数据源 + 本地降级策略
// hot：猫眼热映 → 本地匹配 → TMDB 搜图（降级：本地热度排序）
// new：本地最新入库 → TMDB 搜图（降级：只更新 vod_level）
// top：TMDB 高分榜 → 本地匹配，直接用 TMDB backdrop（降级：本地评分排序）
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

// 获取猫眼热映列表
function fetch_maoyan_hot($timeout) {
    $url = 'https://m.maoyan.com/ajax/movieOnInfoList';
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => array(
            'User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/16.0 Mobile/15E148 Safari/604.1',
            'Accept: application/json, text/plain, */*',
        ),
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || $resp === false) {
        return false;
    }
    $data = json_decode((string)$resp, true);
    if (!is_array($data) || !isset($data['movieList']) || !is_array($data['movieList'])) {
        return false;
    }
    $names = array();
    foreach ($data['movieList'] as $m) {
        if (isset($m['nm']) && is_string($m['nm']) && $m['nm'] !== '') {
            $names[] = $m['nm'];
        }
    }
    return $names;
}

// 本地热度降级
function fetch_local_hot($num, $conn, $table) {
    $sql = "SELECT vod_id, vod_name FROM {$table} WHERE vod_hits > 0 ORDER BY vod_hits DESC LIMIT " . (int)$num;
    $res = $conn->query($sql);
    $rows = array();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

// 本地评分降级
function fetch_local_top($num, $conn, $table) {
    $sql = "SELECT vod_id, vod_name FROM {$table} WHERE vod_score > 0 ORDER BY vod_score DESC LIMIT " . (int)$num;
    $res = $conn->query($sql);
    $rows = array();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

// 本地最新影片
function fetch_local_new($num, $conn, $table) {
    $sql = "SELECT vod_id, vod_name FROM {$table} ORDER BY vod_time DESC LIMIT " . (int)$num;
    $res = $conn->query($sql);
    $rows = array();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $res->free();
    }
    return $rows;
}

// TMDB 搜索 backdrop，&$api_error 用于区分 API 错误与无结果
function tmdb_search_backdrop($name, $api_key, $lang, $img_base, $timeout, &$api_error = false) {
    $url = 'https://api.themoviedb.org/3/search/movie'
         . '?api_key=' . urlencode($api_key)
         . '&language=' . urlencode($lang)
         . '&query=' . urlencode($name);
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ));
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || $resp === false) {
        $api_error = true;
        return false;
    }
    $data = json_decode((string)$resp, true);
    if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
        return false;
    }
    foreach ($data['results'] as $r) {
        if (isset($r['backdrop_path']) && is_string($r['backdrop_path']) && $r['backdrop_path'] !== '') {
            return $img_base . ltrim($r['backdrop_path'], '/');
        }
    }
    return false;
}

// TMDB 获取 top_rated
function fetch_tmdb_top_rated($num, $api_key, $lang, $autofill) {
    $results = array();
    $max_page = $autofill ? 5 : 1;
    $api_base = 'https://api.themoviedb.org/3';

    for ($page = 1; $page <= $max_page && count($results) < $num; $page++) {
        $url = $api_base . '/movie/top_rated'
             . '?api_key=' . urlencode($api_key)
             . '&language=' . urlencode($lang)
             . '&page=' . $page;
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || $resp === false) {
            return false;
        }
        $data = json_decode((string)$resp, true);
        if (!is_array($data) || !isset($data['results']) || !is_array($data['results'])) {
            return false;
        }
        foreach ($data['results'] as $r) {
            if (count($results) >= $num) break;
            if (isset($r['backdrop_path']) && is_string($r['backdrop_path']) && $r['backdrop_path'] !== '' && isset($r['title'])) {
                $results[] = array(
                    'title' => $r['title'],
                    'url'   => $r['backdrop_path'],
                );
            }
        }
    }
    return $results;
}

// hot 模式：猫眼 → 本地匹配 → TMDB 搜图
function fetch_hot_movies($num, $autofill, $conn, $table, $cfg, $img_base) {
    $maoyan_enabled = isset($cfg['maoyan_enabled']) ? (bool)$cfg['maoyan_enabled'] : true;
    $maoyan_timeout = isset($cfg['maoyan_timeout']) ? (int)$cfg['maoyan_timeout'] : 8;
    $tmdb_timeout   = isset($cfg['tmdb_search_timeout']) ? (int)$cfg['tmdb_search_timeout'] : 5;
    $api_key = $cfg['tmdb_v3_key'] ?? '';
    $lang    = isset($cfg['tmdb_lang']) ? $cfg['tmdb_lang'] : 'zh-CN';

    $names = false;
    if ($maoyan_enabled) {
        $names = fetch_maoyan_hot($maoyan_timeout);
    }

    $is_degraded = false;
    if ($names === false) {
        $rows = fetch_local_hot($num, $conn, $table);
        $is_degraded = true;
        $names = array();
        foreach ($rows as $row) {
            $names[] = $row['vod_name'];
        }
    }

    if (empty($names)) {
        return array('movies' => array(), 'is_degraded' => $is_degraded, 'degrade_msg' => '');
    }

    $movies = array();
    $tmdb_api_error = false;

    foreach ($names as $name) {
        if (count($movies) >= $num) break;

        $stmt = $conn->prepare("SELECT vod_id FROM {$table} WHERE vod_name = ? LIMIT 1");
        if (!$stmt) continue;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) continue;

        $api_error = false;
        $backdrop = tmdb_search_backdrop($name, $api_key, $lang, $img_base, $tmdb_timeout, $api_error);
        if ($api_error) {
            $tmdb_api_error = true;
            break;
        }
        if (!$backdrop) {
            usleep(250000);
            continue;
        }
        usleep(250000);

        $movies[] = array(
            'vod_id' => (int)$row['vod_id'],
            'title'  => $name,
            'url'    => $backdrop,
        );
    }

    if ($tmdb_api_error) {
        $degrade_msg = '⚠️ 外部数据源不可用，已使用本地数据降级更新';
        $local_rows  = fetch_local_hot($num, $conn, $table);
        $matched_ids = array();
        foreach ($movies as $m) {
            $matched_ids[$m['vod_id']] = true;
        }
        foreach ($local_rows as $row) {
            if (count($movies) >= $num) break;
            $vid = (int)$row['vod_id'];
            if (isset($matched_ids[$vid])) continue;
            $movies[] = array(
                'vod_id' => $vid,
                'title'  => $row['vod_name'],
                'url'    => '',
            );
        }
        return array('movies' => $movies, 'is_degraded' => true, 'degrade_msg' => $degrade_msg);
    }

    $degrade_msg = $is_degraded ? '⚠️ 猫眼数据源不可用，已使用本地热度降级更新' : '';
    return array('movies' => $movies, 'is_degraded' => $is_degraded, 'degrade_msg' => $degrade_msg);
}

// new 模式：本地最新 → TMDB 搜图
function fetch_new_movies($num, $autofill, $conn, $table, $cfg, $img_base) {
    $tmdb_timeout = isset($cfg['tmdb_search_timeout']) ? (int)$cfg['tmdb_search_timeout'] : 5;
    $api_key = $cfg['tmdb_v3_key'] ?? '';
    $lang    = isset($cfg['tmdb_lang']) ? $cfg['tmdb_lang'] : 'zh-CN';

    $limit = $autofill ? $num * 3 : $num;
    $rows  = fetch_local_new($limit, $conn, $table);

    if (empty($rows)) {
        return array('movies' => array(), 'is_degraded' => false, 'degrade_msg' => '');
    }

    $movies = array();
    $tmdb_api_error = false;

    foreach ($rows as $row) {
        if (count($movies) >= $num) break;

        $name = $row['vod_name'];
        $vid  = (int)$row['vod_id'];

        $api_error = false;
        $backdrop = tmdb_search_backdrop($name, $api_key, $lang, $img_base, $tmdb_timeout, $api_error);
        if ($api_error) {
            $tmdb_api_error = true;
            break;
        }
        if (!$backdrop) {
            usleep(250000);
            continue;
        }
        usleep(250000);

        $movies[] = array(
            'vod_id' => $vid,
            'title'  => $name,
            'url'    => $backdrop,
        );
    }

    if ($tmdb_api_error) {
        $degrade_msg = '⚠️ 外部数据源不可用，已使用本地数据降级更新';
        $degraded_movies = array();
        foreach ($rows as $row) {
            if (count($degraded_movies) >= $num) break;
            $degraded_movies[] = array(
                'vod_id' => (int)$row['vod_id'],
                'title'  => $row['vod_name'],
                'url'    => '',
            );
        }
        return array('movies' => $degraded_movies, 'is_degraded' => true, 'degrade_msg' => $degrade_msg);
    }

    if (empty($movies)) {
        return array('movies' => array(), 'is_degraded' => false, 'degrade_msg' => '❌ TMDB 搜索服务不可用，无法获取海报图');
    }

    return array('movies' => $movies, 'is_degraded' => false, 'degrade_msg' => '');
}

// top 模式：TMDB top_rated → 本地匹配
function fetch_top_movies($num, $autofill, $conn, $table, $cfg, $img_base) {
    $api_key = $cfg['tmdb_v3_key'] ?? '';
    $lang    = isset($cfg['tmdb_lang']) ? $cfg['tmdb_lang'] : 'zh-CN';

    $tmdb_list = fetch_tmdb_top_rated($num, $api_key, $lang, $autofill);

    if ($tmdb_list === false) {
        $rows = fetch_local_top($num, $conn, $table);
        $movies = array();
        foreach ($rows as $row) {
            $movies[] = array(
                'vod_id' => (int)$row['vod_id'],
                'title'  => $row['vod_name'],
                'url'    => '',
            );
        }
        return array('movies' => $movies, 'is_degraded' => true, 'degrade_msg' => '⚠️ 外部数据源不可用，已使用本地数据降级更新');
    }

    $movies = array();
    foreach ($tmdb_list as $item) {
        if (count($movies) >= $num) break;

        $name = $item['title'];
        $backdrop_path = $item['url'];

        $stmt = $conn->prepare("SELECT vod_id FROM {$table} WHERE vod_name = ? LIMIT 1");
        if (!$stmt) continue;
        $stmt->bind_param('s', $name);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res->fetch_assoc();
        $stmt->close();

        if (!$row) continue;

        $movies[] = array(
            'vod_id' => (int)$row['vod_id'],
            'title'  => $name,
            'url'    => $img_base . ltrim($backdrop_path, '/'),
        );
    }

    return array('movies' => $movies, 'is_degraded' => false, 'degrade_msg' => '');
}

// 写库
function write_movies_to_db($movies, $level, $conn, $table) {
    $conn->query("DELETE FROM {$table} WHERE vod_name LIKE 'BBJ轮播图-%'");
    $conn->query("UPDATE {$table} SET vod_level = 0 WHERE vod_level = {$level}");

    $upd_stmt = $conn->prepare("UPDATE {$table} SET vod_pic_slide = ?, vod_level = {$level} WHERE vod_id = ?");
    if (!$upd_stmt) {
        return array('updated' => 0, 'skipped' => 0, 'matched_list' => array(), 'errors' => array('预编译失败: ' . $conn->error));
    }

    $updated = 0;
    $skipped = 0;
    $matched_list = array();
    $errors = array();

    foreach ($movies as $movie) {
        $title = $movie['title'];
        $url   = $movie['url'];
        $vid   = (int)$movie['vod_id'];

        if ($url === '') {
            $conn->query("UPDATE {$table} SET vod_level = {$level} WHERE vod_id = " . $vid);
            $updated++;
            $matched_list[] = $title;
            continue;
        }

        $upd_stmt->bind_param('si', $url, $vid);
        if ($upd_stmt->execute() && $upd_stmt->affected_rows >= 0) {
            $updated++;
            $matched_list[] = $title;
        } else {
            $errors[] = $title . ' UPDATE失败';
        }
    }
    $upd_stmt->close();

    return array(
        'updated'      => $updated,
        'skipped'      => $skipped,
        'matched_list' => $matched_list,
        'errors'       => $errors,
    );
}

// ===== 主流程 =====

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
if (!in_array($bbjtype, array('hot', 'new', 'even', 'top'), true)) {
    $bbjtype = 'hot';
}
if ($bbjtype === 'even') {
    $bbjtype = 'top';
}

$num_max = isset($cfg['num_max']) ? (int)$cfg['num_max'] : 20;
$num = isset($_GET['num']) ? (int)$_GET['num'] : 10;
if ($num < 1) $num = 1;
if ($num > $num_max) $num = $num_max;

$level = isset($_GET['level']) ? (int)$_GET['level'] : 9;
if ($level < 1) $level = 1;
if ($level > 9) $level = 9;

$autofill = !(isset($_GET['autofill']) && $_GET['autofill'] === 'off');

// 兼容旧 URL 参数，内部不使用
$filtercondi = isset($_GET['filtercondi']) ? (string)$_GET['filtercondi'] : 'doubanid';

$orderby = isset($_GET['orderby']) && strtoupper($_GET['orderby']) === 'DESC' ? 'DESC' : 'ASC';
$order_sql = ($orderby === 'ASC') ? 'DESC' : 'ASC';

$cache_ttl = isset($cfg['cache_ttl']) ? (int)$cfg['cache_ttl'] : 3600;
$cache_dir = __DIR__ . '/cache/';
$table = isset($cfg['db_table']) ? $cfg['db_table'] : 'mac_vod';

$img_base = 'https://image.tmdb.org/t/p/' . (isset($cfg['backdrop_size']) ? $cfg['backdrop_size'] : 'w1280') . '/';

// 缓存查询
$cache_key = 'haibao_' . $bbjtype . '_' . $num . '_' . $level . '_' . ($autofill ? 'on' : 'off');
$movies_data = bbj_cache_get($cache_key, $cache_ttl, $cache_dir);
$from_cache = ($movies_data !== false && is_array($movies_data));

$is_degraded = false;
$degrade_msg = '';
$movies = array();

if ($from_cache) {
    $movies = $movies_data;
} else {
    switch ($bbjtype) {
        case 'hot':
            $result = fetch_hot_movies($num, $autofill, $conn, $table, $cfg, $img_base);
            break;
        case 'new':
            $result = fetch_new_movies($num, $autofill, $conn, $table, $cfg, $img_base);
            break;
        case 'top':
            $result = fetch_top_movies($num, $autofill, $conn, $table, $cfg, $img_base);
            break;
        default:
            $result = array('movies' => array(), 'is_degraded' => false, 'degrade_msg' => '');
    }

    $movies = $result['movies'];
    $is_degraded = $result['is_degraded'];
    $degrade_msg = $result['degrade_msg'];

    // 降级结果不缓存
    if (!$is_degraded && !empty($movies)) {
        bbj_cache_set($cache_key, $movies, $cache_dir);
    }
}

// 写库
$write_result = write_movies_to_db($movies, $level, $conn, $table);
$updated = $write_result['updated'];
$skipped = $write_result['skipped'];
$matched_list = $write_result['matched_list'];
$errors = $write_result['errors'];

// 输出
if ($updated === 0) {
    die('❌ 未匹配到任何本地影片，共尝试 ' . count($movies) . ' 部，全部跳过');
}

if ($degrade_msg !== '') {
    echo $degrade_msg . '<br>';
}

$cache_tag = $from_cache ? '，来自缓存' : '';
echo '✅ 海报已成功更新（' . $bbjtype . '，' . $level . '星，' . $updated . '/' . count($movies) . '张匹配' . $cache_tag . '）'
   . '<br>已更新影片：' . implode('、', $matched_list);
if ($skipped > 0) {
    echo '<br>⚠️ 跳过 ' . $skipped . ' 部（本地无匹配或无横图）';
}
if (!empty($errors)) {
    echo '<br>⚠️ 部分失败：' . htmlspecialchars(implode('；', $errors));
}

$conn->close();
