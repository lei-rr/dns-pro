/**
 * DNS 共享 UI 的格式化工具
 *
 * 这里只放跨服务商通用的常量(如记录类型 → 颜色)。
 * provider 特定的状态翻译放到各自模块的 hook.js。
 */
export const dnsRecordTypeColors = {
  A: 'blue',
  AAAA: 'cyan',
  CNAME: 'purple',
  MX: 'orange',
  TXT: 'green',
  NS: 'geekblue',
  SRV: 'magenta',
  CAA: 'gold',
}
