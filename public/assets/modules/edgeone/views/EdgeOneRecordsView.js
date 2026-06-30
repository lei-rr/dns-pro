import { edgeOneApi } from '../utils/api.js'
import { providerPath } from '../../../routes/paths.js'
import { loadProviders } from '../../../providers/store.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'
import { tablePagination } from '../../../shared/utils/pagination.js'
import { showBatchFailures } from '../../../shared/utils/batch.js'
import BatchToolbar from '../../../shared/components/BatchToolbar.js'
import EdgeOneCertificateForm from '../components/EdgeOneCertificateForm.js'
import EdgeOneRecordForm from '../components/EdgeOneRecordForm.js'
import EdgeOneRecordTable from '../components/EdgeOneRecordTable.js'

export default {
  components: { BatchToolbar, EdgeOneCertificateForm, EdgeOneRecordForm, EdgeOneRecordTable },
  props: ['provider', 'zoneId'],
  data() {
    return {
      records: [],
      recordMeta: { page: 1, per_page: 20, total: 0 },
      selectedRecords: [],
      selectionResetKey: 0,
      notFound: false,
      editing: null,
      certEditing: null,
      showForm: false,
      showCertForm: false,
      loading: true,
      saving: false,
      deleting: false,
      statusUpdating: false,
      deletingText: '',
      statusUpdatingText: '',
      providerMeta: null,
      zoneMeta: null,
      loadRequestToken: 0,
    }
  },
  computed: {
    decodedZoneId() { return decodeURIComponent(this.zoneId) },
    displayZoneName() { return this.zoneMeta?.name || '' },
    zonesPath() { return providerPath(this.provider) },
    dnspodLinked() { return Boolean(this.providerMeta?.dnspod_provider) },
    pagination() {
      return tablePagination({
        current: this.recordMeta.page || 1,
        pageSize: this.recordMeta.per_page || 20,
        total: this.recordMeta.total || 0,
      })
    },
  },
  async mounted() {
    await this.load()
  },
  watch: {
    provider() { this.providerMeta = null; this.resetAndLoad() },
    zoneId() { this.resetAndLoad() },
  },
  methods: {
    resetAndLoad() {
      this.clearSelection()
      this.editing = null
      this.certEditing = null
      this.showForm = false
      this.showCertForm = false
      this.notFound = false
      this.zoneMeta = null
      this.recordMeta = { page: 1, per_page: 20, total: 0 }
      this.load()
    },
    async ensureZoneMeta(requestToken) {
      if (this.zoneMeta) return
      const response = await edgeOneApi.zone(this.provider, this.decodedZoneId)
      if (requestToken !== this.loadRequestToken) return
      this.zoneMeta = response.data || null
    },
    handleTableChange(pagination) {
      const nextPerPage = Number(pagination?.pageSize) || this.recordMeta.per_page || 20
      const pageSizeChanged = nextPerPage !== this.recordMeta.per_page
      const nextPage = pageSizeChanged ? 1 : (Number(pagination?.current) || 1)
      if (nextPage === this.recordMeta.page && nextPerPage === this.recordMeta.per_page) return
      this.recordMeta = { ...this.recordMeta, page: nextPage, per_page: nextPerPage }
      this.load()
    },
    async load(options = {}) {
      const requestToken = this.loadRequestToken + 1
      this.loadRequestToken = requestToken
      this.loading = true
      try {
        this.notFound = false
        if (!this.providerMeta) {
          const providers = await loadProviders()
          if (requestToken !== this.loadRequestToken) return
          this.providerMeta = providers.find((p) => p.id === this.provider) || null
        }
        if (requestToken !== this.loadRequestToken) return
        await this.ensureZoneMeta(requestToken)
        if (requestToken !== this.loadRequestToken) return
        const records = await edgeOneApi.accelerationDomains(this.provider, this.decodedZoneId, {
          page: this.recordMeta.page,
          per_page: this.recordMeta.per_page,
          ...options,
        })
        if (requestToken !== this.loadRequestToken) return
        this.records = records.data
        this.recordMeta = {
          page: records.meta?.page || this.recordMeta.page,
          per_page: records.meta?.per_page || this.recordMeta.per_page,
          total: records.meta?.total || 0,
        }
        if (options.refresh) message.success('已刷新')
      } catch (error) {
        if (requestToken !== this.loadRequestToken) return
        if (Number(error.status) === 404 || error.code === 'edgeone_zone_not_found') {
          this.records = []
          this.notFound = true
          return
        }

        message.error(errorMessage(error))
      } finally {
        if (requestToken === this.loadRequestToken) this.loading = false
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
          await edgeOneApi.updateAccelerationDomain(this.provider, this.decodedZoneId, this.editing.name, form)
          message.success('加速域名已更新')
        } else {
          const { autoSync, ...payload } = form
          const result = await edgeOneApi.createAccelerationDomain(this.provider, this.decodedZoneId, payload, { autoSync })
          const sync = result?.data?.side_effects?.dns?.sync
          if (autoSync && sync && sync.status === 'failed') {
            message.warning(`加速域名已添加，CNAME 同步失败：${sync.message || '-'}`)
          } else if (autoSync && sync && sync.status === 'skipped') {
            message.warning(`加速域名已添加，CNAME 稍后需处理：${sync.message || '-'}`)
          } else if (autoSync && sync) {
            message.success(sync.message || 'CNAME 已同步')
          } else {
            message.success('加速域名已添加')
          }
        }
        this.showForm = false
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.saving = false
      }
    },
    async saveCertificate(form) {
      if (!this.certEditing) return
      this.saving = true
      try {
        await edgeOneApi.updateCertificate(this.provider, this.decodedZoneId, this.certEditing.name, form)
        message.success('HTTPS 配置已更新')
        this.showCertForm = false
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
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
        : `确认删除 ${record.name}？删除后将从 EdgeOne 移除。`
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
      const total = this.selectedRecords.length
      const content = this.dnspodLinked
        ? `确认删除已选的 ${total} 个加速域名？关联的 DNSPod CNAME 记录会按可用情况清理。`
        : `确认删除已选的 ${total} 个加速域名？删除后将从 EdgeOne 移除。`
      let dialog = null
      dialog = modal.confirm({
        title: '批量删除加速域名',
        content: this.batchRemoveConfirmContent(content),
        okText: '批量删除',
        okType: 'danger',
        cancelText: '取消',
        onOk: () => this.batchRemove(dialog, total, content),
      })
    },
    askBatchDisable() {
      const onlineRecords = this.selectedRecords.filter((record) => record.status !== 'offline')
      if (!onlineRecords.length) {
        message.warning('已选域名均已停用')
        return
      }

      const total = onlineRecords.length
      let dialog = null
      dialog = modal.confirm({
        title: '批量停用加速域名',
        content: this.batchStatusConfirmContent(total),
        okText: '批量停用',
        okType: 'danger',
        cancelText: '取消',
        onOk: () => this.batchDisable(dialog, onlineRecords),
      })
    },
    batchStatusConfirmContent(total) {
      const base = `确认停用已选的 ${total} 个加速域名？`
      return Vue.h('div', { style: 'white-space: pre-wrap' }, this.statusUpdatingText ? `${base}\n\n${this.statusUpdatingText}` : base)
    },
    updateBatchStatusDialog(dialog, total) {
      dialog?.update?.({
        content: this.batchStatusConfirmContent(total),
        cancelButtonProps: { disabled: this.statusUpdating },
      })
    },
    batchRemoveConfirmContent(base) {
      return Vue.h('div', { style: 'white-space: pre-wrap' }, this.deletingText ? `${base}\n\n${this.deletingText}` : base)
    },
    updateBatchRemoveDialog(dialog, base) {
      dialog?.update?.({
        content: this.batchRemoveConfirmContent(base),
        cancelButtonProps: { disabled: this.deleting },
      })
    },
    async remove(record) {
      this.deleting = true
      try {
        const response = await edgeOneApi.deleteAccelerationDomain(this.provider, this.decodedZoneId, record.name)
        const cleaned = Number(response?.data?.side_effects?.dns?.cleanup?.details?.cleaned || 0)
        message.success(cleaned > 0 ? '已删除，DNSPod CNAME 已清理' : '已删除')
        this.clearSelection()
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.deleting = false
        this.deletingText = ''
      }
    },
    async updateStatus(record, status) {
      this.statusUpdating = true
      try {
        await edgeOneApi.updateAccelerationDomainStatus(this.provider, this.decodedZoneId, record.name, status)
        message.success(status === 'offline' ? '已停用' : '已启用')
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.statusUpdating = false
      }
    },
    async batchDisable(dialog, records) {
      this.statusUpdating = true
      const failed = []
      const total = records.length
      try {
        this.statusUpdatingText = '正在停用 0/' + total
        this.updateBatchStatusDialog(dialog, total)
        for (const [index, record] of records.entries()) {
          this.statusUpdatingText = `正在停用 ${index + 1}/${total}`
          this.updateBatchStatusDialog(dialog, total)
          try {
            await edgeOneApi.updateAccelerationDomainStatus(this.provider, this.decodedZoneId, record.name, 'offline')
          } catch (error) {
            failed.push(`${record.name}: ${error.message}`)
          }
        }

        if (failed.length) showBatchFailures('批量停用完成', failed, '个')
        else message.success('批量停用完成')

        this.clearSelection()
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.statusUpdating = false
        this.statusUpdatingText = ''
      }
    },
    async batchRemove(dialog, total, base) {
      this.deleting = true
      const failed = []
      try {
        this.deletingText = '正在删除 0/' + total
        this.updateBatchRemoveDialog(dialog, base)
        const records = [...this.selectedRecords]
        for (const [index, record] of records.entries()) {
          this.deletingText = `正在删除 ${index + 1}/${records.length}`
          this.updateBatchRemoveDialog(dialog, base)
          try {
            await edgeOneApi.deleteAccelerationDomain(this.provider, this.decodedZoneId, record.name)
          } catch (error) {
            failed.push(`${record.name}: ${error.message}`)
          }
        }
        if (failed.length) showBatchFailures('批量删除完成', failed, '个')
        else message.success('批量删除完成')
        this.clearSelection()
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
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
          <a-typography-title :level="3" style="margin: 4px 0">{{ displayZoneName || decodedZoneId }}</a-typography-title>
          <a-typography-text type="secondary">EdgeOne 加速域名</a-typography-text>
        </div>
        <div class="page-actions">
          <a-button :loading="loading" :disabled="saving || deleting || statusUpdating" @click="load({ refresh: true })">刷新</a-button>
          <a-button v-if="!notFound" type="primary" :disabled="saving || deleting || statusUpdating || !displayZoneName" @click="create">添加加速域名</a-button>
        </div>
      </div>
      <a-result v-if="notFound" status="404" title="站点不存在或未配置" :sub-title="decodedZoneId">
        <template #extra><a-button type="primary" @click="$router.push(zonesPath)">返回 EdgeOne</a-button></template>
      </a-result>
      <template v-else>
      <BatchToolbar :count="selectedRecords.length" :deleting="deleting || statusUpdating" delete-text="批量删除" :actions="[{ key: 'offline', label: '批量停用', loading: statusUpdating, disabled: selectedRecords.every(record => record.status === 'offline') }]" @delete="askBatchRemove" @action="key => { if (key === 'offline') askBatchDisable() }" @clear="clearSelection" />
      <EdgeOneRecordTable
        :records="records"
        :loading="loading"
        :pagination="pagination"
        :selection-reset-key="selectionResetKey"
        empty-text="暂无匹配的加速域名"
        @edit="edit"
        @status="askStatus"
        @certificate="configureCertificate"
        @delete="askRemove"
        @change="handleTableChange"
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
