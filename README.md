# dns-pro

DNSPod / Cloudflare / Cloudflare for SaaS / Cloudflare Tunnel / 腾讯云 EdgeOne 一体的 DNS 与隧道管理面板。

基于 ThinkPHP 8 + Vue 3（无构建 ESM）。

## 功能

- **DNSPod**：站点 / 解析记录 CRUD
- **Cloudflare**：站点 / 解析记录 CRUD
- **Cloudflare for SaaS（自定义主机名）**：创建 / 删除自定义主机名，DCV 验证、SSL 状态展示、自定义源服务器、默认回源（fallback origin）、境内优选 CNAME，自动同步到 DNSPod
- **Cloudflare Tunnel（cloudflared）**：创建 / 删除隧道，多平台安装引导、隧道状态、令牌轮换，公共主机名路由（公共域名 → 本地服务），自动在 Cloudflare 区域建 CNAME
- **EdgeOne**：腾讯云 EdgeOne 站点 / 加速域名管理，创建时可自动同步 CNAME 到 DNSPod
- **优选域名**：维护一套境内优选 CNAME 候选清单

## 技术栈

- **后端**：PHP 8.1+ / ThinkPHP 8，分层为 controller → service → repository，凭据走文件存储（无数据库）
- **前端**：Vue 3 + Vue Router + Pinia + Ant Design Vue，**无构建（原生 ESM）**，浏览器直接加载 `public/assets` 下的源码，改完即生效、无需 npm build
- **第三方 SDK**：腾讯云 SDK（DNSPod / EdgeOne），Cloudflare 走原生 REST API
- **前端依赖**：Vue / Pinia / Vue Router / axios / dayjs / Ant Design Vue 通过国内 CDN（七牛 `s4.zstatic.net`，带 brotli 压缩）引入，见 `public/app.html`

## 环境要求

- PHP 8.1+
- Composer
- 推荐部署在 Nginx + PHP-FPM 之后，建议开启 OPcache
- 推荐启用 HTTPS（复制按钮的剪贴板 API 在非安全上下文下会降级，且凭据走明文传输有风险）

## API 凭据与所需权限

在控制台「服务商」页添加凭据。各服务商对应的 API 权限如下。

### DNSPod（腾讯云 SecretId / SecretKey）

使用腾讯云 API 密钥（SecretId + SecretKey）。建议用子账号并授予 DNSPod 相关权限：

- `QcloudDNSPodFullAccess`，或最小化到以下接口：
  - `DescribeDomainList` / `CreateDomain` / `DeleteDomain`
  - `DescribeRecordList` / `CreateRecord` / `ModifyRecord` / `DeleteRecord`

### Cloudflare（API Token）

在 Cloudflare → My Profile → API Tokens 创建 **自定义 Token**。按你要用的功能勾选权限：

| 功能 | 所需权限（Permission） | 作用域（Scope） |
|------|----------------------|----------------|
| 站点列表 | Zone → Zone → **Read** | All zones（或指定 zone） |
| 解析记录 CRUD | Zone → DNS → **Edit** | All zones（或指定 zone） |
| Cloudflare for SaaS 自定义主机名 | Zone → SSL and Certificates → **Edit** | 目标 zone |
| Cloudflare Tunnel（cloudflared） | Account → Cloudflare Tunnel → **Edit** | 目标 account |
| Tunnel 公共主机名路由（自动建 CNAME） | Zone → DNS → **Edit** | 路由所在 zone |

补充说明：

- **account_id**：创建站点（Zone）、以及 Cloudflare Tunnel 的所有操作都是 account 级，**必须**在 Cloudflare 服务商配置里填写 Account ID（位于 Cloudflare 仪表盘右下角 / 任一 zone 的 Overview 页）。
- **Cloudflare Tunnel 服务商**不单独保存密钥，而是关联一个已配置的 Cloudflare 服务商，复用其 API Token 与 account_id。因此该 Token 必须同时具备上表中 Tunnel 与 DNS 两项权限。
- Cloudflare Tunnel 的公共主机名只能路由到**同一 Cloudflare 账户内托管的域名**（CNAME 目标 `<UUID>.cfargotunnel.com` 仅代理同账户 zone）。

### EdgeOne（腾讯云）

EdgeOne 服务商不单独保存密钥，关联一个已配置的 DNSPod 服务商，复用其腾讯云密钥。该密钥需具备 EdgeOne（TEO）与 DNSPod 权限：

- EdgeOne：`DescribeZones` / `DescribeAccelerationDomains` / `CreateAccelerationDomain` / `ModifyAccelerationDomain` / `ModifyAccelerationDomainStatuses` / `DeleteAccelerationDomains` / `ModifyHostsCertificate`
- DNSPod：解析记录读写（用于把加速域名 CNAME 自动同步到 DNSPod）

### 服务商关联关系

```
edgeone     ──关联──> dnspod      （复用腾讯云密钥；CNAME 同步到 DNSPod）
saas        ──关联──> cloudflare  （Cloudflare for SaaS）
            └─关联──> dnspod（可选，自动同步 DNS 记录）
cloudflared ──关联──> cloudflare  （隧道 + 路由 CNAME 都用 CF Token）
```

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

访问首页登录，进入控制台后在「服务商」里添加你的 DNSPod / Cloudflare / EdgeOne / Cloudflare Tunnel 凭据。

## 使用流程

推荐按下面顺序初始化和使用系统：

