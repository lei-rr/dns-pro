import dnspodModule from '../modules/dnspod/index.js'
import cloudflareModule from '../modules/cloudflare/index.js'
import saasModule from '../modules/saas/index.js'
import edgeOneModule from '../modules/edgeone/index.js'
import cloudflaredModule from '../modules/cloudflared/index.js'
import { defaultProviderHook, mergeHook } from '../modules/common/dns/hook.js'
import { providerAvatarColor } from './branding.js'

/**
 * 前端 provider 模块注册中心
 *
 * 每个模块按 providerType 注册;模块 manifest 必须包含:
 *   - providerType: 与后端 provider.type 一致(dnspod / cloudflare / saas / edgeone / cloudflared)
 *   - resolveEntry / resolveChild / menuEntries / cards
 *   - hook(可选): DNS 通用视图(ZonesView / RecordsView)用的行为参数
 */
const providerFrontendModules = [dnspodModule, cloudflareModule, saasModule, edgeOneModule, cloudflaredModule]

const providerModules = Object.fromEntries(
  providerFrontendModules
    .filter((module) => module.providerType)
    .map((module) => [module.providerType, module])
)

export function providerModule(provider) {
  if (!provider?.type) return null
  return providerModules[provider.type] || null
}

export function resolveProviderEntry(provider) {
  return providerModule(provider)?.resolveEntry?.(provider) || null
}

export function resolveProviderChild(provider, childId) {
  return providerModule(provider)?.resolveChild?.(provider, childId) || null
}

/**
 * 取指定 provider type 的 DNS 通用 UI hook(用于 ZonesView/RecordsView)
 *
 * 未注册或没有 hook 的 type 回退到 defaultProviderHook。
 */
export function resolveProviderHook(type) {
  return mergeHook(providerModules[type]?.hook)
}

export function resolveProviderAvatarColor(provider) {
  const mod = providerModule(provider)
  if (!mod?.cards) return providerAvatarColor(provider?.type)
  const cards = mod.cards(provider)
  return cards[0]?.avatarColor || providerAvatarColor(provider?.type)
}

export { defaultProviderHook }
