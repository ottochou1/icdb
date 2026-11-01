# 华政ICDB · 课程评分与讨论社区（Node.js 版）

给华东政法大学同学用的选课参考站：课程信息共建 + 豆瓣式评分短评 + 课程讨论 + 「友邻」闲聊板块。注册登录后才能评分、发帖和添加课程。

> **要部署上线请用 [`php/`](./php/README.md) 里的 PHP + MySQL 版本**。PHP 版是当前生产主线，额外包含学生认证、课程上传授权、私信、管理后台、教务导入与闭站维护等功能。
> 本目录的 Node.js 版零依赖、开箱即跑，主要用于本地预览与功能迭代。

## 快速开始

```bash
node server.js          # 默认 3000 端口
PORT=8080 node server.js
```

打开 http://localhost:3000

演示账号：`demo@hz.example` / `123456`（第一位注册的用户自动成为管理员）

> 零第三方依赖，只需要 Node 18+（推荐 20/22），不需要 npm install。

## 功能

| 模块 | 说明 |
| --- | --- |
| 课程库 | 课程名称、任课老师、课程代码、学分、开课学院、类别、简介；支持关键词 / 学院 / 类别筛选，按热度、高分、讨论数、最新排序 |
| 评分短评 | 1-5 星 + 标签（给分好、点名少、硬核…）+ 上学学期 + 匿名发布；每人每门课只能评一次，可修改；支持「有用」点赞；自动统计均分与星级分布 |
| 课程讨论 | 每门课下有独立讨论区，可发帖、回复、点赞、删除自己的内容 |
| 友邻广场 | 纯闲聊论坛，带话题标签（闲聊 / 吐槽 / 求助 / 二手 / 拼课 / 选课咨询） |
| 用户体系 | 邮箱 + 密码注册（scrypt 加盐哈希），学号与年级选填且不公开；httpOnly Cookie 会话，有效期 30 天 |
| 批量导入 | 表单逐个添加，或上传 / 粘贴 CSV 一次导入整批课程，同名同老师自动跳过 |

## 目录结构

```
server.js          入口：HTTP 服务 + 静态资源
src/store.js       JSON 文件存储（内存 + 原子落盘）
src/auth.js        密码哈希与会话
src/api.js         全部业务逻辑与 REST 接口
src/seed.js        演示数据
src/csv.js         CSV 解析与生成
src/reset.js       清空数据
public/            前端（原生 ES Module，无构建步骤）
  index.html       页面骨架
  style.css        样式
  js/app.js        路由
  js/views/        各页面视图
data/              运行时数据（首次启动自动生成）
```

## 数据与备份

所有数据存放在 `data/*.json`（users / courses / reviews / posts / comments / likes / sessions）。
备份只需拷贝整个 `data` 目录；恢复时覆盖回去再重启服务即可。

清空所有数据（含演示数据）：

```bash
npm run reset       # 或 node src/reset.js
rm -rf data         # 等价做法
```

> 演示数据中的教师姓名与评价内容均为虚构，正式上线前建议先 `npm run reset`，再由同学录入真实课程。

## 部署到服务器

如果主机支持 Node.js（Node 18+）：

1. 宝塔面板可用「Node 项目」，轻量服务器直接 `node server.js`。
2. 上传整个项目目录（不需要 `node_modules`）。
3. 用 PM2 常驻：`npm i -g pm2 && pm2 start server.js --name huazheng-course`。
4. 用 Nginx 反代到对应端口，配置域名与 HTTPS。

**如果主机只提供 PHP + MySQL（大多数虚拟主机的情况），请使用 [`php/`](./php/README.md) 版本**，功能一致，上传后访问 `install.php` 即可自动建表。

生产环境建议：
- 修改 `src/auth.js` 中的 `SESSION_DAYS` 或接入 Redis 做会话存储（当前会话存 JSON 文件，重启不丢失）
- 定期备份 `data` 目录
- 数据量大后（课程上万条）可迁移到 SQLite / MySQL，`src/store.js` 是唯一需要替换的文件

## 接口一览

```
POST   /api/auth/register        注册（email, nickname, password, studentId?, grade?）
POST   /api/auth/login           登录
POST   /api/auth/logout          退出
GET    /api/auth/me              当前用户
PATCH  /api/auth/profile         修改资料

GET    /api/config               类别 / 学院 / 标签配置
GET    /api/home                 首页聚合数据
GET    /api/courses              课程列表（q, category, college, teacher, sort, page）
POST   /api/courses              添加课程
POST   /api/courses/import       CSV 批量导入
GET    /api/courses/:id          课程详情
PATCH  /api/courses/:id          编辑（创建者）
DELETE /api/courses/:id          删除（创建者，级联）

GET    /api/courses/:id/reviews  短评列表（sort, rating）
POST   /api/courses/:id/reviews  发布短评
PATCH  /api/reviews/:id          编辑短评
DELETE /api/reviews/:id          删除短评
POST   /api/reviews/:id/like     点赞 / 取消

GET    /api/posts                帖子列表（type, courseId, tag, q, page）
POST   /api/posts                发帖
GET    /api/posts/:id            帖子详情 + 回复
DELETE /api/posts/:id            删除帖子
POST   /api/posts/:id/comments   回复
DELETE /api/comments/:id         删除回复
POST   /api/posts/:id/like       点赞

GET    /api/users/:id            用户主页
GET    /api/me/feed              我的短评 / 帖子 / 课程
```
