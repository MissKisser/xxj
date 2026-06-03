# XXJ - 苹果 CMS 海报轮播图管理插件

XXJ 由 [BBJ](https://baiduc.github.io/pub/bbj/plug/word.html) 二次开发而来。在 BBJ 原有功能基础上，剔除了所有已失效的远程依赖，将数据源切换至 TMDB 开放 API，实现完全本地化运行。

> **鸣谢 BBJ**：本项目的管理界面（layui 表单）、URL 参数规范和轮播图写入策略均沿用 BBJ 的设计。感谢 BBJ 团队为苹果 CMS 社区提供的开源插件。
>
> BBJ 原项目地址：[https://baiduc.github.io/pub/bbj/plug/word.html](https://baiduc.github.io/pub/bbj/plug/word.html)

## 与 BBJ 的区别

| 维度 | BBJ 原版 | XXJ 二次开发版 |
|------|----------|----------------|
| 数据源 | 远程服务 `bibij.icu` 生成 SQL（已失效） | TMDB v3 API 直连，获取热门/最新/高分影片 |
| 海报图源 | 远程服务返回图片 URL | TMDB CDN (`image.tmdb.org`)，支持 poster/backdrop 两种比例 |
| 代码执行 | `eval(远程 PHP)` | 纯本地 PHP，无 `eval`、无远程代码拉取 |
| 影片匹配 | `bibij.icu` 按 `filtercondi=doubanid` 生成 SQL | 本地按 `vod_name` 精确匹配，`filtercondi` 参数支持 `doubanid`（校验豆瓣 ID）和 `name`（纯标题匹配）两种模式 |
| 重复片名 | 由远程服务处理 | `orderby` 参数控制同名多部时取最近/最早记录 |
| 推荐等级 | 硬编码 level=9 | `level` 参数动态可配（1-9） |
| 管理界面 | 远程拉取 `index.html` | 本地化 `index.html`，不再做远程同步 |
| 缓存 | 1 小时文件缓存 | 保留，缓存结构优化为数组存储（含影片标题） |
| 安全 | 远程 SQL 直接执行（无预编译） | 全部 `mysqli::prepare` + `bind_param` 预编译 |
| 评论/弹幕 | 依赖 `dm.bbj.icu` / `bbj.icu` | 已禁用，入口保留防止 404 |

### 引入的新组件

- **TMDB v3 API**：作为唯一外部数据源，获取影片列表和海报图 URL
- **本地配置文件** `haibao_config.php`：TMDB API Key、图片尺寸、缓存 TTL 等参数集中管理
- **配置文件保护**：`BBJ_INCLUDED` 常量守卫 + 403 响应，防止凭证被直接访问
- **缓存清理工具** `clear.php`：一键清空缓存目录

### 剔除的依赖

- `bibij.icu`（SQL 生成服务，已失效）
- `bbj.icu`（评论/弹幕服务，域名已停放）
- `baiduc.github.io/check.txt`（远程文件同步协议）
- 所有 `eval()` 调用
- 远程 PHP/HTML 模板拉取

## 功能

- 三种更新策略：最热影片 / 最新上映 / 高分均衡
- 两种匹配模式：豆瓣 ID 校验 / 纯影片名称
- 16:9 横幅图（backdrop）适配轮播图区域
- 1 小时文件缓存，减少 TMDB API 调用
- 完全本地化，无任何外部代码执行依赖
- URL 参数与原 BBJ 插件完全兼容

## 文件说明

```
xxj/
├── haibao.php          # 海报更新入口（核心业务）
├── haibao_config.php   # TMDB 凭证与运行参数配置（需自行创建）
├── haibao_config.example.php  # 配置文件模板
├── index.php           # 管理界面入口
├── index.html          # 管理界面（layui 表单）
├── pinglun.php         # 评论入口（已禁用）
├── clear.php           # 缓存清理工具
├── 使用教程.txt         # 安装使用说明
├── cache/              # 文件缓存目录（运行时自动创建）
└── .gitignore          # 忽略凭证与缓存
```

## 配置

### 1. 申请 TMDB API Key

前往 [https://www.themoviedb.org/settings/api](https://www.themoviedb.org/settings/api) 注册并申请 API Key（v3 auth）。

### 2. 编辑 `haibao_config.php`

```php
<?php
if (!defined('BBJ_INCLUDED')) {
    http_response_code(403);
    die('Forbidden');
}

return [
    'tmdb_v3_key'    => '你的TMDB_API_KEY',  // 必填
    'tmdb_lang'      => 'zh-CN',             // 接口语言
    'tmdb_region'    => 'HK',                // 地区
    'image_field'    => 'backdrop',          // poster(2:3竖图) 或 backdrop(16:9横图)
    'image_size'     => 'w500',              // poster 尺寸：w185/w342/w500/original
    'backdrop_size'  => 'w1280',             // backdrop 尺寸：w300/w780/w1280/original
    'num_max'        => 20,                  // 轮播图最大张数
    'cache_ttl'      => 3600,                // 缓存秒数（默认1小时）
    'db_table'       => 'mac_vod',           // 数据库表名
    'slide_sep'      => '|',                 // 轮播图字段分隔符（兼容保留）
];
```

### 3. 安装到苹果 CMS

将 `xxj/` 目录上传至站点根目录：

```
/www/wwwroot/your-site/
└── xxj/
    ├── haibao.php
    ├── haibao_config.php
    ├── index.php
    ├── index.html
    ├── pinglun.php
    ├── clear.php
    └── 使用教程.txt
```

在苹果 CMS 后台添加自定义菜单：

```
XXJ管理,/xxj/index.php
```

## 使用

### 管理界面

访问 `https://你的域名/xxj/index.php`，在表单中选择参数后点击「获取代码」，复制生成的链接。

### URL 参数

| 参数 | 取值 | 默认 | 说明 |
|------|------|------|------|
| `bbjtype` | `hot` / `new` / `even` | `hot` | hot=最热，new=最新上映，even=高分影片 |
| `filtercondi` | `doubanid` / `name` | `doubanid` | doubanid=按豆瓣ID校验匹配，name=纯影片名称匹配 |
| `orderby` | `ASC` / `DESC` | `ASC` | 同名多部时的选取顺序。ASC=取最近一次，DESC=取第一次 |
| `num` | 1-20 整数 | 10 | 轮播图张数 |
| `level` | 1-9 整数 | 9 | 推荐等级（需与模板对应） |
| `cmsname` | 任意字符串 | - | 兼容原插件参数，内部不使用 |
| `codetype` | 任意字符串 | - | 兼容原插件参数，内部不使用 |

### 示例链接

```
# 默认配置：热门影片，豆瓣ID匹配，10张，推荐等级9
https://你的域名/xxj/haibao.php?bbjtype=hot&num=10&level=9&filtercondi=doubanid&orderby=ASC&cmsname=maccms10&codetype=php

# 最新上映，影片名称匹配，6张
https://你的域名/xxj/haibao.php?bbjtype=new&num=6&level=9&filtercondi=name&orderby=ASC&cmsname=maccms10&codetype=php

# 高分均衡，8张，推荐等级5
https://你的域名/xxj/haibao.php?bbjtype=even&num=8&level=5&filtercondi=doubanid&orderby=ASC&cmsname=maccms10&codetype=php
```

### 定时自动更新

将链接添加到服务器 cron 任务：

```bash
# 每天凌晨 3 点自动更新
0 3 * * * curl -s "https://你的域名/xxj/haibao.php?bbjtype=hot&num=10&level=9&filtercondi=doubanid&orderby=ASC&cmsname=maccms10&codetype=php" > /dev/null 2>&1
```

### 清除缓存

访问 `https://你的域名/xxj/clear.php` 清空缓存目录，下次访问将重新从 TMDB 拉取数据。

## 匹配机制

插件通过 TMDB API 获取影片列表（含中文标题），然后在本地数据库 `mac_vod` 表中按 `vod_name` 精确匹配：

- **豆瓣 ID 模式**（`filtercondi=doubanid`）：匹配 `vod_name` 且 `vod_douban_id > 0`，确保目标为真实影片
- **影片名称模式**（`filtercondi=name`）：仅按 `vod_name` 匹配，不校验豆瓣 ID，覆盖范围更广
- **重复片名处理**：同名多部时按 `orderby` 参数决定取最近还是最早添加的记录

匹配成功后，UPDATE 该影片的 `vod_pic_slide`（海报图 URL）和 `vod_level`（推荐等级）。模板标签 `{maccms:vod level="9"}` 自动读取这些数据展示轮播图。

## 模板适配

默认主题轮播图模板标签：

```html
{maccms:vod level="9" num="6" by="time" order="desc"}
<a class="swiper-slide" href="{$vo|mac_url_vod_detail}"
   data-background="{$vo.vod_pic_slide|mac_url_img}"
   title="{$vo.vod_name}">
{/maccms}
```

`level` 值需与插件配置中的 `level` 参数一致。如模板使用 `level="9"`，插件默认 `level=9` 即可。

## 注意事项

- TMDB API 限制 50 请求/秒，1 小时缓存已足够覆盖正常使用
- 首次使用需清除苹果 CMS 后台缓存后才能在首页看到更新
- `haibao_config.php` 包含 API 密钥，已在 `.gitignore` 中排除，请勿提交到公开仓库
- 评论功能已禁用，`pinglun.php` 保留文件仅防止引用报 404
