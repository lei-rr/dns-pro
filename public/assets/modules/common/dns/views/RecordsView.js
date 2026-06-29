import { dnsApi } from '../api.js'
import { loadProviders } from '../../../../providers/store.js'
import { message, modal } from '../../../../shared/plugins/antDesignVue.js'
import { providerPath } from '../../../../routes/paths.js'
import { resolveProviderHook } from '../../../../providers/registry.js'
import { chooseJsonFile, downloadJson } from '../../../../shared/utils/files.js'
import { errorMessage } from '../../../../shared/utils/errors.js'
import BatchToolbar from '../../../../shared/components/BatchToolbar.js'
import RecordForm from '../components/RecordForm.js'
import RecordTable from '../components/RecordTable.js'
import { defaultProviderHook } from '../hook.js'
import { showBatchFailures } from '../../../../shared/utils/batch.js'

export default {
  components: { BatchToolbar, RecordForm, RecordTable },
  props: { provider: String, domain: String, providerMeta: Object },
  data() {
    return { records: [], selectedRecords: [], selectionResetKey: 0, lines: [], currentProviderMeta: this.providerMeta || null, currentDomainName: '', providerHook: defaultProviderHook, editing: null, showForm: false, keyword: '', loading: true, saving: false, deleting: false, deletingText: '', loadRequestToken: 0 }
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
    filteredRecords() {
      const keyword = this.keyword.trim().toLowerCase()
      if (!keyword) return this.records
      return this.records.filter((record) => [record.name, record.type, record.value, record.line, record.remark]
        .some((value) => String(value || '').toLowerCase().includes(keyword)))
    },
  },
  async mounted() { await this.load() },
  watch: {
    provider() { this.currentProviderMeta = this.providerMeta || null; this.resetAndLoad() },
    domain() { this.resetAndLoad() },
    providerMeta(value) { this.currentProviderMeta = value || null },
  },
  methods: {
    routeBase() { return providerPath(this.provider) },
    resetAndLoad() {
      this.clearSelection()
      this.editing = null
      this.showForm = false
      this.keyword = ''
      this.currentDomainName = ''
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
        const records = await dnsApi.records(this.provider, this.recordsTarget, options)
        if (requestToken !== this.loadRequestToken) return
        this.records = records.data
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
        await this.confirmImport(records)
      } catch (error) {
        message.error(errorMessage(error))
      }
    },
    async confirmImport(records) {
      const preview = records.slice(0, 8).map((record, index) => `${index + 1}. ${record.name || '@'} ${record.type || '-'} ${record.value || ''}`).join('\n')
      const more = records.length > 8 ? `\n... 另有 ${records.length - 8} 条` : ''
      modal.confirm({
        title: '预检导入解析记录',
        width: 720,
        content: Vue.h('div', { style: 'white-space: pre-wrap' }, `将向 ${this.decodedDomain} 创建 ${records.length} 条解析记录：\n\n${preview}${more}\n\n导入会逐条调用云服务商接口，已存在或格式不支持的记录会在结果中提示失败。`),
        okText: '导入',
        cancelText: '取消',
        onOk: () => this.batchImport(records),
      })
    },
    async batchImport(records) {
      this.saving = true
      const failed = []
      try {
        for (const [index, record] of records.entries()) {
          this.deletingText = `正在导入 ${index + 1}/${records.length}`
          try {
            await dnsApi.createRecord(this.provider, this.recordsTarget, record, { zoneName: this.displayDomain })
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
      }
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-button type="link" style="padding: 0" @click="$router.push(routeBase())">返回域名</a-button>
          <a-typography-title :level="3" style="margin: 4px 0">{{ displayDomain }}</a-typography-title>
          <a-typography-text type="secondary">解析记录</a-typography-text>
        </div>
        <div class="page-actions">
          <a-input-search v-model:value="keyword" placeholder="搜索记录" allow-clear />
          <a-button v-if="capabilities.importRecords" :disabled="saving || deleting" @click="importRecords">导入</a-button>
          <a-button v-if="capabilities.exportRecords" :disabled="!records.length" @click="exportRecords">导出</a-button>
          <a-button :loading="loading" :disabled="saving || deleting" @click="load({ refresh: true })">刷新</a-button>
          <a-button type="primary" :disabled="saving || deleting" @click="create">添加记录</a-button>
        </div>
      </div>
      <a-alert v-if="deletingText" type="warning" show-icon style="margin-bottom: 16px" :message="deletingText" />
      <BatchToolbar :count="selectedRecords.length" :deleting="deleting" delete-text="批量删除" @delete="askBatchRemove" @clear="clearSelection" />
      <RecordTable
        :records="filteredRecords"
        :provider-hook="providerHook"
        :loading="loading"
        :selection-reset-key="selectionResetKey"
        :type-options="typeOptions"
        empty-text="暂无匹配的解析记录"
        @edit="edit"
        @delete="askRemove"
        @selection-change="selectedRecords = $event"
      />
      <a-modal v-model:open="showForm" :title="editing ? '编辑解析记录' : '添加解析记录'" :footer="null" destroy-on-close>
        <RecordForm :model-value="editing" :saving="saving" :provider-hook="providerHook" :lines="lines" @save="save" @cancel="showForm = false" @delete="record => { showForm = false; askRemove(record) }" />
      </a-modal>
    </section>
  `,
}
