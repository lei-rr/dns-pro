/**
 * Hostname 模块在 DNS 通用视图(ZonesView)里的 hook
 *
 * Cloudflare for SaaS 主机名管理:
 *   - 只读视图,不允许在这里加/删 zone
 *   - 状态值与 CF zone 一致(因为本质就是 CF zone 列表的过滤展示)
 */

const zoneStatusLabels = {
  active: '正常',
  pending: '待接入',
  pending_nameserver: '待接入',
  initializing: '初始化中',
  moved: '已迁移',
  deactivated: '已停用',
}

const zoneStatusGreen = new Set(['active'])
const zoneStatusGold = new Set(['pending', 'pending_nameserver', 'initializing'])
const zoneStatusDefault = new Set(['moved', 'deactivated'])

export default {
  capabilities: {
    createZone: false,
    deleteZone: false,
    importRecords: false,
    exportRecords: false,
  },
  showTtl: false,
  lineLabel: '代理',
  proxyLabel: '启用 Cloudflare 代理',
  proxyOnText: '已开启',
  proxyOnColor: 'cyan',
  proxyTypes: [],
  recordLines: [],
  showLine: () => false,
  zoneStatusLabel(status) {
    const k = String(status || '').toLowerCase()
    return zoneStatusLabels[k] || status || '-'
  },
  zoneStatusColor(status) {
    const k = String(status || '').toLowerCase()
    if (zoneStatusGreen.has(k)) return 'green'
    if (zoneStatusGold.has(k)) return 'gold'
    if (zoneStatusDefault.has(k)) return 'default'
    return k ? 'blue' : 'default'
  },
}
