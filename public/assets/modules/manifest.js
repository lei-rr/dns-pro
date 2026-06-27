import { childRoutes as systemChildRoutes, publicRoutes } from './system/routes.js'
import { childRoutes as providerChildRoutes } from './provider/routes.js'
import { ProviderChildRouteView, ProviderEntryRouteView } from '../routes/ProviderRouteView.js'

export const modulePublicRoutes = [
  ...publicRoutes,
]

export const moduleChildRoutes = [
  ...systemChildRoutes,
  ...providerChildRoutes,
  { path: ':provider', component: ProviderEntryRouteView },
  { path: ':provider/:second', component: ProviderChildRouteView },
]
