export default {
  name: 'TunnelCreateModal',
  props: {
    open: { type: Boolean, default: false },
    confirmLoading: { type: Boolean, default: false },
  },
  emits: ['update:open', 'submit'],
  data() {
    return { name: '' }
  },
  watch: {
    open(value) { if (value) this.name = '' },
  },
  computed: {
    canSubmit() { return String(this.name || '').trim().length > 0 },
  },
  methods: {
    submit() {
      if (!this.canSubmit) return
      this.$emit('submit', String(this.name).trim())
    },
  },
  template: `
    <a-modal :open="open" @update:open="v => $emit('update:open', v)" title="创建隧道" :confirm-loading="confirmLoading" :ok-button-props="{ disabled: !canSubmit }" ok-text="创建" cancel-text="取消" @ok="submit">
      <a-form layout="vertical">
        <a-form-item label="隧道名称" required>
          <a-input v-model:value="name" placeholder="如 home-server / ctyun" @keyup.enter="submit" />
        </a-form-item>
        <a-typography-text type="secondary" style="font-size: 12px">
          创建后将获得隧道凭据（token），在服务器上运行客户端即可激活。
        </a-typography-text>
      </a-form>
    </a-modal>
  `,
}
