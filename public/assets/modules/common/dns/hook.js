/**
 * DNS 共享 UI 的 provider hook 默认值
 *
 * 各服务商模块通过 `hook.js` 导出自己的覆盖,registry 负责合并。
 * ZonesView / RecordsView / RecordTable / RecordForm 通过 props 接收 hook 使用。
 */
export const defaultProviderHook = {
  capabilities: {
    createZone: true,
    deleteZone: true,
    importRecords: true,
    exportRecords: true,
  },
  showTtl: true,
  lineLabel: '线路',
  proxyLabel: '启用代理',
  proxyOnText: '代理开启',
  proxyOffText: '仅 DNS',
  proxyOnColor: 'green',
  proxyOffColor: 'default',
  proxyTypes: [],
  recordLines: [],
  showLine: (lines) => lines.length > 0,
  // status 默认实现:原值显示,颜色按通用规则
  zoneStatusLabel: (status) => status || '-',
  zoneStatusColor: (status) => (status ? 'blue' : 'default'),
}

/**
 * 把自定义 hook 与 default 合并
 */
export function mergeHook(custom) {
  if (!custom) return defaultProviderHook
  return {
    ...defaultProviderHook,
    ...custom,
    capabilities: { ...defaultProviderHook.capabilities, ...(custom.capabilities || {}) },
  }
}
