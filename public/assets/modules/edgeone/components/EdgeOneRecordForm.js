import { message } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'
import { recordToFormState, formStateToRecordPayload, fullDomainName, validateEdgeOneRecordForm } from '../utils/recordPayload.js'

export default {
  props: { modelValue: Object, saving: Boolean, zoneName: String, dnspodLinked: { type: Boolean, default: false } },
  emits: ['save', 'cancel'],
  data() {
    return {
      form: {
        prefix: '',
        origin_type: 'IP_DOMAIN',
        origin: '',
        origin_protocol: 'HTTP',
        http_origin_port: 80,
        https_origin_port: 443,
        host_header: '',
        host_header_mode: 'accelerate',
        ipv6_status: 'follow',
        autoSync: this.dnspodLinked,
      },
    }
  },
  watch: {
    modelValue: {
      immediate: true,
      handler(value) {
        const state = recordToFormState(value, this.zoneName)
        state.autoSync = !value && this.dnspodLinked
        this.form = state
      },
    },
  },
  computed: {
    domainSuffix() {
      return this.zoneName || ''
    },
    fullDomainName() {
      const prefix = String(this.form.prefix || '').trim().toLowerCase()
      return fullDomainName(prefix, this.domainSuffix)
    },
    isWildcardDomain() {
      return String(this.form.prefix || '').trim() === '*'
    },
    hostHeaderAutoText() {
      if (this.isWildcardDomain) return '使用请求 HOST 作为回源 HOST'
      return `使用加速域名 ${this.fullDomainName || ('.' + this.domainSuffix)}`
    },
    originPlaceholder() {
      return ({
        IP_DOMAIN: '请输入合法的 IP 或域名，例如 1.2.3.4 或 origin.example.com',
        COS: '请输入 COS 访问域名，例如 bucket-1250000000.cos.ap-guangzhou.myqcloud.com',
        AWS_S3: '请输入 S3 访问域名',
        ORIGIN_GROUP: '请输入源站组 ID',
        VOD: '请输入云点播应用 ID',
      })[this.form.origin_type] || '请输入源站地址或资源 ID'
    },
    submitPayload() {
      return formStateToRecordPayload(this.form, this.domainSuffix)
    },
    showHostHeader() {
      return this.form.origin_type === 'IP_DOMAIN'
    },
    showHttpPort() {
      return ['FOLLOW', 'HTTP'].includes(this.form.origin_protocol)
    },
    showHttpsPort() {
      return ['FOLLOW', 'HTTPS'].includes(this.form.origin_protocol)
    },
  },
  methods: {
    submit() {
      const error = validateEdgeOneRecordForm(this.form, this.domainSuffix)
      if (error) {
        message.error(errorMessage(error, '表单校验失败'))
        return
      }
      const payload = this.submitPayload
      payload.autoSync = !this.modelValue && this.form.autoSync && this.dnspodLinked
      this.$emit('save', payload)
    },
  },
  template: `
    <a-form layout="vertical">
      <a-row :gutter="16">
        <a-col :xs="24" :sm="12">
          <a-form-item label="加速域名" required>
            <a-input-group compact>
              <a-input v-model:value="form.prefix" :disabled="!!modelValue" placeholder="www / * / @" style="width: 45%" />
              <a-input :value="'.' + domainSuffix" disabled style="width: 55%" />
            </a-input-group>
          </a-form-item>
        </a-col>
        <a-col :xs="24" :sm="12">
          <a-form-item label="IPv6 访问">
            <a-select v-model:value="form.ipv6_status">
              <a-select-option value="follow">遵循站点配置</a-select-option>
              <a-select-option value="on">开启</a-select-option>
              <a-select-option value="off">关闭</a-select-option>
            </a-select>
          </a-form-item>
        </a-col>
        <a-col :xs="24" :sm="12">
          <a-form-item label="源站类型" required>
            <a-select v-model:value="form.origin_type">
              <a-select-option value="IP_DOMAIN">IP/域名</a-select-option>
              <a-select-option value="COS">腾讯云 COS</a-select-option>
              <a-select-option value="AWS_S3">AWS S3</a-select-option>
              <a-select-option value="ORIGIN_GROUP">源站组</a-select-option>
              <a-select-option value="VOD">云点播</a-select-option>
            </a-select>
          </a-form-item>
        </a-col>
        <a-col :xs="24" :sm="12">
          <a-form-item label="源站地址" required>
            <a-input v-model:value="form.origin" :placeholder="originPlaceholder" />
          </a-form-item>
        </a-col>
        <a-col :xs="24" :sm="12">
          <a-form-item label="回源协议" required>
            <a-radio-group v-model:value="form.origin_protocol">
              <a-radio-button value="HTTP">HTTP</a-radio-button>
              <a-radio-button value="HTTPS">HTTPS</a-radio-button>
              <a-radio-button value="FOLLOW">协议跟随</a-radio-button>
            </a-radio-group>
          </a-form-item>
        </a-col>
        <a-col v-if="showHttpPort" :xs="24" :sm="6">
          <a-form-item label="HTTP 端口">
            <a-input-number v-model:value="form.http_origin_port" :min="1" :max="65535" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col v-if="showHttpsPort" :xs="24" :sm="6">
          <a-form-item label="HTTPS 端口">
            <a-input-number v-model:value="form.https_origin_port" :min="1" :max="65535" style="width: 100%" />
          </a-form-item>
        </a-col>
        <a-col :span="24">
          <a-form-item label="回源 HOST 头">
            <template v-if="showHostHeader">
              <a-radio-group v-model:value="form.host_header_mode" style="display: grid; gap: 8px">
                <a-radio value="accelerate">{{ hostHeaderAutoText }}</a-radio>
                <a-radio value="custom">自定义</a-radio>
              </a-radio-group>
              <a-input v-if="form.host_header_mode === 'custom'" v-model:value="form.host_header" placeholder="请输入回源 HOST 头" style="margin-top: 12px" />
            </template>
            <a-typography-text v-else type="secondary">当前源站类型无需设置。</a-typography-text>
          </a-form-item>
        </a-col>
      </a-row>

      <a-form-item v-if="dnspodLinked && !modelValue" label="自动同步 DNSPod" style="margin-bottom: 16px">
        <a-switch v-model:checked="form.autoSync" />
        <a-typography-text v-if="form.autoSync" type="secondary" style="margin-left: 12px">创建后自动添加 CNAME 解析到 DNSPod</a-typography-text>
      </a-form-item>

      <div class="modal-form-actions">
        <span></span>
        <div class="modal-form-actions-main">
          <a-button :disabled="saving" @click="$emit('cancel')">取消</a-button>
          <a-button type="primary" :loading="saving" @click="submit">保存</a-button>
        </div>
      </div>
    </a-form>
  `,
}
