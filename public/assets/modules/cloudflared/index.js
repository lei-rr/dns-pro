import CloudflaredView from './views/CloudflaredView.js'
import CloudflaredDetailView from './views/CloudflaredDetailView.js'
import { providerBrand } from '../../providers/branding.js'
import { providerPath } from '../../routes/paths.js'

export default {
  name: 'cloudflared',
  providerType: 'cloudflared',
  resolveEntry(provider) {
    return { type: 'cloudflared', id: provider.id, provider, component: CloudflaredView }
  },
  resolveChild(provider, childId) {
    if (!String(childId || '').trim()) return null

    return {
      type: 'cloudflared',
      id: provider.id,
      provider,
      childId,
      childType: 'cloudflared-tunnel',
      component: CloudflaredDetailView,
      props: { tunnelId: childId },
    }
  },
  menuEntries(provider) {
    return [{ key: provider.id, label: provider.name, path: providerPath(provider.id) }]
  },
  cards(provider) {
    const brand = providerBrand('cloudflared')
    return [{
      ...provider,
      path: providerPath(provider.id),
      description: '管理 Cloudflare Tunnel 隧道与路由',
      tag: 'Cloudflare Tunnel',
      color: brand.color,
      avatarColor: brand.avatarColor,
    }]
  },
}
