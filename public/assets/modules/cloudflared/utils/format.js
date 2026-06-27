export const tunnelStatusLabels = {
  healthy: '已连接',
  degraded: '降级',
  down: '已断开',
  inactive: '未连接',
}

export const tunnelStatusColors = {
  healthy: 'green',
  degraded: 'orange',
  down: 'red',
  inactive: 'default',
}

export const protocolOptions = [
  { value: 'http', label: 'HTTP' },
  { value: 'https', label: 'HTTPS' },
  { value: 'tcp', label: 'TCP' },
  { value: 'ssh', label: 'SSH' },
  { value: 'rdp', label: 'RDP' },
  { value: 'smb', label: 'SMB' },
]

export function statusLabel(status) {
  return tunnelStatusLabels[status] || status || '-'
}

export function statusColor(status) {
  return tunnelStatusColors[status] || 'default'
}

/**
 * 把 service URL 拆成协议 + 地址
 * 例：http://localhost:8888 → { protocol: 'http', address: 'localhost:8888' }
 */
export function parseServiceUrl(service) {
  const match = String(service || '').match(/^(\w+):\/\/(.+)$/)
  if (!match) return { protocol: 'http', address: '' }
  return { protocol: match[1].toLowerCase(), address: match[2] }
}

/**
 * 计算 hostname 前缀
 * sss.100022.xyz + 100022.xyz → sss
 */
export function hostnamePrefix(hostname, zoneName) {
  if (!hostname || !zoneName) return ''
  if (hostname === zoneName) return '@'
  const suffix = '.' + zoneName
  return hostname.endsWith(suffix) ? hostname.slice(0, -suffix.length) : hostname
}
