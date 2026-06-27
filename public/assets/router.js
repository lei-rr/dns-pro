import { moduleChildRoutes, modulePublicRoutes } from './modules/manifest.js'
import { systemRouteIds, systemRoutes } from './modules/system/routes.js'
import { authApi } from './modules/system/api/auth.js'
import { loadProviders } from './providers/store.js'
import { message } from './shared/plugins/antDesignVue.js'
import { resolveChildRoute, resolveEntryRoute } from './routes/utils.js'

const { createRouter, createWebHashHistory } = VueRouter

const router = createRouter({
  history: createWebHashHistory(),
  routes: [
    ...modulePublicRoutes,
    {
      path: '/',
      component: systemRoutes.layout,
      children: moduleChildRoutes,
    },
  ],
})

router.beforeEach(async (to) => {
  if (to.path === '/login') return true

  try {
    await authApi.me()
  } catch {
    return '/login'
  }

  const first = to.path.split('/').filter(Boolean)[0] || ''
  if (systemRouteIds.has(first)) return true

  try {
    const providers = await loadProviders()
    const parts = to.path.split('/').filter(Boolean)
    if (parts[1] ? resolveChildRoute(providers, first, parts[1]) : resolveEntryRoute(providers, first)) return true
    message.warning('该 DNS 服务商未配置或不可用')
    return '/'
  } catch (error) {
    message.error(error.message)
    return '/'
  }
})

export default router
