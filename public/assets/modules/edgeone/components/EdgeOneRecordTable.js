import { uniqueFilters } from '../../../shared/utils/format.js'
import { tablePagination } from '../../../shared/utils/pagination.js'
import CopyButton from '../../../shared/components/CopyButton.js'
import TableActions from '../../../shared/components/TableActions.js'
import { certificateStatusColor, certificateStatusLabel, edgeOneIpv6Labels, edgeOneOriginTypeLabels, edgeOneStatusColors, edgeOneStatusLabels, normalizeStatus } from '../utils/format.js'

export default {
  components: { CopyButton, TableActions },
  props: {
    records: Array,
    loading: Boolean,
    emptyText: { type: String, default: '暂无加速域名' },
    selectionResetKey: Number,
    syncingCname: String,
  },
  emits: ['edit', 'status', 'certificate', 'delete', 'sync-cname', 'selection-change'],
  data() {
    return { selectedRowKeys: [] }
  },
  computed: {
    columns() {
      return [
        { title: '加速域名', dataIndex: 'name', key: 'name', width: 190 },
        {
          title: '状态',
          key: 'status',
          width: 80,
          filters: [
            { text: '已生效', value: 'online' },
            { text: '部署中', value: 'process' },
            { text: '已停用', value: 'offline' },
            { text: '未生效', value: 'init' },
            { text: '已封禁', value: 'forbidden' },
            { text: '请添加 CNAME', value: 'cname_moved' },
            { text: 'CNAME 异常', value: 'cname_invalid' },
          ],
          onFilter: (value, record) => this.displayStatus(record) === value,
        },
        {
          title: 'CNAME',
          dataIndex: 'cname',
          key: 'cname',
          width: 320,
          filters: uniqueFilters(this.records.map((record) => record.cname)),
          onFilter: (value, record) => record.cname === value,
        },
        {
          title: '源站',
          key: 'origin',
          width: 320,
          filters: uniqueFilters(this.records.map((record) => record.origin?.value)),
          onFilter: (value, record) => record.origin?.value === value,
        },
        { title: 'IPv6', key: 'ipv6', width: 90 },
        {
          title: 'HTTPS',
          key: 'https',
          width: 130,
          filters: [
            { text: '已配置', value: 'enabled' },
            { text: '未配置', value: 'disabled' },
          ],
          onFilter: (value, record) => value === 'enabled' ? record.certificate?.mode !== 'disable' : record.certificate?.mode === 'disable',
        },
        { title: '操作', key: 'actions', width: 110, align: 'right' },
      ]
    },
    pagination() { return tablePagination() },
  },
  watch: {
    records() {
      this.selectedRowKeys = []
      this.$emit('selection-change', [])
    },
    selectionResetKey() {
      this.selectedRowKeys = []
      this.$emit('selection-change', [])
    },
  },
  methods: {
    statusColor(status) {
      return edgeOneStatusColors[status] || (status ? 'red' : 'default')
    },
    displayStatus(record) {
      if (record.status === 'online' && ['moved', 'invalid'].includes(record.cname_status)) return `cname_${record.cname_status}`
      return record.status
    },
    statusLabel(record) {
      const status = this.displayStatus(record)
      return ({ cname_moved: '请添加 CNAME', cname_invalid: 'CNAME 异常' })[status] || edgeOneStatusLabels[status] || status || '-'
    },
    statusTagColor(record) {
      const status = this.displayStatus(record)
      if (status === 'cname_moved') return 'gold'
      if (status === 'cname_invalid') return 'red'
      return this.statusColor(status)
    },
    needsCnameSync(record) {
      return ['cname_moved', 'cname_invalid'].includes(this.displayStatus(record))
    },
    cnameSyncText(record) {
      return this.displayStatus(record) === 'cname_invalid' ? '一键修复' : '一键添加'
    },
    cnameSyncing(record) {
      return this.syncingCname === record.name
    },
    selectRows(keys, rows) {
      this.selectedRowKeys = keys
      this.$emit('selection-change', rows)
    },
    clearSelection() {
      this.selectedRowKeys = []
      this.$emit('selection-change', [])
    },
    originTypeLabel(type) {
      return edgeOneOriginTypeLabels[type] || type || '-'
    },
    ipv6Label(status) {
      const normalized = String(status || '').toLowerCase()
      return edgeOneIpv6Labels[normalized] || status || '-'
    },
    ipv6Enabled(status) {
      return ['on', 'enabled', 'enable'].includes(String(status || '').toLowerCase())
    },
    httpsLabel(record) {
      const mode = record.certificate?.mode || 'disable'
      if (mode === 'disable') return '未配置'
      const cert = record.certificate?.list?.[0]
      if (cert?.status && normalizeStatus(cert.status) !== 'deployed') return certificateStatusLabel(cert.status)
      return '已部署'
    },
    httpsColor(record) {
      const mode = record.certificate?.mode || 'disable'
      if (mode === 'disable') return 'default'
      const cert = record.certificate?.list?.[0]
      return certificateStatusColor(cert?.status || 'deployed')
    },
    actionItems(record) {
      const items = [
        { key: 'status', label: record.status === 'offline' ? '启用' : '停用' },
      ]

      if (record.status === 'offline') {
        items.push({ key: 'delete', label: '删除', danger: true })
      }

      return items
    },
    selectAction(action, record) {
      if (action === 'status') this.$emit('status', record)
      if (action === 'delete') this.$emit('delete', record)
    },
  },
  template: `
    <a-table
      :columns="columns"
      :data-source="records"
      :row-key="record => record.name"
      :loading="loading"
      :pagination="pagination"
      :row-selection="{ selectedRowKeys, onChange: selectRows, getCheckboxProps: record => ({ disabled: record.status !== 'offline' }) }"
      :locale="{ emptyText }"
      size="middle"
      :scroll="{ x: 1060 }"
      @change="clearSelection"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a-typography-text strong class="break-text">{{ record.name }}</a-typography-text>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-space direction="vertical" size="small">
            <a-tag :color="statusTagColor(record)">{{ statusLabel(record) }}</a-tag>
            <a-button v-if="needsCnameSync(record)" type="link" size="small" style="padding: 0" :loading="cnameSyncing(record)" :disabled="!!syncingCname" @click="$emit('sync-cname', record)">{{ cnameSyncText(record) }}</a-button>
          </a-space>
        </template>
        <template v-else-if="column.key === 'cname'">
          <div class="copy-cell">
            <span class="truncate-text" :title="record.cname">{{ record.cname || '-' }}</span>
            <CopyButton v-if="record.cname" :value="record.cname" />
          </div>
        </template>
        <template v-else-if="column.key === 'origin'">
          <a-space direction="vertical" size="small">
            <a-space size="small" wrap>
              <a-tag>{{ originTypeLabel(record.origin?.type) }}</a-tag>
              <a-typography-text :ellipsis="{ tooltip: record.origin?.value }" style="max-width: var(--table-copy-width)">{{ record.origin?.value || '-' }}</a-typography-text>
            </a-space>
            <a-typography-text type="secondary">{{ record.origin_protocol || '-' }} · {{ record.http_origin_port || '-' }}{{ record.origin_protocol === 'FOLLOW' ? ' / ' + (record.https_origin_port || '-') : '' }}</a-typography-text>
          </a-space>
        </template>
        <template v-else-if="column.key === 'ipv6'">
          <a-tag :color="ipv6Enabled(record.ipv6_status) ? 'green' : 'default'">{{ ipv6Label(record.ipv6_status) }}</a-tag>
        </template>
        <template v-else-if="column.key === 'https'">
          <a-space size="small" class="nowrap-cell">
            <a-tag :color="httpsColor(record)">{{ httpsLabel(record) }}</a-tag>
            <a-button type="link" size="small" style="padding: 0" @click="$emit('certificate', record)">配置</a-button>
          </a-space>
        </template>
        <template v-else-if="column.key === 'actions'">
          <TableActions :items="actionItems(record)" @edit="$emit('edit', record)" @select="action => selectAction(action, record)" />
        </template>
      </template>
    </a-table>
  `,
}
