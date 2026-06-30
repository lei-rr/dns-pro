import { statusColor, statusLabel, minTlsLabel, formatDate } from '../utils/saas.js'

export default {
  name: 'SaasDetailModal',
  props: {
    open: { type: Boolean, default: false },
    hostname: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    refreshing: { type: Boolean, default: false },
  },
  emits: ['update:open', 'refresh', 'edit'],
  computed: {
    syncTargetLabel() {
      const target = this.hostname?.effective_sync_target || this.hostname?.sync_target
      if (target === 'dnspod') return 'DNSPod'
      if (target === 'cloudflare_dns') return 'Cloudflare DNS'
      return '未配置'
    },
    syncConfigModeLabel() {
      if (!this.hostname?.effective_sync_target) return '未配置'
      return this.hostname?.sync_config_explicit ? '显式配置' : '兼容默认'
    },
    // 是否仍需要提示用户完成 DCV 验证（active/deleted 等终态不再提示）
    needsDcvHelp() {
      const status = this.hostname?.ssl?.status
      const finalStates = ['active', 'deleted', 'deactivated', 'pending_deletion']
      return !!status && !finalStates.includes(status)
    },
    // 是否仍需要提示用户完成域名所有权验证
    needsOwnershipHelp() {
      const status = this.hostname?.status
      const finalStates = ['active', 'active_renewing', 'moved', 'deleted', 'blocked', 'pending_deletion']
      return !!status && !finalStates.includes(status)
    },
    // hostname 级错误(如 zone 没设 fallback origin)+ SSL 级错误,合并展示
    errorMessages() {
      const out = []
      const top = this.hostname?.verification_errors || []
      for (const e of top) {
        if (typeof e === 'string') out.push(e)
        else if (e && typeof e === 'object') out.push(e.message || e.error || JSON.stringify(e))
      }
      const ssl = this.hostname?.ssl?.validation_errors || []
      for (const e of ssl) {
        if (typeof e === 'string') out.push(e)
        else if (e && typeof e === 'object') out.push(e.message || e.error || JSON.stringify(e))
      }
      return out
    },
    dcvDelegationRecords() {
      if (!this.needsDcvHelp) return []

      const records = this.hostname?.ssl?.dcv_delegation_records || []
      if (Array.isArray(records) && records.length > 0) return records

      // Cloudflare 不返回 dcv_delegation_records 时，用 zone uuid 拼一条参考记录
      const uuid = this.hostname?.ssl?.dcv_delegation_uuid
      const fqdn = this.hostname?.hostname
      if (uuid && fqdn) {
        return [{
          cname: '_acme-challenge.' + fqdn,
          cname_target: fqdn + '.' + uuid + '.dcv.cloudflare.com',
        }]
      }
      return []
    },
    acmeTempRecords() {
      if (!this.needsDcvHelp) return []

      const records = this.hostname?.ssl?.validation_records || []
      if (!Array.isArray(records)) return []
      return records
        .map((r) => {
          if (r?.txt_name && r?.txt_value) return { type: 'TXT', name: r.txt_name, value: r.txt_value, status: r.status }
          if (r?.http_url && r?.http_body) return { type: 'HTTP', name: r.http_url, value: r.http_body, status: r.status }
          return null
        })
        .filter(Boolean)
    },
  },
  methods: {
    statusColor,
    statusLabel,
    minTlsLabel,
    formatDate,
    close() { this.$emit('update:open', false) },
  },
  template: `
    <a-modal :open="open" @update:open="v => $emit('update:open', v)" title="自定义主机名详情" :footer="null" width="760px" destroy-on-close>
      <a-spin :spinning="loading">
      <template v-if="hostname">
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

        <a-descriptions bordered size="small" :column="1" style="margin-bottom: 16px">
          <a-descriptions-item label="主机名">{{ hostname.hostname || '-' }}</a-descriptions-item>
          <a-descriptions-item label="主机名状态">
            <a-tag :color="statusColor(hostname.status)">{{ statusLabel(hostname.status) }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="同步模式">{{ syncConfigModeLabel }}</a-descriptions-item>
          <a-descriptions-item label="同步目标">{{ syncTargetLabel }}</a-descriptions-item>
          <a-descriptions-item label="同步服务商">{{ hostname.effective_sync_provider_id || hostname.sync_provider_id || '-' }}</a-descriptions-item>
          <a-descriptions-item label="同步域名">{{ hostname.effective_sync_zone || hostname.sync_zone || '-' }}</a-descriptions-item>
          <a-descriptions-item label="自动优选">{{ hostname.auto_preferred ? '开启' : '关闭' }}</a-descriptions-item>
          <a-descriptions-item label="回源服务器">{{ hostname.custom_origin_server || '默认源服务器' }}</a-descriptions-item>
          <a-descriptions-item label="最低 TLS 版本">{{ minTlsLabel(hostname.ssl?.settings?.min_tls_version) }}</a-descriptions-item>
          <a-descriptions-item label="DCV 验证方式">{{ hostname.ssl?.method || 'txt' }}</a-descriptions-item>
          <a-descriptions-item label="证书状态">
            <a-tag :color="statusColor(hostname.ssl?.status)">{{ statusLabel(hostname.ssl?.status) }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="证书到期">{{ formatDate(hostname.ssl?.expires_on) }}</a-descriptions-item>
          <a-descriptions-item v-if="hostname.ssl?.issuer" label="证书颁发">{{ hostname.ssl.issuer }}</a-descriptions-item>
        </a-descriptions>

        <a-descriptions
          v-if="needsOwnershipHelp && hostname.ownership_verification?.name"
          bordered size="small" :column="1" title="域名所有权验证（TXT）"
          style="margin-bottom: 16px"
        >
          <a-descriptions-item label="记录名">{{ hostname.ownership_verification.name }}</a-descriptions-item>
          <a-descriptions-item label="记录值">{{ hostname.ownership_verification.value || '-' }}</a-descriptions-item>
        </a-descriptions>

        <a-descriptions
          v-for="(rec, index) in dcvDelegationRecords" :key="'dcv-'+index"
          bordered size="small" :column="1"
          title="自定义主机名的 DCV 委派（CNAME）"
          style="margin-bottom: 16px"
        >
          <a-descriptions-item label="记录名">{{ rec.cname }}</a-descriptions-item>
          <a-descriptions-item label="记录值">{{ rec.cname_target }}</a-descriptions-item>
        </a-descriptions>

        <details v-if="acmeTempRecords.length" style="margin-bottom: 16px">
          <summary style="cursor: pointer; padding: 4px 0; color: rgba(0,0,0,0.45)">
            临时 ACME 验证 TXT（{{ acmeTempRecords.length }} 条，每次续期变化，添加 DCV 委派 CNAME 后无需关注）
          </summary>
          <a-descriptions
            v-for="(rec, index) in acmeTempRecords" :key="'acme-'+index"
            bordered size="small" :column="1"
            :title="'临时验证（' + rec.type + '）'"
            style="margin-top: 12px"
          >
            <a-descriptions-item label="记录名">{{ rec.name }}</a-descriptions-item>
            <a-descriptions-item label="记录值">{{ rec.value }}</a-descriptions-item>
          </a-descriptions>
        </details>

        <div style="display: flex; justify-content: flex-end; gap: 8px;">
          <a-button @click="$emit('edit', hostname)">编辑</a-button>
          <a-button :loading="refreshing" @click="$emit('refresh', hostname)">刷新状态</a-button>
          <a-button @click="close">关闭</a-button>
        </div>
      </template>
      </a-spin>
    </a-modal>
  `,
}
