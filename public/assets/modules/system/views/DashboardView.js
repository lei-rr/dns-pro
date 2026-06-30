import { useProviderStore } from '../../../providers/store.js'
import { providerCards } from '../../../routes/utils.js'

export default {
  computed: {
    cards() {
      return this.providerStore.providers?.flatMap(providerCards) || []
    },
    loading() {
      return this.providerStore.loading
    },
    providerStore() {
      return useProviderStore()
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-typography-title :level="3" style="margin-bottom: 4px">控制台</a-typography-title>
          <a-typography-text type="secondary">选择服务商进入 DNS 或 EdgeOne 管理。</a-typography-text>
        </div>
      </div>
      <a-spin :spinning="loading">
        <a-row :gutter="[16, 16]">
          <a-col v-for="provider in cards" :key="provider.id" :xs="24" :sm="12" :lg="8">
            <router-link :to="provider.path">
              <a-card hoverable class="provider-card">
                <a-card-meta :title="provider.name" :description="provider.description">
                  <template #avatar>
                    <a-avatar :style="{ background: provider.avatarColor }">{{ provider.name.slice(0, 1) }}</a-avatar>
                  </template>
                </a-card-meta>
                <a-divider style="margin: 16px 0" />
                <a-space style="display: flex; justify-content: space-between">
                  <a-tag :color="provider.color">{{ provider.tag }}</a-tag>
                  <a-typography-link>进入管理</a-typography-link>
                </a-space>
              </a-card>
            </router-link>
          </a-col>
        </a-row>
        <a-empty v-if="!loading && !cards.length" description="暂无可用服务商" />
      </a-spin>
    </section>
  `,
}
