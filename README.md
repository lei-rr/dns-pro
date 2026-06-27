# dns-pro

DNSPod / Cloudflare / Cloudflare for SaaS / 腾讯云 EdgeOne 一体的 DNS 与自定义主机名管理面板。

基于 ThinkPHP 8 + Vue 3（无构建 ESM）。

## 功能

- **DNSPod**：站点 / 解析记录 CRUD
- **Cloudflare**：站点 / 解析记录 CRUD
- **Cloudflare for SaaS（自定义主机名）**：创建 / 删除自定义主机名，DCV 验证、SSL 状态展示、自定义源服务器、默认回源（fallback origin）、境内优选 CNAME，自动同步到 DNSPod
- **EdgeOne**：腾讯云 EdgeOne 加速域名管理
- **优选域名**：维护一套境内优选 CNAME 候选清单

## 环境要求

- PHP 8.1+
- Composer
- 推荐部署在 Nginx + PHP-FPM 之后

## 快速开始

```bash
git clone https://github.com/<你的账号>/dns-pro.git
cd dns-pro
composer install --no-dev

# 配置登录账号密码
cp data/config.json.example data/config.json
vim data/config.json
```

把站点根目录指向 `public/`，确保 `data/` 和 `runtime/` 可写：

```bash
chmod -R 755 data runtime
```

访问首页登录，进入控制台后在「Provider 管理」里添加你的 DNSPod / Cloudflare / EdgeOne 凭据。

## 目录结构

```
app/                 ThinkPHP MVC（controller / service / repository / validate / middleware / support）
config/              ThinkPHP 配置
public/              Web 入口
public/assets/       Vue 3 前端（modules 按 provider 平级模块化）
route/               路由定义
data/                JSON 存储（运行时凭据 / hostname 偏好等，包含敏感信息，已 ignore）
runtime/             缓存 / session / 日志（已 ignore）
```

## 数据存储

不依赖数据库，使用文件存储：

- `data/config.json` — 登录账号密码
- `data/providers.json` — 各 provider 凭据
- `data/hostname/preferences.json` — hostname 偏好（境内优选 CNAME 等）
- `data/hostname/preferred-domains.json` — 优选域名候选清单

所有 JSON 文件首次写入时自动创建，无需手动 mkdir。

## 鉴权

单用户明文鉴权 + session + captcha，凭据放在 `data/config.json`。修改账号密码直接改文件即可，无须重启。

## 缓存

provider / zone / record 列表统一走 ThinkPHP 缓存（默认 file 驱动），TTL 3 天，按 provider tag 失效。可在 `config/services.php` 调整 `cache_ttl`。
