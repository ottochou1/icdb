<?php
/**
 * 数据库配置模板：复制为 config.php 后修改
 * 一般由 install.php 自动生成，只有在无法自动安装时才需要手动改这个文件
 */
if (basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'config.php' || basename(__FILE__) === 'config.php') {
    http_response_code(403);
    exit;
}

return [
    'db_host' => 'localhost',      // 数据库主机，部分主机是 localhost，有的是独立地址
    'db_port' => '3306',
    'db_name' => 'huazheng_course', // 数据库名
    'db_user' => 'root',            // 数据库用户名
    'db_pass' => '',                // 数据库密码
    'db_charset' => 'utf8mb4',
    'table_prefix' => 'hz_',        // 表前缀，同库多站点时区分
    'session_days' => 30,           // 登录有效期（天）
];
