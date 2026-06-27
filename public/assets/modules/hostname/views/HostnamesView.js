import { hostnameApi, preferredDomainApi } from '../utils/api.js'
import { statusColor, statusLabel, formatDate } from '../utils/hostname.js'
import { loadProviders } from '../../../providers/store.js'
import { providerPath } from '../../../routes/paths.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'
import { tablePagination } from '../../../shared/utils/pagination.js'
import HostnameCreateModal from '../components/HostnameCreateModal.js'
import HostnameDetailModal from '../components/HostnameDetailModal.js'
import HostnameFallbackOriginModal from '../components/HostnameFallbackOriginModal.js'
import PreferredDomainsModal from '../components/PreferredDomainsModal.js'

export default {
  components: { HostnameCreateModal, HostnameDetailModal, HostnameFallbackOriginModal, PreferredDomainsModal },
  props: ['provider', 'zoneName'],
  data() {
    return {
      hostnames: [],
      loading: true,
      notFound: false,
      creating: false,
      deleting: false,
      refreshing: {},
      providerMeta: null,
      selectedHostname: null,
      showCreateForm: false,
      showDetails: false,
      preferredDomains: [],
      showPreferredManager: false,
      showFallbackOrigin: false,
    }
  },
  computed: {
    decodedZoneName() { return decodeURIComponent(this.zoneName) },
    zonesPath() { return providerPath(this.provider) },
    dnspodLinked() { return Boolean(this.providerMeta?.dnspod_provider) },
    /**
     * 当前 zone 下已使用过的回源服务器（去重，供新增/编辑表单下拉复用）
     */
    originSuggestions() {
      const seen = new Set()
      const list = []
      for (const h of this.hostnames) {
        const v = String(h?.custom_origin_server || '').trim()
        if (v !== '' && !seen.has(v)) {
          seen.add(v)
          list.push({ value: v })
        }
      }
      return list
    },
    columns() {
      const cols = [
        { title: '自定义主机名', dataIndex: 'hostname', key: 'hostname', width: 240 },
        { title: '证书状态', key: 'ssl_status', width: 100 },
        { title: '到期日期', key: 'expires_on', width: 110 },
        { title: '主机名状态', key: 'status', width: 100 },
        { title: '源服务器', key: 'custom_origin_server', width: 200 },
        { title: '操作', key: 'actions', width: 110, align: 'right' },
      ]
      return cols
    },
    pagination() { return tablePagination() },
  },
  async mounted() { await this.load(); this.loadPreferredDomains() },
  watch: {
    provider() { this.providerMeta = null; this.load(); this.loadPreferredDomains() },
    zoneName() { this.load() },
  },
  methods: {
    statusColor,
    statusLabel,
    formatDate,

    hostnameAvatar(hostname) {
      return (String(hostname || '').match(/[a-z0-9]/i)?.[0] || '#').toUpperCase()
    },

    async load(options = {}) {
      this.loading = true
      try {
        this.notFound = false
        if (!this.providerMeta) {
          const providers = await loadProviders()
          this.providerMeta = providers.find((p) => p.id === this.provider) || null
        }
        const response = await hostnameApi.hostnames(this.provider, this.decodedZoneName, { page: 1, per_page: 100, ...options })
        this.hostnames = response.data
      } catch (error) {
        if (Number(error.status) === 404 || error.code === 'cloudflare_zone_not_found') {
          this.hostnames = []
          this.notFound = true
          return
        }
        message.error(error.message)
      } finally {
        this.loading = false
      }
    },

    async loadPreferredDomains() {
      try {
        const response = await preferredDomainApi.list()
        this.preferredDomains = response.data || []
      } catch (error) {
        // 静默失败：不阻塞主流程，下拉为空即可
        this.preferredDomains = []
      }
    },

    openPreferredManager() { this.showPreferredManager = true },
    onPreferredUpdate(items) { this.preferredDomains = items || [] },

    openFallbackOrigin() { this.showFallbackOrigin = true },
    onFallbackUpdated() {
      // 默认回源变了,刷新 hostname 列表(那些没自定义源的 hostname 实际源服务器跟着变)
      this.load({ refresh: true })
    },

    // ----- 手动刷新（带提示） -----
    async handleRefresh() {
      await this.load({ refresh: true })
      message.success('已刷新')
    },

    // ----- 创建 -----
    openCreate() { this.showCreateForm = true },
    async create(formData) {
      this.creating = true
      try {
        const payload = {
          hostname: String(formData.hostname || '').trim(),
          method: String(formData.method || 'txt').trim(),
          min_tls_version: String(formData.min_tls_version || '1.0').trim(),
        }
        if (formData.use_custom_origin_server) {
          payload.custom_origin_server = String(formData.custom_origin_server || '').trim()
        }
        const preferred = String(formData.preferred_domain || '').trim()
        if (preferred) {
          payload.preferred_domain = preferred
        }
        if (!payload.hostname) {
          message.warning('请输入主机名')
          return
        }

        const options = { autoSync: formData.autoSync && this.dnspodLinked }
        const response = await hostnameApi.createHostname(this.provider, this.decodedZoneName, payload, options)
        message.success('自定义主机名已创建')
        this.selectedHostname = response.data
        this.showCreateForm = false
        this.showDetails = true
        await this.load({ refresh: true })
      } catch (error) {
        message.error(error.message)
      } finally {
        this.creating = false
      }
    },

    // ----- 详情 / 刷新 -----
    async openDetails(record) {
      // 详情走缓存，避免每次都拉 Cloudflare API（zone idByName + show）
      // 用户主动需要最新数据时点"刷新状态"
      try {
        const response = await hostnameApi.hostname(this.provider, this.decodedZoneName, record.hostname)
        this.selectedHostname = response.data
        this.showDetails = true
      } catch (error) {
        message.error(error.message)
      }
    },
    async refreshHostname(record) {
      this.refreshing = { ...this.refreshing, [record.id]: true }
      try {
        const response = await hostnameApi.refreshHostname(this.provider, this.decodedZoneName, record.hostname)
        this.mergeHostnameRecord(response.data)
        // 只在详情已经打开且就是同一条 hostname 时才更新详情内容,不主动弹出详情
        if (this.showDetails && this.selectedHostname?.id === record.id) {
          this.selectedHostname = response.data
        }
        const cleaned = Number(response.data?.dns_cleanup?.cleaned || 0)
        message.success(cleaned > 0 ? '已刷新,已自动清理TXT验证' : '已刷新')
      } catch (error) {
        message.error(error.message)
      } finally {
        this.refreshing = { ...this.refreshing, [record.id]: false }
      }
    },

    // ----- 删除 -----
    askDelete(record) {
      modal.confirm({
        title: '删除自定义主机名',
        content: `确认删除 ${record.hostname}？关联的 DNSPod 记录会一并清理。`,
        okText: '删除', okType: 'danger', cancelText: '取消',
        onOk: () => this.deleteHostname(record),
      })
    },
    async deleteHostname(record) {
      this.deleting = true
      try {
        await hostnameApi.deleteHostname(this.provider, this.decodedZoneName, record.hostname)
        message.success('已删除')
        if (this.selectedHostname?.id === record.id) {
          this.selectedHostname = null
          this.showDetails = false
        }
        await this.load({ refresh: true })
      } catch (error) {
        message.error(error.message)
      } finally {
        this.deleting = false
      }
    },

    mergeHostnameRecord(updated) {
      if (!updated?.id) return
      const index = this.hostnames.findIndex((item) => item.id === updated.id)
      if (index >= 0) this.hostnames.splice(index, 1, { ...this.hostnames[index], ...updated })
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-button type="link" style="padding: 0" @click="$router.push(zonesPath)">返回站点</a-button>
          <a-typography-title :level="3" style="margin: 4px 0">{{ decodedZoneName }}</a-typography-title>
          <a-typography-text type="secondary">Cloudflare for SaaS 自定义主机名列表</a-typography-text>
        </div>
        <div class="page-actions">
          <a-button :loading="loading" @click="handleRefresh">刷新</a-button>
          <a-button :disabled="notFound" @click="openFallbackOrigin">默认回源</a-button>
          <a-button v-if="dnspodLinked" @click="openPreferredManager">优选域名</a-button>
          <a-button type="primary" :disabled="notFound" @click="openCreate">新增主机名</a-button>
        </div>
      </div>

      <a-result v-if="notFound" status="404" title="站点不存在或不可访问" :sub-title="decodedZoneName">
        <template #extra><a-button type="primary" @click="$router.push(zonesPath)">返回站点</a-button></template>
      </a-result>

      <a-table
        v-else
        :columns="columns"
        :data-source="hostnames"
        :row-key="record => record.id || record.hostname"
        :loading="loading"
        :pagination="pagination"
        size="middle"
        :scroll="{ x: 1000 }"
        :locale="{ emptyText: '暂无自定义主机名' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'hostname'">
            <a-space size="small">
              <a-avatar size="small" style="background: #722ed1">{{ hostnameAvatar(record.hostname) }}</a-avatar>
              <a @click="openDetails(record)">{{ record.hostname }}</a>
            </a-space>
          </template>
          <template v-else-if="column.key === 'ssl_status'">
            <a-tag :color="statusColor(record.ssl?.status)">{{ statusLabel(record.ssl?.status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'expires_on'">
            {{ formatDate(record.ssl?.expires_on) }}
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'custom_origin_server'">
            <div class="origin-cell">
              <a-tag v-if="!record.custom_origin_server" color="blue">默认回源</a-tag>
              <a-typography-text v-else :ellipsis="{ tooltip: record.custom_origin_server }" style="max-width: 140px; display: inline-block;">
                {{ record.custom_origin_server }}
              </a-typography-text>
            </div>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space size="small">
              <a-button type="link" size="small" @click="openDetails(record)">详情</a-button>
              <a-dropdown>
                <a-button type="link" size="small">更多</a-button>
                <template #overlay>
                  <a-menu>
                    <a-menu-item danger @click="askDelete(record)">删除</a-menu-item>
                  </a-menu>
                </template>
              </a-dropdown>
            </a-space>
          </template>
        </template>
      </a-table>

      <hostname-create-modal
        v-model:open="showCreateForm"
        :confirm-loading="creating"
        :dnspod-linked="dnspodLinked"
        :origin-suggestions="originSuggestions"
        :preferred-domains="preferredDomains"
        @submit="create"
      />
      <hostname-detail-modal
        v-model:open="showDetails"
        :hostname="selectedHostname"
        :refreshing="refreshing[selectedHostname?.id] || false"
        @refresh="refreshHostname"
      />
      <preferred-domains-modal
        v-model:open="showPreferredManager"
        @update="onPreferredUpdate"
      />
      <hostname-fallback-origin-modal
        v-model:open="showFallbackOrigin"
        :provider="provider"
        :zone-name="decodedZoneName"
        @updated="onFallbackUpdated"
      />
    </section>
  `,
}
