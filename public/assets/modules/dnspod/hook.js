/**
 * DNSPod 模块的 provider hook
 *
 * 覆盖 common/dns/hook.js 中的默认值,提供 DNSPod 专属能力:
 *   - 支持多线路(默认/境内/电信/...)
 *   - 不支持代理(only DNSPod 才有的"线路"概念)
 *   - zone 状态值映射(DNSPod 的 Status / DNSStatus 字段语义)
 */

// DNSPod zone 状态 → 中文文案
const zoneStatusLabels = {
  enable: '正常',
  enabled: '正常',
  success: '正常',
  pause: '已暂停',
  spam: '违规',
  dnserror: 'NS 异常',
  dns_error: 'NS 异常',
}

// DNSPod zone 状态 → 颜色
const zoneStatusGreen = new Set(['enable', 'enabled', 'success'])
const zoneStatusGold = new Set(['pause'])
const zoneStatusRed = new Set(['dnserror', 'dns_error', 'spam'])

export default {
  recordLines: [
    { label: '默认', value: '默认' },
    { label: '境内', value: '境内' },
    { label: '电信', value: '电信' },
    { label: '联通', value: '联通' },
    { label: '移动', value: '移动' },
    { label: '境外', value: '境外' },
  ],
  zoneStatusColumns: [
    {
      key: 'status',
      title: '托管状态',
      getStatus: (record) => record.status,
    },
    {
      key: 'dns_status',
      title: 'NS 状态',
      getStatus: (record) => record.dns_status,
    },
  ],
  showLine: (lines) => lines.length > 0,
  zoneStatusLabel(status) {
    if (status === '') return '正常'
    const k = String(status || '').toLowerCase()
    return zoneStatusLabels[k] || status || '-'
  },
  zoneStatusColor(status) {
    if (status === '') return 'green'
    const k = String(status || '').toLowerCase()
    if (zoneStatusGreen.has(k)) return 'green'
    if (zoneStatusGold.has(k)) return 'gold'
    if (zoneStatusRed.has(k)) return 'red'
    return k ? 'blue' : 'default'
  },
}
