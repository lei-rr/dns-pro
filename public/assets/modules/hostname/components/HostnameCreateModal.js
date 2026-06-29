import { filterOption } from '../utils/hostname.js'

export default {
  name: 'HostnameCreateModal',
  props: {
    open: { type: Boolean, default: false },
    title: { type: String, default: '新增自定义主机名' },
    okText: { type: String, default: '创建' },
    confirmLoading: { type: Boolean, default: false },
    dnspodLinked: { type: Boolean, default: false },
    originSuggestions: { type: Array, default: () => [] },
    preferredDomains: { type: Array, default: () => [] },
    initialValue: { type: Object, default: null },
    editing: { type: Boolean, default: false },
  },
  emits: ['update:open', 'submit'],
  data() {
    return { form: this.defaultForm() }
  },
  watch: {
    open(value) { if (value) this.form = this.defaultForm() },
    preferredDomains() {
      if (this.open && this.dnspodLinked && !this.editing && !this.form.preferred_domain) {
        this.form.preferred_domain = this.firstPreferred()
      }
    },
  },
  computed: {
    preferredOptions() {
      return (this.preferredDomains || []).map((item) => ({
        value: item.domain ?? item.value,
        label: item.domain ?? item.label,
      }))
    },
    hostnameEmpty() {
      return !String(this.form.hostname || '').trim()
    },
    originRequired() {
      return this.form.use_custom_origin_server && !String(this.form.custom_origin_server || '').trim()
    },
    canSubmit() {
      return !this.hostnameEmpty && !this.originRequired
    },
  },
  methods: {
    firstPreferred() {
      return this.preferredOptions[0]?.value || ''
    },
    normalizeInitialValue() {
      const current = this.initialValue || {}
      const customOriginServer = String(current.custom_origin_server || '').trim()

      return {
        hostname: String(current.hostname || '').trim(),
        custom_origin_server: customOriginServer,
        method: String(current.ssl?.method || 'txt').trim() || 'txt',
        min_tls_version: String(current.ssl?.settings?.min_tls_version || '1.0').trim() || '1.0',
        use_custom_origin_server: customOriginServer !== '',
        autoSync: this.dnspodLinked,
        preferred_domain: String(current.custom_metadata?.preferred_domain || '').trim(),
      }
    },
    defaultForm() {
      if (this.editing) return this.normalizeInitialValue()

      return {
        hostname: '',
        custom_origin_server: '',
        method: 'txt',
        min_tls_version: '1.0',
        use_custom_origin_server: true,
        autoSync: this.dnspodLinked,
        preferred_domain: this.dnspodLinked ? this.firstPreferred() : '',
      }
    },
    filterOption,
    submit() {
      if (!this.canSubmit) return
      this.$emit('submit', { ...this.form })
    },
  },
  template: `
    <a-modal :open="open" @update:open="v => $emit('update:open', v)" :title="title" :confirm-loading="confirmLoading" :ok-button-props="{ disabled: !canSubmit }" :ok-text="okText" cancel-text="取消" @ok="submit">
      <a-form layout="vertical">
        <a-form-item label="主机名" required>
          <a-input v-model:value="form.hostname" placeholder="app.example.com" :disabled="editing" />
        </a-form-item>
        <a-form-item label="DCV 认证">
          <a-select v-model:value="form.method">
            <a-select-option value="txt">TXT 验证（推荐）</a-select-option>
            <a-select-option value="http">HTTP 验证</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="最低 TLS 版本">
          <a-select v-model:value="form.min_tls_version">
            <a-select-option value="1.0">TLS 1.0（默认）</a-select-option>
            <a-select-option value="1.1">TLS 1.1</a-select-option>
            <a-select-option value="1.2">TLS 1.2</a-select-option>
            <a-select-option value="1.3">TLS 1.3</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="证书类型">
          <a-typography-text>由 Cloudflare 提供</a-typography-text>
        </a-form-item>
        <a-form-item
          label="自定义源服务器"
          :validate-status="originRequired ? 'error' : ''"
        >
          <a-switch v-model:checked="form.use_custom_origin_server" />
          <a-auto-complete
            v-if="form.use_custom_origin_server"
            v-model:value="form.custom_origin_server"
            :options="originSuggestions"
            :filter-option="filterOption"
            placeholder="输入或从已用源服务器选择，如 origin.example.com"
            style="margin-top: 12px; width: 100%"
          />
        </a-form-item>
        <a-form-item v-if="dnspodLinked" label="自动同步 DNSPod">
          <a-switch v-model:checked="form.autoSync" />
        </a-form-item>
        <a-form-item v-if="dnspodLinked && form.autoSync" label="境内优选 CNAME">
          <a-select v-model:value="form.preferred_domain" allow-clear placeholder="可选；选择后会同步一条 CNAME（线路：境内）">
            <a-select-option v-for="opt in preferredOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</a-select-option>
          </a-select>
        </a-form-item>
      </a-form>
    </a-modal>
  `,
}
