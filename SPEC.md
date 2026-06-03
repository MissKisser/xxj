# BBJ 海报管理插件 · 重构规格说明

| 项目 | 内容 |
|------|------|
| 版本 | v2.0（本地化重构） |
| 范围 | 海报轮播图自动更新 |
| 外部依赖 | 仅 TMDB v3 API |
| 部署目标 | 香港服务器 `mv.viaxv.top/bbj/` |
| 文档定位 | 编码前的功能契约，所有实现以本文件为准 |

## 1. 重构目标

**剔除所有外部代码与数据源依赖，保留与原 BBJ 插件一致的业务行为。**

| 维度 | 原插件 | 重构后 |
|------|--------|--------|
| 管理界面 | 远程 `baiduc.github.io/index.html` | 本地 `index.html`（一次性落地） |
| 业务执行 | `eval(远程 PHP)` 调用 `bibij.icu/BBJ-code` | 本地 PHP 直调 `api.themoviedb.org` |
| 海报图源 | 远程服务返回的 SQL 字符串 | TMDB `/movie/popular` 等接口 + 本地拼 SQL |
| 评论功能 | `eval(pinglun.html)` 调 `dm.bbj.icu` | 保留文件、入口 `die()` 禁用 |
| 远程文件同步 | `check.txt` foreach 拉取 | 彻底移除 |
| 缓存 | 1 小时文件缓存 | 1 小时文件缓存（保留） |
| URL 兼容性 | `?bbjtype=hot&num=10&level=9...` | 完全兼容 |

## 2. 核心原则

1. **零远程代码执行**：不出现 `eval`、不拉远程 PHP 模板
2. **零远程数据源依赖**：除 TMDB API 外不调任何第三方
3. **零凭证泄露**：`haibao_config.php` 必须经 `BBJ_INCLUDED` 常量守卫
4. **零破坏性变更**：URL 参数、输出格式、数据库字段、轮播图写入行为与原插件一致
5. **可降级**：TMDB API 不可用时返回明确错误，不写半截数据

## 3. 文件清单

| 路径 | 类型 | 状态 | 职责 |
|------|------|------|------|
| `haibao.php` | PHP | **重写** | 海报更新入口 |
| `haibao_config.php` | PHP | 新增 | TMDB 凭证 + 运行参数 |
| `index.php` | PHP | **重写** | 简化为读本地 `index.html` 并 echo |
| `index.html` | HTML | 新增 | 从 `baiduc.github.io` 一次性 curl 落地 |
| `pinglun.php` | PHP | **改写** | 保留文件，入口 `die()` 提示"评论功能已禁用" |
| `pinglun.html` | PHP | 删除 | 原 `eval` 目标，删除 |
| `clear.php` | PHP | 新增（最简版） | 本地清空 `cache/` 目录，无任何远程调用 |
| `cache/` | 目录 | 保留 | 1 小时文件缓存目录 |
| `使用教程.txt` | TXT | 保留 | 不动 |
| `.gitignore` | 文本 | 新增 | 忽略凭证、缓存、远程同步残留 |
| `CLAUDE.md` | Markdown | 保留 | 项目说明 |
| `SPEC.md` | Markdown | 本文档 | 重构契约 |

## 4. 接口契约

### 4.1 `haibao.php`（核心业务）

**URL**：`GET /bbj/haibao.php`

**Query 参数**（与原插件一致，新实现忽略 `filtercondi` / `codetype`）：

| 参数 | 取值 | 默认 | 用途 |
|------|------|------|------|
| `bbjtype` | `hot` / `new` / `even` | `hot` | 拉取策略：popular / now_playing / top_rated 多页轮询 |
| `num` | 1-20 整数 | 10 | 轮播图张数 |
| `level` | 1-9 整数 | 9 | mac_vod 推荐等级筛选 |
| `filtercondi` | 任意字符串 | `doubanid` | **保留参数，内部忽略**（兼容原 URL） |
| `orderby` | `ASC` / `DESC` | `DESC` | 目标行选取顺序 |
| `codetype` | 任意字符串 | `php` | **保留参数，内部忽略** |

**响应**（HTML 片段，纯文本风格与原插件一致）：

成功时：
```
成功执行命令：1条
✅ 海报已成功更新（hot，9星，10张）
预览：https://image.tmdb.org/t/p/w500/xxx.jpg|https://image.tmdb.org/t/p/w500/yyy.jpg|...
```

缓存命中时（不调 TMDB）：
```
✅ 海报已成功更新（hot，9星，10张，来自缓存）
预览：...
```

失败时（HTTP 200 + 错误文本，不抛 5xx 便于排查）：
```
❌ TMDB API 错误 401：Invalid API key
```

**行为细节**：

