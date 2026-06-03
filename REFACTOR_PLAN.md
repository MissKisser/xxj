# haibao.php 改造落地方案

## 背景与问题

当前 `haibao.php` 三种模式（`hot`/`new`/`even`）全部从 TMDB 获取影片列表，再用影片名到本地 `mac_vod` 精确匹配。存在的问题：

1. **hot 和 new 数据源重叠**：`movie/popular` 和 `movie/now_playing` 返回的影片高度重合
2. **匹配率低**：TMDB 返回的是全球热门影片，本地库多为中文片名，精确匹配成功率低
3. **无降级**：TMDB 不可用时直接 die()，不写半截数据

## 改造目标

| 维度 | 改造前 | 改造后 |
|------|--------|--------|
| hot 数据源 | TMDB `movie/popular` | 猫眼热映 → 本地匹配 → TMDB 搜图 |
| new 数据源 | TMDB `movie/now_playing` | 本地 `mac_vod ORDER BY vod_time DESC` |
| even→top 数据源 | TMDB `movie/top_rated` 多页轮询 | TMDB `movie/top_rated` → 本地匹配 |
| 降级策略 | 无 | 外部 API 不可用时回退到本地数据库排序 |
| auto 模式 | 不存在 | 不新增（用户在宝塔定时任务自行选择模式即可） |

## 三种模式详细设计

### 模式 1：hot（最热）

**主链路**：猫眼热映列表 → 本地 `mac_vod` 匹配 → TMDB 搜索取 `backdrop_path`

```
┌─────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  猫眼 API    │────→│  本地 mac_vod     │────→│  TMDB search    │
│  热映列表    │     │  vod_name 匹配    │     │  backdrop_path  │
└─────────────┘     └──────────────────┘     └─────────────────┘
```

**步骤**：

1. `GET https://m.maoyan.com/ajax/movieOnInfoList`，Header 带 `User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)`
2. 解析 `movieList[].nm`（影片名），逐条在 `mac_vod` 中 `WHERE vod_name = ?` 精确匹配
3. 匹配成功 → 用影片名调 `GET https://api.themoviedb.org/3/search/movie?api_key=KEY&language=zh-CN&query=影片名`
4. 取 `results[0].backdrop_path`，拼成完整 URL
5. `UPDATE mac_vod SET vod_pic_slide=url, vod_level=level WHERE vod_id=?`
6. 未匹配 → 跳过；`autofill=on` 时继续取下一条直到凑够 `num` 或列表耗尽

**降级**：猫眼不可用（超时/HTTP 非 200/JSON 解析失败）→ `SELECT vod_id, vod_name FROM mac_vod WHERE vod_hits > 0 ORDER BY vod_hits DESC LIMIT num`，直接用本地热度排序，匹配率 100%，再用 TMDB 搜索取图。

**猫眼 API 参考**：

```
GET https://m.maoyan.com/ajax/movieOnInfoList
Header: User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 16_0 like Mac OS X)
返回: { movieList: [{ id, nm, sc, img, rt, ... }], ... }
```

### 模式 2：new（最新）

**主链路**：本地 `mac_vod` 按时间倒序 → TMDB 搜索取图

```
┌──────────────────┐     ┌─────────────────┐
│  本地 mac_vod     │────→│  TMDB search    │
│  ORDER BY vod_time│     │  backdrop_path  │
└──────────────────┘     └─────────────────┘
```

**步骤**：

1. `SELECT vod_id, vod_name FROM mac_vod ORDER BY vod_time DESC LIMIT num * 2`（取 2 倍量，为跳过无 backdrop 的影片留余量）
2. 遍历结果，用 `vod_name` 调 TMDB `/search/movie` 获取 `backdrop_path`
3. 找到 backdrop → `UPDATE vod_pic_slide + vod_level`
4. 未找到 → 跳过，继续取下一条
5. 凑够 `num` 条或遍历完停止

**降级**：此模式不依赖猫眼。TMDB 搜索不可用时 → 直接 `UPDATE vod_level` 但不更新 `vod_pic_slide`（保持原图），输出警告信息。

### 模式 3：top（最高分，替代原 even）

**主链路**：TMDB 高分影片 → 本地匹配 → 直接用 TMDB backdrop

