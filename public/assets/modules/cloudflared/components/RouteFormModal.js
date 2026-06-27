import { protocolOptions, parseServiceUrl, hostnamePrefix } from '../utils/format.js'

export default {
  name: 'RouteFormModal',
  props: {
    open: { type: Boolean, default: false },
    confirmLoading: { type: Boolean, default: false },
    zones: { type: Array, default: () => [] },
    initialRoute: { type: Object, default: null },
  },
  emits: ['update:open', 'submit'],
  data() {
    return { form: this.defaultForm() }
  },
  computed: {
    protocols() { return protocolOptions },
    zoneOptions() {
      return this.zones.map((zone) => ({ value: zone.id, label: zone.name, name: zone.name }))
    },
    selectedZone() {
      return this.zoneOptions.find((z) => z.value === this.form.zone_id) || null
    },
    fullHostname() {
      const prefix = String(this.form.prefix || '').trim().toLowerCase()
      const zone = this.selectedZone?.name || ''
      if (!zone) return ''
      if (prefix === '' || prefix === '@') return zone
      return `${prefix}.${zone}`
    },
    canSubmit() {
      return Boolean(this.form.zone_id && this.fullHostname && String(this.form.address || '').trim())
    },
    title() {
      return this.initialRoute ? '编辑路由' : '添加路由'
    },
  },
  watch: {
    open(value) {
      if (value) this.form = this.defaultForm()
    },
  },
  methods: {
    /**
     * 按最长后缀匹配 zone（避免 co.uk 等多段 TLD 误判）
     */
    matchZone(hostname) {
      const fqdn = String(hostname || '').toLowerCase()
      const sorted = [...this.zones].sort((a, b) => (b.name?.length || 0) - (a.name?.length || 0))
      return sorted.find((z) => z.name && (fqdn === z.name || fqdn.endsWith('.' + z.name))) || null
    },
    defaultForm() {
      const initial = this.initialRoute
      if (initial?.hostname) {
        const matched = this.matchZone(initial.hostname)
        const { protocol, address } = parseServiceUrl(initial.service)
        return {
          zone_id: matched?.id || '',
          prefix: hostnamePrefix(initial.hostname, matched?.name || '') || '@',
          protocol,
          address,
          path: initial.path || '',
        }
      }
      return { zone_id: '', prefix: '', protocol: 'http', address: '', path: '' }
    },
    submit() {
      if (!this.canSubmit) return
      this.$emit('submit', {
        zone_id: this.form.zone_id,
        hostname: this.fullHostname,
        protocol: this.form.protocol,
        address: String(this.form.address).trim(),
        path: String(this.form.path || '').trim(),
      })
    },
  },
  template: `
    <a-modal :open="open" @update:open="v => $emit('update:open', v)" :title="title" :confirm-loading="confirmLoading" :ok-button-props="{ disabled: !canSubmit }" ok-text="保存" cancel-text="取消" @ok="submit">
      <a-form layout="vertical">
        <a-form-item label="域名（Cloudflare 站点）" required>
          <a-select v-model:value="form.zone_id" placeholder="选择已托管的 Cloudflare 站点" show-search :filter-option="(input, option) => option.label.toLowerCase().includes(input.toLowerCase())">
            <a-select-option v-for="z in zoneOptions" :key="z.value" :value="z.value" :label="z.label">{{ z.label }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="公共主机名" required>
          <a-input-group compact>
            <a-input v-model:value="form.prefix" placeholder="如 sss 或 @ 表示根域" style="width: 45%" />
            <a-input :value="selectedZone ? '.' + selectedZone.name : ''" disabled style="width: 55%" />
          </a-input-group>
        </a-form-item>
        <a-form-item label="协议" required>
          <a-select v-model:value="form.protocol">
            <a-select-option v-for="p in protocols" :key="p.value" :value="p.value">{{ p.label }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="本地服务地址" required>
          <a-input v-model:value="form.address" placeholder="如 localhost:8888 或 192.168.1.10:3000" />
        </a-form-item>
        <a-form-item label="路径（可选）">
          <a-input v-model:value="form.path" placeholder="如 /api，留空表示所有路径" />
        </a-form-item>
      </a-form>
    </a-modal>
  `,
}
