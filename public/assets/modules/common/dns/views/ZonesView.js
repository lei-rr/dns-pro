import { dnsApi } from '../api.js'
import { loadProviders } from '../../../../providers/store.js'
import { providerPath } from '../../../../routes/paths.js'
import { resolveProviderAvatarColor, resolveProviderHook } from '../../../../providers/registry.js'
import { message, modal } from '../../../../shared/plugins/antDesignVue.js'
import ListToolbar from '../../../../shared/components/ListToolbar.js'
import { errorMessage } from '../../../../shared/utils/errors.js'
import { mergePaginationMeta, nextPaginationState, paginationState, tablePagination } from '../../../../shared/utils/pagination.js'
import { defaultProviderHook } from '../hook.js'

export default {
  components: { ListToolbar },
  props: { provider: String, providerMeta: Object },
  data() {
    return {
      zones: [],
      zoneMeta: paginationState(),
      currentProviderMeta: this.providerMeta || null,
      providerHook: defaultProviderHook,
      keyword: '',
      appliedKeyword: '',
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
      return this.zones
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
      this.zoneMeta = paginationState()
      this.keyword = ''
      this.appliedKeyword = ''
      this.showAddZone = false
      this.addZoneName = ''
      this.load()
    },
    providerMeta(value) { this.currentProviderMeta = value || null },
    keyword(value) {
      if (String(value || '').trim() === '' && this.appliedKeyword !== '') {
        this.applyKeyword()
      }
    },
  },
  methods: {
    routeBase() { return providerPath(this.provider) },
    zoneAvatar(zone) { return (String(zone || '').match(/[a-z0-9]/i)?.[0] || '#').toUpperCase() },
    avatarColor() { return resolveProviderAvatarColor(this.currentProviderMeta) },
    statusDefinition(key) { return this.statusDefinitions.find((item) => item.key === key) || null },
    zoneStatus(record, column) { return (column?.getStatus || ((item) => item.status || item.access_status || item.dns_status))(record) },
    statusColor(record, column) { return this.providerHook.zoneStatusColor(this.zoneStatus(record, column)) },
    statusText(record, column) { return this.providerHook.zoneStatusLabel(this.zoneStatus(record, column)) },
    applyKeyword() {
      const nextKeyword = this.keyword.trim()
      if (nextKeyword === this.appliedKeyword && this.zoneMeta.page === 1) return
      this.appliedKeyword = nextKeyword
      this.zoneMeta = { ...this.zoneMeta, page: 1 }
      this.load()
    },
    openAddZone() { this.addZoneName = ''; this.showAddZone = true },
    zoneRouteId(zone) { return zone.name },
    handleTableChange(pagination) {
      const next = nextPaginationState(this.zoneMeta, pagination)
      if (!next) return
      this.zoneMeta = next
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
      const summary = result.message || `域名 ${result.name || this.addZoneName.trim().toLowerCase()} 已添加`
      modal.info({
        title: '域名已添加',
        content: nameServers.length ? `${summary}\n\n请将域名 NS 修改为：\n${nameServers.join('\n')}` : `${summary}\n\n当前接口未返回 NS，请到 ${result.provider_name || this.providerName} 控制台查看应修改的 NS。`,
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
          keyword: this.appliedKeyword,
          ...options,
        })
        if (requestToken !== this.loadRequestToken) return
        this.providerHook = resolveProviderHook(this.currentProviderMeta?.type || this.provider)
        this.zones = zones.data
        this.zoneMeta = mergePaginationMeta(this.zoneMeta, zones.meta)
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
      <ListToolbar :title="providerName" subtitle="选择域名进入解析管理。" v-model:keyword="keyword" search-placeholder="搜索域名" @search="applyKeyword">
        <template #actions>
          <a-button :loading="loading" :disabled="deleting" @click="load({ refresh: true })">刷新</a-button>
          <a-button v-if="capabilities.createZone" type="primary" :disabled="loading || deleting" @click="openAddZone">添加域名</a-button>
        </template>
      </ListToolbar>
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