```
┌─────────────────┐     ┌──────────────────┐
│  TMDB top_rated  │────→│  本地 mac_vod     │
│  backdrop_path   │     │  vod_name 匹配    │
└─────────────────┘     └──────────────────┘
```

**步骤**：

1. `GET https://api.themoviedb.org/3/movie/top_rated?api_key=KEY&language=zh-CN&page=1..N`
2. 遍历 `results[].title`，在本地 `mac_vod` 按 `vod_name` 匹配
3. 匹配到 → 直接用 TMDB 返回的 `backdrop_path`（无需二次搜索）
4. 未匹配 → 跳过，`autofill=on` 时翻页继续
5. `UPDATE mac_vod SET vod_pic_slide=url, vod_level=level`

**降级**：TMDB 不可用 → `SELECT vod_id, vod_name FROM mac_vod WHERE vod_score > 0 ORDER BY vod_score DESC LIMIT num`，按本地评分排序。不更新 `vod_pic_slide`，输出警告。

**even → top 兼容**：`bbjtype=even` 自动映射为 `top`，内部逻辑一致。

## 参数变更

### 新增参数

| 参数 | 值 | 默认 | 说明 |
|------|---|------|------|
| `autofill` | `on` / `off` | `on` | 匹配不足时自动翻页补齐 |

### 参数变更

| 参数 | 改造前 | 改造后 |
|------|--------|--------|
| `bbjtype` | `hot`/`new`/`even` | `hot`/`new`/`top`（`even` 自动映射为 `top`） |
| `filtercondi` | `doubanid`/`name`，影响匹配逻辑 | 保留参数，内部忽略（兼容旧 URL） |

### 保留参数

| 参数 | 说明 |
|------|------|
| `num` | 轮播图张数（1-20，默认 10） |
| `level` | 推荐等级（1-9，默认 9） |
| `orderby` | 同名多部选取顺序（ASC/DESC） |
| `codetype` | 兼容原插件（内部忽略） |
| `cmsname` | 兼容原插件（内部忽略） |

## 配置文件新增项

`haibao_config.php` 在现有基础上新增：

```php
'maoyan_enabled'   => true,     // 是否启用猫眼数据源（hot 模式）
'maoyan_timeout'   => 8,        // 猫眼 API 超时秒数
'tmdb_search_timeout' => 5,     // TMDB 搜索 API 超时秒数（用于逐条搜图）
```

完整配置模板更新到 `haibao_config.example.php`。

## 缓存策略

缓存 key 必须包含 `autofill` 参数，避免 on/off 命中同一份缓存：

```
haibao_{bbjtype}_{num}_{level}_{autofill}
```

缓存 TTL 保持 3600 秒不变。

降级路径的结果**不缓存**（降级是临时状态，下次请求应重试主链路）。

## 核心伪代码

### haibao.php 主流程

```php
// 1. 参数解析
$bbjtype = in_array($_GET['bbjtype'], ['hot','new','even','top']) ? $_GET['bbjtype'] : 'hot';
if ($bbjtype === 'even') $bbjtype = 'top';
$autofill = isset($_GET['autofill']) && $_GET['autofill'] === 'off' ? false : true;

// 2. 缓存查询
$cache_key = "haibao_{$bbjtype}_{$num}_{$level}_" . ($autofill ? 'on' : 'off');
$cached = bbj_cache_get($cache_key, $cache_ttl, $cache_dir);
if ($cached !== false) {
    $movies = $cached;
    $from_cache = true;
} else {
    // 3. 按模式获取影片列表
    switch ($bbjtype) {
        case 'hot':  $movies = fetch_hot_movies($num, $autofill);  break;
        case 'new':  $movies = fetch_new_movies($num, $autofill);  break;
        case 'top':  $movies = fetch_top_movies($num, $autofill);  break;
    }
    // 4. 缓存（降级结果不缓存，下次重试主链路）
    if (!$is_degraded) {
        bbj_cache_set($cache_key, $movies, $cache_dir);
    }
}

// 5. 写库
write_to_db($movies, $level);

// 6. 输出结果
```

### fetch_hot_movies()

