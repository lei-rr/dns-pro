export const edgeOneStatusLabels = {
  online: '已生效',
  process: '部署中',
  offline: '已停用',
  forbidden: '已封禁',
  init: '未生效',
}

export const edgeOneStatusColors = {
  online: 'green',
  process: 'gold',
  init: 'gold',
  offline: 'default',
  forbidden: 'red',
}

export const edgeOneOriginTypeLabels = {
  IP_DOMAIN: 'IP/域名',
  COS: 'COS',
  AWS_S3: 'AWS S3',
  ORIGIN_GROUP: '源站组',
  VOD: '云点播',
}

export const edgeOneIpv6Labels = {
  follow: '遵循站点',
  on: '开启',
  off: '关闭',
}

const certificateStatusLabels = {
  applying: '申请中',
  deployed: '已部署',
  processing: '部署中',
  failed: '申请失败',
}

const certificateStatusColors = {
  applying: 'gold',
  processing: 'gold',
  deployed: 'green',
  failed: 'red',
}

export function normalizeStatus(status) {
  return String(status || '').trim().toLowerCase()
}

export function certificateStatusLabel(status) {
  return certificateStatusLabels[normalizeStatus(status)] || status || '-'
}

export function certificateStatusColor(status) {
  return certificateStatusColors[normalizeStatus(status)] || 'default'
}
