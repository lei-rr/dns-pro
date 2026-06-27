import { edgeOneApi } from '../utils/api.js'
import { providerPath } from '../../../routes/paths.js'
import { loadProviders } from '../../../providers/store.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'
import BatchToolbar from '../../../shared/components/BatchToolbar.js'
import EdgeOneCertificateForm from '../components/EdgeOneCertificateForm.js'
import EdgeOneRecordForm from '../components/EdgeOneRecordForm.js'
import EdgeOneRecordTable from '../components/EdgeOneRecordTable.js'

export default {
  components: { BatchToolbar, EdgeOneCertificateForm, EdgeOneRecordForm, EdgeOneRecordTable },
  props: ['provider', 'zoneName'],
  data() {
    return { records: [], selectedRecords: [], selectionResetKey: 0, notFound: false, editing: null, certEditing: null, showForm: false, showCertForm: false, loading: true, saving: false, deleting: false, deletingText: '', syncingCname: '', cnameStatusWarning: false, providerMeta: null }
  },
  computed: {
    decodedZoneName() { return decodeURIComponent(this.zoneName) },
    displayZoneName() { return this.decodedZoneName },
    zonesPath() { return providerPath(this.provider) },
    dnspodLinked() { return Boolean(this.providerMeta?.dnspod_provider) },
  },
  async mounted() {
    await this.load()
  },
  watch: {
    provider() { this.providerMeta = null; this.resetAndLoad() },
    zoneName() { this.resetAndLoad() },
  },
  methods: {
    resetAndLoad() {
      this.clearSelection()
      this.editing = null
      this.certEditing = null
      this.showForm = false
      this.showCertForm = false
      this.cnameStatusWarning = false
      this.notFound = false
      this.load()
    },
    async load(options = {}) {
      this.loading = true
      try {
        this.notFound = false
        if (!this.providerMeta) {
          const providers = await loadProviders()
          this.providerMeta = providers.find((p) => p.id === this.provider) || null
        }
        const records = await edgeOneApi.accelerationDomains(this.provider, this.decodedZoneName, options)
        this.records = records.data
        this.cnameStatusWarning = records.data.some((record) => record.cname_status_error)
        if (options.refresh) message.success('已刷新')
      } catch (error) {
        if (Number(error.status) === 404 || error.code === 'edgeone_zone_not_found') {
          this.records = []
          this.notFound = true
          return
        }

        message.error(error.message)
      } finally {
        this.loading = false
      }
    },
    edit(record) { this.editing = { ...record }; this.showForm = true },
    create() { this.editing = null; this.showForm = true },
    clearSelection() { this.selectedRecords = []; this.selectionResetKey += 1 },
    configureCertificate(record) { this.certEditing = { ...record }; this.showCertForm = true },
    async save(form) {
      this.saving = true
      try {
        if (this.editing) {
          await edgeOneApi.updateAccelerationDomain(this.provider, this.decodedZoneName, this.editing.name, form)
          message.success('加速域名已更新')
        } else {
          const { autoSync, ...payload } = form
          const result = await edgeOneApi.createAccelerationDomain(this.provider, this.decodedZoneName, payload, { autoSync })
          const sync = result?.data?.dns_record
          if (autoSync && sync && sync.synced === false) {
            message.warning(`加速域名已添加，CNAME 同步失败：${sync.message || '-'}`)
          } else if (autoSync && sync) {
            message.success(this.dnsSyncMessage(sync))
          } else {
            message.success('加速域名已添加')
          }
        }
        this.showForm = false
        await this.load({ refresh: true })
      } catch (error) {
        message.error(error.message)
      } finally {
        this.saving = false
      }
    },
    async saveCertificate(form) {
      if (!this.certEditing) return
      this.saving = true
      try {
        await edgeOneApi.updateCertificate(this.provider, this.decodedZoneName, this.certEditing.name, form)
        message.success('HTTPS 配置已更新')
        this.showCertForm = false
        await this.load({ refresh: true })
      } catch (error) {
        message.error(error.message)
      } finally {
        this.saving = false
      }
    },
    askStatus(record) {
      const nextStatus = record.status === 'offline' ? 'online' : 'offline'
      const action = nextStatus === 'online' ? '启用' : '停用'
      modal.confirm({
        title: `${action}加速域名`,
        content: `确认${action} ${record.name}？`,
        okText: action,
        okType: nextStatus === 'offline' ? 'danger' : 'primary',
        cancelText: '取消',
        onOk: () => this.updateStatus(record, nextStatus),
      })
    },
    askRemove(record) {
      if (record.status !== 'offline') {
        message.error('请先停用加速域名，再删除')
        return
      }
      const content = this.dnspodLinked
        ? `确认删除 ${record.name}？关联的 DNSPod CNAME 记录会一并清理。`
        : `确认删除 ${record.name}？删除后将同步到 EdgeOne。`
      modal.confirm({
        title: '删除加速域名',
        content,
        okText: '删除',
        okType: 'danger',
        cancelText: '取消',
        onOk: () => this.remove(record),
      })
    },
    askBatchRemove() {
      if (!this.selectedRecords.length) return
      if (this.selectedRecords.some((record) => record.status !== 'offline')) {
        message.error('只能删除已停用的加速域名')
        return
      }
      modal.confirm({
        title: '批量删除加速域名',
        content: `确认删除已选的 ${this.selectedRecords.length} 个加速域名？删除后将同步到 EdgeOne。`,
        okText: '批量删除',
        okType: 'danger',
        cancelText: '取消',
        onOk: () => this.batchRemove(),
      })
    },
    async remove(record) {
      this.deleting = true
      try {
        const response = await edgeOneApi.deleteAccelerationDomain(this.provider, this.decodedZoneName, record.name)
        const cleaned = Number(response?.data?.dns_cleanup?.cleaned || 0)
        message.success(cleaned > 0 ? '已删除，DNSPod CNAME 已清理' : '已删除')
        await this.load({ refresh: true })
      } catch (error) {
        message.error(error.message)
      } finally {
        this.deleting = false
        this.deletingText = ''
      }
    },
    async updateStatus(record, status) {
      this.deleting = true
      try {
        await edgeOneApi.updateAccelerationDomainStatus(this.provider, this.decodedZoneName, record.name, status)
        message.success(status === 'offline' ? '已停用' : '已启用')
        await this.load({ refresh: true })
      } catch (error) {
        message.error(error.message)
      } finally {
        this.deleting = false
      }
    },
    async syncCname(record) {
      this.syncingCname = record.name
      try {
        const response = await edgeOneApi.syncAccelerationDomainCname(this.provider, this.decodedZoneName, record.name)
        message.success(this.dnsSyncMessage(response.data))
        await this.load({ refresh: true })
      } catch (error) {
        message.error(error.message)
      } finally {
        this.syncingCname = ''
      }
    },
    dnsSyncMessage(result = {}) {
      return result.message || 'CNAME 已同步'
    },
    async batchRemove() {
      this.deleting = true
      const failed = []
      try {
        const records = [...this.selectedRecords]
        for (const [index, record] of records.entries()) {
          this.deletingText = `正在删除 ${index + 1}/${records.length}`
          try {
            await edgeOneApi.deleteAccelerationDomain(this.provider, this.decodedZoneName, record.name)
          } catch (error) {
            failed.push(`${record.name}: ${error.message}`)
          }
        }
        if (failed.length) showBatchFailures('批量删除完成', failed, '个')
        else message.success('批量删除完成')
        this.selectedRecords = []
        await this.load({ refresh: true })
      } catch (error) {
        message.error(error.message)
      } finally {
        this.deleting = false
        this.deletingText = ''
      }
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-button type="link" style="padding: 0" @click="$router.push(zonesPath)">返回站点</a-button>
          <a-typography-title :level="3" style="margin: 4px 0">{{ displayZoneName }}</a-typography-title>
          <a-typography-text type="secondary">EdgeOne 加速域名</a-typography-text>
        </div>
        <div class="page-actions">
          <a-button :loading="loading" :disabled="saving || deleting" @click="load({ refresh: true })">刷新</a-button>
          <a-button v-if="!notFound" type="primary" :disabled="saving || deleting" @click="create">添加加速域名</a-button>
        </div>
      </div>
      <a-result v-if="notFound" status="404" title="站点不存在或未配置" :sub-title="decodedZoneName">
        <template #extra><a-button type="primary" @click="$router.push(zonesPath)">返回 EdgeOne</a-button></template>
      </a-result>
      <template v-else>
      <a-alert v-if="deletingText" type="warning" show-icon style="margin-bottom: 16px" :message="deletingText" />
      <a-alert v-if="cnameStatusWarning" type="warning" show-icon style="margin-bottom: 16px" message="CNAME 状态检查失败，已显示基础状态。" />
      <BatchToolbar :count="selectedRecords.length" :deleting="deleting" delete-text="批量删除" @delete="askBatchRemove" @clear="clearSelection" />
      <EdgeOneRecordTable
        :records="records"
        :loading="loading"
        :syncing-cname="syncingCname"
        :selection-reset-key="selectionResetKey"
        empty-text="暂无匹配的加速域名"
        @edit="edit"
        @status="askStatus"
        @certificate="configureCertificate"
        @sync-cname="syncCname"
        @delete="askRemove"
        @selection-change="selectedRecords = $event"
      />
      <a-modal v-model:open="showForm" :title="editing ? '编辑加速域名' : '添加加速域名'" :footer="null" destroy-on-close>
        <EdgeOneRecordForm :model-value="editing" :saving="saving" :zone-name="displayZoneName" :dnspod-linked="dnspodLinked" @save="save" @cancel="showForm = false" />
      </a-modal>
      <a-modal v-model:open="showCertForm" title="HTTPS 配置" :footer="null" destroy-on-close>
        <EdgeOneCertificateForm :model-value="certEditing" :saving="saving" @save="saveCertificate" @cancel="showCertForm = false" />
      </a-modal>
      </template>
    </section>
  `,
}