```php
function fetch_hot_movies($num, $autofill) {
    $names = fetch_maoyan_hot();              // 猫眼热映列表
    if ($names === false) {
        // 降级：本地热度排序，仍尝试 TMDB 搜图
        return fetch_local_hot($num);
    }
    return match_and_search_tmdb($names, $num, $autofill);
}

function fetch_local_hot($num) {
    // 降级数据源：本地热度排序，匹配率 100%
    $rows = db_query("SELECT vod_id, vod_name FROM mac_vod WHERE vod_hits > 0 ORDER BY vod_hits DESC LIMIT ?", [$num]);
    // 仍尝试 TMDB 搜图；若 TMDB 也挂则不更新 vod_pic_slide
    return search_tmdb_backdrop($rows, $num, false);
}
```

### fetch_new_movies()

```php
function fetch_new_movies($num, $autofill) {
    $limit = $autofill ? $num * 2 : $num;
    $rows = db_query("SELECT vod_id, vod_name FROM mac_vod ORDER BY vod_time DESC LIMIT ?", [$limit]);
    return search_tmdb_backdrop($rows, $num, $autofill);
}
```

### fetch_top_movies()

```php
function fetch_top_movies($num, $autofill) {
    $tmdb_list = fetch_tmdb_top_rated($num, $autofill);
    if ($tmdb_list === false) {
        // 降级：本地评分排序
        return fetch_local_top($num);
    }
    return match_tmdb_to_local($tmdb_list, $num, $autofill);
}

function fetch_local_top($num) {
    // 降级数据源：本地评分排序，不更新 vod_pic_slide
    $rows = db_query("SELECT vod_id, vod_name FROM mac_vod WHERE vod_score > 0 ORDER BY vod_score DESC LIMIT ?", [$num]);
    // 降级时保持原图，仅返回 vod_id 和 vod_name（url 留空）
    return array_map(function($r) { return ['vod_id' => $r['vod_id'], 'title' => $r['vod_name'], 'url' => '']; }, $rows);
}
```

### match_and_search_tmdb()（hot 模式核心）

```php
function match_and_search_tmdb($names, $num, $autofill) {
    $results = [];
    foreach ($names as $name) {
        if (count($results) >= $num) break;
        // 1. 本地匹配
        $row = db_query("SELECT vod_id FROM mac_vod WHERE vod_name = ? LIMIT 1", [$name]);
        if (!$row) continue;  // 跳过未匹配
        // 2. TMDB 搜图
        $backdrop = tmdb_search_backdrop($name);
        if (!$backdrop) continue;  // 跳过无图
        $results[] = ['vod_id' => $row['vod_id'], 'url' => $backdrop, 'title' => $name];
    }
    return $results;
}
```

## 前端 index.html 改动

1. **更新方式选项**：`hot`（最热）/ `new`（最新）/ `top`（最高分）
2. **移除**："更新条件"区域（`filtercondi` 的 doubanid/name 单选按钮）
3. **新增**："匹配不足自动补齐"开关（默认开启）
4. **URL 兼容**：`filtercondi` / `codetype` / `cmsname` 仍接受但忽略
5. **更新代码**区域简化（只保留 PHP 选项，移除 shell/sql 选项）

具体改动点：

```html
<!-- 更新方式：替换 even 为 top -->
<input type="radio" name="bbjtype" value="hot" checked title="最热海报">
<input type="radio" name="bbjtype" value="new" title="最新海报">
<input type="radio" name="bbjtype" value="top" title="最高分海报">

<!-- 更新条件：替换为自动补齐开关 -->
<input type="checkbox" name="autofill" value="on" lay-skin="switch" 
       lay-text="开启|关闭" checked title="匹配不足自动补齐">

<!-- 移除更新条件中的 doubanid/name 单选 -->
<!-- 移除更新代码中的 shell/sql 选项 -->
```

## 降级策略汇总

| 模式 | 外部依赖 | 降级条件 | 降级行为 | 图片处理 |
|------|---------|---------|---------|---------|
| hot | 猫眼 API + TMDB 搜索 | 猫眼超时/非 200 | `mac_vod ORDER BY vod_hits DESC` | TMDB 搜索取图（若 TMDB 也挂则不更新 vod_pic_slide） |
| new | 仅 TMDB 搜索 | TMDB 超时/非 200 | 不更新 `vod_pic_slide`，仅 `UPDATE vod_level` | 保持原图 |
| top | TMDB top_rated | TMDB 超时/非 200 | `mac_vod ORDER BY vod_score DESC` | 保持原图 |

