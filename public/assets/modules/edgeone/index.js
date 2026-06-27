import EdgeOneRecordsView from './views/EdgeOneRecordsView.js'
import EdgeOneView from './views/EdgeOneView.js'
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
      description: '管理 EdgeOne 站点和加速域名',
      tag: 'EdgeOne',
      color: 'purple',
      avatarColor: '#722ed1',
    }]
  },
}
