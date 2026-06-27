import { providerModule, resolveProviderChild, resolveProviderEntry } from '../providers/registry.js'
import { providerPath } from './paths.js'

export function resolveEntryRoute(providers, routeId) {
  const provider = providers.find((provider) => provider.id === routeId)
  if (provider) return resolveProviderEntry(provider)

  return null
}

export function resolveChildRoute(providers, routeId, childId) {
  const entry = resolveEntryRoute(providers, routeId)
  if (!entry) return null

  return resolveProviderChild(entry.provider, childId)
}

export function selectedMenuKey(path) {
  const first = path.split('/').filter(Boolean)[0] || ''
  return first || 'home'
}

export function providerMenuEntries(provider) {
  return providerModule(provider)?.menuEntries?.(provider) || [{ key: provider.id, label: provider.name, path: providerPath(provider.id) }]
}

export function providerCards(provider) {
  return providerModule(provider)?.cards?.(provider) || [{ ...provider, path: providerPath(provider.id) }]
}
export { providerChildPath, providerPath } from './paths.js'
