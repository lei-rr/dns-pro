import { edgeOneApi } from '../utils/api.js'
import { providerChildPath } from '../../../routes/paths.js'
import { message } from '../../../shared/plugins/antDesignVue.js'
import { tablePagination } from '../../../shared/utils/pagination.js'

export default {
  props: ['provider'],
  data() {
    return { zones: [], keyword: '', loading: true }
  },
  computed: {
    filteredZones() {
      const keyword = this.keyword.trim().toLowerCase()
      if (!keyword) return this.zones
      return this.zones.filter((zone) => zone.name.toLowerCase().includes(keyword) || zone.id.toLowerCase().includes(keyword))
    },
    columns() {
      return [
        { title: '站点', dataIndex: 'name', key: 'name', width: 320 },
        { title: '服务商', key: 'provider', width: 140 },
        { title: '站点 ID', dataIndex: 'id', key: 'id', width: 180, responsive: ['md'] },
        { title: '服务区域', key: 'area', width: 100, responsive: ['sm'] },
        { title: '接入方式', key: 'type', width: 120 },
        { title: '状态', key: 'status', width: 120 },
        { title: '操作', key: 'actions', width: 100, align: 'right' },
      ]
    },
    pagination() { return tablePagination() },
  },
  async mounted() {
    await this.load()
  },
  watch: {
    provider() {
      this.keyword = ''
      this.load()
    },
  },
  methods: {
    async load(options = {}) {
      this.loading = true
      try {
        const response = await edgeOneApi.zones(this.provider, options)
        this.zones = response.data
        if (options.refresh) message.success('已刷新')
      } catch (error) {
        message.error(error.message)
      } finally {
        this.loading = false
      }
    },
    zoneAvatar(zone) {
      return (String(zone || '').match(/[a-z0-9]/i)?.[0] || 'E').toUpperCase()
    },
    areaLabel(value) {
      return ({ global: '全球', mainland: '中国大陆', overseas: '海外' })[value] || value || '-'
    },
    typeLabel(value) {
      return ({ full: 'NS 接入', partial: 'CNAME 接入', noDomainAccess: '无域名接入', dnsPodAccess: 'DNSPod 托管', pages: 'Pages', ai: '边缘推理' })[value] || value || '-'
    },
    activeStatusLabel(value) {
      return ({ active: '已启用', inactive: '未生效', paused: '已停用' })[value] || value || '-'
    },
    zonePath(zoneName) {
      return providerChildPath(this.provider, zoneName)
    },
    zoneRoute(zone) {
      return this.zonePath(zone.name)
    },
    statusColor(status) {
      if (['active', 'online', 'enable', 'normal'].includes(status)) return 'green'
      if (['process', 'pending', 'initializing', 'init', 'plan_migrate'].includes(status)) return 'gold'
      if (['paused', 'offline', 'inactive'].includes(status)) return 'default'
      if (['deactivated', 'isolated', 'destroyed', 'disable'].includes(status)) return 'red'
      return status ? 'red' : 'default'
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-typography-title :level="3" style="margin-bottom: 4px">EdgeOne</a-typography-title>
          <a-typography-text type="secondary">选择站点进入安全加速域名管理。</a-typography-text>
        </div>
        <div class="page-actions">
          <a-input-search v-model:value="keyword" placeholder="搜索站点" allow-clear />
          <a-button :loading="loading" @click="load({ refresh: true })">刷新</a-button>
        </div>
      </div>
      <a-table
        :columns="columns"
        :data-source="filteredZones"
        :row-key="zone => zone.id"
        :loading="loading"
        :pagination="pagination"
        size="middle"
        :scroll="{ x: 820 }"
        :locale="{ emptyText: '暂无 EdgeOne 站点' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <a-space>
              <a-avatar size="small" style="background: #722ed1">{{ zoneAvatar(record.name) }}</a-avatar>
              <router-link :to="zoneRoute(record)">{{ record.name }}</router-link>
            </a-space>
          </template>
          <template v-else-if="column.key === 'provider'">
            <a-tag>EdgeOne</a-tag>
          </template>
          <template v-else-if="column.key === 'id'">
            <a-typography-text :ellipsis="{ tooltip: record.id }" style="max-width: 160px">{{ record.id }}</a-typography-text>
          </template>
          <template v-else-if="column.key === 'area'">{{ areaLabel(record.area) }}</template>
          <template v-else-if="column.key === 'type'">{{ typeLabel(record.type) }}</template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.active_status || record.status)">{{ activeStatusLabel(record.active_status || record.status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'actions'">
            <router-link :to="zoneRoute(record)">管理</router-link>
          </template>
        </template>
      </a-table>
    </section>
  `,
}
