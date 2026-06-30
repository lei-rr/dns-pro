import { providerSettingsApi } from '../api/providers.js'
import { replaceProvidersCache } from '../../../providers/store.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'

export default {
  data() {
    return {
      providers: [],
      editing: null,
      creating: false,
      form: {},
      createForm: { id: '', name: '', type: 'dnspod' },
      loading: true,
      saving: false,
      sortSaving: false,
      draggingProvider: '',
      providerOperation: null,
      providerDefinitions: null,
      loadRequestToken: 0,
      definitionLoadRequestToken: 0,
    }
  },
  async mounted() {
    await this.loadProviderDefinitions()
    await this.load()
  },
  methods: {
    async loadProviderDefinitions() {
      const requestToken = this.definitionLoadRequestToken + 1
      this.definitionLoadRequestToken = requestToken

      try {
        const definitions = (await providerSettingsApi.providerDefinitions()).data
        if (requestToken !== this.definitionLoadRequestToken) return
        this.providerDefinitions = definitions
      } catch {
        if (requestToken !== this.definitionLoadRequestToken) return
        this.providerDefinitions = null
      }
    },
    async load() {
      const requestToken = this.loadRequestToken + 1
      this.loadRequestToken = requestToken
      this.loading = true

      try {
        const providers = (await providerSettingsApi.providers()).data
        if (requestToken !== this.loadRequestToken) return
        this.providers = providers
        replaceProvidersCache(providers)
      } catch (error) {
        if (requestToken !== this.loadRequestToken) return
        message.error(errorMessage(error))
      } finally {
        if (requestToken === this.loadRequestToken) this.loading = false
      }
    },
    edit(provider) {
      this.editing = provider
      this.form = Object.fromEntries(
        provider.editable_fields.map((field) => [field, this.editFieldValue(provider, field)]),
      )
    },
    openCreate() {
      this.createForm = { id: '', name: '', type: 'dnspod' }
      this.creating = true
    },
    /**
     * 切换类型时，预填关联 provider 字段（dnspod_provider / cloudflare_provider）
     * 避免用户忘记选择导致"参数校验未通过"
     */
    onCreateTypeChange(type) {
      const fields = this.createFields(type)
      const next = { id: this.createForm.id, name: this.createForm.name, type }
      for (const field of fields) {
        next[field] = this.defaultSelectFieldValue(field)
      }
      this.createForm = next
    },
    createFields(type = this.createForm.type) {
      return this.providerDefinition(type)?.fields || []
    },
    moveProvider(sourceId, targetId) {
      const sourceIndex = this.providers.findIndex((provider) => provider.id === sourceId)
      const targetIndex = this.providers.findIndex((provider) => provider.id === targetId)
      if (sourceIndex < 0 || targetIndex < 0 || sourceIndex === targetIndex) return this.providers

      const next = [...this.providers]
      const [moved] = next.splice(sourceIndex, 1)
      next.splice(targetIndex, 0, moved)
      return next
    },
    sortHandleProps(record) {
      return {
        draggable: !this.sortSaving,
        onDragstart: (event) => {
          this.draggingProvider = record.id
          event.dataTransfer.effectAllowed = 'move'
          event.dataTransfer.setData('text/plain', record.id)

          const row = event.currentTarget.closest('tr')
          if (row) event.dataTransfer.setDragImage(row, 0, Math.floor(row.offsetHeight / 2))
        },
        onDragend: () => { this.draggingProvider = '' },
      }
    },
    providerRowProps(record) {
      return {
        class: this.draggingProvider === record.id ? 'provider-sort-row-dragging' : '',
        onDragover: (event) => {
          if (!this.draggingProvider || this.sortSaving) return
          event.preventDefault()
          event.dataTransfer.dropEffect = 'move'
        },
        onDrop: (event) => {
          event.preventDefault()
          this.dropProvider(record.id)
        },
      }
    },
    async dropProvider(targetId) {
      const sourceId = this.draggingProvider
      if (!sourceId || sourceId === targetId || this.sortSaving) return

      const previous = [...this.providers]
      const next = this.moveProvider(sourceId, targetId)
      if (next === this.providers) return
      this.providers = next
      this.sortSaving = true
      try {
        const response = await providerSettingsApi.updateProviderOrder(next.map((provider) => provider.id))
        this.providers = response.data
        replaceProvidersCache(this.providers)
        message.success('API 顺序已保存')
      } catch (error) {
        this.providers = previous
        message.error(errorMessage(error))
      } finally {
        this.sortSaving = false
        this.draggingProvider = ''
      }
    },
    async create() {
      this.saving = true
      try {
        const fields = this.createFields()
        const reserved = ['home', 'login', 'providers', 'user']
        const id = this.createForm.id.trim().toLowerCase()
        if (!id) {
          message.warning('请输入配置标识')
          return
        }
        if (!/^[a-z0-9][a-z0-9_-]{0,63}$/.test(id)) {
          message.warning('配置标识格式不正确')
          return
        }
        if (reserved.includes(id)) {
          message.warning('配置标识不能使用系统路由名称')
          return
        }
        const payload = {
          id,
          name: this.createForm.name.trim(),
          type: this.createForm.type,
        }
        for (const field of fields) {
          let value = String(this.createForm[field] || '').trim()
          // 兜底：provider 关联字段若未选且只有一个候选，自动选中；避免误报"参数校验未通过"
          if (value === '' && this.isProviderSelectField(field)) {
            const candidates = this.selectFieldProviders(field)
            if (candidates.length === 1) value = candidates[0].id
          }
          payload[field] = value
        }
        const missing = this.requiredFields(this.createForm.type).find((field) => !payload[field])
        if (missing) {
          const hint = this.isProviderSelectField(missing) && this.selectFieldProviders(missing).length === 0
            ? `请先创建并配置一个${this.selectFieldPlaceholder(missing).replace('选择', '')}`
            : `${this.fieldLabel(missing)} 不能为空`
          message.warning(hint)
          return
        }
        await providerSettingsApi.createProvider(payload)
        message.success('服务商已添加')
        this.creating = false
        await this.load()
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.saving = false
      }
    },
    async save() {
      if (!this.editing) return
      this.saving = true
      try {
        const payload = Object.fromEntries(this.editing.editable_fields
          .map((field) => [field, String(this.form[field] ?? '').trim()]))
        if (!Object.keys(payload).length) {
          message.warning('请输入要更新的配置')
          return
        }
        await providerSettingsApi.updateProvider(this.editing.id, payload)
        message.success('配置已保存')
        this.editing = null
        await this.load()
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.saving = false
      }
    },
    askDelete(provider) {
      const dependencies = this.providerReferenceDependencies(provider)
      const content = dependencies.length
        ? Vue.h('div', { style: 'white-space: pre-wrap' }, `确认删除 ${provider.name}（${provider.id}）？删除后该服务商配置会被移除。\n\n依赖关系：\n${dependencies.map((item) => `- ${item.name}（${item.id}）`).join('\n')}\n\n请先修改或删除上述关联配置。`)
        : `确认删除 ${provider.name}（${provider.id}）？删除后该服务商配置会被移除。`
      modal.confirm({
        title: '删除服务商配置',
        content,
        okText: '删除',
        okType: 'danger',
        okButtonProps: { disabled: dependencies.length > 0 },
        cancelText: '取消',
        onOk: () => this.remove(provider),
      })
    },
    async remove(provider) {
      if (this.providerOperation) return
      this.providerOperation = { providerId: provider.id, action: 'delete' }
      try {
        await providerSettingsApi.deleteProvider(provider.id)
        message.success('服务商配置已删除')
        await this.load()
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.providerOperation = null
      }
    },
    providerOperationLoading(providerId, action) {
      return this.providerOperation?.providerId === providerId && this.providerOperation?.action === action
    },
    providerReferenceDependencies(provider) {
      if (provider.type === 'dnspod') {
        return this.providers.filter((item) => 
          (item.type === 'edgeone' && item.dnspod_provider === provider.id) ||
          (item.type === 'hostname' && item.dnspod_provider === provider.id)
        )
      }

      if (provider.type === 'cloudflare') {
        return this.providers.filter((item) =>
          (item.type === 'hostname' && item.cloudflare_provider === provider.id) ||
          (item.type === 'hostname' && item.cloudflare_dns_provider === provider.id) ||
          (item.type === 'cloudflared' && item.cloudflare_provider === provider.id)
        )
      }

      return []
    },
    fieldLabel(field) {
      return this.providerDefinitions?.labels?.[field] || field
    },
    editFieldValue(provider, field) {
      if (this.isSecretField(field)) return ''
      if (!this.isProviderSelectField(field)) return provider[field] || ''
      return this.defaultSelectFieldValue(field, provider[field] || '')
    },
    defaultSelectFieldValue(field, currentValue = '') {
      if (!this.isProviderSelectField(field)) return currentValue
      return currentValue || this.selectFieldProviders(field)[0]?.id || ''
    },
    requiredFields(type) {
      return this.providerDefinition(type)?.required || []
    },
    providerDefinition(type) {
      return this.providerDefinitions?.types?.find((providerType) => providerType.type === type) || null
    },
    isProviderSelectField(field) {
      return field === 'dnspod_provider' || field === 'cloudflare_provider'
    },
    selectFieldProviders(field) {
      if (field === 'dnspod_provider') return this.dnspodProviders
      if (field === 'cloudflare_provider') return this.cloudflareProviders
      return []
    },
    selectFieldPlaceholder(field) {
      if (field === 'dnspod_provider') return '选择 DNSPod API'
      if (field === 'cloudflare_provider') return '选择 Cloudflare API'
      return '请选择'
    },
    isSecretField(field) {
      return field.includes('key') || field.includes('token')
    },
    configTags(provider) {
      return provider.editable_fields.map((field) => ({
        label: this.fieldLabel(field),
        value: provider.fields[field] || '未配置',
      }))
    },
  },
  computed: {
    dnspodProviders() {
      return this.providers.filter((provider) => provider.type === 'dnspod' && provider.configured)
    },
    cloudflareProviders() {
      return this.providers.filter((provider) => provider.type === 'cloudflare' && provider.configured)
    },
    providerTypes() {
      return this.providerDefinitions?.types || []
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-typography-title :level="3" style="margin-bottom: 4px">服务商</a-typography-title>
          <a-typography-text type="secondary">管理 DNS 和 EdgeOne 服务商配置。已保存的密钥不会明文显示。</a-typography-text>
        </div>
        <div class="page-actions">
          <a-button type="primary" @click="openCreate">新增服务商</a-button>
          <a-button :loading="loading" @click="load">刷新</a-button>
        </div>
      </div>
      <a-table
        :data-source="providers"
        :row-key="provider => provider.id"
        :loading="loading"
        :pagination="false"
        :columns="[
          { title: '排序', key: 'sort', width: 70 },
          { title: '服务商', key: 'name', width: 180 },
          { title: '状态', key: 'status', width: 120 },
          { title: '配置', key: 'fields', width: 300 },
          { title: '操作', key: 'actions', width: 270, align: 'right' },
        ]"
        size="middle"
        :scroll="{ x: 890 }"
        :custom-row="providerRowProps"
        :locale="{ emptyText: '暂无服务商配置' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'sort'">
            <a-typography-text type="secondary" style="cursor: grab" title="拖动调整顺序" v-bind="sortHandleProps(record)">☰</a-typography-text>
          </template>
          <template v-else-if="column.key === 'name'">
            <a-space><a-tag>{{ record.type }}</a-tag><span>{{ record.name }}</span></a-space>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="record.configured ? 'green' : 'default'">{{ record.configured ? '已配置' : '未配置' }}</a-tag>
          </template>
          <template v-else-if="column.key === 'fields'">
            <a-space wrap>
              <a-tag v-for="item in configTags(record)" :key="item.label">{{ item.label }}: {{ item.value }}</a-tag>
            </a-space>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space size="small">
              <a-button type="link" size="small" :disabled="!!providerOperation" @click="edit(record)">更新</a-button>
              <a-button type="link" size="small" danger :loading="providerOperationLoading(record.id, 'delete')" :disabled="!!providerOperation" @click="askDelete(record)">删除</a-button>
            </a-space>
          </template>
        </template>
      </a-table>
      <a-modal :open="!!editing" :title="editing ? '更新 ' + editing.name + ' 服务商配置' : ''" :confirm-loading="saving" ok-text="保存" cancel-text="取消" @ok="save" @cancel="editing = null" @update:open="open => { if (!open) editing = null }">
        <a-alert type="info" show-icon style="margin-bottom: 16px" message="留空的字段不会覆盖现有配置；如需清空请使用清除。" />
        <a-form v-if="editing" layout="vertical">
          <a-form-item v-for="field in editing.editable_fields" :key="field" :label="fieldLabel(field)">
            <a-select v-if="isProviderSelectField(field)" v-model:value="form[field]" :placeholder="selectFieldPlaceholder(field)">
              <a-select-option v-for="provider in selectFieldProviders(field)" :key="provider.id" :value="provider.id">{{ provider.name }}（{{ provider.id }}）</a-select-option>
            </a-select>
            <a-input-password v-else-if="isSecretField(field)" v-model:value="form[field]" :placeholder="editing.fields[field] || '未配置'" />
            <a-input v-else v-model:value="form[field]" :placeholder="editing.fields[field] || '未配置'" />
          </a-form-item>
        </a-form>
      </a-modal>
      <a-modal v-model:open="creating" title="新增服务商" :confirm-loading="saving" ok-text="保存" cancel-text="取消" @ok="create">
        <a-form layout="vertical">
          <a-form-item label="类型" required>
            <a-select :value="createForm.type" @change="onCreateTypeChange">
              <a-select-option v-for="providerType in providerTypes" :key="providerType.type" :value="providerType.type">{{ providerType.name }}</a-select-option>
            </a-select>
          </a-form-item>
          <a-form-item label="配置标识" required>
            <a-input v-model:value="createForm.id" placeholder="例如 dnspod-main / dnspod-work" />
            <a-typography-text type="secondary">用于区分多个账号，也会作为访问路径；只能用字母、数字、-、_，不能使用 home、login、providers、user</a-typography-text>
          </a-form-item>
          <a-form-item label="显示名称">
            <a-input v-model:value="createForm.name" placeholder="留空使用默认名称" />
          </a-form-item>
          <a-form-item v-for="field in createFields()" :key="field" :label="fieldLabel(field)" :required="requiredFields(createForm.type).includes(field)">
            <a-select v-if="isProviderSelectField(field)" v-model:value="createForm[field]" :placeholder="selectFieldPlaceholder(field)">
              <a-select-option v-for="provider in selectFieldProviders(field)" :key="provider.id" :value="provider.id">{{ provider.name }}（{{ provider.id }}）</a-select-option>
            </a-select>
            <a-input-password v-else-if="isSecretField(field)" v-model:value="createForm[field]" />
            <a-input v-else v-model:value="createForm[field]" />
          </a-form-item>
        </a-form>
      </a-modal>
    </section>
  `,
}
