export default {
  props: {
    count: { type: Number, default: 0 },
    deleting: Boolean,
    deleteText: { type: String, default: '批量删除' },
    actions: { type: Array, default: () => [] },
  },
  emits: ['delete', 'clear', 'action'],
  template: `
    <div v-if="count" class="batch-toolbar" aria-live="polite">
      <span>已选择 {{ count }} 项</span>
      <a-space size="small">
        <a-button v-for="action in actions" :key="action.key" size="small" :type="action.type || 'default'" :danger="!!action.danger" :loading="!!action.loading" @click="$emit('action', action.key)">{{ action.label }}</a-button>
        <a-button size="small" danger :loading="deleting" @click="$emit('delete')">{{ deleteText }}</a-button>
        <a-button size="small" @click="$emit('clear')">取消选择</a-button>
      </a-space>
    </div>
  `,
}
