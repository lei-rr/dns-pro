import { message } from '../../../shared/plugins/antDesignVue.js'
import { certificateStatusColor, certificateStatusLabel } from '../utils/format.js'

export default {
  props: { modelValue: Object, saving: Boolean },
  emits: ['save', 'cancel'],
  data() {
    return { form: { https_mode: 'disable', cert_id: '' } }
  },
  computed: {
    showCertId() {
      return this.form.https_mode === 'sslcert'
    },
    certificate() {
      return this.modelValue?.certificate?.list?.[0] || null
    },
    modeText() {
      return ({ disable: '未配置', eofreecert: 'EdgeOne 免费证书', sslcert: 'SSL 证书 ID' })[this.form.https_mode] || this.form.https_mode
    },
    certificateDetails() {
      if (!this.certificate) return []
      return [
        { label: '证书类型', value: this.certificateTypeLabel(this.certificate.type) },
        { label: '自动更新', value: this.autoRenewText() },
        { label: '到期时间', value: this.formatTime(this.certificate.expire_time) },
        { label: '状态', value: certificateStatusLabel(this.certificate.status), status: this.certificate.status },
      ]
    },
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(value) {
        this.form = {
          https_mode: value?.certificate?.mode || 'disable',
          cert_id: (value?.certificate?.items || value?.certificate?.list || [])[0]?.cert_id || '',
        }
      },
    },
  },
  methods: {
    submit() {
      if (this.showCertId && !String(this.form.cert_id || '').trim()) {
        message.error('证书 ID 不能为空')
        return
      }
      this.$emit('save', this.form)
    },
    certificateTypeLabel(type) {
      return ({ default: '免费证书', free: '免费证书', upload: '上传证书', managed: '托管证书' })[type] || type || '-'
    },
    autoRenewText() {
      if (this.form.https_mode === 'eofreecert' || this.certificate?.type === 'default') return '到期前 15 天自动更新'
      return '-'
    },
    statusColor(status) {
      return certificateStatusColor(status)
    },
    formatTime(value) {
      if (!value) return '-'
      const date = new Date(value)
      if (Number.isNaN(date.getTime())) return value
      const pad = (number) => String(number).padStart(2, '0')
      return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())} ${pad(date.getHours())}:${pad(date.getMinutes())}:${pad(date.getSeconds())}`
    },
  },
  template: `
    <a-form layout="vertical">
      <a-form-item label="HTTPS 配置">
        <a-select v-model:value="form.https_mode">
          <a-select-option value="disable">不配置</a-select-option>
          <a-select-option value="eofreecert">EdgeOne 免费证书</a-select-option>
          <a-select-option value="sslcert">SSL 证书 ID</a-select-option>
        </a-select>
      </a-form-item>
      <a-descriptions v-if="certificate" title="当前证书" bordered size="small" :column="1" style="margin-bottom: 16px">
        <a-descriptions-item v-for="item in certificateDetails" :key="item.label" :label="item.label">
          <a-tag v-if="item.status" :color="statusColor(item.status)">{{ item.value }}</a-tag>
          <template v-else>{{ item.value }}</template>
        </a-descriptions-item>
      </a-descriptions>
      <a-form-item v-if="showCertId" label="证书 ID" required>
        <a-input v-model:value="form.cert_id" placeholder="请输入 CertId" />
      </a-form-item>
      <a-space style="display: flex; justify-content: flex-end">
        <a-button :disabled="saving" @click="$emit('cancel')">取消</a-button>
        <a-button type="primary" :loading="saving" @click="submit">保存</a-button>
      </a-space>
    </a-form>
  `,
}
