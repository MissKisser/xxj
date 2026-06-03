<?php
// BBJ 评论功能入口（已禁用）
// 原远程数据源不可用，本地不实现批量评论导入
// 文件保留以避免管理菜单 404
http_response_code(503);
header('Content-Type: text/plain; charset=utf-8');
die('评论功能已禁用。如需恢复批量导入，请参考 SPEC.md 重新实现数据源。');
