import { useProviderStore } from '../providers/store.js'
import { resolveChildRoute, resolveEntryRoute } from './utils.js'

function routeView(child = false) {
  return {
    computed: {
      providerStore() { return useProviderStore() },
      providers() { return this.providerStore.providers || [] },
      loading() { return this.providerStore.loading },
      routeId() { return this.$route.params.provider },
      second() { return this.$route.params.second },
      routeEntry() {
        return child
          ? resolveChildRoute(this.providers, this.routeId, this.second)
          : resolveEntryRoute(this.providers, this.routeId)
      },
      component() {
        return this.routeEntry?.component || null
      },
      componentProps() {
        return { provider: this.routeId, ...(this.routeEntry?.props || {}) }
      },
    },
    template: `
      <a-spin :spinning="loading">
        <component v-if="!loading && routeEntry && component" :is="component" v-bind="componentProps" />
      </a-spin>
    `,
  }
}

export const ProviderEntryRouteView = routeView(false)
export const ProviderChildRouteView = routeView(true)
