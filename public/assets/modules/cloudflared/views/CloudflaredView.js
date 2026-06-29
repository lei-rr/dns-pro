import { cloudflaredApi } from '../utils/api.js'
import { statusLabel, statusColor } from '../utils/format.js'
import { providerChildPath } from '../../../routes/paths.js'
import { message, modal } from '../../../shared/plugins/antDesignVue.js'
import { errorMessage } from '../../../shared/utils/errors.js'
import TunnelCreateModal from '../components/TunnelCreateModal.js'

export default {
  components: { TunnelCreateModal },
  props: ['provider'],
  data() {
    return {
      tunnels: [],
      loading: true,
      creating: false,
      showCreate: false,
      loadRequestToken: 0,
    }
  },
  computed: {
    columns() {
      return [
        { title: '名称', dataIndex: 'name', key: 'name', width: 220 },
        { title: '状态', key: 'status', width: 110 },
        { title: '副本', key: 'replicas', width: 80 },
        { title: '类型', key: 'type', width: 110 },
        { title: '隧道 ID', dataIndex: 'id', key: 'id', width: 280, responsive: ['lg'] },
        { title: '操作', key: 'actions', width: 110, align: 'right' },
      ]
    },
  },
  async mounted() {
    await this.load()
  },
  watch: {
    provider() { this.tunnels = []; this.showCreate = false; this.load() },
  },
  methods: {
    statusLabel,
    statusColor,
    detailPath(tunnel) { return providerChildPath(this.provider, tunnel.id) },

    async load(options = {}) {
      const requestToken = this.loadRequestToken + 1
      this.loadRequestToken = requestToken
      this.loading = true
      try {
        const response = await cloudflaredApi.tunnels(this.provider, options)
        if (requestToken !== this.loadRequestToken) return
        this.tunnels = response.data || []
        if (options.refresh) message.success('已刷新')
      } catch (error) {
        if (requestToken !== this.loadRequestToken) return
        message.error(errorMessage(error))
      } finally {
        if (requestToken === this.loadRequestToken) this.loading = false
      }
    },

    openCreate() { this.showCreate = true },

    async create(name) {
      this.creating = true
      try {
        const response = await cloudflaredApi.createTunnel(this.provider, name)
        const tunnel = response.data?.tunnel || {}
        this.showCreate = false
        message.success('隧道已创建')
        // 跳转到详情页
        this.$router.push(this.detailPath(tunnel))
      } catch (error) {
        message.error(errorMessage(error))
      } finally {
        this.creating = false
      }
    },

    askDelete(tunnel) {
      modal.confirm({
        title: '删除隧道',
        content: `确认删除隧道「${tunnel.name}」？`,
        okText: '删除', okType: 'danger', cancelText: '取消',
        onOk: () => this.remove(tunnel),
      })
    },

    async remove(tunnel) {
      try {
        await cloudflaredApi.deleteTunnel(this.provider, tunnel.id)
        message.success('已删除')
        await this.load({ refresh: true })
      } catch (error) {
        message.error(errorMessage(error))
      }
    },

    replicaCount(tunnel) {
      return (tunnel.connections || []).filter((c) => !c.is_pending_reconnect).length
    },
  },
  template: `
    <section>
      <div class="page-toolbar">
        <div>
          <a-typography-title :level="3" style="margin-bottom: 4px">Cloudflare Tunnel</a-typography-title>
          <a-typography-text type="secondary">隧道列表，状态每 3 秒自动刷新</a-typography-text>
        </div>
        <div class="page-actions">
          <a-button :loading="loading" @click="load({ refresh: true })">刷新</a-button>
          <a-button type="primary" @click="openCreate">创建隧道</a-button>
        </div>
      </div>

      <a-table
        :columns="columns"
        :data-source="tunnels"
        :row-key="record => record.id"
        :loading="loading"
        :pagination="false"
        size="middle"
        :scroll="{ x: 880 }"
        :locale="{ emptyText: '暂无隧道，点击「创建隧道」开始' }"
      >
        <template #bodyCell="{ column, record }">
          <template v-if="column.key === 'name'">
            <router-link :to="detailPath(record)">{{ record.name }}</router-link>
          </template>
          <template v-else-if="column.key === 'status'">
            <a-tag :color="statusColor(record.status)">{{ statusLabel(record.status) }}</a-tag>
          </template>
          <template v-else-if="column.key === 'replicas'">{{ replicaCount(record) }}</template>
          <template v-else-if="column.key === 'type'"><a-tag>cloudflared</a-tag></template>
          <template v-else-if="column.key === 'id'">
            <a-typography-text :ellipsis="{ tooltip: record.id }" code style="max-width: 260px">{{ record.id }}</a-typography-text>
          </template>
          <template v-else-if="column.key === 'actions'">
            <a-space size="small">
              <router-link :to="detailPath(record)">管理</router-link>
              <a-divider type="vertical" />
              <a class="ant-typography ant-typography-danger" style="cursor: pointer" @click="askDelete(record)">删除</a>
            </a-space>
          </template>
        </template>
      </a-table>

      <TunnelCreateModal v-model:open="showCreate" :confirm-loading="creating" @submit="create" />
    </section>
  `,
}
