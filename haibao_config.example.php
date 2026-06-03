<?php
// TMDB API 凭证配置模板
// 部署时复制为 haibao_config.php 并填入你的 API Key
if (!defined('BBJ_INCLUDED')) {
    http_response_code(403);
    die('Forbidden');
}

return [
    'tmdb_v3_key'         => '',         // TMDB v3 API Key（必填，从 https://www.themoviedb.org/settings/api 获取）
    'tmdb_lang'           => 'zh-CN',    // 接口语言
    'tmdb_region'         => 'HK',       // 地区
    'image_field'         => 'backdrop', // poster(2:3竖图) 或 backdrop(16:9横图)
    'image_size'          => 'w500',     // poster 尺寸：w185/w342/w500/original
    'backdrop_size'       => 'w1280',    // backdrop 尺寸：w300/w780/w1280/original
    'num_max'             => 20,         // 轮播图最大张数
    'cache_ttl'           => 3600,       // 缓存秒数（默认1小时）
    'db_table'            => 'mac_vod',  // 数据库表名
    'slide_sep'           => '|',        // 轮播图字段分隔符（兼容保留）
    'maoyan_enabled'      => true,       // 是否启用猫眼数据源（hot 模式）
    'maoyan_timeout'      => 8,          // 猫眼 API 超时秒数
    'tmdb_search_timeout' => 5,          // TMDB 搜索 API 超时秒数（逐条搜图用）
];
