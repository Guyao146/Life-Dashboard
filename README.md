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

支持 H5 的运行环境
```

---

## 下载项目

```bash
git clone https://github.com/Guyao146/life-dashboard.git
```

---

## 配置修改

项目只使用根目录中的一个 `config.js`。请直接填写 Authentik 公共客户端和 Home Assistant 连接信息：

```javascript
window.LIFE_HUB_CONFIG = {
  oidc: {
    clientId: "填入 Authentik OIDC 客户端 ID",
    authorize: "https://login.example.com/application/o/authorize/",
    token: "https://login.example.com/application/o/token/",
  },
  homeAssistant: {
    url: "https://home.example.com",
    token: "填入 Home Assistant 长期访问令牌",
  },
};
```

> `config.js` 会发送到访问看板的浏览器，其中的 Home Assistant 令牌可被查看。请只在私密仓库与受保护的部署环境中使用，并采用最低权限专用账号。

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
