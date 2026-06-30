import RecordsView from './views/RecordsView.js'
import ZonesView from './views/ZonesView.js'
import { providerBrand } from '../../../providers/branding.js'
import { providerPath } from '../../../routes/paths.js'

/**
 * 通用 DNS 模块 manifest 工厂
 *
 * dnspod / cloudflare 这类标准 DNS 服务商共享 ZonesView + RecordsView UI,
 * 只需提供自己的展示参数(颜色/描述)即可。
 *
 * 用法 (在各模块 index.js):
 *   import { createDnsModule } from '../common/dns/index.js'
 *   import hook from './hook.js'
 *
 *   export default createDnsModule({
 *     name: 'dnspod',
 *     providerType: 'dnspod',
 *     hook,
 *     description: (provider) => `管理 ${provider.name} 域名解析`,
 *   })
 */
export function createDnsModule({ name, providerType, hook, color, avatarColor, description }) {
  return {
    name,
    providerType,
    hook,
    resolveEntry(provider) {
      return { type: 'dns', id: provider.id, provider, component: ZonesView, props: { providerMeta: provider } }
    },
    resolveChild(provider, childId) {
      return {
        type: 'dns',
        id: provider.id,
        provider,
        childId,
        childType: 'dns-record',
        component: RecordsView,
        props: { domain: childId },
      }
    },
    menuEntries(provider) {
      return [{ key: provider.id, label: provider.name, path: providerPath(provider.id) }]
    },
    cards(provider) {
      const text = typeof description === 'function' ? description(provider) : (description || `管理 ${provider.name}`)
      const brand = providerBrand(providerType)
      return [{
        ...provider,
        path: providerPath(provider.id),
        description: text,
        tag: provider.name,
        color: color || brand.color,
        avatarColor: avatarColor || brand.avatarColor,
      }]
    },
  }
}

export { ZonesView, RecordsView }
