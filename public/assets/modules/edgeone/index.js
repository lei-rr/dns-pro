import EdgeOneRecordsView from './views/EdgeOneRecordsView.js'
import EdgeOneView from './views/EdgeOneView.js'
import { providerBrand } from '../../providers/branding.js'
import { providerPath } from '../../routes/paths.js'

export default {
  name: 'edgeone',
  providerType: 'edgeone',
  resolveEntry(provider) {
    return { type: 'edgeone', id: provider.id, provider, component: EdgeOneView }
  },
  resolveChild(provider, childId) {
    if (!String(childId || '').trim()) return null

    return {
      type: 'edgeone',
      id: provider.id,
      provider,
      childId,
      childType: 'edgeone-zone',
      component: EdgeOneRecordsView,
      props: { zoneId: childId },
    }
  },
  menuEntries(provider) {
    return [{ key: provider.id, label: provider.name, path: providerPath(provider.id) }]
  },
  cards(provider) {
    const brand = providerBrand('edgeone')
    return [{
      ...provider,
      path: providerPath(provider.id),
      description: '管理 EdgeOne 站点和加速域名',
      tag: 'EdgeOne',
      color: brand.color,
      avatarColor: brand.avatarColor,
    }]
  },
}
