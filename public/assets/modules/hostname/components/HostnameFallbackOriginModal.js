import { hostnameApi } from '../utils/api.js'
import { statusColor, statusLabel } from '../utils/hostname.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'

/**
 * Zone 级默认回源(fallback origin)管理弹窗
 *
 * 行为:
 *   - 打开时拉取当前 zone 的 fallback origin 状态
 *   - Cloudflare 要求 fallback origin 必须在该 zone 内(必须以 .<zoneName> 结尾)
 *   - 操作成功后通过 `updated` 事件通知父组件刷新 hostname 列表
 */

// fallback origin 的状态映射(Cloudflare 返回 initializing / pending_deployment / active / pending_deletion)
const FALLBACK_STATUS_LABELS = {
  initializing: '初始化中',
  pending_deployment: '待部署',
  pending_deletion: '删除中',
  active: '已生效',
}

const FALLBACK_STATUS_COLORS = {
  initializing: 'gold',
  pending_deployment: 'gold',
  pending_deletion: 'red',
  active: 'green',
}

export default {
  name: 'HostnameFallbackOriginModal',
  props: {
    open: { type: Boolean, default: false },
    provider: { type: String, required: true },
    zoneName: { type: String, required: true },
  },
  emits: ['update:open', 'updated'],
  data() {
    return {
      loading: false,
      saving: false,
      deleting: false,
      enabled: false,
      origin: '',
      currentOrigin: '',
      currentStatus: '',
      currentErrors: [],
    }
  },
  computed: {
    trimmedOrigin() {
      return String(this.origin || '').trim().toLowerCase()
    },
    requiredSuffix() {
      return '.' + String(this.zoneName || '').toLowerCase()
    },
    originRequired() {
      return this.enabled && !this.trimmedOrigin
    },
    originSuffixInvalid() {
      // 必须以 .<zoneName> 结尾,且不能等于 zoneName 自身
      if (!this.enabled || !this.trimmedOrigin) return false
      const v = this.trimmedOrigin
      const suffix = this.requiredSuffix
      return v === this.zoneName.toLowerCase() || !v.endsWith(suffix) || v === suffix.slice(1)
    },
    originError() {
      if (this.originRequired) return '请输入源服务器地址'
      if (this.originSuffixInvalid) return `源服务器必须是 ${this.zoneName} 的子域名(如 origin${this.requiredSuffix})`
      return ''
    },
    canSave() {
      if (this.originRequired || this.originSuffixInvalid) return false
      const next = this.enabled ? this.trimmedOrigin : ''
      return next !== this.currentOrigin
    },
    hasExisting() {
      return !!this.currentOrigin
    },
    statusLabel() {
      return FALLBACK_STATUS_LABELS[this.currentStatus] || (this.currentStatus || '-')
    },
    statusColor() {
      return FALLBACK_STATUS_COLORS[this.currentStatus] || (this.currentStatus ? 'blue' : 'default')
    },
    errorMessages() {
      return (this.currentErrors || []).map((e) => {
        if (typeof e === 'string') return e
        return e?.message || e?.error || JSON.stringify(e)
      }).filter(Boolean)
    },
  },
  watch: {
    open(value) {
      if (value) this.load()
    },
  },
  methods: {
    async load() {
      this.loading = true
      try {
        const response = await hostnameApi.fallbackOrigin(this.provider, this.zoneName, { refresh: true })
        const data = response.data || {}
        const origin = data.origin || ''
        this.currentOrigin = origin
        this.origin = origin
        this.enabled = !!origin
        this.currentStatus = data.status || ''
        this.currentErrors = Array.isArray(data.errors) ? data.errors : []
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.loading = false
      }
    },
    async save() {
      if (!this.canSave) return
      if (!this.enabled) {
        this.askDelete()
        return
      }
      this.saving = true
      try {
        const response = await hostnameApi.setFallbackOrigin(this.provider, this.zoneName, this.trimmedOrigin)
        const data = response.data || {}
        this.currentOrigin = data.origin || this.trimmedOrigin
        this.origin = this.currentOrigin
        this.currentStatus = data.status || ''
        this.currentErrors = Array.isArray(data.errors) ? data.errors : []
        message.success('默认回源已保存')
        this.$emit('updated', this.currentOrigin)
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.saving = false
      }
    },
    askDelete() {
      modal.confirm({
        title: '删除默认回源',
        content: `确认删除 ${this.zoneName} 的默认回源?删除后,未配置自定义源服务器的主机名将无法回源。`,
        okText: '删除', okType: 'danger', cancelText: '取消',
        onOk: () => this.doDelete(),
      })
    },
    async doDelete() {
      this.deleting = true
      try {
        await hostnameApi.deleteFallbackOrigin(this.provider, this.zoneName)
        this.currentOrigin = ''
        this.origin = ''
        this.enabled = false
        this.currentStatus = ''
        this.currentErrors = []
        message.success('默认回源已删除')
        this.$emit('updated', '')
        this.$emit('update:open', false)
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.deleting = false
      }
    },
    close() {
      this.$emit('update:open', false)
    },
  },
  template: `
    <a-modal
      :open="open"
      @update:open="v => $emit('update:open', v)"
      :title="'默认回源 · ' + zoneName"
      :confirm-loading="saving"
      :ok-button-props="{ disabled: !canSave }"
      ok-text="保存"
      cancel-text="取消"
      @ok="save"
    >
      <a-spin :spinning="loading">
        <a-alert
          v-if="errorMessages.length"
          type="error"
          show-icon
          style="margin-bottom: 16px"
        >
          <template #description>
            <ul style="margin: 0; padding-left: 18px">
              <li v-for="(msg, i) in errorMessages" :key="i">{{ msg }}</li>
            </ul>
          </template>
        </a-alert>
        <a-form layout="vertical">
          <a-form-item v-if="hasExisting" label="当前状态">
            <a-tag :color="statusColor">{{ statusLabel }}</a-tag>
            <a-typography-text v-if="hasExisting" type="secondary" style="margin-left: 8px">{{ currentOrigin }}</a-typography-text>
          </a-form-item>
          <a-form-item label="启用默认回源">
            <a-switch v-model:checked="enabled" />
          </a-form-item>
          <a-form-item
            v-if="enabled"
            label="源服务器"
            required
            :validate-status="(originRequired || originSuffixInvalid) ? 'error' : ''"
            :help="originError || ('如 origin' + requiredSuffix)"
          >
            <a-input v-model:value="origin" :placeholder="'origin' + requiredSuffix" @press-enter="save" />
          </a-form-item>
          <a-form-item v-if="hasExisting">
            <a-button danger :loading="deleting" @click="askDelete">删除默认回源</a-button>
          </a-form-item>
        </a-form>
      </a-spin>
    </a-modal>
  `,
}
