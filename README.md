<p align="center"><img src="https://raw.githubusercontent.com/Guyao146/Sakura-EcoSystem-wiki/main/assets/life.svg" width="180"></p>

# Life Dashboard —— 智能生活看板

[![樱落生态成员](https://raw.githubusercontent.com/Guyao146/Sakura-EcoSystem-wiki/main/assets/ConnectEcoSystem.svg)](https://mcylyr.cn)

<p align="lift">
  <b>一个连接现实生活与数字世界的个人生活中枢</b>
</p>

<p align="lift">
  📊 数据可视化 · 🏠 智能家居控制 · ⏰ 日程管理
</p>
<p align="lift">
  📝 生活记录 · 🤖 HomeAssistant联动 · 🔒 Authentik统一认证
</p>

## ✨ 项目简介

**Life Dashboard（生活看板）** 是一个面向个人用户打造的全栈数字生活管理平台

通过 HomeAssistant + 米家集成 + Microsoft To Do + DeepSeek Harness 将日常生活中的各种信息进行整合

让用户可以在一个统一的可视化界面中，快速了解自己的生活状态，并实现人与环境之间更加智能、高效的连接


> **你的生活，不应该被碎片化的信息打扰**
> 
> **Life Dashboard 希望成为你的个人数字驾驶舱**

---

# 🖼️ 项目展示

<img width="2553" height="1320" alt="image" src="https://github.com/user-attachments/assets/31c3d2e6-7554-494f-a0ba-7fc2d73acde3" />

---

# 🌟 核心功能

## 📊 个人数据可视化

将生活数据以直观、美观的方式呈现：

- 🕒 实时时间展示
- 🌤️ 天气信息展示 (未来可能接入)
- 📈 数据统计分析 (未来可能接入)
- 📌 生活状态概览
- 🚗 外卖进度 (未来可能接入)
- ✅ 每日日程管理


---

## 🏠 Home Assistant 智能家居控制

深度连接智能家居生态：

支持：

- 设备状态读取 (未来更新)
- 智能设备控制
- 自动化场景触发 (未来更新)
- 家庭环境监控 (未来更新)

让智能家居真正融入生活。


## 💻 DSH 工作区动态

本地 `dsh-activity-tracker 1.6.0+` 每 10 秒主动通过 HTTPS 向远端生活看板推送工作区快照，因此本地 DSH 在 NAT 后也无需开放端口。卡片会显示工作中、活跃、最近活动、空闲，以及本地数据源在线/离线；已授权工作区可查看会话记录并向当前运行中的 DSH 会话发送后续消息。

### 远端服务器配置

在 Life Dashboard 服务器 `.env` 添加：

```dotenv
LIFE_HUB_DSH_PUSH_SECRET="replace-with-a-random-32-byte-or-longer-secret"
LIFE_HUB_DSH_OFFLINE_AFTER_SECONDS="45"
LIFE_HUB_OIDC_REMEMBER_DAYS="30"
```

### 本地 DSH 配对（无需手写文件）

1. 以 Authentik 管理员身份打开 **设置 → 连接与账户 → 连接本机 DSH**；
2. 点击「生成 DSH 配对码」；
3. 在本机 DSH 打开 **📊 活动统计 → 总设置 → 生活看板连接**，输入显示的 6 位验证码；
4. 可选勾选「允许查看会话详情」，然后点击「输入验证码并连接」。

验证码有效期为 5 分钟，仅能成功使用一次，最多允许 5 次尝试。配对完成后插件自动在本机保存连接信息，页面不会显示或要求复制 HMAC 密钥。推送仍采用 HMAC-SHA256、120 秒时间窗口和重放防护，远端缓存写入系统临时目录；浏览器仍需 Authentik 管理员身份才能读取。

### 远端会话消息

在“工作区动态 → 查看详情”中选择一个会话后，管理员可填写并发送消息。服务端会再次验证管理员身份、已授权工作区和该会话是否仍在最新快照中，然后将消息写入 120 秒短时队列；本地 DSH 在下一次已签名推送时领取，并将其作为下一轮用户消息交给 `agent.followup()`。

- 单条消息最大 8,000 字符；
- 命令按 UUID 幂等投递，并由本地下一次快照回执删除；
- 目标会话必须当前正在 DSH 中运行；否则命令超时失效，不会误投递到其他会话；
- `LIFE_HUB_DSH_PUSH_SECRET` / 本地 `token` 至少 32 个随机字符，必须保密且不得提交到 Git。

### 登录续期

OIDC 登录默认最多记住 30 天，Access Token 到期前会使用 Refresh Token 自动续期；续期失败、会话超过期限或私密接口返回 401 时会清理会话并返回登录页。Authentik Provider 必须允许公共客户端使用 Refresh Token，并允许 `offline_access` scope；否则仍会在 Access Token 到期后要求重新登录。

# 📦 项目特点

### 🎨 极简可视化设计

采用 Dashboard 设计理念：

- 信息层级清晰
- 数据一目了然
- 适合桌面大屏展示


### 🌱 Personal Life OS

不仅是一个 Dashboard

它更像一个：

> 属于自己的数字生活操作系统


---

# 🚀 快速开始

## 环境要求

```
现代浏览器
PHP 8.2（配置权限网关与一键升级接口）
Nginx 或 Apache（必须禁止 Web 访问 .env）
```

---

## 下载项目

```bash
git clone https://github.com/Guyao146/Life-Dashboard.git
```

---

## 配置修改

复制 `.env.example` 为服务器私密配置：

```bash
cp .env.example .env
chmod 600 .env
```

至少填写：

```dotenv
LIFE_HUB_OIDC_CLIENT_ID="Authentik 公共客户端 ID"
LIFE_HUB_OIDC_AUTHORIZE_URL="https://login.example.com/application/o/authorize/"
LIFE_HUB_OIDC_TOKEN_URL="https://login.example.com/application/o/token/"
LIFE_HUB_OIDC_USERINFO_URL="https://login.example.com/application/o/userinfo/"

LIFE_HUB_ADMIN_GROUPS="Life Dashboard Admins"
LIFE_HUB_ADMIN_USERS=""
LIFE_HUB_ADMIN_EMAILS=""

LIFE_HUB_HA_URL="https://home.example.com"
LIFE_HUB_HA_TOKEN="Home Assistant 长期访问令牌"
```

管理员可按 Authentik **组 / 用户名 / 邮箱** 任意一种白名单判定，多个值用逗号分隔。三种白名单全部为空时，后端默认拒绝所有私密配置请求。

推荐在 Authentik 后台按组管理管理员：

1. 在 **Directory → Groups** 创建 `Life Dashboard Admins`（也可使用其他名称）；
2. 把需要管理看板和执行一键升级的 OIDC 用户加入该组；
3. 在 Life Dashboard 服务器 `.env` 中填写完全相同的组名：`LIFE_HUB_ADMIN_GROUPS="Life Dashboard Admins"`；
4. 在该应用的 OAuth2/OIDC Provider 中确认已选择 Authentik 默认的 **OpenID `profile` Scope Mapping**。应用登录时已请求 `profile`，UserInfo 应由该映射返回 `groups` 声明；
5. 退出 Life Dashboard 后重新走一次 OIDC 登录，使新组成员关系进入新的 access token。

登录后可在 **设置 → 连接与账户** 查看身份诊断，包括当前 OIDC 用户、UserInfo 返回的组、服务器允许的管理员组和最终匹配结果。如果“当前组”显示未收到声明，应先修复 Authentik Provider 的 Scope Mapping；如果两边组名不同，则修改 `.env` 或 Authentik 组名。

配置加载流程：

1. 未登录浏览器只可通过 `config.php?action=public` 获取 OIDC 公共参数；
2. Authentik 登录完成后，浏览器把 access token 发送给同域 `config.php?action=private`；
3. PHP 服务端调用 Authentik UserInfo 校验令牌与管理员身份；
4. 只有管理员才会收到 Home Assistant 地址与令牌。

> Home Assistant Token 最终仍需发送到已通过校验的管理员浏览器才能由前端调用 HA API，但普通用户、未登录用户和本地前端账号无法获取它。

### Nginx 必须禁止 `.env`

`.env` 放在 Web 根目录时**不会天然安全**。请把 `nginx-life-dashboard.conf.example` 中的规则加入站点 `server {}`，至少包含：

```nginx
location ~ /\.(?!well-known(?:/|$)) {
    return 404;
}
location = /config.js { return 404; }
location = /config.example.js { return 404; }
```

PHP FastCGI 必须传递 `Authorization` 头，否则管理员校验拿不到 access token：

```nginx
fastcgi_param HTTP_AUTHORIZATION $http_authorization;
```

应用配置后执行：

```bash
nginx -t && systemctl reload nginx
curl -i https://life.example.com/.env
```

最后一个请求必须返回 `404` 或 `403`，绝不能返回 `.env` 内容。更安全的做法是把 `.env` 放在 Web 根目录之外，并通过 `LIFE_HUB_ENV_FILE=/安全路径/life-dashboard.env` 指定。

---

## 🔑 本地账号密码登录（可选）

登录卡片在 OAuth 按钮上方提供账号密码登录，密码使用浏览器端 PBKDF2-SHA256 校验；用户名与哈希由 PHP 公开配置接口提供。

在 `.env` 中配置本地登录：

```dotenv
LIFE_HUB_LOCAL_AUTH_USERNAME="admin"
LIFE_HUB_LOCAL_AUTH_SALT="base64 盐"
LIFE_HUB_LOCAL_AUTH_HASH="base64 密码哈希"
LIFE_HUB_LOCAL_AUTH_ITERATIONS="310000"
```

生成新的密码哈希：在任意 HTTPS 页面（或 localhost）的浏览器控制台运行：

```javascript
const s = crypto.getRandomValues(new Uint8Array(16));
const k = await crypto.subtle.importKey('raw', new TextEncoder().encode('你的密码'), 'PBKDF2', false, ['deriveBits']);
const b = await crypto.subtle.deriveBits({ name: 'PBKDF2', hash: 'SHA-256', salt: s, iterations: 310000 }, k, 256);
const e = a => btoa(String.fromCharCode(...a));
JSON.stringify({ salt: e(s), hash: e(new Uint8Array(b)), iterations: 310000 });
```

> ⚠️ 本地登录属于体验层门槛：哈希会由公开配置接口下发到浏览器，无法抵御能读取源码的访问者。本地登录只允许进入不含私密 HA 数据的看板，**不能解锁 Home Assistant Token**。真正的管理员身份由 Authentik UserInfo 校验。

---

## 🌤 天气与预报（免费）

天气卡片使用 [Open-Meteo](https://open-meteo.com/) 开放接口：**无需注册、无需 API Key、非商业用途免费**。在设置 → 天气与位置中填写城市名称即可，数据缓存 30 分钟。

---

## 🤖 AI 助手

设置 → AI 助手中接入任意 OpenAI 兼容接口（OpenAI、DeepSeek、各类中转站均可）：

1. 填写接口地址（如 `https://api.example.com/v1`）与 API Key；
2. 点击「拉取模型」自动获取该接口的模型列表并选择；
3. 保存后从侧栏「AI 助手」打开对话浮层。

对话时会自动带上看板的实时数据（室内环境、设备状态、今日日程、天气、纪念日倒数），可以直接问「家里现在多少度」「总结一下今天的安排」。

> ⚠️ API Key 仅保存在当前浏览器的 localStorage；接口站点需允许浏览器跨域（CORS），大多数中转站默认支持。

---

## 🌙 夜间模式

设置 → 外观中可切换 **日间 / 夜间 / 跟随系统**（默认跟随系统），跟随系统会随设备的外观设置自动切换，刷新页面不会闪烁。

---

## 🎂 纪念日与倒数

设置 → 纪念日与倒数中添加生日、节日和重要日子，支持"每年重复"，看板自动计算倒数天数，当天会高亮提示。

---

# 🗺️ Roadmap

- [x] 基础生活数据展示
- [x] H5 Dashboard 页面
- [x] Home Assistant 接入

- [ ] AI 智能生活助手
- [ ] 语音控制
- [ ] 多设备适配
- [ ] 移动端 APP
- [ ] 数据分析与趋势预测

---

# 🏷️ 版本发布

当前版本由根目录的 `version.js` 统一管理，并显示在登录页、桌面侧栏和设置页。
项目遵循语义化版本；每次更新产品代码时，必须同步升级版本号并填写 `CHANGELOG.md`。
发布辅助工具使用 PHP 8 CLI，不依赖 Python 或 Node.js。

### PHP 8.2 一键升级（可选）

站点目录无需是 Git 工作区。`update.php` 会在 PHP 系统临时目录维护一个远端浅克隆，并把其中的产品文件部署到站点目录：

1. 将根目录的 `update.php` 暴露在与看板相同的域名下；
2. 升级权限复用 Authentik 管理员登录，不需要额外的 `LIFE_HUB_UPDATE_TOKEN`：

   ```bash
   LIFE_HUB_UPDATE_BRANCH="main"
   LIFE_HUB_UPDATE_REPOSITORY="https://github.com/Guyao146/Life-Dashboard.git"
   ```

3. 确保服务器已经配置 GitHub 仓库的读取凭证（公开仓库可直接使用 HTTPS，私有仓库建议使用只读 Deploy Key）；
4. 确保 PHP 用户可写 PHP 系统临时目录；执行升级时还需能写站点目录，并允许 PHP 使用 `proc_open` 执行 `git`；
5. 在设置 → 版本与更新中点击“检查更新”。检测到远端版本更高时，可点击“升级到 vX.Y.Z”或进入“升级控制台”；控制台会实时展示源码同步、版本校验、逐文件原子部署和清理阶段的服务器日志。服务器本地的 `.env` 和 `.git` 不会被覆盖；遗留 `config.js` 会被删除。

`update.php` 只接受同源 POST，并要求当前浏览器携带有效的 Authentik 管理员会话；普通 Authentik 用户、本地账号、未登录用户和跨站请求都不能触发升级。它只允许拉取 `LIFE_HUB_UPDATE_BRANCH` 指定的分支（默认 `main`），同时使用文件锁防止并发升级，并要求远端版本**严格高于**当前部署版本以拒绝重复升级与降级。升级控制台使用同一接口的 `update-stream` 指令接收 SSE 进度，但每次升级请求都会在服务器端重新验证管理员身份；访问令牌不会写入升级日志或磁盘。

### 从旧 `config.js` 迁移

1. **先**创建完整 `.env` 并配置 Nginx 点文件拒绝规则；
2. 确认 `GET /config.php?action=public` 能返回 OIDC 公共参数；
3. 部署 `0.6.0` 文件；升级器会删除遗留 `config.js`；
4. 立即在 Home Assistant 撤销旧长期访问令牌并生成新令牌写入 `.env`；
5. 如果旧 `config.js` 曾提交到 Git，即使仓库私有，也应视为已经泄露并轮换其中所有密钥；
6. 使用普通 Authentik 用户测试 `action=private` 返回 `403`，管理员账号应返回 `200`。

前端版本检查同样通过 `update.php` 完成。PHP 每 5 分钟最多刷新一次临时浅克隆并读取其中的 `version.js`；浏览器不会再请求 GitHub Raw 或 CDN，因此不受浏览器侧 CORS、429 和 CDN 暂时故障影响。

```bash
# 修复问题：0.3.0 -> 0.3.1
php scripts/bump_version.php patch

# 新增兼容功能：0.3.0 -> 0.4.0
php scripts/bump_version.php minor

# 不兼容升级：0.3.0 -> 1.0.0
php scripts/bump_version.php major

# 发布前校验
php scripts/check_version.php
```

升级脚本会同时更新发布日期并创建更新日志模板。提交前必须将模板中的 `TODO` 替换为实际变更；Pull Request 会通过 GitHub Actions 检查产品代码是否同步升级了版本。


---

# 🤝 贡献

欢迎提交 Issue 和 Pull Request。

如果你喜欢这个项目，可以点一个 ⭐ Star 支持一下！


---

# 📄 License

MIT License

---

<p align="center">

Made with ❤️ by Sakura Gu

</p>
