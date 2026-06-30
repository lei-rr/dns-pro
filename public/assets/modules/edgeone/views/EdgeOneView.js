import { edgeOneApi } from '../utils/api.js'
import { providerAvatarColor } from '../../../providers/branding.js'
import { providerChildPath } from '../../../routes/paths.js'
import ListToolbar from '../../../shared/components/ListToolbar.js'
import { message } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'
import { mergePaginationMeta, nextPaginationState, paginationState, tablePagination } from '../../../shared/utils/pagination.js'

export default {
  components: { ListToolbar },
  props: ['provider'],
  data() {
    return { zones: [], zoneMeta: paginationState(), keyword: '', loading: true, loadRequestToken: 0 }
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
    pagination() {
      return tablePagination({
        current: this.zoneMeta.page || 1,
        pageSize: this.zoneMeta.per_page || 20,
        total: this.zoneMeta.total || 0,
      })
    },
  },
  async mounted() {
    await this.load()
  },
  watch: {
    provider() {
      this.zoneMeta = paginationState()
      this.keyword = ''
      this.load()
    },
  },
  methods: {
    handleTableChange(pagination) {
      const next = nextPaginationState(this.zoneMeta, pagination)
      if (!next) return
      this.zoneMeta = next
      this.load()
    },
    async load(options = {}) {
      const requestToken = this.loadRequestToken + 1
      this.loadRequestToken = requestToken
      this.loading = true
      try {
        const response = await edgeOneApi.zones(this.provider, {
          page: this.zoneMeta.page,
          per_page: this.zoneMeta.per_page,
          ...options,
        })
        if (requestToken !== this.loadRequestToken) return
        this.zones = response.data
        this.zoneMeta = mergePaginationMeta(this.zoneMeta, response.meta)
        if (options.refresh) message.success('已刷新')
      } catch (error) {
        if (requestToken !== this.loadRequestToken) return
        message.error(errorMessage(error))
      } finally {
        if (requestToken === this.loadRequestToken) this.loading = false
      }
    },
    zoneAvatar(zone) {
      return (String(zone || '').match(/[a-z0-9]/i)?.[0] || 'E').toUpperCase()
    },
    zoneAvatarColor() {
      return providerAvatarColor('edgeone')
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
    zonePath(zone) {
      return providerChildPath(this.provider, zone.id)
    },
    zoneRoute(zone) {
      return this.zonePath(zone)
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
      <ListToolbar title="EdgeOne" subtitle="选择站点进入安全加速域名管理。" v-model:keyword="keyword" search-placeholder="搜索站点">
        <template #actions>
          <a-button :loading="loading" @click="load({ refresh: true })">刷新</a-button>
        </template>
      </ListToolbar>
      <a-table
        :columns="columns"
        :data-source="filteredZones"
        :row-key="zone => zone.id"
        :loading="loading"
        :pagination="pagination"
        size="middle"
        :scroll="{ x: 820 }"
        :locale="{ emptyText: '暂无 EdgeOne 站点' }"
        @change="handleTableChange"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <a-space>
              <a-avatar size="small" :style="{ background: zoneAvatarColor() }">{{ zoneAvatar(record.name) }}</a-avatar>
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
