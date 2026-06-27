import { providersApi } from './api.js'

const { defineStore } = Pinia

let providersPromise = null

export const useProviderStore = defineStore('providers', {
  state: () => ({ providers: null, loading: false, error: null }),
  actions: {
    async load(options = {}) {
      if (options.refresh) {
        providersPromise = null
        this.providers = null
      }
      if (this.providers) return this.providers

      if (!providersPromise) {
        this.loading = true
        this.error = null
        providersPromise = providersApi.configured()
          .then((response) => {
            this.providers = response.data
            return this.providers
          })
          .catch((error) => {
            this.error = error
            throw error
          })
          .finally(() => {
            providersPromise = null
            this.loading = false
          })
      }

      await providersPromise

      return this.providers
    },
    clear() {
      providersPromise = null
      this.providers = null
      this.error = null
    },
  },
})

export async function loadProviders(options = {}) {
  return useProviderStore().load(options)
}

export function clearProvidersCache() {
  useProviderStore().clear()
}

export function replaceProvidersCache(providers) {
  const store = useProviderStore()
  providersPromise = null
  store.providers = providers
  store.error = null
  store.loading = false
}

export function getCachedProvider(providerId) {
  return (useProviderStore().providers || []).find((provider) => provider.id === providerId) || null
}

window.addEventListener('providers-updated', (event) => {
  if (Array.isArray(event.detail?.providers)) {
    replaceProvidersCache(event.detail.providers)
    return
  }

  clearProvidersCache()
})
