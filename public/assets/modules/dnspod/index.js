import { createDnsModule } from '../common/dns/index.js'
import hook from './hook.js'

export default createDnsModule({
  name: 'dnspod',
  providerType: 'dnspod',
  hook,
  color: 'blue',
  avatarColor: '#1677ff',
  description: (provider) => `管理 ${provider.name} 域名解析`,
})
