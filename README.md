# 🌌 Life Dashboard —— 智能生活看板

<p align="center">
  <b>一个连接现实生活与数字世界的个人生活中枢</b>
</p>

<p align="center">
  📊 数据可视化 · 🏠 智能家居控制 · ⏰ 日程管理 · 📝 生活记录 · 🤖 HomeAssistant联动 · 🔒 Authentik统一认证
</p>

---

## ✨ 项目简介

**Life Dashboard（生活看板）** 是一个面向个人用户打造的全栈数字生活管理平台

通过 HomeAssistant + 米家集成 + Microsoft To Do 将日常生活中的各种信息进行整合

让用户可以在一个统一的可视化界面中，快速了解自己的生活状态，并实现人与环境之间更加智能、高效的连接

> **你的生活，不应该被碎片化的信息打扰**
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
- 📅 今日 / 未来日程
- 📈 数据统计分析 (未来可能接入)
- 📌 生活状态概览
- 🚗 外卖进度 (未来可能接入)


---

## 🏠 Home Assistant 智能家居控制

深度连接智能家居生态：

支持：

- 设备状态读取 (未来更新)
- 智能设备控制
- 自动化场景触发 (未来更新)
- 家庭环境监控 (未来更新)


例如：

```
回家模式

↓


打开灯光

↓

调整空调温度

↓

播放喜欢的音乐

↓

展示今日生活摘要
```

让智能家居真正融入生活。


---

## 📝 生活记录系统

记录每天发生的重要事情：

- 📖 日记记录 (排期中)
- ✅ 习惯打卡 (排期中)
- 🎯 目标管理 (排期中)
- 📚 生活轨迹沉淀 (排期中)


通过长期积累，形成属于自己的数字生活档案。

---

# 🛠️ 技术栈

## 前端

- HTML5
- CSS3
- JavaScript


## 后端

- Java
- RESTful API
- 数据管理服务


## 智能家居

- Home Assistant
- XiaoMi Home 米家集成


---

# 📦 项目特点

### 🎨 极简可视化设计

采用 Dashboard 设计理念：

- 信息层级清晰
- 数据一目了然
- 适合桌面大屏展示


### 🔌 高扩展性

模块化设计，可持续扩展：

未来支持：

- AI 生活助手
- 智能推荐
- 更多 IoT 平台接入
- 数据分析模型


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

## 后端启动

进入后端目录：

```
复制config.example.js，重命名为config.js

修改 config.js 内的内容
```

window.LIFE_HUB_CONFIG = {
  oidc: {
    clientId: '填入你authentik的OIDC客户端ID',
    authorize: '填入回调地址 https://login.example.com/application/o/authorize/',
    token: '填入获取Token链接 https://login.example.com/application/o/token/'
  },
  homeAssistant: {
    url: '填入你的Homeassistant地址 https://home.example.com',
    token: '填入你的Homeassistant长期Token your-home-assistant-long-lived-access-token'
  }
};

---

## 前端访问

启动完成后访问：

```
http://localhost:8080
```

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
