import { loadProviders, useProviderStore } from '../providers/store.js'
import { resolveChildRoute, resolveEntryRoute } from './utils.js'
import { message } from '../shared/plugins/antDesignVue.js'

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
    async mounted() {
      this._onProvidersUpdated = () => this.load()
      window.addEventListener('providers-updated', this._onProvidersUpdated)
      await this.load()
    },
    beforeUnmount() {
      window.removeEventListener('providers-updated', this._onProvidersUpdated)
    },
    watch: {
      '$route.params.provider': 'load',
    },
    methods: {
      async load() {
        try {
          await loadProviders()
        } catch (error) {
          message.error(error.message)
        }
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