1. 连接 `../application/database.php` 读取 MySQL 凭证
2. `getCache('haibao_local')` 命中且未过期 → 直接写库 + 输出
3. 未命中 → curl TMDB → 解析 `results[].poster_path` → 拼成 `url1|url2|...` → `setCache` → 写库
4. 写库 SQL（DELETE 旧占位行 + INSERT N 行新占位行）：

   ```sql
   -- 第一步：清旧（用 vod_name LIKE 'BBJ轮播图-%' 标识）
   DELETE FROM mac_vod WHERE vod_name LIKE 'BBJ轮播图-%';

   -- 第二步：每张海报图 INSERT 一行占位记录
   INSERT INTO mac_vod (
       vod_name, vod_pic, vod_pic_slide, vod_level, vod_time, vod_hits,
       vod_content, vod_play_url, vod_down_url, vod_plot_name, vod_plot_detail
   ) VALUES (?, ?, ?, 9, ?, 0, '', '', '', '', '');
   ```

   **设计依据**：default 主题模板 `{maccms:vod level="9" num="6" by="time" order="desc"}` 查 level=9 的最新 6 条，每条 `vod_pic_slide` 字段为单张图 URL。INSERT N 行后，模板自动按 `vod_time` 倒序取前 6 张展示为 swiper-slide。
5. TMDB 调用映射：

| `bbjtype` | TMDB 端点 | 说明 |
|-----------|-----------|------|
| `hot` | `GET /movie/popular` | 当下最热 |
| `new` | `GET /movie/now_playing` | 当前上映 |
| `even` | `GET /movie/top_rated` + 多页轮询 | 高分均摊 |

6. 错误码映射：

| 触发条件 | 输出 |
|----------|------|
| `haibao_config.php` 缺失 | `❌ 配置文件缺失，请检查 haibao_config.php` |
| `../application/database.php` 缺失 | `❌ 无法找到数据库配置文件` |
| MySQL 连接失败 | `❌ 连接数据库失败: ...` |
| TMDB HTTP 非 200 | `❌ TMDB API 错误 {code}：{body}` |
| TMDB 返回 `results` 为空 | `❌ TMDB 未返回结果，请稍后重试` |
| 目标行不存在（库内无匹配 level 影片） | `❌ 数据库内没有推荐等级 = {level} 的影片` |
| `num` 越界 | 钳制到 [1, 20] |

### 4.2 `index.php`（管理界面壳）

**URL**：`GET /bbj/index.php`

**行为**：
1. 检查本地 `index.html` 是否存在
2. 存在 → `readfile()` 输出
3. 不存在 → 输出引导 HTML："请从 https://baiduc.github.io/pub/bbj/plug/index.html 手动下载到本目录"
4. **不做**任何远程拉取、**不做**任何 `check.txt` 同步

### 4.3 `pinglun.php`（保留禁用）

**URL**：`GET /bbj/pinglun.php`

**行为**：
1. 文件保留（防止其他系统引用 404）
2. 入口立即 `die('评论功能已禁用')`，无任何外部调用

### 4.4 `clear.php`（本地缓存清理）

**URL**：`GET /bbj/clear.php`

**行为**：
1. 校验请求来源（可选，限制为同源 Referer）
2. 删除 `./cache/` 目录下所有文件
3. 输出"缓存已清理"
4. **不**做远程调用、**不**接收外部参数

## 5. 数据流

```
浏览器 GET /bbj/haibao.php?bbjtype=hot&num=10&level=9
  ↓
haibao.php
  ├── include haibao_config.php        ← 加载 TMDB key
  ├── include ../application/database.php ← 加载 MySQL 凭证
  ├── getCache('haibao_local')          ← 命中?
  │     ├── 命中 → 跳过 TMDB
  │     └── 未命中 → curl api.themoviedb.org/3/movie/popular
  │                       ↓
  │                  json_decode → results[0..num-1].poster_path
  │                       ↓
  │                  拼成 "https://image.tmdb.org/t/p/w500/xxx.jpg|..."
  │                       ↓
  │                  setCache('haibao_local', $slide)
  ├── UPDATE mac_vod SET vod_pic_slide = ? WHERE ... ← 单条预编译
  └── echo 结果
```

## 6. 安全设计

| 风险点 | 防护 |
|--------|------|
| `haibao_config.php` 被直接访问泄露 key | `BBJ_INCLUDED` 常量守卫 + 403 |
| TMDB key 提交到 git | `.gitignore` 忽略 `haibao_config.php` |
| `check.txt` 远程 RCE 入口 | `index.php` 不再同步任何远程文件 |
| SQL 注入 | 全部用 `mysqli::prepare` + `bind_param` 预编译，无字符串拼接 |
| CSRF | POST 拒绝（接口只接受 GET），Referer 校验在 `clear.php` |
| 缓存被攻击者投毒 | key 用固定字符串 `haibao_local`，路径用 `md5()` 命名 |
| 远程 HTML 内嵌恶意 JS | `index.html` 一次性本地化后不再更新，攻击面止于落盘那一刻 |
| TMDB key 限流 | 1 小时文件缓存兜底，正常使用远低于 50 req/s 限制 |

## 7. 配置项

`haibao_config.php`：

