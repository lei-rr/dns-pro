import { createDnsModule } from '../common/dns/index.js'
import hook from './hook.js'

export default createDnsModule({
  name: 'dnspod',
  providerType: 'dnspod',
  hook,
  description: (provider) => `管理 ${provider.name} 域名解析`,
})
