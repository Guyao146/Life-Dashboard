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

## ❀ Authentik 静默单点登录

打开登录页时，看板会向 Authentik 发起一次 `prompt=none` 的静默探测（Authentik 对授权页返回 `X-Frame-Options: deny`，因此无法用隐藏 iframe，只能顶层跳转，过程通常快到看不见）。探测结果分三种：

| 探测结果 | 登录页表现 |
| --- | --- |
| 已有会话且授权流程为隐式同意 | 直接显示「以 *** 的身份继续」，点击即进入看板 |
| 已有会话但返回 `consent_required` / `interaction_required` | 同样显示「以 *** 的身份继续」，点击后走一次正常授权（会带上 `login_hint`） |
| 没有会话（`login_required`） | 静默退回普通登录页，不显示任何错误 |

每个浏览器标签页只探测一次（用 `sessionStorage` 的 `life-hub-silent-flow` 标记），不会循环跳转。

想要「点一下就进」的完全无感体验，需在 Authentik 里把该应用的授权流程设为隐式同意：

1. 打开 **Applications → Providers**，编辑看板对应的 OAuth2/OpenID Provider；
2. 把 **Authorization flow** 改为 `default-provider-authorization-implicit-consent`；
3. 保存后重新打开看板登录页。

若保留显式同意流程（`default-provider-authorization-explicit-consent`），静默探测会返回 `consent_required`；此时看板仍会显示身份卡片，只是点击后多一次 Authentik 同意页。

> 「改用其他账号登录」会清除本机记住的身份并停止本标签页的静默探测；「清除本机登录信息」会额外清掉静默探测标记，下次打开重新探测。

### 登录很快就过期？必须开启 `offline_access`

**这是最常见的「每隔几分钟就要重新登录」的原因。** Authentik 默认只下发 access token，不下发 refresh token；access token 的有效期在 provider 里默认只有几分钟，一到期看板就没有任何东西可以用来续期。

按官方文档，应用要拿到 refresh token，**provider 和应用两边都必须请求 `offline_access` scope**。看板侧已经在授权请求里带了 `offline_access`，还需要在 Authentik 补上服务端配置：

1. 打开 **Applications → Providers**，编辑看板对应的 OAuth2/OpenID Provider；
2. 在 **Advanced protocol settings → Scope mapping** 中加入 `authentik default OAuth Mapping: OpenID 'offline_access'`（与 `openid`、`profile`、`email` 一并选中）；
3. 顺手确认同一页的有效期设置，建议：
   - **Access token validity**：`minutes=10` 或 `hours=1`
   - **Refresh token validity**：`days=30`（与 `.env` 的 `LIFE_HUB_OIDC_REMEMBER_DAYS` 对应）
4. 保存后在看板点「清除本机登录信息」，重新登录一次，让新的授权带回 refresh token。

配置是否生效可以在 **设置 → 连接与账户** 的身份诊断行查看：出现「登录续期：可自动续期」即为正常；若显示「无 refresh_token」，说明第 2 步没生效。

即使没有 refresh token，看板也不会直接把你踢回登录页：access token 失效时会先尝试一次 `prompt=none` 静默重授权（同一标签页至少间隔 20 秒），成功则无感回到看板。但这依赖 Authentik 会话仍然有效，且每次都会有一次极快的跳转，不能替代 `offline_access`。

### 关于「像 Google 那样的登录弹窗」

Google 账号那种不跳转、直接浮在页面上的账号选择气泡，用的是浏览器原生的 FedCM（Federated Credential Management）API，需要身份提供方实现 `/.well-known/web-identity` 等一套端点。**Authentik 目前没有实现 FedCM**，所以这种弹窗在自建 Authentik 上无法做到。

看板能做到的最接近效果就是当前方案：静默探测 + 登录页的「以 *** 的身份继续」身份卡片，代价是首次进入时一次极快的顶层跳转。

---


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

