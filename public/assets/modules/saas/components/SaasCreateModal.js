import { filterOption } from '../utils/saas.js'

export default {
  name: 'SaasCreateModal',
  props: {
    open: { type: Boolean, default: false },
    title: { type: String, default: '新增自定义主机名' },
    okText: { type: String, default: '创建' },
    confirmLoading: { type: Boolean, default: false },
    dnspodLinked: { type: Boolean, default: false },
    cloudflareDnsLinked: { type: Boolean, default: false },
    dnspodProviders: { type: Array, default: () => [] },
    cloudflareDnsProviders: { type: Array, default: () => [] },
    dnspodZones: { type: Object, default: () => ({}) },
    cloudflareDnsZones: { type: Object, default: () => ({}) },
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
    dnspodZones() { this.ensureSyncDefaults() },
    cloudflareDnsZones() { this.ensureSyncDefaults() },
    'form.sync_target'(value) {
      if (!value) return
      this.form.sync_provider_id = this.defaultSyncProviderId(value)
      this.form.sync_zone = this.defaultSyncZone(value)
    },
    'form.sync_provider_id'(value) {
      if (!value) return
      this.form.sync_zone = this.defaultSyncZone(this.form.sync_target, value)
    },
    'form.autoPreferred'(value) {
      if (value && !this.form.preferred_domain) {
        this.form.preferred_domain = this.firstPreferred()
      }
    },
    preferredDomains() {
      if (this.open && this.form.autoPreferred && !this.form.preferred_domain) {
        this.form.preferred_domain = this.firstPreferred()
      }
    },
  },
  computed: {
    syncProviderOptions() {
      return [
        ...(this.cloudflareDnsProviders || []).map((provider) => ({
          label: `${provider.name}（Cloudflare）`,
          value: provider.id,
        })),
        ...(this.dnspodProviders || []).map((provider) => ({
          label: `${provider.name}（DNSPod）`,
          value: provider.id,
        })),
      ]
    },
    selectedSyncTarget() {
      if ((this.cloudflareDnsProviders || []).some((provider) => provider.id === this.form.sync_provider_id)) return 'cloudflare_dns'
      if ((this.dnspodProviders || []).some((provider) => provider.id === this.form.sync_provider_id)) return 'dnspod'
      return ''
    },
    activeSyncZones() {
      if (this.selectedSyncTarget === 'dnspod') return this.dnspodZones?.[this.form.sync_provider_id] || []
      if (this.selectedSyncTarget === 'cloudflare_dns') return this.cloudflareDnsZones?.[this.form.sync_provider_id] || []
      return []
    },
    syncPlatformLabel() {
      if (this.selectedSyncTarget === 'dnspod') return 'DNSPod'
      if (this.selectedSyncTarget === 'cloudflare_dns') return 'Cloudflare'
      return ''
    },
    usesGuidedHostname() {
      return !this.editing && !!this.form.sync_provider_id
    },
    hostnamePreview() {
      if (!this.usesGuidedHostname || !this.form.sync_zone) return ''
      const prefix = String(this.form.hostname_prefix || '').trim().toLowerCase()
      return prefix ? `${prefix}.${this.form.sync_zone}` : this.form.sync_zone
    },
    preferredOptions() {
      return (this.preferredDomains || []).map((item) => ({
        value: item.domain ?? item.value,
        label: item.domain ?? item.label,
      }))
    },
    hostnameEmpty() {
      return !String(this.usesGuidedHostname ? this.hostnamePreview : this.form.hostname || '').trim()
    },
    originRequired() {
      return this.form.use_custom_origin_server && !String(this.form.custom_origin_server || '').trim()
    },
    preferredRequired() {
      return this.form.autoPreferred && !String(this.form.preferred_domain || '').trim()
    },
    syncZoneRequired() {
      return this.usesGuidedHostname && !String(this.form.sync_zone || '').trim()
    },
    canSubmit() {
      return !this.hostnameEmpty && !this.originRequired && !this.syncZoneRequired && !this.preferredRequired
    },
  },
  methods: {
    firstPreferred() {
      return this.preferredOptions[0]?.value || ''
    },
    defaultSyncZone(providerId = '') {
      const currentProviderId = providerId || this.form?.sync_provider_id || ''
      const zones = this.dnspodZones?.[currentProviderId] || this.cloudflareDnsZones?.[currentProviderId] || []
      return zones[0]?.name || ''
    },
    defaultSyncProviderId() {
      return this.cloudflareDnsProviders?.[0]?.id || this.dnspodProviders?.[0]?.id || ''
    },
    ensureSyncDefaults() {
      if (!this.open || this.editing) return
      if (!this.form.sync_provider_id) this.form.sync_provider_id = this.defaultSyncProviderId()
      if (this.form.sync_provider_id && !this.form.sync_zone) this.form.sync_zone = this.defaultSyncZone(this.form.sync_provider_id)
    },
    normalizeInitialValue() {
      const current = this.initialValue || {}
      const customOriginServer = String(current.custom_origin_server || '').trim()

      return {
        hostname: String(current.hostname || '').trim(),
        hostname_prefix: '',
        custom_origin_server: customOriginServer,
        method: String(current.ssl?.method || 'txt').trim() || 'txt',
        min_tls_version: String(current.ssl?.settings?.min_tls_version || '1.0').trim() || '1.0',
        use_custom_origin_server: customOriginServer !== '',
        autoPreferred: Boolean(current.auto_preferred),
        preferred_domain: String(current.custom_metadata?.preferred_domain || '').trim(),
        sync_provider_id: String(current.sync_provider_id || '').trim(),
        sync_zone: String(current.sync_zone || '').trim(),
      }
    },
    defaultForm() {
      if (this.editing) return this.normalizeInitialValue()

      const syncProviderId = this.defaultSyncProviderId()

      return {
        hostname: '',
        hostname_prefix: '',
        custom_origin_server: '',
        method: 'txt',
        min_tls_version: '1.0',
        use_custom_origin_server: true,
        autoPreferred: true,
        preferred_domain: this.dnspodLinked ? this.firstPreferred() : '',
        sync_provider_id: syncProviderId,
        sync_zone: this.defaultSyncZone(syncProviderId),
      }
    },
    filterOption,
    submit() {
      if (!this.canSubmit) return
      const hostname = this.usesGuidedHostname
        ? this.hostnamePreview
        : String(this.form.hostname || '').trim()
      this.$emit('submit', {
        ...this.form,
        sync_target: this.selectedSyncTarget,
        hostname,
      })
    },
  },
  template: `
    <a-modal :open="open" @update:open="v => $emit('update:open', v)" :title="title" :confirm-loading="confirmLoading" :ok-button-props="{ disabled: !canSubmit }" :ok-text="okText" cancel-text="取消" @ok="submit">
      <a-form layout="vertical">
        <a-typography-title :level="5">主机名设置</a-typography-title>
        <a-form-item v-if="editing || !usesGuidedHostname" label="主机名" required>
          <a-input v-model:value="form.hostname" placeholder="app.example.com" :disabled="editing" />
        </a-form-item>
        <template v-else>
          <a-row :gutter="12">
            <a-col :span="12">
              <a-form-item label="同步服务商">
                <a-select v-model:value="form.sync_provider_id" :options="syncProviderOptions" placeholder="选择服务商" />
              </a-form-item>
            </a-col>
          </a-row>
          <a-row :gutter="12">
            <a-col :span="12">
              <a-form-item label="主机名前缀">
                <a-input v-model:value="form.hostname_prefix" placeholder="如 app；留空表示根域名" />
              </a-form-item>
            </a-col>
            <a-col :span="12">
              <a-form-item label="同步域名">
                <a-select v-model:value="form.sync_zone" :options="activeSyncZones.map(zone => ({ label: zone.name, value: zone.name }))" placeholder="选择域名" show-search option-filter-prop="label" />
              </a-form-item>
            </a-col>
          </a-row>
        </template>

        <a-typography-title :level="5">证书与回源</a-typography-title>
        <a-row :gutter="12">
          <a-col :span="12">
            <a-form-item label="DCV 认证">
              <a-select v-model:value="form.method">
                <a-select-option value="txt">TXT 验证（推荐）</a-select-option>
                <a-select-option value="http">HTTP 验证</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="最低 TLS 版本">
              <a-select v-model:value="form.min_tls_version">
                <a-select-option value="1.0">TLS 1.0（默认）</a-select-option>
                <a-select-option value="1.1">TLS 1.1</a-select-option>
                <a-select-option value="1.2">TLS 1.2</a-select-option>
                <a-select-option value="1.3">TLS 1.3</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
        </a-row>
        <a-form-item label="证书类型">
          <a-typography-text>由 Cloudflare 提供</a-typography-text>
        </a-form-item>
        <a-form-item
          :validate-status="originRequired ? 'error' : ''"
          :help="form.use_custom_origin_server ? '' : '关闭后使用默认回退源'"
        >
          <a-space>
            <a-typography-title :level="5" style="margin: 0;">自定义源服务器</a-typography-title>
            <a-switch v-model:checked="form.use_custom_origin_server" size="small" />
          </a-space>
          <a-auto-complete
            v-if="form.use_custom_origin_server"
            v-model:value="form.custom_origin_server"
            :options="originSuggestions"
            :filter-option="filterOption"
            placeholder="输入或从已用源服务器选择，如 origin.example.com"
            style="margin-top: 12px; width: 100%"
          />
        </a-form-item>

        <template v-if="selectedSyncTarget === 'dnspod' || selectedSyncTarget === 'cloudflare_dns'">
        <a-form-item>
          <a-space>
            <a-typography-title :level="5" style="margin: 0;">自动优选</a-typography-title>
            <a-switch v-model:checked="form.autoPreferred" size="small" />
          </a-space>
        </a-form-item>
        <a-form-item v-if="form.autoPreferred && (selectedSyncTarget === 'dnspod' || selectedSyncTarget === 'cloudflare_dns')" :label="selectedSyncTarget === 'cloudflare_dns' ? '优选域名' : '境内优选 CNAME'">
          <a-select v-model:value="form.preferred_domain" allow-clear :placeholder="selectedSyncTarget === 'cloudflare_dns' ? '可选；选择后主业务 CNAME 直接指向该优选域名' : '可选；选择后会同步一条 CNAME（线路：境内）'">
            <a-select-option v-for="opt in preferredOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</a-select-option>
          </a-select>
        </a-form-item>
        </template>
      </a-form>
    </a-modal>
  `,
}
