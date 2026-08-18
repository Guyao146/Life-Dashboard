# 🌌 Life Dashboard —— 智能生活看板

<p align="center">
  <b>一个连接现实生活与数字世界的个人生活中枢</b>
</p>

<p align="center">
  📊 数据可视化 · 🏠 智能家居控制 · ⏰ 日程管理
</p>
<p align="center">
  📝 生活记录 · 🤖 HomeAssistant联动 · 🔒 Authentik统一认证
</p>

## ✨ 项目简介

**Life Dashboard（生活看板）** 是一个面向个人用户打造的全栈数字生活管理平台

通过 HomeAssistant + 米家集成 + Microsoft To Do 将日常生活中的各种信息进行整合

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

如果使用组白名单，请确认 Authentik 的 OAuth/OIDC Scope Mapping 会在 UserInfo 的 `groups` 字段中返回组名；否则请使用用户名或邮箱白名单。

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
2. 推荐使用密钥模式，在服务器环境变量中设置：

   ```bash
   LIFE_HUB_UPDATE_TOKEN="请替换为至少 32 位随机字符串"
   LIFE_HUB_UPDATE_MODE="token"
   LIFE_HUB_UPDATE_BRANCH="main"
   LIFE_HUB_UPDATE_REPOSITORY="https://github.com/Guyao146/Life-Dashboard.git"
   ```

3. 确保服务器已经配置 GitHub 仓库的读取凭证（公开仓库可直接使用 HTTPS，私有仓库建议使用只读 Deploy Key）；
4. 确保 PHP 用户可写 PHP 系统临时目录；执行升级时还需能写站点目录，并允许 PHP 使用 `proc_open` 执行 `git`；
5. 在设置 → 版本与更新中点击“检查更新”。检测到远端版本更高时，点击“升级到 vX.Y.Z”，PHP 会刷新临时浅克隆并部署文件。服务器本地的 `.env` 和 `.git` 不会被覆盖；遗留 `config.js` 会被删除。

如果你明确希望“前端点升级就直接拉取”，可以改成无密钥模式：

```bash
LIFE_HUB_UPDATE_MODE="auto"
LIFE_HUB_UPDATE_BRANCH="main"
```

`auto` 模式不需要输入密钥，但任何能访问 `update.php` 的人都可以触发一次固定分支的升级。因此建议把 `update.php` 放到反向代理的访问控制后面；否则请使用推荐的 `token` 模式。

`update.php` 只接受同源 POST、校验升级指令，并且只允许拉取 `LIFE_HUB_UPDATE_BRANCH` 指定的分支（默认 `main`），同时使用文件锁防止并发升级。升级密钥只保存在 `.env` 或 PHP-FPM 环境变量中。

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
