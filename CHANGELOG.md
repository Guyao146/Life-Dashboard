# 更新日志

本项目遵循 [语义化版本](https://semver.org/lang/zh-CN/)：

- `PATCH`：兼容性问题修复，例如 `0.3.0 → 0.3.1`
- `MINOR`：向下兼容的新功能，例如 `0.3.0 → 0.4.0`
- `MAJOR`：不兼容改动，例如 `0.3.0 → 1.0.0`

## [0.5.10] - 2026-08-18

### 修复

- 更新器改为在 PHP 系统临时目录维护 GitHub 浅克隆，版本检查与升级不再要求 Web 站点目录包含 `.git`。
- 无 Git 部署升级时原子覆盖产品文件，并保留服务器本地的 `config.js`、`.env` 与现有 `.git` 目录。

## [0.5.9] - 2026-08-18

### 修复

- 将升级锁移到 PHP 系统临时目录，修复站点根目录不可写时版本检查误报 `409 Conflict` 的问题。
- 合并前端并发版本检查；后端在升级锁繁忙时短暂等待，并可读取现有远端引用降级返回。
- 禁止 PHP 文件权限 Warning 污染 JSON 响应，并区分锁目录不可写与真正的并发任务。

## [0.5.8] - 2026-08-17

### 修复

- 版本检查改为调用同域 PHP 接口，由服务器通过 Git 读取 `origin/main:version.js`，消除 GitHub Raw 429、CDN 503 和不存在的 `master` 分支 404。
- 增加 5 分钟服务器端版本缓存，避免页面刷新时频繁执行远端 Git 检查。

## [0.5.7] - 2026-08-17

### 修复

- 将版本检查、GitHub 按钮和发布链接统一切换到迁移后的 `Guyao146/Life-Dashboard` 仓库，避免继续读取旧仓库的 `0.4.0`。

## [0.5.6] - 2026-08-17

### 新增

- 增加 PHP 8.2 同域升级程序，支持前端检测到新版本后发送升级指令并由服务器执行固定分支的 fast-forward 更新。
- 版本检查改用 GitHub Raw/jsDelivr 跨域源，并按语义化版本号判断远端是否真正更新。

### 修复

- 修复远端版本低于本地版本时被误报为“发现新版本”的问题。

## [0.5.0] - 2026-08-17

### 新增

- AI 助手：设置中可自配 OpenAI 兼容接口地址、API Key 与模型，支持一键拉取接口的模型列表；对话自动带上看板实时数据（环境、设备、日程、天气、纪念日），侧栏入口打开浮层对话。
- 天气与预报卡片：接入 Open-Meteo 免费开放接口，无需注册与 API Key，配置城市后展示实时天气与三日预报，数据缓存 30 分钟。
- 纪念日与倒数卡片：设置中添加生日、节日等日子，支持每年重复，自动计算倒数天数，当天高亮。
- 夜间模式：设置中可切换日间 / 夜间 / 跟随系统（默认跟随系统），全站配色适配并避免刷新闪烁。

## [0.4.0] - 2026-08-16

### 新增

- 登录页全新改版：左侧品牌介绍栏 + 右侧登录卡片，窄屏自动收起为单卡片。
- 本地账号密码登录（纯前端 PBKDF2-SHA256 校验），支持静态托管，与 Authentik OAuth 登录并存。
- `config.js` 新增 `localAuth` 配置项，附密码哈希生成说明；连续失败 5 次锁定 30 秒。

## [0.3.0] - 2026-08-07

### 新增

- 从浏览器实时读取日期、时间、问候语、配送预计时间、习惯周历和能耗日期。
- 看板组件显示、尺寸调整和桌面端拖拽排序。
- 页面版本号展示，以及版本升级、变更日志和 CI 校验流程。

### 修复

- 恢复 Authentik 公共客户端配置的默认加载，私有 `config.js` 缺失时仍可登录。
- 登录配置加载前禁用登录按钮，并在配置异常时显示明确错误。

[0.5.10]: https://github.com/Guyao146/Life-Dashboard/releases/tag/v0.5.10
[0.5.9]: https://github.com/Guyao146/Life-Dashboard/releases/tag/v0.5.9
[0.5.8]: https://github.com/Guyao146/Life-Dashboard/releases/tag/v0.5.8
[0.5.7]: https://github.com/Guyao146/Life-Dashboard/releases/tag/v0.5.7
[0.5.6]: https://github.com/Guyao146/Life-Dashboard/releases/tag/v0.5.6
[0.5.0]: https://github.com/Guyao146/Life-Dashboard/releases/tag/v0.5.0
[0.4.0]: https://github.com/Guyao146/Life-Dashboard/releases/tag/v0.4.0
[0.3.0]: https://github.com/Guyao146/Life-Dashboard/releases/tag/v0.3.0
