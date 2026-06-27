import { ZonesView } from '../common/dns/index.js'
import HostnamesView from './views/HostnamesView.js'
import hook from './hook.js'
import { providerPath } from '../../routes/paths.js'

export default {
  name: 'hostname',
  providerType: 'hostname',
  hook,
  resolveEntry(provider) {
    return { type: 'dns', id: provider.id, provider, component: ZonesView, props: { providerMeta: provider } }
  },
  resolveChild(provider, childId) {
    return {
      type: 'hostname',
      id: provider.id,
      provider,
      childId,
      childType: 'hostname-zone',
      component: HostnamesView,
      props: { zoneName: childId },
    }
  },
  menuEntries(provider) {
    return [{ key: provider.id, label: provider.name, path: providerPath(provider.id) }]
  },
  cards(provider) {
    return [{
      ...provider,
      path: providerPath(provider.id),
      description: '查看 Cloudflare for SaaS 自定义主机名',
      tag: 'Hostname',
      color: 'purple',
      avatarColor: '#722ed1',
    }]
  },
}
