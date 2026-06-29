import { dnsApi } from '../api.js'
import { loadProviders } from '../../../../providers/store.js'
import { providerPath } from '../../../../routes/paths.js'
import { resolveProviderAvatarColor, resolveProviderHook } from '../../../../providers/registry.js'
import { message, modal } from '../../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../../shared/utils/errors.js'
import { tablePagination } from '../../../../shared/utils/pagination.js'
import { defaultProviderHook } from '../hook.js'

export default {
  props: { provider: String, providerMeta: Object },
  data() {
    return {
      zones: [],
      zoneMeta: { page: 1, per_page: 20, total: 0 },
      currentProviderMeta: this.providerMeta || null,
      providerHook: defaultProviderHook,
      keyword: '',
      loading: true,
      adding: false,
      deleting: false,
      showAddZone: false,
      addZoneName: '',
      loadRequestToken: 0,
    }
  },
  computed: {
    providerName() { return this.currentProviderMeta?.name || this.provider },
    capabilities() { return this.providerHook.capabilities || defaultProviderHook.capabilities },
    filteredZones() {
      const keyword = this.keyword.trim().toLowerCase()
      if (!keyword) return this.zones
      return this.zones.filter((zone) => zone.name.toLowerCase().includes(keyword))
    },
    statusColumns() {
      return (this.providerHook.zoneStatusColumns || defaultProviderHook.zoneStatusColumns).map((column) => ({
        title: column.title,
        key: column.key,
        width: column.width || 120,
        responsive: ['sm'],
      }))
    },
    statusDefinitions() {
      return this.providerHook.zoneStatusColumns || defaultProviderHook.zoneStatusColumns
    },
    columns() {
      return [
        { title: '域名', dataIndex: 'name', key: 'name', width: 320 },
        { title: '服务商', key: 'provider', width: 140 },
        ...this.statusColumns,
        { title: '操作', key: 'actions', width: 120, align: 'right' },
      ]
    },
    pagination() {
      return tablePagination({
        current: this.zoneMeta.page || 1,
        pageSize: this.zoneMeta.per_page || 20,
        total: this.zoneMeta.total || 0,
      })
    },
  },
  async mounted() { await this.load() },
  watch: {
    provider() {
      this.currentProviderMeta = this.providerMeta || null
      this.zoneMeta = { page: 1, per_page: 20, total: 0 }
      this.keyword = ''
      this.showAddZone = false
      this.addZoneName = ''
      this.load()
    },
    providerMeta(value) { this.currentProviderMeta = value || null },
  },
  methods: {
    routeBase() { return providerPath(this.provider) },
    zoneAvatar(zone) { return (String(zone || '').match(/[a-z0-9]/i)?.[0] || '#').toUpperCase() },
    avatarColor() { return resolveProviderAvatarColor(this.currentProviderMeta) },
    statusDefinition(key) { return this.statusDefinitions.find((item) => item.key === key) || null },
    zoneStatus(record, column) { return (column?.getStatus || ((item) => item.status || item.access_status || item.dns_status))(record) },
    statusColor(record, column) { return this.providerHook.zoneStatusColor(this.zoneStatus(record, column)) },
    statusText(record, column) { return this.providerHook.zoneStatusLabel(this.zoneStatus(record, column)) },
    openAddZone() { this.addZoneName = ''; this.showAddZone = true },
    zoneRouteId(zone) { return zone.name },
    handleTableChange(pagination) {
      const nextPerPage = Number(pagination?.pageSize) || this.zoneMeta.per_page || 20
      const pageSizeChanged = nextPerPage !== this.zoneMeta.per_page
      const nextPage = pageSizeChanged ? 1 : (Number(pagination?.current) || 1)
      if (nextPage === this.zoneMeta.page && nextPerPage === this.zoneMeta.per_page) return
      this.zoneMeta = { ...this.zoneMeta, page: nextPage, per_page: nextPerPage }
      this.load()
    },
    async createZone() {
      const zone = this.addZoneName.trim().toLowerCase()
      if (!zone) { message.error('请输入域名'); return }
      this.adding = true
      try {
        const response = await dnsApi.createZone(this.provider, { domain: zone })
        this.showAddZone = false
        await this.load({ refresh: true })
        this.showCreateResult(response.data)
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.adding = false
      }
    },
    showCreateResult(result) {
      const nameServers = result.name_servers || []
      modal.info({
        title: '域名已添加',
        content: nameServers.length ? `${result.message}\n\n请将域名 NS 修改为：\n${nameServers.join('\n')}` : `${result.message}\n\n当前接口未返回 NS，请到 ${result.provider_name || this.providerName} 控制台查看应修改的 NS。`,
        okText: '知道了',
      })
    },
    askRemove(zone) {
      modal.confirm({ title: '删除域名托管', content: `确认从 ${this.providerName} 删除 ${zone.name}？这会删除服务商中的域名托管和解析记录，不会删除注册商里的域名。若 NS 仍指向该服务商，解析可能中断。`, okText: '删除', okType: 'danger', cancelText: '取消', onOk: () => this.remove(zone) })
    },
    async remove(zone) {
      this.deleting = true
      try {
        await dnsApi.deleteZone(this.provider, this.zoneRouteId(zone))
        message.success('域名已删除')
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.deleting = false
      }
    },
    async load(options = {}) {
      const requestToken = this.loadRequestToken + 1
      this.loadRequestToken = requestToken
      this.loading = true
      try {
        if (!this.currentProviderMeta) {
          const providers = await loadProviders()
          if (requestToken !== this.loadRequestToken) return
          this.currentProviderMeta = providers.find((provider) => provider.id === this.provider) || null
        }
        if (requestToken !== this.loadRequestToken) return
        const zones = await dnsApi.zones(this.provider, {
          page: this.zoneMeta.page,
          per_page: this.zoneMeta.per_page,
          ...options,
        })
        if (requestToken !== this.loadRequestToken) return
        this.providerHook = resolveProviderHook(this.currentProviderMeta?.type || this.provider)
        this.zones = zones.data
        this.zoneMeta = {
          page: zones.meta?.page || this.zoneMeta.page,
          per_page: zones.meta?.per_page || this.zoneMeta.per_page,
          total: zones.meta?.total || 0,
        }
        if (options.refresh) message.success('已刷新')
      } catch (error) {
        if (requestToken !== this.loadRequestToken) return
        message.error(errorMessage(error))
      } finally {
        if (requestToken === this.loadRequestToken) this.loading = false
      }
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-typography-title :level="3" style="margin-bottom: 4px">{{ providerName }}</a-typography-title>
          <a-typography-text type="secondary">选择域名进入解析管理。</a-typography-text>
        </div>
        <div class="page-actions">
          <a-input-search v-model:value="keyword" placeholder="搜索域名" allow-clear />
          <a-button :loading="loading" :disabled="deleting" @click="load({ refresh: true })">刷新</a-button>
          <a-button v-if="capabilities.createZone" type="primary" :disabled="loading || deleting" @click="openAddZone">添加域名</a-button>
        </div>
      </div>
      <a-table :columns="columns" :data-source="filteredZones" :row-key="zone => zone.provider + zone.name" :loading="loading" :pagination="pagination" size="middle" :scroll="{ x: 820 }" :locale="{ emptyText: '暂无域名' }" @change="handleTableChange">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><a-space><a-avatar size="small" :style="{ background: avatarColor() }">{{ zoneAvatar(record.name) }}</a-avatar><router-link :to="routeBase() + '/' + encodeURIComponent(zoneRouteId(record))">{{ record.name }}</router-link></a-space></template>
          <template v-else-if="column.key === 'provider'"><a-tag>{{ providerName }}</a-tag></template>
          <template v-else-if="statusColumns.some(item => item.key === column.key)"><a-tag :color="statusColor(record, statusDefinition(column.key))">{{ statusText(record, statusDefinition(column.key)) }}</a-tag></template>
          <template v-else-if="column.key === 'actions'"><a-space size="small"><router-link :to="routeBase() + '/' + encodeURIComponent(zoneRouteId(record))">管理</router-link><a-dropdown v-if="capabilities.deleteZone"><a-button type="link" size="small" style="padding: 0">更多</a-button><template #overlay><a-menu><a-menu-item danger @click="askRemove(record)">删除</a-menu-item></a-menu></template></a-dropdown></a-space></template>
        </template>
      </a-table>
      <a-modal v-model:open="showAddZone" title="添加域名" :confirm-loading="adding" ok-text="添加" cancel-text="取消" @ok="createZone">
        <a-alert type="info" show-icon style="margin-bottom: 16px" message="添加域名后，还需要到域名注册商处修改 NS。NS 生效前解析可能不会生效。" />
        <a-form layout="vertical"><a-form-item label="域名" required><a-input v-model:value="addZoneName" placeholder="example.com" @pressEnter="createZone" /></a-form-item></a-form>
      </a-modal>
    </section>
  `,
}
