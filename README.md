<p align="lift"><img src="https://raw.githubusercontent.com/Guyao146/Sakura-EcoSystem-wiki/main/assets/life.svg" width="100"></p>

# Life Dashboard —— 智能生活看板

[![樱落生态成员](https://raw.githubusercontent.com/Guyao146/Sakura-EcoSystem-wiki/main/assets/ConnectEcoSystem.svg)](https://mcylyr.cn)
[![已编写Wiki](https://raw.githubusercontent.com/Guyao146/Sakura-EcoSystem-wiki/main/assets/sakura-wiki.svg)](https://wiki.mcylyr.cn/)

<p align="left">
  <b>一个连接现实生活与数字世界的个人生活中枢</b>
</p>

<p align="left">
  📊 数据可视化 · 🏠 智能家居控制 · ⏰ 日程管理
</p>
<p align="left">
  📝 生活记录 · 🤖 HomeAssistant联动 · 🔒 Authentik统一认证
</p>

## 樱落生态Wiki
该项目已编写Wiki，了解项目更多细节 https://wiki.mcylyr.cn

## 项目简介

**Life Dashboard（生活看板）** 是一个面向个人用户打造的全栈数字生活管理平台

通过 HomeAssistant + 米家集成 + Microsoft To Do + DeepSeek Harness 将日常生活中的各种信息进行整合

让用户可以在一个统一的可视化界面中，快速了解自己的生活状态，并实现人与环境之间更加智能、高效的连接


> **你的生活，不应该被碎片化的信息打扰**
> 
> **Life Dashboard 希望成为你的个人数字驾驶舱**

---

# 项目展示

<img width="2553" height="1320" alt="image" src="https://github.com/user-attachments/assets/31c3d2e6-7554-494f-a0ba-7fc2d73acde3" />


# 快速开始

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

