import { cloudflaredApi } from '../utils/api.js'
import { statusLabel, statusColor } from '../utils/format.js'
import { providerPath } from '../../../routes/paths.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'
import CopyButton from '../../../shared/components/CopyButton.js'
import TunnelInstallPanel from '../components/TunnelInstallPanel.js'
import RouteFormModal from '../components/RouteFormModal.js'

const REFRESH_INTERVAL_MS = 3000

export default {
  components: { CopyButton, TunnelInstallPanel, RouteFormModal },
  props: ['provider', 'tunnelId'],
  data() {
    return {
      tunnel: null,
      token: '',
      config: null,
      zones: [],
      tokenError: '',
      routesError: '',
      zonesError: '',
      loading: true,
      loadingConfig: false,
      savingRoute: false,
      rotating: false,
      activeTab: 'overview',
      showRouteForm: false,
      editingRoute: null, // null=添加, object=编辑
      pollingTimer: null,
      contextToken: 0,
    }
  },
  computed: {
    backPath() { return providerPath(this.provider) },
    tunnelName() { return this.tunnel?.name || '' },
    tunnelStatus() { return this.tunnel?.status || 'inactive' },
    isConnected() { return this.tunnelStatus === 'healthy' || this.tunnelStatus === 'degraded' },
    replicaCount() { return (this.tunnel?.connections || []).filter((c) => !c.is_pending_reconnect).length },
    routes() { return this.config?.routes || [] },
    uptime() {
      const active = this.tunnel?.conns_active_at
      if (!active) return '-'
      const ms = Date.now() - new Date(active).getTime()
      if (ms < 0) return '-'
      const hours = Math.floor(ms / 3600000)
      const minutes = Math.floor((ms % 3600000) / 60000)
      if (hours > 24) return `${Math.floor(hours / 24)}天 ${hours % 24}小时`
      if (hours > 0) return `${hours}小时 ${minutes}分`
      return `${minutes}分钟`
    },
    connectionColumns() {
      return [
        { title: '连接器 ID', dataIndex: 'client_id', key: 'client_id', width: 280 },
        { title: '版本', dataIndex: 'client_version', key: 'client_version', width: 120 },
        { title: '数据中心', dataIndex: 'colo_name', key: 'colo_name', width: 100 },
        { title: '来源 IP', dataIndex: 'origin_ip', key: 'origin_ip', width: 140 },
        { title: '连接时间', dataIndex: 'opened_at', key: 'opened_at', width: 180 },
      ]
    },
    routeColumns() {
      return [
        { title: '公共主机名', dataIndex: 'hostname', key: 'hostname', width: 240 },
        { title: '服务', dataIndex: 'service', key: 'service', width: 240 },
        { title: '路径', key: 'path', width: 120 },
        { title: '操作', key: 'actions', width: 130, align: 'right' },
      ]
    },
  },
  async mounted() {
    await this.reloadContext()
  },
  beforeUnmount() {
    this.contextToken += 1
    this.stopPolling()
  },
  watch: {
    tunnelId() { this.reloadContext() },
    provider() { this.reloadContext() },
  },
  methods: {
    statusLabel,
    statusColor,

    async reloadContext() {
      const token = this.contextToken + 1
      this.contextToken = token
      this.stopPolling()
      this.tunnel = null
      this.token = ''
      this.config = null
      this.zones = []
      this.tokenError = ''
      this.routesError = ''
      this.zonesError = ''
      this.showRouteForm = false
      this.editingRoute = null
      await this.loadAll(token)
    },

    async loadAll(token = this.contextToken) {
      this.loading = true
      try {
        await Promise.all([this.loadTunnel(token), this.loadToken(token), this.loadRoutes(token), this.loadZones(token)])
        if (token !== this.contextToken) return
        // 刷新后按最新状态决定是否轮询（未连接则恢复轮询）
        this.startPolling()
      } finally {
        if (token === this.contextToken) this.loading = false
      }
    },

    async loadTunnel(token = this.contextToken) {
      try {
        const response = await cloudflaredApi.tunnel(this.provider, this.tunnelId, { refresh: true })
        if (token !== this.contextToken) return
        this.tunnel = response.data || null
        // 已连接则停止轮询（startPolling 内部已含同样判断，这里在轮询回调中即时生效）
        if (this.isConnected) {
          this.stopPolling()
        }
      } catch (error) {
        if (token !== this.contextToken) return
        message.error(errorMessage(error))
      }
    },

    async loadToken(token = this.contextToken) {
      try {
        const response = await cloudflaredApi.tunnelToken(this.provider, this.tunnelId)
        if (token !== this.contextToken) return
        this.token = response.data?.token || ''
        this.tokenError = ''
      } catch (error) {
        if (token !== this.contextToken) return
        this.tokenError = error.message || '无法获取安装命令'
      }
    },

    askRotateToken() {
      modal.confirm({
        title: '轮换令牌',
        content: '轮换后当前令牌立即失效，所有已连接的副本会断开，需用新令牌重新安装/启动。确认轮换？',
        okText: '轮换', okType: 'danger', cancelText: '取消',
        onOk: () => this.rotateToken(),
      })
    },

    async rotateToken() {
      this.rotating = true
      try {
        const response = await cloudflaredApi.rotateToken(this.provider, this.tunnelId)
        this.token = response.data?.token || ''
        message.success('令牌已轮换，请用新令牌更新所有副本')
        // 旧副本会断开，恢复轮询以反映最新连接状态
        this.startPolling()
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.rotating = false
      }
    },

    async loadRoutes(token = this.contextToken) {
      this.loadingConfig = true
      try {
        const response = await cloudflaredApi.routes(this.provider, this.tunnelId)
        if (token !== this.contextToken) return
        this.config = response.data || null
        this.routesError = ''
      } catch (error) {
        if (token !== this.contextToken) return
        this.routesError = error.message || '无法加载路由配置'
      } finally {
        if (token === this.contextToken) this.loadingConfig = false
      }
    },

    async loadZones(token = this.contextToken) {
      try {
        const response = await cloudflaredApi.zones(this.provider)
        if (token !== this.contextToken) return
        this.zones = response.data || []
        this.zonesError = ''
      } catch (error) {
        if (token !== this.contextToken) return
        this.zonesError = error.message || '无法加载 Cloudflare 站点列表'
      }
    },

    startPolling() {
      this.stopPolling()
      // 仅在未连接时轮询；已连接无需自动刷新
      if (this.isConnected) return
      this.pollingTimer = setInterval(() => this.loadTunnel(this.contextToken), REFRESH_INTERVAL_MS)
    },
    stopPolling() {
      if (this.pollingTimer) { clearInterval(this.pollingTimer); this.pollingTimer = null }
    },

    openAddRoute() { this.editingRoute = null; this.showRouteForm = true },
    openEditRoute(route) { this.editingRoute = { ...route }; this.showRouteForm = true },

    notifyDnsOperation(operation, successFallback) {
      if (!operation) {
        message.success(successFallback)
        return
      }

      const text = operation.message || successFallback
      if (operation.status === 'failed') {
        message.warning(text)
        return
      }
      if (operation.status === 'skipped') {
        message.warning(text)
        return
      }

      message.success(text)
    },

    async saveRoute(form) {
      this.savingRoute = true
      try {
        let response = null
        if (this.editingRoute) {
          response = await cloudflaredApi.updateRoute(
            this.provider,
            this.tunnelId,
            form,
            this.editingRoute.hostname,
            this.editingRoute.path || '',
          )
          this.notifyDnsOperation(response?.data?.side_effects?.dns?.sync, '路由已更新')
        } else {
          response = await cloudflaredApi.addRoute(this.provider, this.tunnelId, form)
          this.notifyDnsOperation(response?.data?.side_effects?.dns?.sync, '路由已添加')
        }
        this.showRouteForm = false
        this.editingRoute = null
        await this.loadRoutes()
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.savingRoute = false
      }
    },

    askDeleteRoute(route) {
      modal.confirm({
        title: '删除路由',
        content: `确认删除 ${route.hostname}${route.path ? ' (' + route.path + ')' : ''} 的路由？`,
        okText: '删除', okType: 'danger', cancelText: '取消',
        onOk: () => this.removeRoute(route),
      })
    },

    async removeRoute(route) {
      try {
        const zone = this.matchZone(route.hostname)
        const response = await cloudflaredApi.deleteRoute(this.provider, this.tunnelId, route.hostname, route.path || '', zone?.id || '')
        this.notifyDnsOperation(response?.data?.side_effects?.dns?.cleanup, '路由已删除')
        await this.loadRoutes()
      } catch (error) {
        message.error(errorMessage(error))
      }
    },

    matchZone(hostname) {
      const sorted = [...this.zones].sort((a, b) => (b.name?.length || 0) - (a.name?.length || 0))
      return sorted.find((zone) => hostname === zone.name || hostname.endsWith('.' + zone.name)) || null
    },

    formatDate(value) {
      if (!value) return '-'
      return new Date(value).toLocaleString('zh-CN')
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-button type="link" style="padding: 0" @click="$router.push(backPath)">返回隧道列表</a-button>
          <a-typography-title :level="3" style="margin: 4px 0">{{ tunnelName || tunnelId }}</a-typography-title>
          <a-space>
            <a-tag :color="statusColor(tunnelStatus)">{{ statusLabel(tunnelStatus) }}</a-tag>
            <a-typography-text type="secondary">Cloudflare Tunnel</a-typography-text>
          </a-space>
        </div>
        <div class="page-actions">
          <a-button :loading="loading" @click="loadAll">刷新</a-button>
        </div>
      </div>

      <a-spin :spinning="loading && !tunnel">
        <a-tabs v-model:active-key="activeTab">
          <a-tab-pane key="overview" tab="概览">
            <a-row :gutter="16" style="margin-bottom: 24px">
              <a-col :xs="12" :sm="6">
                <a-card size="small" :body-style="{ height: '88px', display: 'flex', flexDirection: 'column', justifyContent: 'center' }">
                  <a-typography-text type="secondary">活动副本</a-typography-text>
                  <a-typography-title :level="3" style="margin: 4px 0 0">{{ replicaCount }}</a-typography-title>
                </a-card>
              </a-col>
              <a-col :xs="12" :sm="6">
                <a-card size="small" :body-style="{ height: '88px', display: 'flex', flexDirection: 'column', justifyContent: 'center' }">
                  <a-typography-text type="secondary">路由</a-typography-text>
                  <a-typography-title :level="3" style="margin: 4px 0 0">{{ routes.length }}</a-typography-title>
                </a-card>
              </a-col>
              <a-col :xs="12" :sm="6">
                <a-card size="small" :body-style="{ height: '88px', display: 'flex', flexDirection: 'column', justifyContent: 'center' }">
                  <a-typography-text type="secondary">状态</a-typography-text>
                  <div style="margin-top: 8px"><a-tag :color="statusColor(tunnelStatus)">{{ statusLabel(tunnelStatus) }}</a-tag></div>
                </a-card>
              </a-col>
              <a-col :xs="12" :sm="6">
                <a-card size="small" :body-style="{ height: '88px', display: 'flex', flexDirection: 'column', justifyContent: 'center' }">
                  <a-typography-text type="secondary">运行时间</a-typography-text>
                  <a-typography-title :level="5" style="margin: 4px 0 0">{{ uptime }}</a-typography-title>
                </a-card>
              </a-col>
            </a-row>

            <template v-if="(tunnel?.connections || []).length > 0">
              <a-typography-title :level="5">副本</a-typography-title>
              <a-table :columns="connectionColumns" :data-source="tunnel.connections" :row-key="r => r.id" :pagination="false" size="small" style="margin-bottom: 24px" />
            </template>

            <a-card size="small" style="margin-bottom: 24px">
              <template #title>安装 cloudflared 连接器</template>
              <a-typography-text v-if="!isConnected" type="secondary" style="display: block; margin-bottom: 12px">
                要激活此隧道，请在服务器上安装 cloudflared 连接器。每个连接器会创建一个副本，并与 Cloudflare 的网络建立 4 个连接以实现高可用性。
              </a-typography-text>
              <a-alert v-else type="success" show-icon message="客户端已连接" style="margin-bottom: 12px" />
              <a-alert v-if="tokenError" type="error" show-icon :message="tokenError" style="margin-bottom: 12px" />
              <TunnelInstallPanel v-if="token" :token="token" />
              <a-empty v-else description="无法获取安装命令" />
            </a-card>

            <a-card size="small">
              <template #title>隧道详情</template>
              <a-descriptions :column="1" bordered size="small">
                <a-descriptions-item label="名称">{{ tunnelName }}</a-descriptions-item>
                <a-descriptions-item label="隧道 ID"><a-space size="small"><a-typography-text code>{{ tunnelId }}</a-typography-text><CopyButton :value="tunnelId" /></a-space></a-descriptions-item>
                <a-descriptions-item label="类型">cloudflared</a-descriptions-item>
                <a-descriptions-item label="创建时间">{{ formatDate(tunnel?.created_at) }}</a-descriptions-item>
              </a-descriptions>
            </a-card>

            <a-card size="small" style="margin-top: 24px">
              <template #title>轮换令牌</template>
              <a-typography-text type="secondary" style="display: block; margin-bottom: 12px">
                刷新隧道令牌以使当前令牌失效并生成新令牌。这将需要使用新令牌更新所有副本实例。
              </a-typography-text>
              <a-button danger :loading="rotating" @click="askRotateToken">轮换令牌</a-button>
            </a-card>
          </a-tab-pane>

          <a-tab-pane key="routes" tab="路由">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px">
              <a-typography-text type="secondary">公共主机名到本地服务的映射</a-typography-text>
              <a-button type="primary" :disabled="!isConnected" @click="openAddRoute">添加路由</a-button>
            </div>
            <a-alert v-if="!isConnected" type="warning" show-icon message="隧道未连接，请先安装客户端后再配置路由" style="margin-bottom: 16px" />
            <a-alert v-if="routesError" type="error" show-icon :message="routesError" style="margin-bottom: 16px" />
            <a-alert v-else-if="zonesError" type="warning" show-icon :message="zonesError" style="margin-bottom: 16px" />
            <a-table :columns="routeColumns" :data-source="routes" :row-key="r => r.hostname + (r.path || '')" :loading="loadingConfig" :pagination="false" size="middle" :locale="{ emptyText: '暂无路由' }">
              <template #bodyCell="{ column, record }">
                <template v-if="column.key === 'path'">{{ record.path || '/' }}</template>
                <template v-else-if="column.key === 'actions'">
                  <a-space size="small">
                    <a style="cursor: pointer" @click="openEditRoute(record)">编辑</a>
                    <a-divider type="vertical" />
                    <a class="ant-typography ant-typography-danger" style="cursor: pointer" @click="askDeleteRoute(record)">删除</a>
                  </a-space>
                </template>
              </template>
            </a-table>
          </a-tab-pane>
        </a-tabs>
      </a-spin>

      <RouteFormModal
        v-model:open="showRouteForm"
        :confirm-loading="savingRoute"
        :zones="zones"
        :initial-route="editingRoute"
        @submit="saveRoute"
      />
    </section>
  `,
}
