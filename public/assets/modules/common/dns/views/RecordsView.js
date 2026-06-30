import { dnsApi } from '../api.js'
import { loadProviders } from '../../../../providers/store.js'
import { message, modal } from '../../../../shared/plugins/antDesignVue.js'
import { providerPath } from '../../../../routes/paths.js'
import { resolveProviderHook } from '../../../../providers/registry.js'
import ListToolbar from '../../../../shared/components/ListToolbar.js'
import { chooseJsonFile, downloadJson } from '../../../../shared/utils/files.js'
import { errorMessage } from '../../../../shared/utils/errors.js'
import { mergePaginationMeta, nextPaginationState, paginationState, tablePagination } from '../../../../shared/utils/pagination.js'
import BatchToolbar from '../../../../shared/components/BatchToolbar.js'
import RecordForm from '../components/RecordForm.js'
import RecordTable from '../components/RecordTable.js'
import { defaultProviderHook } from '../hook.js'
import { showBatchFailures } from '../../../../shared/utils/batch.js'

export default {
  components: { BatchToolbar, ListToolbar, RecordForm, RecordTable },
  props: { provider: String, domain: String, providerMeta: Object },
  data() {
    return {
      records: [],
      recordMeta: paginationState(),
      selectedRecords: [],
      selectionResetKey: 0,
      lines: [],
      currentProviderMeta: this.providerMeta || null,
      currentDomainName: '',
      providerHook: defaultProviderHook,
      editing: null,
      showForm: false,
      keyword: '',
      appliedKeyword: '',
      loading: true,
      saving: false,
      deleting: false,
      deletingText: '',
      importMode: 'create',
      showImportConfirm: false,
      pendingImportRecords: [],
      loadRequestToken: 0,
    }
  },
  computed: {
    decodedDomain() { return decodeURIComponent(this.domain) },
    providerType() { return this.currentProviderMeta?.type || this.records[0]?.provider_type || this.provider },
    displayDomain() { return this.currentDomainName || this.decodedDomain },
    recordsTarget() { return this.decodedDomain },
    capabilities() { return this.providerHook.capabilities || defaultProviderHook.capabilities },
    typeOptions() {
      return [...new Set(this.records.map((record) => record.type).filter(Boolean))].sort().map((type) => ({ label: type, value: type }))
    },
    importPreviewText() {
      const preview = this.pendingImportRecords.slice(0, 8).map((record, index) => `${index + 1}. ${record.name || '@'} ${record.type || '-'} ${record.value || ''}`).join('\n')
      return this.pendingImportRecords.length > 8
        ? `${preview}\n... 另有 ${this.pendingImportRecords.length - 8} 条`
        : preview
    },
    pagination() {
      return tablePagination({
        current: this.recordMeta.page || 1,
        pageSize: this.recordMeta.per_page || 20,
        total: this.recordMeta.total || 0,
        defaultPageSize: 20,
      })
    },
    filteredRecords() {
      return this.records
    },
  },
  async mounted() { await this.load() },
  watch: {
    provider() { this.currentProviderMeta = this.providerMeta || null; this.resetAndLoad() },
    domain() { this.resetAndLoad() },
    providerMeta(value) { this.currentProviderMeta = value || null },
    keyword(value) {
      if (String(value || '').trim() === '' && this.appliedKeyword !== '') {
        this.applyKeyword()
      }
    },
  },
  methods: {
    routeBase() { return providerPath(this.provider) },
    resetAndLoad() {
      this.clearSelection()
      this.editing = null
      this.showForm = false
      this.recordMeta = paginationState()
      this.keyword = ''
      this.appliedKeyword = ''
      this.currentDomainName = ''
      this.load()
    },
    applyKeyword() {
      const nextKeyword = this.keyword.trim()
      if (nextKeyword === this.appliedKeyword && this.recordMeta.page === 1) return
      this.appliedKeyword = nextKeyword
      this.recordMeta = { ...this.recordMeta, page: 1, total: 0 }
      this.load()
    },
    handleTableChange(pagination) {
      const next = nextPaginationState(this.recordMeta, pagination)
      if (!next) return
      this.recordMeta = next
      this.load()
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
        this.currentDomainName = this.decodedDomain
        const records = await dnsApi.records(this.provider, this.recordsTarget, {
          page: this.recordMeta.page,
          per_page: this.recordMeta.per_page,
          keyword: this.appliedKeyword,
          ...options,
        })
        if (requestToken !== this.loadRequestToken) return
        this.records = records.data
        this.recordMeta = mergePaginationMeta(this.recordMeta, records.meta)
        this.providerHook = resolveProviderHook(this.providerType)
        this.lines = this.providerHook.recordLines || []
      } catch (error) {
        if (requestToken !== this.loadRequestToken) return
        if (this.shouldReturnToDomains(error)) {
          await this.returnToDomains()
          return
        }
        message.error(errorMessage(error))
      } finally {
        if (requestToken === this.loadRequestToken) this.loading = false
      }
    },
    shouldReturnToDomains(error) {
      // 用稳定的 error.code 判断,不依赖 message 字符串
      const notFoundCodes = [
        'provider_not_found',
        'provider_not_configured',
        'cloudflare_zone_not_found',
        'dnspod_zone_not_found',
        'edgeone_zone_not_found',
      ]
      return notFoundCodes.includes(error.code)
    },
    async returnToDomains() {
      const path = providerPath(this.provider)
      message.warning('当前域名未添加解析，已返回域名列表。')
      await this.$router.replace(path).catch(() => {})
      if (this.$route.path !== path) window.location.hash = '#' + path
    },
    edit(record) { this.editing = { ...record }; this.showForm = true },
    create() { this.editing = null; this.showForm = true },
    clearSelection() { this.selectedRecords = []; this.selectionResetKey += 1 },
    async handleRefresh() {
      await this.load({ refresh: true })
      message.success('已刷新')
    },
    async save(form) {
      this.saving = true
      try {
        const recordOptions = { zoneName: this.displayDomain }
        if (form.id) await dnsApi.updateRecord(this.provider, this.recordsTarget, form.id, form, recordOptions)
        else await dnsApi.createRecord(this.provider, this.recordsTarget, form, recordOptions)
        message.success(form.id ? '记录已更新' : '记录已添加')
        this.showForm = false
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.saving = false
      }
    },
    askRemove(record) {
      modal.confirm({
        title: '删除解析记录',
        content: `确认删除 ${record.name} · ${record.type}？删除后将立即同步到云服务商。`,
        okText: '删除',
        okType: 'danger',
        cancelText: '取消',
        onOk: () => this.remove(record),
      })
    },
    askBatchRemove() {
      if (!this.selectedRecords.length) return
      modal.confirm({
        title: '批量删除解析记录',
        content: `确认删除已选的 ${this.selectedRecords.length} 条解析记录？删除后将立即同步到云服务商。`,
        okText: '批量删除',
        okType: 'danger',
        cancelText: '取消',
        onOk: () => this.batchRemove(),
      })
    },
    async remove(record) {
      this.deleting = true
      try {
        await dnsApi.deleteRecord(this.provider, this.recordsTarget, record.id)
        message.success('已删除')
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.deleting = false
        this.deletingText = ''
      }
    },
    async batchRemove() {
      this.deleting = true
      const failed = []
      try {
        const records = [...this.selectedRecords]
        for (const [index, record] of records.entries()) {
          this.deletingText = `正在删除 ${index + 1}/${records.length}`
          try {
            await dnsApi.deleteRecord(this.provider, this.recordsTarget, record.id)
          } catch (error) {
            failed.push(`${record.name} ${record.type}: ${error.message}`)
          }
        }
        if (failed.length) showBatchFailures('批量删除完成', failed)
        else message.success('批量删除完成')
        this.selectedRecords = []
        await this.load({ refresh: true })
      } finally {
        this.deleting = false
        this.deletingText = ''
      }
    },
    exportRecords() {
      const payload = this.records.map(({ id, provider, provider_type, provider_name, ...record }) => record)
      downloadJson(`${this.decodedDomain}-records.json`, payload)
    },
    async importRecords() {
      try {
        const records = await chooseJsonFile()
        if (!records) return
        if (!Array.isArray(records)) throw new Error('导入文件必须是记录数组')
        this.pendingImportRecords = records
        this.importMode = 'create'
        this.showImportConfirm = true
      } catch (error) {
        message.error(errorMessage(error))
      }
    },
    async loadAllRecordsForImport() {
      const all = []
      let page = 1
      const perPage = 20

      while (true) {
        const response = await dnsApi.records(this.provider, this.recordsTarget, { page, per_page: perPage, refresh: page === 1 })
        all.push(...(response.data || []))
        const totalPages = Number(response.meta?.total_pages || 1)
        if (page >= totalPages) break
        page += 1
      }

      return all
    },
    importMatchKey(record) {
      const type = String(record.type || record.record_type || '').toUpperCase()
      const name = String(record.name || record.subdomain || '@').trim().toLowerCase()
      const line = String(record.line || record.record_line || '默认').trim()
      return `${name}__${type}__${line}`
    },
    async confirmImport() {
      const records = this.pendingImportRecords
      if (!records.length) return
      this.showImportConfirm = false
      await this.batchImport(records, this.importMode)
    },
    async batchImport(records, mode = 'create') {
      this.saving = true
      const failed = []
      try {
        const existingRecords = mode === 'overwrite' ? await this.loadAllRecordsForImport() : []
        const existingMap = new Map(existingRecords.map((record) => [this.importMatchKey(record), record]))

        for (const [index, record] of records.entries()) {
          this.deletingText = `正在导入 ${index + 1}/${records.length}`
          try {
            const key = this.importMatchKey(record)
            const existing = mode === 'overwrite' ? existingMap.get(key) : null
            if (existing?.id) {
              const updated = await dnsApi.updateRecord(this.provider, this.recordsTarget, existing.id, { ...existing, ...record }, { zoneName: this.displayDomain })
              existingMap.set(key, { ...(updated?.data || existing), ...record, id: updated?.data?.id || existing.id })
            } else {
              const created = await dnsApi.createRecord(this.provider, this.recordsTarget, record, { zoneName: this.displayDomain })
              existingMap.set(key, { ...record, id: created?.data?.id || '' })
            }
          } catch (error) {
            failed.push(`第 ${index + 1} 条 ${record.name || ''} ${record.type || ''}: ${error.message}`)
          }
        }
        if (failed.length) showBatchFailures('导入完成', failed)
        else message.success('导入完成')
        await this.load({ refresh: true })
      } finally {
        this.saving = false
        this.deletingText = ''
        this.pendingImportRecords = []
      }
    },
  },
  template: `
    <section>
      <ListToolbar back-text="返回域名" :title="displayDomain" subtitle="解析记录" v-model:keyword="keyword" search-placeholder="搜索记录" @back="$router.push(routeBase())" @search="applyKeyword">
        <template #actions>
          <a-button v-if="capabilities.importRecords" :disabled="saving || deleting" @click="importRecords">导入</a-button>
          <a-button v-if="capabilities.exportRecords" :disabled="!records.length" @click="exportRecords">导出</a-button>
          <a-button :loading="loading" :disabled="saving || deleting" @click="handleRefresh">刷新</a-button>
          <a-button type="primary" :disabled="saving || deleting" @click="create">添加记录</a-button>
        </template>
      </ListToolbar>
      <a-alert v-if="deletingText" type="warning" show-icon style="margin-bottom: 16px" :message="deletingText" />
      <BatchToolbar :count="selectedRecords.length" :deleting="deleting" delete-text="批量删除" @delete="askBatchRemove" @clear="clearSelection" />
      <RecordTable
        :records="filteredRecords"
        :provider-hook="providerHook"
        :loading="loading"
        :pagination="pagination"
        :selection-reset-key="selectionResetKey"
        :type-options="typeOptions"
        empty-text="暂无匹配的解析记录"
        @edit="edit"
        @delete="askRemove"
        @change="handleTableChange"
        @selection-change="selectedRecords = $event"
      />
      <a-modal v-model:open="showForm" :title="editing ? '编辑解析记录' : '添加解析记录'" :footer="null" destroy-on-close>
        <RecordForm :model-value="editing" :saving="saving" :provider-hook="providerHook" :lines="lines" @save="save" @cancel="showForm = false" @delete="record => { showForm = false; askRemove(record) }" />
      </a-modal>
      <a-modal v-model:open="showImportConfirm" title="预检导入解析记录" :confirm-loading="saving" ok-text="导入" cancel-text="取消" @ok="confirmImport">
        <a-form layout="vertical">
          <a-form-item label="导入模式">
            <a-radio-group v-model:value="importMode">
              <a-radio value="create">仅新增</a-radio>
              <a-radio value="overwrite">新增或覆盖</a-radio>
            </a-radio-group>
          </a-form-item>
        </a-form>
        <a-alert type="info" show-icon :message="importMode === 'overwrite' ? '按 主机记录 + 类型 + 线路 匹配；已存在则更新，不存在则新增。' : '逐条新增；已存在或格式不支持的记录会提示失败。'" style="margin-bottom: 16px" />
        <div style="white-space: pre-wrap">{{ importPreviewText }}</div>
      </a-modal>
    </section>
  `,
}
