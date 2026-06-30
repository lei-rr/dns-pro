import { ZonesView } from '../common/dns/index.js'
import SaasHostsView from './views/SaasHostsView.js'
import hook from './hook.js'
import { providerBrand } from '../../providers/branding.js'
import { providerPath } from '../../routes/paths.js'

export default {
  name: 'saas',
  providerType: 'saas',
  hook,
  resolveEntry(provider) {
    return { type: 'dns', id: provider.id, provider, component: ZonesView, props: { providerMeta: provider } }
  },
  resolveChild(provider, childId) {
    return {
      type: 'saas',
      id: provider.id,
      provider,
      childId,
      childType: 'saas-zone',
      component: SaasHostsView,
      props: { zoneName: childId },
    }
  },
  menuEntries(provider) {
    return [{ key: provider.id, label: provider.name, path: providerPath(provider.id) }]
  },
  cards(provider) {
    const brand = providerBrand('saas')
    return [{
      ...provider,
      path: providerPath(provider.id),
      description: '查看 Cloudflare for SaaS 自定义主机名',
      tag: 'SaaS',
      color: brand.color,
      avatarColor: brand.avatarColor,
    }]
  },
}
