import { preferredDomainApi } from '../utils/api.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'

/**
 * 优选域名管理弹窗
 *
 * 记录以 `domain` 字符串作为稳定标识（后端 KV 表 key 即 domain）。
 *
 * 行为：
 *   - 打开时拉取列表，本地维护可编辑数组
 *   - 新增/编辑/删除/拖动排序均即时调用后端
 *   - 关闭时通过 `update` 事件向父组件返回最新列表
 */
export default {
  name: 'PreferredDomainsModal',
  props: {
    open: { type: Boolean, default: false },
  },
  emits: ['update:open', 'update'],
  data() {
    return {
      items: [],
      loading: false,
      saving: false,
      newDomain: '',
      editingDomain: null,   // 正在编辑的原 domain（即 row key）
      editingValue: '',      // 编辑框当前值
      draggingDomain: null,
    }
  },
  watch: {
    open(value) {
      if (value) {
        this.resetEditing()
        this.newDomain = ''
        this.load()
      }
    },
  },
  methods: {
    async load() {
      this.loading = true
      try {
        const response = await preferredDomainApi.list()
        this.items = response.data || []
      } catch (error) {
        message.error(error.message)
      } finally {
        this.loading = false
      }
    },
    notifyChange() {
      this.$emit('update', [...this.items])
    },
    resetEditing() {
      this.editingDomain = null
      this.editingValue = ''
    },
    async addDomain() {
      const domain = String(this.newDomain || '').trim()
      if (!domain) {
        message.warning('请输入域名')
        return
      }
      this.saving = true
      try {
        const response = await preferredDomainApi.create(domain)
        this.items.push(response.data)
        this.newDomain = ''
        message.success('已添加')
        this.notifyChange()
      } catch (error) {
        message.error(error.message)
      } finally {
        this.saving = false
      }
    },
    startEdit(item) {
      this.editingDomain = item.domain
      this.editingValue = item.domain
    },
    cancelEdit() {
      this.resetEditing()
    },
    async saveEdit() {
      if (this.editingDomain === null) return
      const newDomain = String(this.editingValue || '').trim()
      if (!newDomain) {
        message.warning('域名不能为空')
        return
      }
      if (newDomain === this.editingDomain) {
        this.resetEditing()
        return
      }
      this.saving = true
      try {
        const response = await preferredDomainApi.rename(this.editingDomain, newDomain)
        const index = this.items.findIndex((item) => item.domain === this.editingDomain)
        if (index >= 0) this.items.splice(index, 1, response.data)
        message.success('已更新')
        this.resetEditing()
        this.notifyChange()
      } catch (error) {
        message.error(error.message)
      } finally {
        this.saving = false
      }
    },
    askDelete(item) {
      modal.confirm({
        title: '删除优选域名',
        content: `确认删除 ${item.domain}？已使用该域名的 hostname 不会被自动清理。`,
        okText: '删除', okType: 'danger', cancelText: '取消',
        onOk: () => this.removeItem(item),
      })
    },
    async removeItem(item) {
      this.saving = true
      try {
        await preferredDomainApi.delete(item.domain)
        this.items = this.items.filter((row) => row.domain !== item.domain)
        message.success('已删除')
        this.notifyChange()
      } catch (error) {
        message.error(error.message)
      } finally {
        this.saving = false
      }
    },
    rowProps(record) {
      return {
        class: this.draggingDomain === record.domain ? 'preferred-domain-row-dragging' : '',
        draggable: !this.saving,
        onDragstart: (event) => {
          this.draggingDomain = record.domain
          event.dataTransfer.effectAllowed = 'move'
          event.dataTransfer.setData('text/plain', record.domain)
          const row = event.currentTarget.closest('tr')
          if (row) event.dataTransfer.setDragImage(row, 0, Math.floor(row.offsetHeight / 2))
        },
        onDragover: (event) => {
          if (this.draggingDomain === null || this.saving) return
          event.preventDefault()
          event.dataTransfer.dropEffect = 'move'
        },
        onDrop: (event) => {
          event.preventDefault()
          this.dropOn(record.domain)
        },
        onDragend: () => { this.draggingDomain = null },
      }
    },
    async dropOn(targetDomain) {
      const sourceDomain = this.draggingDomain
      if (!sourceDomain || sourceDomain === targetDomain || this.saving) return

      const sourceIndex = this.items.findIndex((item) => item.domain === sourceDomain)
      const targetIndex = this.items.findIndex((item) => item.domain === targetDomain)
      if (sourceIndex < 0 || targetIndex < 0 || sourceIndex === targetIndex) return

      const previous = [...this.items]
      const next = [...this.items]
      const [moved] = next.splice(sourceIndex, 1)
      next.splice(targetIndex, 0, moved)
      this.items = next
      this.saving = true
      try {
        const response = await preferredDomainApi.sort(next.map((item) => item.domain))
        this.items = response.data || []
        this.notifyChange()
      } catch (error) {
        this.items = previous
        message.error(error.message)
      } finally {
        this.saving = false
        this.draggingDomain = null
      }
    },
    close() {
      this.$emit('update:open', false)
    },
  },
  template: `
    <a-modal :open="open" @update:open="v => $emit('update:open', v)" title="管理优选域名" width="560px" :footer="null">
      <a-typography-paragraph type="secondary">
        创建/编辑自定义主机名时可从这里选择"境内优选 CNAME"目标。同步到 DNSPod 时会下发线路为「境内」的 CNAME。
      </a-typography-paragraph>
      <a-form layout="inline" style="margin-bottom: 12px; width: 100%">
        <a-form-item style="flex: 1">
          <a-input v-model:value="newDomain" placeholder="如 saas.sin.fan" allow-clear @press-enter="addDomain" />
        </a-form-item>
        <a-form-item>
          <a-button type="primary" :loading="saving" @click="addDomain">添加</a-button>
        </a-form-item>
      </a-form>
      <a-table
        :data-source="items"
        :row-key="record => record.domain"
        :loading="loading"
        :pagination="false"
        size="small"
        :custom-row="rowProps"
        :locale="{ emptyText: '暂无优选域名' }"
        :columns="[
          { title: '排序', key: 'sort', width: 60 },
          { title: '域名', dataIndex: 'domain', key: 'domain' },
          { title: '操作', key: 'actions', width: 140, align: 'right' },
        ]"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'sort'">
            <a-typography-text type="secondary" style="cursor: grab" title="拖动调整顺序">☰</a-typography-text>
          </template>
          <template v-else-if="column.key === 'domain'">
            <template v-if="editingDomain === record.domain">
              <a-input v-model:value="editingValue" size="small" @press-enter="saveEdit" />
            </template>
            <template v-else>{{ record.domain }}</template>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space size="small">
              <template v-if="editingDomain === record.domain">
                <a-button type="link" size="small" :loading="saving" @click="saveEdit">保存</a-button>
                <a-button type="link" size="small" @click="cancelEdit">取消</a-button>
              </template>
              <template v-else>
                <a-button type="link" size="small" :disabled="saving" @click="startEdit(record)">编辑</a-button>
                <a-button type="link" size="small" danger :disabled="saving" @click="askDelete(record)">删除</a-button>
              </template>
            </a-space>
          </template>
        </template>
      </a-table>
      <div style="text-align: right; margin-top: 16px">
        <a-button @click="close">关闭</a-button>
      </div>
    </a-modal>
  `,
}
