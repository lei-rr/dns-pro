import { preferredDomainApi, saasApi } from '../utils/api.js'
import { dnsApi } from '../../common/dns/api.js'
import { statusColor, statusLabel, formatDate } from '../utils/saas.js'
import { providerAvatarColor } from '../../../providers/branding.js'
import { loadProviders } from '../../../providers/store.js'
import { providerPath } from '../../../routes/paths.js'
import ListToolbar from '../../../shared/components/ListToolbar.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'
import BatchToolbar from '../../../shared/components/BatchToolbar.js'
import { errorMessage } from '../../../shared/utils/errors.js'
import { showBatchFailures } from '../../../shared/utils/batch.js'
import { tablePagination } from '../../../shared/utils/pagination.js'
import SaasCreateModal from '../components/SaasCreateModal.js'
import SaasDetailModal from '../components/SaasDetailModal.js'
import SaasFallbackOriginModal from '../components/SaasFallbackOriginModal.js'
import PreferredDomainsModal from '../components/PreferredDomainsModal.js'

export default {
  components: { BatchToolbar, ListToolbar, SaasCreateModal, SaasDetailModal, SaasFallbackOriginModal, PreferredDomainsModal },
  props: ['provider', 'zoneName'],
  data() {
    return {
      hostnames: [],
      loading: true,
      notFound: false,
      selectedHostnames: [],
      selectionResetKey: 0,
      creating: false,
      editingHostname: null,
      savingEdit: false,
      deleting: false,
      deletingText: '',
      detailLoading: false,
      detailRequestToken: 0,
      listLoadRequestToken: 0,
      preferredLoadRequestToken: 0,
      syncZoneLoadRequestToken: 0,
      refreshing: {},
      providerMeta: null,
      allProviders: [],
      dnspodZones: [],
      cloudflareDnsZones: [],
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
    hostnameCloudflareProvider() {
      const providerId = this.providerMeta?.cloudflare_provider || ''
      if (!providerId) return null
      return this.allProviders.find((provider) => provider.id === providerId && provider.type === 'cloudflare' && provider.configured) || null
    },
    dnspodProviders() {
      return this.allProviders.filter((provider) => provider.type === 'dnspod' && provider.configured)
    },
    cloudflareProviders() {
      return this.hostnameCloudflareProvider ? [this.hostnameCloudflareProvider] : []
    },
    dnspodLinked() { return this.dnspodProviders.length > 0 },
    cloudflareDnsLinked() { return this.cloudflareProviders.length > 0 },
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
        { title: '同步配置', key: 'sync', width: 220 },
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
    provider() { this.resetContextState(); this.providerMeta = null; this.dnspodZones = []; this.cloudflareDnsZones = []; this.load(); this.loadPreferredDomains() },
    zoneName() { this.resetContextState(); this.load() },
  },
  methods: {
    statusColor,
    statusLabel,
    formatDate,

    hostnameAvatar(hostname) {
      return (String(hostname || '').match(/[a-z0-9]/i)?.[0] || '#').toUpperCase()
    },

    hostnameAvatarColor() {
      return providerAvatarColor('saas')
    },

    resetContextState() {
      this.showCreateForm = false
      this.showDetails = false
      this.showFallbackOrigin = false
      this.editingHostname = null
      this.selectedHostname = null
      this.selectedHostnames = []
      this.selectionResetKey += 1
      this.handleDetailsOpenChange(false)
    },
    clearSelection() {
      this.selectedHostnames = []
      this.selectionResetKey += 1
    },

    async load(options = {}) {
      const requestToken = this.listLoadRequestToken + 1
      this.listLoadRequestToken = requestToken
      this.loading = true
      try {
        this.notFound = false
        if (!this.providerMeta) {
          const providers = await loadProviders()
          if (requestToken !== this.listLoadRequestToken) return
          this.allProviders = providers || []
          this.providerMeta = providers.find((p) => p.id === this.provider) || null
          await this.loadSyncZones(requestToken)
        }
        if (requestToken !== this.listLoadRequestToken) return
        const response = await saasApi.hostnames(this.provider, this.decodedZoneName, {
          page: 1,
          per_page: 100,
          ...options,
        })
        if (requestToken !== this.listLoadRequestToken) return
        this.hostnames = response.data
        this.syncSelectedHostnameFromList()
      } catch (error) {
        if (requestToken !== this.listLoadRequestToken) return
        if (Number(error.status) === 404 || error.code === 'cloudflare_zone_not_found') {
          this.hostnames = []
          this.notFound = true
          return
        }
        message.error(errorMessage(error))
      } finally {
        if (requestToken === this.listLoadRequestToken) this.loading = false
      }
    },

    async loadSyncZones(requestToken = this.listLoadRequestToken) {
      const zoneRequestToken = this.syncZoneLoadRequestToken + 1
      this.syncZoneLoadRequestToken = zoneRequestToken

      try {
        const [dnspodZones, cloudflareDnsZones] = await Promise.all([
          this.loadZonesForProviders(this.dnspodProviders),
          this.loadZonesForProviders(this.cloudflareProviders),
        ])

        if (requestToken !== this.listLoadRequestToken || zoneRequestToken !== this.syncZoneLoadRequestToken) return
        this.dnspodZones = dnspodZones
        this.cloudflareDnsZones = cloudflareDnsZones
      } catch {
        if (requestToken !== this.listLoadRequestToken || zoneRequestToken !== this.syncZoneLoadRequestToken) return
        this.dnspodZones = []
        this.cloudflareDnsZones = []
      }
    },

    async loadZonesForProvider(providerId) {
      const zones = []
      let page = 1

      while (true) {
        const response = await dnsApi.zones(providerId, { page, per_page: 100, refresh: page === 1 })
        zones.push(...(response.data || []))
        const totalPages = Number(response.meta?.total_pages || 1)
        if (page >= totalPages) break
        page += 1
      }

      return zones
    },

    async loadZonesForProviders(providers) {
      const map = {}
      for (const provider of providers || []) {
        map[provider.id] = await this.loadZonesForProvider(provider.id)
      }
      return map
    },

    async loadPreferredDomains() {
      const requestToken = this.preferredLoadRequestToken + 1
      this.preferredLoadRequestToken = requestToken
      try {
        const response = await preferredDomainApi.list()
        if (requestToken !== this.preferredLoadRequestToken) return
        this.preferredDomains = response.data || []
      } catch (error) {
        if (requestToken !== this.preferredLoadRequestToken) return
        // 静默失败：不阻塞主流程，下拉为空即可
        this.preferredDomains = []
      }
    },

    openPreferredManager() { this.showPreferredManager = true },
    onPreferredUpdate(items) { this.preferredDomains = items || [] },

    dnsOperationMessage(operation, fallback) {
      if (!operation) return fallback
      return operation.message || fallback
    },

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
    openCreate() { this.editingHostname = null; this.showCreateForm = true },
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
        if (formData.sync_target) payload.sync_target = String(formData.sync_target).trim()
        if (formData.sync_provider_id) payload.sync_provider_id = String(formData.sync_provider_id).trim()
        if (formData.sync_zone) payload.sync_zone = String(formData.sync_zone).trim()
        payload.auto_preferred = !!formData.autoPreferred
        if (!payload.hostname) {
          message.warning('请输入主机名')
          return
        }

        const options = { autoSync: !!formData.sync_target }
        const response = await saasApi.createHostname(this.provider, this.decodedZoneName, payload, options)
        message.success(this.dnsOperationMessage(response?.data?.side_effects?.dns?.sync, '自定义主机名已创建'))
        this.showCreateForm = false
        await this.load({ refresh: true })
        await this.openDetails(response.data)
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.creating = false
      }
    },

    openEdit(record) {
      this.handleDetailsOpenChange(false)
      this.editingHostname = record
      this.showCreateForm = true
    },
    async update(formData) {
      if (!this.editingHostname?.hostname) return

      this.savingEdit = true
      try {
        const payload = {
          method: String(formData.method || 'txt').trim(),
          min_tls_version: String(formData.min_tls_version || '1.0').trim(),
          custom_origin_server: formData.use_custom_origin_server
            ? String(formData.custom_origin_server || '').trim()
            : '',
          preferred_domain: String(formData.preferred_domain || '').trim(),
          sync_target: String(formData.sync_target || '').trim(),
          sync_provider_id: String(formData.sync_provider_id || '').trim(),
          sync_zone: String(formData.sync_zone || '').trim(),
          auto_preferred: !!formData.autoPreferred,
        }

        const options = { autoSync: !!formData.sync_target }
        const response = await saasApi.updateHostname(
          this.provider,
          this.decodedZoneName,
          this.editingHostname.hostname,
          payload,
          options,
        )
        message.success(this.dnsOperationMessage(response?.data?.side_effects?.dns?.sync, '自定义主机名已更新'))
        this.showCreateForm = false
        this.editingHostname = null
        this.mergeHostnameRecord(response.data)
        await this.load({ refresh: true })
        if (this.showDetails && this.selectedHostname?.id === response.data?.id) {
          await this.openDetails(response.data)
        }
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.savingEdit = false
      }
    },

    // ----- 详情 / 刷新 -----
    async openDetails(record) {
      // 详情走缓存，避免每次都拉 Cloudflare API（zone idByName + show）
      // 用户主动需要最新数据时点"刷新状态"
      const requestToken = this.detailRequestToken + 1
      this.detailRequestToken = requestToken
      this.selectedHostname = {
        ...record,
        ssl: { ...(record?.ssl || {}) },
      }
      this.showDetails = true
      this.detailLoading = true

      try {
        const response = await saasApi.hostname(this.provider, this.decodedZoneName, record.hostname)
        if (requestToken !== this.detailRequestToken) return
        this.selectedHostname = response.data
        this.mergeHostnameRecord(response.data)
      } catch (error) {
        if (requestToken !== this.detailRequestToken) return
        message.error(errorMessage(error))
      } finally {
        if (requestToken === this.detailRequestToken) this.detailLoading = false
      }
    },
    async refreshHostname(record) {
      this.refreshing = { ...this.refreshing, [record.id]: true }
      try {
        const response = await saasApi.refreshHostname(this.provider, this.decodedZoneName, record.hostname)
        this.mergeHostnameRecord(response.data)
        // 只在详情已经打开且就是同一条 hostname 时才更新详情内容,不主动弹出详情
        if (this.showDetails && this.selectedHostname?.id === record.id) {
          this.selectedHostname = response.data
        }
        const cleaned = Number(response.data?.side_effects?.dns?.cleanup?.details?.cleaned || 0)
        message.success(cleaned > 0 ? '已刷新,已自动清理TXT验证' : '已刷新')
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.refreshing = { ...this.refreshing, [record.id]: false }
      }
    },
    syncConfigText(record) {
      const target = record.effective_sync_target || record.sync_target || ''
      const providerId = record.effective_sync_provider_id || record.sync_provider_id || ''
      const zone = record.effective_sync_zone || record.sync_zone || ''
      if (!target) return '未配置'
      const targetLabel = target === 'cloudflare_dns' ? 'Cloudflare DNS' : 'DNSPod'
      return [targetLabel, providerId, zone].filter(Boolean).join(' / ')
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
    askBatchDelete() {
      if (!this.selectedHostnames.length) return
      const total = this.selectedHostnames.length
      let dialog = null
      dialog = modal.confirm({
        title: '批量删除自定义主机名',
        content: this.batchDeleteConfirmContent(total),
        okText: '批量删除',
        okType: 'danger',
        cancelText: '取消',
        onOk: () => this.batchDeleteHostnames(dialog, total),
      })
    },
    batchDeleteConfirmContent(total) {
      const base = `确认删除已选的 ${total} 个自定义主机名？关联的 DNS 记录会按可用情况清理。`
      return Vue.h('div', { style: 'white-space: pre-wrap' }, this.deletingText ? `${base}\n\n${this.deletingText}` : base)
    },
    updateBatchDeleteDialog(dialog, total) {
      dialog?.update?.({
        content: this.batchDeleteConfirmContent(total),
        cancelButtonProps: { disabled: this.deleting },
      })
    },
    isCurrentHostname(record) {
      if (!record || !this.selectedHostname) return false
      const currentId = this.selectedHostname.id
      const recordId = record.id
      if (currentId && recordId) return currentId === recordId

      const currentHostname = String(this.selectedHostname.hostname || '').trim().toLowerCase()
      const recordHostname = String(record.hostname || '').trim().toLowerCase()
      return currentHostname !== '' && currentHostname === recordHostname
    },
    async deleteHostname(record) {
      this.deleting = true
      try {
        const response = await saasApi.deleteHostname(this.provider, this.decodedZoneName, record.hostname)
        message.success(this.dnsOperationMessage(response?.data?.side_effects?.dns?.cleanup, '已删除'))
        if (this.isCurrentHostname(record)) {
          this.selectedHostname = null
          this.showDetails = false
        }
        this.clearSelection()
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.deleting = false
      }
    },
    async batchDeleteHostnames(dialog, total) {
      this.deleting = true
      const failed = []
      const deletingCurrent = this.selectedHostnames.some((record) => this.isCurrentHostname(record))
      try {
        this.deletingText = '正在删除 0/' + total
        this.updateBatchDeleteDialog(dialog, total)
        const records = [...this.selectedHostnames]
        for (const [index, record] of records.entries()) {
          this.deletingText = `正在删除 ${index + 1}/${records.length}`
          this.updateBatchDeleteDialog(dialog, total)
          try {
            await saasApi.deleteHostname(this.provider, this.decodedZoneName, record.hostname)
          } catch (error) {
            failed.push(`${record.hostname}: ${error.message}`)
          }
        }
        if (failed.length) showBatchFailures('批量删除完成', failed, '个')
        else message.success('批量删除完成')
        if (deletingCurrent) {
          this.selectedHostname = null
          this.showDetails = false
        }
        this.clearSelection()
        await this.load({ refresh: true })
      } finally {
        this.deleting = false
        this.deletingText = ''
      }
    },

    mergeHostnameRecord(updated) {
      if (!updated?.id) return
      const index = this.hostnames.findIndex((item) => item.id === updated.id)
      if (index >= 0) this.hostnames.splice(index, 1, { ...this.hostnames[index], ...updated })
    },

    syncSelectedHostnameFromList() {
      if (!this.selectedHostname) return
      const selectedId = this.selectedHostname.id
      const selectedName = String(this.selectedHostname.hostname || '').toLowerCase()
      const updated = this.hostnames.find((item) => (
        (selectedId && item.id === selectedId)
        || String(item.hostname || '').toLowerCase() === selectedName
      ))
      if (!updated) return

      this.selectedHostname = {
        ...this.selectedHostname,
        ...updated,
        ssl: { ...(this.selectedHostname.ssl || {}), ...(updated.ssl || {}) },
      }
    },

    handleDetailsOpenChange(open) {
      this.showDetails = open
      if (!open) {
        this.detailRequestToken += 1
        this.detailLoading = false
      }
    },
  },
  template: `
    <section>
      <ListToolbar back-text="返回站点" :title="decodedZoneName" subtitle="Cloudflare for SaaS 自定义主机名列表" @back="$router.push(zonesPath)">
        <template #actions>
          <a-button :loading="loading" @click="handleRefresh">刷新</a-button>
          <a-button :disabled="notFound" @click="openFallbackOrigin">默认回源</a-button>
          <a-button v-if="dnspodLinked" @click="openPreferredManager">优选域名</a-button>
          <a-button type="primary" :disabled="notFound" @click="openCreate">新增主机名</a-button>
        </template>
      </ListToolbar>
      <BatchToolbar :count="selectedHostnames.length" :deleting="deleting" delete-text="批量删除" @delete="askBatchDelete" @clear="clearSelection" />
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
        :row-selection="{ selectedRowKeys: selectedHostnames.map(item => item.id || item.hostname), onChange: (_keys, rows) => selectedHostnames = rows }"
        size="middle"
        :scroll="{ x: 1000 }"
        :locale="{ emptyText: '暂无自定义主机名' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'hostname'">
            <a-space size="small">
              <a-avatar size="small" :style="{ background: hostnameAvatarColor() }">{{ hostnameAvatar(record.hostname) }}</a-avatar>
              <a @click="openDetails(record)">{{ record.hostname }}</a>
            </a-space>
          </template>
          <template v-else-if="column.key === 'sync'">
            <a-typography-text :ellipsis="{ tooltip: syncConfigText(record) }" style="max-width: 200px; display: inline-block;">
              {{ syncConfigText(record) }}
            </a-typography-text>
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
                      <a-menu-item @click="openEdit(record)">编辑</a-menu-item>
                      <a-menu-item danger @click="askDelete(record)">删除</a-menu-item>
                    </a-menu>
                </template>
              </a-dropdown>
            </a-space>
          </template>
        </template>
      </a-table>

      <saas-create-modal
        :open="showCreateForm"
        :title="editingHostname ? '编辑自定义主机名' : '新增自定义主机名'"
        :ok-text="editingHostname ? '保存' : '创建'"
        :confirm-loading="editingHostname ? savingEdit : creating"
        :dnspod-linked="dnspodLinked"
        :cloudflare-dns-linked="cloudflareDnsLinked"
        :dnspod-providers="dnspodProviders"
        :cloudflare-dns-providers="cloudflareProviders"
        :dnspod-zones="dnspodZones"
        :cloudflare-dns-zones="cloudflareDnsZones"
        :origin-suggestions="originSuggestions"
        :preferred-domains="preferredDomains"
        :initial-value="editingHostname"
        :editing="!!editingHostname"
        @update:open="value => { showCreateForm = value; if (!value) editingHostname = null }"
        @submit="editingHostname ? update($event) : create($event)"
      />
      <saas-detail-modal
        :open="showDetails"
        :hostname="selectedHostname"
        :loading="detailLoading"
        :refreshing="refreshing[selectedHostname?.id] || false"
        @update:open="handleDetailsOpenChange"
        @edit="openEdit"
        @refresh="refreshHostname"
      />
      <preferred-domains-modal
        v-model:open="showPreferredManager"
        @update="onPreferredUpdate"
      />
      <saas-fallback-origin-modal
        v-model:open="showFallbackOrigin"
        :provider="provider"
        :zone-name="decodedZoneName"
        @updated="onFallbackUpdated"
      />
    </section>
  `,
}
