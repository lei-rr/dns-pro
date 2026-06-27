import { dnsApi } from '../api.js'
import { loadProviders } from '../../../../providers/store.js'
import { providerPath } from '../../../../routes/paths.js'
import { resolveProviderAvatarColor, resolveProviderHook } from '../../../../providers/registry.js'
import { message, modal } from '../../../../shared/plugins/antDesignVue.js'
import { tablePagination } from '../../../../shared/utils/pagination.js'
import { defaultProviderHook } from '../hook.js'

export default {
  props: { provider: String, providerMeta: Object },
  data() {
    return { zones: [], currentProviderMeta: this.providerMeta || null, providerHook: defaultProviderHook, keyword: '', loading: true, adding: false, deleting: false, showAddZone: false, addZoneName: '' }
  },
  computed: {
    providerName() { return this.currentProviderMeta?.name || this.provider },
    capabilities() { return this.providerHook.capabilities || defaultProviderHook.capabilities },
    filteredZones() {
      const keyword = this.keyword.trim().toLowerCase()
      if (!keyword) return this.zones
      return this.zones.filter((zone) => zone.name.toLowerCase().includes(keyword))
    },
    columns() {
      return [
        { title: '域名', dataIndex: 'name', key: 'name', width: 320 },
        { title: '服务商', key: 'provider', width: 140 },
        { title: '状态', key: 'status', width: 120, responsive: ['sm'] },
        { title: '操作', key: 'actions', width: 120, align: 'right' },
      ]
    },
    pagination() { return tablePagination() },
  },
  async mounted() { await this.load() },
  watch: {
    provider() {
      this.currentProviderMeta = this.providerMeta || null
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
    zoneStatus(record) { return record.access_status || record.dns_status || record.status },
    statusColor(record) { return this.providerHook.zoneStatusColor(this.zoneStatus(record)) },
    statusText(record) { return this.providerHook.zoneStatusLabel(this.zoneStatus(record)) },
    openAddZone() { this.addZoneName = ''; this.showAddZone = true },
    zoneRouteId(zone) { return zone.name },
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
        message.error(error.message)
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
        message.error(error.message)
      } finally {
        this.deleting = false
      }
    },
    async load(options = {}) {
      this.loading = true
      try {
        if (!this.currentProviderMeta) {
          const providers = await loadProviders()
          this.currentProviderMeta = providers.find((provider) => provider.id === this.provider) || null
        }
        const zones = await dnsApi.zones(this.provider, options)
        this.providerHook = resolveProviderHook(this.currentProviderMeta?.type || this.provider)
        this.zones = zones.data
        if (options.refresh) message.success('已刷新')
      } catch (error) {
        message.error(error.message)
      } finally {
        this.loading = false
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
      <a-table :columns="columns" :data-source="filteredZones" :row-key="zone => zone.provider + zone.name" :loading="loading" :pagination="pagination" size="middle" :scroll="{ x: 680 }" :locale="{ emptyText: '暂无域名' }">
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'"><a-space><a-avatar size="small" :style="{ background: avatarColor() }">{{ zoneAvatar(record.name) }}</a-avatar><router-link :to="routeBase() + '/' + encodeURIComponent(zoneRouteId(record))">{{ record.name }}</router-link></a-space></template>
          <template v-else-if="column.key === 'provider'"><a-tag>{{ providerName }}</a-tag></template>
          <template v-else-if="column.key === 'status'"><a-tag :color="statusColor(record)">{{ statusText(record) }}</a-tag></template>
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
