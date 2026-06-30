import { authApi } from '../api/auth.js'
import { loadProviders, useProviderStore } from '../../../providers/store.js'
import { providerMenuEntries, selectedMenuKey } from '../../../routes/utils.js'
import { message } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'

const { h } = Vue
const { RouterLink } = VueRouter

export default {
  computed: {
    selectedKeys() {
      return [selectedMenuKey(this.$route.path)]
    },
    menuItems() {
      return [
        { key: 'home', label: h(RouterLink, { to: '/' }, { default: () => '控制台' }), path: '/' },
        ...(this.providerStore.providers || []).flatMap(providerMenuEntries),
      ]
    },
    menuPathMap() {
      return Object.fromEntries(this.menuItems.map((item) => [item.key, item.path]))
    },
    providerStore() {
      return useProviderStore()
    },
  },
  async mounted() {
    await this.loadProviders()
  },
  methods: {
    async loadProviders() {
      try {
        await loadProviders()
      } catch (error) {
        message.error(errorMessage(error))
      }
    },
    async logout() {
      await authApi.logout().catch(() => {})
      this.$router.replace('/login')
    },
    handleUserMenu({ key }) {
      if (key === 'providers') this.$router.push('/providers')
      if (key === 'logout') this.logout()
    },
    openMenu({ key }) {
      if (key === 'home') return

      const path = this.menuPathMap[key]
      if (path && path !== this.$route.path) this.$router.push(path)
    },
  },
  template: `
    <a-layout style="min-height: 100vh; background: #fff">
      <a-layout-header style="position: sticky; top: 0; z-index: 10; background: #fff; padding: 0">
        <div class="app-container app-header-inner">
          <div class="app-brand-nav">
            <router-link to="/" class="app-brand">
              <a-avatar shape="square" style="background: #1677ff">D</a-avatar>
              <a-typography-text strong style="font-size: 16px">DNS-PRO</a-typography-text>
            </router-link>
            <a-menu class="app-menu" mode="horizontal" :selected-keys="selectedKeys" :items="menuItems" @click="openMenu" />
          </div>
          <a-dropdown :trigger="['click']">
            <a-button shape="circle" title="管理" aria-label="管理">☰</a-button>
            <template #overlay>
              <a-menu @click="handleUserMenu">
                <a-menu-item key="providers">服务商</a-menu-item>
                <a-menu-divider />
                <a-menu-item key="logout" danger>退出</a-menu-item>
              </a-menu>
            </template>
          </a-dropdown>
        </div>
      </a-layout-header>
      <a-layout-content>
        <div class="app-container app-main">
          <router-view />
        </div>
      </a-layout-content>
    </a-layout>
  `,
}
