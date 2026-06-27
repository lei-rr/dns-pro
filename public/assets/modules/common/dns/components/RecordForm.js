import { message } from '../../../../shared/plugins/antDesignVue.js'
import { defaultProviderHook } from '../hook.js'

export default {
  props: { modelValue: Object, saving: Boolean, providerHook: Object, lines: Array },
  emits: ['save', 'cancel', 'delete'],
  data() {
    return {
      form: { id: '', name: '@', type: 'A', value: '', ttl: 600, priority: '', remark: '', proxied: false },
    }
  },
  computed: {
    showTtl() {
      return this.hook.showTtl
    },
    showPriority() {
      return this.form.type === 'MX'
    },
    showProxy() {
      return this.hook.proxyTypes.includes(this.form.type)
    },
    showLine() {
      return this.hook.showLine(this.lines || [])
    },
    lineOptions() {
      const options = [...(this.lines || [])]
      const current = String(this.form.line || '').trim()
      if (current && !options.some((line) => line.value === current)) {
        options.push({ label: current, value: current })
      }
      return options
    },
    hook() {
      return this.providerHook || defaultProviderHook
    },
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(value) {
        this.form = { id: '', name: '@', type: 'A', value: '', ttl: 600, priority: '', remark: '', line: '默认', proxied: false, ...(value || {}) }
        if (this.form.proxied === undefined) this.form.proxied = false
      },
    },
  },
  methods: {
    submit() {
      const error = this.validate()
      if (error) {
        message.error(error)
        return
      }

      this.$emit('save', this.form)
    },
    validate() {
      const type = String(this.form.type || '').toUpperCase()
      const name = String(this.form.name || '').trim()
      const value = String(this.form.value || '').trim()

      if (!name) return '主机记录不能为空'
      if (!type) return '记录类型不能为空'
      if (!value) return '记录值不能为空'
      if (!this.validRecordName(name)) return '主机记录格式不正确'

      if (this.showTtl) {
        const ttl = Number(this.form.ttl)
        if (!Number.isInteger(ttl) || ttl < 1 || ttl > 604800) return 'TTL 必须是 1 到 604800 之间的整数'
      }

      if (type === 'A' && !this.validIpv4(value)) return 'A 记录值必须是有效 IPv4 地址'
      if (type === 'AAAA' && !this.validIpv6(value)) return 'AAAA 记录值必须是有效 IPv6 地址'
      if (['CNAME', 'NS'].includes(type) && !this.validHostname(value)) return `${type} 记录值必须是有效域名`
      if (type === 'MX') {
        if (!this.validHostname(value)) return 'MX 记录值必须是有效域名'
        const priority = Number(this.form.priority || 10)
        if (!Number.isInteger(priority) || priority < 0 || priority > 65535) return 'MX 优先级必须是 0 到 65535 之间的整数'
      }

      return ''
    },
    validRecordName(value) {
      if (value === '@') return true
      return /^(\*\.)?([a-z0-9_-]{1,63}\.)*[a-z0-9_-]{1,63}$/i.test(value)
    },
    validHostname(value) {
      return /^(?!-)([a-z0-9-]{1,63}\.)+[a-z]{2,63}\.?$/i.test(value)
    },
    validIpv4(value) {
      const parts = value.split('.')
      return parts.length === 4 && parts.every((part) => /^\d+$/.test(part) && Number(part) >= 0 && Number(part) <= 255)
    },
    validIpv6(value) {
      if (!value) return false
      const v = value.toLowerCase()
      // 处理 :: 压缩:替换为 :0: 补齐
      const hasDoubleColon = v.includes('::')
      if (hasDoubleColon) {
        const parts = v.split('::')
        if (parts.length > 2) return false
        const left = parts[0] ? parts[0].split(':') : []
        const right = parts[1] ? parts[1].split(':') : []
        const missing = 8 - left.length - right.length
        if (missing < 1) return false
        return [...left, ...Array(missing).fill('0'), ...right].every((p) => /^[0-9a-f]{1,4}$/.test(p))
      }
      const parts = v.split(':')
      if (parts.length !== 8) return false
      return parts.every((p) => /^[0-9a-f]{1,4}$/.test(p))
    },
  },
  template: `
    <a-form layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :sm="12">
          <a-form-item label="主机记录" required>
            <a-input v-model:value="form.name" placeholder="@ / www / api" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :sm="12">
          <a-form-item label="记录类型" required>
            <a-select v-model:value="form.type">
              <a-select-option value="A">A</a-select-option>
              <a-select-option value="AAAA">AAAA</a-select-option>
              <a-select-option value="CNAME">CNAME</a-select-option>
              <a-select-option value="TXT">TXT</a-select-option>
              <a-select-option value="MX">MX</a-select-option>
              <a-select-option value="NS">NS</a-select-option>
              <a-select-option value="SRV">SRV</a-select-option>
              <a-select-option value="CAA">CAA</a-select-option>
            </a-select>
          </a-form-item>
        </a-col>
        <a-col :span="24">
          <a-form-item label="记录值" required>
            <a-input v-model:value="form.value" placeholder="记录值" />
          </a-form-item>
        </a-col>
        <a-col v-if="showLine" :xs="24" :sm="12">
          <a-form-item :label="hook.lineLabel">
            <a-select v-model:value="form.line">
              <a-select-option v-for="line in lineOptions" :key="line.value" :value="line.value">{{ line.label }}</a-select-option>
            </a-select>
          </a-form-item>
        </a-col>
        <a-col v-if="showTtl" :xs="24" :sm="12">
          <a-form-item label="TTL">
            <a-input-number v-model:value="form.ttl" :min="1" :max="604800" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col v-if="showPriority" :xs="24" :sm="12">
          <a-form-item label="MX 优先级">
            <a-input-number v-model:value="form.priority" :min="0" :max="65535" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :span="24">
          <a-form-item label="备注">
            <a-input v-model:value="form.remark" placeholder="可选" />
          </a-form-item>
        </a-col>
        <a-col v-if="showProxy" :span="24">
          <a-form-item>
            <a-checkbox v-model:checked="form.proxied">{{ hook.proxyLabel }}</a-checkbox>
          </a-form-item>
        </a-col>
      </a-row>
      <div class="modal-form-actions">
        <a-button v-if="form.id" danger :disabled="saving" @click="$emit('delete', form)">删除</a-button>
        <span v-else></span>
        <div class="modal-form-actions-main">
          <a-button :disabled="saving" @click="$emit('cancel')">取消</a-button>
          <a-button type="primary" :loading="saving" @click="submit">保存</a-button>
        </div>
      </div>
    </a-form>
  `,
}