| 字段 | 类型 | 默认 | 说明 |
|------|------|------|------|
| `tmdb_v3_key` | string | 必填 | TMDB v3 API key |
| `tmdb_lang` | string | `zh-CN` | 接口 `language` 参数 |
| `tmdb_region` | string | `HK` | 接口 `region` 参数 |
| `image_size` | string | `w500` | TMDB 海报尺寸档位（w185/w342/w500/original），仅在 `image_field=poster` 时使用 |
| `image_field` | string | `backdrop` | 拉取字段：`poster`（2:3 竖图）或 `backdrop`（16:9 横图） |
| `backdrop_size` | string | `w1280` | TMDB 横图尺寸档位（w300/w780/w1280/original），仅在 `image_field=backdrop` 时使用 |
| `cache_ttl` | int | 3600 | 缓存秒数 |
| `db_table` | string | `mac_vod` | 数据库表名 |
| `slide_sep` | string | `\|` | 轮播图字段分隔符 |

## 8. 部署步骤

按顺序执行：

1. **本地**：`D:\Download\browser\bbj\` 完成所有文件
2. **本地**：`curl -o D:\Download\browser\bbj\index.html https://baiduc.github.io/pub/bbj/plug/index.html`
3. **本地验证**：人工阅读 `index.html` 确认无异常（这一步是信任链的最后一关）
4. **传输**到服务器（scp / git push）：

   ```
   /www/wwwroot/mv.viaxv.top/bbj/
   ├── haibao.php
   ├── haibao_config.php
   ├── index.php
   ├── index.html
   ├── pinglun.php
   ├── clear.php
   ├── 使用教程.txt
   └── .gitignore
   ```

5. **服务器首次验证**（用 curl 不走浏览器，看 HTTP 头）：
   ```bash
   curl -sI https://mv.viaxv.top/bbj/haibao_config.php
   # 期望：HTTP/1.1 403 Forbidden

   curl -s "https://mv.viaxv.top/bbj/haibao.php?bbjtype=hot&num=5&level=9"
   # 期望：成功消息 + 预览 URL
   ```

6. **清理旧文件**（如有旧版残留）：
   - 删除 `haibao.html` `pinglun.html` `cache/`（`cache/` 删了让新缓存从零开始）
   - 保留 `使用教程.txt`（用户文档）

## 9. 验收标准

每条都要逐项验证：

- [ ] 浏览器访问 `https://mv.viaxv.top/bbj/index.php` 看到管理界面（layui 标签、海报图 / 弹幕库 / 评论库 tab）
- [ ] 提交表单后跳到 `haibao.php`，URL 带原插件兼容的 GET 参数
- [ ] `haibao.php` 成功执行后，浏览器输出"✅ 海报已成功更新"
- [ ] 再次访问同一 URL，输出包含"来自缓存"
- [ ] `mac_vod` 表中匹配 level 的某行 `vod_pic_slide` 字段被更新为 `https://image.tmdb.org/t/p/w500/...|...` 格式
- [ ] `num` 传 20 时被自动钳制到 15（不报错）
- [ ] 访问 `https://mv.viaxv.top/bbj/haibao_config.php` 返回 403
- [ ] 访问 `https://mv.viaxv.top/bbj/pinglun.php` 返回"评论功能已禁用"
- [ ] `clear.php` 能清空 `cache/` 目录
- [ ] 项目内**无任何 `eval`**：grep `eval` 在所有 PHP 文件中应无匹配
- [ ] 项目内**无任何 `bibij.icu` / `bbj.icu` / `baiduc.github.io` 远程引用**（除静态注释和 `index.html` 内已本地化的外链）

## 10. 不做事项（Out of Scope）

- ❌ 评论功能实现（保留文件 + 禁用入口）
- ❌ 弹幕功能（`dm.bbj.icu` 已死，本项目不涉及）
- ❌ 苹果 CMS 模板适配（轮播图字段写入即视为完成，前端展示由模板负责）
- ❌ 用户鉴权（沿用原插件"放在管理后台菜单中"的隐式保护）
- ❌ `clear.php` 的图形化界面（提供最简 GET 入口即可）
- ❌ 国际化（沿用 `zh-CN`）
- ❌ 海报图本地缓存（TMDB CDN 已足够稳定，重复缓存价值低）

## 11. 风险与回退

| 风险 | 概率 | 兜底 |
|------|------|------|
| TMDB v3 key 被滥用撤销 | 低 | v4 JWT 可作切换；新 key 在 TMDB 控制台即时生成 |
| 苹果 CMS 表前缀不是 `mac_` | 中 | `db_table` 字段可改，spec 默认 `mac_vod` |
| `vod_pic_slide` 字段长度不够 | 低 | TMDB URL 平均 60 字符 × 20 张 ≈ 1200 字符，远低于 TEXT 类型 65535 限制 |
| 服务器 curl 调 TMDB 慢 | 低 | 1 小时缓存兜底 |
| `bibij.icu` 恢复后用户混淆新旧 | 低 | 重构后无任何代码引用旧域，混淆面为零 |

---

**本 spec 文档为编码前的最终契约。任何实现细节变更需同步更新本文件。**
