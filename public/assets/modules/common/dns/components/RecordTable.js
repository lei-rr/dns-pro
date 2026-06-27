import { uniqueFilters } from '../../../../shared/utils/format.js'
import { tablePagination } from '../../../../shared/utils/pagination.js'
import CopyButton from '../../../../shared/components/CopyButton.js'
import TableActions from '../../../../shared/components/TableActions.js'
import { defaultProviderHook } from '../hook.js'
import { dnsRecordTypeColors } from '../utils/format.js'

export default {
  components: { CopyButton, TableActions },
  props: {
    records: Array,
    providerHook: Object,
    loading: Boolean,
    emptyText: { type: String, default: '暂无解析记录' },
    selectionResetKey: Number,
    typeOptions: { type: Array, default: () => [] },
  },
  emits: ['edit', 'delete', 'selection-change'],
  computed: {
    showTtl() {
      return this.hook.showTtl
    },
    hook() {
      return this.providerHook || defaultProviderHook
    },
    columns() {
      const columns = [
        { title: '主机', dataIndex: 'name', key: 'name', width: 150 },
        {
          title: '类型',
          dataIndex: 'type',
          key: 'type',
          width: 80,
          filters: this.typeOptions.map((item) => ({ text: item.label, value: item.value })),
          onFilter: (value, record) => record.type === value,
        },
        {
          title: '记录值',
          dataIndex: 'value',
          key: 'value',
          width: 360,
          filters: uniqueFilters(this.records.map((record) => record.value)),
          onFilter: (value, record) => record.value === value,
        },
      ]

      if (this.showTtl) {
        columns.push({ title: 'TTL', dataIndex: 'ttl', key: 'ttl', width: 80 })
      }

      columns.push(
        {
          title: this.hook.lineLabel,
          key: 'line',
          width: 100,
          filters: this.hasProxy
            ? [{ text: this.hook.proxyOnText, value: 'proxied' }, { text: this.hook.proxyOffText, value: 'dns_only' }]
            : uniqueFilters(this.records.map((record) => record.line || '默认')),
          onFilter: (value, record) => this.hasProxy
            ? (value === 'proxied' ? !!record.proxied : !record.proxied)
            : (record.line || '默认') === value,
        },
        { title: '备注', dataIndex: 'remark', key: 'remark', width: 160 },
        { title: '操作', key: 'actions', width: 110, align: 'right' },
      )

      return columns
    },
    hasProxy() {
      return this.hook.proxyTypes.length > 0
    },
    pagination() { return tablePagination() },
  },
  data() {
    return { selectedRowKeys: [] }
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
    typeColor(type) {
      return dnsRecordTypeColors[type] || 'default'
    },
    selectRows(keys, rows) {
      this.selectedRowKeys = keys
      this.$emit('selection-change', rows)
    },
    clearSelection() {
      this.selectedRowKeys = []
      this.$emit('selection-change', [])
    },
    actionItems() {
      return [{ key: 'delete', label: '删除', danger: true }]
    },
    selectAction(action, record) {
      if (action === 'delete') this.$emit('delete', record)
    },
  },
  template: `
    <a-table
      :columns="columns"
      :data-source="records"
      :row-key="record => record.id"
      :loading="loading"
      :pagination="pagination"
      :row-selection="{ selectedRowKeys, onChange: selectRows }"
      :locale="{ emptyText }"
      size="middle"
      class="dns-record-table"
      :scroll="{ x: 760 }"
      @change="clearSelection"
    >
      <template #bodyCell="{ column, record }">
        <template v-if="column.key === 'name'">
          <a-typography-text strong :ellipsis="{ tooltip: record.name }" class="dns-record-name">{{ record.name }}</a-typography-text>
        </template>
        <template v-else-if="column.key === 'type'">
          <span class="dns-type-cell">
            <a-tag :color="typeColor(record.type)">{{ record.type }}</a-tag>
            <a-tag v-if="record.type === 'MX' && record.priority !== undefined && record.priority !== null && record.priority !== ''" color="blue" class="dns-priority-tag">{{ record.priority }}</a-tag>
          </span>
        </template>
        <template v-else-if="column.key === 'value'">
          <div class="copy-cell">
            <span class="truncate-text" :title="record.value">{{ record.value }}</span>
            <CopyButton :value="record.value" />
          </div>
        </template>
        <template v-else-if="column.key === 'line'">
          <a-tag v-if="hasProxy" :color="record.proxied ? hook.proxyOnColor : hook.proxyOffColor">{{ record.proxied ? hook.proxyOnText : hook.proxyOffText }}</a-tag>
          <span v-else class="nowrap-cell">{{ record.line || '默认' }}</span>
        </template>
        <template v-else-if="column.key === 'remark'">
          <a-typography-text type="secondary" :ellipsis="{ tooltip: record.remark }" class="table-remark">{{ record.remark || '-' }}</a-typography-text>
        </template>
        <template v-else-if="column.key === 'actions'">
          <TableActions :items="actionItems(record)" @edit="$emit('edit', record)" @select="action => selectAction(action, record)" />
        </template>
      </template>
    </a-table>
  `,
}
