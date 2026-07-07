<h1 align="center">TypechoMeta</h1>

<p align="center">
  <strong>Typecho 文章 SEO 元信息增强插件 · 支持 AI 自动生成 Description / Keywords</strong>
</p>

<p align="center">
  <a href="https://github.com/lhl77/Typecho-Plugin-Meta"><img src="https://img.shields.io/badge/GitHub-Typecho--Plugin--Meta-181717?style=flat-square&logo=github" alt="GitHub"></a>
  <img src="https://img.shields.io/badge/Typecho-%3E%3D1.3.0-orange?style=flat-square" alt="Typecho >= 1.3.0">
  <img src="https://img.shields.io/badge/PHP-%3E%3D7.2-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP >= 7.2">
  <a href="https://github.com/lhl77/Typecho-Plugin-Meta/issues"><img src="https://img.shields.io/github/issues/lhl77/Typecho-Plugin-Meta?style=flat-square" alt="Issues"></a>
  <a href="https://github.com/lhl77/Typecho-Plugin-Meta/stargazers"><img src="https://img.shields.io/github/stars/lhl77/Typecho-Plugin-Meta?style=flat-square&logo=github" alt="GitHub Stars"></a>
</p>

<p align="center">
  快捷链接：
  <a href="https://github.com/lhl77/Typecho-Plugin-Meta">GitHub</a> |
  <a href="https://github.com/lhl77/Typecho-Plugin-Meta/issues">问题反馈</a> |
  <a href="https://blog.lhl.one">作者博客</a>
</p>

---

## 功能特色

| 功能 | 说明 |
| --- | --- |
| 自定义 Meta 字段 | 在文章编辑页直接填写 Meta Title / Description / Keywords |
| 文章页 SEO 增强 | 可选输出 canonical / robots / Open Graph / Twitter Card / JSON-LD |
| AI 一键生成 | 支持在编辑页与配置页测试区生成 Description / Keywords |
| 多模型接口支持 | 支持 OpenAI 兼容、Gemini、Anthropic 三类接口 |
| 稳定路由与鉴权 | 插件启用后自动注册 action，并带安全 token 校验 |

## 安装

### 方式一：下载压缩包

1. 前往 [GitHub 仓库](https://github.com/lhl77/Typecho-Plugin-Meta) 下载源码或发布包
2. 解压后将目录重命名为 `TypechoMeta`
3. 上传到 Typecho 的 `usr/plugins/` 目录
4. 在后台进入 控制台 -> 插件，启用 `TypechoMeta`

### 方式二：Git 克隆

```bash
cd /your-site/usr/plugins/
git clone https://github.com/lhl77/Typecho-Plugin-Meta.git TypechoMeta
```

## 使用说明

1. 启用插件后进入插件设置页，填写 AI 提供方、接口地址、模型名、Token。
2. 在文章编辑页会出现 AI 生成按钮，可一键填充 Description 和 Keywords。
3. 生成结果仅写入表单，需手动保存文章。
4. 若不使用 AI，也可仅使用 Meta 字段和 SEO 标签增强能力。

## 常见问题

### 1) 提示 AI 接口 404

请检查接口地址是否完整。OpenAI 兼容接口通常为：

`https://api.openai.com/v1/chat/completions`

### 2) 返回非 JSON

请确认模型输出遵循插件提示词，且接口确实返回 JSON。

### 3) 按钮点击无反应

请确认插件已启用，且后台登录状态有效；建议刷新后台后重试。

---

<p align="center">
  Made with ❤️ by <a href="https://github.com/lhl77">LHL</a>
</p>