降级时输出明确提示：`⚠️ 外部数据源不可用，已使用本地数据降级更新`

降级结果不写入缓存（下次请求重试主链路）。

## TMDB 速率控制

hot 和 new 模式逐条调 TMDB `/search/movie`，需要控制并发：

- 每次请求间隔 ≥ 250ms（TMDB 限制 40 次/10 秒）
- 单次请求超时 5 秒
- 最大翻页数 5 页（top 模式最多取 100 部影片）

## 写库逻辑（保持现有行为不变）

1. `DELETE FROM mac_vod WHERE vod_name LIKE 'BBJ轮播图-%'`（清理旧占位行）
2. `UPDATE mac_vod SET vod_level = 0 WHERE vod_level = {$level}`（重置同 level）
3. 逐条预编译 `UPDATE mac_vod SET vod_pic_slide = ?, vod_level = ? WHERE vod_id = ?`
4. `orderby` 参数控制同名影片选取顺序（ASC→最近一次，DESC→第一次）

## 错误输出规范

| 触发条件 | 输出 |
|----------|------|
| 猫眼 API 失败 + 本地降级成功 | `⚠️ 猫眼数据源不可用，已使用本地热度降级更新` |
| TMDB 搜索失败（部分） | `⚠️ 部分影片未找到横图，已跳过` |
| TMDB 搜索失败（全部） | `❌ TMDB 搜索服务不可用，无法获取海报图` |
| 匹配数为 0 | `❌ 未匹配到任何本地影片，共尝试 N 部，全部跳过` |
| 降级后匹配数 < num | `⚠️ 仅匹配到 N/M 张，已补齐可用数量` |

## 实施步骤

### 步骤 1：备份现有文件

```bash
cp haibao.php haibao.php.bak.v2
cp index.html index.html.bak
cp haibao_config.example.php haibao_config.example.php.bak
```

### 步骤 2：更新 haibao_config.example.php

新增 `maoyan_enabled`、`maoyan_timeout`、`tmdb_search_timeout` 三个配置项。

### 步骤 3：改造 haibao.php

按上述设计重写，保留现有的：
- `bbj_cache_set` / `bbj_cache_get` 缓存函数
- 参数钳制逻辑
- 预编译写库逻辑
- 输出格式

新增：
- `fetch_maoyan_hot()` 函数
- `fetch_local_hot()` 降级函数
- `fetch_local_top()` 降级函数
- `tmdb_search_backdrop()` TMDB 搜图函数
- `match_and_search_tmdb()` 匹配+搜图函数
- TMDB 速率控制（250ms 间隔）
- `autofill` 参数支持
- `even → top` 映射

### 步骤 4：改造 index.html

- 替换更新方式选项
- 移除更新条件区域
- 新增自动补齐开关
- 简化更新代码区域

### 步骤 5：本地验证

```bash
# PHP 语法检查
php -l haibao.php
php -l haibao_config.example.php

# 无 eval 检查
grep -n "eval(" haibao.php

# 无远程域检查
grep -n "bibij.icu\|bbj.icu" haibao.php
```

### 步骤 6：部署到服务器

将改动文件同步到 `mv.viaxv.top/bbj/`，更新 `haibao_config.php` 新增配置项。

### 步骤 7：服务器端验收

```bash
# hot 模式（主链路）
curl -s "https://mv.viaxv.top/bbj/haibao.php?bbjtype=hot&num=5&level=9"

# new 模式
curl -s "https://mv.viaxv.top/bbj/haibao.php?bbjtype=new&num=5&level=9"

# top 模式
curl -s "https://mv.viaxv.top/bbj/haibao.php?bbjtype=top&num=5&level=9"

# even 兼容（应映射为 top）
curl -s "https://mv.viaxv.top/bbj/haibao.php?bbjtype=even&num=3&level=9"

# autofill=off
curl -s "https://mv.viaxv.top/bbj/haibao.php?bbjtype=hot&num=5&level=9&autofill=off"

# 缓存命中
curl -s "https://mv.viaxv.top/bbj/haibao.php?bbjtype=hot&num=5&level=9" | grep "缓存"

# 管理界面
curl -sI "https://mv.viaxv.top/bbj/index.php" | head -1
```