1. 在「服务商」中添加基础凭据：DNSPod / Cloudflare。
2. 如果需要 Cloudflare for SaaS、Tunnel 或 EdgeOne，再为它们添加关联型 provider。
3. 进入对应模块完成站点接入：
   - DNSPod / Cloudflare：添加 zone 后管理解析记录
   - EdgeOne：选择站点后管理加速域名、证书、启停状态
   - SaaS：管理自定义主机名、DCV、默认回源与境内优选
   - Cloudflared：管理隧道、令牌与公共主机名路由
4. 如需自动写入 DNS，确保 provider 关联关系与目标权限已配置完整。

典型场景：

- **普通 DNS 托管**：直接使用 DNSPod / Cloudflare 模块管理 zone 和记录。
- **Cloudflare for SaaS**：在 `saas` 模块创建自定义主机名，并按需自动同步验证/优选记录到 DNSPod。
- **Tunnel 暴露本地服务**：在 `cloudflared` 模块创建 tunnel，再为公共主机名绑定本地服务。
- **EdgeOne 加速域名接入**：在 `edgeone` 模块管理加速域名，并在创建时自动把 CNAME 同步到 DNSPod。

## 目录结构

```
app/
├── controller/      各 provider 的 HTTP 入口（cloudflare / cloudflared / dnspod / edgeone / saas / provider）
│   └── concerns/    控制器共享 trait（如查询参数解析）
├── service/         业务逻辑，按 provider 分目录
│   ├── concerns/    service 共享 trait（缓存 / 分页 / SDK 异常）
│   ├── dnspod/      含 DnsPodSyncSupport：被 edgeone / saas 复用的 DNSPod 同步支撑
│   ├── cloudflare/  含 CloudflareApiClient：被 cloudflared 复用
│   └── ...
├── repository/      ProviderRepository（凭据读写 + 缓存失效）
├── validate/        请求参数验证器
├── support/         ApiResponse / AuthSession / JsonStore 等基础设施
└── middleware/      鉴权中间件
config/              ThinkPHP 配置（providers.php 定义各服务商字段，services.php 定义缓存）
route/api/           各 provider 的 API 路由
public/              Web 入口
public/assets/       Vue 3 前端
├── modules/         按 provider 平级模块化（每个模块自带 views / components / utils / api）
│   └── common/dns/  DNSPod 与 Cloudflare 复用的通用 DNS 记录视图
├── shared/          跨模块共享组件与工具
├── providers/       provider 注册中心（registry）与状态（store）
└── routes/          路由解析
data/                JSON 存储（运行时凭据 / saas 偏好等，含敏感信息，已 ignore）
runtime/             缓存 / session / 日志（已 ignore）
```

## 架构说明

项目的设计目标不是做“全家桶式平台框架”，而是在当前规模下保持：

- 模块边界清楚
- 职责稳定
- 易于持续迭代
- 少做无收益抽象

- **模块边界**：每个 provider 是独立模块，互不横向依赖。跨模块复用通过下沉到中立层实现：
  - `service/dnspod/DnsPodSyncSupport` — edgeone / saas 共用的「DNS 记录同步到 DNSPod」支撑（zone 最长后缀匹配、冲突清理、provider 查找）
  - `service/cloudflare/*` — cloudflared 复用 Cloudflare 的 ApiClient / ZoneService / DnsRecordService
  - `controller/concerns/ResolvesQueryParams` — 控制器共用的 query 参数解析
- **关联型 provider**：edgeone / saas / cloudflared 不单独保存密钥，而是关联一个基础 provider（dnspod 或 cloudflare）复用其凭据。
- **服务商占用检查**：provider 删除依赖由后端统一计算，除 provider 间引用外，还会检查 saas 记录级 `sync_provider_id` 占用，避免删掉仍在被 saas 同步使用的服务商。
- **后端分层**：`controller -> service -> repository -> support`
  - `controller`：参数读取、校验、响应包装
  - `service`：领域逻辑、provider API 调用、workflow 编排
  - `repository`：本地 JSON 数据访问
  - `support`：基础设施与共享技术能力
- **前端分层**：`modules -> shared -> providers/routes`
  - `modules/`：按 provider 组织业务页面与组件
  - `shared/`：跨模块复用组件、错误处理、批量操作、请求工具
  - `providers/` / `routes/`：provider 注册、状态管理与路由解析
  - `providers/branding.js`：provider 品牌色统一来源；腾讯云系模块统一蓝色，Cloudflare 系模块统一橙色，避免在各视图散落硬编码颜色
- **副作用契约**：DNS 自动同步 / 清理统一通过 `side_effects.dns` 返回，前端据此展示真实执行结果。
- **SaaS 同步策略**：saas 响应同时返回显式同步配置（`sync_*`）和有效同步配置（`effective_sync_*`），前端据此做运营可视化、手动重同步和批量重同步。
- **运营可见性**：provider 删除提示会展示占用关系；saas 支持单条/批量检查同步状态，以及单条/批量重同步。

更完整的开发与分层约定见：`ARCHITECTURE.md`

## 数据存储

不依赖数据库，使用文件存储：

- `data/config.json` — 登录账号密码
- `data/providers.json` — 各 provider 凭据
- `data/saas/preferences.json` — saas 偏好（境内优选 CNAME 等）
- `data/saas/preferred-domains.json` — 优选域名候选清单

所有 JSON 文件首次写入时自动创建，无需手动 mkdir。

## 鉴权

单用户明文鉴权 + session + captcha，凭据放在 `data/config.json`。修改账号密码直接改文件即可，无须重启。

## 缓存

provider / zone / record / 隧道列表统一走 ThinkPHP 缓存（默认 file 驱动），TTL 3 天，按 provider tag 失效。可在 `config/services.php` 调整 `cache_ttl`。隧道连接状态在详情页未连接时每 3 秒自动刷新，连接后停止；列表页与路由配置可手动刷新穿透缓存。
