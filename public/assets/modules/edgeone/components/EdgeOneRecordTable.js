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
    pagination: Object,
    emptyText: { type: String, default: '暂无加速域名' },
    selectionResetKey: Number,
  },
  emits: ['edit', 'status', 'certificate', 'delete', 'selection-change', 'change'],
  data() {
    return { internalSelectedRowKeys: [] }
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
          ],
          onFilter: (value, record) => record.status === value,
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
    tablePaginationConfig() { return this.pagination || tablePagination() },
  },
  watch: {
    records() {
      this.internalSelectedRowKeys = []
      this.$emit('selection-change', [])
    },
    selectionResetKey() {
      this.internalSelectedRowKeys = []
      this.$emit('selection-change', [])
    },
  },
  methods: {
    statusColor(status) {
      return edgeOneStatusColors[status] || (status ? 'red' : 'default')
    },
    statusLabel(record) {
      return edgeOneStatusLabels[record.status] || record.status || '-'
    },
    selectRows(keys, rows) {
      this.internalSelectedRowKeys = keys
      this.$emit('selection-change', rows)
    },
    clearSelection() {
      this.internalSelectedRowKeys = []
      this.$emit('selection-change', [])
    },
    handleTableChange(pagination) {
      this.clearSelection()
      this.$emit('change', pagination)
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
      const cert = (record.certificate?.items || record.certificate?.list || [])[0]
      if (cert?.status && normalizeStatus(cert.status) !== 'deployed') return certificateStatusLabel(cert.status)
      return '已部署'
    },
    httpsColor(record) {
      const mode = record.certificate?.mode || 'disable'
      if (mode === 'disable') return 'default'
      const cert = (record.certificate?.items || record.certificate?.list || [])[0]
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
      :pagination="tablePaginationConfig"
      :row-selection="{ selectedRowKeys: internalSelectedRowKeys, onChange: selectRows }"
      :locale="{ emptyText }"
      size="middle"
      :scroll="{ x: 1060 }"
      @change="handleTableChange"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a-typography-text strong class="break-text">{{ record.name }}</a-typography-text>
        </template>
        <template v-else-if="column.key === 'status'">
          <a-tag :color="statusColor(record.status)">{{ statusLabel(record) }}</a-tag>
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
