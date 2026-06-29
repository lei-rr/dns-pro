# ARCHITECTURE

本文件是本项目的开发规范与架构约定。

目标不是追求教科书式分层，而是在当前项目规模下，保持：

- 边界清楚
- 职责稳定
- 代码可持续演进
- 不过度封装
- 不围绕单个功能无限优化

后续开发默认严格遵循本文件，除非有明确的系统级理由需要调整。

## 1. 总体原则

1. 先做系统架构优化，再做模块功能扩展。
2. 不为抽象而抽象，不为统一而统一。
3. 模块之间不允许横向直接耦合业务实现。
4. 能在当前层清楚表达的逻辑，不新增一层包装。
5. 发现结构性问题时，优先做局部重构，不继续堆补丁。
6. 新增复杂能力前，先判断是否会破坏现有边界。

## 2. 后端分层约定

当前后端统一采用以下层次：

1. `controller`
2. `controller/concerns`
3. `service`
4. `repository`
5. `support`

### 2.1 Controller 约定

`controller` 只负责：

- 路由参数读取
- 输入校验
- query 参数解析
- 调用 `service` / `workflow`
- 响应包装

`controller` 禁止负责：

- 多步业务编排
- 状态跃迁判断
- `auto_sync` / `auto_cleanup` 逻辑
- 副作用清理/同步
- provider 级缓存处理

### 2.2 Controller Concerns 约定

统一使用：

- `ResolvesQueryParams`
- `ValidatesInput`

`ValidatesInput` 是控制层唯一输入校验入口：

- `queryInput()` / `postInput()` / `putInput()`：返回 `checked()` 后的净化输入
- `rawQueryInput()` / `rawPostInput()` / `rawPutInput()`：先校验，再返回原始输入

规则：

1. 默认使用净化输入。
2. 只有在 service 需要保留未声明字段时，才使用 `raw*Input()`。
3. controller 不再手写 `validate(...)->scene(...)->checked(input(...))`。

## 3. Service 分层约定

`service` 分成三类：

1. 领域服务（Domain Service）
2. 编排服务（Workflow / Orchestration Service）
3. Provider Gateway

### 3.1 领域服务

领域服务负责：

- 单一业务域动作
- 资源定位
- 领域数据拼装
- 纯业务规则

领域服务不负责：

- 多步用例编排
- 副作用降级包装
- `auto_sync` / `auto_cleanup`

典型类：

- `HostnameService`
- `EdgeOneService`
- `ProviderService`
- `CloudflaredTunnelService`
- `CloudflaredRouteService`

### 3.2 Workflow / Orchestration Service

只有在满足下面两个条件时才新增 workflow：

1. 一个用例涉及多个 service 协作
2. 一个用例带有副作用、降级、同步、清理、状态跃迁

workflow 负责：

- 多步用例编排
- `auto_sync` / `auto_cleanup`
- 副作用容错与降级
- 清理前收集 / 清理后处理

workflow 不负责：

- provider 原始访问
- 本地持久化
- 单一领域动作本身

典型类：

- `HostnameWorkflowService`
- `EdgeOneWorkflowService`

### 3.3 Provider Gateway

所有真正承担外部 provider / SDK / API 访问职责的类，统一命名为 `*Gateway`。

当前统一命名：

- `CloudflareZoneGateway`
- `CloudflareDnsRecordGateway`
- `CloudflareCustomHostnameGateway`
- `DnsPodZoneGateway`
- `DnsPodRecordGateway`

Gateway 负责：

- provider API / SDK 调用
- provider 级缓存
- provider 返回结果映射

Gateway 不负责：

- `auto_sync` / `auto_cleanup`
- 业务编排
- workflow 降级语义

## 4. Repository 约定

所有本地持久化统一进入 `repository/`。

当前 repository：

- `ProviderRepository`
- `AppConfigRepository`
- `HostnamePreferenceRepository`
- `PreferredDomainRepository`

规则：

1. `service` 和 `support` 不直接 new `JsonStore`。
2. 新增任何本地文件数据源时，先建 repository，再由 service 调用。
3. repository 只负责本地数据访问，不负责业务编排。

## 5. Support 约定

`support/` 只放基础设施和共享技术能力：

- `JsonStore`
- `ApiResponse`
- `AuthSession`
- `ErrorMessages`
- `SideEffectResult`

禁止把业务模块逻辑塞入 `support/`。

## 6. 副作用结果契约约定

所有 workflow/service 对外暴露副作用结果时，统一使用：

```php
side_effects: {
  dns: {
    sync?: {
      status: 'completed' | 'skipped' | 'failed',
      message: string,
      details: array
    },
    cleanup?: {
      status: 'completed' | 'skipped' | 'failed',
      message: string,
      details: array
    }
  }
}
```

规则：

1. 不再新增 `dns_sync` / `dns_cleanup` / `dns_record` 这类旧字段。
2. `details` 放原始结构化结果。
3. `status` 必须真实反映执行结果，不能默认写成 `completed`。
4. 前端统一优先消费 `side_effects`。

## 7. 模块边界约定

业务模块：

- `hostname`
- `edgeone`
- `cloudflared`
- `cloudflare`
- `dnspod`
- `provider`

规则：

1. 业务模块之间不允许横向直接调用彼此业务实现。
2. 跨模块复用只能走：
- `support/`
- `service/concerns/`
- 中立支撑服务
- provider gateway
3. 不允许出现：
- `hostname -> edgeone`
- `cloudflared -> hostname`
- `edgeone -> cloudflared`

## 8. 前端架构约定

前端分三层：

1. `modules/`：业务模块
2. `shared/`：共享组件与工具
3. `providers/` / `routes/`：系统级共享状态与路由解析

规则：

1. 页面错误展示统一通过共享错误文案工具处理。
2. 不允许新代码直接写 `message.error(error)` 或 `message.error(error.message)`。
3. 使用：

```js
errorMessage(error)
```

4. 高风险页面必须做请求生命周期保护（latest-only token）。
5. route 切换时，旧弹窗和旧上下文必须及时关闭或失效。

## 9. 请求生命周期约定

对以下类型页面，必须做 latest-only 保护：

- provider/zone/domain/tunnel 切换后会触发异步加载的页面
- 多个异步请求可能交错返回的页面

当前做法：

- `loadRequestToken`
- `contextToken`
- `detailRequestToken`

规则：

1. 当前项目允许页面内部自己维护 token。
2. 只有在多处模式完全一致时，才允许再抽共享工具。
3. 在没有明显收益前，不为 token 模式单独再加抽象层。

## 10. 何时允许继续拆分

满足下列条件才允许继续拆 service / gateway / workflow：

1. 当前类已经同时承担两类以上稳定职责
2. 拆分后调用链更清楚，而不是更长
3. 拆分能减少跨模块耦合

禁止因为下面原因拆分：

1. 只是觉得文件有点长
2. 为了形式上“更像架构图”
3. 为了把每个模块都套成同样层数

## 11. 何时停止优化

当某块已经满足：

- controller 足够薄
- workflow 只承接编排
- domain service 只承接领域动作
- gateway 只承接 provider 访问
- repository 只承接本地持久化

就停止继续拆，避免系统臃肿。

## 12. 后续开发要求

后续新代码默认必须遵守：

1. 不新增绕过 repository 的本地文件访问
2. 不在 controller 中重新塞业务编排
3. 不再输出旧式 DNS 副作用字段
4. 不在前端直接使用不统一的错误提示写法
5. 不新增横向模块依赖
6. 不为了单个功能持续膨胀某个模块

如确需突破以上规则，必须先说明这是系统级调整，而不是局部便利性改动。
